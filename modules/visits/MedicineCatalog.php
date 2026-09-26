<?php
declare(strict_types=1);

namespace App\Modules\Visits;

use App\Core\DB;

/**
 * Read access to medicines and the dosing masters for the consultation
 * screen, plus conversion of a medicine into a prescription line.
 */
final class MedicineCatalog
{
    private const SELECT = 'SELECT m.id, m.name, m.generic_name, m.brand_name, m.strength, m.default_dose, m.dose_unit,
            m.default_duration, m.unit, m.pack_size, m.price, m.frequency_id, m.route_id, m.instruction_id, m.is_active,
            df.name AS dosage_form, s.id AS source_id, s.name AS source_name, i.name AS instruction_name
        FROM medicines m
        LEFT JOIN dosage_forms df ON df.id = m.dosage_form_id
        LEFT JOIN medicine_sources s ON s.id = m.source_id AND s.deleted_at IS NULL
        LEFT JOIN instructions i ON i.id = m.instruction_id AND i.deleted_at IS NULL';

    /** Global medicine search by name, generic or brand (BRD §39). */
    public static function search(string $q, int $limit = 20): array
    {
        $q = trim($q);
        $limit = max(1, min(50, $limit));
        if ($q === '') {
            $rows = DB::all(self::SELECT . ' WHERE m.deleted_at IS NULL AND m.is_active = 1 ORDER BY m.display_order, m.name LIMIT ' . $limit);
        } else {
            $like = '%' . like_escape($q) . '%';
            $prefix = like_escape($q) . '%';
            $rows = DB::all(
                self::SELECT . ' WHERE m.deleted_at IS NULL AND m.is_active = 1
                   AND (m.name LIKE ? OR m.generic_name LIKE ? OR m.brand_name LIKE ?)
                 ORDER BY (m.name LIKE ?) DESC, m.display_order, m.name LIMIT ' . $limit,
                [$like, $like, $like, $prefix]
            );
        }
        $frequencies = self::frequencies();
        return array_map(static fn (array $m) => self::line($m, [], $frequencies), $rows);
    }

    /** Active, non-deleted medicines keyed by id. */
    public static function activeByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $rows = DB::all(self::SELECT . ' WHERE m.deleted_at IS NULL AND m.is_active = 1 AND m.id IN (' . DB::placeholders($ids) . ')', $ids);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    /** @return array<int, array> id => frequency (active) */
    public static function frequencies(bool $includeInactive = false): array
    {
        $rows = DB::all(
            'SELECT id, name, code, doses_per_day, calc_mode, is_active FROM frequencies WHERE deleted_at IS NULL'
            . ($includeInactive ? '' : ' AND is_active = 1') . ' ORDER BY display_order, name'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    /** @return array<int, array> id => route */
    public static function routes(bool $includeInactive = false): array
    {
        $rows = DB::all(
            'SELECT id, name, short_name FROM routes WHERE deleted_at IS NULL'
            . ($includeInactive ? '' : ' AND is_active = 1') . ' ORDER BY display_order, name'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    public static function instructions(): array
    {
        return DB::column('SELECT name FROM instructions WHERE deleted_at IS NULL AND is_active = 1 ORDER BY display_order, name');
    }

    /** Source id => display order, for Rx grouping. */
    public static function sourceOrder(): array
    {
        return array_map('intval', DB::pairs('SELECT id, display_order FROM medicine_sources'));
    }

    /**
     * Converts a medicine row into a prescription line for the screen.
     * $o may override dose, frequency, route, duration, quantity, price, instructions.
     */
    public static function line(array $m, array $o = [], ?array $frequencies = null): array
    {
        $frequencies = $frequencies ?? self::frequencies();
        $frequencyId = array_key_exists('frequency_id', $o) ? (int) $o['frequency_id'] : (int) $m['frequency_id'];
        if (!isset($frequencies[$frequencyId])) {
            $frequencyId = 0;
        }
        $dose = (float) ($o['dose'] ?? $m['default_dose'] ?? 1);
        $duration = (int) ($o['duration_days'] ?? $m['default_duration'] ?? 0);
        $packSize = (float) ($m['pack_size'] ?? 1);

        $auto = null;
        if ($frequencyId) {
            $f = $frequencies[$frequencyId];
            $auto = PrescriptionMath::quantity($dose, (float) $f['doses_per_day'], (string) $f['calc_mode'], $duration, $packSize);
        }
        $manual = (int) ($o['qty_manual'] ?? 0) === 1 || $auto === null;
        $quantity = $manual ? (float) ($o['quantity'] ?? 1) : $auto;
        if ($quantity <= 0) {
            $quantity = 1;
        }
        $price = array_key_exists('unit_price', $o) ? (float) $o['unit_price'] : (float) $m['price'];

        return [
            'medicine_id'   => (int) $m['id'],
            'name'          => $m['name'],
            'generic_name'  => $m['generic_name'] ?? '',
            'strength'      => $m['strength'] ?? '',
            'dosage_form'   => $m['dosage_form'] ?? '',
            'dose'          => $dose,
            'dose_unit'     => $m['dose_unit'] ?? '',
            'frequency_id'  => $frequencyId ?: null,
            'route_id'      => array_key_exists('route_id', $o) ? ($o['route_id'] ? (int) $o['route_id'] : null) : ($m['route_id'] ? (int) $m['route_id'] : null),
            'duration_days' => $duration,
            'quantity'      => $quantity,
            'qty_manual'    => $manual ? 1 : 0,
            'unit'          => $m['unit'] ?? '',
            'pack_size'     => $packSize,
            'unit_price'    => $price,
            'master_price'  => (float) $m['price'],
            'source_id'     => $m['source_id'] ? (int) $m['source_id'] : null,
            'source_name'   => $m['source_name'] ?? '',
            'instructions'  => array_key_exists('instructions', $o) ? (string) $o['instructions'] : (string) ($m['instruction_name'] ?? ''),
        ];
    }
}
