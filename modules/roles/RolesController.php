<?php
declare(strict_types=1);

namespace App\Modules\Roles;

use App\Core\ActivityLog;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Rbac;
use App\Core\Seeder;

/**
 * Roles & permissions (Doc 1 §2.1, Doc 8 §1): the seed roles are a starting
 * point — the Super Admin edits them, composes custom roles, sets a base role
 * to inherit from, grants or denies each permission with a scope, and picks
 * the workspaces (menu + home screen) of the role.
 */
final class RolesController extends Controller
{
    public function index(): void
    {
        $rows = DB::all(
            'SELECT r.*, b.code AS base_code, b.name AS base_name, s.name AS specialty_name, w.name AS workspace_name,
                    (SELECT COUNT(DISTINCT x.user_id) FROM user_role_branch x JOIN users u ON u.id = x.user_id AND u.deleted_at IS NULL WHERE x.role_id = r.id) AS users,
                    (SELECT COUNT(*) FROM role_permission rp WHERE rp.role_id = r.id AND rp.effect = \'grant\') AS grants,
                    (SELECT COUNT(*) FROM role_permission rp WHERE rp.role_id = r.id AND rp.effect = \'deny\') AS denies
               FROM roles r LEFT JOIN roles b ON b.id = r.base_role_id LEFT JOIN specialties s ON s.id = r.specialty_id
               LEFT JOIN workspaces w ON w.id = r.default_workspace_id
              WHERE r.deleted_at IS NULL ORDER BY r.tier, r.display_order, r.name'
        );
        $this->view('roles/index', ['title' => 'Roles & Permissions', 'rows' => $rows]);
    }

    public function edit(): void
    {
        $id = (int) input('id', 0);
        $role = $id ? DB::one('SELECT * FROM roles WHERE id = ? AND deleted_at IS NULL', [$id]) : null;
        if ($id && !$role) {
            abort(404);
        }
        $errors = [];
        if (is_post()) {
            $code = strtoupper(trim((string) input('code', $role['code'] ?? '')));
            $name = trim((string) input('name', ''));
            $base = (int) input('base_role_id', 0) ?: null;
            if (!preg_match('/^[A-Z][A-Z0-9_]{1,11}$/', $code)) {
                $errors[] = 'Code: 2–12 capital letters / digits, e.g. NSS.';
            }
            if (DB::value('SELECT 1 FROM roles WHERE code = ? AND id <> ?', [$code, $id])) {
                $errors[] = 'Another role already uses code ' . $code . '.';
            }
            if ($name === '') {
                $errors[] = 'Role name is required.';
            }
            if ($base && $id && in_array($id, Rbac::chain($base), true)) {
                $errors[] = 'A role cannot inherit from itself (circular base role).';
            }
            $class = (string) input('user_class', 'staff');
            if (!isset(config('permissions.classes')[$class])) {
                $class = 'staff';
            }
            if (!$errors) {
                $data = [
                    'name' => mb_substr($name, 0, 100), 'description' => mb_substr((string) input('description', ''), 0, 500) ?: null,
                    'tier' => in_array(input('tier'), ['A', 'B', 'C', 'D'], true) ? (string) input('tier') : 'D', 'base_role_id' => $base,
                    'specialty_id' => (int) input('specialty_id', 0) ?: null, 'department_type' => (string) input('department_type', '') ?: null,
                    'user_class' => $class, 'session_timeout_min' => (int) input('session_timeout_min', 0) ?: null,
                    'requires_2fa' => input('requires_2fa') ? 1 : 0, 'requires_cosign' => input('requires_cosign') ? 1 : 0,
                    'discount_limit' => is_numeric(input('discount_limit')) ? (float) input('discount_limit') : null, 'is_active' => input('is_active') ? 1 : 0,
                ];
                if ($role) {
                    if (!(int) $role['is_system']) {
                        $data['code'] = $code;
                    }
                    DB::update('roles', $data + ['updated_at' => now(), 'updated_by' => \App\Core\Auth::id()], 'id = ?', [$id]);
                    [$o, $n] = ActivityLog::diff($role, $data);
                    ActivityLog::record('Role updated', 'roles', (string) $id, $role['code'] . ' — ' . $name, null, null, ['entity' => 'roles', 'old' => $o, 'new' => $n]);
                } else {
                    $id = DB::insert('roles', $data + ['code' => $code, 'is_system' => 0, 'display_order' => 900, 'created_at' => now(), 'created_by' => \App\Core\Auth::id()]);
                    ActivityLog::record('Role created', 'roles', (string) $id, $code . ' — ' . $name);
                }
                // Workspaces
                DB::run('DELETE FROM role_workspace WHERE role_id = ?', [$id]);
                $default = (int) input('default_workspace_id', 0);
                foreach (array_unique(array_map('intval', (array) ($_POST['workspaces'] ?? []))) as $w) {
                    DB::insert('role_workspace', ['role_id' => $id, 'workspace_id' => $w, 'is_default' => $w === $default ? 1 : 0]);
                }
                DB::update('roles', ['default_workspace_id' => $default ?: null], 'id = ?', [$id]);
                Rbac::bump();
                flash('success', 'Role saved.');
                redirect('roles/matrix', ['id' => $id]);
            }
        }
        $this->view('roles/edit', [
            'title'      => $role ? 'Edit Role — ' . $role['name'] : 'New Role',
            'role'       => $role,
            'errors'     => $errors,
            'roles'      => DB::pairs('SELECT id, CONCAT(name, " (", code, ")") FROM roles WHERE deleted_at IS NULL AND id <> ? ORDER BY tier, name', [$id]),
            'specialties'=> DB::pairs('SELECT id, name FROM specialties WHERE deleted_at IS NULL ORDER BY name'),
            'workspaces' => DB::all('SELECT * FROM workspaces WHERE is_active = 1 ORDER BY section, display_order'),
            'assigned'   => $id ? array_map('intval', DB::column('SELECT workspace_id FROM role_workspace WHERE role_id = ?', [$id])) : [],
        ]);
    }

    /** Permission matrix of one role: grant (with scope) / deny / inherit. */
    public function matrix(): void
    {
        $id = $this->requireId();
        $role = DB::one('SELECT * FROM roles WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$role) {
            abort(404);
        }
        if (is_post()) {
            $before = Rbac::rolePermissions($id);
            DB::transaction(static function () use ($id): void {
                DB::run('DELETE FROM role_permission WHERE role_id = ?', [$id]);
                $permIds = DB::pairs('SELECT code, id FROM permissions');
                foreach ((array) ($_POST['perm'] ?? []) as $code => $val) {
                    if (!isset($permIds[$code]) || $val === '' || $val === 'inherit') {
                        continue;
                    }
                    if ($val === 'deny') {
                        DB::insert('role_permission', ['role_id' => $id, 'permission_id' => $permIds[$code], 'scope' => 'branch', 'effect' => 'deny']);
                    } elseif (isset(Rbac::SCOPES[$val])) {
                        DB::insert('role_permission', ['role_id' => $id, 'permission_id' => $permIds[$code], 'scope' => $val, 'effect' => 'grant']);
                    }
                }
            });
            Rbac::bump();
            $after = Rbac::rolePermissions($id);
            $added = array_diff_assoc($after, $before);
            $removed = array_diff_key($before, $after);
            ActivityLog::record('Role permissions changed', 'roles', (string) $id, $role['code'] . ': +' . count($added) . ' / −' . count($removed),
                null, null, ['entity' => 'role_permission', 'old' => $removed, 'new' => $added]);
            flash('success', 'Permissions of ' . $role['name'] . ' saved. They apply at the users\' next page load.');
            redirect('roles/matrix', ['id' => $id]);
        }
        $own = [];
        foreach (DB::all('SELECT p.code, rp.scope, rp.effect FROM role_permission rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?', [$id]) as $r) {
            $own[$r['code']] = $r['effect'] === 'deny' ? 'deny' : $r['scope'];
        }
        $inherited = $role['base_role_id'] ? Rbac::rolePermissions((int) $role['base_role_id']) : [];
        $perms = [];
        foreach (DB::all('SELECT * FROM permissions ORDER BY sort_order') as $p) {
            $perms[$p['module']][] = $p;
        }
        $this->view('roles/matrix', [
            'title'     => 'Permissions — ' . $role['name'],
            'role'      => $role,
            'own'       => $own,
            'inherited' => $inherited,
            'effective' => Rbac::rolePermissions($id),
            'perms'     => $perms,
            'base'      => $role['base_role_id'] ? Rbac::role((int) $role['base_role_id']) : null,
        ]);
    }

    public function reset(): void
    {
        $id = $this->requireId();
        $role = DB::one('SELECT * FROM roles WHERE id = ?', [$id]);
        if (!$role || !isset(config('permissions.roles')[$role['code']])) {
            flash('error', 'Only seed roles can be reset to their defaults.');
            redirect('roles');
        }
        Seeder::roles(true, $role['code']);
        Rbac::bump();
        ActivityLog::record('Role reset', 'roles', (string) $id, $role['code'] . ' reset to default permissions');
        flash('success', $role['name'] . ' was reset to its default permissions.');
        redirect('roles/matrix', ['id' => $id]);
    }

    public function delete(): void
    {
        $id = $this->requireId();
        $role = DB::one('SELECT * FROM roles WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$role || (int) $role['is_system']) {
            flash('error', 'Seed roles cannot be deleted — deactivate them instead.');
            redirect('roles');
        }
        if (DB::value('SELECT 1 FROM user_role_branch WHERE role_id = ?', [$id]) || DB::value('SELECT 1 FROM roles WHERE base_role_id = ? AND deleted_at IS NULL', [$id])) {
            flash('error', 'This role is assigned to users or used as a base role.');
            redirect('roles');
        }
        DB::update('roles', ['deleted_at' => now(), 'deleted_by' => \App\Core\Auth::id(), 'code' => substr($role['code'], 0, 5) . '#' . $id], 'id = ?', [$id]);
        Rbac::bump();
        ActivityLog::record('Role deleted', 'roles', (string) $id, $role['code']);
        flash('success', 'Role deleted.');
        redirect('roles');
    }
}
