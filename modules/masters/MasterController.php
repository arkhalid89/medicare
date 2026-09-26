<?php
declare(strict_types=1);

namespace App\Modules\Masters;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Paginator;
use App\Core\Router;

/**
 * Generic master-data engine driven by config/masters.php:
 * list · search · filter · add · edit · activate/deactivate · soft delete · Excel.
 * Delete is Super Admin only (records.delete); deleted rows go to the Recycle Bin.
 */
final class MasterController extends Controller
{
    public function index(): void
    {
        $key = substr(Router::current(), strlen('masters/'));
        $def = $this->def($key);

        $q = trim((string) input('q', ''));
        $status = (string) input('status', '');
        [$where, $params] = $this->filters($def, $q, $status);
        [$select, $joins] = $this->selectParts($def);

        $from = ' FROM `' . $def['table'] . '` t' . $joins . ' WHERE ' . $where;
        $order = ' ORDER BY t.display_order, t.name';

        if ($this->wantsExport()) {
            $columns = ['name' => $def['name_label']];
            foreach ($def['fields'] as $field => $f) {
                $columns[$field] = [$f['label'], $this->cellFormatter($field, $f)];
            }
            $columns['is_active'] = ['Status', static fn ($r) => (int) $r['is_active'] === 1 ? 'Active' : 'Inactive'];
            $columns['display_order'] = 'Display Order';
            $filters = ['Search' => $q, 'Status' => ucfirst($status)];
            foreach ($def['fields'] as $field => $f) {
                if (!empty($f['filter']) && input($field)) {
                    $filters[$f['label']] = $this->lookupOptions($f['lookup'])[(int) input($field)] ?? '';
                }
            }
            $this->export($key, $def['title'], $columns, DB::run('SELECT ' . $select . $from . $order, $params), $filters);
            return;
        }

        $pager = new Paginator((int) DB::value('SELECT COUNT(*)' . $from, $params));
        $rows = DB::all('SELECT ' . $select . $from . $order . $pager->limitSql(), $params);

        $lookups = [];
        foreach ($def['fields'] as $field => $f) {
            if (!empty($f['filter']) && $f['type'] === 'select') {
                $lookups[$field] = $this->lookupOptions($f['lookup']);
            }
        }

        $this->view('masters/index', [
            'title'     => $def['title'],
            'def'       => $def,
            'rows'      => $rows,
            'pager'     => $pager,
            'q'         => $q,
            'status'    => $status,
            'lookups'   => $lookups,
            'canManage' => can($def['perm_manage']),
            'canDelete' => can('records.delete'),
        ]);
    }

    public function form(): void
    {
        $key = (string) input('type', '');
        $def = $this->def($key);
        if (!can($def['perm_manage'])) {
            abort(403);
        }
        $id = (int) input('id', 0);
        $row = null;
        if ($id > 0) {
            $row = DB::one('SELECT * FROM `' . $def['table'] . '` WHERE id = ? AND deleted_at IS NULL', [$id]);
            if (!$row) {
                abort(404, $def['singular'] . ' not found.');
            }
        }

        $errors = [];
        if (is_post()) {
            [$data, $errors] = $this->validate($def, $_POST, $id);
            if (!$errors) {
                if ($row) {
                    DB::update($def['table'], $data + ['updated_at' => now(), 'updated_by' => Auth::id()], 'id = ?', [$id]);
                    ActivityLog::record($def['singular'] . ' updated', $key, (string) $id, $def['singular'] . ': ' . $data['name']);
                    $this->logFieldChanges($def, $key, $id, $row, $data);
                    flash('success', $def['singular'] . ' "' . $data['name'] . '" updated successfully.');
                } else {
                    $id = DB::insert($def['table'], $data + ['created_at' => now(), 'created_by' => Auth::id()]);
                    ActivityLog::record($def['singular'] . ' created', $key, (string) $id, $def['singular'] . ': ' . $data['name']);
                    flash('success', $def['singular'] . ' "' . $data['name'] . '" added successfully.');
                }
                if (isset($_POST['save_new'])) {
                    redirect('masters/form', ['type' => $key]);
                }
                redirect('masters/' . $key);
            }
        }

        $lookups = [];
        foreach ($def['fields'] as $field => $f) {
            if ($f['type'] === 'select') {
                $lookups[$field] = $this->lookupOptions($f['lookup'], true);
            }
        }
        $nextOrder = (int) DB::value('SELECT COALESCE(MAX(display_order), 0) + 1 FROM `' . $def['table'] . '` WHERE deleted_at IS NULL');

        $this->view('masters/form', [
            'title'     => ($row ? 'Edit ' : 'Add ') . $def['singular'],
            'def'       => $def,
            'row'       => $row,
            'errors'    => array_values($errors),
            'fieldErrors' => $errors,
            'lookups'   => $lookups,
            'nextOrder' => $nextOrder,
        ]);
    }

