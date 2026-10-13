<?php
declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Scope;

/**
 * Role-specific dashboards (BRD §14, §15, §78). Every figure respects
 * Scope: a doctor sees own work, a department admin sees own departments.
 */
final class DashboardController extends Controller
{
    public function index(): void
    {
        $user = Auth::user();
        if ($user['role'] === 'doctor') {
            $this->doctor($user);
            return;
        }
        if ($user['role'] === 'staff') {
            $this->staff($user);
            return;
        }
        $this->admin($user);
    }

    /**
     * Staff dashboard (front desk, billing, pharmacy, lab, radiology, store,
     * nursing …): the user's workspaces, the work waiting for them and the
     * department figures they are allowed to see.
     */
    private function staff(array $user): void
    {
        $today = today();
        $kpis = [];
        $add = static function (string $perm, string $label, string $icon, string $sql, array $params = [], bool $money = false) use (&$kpis): void {
            if (!Auth::can($perm)) {
                return;
            }
            try {
                $kpis[] = ['label' => $label, 'icon' => $icon, 'value' => (float) DB::value($sql, $params), 'money' => $money];
            } catch (\PDOException $e) {
                // module not installed
            }
        };
        $d0 = $today . ' 00:00:00';
        $add('frontdesk.register', 'Tokens issued today', 'list', 'SELECT COUNT(*) FROM tokens WHERE issue_date = ?', [$today]);
        $add('frontdesk.register', 'Waiting now', 'clock', "SELECT COUNT(*) FROM tokens WHERE issue_date = ? AND status = 'waiting'", [$today]);
        $add('billing.payment.collect', 'Collected today', 'money', 'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE is_void = 0 AND collected_at >= ?', [$d0], true);
        $add('billing.payment.collect', 'Open balances', 'receipt', "SELECT COUNT(*) FROM invoices WHERE status IN ('open','partial')");
        $add('vitals.record', 'Vitals recorded today', 'activity', 'SELECT COUNT(*) FROM vitals WHERE recorded_at >= ?', [$d0]);
        $add('pharmacy.view', 'Dispensed today', 'pill', "SELECT COUNT(*) FROM dispenses WHERE status = 'dispensed' AND dispensed_at >= ?", [$d0]);
        $add('pharmacy.counter_sale', 'Counter sales today', 'cart', 'SELECT COALESCE(SUM(total_amount), 0) FROM pharmacy_sales WHERE sold_at >= ?', [$d0], true);
        $add('lab.view', 'Lab tests ordered today', 'flask', 'SELECT COUNT(*) FROM lab_order_items li JOIN lab_orders lo ON lo.id = li.lab_order_id WHERE lo.ordered_at >= ?', [$d0]);
        $add('lab.view', 'Reports verified today', 'check', "SELECT COUNT(*) FROM lab_order_items WHERE status IN ('verified','released') AND verified_at >= ?", [$d0]);
        $add('radiology.view', 'Imaging studies today', 'image', 'SELECT COUNT(*) FROM radiology_orders WHERE ordered_at >= ?', [$d0]);
        $add('inventory.view', 'Items below reorder level', 'alert', 'SELECT COUNT(*) FROM item_location_rules r WHERE r.min_qty > 0 AND COALESCE((SELECT SUM(b.qty_on_hand) FROM stock_balances b WHERE b.item_id = r.item_id AND b.location_id = r.location_id), 0) < r.min_qty');
        $add('inventory.view', 'Batches expiring in 90 days', 'clock', "SELECT COUNT(*) FROM stock_batches WHERE status = 'active' AND expiry_date IS NOT NULL AND expiry_date <= ?", [date('Y-m-d', strtotime('+90 days'))]);
        $add('dental.chart.view', 'Open dental treatment plans', 'tooth', "SELECT COUNT(*) FROM dental_treatment_plans WHERE status IN ('proposed','accepted','in_progress')");
        $add('obgyn.history.view', 'Ongoing pregnancies', 'heart', "SELECT COUNT(*) FROM pregnancies WHERE status = 'ongoing'");
        $this->view('dashboard/staff', [
            'title'      => 'Dashboard',
            'user'       => $user,
            'workspaces' => Auth::workspaces(),
            'tasks'      => \App\Core\Notifications::forUser(),
            'kpis'       => $kpis,
        ]);
    }

