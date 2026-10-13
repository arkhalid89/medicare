<?php
declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\CodeGenerator;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Paginator;
use App\Core\Password;
use App\Core\Rbac;
use App\Core\Validator;

/**
 * User management (BRD §27, Doc 1 §1 note, Doc 8 §6): account, one or more
 * roles per branch and department, department membership, doctor profile &
 * MRN pattern, activate/deactivate, reset password, soft delete, Excel.
 */
final class UserController extends Controller
{
    public function index(): void
    {
        [$where, $params, $filters] = $this->filters();
        $base = ' FROM users u LEFT JOIN doctor_profiles dp ON dp.user_id = u.id WHERE ' . $where;
        $select = "SELECT u.id, u.employee_code, u.name, u.username, u.mobile, u.email, u.cnic, u.role, u.is_active, u.last_login_at, u.created_at,
                          u.two_factor_enabled, dp.specialization, dp.pmdc_registration,
                          (SELECT GROUP_CONCAT(d.name ORDER BY d.display_order, d.name SEPARATOR ', ')
                             FROM user_departments ud JOIN departments d ON d.id = ud.department_id AND d.deleted_at IS NULL
                            WHERE ud.user_id = u.id) AS departments,
                          (SELECT GROUP_CONCAT(DISTINCT r.name ORDER BY x.is_primary DESC, r.name SEPARATOR ', ')
                             FROM user_role_branch x JOIN roles r ON r.id = x.role_id WHERE x.user_id = u.id) AS roles";
        $order = " ORDER BY FIELD(u.role, 'super_admin', 'dept_admin', 'doctor', 'reporting', 'staff'), u.name";

        if ($this->wantsExport()) {
            $this->export('users', 'Users', [
                'employee_code' => 'Employee Code',
                'name'          => 'Name',
                'username'      => 'Username',
                'role'          => ['User Type', static fn ($r) => role_label($r['role'])],
                'roles'         => 'Roles',
                'departments'   => 'Departments',
                'mobile'        => 'Mobile',
                'email'         => 'Email',
                'cnic'          => 'CNIC',
                'specialization'=> 'Specialization',
                'is_active'     => ['Status', static fn ($r) => (int) $r['is_active'] ? 'Active' : 'Inactive'],
                'last_login_at' => ['Last Login', static fn ($r) => fmt_datetime($r['last_login_at'])],
            ], DB::run($select . $base . $order, $params), $filters);
            return;
        }

        $pager = new Paginator((int) DB::value('SELECT COUNT(*)' . $base, $params));
        $this->view('users/index', [
            'title'       => 'Users',
            'rows'        => DB::all($select . $base . $order . $pager->limitSql(), $params),
            'pager'       => $pager,
            'departments' => $this->departmentOptions(),
            'roles'       => DB::pairs('SELECT code, name FROM roles WHERE deleted_at IS NULL ORDER BY tier, display_order, name'),
            'canManage'   => can('users.manage'),
            'canDelete'   => can('records.delete'),
        ]);
    }

