<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Idempotent seeding of the v2 reference data, shared by the installer
 * (fresh install / factory reset) and the migrator (upgrade of a v1.x
 * database). Running any method twice never duplicates rows and never
 * overwrites what an administrator changed later.
 */
final class Seeder
{
    /**
     * Sample logins of a fresh install with sample users:
     * [name, username, password, role code, departments, mobile, doctor profile, email]
     */
    public const SAMPLE_USERS = [
        ['Ayesha Siddiqui', 'deptadmin', 'admin123', 'DA', ['General OPD', 'Internal Medicine'], '03000000002', null, null],
        ['Dr. Ahmed Raza', 'doctor', 'doctor123', 'CD', ['General OPD', 'Internal Medicine'], '03000000003',
            ['pmdc_registration' => '12345-P', 'qualification' => 'MBBS, FCPS (Medicine)', 'specialization' => 'Consultant Physician',
             'mrn_pattern' => 'DR01-{YYYY}-{000001}', 'default_fee' => 1500, 'signature_text' => 'Dr. Ahmed Raza, MBBS, FCPS'], null],
        ['Dr. Sana Malik', 'doctor2', 'doctor123', 'PED', ['Paediatrics'], '03000000004',
            ['pmdc_registration' => '67890-P', 'qualification' => 'MBBS, DCH', 'specialization' => 'Paediatrician',
             'mrn_pattern' => 'DOC2-{YYYY}-{000001}', 'default_fee' => 1200, 'signature_text' => 'Dr. Sana Malik, MBBS, DCH'], null],
        ['Bilal Ahmad', 'reporting', 'report123', 'RPT', [], '03000000005', null, null],
        ['Kamran Haider', 'hadmin', 'admin123', 'HA', [], '03000000006', null, null],
        ['Rabia Noor', 'reception', 'staff123', 'RC', ['Front Desk'], '03000000007', null, null],
        ['Usman Ghani', 'cashier', 'staff123', 'BC', ['Billing'], '03000000008', null, null],
        ['Nadia Perveen', 'nurse', 'staff123', 'SN', ['General OPD'], '03000000009', null, null],
        ['Faisal Mehmood', 'pharmacist', 'staff123', 'PH', ['Pharmacy'], '03000000010', null, null],
        ['Hassan Ali', 'store', 'staff123', 'SM', ['Central Store'], '03000000011', null, null],
        ['Bilal Hussain', 'labtech', 'staff123', 'LT', ['Laboratory'], '03000000012', null, null],
        ['Dr. Farah Javed', 'pathologist', 'staff123', 'PT', ['Laboratory'], '03000000013', null, null],
        ['Zubair Shah', 'radtech', 'staff123', 'RT', ['Radiology'], '03000000014', null, null],
        ['Dr. Omer Saleem', 'radiologist', 'staff123', 'RAD', ['Radiology'], '03000000015', null, null],
        ['Dr. Hira Aslam', 'dentist', 'doctor123', 'DEN', ['Dental'], '03000000016',
            ['pmdc_registration' => '22334-D', 'qualification' => 'BDS, FCPS (Operative Dentistry)', 'specialization' => 'Dental Surgeon',
             'mrn_pattern' => null, 'default_fee' => 800, 'signature_text' => 'Dr. Hira Aslam, BDS, FCPS'], null],
        ['Dr. Saima Tariq', 'gynae', 'doctor123', 'GYN', ['Gynecology / Obstetrics'], '03000000017',
            ['pmdc_registration' => '44556-P', 'qualification' => 'MBBS, FCPS (Obs & Gynae)', 'specialization' => 'Gynecologist / Obstetrician',
             'mrn_pattern' => null, 'default_fee' => 1500, 'signature_text' => 'Dr. Saima Tariq, MBBS, FCPS'], null],
        ['Shazia Bibi', 'midwife', 'staff123', 'MWF', ['Gynecology / Obstetrics'], '03000000018', null, null],
    ];

