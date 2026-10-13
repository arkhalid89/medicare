<?php
/**
 * Hospital reference lists and seed data for the department system (v2).
 * Lists here are code => label pairs used by screens and validation; the
 * 'seed' entries are copied into the database once (installer / migrator)
 * and edited afterwards in Master Data.
 */
return [

    'department_types' => [
        'clinical'   => 'Clinical',
        'front_desk' => 'Front Desk',
        'billing'    => 'Billing',
        'pharmacy'   => 'Pharmacy',
        'store'      => 'Store / Inventory',
        'lab'        => 'Laboratory',
        'radiology'  => 'Radiology',
        'ward'       => 'Ward / Nursing',
        'admin'      => 'Administration',
    ],

    // code => [name, module_key]  (module_key drives the first-class clinical UI)
    'specialties' => [
        'GEN'   => ['General Medicine', ''],
        'DENT'  => ['Dentistry', 'dental'],
        'OBGYN' => ['Gynecology / Obstetrics', 'obgyn'],
        'CARD'  => ['Cardiology', 'cardiology'],
        'OPHT'  => ['Ophthalmology', 'ophthalmology'],
        'ENT'   => ['ENT (Otolaryngology)', 'ent'],
        'PEDS'  => ['Pediatrics', 'pediatrics'],
        'ORTH'  => ['Orthopedics', 'orthopedics'],
        'DERM'  => ['Dermatology', 'dermatology'],
        'PSYC'  => ['Psychiatry', 'psychiatry'],
        'UROL'  => ['Urology', ''],
    ],

    'specialty_modules' => [
        ''              => 'Generic (configurable fields only)',
        'dental'        => 'Dentistry — tooth chart & treatment plan',
        'obgyn'         => 'Gynecology / Obstetrics — history, pregnancy, ANC',
        'ophthalmology' => 'Ophthalmology — eye exam',
        'pediatrics'    => 'Pediatrics — growth & immunization',
        'cardiology'    => 'Cardiology — cardiac investigations',
        'ent'           => 'ENT — audiogram',
        'dermatology'   => 'Dermatology — body map',
        'orthopedics'   => 'Orthopedics — body map',
        'psychiatry'    => 'Psychiatry — restricted notes',
    ],

    'service_point_types' => [
        'registration_desk'      => 'Registration desk',
        'token_counter'          => 'Token counter',
        'consultation_room'      => 'Consultation room',
        'dental_chair'           => 'Dental chair',
        'sample_collection_room' => 'Sample collection room',
        'lab_bench'              => 'Lab bench',
        'imaging_room'           => 'Imaging room',
        'pharmacy_counter'       => 'Pharmacy counter',
        'store_counter'          => 'Store counter',
        'billing_counter'        => 'Billing counter',
        'ward_station'           => 'Ward / nurse station',
    ],

    'service_types' => [
        'consultation'     => 'Consultation',
        'registration'     => 'Registration / card',
        'lab_test'         => 'Lab test',
        'radiology'        => 'Radiology',
        'dental_procedure' => 'Dental procedure',
        'procedure'        => 'Procedure',
        'package'          => 'Package',
        'pharmacy_item'    => 'Pharmacy item',
        'consumable'       => 'Consumable',
        'other'            => 'Other',
    ],

    'queue_types' => [
        'consultation'   => 'Consultation',
        'registration'   => 'Registration',
        'lab_collection' => 'Lab collection',
        'radiology'      => 'Radiology',
        'pharmacy'       => 'Pharmacy',
        'billing'        => 'Billing',
        'vitals'         => 'Vitals / triage',
    ],

    // key => [label, weight (higher is called first), Urdu]
    'priorities' => [
        'emergency' => ['Emergency', 100, 'ایمرجنسی'],
        'pregnant'  => ['Pregnant', 60, 'حاملہ'],
        'disabled'  => ['Disabled', 60, 'معذور'],
        'senior'    => ['Senior citizen', 60, 'بزرگ شہری'],
        'vip'       => ['VIP', 40, 'وی آئی پی'],
        'normal'    => ['Normal', 0, 'عام'],
    ],

    'token_statuses' => [
        'waiting' => 'Waiting', 'called' => 'Called', 'serving' => 'In consultation', 'completed' => 'Completed',
        'no_show' => 'No show', 'transferred' => 'Transferred', 'cancelled' => 'Cancelled',
    ],

    'payment_modes' => [
        'cash' => 'Cash', 'card' => 'Card / POS', 'bank_transfer' => 'Bank transfer', 'easypaisa' => 'Easypaisa',
        'jazzcash' => 'JazzCash', 'cheque' => 'Cheque', 'other' => 'Other',
    ],

    'invoice_types' => [
        'opd' => 'OPD', 'pharmacy' => 'Pharmacy', 'lab' => 'Laboratory', 'radiology' => 'Radiology',
        'dental' => 'Dental', 'misc' => 'Miscellaneous',
    ],

    'patient_id_types' => [
        'cnic' => 'CNIC', 'b_form' => 'B-Form (minor)', 'nicop' => 'NICOP', 'passport' => 'Passport',
        'temp_er_id' => 'Temporary ER ID', 'insurance_member' => 'Insurance / Sehat card',
    ],

    'registration_types' => [
        'normal' => 'Normal', 'emergency_unidentified' => 'Emergency (unidentified)', 'newborn' => 'Newborn',
    ],

    'document_types' => [
        'cnic_scan' => 'CNIC scan', 'referral_letter' => 'Referral letter', 'insurance_card' => 'Insurance card',
        'consent' => 'Consent form', 'outside_report' => 'Outside report', 'photo' => 'Photo', 'other' => 'Other',
    ],

    'marital_statuses' => ['single' => 'Single', 'married' => 'Married', 'widowed' => 'Widowed', 'divorced' => 'Divorced'],
    'languages'        => ['ur' => 'Urdu', 'en' => 'English', 'pa' => 'Punjabi', 'ps' => 'Pashto', 'sd' => 'Sindhi'],
    'relations'        => ['Father', 'Mother', 'Husband', 'Wife', 'Son', 'Daughter', 'Brother', 'Sister', 'Guardian', 'Other'],

    /*
     * Workspaces (Doc 8 §4.3): code => [name, route, icon, section, permission, default-for roles, also-for roles]
     * A workspace appears in a user's menu only when the route exists and the
     * user holds the permission in the current branch / department.
     */
    'workspaces' => [
        'frontdesk.registration'  => ['Registration Desk', 'frontdesk', 'user-plus', 'Front Desk', 'frontdesk.register', ['RC', 'RGS', 'ERG'], ['HA']],
        'frontdesk.token_console' => ['Token Console', 'frontdesk/tokens', 'list', 'Front Desk', 'frontdesk.token.manage', ['TKO'], ['RC', 'RGS', 'CN', 'HA']],
        'frontdesk.appointments'  => ['Appointments', 'appointments', 'calendar', 'Front Desk', 'appointments.view', ['APC'], ['RC', 'RGS', 'HA', 'CD']],
        'frontdesk.vitals'        => ['Vitals & Triage', 'frontdesk/vitals', 'activity', 'Nursing', 'vitals.record', ['SN', 'CN', 'TRN'], ['DAS', 'DHY', 'MWF', 'LRN', 'OPT', 'VAC']],
        'consult.general'         => ['My OPD Queue', 'consult', 'stethoscope', 'Clinical', 'visits.create', ['CD', 'RM', 'DH', 'CAR', 'OPH', 'ENT', 'PED', 'ORT', 'DER', 'PSY', 'ERP'], ['DEN', 'DSG', 'DRM', 'GYN', 'GRM']],
        'billing.counter'         => ['Billing Counter', 'billing', 'receipt', 'Billing', 'billing.payment.collect', ['BC', 'BSV', 'PCH'], ['RC']],
        'billing.approvals'       => ['Billing Approvals', 'billing/approvals', 'check', 'Billing', 'billing.refund.approve', ['HA'], ['BSV']],
        // Phase 2
        'pharmacy.dispense'       => ['Pharmacy Dispensing', 'pharmacy', 'pill', 'Pharmacy', 'pharmacy.dispense', ['PH', 'PA', 'WPH'], ['CPH']],
        'pharmacy.counter_sale'   => ['Counter Sale', 'pharmacy/sale', 'cart', 'Pharmacy', 'pharmacy.counter_sale', ['PCS'], ['PH', 'PA', 'CPH']],
        'pharmacy.chief'          => ['Chief Pharmacist', 'pharmacy/chief', 'shield', 'Pharmacy', 'pharmacy.formulary.manage', ['CPH'], []],
        'store.dashboard'         => ['Store Dashboard', 'inventory', 'box', 'Store', 'inventory.view', ['SM', 'SKP', 'AST'], ['HA', 'PH']],
        'store.receiving'         => ['Goods Receiving (GRN)', 'inventory/grn', 'download', 'Store', 'inventory.grn.post', ['GRC'], ['SM']],
        'store.procurement'       => ['Procurement', 'inventory/po', 'truck', 'Store', 'inventory.po.create', ['PRO'], ['SM']],
        'store.stocktake'         => ['Stocktake', 'inventory/counts', 'clipboard', 'Store', 'inventory.count', ['SAU'], ['SM', 'SKP']],
        'indent.request'          => ['My Indents', 'inventory/indents', 'send', 'Store', 'inventory.requisition.create', ['IND'], ['PH', 'SM', 'CN']],
        'lab.reception'           => ['Lab Reception', 'lab/reception', 'flask', 'Laboratory', 'lab.reception', ['LRC'], ['PT', 'LMG']],
        'lab.collection'          => ['Sample Collection', 'lab/collection', 'droplet', 'Laboratory', 'lab.sample.collect', ['SC'], ['LT', 'LSS']],
        'lab.bench'               => ['Lab Bench', 'lab/bench', 'activity', 'Laboratory', 'lab.result.enter', ['LT', 'LSS', 'LMG'], ['PT']],
        'lab.pathologist'         => ['Pathologist Desk', 'lab/pathologist', 'shield', 'Laboratory', 'lab.sample.review', ['PT'], []],
        'lab.qc'                  => ['Lab QC', 'lab/qc', 'chart', 'Laboratory', 'lab.qc.record', ['LQC'], ['LSS', 'LMG', 'PT', 'LT']],
        // Phase 3
        'dental.consult'          => ['Dental Clinic', 'dental', 'tooth', 'Dentistry', 'dental.chart.view', ['DEN', 'DSG', 'DRM'], []],
        'dental.chairside'        => ['Dental Chairside', 'dental/chairside', 'tooth', 'Dentistry', 'dental.consumables', ['DHY', 'DAS'], []],
        'dental.lab_cases'        => ['Dental Lab Cases', 'dental/lab-cases', 'box', 'Dentistry', 'dental.labcase.view', ['DTC'], ['DEN', 'DSG', 'DAS']],
        'gyn.consult'             => ['Gynae / Obs Clinic', 'obgyn', 'heart', 'Gynecology / Obstetrics', 'obgyn.history.view', ['GYN', 'GRM'], []],
        'gyn.anc_nurse'           => ['ANC / Labour Room', 'obgyn/anc', 'heart', 'Gynecology / Obstetrics', 'obgyn.anc.record', ['MWF', 'LRN'], ['GYN']],
        'rad.reception'           => ['Radiology Reception', 'radiology/reception', 'calendar', 'Radiology', 'radiology.schedule', ['RRC', 'RIC'], []],
        'rad.technician'          => ['Imaging Worklist', 'radiology/technician', 'image', 'Radiology', 'radiology.acquire', ['RT', 'SON'], ['RIC']],
        'rad.radiologist'         => ['Radiologist Desk', 'radiology/radiologist', 'shield', 'Radiology', 'radiology.review', ['RAD'], []],
        'specialty.exams'         => ['Specialty Exams', 'specialty', 'eye', 'Clinical', 'specialty.exam.record', ['ECT', 'ECH', 'OPT', 'AUDI', 'PHY'], ['CAR', 'OPH', 'ENT', 'PED', 'ORT']],
        'specialty.immunization'  => ['Immunization', 'specialty/immunization', 'shield', 'Clinical', 'immunization.record', ['VAC'], ['PED']],
    ],

    // Seed services (code => [name, type, price])
    'services' => [
        'REG-CARD' => ['Registration / patient card', 'registration', 100],
        'CONS-OPD' => ['OPD consultation', 'consultation', 1000],
        'CONS-FU'  => ['Follow-up consultation', 'consultation', 500],
        'CONS-ER'  => ['Emergency consultation', 'consultation', 1500],
        'CONS-DEN' => ['Dental consultation', 'consultation', 800],
        'CONS-ANC' => ['Antenatal consultation', 'consultation', 1000],
        'PROC-DRS' => ['Dressing (small)', 'procedure', 300],
        'PROC-INJ' => ['Injection administration', 'procedure', 150],
        'PROC-NEB' => ['Nebulization', 'procedure', 300],
        'PROC-ECG' => ['ECG (12-lead)', 'procedure', 800],
    ],

    // Seed visit types (code => [name, service code, free follow-up days])
    'visit_types' => [
        'new_opd'        => ['New OPD', 'CONS-OPD', null],
        'follow_up'      => ['Follow-up', 'CONS-FU', 7],
        'emergency'      => ['Emergency', 'CONS-ER', null],
        'procedure_only' => ['Procedure only', null, null],
        'lab_only'       => ['Lab only', null, null],
        'radiology_only' => ['Radiology only', null, null],
        'dental'         => ['Dental', 'CONS-DEN', null],
        'antenatal'      => ['Antenatal', 'CONS-ANC', null],
    ],

    // Seed departments for a fresh install (name => [code, type, specialty code])
    'departments' => [
        'General OPD'             => ['OPD', 'clinical', 'GEN'],
        'Internal Medicine'       => ['MED', 'clinical', 'GEN'],
        'Paediatrics'             => ['PED', 'clinical', 'PEDS'],
        'Dental'                  => ['DEN', 'clinical', 'DENT'],
        'Gynecology / Obstetrics' => ['GYN', 'clinical', 'OBGYN'],
        'Front Desk'              => ['FD', 'front_desk', null],
        'Billing'                 => ['BIL', 'billing', null],
        'Pharmacy'                => ['PHR', 'pharmacy', null],
        'Central Store'           => ['STR', 'store', null],
        'Laboratory'              => ['LAB', 'lab', null],
        'Radiology'               => ['RAD', 'radiology', null],
    ],
];
