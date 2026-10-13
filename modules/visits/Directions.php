<?php
declare(strict_types=1);

namespace App\Modules\Visits;

use App\Core\DB;
use App\Core\Urdu;

/**
 * Plain-language directions for a saved prescription line, in English and
 * Urdu (the patient's "noskha"), following the PHC three-line format:
 *   Line 1  dosage form, name (generic + brand), strength
 *   Line 2  quantity per dose, route, frequency, duration
 *   Line 3  other instructions
 */
final class Directions
{
    private static ?array $maps = null;

    /** Urdu wording from the masters (editable), falling back to the defaults. */
    private static function maps(): array
    {
        if (self::$maps === null) {
            $freq = [];
            foreach (DB::all('SELECT id, code, name_ur FROM frequencies') as $f) {
                $freq[(int) $f['id']] = $f['name_ur'] ?: (Urdu::FREQUENCIES[$f['code']] ?? '');
            }
            $routes = [];
            foreach (DB::all('SELECT id, name, name_ur FROM routes') as $r) {
                $routes[(int) $r['id']] = $r['name_ur'] ?: (Urdu::ROUTES[$r['name']] ?? '');
            }
            self::$maps = ['freq' => $freq, 'routes' => $routes, 'instr' => MedicineCatalog::instructionsUrdu()];
        }
        return self::$maps;
    }

    public static function line1(array $m): string
    {
        $form = trim((string) ($m['dosage_form'] ?? ''));
        $name = (string) $m['medicine_name'];
        $generic = trim((string) ($m['generic_name'] ?? ''));
        return trim(($form !== '' ? $form . ' ' : '') . $name . ($generic !== '' && strcasecmp($generic, $name) !== 0 ? ' (' . $generic . ')' : '')
            . ' ' . ($m['strength'] ?? ''));
    }

    public static function english(array $m): string
    {
        $unitKey = Urdu::unitKey($m['dose_unit'] ?? '');
        $unit = Urdu::UNITS[$unitKey][0] ?? (string) $m['dose_unit'];
        $dose = (float) $m['dose'];
        $parts = [Urdu::fraction($dose) . ' ' . $unit . ($dose > 1 && !str_ends_with($unit, 's') ? 's' : '')];
        if (!empty($m['route_name']) && $m['route_name'] !== 'Oral') {
            $parts[] = Urdu::ROUTES_EN[$m['route_name']] ?? strtolower((string) $m['route_name']);
        }
        if (!empty($m['frequency_name'])) {
            $parts[] = strtolower((string) $m['frequency_name']) . (!empty($m['frequency_code']) ? ' (' . $m['frequency_code'] . ')' : '');
        }
        if ($m['duration_days'] !== null && $m['duration_days'] !== '' && (int) $m['duration_days'] > 0) {
            $parts[] = 'for ' . (int) $m['duration_days'] . ' day' . ((int) $m['duration_days'] > 1 ? 's' : '');
        }
        return implode(', ', $parts);
    }

    public static function urdu(array $m): string
    {
        $maps = self::maps();
        $unitKey = Urdu::unitKey($m['dose_unit'] ?? '');
        $parts = [Urdu::fraction((float) $m['dose']) . ' ' . (Urdu::UNITS[$unitKey][1] ?? (string) $m['dose_unit'])];
        $route = $maps['routes'][(int) ($m['route_id'] ?? 0)] ?? (Urdu::ROUTES[$m['route_name'] ?? ''] ?? '');
        if ($route !== '' && ($m['route_name'] ?? '') !== 'Oral') {
            $parts[] = $route;
        }
        $freq = $maps['freq'][(int) ($m['frequency_id'] ?? 0)] ?? (Urdu::FREQUENCIES[$m['frequency_code'] ?? ''] ?? '');
        if ($freq !== '') {
            $parts[] = $freq;
        }
        if (!empty($m['instructions'])) {
            $parts[] = $maps['instr'][$m['instructions']] ?? $m['instructions'];
        }
        if ($m['duration_days'] !== null && $m['duration_days'] !== '' && (int) $m['duration_days'] > 0) {
            $parts[] = (int) $m['duration_days'] . ' دن تک';
        }
        return implode('، ', $parts);
    }

    /** Test hook: forget cached master wording. */
    public static function flush(): void
    {
        self::$maps = null;
    }
}