    /**
     * Department catalogs of every phase (fresh install). Each part checks
     * whether its tables exist, so the same call works at any schema version.
     */
    public static function modules(): void
    {
        foreach (['App\\Modules\\Inventory\\InventorySeeder', 'App\\Modules\\Lab\\LabSeeder', 'App\\Modules\\Radiology\\RadiologySeeder',
                  'App\\Modules\\Specialty\\SpecialtySeeder', 'App\\Modules\\Dental\\DentalSeeder', 'App\\Modules\\Obgyn\\ObgynSeeder'] as $class) {
            if (class_exists($class)) {
                $class::run();
            }
        }
        Rbac::bump();
    }

    /** Everything Phase 1 needs. */
    public static function platform(): void
    {
        self::permissions();
        self::roles();
        self::branch();
        self::specialties();
        self::departments();
        self::workspaces();
        self::services();
        self::visitTypes();
        self::legacyUsers();
        self::displayBoard();
        Rbac::bump();
    }

    // ------------------------------------------------------------------ RBAC

    public static function permissions(): void
    {
        $i = 0;
        $rows = config('permissions.permissions', []);
        $rows['*'] = ['admin', 'ALL permissions (Super Admin)', false];
        foreach ($rows as $code => $p) {
            $i++;
            $existing = DB::value('SELECT id FROM permissions WHERE code = ?', [$code]);
            $data = ['module' => $p[0], 'label' => $p[1], 'is_approval' => !empty($p[2]) ? 1 : 0, 'sort_order' => $i];
            if ($existing) {
                DB::update('permissions', $data, 'id = ?', [$existing]);
            } else {
                DB::insert('permissions', $data + ['code' => $code]);
            }
        }
    }

