-- ============================================================================
--  v2 · Phase 1 · Platform: branches, specialties, RBAC, workspaces, service
--  points, break-glass access. (Docs 1, 4 §1, 5 §5, 7 §1, 8)
--  Every statement is idempotent: fresh installs and upgrades run this file.
-- ============================================================================

CREATE TABLE IF NOT EXISTS branches (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code          VARCHAR(20) NULL,
    name          VARCHAR(150) NOT NULL,
    address       VARCHAR(255) NULL,
    city          VARCHAR(80) NULL,
    phone         VARCHAR(40) NULL,
    license_no    VARCHAR(60) NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    updated_at    DATETIME NULL,
    updated_by    INT UNSIGNED NULL,
    deleted_at    DATETIME NULL,
    deleted_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_branches_list (deleted_at, is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- module_key decides the first-class clinical module shown in the
-- consultation: '' (generic) | dental | obgyn | ophthalmology | pediatrics |
-- cardiology | ent | dermatology | orthopedics | psychiatry
CREATE TABLE IF NOT EXISTS specialties (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code          VARCHAR(20) NULL,
    name          VARCHAR(150) NOT NULL,
    module_key    VARCHAR(30) NOT NULL DEFAULT '',
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
    KEY idx_specialties_list (deleted_at, is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A role is a named bundle of permissions. Department roles inherit a base
-- role (base_role_id) and add or restrict permissions (Doc 8 §1).
-- user_class keeps the classic user type the rest of the code relies on.
CREATE TABLE IF NOT EXISTS roles (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code                 VARCHAR(12) NOT NULL,
    name                 VARCHAR(100) NOT NULL,
    description          VARCHAR(500) NULL,
    tier                 CHAR(1) NOT NULL DEFAULT 'D',
    base_role_id         INT UNSIGNED NULL,
    specialty_id         INT UNSIGNED NULL,
    department_type      VARCHAR(20) NULL,
    user_class           VARCHAR(20) NOT NULL DEFAULT 'staff',
    default_workspace_id INT UNSIGNED NULL,
    session_timeout_min  SMALLINT UNSIGNED NULL,
    requires_2fa         TINYINT(1) NOT NULL DEFAULT 0,
    requires_cosign      TINYINT(1) NOT NULL DEFAULT 0,
    discount_limit       DECIMAL(12,2) NULL,
    is_system            TINYINT(1) NOT NULL DEFAULT 0,
    is_active            TINYINT(1) NOT NULL DEFAULT 1,
    display_order        INT NOT NULL DEFAULT 0,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by           INT UNSIGNED NULL,
    updated_at           DATETIME NULL,
    updated_by           INT UNSIGNED NULL,
    deleted_at           DATETIME NULL,
    deleted_by           INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_code (code),
    KEY idx_roles_base (base_role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(100) NOT NULL,
    module      VARCHAR(40) NOT NULL,
    label       VARCHAR(200) NOT NULL,
    is_approval TINYINT(1) NOT NULL DEFAULT 0,
    sort_order  INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_code (code),
    KEY idx_permissions_module (module, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- effect = deny removes a permission the role would inherit from its base role.
CREATE TABLE IF NOT EXISTS role_permission (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    scope         ENUM('own','department','branch','chain') NOT NULL DEFAULT 'branch',
    effect        ENUM('grant','deny') NOT NULL DEFAULT 'grant',
    PRIMARY KEY (role_id, permission_id),
    KEY idx_rp_permission (permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per (user, role, branch, department). branch_id NULL = every branch
-- (chain-wide roles such as SA / ITA / AUD); department_id NULL = the whole branch.
CREATE TABLE IF NOT EXISTS user_role_branch (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    role_id       INT UNSIGNED NOT NULL,
    branch_id     INT UNSIGNED NULL,
    department_id INT UNSIGNED NULL,
    is_primary    TINYINT(1) NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_urb (user_id, role_id, branch_id, department_id),
    KEY idx_urb_role (role_id),
    CONSTRAINT fk_urb_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_urb_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every counter, room, chair, bench and desk (Doc 7 §1).
CREATE TABLE IF NOT EXISTS service_points (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id     INT UNSIGNED NULL,
    department_id INT UNSIGNED NULL,
    code          VARCHAR(20) NULL,
    name          VARCHAR(150) NOT NULL,
    type          VARCHAR(30) NOT NULL DEFAULT 'consultation_room',
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    updated_at    DATETIME NULL,
    updated_by    INT UNSIGNED NULL,
    deleted_at    DATETIME NULL,
    deleted_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_sp_list (deleted_at, is_active, display_order),
    KEY idx_sp_dept (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A workspace is a screen (route) + home widgets, stored as data. Roles get
-- workspaces through role_workspace; the menu is built from them (Doc 8 §4).
CREATE TABLE IF NOT EXISTS workspaces (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code                VARCHAR(60) NOT NULL,
    name                VARCHAR(150) NOT NULL,
    section             VARCHAR(40) NOT NULL DEFAULT 'Workspaces',
    icon                VARCHAR(30) NOT NULL DEFAULT 'layers',
    route               VARCHAR(100) NOT NULL,
    required_permission VARCHAR(100) NULL,
    description         VARCHAR(255) NULL,
    layout_config_json  TEXT NULL,
    specialty_id        INT UNSIGNED NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    display_order       INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workspaces_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_widgets (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    workspace_id        INT UNSIGNED NOT NULL,
    widget_key          VARCHAR(60) NOT NULL,
    config_json         TEXT NULL,
    display_order       INT NOT NULL DEFAULT 0,
    required_permission VARCHAR(100) NULL,
    PRIMARY KEY (id),
    KEY idx_ww_workspace (workspace_id, display_order),
    CONSTRAINT fk_ww_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_workspace (
    role_id      INT UNSIGNED NOT NULL,
    workspace_id INT UNSIGNED NOT NULL,
    is_default   TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (role_id, workspace_id),
    KEY idx_rw_workspace (workspace_id),
    CONSTRAINT fk_rw_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_rw_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Break-glass emergency access (Doc 1 §2.7, Doc 5 §5.6): time-boxed, reason
-- mandatory, always reviewed afterwards by HA / AUD.
CREATE TABLE IF NOT EXISTS emergency_access_grants (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    patient_id  INT UNSIGNED NOT NULL,
    reason      VARCHAR(500) NOT NULL,
    granted_at  DATETIME NOT NULL,
    expires_at  DATETIME NOT NULL,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_note VARCHAR(500) NULL,
    PRIMARY KEY (id),
    KEY idx_eag_user (user_id, patient_id, expires_at),
    KEY idx_eag_review (reviewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
