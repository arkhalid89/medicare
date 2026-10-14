-- MediCare Pharmacy schema v1. Money: *_paisa INTEGER (Rs 1 = 100). Unit cost: *_e4 INTEGER (Rs 1 = 10000).
-- Quantities: INTEGER base units. Timestamps: ISO-8601 UTC text set by the host. Business dates: yyyy-MM-dd (PKT).

CREATE TABLE app_settings(key TEXT PRIMARY KEY, value TEXT NOT NULL, updated_by INTEGER, updated_at TEXT);

CREATE TABLE roles(
  id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE COLLATE NOCASE,
  max_discount_bp INTEGER NOT NULL DEFAULT 0, is_system INTEGER NOT NULL DEFAULT 0);
CREATE TABLE role_permissions(role_id INTEGER NOT NULL REFERENCES roles(id), permission TEXT NOT NULL, PRIMARY KEY(role_id, permission));

CREATE TABLE users(
  id INTEGER PRIMARY KEY, username TEXT NOT NULL UNIQUE COLLATE NOCASE, full_name TEXT NOT NULL,
  role_id INTEGER NOT NULL REFERENCES roles(id), pwd_hash TEXT NOT NULL, pin_hash TEXT,
  must_change_pwd INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1,
  failed_count INTEGER NOT NULL DEFAULT 0, locked_until TEXT, pin_failed INTEGER NOT NULL DEFAULT 0, pin_locked_until TEXT,
  session_timeout_min INTEGER NOT NULL DEFAULT 10, last_login_at TEXT, last_workstation TEXT,
  pwd_history TEXT NOT NULL DEFAULT '[]', created_at TEXT NOT NULL);
CREATE TABLE user_permission_overrides(user_id INTEGER NOT NULL REFERENCES users(id), permission TEXT NOT NULL, granted INTEGER NOT NULL, PRIMARY KEY(user_id, permission));

CREATE TABLE workstations(
  id INTEGER PRIMARY KEY, machine_id TEXT NOT NULL UNIQUE, name TEXT NOT NULL, os TEXT NOT NULL DEFAULT '',
  client_version TEXT NOT NULL DEFAULT '', counter_no INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL, last_seen_at TEXT, last_user TEXT);
CREATE TABLE sessions(
  id INTEGER PRIMARY KEY, token_hash TEXT NOT NULL UNIQUE, user_id INTEGER NOT NULL REFERENCES users(id),
  workstation_id INTEGER REFERENCES workstations(id), created_at TEXT NOT NULL, last_seen_at TEXT NOT NULL, revoked_at TEXT);

CREATE TABLE lists(id INTEGER PRIMARY KEY, kind TEXT NOT NULL, name TEXT NOT NULL COLLATE NOCASE, is_active INTEGER NOT NULL DEFAULT 1, UNIQUE(kind, name));