    /** Doctor dashboard: same layout as the admin dashboard, scoped to the doctor's own work. */
    private function doctor(array $user): void
    {
        $today = today();
        $id = (int) $user['id'];
        $todayRow = DB::one(
            "SELECT COUNT(*) AS visits, COUNT(DISTINCT patient_id) AS patients,
                    COALESCE(SUM(consultation_fee), 0) AS fee, COALESCE(SUM(grand_total), 0) AS grand
               FROM visits WHERE deleted_at IS NULL AND doctor_id = ? AND visit_date >= ?",
            [$id, $today . ' 00:00:00']
        );
        $monthStart = date('Y-m-01');
        $lastStart = date('Y-m-01', strtotime('first day of last month'));
        $monthRow = DB::one(
            'SELECT COUNT(*) AS visits, COALESCE(SUM(consultation_fee), 0) AS fee, COALESCE(SUM(grand_total), 0) AS grand
               FROM visits WHERE deleted_at IS NULL AND doctor_id = ? AND visit_date >= ?',
            [$id, $monthStart . ' 00:00:00']
        );
        $lastMonth = (int) DB::value('SELECT COUNT(*) FROM visits WHERE deleted_at IS NULL AND doctor_id = ? AND visit_date >= ? AND visit_date < ?',
            [$id, $lastStart . ' 00:00:00', $monthStart . ' 00:00:00']);
        $followDue = (int) DB::value(
            'SELECT COUNT(*) FROM visits v JOIN patients p ON p.id = v.patient_id
              WHERE v.deleted_at IS NULL AND p.deleted_at IS NULL AND v.doctor_id = ? AND v.follow_up_date = ?',
            [$id, $today]
        );
        $pending = DB::one("SELECT COUNT(*) AS n, COALESCE(SUM(consultation_fee), 0) AS amount FROM visits
                             WHERE deleted_at IS NULL AND doctor_id = ? AND payment_status = 'Pending'", [$id]);

        // Visits per month: this year vs last year (grouped bars).
        $thisYear = (int) date('Y');
        $series = [];
        foreach ([$thisYear, $thisYear - 1] as $year) {
            $rows = DB::pairs(
                'SELECT MONTH(visit_date) AS m, COUNT(*) FROM visits WHERE deleted_at IS NULL AND doctor_id = ? AND visit_date >= ? AND visit_date < ? GROUP BY MONTH(visit_date)',
                [$id, $year . '-01-01 00:00:00', ($year + 1) . '-01-01 00:00:00']
            );
            $data = [];
            for ($m = 1; $m <= 12; $m++) {
                $data[] = (int) ($rows[$m] ?? 0);
            }
            $series[] = ['name' => (string) $year, 'data' => $data, 'total' => array_sum($data)];
        }

        $params = [$id];
        $scope = ' AND v.doctor_id = ?';
        $ninety = date('Y-m-d', strtotime('-90 days')) . ' 00:00:00';
        $this->view('dashboard/doctor', [
            'title'        => 'Dashboard',
            'user'         => $user,
            'today'        => $todayRow,
            'month'        => $monthRow,
            'lastMonth'    => $lastMonth,
            'followDue'    => $followDue,
            'pending'      => $pending,
            'years'        => $series,
            'visitTrend'   => $this->dailyVisits(30, $scope, $params),
            'topDiagnoses' => DB::all(
                "SELECT i.name, COUNT(*) AS total FROM visit_clinical_items i JOIN visits v ON v.id = i.visit_id
                  WHERE i.item_type = 'diagnosis' AND v.deleted_at IS NULL AND v.visit_date >= ? AND v.doctor_id = ?
                  GROUP BY i.name ORDER BY total DESC LIMIT 8",
                [$ninety, $id]
            ),
            'topMedicines' => DB::all(
                'SELECT m.medicine_name AS name, COUNT(*) AS total FROM visit_medicines m JOIN visits v ON v.id = m.visit_id
                  WHERE v.deleted_at IS NULL AND v.visit_date >= ? AND v.doctor_id = ?
                  GROUP BY m.medicine_name ORDER BY total DESC LIMIT 8',
                [$ninety, $id]
            ),
            'recentVisits' => $this->recentVisits($scope, $params, 7),
            'followUps'    => DB::all(
                'SELECT v.id, v.follow_up_date, p.id AS patient_id, p.name, p.mrn, p.phone
                   FROM visits v JOIN patients p ON p.id = v.patient_id
                  WHERE v.deleted_at IS NULL AND p.deleted_at IS NULL AND v.doctor_id = ? AND v.follow_up_date BETWEEN ? AND ?
                  ORDER BY v.follow_up_date, p.name LIMIT 7',
                [$id, $today, date('Y-m-d', strtotime('+7 days'))]
            ),
        ]);
    }

    private function admin(array $user): void
    {
        $today = today();
        $monthStart = date('Y-m-01');

        $params = [];
        $scope = Scope::sql('v.doctor_id', $params);
        $pScopeParams = [];
        $pScope = Scope::sql('doctor_id', $pScopeParams);

        $todayRow = DB::one(
            'SELECT COUNT(*) AS visits, COALESCE(SUM(v.consultation_fee), 0) AS fee, COALESCE(SUM(v.grand_total), 0) AS grand
               FROM visits v WHERE v.deleted_at IS NULL AND v.visit_date >= ?' . $scope,
            array_merge([$today . ' 00:00:00'], $params)
        );
        $monthRow = DB::one(
            'SELECT COUNT(*) AS visits, COALESCE(SUM(v.consultation_fee), 0) AS fee, COALESCE(SUM(v.grand_total), 0) AS grand
               FROM visits v WHERE v.deleted_at IS NULL AND v.visit_date >= ?' . $scope,
            array_merge([$monthStart . ' 00:00:00'], $params)
        );

        $stats = [
            'users'         => (int) DB::value('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL'),
            'doctors'       => (int) DB::value("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND role = 'doctor'"),
            'patients'      => (int) DB::value('SELECT COUNT(*) FROM patients WHERE deleted_at IS NULL' . $pScope, $pScopeParams),
            'today_visits'  => (int) $todayRow['visits'],
            'today_fee'     => (float) $todayRow['fee'],
            'today_grand'   => (float) $todayRow['grand'],
            'month_visits'  => (int) $monthRow['visits'],
            'month_fee'     => (float) $monthRow['fee'],
            'month_grand'   => (float) $monthRow['grand'],
        ];

        // Monthly series (last 12 months)
        $from = date('Y-m-01', strtotime('-11 months'));
        $monthly = DB::all(
            "SELECT DATE_FORMAT(v.visit_date, '%Y-%m') AS ym, COUNT(*) AS visits,
                    COALESCE(SUM(v.consultation_fee), 0) AS fee, COALESCE(SUM(v.medicine_net), 0) AS med
               FROM visits v WHERE v.deleted_at IS NULL AND v.visit_date >= ?" . $scope . "
              GROUP BY DATE_FORMAT(v.visit_date, '%Y-%m')",
            array_merge([$from . ' 00:00:00'], $params)
        );
        $registrations = DB::pairs(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) FROM patients
              WHERE deleted_at IS NULL AND created_at >= ?" . $pScope . " GROUP BY DATE_FORMAT(created_at, '%Y-%m')",
            array_merge([$from . ' 00:00:00'], $pScopeParams)
        );
        $byMonth = [];
        foreach ($monthly as $m) {
            $byMonth[$m['ym']] = $m;
        }
        $months = ['labels' => [], 'fee' => [], 'med' => [], 'reg' => []];
        for ($i = 11; $i >= 0; $i--) {
            $ts = strtotime(date('Y-m-01') . " -$i months");
            $key = date('Y-m', $ts);
            $months['labels'][] = date('M y', $ts);
            $months['fee'][] = round((float) ($byMonth[$key]['fee'] ?? 0), 2);
            $months['med'][] = round((float) ($byMonth[$key]['med'] ?? 0), 2);
            $months['reg'][] = (int) ($registrations[$key] ?? 0);
        }

        $ninety = date('Y-m-d', strtotime('-90 days')) . ' 00:00:00';
        $topDiagnoses = DB::all(
            "SELECT i.name, COUNT(*) AS total FROM visit_clinical_items i JOIN visits v ON v.id = i.visit_id
              WHERE i.item_type = 'diagnosis' AND v.deleted_at IS NULL AND v.visit_date >= ?" . $scope . '
              GROUP BY i.name ORDER BY total DESC LIMIT 8',
            array_merge([$ninety], $params)
        );
        $topMedicines = DB::all(
            'SELECT m.medicine_name AS name, COUNT(*) AS total FROM visit_medicines m JOIN visits v ON v.id = m.visit_id
              WHERE v.deleted_at IS NULL AND v.visit_date >= ?' . $scope . '
              GROUP BY m.medicine_name ORDER BY total DESC LIMIT 8',
            array_merge([$ninety], $params)
        );

        $this->view('dashboard/admin', [
            'title'          => 'Dashboard',
            'user'           => $user,
            'stats'          => $stats,
            'visitTrend'     => $this->dailyVisits(30, $scope, $params),
            'months'         => $months,
            'topDiagnoses'   => $topDiagnoses,
            'topMedicines'   => $topMedicines,
            'recentVisits'   => $this->recentVisits($scope, $params, 7),
            'recentPatients' => DB::all('SELECT id, mrn, name, gender, dob, created_at FROM patients WHERE deleted_at IS NULL' . $pScope . ' ORDER BY id DESC LIMIT 7', $pScopeParams),
            'activity'       => can('activity.view')
                ? DB::all('SELECT action, description, username, created_at FROM activity_logs ORDER BY id DESC LIMIT 8')
                : [],
        ]);
    }

    /** @return array{labels: string[], data: int[]} */
    private function dailyVisits(int $days, string $scope, array $params): array
    {
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $rows = DB::pairs(
            'SELECT DATE(v.visit_date) AS d, COUNT(*) FROM visits v WHERE v.deleted_at IS NULL AND v.visit_date >= ?' . $scope . ' GROUP BY DATE(v.visit_date)',
            array_merge([$from . ' 00:00:00'], $params)
        );
        $out = ['labels' => [], 'data' => []];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $out['labels'][] = date('d M', strtotime($d));
            $out['data'][] = (int) ($rows[$d] ?? 0);
        }
        return $out;
    }

    private function recentVisits(string $scope, array $params, int $limit): array
    {
        return DB::all(
            'SELECT v.id, v.visit_no, v.visit_date, v.grand_total, v.payment_status, p.name, p.mrn, u.name AS doctor_name
               FROM visits v JOIN patients p ON p.id = v.patient_id JOIN users u ON u.id = v.doctor_id
              WHERE v.deleted_at IS NULL' . $scope . ' ORDER BY v.visit_date DESC, v.id DESC LIMIT ' . $limit,
            $params
        );
    }
}
