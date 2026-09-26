<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Full database backup & restore written in pure PHP (no mysqldump needed,
 * so it works the same on XAMPP, Laragon or a server).
 *
 * Backup files: storage/backups/MediCare_Backup_YYYYMMDD_HHMMSS.sql
 * Each file starts with a signature line; restore refuses files without it.
 */
final class Backup
{
    public const SIGNATURE = '-- MediCare Practice Database Backup';

    public static function dir(): string
    {
        $dir = STORAGE_PATH . '/backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * Writes a complete dump of every table (structure + data).
     * @param string $kind manual | auto | pre-restore | pre-reset
     * @return string file name (inside storage/backups)
     */
    public static function create(string $kind = 'manual', bool $applyRetention = true): string
    {
        @set_time_limit(0);
        $suffix = $kind === 'manual' ? '' : '_' . preg_replace('/[^a-z\-]/', '', $kind);
        $name = 'MediCare_Backup_' . date('Ymd_His') . $suffix . '.sql';
        $path = self::dir() . '/' . $name;
        // Avoid overwriting when two backups are made in the same second.
        for ($i = 2; is_file($path); $i++) {
            $name = 'MediCare_Backup_' . date('Ymd_His') . $suffix . '_' . $i . '.sql';
            $path = self::dir() . '/' . $name;
        }

        $out = fopen($path, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Backup folder is not writable: storage/backups');
        }
        $pdo = DB::pdo();
        try {
            fwrite($out, self::SIGNATURE . "\n");
            fwrite($out, '-- Database: ' . config('db.name') . "\n");
            fwrite($out, '-- Created : ' . date('Y-m-d H:i:s') . ' (' . $kind . ")\n");
            fwrite($out, '-- App     : ' . config('app.name') . ' ' . config('app.version') . "\n\n");
            fwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

            $tables = DB::column("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            foreach ($tables as $table) {
                $create = DB::one('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`');
                fwrite($out, "-- Table: $table\n");
                fwrite($out, 'DROP TABLE IF EXISTS `' . $table . "`;\n");
                fwrite($out, $create['Create Table'] . ";\n\n");
                self::dumpRows($pdo, $out, $table);
                fwrite($out, "\n");
            }
            fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n-- End of backup\n");
        } finally {
            fclose($out);
        }

        ActivityLog::record('Backup created', 'backup', $name, 'Database backup created (' . $kind . ')');
        if ($applyRetention) {
            self::applyRetention();
        }
        return $name;
    }

    /** @param resource $out */
    private static function dumpRows(\PDO $pdo, $out, string $table): void
    {
        $columns = DB::column('SHOW COLUMNS FROM `' . $table . '`');
        $colList = '`' . implode('`,`', $columns) . '`';
        $hasId = in_array('id', $columns, true);
        $batch = 500;
        $lastId = 0;
        $offset = 0;

        while (true) {
            if ($hasId) {
                $rows = DB::all('SELECT * FROM `' . $table . '` WHERE id > ? ORDER BY id LIMIT ' . $batch, [$lastId]);
            } else {
                $rows = DB::all('SELECT * FROM `' . $table . '` LIMIT ' . $batch . ' OFFSET ' . $offset);
                $offset += $batch;
            }
            if (!$rows) {
                break;
            }
            $values = [];
            foreach ($rows as $row) {
                $cells = [];
                foreach ($row as $v) {
                    $cells[] = $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v));
                }
                $values[] = '(' . implode(',', $cells) . ')';
                if ($hasId) {
                    $lastId = (int) $row['id'];
                }
            }
            fwrite($out, 'INSERT INTO `' . $table . '` (' . $colList . ") VALUES\n" . implode(",\n", $values) . ";\n");
            if (count($rows) < $batch) {
                break;
            }
        }
    }

    /** Validates that a file is a MediCare backup. */
    public static function isValidFile(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $h = fopen($path, 'rb');
        if ($h === false) {
            return false;
        }
        $first = (string) fgets($h);
        fclose($h);
        return str_starts_with(trim($first), self::SIGNATURE);
    }

    /**
     * Restores a backup file. The current database is archived first
     * (pre-restore backup) so a restore can always be undone.
     * @return string name of the pre-restore archive
     */
    public static function restore(string $path): string
    {
        if (!self::isValidFile($path)) {
            throw new \RuntimeException('This file is not a valid MediCare Practice backup.');
        }
        @set_time_limit(0);
        // Retention runs only after the restore, so it can never delete the
        // very file that is being restored.
        $archive = self::create('pre-restore', false);
        $pdo = DB::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (SqlSplitter::fromFile($path) as $statement) {
                $pdo->exec($statement);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        Settings::flush();
        self::applyRetention();
        ActivityLog::record('Backup restored', 'backup', basename($path), 'Database restored from ' . basename($path) . '; previous data archived as ' . $archive);
        return $archive;
    }

    /** @return array<int, array{name:string, size:int, time:int, kind:string}> newest first */
    public static function list(): array
    {
        $files = [];
        foreach (glob(self::dir() . '/*.sql') ?: [] as $file) {
            $name = basename($file);
            $kind = 'manual';
            foreach (['auto', 'pre-restore', 'pre-reset'] as $k) {
                if (str_contains($name, '_' . $k)) {
                    $kind = $k;
                }
            }
            $files[] = ['name' => $name, 'size' => (int) filesize($file), 'time' => (int) filemtime($file), 'kind' => $kind];
        }
        usort($files, static fn ($a, $b) => [$b['time'], $b['name']] <=> [$a['time'], $a['name']]);
        return $files;
    }

    /** Resolves a file name from the listing to a safe absolute path. */
    public static function path(string $name): ?string
    {
        $name = basename($name);
        if (!preg_match('/^MediCare_Backup_[A-Za-z0-9_\-]+\.sql$/', $name)) {
            return null;
        }
        $path = self::dir() . '/' . $name;
        return is_file($path) ? $path : null;
    }

    /** Keeps only the newest N backups (setting backup_retention). */
    public static function applyRetention(): void
    {
        $keep = max(1, (int) setting('backup_retention', 20));
        $files = self::list();
        foreach (array_slice($files, $keep) as $old) {
            @unlink(self::dir() . '/' . $old['name']);
        }
    }

    /**
     * Scheduled backup without a server cron: called after login; creates an
     * automatic backup when the last one is older than the chosen period.
     */
    public static function runScheduled(): ?string
    {
        $mode = (string) setting('auto_backup', 'off');
        if (!in_array($mode, ['daily', 'weekly'], true)) {
            return null;
        }
        $last = strtotime((string) setting('last_auto_backup', '')) ?: 0;
        $period = $mode === 'daily' ? 86400 : 7 * 86400;
        if (time() - $last < $period) {
            return null;
        }
        try {
            $name = self::create('auto');
            Settings::set(['last_auto_backup' => now()]);
            return $name;
        } catch (\Throwable $e) {
            Logger::error('Scheduled backup failed: ' . $e->getMessage());
            return null;
        }
    }

    public static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return round($size, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}