    /**
     * Creates missing roles with their default grants. Existing roles keep
     * their (possibly edited) grants unless $reset is true.
     */
    public static function roles(bool $reset = false, ?string $only = null): void
    {
        $defs = config('permissions.roles', []);
        $permIds = DB::pairs('SELECT code, id FROM permissions');
        $specIds = DB::pairs('SELECT code, id FROM specialties WHERE deleted_at IS NULL');
        $created = [];
        $order = 0;
        foreach ($defs as $code => $def) {
            $order++;
            if ($only !== null && $code !== $only) {
                continue;
            }
            $id = DB::value('SELECT id FROM roles WHERE code = ?', [$code]);
            $props = [
                'name'                => $def['name'],
                'description'         => $def['description'] ?? null,
                'tier'                => $def['tier'] ?? 'D',
                'department_type'     => $def['dept'] ?? null,
                'user_class'          => self::roleClass($code, $defs),
                'session_timeout_min' => $def['timeout'] ?? null,
                'requires_2fa'        => !empty($def['2fa']) ? 1 : 0,
                'requires_cosign'     => !empty($def['cosign']) ? 1 : 0,
                'discount_limit'      => $def['discount_limit'] ?? null,
                'specialty_id'        => isset($def['specialty']) ? ($specIds[$def['specialty']] ?? null) : null,
            ];
            if (!$id) {
                $id = DB::insert('roles', $props + ['code' => $code, 'is_system' => 1, 'is_active' => 1, 'display_order' => $order, 'created_at' => now()]);
                $created[] = $code;
            } elseif ($reset) {
                DB::update('roles', $props + ['updated_at' => now()], 'id = ?', [$id]);
            } elseif ($props['specialty_id'] !== null) {
                DB::run('UPDATE roles SET specialty_id = COALESCE(specialty_id, ?) WHERE id = ?', [$props['specialty_id'], $id]);
            }
        }
        // Base roles (second pass: the base may be defined after the role).
        foreach ($defs as $code => $def) {
            if (isset($def['base']) && ($only === null || $code === $only)) {
                $base = DB::value('SELECT id FROM roles WHERE code = ?', [$def['base']]);
                if ($base && (in_array($code, $created, true) || $reset)) {
                    DB::run('UPDATE roles SET base_role_id = ? WHERE code = ?', [$base, $code]);
                }
            }
        }
        foreach ($defs as $code => $def) {
            if (!in_array($code, $created, true) && !$reset) {
                continue;
            }
            if ($only !== null && $code !== $only) {
                continue;
            }
            $roleId = (int) DB::value('SELECT id FROM roles WHERE code = ?', [$code]);
            DB::run('DELETE FROM role_permission WHERE role_id = ?', [$roleId]);
            $defaultScope = $def['scope'] ?? 'branch';
            foreach ($def['grants'] ?? [] as $grant) {
                [$perm, $scope] = array_pad(explode('@', $grant, 2), 2, $defaultScope);
                if (!isset($permIds[$perm]) || !isset(Rbac::SCOPES[$scope])) {
                    continue;
                }
                DB::run('REPLACE INTO role_permission (role_id, permission_id, scope, effect) VALUES (?, ?, ?, ?)', [$roleId, $permIds[$perm], $scope, 'grant']);
            }
            foreach ($def['deny'] ?? [] as $perm) {
                if (isset($permIds[$perm])) {
                    DB::run('REPLACE INTO role_permission (role_id, permission_id, scope, effect) VALUES (?, ?, ?, ?)', [$roleId, $permIds[$perm], 'branch', 'deny']);
                }
            }
        }
        // Grants added to config for an EXISTING role in a later version are
        // added once (new permissions only; nothing is removed).
        if (!$reset) {
            $seen = json_decode((string) Settings::get('seeded_grants', '{}'), true) ?: [];
            foreach ($defs as $code => $def) {
                $roleId = (int) DB::value('SELECT id FROM roles WHERE code = ?', [$code]);
                if (!$roleId) {
                    continue;
                }
                $defaultScope = $def['scope'] ?? 'branch';
                foreach ($def['grants'] ?? [] as $grant) {
                    [$perm, $scope] = array_pad(explode('@', $grant, 2), 2, $defaultScope);
                    $key = $code . '|' . $perm;
                    if (isset($seen[$key]) || !isset($permIds[$perm]) || !isset(Rbac::SCOPES[$scope])) {
                        $seen[$key] = 1;
                        continue;
                    }
                    DB::run('INSERT IGNORE INTO role_permission (role_id, permission_id, scope, effect) VALUES (?, ?, ?, ?)', [$roleId, $permIds[$perm], $scope, 'grant']);
                    $seen[$key] = 1;
                }
            }
            Settings::set(['seeded_grants' => json_encode($seen)]);
        }
        Rbac::flush();
    }

    /** User class of a role: own 'class', else the base role's. */
    private static function roleClass(string $code, array $defs): string
    {
        for ($guard = 0; $guard < 10 && isset($defs[$code]); $guard++) {
            if (isset($defs[$code]['class'])) {
                return $defs[$code]['class'];
            }
            if (!isset($defs[$code]['base'])) {
                break;
            }
            $code = $defs[$code]['base'];
        }
        return 'staff';
    }

    public static function workspaces(): void
    {
        $order = 0;
        foreach (config('hospital.workspaces', []) as $code => $w) {
            [$name, $route, $icon, $section, $perm, $defaults, $also] = $w;
            $order++;
            $id = DB::value('SELECT id FROM workspaces WHERE code = ?', [$code]);
            if (!$id) {
                $id = DB::insert('workspaces', ['code' => $code, 'name' => $name, 'route' => $route, 'icon' => $icon, 'section' => $section,
                    'required_permission' => $perm, 'is_active' => 1, 'display_order' => $order]);
            } else {
                DB::update('workspaces', ['route' => $route, 'required_permission' => $perm], 'id = ?', [$id]);
            }
            foreach (array_merge($defaults, $also) as $roleCode) {
                $roleId = DB::value('SELECT id FROM roles WHERE code = ?', [$roleCode]);
                if (!$roleId) {
                    continue;
                }
                $isDefault = in_array($roleCode, $defaults, true) ? 1 : 0;
                DB::run('INSERT IGNORE INTO role_workspace (role_id, workspace_id, is_default) VALUES (?, ?, ?)', [$roleId, $id, $isDefault]);
                if ($isDefault) {
                    DB::run('UPDATE roles SET default_workspace_id = ? WHERE id = ? AND default_workspace_id IS NULL', [$id, $roleId]);
                }
            }
        }
    }