    public function toggle(): void
    {
        $key = (string) input('type', '');
        $def = $this->def($key);
        if (!can($def['perm_manage'])) {
            abort(403);
        }
        $id = $this->requireId();
        $row = DB::one('SELECT id, name, is_active FROM `' . $def['table'] . '` WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$row) {
            abort(404);
        }
        $new = (int) $row['is_active'] === 1 ? 0 : 1;
        DB::update($def['table'], ['is_active' => $new, 'updated_at' => now(), 'updated_by' => Auth::id()], 'id = ?', [$id]);
        ActivityLog::record($def['singular'] . ($new ? ' activated' : ' deactivated'), $key, (string) $id, $def['singular'] . ': ' . $row['name']);
        flash('success', $def['singular'] . ' "' . $row['name'] . '" ' . ($new ? 'activated' : 'deactivated') . '.');
        back('masters/' . $key);
    }

    public function delete(): void
    {
        $key = (string) input('type', '');
        $def = $this->def($key);
        $id = $this->requireId();
        $row = DB::one('SELECT id, name FROM `' . $def['table'] . '` WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$row) {
            abort(404);
        }
        DB::update($def['table'], ['deleted_at' => now(), 'deleted_by' => Auth::id()], 'id = ?', [$id]);
        ActivityLog::record($def['singular'] . ' deleted', $key, (string) $id, $def['singular'] . ' moved to Recycle Bin: ' . $row['name']);
        flash('success', $def['singular'] . ' "' . $row['name'] . '" deleted. It can be restored from the Recycle Bin.');
        back('masters/' . $key);
    }

    /** Medicine–Source mapping with bulk re-assignment (BRD §17). */
    public function mapping(): void
    {
        $sources = $this->lookupOptions('medicine_sources', true);
        if (is_post()) {
            if (!can('masters.manage')) {
                abort(403);
            }
            $ids = array_values(array_filter(array_map('intval', (array) ($_POST['medicine_ids'] ?? []))));
            $sourceRaw = (string) ($_POST['source_id'] ?? '');
            $sourceId = $sourceRaw === 'none' ? null : (int) $sourceRaw;
            if (!$ids) {
                flash('warning', 'Select at least one medicine.');
            } elseif ($sourceRaw === '' || ($sourceId !== null && !isset($sources[$sourceId]))) {
                flash('warning', 'Select the source to assign.');
            } else {
                $names = DB::column('SELECT name FROM medicines WHERE deleted_at IS NULL AND id IN (' . DB::placeholders($ids) . ')', $ids);
                DB::run(
                    'UPDATE medicines SET source_id = ?, updated_at = ?, updated_by = ? WHERE deleted_at IS NULL AND id IN (' . DB::placeholders($ids) . ')',
                    array_merge([$sourceId, now(), Auth::id()], $ids)
                );
                $label = $sourceId ? $sources[$sourceId] : 'No source';
                ActivityLog::record('Medicine source changed', 'medicines', null, count($names) . ' medicine(s) mapped to ' . $label . ': ' . implode(', ', array_slice($names, 0, 20)));
                flash('success', count($names) . ' medicine(s) mapped to "' . $label . '".');
            }
            redirect_to(url('masters/mapping', array_filter(['source' => input('return_source'), 'q' => input('return_q')])));
        }

        $filterSource = (string) input('source', '');
        $q = trim((string) input('q', ''));
        $where = 'm.deleted_at IS NULL';
        $params = [];
        if ($filterSource === 'none') {
            $where .= ' AND (m.source_id IS NULL OR s.id IS NULL)';
        } elseif ($filterSource !== '') {
            $where .= ' AND m.source_id = ?';
            $params[] = (int) $filterSource;
        }
        if ($q !== '') {
            $where .= ' AND (m.name LIKE ? OR m.generic_name LIKE ? OR m.brand_name LIKE ?)';
            $like = '%' . like_escape($q) . '%';
            array_push($params, $like, $like, $like);
        }
        $sql = 'SELECT m.id, m.name, m.generic_name, m.strength, m.price, m.is_active, s.name AS source_name
                  FROM medicines m LEFT JOIN medicine_sources s ON s.id = m.source_id AND s.deleted_at IS NULL
                 WHERE ' . $where . ' ORDER BY s.display_order IS NULL, s.display_order, m.name';

        if ($this->wantsExport()) {
            $this->export('medicine_source_mapping', 'Medicine–Source Mapping', [
                'name'         => 'Medicine',
                'generic_name' => 'Generic',
                'strength'     => 'Strength',
                'price'        => 'Unit Price',
                'source_name'  => ['Source', static fn ($r) => $r['source_name'] ?: 'Not mapped'],
                'is_active'    => ['Status', static fn ($r) => (int) $r['is_active'] === 1 ? 'Active' : 'Inactive'],
            ], DB::run($sql, $params), ['Search' => $q]);
            return;
        }

        $pager = new Paginator((int) DB::value('SELECT COUNT(*) FROM medicines m LEFT JOIN medicine_sources s ON s.id = m.source_id AND s.deleted_at IS NULL WHERE ' . $where, $params), 50);
        $summary = DB::all(
            'SELECT s.id, s.name, s.is_active, COUNT(m.id) AS total FROM medicine_sources s
               LEFT JOIN medicines m ON m.source_id = s.id AND m.deleted_at IS NULL
              WHERE s.deleted_at IS NULL GROUP BY s.id, s.name, s.is_active, s.display_order ORDER BY s.display_order, s.name'
        );
        $unmapped = (int) DB::value('SELECT COUNT(*) FROM medicines m LEFT JOIN medicine_sources s ON s.id = m.source_id AND s.deleted_at IS NULL WHERE m.deleted_at IS NULL AND s.id IS NULL');

        $this->view('masters/mapping', [
            'title'        => 'Medicine–Source Mapping',
            'rows'         => DB::all($sql . $pager->limitSql(), $params),
            'pager'        => $pager,
            'sources'      => $sources,
            'summary'      => $summary,
            'unmapped'     => $unmapped,
            'filterSource' => $filterSource,
            'q'            => $q,
            'canManage'    => can('masters.manage'),
        ]);
    }

