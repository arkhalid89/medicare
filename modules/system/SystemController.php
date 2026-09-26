<?php
declare(strict_types=1);

namespace App\Modules\System;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Backup;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Installer;
use App\Core\Logger;
use App\Core\Paginator;
use App\Core\Password;
use App\Core\Session;

final class SystemController extends Controller
{
    // ================================================================ backup

    public function backups(): void
    {
        $this->view('system/backups', [
            'title'      => 'Backup & Restore',
            'backups'    => Backup::list(),
            'canRestore' => can('backup.restore'),
        ]);
    }

    /** Creates a full database backup and downloads it immediately. */
    public function createBackup(): void
    {
        try {
            $name = Backup::create('manual');
        } catch (\Throwable $e) {
            Logger::error('Backup failed: ' . $e->getMessage());
            flash('error', 'Backup failed. ' . $e->getMessage());
            redirect('system/backup');
        }
        $this->send(Backup::dir() . '/' . $name, $name);
    }

    public function downloadBackup(): void
    {
        $path = Backup::path((string) input('file', ''));
        if (!$path) {
            abort(404, 'Backup file not found.');
        }
        ActivityLog::record('Backup downloaded', 'backup', basename($path), 'Downloaded ' . basename($path));
        $this->send($path, basename($path));
    }

    public function restoreBackup(): void
    {
        $upload = $_FILES['backup_file'] ?? null;
        $path = null;
        $temp = null;
        if ($upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $maxMb = (int) config('uploads.max_restore_mb', 512);
            if ($upload['error'] !== UPLOAD_ERR_OK) {
                flash('error', 'Restore failed: the file could not be uploaded (error ' . (int) $upload['error'] . '). Check upload_max_filesize in php.ini.');
                redirect('system/backup');
            }
            if ($upload['size'] > $maxMb * 1024 * 1024) {
                flash('error', 'Restore failed: the file is larger than ' . $maxMb . ' MB.');
                redirect('system/backup');
            }
            $temp = Backup::dir() . '/upload_' . bin2hex(random_bytes(6)) . '.tmp';
            if (!move_uploaded_file($upload['tmp_name'], $temp)) {
                flash('error', 'Restore failed: could not store the uploaded file.');
                redirect('system/backup');
            }
            $path = $temp;
        } else {
            $path = Backup::path((string) input('file', ''));
        }
        if (!$path || !Backup::isValidFile($path)) {
            if ($temp) {
                @unlink($temp);
            }
            flash('error', 'Restore failed: this is not a valid MediCare Practice backup file.');
            redirect('system/backup');
        }
        if (trim((string) input('confirm', '')) !== 'RESTORE') {
            if ($temp) {
                @unlink($temp);
            }
            flash('error', 'Type RESTORE to confirm. The database was not changed.');
            redirect('system/backup');
        }

        try {
            $archive = Backup::restore($path);
        } catch (\Throwable $e) {
            Logger::error('Restore failed: ' . $e->getMessage());
            if ($temp) {
                @unlink($temp);
            }
            flash('error', 'Restore failed: ' . $e->getMessage() . ' Your previous data was archived before the attempt.');
            redirect('system/backup');
        }
        if ($temp) {
            @unlink($temp);
        }
        // The users table may have changed: start a fresh session.
        Auth::logout();
        Session::start();
        flash('success', 'Database restored successfully. The previous data was archived as ' . $archive . '. Please log in again.');
        redirect('login');
    }

    public function deleteBackup(): void
    {
        $path = Backup::path((string) input('file', ''));
        if (!$path) {
            abort(404, 'Backup file not found.');
        }
        @unlink($path);
        ActivityLog::record('Backup deleted', 'backup', basename($path), 'Deleted backup file ' . basename($path));
        flash('success', 'Backup ' . basename($path) . ' deleted.');
        redirect('system/backup');
    }

