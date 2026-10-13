<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Permanent, date-ranged deletion of selected data (Super Admin only):
 * Database Reset → "Delete by table & date" and Activity Logs → "Clean".
 * Every run is previewed with counts first and preceded by a safety backup
 * (taken by the caller).
 */
final class DataPurger
{
    /** key => [label, description] */
    public const DATASETS = [
        'visits'        => ['Visits & prescriptions', 'Visits whose visit date is in the range, with their complaints, diagnoses and medicines.'],
        'patients'      => ['Patients', 'Patients registered in the range, together with ALL their visits.'],
        'templates'     => ['Favourite templates', 'Templates created in the range.'],
        'activity_logs' => ['Activity logs', 'Log entries written in the range.'],
        'recycle'       => ['Recycle Bin (purge)', 'Records soft-deleted in the range: patients, visits, templates and master data. Users are never purged.'],
    ];

    /** Recycle-bin tables that can be purged (users excluded: history refers to them). */
    private const RECYCLE_TABLES = ['visits', 'patients', 'templates', 'medicines', 'medicine_sources', 'presenting_complaints', 'symptoms',
        'diagnoses', 'investigations', 'routes', 'dosage_forms', 'frequencies', 'instructions', 'departments'];

    /** @return array{0:string,1:string} normalised [from, to] datetimes */
    public static function range(string $from, string $to): array
    {
        $f = strtotime($from);
        $t = strtotime($to);
        if (!$f || !$t) {
            throw new \InvalidArgumentException('Choose a valid date range.');
        }
        if ($f > $t) {
            [$f, $t] = [$t, $f];
        }
        return [date('Y-m-d', $f) . ' 00:00:00', date('Y-m-d', $t) . ' 23:59:59'];
    }

    /** @return array<string,int> dataset => rows that would be deleted */
    public static function preview(array $datasets, string $from, string $to, array $filters = []): array
    {
        [$f, $t] = self::range($from, $to);
        $out = [];
        foreach ($datasets as $d) {
            switch ($d) {
                case 'visits':
                    $out[$d] = (int) DB::value('SELECT COUNT(*) FROM visits WHERE visit_date BETWEEN ? AND ?', [$f, $t]);
                    break;
                case 'patients':
                    $out[$d] = (int) DB::value('SELECT COUNT(*) FROM patients WHERE created_at BETWEEN ? AND ?', [$f, $t]);
                    break;
                case 'templates':
                    $out[$d] = (int) DB::value('SELECT COUNT(*) FROM templates WHERE created_at BETWEEN ? AND ?', [$f, $t]);
                    break;
                case 'activity_logs':
                    [$w, $p] = self::logFilter($filters);
                    $out[$d] = (int) DB::value('SELECT COUNT(*) FROM activity_logs WHERE created_at BETWEEN ? AND ?' . self::keepAudit() . $w, array_merge([$f, $t], $p));
                    break;
                case 'recycle':
                    $n = 0;
                    foreach (self::RECYCLE_TABLES as $table) {
                        $n += (int) DB::value('SELECT COUNT(*) FROM `' . $table . '` WHERE deleted_at BETWEEN ? AND ?', [$f, $t]);
                    }
                    $out[$d] = $n;
                    break;
            }
        }
        return $out;
    }

    /**
     * Deletes the chosen datasets in one transaction.
     * @return array<string,int> dataset => rows deleted
     */
    public static function purge(array $datasets, string $from, string $to, array $filters = []): array
    {
        $datasets = array_values(array_intersect(array_keys(self::DATASETS), $datasets));
        if (!$datasets) {
            throw new \InvalidArgumentException('Choose at least one kind of data to delete.');
        }
        [$f, $t] = self::range($from, $to);
        return DB::transaction(static function () use ($datasets, $f, $t, $filters): array {
            $done = [];
            foreach ($datasets as $d) {
                switch ($d) {
                    case 'visits':
                        // Clinical items and medicines cascade with the visit.
                        $done[$d] = DB::run('DELETE FROM visits WHERE visit_date BETWEEN ? AND ?', [$f, $t])->rowCount();
                        break;
                    case 'patients':
                        DB::run('DELETE v FROM visits v JOIN patients p ON p.id = v.patient_id WHERE p.created_at BETWEEN ? AND ?', [$f, $t]);
                        $done[$d] = DB::run('DELETE FROM patients WHERE created_at BETWEEN ? AND ?', [$f, $t])->rowCount();
                        break;
                    case 'templates':
                        $done[$d] = DB::run('DELETE FROM templates WHERE created_at BETWEEN ? AND ?', [$f, $t])->rowCount();
                        break;
                    case 'activity_logs':
                        [$w, $p] = self::logFilter($filters);
                        $done[$d] = DB::run('DELETE FROM activity_logs WHERE created_at BETWEEN ? AND ?' . self::keepAudit() . $w, array_merge([$f, $t], $p))->rowCount();
                        break;
                    case 'recycle':
                        $n = 0;
                        // Visits of purged patients first (foreign key), then everything else.
                        DB::run('DELETE v FROM visits v JOIN patients p ON p.id = v.patient_id WHERE p.deleted_at BETWEEN ? AND ?', [$f, $t]);
                        foreach (self::RECYCLE_TABLES as $table) {
                            $n += DB::run('DELETE FROM `' . $table . '` WHERE deleted_at BETWEEN ? AND ?', [$f, $t])->rowCount();
                        }
                        $done[$d] = $n;
                        break;
                }
            }
            return $done;
        });
    }

    /** Optional module / user filter for activity-log cleaning. */
    private static function logFilter(array $filters): array
    {
        $w = '';
        $p = [];
        if (!empty($filters['module'])) {
            $w .= ' AND module = ?';
            $p[] = (string) $filters['module'];
        }
        if (!empty($filters['user_id'])) {
            $w .= ' AND user_id = ?';
            $p[] = (int) $filters['user_id'];
        }
        return [$w, $p];
    }

    /**
     * Audit-trail rows (old → new values, approvals, break-glass) are immutable
     * (Doc 1 §2.11): only plain activity lines can be cleaned.
     */
    private static function keepAudit(): string
    {
        return Migrator::columnExists('activity_logs', 'old_value') ? ' AND old_value IS NULL AND new_value IS NULL' : '';
    }
}
