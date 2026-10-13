<?php
declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Paginator;
use App\Core\Scope;

/** Billing counter, invoices, approvals, cash sessions and the service catalog. */
final class BillingController extends Controller
{
    public function counter(): void
    {
        $q = trim((string) input('q', ''));
        $found = [];
        if ($q !== '') {
            $like = like_escape($q) . '%';
            $found = DB::all(
                "SELECT i.*, p.name AS patient_name, p.mrn FROM invoices i LEFT JOIN patients p ON p.id = i.patient_id
                  WHERE i.invoice_no LIKE ? OR p.mrn LIKE ? OR p.name LIKE ? OR p.phone LIKE ? OR i.customer_name LIKE ?
                  ORDER BY i.id DESC LIMIT 30",
                [$like, $like, '%' . like_escape($q) . '%', $like, '%' . like_escape($q) . '%']
            );
        }
        $session = BillingService::sessionId() ? DB::one("SELECT * FROM cash_sessions WHERE user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1", [Auth::id()]) : null;
        $this->view('billing/counter', [
            'title'    => 'Billing Counter',
            'q'        => $q,
            'found'    => $found,
            'open'     => DB::all(
                "SELECT i.*, p.name AS patient_name, p.mrn FROM invoices i LEFT JOIN patients p ON p.id = i.patient_id
                  WHERE i.status IN ('open','partial') AND i.created_at >= ? ORDER BY i.id DESC LIMIT 50",
                [date('Y-m-d', strtotime('-7 days')) . ' 00:00:00']
            ),
            'refunds'  => can('billing.refund.process') ? DB::all(
                "SELECT r.*, i.invoice_no, p.name AS patient_name FROM refunds r JOIN invoices i ON i.id = r.invoice_id LEFT JOIN patients p ON p.id = i.patient_id
                  WHERE r.status = 'approved' ORDER BY r.id"
            ) : [],
            'session'  => $session,
            'totals'   => $session ? BillingService::sessionTotals((int) $session['id']) : null,
        ]);
    }