    // ------------------------------------------------------------------

    private function def(string $key): array
    {
        $defs = config('masters');
        if (!isset($defs[$key])) {
            abort(404);
        }
        $def = $defs[$key] + ['key' => $key, 'table' => $key, 'perm_view' => 'masters.view', 'perm_manage' => 'masters.manage', 'unique' => ['name']];
        if (!can($def['perm_view'])) {
            abort(403);
        }
        return $def;
    }

    /** @return array{0:string, 1:array} */
    private function filters(array $def, string $q, string $status): array
    {
        $where = 't.deleted_at IS NULL';
        $params = [];
        if ($q !== '') {
            $cols = ['t.name'];
            foreach ($def['fields'] as $field => $f) {
                if (!empty($f['search'])) {
                    $cols[] = 't.' . $field;
                }
            }
            $where .= ' AND (' . implode(' OR ', array_map(static fn ($c) => $c . ' LIKE ?', $cols)) . ')';
            foreach ($cols as $c) {
                $params[] = '%' . like_escape($q) . '%';
            }
        }
        if ($status === 'active') {
            $where .= ' AND t.is_active = 1';
        } elseif ($status === 'inactive') {
            $where .= ' AND t.is_active = 0';
        }
        foreach ($def['fields'] as $field => $f) {
            if (!empty($f['filter']) && ($v = input($field)) !== null && $v !== '') {
                $where .= ' AND t.' . $field . ' = ?';
                $params[] = (int) $v;
            }
        }
        return [$where, $params];
    }

    /** @return array{0:string, 1:string} select list and joins (lookup labels) */
    private function selectParts(array $def): array
    {
        $select = 't.*';
        $joins = '';
        foreach ($def['fields'] as $field => $f) {
            if ($f['type'] === 'select') {
                $alias = 'j_' . $field;
                $joins .= ' LEFT JOIN `' . $f['lookup'] . '` ' . $alias . ' ON ' . $alias . '.id = t.' . $field . ' AND ' . $alias . '.deleted_at IS NULL';
                $select .= ', ' . $alias . '.name AS ' . $field . '_label';
            }
        }
        return [$select, $joins];
    }

    private function cellFormatter(string $field, array $f): callable
    {
        return static function (array $r) use ($field, $f) {
            if ($f['type'] === 'select') {
                return $r[$field . '_label'] ?? '';
            }
            if ($f['type'] === 'options') {
                return $f['options'][$r[$field]] ?? $r[$field];
            }
            if (in_array($f['type'], ['decimal', 'money'], true)) {
                return $r[$field] === null ? '' : (float) $r[$field];
            }
            return $r[$field] ?? '';
        };
    }

