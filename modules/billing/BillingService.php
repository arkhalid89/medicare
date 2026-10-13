<?php
declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\CodeGenerator;
use App\Core\DB;

/**
 * Billing glue for every department (Doc 2 §5.12, Doc 3 WF 8, Doc 7 §8):
 * invoices with FBR-style sequential numbers, line items from any module
 * (polymorphic source reference), payments, discounts within the user's
 * limit (else approval), refunds (general = approval, lab/radiology
 * rejection = pre-approved by the clinician), voids and fee waivers.
 * Nothing is hard-deleted: voids and reversals keep the trail.
 */
final class BillingService
{
    public const MODES = ['cash', 'card', 'bank_transfer', 'easypaisa', 'jazzcash', 'cheque', 'other'];

    // ------------------------------------------------------------ invoices

    public static function nextInvoiceNo(): string
    {
        return CodeGenerator::next(
            'invoice',
            (string) setting('invoice_no_pattern', 'INV-{YYYY}-{000001}'),
            static fn (string $c): bool => DB::value('SELECT 1 FROM invoices WHERE invoice_no = ?', [$c]) !== null
        );
    }

    /**
     * @param array $header patient_id, visit_id, token_id, doctor_id, department_id, invoice_type, customer_name, notes
     * @param array $items  each: description, service_id, quantity, unit_price, source_module, source_reference_type, source_reference_id
     */
    public static function create(array $header, array $items = []): int
    {
        return DB::transaction(static function () use ($header, $items): int {
            $id = DB::insert('invoices', [
                'branch_id'     => $header['branch_id'] ?? Auth::branchId(),
                'invoice_no'    => self::nextInvoiceNo(),
                'invoice_type'  => $header['invoice_type'] ?? 'opd',
                'patient_id'    => $header['patient_id'] ?? null,
                'customer_name' => $header['customer_name'] ?? null,
                'visit_id'      => $header['visit_id'] ?? null,
                'token_id'      => $header['token_id'] ?? null,
                'doctor_id'     => $header['doctor_id'] ?? null,
                'department_id' => $header['department_id'] ?? null,
                'status'        => 'open',
                'notes'         => $header['notes'] ?? null,
                'created_at'    => $header['created_at'] ?? now(),
                'created_by'    => Auth::id(),
            ]);
            foreach ($items as $item) {
                self::addItem($id, $item, false);
            }
            self::recalc($id);
            return $id;
        });
    }

    public static function addItem(int $invoiceId, array $item, bool $recalc = true): int
    {
        $qty = round((float) ($item['quantity'] ?? 1), 3);
        $price = round((float) ($item['unit_price'] ?? 0), 2);
        $discount = round((float) ($item['discount_amount'] ?? 0), 2);
        $tax = round((float) ($item['tax_amount'] ?? 0), 2);
        $id = DB::insert('invoice_items', [
            'invoice_id'            => $invoiceId,
            'service_id'            => $item['service_id'] ?? null,
            'description'           => mb_substr((string) $item['description'], 0, 255),
            'source_module'         => $item['source_module'] ?? 'registration',
            'source_reference_type' => $item['source_reference_type'] ?? null,
            'source_reference_id'   => $item['source_reference_id'] ?? null,
            'quantity'              => $qty,
            'unit_price'            => $price,
            'discount_amount'       => $discount,
            'tax_amount'            => $tax,
            'amount'                => round($qty * $price - $discount + $tax, 2),
            'created_at'            => now(),
            'created_by'            => Auth::id(),
        ]);
        if ($recalc) {
            self::recalc($invoiceId);
        }
        return $id;
    }