    private function send(string $path, string $name): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        exit;
    }

    // ================================================================= reset

    public function reset(): void
    {
        $errors = [];
        if (is_post()) {
            $mode = (string) input('mode', '');
            $me = DB::one('SELECT * FROM users WHERE id = ?', [Auth::id()]);
            if (!in_array($mode, ['clinical', 'factory'], true)) {
                $errors[] = 'Choose what to reset.';
            }
            if (trim((string) input('confirm', '')) !== 'RESET') {
                $errors[] = 'Type RESET in capital letters to confirm.';
            }
            if (!Password::verify((string) ($_POST['password'] ?? ''), (string) $me['password'])) {
                $errors[] = 'Your password is incorrect.';
            }
            if (!$errors) {
                try {
                    $backup = Installer::reset($mode, $me);
                } catch (\Throwable $e) {
                    Logger::error('Database reset failed: ' . $e->getMessage());
                    $errors[] = 'Reset failed: ' . $e->getMessage();
                }
                if (!$errors) {
                    Auth::logout();
                    Session::start();
                    flash('success', ($mode === 'factory' ? 'Factory reset complete.' : 'Clinical data cleared.') . ' Safety backup: ' . $backup . '. Please log in again.');
                    redirect('login');
                }
            }
        }
        $counts = [];
        foreach (['patients' => 'Patients', 'visits' => 'Visits', 'templates' => 'Templates', 'activity_logs' => 'Activity log entries', 'users' => 'Users', 'medicines' => 'Medicines'] as $t => $l) {
            $counts[$l] = (int) DB::value('SELECT COUNT(*) FROM `' . $t . '`');
        }
        $this->view('system/reset', ['title' => 'Database Reset', 'errors' => $errors, 'counts' => $counts]);
    }

    // =========================================================== recycle bin

    /** Entities that support soft delete: key => [label, table, label SQL]. */
    private function entities(): array
    {
        $list = [
            'patients'  => ['Patients', 'patients', "CONCAT(x.name, ' (', x.mrn, ')')"],
            'visits'    => ['Visits', 'visits', 'x.visit_no'],
            'users'     => ['Users', 'users', "CONCAT(x.name, ' (', x.username, ')')"],
            'templates' => ['Templates', 'templates', 'x.name'],
        ];
        foreach (config('masters') as $key => $def) {
            $list[$key] = [$def['title'], $key, 'x.name'];
        }
        return $list;
    }

    public function recycle(): void
    {
        $entities = $this->entities();
        $type = (string) input('type', 'patients');
        if (!isset($entities[$type])) {
            $type = 'patients';
        }
        [$label, $table, $labelSql] = $entities[$type];
        $counts = [];
        foreach ($entities as $k => [$l, $t]) {
            $counts[$k] = (int) DB::value('SELECT COUNT(*) FROM `' . $t . '` WHERE deleted_at IS NOT NULL');
        }
        $select = 'SELECT x.id, ' . $labelSql . ' AS label, x.deleted_at, u.name AS deleted_by_name FROM `' . $table . '` x LEFT JOIN users u ON u.id = x.deleted_by WHERE x.deleted_at IS NOT NULL';
        if ($this->wantsExport()) {
            $this->export('recycle_bin_' . $type, 'Recycle Bin — ' . $label, [
                'label'           => 'Record',
                'deleted_at'      => ['Deleted On', static fn ($r) => fmt_datetime($r['deleted_at'])],
                'deleted_by_name' => 'Deleted By',
            ], DB::run($select . ' ORDER BY x.deleted_at DESC'));
            return;
        }
        $pager = new Paginator($counts[$type]);
        $this->view('system/recycle', [
            'title'    => 'Recycle Bin',
            'entities' => $entities,
            'type'     => $type,
            'counts'   => $counts,
            'rows'     => DB::all($select . ' ORDER BY x.deleted_at DESC' . $pager->limitSql()),
            'pager'    => $pager,
        ]);
    }

    public function restore(): void
    {
        $entities = $this->entities();
        $type = (string) input('type', '');
        if (!isset($entities[$type])) {
            abort(404);
        }
        [$label, $table] = $entities[$type];
        $id = $this->requireId();
        $row = DB::one('SELECT * FROM `' . $table . '` WHERE id = ? AND deleted_at IS NOT NULL', [$id]);
        if (!$row) {
            abort(404, 'Record not found in the Recycle Bin.');
        }
        // A master record cannot come back if an active record now uses its name.
        $masters = config('masters');
        if (isset($masters[$type])) {
            $where = 'deleted_at IS NULL AND id <> ?';
            $params = [$id];
            foreach ($masters[$type]['unique'] ?? ['name'] as $col) {
                if ($row[$col] === null) {
                    $where .= ' AND `' . $col . '` IS NULL';
                } else {
                    $where .= ' AND LOWER(`' . $col . '`) = LOWER(?)';
                    $params[] = $row[$col];
                }
            }
            if (DB::value('SELECT 1 FROM `' . $table . '` WHERE ' . $where, $params)) {
                flash('error', 'Cannot restore “' . $row['name'] . '”: an active record with the same name already exists. Rename or delete that record first.');
                redirect('system/recycle', ['type' => $type]);
            }
        }
        DB::update($table, ['deleted_at' => null, 'deleted_by' => null, 'updated_at' => now(), 'updated_by' => Auth::id()], 'id = ?', [$id]);
        $name = $row['name'] ?? $row['visit_no'] ?? ('#' . $id);
        ActivityLog::record('Record restored', $type, (string) $id, $label . ': ' . $name . ' restored from Recycle Bin');
        flash('success', '“' . $name . '” restored.');
        redirect('system/recycle', ['type' => $type]);
    }

    // ========================================================== activity log

    public function logs(): void
    {
        $q = trim((string) input('q', ''));
        $userId = (int) input('user_id', 0);
        $module = (string) input('module', '');
        $from = (string) input('from', '');
        $to = (string) input('to', '');

        $where = '1 = 1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (l.action LIKE ? OR l.description LIKE ? OR l.username LIKE ?)';
            $like = '%' . like_escape($q) . '%';
            array_push($params, $like, $like, $like);
        }
        if ($userId > 0) {
            $where .= ' AND l.user_id = ?';
            $params[] = $userId;
        }
        if ($module !== '') {
            $where .= ' AND l.module = ?';
            $params[] = $module;
        }
        if ($from !== '' && strtotime($from)) {
            $where .= ' AND l.created_at >= ?';
            $params[] = date('Y-m-d', strtotime($from)) . ' 00:00:00';
        }
        if ($to !== '' && strtotime($to)) {
            $where .= ' AND l.created_at <= ?';
            $params[] = date('Y-m-d', strtotime($to)) . ' 23:59:59';
        }
        $select = 'SELECT l.* FROM activity_logs l WHERE ' . $where . ' ORDER BY l.id DESC';

        if ($this->wantsExport()) {
            $this->export('activity_logs', 'Activity Logs', [
                'created_at'  => ['Date / Time', static fn ($r) => fmt_datetime($r['created_at'])],
                'username'    => 'User',
                'role'        => ['Role', static fn ($r) => $r['role'] ? role_label($r['role']) : ''],
                'action'      => 'Action',
                'module'      => 'Module',
                'record_id'   => 'Record',
                'description' => 'Details',
                'ip_address'  => 'IP Address',
            ], DB::run($select, $params), ['Search' => $q, 'Module' => $module, 'From' => $from, 'To' => $to]);
            return;
        }

        $pager = new Paginator((int) DB::value('SELECT COUNT(*) FROM activity_logs l WHERE ' . $where, $params));
        $this->view('system/logs', [
            'title'   => 'Activity Logs',
            'rows'    => DB::all($select . $pager->limitSql(), $params),
            'pager'   => $pager,
            'users'   => DB::pairs('SELECT id, name FROM users ORDER BY name'),
            'modules' => DB::column('SELECT DISTINCT module FROM activity_logs WHERE module IS NOT NULL ORDER BY module'),
        ]);
    }
}
