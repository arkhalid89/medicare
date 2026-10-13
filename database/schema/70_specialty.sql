-- ============================================================================
--  v2 · Phase 3 · Specialty framework (Docs 6, 7 §3.3)
--  Tier 1: configurable fields per specialty. Body-map findings. Light
--  first-class tables (eye, growth, immunization, cardiac, audiogram).
--  Generic clinical notes (restricted flag) and referrals.
--  encounter_id = visits.id
-- ============================================================================

CREATE TABLE IF NOT EXISTS clinical_parameter_templates (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    specialty_id  INT UNSIGNED NOT NULL,
    branch_id     INT UNSIGNED NULL,
    field_key     VARCHAR(50) NOT NULL,
    label         VARCHAR(150) NOT NULL,
    field_type    VARCHAR(12) NOT NULL DEFAULT 'text',
    options_json  TEXT NULL,
    unit          VARCHAR(20) NULL,
    is_required   TINYINT(1) NOT NULL DEFAULT 0,
    display_order INT NOT NULL DEFAULT 0,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    updated_at    DATETIME NULL,
    updated_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cpt_key (specialty_id, field_key),
    KEY idx_cpt_spec (specialty_id, is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS encounter_clinical_parameters (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    encounter_id      INT UNSIGNED NOT NULL,
    template_field_id INT UNSIGNED NOT NULL,
    value_text        VARCHAR(1000) NULL,
    value_number      DECIMAL(14,4) NULL,
    value_json        TEXT NULL,
    recorded_by       INT UNSIGNED NULL,
    recorded_at       DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ecp (encounter_id, template_field_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS body_map_templates (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    specialty_id  INT UNSIGNED NULL,
    name          VARCHAR(100) NOT NULL,
    view_code     VARCHAR(30) NOT NULL,
    svg_asset_path VARCHAR(255) NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bmt_code (view_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS body_map_regions (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_id    INT UNSIGNED NOT NULL,
    region_code    VARCHAR(40) NOT NULL,
    label          VARCHAR(100) NOT NULL,
    svg_element_id VARCHAR(60) NULL,
    shape_json     TEXT NULL,
    display_order  INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bmr (template_id, region_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS encounter_body_map_findings (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    encounter_id INT UNSIGNED NOT NULL,
    patient_id   INT UNSIGNED NOT NULL,
    region_id    INT UNSIGNED NOT NULL,
    laterality   VARCHAR(10) NOT NULL DEFAULT 'na',
    finding_type VARCHAR(60) NOT NULL,
    severity     VARCHAR(10) NULL,
    size_cm      DECIMAL(6,2) NULL,
    description  VARCHAR(500) NULL,
    photo_path   VARCHAR(255) NULL,
    recorded_by  INT UNSIGNED NULL,
    recorded_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ebmf_enc (encounter_id),
    KEY idx_ebmf_patient (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Restricted notes are visible only to the author, the treating team and break-glass (Doc 1 §2.8 / §6).
CREATE TABLE IF NOT EXISTS clinical_notes (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    encounter_id  INT UNSIGNED NULL,
    patient_id    INT UNSIGNED NOT NULL,
    author_id     INT UNSIGNED NOT NULL,
    note_type     VARCHAR(20) NOT NULL DEFAULT 'progress',
    note_text     TEXT NOT NULL,
    is_restricted TINYINT(1) NOT NULL DEFAULT 0,
    shared_user_ids VARCHAR(255) NULL,
    created_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cn_patient (patient_id, created_at),
    KEY idx_cn_enc (encounter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referrals (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id        INT UNSIGNED NULL,
    encounter_id     INT UNSIGNED NULL,
    patient_id       INT UNSIGNED NOT NULL,
    from_doctor_id   INT UNSIGNED NOT NULL,
    to_department_id INT UNSIGNED NULL,
    to_doctor_id     INT UNSIGNED NULL,
    external_to      VARCHAR(150) NULL,
    urgency          VARCHAR(10) NOT NULL DEFAULT 'routine',
    reason           VARCHAR(1000) NOT NULL,
    source           VARCHAR(20) NULL,
    status           VARCHAR(12) NOT NULL DEFAULT 'open',
    accepted_by      INT UNSIGNED NULL,
    closed_at        DATETIME NULL,
    created_at       DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ref_to (to_department_id, status),
    KEY idx_ref_doc (to_doctor_id, status),
    KEY idx_ref_patient (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eye_exams (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    encounter_id    INT UNSIGNED NOT NULL,
    patient_id      INT UNSIGNED NOT NULL,
    va_od_unaided   VARCHAR(12) NULL,
    va_os_unaided   VARCHAR(12) NULL,
    va_od_corrected VARCHAR(12) NULL,
    va_os_corrected VARCHAR(12) NULL,
    iop_od_mmhg     DECIMAL(5,1) NULL,
    iop_os_mmhg     DECIMAL(5,1) NULL,
    refraction_json TEXT NULL,
    slit_lamp_json  TEXT NULL,
    fundus_findings VARCHAR(1000) NULL,
    recorded_by     INT UNSIGNED NULL,
    recorded_at     DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_eye_enc (encounter_id),
    KEY idx_eye_patient (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS growth_measurements (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id            INT UNSIGNED NOT NULL,
    encounter_id          INT UNSIGNED NULL,
    measured_on           DATE NOT NULL,
    age_months            DECIMAL(6,1) NOT NULL,
    weight_kg             DECIMAL(6,2) NULL,
    height_cm             DECIMAL(6,1) NULL,
    head_circumference_cm DECIMAL(5,1) NULL,
    bmi                   DECIMAL(5,2) NULL,
    percentiles_json      TEXT NULL,
    chart_standard        VARCHAR(5) NOT NULL DEFAULT 'WHO',
    recorded_by           INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_gm_patient (patient_id, measured_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- schedule_json: [{"dose":1,"age_weeks":0,"label":"At birth"}, ...]
CREATE TABLE IF NOT EXISTS vaccines (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code          VARCHAR(20) NULL,
    name          VARCHAR(150) NOT NULL,
    item_id       INT UNSIGNED NULL,
    service_id    INT UNSIGNED NULL,
    schedule_json TEXT NULL,
    is_epi        TINYINT(1) NOT NULL DEFAULT 1,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS immunization_records (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id     INT UNSIGNED NOT NULL,
    encounter_id   INT UNSIGNED NULL,
    vaccine_id     INT UNSIGNED NOT NULL,
    dose_no        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    given_at       DATETIME NOT NULL,
    stock_batch_id INT UNSIGNED NULL,
    site           VARCHAR(30) NULL,
    given_by       INT UNSIGNED NULL,
    next_due_date  DATE NULL,
    notes          VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_imm (patient_id, vaccine_id, dose_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cardiac_investigations (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    encounter_id     INT UNSIGNED NOT NULL,
    patient_id       INT UNSIGNED NOT NULL,
    type             VARCHAR(10) NOT NULL,
    rhythm           VARCHAR(60) NULL,
    heart_rate       SMALLINT UNSIGNED NULL,
    ef_percent       DECIMAL(4,1) NULL,
    findings         VARCHAR(1000) NULL,
    report_file_path VARCHAR(255) NULL,
    performed_by     INT UNSIGNED NULL,
    performed_at     DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ci_patient (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- *_json: {"250":20,"500":25,"1000":30,"2000":35,"4000":40,"8000":45} dB HL
CREATE TABLE IF NOT EXISTS audiograms (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    encounter_id         INT UNSIGNED NOT NULL,
    patient_id           INT UNSIGNED NOT NULL,
    ear                  CHAR(1) NOT NULL,
    air_conduction_json  TEXT NULL,
    bone_conduction_json TEXT NULL,
    tympanometry_type    VARCHAR(5) NULL,
    pta_db               DECIMAL(5,1) NULL,
    performed_by         INT UNSIGNED NULL,
    performed_at         DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_aud (encounter_id, ear)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
