-- ============================================================================
--  v2 · Phase 2 · Unified inventory, store & stock keeping (Doc 7 §6)
--  One item master, one batch table, one append-only movement ledger, and
--  stock locations (central store, sub-stores, pharmacies, wards, OT, lab,
--  radiology, dental, quarantine, expired hold).
--  Balances are a cache of SUM(ledger) — StockService::move() is the only
--  writer and a daily job reconciles them.
-- ============================================================================

CREATE TABLE IF NOT EXISTS item_categories (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id     INT UNSIGNED NULL,
    name          VARCHAR(150) NOT NULL,
    type          VARCHAR(30) NOT NULL DEFAULT 'medical_consumable',
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    updated_at    DATETIME NULL,
    updated_by    INT UNSIGNED NULL,
    deleted_at    DATETIME NULL,
    deleted_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_ic_list (deleted_at, is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uoms (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(60) NOT NULL,
    code          VARCHAR(15) NULL,
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

CREATE TABLE IF NOT EXISTS items (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sku               VARCHAR(40) NULL,
    name              VARCHAR(200) NOT NULL,
    category_id       INT UNSIGNED NULL,
    item_type         VARCHAR(30) NOT NULL DEFAULT 'medical_consumable',
    base_uom_id       INT UNSIGNED NULL,
    purchase_uom_id   INT UNSIGNED NULL,
    purchase_factor   DECIMAL(12,3) NOT NULL DEFAULT 1,
    medicine_id       INT UNSIGNED NULL,
    barcode           VARCHAR(60) NULL,
    is_batch_tracked  TINYINT(1) NOT NULL DEFAULT 1,
    is_expiry_tracked TINYINT(1) NOT NULL DEFAULT 1,
    is_serial_tracked TINYINT(1) NOT NULL DEFAULT 0,
    is_controlled     TINYINT(1) NOT NULL DEFAULT 0,
    is_cold_chain     TINYINT(1) NOT NULL DEFAULT 0,
    shelf_life_days   INT UNSIGNED NULL,
    standard_cost     DECIMAL(12,4) NOT NULL DEFAULT 0,
    avg_cost          DECIMAL(12,4) NOT NULL DEFAULT 0,
    sale_price        DECIMAL(12,2) NOT NULL DEFAULT 0,
    is_active         TINYINT(1) NOT NULL DEFAULT 1,
    display_order     INT NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by        INT UNSIGNED NULL,
    updated_at        DATETIME NULL,
    updated_by        INT UNSIGNED NULL,
    deleted_at        DATETIME NULL,
    deleted_by        INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_items_sku (sku),
    UNIQUE KEY uq_items_medicine (medicine_id),
    KEY idx_items_list (deleted_at, is_active, display_order),
    KEY idx_items_name (name),
    KEY idx_items_barcode (barcode),
    KEY idx_items_type (item_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_uom_conversions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_id     INT UNSIGNED NOT NULL,
    from_uom_id INT UNSIGNED NOT NULL,
    to_uom_id   INT UNSIGNED NOT NULL,
    factor      DECIMAL(12,4) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_iuc_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_locations (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id          INT UNSIGNED NULL,
    parent_location_id INT UNSIGNED NULL,
    code               VARCHAR(20) NULL,
    name               VARCHAR(150) NOT NULL,
    location_type      VARCHAR(30) NOT NULL DEFAULT 'sub_store',
    department_id      INT UNSIGNED NULL,
    custodian_user_id  INT UNSIGNED NULL,
    service_point_id   INT UNSIGNED NULL,
    allow_negative     TINYINT(1) NOT NULL DEFAULT 0,
    cold_storage       TINYINT(1) NOT NULL DEFAULT 0,
    is_active          TINYINT(1) NOT NULL DEFAULT 1,
    display_order      INT NOT NULL DEFAULT 0,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by         INT UNSIGNED NULL,
    updated_at         DATETIME NULL,
    updated_by         INT UNSIGNED NULL,
    deleted_at         DATETIME NULL,
    deleted_by         INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_sl_list (deleted_at, is_active, display_order),
    KEY idx_sl_type (location_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which store locations a user works in (Doc 8 §6): issue / receive / adjust rights per location.
CREATE TABLE IF NOT EXISTS user_stock_locations (
    user_id     INT UNSIGNED NOT NULL,
    location_id INT UNSIGNED NOT NULL,
    can_issue   TINYINT(1) NOT NULL DEFAULT 1,
    can_receive TINYINT(1) NOT NULL DEFAULT 1,
    can_adjust  TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_location_rules (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_id     INT UNSIGNED NOT NULL,
    location_id INT UNSIGNED NOT NULL,
    min_qty     DECIMAL(12,3) NOT NULL DEFAULT 0,
    reorder_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
    max_qty     DECIMAL(12,3) NOT NULL DEFAULT 0,
    bin_code    VARCHAR(30) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ilr (item_id, location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendors (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name               VARCHAR(150) NOT NULL,
    vendor_type        VARCHAR(20) NOT NULL DEFAULT 'supplier',
    contact_person     VARCHAR(100) NULL,
    phone              VARCHAR(40) NULL,
    email              VARCHAR(150) NULL,
    address            VARCHAR(255) NULL,
    ntn_no             VARCHAR(30) NULL,
    drug_license_no    VARCHAR(60) NULL,
    payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    rating             TINYINT UNSIGNED NULL,
    is_active          TINYINT(1) NOT NULL DEFAULT 1,
    display_order      INT NOT NULL DEFAULT 0,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by         INT UNSIGNED NULL,
    updated_at         DATETIME NULL,
    updated_by         INT UNSIGNED NULL,
    deleted_at         DATETIME NULL,
    deleted_by         INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_vendors_list (deleted_at, is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendor_items (
    vendor_id      INT UNSIGNED NOT NULL,
    item_id        INT UNSIGNED NOT NULL,
    vendor_sku     VARCHAR(60) NULL,
    last_price     DECIMAL(12,4) NULL,
    last_bought_at DATETIME NULL,
    lead_time_days SMALLINT UNSIGNED NULL,
    PRIMARY KEY (vendor_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------ stock core
CREATE TABLE IF NOT EXISTS stock_batches (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id   INT UNSIGNED NULL,
    item_id     INT UNSIGNED NOT NULL,
    batch_no    VARCHAR(60) NOT NULL,
    expiry_date DATE NULL,
    mfg_date    DATE NULL,
    vendor_id   INT UNSIGNED NULL,
    unit_cost   DECIMAL(12,4) NOT NULL DEFAULT 0,
    mrp         DECIMAL(12,2) NULL,
    grn_item_id INT UNSIGNED NULL,
    status      VARCHAR(12) NOT NULL DEFAULT 'active',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_batch (item_id, batch_no, branch_id),
    KEY idx_batch_expiry (status, expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- batch_id 0 = item without batch tracking.
CREATE TABLE IF NOT EXISTS stock_balances (
    location_id  INT UNSIGNED NOT NULL,
    item_id      INT UNSIGNED NOT NULL,
    batch_id     INT UNSIGNED NOT NULL DEFAULT 0,
    qty_on_hand  DECIMAL(14,3) NOT NULL DEFAULT 0,
    qty_reserved DECIMAL(14,3) NOT NULL DEFAULT 0,
    updated_at   DATETIME NULL,
    PRIMARY KEY (location_id, item_id, batch_id),
    KEY idx_sb_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only: every stock change is exactly one row. Never updated or deleted.
CREATE TABLE IF NOT EXISTS stock_ledger (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id      INT UNSIGNED NULL,
    occurred_at    DATETIME NOT NULL,
    item_id        INT UNSIGNED NOT NULL,
    batch_id       INT UNSIGNED NOT NULL DEFAULT 0,
    location_id    INT UNSIGNED NOT NULL,
    movement_type  VARCHAR(25) NOT NULL,
    qty_signed     DECIMAL(14,3) NOT NULL,
    unit_cost      DECIMAL(12,4) NOT NULL DEFAULT 0,
    balance_after  DECIMAL(14,3) NULL,
    reference_type VARCHAR(40) NULL,
    reference_id   INT UNSIGNED NULL,
    note           VARCHAR(255) NULL,
    performed_by   INT UNSIGNED NULL,
    cosigned_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_led_item (item_id, location_id, occurred_at),
    KEY idx_led_ref (reference_type, reference_id),
    KEY idx_led_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------ procurement
CREATE TABLE IF NOT EXISTS purchase_requisitions (
    id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id                INT UNSIGNED NULL,
    pr_number                VARCHAR(40) NOT NULL,
    requested_by_location_id INT UNSIGNED NULL,
    requested_by             INT UNSIGNED NULL,
    status                   VARCHAR(12) NOT NULL DEFAULT 'draft',
    notes                    VARCHAR(500) NULL,
    approved_by              INT UNSIGNED NULL,
    approved_at              DATETIME NULL,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pr_no (pr_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_requisition_items (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    requisition_id INT UNSIGNED NOT NULL,
    item_id        INT UNSIGNED NOT NULL,
    qty            DECIMAL(12,3) NOT NULL,
    reason         VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_pri_req (requisition_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_orders (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id      INT UNSIGNED NULL,
    po_number      VARCHAR(40) NOT NULL,
    vendor_id      INT UNSIGNED NOT NULL,
    requisition_id INT UNSIGNED NULL,
    location_id    INT UNSIGNED NULL,
    status         VARCHAR(20) NOT NULL DEFAULT 'draft',
    expected_date  DATE NULL,
    payment_terms  VARCHAR(100) NULL,
    total_amount   DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes          VARCHAR(500) NULL,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at   DATETIME NULL,
    approved_by    INT UNSIGNED NULL,
    approved_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_po_no (po_number),
    KEY idx_po_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_items (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    purchase_order_id INT UNSIGNED NOT NULL,
    item_id           INT UNSIGNED NOT NULL,
    qty_ordered       DECIMAL(12,3) NOT NULL,
    qty_received      DECIMAL(12,3) NOT NULL DEFAULT 0,
    unit_price        DECIMAL(12,4) NOT NULL DEFAULT 0,
    tax_pct           DECIMAL(5,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_poi_po (purchase_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goods_receipt_notes (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id             INT UNSIGNED NULL,
    grn_number            VARCHAR(40) NOT NULL,
    purchase_order_id     INT UNSIGNED NULL,
    vendor_id             INT UNSIGNED NOT NULL,
    receiving_location_id INT UNSIGNED NOT NULL,
    vendor_invoice_no     VARCHAR(60) NULL,
    vendor_invoice_date   DATE NULL,
    status                VARCHAR(12) NOT NULL DEFAULT 'draft',
    total_amount          DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes                 VARCHAR(500) NULL,
    received_by           INT UNSIGNED NULL,
    inspected_by          INT UNSIGNED NULL,
    received_at           DATETIME NOT NULL,
    posted_at             DATETIME NULL,
    posted_by             INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_grn_no (grn_number),
    KEY idx_grn_po (purchase_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grn_items (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    grn_id        INT UNSIGNED NOT NULL,
    po_item_id    INT UNSIGNED NULL,
    item_id       INT UNSIGNED NOT NULL,
    batch_no      VARCHAR(60) NULL,
    expiry_date   DATE NULL,
    mfg_date      DATE NULL,
    qty_received  DECIMAL(12,3) NOT NULL DEFAULT 0,
    qty_rejected  DECIMAL(12,3) NOT NULL DEFAULT 0,
    bonus_qty     DECIMAL(12,3) NOT NULL DEFAULT 0,
    reject_reason VARCHAR(255) NULL,
    unit_cost     DECIMAL(12,4) NOT NULL DEFAULT 0,
    mrp           DECIMAL(12,2) NULL,
    batch_id      INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_grni_grn (grn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendor_returns (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id     INT UNSIGNED NULL,
    return_number VARCHAR(40) NOT NULL,
    vendor_id     INT UNSIGNED NOT NULL,
    location_id   INT UNSIGNED NOT NULL,
    reason        VARCHAR(255) NULL,
    status        VARCHAR(12) NOT NULL DEFAULT 'posted',
    total_amount  DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vr_no (return_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendor_return_items (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    return_id INT UNSIGNED NOT NULL,
    item_id   INT UNSIGNED NOT NULL,
    batch_id  INT UNSIGNED NOT NULL DEFAULT 0,
    qty       DECIMAL(12,3) NOT NULL,
    unit_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_vri_ret (return_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------- internal distribution
CREATE TABLE IF NOT EXISTS stock_requisitions (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id        INT UNSIGNED NULL,
    req_number       VARCHAR(40) NOT NULL,
    from_location_id INT UNSIGNED NOT NULL,
    to_location_id   INT UNSIGNED NOT NULL,
    requested_by     INT UNSIGNED NULL,
    priority         VARCHAR(10) NOT NULL DEFAULT 'normal',
    status           VARCHAR(20) NOT NULL DEFAULT 'draft',
    notes            VARCHAR(500) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at     DATETIME NULL,
    approved_by      INT UNSIGNED NULL,
    approved_at      DATETIME NULL,
    rejected_reason  VARCHAR(255) NULL,
    received_confirmed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sr_no (req_number),
    KEY idx_sr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_requisition_items (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    requisition_id INT UNSIGNED NOT NULL,
    item_id        INT UNSIGNED NOT NULL,
    qty_requested  DECIMAL(12,3) NOT NULL,
    qty_approved   DECIMAL(12,3) NULL,
    qty_issued     DECIMAL(12,3) NOT NULL DEFAULT 0,
    note           VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_sri_req (requisition_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_issues (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id        INT UNSIGNED NULL,
    issue_number     VARCHAR(40) NOT NULL,
    requisition_id   INT UNSIGNED NULL,
    from_location_id INT UNSIGNED NOT NULL,
    to_location_id   INT UNSIGNED NOT NULL,
    issued_by        INT UNSIGNED NULL,
    issued_at        DATETIME NOT NULL,
    received_by      INT UNSIGNED NULL,
    received_at      DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_si_no (issue_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_issue_items (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    issue_id  INT UNSIGNED NOT NULL,
    item_id   INT UNSIGNED NOT NULL,
    batch_id  INT UNSIGNED NOT NULL DEFAULT 0,
    qty       DECIMAL(12,3) NOT NULL,
    unit_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_sii_issue (issue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_transfers (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id        INT UNSIGNED NULL,
    transfer_number  VARCHAR(40) NOT NULL,
    from_location_id INT UNSIGNED NOT NULL,
    to_location_id   INT UNSIGNED NOT NULL,
    status           VARCHAR(12) NOT NULL DEFAULT 'in_transit',
    notes            VARCHAR(500) NULL,
    dispatched_by    INT UNSIGNED NULL,
    dispatched_at    DATETIME NULL,
    received_by      INT UNSIGNED NULL,
    received_at      DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_st_no (transfer_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_transfer_items (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    transfer_id     INT UNSIGNED NOT NULL,
    item_id         INT UNSIGNED NOT NULL,
    batch_id        INT UNSIGNED NOT NULL DEFAULT 0,
    qty_sent        DECIMAL(12,3) NOT NULL,
    qty_received    DECIMAL(12,3) NULL,
    unit_cost       DECIMAL(12,4) NOT NULL DEFAULT 0,
    variance_reason VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_sti_tr (transfer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_adjustments (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id   INT UNSIGNED NULL,
    adj_number  VARCHAR(40) NOT NULL,
    location_id INT UNSIGNED NOT NULL,
    reason_code VARCHAR(20) NOT NULL,
    status      VARCHAR(12) NOT NULL DEFAULT 'proposed',
    total_value DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes       VARCHAR(500) NULL,
    proposed_by INT UNSIGNED NULL,
    proposed_at DATETIME NOT NULL,
    approved_by INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    count_id    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_adj_no (adj_number),
    KEY idx_adj_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_adjustment_items (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    adjustment_id INT UNSIGNED NOT NULL,
    item_id       INT UNSIGNED NOT NULL,
    batch_id      INT UNSIGNED NOT NULL DEFAULT 0,
    qty_signed    DECIMAL(12,3) NOT NULL,
    unit_cost     DECIMAL(12,4) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_sai_adj (adjustment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_counts (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id    INT UNSIGNED NULL,
    count_number VARCHAR(40) NOT NULL,
    location_id  INT UNSIGNED NOT NULL,
    count_type   VARCHAR(10) NOT NULL DEFAULT 'full',
    status       VARCHAR(12) NOT NULL DEFAULT 'counting',
    snapshot_at  DATETIME NOT NULL,
    notes        VARCHAR(500) NULL,
    created_by   INT UNSIGNED NULL,
    reviewed_by  INT UNSIGNED NULL,
    posted_by    INT UNSIGNED NULL,
    posted_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cnt_no (count_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_count_lines (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    count_id     INT UNSIGNED NOT NULL,
    item_id      INT UNSIGNED NOT NULL,
    batch_id     INT UNSIGNED NOT NULL DEFAULT 0,
    system_qty   DECIMAL(14,3) NOT NULL DEFAULT 0,
    counted_qty  DECIMAL(14,3) NULL,
    recount_qty  DECIMAL(14,3) NULL,
    variance     DECIMAL(14,3) NULL,
    counted_by   INT UNSIGNED NULL,
    counted_at   DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_scl_count (count_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expiry_watchlist (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id      INT UNSIGNED NULL,
    batch_id       INT UNSIGNED NOT NULL,
    location_id    INT UNSIGNED NOT NULL,
    expiry_date    DATE NOT NULL,
    days_to_expiry INT NOT NULL,
    alert_level    VARCHAR(10) NOT NULL,
    qty            DECIMAL(14,3) NOT NULL DEFAULT 0,
    action_taken   VARCHAR(255) NULL,
    updated_at     DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ew (batch_id, location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recall_notices (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_id       INT UNSIGNED NOT NULL,
    batch_no      VARCHAR(60) NOT NULL,
    source        VARCHAR(20) NOT NULL DEFAULT 'DRAP',
    reference     VARCHAR(100) NULL,
    issued_at     DATE NOT NULL,
    action_status VARCHAR(20) NOT NULL DEFAULT 'quarantined',
    notes         VARCHAR(500) NULL,
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- When a service is performed these items are deducted automatically (Doc 7 §8).
CREATE TABLE IF NOT EXISTS service_consumption_templates (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_id         INT UNSIGNED NOT NULL,
    item_id            INT UNSIGNED NOT NULL,
    qty                DECIMAL(12,3) NOT NULL DEFAULT 1,
    from_location_type VARCHAR(30) NOT NULL DEFAULT 'lab_stock',
    is_mandatory       TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_sct_service (service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assets (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id        INT UNSIGNED NULL,
    item_id          INT UNSIGNED NULL,
    name             VARCHAR(150) NOT NULL,
    asset_tag        VARCHAR(40) NULL,
    serial_no        VARCHAR(80) NULL,
    location_id      INT UNSIGNED NULL,
    vendor_id        INT UNSIGNED NULL,
    purchase_date    DATE NULL,
    cost             DECIMAL(14,2) NULL,
    warranty_until   DATE NULL,
    next_maintenance DATE NULL,
    status           VARCHAR(20) NOT NULL DEFAULT 'in_use',
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    display_order    INT NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by       INT UNSIGNED NULL,
    updated_at       DATETIME NULL,
    updated_by       INT UNSIGNED NULL,
    deleted_at       DATETIME NULL,
    deleted_by       INT UNSIGNED NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_maintenance_log (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id     INT UNSIGNED NOT NULL,
    type         VARCHAR(20) NOT NULL DEFAULT 'preventive',
    performed_on DATE NOT NULL,
    cost         DECIMAL(12,2) NULL,
    next_due     DATE NULL,
    vendor_id    INT UNSIGNED NULL,
    notes        VARCHAR(500) NULL,
    created_by   INT UNSIGNED NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_aml_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
