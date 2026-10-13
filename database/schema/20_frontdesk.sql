-- ============================================================================
--  v2 · Phase 1 · Front desk, registration, billing glue, token queues,
--  appointments and nurse vitals. (Docs 2 §5.1/5.2/5.12, 3 WF 1/8/9, 7 §2/§8)
-- ============================================================================

-- ---------------------------------------------------------------- patients
CREATE TABLE IF NOT EXISTS patient_identifiers (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id  INT UNSIGNED NOT NULL,
    id_type     VARCHAR(30) NOT NULL,
    id_value    VARCHAR(60) NOT NULL,
    is_primary  TINYINT(1) NOT NULL DEFAULT 0,
    verified_at DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by  INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_pi_patient (patient_id),
    KEY idx_pi_value (id_type, id_value),
    CONSTRAINT fk_pi_patient FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_contacts (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id           INT UNSIGNED NOT NULL,
    name                 VARCHAR(150) NOT NULL,
    relation             VARCHAR(50) NULL,
    phone                VARCHAR(20) NULL,
    cnic                 VARCHAR(15) NULL,
    is_guardian          TINYINT(1) NOT NULL DEFAULT 0,
    is_emergency_contact TINYINT(1) NOT NULL DEFAULT 0,
    is_next_of_kin       TINYINT(1) NOT NULL DEFAULT 0,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by           INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_pc_patient (patient_id),
    CONSTRAINT fk_pc_patient FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_documents (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id  INT UNSIGNED NOT NULL,
    doc_type    VARCHAR(30) NOT NULL,
    title       VARCHAR(150) NULL,
    file_path   VARCHAR(255) NOT NULL,
    mime        VARCHAR(80) NULL,
    size_bytes  INT UNSIGNED NULL,
    uploaded_by INT UNSIGNED NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at  DATETIME NULL,
    deleted_by  INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_pd_patient (patient_id),
    CONSTRAINT fk_pd_patient FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_merge_log (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kept_patient_id   INT UNSIGNED NOT NULL,
    merged_patient_id INT UNSIGNED NOT NULL,
    merged_by         INT UNSIGNED NULL,
    reason            VARCHAR(500) NULL,
    snapshot_json     LONGTEXT NULL,
    merged_at         DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_pml_kept (kept_patient_id),
    KEY idx_pml_merged (merged_patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------- service catalog & prices
-- Single source of what can be billed (Doc 7 §8). default_price is a cache
-- of the active chain-wide cash price.
CREATE TABLE IF NOT EXISTS service_catalog (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code          VARCHAR(30) NULL,
    name          VARCHAR(150) NOT NULL,
    service_type  VARCHAR(30) NOT NULL DEFAULT 'procedure',
    department_id INT UNSIGNED NULL,
    specialty_id  INT UNSIGNED NULL,
    revenue_head  VARCHAR(60) NULL,
    tax_code      VARCHAR(20) NULL,
    default_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    updated_at    DATETIME NULL,
    updated_by    INT UNSIGNED NULL,
    deleted_at    DATETIME NULL,
    deleted_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_sc_list (deleted_at, is_active, display_order),
    KEY idx_sc_code (code),
    KEY idx_sc_type (service_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_prices (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_id      INT UNSIGNED NOT NULL,
    branch_id       INT UNSIGNED NULL,
    price_list_type VARCHAR(20) NOT NULL DEFAULT 'cash',
    price           DECIMAL(12,2) NOT NULL DEFAULT 0,
    valid_from      DATE NOT NULL,
    valid_to        DATE NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by      INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_sp_service (service_id, price_list_type, valid_from),
    CONSTRAINT fk_sprice_service FOREIGN KEY (service_id) REFERENCES service_catalog (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_package_items (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    package_service_id INT UNSIGNED NOT NULL,
    member_service_id  INT UNSIGNED NOT NULL,
    quantity           DECIMAL(12,3) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_spi_package (package_service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visit_types (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code                  VARCHAR(30) NULL,
    name                  VARCHAR(150) NOT NULL,
    default_service_id    INT UNSIGNED NULL,
    follow_up_window_days SMALLINT UNSIGNED NULL,
    is_active             TINYINT(1) NOT NULL DEFAULT 1,
    display_order         INT NOT NULL DEFAULT 0,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by            INT UNSIGNED NULL,
    updated_at            DATETIME NULL,
    updated_by            INT UNSIGNED NULL,
    deleted_at            DATETIME NULL,
    deleted_by            INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_vt_list (deleted_at, is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------- billing
-- FBR-style sequential invoice numbers; never hard-deleted (void + reason).
CREATE TABLE IF NOT EXISTS invoices (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id        INT UNSIGNED NULL,
    invoice_no       VARCHAR(50) NOT NULL,
    invoice_type     VARCHAR(20) NOT NULL DEFAULT 'opd',
    patient_id       INT UNSIGNED NULL,
    customer_name    VARCHAR(150) NULL,
    visit_id         INT UNSIGNED NULL,
    token_id         INT UNSIGNED NULL,
    doctor_id        INT UNSIGNED NULL,
    department_id    INT UNSIGNED NULL,
    status           VARCHAR(20) NOT NULL DEFAULT 'open',
    gross_amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_status  VARCHAR(20) NOT NULL DEFAULT 'none',
    discount_reason  VARCHAR(255) NULL,
    discount_by      INT UNSIGNED NULL,
    discount_approved_by INT UNSIGNED NULL,
    tax_amount       DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_amount       DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid_amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
    refunded_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,
    waived           TINYINT(1) NOT NULL DEFAULT 0,
    waiver_reason    VARCHAR(255) NULL,
    waived_by        INT UNSIGNED NULL,
    notes            VARCHAR(500) NULL,
    print_count      INT UNSIGNED NOT NULL DEFAULT 0,
    void_reason      VARCHAR(255) NULL,
    voided_by        INT UNSIGNED NULL,
    voided_at        DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by       INT UNSIGNED NULL,
    updated_at       DATETIME NULL,
    updated_by       INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoices_no (invoice_no),
    KEY idx_inv_patient (patient_id, created_at),
    KEY idx_inv_visit (visit_id),
    KEY idx_inv_token (token_id),
    KEY idx_inv_status (status, created_at),
    KEY idx_inv_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- source_reference_* is polymorphic: lab_order_items, radiology_orders,
-- dental_treatment_plan_items, dispense_items, pharmacy_sale_items, visits …
CREATE TABLE IF NOT EXISTS invoice_items (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id            INT UNSIGNED NOT NULL,
    service_id            INT UNSIGNED NULL,
    description           VARCHAR(255) NOT NULL,
    source_module         VARCHAR(30) NOT NULL DEFAULT 'registration',
    source_reference_type VARCHAR(50) NULL,
    source_reference_id   INT UNSIGNED NULL,
    quantity              DECIMAL(12,3) NOT NULL DEFAULT 1,
    unit_price            DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_amount       DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_amount            DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount                DECIMAL(12,2) NOT NULL DEFAULT 0,
    refunded_amount       DECIMAL(12,2) NOT NULL DEFAULT 0,
    is_void               TINYINT(1) NOT NULL DEFAULT 0,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by            INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_ii_invoice (invoice_id),
    KEY idx_ii_source (source_reference_type, source_reference_id),
    KEY idx_ii_service (service_id),
    CONSTRAINT fk_ii_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_sessions (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id      INT UNSIGNED NULL,
    user_id        INT UNSIGNED NOT NULL,
    counter_name   VARCHAR(60) NULL,
    opened_at      DATETIME NOT NULL,
    opening_float  DECIMAL(12,2) NOT NULL DEFAULT 0,
    closed_at      DATETIME NULL,
    expected_cash  DECIMAL(12,2) NULL,
    counted_cash   DECIMAL(12,2) NULL,
    variance       DECIMAL(12,2) NULL,
    notes          VARCHAR(500) NULL,
    status         VARCHAR(10) NOT NULL DEFAULT 'open',
    PRIMARY KEY (id),
    KEY idx_cs_user (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id      INT UNSIGNED NOT NULL,
    branch_id       INT UNSIGNED NULL,
    amount          DECIMAL(12,2) NOT NULL,
    mode            VARCHAR(20) NOT NULL DEFAULT 'cash',
    reference       VARCHAR(100) NULL,
    cash_session_id INT UNSIGNED NULL,
    collected_by    INT UNSIGNED NULL,
    collected_at    DATETIME NOT NULL,
    is_void         TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_pay_invoice (invoice_id),
    KEY idx_pay_collected (collected_at, collected_by),
    CONSTRAINT fk_pay_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- General refunds need approval (HA / BSV). Rejection-linked refunds from the
-- Pathologist / Radiologist gate are pre-approved by that clinical decision
-- (Doc 1 §2.12) and only executed by the Billing Clerk.
CREATE TABLE IF NOT EXISTS refunds (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id            INT UNSIGNED NOT NULL,
    invoice_item_id       INT UNSIGNED NULL,
    branch_id             INT UNSIGNED NULL,
    amount                DECIMAL(12,2) NOT NULL,
    reason                VARCHAR(500) NOT NULL,
    source_module         VARCHAR(30) NOT NULL DEFAULT 'billing',
    source_reference_type VARCHAR(50) NULL,
    source_reference_id   INT UNSIGNED NULL,
    status                VARCHAR(20) NOT NULL DEFAULT 'requested',
    mode                  VARCHAR(20) NULL,
    initiated_by          INT UNSIGNED NULL,
    initiated_at          DATETIME NOT NULL,
    approved_by           INT UNSIGNED NULL,
    approved_at           DATETIME NULL,
    rejection_note        VARCHAR(255) NULL,
    processed_by          INT UNSIGNED NULL,
    processed_at          DATETIME NULL,
    cash_session_id       INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_ref_invoice (invoice_id),
    KEY idx_ref_status (status, initiated_at),
    CONSTRAINT fk_ref_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- tokens
CREATE TABLE IF NOT EXISTS token_queues (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id           INT UNSIGNED NULL,
    department_id       INT UNSIGNED NULL,
    doctor_id           INT UNSIGNED NULL,
    service_point_id    INT UNSIGNED NULL,
    queue_type          VARCHAR(20) NOT NULL DEFAULT 'consultation',
    name                VARCHAR(150) NOT NULL,
    prefix              VARCHAR(5) NOT NULL DEFAULT 'T',
    reset_policy        VARCHAR(10) NOT NULL DEFAULT 'daily',
    avg_service_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    display_order       INT NOT NULL DEFAULT 0,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          INT UNSIGNED NULL,
    updated_at          DATETIME NULL,
    updated_by          INT UNSIGNED NULL,
    deleted_at          DATETIME NULL,
    deleted_by          INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_tq_list (deleted_at, is_active, display_order),
    KEY idx_tq_doctor (doctor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tokens (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id          INT UNSIGNED NULL,
    queue_id           INT UNSIGNED NOT NULL,
    issue_date         DATE NOT NULL,
    token_number       INT UNSIGNED NOT NULL,
    token_label        VARCHAR(20) NOT NULL,
    patient_id         INT UNSIGNED NOT NULL,
    visit_id           INT UNSIGNED NULL,
    appointment_id     INT UNSIGNED NULL,
    invoice_id         INT UNSIGNED NULL,
    visit_type_id      INT UNSIGNED NULL,
    priority           VARCHAR(12) NOT NULL DEFAULT 'normal',
    status             VARCHAR(12) NOT NULL DEFAULT 'waiting',
    service_point_id   INT UNSIGNED NULL,
    previous_token_id  INT UNSIGNED NULL,
    recall_count       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    notes              VARCHAR(255) NULL,
    issued_by          INT UNSIGNED NULL,
    issued_at          DATETIME NOT NULL,
    called_at          DATETIME NULL,
    called_by          INT UNSIGNED NULL,
    serving_started_at DATETIME NULL,
    completed_at       DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tokens_number (queue_id, issue_date, token_number),
    KEY idx_tokens_status (queue_id, issue_date, status),
    KEY idx_tokens_patient (patient_id, issue_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS token_events (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_id         INT UNSIGNED NOT NULL,
    event_type       VARCHAR(20) NOT NULL,
    from_queue_id    INT UNSIGNED NULL,
    to_queue_id      INT UNSIGNED NULL,
    service_point_id INT UNSIGNED NULL,
    user_id          INT UNSIGNED NULL,
    note             VARCHAR(255) NULL,
    occurred_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_te_token (token_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS display_boards (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id       INT UNSIGNED NULL,
    name            VARCHAR(150) NOT NULL,
    location_text   VARCHAR(150) NULL,
    access_key      CHAR(32) NOT NULL,
    refresh_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    show_urdu       TINYINT(1) NOT NULL DEFAULT 1,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    display_order   INT NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by      INT UNSIGNED NULL,
    updated_at      DATETIME NULL,
    updated_by      INT UNSIGNED NULL,
    deleted_at      DATETIME NULL,
    deleted_by      INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_db_key (access_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS display_board_queues (
    display_board_id INT UNSIGNED NOT NULL,
    token_queue_id   INT UNSIGNED NOT NULL,
    display_order    INT NOT NULL DEFAULT 0,
    PRIMARY KEY (display_board_id, token_queue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------ appointments (WF 9)
CREATE TABLE IF NOT EXISTS doctor_schedules (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id     INT UNSIGNED NULL,
    doctor_id     INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NULL,
    day_of_week   TINYINT UNSIGNED NOT NULL,
    start_time    TIME NOT NULL,
    end_time      TIME NOT NULL,
    slot_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_ds_doctor (doctor_id, day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The reason is staff-personal data: front desk sees only "unavailable".
CREATE TABLE IF NOT EXISTS doctor_leaves (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    doctor_id   INT UNSIGNED NOT NULL,
    start_at    DATETIME NOT NULL,
    end_at      DATETIME NOT NULL,
    reason      VARCHAR(255) NULL,
    status      VARCHAR(12) NOT NULL DEFAULT 'pending',
    approved_by INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by  INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_dl_doctor (doctor_id, start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointment_slots (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id          INT UNSIGNED NULL,
    doctor_schedule_id INT UNSIGNED NULL,
    doctor_id          INT UNSIGNED NOT NULL,
    slot_start         DATETIME NOT NULL,
    slot_end           DATETIME NOT NULL,
    status             VARCHAR(10) NOT NULL DEFAULT 'open',
    appointment_id     INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_slot (doctor_id, slot_start),
    KEY idx_slot_status (doctor_id, status, slot_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointments (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id        INT UNSIGNED NULL,
    patient_id       INT UNSIGNED NOT NULL,
    doctor_id        INT UNSIGNED NOT NULL,
    department_id    INT UNSIGNED NULL,
    slot_id          INT UNSIGNED NULL,
    visit_type_id    INT UNSIGNED NULL,
    scheduled_at     DATETIME NOT NULL,
    status           VARCHAR(12) NOT NULL DEFAULT 'booked',
    source           VARCHAR(12) NOT NULL DEFAULT 'reception',
    notes            VARCHAR(255) NULL,
    token_id         INT UNSIGNED NULL,
    booked_by        INT UNSIGNED NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checked_in_at    DATETIME NULL,
    cancelled_at     DATETIME NULL,
    cancelled_reason VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_appt_doctor (doctor_id, scheduled_at),
    KEY idx_appt_patient (patient_id, scheduled_at),
    KEY idx_appt_status (status, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------- nurse vitals / ER triage
CREATE TABLE IF NOT EXISTS vitals (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id     INT UNSIGNED NULL,
    patient_id    INT UNSIGNED NOT NULL,
    token_id      INT UNSIGNED NULL,
    visit_id      INT UNSIGNED NULL,
    bp_systolic   SMALLINT UNSIGNED NULL,
    bp_diastolic  SMALLINT UNSIGNED NULL,
    pulse         SMALLINT UNSIGNED NULL,
    temperature   DECIMAL(4,1) NULL,
    weight        DECIMAL(5,1) NULL,
    height        DECIMAL(5,1) NULL,
    spo2          SMALLINT UNSIGNED NULL,
    resp_rate     SMALLINT UNSIGNED NULL,
    bmi           DECIMAL(5,1) NULL,
    other_vitals  TEXT NULL,
    triage_level  VARCHAR(10) NULL,
    notes         VARCHAR(255) NULL,
    recorded_by   INT UNSIGNED NULL,
    recorded_at   DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_vitals_patient (patient_id, recorded_at),
    KEY idx_vitals_token (token_id),
    KEY idx_vitals_visit (visit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
