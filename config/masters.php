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
$hospital = require __DIR__ . '/hospital.php';

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
            'dose_unit'        => ['label' => 'Default Dose Unit', 'type' => 'options', 'default' => 'tab', 'options' => [
                'tab' => 'Tablet', 'cap' => 'Capsule', 'tsp' => 'Teaspoon (5 ml)', 'tbsp' => 'Tablespoon (15 ml)', 'ml' => 'ml', 'drop' => 'Drop',
                'amp' => 'Ampoule', 'vial' => 'Vial', 'puff' => 'Puff', 'sachet' => 'Sachet', 'application' => 'Application']],
            'default_duration' => ['label' => 'Default Duration (days)', 'type' => 'int', 'min' => 0, 'default' => 5],
            'unit'             => ['label' => 'Billing Unit', 'type' => 'text', 'max' => 30, 'placeholder' => 'Tablet / Bottle / Tube'],
            'pack_size'        => ['label' => 'Pack Size (per billing unit)', 'type' => 'decimal', 'min' => 0.01, 'default' => 1,
                                   'help' => '1 for tablets/capsules. Liquids: ml per bottle (teaspoon = 5 ml and tablespoon = 15 ml are converted). Drops: drops per bottle.'],
            'price'            => ['label' => 'Unit Price (PKR)', 'type' => 'money', 'min' => 0, 'list' => true, 'default' => 0],
            'source_id'        => ['label' => 'Source', 'type' => 'select', 'lookup' => 'medicine_sources', 'list' => true, 'filter' => true],
            'drap_registration_no' => ['label' => 'DRAP Registration No.', 'type' => 'text', 'max' => 40, 'search' => true],
            'is_controlled'    => ['label' => 'Controlled drug', 'type' => 'options', 'default' => '0', 'options' => ['0' => 'No', '1' => 'Yes — pharmacist co-sign & register']],
            'is_high_alert'    => ['label' => 'High-alert medicine', 'type' => 'options', 'default' => '0', 'options' => ['0' => 'No', '1' => 'Yes']],
            'is_lasa'          => ['label' => 'Look-alike / sound-alike', 'type' => 'options', 'default' => '0', 'options' => ['0' => 'No', '1' => 'Yes']],
            'is_formulary'     => ['label' => 'In hospital formulary', 'type' => 'options', 'default' => '1', 'options' => ['1' => 'Yes', '0' => 'No']],
            'pregnancy_category' => ['label' => 'Pregnancy category', 'type' => 'options', 'default' => '', 'options' => ['' => '—', 'A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D', 'X' => 'X']],
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
            'name_ur'    => ['label' => 'Urdu (for patient noskha)', 'type' => 'text', 'max' => 150, 'list' => true, 'rtl' => true],
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
            'dose_type'  => ['label' => 'Dose Dropdown Type', 'type' => 'options', 'list' => true, 'default' => 'other',
                             'options' => \App\Core\Urdu::DOSE_TYPES,
                             'help' => 'Decides the dose choices on the prescription: tablets 1/4 1/3 1/2 1 2..., syrups in teaspoon/tablespoon, drops, injections.'],
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
            'name_ur'       => ['label' => 'Urdu (for patient noskha)', 'type' => 'text', 'max' => 150, 'list' => true, 'rtl' => true],
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
            'name_ur'     => ['label' => 'Urdu (for patient noskha)', 'type' => 'text', 'max' => 150, 'list' => true, 'rtl' => true],
            'description' => ['label' => 'Description', 'type' => 'textarea', 'max' => 255, 'list' => true],
        ],
    ],

    'departments' => [
        'title'       => 'Departments',
        'singular'    => 'Department',
        'icon'        => 'building',
        'description' => 'Clinical and support departments. The specialty decides which department screens a doctor gets (tooth chart, ANC …).',
        'unique'      => ['name'],
        'name_label'  => 'Department Name',
        'perm_manage' => 'departments.manage',
        'perm_view'   => 'departments.manage',
        'fields'      => [
            'code'            => ['label' => 'Code', 'type' => 'text', 'max' => 20, 'list' => true, 'search' => true, 'placeholder' => 'OPD'],
            'department_type' => ['label' => 'Type', 'type' => 'options', 'list' => true, 'default' => 'clinical', 'options' => $hospital['department_types']],
            'specialty_id'    => ['label' => 'Specialty', 'type' => 'select', 'lookup' => 'specialties', 'list' => true, 'filter' => true],
            'branch_id'       => ['label' => 'Branch', 'type' => 'select', 'lookup' => 'branches'],
            'description'     => ['label' => 'Description', 'type' => 'textarea', 'max' => 255],
        ],
    ],

    'branches' => [
        'title'       => 'Branches',
        'singular'    => 'Branch',
        'icon'        => 'branch',
        'description' => 'Hospitals / facilities of the chain. With one branch the branch switcher stays hidden.',
        'unique'      => ['name'],
        'name_label'  => 'Branch Name',
        'perm_manage' => 'branches.manage',
        'perm_view'   => 'branches.manage',
        'fields'      => [
            'code'       => ['label' => 'Code', 'type' => 'text', 'max' => 20, 'list' => true, 'search' => true],
            'city'       => ['label' => 'City', 'type' => 'text', 'max' => 80, 'list' => true],
            'phone'      => ['label' => 'Phone', 'type' => 'text', 'max' => 40, 'list' => true],
            'license_no' => ['label' => 'License / PHC No.', 'type' => 'text', 'max' => 60],
            'address'    => ['label' => 'Address', 'type' => 'textarea', 'max' => 255],
        ],
    ],

    'specialties' => [
        'title'       => 'Specialties',
        'singular'    => 'Specialty',
        'icon'        => 'heart',
        'description' => 'Medical specialties. The module decides the first-class department screen; extra fields are configured under Specialty Templates.',
        'unique'      => ['name'],
        'name_label'  => 'Specialty',
        'perm_manage' => 'departments.manage',
        'perm_view'   => 'departments.manage',
        'fields'      => [
            'code'        => ['label' => 'Code', 'type' => 'text', 'max' => 20, 'list' => true, 'search' => true],
            'module_key'  => ['label' => 'Department module', 'type' => 'options', 'list' => true, 'default' => '', 'options' => $hospital['specialty_modules']],
            'description' => ['label' => 'Description', 'type' => 'textarea', 'max' => 255],
        ],
    ],

    'service_points' => [
        'title'       => 'Service Points',
        'singular'    => 'Service Point',
        'icon'        => 'grid',
        'description' => 'Every counter, room, dental chair, bench and desk (shown on tokens and display boards).',
        'unique'      => ['name'],
        'name_label'  => 'Name',
        'perm_manage' => 'departments.manage',
        'perm_view'   => 'departments.manage',
        'fields'      => [
            'code'          => ['label' => 'Code', 'type' => 'text', 'max' => 20, 'list' => true],
            'type'          => ['label' => 'Type', 'type' => 'options', 'list' => true, 'default' => 'consultation_room', 'options' => $hospital['service_point_types']],
            'department_id' => ['label' => 'Department', 'type' => 'select', 'lookup' => 'departments', 'list' => true, 'filter' => true],
            'branch_id'     => ['label' => 'Branch', 'type' => 'select', 'lookup' => 'branches'],
        ],
    ],

    'visit_types' => [
        'title'       => 'Visit Types',
        'singular'    => 'Visit Type',
        'icon'        => 'calendar',
        'description' => 'New OPD, follow-up, emergency … with the default fee service and a free follow-up window.',
        'unique'      => ['name'],
        'name_label'  => 'Visit Type',
        'perm_manage' => 'billing.services.manage',
        'perm_view'   => 'billing.services.manage',
        'fields'      => [
            'code'                  => ['label' => 'Code', 'type' => 'text', 'max' => 30, 'list' => true],
            'default_service_id'    => ['label' => 'Fee service', 'type' => 'select', 'lookup' => 'service_catalog', 'list' => true],
            'follow_up_window_days' => ['label' => 'Free follow-up within (days)', 'type' => 'int', 'min' => 0, 'list' => true, 'default' => 0],
        ],
    ],

    'token_queues' => [
        'title'       => 'Token Queues',
        'singular'    => 'Token Queue',
        'icon'        => 'list',
        'description' => 'Queues of the waiting rooms. Doctor queues are created automatically; add queues for lab collection, radiology, pharmacy or billing.',
        'unique'      => ['name'],
        'name_label'  => 'Queue Name',
        'perm_manage' => 'frontdesk.queue.configure',
        'perm_view'   => 'frontdesk.queue.configure',
        'fields'      => [
            'prefix'              => ['label' => 'Token prefix', 'type' => 'text', 'max' => 5, 'list' => true, 'required' => true],
            'queue_type'          => ['label' => 'Type', 'type' => 'options', 'list' => true, 'default' => 'consultation', 'options' => $hospital['queue_types']],
            'department_id'       => ['label' => 'Department', 'type' => 'select', 'lookup' => 'departments', 'list' => true],
            'service_point_id'    => ['label' => 'Room / counter', 'type' => 'select', 'lookup' => 'service_points'],
            'avg_service_minutes' => ['label' => 'Average minutes per patient', 'type' => 'int', 'min' => 1, 'default' => 10],
            'reset_policy'        => ['label' => 'Numbering', 'type' => 'options', 'default' => 'daily', 'options' => ['daily' => 'Restart every day', 'never' => 'Never restart']],
        ],
    ],

    'vendors' => [
        'title' => 'Vendors', 'singular' => 'Vendor', 'icon' => 'truck',
        'description' => 'Suppliers, manufacturers and external laboratories (purchase orders, GRN, outsourced tests).',
        'unique' => ['name'], 'name_label' => 'Vendor Name', 'perm_manage' => 'inventory.vendors.manage', 'perm_view' => 'inventory.view',
        'fields' => [
            'vendor_type'        => ['label' => 'Type', 'type' => 'options', 'list' => true, 'default' => 'supplier', 'options' => ['supplier' => 'Supplier', 'manufacturer' => 'Manufacturer', 'external_lab' => 'External laboratory', 'service' => 'Service provider']],
            'contact_person'     => ['label' => 'Contact Person', 'type' => 'text', 'max' => 100, 'list' => true],
            'phone'              => ['label' => 'Phone', 'type' => 'text', 'max' => 40, 'list' => true, 'search' => true],
            'email'              => ['label' => 'Email', 'type' => 'text', 'max' => 150],
            'ntn_no'             => ['label' => 'NTN', 'type' => 'text', 'max' => 30],
            'drug_license_no'    => ['label' => 'Drug sale licence', 'type' => 'text', 'max' => 60],
            'payment_terms_days' => ['label' => 'Payment terms (days)', 'type' => 'int', 'min' => 0, 'default' => 30],
            'address'            => ['label' => 'Address', 'type' => 'textarea', 'max' => 255],
        ],
    ],

    'item_categories' => [
        'title' => 'Item Categories', 'singular' => 'Item Category', 'icon' => 'layers',
        'description' => 'Categories of stock items (drugs, consumables, reagents, implants …).',
        'unique' => ['name'], 'name_label' => 'Category', 'perm_manage' => 'inventory.items.manage', 'perm_view' => 'inventory.view',
        'fields' => [
            'type' => ['label' => 'Type', 'type' => 'options', 'list' => true, 'default' => 'medical_consumable', 'options' => [
                'drug' => 'Drug', 'medical_consumable' => 'Medical consumable', 'surgical_instrument' => 'Surgical instrument', 'implant' => 'Implant', 'reagent' => 'Lab reagent',
                'radiology_consumable' => 'Radiology consumable', 'dental_consumable' => 'Dental consumable', 'housekeeping' => 'Housekeeping', 'stationery' => 'Stationery',
                'equipment' => 'Equipment', 'vaccine' => 'Vaccine']],
        ],
    ],

    'uoms' => [
        'title' => 'Units of Measure', 'singular' => 'Unit', 'icon' => 'box',
        'description' => 'Stock units (tablet, box, vial, ml …).',
        'unique' => ['name'], 'name_label' => 'Unit', 'perm_manage' => 'inventory.items.manage', 'perm_view' => 'inventory.view',
        'fields' => ['code' => ['label' => 'Code', 'type' => 'text', 'max' => 15, 'list' => true]],
    ],

    'stock_locations' => [
        'title' => 'Stock Locations', 'singular' => 'Stock Location', 'icon' => 'building',
        'description' => 'Central store, sub-stores, pharmacies, ward / OT / lab / radiology / dental stock, quarantine and expired hold.',
        'unique' => ['name'], 'name_label' => 'Location', 'perm_manage' => 'inventory.items.manage', 'perm_view' => 'inventory.view',
        'fields' => [
            'code'           => ['label' => 'Code', 'type' => 'text', 'max' => 20, 'list' => true],
            'location_type'  => ['label' => 'Type', 'type' => 'options', 'list' => true, 'default' => 'sub_store', 'options' => \App\Modules\Inventory\StockService::LOCATION_TYPES],
            'department_id'  => ['label' => 'Department', 'type' => 'select', 'lookup' => 'departments', 'list' => true],
            'branch_id'      => ['label' => 'Branch', 'type' => 'select', 'lookup' => 'branches'],
            'allow_negative' => ['label' => 'Allow negative stock (emergency / OT)', 'type' => 'options', 'default' => '0', 'options' => ['0' => 'No', '1' => 'Yes']],
            'cold_storage'   => ['label' => 'Cold storage (vaccines, insulin)', 'type' => 'options', 'default' => '0', 'options' => ['0' => 'No', '1' => 'Yes']],
        ],
    ],

    'lab_sections' => [
        'title' => 'Lab Sections', 'singular' => 'Lab Section', 'icon' => 'flask',
        'description' => 'Haematology, Biochemistry, Microbiology … — the unit of work allocation. Technicians are assigned to sections.',
        'unique' => ['name'], 'name_label' => 'Section', 'perm_manage' => 'lab.catalog.manage', 'perm_view' => 'lab.view',
        'fields' => ['code' => ['label' => 'Code', 'type' => 'text', 'max' => 20, 'list' => true]],
    ],

    'lab_test_categories' => [
        'title' => 'Lab Test Categories', 'singular' => 'Test Category', 'icon' => 'layers', 'description' => 'Grouping of tests on the order screen and report.',
        'unique' => ['name'], 'name_label' => 'Category', 'perm_manage' => 'lab.catalog.manage', 'perm_view' => 'lab.view', 'fields' => [],
    ],

    'lab_specimen_types' => [
        'title' => 'Specimen Types', 'singular' => 'Specimen Type', 'icon' => 'droplet', 'description' => 'EDTA blood, serum, urine, swab …',
        'unique' => ['name'], 'name_label' => 'Specimen', 'perm_manage' => 'lab.catalog.manage', 'perm_view' => 'lab.view',
        'fields' => [
            'storage_condition' => ['label' => 'Storage', 'type' => 'text', 'max' => 100, 'list' => true],
            'max_hold_hours'    => ['label' => 'Max hold (hours)', 'type' => 'int', 'min' => 0, 'list' => true, 'default' => 24],
        ],
    ],

    'lab_containers' => [
        'title' => 'Sample Containers', 'singular' => 'Container', 'icon' => 'droplet', 'description' => 'Tube / container with cap colour (collection is grouped by colour).',
        'unique' => ['name'], 'name_label' => 'Container', 'perm_manage' => 'lab.catalog.manage', 'perm_view' => 'lab.view',
        'fields' => ['color_code' => ['label' => 'Cap colour', 'type' => 'text', 'max' => 20, 'list' => true, 'placeholder' => 'purple']],
    ],

    'lab_analyzers' => [
        'title' => 'Lab Analyzers', 'singular' => 'Analyzer', 'icon' => 'activity', 'description' => 'Instruments per section (manual / CSV import; ASTM / HL7 later).',
        'unique' => ['name'], 'name_label' => 'Analyzer', 'perm_manage' => 'lab.catalog.manage', 'perm_view' => 'lab.view',
        'fields' => [
            'section_id'     => ['label' => 'Section', 'type' => 'select', 'lookup' => 'lab_sections', 'list' => true],
            'model'          => ['label' => 'Model', 'type' => 'text', 'max' => 100, 'list' => true],
            'serial_no'      => ['label' => 'Serial No.', 'type' => 'text', 'max' => 80],
            'interface_type' => ['label' => 'Interface', 'type' => 'options', 'default' => 'manual', 'options' => ['manual' => 'Manual entry', 'CSV' => 'CSV import', 'ASTM' => 'ASTM', 'HL7' => 'HL7']],
            'status'         => ['label' => 'Status', 'type' => 'options', 'list' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'maintenance' => 'Maintenance', 'out_of_service' => 'Out of service']],
        ],
    ],
];
