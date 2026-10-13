<?php
declare(strict_types=1);

namespace App\Modules\Pharmacy;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\DB;
use App\Modules\Inventory\InventoryService;

final class PharmacyController extends Controller
{
    /** Dispensing queue: today's e-Rx by token / time, partial and co-sign tabs. */
    public function queue(): void
    {
        $tab = (string) input('tab', 'pending');
        $status = ['pending' => ['pending'], 'partial' => ['partial'], 'cosign' => ['awaiting_cosign'], 'done' => ['dispensed']][$tab] ?? ['pending'];
        $params = $status;
        $when = $tab === 'done' ? ' AND d.dispensed_at >= ?' : '';
        if ($when) {
            $params[] = today() . ' 00:00:00';
        }
        $rows = DB::all('SELECT d.*, p.name AS patient_name, p.mrn, p.allergies, v.visit_no, u.name AS doctor_name, t.token_label, t.priority,
                                (SELECT COUNT(*) FROM visit_medicines vm JOIN medicine_sources s ON s.id = vm.source_id AND s.stock_location_id IS NOT NULL WHERE vm.visit_id = d.visit_id) AS line_count
                           FROM dispenses d JOIN patients p ON p.id = d.patient_id LEFT JOIN visits v ON v.id = d.visit_id LEFT JOIN users u ON u.id = v.doctor_id
                           LEFT JOIN tokens t ON t.id = d.token_id
                          WHERE d.status IN (' . DB::placeholders($status) . ')' . $when . " ORDER BY FIELD(t.priority, 'emergency') DESC, d.created_at LIMIT 200", $params);
        $counts = DB::pairs("SELECT status, COUNT(*) FROM dispenses WHERE status IN ('pending','partial','awaiting_cosign') GROUP BY status");
        $this->view('pharmacy/queue', ['title' => 'Pharmacy Dispensing', 'rows' => $rows, 'tab' => $tab, 'counts' => $counts]);
    }

    public function dispense(): void
    {
        $id = $this->requireId();
        if (is_post()) {
            try {
                if (input('do') === 'cosign') {
                    if (!can('pharmacy.dispense.finalize')) {
                        abort(403);
                    }
                    PharmacyService::cosign($id);
                    flash('success', 'Controlled drugs co-signed and released.');
                } else {
                    $res = PharmacyService::dispense($id, (array) ($_POST['give'] ?? []), (bool) input('counselled'), (bool) input('ack_major'));
                    flash($res['status'] === 'awaiting_cosign' ? 'warning' : 'success', $res['status'] === 'awaiting_cosign'
                        ? 'Dispensed. Controlled drug(s) wait for a second pharmacist to co-sign before they leave the pharmacy.' : 'Dispensed (' . str_replace('_', ' ', $res['status']) . ').');
                }
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            redirect('pharmacy/dispense', ['id' => $id]);
        }
        try {
            $d = PharmacyService::prepare($id);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }
        $this->view('pharmacy/dispense', ['title' => 'Dispense — ' . $d['patient_name'], 'd' => $d]);
    }

    public function sale(): void
    {
        $loc = (int) input('location_id', 0) ?: ((int) setting('pharmacy_location_id', 0) ?: (int) (array_key_first(InventoryService::locations(['pharmacy_main', 'pharmacy_opd'])) ?? 0));
        if (is_post()) {
            try {
                $id = PharmacyService::counterSale($loc, array_values((array) ($_POST['lines'] ?? [])), (int) input('patient_id', 0) ?: null,
                    (string) input('customer_name', ''), (string) input('mode', 'cash'), (string) input('sale_type', 'otc_walk_in'));
                $inv = (int) DB::value('SELECT invoice_id FROM pharmacy_sales WHERE id = ?', [$id]);
                flash('success', 'Sale completed.');
                redirect('pharmacy/sale', ['location_id' => $loc, 'print' => $inv]);
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
                redirect('pharmacy/sale', ['location_id' => $loc]);
            }
        }
        $this->view('pharmacy/sale', ['title' => 'Counter Sale', 'loc' => $loc, 'locations' => InventoryService::locations(['pharmacy_main', 'pharmacy_opd', 'pharmacy_ipd']),
            'scripts' => ['js/stock.js'],
            'today' => DB::all('SELECT s.*, u.name AS sold_by_name FROM pharmacy_sales s LEFT JOIN users u ON u.id = s.sold_by WHERE s.sold_at >= ? ORDER BY s.id DESC LIMIT 20', [today() . ' 00:00:00'])]);
    }

    public function returns(): void
    {
        if (is_post()) {
            try {
                if (input('decide')) {
                    if (!can('pharmacy.return.approve')) {
                        abort(403);
                    }
                    PharmacyService::decideReturn((int) input('decide'), input('decision') === 'approve');
                    flash('success', 'Return ' . (input('decision') === 'approve' ? 'approved — stock taken back; the refund is ready at the billing counter.' : 'rejected.'));
                } else {
                    PharmacyService::requestReturn((string) input('source', 'sale'), (int) input('source_id', 0), (array) ($_POST['ret'] ?? []), (string) input('reason', ''));
                    flash('success', 'Return recorded — waiting for the chief pharmacist.');
                }
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            redirect('pharmacy/returns');
        }
        $lookup = trim((string) input('q', ''));
        $found = null;
        if ($lookup !== '') {
            $sale = DB::one('SELECT s.*, "sale" AS src FROM pharmacy_sales s WHERE s.sale_number = ?', [$lookup]);
            $disp = $sale ? null : DB::one('SELECT d.*, "dispense" AS src FROM dispenses d JOIN visits v ON v.id = d.visit_id WHERE v.visit_no = ? ORDER BY d.id DESC LIMIT 1', [$lookup]);
            $found = $sale ?: $disp;
            if ($found) {
                $found['lines'] = $found['src'] === 'sale'
                    ? DB::all('SELECT si.*, i.name AS item_name, sb.batch_no FROM pharmacy_sale_items si JOIN items i ON i.id = si.item_id LEFT JOIN stock_batches sb ON sb.id = si.batch_id WHERE si.sale_id = ?', [$found['id']])
                    : DB::all('SELECT di.*, i.name AS item_name, sb.batch_no FROM dispense_items di JOIN items i ON i.id = di.item_id LEFT JOIN stock_batches sb ON sb.id = di.batch_id WHERE di.dispense_id = ? AND di.ledger_id IS NOT NULL', [$found['id']]);
            } else {
                flash('warning', 'No sale or prescription found for "' . $lookup . '". Use the sale number (PS-…) or the visit number.');
            }
        }
        $this->view('pharmacy/returns', ['title' => 'Pharmacy Returns', 'found' => $found, 'q' => $lookup,
            'rows' => DB::all('SELECT r.*, p.name AS patient_name, u.name AS requested_by_name, a.name AS approved_by_name FROM pharmacy_returns r LEFT JOIN patients p ON p.id = r.patient_id
                                 LEFT JOIN users u ON u.id = r.requested_by LEFT JOIN users a ON a.id = r.approved_by ORDER BY r.status = \'requested\' DESC, r.id DESC LIMIT 60')]);
    }

    public function controlled(): void
    {
        $itemId = (int) input('item_id', 0);
        $params = [];
        $w = '';
        if ($itemId) {
            $w = ' WHERE c.item_id = ?';
            $params[] = $itemId;
        }
        $rows = DB::all('SELECT c.*, i.name AS item_name, sb.batch_no, sl.name AS location_name, p.name AS patient_name, p.mrn, u.name AS issued_by_name, w.name AS witness_name
                           FROM controlled_drug_register c JOIN items i ON i.id = c.item_id LEFT JOIN stock_batches sb ON sb.id = c.batch_id JOIN stock_locations sl ON sl.id = c.location_id
                           LEFT JOIN patients p ON p.id = c.patient_id LEFT JOIN users u ON u.id = c.issued_by LEFT JOIN users w ON w.id = c.witness_by' . $w . ' ORDER BY c.id DESC LIMIT 500', $params);
        if ($this->wantsExport()) {
            $this->export('controlled_register', 'Controlled Drug Register', ['occurred_at' => ['Date', static fn ($r) => fmt_datetime($r['occurred_at'])], 'item_name' => 'Drug',
                'batch_no' => 'Batch', 'location_name' => 'Location', 'entry_type' => 'Entry', 'qty' => ['Qty', static fn ($r) => (float) $r['qty']],
                'balance_after' => ['Balance', static fn ($r) => (float) $r['balance_after']], 'patient_name' => 'Patient', 'mrn' => 'MRN',
                'issued_by_name' => 'Issued by', 'witness_name' => 'Witness'], $rows);
            return;
        }
        $this->view('pharmacy/controlled', ['title' => 'Controlled Drug Register', 'rows' => $rows, 'itemId' => $itemId,
            'items' => DB::pairs('SELECT id, name FROM items WHERE is_controlled = 1 AND deleted_at IS NULL ORDER BY name')]);
    }

    /** Chief pharmacist: formulary data (interactions, allergy classes, substitutes) and sale prices. */
    public function chief(): void
    {
        if (is_post()) {
            $do = (string) input('do', '');
            if ($do === 'interaction' && trim((string) input('generic_a')) !== '' && trim((string) input('generic_b')) !== '') {
                DB::insert('drug_interactions', ['generic_a' => mb_substr(trim((string) input('generic_a')), 0, 150), 'generic_b' => mb_substr(trim((string) input('generic_b')), 0, 150),
                    'severity' => in_array(input('severity'), ['minor', 'moderate', 'major', 'contraindicated'], true) ? input('severity') : 'moderate',
                    'description' => mb_substr((string) input('description', ''), 0, 500), 'is_active' => 1, 'created_at' => now(), 'created_by' => Auth::id()]);
            } elseif ($do === 'interaction_off') {
                DB::update('drug_interactions', ['is_active' => 0], 'id = ?', [(int) input('id')]);
            } elseif ($do === 'allergy' && (int) input('medicine_id') && trim((string) input('allergy_class')) !== '') {
                DB::insert('drug_allergy_classes', ['medicine_id' => (int) input('medicine_id'), 'allergy_class' => mb_substr(trim((string) input('allergy_class')), 0, 60)]);
            } elseif ($do === 'allergy_del') {
                DB::run('DELETE FROM drug_allergy_classes WHERE id = ?', [(int) input('id')]);
            } elseif ($do === 'substitute' && (int) input('medicine_id') && (int) input('substitute_medicine_id')) {
                DB::run('INSERT IGNORE INTO drug_substitutions (medicine_id, substitute_medicine_id, is_therapeutic_equivalent) VALUES (?, ?, 1)', [(int) input('medicine_id'), (int) input('substitute_medicine_id')]);
            } elseif ($do === 'price' && (int) input('item_id') && is_numeric(input('price'))) {
                $old = (float) DB::value('SELECT sale_price FROM items WHERE id = ?', [(int) input('item_id')]);
                DB::update('items', ['sale_price' => round((float) input('price'), 2), 'updated_at' => now(), 'updated_by' => Auth::id()], 'id = ?', [(int) input('item_id')]);
                DB::run('UPDATE medicines m JOIN items i ON i.medicine_id = m.id SET m.price = i.sale_price WHERE i.id = ?', [(int) input('item_id')]);
                ActivityLog::record('Price approved', 'pharmacy', (string) input('item_id'), money($old) . ' → ' . money(input('price')), null, null,
                    ['entity' => 'items', 'old' => ['sale_price' => $old], 'new' => ['sale_price' => (float) input('price')]]);
            }
            ActivityLog::record('Formulary updated', 'pharmacy', null, $do);
            flash('success', 'Saved.');
            redirect('pharmacy/chief');
        }
        $this->view('pharmacy/chief', [
            'title' => 'Chief Pharmacist',
            'interactions' => DB::all('SELECT * FROM drug_interactions WHERE is_active = 1 ORDER BY FIELD(severity, "contraindicated","major","moderate","minor"), generic_a'),
            'allergies' => DB::all('SELECT a.*, m.name FROM drug_allergy_classes a JOIN medicines m ON m.id = a.medicine_id ORDER BY a.allergy_class, m.name'),
            'subs' => DB::all('SELECT s.*, a.name AS med, b.name AS sub FROM drug_substitutions s JOIN medicines a ON a.id = s.medicine_id JOIN medicines b ON b.id = s.substitute_medicine_id ORDER BY a.name'),
            'medicines' => DB::pairs('SELECT id, CONCAT(name, " ", COALESCE(strength, "")) FROM medicines WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name'),
            'expiring' => DB::all("SELECT i.name, sb.batch_no, sb.expiry_date, SUM(b.qty_on_hand) AS qty FROM stock_balances b JOIN stock_batches sb ON sb.id = b.batch_id JOIN items i ON i.id = b.item_id
                                   JOIN stock_locations sl ON sl.id = b.location_id WHERE i.item_type = 'drug' AND b.qty_on_hand > 0 AND sb.expiry_date <= ? AND sl.location_type LIKE 'pharmacy%'
                                   GROUP BY i.name, sb.batch_no, sb.expiry_date ORDER BY sb.expiry_date LIMIT 30", [date('Y-m-d', strtotime('+90 days'))]),
        ]);
    }
}
