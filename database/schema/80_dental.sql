-- ============================================================================
--  v2 · Phase 3 · Dentistry (Docs 6 §5, 7 §3.1, 8 §5.2)
--  FDI tooth map → findings (+ surfaces) → treatment plan → done today:
--  invoice line via service_id + consumables via service_consumption_templates.
--  encounter_id = visits.id
-- ============================================================================

CREATE TABLE IF NOT EXISTS tooth_reference (
    tooth_number        CHAR(2) NOT NULL,
    name                VARCHAR(60) NOT NULL,
    dentition           VARCHAR(10) NOT NULL,
    arch                VARCHAR(5) NOT NULL,
    quadrant            TINYINT UNSIGNED NOT NULL,
    tooth_type          VARCHAR(10) NOT NULL,
    valid_surfaces_json VARCHAR(255) NOT NULL,
    chart_x             SMALLINT NOT NULL DEFAULT 0,
    chart_y             SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (tooth_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_procedure_catalog (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code             VARCHAR(20) NOT NULL,
    name             VARCHAR(150) NOT NULL,
    category         VARCHAR(15) NOT NULL DEFAULT 'restorative',
    applies_to       VARCHAR(12) NOT NULL DEFAULT 'tooth',
    requires_tooth   TINYINT(1) NOT NULL DEFAULT 1,
    requires_surface TINYINT(1) NOT NULL DEFAULT 0,
    hygienist_allowed TINYINT(1) NOT NULL DEFAULT 0,
    needs_lab_case   TINYINT(1) NOT NULL DEFAULT 0,
    result_condition VARCHAR(20) NULL,
    service_id       INT UNSIGNED NULL,
    typical_visits   TINYINT UNSIGNED NOT NULL DEFAULT 1,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    display_order    INT NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dpc_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_charts (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id         INT UNSIGNED NULL,
    encounter_id      INT UNSIGNED NOT NULL,
    patient_id        INT UNSIGNED NOT NULL,
    chart_date        DATE NOT NULL,
    dentition_type    VARCHAR(10) NOT NULL DEFAULT 'permanent',
    chart_type        VARCHAR(15) NOT NULL DEFAULT 'initial',
    previous_chart_id INT UNSIGNED NULL,
    general_notes     VARCHAR(1000) NULL,
    created_by        INT UNSIGNED NULL,
    created_at        DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dc_enc (encounter_id),
    KEY idx_dc_patient (patient_id, chart_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- procedure_status: planned / completed / n_a. cosigned_* filled when a resident's chart is co-signed.
CREATE TABLE IF NOT EXISTS dental_tooth_findings (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dental_chart_id  INT UNSIGNED NOT NULL,
    tooth_number     CHAR(2) NOT NULL,
    `condition`      VARCHAR(20) NOT NULL,
    finding_status   VARCHAR(10) NOT NULL DEFAULT 'new',
    severity         VARCHAR(10) NULL,
    mobility_grade   TINYINT UNSIGNED NULL,
    procedure_id     INT UNSIGNED NULL,
    procedure_status VARCHAR(10) NOT NULL DEFAULT 'n_a',
    plan_item_id     INT UNSIGNED NULL,
    notes            VARCHAR(500) NULL,
    attachment_path  VARCHAR(255) NULL,
    recorded_by      INT UNSIGNED NULL,
    cosigned_by      INT UNSIGNED NULL,
    cosigned_at      DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_dtf_chart (dental_chart_id),
    KEY idx_dtf_tooth (tooth_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_finding_surfaces (
    id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dental_tooth_finding_id INT UNSIGNED NOT NULL,
    surface                 VARCHAR(10) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dfs (dental_tooth_finding_id, surface)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_treatment_plans (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id           INT UNSIGNED NULL,
    patient_id          INT UNSIGNED NOT NULL,
    origin_encounter_id INT UNSIGNED NULL,
    dentist_id          INT UNSIGNED NULL,
    status              VARCHAR(12) NOT NULL DEFAULT 'proposed',
    estimated_total     DECIMAL(12,2) NOT NULL DEFAULT 0,
    accepted_at         DATETIME NULL,
    accepted_by_name    VARCHAR(150) NULL,
    notes               VARCHAR(500) NULL,
    created_at          DATETIME NOT NULL,
    updated_at          DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_dtp_patient (patient_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_treatment_plan_items (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    plan_id                INT UNSIGNED NOT NULL,
    procedure_id           INT UNSIGNED NOT NULL,
    tooth_number           CHAR(2) NULL,
    surfaces               VARCHAR(40) NULL,
    quadrant               TINYINT UNSIGNED NULL,
    source_finding_id      INT UNSIGNED NULL,
    sequence_no            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    planned_visit_no       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    status                 VARCHAR(10) NOT NULL DEFAULT 'planned',
    performed_encounter_id INT UNSIGNED NULL,
    performed_by           INT UNSIGNED NULL,
    performed_at           DATETIME NULL,
    estimated_price        DECIMAL(12,2) NOT NULL DEFAULT 0,
    invoice_item_id        INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_dtpi_plan (plan_id, sequence_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_perio_charts (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    encounter_id INT UNSIGNED NULL,
    patient_id   INT UNSIGNED NOT NULL,
    charted_by   INT UNSIGNED NULL,
    charted_at   DATETIME NOT NULL,
    notes        VARCHAR(500) NULL,
    PRIMARY KEY (id),
    KEY idx_dpc_patient (patient_id, charted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_perio_measurements (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    perio_chart_id      INT UNSIGNED NOT NULL,
    tooth_number        CHAR(2) NOT NULL,
    site                VARCHAR(3) NOT NULL,
    pocket_depth_mm     TINYINT UNSIGNED NULL,
    recession_mm        TINYINT UNSIGNED NULL,
    bleeding_on_probing TINYINT(1) NOT NULL DEFAULT 0,
    plaque              TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dpm (perio_chart_id, tooth_number, site)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- status: created → sent → in_progress → received → fitted (or cancelled)
CREATE TABLE IF NOT EXISTS dental_lab_cases (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id              INT UNSIGNED NULL,
    case_number            VARCHAR(40) NOT NULL,
    patient_id             INT UNSIGNED NOT NULL,
    treatment_plan_item_id INT UNSIGNED NULL,
    tooth_numbers          VARCHAR(60) NULL,
    case_type              VARCHAR(12) NOT NULL DEFAULT 'crown',
    shade                  VARCHAR(20) NULL,
    material               VARCHAR(60) NULL,
    instructions           VARCHAR(500) NULL,
    technician_user_id     INT UNSIGNED NULL,
    external_vendor_id     INT UNSIGNED NULL,
    created_by             INT UNSIGNED NULL,
    created_at             DATETIME NOT NULL,
    sent_at                DATETIME NULL,
    due_date               DATE NULL,
    received_at            DATETIME NULL,
    fitted_at              DATETIME NULL,
    status                 VARCHAR(12) NOT NULL DEFAULT 'created',
    status_note            VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dlc_no (case_number),
    KEY idx_dlc_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_chart_images (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dental_chart_id    INT UNSIGNED NULL,
    patient_id         INT UNSIGNED NOT NULL,
    radiology_order_id INT UNSIGNED NULL,
    image_type         VARCHAR(16) NOT NULL DEFAULT 'intraoral_photo',
    tooth_number       CHAR(2) NULL,
    file_path          VARCHAR(255) NULL,
    caption            VARCHAR(255) NULL,
    uploaded_by        INT UNSIGNED NULL,
    uploaded_at        DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_dci_patient (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
