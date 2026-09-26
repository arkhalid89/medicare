<?php
/**
 * Sidebar menu. Items are shown only when the user holds 'perm'.
 * 'match' lists route prefixes that keep the item highlighted.
 */
return [
    ['label' => 'Dashboard', 'icon' => 'home', 'route' => 'dashboard', 'perm' => 'dashboard.view'],

    ['section' => 'Clinical'],
    ['label' => 'New Visit', 'icon' => 'stethoscope', 'route' => 'visits/new', 'perm' => 'visits.create', 'highlight' => true],
    ['label' => 'Patients', 'icon' => 'users', 'route' => 'patients', 'perm' => 'patients.view', 'match' => ['patients']],
    ['label' => 'Visits', 'icon' => 'calendar', 'route' => 'visits', 'perm' => 'visits.view', 'match' => ['visits/view', 'visits/print']],
    ['label' => 'Favourite Templates', 'icon' => 'star', 'route' => 'templates', 'perm' => 'templates.manage', 'match' => ['templates']],

    ['section' => 'Reports'],
    ['label' => 'Reports', 'icon' => 'chart', 'perm' => 'reports.view', 'children' => [
        ['label' => 'Patient Visit Report', 'route' => 'reports/visits', 'perm' => 'reports.view'],
        ['label' => 'Fee Report', 'route' => 'reports/fees', 'perm' => 'reports.view'],
    ]],

    ['section' => 'Configuration'],
    ['label' => 'Master Data', 'icon' => 'layers', 'perm' => 'masters.view', 'children' => [
        ['label' => 'Medicines', 'route' => 'masters/medicines', 'perm' => 'masters.view'],
        ['label' => 'Medicine Sources', 'route' => 'masters/medicine_sources', 'perm' => 'masters.view'],
        ['label' => 'Medicine–Source Mapping', 'route' => 'masters/mapping', 'perm' => 'masters.view'],
        ['label' => 'Presenting Complaints', 'route' => 'masters/presenting_complaints', 'perm' => 'masters.view'],
        ['label' => 'Symptoms', 'route' => 'masters/symptoms', 'perm' => 'masters.view'],
        ['label' => 'Diagnoses', 'route' => 'masters/diagnoses', 'perm' => 'masters.view'],
        ['label' => 'Investigations', 'route' => 'masters/investigations', 'perm' => 'masters.view'],
        ['label' => 'Routes', 'route' => 'masters/routes', 'perm' => 'masters.view'],
        ['label' => 'Dosage Forms', 'route' => 'masters/dosage_forms', 'perm' => 'masters.view'],
        ['label' => 'Frequencies', 'route' => 'masters/frequencies', 'perm' => 'masters.view'],
        ['label' => 'Instructions', 'route' => 'masters/instructions', 'perm' => 'masters.view'],
        ['label' => 'Departments', 'route' => 'masters/departments', 'perm' => 'departments.manage'],
    ]],
    ['label' => 'Settings', 'icon' => 'settings', 'perm' => 'print_designs.manage', 'children' => [
        ['label' => 'Organization & System', 'route' => 'settings/general', 'perm' => 'settings.manage'],
        ['label' => 'Codes & Patterns', 'route' => 'settings/codes', 'perm' => 'settings.manage'],
        ['label' => 'Rx Split', 'route' => 'settings/rx', 'perm' => 'settings.manage'],
        ['label' => 'Prescription Designs', 'route' => 'settings/print', 'perm' => 'print_designs.manage'],
        ['label' => 'Theme', 'route' => 'settings/theme', 'perm' => 'settings.manage'],
    ]],

    ['section' => 'Administration'],
    ['label' => 'Users', 'icon' => 'user-cog', 'route' => 'users', 'perm' => 'users.view', 'match' => ['users']],
    ['label' => 'Backup & Restore', 'icon' => 'database', 'route' => 'system/backup', 'perm' => 'backup.create'],
    ['label' => 'Recycle Bin', 'icon' => 'trash', 'route' => 'system/recycle', 'perm' => 'records.delete'],
    ['label' => 'Activity Logs', 'icon' => 'list', 'route' => 'system/logs', 'perm' => 'activity.view'],
    ['label' => 'Database Reset', 'icon' => 'alert', 'route' => 'system/reset', 'perm' => 'db.reset'],
];
