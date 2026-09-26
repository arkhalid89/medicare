<?php
/**
 * Master data definitions.
 *
 * Every master screen (list, search, filter, add, edit, activate/deactivate,
 * soft delete, Excel export) is generated from this file by the Masters
 * module, so all masters behave identically.
 *
 * Every master table has these columns in addition to the fields below:
 *   id, name, is_active, display_order, created_*, updated_*, deleted_*
 *
 * Field types: text | textarea | int | decimal | money | select | options
 *   select  => 'lookup' names another master table (FK, dropdown of active rows)
 *   options => 'options' is a fixed value => label list
 */
return [

    'medicines' => [
        'title'       => 'Medicines',
        'singular'    => 'Medicine',
        'icon'        => 'pill',
        'description' => 'Medicine catalogue with defaults, unit price and purchase source.',
        'unique'      => ['name', 'strength'],
        'name_label'  => 'Medicine Name',
        'fields'      => [
            'generic_name'     => ['label' => 'Generic Name', 'type' => 'text', 'max' => 150, 'list' => true, 'search' => true],
            'brand_name'       => ['label' => 'Brand Name', 'type' => 'text', 'max' => 150, 'search' => true],
            'strength'         => ['label' => 'Strength', 'type' => 'text', 'max' => 50, 'list' => true, 'placeholder' => '500mg'],
            'dosage_form_id'   => ['label' => 'Dosage Form', 'type' => 'select', 'lookup' => 'dosage_forms', 'list' => true],
            'route_id'         => ['label' => 'Default Route', 'type' => 'select', 'lookup' => 'routes'],
            'frequency_id'     => ['label' => 'Default Frequency', 'type' => 'select', 'lookup' => 'frequencies'],
            'instruction_id'   => ['label' => 'Default Instruction', 'type' => 'select', 'lookup' => 'instructions'],
            'default_dose'     => ['label' => 'Default Dose (per intake)', 'type' => 'decimal', 'min' => 0, 'default' => 1],
            'dose_unit'        => ['label' => 'Dose Unit', 'type' => 'text', 'max' => 30, 'placeholder' => 'tab / cap / ml / puff'],
            'default_duration' => ['label' => 'Default Duration (days)', 'type' => 'int', 'min' => 0, 'default' => 5],
            'unit'             => ['label' => 'Billing Unit', 'type' => 'text', 'max' => 30, 'placeholder' => 'Tablet / Bottle / Tube'],
            'pack_size'        => ['label' => 'Doses per Billing Unit', 'type' => 'decimal', 'min' => 0.01, 'default' => 1,
                                   'help' => '1 for tablets/capsules. For a 120 ml bottle dosed in ml enter 120, so 150 ml prescribed bills 2 bottles.'],
            'price'            => ['label' => 'Unit Price (PKR)', 'type' => 'money', 'min' => 0, 'list' => true, 'default' => 0],
            'source_id'        => ['label' => 'Source', 'type' => 'select', 'lookup' => 'medicine_sources', 'list' => true, 'filter' => true],
        ],
        // A change to these fields writes its own activity-log line (BRD §63).
        'log_fields'  => ['price' => 'Medicine price updated', 'source_id' => 'Medicine source changed'],
    ],

    'medicine_sources' => [
        'title'       => 'Medicine Sources',
        'singular'    => 'Medicine Source',
        'icon'        => 'truck',
        'description' => 'Where medicines are purchased from. Drives source-wise Rx splitting.',
        'unique'      => ['name'],
        'name_label'  => 'Source Name',
        'fields'      => [
            'short_code'     => ['label' => 'Short Code', 'type' => 'text', 'max' => 20, 'list' => true, 'search' => true],
            'contact_person' => ['label' => 'Contact Person', 'type' => 'text', 'max' => 100, 'list' => true],
            'contact_phone'  => ['label' => 'Contact Phone', 'type' => 'text', 'max' => 30, 'list' => true],
            'address'        => ['label' => 'Address', 'type' => 'textarea', 'max' => 255],
        ],
    ],

    'presenting_complaints' => [
        'title'       => 'Presenting Complaints',
        'singular'    => 'Presenting Complaint',
        'icon'        => 'message',
        'description' => 'Common presenting complaints offered on the consultation screen.',
        'unique'      => ['name'],
        'name_label'  => 'Complaint Name',
        'fields'      => [
            'description' => ['label' => 'Description', 'type' => 'textarea', 'max' => 500, 'list' => true],
        ],
    ],

    'symptoms' => [
        'title'       => 'Symptoms',
        'singular'    => 'Symptom',
        'icon'        => 'activity',
        'description' => 'Symptom master offered on the consultation screen.',
        'unique'      => ['name'],
        'name_label'  => 'Symptom Name',
        'fields'      => [
            'category'    => ['label' => 'Category', 'type' => 'text', 'max' => 80, 'list' => true, 'search' => true],
            'description' => ['label' => 'Description', 'type' => 'textarea', 'max' => 500],
        ],
    ],

    'diagnoses' => [
        'title'       => 'Diagnoses',
        'singular'    => 'Diagnosis',
        'icon'        => 'clipboard',
        'description' => 'Diagnosis list with optional ICD code.',
        'unique'      => ['name'],
        'name_label'  => 'Diagnosis Name',
        'fields'      => [
            'code'        => ['label' => 'Diagnosis Code', 'type' => 'text', 'max' => 30, 'list' => true, 'search' => true],
            'description' => ['label' => 'Description', 'type' => 'textarea', 'max' => 500],
        ],
    ],

    'investigations' => [
        'title'       => 'Investigations',
        'singular'    => 'Investigation',
        'icon'        => 'flask',
        'description' => 'Lab tests and imaging the doctor can advise.',
        'unique'      => ['name'],
        'name_label'  => 'Investigation Name',
        'fields'      => [
            'category'    => ['label' => 'Category', 'type' => 'text', 'max' => 80, 'list' => true, 'search' => true],
            'description' => ['label' => 'Description', 'type' => 'textarea', 'max' => 500],
        ],
    ],

    'routes' => [
        'title'       => 'Routes',
        'singular'    => 'Route',
        'icon'        => 'route',
        'description' => 'Routes of administration (Oral, IV, IM ...).',
        'unique'      => ['name'],
        'name_label'  => 'Route Name',
        'fields'      => [
            'short_name' => ['label' => 'Short Name', 'type' => 'text', 'max' => 20, 'list' => true],
        ],
    ],

    'dosage_forms' => [
        'title'       => 'Dosage Forms',
        'singular'    => 'Dosage Form',
        'icon'        => 'box',
        'description' => 'Tablet, Capsule, Syrup, Injection ...',
        'unique'      => ['name'],
        'name_label'  => 'Dosage Form',
        'fields'      => [
            'short_name' => ['label' => 'Short Name', 'type' => 'text', 'max' => 20, 'list' => true],
        ],
    ],

    'frequencies' => [
        'title'       => 'Frequencies',
        'singular'    => 'Frequency',
        'icon'        => 'clock',
        'description' => 'Dosing frequencies. "Doses" drives automatic quantity calculation.',
        'unique'      => ['name'],
        'name_label'  => 'Frequency Name',
        'fields'      => [
            'code'          => ['label' => 'Short Code', 'type' => 'text', 'max' => 20, 'list' => true, 'search' => true],
            'doses_per_day' => ['label' => 'Doses (per day, or per week for Weekly)', 'type' => 'decimal', 'min' => 0, 'list' => true, 'default' => 1],
            'calc_mode'     => ['label' => 'Quantity Rule', 'type' => 'options', 'list' => true, 'default' => 'daily', 'options' => [
                'daily'  => 'Daily — dose × doses/day × days',
                'weekly' => 'Weekly — dose × doses/week × weeks',
                'manual' => 'Manual — doctor enters quantity (SOS / PRN / Custom)',
            ]],
            'description'   => ['label' => 'Description', 'type' => 'textarea', 'max' => 255],
        ],
    ],

    'instructions' => [
        'title'       => 'Instructions',
        'singular'    => 'Instruction',
        'icon'        => 'info',
        'description' => 'Medicine instructions the doctor selects without typing.',
        'unique'      => ['name'],
        'name_label'  => 'Instruction',
        'fields'      => [
            'description' => ['label' => 'Description', 'type' => 'textarea', 'max' => 255, 'list' => true],
        ],
    ],

    'departments' => [
        'title'       => 'Departments',
        'singular'    => 'Department',
        'icon'        => 'building',
        'description' => 'Departments. Department Admins are assigned one or more departments.',
        'unique'      => ['name'],
        'name_label'  => 'Department Name',
        'perm_manage' => 'departments.manage',
        'perm_view'   => 'departments.manage',
        'fields'      => [
            'code'        => ['label' => 'Code', 'type' => 'text', 'max' => 20, 'list' => true, 'search' => true, 'placeholder' => 'OPD'],
            'description' => ['label' => 'Description', 'type' => 'textarea', 'max' => 255, 'list' => true],
        ],
    ],
];