    /** id => name for a lookup table (non-deleted). */
    private function lookupOptions(string $table, bool $markInactive = false): array
    {
        $rows = DB::all('SELECT id, name, is_active FROM `' . $table . '` WHERE deleted_at IS NULL ORDER BY is_active DESC, display_order, name');
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = $r['name'] . ($markInactive && !(int) $r['is_active'] ? ' (inactive)' : '');
        }
        return $out;
    }

    /** @return array{0: array, 1: array} */
    private function validate(array $def, array $in, int $id): array
    {
        $errors = [];
        $data = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = $def['name_label'] . ' is required.';
        } elseif (mb_strlen($name) > 150) {
            $errors['name'] = $def['name_label'] . ' must not exceed 150 characters.';
        }
        $data['name'] = $name;

        foreach ($def['fields'] as $field => $f) {
            $raw = $in[$field] ?? '';
            $raw = is_string($raw) ? trim($raw) : '';
            $label = $f['label'];
            switch ($f['type']) {
                case 'text':
                case 'textarea':
                    if (!empty($f['required']) && $raw === '') {
                        $errors[$field] = $label . ' is required.';
                    } elseif (mb_strlen($raw) > ($f['max'] ?? 255)) {
                        $errors[$field] = $label . ' must not exceed ' . ($f['max'] ?? 255) . ' characters.';
                    }
                    $data[$field] = $raw === '' ? null : $raw;
                    break;
                case 'int':
                    if ($raw === '') {
                        $data[$field] = (int) ($f['default'] ?? 0);
                    } elseif (!preg_match('/^\d+$/', $raw) || (int) $raw > 65000) {
                        $errors[$field] = $label . ' must be a whole number.';
                    } else {
                        $data[$field] = (int) $raw;
                    }
                    break;
                case 'decimal':
                case 'money':
                    if ($raw === '') {
                        $data[$field] = (float) ($f['default'] ?? 0);
                    } elseif (!is_numeric($raw) || (float) $raw < ($f['min'] ?? 0) || (float) $raw > 99999999) {
                        $errors[$field] = $label . ' must be a number of at least ' . num($f['min'] ?? 0) . '.';
                    } else {
                        $data[$field] = round((float) $raw, 2);
                    }
                    break;
                case 'select':
                    if ($raw === '') {
                        $data[$field] = null;
                    } elseif (!DB::value('SELECT 1 FROM `' . $f['lookup'] . '` WHERE id = ? AND deleted_at IS NULL', [(int) $raw])) {
                        $errors[$field] = $label . ' is not valid.';
                    } else {
                        $data[$field] = (int) $raw;
                    }
                    break;
                case 'options':
                    $value = $raw === '' ? (string) ($f['default'] ?? '') : $raw;
                    if (!array_key_exists($value, $f['options'])) {
                        $errors[$field] = $label . ' has an invalid value.';
                    }
                    $data[$field] = $value;
                    break;
            }
        }

        $order = trim((string) ($in['display_order'] ?? '0'));
        if (!preg_match('/^\d{1,6}$/', $order)) {
            $errors['display_order'] = 'Display order must be a whole number.';
        }
        $data['display_order'] = (int) $order;
        $data['is_active'] = !empty($in['is_active']) ? 1 : 0;

        if (!isset($errors['name'])) {
            $where = 'deleted_at IS NULL AND id <> ?';
            $params = [$id];
            foreach ($def['unique'] as $col) {
                if ($data[$col] === null) {
                    $where .= ' AND `' . $col . '` IS NULL';
                } else {
                    $where .= ' AND LOWER(`' . $col . '`) = LOWER(?)';
                    $params[] = $data[$col];
                }
            }
            if (DB::value('SELECT 1 FROM `' . $def['table'] . '` WHERE ' . $where, $params)) {
                $errors['name'] = 'A ' . strtolower($def['singular']) . ' with this ' . strtolower($def['name_label'])
                    . (count($def['unique']) > 1 ? ' and ' . strtolower($def['fields'][$def['unique'][1]]['label']) : '') . ' already exists.';
            }
        }
        return [$data, $errors];
    }

    /** Extra activity lines for sensitive fields (price, source). */
    private function logFieldChanges(array $def, string $key, int $id, array $old, array $new): void
    {
        foreach ($def['log_fields'] ?? [] as $field => $action) {
            $before = $old[$field];
            $after = $new[$field];
            $changed = in_array($def['fields'][$field]['type'], ['money', 'decimal'], true)
                ? round((float) $before, 2) !== round((float) $after, 2)
                : (string) $before !== (string) $after;
            if (!$changed) {
                continue;
            }
            if ($def['fields'][$field]['type'] === 'select') {
                $opts = $this->lookupOptions($def['fields'][$field]['lookup']);
                $before = $before ? ($opts[(int) $before] ?? '#' . $before) : 'none';
                $after = $after ? ($opts[(int) $after] ?? '#' . $after) : 'none';
            } else {
                $before = num($before);
                $after = num($after);
            }
            ActivityLog::record($action, $key, (string) $id, $new['name'] . ': ' . $before . ' → ' . $after);
        }
    }
}
