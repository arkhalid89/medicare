<?php
declare(strict_types=1);

namespace App\Modules\Visits;

use App\Core\DB;

/**
 * Presenting complaints, symptoms, diagnoses and investigations — the four
 * tag lists of the consultation screen. Shared by visits and templates.
 */
final class ClinicalItems
{
    /** item type => [master table, payload key, label, free text allowed] */
    public const TYPES = [
        'complaint'     => ['presenting_complaints', 'complaints', 'Presenting complaint', true],
        'symptom'       => ['symptoms', 'symptoms', 'Symptom', true],
        'diagnosis'     => ['diagnoses', 'diagnoses', 'Diagnosis', false],
        'investigation' => ['investigations', 'investigations', 'Investigation', false],
    ];

    public const MAX_PER_TYPE = 50;

    /** Live search inside one master (BRD §36–38). Empty query => first items. */
    public static function lookup(string $type, string $q, int $limit = 15): array
    {
        if (!isset(self::TYPES[$type])) {
            return [];
        }
        $table = self::TYPES[$type][0];
        $hasCode = $table === 'diagnoses';
        $select = 'SELECT id, name' . ($hasCode ? ', code' : ', NULL AS code') . ' FROM ' . $table . ' WHERE deleted_at IS NULL AND is_active = 1';
        $limit = max(1, min(50, $limit));
        $q = trim($q);
        if ($q === '') {
            $rows = DB::all($select . ' ORDER BY display_order, name LIMIT ' . $limit);
        } else {
            $params = ['%' . like_escape($q) . '%'];
            $where = ' AND (name LIKE ?';
            if ($hasCode) {
                $where .= ' OR code LIKE ?';
                $params[] = like_escape($q) . '%';
            }
            $where .= ')';
            $params[] = like_escape($q) . '%';
            $rows = DB::all($select . $where . ' ORDER BY (name LIKE ?) DESC, display_order, name LIMIT ' . $limit, $params);
        }
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'code' => $r['code'] ?? ''], $rows);
    }

    /**
     * Validates the four lists from a payload and resolves master names.
     * @return array{0: array<int, array{item_type:string, ref_id:?int, name:string, code:?string}>, 1: string[]}
     */
    public static function resolve(array $payload): array
    {
        $items = [];
        $errors = [];
        foreach (self::TYPES as $type => [$table, $key, $label, $freeText]) {
            $list = $payload[$key] ?? [];
            if (!is_array($list)) {
                continue;
            }
            if (count($list) > self::MAX_PER_TYPE) {
                $errors[] = 'Too many ' . strtolower($label) . ' entries (maximum ' . self::MAX_PER_TYPE . ').';
                continue;
            }
            $ids = [];
            foreach ($list as $entry) {
                if (is_array($entry) && !empty($entry['id'])) {
                    $ids[] = (int) $entry['id'];
                }
            }
            $master = [];
            if ($ids) {
                $rows = DB::all(
                    'SELECT id, name' . ($table === 'diagnoses' ? ', code' : ', NULL AS code') . ' FROM ' . $table
                    . ' WHERE deleted_at IS NULL AND id IN (' . DB::placeholders($ids) . ')',
                    $ids
                );
                foreach ($rows as $r) {
                    $master[(int) $r['id']] = $r;
                }
            }

            $seen = [];
            foreach ($list as $entry) {
                $id = is_array($entry) ? (int) ($entry['id'] ?? 0) : 0;
                $name = is_array($entry) ? trim((string) ($entry['name'] ?? '')) : trim((string) $entry);
                $code = null;
                if ($id) {
                    if (!isset($master[$id])) {
                        $errors[] = $label . ' "' . $name . '" is no longer available.';
                        continue;
                    }
                    $name = $master[$id]['name'];
                    $code = $master[$id]['code'] ?: null;
                } else {
                    if (!$freeText) {
                        $errors[] = $label . ' "' . $name . '" must be selected from the list.';
                        continue;
                    }
                    if ($name === '') {
                        continue;
                    }
                    if (mb_strlen($name) > 200) {
                        $errors[] = $label . ' text must not exceed 200 characters.';
                        continue;
                    }
                }
                $dedupe = mb_strtolower($name);
                if (isset($seen[$dedupe])) {
                    continue;
                }
                $seen[$dedupe] = true;
                $items[] = ['item_type' => $type, 'ref_id' => $id ?: null, 'name' => $name, 'code' => $code];
            }
        }
        return [$items, $errors];
    }

    /** Saves resolved items for a visit or template. */
    public static function save(string $table, string $foreignKey, int $ownerId, array $items): void
    {
        $order = [];
        foreach ($items as $item) {
            $order[$item['item_type']] = ($order[$item['item_type']] ?? 0) + 1;
            DB::insert($table, [
                $foreignKey  => $ownerId,
                'item_type'  => $item['item_type'],
                'ref_id'     => $item['ref_id'],
                'name'       => $item['name'],
                'code'       => $item['code'],
                'sort_order' => $order[$item['item_type']],
            ]);
        }
    }

    /**
     * Loads items grouped by payload key: ['complaints' => [...], 'symptoms' => [...], ...]
     */
    public static function load(string $table, string $foreignKey, int $ownerId): array
    {
        $out = ['complaints' => [], 'symptoms' => [], 'diagnoses' => [], 'investigations' => []];
        $rows = DB::all('SELECT item_type, ref_id, name, code FROM ' . $table . ' WHERE ' . $foreignKey . ' = ? ORDER BY item_type, sort_order, id', [$ownerId]);
        foreach ($rows as $r) {
            $key = self::TYPES[$r['item_type']][1];
            $out[$key][] = ['id' => $r['ref_id'] ? (int) $r['ref_id'] : null, 'name' => $r['name'], 'code' => $r['code'] ?? ''];
        }
        return $out;
    }
}
