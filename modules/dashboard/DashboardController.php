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
        $this->admin($user);
    }

    private function doctor(array $user): void
    {
        $today = today();
        $p = [$today . ' 00:00:00', $today . ' 23:59:59', $user['id']];
        $todayStats = DB::one(
            "SELECT COUNT(*) AS visits, COUNT(DISTINCT patient_id) AS patients,
                    COALESCE(SUM(consultation_fee), 0) AS fee,
                    COALESCE(SUM(CASE WHEN payment_status = 'Paid' THEN consultation_fee ELSE 0 END), 0) AS paid,
                    COALESCE(SUM(grand_total), 0) AS grand
               FROM visits WHERE deleted_at IS NULL AND visit_date BETWEEN ? AND ? AND doctor_id = ?",
            $p
        );
        $followDue = (int) DB::value(
            'SELECT COUNT(*) FROM visits v JOIN patients pt ON pt.id = v.patient_id
              WHERE v.deleted_at IS NULL AND pt.deleted_at IS NULL AND v.follow_up_date = ? AND v.doctor_id = ?',
            [$today, $user['id']]
        );
        $newPatients = (int) DB::value('SELECT COUNT(*) FROM patients WHERE deleted_at IS NULL AND doctor_id = ? AND created_at >= ?', [$user['id'], $today . ' 00:00:00']);

        $params = [];
        $scope = Scope::sql('v.doctor_id', $params);
        $this->view('dashboard/doctor', [
            'title'        => 'Dashboard',
            'user'         => $user,
            'stats'        => $todayStats,
            'followDue'    => $followDue,
            'newPatients'  => $newPatients,
            'visitTrend'   => $this->dailyVisits(14, $scope, $params),
            'recentVisits' => $this->recentVisits($scope, $params, 8),
            'followUps'    => DB::all(
                'SELECT v.id, v.follow_up_date, v.follow_up_instructions, p.id AS patient_id, p.name, p.mrn, p.phone
                   FROM visits v JOIN patients p ON p.id = v.patient_id
                  WHERE v.deleted_at IS NULL AND p.deleted_at IS NULL AND v.doctor_id = ? AND v.follow_up_date BETWEEN ? AND ?
                  ORDER BY v.follow_up_date, p.name LIMIT 8',
                [$user['id'], $today, date('Y-m-d', strtotime('+7 days'))]
            ),
            'templates'    => DB::all('SELECT id, name, usage_count FROM templates WHERE doctor_id = ? AND deleted_at IS NULL AND is_favourite = 1 ORDER BY usage_count DESC, name LIMIT 6', [$user['id']]),
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
