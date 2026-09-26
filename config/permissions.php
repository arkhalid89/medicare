<?php
/**
 * Role => permission map.
 *
 * '*' grants everything EXCEPT the permissions listed in 'doctor_only',
 * which belong to the Doctor role alone (templates and the doctor profile
 * are doctor-specific by BRD §55).
 */
return [
    'roles' => [
        'super_admin' => [
            'label'       => 'Super Admin',
            'permissions' => ['*'],
        ],
        'dept_admin' => [
            'label'       => 'Department Admin',
            'permissions' => [
                'dashboard.view', 'search.use',
                'patients.view', 'visits.view', 'visits.print',
                'reports.view',
                'masters.view', 'masters.manage',
                'users.view',
                'print_designs.manage',
                'backup.create',
                'activity.view',
            ],
        ],
        'doctor' => [
            'label'       => 'Doctor',
            'permissions' => [
                'dashboard.view', 'search.use',
                'patients.view', 'patients.create', 'patients.edit',
                'visits.view', 'visits.create', 'visits.print',
                'templates.manage',
                'reports.view',
                'profile.doctor',
            ],
        ],
        'reporting' => [
            'label'       => 'Reporting User',
            'permissions' => [
                'dashboard.view', 'search.use',
                'patients.view', 'visits.view', 'visits.print',
                'reports.view',
            ],
        ],
    ],

    'doctor_only' => ['templates.manage', 'profile.doctor'],

    // Human readable list (shown on the Users screen).
    'labels' => [
        'dashboard.view'       => 'View dashboard',
        'search.use'           => 'Global search',
        'patients.view'        => 'View patients',
        'patients.create'      => 'Register patients',
        'patients.edit'        => 'Edit patients',
        'visits.view'          => 'View visits',
        'visits.create'        => 'Consultation / checkout',
        'visits.print'         => 'Print prescriptions',
        'templates.manage'     => 'Favourite templates',
        'reports.view'         => 'Reports & Excel export',
        'masters.view'         => 'View master data',
        'masters.manage'       => 'Add / edit / activate master data',
        'departments.manage'   => 'Manage departments',
        'users.view'           => 'View users',
        'users.manage'         => 'Manage users',
        'print_designs.manage' => 'Prescription print designs',
        'settings.manage'      => 'System settings',
        'backup.create'        => 'Create & download backup',
        'backup.restore'       => 'Restore backup',
        'db.reset'             => 'Reset database',
        'records.delete'       => 'Soft delete & recycle bin',
        'activity.view'        => 'Activity logs',
        'profile.doctor'       => 'Doctor profile',
    ],
];
