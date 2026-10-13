<?php
/**
 * Permission catalog and the DEFAULT role set (Docs 1, 5 §5, 8).
 *
 * This file is the SEED: the installer and the migrator copy it into the
 * permissions / roles / role_permission tables. After that the Super Admin
 * edits roles in Administration → Roles & Permissions; a role's rights are
 * read from the database, never from this file.
 *
 * Grants: 'code' (role's default scope) or 'code@scope'
 *   scope = own | department | branch | chain
 * '*'    : every permission except those in 'doctor_only'.
 * 'base' : the role inherits every grant of its base role; 'deny' removes
 *          inherited permissions (Doc 8 §1 "add or restrict").
 * 'class': classic user type used across the app (super_admin, dept_admin,
 *          doctor, reporting, staff). Doctors appear in doctor pickers, get a
 *          doctor profile, prescription designs and an MRN pattern.
 */
return [

    'modules' => [
        'core'         => 'Core',
        'patients'     => 'Patients & Registration',
        'frontdesk'    => 'Front Desk & Tokens',
        'appointments' => 'Appointments & Scheduling',
        'vitals'       => 'Vitals & Triage',
        'clinical'     => 'Clinical / EHR',
        'billing'      => 'Billing & Receipts',
        'pharmacy'     => 'Pharmacy',
        'inventory'    => 'Inventory & Stores',
        'lab'          => 'Laboratory',
        'radiology'    => 'Radiology',
        'dental'       => 'Dentistry',
        'obgyn'        => 'Gynecology / Obstetrics',
        'specialty'    => 'Other Specialties',
        'admin'        => 'Administration',
    ],

    // code => [module, label, is_approval]
    'permissions' => [
        // ---- core (v1 permissions keep their codes) -------------------------
        'dashboard.view'       => ['core', 'View dashboard'],
        'search.use'           => ['core', 'Global search'],
        'reports.view'         => ['core', 'Reports & Excel export'],
        'masters.view'         => ['admin', 'View master data'],
        'masters.manage'       => ['admin', 'Add / edit / activate master data'],
        'departments.manage'   => ['admin', 'Manage departments, specialties & service points'],
        'branches.manage'      => ['admin', 'Manage branches'],
        'users.view'           => ['admin', 'View users'],
        'users.manage'         => ['admin', 'Manage users & role assignments'],
        'roles.manage'         => ['admin', 'Roles, permissions & workspaces'],
        'print_designs.manage' => ['admin', 'Prescription print designs'],
        'settings.manage'      => ['admin', 'System settings & integrations'],
        'backup.create'        => ['admin', 'Create & download backup'],
        'backup.restore'       => ['admin', 'Restore backup'],
        'db.reset'             => ['admin', 'Reset database'],
        'records.delete'       => ['admin', 'Soft delete & recycle bin'],
        'activity.view'        => ['admin', 'Activity logs'],
        'audit.view'           => ['admin', 'Audit trail (old → new values)'],
        'emergency.review'     => ['admin', 'Review break-glass access', true],
        'profile.doctor'       => ['clinical', 'Doctor profile'],

        // ---- patients --------------------------------------------------------
        'patients.view'            => ['patients', 'View patients (demographics)'],
        'patients.create'          => ['patients', 'Register patients'],
        'patients.edit'            => ['patients', 'Edit demographics (before the first visit)'],
        'patients.edit_any'        => ['patients', 'Edit demographics / CNIC after visits'],
        'patients.cnic.full'       => ['patients', 'See full CNIC (unmasked)'],
        'patients.merge'           => ['patients', 'Merge duplicate patients', true],
        'patients.emergency'       => ['patients', 'Emergency / unidentified registration'],
        'patients.documents'       => ['patients', 'Upload & view patient documents'],

        // ---- front desk -------------------------------------------------------
        'frontdesk.register'        => ['frontdesk', 'Registration desk'],
        'frontdesk.token.issue'     => ['frontdesk', 'Issue OPD tokens'],
        'frontdesk.token.manage'    => ['frontdesk', 'Call / recall / skip / transfer tokens'],
        'frontdesk.queue.configure' => ['frontdesk', 'Configure queues & display boards'],
        'frontdesk.fee.waive'       => ['frontdesk', 'Waive registration fee', true],
        'frontdesk.checkin'         => ['frontdesk', 'Check-in appointments'],

        // ---- appointments -----------------------------------------------------
        'appointments.view'          => ['appointments', 'View appointments & free/busy'],
        'appointments.book'          => ['appointments', 'Book / reschedule / cancel appointments'],
        'appointments.slots.manage'  => ['appointments', 'Manage doctor slots in bulk'],
        'appointments.schedule.own'  => ['appointments', 'Own calendar & leave requests'],
        'appointments.leave.approve' => ['appointments', 'Approve doctor leave', true],

        // ---- vitals ------------------------------------------------------------
        'vitals.record' => ['vitals', 'Record vitals / triage'],

        // ---- clinical ----------------------------------------------------------
        'visits.view'               => ['clinical', 'View visits & prescriptions'],
        'visits.create'             => ['clinical', 'Consultation / e-Rx / checkout'],
        'visits.print'              => ['clinical', 'Print prescriptions'],
        'templates.manage'          => ['clinical', 'Favourite templates'],
        'clinical.cosign'           => ['clinical', 'Co-sign resident entries', true],
        'clinical.notes.write'      => ['clinical', 'Clinical notes (incl. restricted flag)'],
        'clinical.referral.create'  => ['clinical', 'Refer to another department / doctor'],
        'clinical.parameters.configure' => ['clinical', 'Configure specialty templates'],
        'emergency.access'          => ['clinical', 'Break-glass emergency access'],

        // ---- billing -----------------------------------------------------------
        'billing.view'             => ['billing', 'View invoices & receipts'],
        'billing.invoice.create'   => ['billing', 'Create invoices / add charges'],
        'billing.payment.collect'  => ['billing', 'Collect payments'],
        'billing.discount.apply'   => ['billing', 'Apply discount (within own limit)'],
        'billing.discount.approve' => ['billing', 'Approve discount above limit', true],
        'billing.refund.request'   => ['billing', 'Initiate refund'],
        'billing.refund.approve'   => ['billing', 'Approve general refunds', true],
        'billing.refund.process'   => ['billing', 'Execute refunds (pay out)'],
        'billing.void.approve'     => ['billing', 'Void invoices', true],
        'billing.cash.close'       => ['billing', 'Cash session & shift close'],
        'billing.services.manage'  => ['billing', 'Service catalog & price lists'],

        // ---- pharmacy ----------------------------------------------------------
        'pharmacy.view'              => ['pharmacy', 'View pharmacy queue & stock'],
        'pharmacy.dispense'          => ['pharmacy', 'Dispense e-Rx'],
        'pharmacy.dispense.finalize' => ['pharmacy', 'Finalize controlled drugs / co-sign', true],
        'pharmacy.counter_sale'      => ['pharmacy', 'OTC counter sale'],
        'pharmacy.return'            => ['pharmacy', 'Customer returns'],
        'pharmacy.return.approve'    => ['pharmacy', 'Approve returns', true],
        'pharmacy.formulary.manage'  => ['pharmacy', 'Formulary, interactions & prices'],
        'pharmacy.controlled.register' => ['pharmacy', 'Controlled-drug register'],
        'pharmacy.writeoff.approve'  => ['pharmacy', 'Expiry write-off & recalls', true],
        'pharmacy.ward_stock'        => ['pharmacy', 'Ward stock replenishment'],

        // ---- inventory ---------------------------------------------------------
        'inventory.view'              => ['inventory', 'View stock, ledger & stock cards'],
        'inventory.items.manage'      => ['inventory', 'Item master, locations & reorder rules'],
        'inventory.vendors.manage'    => ['inventory', 'Vendor master & price history'],
        'inventory.requisition.create'=> ['inventory', 'Raise requisitions (indents)'],
        'inventory.requisition.approve'=> ['inventory', 'Approve requisitions', true],
        'inventory.po.create'         => ['inventory', 'Create purchase orders'],
        'inventory.po.approve'        => ['inventory', 'Approve purchase orders', true],
        'inventory.grn.post'          => ['inventory', 'Create & post GRN'],
        'inventory.issue'             => ['inventory', 'Issue stock'],
        'inventory.transfer'          => ['inventory', 'Transfer / receive stock'],
        'inventory.adjust.propose'    => ['inventory', 'Propose stock adjustment'],
        'inventory.adjust.approve'    => ['inventory', 'Approve stock adjustment', true],
        'inventory.count'             => ['inventory', 'Physical (blind) count'],
        'inventory.count.post'        => ['inventory', 'Post count variance', true],
        'inventory.assets.manage'     => ['inventory', 'Assets & maintenance'],

        // ---- laboratory --------------------------------------------------------
        'lab.view'            => ['lab', 'View lab worklists & results'],
        'lab.order.create'    => ['lab', 'Order lab tests'],
        'lab.reception'       => ['lab', 'Lab reception, labels & tracking'],
        'lab.sample.collect'  => ['lab', 'Collect & label samples'],
        'lab.sample.review'   => ['lab', 'Accept / reject samples (resample or refund)', true],
        'lab.worksheet'       => ['lab', 'Worksheets & analyzer runs'],
        'lab.result.enter'    => ['lab', 'Enter provisional results'],
        'lab.result.verify'   => ['lab', 'Verify / sign-off / amend reports', true],
        'lab.qc.record'       => ['lab', 'Record QC runs'],
        'lab.qc.manage'       => ['lab', 'QC materials, release / lock runs', true],
        'lab.catalog.manage'  => ['lab', 'Test catalog & reference ranges'],
        'lab.outsource'       => ['lab', 'Outsource referrals'],
        'lab.report.handover' => ['lab', 'Hand over printed reports'],

        // ---- radiology ---------------------------------------------------------
        'radiology.view'     => ['radiology', 'View radiology worklists & reports'],
        'radiology.order.create' => ['radiology', 'Order imaging'],
        'radiology.schedule' => ['radiology', 'Schedule & safety screening'],
        'radiology.acquire'  => ['radiology', 'Perform acquisition, contrast & dose log'],
        'radiology.review'   => ['radiology', 'Accept / reprocess / reject + refund', true],
        'radiology.report'   => ['radiology', 'Author & sign reports, addenda'],
        'radiology.manage'   => ['radiology', 'Rooms, equipment, protocols, templates'],

        // ---- dentistry ---------------------------------------------------------
        'dental.chart.view'      => ['dental', 'View tooth chart'],
        'dental.chart.edit'      => ['dental', 'Create / modify tooth findings'],
        'dental.perio.edit'      => ['dental', 'Perio chart'],
        'dental.plan.create'     => ['dental', 'Create treatment plans'],
        'dental.procedure.complete' => ['dental', 'Mark procedures completed'],
        'dental.procedure.hygiene'  => ['dental', 'Complete scaling / polishing only'],
        'dental.labcase.view'    => ['dental', 'View dental lab cases'],
        'dental.labcase.manage'  => ['dental', 'Create dental lab cases'],
        'dental.labcase.update'  => ['dental', 'Update lab case status (technician)'],
        'dental.consumables'     => ['dental', 'Chair consumables entry'],

        // ---- gynecology / obstetrics -------------------------------------------
        'obgyn.history.view'    => ['obgyn', 'View gynecologic / obstetric history'],
        'obgyn.history.edit'    => ['obgyn', 'Edit gynecologic history'],
        'obgyn.pregnancy.manage'=> ['obgyn', 'Open / close pregnancy records'],
        'obgyn.anc.record'      => ['obgyn', 'ANC / PNC visit form'],
        'obgyn.delivery.record' => ['obgyn', 'Delivery record'],
        'obgyn.newborn.register'=> ['obgyn', 'Newborn registration'],
        'obgyn.us.biometry'     => ['obgyn', 'Obstetric ultrasound biometry'],

        // ---- other specialties -------------------------------------------------
        'specialty.exam.record' => ['specialty', 'Specialty exams (eye, cardiac, audiogram, growth, body map)'],
        'immunization.record'   => ['specialty', 'Immunization records & vaccine draw'],
    ],

    // Template and doctor-profile rights belong to doctors only, even for '*'.
    'doctor_only' => ['templates.manage', 'profile.doctor'],

    'classes' => [
        'super_admin' => 'Super Admin',
        'dept_admin'  => 'Department Admin',
        'doctor'      => 'Doctor',
        'reporting'   => 'Reporting User',
        'staff'       => 'Staff',
    ],

    // v1 users.role => role code assigned by the migrator.
    'legacy_roles' => ['super_admin' => 'SA', 'dept_admin' => 'DA', 'doctor' => 'CD', 'reporting' => 'RPT'],

    'roles' => [
        // ===== Tier A — system & chain ======================================
        'SA'  => ['name' => 'Super Admin', 'tier' => 'A', 'class' => 'super_admin', 'scope' => 'chain', '2fa' => true,
                  'grants' => ['*']],
        'ITA' => ['name' => 'IT Admin', 'tier' => 'A', 'class' => 'staff', 'scope' => 'chain', '2fa' => true,
                  'grants' => ['dashboard.view', 'users.view', 'users.manage', 'settings.manage', 'backup.create', 'backup.restore', 'activity.view']],
        'AUD' => ['name' => 'Auditor / Compliance', 'tier' => 'A', 'class' => 'reporting', 'scope' => 'chain',
                  'grants' => ['dashboard.view', 'search.use', 'patients.view', 'visits.view', 'visits.print', 'reports.view', 'masters.view', 'users.view',
                               'activity.view', 'audit.view', 'emergency.review', 'billing.view', 'pharmacy.view', 'inventory.view', 'lab.view', 'radiology.view',
                               'dental.chart.view', 'obgyn.history.view']],

        // ===== Tier B — branch administration ===============================
        'HA'  => ['name' => 'Hospital / Branch Admin', 'tier' => 'B', 'class' => 'dept_admin', 'scope' => 'branch', '2fa' => true,
                  'grants' => ['dashboard.view', 'search.use', 'reports.view', 'patients.view', 'patients.create', 'patients.edit', 'patients.edit_any',
                               'patients.cnic.full', 'patients.merge', 'patients.documents', 'frontdesk.register', 'frontdesk.token.issue', 'frontdesk.token.manage',
                               'frontdesk.queue.configure', 'frontdesk.fee.waive', 'frontdesk.checkin', 'appointments.view', 'appointments.book',
                               'appointments.slots.manage', 'appointments.leave.approve', 'masters.view', 'masters.manage', 'departments.manage', 'users.view',
                               'users.manage', 'print_designs.manage', 'backup.create', 'activity.view', 'audit.view', 'emergency.review', 'billing.view',
                               'billing.discount.approve', 'billing.refund.approve', 'billing.void.approve', 'billing.services.manage', 'inventory.view',
                               'inventory.po.approve', 'inventory.adjust.approve', 'inventory.count.post', 'inventory.requisition.approve',
                               'pharmacy.view', 'lab.view', 'radiology.view', 'clinical.parameters.configure']],
        'DA'  => ['name' => 'Department Admin', 'tier' => 'B', 'class' => 'dept_admin', 'scope' => 'department',
                  'description' => 'Classic department administrator: master data, print designs, backups and reports of the assigned departments.',
                  'grants' => ['dashboard.view', 'search.use', 'patients.view', 'visits.view', 'visits.print', 'reports.view', 'masters.view', 'masters.manage',
                               'users.view', 'print_designs.manage', 'backup.create', 'activity.view']],
        'RPT' => ['name' => 'Reporting User', 'tier' => 'B', 'class' => 'reporting', 'scope' => 'chain',
                  'grants' => ['dashboard.view', 'search.use', 'patients.view', 'visits.view', 'visits.print', 'reports.view']],
        'BSV' => ['name' => 'Billing Supervisor', 'tier' => 'B', 'base' => 'BC', '2fa' => true, 'discount_limit' => 5000,
                  'grants' => ['billing.discount.approve', 'billing.refund.approve', 'billing.void.approve', 'reports.view']],

        // ===== Tier C — clinical =============================================
        'CD'  => ['name' => 'Consultant Doctor', 'tier' => 'C', 'class' => 'doctor', 'scope' => 'own', 'dept' => 'clinical',
                  'grants' => ['dashboard.view', 'search.use', 'patients.view@branch', 'patients.create@branch', 'patients.edit@branch', 'visits.view',
                               'visits.create', 'visits.print', 'templates.manage', 'reports.view', 'profile.doctor', 'appointments.view',
                               'appointments.schedule.own', 'frontdesk.token.manage', 'vitals.record', 'clinical.notes.write', 'clinical.referral.create',
                               'emergency.access', 'lab.order.create', 'radiology.order.create', 'specialty.exam.record', 'patients.documents']],
        'DH'  => ['name' => 'Department Head (HOD)', 'tier' => 'C', 'base' => 'CD', 'class' => 'doctor', 'scope' => 'department', 'dept' => 'clinical',
                  'grants' => ['visits.view@department', 'reports.view@department', 'appointments.leave.approve', 'clinical.cosign', 'dental.chart.view',
                               'obgyn.history.view', 'clinical.parameters.configure']],
        'RM'  => ['name' => 'Resident / Medical Officer', 'tier' => 'C', 'base' => 'CD', 'class' => 'doctor', 'scope' => 'own', 'dept' => 'clinical', 'cosign' => true,
                  'grants' => []],
        'CN'  => ['name' => 'Charge Nurse', 'tier' => 'C', 'class' => 'staff', 'scope' => 'department', 'dept' => 'clinical',
                  'grants' => ['dashboard.view', 'patients.view', 'vitals.record', 'emergency.access', 'visits.view@department', 'frontdesk.token.manage']],
        'SN'  => ['name' => 'Staff Nurse', 'tier' => 'C', 'class' => 'staff', 'scope' => 'department', 'dept' => 'clinical',
                  'grants' => ['dashboard.view', 'patients.view', 'vitals.record']],
        'PT'  => ['name' => 'Pathologist', 'tier' => 'C', 'class' => 'staff', 'scope' => 'branch', 'dept' => 'lab',
                  'grants' => ['dashboard.view', 'search.use', 'patients.view', 'lab.view', 'lab.sample.review', 'lab.result.enter', 'lab.result.verify',
                               'lab.qc.record', 'reports.view']],
        'RAD' => ['name' => 'Radiologist', 'tier' => 'C', 'class' => 'staff', 'scope' => 'branch', 'dept' => 'radiology',
                  'grants' => ['dashboard.view', 'search.use', 'patients.view', 'radiology.view', 'radiology.review', 'radiology.report', 'reports.view',
                               'obgyn.us.biometry']],

        // ===== Tier D — operational & support ================================
        'RC'  => ['name' => 'Receptionist / Front Desk', 'tier' => 'D', 'class' => 'staff', 'scope' => 'branch', 'dept' => 'front_desk', 'timeout' => 10,
                  'grants' => ['dashboard.view', 'search.use', 'patients.view', 'patients.create', 'patients.edit', 'patients.cnic.full', 'patients.documents',
                               'frontdesk.register', 'frontdesk.token.issue', 'frontdesk.checkin', 'appointments.view', 'appointments.book',
                               'billing.view', 'billing.invoice.create', 'billing.payment.collect']],
        'BC'  => ['name' => 'Billing Clerk / Cashier', 'tier' => 'D', 'class' => 'staff', 'scope' => 'branch', 'dept' => 'billing', '2fa' => true, 'discount_limit' => 500,
                  'grants' => ['dashboard.view', 'search.use', 'patients.view', 'patients.cnic.full', 'billing.view', 'billing.invoice.create',
                               'billing.payment.collect', 'billing.discount.apply', 'billing.refund.request', 'billing.refund.process', 'billing.cash.close']],
        'PH'  => ['name' => 'Pharmacist', 'tier' => 'D', 'class' => 'staff', 'scope' => 'branch', 'dept' => 'pharmacy',
                  'grants' => ['dashboard.view', 'search.use', 'patients.view', 'pharmacy.view', 'pharmacy.dispense', 'pharmacy.dispense.finalize',
                               'pharmacy.counter_sale', 'pharmacy.return', 'pharmacy.controlled.register', 'inventory.view', 'inventory.requisition.create',
                               'inventory.transfer', 'inventory.count', 'billing.view']],
        'PA'  => ['name' => 'Pharmacy Assistant', 'tier' => 'D', 'class' => 'staff', 'scope' => 'branch', 'dept' => 'pharmacy',
                  'grants' => ['dashboard.view', 'patients.view', 'pharmacy.view', 'pharmacy.dispense', 'pharmacy.counter_sale', 'inventory.view', 'inventory.count']],
        'SC'  => ['name' => 'Sample Collector', 'tier' => 'D', 'class' => 'staff', 'scope' => 'branch', 'dept' => 'lab',
                  'grants' => ['dashboard.view', 'patients.view', 'lab.view', 'lab.sample.collect']],
        'LT'  => ['name' => 'Lab Technician', 'tier' => 'D', 'class' => 'staff', 'scope' => 'branch', 'dept' => 'lab',
                  'grants' => ['dashboard.view', 'patients.view', 'lab.view', 'lab.sample.collect', 'lab.worksheet', 'lab.result.enter', 'lab.qc.record']],
        'RT'  => ['name' => 'Radiology Technician', 'tier' => 'D', 'class' => 'staff', 'scope' => 'branch', 'dept' => 'radiology',
                  'grants' => ['dashboard.view', 'patients.view', 'radiology.view', 'radiology.acquire']],
        'SM'  => ['name' => 'Store / Inventory Manager', 'tier' => 'D', 'class' => 'staff', 'scope' => 'branch', 'dept' => 'store',
                  'grants' => ['dashboard.view', 'reports.view', 'inventory.view', 'inventory.items.manage', 'inventory.vendors.manage',
                               'inventory.requisition.create', 'inventory.requisition.approve', 'inventory.po.approve', 'inventory.issue', 'inventory.transfer',
                               'inventory.adjust.approve', 'inventory.count', 'inventory.count.post', 'inventory.assets.manage']],

        // ===== Doc 8 — front desk department roles ==========================
        'TKO' => ['name' => 'Token Desk Operator', 'base' => 'RC', 'dept' => 'front_desk',
                  'grants' => ['frontdesk.token.manage'], 'deny' => ['patients.edit', 'patients.create']],
        'RGS' => ['name' => 'Registration Supervisor', 'base' => 'RC', 'dept' => 'front_desk',
                  'grants' => ['patients.merge', 'patients.edit_any', 'frontdesk.fee.waive', 'frontdesk.token.manage', 'activity.view']],
        'ERG' => ['name' => 'Emergency Registrar', 'base' => 'RC', 'dept' => 'front_desk',
                  'grants' => ['patients.emergency', 'frontdesk.fee.waive']],
        'APC' => ['name' => 'Appointment Coordinator', 'base' => 'RC', 'dept' => 'front_desk',
                  'grants' => ['appointments.slots.manage'], 'deny' => ['frontdesk.token.issue', 'billing.payment.collect', 'billing.invoice.create']],

        // ===== Doc 8 — dentistry ============================================
        'DEN' => ['name' => 'Dentist (Dental Consultant)', 'base' => 'CD', 'specialty' => 'DENT',
                  'grants' => ['dental.chart.view', 'dental.chart.edit', 'dental.perio.edit', 'dental.plan.create', 'dental.procedure.complete',
                               'dental.labcase.view', 'dental.labcase.manage', 'dental.consumables']],
        'DSG' => ['name' => 'Dental Surgeon (OMFS)', 'base' => 'DEN', 'specialty' => 'DENT', 'grants' => []],
        'DRM' => ['name' => 'Dental Resident / House Officer', 'base' => 'RM', 'specialty' => 'DENT', 'cosign' => true,
                  'grants' => ['dental.chart.view', 'dental.chart.edit', 'dental.perio.edit', 'dental.plan.create', 'dental.procedure.complete', 'dental.consumables']],
        'DHY' => ['name' => 'Dental Hygienist', 'class' => 'staff', 'scope' => 'department', 'dept' => 'clinical', 'specialty' => 'DENT',
                  'grants' => ['dashboard.view', 'patients.view', 'vitals.record', 'dental.chart.view', 'dental.perio.edit', 'dental.procedure.hygiene', 'dental.consumables']],
        'DAS' => ['name' => 'Dental Assistant', 'class' => 'staff', 'scope' => 'department', 'dept' => 'clinical', 'specialty' => 'DENT',
                  'grants' => ['dashboard.view', 'patients.view', 'vitals.record', 'dental.chart.view', 'dental.consumables', 'dental.labcase.view']],
        'DTC' => ['name' => 'Dental Technician', 'class' => 'staff', 'scope' => 'department', 'dept' => 'clinical', 'specialty' => 'DENT',
                  'grants' => ['dashboard.view', 'dental.labcase.view@own', 'dental.labcase.update@own']],

        // ===== Doc 8 — gynecology / obstetrics ===============================
        'GYN' => ['name' => 'Gynecologist / Obstetrician', 'base' => 'CD', 'specialty' => 'OBGYN',
                  'grants' => ['obgyn.history.view', 'obgyn.history.edit', 'obgyn.pregnancy.manage', 'obgyn.anc.record', 'obgyn.delivery.record',
                               'obgyn.newborn.register', 'obgyn.us.biometry']],
        'GRM' => ['name' => 'Gynae Resident / MO', 'base' => 'RM', 'specialty' => 'OBGYN', 'cosign' => true,
                  'grants' => ['obgyn.history.view', 'obgyn.history.edit', 'obgyn.pregnancy.manage', 'obgyn.anc.record', 'obgyn.delivery.record', 'obgyn.newborn.register']],
        'MWF' => ['name' => 'Midwife / Lady Health Visitor', 'base' => 'SN', 'specialty' => 'OBGYN',
                  'grants' => ['obgyn.history.view', 'obgyn.anc.record', 'obgyn.delivery.record', 'obgyn.newborn.register']],
        'SON' => ['name' => 'Sonographer', 'base' => 'RT', 'grants' => ['obgyn.us.biometry']],
        'LRN' => ['name' => 'Labor-Room Nurse', 'base' => 'SN', 'specialty' => 'OBGYN', 'grants' => ['obgyn.delivery.record', 'obgyn.newborn.register']],

        // ===== Doc 8 — other clinical departments ============================
        'CAR' => ['name' => 'Cardiologist', 'base' => 'CD', 'specialty' => 'CARD', 'grants' => []],
        'ECT' => ['name' => 'ECG Technician', 'class' => 'staff', 'scope' => 'department', 'dept' => 'clinical', 'specialty' => 'CARD',
                  'grants' => ['dashboard.view', 'patients.view', 'specialty.exam.record']],
        'ECH' => ['name' => 'Echo Technician', 'base' => 'ECT', 'grants' => []],
        'OPH' => ['name' => 'Ophthalmologist', 'base' => 'CD', 'specialty' => 'OPHT', 'grants' => []],
        'OPT' => ['name' => 'Optometrist', 'class' => 'staff', 'scope' => 'department', 'dept' => 'clinical', 'specialty' => 'OPHT',
                  'grants' => ['dashboard.view', 'patients.view', 'vitals.record', 'specialty.exam.record']],
        'ENT' => ['name' => 'ENT Surgeon', 'base' => 'CD', 'specialty' => 'ENT', 'grants' => []],
        'AUDI'=> ['name' => 'Audiologist', 'class' => 'staff', 'scope' => 'department', 'dept' => 'clinical', 'specialty' => 'ENT',
                  'grants' => ['dashboard.view', 'patients.view', 'specialty.exam.record']],
        'PED' => ['name' => 'Pediatrician', 'base' => 'CD', 'specialty' => 'PEDS', 'grants' => ['immunization.record']],
        'VAC' => ['name' => 'Vaccinator', 'class' => 'staff', 'scope' => 'department', 'dept' => 'clinical', 'specialty' => 'PEDS',
                  'grants' => ['dashboard.view', 'patients.view', 'vitals.record', 'immunization.record']],
        'ORT' => ['name' => 'Orthopedic Surgeon', 'base' => 'CD', 'specialty' => 'ORTH', 'grants' => []],
        'PHY' => ['name' => 'Physiotherapist', 'class' => 'staff', 'scope' => 'department', 'dept' => 'clinical', 'specialty' => 'ORTH',
                  'grants' => ['dashboard.view', 'patients.view', 'vitals.record', 'specialty.exam.record']],
        'DER' => ['name' => 'Dermatologist', 'base' => 'CD', 'specialty' => 'DERM', 'grants' => []],
        'PSY' => ['name' => 'Psychiatrist', 'base' => 'CD', 'specialty' => 'PSYC', 'grants' => []],
        'PSC' => ['name' => 'Psychologist', 'class' => 'staff', 'scope' => 'department', 'dept' => 'clinical', 'specialty' => 'PSYC',
                  'grants' => ['dashboard.view', 'patients.view', 'clinical.notes.write']],
        'ERP' => ['name' => 'ER Physician', 'base' => 'CD', 'grants' => ['patients.emergency']],
        'TRN' => ['name' => 'Triage Nurse', 'base' => 'SN', 'grants' => ['patients.emergency', 'patients.create']],

        // ===== Doc 8 — laboratory ============================================
        'LRC' => ['name' => 'Lab Reception / Order Desk', 'class' => 'staff', 'scope' => 'branch', 'dept' => 'lab',
                  'grants' => ['dashboard.view', 'search.use', 'patients.view', 'lab.view', 'lab.reception', 'lab.report.handover', 'billing.view']],
        'LSS' => ['name' => 'Section Supervisor / Senior Technologist', 'base' => 'LT', 'grants' => ['lab.qc.manage']],
        'LQC' => ['name' => 'Lab QC Officer', 'class' => 'staff', 'scope' => 'branch', 'dept' => 'lab',
                  'grants' => ['dashboard.view', 'lab.view', 'lab.qc.record', 'lab.qc.manage']],
        'LMG' => ['name' => 'Lab Manager', 'base' => 'LSS', 'grants' => ['lab.catalog.manage', 'lab.outsource', 'reports.view']],

        // ===== Doc 8 — radiology =============================================
        'RRC' => ['name' => 'Radiology Reception', 'class' => 'staff', 'scope' => 'branch', 'dept' => 'radiology',
                  'grants' => ['dashboard.view', 'search.use', 'patients.view', 'radiology.view', 'radiology.schedule']],
        'RIC' => ['name' => 'Radiology In-charge', 'base' => 'RT', 'grants' => ['radiology.manage', 'radiology.schedule', 'reports.view']],

        // ===== Doc 8 — pharmacy ==============================================
        'PCS' => ['name' => 'Counter Sales Operator', 'base' => 'PA', 'deny' => ['pharmacy.dispense'], 'grants' => []],
        'CPH' => ['name' => 'Chief Pharmacist', 'base' => 'PH', '2fa' => true,
                  'grants' => ['pharmacy.formulary.manage', 'pharmacy.writeoff.approve', 'pharmacy.return.approve', 'reports.view']],
        'WPH' => ['name' => 'Ward Pharmacist (IPD)', 'base' => 'PH', 'grants' => ['pharmacy.ward_stock']],

        // ===== Doc 8 — store / inventory =====================================
        'SKP' => ['name' => 'Store Keeper', 'class' => 'staff', 'scope' => 'branch', 'dept' => 'store',
                  'grants' => ['dashboard.view', 'inventory.view', 'inventory.issue', 'inventory.transfer', 'inventory.count', 'inventory.adjust.propose']],
        'GRC' => ['name' => 'Receiving / GRN Clerk', 'class' => 'staff', 'scope' => 'branch', 'dept' => 'store',
                  'grants' => ['dashboard.view', 'inventory.view', 'inventory.grn.post']],
        'PRO' => ['name' => 'Procurement Officer', 'class' => 'staff', 'scope' => 'branch', 'dept' => 'store',
                  'grants' => ['dashboard.view', 'inventory.view', 'inventory.po.create', 'inventory.vendors.manage', 'inventory.requisition.create']],
        'SAU' => ['name' => 'Stock Auditor / Counter', 'class' => 'staff', 'scope' => 'branch', 'dept' => 'store',
                  'grants' => ['dashboard.view', 'inventory.view', 'inventory.count', 'inventory.adjust.propose']],
        'IND' => ['name' => 'Department Indenter', 'class' => 'staff', 'scope' => 'own',
                  'grants' => ['dashboard.view', 'inventory.view@own', 'inventory.requisition.create@own', 'inventory.transfer@own']],
        'AST' => ['name' => 'Asset Officer', 'class' => 'staff', 'scope' => 'branch', 'dept' => 'store',
                  'grants' => ['dashboard.view', 'inventory.view', 'inventory.assets.manage']],

        // ===== Doc 8 — billing ==============================================
        'PCH' => ['name' => 'Pharmacy Cashier', 'base' => 'BC', 'grants' => []],
    ],
];
