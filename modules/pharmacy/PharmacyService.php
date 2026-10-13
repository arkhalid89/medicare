<?php
declare(strict_types=1);

namespace App\Modules\Pharmacy;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\DB;
use App\Modules\Billing\BillingService;
use App\Modules\Inventory\InventorySeeder;
use App\Modules\Inventory\StockService;

/**
 * Pharmacy (Doc 3 WF 6, Doc 7 §7, Doc 8 §3.6 / §5.6):
 *  - the e-Rx reaches the dispensing queue the moment the doctor checks out
 *    (hospital-supplied lines only — the main Rx is bought outside);
 *  - allergy and interaction alerts, FEFO batches, substitution and partial
 *    dispensing;
 *  - controlled drugs: every movement needs a second pharmacist's co-sign
 *    (never the dispenser) and is written to the controlled-drug register;
 *  - OTC counter sales and patient returns (approved, then refunded).
 */
final class PharmacyService
{
    /** Creates the dispensing order of a visit (called inside the checkout transaction). */
    public static function queueVisit(int $visitId, int $patientId, ?int $tokenId = null): ?int
    {
        $lines = DB::all(
            'SELECT vm.*, s.stock_location_id FROM visit_medicines vm JOIN medicine_sources s ON s.id = vm.source_id
              WHERE vm.visit_id = ? AND s.stock_location_id IS NOT NULL AND vm.medicine_id IS NOT NULL',
            [$visitId]
        );
        if (!$lines) {
            return null;
        }
        $location = (int) $lines[0]['stock_location_id'];
        $id = DB::insert('dispenses', ['branch_id' => Auth::branchId(), 'visit_id' => $visitId, 'patient_id' => $patientId, 'location_id' => $location,
            'dispense_type' => 'opd', 'token_id' => $tokenId, 'status' => 'pending', 'created_at' => now()]);
        foreach ($lines as $l) {
            InventorySeeder::itemForMedicine((int) $l['medicine_id']);
        }
        return $id;
    }