CREATE TABLE medicines(
  id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, generic TEXT NOT NULL DEFAULT '',
  brand TEXT NOT NULL DEFAULT '', manufacturer TEXT NOT NULL DEFAULT '', category TEXT NOT NULL DEFAULT '',
  dosage_form TEXT NOT NULL DEFAULT '', strength TEXT NOT NULL DEFAULT '', base_unit TEXT NOT NULL DEFAULT 'Tablet',
  default_sale_pack_id INTEGER, purchase_pack_id INTEGER, min_stock_qty INTEGER NOT NULL DEFAULT 0,
  tax_bp INTEGER NOT NULL DEFAULT 0, rx_required INTEGER NOT NULL DEFAULT 0, schedule TEXT NOT NULL DEFAULT '',
  urdu_name TEXT NOT NULL DEFAULT '', rack TEXT NOT NULL DEFAULT '', default_purchase_e4 INTEGER NOT NULL DEFAULT 0,
  is_active INTEGER NOT NULL DEFAULT 1, import_job_id INTEGER, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE INDEX ix_med_name ON medicines(name COLLATE NOCASE);
CREATE INDEX ix_med_generic ON medicines(generic COLLATE NOCASE);
CREATE TABLE medicine_packs(
  id INTEGER PRIMARY KEY, medicine_id INTEGER NOT NULL REFERENCES medicines(id), name TEXT NOT NULL,
  base_qty INTEGER NOT NULL CHECK(base_qty >= 1), is_sellable INTEGER NOT NULL DEFAULT 1,
  sale_paisa INTEGER NOT NULL DEFAULT 0 CHECK(sale_paisa >= 0), is_active INTEGER NOT NULL DEFAULT 1, UNIQUE(medicine_id, name));
CREATE TABLE barcodes(id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, medicine_id INTEGER NOT NULL REFERENCES medicines(id), pack_id INTEGER REFERENCES medicine_packs(id));
CREATE INDEX ix_barcode_med ON barcodes(medicine_id);
CREATE VIRTUAL TABLE medicine_fts USING fts5(name, generic, brand, manufacturer, tokenize = 'unicode61');
CREATE TABLE medicine_costs(medicine_id INTEGER PRIMARY KEY REFERENCES medicines(id), avg_cost_e4 INTEGER NOT NULL DEFAULT 0, qty_on_hand INTEGER NOT NULL DEFAULT 0, updated_at TEXT);
CREATE TABLE price_history(id INTEGER PRIMARY KEY, medicine_id INTEGER NOT NULL REFERENCES medicines(id), pack_name TEXT NOT NULL, old_paisa INTEGER NOT NULL, new_paisa INTEGER NOT NULL, changed_by INTEGER, changed_at TEXT NOT NULL, reason TEXT NOT NULL DEFAULT '');

CREATE TABLE suppliers(
  id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, contact TEXT NOT NULL DEFAULT '',
  mobile TEXT NOT NULL DEFAULT '', address TEXT NOT NULL DEFAULT '', tax_no TEXT NOT NULL DEFAULT '',
  terms_days INTEGER NOT NULL DEFAULT 30, is_active INTEGER NOT NULL DEFAULT 1, notes TEXT NOT NULL DEFAULT '',
  opening_paisa INTEGER NOT NULL DEFAULT 0, opening_posted INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL);
CREATE TABLE customers(
  id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, mobile TEXT NOT NULL DEFAULT '',
  mobile_digits TEXT NOT NULL DEFAULT '', address TEXT NOT NULL DEFAULT '',
  type TEXT NOT NULL DEFAULT 'REGULAR' CHECK(type IN ('WALKIN','REGULAR','CREDIT')),
  credit_limit_paisa INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1,
  opening_paisa INTEGER NOT NULL DEFAULT 0, opening_posted INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL);
CREATE INDEX ix_cust_mobile ON customers(mobile_digits);

CREATE TABLE batches(
  id INTEGER PRIMARY KEY, medicine_id INTEGER NOT NULL REFERENCES medicines(id), batch_no TEXT NOT NULL COLLATE NOCASE,
  expiry_date TEXT NOT NULL, mfg_date TEXT, supplier_id INTEGER REFERENCES suppliers(id), first_purchase_item_id INTEGER,
  purchase_e4 INTEGER NOT NULL DEFAULT 0, sale_paisa INTEGER, mrp_paisa INTEGER, rack TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL,
  UNIQUE(medicine_id, batch_no), CHECK(mfg_date IS NULL OR expiry_date >= mfg_date));
CREATE INDEX ix_batch_expiry ON batches(expiry_date);
CREATE TABLE stock_balances(
  batch_id INTEGER NOT NULL REFERENCES batches(id), state TEXT NOT NULL CHECK(state IN ('SALEABLE','QUARANTINE')),
  qty INTEGER NOT NULL DEFAULT 0 CHECK(qty >= 0), PRIMARY KEY(batch_id, state));
CREATE TABLE stock_movements(
  id INTEGER PRIMARY KEY, batch_id INTEGER NOT NULL REFERENCES batches(id), state TEXT NOT NULL, qty_delta INTEGER NOT NULL,
  source_type TEXT NOT NULL, source_id INTEGER NOT NULL, source_line_id INTEGER, doc_no TEXT NOT NULL DEFAULT '',
  cost_e4 INTEGER NOT NULL DEFAULT 0, at TEXT NOT NULL, business_date TEXT NOT NULL, user_id INTEGER);
CREATE INDEX ix_mov_batch ON stock_movements(batch_id, id);
CREATE INDEX ix_mov_source ON stock_movements(source_type, source_id);
CREATE INDEX ix_mov_date ON stock_movements(business_date);

CREATE TABLE accounts(id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
  type TEXT NOT NULL CHECK(type IN ('ASSET','LIABILITY','EQUITY','INCOME','EXPENSE')), is_system INTEGER NOT NULL DEFAULT 1);
CREATE TABLE cash_accounts(id INTEGER PRIMARY KEY, account_id INTEGER NOT NULL UNIQUE REFERENCES accounts(id), name TEXT NOT NULL,
  kind TEXT NOT NULL CHECK(kind IN ('CASH','BANK','CARD','WALLET')), enforce_balance INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1);
CREATE TABLE journal_entries(
  id INTEGER PRIMARY KEY, no TEXT NOT NULL UNIQUE, entry_date TEXT NOT NULL, at TEXT NOT NULL,
  source_type TEXT NOT NULL, source_id INTEGER NOT NULL, doc_no TEXT NOT NULL DEFAULT '', memo TEXT NOT NULL DEFAULT '',
  user_id INTEGER, reverses_id INTEGER);
CREATE INDEX ix_je_date ON journal_entries(entry_date);
CREATE INDEX ix_je_source ON journal_entries(source_type, source_id);
CREATE TABLE journal_lines(
  id INTEGER PRIMARY KEY, entry_id INTEGER NOT NULL REFERENCES journal_entries(id), account_id INTEGER NOT NULL REFERENCES accounts(id),
  debit_paisa INTEGER NOT NULL DEFAULT 0 CHECK(debit_paisa >= 0), credit_paisa INTEGER NOT NULL DEFAULT 0 CHECK(credit_paisa >= 0),
  party_type TEXT, party_id INTEGER, shift_id INTEGER, memo TEXT NOT NULL DEFAULT '');
CREATE INDEX ix_jl_account ON journal_lines(account_id, entry_id);
CREATE INDEX ix_jl_party ON journal_lines(party_type, party_id, entry_id);
CREATE INDEX ix_jl_shift ON journal_lines(shift_id);
CREATE INDEX ix_jl_entry ON journal_lines(entry_id);

CREATE TABLE shifts(
  id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id), workstation_id INTEGER, counter_no INTEGER NOT NULL DEFAULT 0,
  opened_at TEXT NOT NULL, business_date TEXT NOT NULL, float_paisa INTEGER NOT NULL DEFAULT 0, closed_at TEXT,
  expected_paisa INTEGER, counted_paisa INTEGER, diff_paisa INTEGER, reason TEXT NOT NULL DEFAULT '', approval_id INTEGER,
  journal_entry_id INTEGER, status TEXT NOT NULL DEFAULT 'OPEN' CHECK(status IN ('OPEN','CLOSED')));