    // ------------------------------------------------------- reference data

    public static function branch(): int
    {
        $id = (int) DB::value('SELECT id FROM branches WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        if (!$id) {
            $id = DB::insert('branches', [
                'code' => 'MAIN', 'name' => (string) Settings::get('org_name', 'Main Branch'),
                'address' => (string) Settings::get('org_address', ''), 'phone' => (string) Settings::get('org_phone', ''),
                'license_no' => (string) Settings::get('org_phc_reg_no', '') ?: null, 'is_active' => 1, 'display_order' => 1, 'created_at' => now(),
            ]);
        }
        if (!(int) Settings::get('default_branch_id', 0)) {
            Settings::set(['default_branch_id' => (string) $id]);
        }
        // v1 rows get the default branch.
        foreach (['departments', 'visits', 'service_points'] as $table) {
            DB::run('UPDATE `' . $table . '` SET branch_id = ? WHERE branch_id IS NULL', [$id]);
        }
        DB::run('UPDATE patients SET created_branch_id = ? WHERE created_branch_id IS NULL', [$id]);
        return $id;
    }

    public static function specialties(): void
    {
        $order = 0;
        foreach (config('hospital.specialties', []) as $code => [$name, $module]) {
            $order++;
            if (!DB::value('SELECT 1 FROM specialties WHERE code = ?', [$code])) {
                DB::insert('specialties', ['code' => $code, 'name' => $name, 'module_key' => $module, 'is_active' => 1, 'display_order' => $order, 'created_at' => now()]);
            }
        }
    }

    /** Tags v1 departments and adds the hospital departments that are missing. */
    public static function departments(): void
    {
        $branch = (int) Settings::get('default_branch_id', 0) ?: null;
        $spec = DB::pairs('SELECT code, id FROM specialties WHERE deleted_at IS NULL');
        $order = (int) DB::value('SELECT COALESCE(MAX(display_order), 0) FROM departments');
        foreach (config('hospital.departments', []) as $name => [$code, $type, $specCode]) {
            $row = DB::one('SELECT id, specialty_id FROM departments WHERE (name = ? OR code = ?) AND deleted_at IS NULL LIMIT 1', [$name, $code]);
            $specId = $specCode ? ($spec[$specCode] ?? null) : null;
            if ($row) {
                DB::run('UPDATE departments SET department_type = ?, specialty_id = COALESCE(specialty_id, ?) WHERE id = ?', [$type, $specId, $row['id']]);
                continue;
            }
            DB::insert('departments', ['name' => $name, 'code' => $code, 'department_type' => $type, 'specialty_id' => $specId,
                'branch_id' => $branch, 'is_active' => 1, 'display_order' => ++$order, 'created_at' => now()]);
        }
        // Any other clinical department without a specialty becomes General Medicine.
        if (isset($spec['GEN'])) {
            DB::run("UPDATE departments SET specialty_id = ? WHERE specialty_id IS NULL AND department_type = 'clinical'", [$spec['GEN']]);
        }
    }

    public static function services(): void
    {
        $order = 0;
        foreach (config('hospital.services', []) as $code => [$name, $type, $price]) {
            $order++;
            if (DB::value('SELECT 1 FROM service_catalog WHERE code = ?', [$code])) {
                continue;
            }
            $id = DB::insert('service_catalog', ['code' => $code, 'name' => $name, 'service_type' => $type, 'default_price' => $price,
                'is_active' => 1, 'display_order' => $order, 'created_at' => now()]);
            DB::insert('service_prices', ['service_id' => $id, 'branch_id' => null, 'price_list_type' => 'cash', 'price' => $price,
                'valid_from' => '2000-01-01', 'created_at' => now()]);
        }
    }