    /**
     * Everything the dispensing screen needs: lines with prescribed / given
     * quantities, usable stock, FEFO plan, alerts and substitutes.
     */
    public static function prepare(int $dispenseId): array
    {
        $d = DB::one('SELECT d.*, p.name AS patient_name, p.mrn, p.gender, p.dob, p.allergies, p.phone, v.visit_no, u.name AS doctor_name, sl.name AS location_name,
                             t.token_label
                        FROM dispenses d JOIN patients p ON p.id = d.patient_id LEFT JOIN visits v ON v.id = d.visit_id LEFT JOIN users u ON u.id = v.doctor_id
                        JOIN stock_locations sl ON sl.id = d.location_id LEFT JOIN tokens t ON t.id = d.token_id WHERE d.id = ?', [$dispenseId]);
        if (!$d) {
            throw new \RuntimeException('Dispensing order not found.');
        }
        $lines = DB::all(
            'SELECT vm.*, m.item_id, m.is_controlled, m.is_high_alert, m.is_lasa, m.generic_name AS master_generic
               FROM visit_medicines vm JOIN medicine_sources s ON s.id = vm.source_id AND s.stock_location_id IS NOT NULL
               LEFT JOIN medicines m ON m.id = vm.medicine_id WHERE vm.visit_id = ? ORDER BY vm.sort_order, vm.id',
            [$d['visit_id']]
        );
        $generics = [];
        foreach ($lines as &$l) {
            $l['item_id'] = $l['item_id'] ?: InventorySeeder::itemForMedicine((int) $l['medicine_id']);
            $l['given'] = (float) DB::value('SELECT COALESCE(SUM(qty), 0) FROM dispense_items WHERE dispense_id = ? AND visit_medicine_id = ?', [$dispenseId, $l['id']]);
            $l['remaining'] = max(0, round((float) $l['quantity'] - $l['given'], 3));
            $l['available'] = $l['item_id'] ? StockService::available((int) $l['item_id'], (int) $d['location_id']) : 0.0;
            $l['plan'] = $l['item_id'] && $l['remaining'] > 0 ? StockService::fefo((int) $l['item_id'], (int) $d['location_id'], $l['remaining'], true) : [];
            $l['allergy'] = self::allergyAlert((int) $l['medicine_id'], (string) ($l['generic_name'] ?? ''), (string) $d['allergies']);
            $l['substitutes'] = $l['available'] < $l['remaining'] ? self::substitutes((int) $l['medicine_id'], (int) $d['location_id']) : [];
            $generics[(int) $l['id']] = strtolower(trim((string) ($l['generic_name'] ?: $l['master_generic'])));
        }
        unset($l);
        $d['lines'] = $lines;
        $d['interactions'] = self::interactions(array_values(array_filter($generics)));
        $d['items'] = DB::all('SELECT di.*, i.name AS item_name, sb.batch_no, sb.expiry_date FROM dispense_items di JOIN items i ON i.id = di.item_id
                                LEFT JOIN stock_batches sb ON sb.id = di.batch_id WHERE di.dispense_id = ? ORDER BY di.id', [$dispenseId]);
        return $d;
    }

    /** Allergy alert: patient's allergy text against the drug's allergy classes and generic name. */
    public static function allergyAlert(int $medicineId, string $generic, string $allergies): ?string
    {
        $allergies = strtolower(trim($allergies));
        if ($allergies === '') {
            return null;
        }
        $terms = array_filter(array_map('trim', preg_split('/[,;\/]+/', $allergies) ?: []));
        $classes = array_map('strtolower', DB::column('SELECT allergy_class FROM drug_allergy_classes WHERE medicine_id = ?', [$medicineId]));
        $generic = strtolower($generic);
        foreach ($terms as $t) {
            if (strlen($t) < 3) {
                continue;
            }
            foreach ($classes as $c) {
                if (str_contains($c, $t) || str_contains($t, $c)) {
                    return 'Patient is allergic to ' . $t . ' — this drug belongs to the ' . $c . ' class.';
                }
            }
            if ($generic !== '' && (str_contains($generic, $t) || str_contains($t, $generic))) {
                return 'Patient is allergic to ' . $t . ' (' . $generic . ').';
            }
        }
        return null;
    }

    /** Interactions between the generics of one prescription. */
    public static function interactions(array $generics): array
    {
        $generics = array_values(array_unique($generics));
        $out = [];
        if (count($generics) < 2) {
            return $out;
        }
        foreach (DB::all('SELECT * FROM drug_interactions WHERE is_active = 1') as $r) {
            $a = strtolower($r['generic_a']);
            $b = strtolower($r['generic_b']);
            $hitA = $hitB = false;
            foreach ($generics as $g) {
                $hitA = $hitA || str_contains($g, $a);
                $hitB = $hitB || str_contains($g, $b);
            }
            if ($hitA && $hitB) {
                $out[] = $r;
            }
        }
        return $out;
    }

    public static function substitutes(int $medicineId, int $locationId): array
    {
        $rows = DB::all('SELECT m.id, m.name, m.strength, m.item_id, s.is_therapeutic_equivalent FROM drug_substitutions s JOIN medicines m ON m.id = s.substitute_medicine_id
                          WHERE s.medicine_id = ? AND m.is_active = 1 AND m.deleted_at IS NULL', [$medicineId]);
        $generic = (string) DB::value('SELECT generic_name FROM medicines WHERE id = ?', [$medicineId]);
        if ($generic !== '') {
            foreach (DB::all('SELECT id, name, strength, item_id, 1 AS is_therapeutic_equivalent FROM medicines WHERE generic_name = ? AND id <> ? AND is_active = 1 AND deleted_at IS NULL',
                [$generic, $medicineId]) as $m) {
                $rows[] = $m;
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $item = $r['item_id'] ?: InventorySeeder::itemForMedicine((int) $r['id']);
            $avail = $item ? StockService::available((int) $item, $locationId) : 0;
            if ($avail > 0) {
                $out[(int) $r['id']] = ['medicine_id' => (int) $r['id'], 'item_id' => (int) $item, 'name' => trim($r['name'] . ' ' . $r['strength']), 'available' => $avail];
            }
        }
        return array_values($out);
    }

    /**
     * Dispense.
     * @param array<int, array{qty:float, substitute_medicine_id?:int, batch_id?:int}> $lines visit_medicine id => what to give
     */
    public static function dispense(int $dispenseId, array $lines, bool $counselled, bool $ackMajor): array
    {
        $prep = self::prepare($dispenseId);
        if (in_array($prep['status'], ['dispensed', 'cancelled'], true)) {
            throw new \RuntimeException('This prescription was already ' . $prep['status'] . '.');
        }
        foreach ($prep['interactions'] as $i) {
            if (in_array($i['severity'], ['major', 'contraindicated'], true) && !$ackMajor) {
                throw new \RuntimeException('Acknowledge the ' . $i['severity'] . ' interaction (' . $i['generic_a'] . ' + ' . $i['generic_b'] . ') before dispensing.');
            }
        }
        $byId = [];
        foreach ($prep['lines'] as $l) {
            $byId[(int) $l['id']] = $l;
        }
        $billAtDispense = setting('billing_medicines_at', 'checkout') === 'dispense';
        $result = DB::transaction(static function () use ($prep, $lines, $byId, $counselled, $dispenseId, $billAtDispense): array {
            $pendingCosign = false;
            $given = 0;
            foreach ($lines as $vmId => $in) {
                $l = $byId[(int) $vmId] ?? null;
                $qty = round((float) ($in['qty'] ?? 0), 3);
                if (!$l || $qty <= 0) {
                    continue;
                }
                if ($qty > $l['remaining'] + 0.0005) {
                    throw new \RuntimeException($l['medicine_name'] . ': only ' . num($l['remaining']) . ' still to give.');
                }
                if ($l['allergy'] && empty($in['ack_allergy'])) {
                    throw new \RuntimeException($l['medicine_name'] . ': ' . $l['allergy'] . ' Confirm the allergy override first.');
                }
                $itemId = (int) $l['item_id'];
                $subFor = null;
                if (!empty($in['substitute_medicine_id'])) {
                    $itemId = (int) InventorySeeder::itemForMedicine((int) $in['substitute_medicine_id']);
                    $subFor = (int) $l['item_id'];
                }
                $item = DB::one('SELECT * FROM items WHERE id = ?', [$itemId]);
                $price = $subFor ? (float) $item['sale_price'] : (float) $l['unit_price'];
                $controlled = (int) $item['is_controlled'] === 1;
                $plan = (int) ($in['batch_id'] ?? 0) > 0 ? [['batch_id' => (int) $in['batch_id'], 'qty' => $qty]] : StockService::fefo($itemId, (int) $prep['location_id'], $qty);
                foreach ($plan as $p) {
                    $ledgerId = null;
                    if (!$controlled) {
                        $ledgerId = StockService::move($itemId, (int) $prep['location_id'], -$p['qty'], 'dispense', ['batch_id' => $p['batch_id'],
                            'reference_type' => 'dispenses', 'reference_id' => $dispenseId, 'patient_id' => $prep['patient_id'], 'visit_id' => $prep['visit_id']]);
                    } else {
                        $pendingCosign = true;
                    }
                    $diId = DB::insert('dispense_items', ['dispense_id' => $dispenseId, 'visit_medicine_id' => (int) $vmId, 'item_id' => $itemId,
                        'substituted_for_item_id' => $subFor, 'batch_id' => $p['batch_id'], 'qty' => $p['qty'], 'unit_price' => $price,
                        'amount' => round($p['qty'] * $price, 2), 'ledger_id' => $ledgerId, 'created_at' => now()]);
                    if ($billAtDispense && $price > 0) {
                        $ch = BillingService::charge(['patient_id' => $prep['patient_id'], 'visit_id' => $prep['visit_id'], 'invoice_type' => 'pharmacy'],
                            ['description' => $item['name'], 'quantity' => $p['qty'], 'unit_price' => $price, 'source_module' => 'pharmacy',
                             'source_reference_type' => 'dispense_items', 'source_reference_id' => $diId]);
                        DB::update('dispense_items', ['invoice_item_id' => $ch['item_id']], 'id = ?', [$diId]);
                        DB::update('dispenses', ['invoice_id' => $ch['invoice_id']], 'id = ?', [$dispenseId]);
                    }
                    $given++;
                }
            }
            if (!$given) {
                throw new \RuntimeException('Enter the quantity to give for at least one medicine.');
            }
            $open = 0.0;
            foreach ($byId as $vmId => $l) {
                $open += max(0, (float) $l['quantity'] - (float) DB::value('SELECT COALESCE(SUM(qty), 0) FROM dispense_items WHERE dispense_id = ? AND visit_medicine_id = ?', [$dispenseId, $vmId]));
            }
            $status = $pendingCosign ? 'awaiting_cosign' : ($open > 0.0005 ? 'partial' : 'dispensed');
            DB::update('dispenses', ['status' => $status, 'needs_cosign' => $pendingCosign ? 1 : 0, 'counseling_done' => $counselled ? 1 : 0,
                'dispensed_by' => Auth::id(), 'dispensed_at' => now()], 'id = ?', [$dispenseId]);
            ActivityLog::record('Prescription dispensed', 'pharmacy', (string) $dispenseId, ($prep['visit_no'] ?? '') . ' — ' . $given . ' batch line(s), status ' . $status);
            return ['status' => $status];
        });
        return $result;
    }

    /** Second pharmacist co-signs the controlled lines: only now is the stock moved and registered. */
    public static function cosign(int $dispenseId): void
    {
        DB::transaction(static function () use ($dispenseId): void {
            $d = DB::one("SELECT * FROM dispenses WHERE id = ? AND status = 'awaiting_cosign' FOR UPDATE", [$dispenseId]);
            if (!$d) {
                throw new \RuntimeException('Nothing waits for a co-sign on this prescription.');
            }
            if ((int) $d['dispensed_by'] === Auth::id()) {
                throw new \RuntimeException('Controlled drugs need a second pharmacist: you cannot co-sign your own dispensing.');
            }
            foreach (DB::all('SELECT * FROM dispense_items WHERE dispense_id = ? AND ledger_id IS NULL', [$dispenseId]) as $di) {
                $ledger = StockService::move((int) $di['item_id'], (int) $d['location_id'], -(float) $di['qty'], 'dispense', ['batch_id' => (int) $di['batch_id'],
                    'reference_type' => 'dispenses', 'reference_id' => $dispenseId, 'cosigned_by' => Auth::id(), 'patient_id' => $d['patient_id'], 'visit_id' => $d['visit_id']]);
                DB::update('dispense_items', ['ledger_id' => $ledger], 'id = ?', [$di['id']]);
                // The register's issuer is the dispensing pharmacist, the witness is the co-signer.
                DB::run('UPDATE controlled_drug_register SET issued_by = ?, witness_by = ? WHERE ledger_id = ?', [$d['dispensed_by'], Auth::id(), $ledger]);
            }
            $prescribed = (float) DB::value('SELECT COALESCE(SUM(vm.quantity), 0) FROM visit_medicines vm JOIN medicine_sources s ON s.id = vm.source_id
                                              WHERE vm.visit_id = ? AND s.stock_location_id IS NOT NULL', [$d['visit_id']]);
            $given = (float) DB::value('SELECT COALESCE(SUM(qty), 0) FROM dispense_items WHERE dispense_id = ?', [$dispenseId]);
            DB::update('dispenses', ['status' => $given + 0.0005 >= $prescribed ? 'dispensed' : 'partial', 'cosigned_by' => Auth::id(), 'cosigned_at' => now()], 'id = ?', [$dispenseId]);
            ActivityLog::record('Controlled drugs co-signed', 'pharmacy', (string) $dispenseId, 'Witnessed and released');
        });
    }

    // ---------------------------------------------------------- counter sale

    /**
     * OTC counter sale: barcode / search, cart, optional patient, payment.
     * Controlled drugs are never sold over the counter.
     * @param array<int, array{item_id:int, qty:float, unit_price?:float, discount?:float}> $lines
     */
    public static function counterSale(int $locationId, array $lines, ?int $patientId, string $customer, string $mode, string $saleType = 'otc_walk_in'): int
    {
        $lines = array_values(array_filter($lines, static fn ($l) => (int) ($l['item_id'] ?? 0) > 0 && (float) ($l['qty'] ?? 0) > 0));
        if (!$lines) {
            throw new \RuntimeException('The cart is empty.');
        }
        return DB::transaction(static function () use ($locationId, $lines, $patientId, $customer, $mode, $saleType): int {
            $saleId = DB::insert('pharmacy_sales', ['branch_id' => Auth::branchId(), 'sale_number' => StockService::nextNumber('sale', 'PS'), 'location_id' => $locationId,
                'sale_type' => in_array($saleType, ['otc_walk_in', 'prescription_outside', 'staff'], true) ? $saleType : 'otc_walk_in', 'patient_id' => $patientId,
                'customer_name' => $patientId ? null : (mb_substr($customer, 0, 150) ?: 'Walk-in customer'), 'status' => 'completed', 'sold_by' => Auth::id(), 'sold_at' => now()]);
            $invoiceId = BillingService::create(['patient_id' => $patientId, 'customer_name' => $patientId ? null : (mb_substr($customer, 0, 150) ?: 'Walk-in customer'),
                'invoice_type' => 'pharmacy', 'notes' => 'Counter sale']);
            $total = 0.0;
            foreach ($lines as $l) {
                $item = DB::one('SELECT * FROM items WHERE id = ? AND is_active = 1', [(int) $l['item_id']]);
                if (!$item) {
                    throw new \RuntimeException('Item not found.');
                }
                if ((int) $item['is_controlled'] === 1) {
                    throw new \RuntimeException($item['name'] . ' is a controlled drug and cannot be sold over the counter.');
                }
                $price = can('pharmacy.formulary.manage') && isset($l['unit_price']) && is_numeric($l['unit_price']) ? (float) $l['unit_price'] : (float) $item['sale_price'];
                $qty = round((float) $l['qty'], 3);
                foreach (StockService::fefo((int) $item['id'], $locationId, $qty) as $p) {
                    StockService::move((int) $item['id'], $locationId, -$p['qty'], 'sale', ['batch_id' => $p['batch_id'], 'reference_type' => 'pharmacy_sales',
                        'reference_id' => $saleId, 'patient_id' => $patientId]);
                    $amount = round($p['qty'] * $price, 2);
                    $siId = DB::insert('pharmacy_sale_items', ['sale_id' => $saleId, 'item_id' => (int) $item['id'], 'batch_id' => $p['batch_id'], 'qty' => $p['qty'],
                        'unit_price' => $price, 'discount' => 0, 'amount' => $amount]);
                    BillingService::addItem($invoiceId, ['description' => $item['name'], 'quantity' => $p['qty'], 'unit_price' => $price, 'source_module' => 'pharmacy',
                        'source_reference_type' => 'pharmacy_sale_items', 'source_reference_id' => $siId], false);
                    $total += $amount;
                }
            }
            $inv = BillingService::recalc($invoiceId);
            if ((float) $inv['net_amount'] > 0) {
                BillingService::collect($invoiceId, (float) $inv['net_amount'], $mode);
            }
            DB::update('pharmacy_sales', ['invoice_id' => $invoiceId, 'total_amount' => round($total, 2)], 'id = ?', [$saleId]);
            ActivityLog::record('Counter sale', 'pharmacy', (string) $saleId, money($total));
            return $saleId;
        });
    }

    // --------------------------------------------------------------- returns

    /**
     * Patient return (unopened, within policy).
     * @param string $source 'sale' | 'dispense'
     * @param array<int, array{qty:float, resellable?:bool}> $lines source item id => qty
     */
    public static function requestReturn(string $source, int $sourceId, array $lines, string $reason): int
    {
        if (trim($reason) === '') {
            throw new \RuntimeException('Enter the reason for the return.');
        }
        return DB::transaction(static function () use ($source, $sourceId, $lines, $reason): int {
            $hdr = $source === 'sale' ? DB::one('SELECT * FROM pharmacy_sales WHERE id = ?', [$sourceId]) : DB::one('SELECT * FROM dispenses WHERE id = ?', [$sourceId]);
            if (!$hdr) {
                throw new \RuntimeException('Sale / dispensing not found.');
            }
            $id = DB::insert('pharmacy_returns', ['branch_id' => Auth::branchId(), 'return_number' => StockService::nextNumber('pret', 'PR'),
                'sale_id' => $source === 'sale' ? $sourceId : null, 'dispense_id' => $source === 'dispense' ? $sourceId : null, 'patient_id' => $hdr['patient_id'],
                'location_id' => $hdr['location_id'], 'status' => 'requested', 'reason' => mb_substr($reason, 0, 255), 'requested_by' => Auth::id(), 'created_at' => now()]);
            $total = 0.0;
            foreach ($lines as $srcItemId => $l) {
                $qty = round((float) ($l['qty'] ?? 0), 3);
                if ($qty <= 0) {
                    continue;
                }
                $row = $source === 'sale'
                    ? DB::one('SELECT id, item_id, batch_id, qty, returned_qty, unit_price FROM pharmacy_sale_items WHERE id = ? AND sale_id = ?', [(int) $srcItemId, $sourceId])
                    : DB::one('SELECT id, item_id, batch_id, qty, returned_qty, unit_price FROM dispense_items WHERE id = ? AND dispense_id = ? AND ledger_id IS NOT NULL', [(int) $srcItemId, $sourceId]);
                if (!$row || $qty > (float) $row['qty'] - (float) $row['returned_qty'] + 0.0005) {
                    throw new \RuntimeException('Return quantity is more than what was given.');
                }
                DB::insert('pharmacy_return_items', ['return_id' => $id, 'source_type' => $source, 'source_item_id' => (int) $row['id'], 'item_id' => $row['item_id'],
                    'batch_id' => $row['batch_id'], 'qty' => $qty, 'unit_price' => $row['unit_price'], 'resellable' => !empty($l['resellable']) ? 1 : 0]);
                $total += $qty * (float) $row['unit_price'];
            }
            if ($total <= 0 && !DB::value('SELECT 1 FROM pharmacy_return_items WHERE return_id = ?', [$id])) {
                throw new \RuntimeException('Enter the quantity returned for at least one line.');
            }
            DB::update('pharmacy_returns', ['total_amount' => round($total, 2)], 'id = ?', [$id]);
            ActivityLog::record('Return requested', 'pharmacy', (string) $id, money($total) . ' — ' . $reason);
            return $id;
        });
    }

    /** Chief pharmacist approves: stock back (or to quarantine) and a pre-approved refund for the cashier. */
    public static function decideReturn(int $returnId, bool $approve): void
    {
        DB::transaction(static function () use ($returnId, $approve): void {
            $r = DB::one("SELECT * FROM pharmacy_returns WHERE id = ? AND status = 'requested' FOR UPDATE", [$returnId]);
            if (!$r) {
                throw new \RuntimeException('Return is not waiting for a decision.');
            }
            if ((int) $r['requested_by'] === Auth::id()) {
                throw new \RuntimeException('The person who took the return cannot approve it.');
            }
            if (!$approve) {
                DB::update('pharmacy_returns', ['status' => 'rejected', 'approved_by' => Auth::id(), 'approved_at' => now()], 'id = ?', [$returnId]);
                return;
            }
            $quarantine = StockService::locationOfType('quarantine') ?? (int) $r['location_id'];
            foreach (DB::all('SELECT * FROM pharmacy_return_items WHERE return_id = ?', [$returnId]) as $i) {
                StockService::move((int) $i['item_id'], (int) $i['resellable'] ? (int) $r['location_id'] : $quarantine, (float) $i['qty'], 'return_from_patient',
                    ['batch_id' => (int) $i['batch_id'], 'reference_type' => 'pharmacy_returns', 'reference_id' => $returnId, 'patient_id' => $r['patient_id']]);
                DB::run('UPDATE ' . ($i['source_type'] === 'sale' ? 'pharmacy_sale_items' : 'dispense_items') . ' SET returned_qty = returned_qty + ? WHERE id = ?', [$i['qty'], $i['source_item_id']]);
            }
            $invoiceId = $r['sale_id'] ? (int) DB::value('SELECT invoice_id FROM pharmacy_sales WHERE id = ?', [$r['sale_id']])
                : (int) DB::value('SELECT invoice_id FROM dispenses WHERE id = ?', [$r['dispense_id']]);
            $refundId = null;
            if ($invoiceId && (float) $r['total_amount'] > 0) {
                $refundId = BillingService::requestRefund($invoiceId, null, (float) $r['total_amount'], 'Pharmacy return ' . $r['return_number'] . ': ' . $r['reason'],
                    'pharmacy', 'pharmacy_returns', $returnId, true);
            }
            DB::update('pharmacy_returns', ['status' => 'completed', 'approved_by' => Auth::id(), 'approved_at' => now(), 'refund_id' => $refundId], 'id = ?', [$returnId]);
            ActivityLog::record('Return approved', 'pharmacy', (string) $returnId, money($r['total_amount']) . ($refundId ? ' — refund ready at the billing counter' : ''));
        });
    }
}
