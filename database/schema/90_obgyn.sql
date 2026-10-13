-- ============================================================================
--  v2 · Phase 3 · Gynecology / Obstetrics (Docs 6 §6, 7 §3.2, 8 §5.3)
--  Patient-level gynae history; pregnancies with deliveries (newborn linked
--  to mother); per-encounter ANC / PNC; obstetric history is a DERIVED cache.
--  encounter_id = visits.id
-- ============================================================================

CREATE TABLE IF NOT EXISTS gynecologic_history (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id           INT UNSIGNED NOT NULL,
    menarche_age         TINYINT UNSIGNED NULL,
    cycle_length_days    TINYINT UNSIGNED NULL,
    cycle_duration_days  TINYINT UNSIGNED NULL,
    cycle_regularity     VARCHAR(10) NULL,
    lmp_date             DATE NULL,
    contraceptive_method VARCHAR(40) NULL,
    menopause_status     VARCHAR(15) NULL,
    pap_smear_last       DATE NULL,
    notes                VARCHAR(1000) NULL,
    updated_by           INT UNSIGNED NULL,
    updated_at           DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gh_patient (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS obstetric_history (
    id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id              INT UNSIGNED NOT NULL,
    gravida                 TINYINT UNSIGNED NOT NULL DEFAULT 0,
    para                    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    abortions               TINYINT UNSIGNED NOT NULL DEFAULT 0,
    living_children         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    previous_cesareans      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    previous_delivery_modes VARCHAR(500) NULL,
    updated_by              INT UNSIGNED NULL,
    updated_at              DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oh_patient (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- status: ongoing / delivered / miscarriage / termination / ectopic / molar
-- recorded_historical = 1 for past pregnancies entered from history (not booked here)
CREATE TABLE IF NOT EXISTS pregnancies (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id            INT UNSIGNED NULL,
    patient_id           INT UNSIGNED NOT NULL,
    pregnancy_no         TINYINT UNSIGNED NOT NULL,
    lmp_date             DATE NULL,
    edd_date             DATE NULL,
    edd_method           VARCHAR(12) NOT NULL DEFAULT 'lmp',
    status               VARCHAR(12) NOT NULL DEFAULT 'ongoing',
    risk_level           VARCHAR(5) NOT NULL DEFAULT 'low',
    risk_reasons_json    TEXT NULL,
    booking_encounter_id INT UNSIGNED NULL,
    blood_group          VARCHAR(5) NULL,
    is_multiple          TINYINT(1) NOT NULL DEFAULT 0,
    recorded_historical  TINYINT(1) NOT NULL DEFAULT 0,
    outcome_date         DATE NULL,
    outcome_notes        VARCHAR(500) NULL,
    created_by           INT UNSIGNED NULL,
    created_at           DATETIME NOT NULL,
    updated_at           DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_preg_no (patient_id, pregnancy_no),
    KEY idx_preg_status (status, edd_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pregnancy_deliveries (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pregnancy_id          INT UNSIGNED NOT NULL,
    delivery_date         DATETIME NOT NULL,
    gestational_age_weeks TINYINT UNSIGNED NULL,
    mode                  VARCHAR(20) NOT NULL,
    place                 VARCHAR(15) NOT NULL DEFAULT 'this_hospital',
    attended_by           INT UNSIGNED NULL,
    outcome               VARCHAR(15) NOT NULL DEFAULT 'live_birth',
    baby_sex              VARCHAR(6) NULL,
    birth_weight_g        SMALLINT UNSIGNED NULL,
    apgar_1min            TINYINT UNSIGNED NULL,
    apgar_5min            TINYINT UNSIGNED NULL,
    complications         VARCHAR(500) NULL,
    baby_patient_id       INT UNSIGNED NULL,
    encounter_id          INT UNSIGNED NULL,
    recorded_by           INT UNSIGNED NULL,
    is_draft              TINYINT(1) NOT NULL DEFAULT 0,
    cosigned_by           INT UNSIGNED NULL,
    cosigned_at           DATETIME NULL,
    created_at            DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_pd_preg (pregnancy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS antenatal_visits (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    encounter_id          INT UNSIGNED NULL,
    pregnancy_id          INT UNSIGNED NOT NULL,
    patient_id            INT UNSIGNED NOT NULL,
    visit_date            DATE NOT NULL,
    gestational_age_weeks DECIMAL(4,1) NULL,
    trimester             TINYINT UNSIGNED NULL,
    fundal_height_cm      DECIMAL(4,1) NULL,
    fetal_heart_rate_bpm  SMALLINT UNSIGNED NULL,
    fetal_position        VARCHAR(30) NULL,
    presentation          VARCHAR(20) NULL,
    bp_systolic           SMALLINT UNSIGNED NULL,
    bp_diastolic          SMALLINT UNSIGNED NULL,
    weight_kg             DECIMAL(5,1) NULL,
    edema_flag            TINYINT(1) NOT NULL DEFAULT 0,
    urine_protein_flag    TINYINT(1) NOT NULL DEFAULT 0,
    hb_g_dl               DECIMAL(4,1) NULL,
    tt_dose_given         TINYINT(1) NOT NULL DEFAULT 0,
    risk_flags_json       TEXT NULL,
    notes                 VARCHAR(1000) NULL,
    next_visit_date       DATE NULL,
    recorded_by           INT UNSIGNED NULL,
    cosigned_by           INT UNSIGNED NULL,
    cosigned_at           DATETIME NULL,
    created_at            DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_anc_preg (pregnancy_id, visit_date),
    KEY idx_anc_enc (encounter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS postnatal_visits (
    id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    encounter_id             INT UNSIGNED NULL,
    pregnancy_id             INT UNSIGNED NOT NULL,
    patient_id               INT UNSIGNED NOT NULL,
    visit_date               DATE NOT NULL,
    days_postpartum          SMALLINT UNSIGNED NULL,
    bp_systolic              SMALLINT UNSIGNED NULL,
    bp_diastolic             SMALLINT UNSIGNED NULL,
    lochia                   VARCHAR(20) NULL,
    uterine_involution       VARCHAR(20) NULL,
    perineal_wound_status    VARCHAR(30) NULL,
    breastfeeding_status     VARCHAR(20) NULL,
    contraception_counselled TINYINT(1) NOT NULL DEFAULT 0,
    notes                    VARCHAR(1000) NULL,
    recorded_by              INT UNSIGNED NULL,
    created_at               DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_pnc_preg (pregnancy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS obstetric_ultrasound_findings (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    radiology_report_id INT UNSIGNED NULL,
    radiology_order_id  INT UNSIGNED NULL,
    pregnancy_id        INT UNSIGNED NOT NULL,
    scan_date           DATE NOT NULL,
    ga_by_scan_weeks    DECIMAL(4,1) NULL,
    biometry_json       TEXT NULL,
    efw_g               SMALLINT UNSIGNED NULL,
    afi_cm              DECIMAL(4,1) NULL,
    fhr_bpm             SMALLINT UNSIGNED NULL,
    placenta_location   VARCHAR(30) NULL,
    fetal_presentation  VARCHAR(20) NULL,
    cervical_length_mm  DECIMAL(4,1) NULL,
    number_of_fetuses   TINYINT UNSIGNED NOT NULL DEFAULT 1,
    notes               VARCHAR(500) NULL,
    recorded_by         INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ous_preg (pregnancy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