    public function invoices(): void
    {
        $where = '1 = 1';
        $params = [];
        $q = trim((string) input('q', ''));
        $status = (string) input('status', '');
        $type = (string) input('type', '');
        $from = (string) input('from', today());
        $to = (string) input('to', today());
        if ($q !== '') {
            $where .= ' AND (i.invoice_no LIKE ? OR p.mrn LIKE ? OR p.name LIKE ? OR i.customer_name LIKE ?)';
            $like = '%' . like_escape($q) . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($status !== '') {
            $where .= ' AND i.status = ?';
            $params[] = $status;
        }
        if (isset(hospital('invoice_types')[$type])) {
            $where .= ' AND i.invoice_type = ?';
            $params[] = $type;
        }
        if ($from !== '' && strtotime($from)) {
            $where .= ' AND i.created_at >= ?';
            $params[] = date('Y-m-d', strtotime($from)) . ' 00:00:00';
        }
        if ($to !== '' && strtotime($to)) {
            $where .= ' AND i.created_at <= ?';
            $params[] = date('Y-m-d', strtotime($to)) . ' 23:59:59';
        }
        $where .= Scope::branchSql('i.branch_id', $params, 'billing.view');
        $base = ' FROM invoices i LEFT JOIN patients p ON p.id = i.patient_id LEFT JOIN users u ON u.id = i.doctor_id LEFT JOIN users c ON c.id = i.created_by WHERE ' . $where;
        $select = 'SELECT i.*, p.name AS patient_name, p.mrn, u.name AS doctor_name, c.name AS created_by_name';
        if ($this->wantsExport()) {
            $this->export('invoices', 'Invoices', [
                'invoice_no' => 'Invoice', 'created_at' => ['Date', static fn ($r) => fmt_datetime($r['created_at'])],
                'invoice_type' => ['Type', static fn ($r) => strtoupper($r['invoice_type'])], 'mrn' => 'MRN',
                'patient_name' => ['Patient / Customer', static fn ($r) => $r['patient_name'] ?: $r['customer_name']], 'doctor_name' => 'Doctor',
                'gross_amount' => ['Gross', static fn ($r) => (float) $r['gross_amount']], 'discount_amount' => ['Discount', static fn ($r) => (float) $r['discount_amount']],
                'net_amount' => ['Net', static fn ($r) => (float) $r['net_amount']], 'paid_amount' => ['Paid', static fn ($r) => (float) $r['paid_amount']],
                'refunded_amount' => ['Refunded', static fn ($r) => (float) $r['refunded_amount']], 'status' => 'Status', 'created_by_name' => 'Cashier',
            ], DB::run($select . $base . ' ORDER BY i.id DESC', $params), ['Search' => $q, 'Status' => $status, 'From' => $from, 'To' => $to]);
            return;
        }
        $pager = new Paginator((int) DB::value('SELECT COUNT(*)' . $base, $params));
        $totals = DB::one('SELECT COALESCE(SUM(i.net_amount), 0) AS net, COALESCE(SUM(i.paid_amount), 0) AS paid, COALESCE(SUM(i.refunded_amount), 0) AS refunded'
            . $base . " AND i.status <> 'void'", $params);
        $this->view('billing/invoices', ['title' => 'Invoices', 'rows' => DB::all($select . $base . ' ORDER BY i.id DESC' . $pager->limitSql(), $params),
            'pager' => $pager, 'totals' => $totals, 'from' => $from, 'to' => $to]);
    }

    public function show(): void
    {
        $inv = BillingService::find($this->requireId());
        if (!$inv) {
            abort(404, 'Invoice not found.');
        }
        $this->view('billing/invoice', ['title' => 'Invoice ' . $inv['invoice_no'], 'inv' => $inv]);
    }

    public function printout(): void
    {
        $inv = BillingService::find($this->requireId());
        if (!$inv) {
            abort(404);
        }
        DB::run('UPDATE invoices SET print_count = print_count + 1 WHERE id = ?', [$inv['id']]);
        ActivityLog::record($inv['print_count'] ? 'Receipt reprinted' : 'Receipt printed', 'billing', (string) $inv['id'], $inv['invoice_no']);
        $this->view('billing/print', ['inv' => $inv, 'paper' => input('paper', 'thermal') === 'a5' ? 'a5' : 'thermal'], null);
    }

    /** New miscellaneous / walk-in invoice (procedures, services). */
    public function create(): void
    {
        if (is_post()) {
            $patientId = (int) input('patient_id', 0) ?: null;
            $customer = trim((string) input('customer_name', ''));
            $lines = [];
            foreach ((array) ($_POST['items'] ?? []) as $it) {
                $sid = (int) ($it['service_id'] ?? 0);
                $svc = $sid ? DB::one('SELECT * FROM service_catalog WHERE id = ? AND deleted_at IS NULL AND is_active = 1', [$sid]) : null;
                $qty = max(0.001, (float) ($it['quantity'] ?? 1));
                if (!$svc) {
                    continue;
                }
                $price = can('billing.services.manage') && is_numeric($it['unit_price'] ?? null) ? (float) $it['unit_price'] : BillingService::price($sid);
                $lines[] = ['description' => $svc['name'], 'service_id' => $sid, 'quantity' => $qty, 'unit_price' => $price, 'source_module' => 'billing',
                    'source_reference_type' => 'service'];
            }
            if (!$lines || (!$patientId && $customer === '')) {
                flash('error', 'Select the patient (or enter a walk-in customer name) and at least one service.');
                redirect('billing/new');
            }
            $id = BillingService::create(['patient_id' => $patientId, 'customer_name' => $patientId ? null : mb_substr($customer, 0, 150),
                'invoice_type' => 'misc', 'notes' => mb_substr((string) input('notes', ''), 0, 500) ?: null], $lines);
            ActivityLog::record('Invoice created', 'billing', (string) $id, count($lines) . ' service(s)');
            redirect('billing/invoice', ['id' => $id]);
        }
        $this->view('billing/new', ['title' => 'New Invoice', 'patient' => ($pid = (int) input('patient_id', 0)) ? \App\Modules\Patients\PatientService::find($pid) : null]);
    }

    public function serviceSearch(): void
    {
        $q = trim((string) input('q', ''));
        $rows = DB::all('SELECT id, code, name, service_type FROM service_catalog WHERE deleted_at IS NULL AND is_active = 1 AND (name LIKE ? OR code LIKE ?)
                          ORDER BY name LIMIT 20', ['%' . like_escape($q) . '%', like_escape($q) . '%']);
        foreach ($rows as &$r) {
            $r['price'] = BillingService::price((int) $r['id']);
        }
        $this->json(['ok' => true, 'items' => $rows]);
    }

    // ------------------------------------------------------------- actions

    private function act(callable $fn, int $invoiceId, string $success): void
    {
        try {
            $fn();
            flash('success', $success);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('billing/invoice', ['id' => $invoiceId]);
    }

    public function collect(): void
    {
        $id = $this->requireId();
        $amount = (float) input('amount', 0);
        $this->act(static fn () => BillingService::collect($id, $amount, (string) input('mode', 'cash'), (string) input('reference', '') ?: null),
            $id, 'Payment of ' . money($amount) . ' collected.');
    }

    public function discount(): void
    {
        $id = $this->requireId();
        $amount = (float) input('amount', 0);
        $reason = trim((string) input('reason', ''));
        try {
            if ($reason === '') {
                throw new \RuntimeException('A reason is required for a discount.');
            }
            $res = BillingService::applyDiscount($id, $amount, $reason);
            flash($res === 'pending' ? 'warning' : 'success', $res === 'pending'
                ? 'Discount of ' . money($amount) . ' is above your limit and was sent for approval.' : 'Discount applied.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('billing/invoice', ['id' => $id]);
    }

    public function refund(): void
    {
        $id = $this->requireId();
        $reason = trim((string) input('reason', ''));
        $this->act(static function () use ($id, $reason): void {
            if ($reason === '') {
                throw new \RuntimeException('A reason is required for a refund.');
            }
            BillingService::requestRefund($id, (int) input('item_id', 0) ?: null, (float) input('amount', 0), $reason);
        }, $id, 'Refund requested — it needs approval before it can be paid out.');
    }

    public function decideRefund(): void
    {
        $rid = $this->requireId();
        try {
            BillingService::decideRefund($rid, input('decision') === 'approve', (string) input('note', ''));
            flash('success', 'Refund ' . (input('decision') === 'approve' ? 'approved.' : 'rejected.'));
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        back('billing/approvals');
    }

    public function processRefund(): void
    {
        $rid = $this->requireId();
        try {
            BillingService::processRefund($rid, (string) input('mode', 'cash'));
            flash('success', 'Refund paid out and recorded in your cash session.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        back('billing');
    }

    public function decideDiscount(): void
    {
        $id = $this->requireId();
        try {
            BillingService::decideDiscount($id, input('decision') === 'approve');
            flash('success', 'Discount ' . (input('decision') === 'approve' ? 'approved.' : 'rejected.'));
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        back('billing/approvals');
    }

    public function void(): void
    {
        $id = $this->requireId();
        $reason = trim((string) input('reason', ''));
        $this->act(static function () use ($id, $reason): void {
            if ($reason === '') {
                throw new \RuntimeException('A reason is required to void an invoice.');
            }
            BillingService::void($id, $reason);
        }, $id, 'Invoice voided.');
    }

    public function approvals(): void
    {
        $this->view('billing/approvals', [
            'title'     => 'Billing Approvals',
            'refunds'   => DB::all("SELECT r.*, i.invoice_no, p.name AS patient_name, u.name AS initiated_by_name FROM refunds r JOIN invoices i ON i.id = r.invoice_id
                                     LEFT JOIN patients p ON p.id = i.patient_id LEFT JOIN users u ON u.id = r.initiated_by WHERE r.status = 'requested' ORDER BY r.id"),
            'discounts' => DB::all("SELECT i.*, p.name AS patient_name, u.name AS discount_by_name FROM invoices i LEFT JOIN patients p ON p.id = i.patient_id
                                     LEFT JOIN users u ON u.id = i.discount_by WHERE i.discount_status = 'pending' ORDER BY i.id"),
            'recent'    => DB::all("SELECT r.*, i.invoice_no, a.name AS approved_by_name FROM refunds r JOIN invoices i ON i.id = r.invoice_id
                                     LEFT JOIN users a ON a.id = r.approved_by WHERE r.status <> 'requested' ORDER BY r.id DESC LIMIT 20"),
        ]);
    }

    // -------------------------------------------------------- cash sessions

    public function sessions(): void
    {
        $where = can('billing.refund.approve') ? '1 = 1' : 'cs.user_id = ' . (int) Auth::id();
        $rows = DB::all("SELECT cs.*, u.name AS user_name FROM cash_sessions cs JOIN users u ON u.id = cs.user_id WHERE $where ORDER BY cs.id DESC LIMIT 60");
        foreach ($rows as &$r) {
            $r['totals'] = BillingService::sessionTotals((int) $r['id']);
        }
        $this->view('billing/sessions', ['title' => 'Cash Sessions', 'rows' => $rows]);
    }

    public function closeSession(): void
    {
        try {
            BillingService::closeSession($this->requireId(), (float) input('counted', 0), (string) input('notes', ''));
            flash('success', 'Cash session closed.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('billing/sessions');
    }

    // ------------------------------------------------------ service catalog

    public function services(): void
    {
        $q = trim((string) input('q', ''));
        $type = (string) input('type', '');
        $where = 's.deleted_at IS NULL';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (s.name LIKE ? OR s.code LIKE ?)';
            array_push($params, '%' . like_escape($q) . '%', like_escape($q) . '%');
        }
        if (isset(hospital('service_types')[$type])) {
            $where .= ' AND s.service_type = ?';
            $params[] = $type;
        }
        $pager = new Paginator((int) DB::value("SELECT COUNT(*) FROM service_catalog s WHERE $where", $params), 50);
        $rows = DB::all("SELECT s.*, d.name AS department_name FROM service_catalog s LEFT JOIN departments d ON d.id = s.department_id WHERE $where
                          ORDER BY s.service_type, s.display_order, s.name" . $pager->limitSql(), $params);
        foreach ($rows as &$r) {
            $r['current'] = BillingService::price((int) $r['id']);
            $r['prices'] = DB::all('SELECT sp.*, b.name AS branch_name FROM service_prices sp LEFT JOIN branches b ON b.id = sp.branch_id WHERE sp.service_id = ?
                                    ORDER BY sp.valid_from DESC, sp.id DESC LIMIT 6', [$r['id']]);
        }
        if ($this->wantsExport()) {
            $this->export('services', 'Service Catalog', ['code' => 'Code', 'name' => 'Service', 'service_type' => 'Type', 'department_name' => 'Department',
                'current' => ['Current Price', static fn ($r) => (float) $r['current']], 'is_active' => ['Status', static fn ($r) => (int) $r['is_active'] ? 'Active' : 'Inactive']], $rows);
            return;
        }
        $this->view('billing/services', ['title' => 'Services & Prices', 'rows' => $rows, 'pager' => $pager,
            'departments' => DB::pairs('SELECT id, name FROM departments WHERE deleted_at IS NULL ORDER BY name'),
            'branches' => DB::pairs('SELECT id, name FROM branches WHERE deleted_at IS NULL ORDER BY name')]);
    }

    public function saveService(): void
    {
        $id = (int) input('id', 0);
        $name = trim((string) input('name', ''));
        $type = (string) input('service_type', 'procedure');
        $code = strtoupper(trim((string) input('code', '')));
        if ($name === '' || !isset(hospital('service_types')[$type])) {
            flash('error', 'Service name and type are required.');
            redirect('billing/services');
        }
        if ($code !== '' && DB::value('SELECT 1 FROM service_catalog WHERE code = ? AND id <> ? AND deleted_at IS NULL', [$code, $id])) {
            flash('error', 'Another service already uses code ' . $code . '.');
            redirect('billing/services');
        }
        $data = ['code' => $code ?: null, 'name' => mb_substr($name, 0, 150), 'service_type' => $type,
            'department_id' => (int) input('department_id', 0) ?: null, 'revenue_head' => mb_substr((string) input('revenue_head', ''), 0, 60) ?: null,
            'tax_code' => mb_substr((string) input('tax_code', ''), 0, 20) ?: null, 'is_active' => input('is_active') ? 1 : 0];
        if ($id) {
            DB::update('service_catalog', $data + ['updated_at' => now(), 'updated_by' => Auth::id()], 'id = ?', [$id]);
        } else {
            $id = DB::insert('service_catalog', $data + ['default_price' => 0, 'display_order' => 0, 'created_at' => now(), 'created_by' => Auth::id()]);
            BillingService::setPrice($id, (float) input('price', 0), null, 'cash', '2000-01-01');
        }
        ActivityLog::record('Service saved', 'billing', (string) $id, $name);
        flash('success', 'Service "' . $name . '" saved.');
        redirect('billing/services', ['q' => $name]);
    }

    public function savePrice(): void
    {
        $sid = (int) input('service_id', 0);
        if (!DB::value('SELECT 1 FROM service_catalog WHERE id = ?', [$sid]) || !is_numeric(input('price'))) {
            flash('error', 'Enter a valid price.');
            redirect('billing/services');
        }
        $list = in_array(input('price_list_type'), ['cash', 'panel', 'staff'], true) ? (string) input('price_list_type') : 'cash';
        $from = strtotime((string) input('valid_from', '')) ? date('Y-m-d', strtotime((string) input('valid_from'))) : today();
        BillingService::setPrice($sid, (float) input('price'), (int) input('branch_id', 0) ?: null, $list, $from);
        flash('success', 'New price saved; older prices stay on past bills.');
        back('billing/services');
    }
}
