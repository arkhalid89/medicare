-- ============================================================================
--  v2 · Phase 3 · Radiology (Docs 2 §5.9, 3 WF 5B, 4 §4, 7 §5, 8 §5.5)
--  Radiologist quality gate: accept / reprocess / reject + refund.
--  A finalized report is immutable — corrections are addenda.
-- ============================================================================

CREATE TABLE IF NOT EXISTS radiology_modalities (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code          VARCHAR(10) NOT NULL,
    name          VARCHAR(100) NOT NULL,
    is_ionizing   TINYINT(1) NOT NULL DEFAULT 0,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rmod_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_radiology_modalities (
    user_id     INT UNSIGNED NOT NULL,
    modality_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, modality_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radiology_rooms (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id        INT UNSIGNED NULL,
    modality_id      INT UNSIGNED NOT NULL,
    name             VARCHAR(100) NOT NULL,
    service_point_id INT UNSIGNED NULL,
    slot_minutes     SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    opens_at         TIME NOT NULL DEFAULT '09:00:00',
    closes_at        TIME NOT NULL DEFAULT '17:00:00',
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    display_order    INT NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at       DATETIME NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radiology_equipment (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id      INT UNSIGNED NULL,
    modality_id  INT UNSIGNED NOT NULL,
    name         VARCHAR(150) NOT NULL,
    manufacturer VARCHAR(100) NULL,
    serial_no    VARCHAR(80) NULL,
    installed_on DATE NULL,
    last_qa_at   DATE NULL,
    status       VARCHAR(20) NOT NULL DEFAULT 'active',
    asset_id     INT UNSIGNED NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at   DATETIME NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radiology_protocols (
    id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    modality_id               INT UNSIGNED NOT NULL,
    name                      VARCHAR(150) NOT NULL,
    technical_parameters_json TEXT NULL,
    contrast_default          TINYINT(1) NOT NULL DEFAULT 0,
    is_active                 TINYINT(1) NOT NULL DEFAULT 1,
    created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- body_json: {"technique": "...", "findings": "...", "impression": "..."} normal-text macros
CREATE TABLE IF NOT EXISTS radiology_report_templates (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    modality_id  INT UNSIGNED NULL,
    procedure_id INT UNSIGNED NULL,
    name         VARCHAR(150) NOT NULL,
    body_json    TEXT NOT NULL,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radiology_procedure_catalog (
    id                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code                       VARCHAR(30) NULL,
    modality_id                INT UNSIGNED NOT NULL,
    name                       VARCHAR(150) NOT NULL,
    body_part                  VARCHAR(80) NULL,
    service_id                 INT UNSIGNED NULL,
    investigation_id           INT UNSIGNED NULL,
    default_protocol_id        INT UNSIGNED NULL,
    default_report_template_id INT UNSIGNED NULL,
    requires_prep              TINYINT(1) NOT NULL DEFAULT 0,
    prep_instructions          VARCHAR(500) NULL,
    est_duration_min           SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    uses_ionizing_radiation    TINYINT(1) NOT NULL DEFAULT 0,
    contrast_possible          TINYINT(1) NOT NULL DEFAULT 0,
    is_obstetric               TINYINT(1) NOT NULL DEFAULT 0,
    is_active                  TINYINT(1) NOT NULL DEFAULT 1,
    display_order              INT NOT NULL DEFAULT 0,
    created_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at                 DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_rpc_inv (investigation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- status: ordered → scheduled → in_progress → under_review → accepted → reported
--         (reprocess_requested → in_progress; rejected_refunded; cancelled)
CREATE TABLE IF NOT EXISTS radiology_orders (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id           INT UNSIGNED NULL,
    order_number        VARCHAR(40) NOT NULL,
    encounter_id        INT UNSIGNED NULL,
    patient_id          INT UNSIGNED NOT NULL,
    procedure_id        INT UNSIGNED NOT NULL,
    modality_id         INT UNSIGNED NOT NULL,
    body_part           VARCHAR(80) NULL,
    ordered_by          INT UNSIGNED NULL,
    ordered_at          DATETIME NOT NULL,
    source              VARCHAR(12) NOT NULL DEFAULT 'consult',
    referring_doctor    VARCHAR(150) NULL,
    priority            VARCHAR(10) NOT NULL DEFAULT 'routine',
    clinical_indication VARCHAR(500) NULL,
    pregnancy_status    VARCHAR(16) NOT NULL DEFAULT 'not_applicable',
    lmp_date            DATE NULL,
    contrast_required   TINYINT(1) NOT NULL DEFAULT 0,
    allergy_checked     TINYINT(1) NOT NULL DEFAULT 0,
    creatinine_value    DECIMAL(6,2) NULL,
    screened_by         INT UNSIGNED NULL,
    screened_at         DATETIME NULL,
    scheduled_at        DATETIME NULL,
    room_id             INT UNSIGNED NULL,
    status              VARCHAR(20) NOT NULL DEFAULT 'ordered',
    price               DECIMAL(12,2) NOT NULL DEFAULT 0,
    service_id          INT UNSIGNED NULL,
    invoice_id          INT UNSIGNED NULL,
    invoice_item_id     INT UNSIGNED NULL,
    pregnancy_id        INT UNSIGNED NULL,
    cancel_reason       VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ro_no (order_number),
    KEY idx_ro_status (status, priority),
    KEY idx_ro_patient (patient_id, ordered_at),
    KEY idx_ro_enc (encounter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radiology_procedures (
    id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    radiology_order_id        INT UNSIGNED NOT NULL,
    room_id                   INT UNSIGNED NULL,
    equipment_id              INT UNSIGNED NULL,
    protocol_id               INT UNSIGNED NULL,
    performed_by              INT UNSIGNED NULL,
    started_at                DATETIME NOT NULL,
    ended_at                  DATETIME NULL,
    performed_at              DATETIME NULL,
    image_count               SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    image_reference           VARCHAR(255) NULL,
    image_path                VARCHAR(255) NULL,
    technical_notes           VARCHAR(1000) NULL,
    reprocess_of_procedure_id INT UNSIGNED NULL,
    status                    VARCHAR(12) NOT NULL DEFAULT 'in_progress',
    PRIMARY KEY (id),
    KEY idx_rp_order (radiology_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- decision accepted / reprocess_requested / rejected; resolution reprocess XOR refund when not accepted
CREATE TABLE IF NOT EXISTS radiology_procedure_reviews (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    radiology_procedure_id INT UNSIGNED NOT NULL,
    reviewed_by            INT UNSIGNED NOT NULL,
    decision               VARCHAR(20) NOT NULL,
    reason                 VARCHAR(255) NULL,
    resolution             VARCHAR(10) NULL,
    refund_amount          DECIMAL(12,2) NULL,
    refund_id              INT UNSIGNED NULL,
    new_procedure_id       INT UNSIGNED NULL,
    reviewed_at            DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rpr_proc (radiology_procedure_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radiology_reports (
    id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    radiology_order_id        INT UNSIGNED NOT NULL,
    radiology_procedure_id    INT UNSIGNED NOT NULL,
    template_id               INT UNSIGNED NULL,
    technique                 TEXT NULL,
    findings                  TEXT NULL,
    impression                TEXT NULL,
    status                    VARCHAR(10) NOT NULL DEFAULT 'draft',
    is_critical_finding       TINYINT(1) NOT NULL DEFAULT 0,
    critical_notified_user_id INT UNSIGNED NULL,
    critical_acknowledged_at  DATETIME NULL,
    reported_by               INT UNSIGNED NULL,
    reported_at               DATETIME NULL,
    finalized_at              DATETIME NULL,
    print_count               INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rr_order (radiology_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radiology_report_addenda (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    radiology_report_id INT UNSIGNED NOT NULL,
    addendum_text       TEXT NOT NULL,
    added_by            INT UNSIGNED NOT NULL,
    added_at            DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_rra_report (radiology_report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radiology_contrast_usage (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    procedure_id       INT UNSIGNED NOT NULL,
    item_id            INT UNSIGNED NOT NULL,
    stock_batch_id     INT UNSIGNED NOT NULL DEFAULT 0,
    volume_ml          DECIMAL(8,2) NOT NULL,
    route              VARCHAR(20) NULL,
    reaction           VARCHAR(255) NULL,
    creatinine_checked TINYINT(1) NOT NULL DEFAULT 0,
    administered_by    INT UNSIGNED NULL,
    administered_at    DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radiology_dose_log (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    procedure_id   INT UNSIGNED NOT NULL,
    equipment_id   INT UNSIGNED NULL,
    kvp            DECIMAL(6,1) NULL,
    mas            DECIMAL(8,2) NULL,
    dap_value      DECIMAL(10,3) NULL,
    ctdi_vol       DECIMAL(10,3) NULL,
    dlp            DECIMAL(10,3) NULL,
    exposure_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    recorded_by    INT UNSIGNED NULL,
    recorded_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_rdl_proc (procedure_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radiology_schedule_slots (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id            INT UNSIGNED NOT NULL,
    slot_start         DATETIME NOT NULL,
    slot_end           DATETIME NOT NULL,
    radiology_order_id INT UNSIGNED NULL,
    status             VARCHAR(12) NOT NULL DEFAULT 'booked',
    booked_by          INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rss_room_start (room_id, slot_start, status),
    KEY idx_rss_order (radiology_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