CREATE TABLE shift_counts(shift_id INTEGER NOT NULL REFERENCES shifts(id), denomination INTEGER NOT NULL, count INTEGER NOT NULL, PRIMARY KEY(shift_id, denomination));
CREATE TABLE day_closings(business_date TEXT PRIMARY KEY, closed_at TEXT NOT NULL, closed_by INTEGER NOT NULL);

CREATE TABLE sales_headers(
  id INTEGER PRIMARY KEY, no TEXT NOT NULL UNIQUE, idempotency_key TEXT NOT NULL UNIQUE, counter_no INTEGER NOT NULL DEFAULT 0,
  workstation_id INTEGER, cashier_id INTEGER NOT NULL REFERENCES users(id), customer_id INTEGER NOT NULL REFERENCES customers(id),
  sold_at TEXT NOT NULL, business_date TEXT NOT NULL, gross_paisa INTEGER NOT NULL, discount_paisa INTEGER NOT NULL,
  taxable_paisa INTEGER NOT NULL, tax_paisa INTEGER NOT NULL, net_paisa INTEGER NOT NULL, credit_paisa INTEGER NOT NULL DEFAULT 0,
  settled_paisa INTEGER NOT NULL DEFAULT 0, tendered_paisa INTEGER NOT NULL DEFAULT 0, change_paisa INTEGER NOT NULL DEFAULT 0,
  cogs_paisa INTEGER NOT NULL DEFAULT 0, discount_approval_id INTEGER,
  status TEXT NOT NULL DEFAULT 'POSTED' CHECK(status IN ('POSTED','REVERSED')), journal_entry_id INTEGER, reversal_id INTEGER, shift_id INTEGER);
CREATE INDEX ix_sales_date ON sales_headers(business_date);
CREATE INDEX ix_sales_customer ON sales_headers(customer_id);
CREATE INDEX ix_sales_cashier ON sales_headers(cashier_id, business_date);
CREATE TABLE sales_items(
  id INTEGER PRIMARY KEY, header_id INTEGER NOT NULL REFERENCES sales_headers(id), line_no INTEGER NOT NULL,
  medicine_id INTEGER NOT NULL REFERENCES medicines(id), medicine_name TEXT NOT NULL, batch_id INTEGER NOT NULL REFERENCES batches(id),
  pack_name TEXT NOT NULL, pack_base_qty INTEGER NOT NULL, base_qty INTEGER NOT NULL CHECK(base_qty > 0),
  returned_base_qty INTEGER NOT NULL DEFAULT 0, rate_paisa INTEGER NOT NULL, gross_paisa INTEGER NOT NULL,
  discount_paisa INTEGER NOT NULL, taxable_paisa INTEGER NOT NULL, tax_paisa INTEGER NOT NULL, tax_bp INTEGER NOT NULL DEFAULT 0,
  net_paisa INTEGER NOT NULL, cost_e4 INTEGER NOT NULL, cogs_paisa INTEGER NOT NULL, pick TEXT NOT NULL DEFAULT 'FEFO');
CREATE INDEX ix_si_header ON sales_items(header_id);
CREATE INDEX ix_si_medicine ON sales_items(medicine_id);
CREATE TABLE sales_payments(id INTEGER PRIMARY KEY, header_id INTEGER NOT NULL REFERENCES sales_headers(id), method TEXT NOT NULL,
  cash_account_id INTEGER, amount_paisa INTEGER NOT NULL, reference TEXT NOT NULL DEFAULT '');
