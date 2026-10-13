-- ============================================================================
--  v2 · Phase 2 · Pharmacy (Docs 2 §5.10, 3 WF 6, 7 §7)
--  Prescriptions stay in visit_medicines (the e-Rx); hospital-source lines
--  enter the dispensing queue. Stock moves through the unified ledger.
-- ============================================================================

CREATE TABLE IF NOT EXISTS dispenses (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id       INT UNSIGNED NULL,
    visit_id        INT UNSIGNED NULL,
    patient_id      INT UNSIGNED NOT NULL,
    location_id     INT UNSIGNED NOT NULL,
    dispense_type   VARCHAR(15) NOT NULL DEFAULT 'opd',
    token_id        INT UNSIGNED NULL,
    status          VARCHAR(16) NOT NULL DEFAULT 'pending',
    counseling_done TINYINT(1) NOT NULL DEFAULT 0,
    needs_cosign    TINYINT(1) NOT NULL DEFAULT 0,
    dispensed_by    INT UNSIGNED NULL,
    dispensed_at    DATETIME NULL,
    cosigned_by     INT UNSIGNED NULL,
    cosigned_at     DATETIME NULL,
    invoice_id      INT UNSIGNED NULL,
    notes           VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_disp_status (status, created_at),
    KEY idx_disp_visit (visit_id),
    KEY idx_disp_patient (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per batch handed out (FEFO may split a prescription line over batches).
CREATE TABLE IF NOT EXISTS dispense_items (
    id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dispense_id             INT UNSIGNED NOT NULL,
    visit_medicine_id       INT UNSIGNED NULL,
    item_id                 INT UNSIGNED NOT NULL,
    substituted_for_item_id INT UNSIGNED NULL,
    batch_id                INT UNSIGNED NOT NULL DEFAULT 0,
    qty                     DECIMAL(12,3) NOT NULL,
    unit_price              DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount                  DECIMAL(12,2) NOT NULL DEFAULT 0,
    invoice_item_id         INT UNSIGNED NULL,
    ledger_id               BIGINT UNSIGNED NULL,
    returned_qty            DECIMAL(12,3) NOT NULL DEFAULT 0,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_di_disp (dispense_id),
    KEY idx_di_batch (batch_id),
    KEY idx_di_vm (visit_medicine_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pharmacy_sales (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id     INT UNSIGNED NULL,
    sale_number   VARCHAR(40) NOT NULL,
    location_id   INT UNSIGNED NOT NULL,
    sale_type     VARCHAR(25) NOT NULL DEFAULT 'otc_walk_in',
    patient_id    INT UNSIGNED NULL,
    customer_name VARCHAR(150) NULL,
    invoice_id    INT UNSIGNED NULL,
    total_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,
    status        VARCHAR(12) NOT NULL DEFAULT 'completed',
    sold_by       INT UNSIGNED NULL,
    sold_at       DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ps_no (sale_number),
    KEY idx_ps_sold (sold_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pharmacy_sale_items (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sale_id      INT UNSIGNED NOT NULL,
    item_id      INT UNSIGNED NOT NULL,
    batch_id     INT UNSIGNED NOT NULL DEFAULT 0,
    qty          DECIMAL(12,3) NOT NULL,
    unit_price   DECIMAL(12,2) NOT NULL,
    discount     DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount       DECIMAL(12,2) NOT NULL,
    returned_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_psi_sale (sale_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pharmacy_returns (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id     INT UNSIGNED NULL,
    return_number VARCHAR(40) NOT NULL,
    sale_id       INT UNSIGNED NULL,
    dispense_id   INT UNSIGNED NULL,
    patient_id    INT UNSIGNED NULL,
    location_id   INT UNSIGNED NOT NULL,
    status        VARCHAR(12) NOT NULL DEFAULT 'requested',
    reason        VARCHAR(255) NOT NULL,
    total_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,
    refund_id     INT UNSIGNED NULL,
    requested_by  INT UNSIGNED NULL,
    approved_by   INT UNSIGNED NULL,
    approved_at   DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pr_ret_no (return_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pharmacy_return_items (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    return_id      INT UNSIGNED NOT NULL,
    source_type    VARCHAR(20) NOT NULL,
    source_item_id INT UNSIGNED NOT NULL,
    item_id        INT UNSIGNED NOT NULL,
    batch_id       INT UNSIGNED NOT NULL DEFAULT 0,
    qty            DECIMAL(12,3) NOT NULL,
    unit_price     DECIMAL(12,2) NOT NULL DEFAULT 0,
    resellable     TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_pri_ret (return_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Interaction checker data (generic names, case-insensitive match).
CREATE TABLE IF NOT EXISTS drug_interactions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    generic_a   VARCHAR(150) NOT NULL,
    generic_b   VARCHAR(150) NOT NULL,
    severity    VARCHAR(16) NOT NULL DEFAULT 'moderate',
    description VARCHAR(500) NOT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by  INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_dint_a (generic_a),
    KEY idx_dint_b (generic_b)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Allergy class of a drug, matched against the patient's allergies text.
CREATE TABLE IF NOT EXISTS drug_allergy_classes (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    medicine_id   INT UNSIGNED NOT NULL,
    allergy_class VARCHAR(60) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_dac_med (medicine_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS drug_substitutions (
    id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    medicine_id              INT UNSIGNED NOT NULL,
    substitute_medicine_id   INT UNSIGNED NOT NULL,
    is_therapeutic_equivalent TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dsub (medicine_id, substitute_medicine_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Regulatory register, append-only; every controlled movement is co-signed.
CREATE TABLE IF NOT EXISTS controlled_drug_register (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id     INT UNSIGNED NULL,
    ledger_id     BIGINT UNSIGNED NULL,
    item_id       INT UNSIGNED NOT NULL,
    batch_id      INT UNSIGNED NOT NULL DEFAULT 0,
    location_id   INT UNSIGNED NOT NULL,
    entry_type    VARCHAR(25) NOT NULL,
    patient_id    INT UNSIGNED NULL,
    visit_id      INT UNSIGNED NULL,
    qty           DECIMAL(12,3) NOT NULL,
    balance_after DECIMAL(14,3) NOT NULL,
    issued_by     INT UNSIGNED NULL,
    witness_by    INT UNSIGNED NULL,
    occurred_at   DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cdr_item (item_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