    public function show(): void
    {
        $id = $this->requireId();
        $user = $this->findVisible($id);
        $departments = DB::column(
            'SELECT d.name FROM departments d JOIN user_departments ud ON ud.department_id = d.id WHERE ud.user_id = ? AND d.deleted_at IS NULL ORDER BY d.display_order, d.name',
            [$id]
        );
        $stats = $user['role'] === 'doctor' ? DB::one(
            'SELECT COUNT(*) AS visits, COUNT(DISTINCT patient_id) AS patients, COALESCE(SUM(consultation_fee), 0) AS fee FROM visits WHERE doctor_id = ? AND deleted_at IS NULL',
            [$id]
        ) : null;
        $activity = DB::all('SELECT action, description, created_at FROM activity_logs WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$id]);
        $this->view('users/view', [
            'title' => $user['name'], 'u' => $user, 'departments' => $departments, 'stats' => $stats, 'activity' => $activity,
            'assignments' => Rbac::assignments($id),
        ]);
    }

    public function form(): void
    {
        $id = (int) input('id', 0);
        $user = null;
        $userDepartments = [];
        $assignments = [];
        if ($id > 0) {
            $user = DB::one('SELECT u.*, dp.pmdc_registration, dp.qualification, dp.specialization, dp.mrn_pattern, dp.default_fee, dp.signature_text
                               FROM users u LEFT JOIN doctor_profiles dp ON dp.user_id = u.id WHERE u.id = ? AND u.deleted_at IS NULL', [$id]);
            if (!$user) {
                abort(404, 'User not found.');
            }
            $this->findVisible($id);
            $userDepartments = array_map('intval', DB::column('SELECT department_id FROM user_departments WHERE user_id = ?', [$id]));
            foreach (Rbac::assignments($id) as $a) {
                $assignments[] = ['role_id' => (int) $a['role_id'], 'branch_id' => $a['branch_id'] !== null ? (int) $a['branch_id'] : null,
                    'department_id' => $a['department_id'] !== null ? (int) $a['department_id'] : null, 'is_primary' => (bool) $a['is_primary']];
            }
        }

        $errors = [];
        if (is_post()) {
            [$data, $profile, $departments, $rows, $errors] = $this->validate($_POST, $user);
            $assignments = $rows;
            if (!$errors) {
                $id = DB::transaction(function () use ($user, $data, $profile, $departments, $rows): int {
                    $primaryCode = (string) DB::value('SELECT code FROM roles WHERE id = ?', [array_values(array_filter($rows, static fn ($r) => $r['is_primary']))[0]['role_id'] ?? $rows[0]['role_id']]);
                    if ($user) {
                        $id = (int) $user['id'];
                        DB::update('users', $data + ['updated_at' => now(), 'updated_by' => Auth::id()], 'id = ?', [$id]);
                        ActivityLog::record('User updated', 'users', (string) $id, 'Updated user ' . $data['username'] . ' (' . role_label($data['role']) . ')');
                        if (isset($data['password'])) {
                            ActivityLog::record('Password reset', 'users', (string) $id, 'Password changed for ' . $data['username']);
                        }
                    } else {
                        $data['employee_code'] = UserService::nextEmployeeCode($data['role'], $departments, $primaryCode);
                        $id = DB::insert('users', $data + ['created_at' => now(), 'created_by' => Auth::id()]);
                        ActivityLog::record('User created', 'users', (string) $id, 'Created user ' . $data['username'] . ' (' . role_label($data['role']) . ', ' . $data['employee_code'] . ')');
                    }
                    DB::run('DELETE FROM user_departments WHERE user_id = ?', [$id]);
                    foreach ($departments as $deptId) {
                        DB::insert('user_departments', ['user_id' => $id, 'department_id' => $deptId]);
                    }
                    $before = $user ? array_map(static fn ($a) => $a['role_code'] . '@' . ($a['branch_name'] ?? 'all') . '/' . ($a['department_name'] ?? 'all'), Rbac::assignments($id)) : [];
                    UserService::saveAssignments($id, $rows);
                    $after = array_map(static fn ($a) => $a['role_code'] . '@' . ($a['branch_name'] ?? 'all') . '/' . ($a['department_name'] ?? 'all'), Rbac::assignments($id));
                    if ($before !== $after) {
                        ActivityLog::record('Roles changed', 'users', (string) $id, 'Roles of ' . $data['username'] . ': ' . implode(', ', $after), null, null,
                            ['entity' => 'users', 'old' => $before, 'new' => $after]);
                    }
                    if ($data['role'] === 'doctor') {
                        UserService::ensureDoctorSetup($id);
                        DB::update('doctor_profiles', $profile + ['updated_at' => now()], 'user_id = ?', [$id]);
                    }
                    return $id;
                });
                flash('success', 'User "' . $data['name'] . '" saved successfully.');
                redirect('users/view', ['id' => $id]);
            }
        }

        $this->view('users/form', [
            'title'           => $user ? 'Edit User' : 'Add User',
            'user'            => $user,
            'userDepartments' => is_post() ? array_map('intval', (array) ($_POST['departments'] ?? [])) : $userDepartments,
            'departments'     => $this->departmentOptions(),
            'branches'        => DB::pairs('SELECT id, name FROM branches WHERE deleted_at IS NULL AND is_active = 1 ORDER BY display_order, name'),
            'roles'           => UserService::assignableRoles(),
            'assignments'     => $assignments,
            'errors'          => array_values($errors),
            'fieldErrors'     => $errors,
            'nextCode'        => CodeGenerator::preview('emp', (string) setting('employee_code_pattern'), ['DEPT' => 'DEPT', 'ROLE' => 'XX']),
        ]);
    }

    public function toggle(): void
    {
        $id = $this->requireId();
        $user = DB::one('SELECT id, name, username, role, is_active FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$user) {
            abort(404);
        }
        $this->findVisible($id);
        if ($id === Auth::id()) {
            flash('error', 'You cannot deactivate your own account.');
            back('users');
        }
        if ((int) $user['is_active'] === 1 && $user['role'] === 'super_admin' && UserService::otherActiveSuperAdmins($id) === 0) {
            flash('error', 'The last active Super Admin cannot be deactivated.');
            back('users');
        }
        $new = (int) $user['is_active'] === 1 ? 0 : 1;
        DB::update('users', ['is_active' => $new, 'updated_at' => now(), 'updated_by' => Auth::id()], 'id = ?', [$id]);
        ActivityLog::record($new ? 'User activated' : 'User deactivated', 'users', (string) $id, $user['name'] . ' (' . $user['username'] . ')');
        flash('success', 'User "' . $user['name'] . '" ' . ($new ? 'activated' : 'deactivated') . '.');
        back('users');
    }

    public function resetPassword(): void
    {
        $id = $this->requireId();
        $user = DB::one('SELECT id, name, username FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$user) {
            abort(404);
        }
        $this->findVisible($id);
        $password = (string) ($_POST['new_password'] ?? '');
        if (mb_strlen($password) < Password::minLength() || mb_strlen($password) > 100) {
            flash('error', 'Password must be ' . Password::minLength() . ' to 100 characters.');
            back('users');
        }
        DB::update('users', ['password' => Password::hash($password), 'password_changed_at' => now(), 'updated_at' => now(), 'updated_by' => Auth::id()], 'id = ?', [$id]);
        ActivityLog::record('Password reset', 'users', (string) $id, 'Password reset for ' . $user['username']);
        flash('success', 'Password for "' . $user['name'] . '" has been reset.');
        back('users');
    }

    /** Clears a user's authenticator (lost phone). */
    public function resetTwoFactor(): void
    {
        $id = $this->requireId();
        $user = DB::one('SELECT id, name, username FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$user) {
            abort(404);
        }
        $this->findVisible($id);
        DB::update('users', ['two_factor_enabled' => 0, 'two_factor_secret' => null, 'updated_at' => now(), 'updated_by' => Auth::id()], 'id = ?', [$id]);
        ActivityLog::record('2FA reset', 'users', (string) $id, 'Two-factor authentication cleared for ' . $user['username']);
        flash('success', 'Two-factor authentication of "' . $user['name'] . '" was cleared. They can set it up again at next login.');
        back('users');
    }

    public function delete(): void
    {
        $id = $this->requireId();
        $user = DB::one('SELECT id, name, username, role FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$user) {
            abort(404);
        }
        if ($id === Auth::id()) {
            flash('error', 'You cannot delete your own account.');
            back('users');
        }
        if ($user['role'] === 'super_admin' && UserService::otherActiveSuperAdmins($id) === 0) {
            flash('error', 'The last active Super Admin cannot be deleted.');
            back('users');
        }
        DB::update('users', ['deleted_at' => now(), 'deleted_by' => Auth::id()], 'id = ?', [$id]);
        ActivityLog::record('User deleted', 'users', (string) $id, 'Moved to Recycle Bin: ' . $user['name'] . ' (' . $user['username'] . ')');
        flash('success', 'User "' . $user['name'] . '" deleted. It can be restored from the Recycle Bin.');
        redirect('users');
    }

    // ------------------------------------------------------------------

    /** @return array{0:string, 1:array, 2:array} */
    private function filters(): array
    {
        $where = 'u.deleted_at IS NULL';
        $params = [];
        $q = trim((string) input('q', ''));
        $role = (string) input('role', '');
        $status = (string) input('status', '');
        $dept = (int) input('department', 0);

        if (Auth::scope('users.view') === 'department') {
            $ids = Auth::user()['department_ids'] ?: [0];
            $where .= ' AND EXISTS (SELECT 1 FROM user_departments x WHERE x.user_id = u.id AND x.department_id IN (' . DB::placeholders($ids) . '))';
            array_push($params, ...$ids);
        }
        if ($q !== '') {
            $like = '%' . like_escape($q) . '%';
            $where .= ' AND (u.name LIKE ? OR u.username LIKE ? OR u.employee_code LIKE ? OR u.mobile LIKE ? OR u.cnic LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $roleName = '';
        if ($role !== '' && ($roleName = (string) DB::value('SELECT name FROM roles WHERE code = ?', [$role])) !== '') {
            $where .= ' AND EXISTS (SELECT 1 FROM user_role_branch z JOIN roles zr ON zr.id = z.role_id WHERE z.user_id = u.id AND zr.code = ?)';
            $params[] = $role;
        } elseif (isset(config('permissions.classes')[$role])) {
            $where .= ' AND u.role = ?';
            $params[] = $role;
            $roleName = role_label($role);
        }
        if ($status === 'active' || $status === 'inactive') {
            $where .= ' AND u.is_active = ?';
            $params[] = $status === 'active' ? 1 : 0;
        }
        if ($dept > 0) {
            $where .= ' AND (EXISTS (SELECT 1 FROM user_departments y WHERE y.user_id = u.id AND y.department_id = ?)
                         OR EXISTS (SELECT 1 FROM user_role_branch y2 WHERE y2.user_id = u.id AND y2.department_id = ?))';
            array_push($params, $dept, $dept);
        }
        $deptName = $dept ? ($this->departmentOptions()[$dept] ?? '') : '';
        return [$where, $params, ['Search' => $q, 'Role' => $roleName, 'Status' => ucfirst($status), 'Department' => $deptName]];
    }

    private function findVisible(int $id): array
    {
        $user = DB::one('SELECT u.*, dp.pmdc_registration, dp.qualification, dp.specialization, dp.mrn_pattern, dp.default_fee, dp.signature_text
                           FROM users u LEFT JOIN doctor_profiles dp ON dp.user_id = u.id WHERE u.id = ? AND u.deleted_at IS NULL', [$id]);
        if (!$user) {
            abort(404, 'User not found.');
        }
        if (Auth::scope('users.view') === 'department') {
            $shared = array_intersect(Auth::user()['department_ids'], array_map('intval', DB::column('SELECT department_id FROM user_departments WHERE user_id = ?', [$id])));
            if (!$shared) {
                abort(403);
            }
        }
        // Only role administrators may edit a chain-level account (SA / ITA / AUD).
        if (!can('roles.manage') && DB::value("SELECT 1 FROM user_role_branch x JOIN roles r ON r.id = x.role_id WHERE x.user_id = ? AND r.tier = 'A'", [$id])
            && in_array(\App\Core\Router::current(), ['users/form', 'users/toggle', 'users/password', 'users/2fa-reset'], true)) {
            abort(403);
        }
        return $user;
    }

    private function departmentOptions(): array
    {
        return array_map('strval', DB::pairs('SELECT id, name FROM departments WHERE deleted_at IS NULL ORDER BY is_active DESC, display_order, name'));
    }

    /** @return array{0: array, 1: array, 2: int[], 3: array, 4: array} */
    private function validate(array $in, ?array $existing): array
    {
        $v = new Validator($in);
        $v->required('name', 'Name')->max('name', 'Name', 150)
            ->required('username', 'Username')->max('username', 'Username', 60)
            ->phone('mobile', 'Mobile')->email('email', 'Email')->max('email', 'Email', 150)
            ->cnic('cnic', 'CNIC');

        $username = trim((string) ($in['username'] ?? ''));
        if ($username !== '' && !preg_match('/^[A-Za-z0-9._\-]{3,60}$/', $username)) {
            $v->add('username', 'Username must be 3–60 characters: letters, digits, dot, dash or underscore.');
        }
        if ($username !== '' && DB::value('SELECT 1 FROM users WHERE username = ? AND id <> ?', [$username, $existing['id'] ?? 0])) {
            $v->add('username', 'This username is already taken (also check the Recycle Bin).');
        }

        $password = (string) ($in['password'] ?? '');
        if (!$existing || $password !== '') {
            if ($password === '') {
                $v->add('password', 'Password is required.');
            } elseif (mb_strlen($password) < Password::minLength() || mb_strlen($password) > 100) {
                $v->add('password', 'Password must be ' . Password::minLength() . ' to 100 characters.');
            }
        }

        // ---- Role assignments ------------------------------------------------
        $allowed = [];
        foreach (UserService::assignableRoles() as $group) {
            foreach ($group as $r) {
                $allowed[$r['id']] = $r;
            }
        }
        $branches = array_map('intval', array_keys(DB::pairs('SELECT id, name FROM branches WHERE deleted_at IS NULL')));
        $validDepts = array_map('intval', array_keys($this->departmentOptions()));
        $rows = [];
        $seen = [];
        $primaryIdx = (int) ($in['primary'] ?? 0);
        foreach (array_values((array) ($in['assign'] ?? [])) as $i => $a) {
            $roleId = (int) ($a['role_id'] ?? 0);
            if ($roleId <= 0) {
                continue;
            }
            $existingRow = $existing ? (bool) DB::value('SELECT 1 FROM user_role_branch WHERE user_id = ? AND role_id = ?', [$existing['id'], $roleId]) : false;
            if (!isset($allowed[$roleId]) && !$existingRow) {
                $v->add('assign', 'You are not allowed to assign one of the selected roles.');
                continue;
            }
            $branchRaw = (string) ($a['branch_id'] ?? '');
            $branchId = $branchRaw === '' ? null : (int) $branchRaw;
            if ($branchId !== null && !in_array($branchId, $branches, true)) {
                $branchId = null;
            }
            $deptRaw = (string) ($a['department_id'] ?? '');
            $deptId = $deptRaw === '' ? null : (int) $deptRaw;
            if ($deptId !== null && !in_array($deptId, $validDepts, true)) {
                $deptId = null;
            }
            $key = $roleId . ':' . (int) $branchId . ':' . (int) $deptId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rows[] = ['role_id' => $roleId, 'branch_id' => $branchId, 'department_id' => $deptId, 'is_primary' => $i === $primaryIdx];
        }
        if (!$rows) {
            $v->add('assign', 'Assign at least one role.');
        } elseif (!array_filter($rows, static fn ($r) => $r['is_primary'])) {
            $rows[0]['is_primary'] = true;
        }
        $roleIds = array_column($rows, 'role_id');
        $primaryId = $rows ? (int) (array_values(array_filter($rows, static fn ($r) => $r['is_primary']))[0]['role_id'] ?? $rows[0]['role_id']) : null;
        $class = $rows ? UserService::classFor($roleIds, $primaryId) : 'staff';

        $isActive = !empty($in['is_active']) ? 1 : 0;
        if ($existing && (int) $existing['id'] === Auth::id()) {
            if ($class !== $existing['role']) {
                $v->add('assign', 'You cannot change your own user type (e.g. remove your own Super Admin role).');
            }
            if (!$isActive) {
                $v->add('is_active', 'You cannot deactivate your own account.');
            }
        }
        if ($existing && $existing['role'] === 'super_admin' && ($class !== 'super_admin' || !$isActive)
            && UserService::otherActiveSuperAdmins((int) $existing['id']) === 0) {
            $v->add('assign', 'This is the last active Super Admin; keep the Super Admin role and status.');
        }

        $departments = array_values(array_unique(array_filter(array_map('intval', (array) ($in['departments'] ?? [])), static fn ($d) => in_array($d, $validDepts, true))));
        $hasDa = (bool) array_filter($roleIds, static fn ($id) => (\App\Core\Rbac::role((int) $id)['code'] ?? '') === 'DA');
        if ($hasDa && !$departments) {
            $v->add('departments', 'Assign at least one department to a Department Admin.');
        }

        $profile = [];
        if ($class === 'doctor') {
            $v->max('pmdc_registration', 'PMDC registration', 50)->max('qualification', 'Qualification', 255)
                ->max('specialization', 'Specialization', 150)->numeric('default_fee', 'Default fee', 0, 10000000)
                ->max('signature_text', 'Signature text', 255);
            $pattern = trim((string) ($in['mrn_pattern'] ?? ''));
            if ($pattern !== '' && ($err = CodeGenerator::validate($pattern))) {
                $v->add('mrn_pattern', 'MRN pattern: ' . $err);
            }
            $profile = [
                'pmdc_registration' => trim((string) ($in['pmdc_registration'] ?? '')) ?: null,
                'qualification'     => trim((string) ($in['qualification'] ?? '')) ?: null,
                'specialization'    => trim((string) ($in['specialization'] ?? '')) ?: null,
                'mrn_pattern'       => $pattern ?: null,
                'default_fee'       => (float) ($in['default_fee'] ?? 0),
                'signature_text'    => trim((string) ($in['signature_text'] ?? '')) ?: null,
            ];
        }

        $data = [
            'name'      => trim((string) ($in['name'] ?? '')),
            'username'  => $username,
            'mobile'    => normalize_phone((string) ($in['mobile'] ?? '')),
            'email'     => trim((string) ($in['email'] ?? '')) ?: null,
            'cnic'      => normalize_cnic((string) ($in['cnic'] ?? '')),
            'role'      => $class,
            'is_active' => $isActive,
        ];
        if ($password !== '') {
            $data['password'] = Password::hash($password);
            $data['password_changed_at'] = now();
        }
        return [$data, $profile, $departments, $rows, $v->errors()];
    }
}