    public static function visitTypes(): void
    {
        $order = 0;
        foreach (config('hospital.visit_types', []) as $code => [$name, $serviceCode, $window]) {
            $order++;
            if (DB::value('SELECT 1 FROM visit_types WHERE code = ?', [$code])) {
                continue;
            }
            DB::insert('visit_types', [
                'code' => $code, 'name' => $name,
                'default_service_id' => $serviceCode ? (DB::value('SELECT id FROM service_catalog WHERE code = ?', [$serviceCode]) ?: null) : null,
                'follow_up_window_days' => $window, 'is_active' => 1, 'display_order' => $order, 'created_at' => now(),
            ]);
        }
    }

    /** v1 users (users.role) get their role row; doctors stay doctors. */
    public static function legacyUsers(): void
    {
        $map = config('permissions.legacy_roles', []);
        foreach (DB::all('SELECT id, role FROM users') as $u) {
            if (DB::value('SELECT 1 FROM user_role_branch WHERE user_id = ?', [$u['id']])) {
                continue;
            }
            $code = $map[$u['role']] ?? null;
            $roleId = $code ? DB::value('SELECT id FROM roles WHERE code = ?', [$code]) : null;
            if (!$roleId) {
                continue;
            }
            $branch = in_array($code, ['SA', 'RPT', 'AUD', 'ITA'], true) ? null : ((int) Settings::get('default_branch_id', 0) ?: null);
            DB::insert('user_role_branch', ['user_id' => $u['id'], 'role_id' => $roleId, 'branch_id' => $branch, 'department_id' => null,
                'is_primary' => 1, 'created_at' => now()]);
            DB::run('UPDATE users SET primary_role_id = ? WHERE id = ?', [$roleId, $u['id']]);
        }
    }

    public static function displayBoard(): void
    {
        if (!DB::value('SELECT 1 FROM display_boards')) {
            DB::insert('display_boards', ['branch_id' => (int) Settings::get('default_branch_id', 0) ?: null, 'name' => 'Main waiting area',
                'location_text' => 'Ground floor', 'access_key' => bin2hex(random_bytes(16)), 'refresh_seconds' => 5, 'show_urdu' => 1,
                'is_active' => 1, 'display_order' => 1, 'created_at' => now()]);
        }
    }

    /** Assigns a role to a user (used by the seed, demo data and tests). */
    public static function assign(int $userId, string $roleCode, ?int $branchId = null, ?int $departmentId = null, bool $primary = false): void
    {
        $roleId = DB::value('SELECT id FROM roles WHERE code = ?', [$roleCode]);
        if (!$roleId) {
            throw new \InvalidArgumentException('Unknown role ' . $roleCode);
        }
        $exists = DB::value(
            'SELECT id FROM user_role_branch WHERE user_id = ? AND role_id = ? AND branch_id <=> ? AND department_id <=> ?',
            [$userId, $roleId, $branchId, $departmentId]
        );
        if (!$exists) {
            DB::insert('user_role_branch', ['user_id' => $userId, 'role_id' => $roleId, 'branch_id' => $branchId,
                'department_id' => $departmentId, 'is_primary' => $primary ? 1 : 0, 'created_at' => now()]);
        }
        if ($primary) {
            DB::run('UPDATE user_role_branch SET is_primary = IF(role_id = ? AND branch_id <=> ? AND department_id <=> ?, 1, 0) WHERE user_id = ?',
                [$roleId, $branchId, $departmentId, $userId]);
            $class = (string) DB::value('SELECT user_class FROM roles WHERE id = ?', [$roleId]);
            DB::run('UPDATE users SET primary_role_id = ?, role = ? WHERE id = ?', [$roleId, $class, $userId]);
        }
    }
}
