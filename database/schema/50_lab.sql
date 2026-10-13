-- ============================================================================
--  v2 · Phase 2 · Laboratory (Docs 1 §4a, 2 §5.8, 3 WF 5, 4 §4, 7 §4, 8 §5.4)
--  Two Pathologist gates: (1) sample accept / reject → resample XOR refund,
--  (2) result verification — blocked while the latest QC of the parameter failed.
-- ============================================================================

CREATE TABLE IF NOT EXISTS lab_sections (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id     INT UNSIGNED NULL,
    code          VARCHAR(20) NULL,
    name          VARCHAR(150) NOT NULL,
    head_user_id  INT UNSIGNED NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    updated_at    DATETIME NULL,
    updated_by    INT UNSIGNED NULL,
    deleted_at    DATETIME NULL,
    deleted_by    INT UNSIGNED NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_lab_sections (
    user_id    INT UNSIGNED NOT NULL,
    section_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_test_categories (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(150) NOT NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    updated_at    DATETIME NULL,
    updated_by    INT UNSIGNED NULL,
    deleted_at    DATETIME NULL,
    deleted_by    INT UNSIGNED NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_specimen_types (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name              VARCHAR(150) NOT NULL,
    storage_condition VARCHAR(100) NULL,
    max_hold_hours    SMALLINT UNSIGNED NULL,
    is_active         TINYINT(1) NOT NULL DEFAULT 1,
    display_order     INT NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by        INT UNSIGNED NULL,
    updated_at        DATETIME NULL,
    updated_by        INT UNSIGNED NULL,
    deleted_at        DATETIME NULL,
    deleted_by        INT UNSIGNED NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_containers (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(150) NOT NULL,
    color_code    VARCHAR(20) NULL,
    item_id       INT UNSIGNED NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    updated_at    DATETIME NULL,
    updated_by    INT UNSIGNED NULL,
    deleted_at    DATETIME NULL,
    deleted_by    INT UNSIGNED NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_tests (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code              VARCHAR(30) NULL,
    name              VARCHAR(150) NOT NULL,
    section_id        INT UNSIGNED NULL,
    category_id       INT UNSIGNED NULL,
    specimen_type_id  INT UNSIGNED NULL,
    container_id      INT UNSIGNED NULL,
    service_id        INT UNSIGNED NULL,
    investigation_id  INT UNSIGNED NULL,
    method            VARCHAR(100) NULL,
    result_type       VARCHAR(12) NOT NULL DEFAULT 'numeric',
    fasting_required  TINYINT(1) NOT NULL DEFAULT 0,
    is_panel          TINYINT(1) NOT NULL DEFAULT 0,
    is_outsourceable  TINYINT(1) NOT NULL DEFAULT 0,
    turnaround_hours  SMALLINT UNSIGNED NOT NULL DEFAULT 24,
    instructions      VARCHAR(255) NULL,
    is_active         TINYINT(1) NOT NULL DEFAULT 1,
    display_order     INT NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by        INT UNSIGNED NULL,
    updated_at        DATETIME NULL,
    updated_by        INT UNSIGNED NULL,
    deleted_at        DATETIME NULL,
    deleted_by        INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_lt_list (deleted_at, is_active, display_order),
    KEY idx_lt_inv (investigation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_test_parameters (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lab_test_id   INT UNSIGNED NOT NULL,
    code          VARCHAR(30) NULL,
    name          VARCHAR(150) NOT NULL,
    unit          VARCHAR(30) NULL,
    data_type     VARCHAR(10) NOT NULL DEFAULT 'numeric',
    options_json  TEXT NULL,
    decimals      TINYINT UNSIGNED NOT NULL DEFAULT 1,
    is_calculated TINYINT(1) NOT NULL DEFAULT 0,
    formula       VARCHAR(255) NULL,
    critical_low  DECIMAL(14,4) NULL,
    critical_high DECIMAL(14,4) NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_ltp_test (lab_test_id, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Age / sex / pregnancy-specific ranges; the applied range is snapshotted on each value.
CREATE TABLE IF NOT EXISTS lab_reference_ranges (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parameter_id INT UNSIGNED NOT NULL,
    sex          CHAR(1) NOT NULL DEFAULT 'A',
    age_min_days INT UNSIGNED NOT NULL DEFAULT 0,
    age_max_days INT UNSIGNED NOT NULL DEFAULT 54750,
    pregnant     TINYINT(1) NULL,
    low          DECIMAL(14,4) NULL,
    high         DECIMAL(14,4) NULL,
    text_range   VARCHAR(150) NULL,
    PRIMARY KEY (id),
    KEY idx_lrr (parameter_id, sex, age_min_days)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_panel_tests (
    panel_id      INT UNSIGNED NOT NULL,
    lab_test_id   INT UNSIGNED NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (panel_id, lab_test_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_analyzers (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id           INT UNSIGNED NULL,
    section_id          INT UNSIGNED NULL,
    name                VARCHAR(150) NOT NULL,
    model               VARCHAR(100) NULL,
    serial_no           VARCHAR(80) NULL,
    interface_type      VARCHAR(10) NOT NULL DEFAULT 'manual',
    status              VARCHAR(20) NOT NULL DEFAULT 'active',
    last_calibration_at DATETIME NULL,
    asset_id            INT UNSIGNED NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    display_order       INT NOT NULL DEFAULT 0,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          INT UNSIGNED NULL,
    updated_at          DATETIME NULL,
    updated_by          INT UNSIGNED NULL,
    deleted_at          DATETIME NULL,
    deleted_by          INT UNSIGNED NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_analyzer_test_map (
    analyzer_id   INT UNSIGNED NOT NULL,
    parameter_id  INT UNSIGNED NOT NULL,
    analyzer_code VARCHAR(30) NOT NULL,
    PRIMARY KEY (analyzer_id, parameter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- orders
CREATE TABLE IF NOT EXISTS lab_orders (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id        INT UNSIGNED NULL,
    order_number     VARCHAR(40) NOT NULL,
    visit_id         INT UNSIGNED NULL,
    patient_id       INT UNSIGNED NOT NULL,
    ordered_by       INT UNSIGNED NULL,
    ordered_at       DATETIME NOT NULL,
    priority         VARCHAR(10) NOT NULL DEFAULT 'routine',
    status           VARCHAR(16) NOT NULL DEFAULT 'ordered',
    source           VARCHAR(12) NOT NULL DEFAULT 'consult',
    referring_doctor VARCHAR(150) NULL,
    clinical_notes   VARCHAR(500) NULL,
    pregnancy_id     INT UNSIGNED NULL,
    invoice_id       INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lo_no (order_number),
    KEY idx_lo_patient (patient_id, ordered_at),
    KEY idx_lo_visit (visit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- status: ordered → collected → accepted → result_entered → verified → released
--         (rejected sample: resample_pending, or rejected_refunded)
CREATE TABLE IF NOT EXISTS lab_order_items (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lab_order_id          INT UNSIGNED NOT NULL,
    lab_test_id           INT UNSIGNED NOT NULL,
    status                VARCHAR(20) NOT NULL DEFAULT 'ordered',
    priority              VARCHAR(10) NOT NULL DEFAULT 'routine',
    performing_section_id INT UNSIGNED NULL,
    price                 DECIMAL(12,2) NOT NULL DEFAULT 0,
    invoice_item_id       INT UNSIGNED NULL,
    outsourced_referral_id INT UNSIGNED NULL,
    collected_at          DATETIME NULL,
    accepted_at           DATETIME NULL,
    result_entered_at     DATETIME NULL,
    verified_at           DATETIME NULL,
    verified_by           INT UNSIGNED NULL,
    released_at           DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_loi_order (lab_order_id),
    KEY idx_loi_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_samples (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lab_order_id          INT UNSIGNED NOT NULL,
    lab_order_item_id     INT UNSIGNED NOT NULL,
    barcode               VARCHAR(30) NOT NULL,
    specimen_type_id      INT UNSIGNED NULL,
    container_id          INT UNSIGNED NULL,
    status                VARCHAR(12) NOT NULL DEFAULT 'collected',
    fasting               TINYINT(1) NULL,
    collected_by          INT UNSIGNED NULL,
    collected_at          DATETIME NOT NULL,
    resample_of_sample_id INT UNSIGNED NULL,
    notes                 VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ls_barcode (barcode),
    KEY idx_ls_item (lab_order_item_id),
    KEY idx_ls_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A rejection carries exactly one resolution: resample XOR refund.
CREATE TABLE IF NOT EXISTS lab_sample_reviews (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lab_sample_id    INT UNSIGNED NOT NULL,
    reviewed_by      INT UNSIGNED NOT NULL,
    decision         VARCHAR(10) NOT NULL,
    rejection_reason VARCHAR(100) NULL,
    resolution       VARCHAR(10) NULL,
    refund_amount    DECIMAL(12,2) NULL,
    refund_id        INT UNSIGNED NULL,
    new_sample_id    INT UNSIGNED NULL,
    reviewed_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lsr_sample (lab_sample_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_worksheets (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id        INT UNSIGNED NULL,
    worksheet_number VARCHAR(40) NOT NULL,
    section_id       INT UNSIGNED NOT NULL,
    analyzer_id      INT UNSIGNED NULL,
    run_date         DATE NOT NULL,
    status           VARCHAR(12) NOT NULL DEFAULT 'open',
    created_by       INT UNSIGNED NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lw_no (worksheet_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_worksheet_items (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    worksheet_id      INT UNSIGNED NOT NULL,
    lab_sample_id     INT UNSIGNED NOT NULL,
    lab_order_item_id INT UNSIGNED NOT NULL,
    position          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_lwi_ws (worksheet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Header of a result (one per order item and version); values in lab_result_values.
CREATE TABLE IF NOT EXISTS lab_results (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lab_order_item_id      INT UNSIGNED NOT NULL,
    version                SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    is_current             TINYINT(1) NOT NULL DEFAULT 1,
    analyzer_id            INT UNSIGNED NULL,
    worksheet_item_id      INT UNSIGNED NULL,
    comment                VARCHAR(1000) NULL,
    is_critical            TINYINT(1) NOT NULL DEFAULT 0,
    entered_by             INT UNSIGNED NULL,
    entered_at             DATETIME NOT NULL,
    verified_by            INT UNSIGNED NULL,
    verified_at            DATETIME NULL,
    amended_from_result_id INT UNSIGNED NULL,
    amend_reason           VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_lr_item (lab_order_item_id, is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_result_values (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lab_result_id INT UNSIGNED NOT NULL,
    parameter_id  INT UNSIGNED NOT NULL,
    value_numeric DECIMAL(14,4) NULL,
    value_text    VARCHAR(500) NULL,
    unit          VARCHAR(30) NULL,
    ref_low       DECIMAL(14,4) NULL,
    ref_high      DECIMAL(14,4) NULL,
    ref_text      VARCHAR(150) NULL,
    flag          VARCHAR(15) NOT NULL DEFAULT 'normal',
    source        VARCHAR(12) NOT NULL DEFAULT 'manual',
    PRIMARY KEY (id),
    KEY idx_lrv_result (lab_result_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_qc_materials (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(150) NOT NULL,
    lot_no       VARCHAR(60) NULL,
    level        VARCHAR(20) NULL,
    expiry_date  DATE NULL,
    section_id   INT UNSIGNED NULL,
    parameter_id INT UNSIGNED NULL,
    target_mean  DECIMAL(14,4) NULL,
    target_sd    DECIMAL(14,4) NULL,
    item_id      INT UNSIGNED NULL,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_qc_runs (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    section_id     INT UNSIGNED NULL,
    analyzer_id    INT UNSIGNED NULL,
    parameter_id   INT UNSIGNED NOT NULL,
    qc_material_id INT UNSIGNED NOT NULL,
    run_at         DATETIME NOT NULL,
    measured_value DECIMAL(14,4) NOT NULL,
    target_mean    DECIMAL(14,4) NOT NULL,
    target_sd      DECIMAL(14,4) NOT NULL,
    z_score        DECIMAL(8,3) NOT NULL,
    status         VARCHAR(6) NOT NULL,
    westgard_rule  VARCHAR(10) NULL,
    override_by    INT UNSIGNED NULL,
    override_note  VARCHAR(255) NULL,
    recorded_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_qc_param (parameter_id, run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_reports (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id              INT UNSIGNED NULL,
    lab_order_id           INT UNSIGNED NOT NULL,
    report_number          VARCHAR(40) NOT NULL,
    version                SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    status                 VARCHAR(12) NOT NULL DEFAULT 'released',
    released_by            INT UNSIGNED NULL,
    released_at            DATETIME NOT NULL,
    handed_over_to         VARCHAR(150) NULL,
    handed_over_by         INT UNSIGNED NULL,
    handed_over_at         DATETIME NULL,
    print_count            INT UNSIGNED NOT NULL DEFAULT 0,
    amended_from_report_id INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lrep_no (report_number),
    KEY idx_lrep_order (lab_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_critical_notifications (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lab_result_value_id INT UNSIGNED NOT NULL,
    lab_order_item_id   INT UNSIGNED NOT NULL,
    patient_id          INT UNSIGNED NOT NULL,
    notified_user_id    INT UNSIGNED NOT NULL,
    notified_at         DATETIME NOT NULL,
    acknowledged_at     DATETIME NULL,
    read_back_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    read_back_note      VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_lcn_user (notified_user_id, acknowledged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_outsource_referrals (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lab_order_item_id    INT UNSIGNED NOT NULL,
    vendor_id            INT UNSIGNED NOT NULL,
    sent_at              DATETIME NOT NULL,
    tracking_no          VARCHAR(60) NULL,
    expected_at          DATE NULL,
    received_at          DATETIME NULL,
    external_report_path VARCHAR(255) NULL,
    cost                 DECIMAL(12,2) NULL,
    status               VARCHAR(12) NOT NULL DEFAULT 'sent',
    notes                VARCHAR(255) NULL,
    created_by           INT UNSIGNED NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_reagent_usage (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    section_id  INT UNSIGNED NULL,
    analyzer_id INT UNSIGNED NULL,
    item_id     INT UNSIGNED NOT NULL,
    batch_id    INT UNSIGNED NOT NULL DEFAULT 0,
    quantity    DECIMAL(12,3) NOT NULL,
    reference   VARCHAR(60) NULL,
    used_at     DATETIME NOT NULL,
    used_by     INT UNSIGNED NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
