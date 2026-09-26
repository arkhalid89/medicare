<?php
declare(strict_types=1);

namespace App\Modules\Reports;

use App\Core\Controller;
use App\Core\DB;
use App\Core\Paginator;
use App\Core\Scope;
use App\Modules\Visits\VisitService;

/**
 * Patient Visit Report (BRD §59) and Fee Reports (§60): day-wise,
 * patient-wise and summary. Scoped by role; every report exports to Excel.
 */
final class ReportController extends Controller
{
    public function visits(): void
    {
        [$where, $params, $f] = $this->filters(true);
        $base = ' FROM visits v JOIN patients p ON p.id = v.patient_id JOIN users u ON u.id = v.doctor_id WHERE ' . $where;
        $items = static fn (string $type): string => "(SELECT GROUP_CONCAT(i.name ORDER BY i.sort_order SEPARATOR ', ') FROM visit_clinical_items i WHERE i.visit_id = v.id AND i.item_type = '$type')";
        $select = 'SELECT v.id, v.visit_no, v.visit_date, v.consultation_fee, v.payment_status, v.medicine_net, v.grand_total, v.follow_up_date,
                          p.id AS patient_id, p.name, p.mrn, p.gender, p.dob, p.cnic, p.phone, u.name AS doctor_name, '
            . $items('complaint') . ' AS complaints, ' . $items('symptom') . ' AS symptoms, '
            . $items('diagnosis') . ' AS diagnoses, ' . $items('investigation') . ' AS investigations,
                          (SELECT GROUP_CONCAT(m.medicine_name ORDER BY m.sort_order SEPARATOR \', \') FROM visit_medicines m WHERE m.visit_id = v.id) AS medicines';
        $order = ' ORDER BY v.visit_date DESC, v.id DESC';

        if ($this->wantsExport()) {
            $this->export('patient_visit_report', 'Patient Visit Report', [
                'visit_date'       => ['Visit Date', static fn ($r) => fmt_datetime($r['visit_date'])],
                'visit_no'         => 'Visit No',
                'name'             => 'Patient',
                'mrn'              => 'MRN',
                'gender'           => 'Gender',
                'dob'              => ['Age', static fn ($r) => age_text($r['dob'], $r['visit_date'])],
                'cnic'             => 'CNIC',
                'phone'            => 'Phone',
                'doctor_name'      => 'Doctor',
                'complaints'       => 'Presenting Complaints',
                'symptoms'         => 'Symptoms',
                'diagnoses'        => 'Diagnosis',
                'investigations'   => 'Investigations',
                'medicines'        => 'Medicines',
                'consultation_fee' => ['Fee', static fn ($r) => (float) $r['consultation_fee']],
                'payment_status'   => 'Payment',
                'medicine_net'     => ['Medicine Cost', static fn ($r) => (float) $r['medicine_net']],
                'grand_total'      => ['Grand Total', static fn ($r) => (float) $r['grand_total']],
                'follow_up_date'   => ['Follow-up', static fn ($r) => fmt_date($r['follow_up_date'])],
            ], DB::run($select . $base . $order, $params), $f['labels']);
            return;
        }

        $pager = new Paginator((int) DB::value('SELECT COUNT(*)' . $base, $params));
        $this->view('reports/visits', [
            'title'   => 'Patient Visit Report',
            'rows'    => DB::all($select . $base . $order . $pager->limitSql(), $params),
            'pager'   => $pager,
            'summary' => $this->summary($base, $params),
            'f'       => $f,
            'doctors' => Scope::doctorOptions(),
        ]);
    }

    public function fees(): void
    {
        $mode = input('mode') === 'patient' ? 'patient' : 'day';
        [$where, $params, $f] = $this->filters(false);
        $base = ' FROM visits v JOIN patients p ON p.id = v.patient_id JOIN users u ON u.id = v.doctor_id WHERE ' . $where;
        $select = 'SELECT v.id, v.visit_no, v.visit_date, DATE(v.visit_date) AS day, v.consultation_fee, v.payment_status, v.medicines_total, v.discount_amount,
                          v.medicine_net, v.grand_total, p.id AS patient_id, p.name, p.mrn, u.name AS doctor_name';
        $order = $mode === 'day' ? ' ORDER BY v.visit_date DESC, v.id DESC' : ' ORDER BY p.name, p.id, v.visit_date DESC';

        if ($this->wantsExport()) {
            $columns = $mode === 'day'
                ? ['day' => ['Date', static fn ($r) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r['day']) ? fmt_date($r['day']) : $r['day']], 'name' => 'Patient', 'mrn' => 'MRN', 'visit_no' => 'Visit']
                : ['name' => 'Patient', 'mrn' => 'MRN', 'visit_date' => ['Visit Date', static fn ($r) => fmt_date($r['visit_date'])], 'visit_no' => 'Visit'];
            $amount = static fn (string $k): \Closure => static fn ($r) => $r[$k] === '' || $r[$k] === null ? '' : (float) $r[$k];
            $columns += [
                'doctor_name'      => 'Doctor',
                'consultation_fee' => ['Consultation Fee', $amount('consultation_fee')],
                'payment_status'   => 'Payment Status',
                'medicine_net'     => ['Medicine Cost', $amount('medicine_net')],
                'grand_total'      => ['Total', $amount('grand_total')],
            ];
            $title = $mode === 'day' ? 'Fee Report — Day-wise' : 'Fee Report — Patient-wise';
            $this->export('fee_report_' . $mode, $title, $columns, $this->withSummaryRow(DB::run($select . $base . $order, $params), $this->summary($base, $params), $mode), $f['labels']);
            return;
        }

        $pager = new Paginator((int) DB::value('SELECT COUNT(*)' . $base, $params));
        $rows = DB::all($select . $base . $order . $pager->limitSql(), $params);

        // Subtotals for the groups visible on this page (whole group, not just this page).
        $subtotals = [];
        if ($rows) {
            if ($mode === 'day') {
                $days = array_values(array_unique(array_column($rows, 'day')));
                $sub = DB::all(
                    'SELECT DATE(v.visit_date) AS k, COUNT(*) AS visits, SUM(v.consultation_fee) AS fee, SUM(v.medicine_net) AS med, SUM(v.grand_total) AS grand' . $base
                    . ' AND DATE(v.visit_date) IN (' . DB::placeholders($days) . ') GROUP BY DATE(v.visit_date)',
                    array_merge($params, $days)
                );
            } else {
                $ids = array_values(array_unique(array_map('intval', array_column($rows, 'patient_id'))));
                $sub = DB::all(
                    'SELECT p.id AS k, COUNT(*) AS visits, SUM(v.consultation_fee) AS fee, SUM(v.medicine_net) AS med, SUM(v.grand_total) AS grand' . $base
                    . ' AND p.id IN (' . DB::placeholders($ids) . ') GROUP BY p.id',
                    array_merge($params, $ids)
                );
            }
            foreach ($sub as $s) {
                $subtotals[(string) $s['k']] = $s;
            }
        }

        $this->view('reports/fees', [
            'title'     => 'Fee Report',
            'mode'      => $mode,
            'rows'      => $rows,
            'subtotals' => $subtotals,
            'pager'     => $pager,
            'summary'   => $this->summary($base, $params),
            'f'         => $f,
            'doctors'   => Scope::doctorOptions(),
        ]);
    }

    // ------------------------------------------------------------------

    /**
     * Shared filters. Dates default to the current month.
     * @return array{0:string, 1:array, 2:array}
     */
    private function filters(bool $clinical): array
    {
        $from = (string) input('from', date('Y-m-01'));
        $to = (string) input('to', today());
        $from = strtotime($from) ? date('Y-m-d', strtotime($from)) : date('Y-m-01');
        $to = strtotime($to) ? date('Y-m-d', strtotime($to)) : today();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $f = [
            'from'      => $from,
            'to'        => $to,
            'doctor_id' => (int) input('doctor_id', 0),
            'payment'   => (string) input('payment', ''),
            'name'      => trim((string) input('name', '')),
            'mrn'       => trim((string) input('mrn', '')),
            'cnic'      => trim((string) input('cnic', '')),
            'phone'     => trim((string) input('phone', '')),
            'diagnosis' => trim((string) input('diagnosis', '')),
            'gender'    => (string) input('gender', ''),
        ];

        $where = 'v.deleted_at IS NULL AND p.deleted_at IS NULL AND v.visit_date BETWEEN ? AND ?';
        $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
        $where .= Scope::sql('v.doctor_id', $params);
        if ($f['doctor_id'] > 0) {
            $where .= ' AND v.doctor_id = ?';
            $params[] = $f['doctor_id'];
        }
        if (in_array($f['payment'], VisitService::PAYMENT_STATUSES, true)) {
            $where .= ' AND v.payment_status = ?';
            $params[] = $f['payment'];
        }
        if ($f['name'] !== '') {
            $where .= ' AND p.name LIKE ?';
            $params[] = '%' . like_escape($f['name']) . '%';
        }
        if ($f['mrn'] !== '') {
            $where .= ' AND p.mrn LIKE ?';
            $params[] = like_escape($f['mrn']) . '%';
        }
        if ($clinical) {
            if ($f['cnic'] !== '') {
                $where .= ' AND p.cnic LIKE ?';
                $params[] = like_escape(cnic_prefix($f['cnic'])) . '%';
            }
            if ($f['phone'] !== '') {
                $where .= ' AND p.phone LIKE ?';
                $params[] = preg_replace('/\D/', '', $f['phone']) . '%';
            }
            if (in_array($f['gender'], ['Male', 'Female', 'Other'], true)) {
                $where .= ' AND p.gender = ?';
                $params[] = $f['gender'];
            }
            if ($f['diagnosis'] !== '') {
                $where .= " AND EXISTS (SELECT 1 FROM visit_clinical_items dx WHERE dx.visit_id = v.id AND dx.item_type = 'diagnosis' AND (dx.name LIKE ? OR dx.code LIKE ?))";
                array_push($params, '%' . like_escape($f['diagnosis']) . '%', like_escape($f['diagnosis']) . '%');
            }
        }
        $doctorName = $f['doctor_id'] ? (string) DB::value('SELECT name FROM users WHERE id = ?', [$f['doctor_id']]) : '';
        $f['labels'] = array_filter([
            'Period'    => fmt_date($from) . ' to ' . fmt_date($to),
            'Doctor'    => $doctorName,
            'Payment'   => $f['payment'],
            'Patient'   => $f['name'],
            'MRN'       => $f['mrn'],
            'CNIC'      => $clinical ? $f['cnic'] : '',
            'Phone'     => $clinical ? $f['phone'] : '',
            'Gender'    => $clinical ? $f['gender'] : '',
            'Diagnosis' => $clinical ? $f['diagnosis'] : '',
        ]);
        return [$where, $params, $f];
    }

    private function summary(string $base, array $params): array
    {
        return DB::one(
            "SELECT COUNT(DISTINCT v.patient_id) AS patients, COUNT(*) AS visits,
                    COALESCE(SUM(v.consultation_fee), 0) AS fee,
                    COALESCE(SUM(CASE WHEN v.payment_status = 'Paid' THEN v.consultation_fee ELSE 0 END), 0) AS paid,
                    COALESCE(SUM(CASE WHEN v.payment_status = 'Pending' THEN v.consultation_fee ELSE 0 END), 0) AS pending,
                    COALESCE(SUM(CASE WHEN v.payment_status = 'Free' THEN 1 ELSE 0 END), 0) AS free_visits,
                    COALESCE(SUM(v.medicines_total), 0) AS medicines_total,
                    COALESCE(SUM(v.discount_amount), 0) AS discount,
                    COALESCE(SUM(v.medicine_net), 0) AS medicine_net,
                    COALESCE(SUM(v.grand_total), 0) AS grand" . $base,
            $params
        ) ?? [];
    }

    /** Appends a summary block after the data rows of the fee export. */
    private function withSummaryRow(iterable $rows, array $s, string $mode): \Generator
    {
        foreach ($rows as $r) {
            yield $r;
        }
        $blank = ['day' => '', 'visit_date' => '', 'name' => '', 'mrn' => '', 'visit_no' => '', 'doctor_name' => '', 'consultation_fee' => '', 'payment_status' => '', 'medicine_net' => '', 'grand_total' => ''];
        $label = $mode === 'day' ? 'day' : 'name';
        $lines = [
            ['SUMMARY', ''],
            ['Total Patients', (int) $s['patients']],
            ['Total Visits', (int) $s['visits']],
            ['Total Consultation Fee', (float) $s['fee']],
            ['Paid Fee', (float) $s['paid']],
            ['Pending Fee', (float) $s['pending']],
            ['Total Medicine Cost', (float) $s['medicine_net']],
            ['Grand Total', (float) $s['grand']],
        ];
        foreach ($lines as [$text, $value]) {
            yield array_merge($blank, [$label => $text, 'grand_total' => $value]);
        }
    }
}