CREATE INDEX ix_sp_header ON sales_payments(header_id);
CREATE TABLE prescriptions(id INTEGER PRIMARY KEY, header_id INTEGER NOT NULL REFERENCES sales_headers(id), sales_item_id INTEGER NOT NULL REFERENCES sales_items(id),
  prescriber TEXT NOT NULL, prescriber_reg TEXT NOT NULL DEFAULT '', patient TEXT NOT NULL, rx_no TEXT NOT NULL DEFAULT '', register_serial TEXT);
CREATE TABLE holds(id INTEGER PRIMARY KEY, counter_no INTEGER NOT NULL, workstation_id INTEGER, user_id INTEGER NOT NULL, customer_id INTEGER,
  customer_name TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '', lines INTEGER NOT NULL DEFAULT 0, amount_paisa INTEGER NOT NULL DEFAULT 0,
  payload_json TEXT NOT NULL, created_at TEXT NOT NULL, expires_at TEXT NOT NULL);
CREATE TABLE reversals(id INTEGER PRIMARY KEY, no TEXT NOT NULL UNIQUE, idempotency_key TEXT NOT NULL UNIQUE, doc_type TEXT NOT NULL, doc_id INTEGER NOT NULL,
  reason TEXT NOT NULL, approval_id INTEGER, journal_entry_id INTEGER, at TEXT NOT NULL, business_date TEXT NOT NULL, user_id INTEGER NOT NULL);

CREATE TABLE sales_returns(
  id INTEGER PRIMARY KEY, no TEXT NOT NULL UNIQUE, idempotency_key TEXT NOT NULL UNIQUE, sale_id INTEGER NOT NULL REFERENCES sales_headers(id),
  at TEXT NOT NULL, business_date TEXT NOT NULL, user_id INTEGER NOT NULL, refund_method TEXT NOT NULL, cash_account_id INTEGER,
  refund_paisa INTEGER NOT NULL, approval_id INTEGER, journal_entry_id INTEGER, shift_id INTEGER);
CREATE TABLE sales_return_items(
  id INTEGER PRIMARY KEY, return_id INTEGER NOT NULL REFERENCES sales_returns(id), sales_item_id INTEGER NOT NULL REFERENCES sales_items(id),
  batch_id INTEGER NOT NULL, base_qty INTEGER NOT NULL CHECK(base_qty > 0), outcome TEXT NOT NULL CHECK(outcome IN ('RESTOCK','QUARANTINE')),
  reason TEXT NOT NULL DEFAULT '', refund_paisa INTEGER NOT NULL, taxable_paisa INTEGER NOT NULL, tax_paisa INTEGER NOT NULL,
  cost_e4 INTEGER NOT NULL, cost_paisa INTEGER NOT NULL);
CREATE INDEX ix_sri_item ON sales_return_items(sales_item_id);