    /** Recomputes totals and status from items, payments and refunds. */
    public static function recalc(int $invoiceId): array
    {
        $inv = DB::one('SELECT * FROM invoices WHERE id = ?', [$invoiceId]);
        $sum = DB::one('SELECT COALESCE(SUM(quantity * unit_price), 0) AS gross, COALESCE(SUM(tax_amount), 0) AS tax, COALESCE(SUM(discount_amount), 0) AS line_disc
                          FROM invoice_items WHERE invoice_id = ? AND is_void = 0', [$invoiceId]);
        $paid = (float) DB::value('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ? AND is_void = 0', [$invoiceId]);
        $refunded = (float) DB::value("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE invoice_id = ? AND status = 'processed'", [$invoiceId]);
        $gross = round((float) $sum['gross'], 2);
        $invoiceDiscount = in_array($inv['discount_status'], ['none', 'approved'], true) ? (float) $inv['discount_amount'] : 0.0;
        $discount = round((float) $sum['line_disc'] + $invoiceDiscount, 2);
        $net = $inv['waived'] ? 0.0 : max(0, round($gross - $discount + (float) $sum['tax'], 2));
        $status = $inv['status'];
        if ($status !== 'void') {
            if ($inv['waived']) {
                $status = 'settled';
            } elseif ($refunded > 0 && $refunded >= $paid && $paid > 0) {
                $status = 'refunded';
            } elseif ($paid + 0.004 >= $net) {
                $status = 'settled';
            } elseif ($paid > 0) {
                $status = 'partial';
            } else {
                $status = 'open';
            }
        }
        DB::update('invoices', [
            'gross_amount' => $gross, 'tax_amount' => round((float) $sum['tax'], 2), 'net_amount' => $net,
            'paid_amount' => round($paid, 2), 'refunded_amount' => round($refunded, 2), 'status' => $status, 'updated_at' => now(),
        ], 'id = ?', [$invoiceId]);
        return DB::one('SELECT * FROM invoices WHERE id = ?', [$invoiceId]);
    }

    public static function find(int $id): ?array
    {
        $inv = DB::one(
            'SELECT i.*, p.name AS patient_name, p.mrn, p.cnic, p.phone, p.gender, p.dob, u.name AS doctor_name, d.name AS department_name,
                    c.name AS created_by_name, t.token_label
               FROM invoices i LEFT JOIN patients p ON p.id = i.patient_id LEFT JOIN users u ON u.id = i.doctor_id
               LEFT JOIN departments d ON d.id = i.department_id LEFT JOIN users c ON c.id = i.created_by
               LEFT JOIN tokens t ON t.id = i.token_id WHERE i.id = ?',
            [$id]
        );
        if (!$inv) {
            return null;
        }
        $inv['items'] = DB::all('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id', [$id]);
        $inv['payments'] = DB::all('SELECT pm.*, u.name AS collected_by_name FROM payments pm LEFT JOIN users u ON u.id = pm.collected_by WHERE pm.invoice_id = ? ORDER BY pm.id', [$id]);
        $inv['refunds'] = DB::all('SELECT r.*, u.name AS initiated_by_name, a.name AS approved_by_name, pr.name AS processed_by_name
                                     FROM refunds r LEFT JOIN users u ON u.id = r.initiated_by LEFT JOIN users a ON a.id = r.approved_by
                                     LEFT JOIN users pr ON pr.id = r.processed_by WHERE r.invoice_id = ? ORDER BY r.id', [$id]);
        $inv['balance'] = round((float) $inv['net_amount'] - (float) $inv['paid_amount'] + (float) $inv['refunded_amount'], 2);
        return $inv;
    }

    public static function balance(int $invoiceId): float
    {
        $i = DB::one('SELECT net_amount, paid_amount, refunded_amount FROM invoices WHERE id = ?', [$invoiceId]);
        return $i ? round((float) $i['net_amount'] - (float) $i['paid_amount'] + (float) $i['refunded_amount'], 2) : 0.0;
    }

    // ------------------------------------------------------------ payments

    /** @return int payment id */
    public static function collect(int $invoiceId, float $amount, string $mode = 'cash', ?string $reference = null): int
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }
        if (!in_array($mode, self::MODES, true)) {
            $mode = 'cash';
        }
        return DB::transaction(static function () use ($invoiceId, $amount, $mode, $reference): int {
            $inv = DB::one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$invoiceId]);
            if (!$inv || $inv['status'] === 'void') {
                throw new \RuntimeException('This invoice cannot take payments.');
            }
            $balance = self::balance($invoiceId);
            if ($amount > $balance + 0.004) {
                throw new \RuntimeException('Payment (' . money($amount) . ') is more than the balance (' . money($balance) . ').');
            }
            $id = DB::insert('payments', [
                'invoice_id' => $invoiceId, 'branch_id' => $inv['branch_id'], 'amount' => round($amount, 2), 'mode' => $mode,
                'reference' => $reference ? mb_substr($reference, 0, 100) : null, 'cash_session_id' => self::sessionId(),
                'collected_by' => Auth::id(), 'collected_at' => now(),
            ]);
            self::recalc($invoiceId);
            ActivityLog::record('Payment collected', 'billing', (string) $invoiceId, $inv['invoice_no'] . ': ' . money($amount) . ' (' . $mode . ')');
            return $id;
        });
    }

    /** Waiver: the invoice is settled at zero (Doc 3 WF 1 — fee waived by an authorised role). */
    public static function waive(int $invoiceId, string $reason): void
    {
        if (!Auth::can('frontdesk.fee.waive') && !Auth::can('billing.discount.approve')) {
            throw new \RuntimeException('You are not allowed to waive fees.');
        }
        DB::update('invoices', ['waived' => 1, 'waiver_reason' => mb_substr($reason, 0, 255), 'waived_by' => Auth::id()], 'id = ?', [$invoiceId]);
        self::recalc($invoiceId);
        ActivityLog::record('Fee waived', 'billing', (string) $invoiceId, 'Fee waived: ' . $reason);
    }

    // ------------------------------------------------------------ discounts

    /** @return string applied | pending */
    public static function applyDiscount(int $invoiceId, float $amount, string $reason): string
    {
        $inv = DB::one('SELECT * FROM invoices WHERE id = ?', [$invoiceId]);
        if (!$inv || in_array($inv['status'], ['void', 'refunded'], true)) {
            throw new \RuntimeException('Discount cannot be applied to this invoice.');
        }
        if (!Auth::can('billing.discount.apply') && !Auth::can('billing.discount.approve')) {
            throw new \RuntimeException('You are not allowed to give discounts.');
        }
        $amount = round(max(0, $amount), 2);
        if ($amount > (float) $inv['gross_amount']) {
            throw new \RuntimeException('Discount cannot exceed the bill.');
        }
        $limit = Auth::discountLimit();
        $needsApproval = $limit !== null && $amount > $limit;
        DB::update('invoices', [
            'discount_amount' => $amount, 'discount_reason' => mb_substr($reason, 0, 255), 'discount_by' => Auth::id(),
            'discount_status' => $needsApproval ? 'pending' : 'approved', 'discount_approved_by' => $needsApproval ? null : Auth::id(),
        ], 'id = ?', [$invoiceId]);
        self::recalc($invoiceId);
        ActivityLog::record($needsApproval ? 'Discount requested' : 'Discount applied', 'billing', (string) $invoiceId,
            $inv['invoice_no'] . ': ' . money($amount) . ' — ' . $reason, null, null, ['entity' => 'invoices', 'old' => ['discount' => $inv['discount_amount']], 'new' => ['discount' => $amount]]);
        return $needsApproval ? 'pending' : 'applied';
    }

    public static function decideDiscount(int $invoiceId, bool $approve): void
    {
        $inv = DB::one("SELECT * FROM invoices WHERE id = ? AND discount_status = 'pending'", [$invoiceId]);
        if (!$inv) {
            throw new \RuntimeException('No pending discount on this invoice.');
        }
        if ((int) $inv['discount_by'] === Auth::id()) {
            throw new \RuntimeException('The person who requested a discount cannot approve it.');
        }
        DB::update('invoices', $approve
            ? ['discount_status' => 'approved', 'discount_approved_by' => Auth::id()]
            : ['discount_status' => 'rejected', 'discount_approved_by' => Auth::id(), 'discount_amount' => 0], 'id = ?', [$invoiceId]);
        self::recalc($invoiceId);
        ActivityLog::record($approve ? 'Discount approved' : 'Discount rejected', 'billing', (string) $invoiceId, $inv['invoice_no'] . ': ' . money($inv['discount_amount']));
    }

    // ------------------------------------------------------------ refunds

    /**
     * General refund (needs approval) or a clinically pre-approved one
     * ($preApproved: Pathologist / Radiologist rejection, Doc 1 §2.12).
     */
    public static function requestRefund(int $invoiceId, ?int $itemId, float $amount, string $reason, string $sourceModule = 'billing',
                                         ?string $refType = null, ?int $refId = null, bool $preApproved = false): int
    {
        $inv = DB::one('SELECT * FROM invoices WHERE id = ?', [$invoiceId]);
        if (!$inv || $inv['status'] === 'void') {
            throw new \RuntimeException('Refund is not possible on this invoice.');
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Refund amount must be greater than zero.');
        }
        $refundable = (float) $inv['paid_amount'] - (float) $inv['refunded_amount']
            - (float) DB::value("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE invoice_id = ? AND status IN ('requested','approved')", [$invoiceId]);
        if ($itemId) {
            $item = DB::one('SELECT * FROM invoice_items WHERE id = ? AND invoice_id = ?', [$itemId, $invoiceId]);
            if (!$item) {
                throw new \RuntimeException('Invoice line not found.');
            }
            $itemLeft = (float) $item['amount'] - (float) $item['refunded_amount']
                - (float) DB::value("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE invoice_item_id = ? AND status IN ('requested','approved')", [$itemId]);
            if ($amount > $itemLeft + 0.004) {
                throw new \RuntimeException('Refund exceeds the amount of that line (' . money($itemLeft) . ').');
            }
        }
        if ($amount > $refundable + 0.004 && !$preApproved) {
            throw new \RuntimeException('Refund exceeds what was paid (' . money(max(0, $refundable)) . ').');
        }
        $id = DB::insert('refunds', [
            'invoice_id' => $invoiceId, 'invoice_item_id' => $itemId, 'branch_id' => $inv['branch_id'], 'amount' => $amount,
            'reason' => mb_substr($reason, 0, 500), 'source_module' => $sourceModule, 'source_reference_type' => $refType,
            'source_reference_id' => $refId, 'status' => $preApproved ? 'approved' : 'requested', 'initiated_by' => Auth::id(),
            'initiated_at' => now(), 'approved_by' => $preApproved ? Auth::id() : null, 'approved_at' => $preApproved ? now() : null,
        ]);
        ActivityLog::record($preApproved ? 'Refund authorised' : 'Refund requested', 'billing', (string) $invoiceId,
            $inv['invoice_no'] . ': ' . money($amount) . ' — ' . $reason . ($preApproved ? ' (' . $sourceModule . ' rejection)' : ''));
        return $id;
    }

    public static function decideRefund(int $refundId, bool $approve, string $note = ''): void
    {
        $r = DB::one("SELECT * FROM refunds WHERE id = ? AND status = 'requested'", [$refundId]);
        if (!$r) {
            throw new \RuntimeException('Refund request not found or already decided.');
        }
        if ((int) $r['initiated_by'] === Auth::id()) {
            throw new \RuntimeException('The person who requested a refund cannot approve it.');
        }
        DB::update('refunds', ['status' => $approve ? 'approved' : 'rejected', 'approved_by' => Auth::id(), 'approved_at' => now(),
            'rejection_note' => $approve ? null : mb_substr($note, 0, 255)], 'id = ?', [$refundId]);
        ActivityLog::record($approve ? 'Refund approved' : 'Refund rejected', 'billing', (string) $r['invoice_id'], money($r['amount']) . ($note ? ' — ' . $note : ''));
    }

    /** Billing Clerk pays out an approved refund (cash drawer). */
    public static function processRefund(int $refundId, string $mode = 'cash'): void
    {
        DB::transaction(static function () use ($refundId, $mode): void {
            $r = DB::one("SELECT * FROM refunds WHERE id = ? AND status = 'approved' FOR UPDATE", [$refundId]);
            if (!$r) {
                throw new \RuntimeException('Refund is not approved yet or was already paid.');
            }
            DB::update('refunds', ['status' => 'processed', 'processed_by' => Auth::id(), 'processed_at' => now(),
                'mode' => in_array($mode, self::MODES, true) ? $mode : 'cash', 'cash_session_id' => self::sessionId()], 'id = ?', [$refundId]);
            if ($r['invoice_item_id']) {
                DB::run('UPDATE invoice_items SET refunded_amount = refunded_amount + ? WHERE id = ?', [$r['amount'], $r['invoice_item_id']]);
            }
            self::recalc((int) $r['invoice_id']);
            ActivityLog::record('Refund paid', 'billing', (string) $r['invoice_id'], money($r['amount']) . ' paid out (' . $mode . ')');
        });
    }

    /** Void: a linked reversal, never a delete (Doc 3 WF 8). */
    public static function void(int $invoiceId, string $reason): void
    {
        $inv = DB::one('SELECT * FROM invoices WHERE id = ?', [$invoiceId]);
        if (!$inv || $inv['status'] === 'void') {
            throw new \RuntimeException('Invoice already void.');
        }
        if ((float) $inv['paid_amount'] - (float) $inv['refunded_amount'] > 0.004) {
            throw new \RuntimeException('Refund the payments first, then void the invoice.');
        }
        DB::update('invoices', ['status' => 'void', 'void_reason' => mb_substr($reason, 0, 255), 'voided_by' => Auth::id(), 'voided_at' => now()], 'id = ?', [$invoiceId]);
        ActivityLog::record('Invoice voided', 'billing', (string) $invoiceId, $inv['invoice_no'] . ' — ' . $reason);
    }

    // ------------------------------------------------------------- prices

    /** Current price of a service for the branch (branch price → chain price → catalog default). */
    public static function price(int $serviceId, ?int $branchId = null, string $list = 'cash', ?string $date = null): float
    {
        $date = $date ?? today();
        $branchId = $branchId ?? Auth::branchId();
        $row = DB::one(
            'SELECT price FROM service_prices WHERE service_id = ? AND price_list_type = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)
                AND (branch_id = ? OR branch_id IS NULL) ORDER BY branch_id IS NULL, valid_from DESC, id DESC LIMIT 1',
            [$serviceId, $list, $date, $date, $branchId]
        );
        if ($row) {
            return (float) $row['price'];
        }
        return (float) (DB::value('SELECT default_price FROM service_catalog WHERE id = ?', [$serviceId]) ?? 0);
    }

    /** New price version (old prices stay for history). */
    public static function setPrice(int $serviceId, float $price, ?int $branchId, string $list = 'cash', ?string $from = null): void
    {
        $from = $from ?? today();
        DB::transaction(static function () use ($serviceId, $price, $branchId, $list, $from): void {
            DB::run('UPDATE service_prices SET valid_to = DATE_SUB(?, INTERVAL 1 DAY) WHERE service_id = ? AND price_list_type = ? AND branch_id <=> ? AND valid_to IS NULL AND valid_from < ?',
                [$from, $serviceId, $list, $branchId, $from]);
            DB::run('DELETE FROM service_prices WHERE service_id = ? AND price_list_type = ? AND branch_id <=> ? AND valid_from = ?', [$serviceId, $list, $branchId, $from]);
            DB::insert('service_prices', ['service_id' => $serviceId, 'branch_id' => $branchId, 'price_list_type' => $list, 'price' => round($price, 2),
                'valid_from' => $from, 'created_at' => now(), 'created_by' => Auth::id()]);
            if ($branchId === null && $list === 'cash' && $from <= today()) {
                DB::update('service_catalog', ['default_price' => round($price, 2), 'updated_at' => now()], 'id = ?', [$serviceId]);
            }
        });
        ActivityLog::record('Service price changed', 'billing', (string) $serviceId, 'New ' . $list . ' price ' . money($price) . ' from ' . fmt_date($from));
    }

    /**
     * Consultation fee for a doctor and visit type: the doctor's own fee for
     * new consultations, else the visit type's service price. A follow-up
     * inside the free window costs nothing.
     * @return array{amount: float, service_id: ?int, label: string, free_follow_up: bool}
     */
    public static function consultationFee(int $doctorId, ?int $visitTypeId, ?int $patientId = null): array
    {
        $vt = $visitTypeId ? DB::one('SELECT * FROM visit_types WHERE id = ?', [$visitTypeId]) : null;
        $doctor = DB::one('SELECT u.name, dp.default_fee FROM users u LEFT JOIN doctor_profiles dp ON dp.user_id = u.id WHERE u.id = ?', [$doctorId]);
        $label = 'Consultation — ' . ($doctor['name'] ?? 'Doctor') . ($vt ? ' (' . $vt['name'] . ')' : '');
        if ($vt && $vt['follow_up_window_days'] && $patientId) {
            $last = DB::value('SELECT MAX(visit_date) FROM visits WHERE patient_id = ? AND doctor_id = ? AND deleted_at IS NULL', [$patientId, $doctorId]);
            if ($last && strtotime((string) $last) >= strtotime('-' . (int) $vt['follow_up_window_days'] . ' days')) {
                return ['amount' => 0.0, 'service_id' => $vt['default_service_id'] ? (int) $vt['default_service_id'] : null, 'label' => $label . ' — free follow-up', 'free_follow_up' => true];
            }
        }
        $serviceId = $vt && $vt['default_service_id'] ? (int) $vt['default_service_id'] : null;
        $isNew = !$vt || in_array((string) $vt['code'], ['new_opd', 'dental', 'antenatal'], true);
        if ($isNew && $doctor && (float) $doctor['default_fee'] > 0) {
            return ['amount' => (float) $doctor['default_fee'], 'service_id' => $serviceId, 'label' => $label, 'free_follow_up' => false];
        }
        return ['amount' => $serviceId ? self::price($serviceId) : (float) ($doctor['default_fee'] ?? 0), 'service_id' => $serviceId, 'label' => $label, 'free_follow_up' => false];
    }

    // -------------------------------------------------------- cash sessions

    /** The user's open cash session (opened automatically on first payment). */
    public static function sessionId(): ?int
    {
        $uid = Auth::id();
        if (!$uid) {
            return null;
        }
        $id = DB::value("SELECT id FROM cash_sessions WHERE user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1", [$uid]);
        if ($id) {
            return (int) $id;
        }
        return DB::insert('cash_sessions', ['branch_id' => Auth::branchId(), 'user_id' => $uid, 'opened_at' => now(), 'opening_float' => 0, 'status' => 'open']);
    }

    /** @return array{cash_in: float, cash_out: float, expected: float, by_mode: array} */
    public static function sessionTotals(int $sessionId): array
    {
        $s = DB::one('SELECT * FROM cash_sessions WHERE id = ?', [$sessionId]);
        $byMode = DB::pairs('SELECT mode, SUM(amount) FROM payments WHERE cash_session_id = ? AND is_void = 0 GROUP BY mode', [$sessionId]);
        $cashIn = (float) ($byMode['cash'] ?? 0);
        $cashOut = (float) DB::value("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE cash_session_id = ? AND status = 'processed' AND (mode = 'cash' OR mode IS NULL)", [$sessionId]);
        return ['cash_in' => $cashIn, 'cash_out' => $cashOut, 'expected' => round((float) $s['opening_float'] + $cashIn - $cashOut, 2), 'by_mode' => $byMode];
    }

    public static function closeSession(int $sessionId, float $counted, string $notes = ''): void
    {
        $s = DB::one("SELECT * FROM cash_sessions WHERE id = ? AND status = 'open'", [$sessionId]);
        if (!$s) {
            throw new \RuntimeException('Cash session not found or already closed.');
        }
        if ((int) $s['user_id'] !== Auth::id() && !Auth::can('billing.refund.approve')) {
            throw new \RuntimeException('Only the cashier or a supervisor can close this session.');
        }
        $t = self::sessionTotals($sessionId);
        DB::update('cash_sessions', ['closed_at' => now(), 'expected_cash' => $t['expected'], 'counted_cash' => round($counted, 2),
            'variance' => round($counted - $t['expected'], 2), 'notes' => mb_substr($notes, 0, 500), 'status' => 'closed'], 'id = ?', [$sessionId]);
        ActivityLog::record('Cash session closed', 'billing', (string) $sessionId, 'Expected ' . money($t['expected']) . ', counted ' . money($counted)
            . ', variance ' . money($counted - $t['expected']));
    }

    // ------------------------------------------------------------ helpers

    /** The most recent open (or partial) invoice of a visit / token, used to append charges. */
    public static function openInvoiceFor(?int $visitId, ?int $tokenId, ?int $patientId, string $type = 'opd'): ?int
    {
        if ($visitId) {
            $id = DB::value("SELECT id FROM invoices WHERE visit_id = ? AND status <> 'void' AND invoice_type = ? ORDER BY id DESC LIMIT 1", [$visitId, $type]);
            if ($id) {
                return (int) $id;
            }
        }
        if ($tokenId) {
            $id = DB::value("SELECT id FROM invoices WHERE token_id = ? AND status <> 'void' ORDER BY id DESC LIMIT 1", [$tokenId]);
            if ($id) {
                return (int) $id;
            }
        }
        return null;
    }

    /** Has this source record already been billed (and not voided)? */
    public static function billedItem(string $refType, int $refId): ?array
    {
        return DB::one(
            'SELECT ii.* FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id
              WHERE ii.source_reference_type = ? AND ii.source_reference_id = ? AND ii.is_void = 0 AND i.status <> \'void\' ORDER BY ii.id DESC LIMIT 1',
            [$refType, $refId]
        );
    }

    /**
     * Charges a department service on the patient's open invoice for the visit
     * (or a new invoice) — used by lab, radiology, dental and procedures.
     * @return array{invoice_id:int, item_id:int}
     */
    public static function charge(array $ctx, array $item): array
    {
        return DB::transaction(static function () use ($ctx, $item): array {
            $invoiceId = self::openInvoiceFor($ctx['visit_id'] ?? null, $ctx['token_id'] ?? null, $ctx['patient_id'] ?? null, $ctx['invoice_type'] ?? 'opd');
            if ($invoiceId && in_array(DB::value('SELECT status FROM invoices WHERE id = ?', [$invoiceId]), ['settled', 'refunded'], true) && !empty($ctx['new_if_settled'])) {
                $invoiceId = null;
            }
            if (!$invoiceId) {
                $invoiceId = self::create([
                    'patient_id' => $ctx['patient_id'] ?? null, 'visit_id' => $ctx['visit_id'] ?? null, 'token_id' => $ctx['token_id'] ?? null,
                    'doctor_id' => $ctx['doctor_id'] ?? null, 'department_id' => $ctx['department_id'] ?? null,
                    'invoice_type' => $ctx['invoice_type'] ?? 'opd',
                ]);
            }
            $itemId = self::addItem($invoiceId, $item);
            return ['invoice_id' => $invoiceId, 'item_id' => $itemId];
        });
    }
}
