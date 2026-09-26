-- ============================================================================
--  MediCare Practice — database schema
--  MySQL 5.7+/8.x and MariaDB 10.4+ (XAMPP)   ·   InnoDB · utf8mb4
--
--  Conventions
--    * Every business table carries created_*, updated_* and deleted_* columns.
--      deleted_at IS NOT NULL means the row is soft-deleted (Recycle Bin).
--    * Visits and templates SNAPSHOT medicine name, price, source, frequency
--      and route, so later master changes never alter history (BRD §74).
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS template_medicines;
DROP TABLE IF EXISTS template_clinical_items;
DROP TABLE IF EXISTS templates;
DROP TABLE IF EXISTS visit_medicines;
DROP TABLE IF EXISTS visit_clinical_items;
DROP TABLE IF EXISTS visits;
DROP TABLE IF EXISTS patients;
DROP TABLE IF EXISTS medicines;
DROP TABLE IF EXISTS instructions;
DROP TABLE IF EXISTS frequencies;
DROP TABLE IF EXISTS dosage_forms;
DROP TABLE IF EXISTS routes;
DROP TABLE IF EXISTS investigations;
DROP TABLE IF EXISTS diagnoses;
DROP TABLE IF EXISTS symptoms;
DROP TABLE IF EXISTS presenting_complaints;
DROP TABLE IF EXISTS medicine_sources;
DROP TABLE IF EXISTS print_designs;
DROP TABLE IF EXISTS doctor_profiles;
DROP TABLE IF EXISTS user_departments;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS departments;
DROP TABLE IF EXISTS counters;
DROP TABLE IF EXISTS settings;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
--  System
-- ---------------------------------------------------------------------------
CREATE TABLE settings (
    setting_key   VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    updated_at    DATETIME NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sequence counters for MRN, visit number and employee code patterns.
CREATE TABLE counters (
    counter_key VARCHAR(150) NOT NULL,
    last_value  INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (counter_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Organisation & users
-- ---------------------------------------------------------------------------
CREATE TABLE departments (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(150) NOT NULL,
    code          VARCHAR(20) NULL,
    description   VARCHAR(255) NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    updated_at    DATETIME NULL,
    updated_by    INT UNSIGNED NULL,
    deleted_at    DATETIME NULL,
    deleted_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_departments_list (deleted_at, is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_code VARCHAR(50) NULL,
    name          VARCHAR(150) NOT NULL,
    username      VARCHAR(60) NOT NULL,
    password      VARCHAR(255) NOT NULL,
    mobile        VARCHAR(20) NULL,
    email         VARCHAR(150) NULL,
    cnic          VARCHAR(15) NULL,
    role          ENUM('super_admin','dept_admin','doctor','reporting') NOT NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    updated_at    DATETIME NULL,
    updated_by    INT UNSIGNED NULL,
    deleted_at    DATETIME NULL,
    deleted_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_employee_code (employee_code),
    KEY idx_users_role (role, deleted_at, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_departments (
    user_id       INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, department_id),
    KEY idx_ud_department (department_id),
    CONSTRAINT fk_ud_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_ud_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE doctor_profiles (
    user_id           INT UNSIGNED NOT NULL,
    pmdc_registration VARCHAR(50) NULL,
    qualification     VARCHAR(255) NULL,
    specialization    VARCHAR(150) NULL,
    mrn_pattern       VARCHAR(100) NULL,
    default_fee       DECIMAL(12,2) NOT NULL DEFAULT 0,
    signature_text    VARCHAR(255) NULL,
    updated_at        DATETIME NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_dp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per doctor per paper size (A4 / A5 / Thermal / Legal).
CREATE TABLE print_designs (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    doctor_id          INT UNSIGNED NOT NULL,
    paper              ENUM('a4','a5','thermal','legal') NOT NULL,
    header_image       VARCHAR(255) NULL,
    show_logo          TINYINT(1) NOT NULL DEFAULT 1,
    header_title       VARCHAR(200) NULL,
    header_subtitle    VARCHAR(255) NULL,
    header_lines       TEXT NULL,
    clinic_info        TEXT NULL,
    footer_address     VARCHAR(255) NULL,
    footer_phone       VARCHAR(100) NULL,
    footer_followup    VARCHAR(500) NULL,
    footer_disclaimer  VARCHAR(500) NULL,
    footer_appointment VARCHAR(500) NULL,
    margin_mm          TINYINT UNSIGNED NOT NULL DEFAULT 10,
    font_size_pt       DECIMAL(4,1) NOT NULL DEFAULT 10.0,
    show_prices        TINYINT(1) NOT NULL DEFAULT 1,
    show_billing       TINYINT(1) NOT NULL DEFAULT 1,
    show_signature     TINYINT(1) NOT NULL DEFAULT 1,
    updated_at         DATETIME NULL,
    updated_by         INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_print_design (doctor_id, paper),
    CONSTRAINT fk_pd_doctor FOREIGN KEY (doctor_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Master data
-- ---------------------------------------------------------------------------
CREATE TABLE medicine_sources (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name           VARCHAR(150) NOT NULL,
    short_code     VARCHAR(20) NULL,
    contact_person VARCHAR(100) NULL,
    contact_phone  VARCHAR(30) NULL,
    address        VARCHAR(255) NULL,
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    display_order  INT NOT NULL DEFAULT 0,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by     INT UNSIGNED NULL,
    updated_at     DATETIME NULL,
    updated_by     INT UNSIGNED NULL,
    deleted_at     DATETIME NULL,
    deleted_by     INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_sources_list (deleted_at, is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE presenting_complaints (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(150) NOT NULL,
    description   VARCHAR(500) NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    updated_at    DATETIME NULL,
    updated_by    INT UNSIGNED NULL,
    deleted_at    DATETIME NULL,
    deleted_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_pc_list (deleted_at, is_active, display_order),
    KEY idx_pc_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE symptoms (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(150) NOT NULL,
    category      VARCHAR(80) NULL,
    description   VARCHAR(500) NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    updated_at    DATETIME NULL,
    updated_by    INT UNSIGNED NULL,
    deleted_at    DATETIME NULL,
    deleted_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_sym_list (deleted_at, is_active, display_order),
    KEY idx_sym_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE diagnoses (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(150) NOT NULL,
    code          VARCHAR(30) NULL,
    description   VARCHAR(500) NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    updated_at    DATETIME NULL,
    updated_by    INT UNSIGNED NULL,
    deleted_at    DATETIME NULL,
    deleted_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_dx_list (deleted_at, is_active, display_order),
    KEY idx_dx_name (name),
    KEY idx_dx_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE investigations (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(150) NOT NULL,
    category      VARCHAR(80) NULL,
    description   VARCHAR(500) NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    updated_at    DATETIME NULL,
    updated_by    INT UNSIGNED NULL,
    deleted_at    DATETIME NULL,
    deleted_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_inv_list (deleted_at, is_active, display_order),
    KEY idx_inv_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE routes (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(150) NOT NULL,
    short_name    VARCHAR(20) NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    updated_at    DATETIME NULL,
    updated_by    INT UNSIGNED NULL,
    deleted_at    DATETIME NULL,
    deleted_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_routes_list (deleted_at, is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dosage_forms (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(150) NOT NULL,
    short_name    VARCHAR(20) NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    updated_at    DATETIME NULL,
    updated_by    INT UNSIGNED NULL,
    deleted_at    DATETIME NULL,
    deleted_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_df_list (deleted_at, is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE frequencies (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(150) NOT NULL,
    code          VARCHAR(20) NULL,
    doses_per_day DECIMAL(6,2) NOT NULL DEFAULT 1,
    calc_mode     ENUM('daily','weekly','manual') NOT NULL DEFAULT 'daily',
    description   VARCHAR(255) NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    updated_at    DATETIME NULL,
    updated_by    INT UNSIGNED NULL,
    deleted_at    DATETIME NULL,
    deleted_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_freq_list (deleted_at, is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE instructions (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(150) NOT NULL,
    description   VARCHAR(255) NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    updated_at    DATETIME NULL,
    updated_by    INT UNSIGNED NULL,
    deleted_at    DATETIME NULL,
    deleted_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_ins_list (deleted_at, is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE medicines (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name             VARCHAR(150) NOT NULL,
    generic_name     VARCHAR(150) NULL,
    brand_name       VARCHAR(150) NULL,
    strength         VARCHAR(50) NULL,
    dosage_form_id   INT UNSIGNED NULL,
    route_id         INT UNSIGNED NULL,
    frequency_id     INT UNSIGNED NULL,
    instruction_id   INT UNSIGNED NULL,
    default_dose     DECIMAL(8,2) NOT NULL DEFAULT 1,
    dose_unit        VARCHAR(30) NULL,
    default_duration SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    unit             VARCHAR(30) NULL,
    pack_size        DECIMAL(10,2) NOT NULL DEFAULT 1,
    price            DECIMAL(12,2) NOT NULL DEFAULT 0,
    source_id        INT UNSIGNED NULL,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    display_order    INT NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by       INT UNSIGNED NULL,
    updated_at       DATETIME NULL,
    updated_by       INT UNSIGNED NULL,
    deleted_at       DATETIME NULL,
    deleted_by       INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_med_list (deleted_at, is_active, display_order),
    KEY idx_med_name (name),
    KEY idx_med_generic (generic_name),
    KEY idx_med_brand (brand_name),
    KEY idx_med_source (source_id),
    CONSTRAINT fk_med_form FOREIGN KEY (dosage_form_id) REFERENCES dosage_forms (id) ON DELETE SET NULL,
    CONSTRAINT fk_med_route FOREIGN KEY (route_id) REFERENCES routes (id) ON DELETE SET NULL,
    CONSTRAINT fk_med_freq FOREIGN KEY (frequency_id) REFERENCES frequencies (id) ON DELETE SET NULL,
    CONSTRAINT fk_med_instr FOREIGN KEY (instruction_id) REFERENCES instructions (id) ON DELETE SET NULL,
    CONSTRAINT fk_med_source FOREIGN KEY (source_id) REFERENCES medicine_sources (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Patients
--  CNIC and phone are deliberately NOT unique: family members share them.
-- ---------------------------------------------------------------------------
CREATE TABLE patients (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    mrn           VARCHAR(50) NOT NULL,
    doctor_id     INT UNSIGNED NOT NULL,
    name          VARCHAR(150) NOT NULL,
    guardian_name VARCHAR(150) NULL,
    cnic          VARCHAR(15) NULL,
    phone         VARCHAR(20) NULL,
    gender        ENUM('Male','Female','Other') NOT NULL,
    dob           DATE NULL,
    dob_estimated TINYINT(1) NOT NULL DEFAULT 0,
    blood_group   VARCHAR(5) NULL,
    address       VARCHAR(255) NULL,
    city          VARCHAR(80) NULL,
    notes         TEXT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    updated_at    DATETIME NULL,
    updated_by    INT UNSIGNED NULL,
    deleted_at    DATETIME NULL,
    deleted_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_patients_mrn (mrn),
    KEY idx_patients_name (name),
    KEY idx_patients_cnic (cnic),
    KEY idx_patients_phone (phone),
    KEY idx_patients_doctor (doctor_id),
    KEY idx_patients_created (created_at),
    KEY idx_patients_deleted (deleted_at),
    CONSTRAINT fk_patient_doctor FOREIGN KEY (doctor_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Visits (consultation + prescription + bill)
-- ---------------------------------------------------------------------------
CREATE TABLE visits (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_no               VARCHAR(50) NOT NULL,
    checkout_token         CHAR(32) NOT NULL,
    patient_id             INT UNSIGNED NOT NULL,
    doctor_id              INT UNSIGNED NOT NULL,
    visit_date             DATETIME NOT NULL,
    bp_systolic            SMALLINT UNSIGNED NULL,
    bp_diastolic           SMALLINT UNSIGNED NULL,
    pulse                  SMALLINT UNSIGNED NULL,
    temperature            DECIMAL(4,1) NULL,
    weight                 DECIMAL(5,1) NULL,
    height                 DECIMAL(5,1) NULL,
    spo2                   SMALLINT UNSIGNED NULL,
    resp_rate              SMALLINT UNSIGNED NULL,
    bmi                    DECIMAL(5,1) NULL,
    other_vitals           TEXT NULL,
    current_history        TEXT NULL,
    follow_up_date         DATE NULL,
    follow_up_instructions VARCHAR(500) NULL,
    consultation_fee       DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_status         ENUM('Paid','Pending','Free') NOT NULL DEFAULT 'Paid',
    medicines_total        DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_type          ENUM('amount','percent') NOT NULL DEFAULT 'amount',
    discount_value         DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_amount        DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_percent            DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_amount             DECIMAL(12,2) NOT NULL DEFAULT 0,
    medicine_net           DECIMAL(12,2) NOT NULL DEFAULT 0,
    grand_total            DECIMAL(12,2) NOT NULL DEFAULT 0,
    overall_instructions   TEXT NULL,
    rx_split_enabled       TINYINT(1) NOT NULL DEFAULT 0,
    rx_split_mode          VARCHAR(30) NULL,
    repeated_from_visit_id INT UNSIGNED NULL,
    template_id            INT UNSIGNED NULL,
    print_count            INT UNSIGNED NOT NULL DEFAULT 0,
    last_printed_at        DATETIME NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by             INT UNSIGNED NULL,
    updated_at             DATETIME NULL,
    updated_by             INT UNSIGNED NULL,
    deleted_at             DATETIME NULL,
    deleted_by             INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_visits_no (visit_no),
    UNIQUE KEY uq_visits_token (checkout_token),
    KEY idx_visits_date (visit_date),
    KEY idx_visits_doctor_date (doctor_id, visit_date),
    KEY idx_visits_patient (patient_id, visit_date),
    KEY idx_visits_followup (follow_up_date),
    KEY idx_visits_deleted (deleted_at),
    CONSTRAINT fk_visit_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
    CONSTRAINT fk_visit_doctor FOREIGN KEY (doctor_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Presenting complaints, symptoms, diagnoses and investigations of a visit.
-- ref_id points at the master row; name/code are snapshots (free text allowed
-- for complaints and symptoms, where ref_id is NULL).
CREATE TABLE visit_clinical_items (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_id  INT UNSIGNED NOT NULL,
    item_type ENUM('complaint','symptom','diagnosis','investigation') NOT NULL,
    ref_id    INT UNSIGNED NULL,
    name      VARCHAR(200) NOT NULL,
    code      VARCHAR(30) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_vci_visit (visit_id, item_type),
    KEY idx_vci_ref (item_type, ref_id),
    KEY idx_vci_name (item_type, name),
    CONSTRAINT fk_vci_visit FOREIGN KEY (visit_id) REFERENCES visits (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE visit_medicines (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_id       INT UNSIGNED NOT NULL,
    medicine_id    INT UNSIGNED NULL,
    medicine_name  VARCHAR(150) NOT NULL,
    generic_name   VARCHAR(150) NULL,
    strength       VARCHAR(50) NULL,
    dosage_form    VARCHAR(150) NULL,
    dose           DECIMAL(8,2) NOT NULL DEFAULT 1,
    dose_unit      VARCHAR(30) NULL,
    frequency_id   INT UNSIGNED NULL,
    frequency_name VARCHAR(150) NULL,
    frequency_code VARCHAR(20) NULL,
    doses_per_day  DECIMAL(6,2) NULL,
    calc_mode      VARCHAR(10) NULL,
    route_id       INT UNSIGNED NULL,
    route_name     VARCHAR(150) NULL,
    duration_days  SMALLINT UNSIGNED NULL,
    quantity       DECIMAL(10,2) NOT NULL DEFAULT 0,
    qty_manual     TINYINT(1) NOT NULL DEFAULT 0,
    unit           VARCHAR(30) NULL,
    unit_price     DECIMAL(12,2) NOT NULL DEFAULT 0,
    line_total     DECIMAL(12,2) NOT NULL DEFAULT 0,
    source_id      INT UNSIGNED NULL,
    source_name    VARCHAR(150) NULL,
    rx_group       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    instructions   VARCHAR(255) NULL,
    sort_order     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_vm_visit (visit_id),
    KEY idx_vm_medicine (medicine_id),
    KEY idx_vm_source (source_id),
    CONSTRAINT fk_vm_visit FOREIGN KEY (visit_id) REFERENCES visits (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Favourite templates (doctor-specific)
-- ---------------------------------------------------------------------------
CREATE TABLE templates (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    doctor_id            INT UNSIGNED NOT NULL,
    name                 VARCHAR(150) NOT NULL,
    description          VARCHAR(255) NULL,
    is_favourite         TINYINT(1) NOT NULL DEFAULT 1,
    vitals               TEXT NULL,
    overall_instructions TEXT NULL,
    follow_up_days       SMALLINT UNSIGNED NULL,
    usage_count          INT UNSIGNED NOT NULL DEFAULT 0,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by           INT UNSIGNED NULL,
    updated_at           DATETIME NULL,
    updated_by           INT UNSIGNED NULL,
    deleted_at           DATETIME NULL,
    deleted_by           INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_tpl_doctor (doctor_id, deleted_at, is_favourite),
    CONSTRAINT fk_tpl_doctor FOREIGN KEY (doctor_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE template_clinical_items (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_id INT UNSIGNED NOT NULL,
    item_type   ENUM('complaint','symptom','diagnosis','investigation') NOT NULL,
    ref_id      INT UNSIGNED NULL,
    name        VARCHAR(200) NOT NULL,
    code        VARCHAR(30) NULL,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_tci_template (template_id, item_type),
    CONSTRAINT fk_tci_template FOREIGN KEY (template_id) REFERENCES templates (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE template_medicines (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_id   INT UNSIGNED NOT NULL,
    medicine_id   INT UNSIGNED NULL,
    medicine_name VARCHAR(150) NOT NULL,
    dose          DECIMAL(8,2) NOT NULL DEFAULT 1,
    dose_unit     VARCHAR(30) NULL,
    frequency_id  INT UNSIGNED NULL,
    route_id      INT UNSIGNED NULL,
    duration_days SMALLINT UNSIGNED NULL,
    quantity      DECIMAL(10,2) NOT NULL DEFAULT 0,
    qty_manual    TINYINT(1) NOT NULL DEFAULT 0,
    unit_price    DECIMAL(12,2) NOT NULL DEFAULT 0,
    source_id     INT UNSIGNED NULL,
    instructions  VARCHAR(255) NULL,
    sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_tm_template (template_id),
    CONSTRAINT fk_tm_template FOREIGN KEY (template_id) REFERENCES templates (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Activity log (append-only)
-- ---------------------------------------------------------------------------
CREATE TABLE activity_logs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NULL,
    username    VARCHAR(60) NULL,
    role        VARCHAR(20) NULL,
    action      VARCHAR(80) NOT NULL,
    module      VARCHAR(50) NULL,
    record_id   VARCHAR(50) NULL,
    description VARCHAR(500) NULL,
    ip_address  VARCHAR(45) NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_logs_created (created_at),
    KEY idx_logs_user (user_id, created_at),
    KEY idx_logs_action (action),
    KEY idx_logs_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
