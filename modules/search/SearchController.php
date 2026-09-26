<?php
declare(strict_types=1);

namespace App\Modules\Search;

use App\Core\Controller;
use App\Core\DB;
use App\Core\Scope;
use App\Modules\Patients\PatientService;

/**
 * Global header search (BRD §62): patients by MRN / name / CNIC / phone and
 * visits / prescriptions by visit number. Visit results respect Scope.
 */
final class SearchController extends Controller
{
    public function index(): void
    {
        $q = trim((string) input('q', ''));
        [$patients, $visits] = $q !== '' ? $this->find($q, 50) : [[], []];
        $this->view('search/index', ['title' => 'Search', 'q' => $q, 'patients' => $patients, 'visits' => $visits]);
    }

    public function quick(): void
    {
        $q = trim((string) input('q', ''));
        [$patients, $visits] = $this->find($q, 6);
        $items = [];
        foreach ($visits as $v) {
            $items[] = ['type' => 'Visit', 'title' => $v['visit_no'] . ' — ' . $v['name'], 'subtitle' => fmt_datetime($v['visit_date']) . ' · ' . $v['doctor_name'], 'url' => url('visits/view', ['id' => $v['id']])];
        }
        foreach ($patients as $p) {
            $items[] = ['type' => 'Patient', 'title' => $p['name'], 'subtitle' => implode(' · ', array_filter([$p['mrn'], $p['gender'] . ' ' . $p['age'], $p['cnic'], $p['phone']])), 'url' => url('patients/view', ['id' => $p['id']])];
        }
        $this->json(['ok' => true, 'items' => $items]);
    }

    /** @return array{0: array, 1: array} */
    private function find(string $q, int $limit): array
    {
        if (mb_strlen($q) < 2) {
            return [[], []];
        }
        $patients = can('patients.view') ? PatientService::search($q, $limit) : [];
        $visits = [];
        if (can('visits.view')) {
            $params = [like_escape($q) . '%'];
            $scope = Scope::sql('v.doctor_id', $params);
            $visits = DB::all(
                'SELECT v.id, v.visit_no, v.visit_date, v.grand_total, p.name, p.mrn, u.name AS doctor_name
                   FROM visits v JOIN patients p ON p.id = v.patient_id JOIN users u ON u.id = v.doctor_id
                  WHERE v.deleted_at IS NULL AND p.deleted_at IS NULL AND v.visit_no LIKE ?' . $scope . '
                  ORDER BY v.id DESC LIMIT ' . $limit,
                $params
            );
        }
        return [$patients, $visits];
    }
}