CREATE TABLE purchases(
  id INTEGER PRIMARY KEY, no TEXT UNIQUE, status TEXT NOT NULL DEFAULT 'DRAFT' CHECK(status IN ('DRAFT','POSTED','REVERSED')),
  supplier_id INTEGER NOT NULL REFERENCES suppliers(id), supplier_inv_no TEXT NOT NULL DEFAULT '', inv_date TEXT NOT NULL, due_date TEXT,
  is_opening INTEGER NOT NULL DEFAULT 0, notes TEXT NOT NULL DEFAULT '', charges_paisa INTEGER NOT NULL DEFAULT 0,
  paid_now_paisa INTEGER NOT NULL DEFAULT 0, paid_account_id INTEGER, paid_reference TEXT NOT NULL DEFAULT '',
  gross_paisa INTEGER NOT NULL DEFAULT 0, discount_paisa INTEGER NOT NULL DEFAULT 0, tax_paisa INTEGER NOT NULL DEFAULT 0,
  net_paisa INTEGER NOT NULL DEFAULT 0, paid_paisa INTEGER NOT NULL DEFAULT 0, returned_paisa INTEGER NOT NULL DEFAULT 0,
  idempotency_key TEXT UNIQUE, business_date TEXT, posted_at TEXT, posted_by INTEGER, journal_entry_id INTEGER, reversal_id INTEGER,
  created_by INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE UNIQUE INDEX ux_purchase_supplier_inv ON purchases(supplier_id, supplier_inv_no) WHERE status <> 'DRAFT' AND supplier_inv_no <> '';
CREATE INDEX ix_purchase_date ON purchases(business_date);
CREATE TABLE purchase_items(
  id INTEGER PRIMARY KEY, purchase_id INTEGER NOT NULL REFERENCES purchases(id), line_no INTEGER NOT NULL,
  medicine_id INTEGER NOT NULL REFERENCES medicines(id), pack_id INTEGER NOT NULL REFERENCES medicine_packs(id), pack_name TEXT NOT NULL,
  pack_base_qty INTEGER NOT NULL, batch_no TEXT NOT NULL DEFAULT '', expiry_date TEXT, mfg_date TEXT, qty INTEGER NOT NULL, free_qty INTEGER NOT NULL DEFAULT 0,
  rate_paisa INTEGER NOT NULL DEFAULT 0, disc_bp INTEGER NOT NULL DEFAULT 0, tax_bp INTEGER NOT NULL DEFAULT 0,
  gross_paisa INTEGER NOT NULL DEFAULT 0, discount_paisa INTEGER NOT NULL DEFAULT 0, tax_paisa INTEGER NOT NULL DEFAULT 0,
  charge_paisa INTEGER NOT NULL DEFAULT 0, net_paisa INTEGER NOT NULL DEFAULT 0, unit_cost_e4 INTEGER NOT NULL DEFAULT 0,
  mrp_paisa INTEGER NOT NULL DEFAULT 0, sale_paisa INTEGER NOT NULL DEFAULT 0, rack TEXT NOT NULL DEFAULT '',
  capture_method TEXT NOT NULL DEFAULT 'SEARCH', batch_id INTEGER REFERENCES batches(id), returned_base_qty INTEGER NOT NULL DEFAULT 0);
CREATE INDEX ix_pi_purchase ON purchase_items(purchase_id);
CREATE TABLE purchase_returns(
  id INTEGER PRIMARY KEY, no TEXT NOT NULL UNIQUE, idempotency_key TEXT NOT NULL UNIQUE, purchase_id INTEGER NOT NULL REFERENCES purchases(id),
  supplier_id INTEGER NOT NULL, credit_note_ref TEXT NOT NULL DEFAULT '', at TEXT NOT NULL, business_date TEXT NOT NULL,
  amount_paisa INTEGER NOT NULL, user_id INTEGER NOT NULL, journal_entry_id INTEGER);
CREATE TABLE purchase_return_items(
  id INTEGER PRIMARY KEY, return_id INTEGER NOT NULL REFERENCES purchase_returns(id), purchase_item_id INTEGER NOT NULL REFERENCES purchase_items(id),
  batch_id INTEGER NOT NULL, from_state TEXT NOT NULL, base_qty INTEGER NOT NULL CHECK(base_qty > 0), unit_cost_e4 INTEGER NOT NULL,
  amount_paisa INTEGER NOT NULL, reason TEXT NOT NULL DEFAULT '');

CREATE TABLE customer_receipts(
  id INTEGER PRIMARY KEY, no TEXT NOT NULL UNIQUE, idempotency_key TEXT NOT NULL UNIQUE, customer_id INTEGER NOT NULL REFERENCES customers(id),
  at TEXT NOT NULL, business_date TEXT NOT NULL, amount_paisa INTEGER NOT NULL CHECK(amount_paisa > 0), method TEXT NOT NULL,
  cash_account_id INTEGER NOT NULL, reference TEXT NOT NULL DEFAULT '', notes TEXT NOT NULL DEFAULT '', user_id INTEGER NOT NULL,
  journal_entry_id INTEGER, shift_id INTEGER);
CREATE TABLE receipt_allocations(id INTEGER PRIMARY KEY, receipt_id INTEGER, return_id INTEGER, sale_id INTEGER NOT NULL REFERENCES sales_headers(id), amount_paisa INTEGER NOT NULL CHECK(amount_paisa > 0));
CREATE TABLE supplier_payments(
  id INTEGER PRIMARY KEY, no TEXT NOT NULL UNIQUE, idempotency_key TEXT NOT NULL UNIQUE, supplier_id INTEGER NOT NULL REFERENCES suppliers(id),
  kind TEXT NOT NULL DEFAULT 'PAYMENT' CHECK(kind IN ('PAYMENT','REFUND','PURCHASE')), at TEXT NOT NULL, business_date TEXT NOT NULL,
  amount_paisa INTEGER NOT NULL CHECK(amount_paisa > 0), cash_account_id INTEGER NOT NULL, reference TEXT NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '', user_id INTEGER NOT NULL, journal_entry_id INTEGER);
CREATE TABLE payment_allocations(id INTEGER PRIMARY KEY, payment_id INTEGER NOT NULL REFERENCES supplier_payments(id), purchase_id INTEGER NOT NULL REFERENCES purchases(id), amount_paisa INTEGER NOT NULL CHECK(amount_paisa > 0));
CREATE TABLE expenses(
  id INTEGER PRIMARY KEY, no TEXT NOT NULL UNIQUE, idempotency_key TEXT NOT NULL UNIQUE, at TEXT NOT NULL, business_date TEXT NOT NULL,
  category TEXT NOT NULL, description TEXT NOT NULL DEFAULT '', amount_paisa INTEGER NOT NULL CHECK(amount_paisa > 0),
  cash_account_id INTEGER NOT NULL, reference TEXT NOT NULL DEFAULT '', user_id INTEGER NOT NULL, approval_id INTEGER, journal_entry_id INTEGER, shift_id INTEGER);
CREATE TABLE cash_entries(
  id INTEGER PRIMARY KEY, no TEXT NOT NULL UNIQUE, idempotency_key TEXT NOT NULL UNIQUE, kind TEXT NOT NULL CHECK(kind IN ('ENTRY','TRANSFER')),
  at TEXT NOT NULL, business_date TEXT NOT NULL, cash_account_id INTEGER NOT NULL, to_account_id INTEGER, category TEXT NOT NULL DEFAULT '',
  amount_paisa INTEGER NOT NULL CHECK(amount_paisa > 0), memo TEXT NOT NULL DEFAULT '', user_id INTEGER NOT NULL, journal_entry_id INTEGER, shift_id INTEGER);

CREATE TABLE stock_adjustments(
  id INTEGER PRIMARY KEY, no TEXT NOT NULL UNIQUE, idempotency_key TEXT NOT NULL UNIQUE, kind TEXT NOT NULL CHECK(kind IN ('ADJUST','COUNT','QUARANTINE','WRITE_OFF')),
  at TEXT NOT NULL, business_date TEXT NOT NULL, reason TEXT NOT NULL, notes TEXT NOT NULL DEFAULT '', value_paisa INTEGER NOT NULL DEFAULT 0,
  user_id INTEGER NOT NULL, approval_id INTEGER, journal_entry_id INTEGER, count_id INTEGER);
CREATE TABLE stock_adjustment_items(
  id INTEGER PRIMARY KEY, adjustment_id INTEGER NOT NULL REFERENCES stock_adjustments(id), batch_id INTEGER NOT NULL REFERENCES batches(id),
  state TEXT NOT NULL, system_qty INTEGER NOT NULL, physical_qty INTEGER NOT NULL, diff_qty INTEGER NOT NULL, cost_e4 INTEGER NOT NULL, value_paisa INTEGER NOT NULL);
CREATE TABLE stock_counts(id INTEGER PRIMARY KEY, no TEXT NOT NULL UNIQUE, scope TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'OPEN' CHECK(status IN ('OPEN','POSTED','CANCELLED')),
  frozen_at TEXT NOT NULL, frozen_movement_id INTEGER NOT NULL, created_by INTEGER NOT NULL, adjustment_id INTEGER);
CREATE TABLE stock_count_items(id INTEGER PRIMARY KEY, count_id INTEGER NOT NULL REFERENCES stock_counts(id), batch_id INTEGER NOT NULL REFERENCES batches(id),
  frozen_qty INTEGER NOT NULL, counted_qty INTEGER, UNIQUE(count_id, batch_id));

CREATE TABLE approvals(
  id INTEGER PRIMARY KEY, action TEXT NOT NULL, context_key TEXT NOT NULL, value_text TEXT NOT NULL DEFAULT '0', summary TEXT NOT NULL DEFAULT '',
  request_reason TEXT NOT NULL DEFAULT '', requester_id INTEGER NOT NULL, workstation_id INTEGER, approver_id INTEGER,
  status TEXT NOT NULL CHECK(status IN ('PENDING','APPROVED','REJECTED','USED','EXPIRED')), token_hash TEXT, token_plain TEXT,
  decision_reason TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, decided_at TEXT, expires_at TEXT, used_at TEXT);
CREATE INDEX ix_appr_token ON approvals(token_hash);
CREATE INDEX ix_appr_status ON approvals(status);

CREATE TABLE audit_logs(
  id INTEGER PRIMARY KEY, at TEXT NOT NULL, user_id INTEGER, username TEXT NOT NULL DEFAULT '', workstation TEXT NOT NULL DEFAULT '',
  action TEXT NOT NULL, module TEXT NOT NULL, record_ref TEXT NOT NULL DEFAULT '', before_json TEXT NOT NULL DEFAULT '', after_json TEXT NOT NULL DEFAULT '',
  reason TEXT NOT NULL DEFAULT '', approver TEXT NOT NULL DEFAULT '', prev_hash TEXT NOT NULL, hash TEXT NOT NULL);
CREATE INDEX ix_audit_at ON audit_logs(at);

CREATE TABLE idempotency_keys(key TEXT PRIMARY KEY, endpoint TEXT NOT NULL, request_hash TEXT NOT NULL, response_json TEXT NOT NULL, created_at TEXT NOT NULL);
CREATE TABLE doc_sequences(series TEXT NOT NULL, period_key TEXT NOT NULL, next_seq INTEGER NOT NULL, PRIMARY KEY(series, period_key));
CREATE TABLE register_sequences(schedule TEXT PRIMARY KEY, next_seq INTEGER NOT NULL);

CREATE TABLE backup_history(
  id INTEGER PRIMARY KEY, kind TEXT NOT NULL, file_name TEXT NOT NULL, path TEXT NOT NULL, size_bytes INTEGER NOT NULL DEFAULT 0,
  sha256 TEXT NOT NULL DEFAULT '', schema_version INTEGER NOT NULL DEFAULT 0, started_at TEXT NOT NULL, finished_at TEXT,
  result TEXT NOT NULL, check_result TEXT NOT NULL DEFAULT '', message TEXT NOT NULL DEFAULT '', copied_to TEXT, user_id INTEGER);
CREATE TABLE selfcheck_runs(id INTEGER PRIMARY KEY, at TEXT NOT NULL, ok INTEGER NOT NULL, details_json TEXT NOT NULL);
CREATE TABLE import_jobs(
  id INTEGER PRIMARY KEY, file_name TEXT NOT NULL, status TEXT NOT NULL, rows_read INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL DEFAULT 0,
  skipped INTEGER NOT NULL DEFAULT 0, columns_json TEXT NOT NULL DEFAULT '[]', mapping_json TEXT NOT NULL DEFAULT '{}', data_json TEXT NOT NULL DEFAULT '[]',
  issues_json TEXT NOT NULL DEFAULT '[]', created_by INTEGER NOT NULL, created_at TEXT NOT NULL, committed_at TEXT, undone_at TEXT);

-- ---------- immutability (NFR-013, ADR-008) ----------
CREATE TRIGGER trg_sales_no_delete BEFORE DELETE ON sales_headers BEGIN SELECT RAISE(ABORT, 'posted sales are immutable'); END;
CREATE TRIGGER trg_sales_no_edit BEFORE UPDATE OF no, idempotency_key, customer_id, cashier_id, sold_at, business_date, gross_paisa, discount_paisa, taxable_paisa, tax_paisa, net_paisa, credit_paisa, cogs_paisa ON sales_headers
BEGIN SELECT RAISE(ABORT, 'posted sales are immutable'); END;
CREATE TRIGGER trg_si_no_delete BEFORE DELETE ON sales_items BEGIN SELECT RAISE(ABORT, 'posted sales are immutable'); END;
CREATE TRIGGER trg_si_no_edit BEFORE UPDATE OF header_id, medicine_id, batch_id, base_qty, rate_paisa, gross_paisa, discount_paisa, taxable_paisa, tax_paisa, net_paisa, cost_e4, cogs_paisa ON sales_items
BEGIN SELECT RAISE(ABORT, 'posted sales are immutable'); END;
CREATE TRIGGER trg_sp_no_change BEFORE UPDATE ON sales_payments BEGIN SELECT RAISE(ABORT, 'posted payments are immutable'); END;
CREATE TRIGGER trg_sp_no_delete BEFORE DELETE ON sales_payments BEGIN SELECT RAISE(ABORT, 'posted payments are immutable'); END;
CREATE TRIGGER trg_mov_no_update BEFORE UPDATE ON stock_movements BEGIN SELECT RAISE(ABORT, 'stock movements are immutable'); END;
CREATE TRIGGER trg_mov_no_delete BEFORE DELETE ON stock_movements BEGIN SELECT RAISE(ABORT, 'stock movements are immutable'); END;
CREATE TRIGGER trg_je_no_update BEFORE UPDATE ON journal_entries BEGIN SELECT RAISE(ABORT, 'journal entries are immutable'); END;
CREATE TRIGGER trg_je_no_delete BEFORE DELETE ON journal_entries BEGIN SELECT RAISE(ABORT, 'journal entries are immutable'); END;
CREATE TRIGGER trg_jl_no_update BEFORE UPDATE ON journal_lines BEGIN SELECT RAISE(ABORT, 'journal lines are immutable'); END;
CREATE TRIGGER trg_jl_no_delete BEFORE DELETE ON journal_lines BEGIN SELECT RAISE(ABORT, 'journal lines are immutable'); END;
CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_logs BEGIN SELECT RAISE(ABORT, 'audit log is append-only'); END;
CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_logs BEGIN SELECT RAISE(ABORT, 'audit log is append-only'); END;
CREATE TRIGGER trg_pur_no_delete BEFORE DELETE ON purchases WHEN OLD.status <> 'DRAFT' BEGIN SELECT RAISE(ABORT, 'posted purchases are immutable'); END;
CREATE TRIGGER trg_pur_no_edit BEFORE UPDATE OF supplier_id, supplier_inv_no, inv_date, gross_paisa, discount_paisa, tax_paisa, net_paisa, charges_paisa, no ON purchases WHEN OLD.status <> 'DRAFT'
BEGIN SELECT RAISE(ABORT, 'posted purchases are immutable'); END;
CREATE TRIGGER trg_pi_no_delete BEFORE DELETE ON purchase_items WHEN (SELECT status FROM purchases WHERE id = OLD.purchase_id) <> 'DRAFT' BEGIN SELECT RAISE(ABORT, 'posted purchases are immutable'); END;
CREATE TRIGGER trg_pi_no_edit BEFORE UPDATE OF medicine_id, qty, free_qty, rate_paisa, net_paisa, unit_cost_e4, batch_id ON purchase_items WHEN (SELECT status FROM purchases WHERE id = OLD.purchase_id) <> 'DRAFT'
BEGIN SELECT RAISE(ABORT, 'posted purchases are immutable'); END;
CREATE TRIGGER trg_doc_r_del BEFORE DELETE ON customer_receipts BEGIN SELECT RAISE(ABORT, 'posted receipts are immutable'); END;
CREATE TRIGGER trg_doc_p_del BEFORE DELETE ON supplier_payments BEGIN SELECT RAISE(ABORT, 'posted payments are immutable'); END;
CREATE TRIGGER trg_doc_e_del BEFORE DELETE ON expenses BEGIN SELECT RAISE(ABORT, 'posted expenses are immutable'); END;
CREATE TRIGGER trg_doc_c_del BEFORE DELETE ON cash_entries BEGIN SELECT RAISE(ABORT, 'posted cash entries are immutable'); END;
CREATE TRIGGER trg_doc_sr_del BEFORE DELETE ON sales_returns BEGIN SELECT RAISE(ABORT, 'posted returns are immutable'); END;
CREATE TRIGGER trg_doc_pr_del BEFORE DELETE ON purchase_returns BEGIN SELECT RAISE(ABORT, 'posted returns are immutable'); END;
CREATE TRIGGER trg_doc_sa_del BEFORE DELETE ON stock_adjustments BEGIN SELECT RAISE(ABORT, 'posted adjustments are immutable'); END;

-- ---------- seed: chart of accounts ----------
INSERT INTO accounts(code, name, type) VALUES
 ('1000','Cash in hand','ASSET'), ('1011','Main bank account','ASSET'), ('1020','Card settlement','ASSET'), ('1030','Mobile wallets','ASSET'),
 ('1100','Customer receivables','ASSET'), ('1200','Inventory (saleable)','ASSET'), ('1210','Inventory (quarantine)','ASSET'),
 ('2000','Supplier payables','LIABILITY'), ('2200','Sales tax payable','LIABILITY'),
 ('3000','Owner''s equity','EQUITY'), ('3100','Opening balance equity','EQUITY'),
 ('4000','Sales','INCOME'), ('4010','Sales returns','INCOME'), ('4020','Sales discounts','INCOME'), ('4100','Other income','INCOME'),
 ('5000','Cost of goods sold','EXPENSE'), ('5010','Inventory write-offs and shrinkage','EXPENSE'), ('5020','Rounding differences','EXPENSE'),
 ('6000','Operating expenses','EXPENSE'), ('6900','Cash over / short','EXPENSE');
INSERT INTO cash_accounts(account_id, name, kind) SELECT id, 'Cash in hand', 'CASH' FROM accounts WHERE code = '1000';
INSERT INTO cash_accounts(account_id, name, kind) SELECT id, 'Main bank account', 'BANK' FROM accounts WHERE code = '1011';
INSERT INTO cash_accounts(account_id, name, kind) SELECT id, 'Card settlement', 'CARD' FROM accounts WHERE code = '1020';
INSERT INTO cash_accounts(account_id, name, kind) SELECT id, 'Mobile wallets (Easypaisa / JazzCash)', 'WALLET' FROM accounts WHERE code = '1030';

-- ---------- seed: roles (permissions inserted by the host from the catalog) ----------
INSERT INTO roles(name, max_discount_bp, is_system) VALUES ('Administrator', 10000, 1), ('Pharmacist / Manager', 1000, 1), ('Cashier', 500, 1), ('Accountant', 0, 1), ('Stock operator', 0, 1);

-- ---------- seed: walk-in customer and lists ----------
INSERT INTO customers(code, name, type, created_at) VALUES ('C-0001', 'Walk-in', 'WALKIN', strftime('%Y-%m-%dT%H:%M:%fZ','now'));
INSERT INTO lists(kind, name) VALUES
 ('dosage_form','Tablet'),('dosage_form','Capsule'),('dosage_form','Syrup'),('dosage_form','Suspension'),('dosage_form','Injection'),
 ('dosage_form','Cream'),('dosage_form','Ointment'),('dosage_form','Drops'),('dosage_form','Inhaler'),('dosage_form','Sachet'),('dosage_form','Infusion'),
 ('unit','Tablet'),('unit','Capsule'),('unit','Bottle'),('unit','Strip'),('unit','Box'),('unit','Tube'),('unit','Vial'),('unit','Ampoule'),('unit','Sachet'),('unit','Piece'),
 ('category','Analgesics'),('category','Antibiotics'),('category','Gastro'),('category','Cardiac'),('category','Diabetes'),('category','Respiratory'),
 ('category','Vitamins and supplements'),('category','Dermatology'),('category','Psychiatric'),('category','Surgical'),('category','General'),
 ('expense_category','Rent'),('expense_category','Salaries'),('expense_category','Utilities'),('expense_category','Fuel'),('expense_category','Repairs'),
 ('expense_category','Staff welfare'),('expense_category','Stationery'),('expense_category','Bank charges'),('expense_category','Other'),
 ('adjustment_reason','Damage'),('adjustment_reason','Expiry'),('adjustment_reason','Breakage'),('adjustment_reason','Shortage'),('adjustment_reason','Correction'),('adjustment_reason','Stock count');
