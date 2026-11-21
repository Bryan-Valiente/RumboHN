-- RUMBO HN B01-L02
-- Initial additive schema for MariaDB 11.8+.
-- Target database is selected by the connection. This migration never creates,
-- drops, truncates, or renames a database or table.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(64) NOT NULL,
    description VARCHAR(255) NOT NULL,
    applied_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    email_verified_at TIMESTAMP(6) NULL,
    last_login_at TIMESTAMP(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    CONSTRAINT chk_users_status CHECK (status IN ('pending', 'active', 'locked', 'disabled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_profiles (
    user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'America/Tegucigalpa',
    locale VARCHAR(16) NOT NULL DEFAULT 'es-HN',
    opening_balance_hnl DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    onboarding_completed BOOLEAN NOT NULL DEFAULT FALSE,
    trial_started_at TIMESTAMP(6) NULL,
    trial_ends_at TIMESTAMP(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_user_profiles_trial CHECK (trial_ends_at IS NULL OR trial_started_at IS NULL OR trial_ends_at >= trial_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_sessions (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    token_hash BINARY(32) NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_seen_at TIMESTAMP(6) NULL,
    expires_at TIMESTAMP(6) NOT NULL,
    revoked_at TIMESTAMP(6) NULL,
    ip_hash BINARY(32) NULL,
    user_agent_hash BINARY(32) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_sessions_token_hash (token_hash),
    KEY idx_auth_sessions_user_active (user_id, revoked_at, expires_at),
    CONSTRAINT fk_auth_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_auth_sessions_expiry CHECK (expires_at > created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    token_hash BINARY(32) NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at TIMESTAMP(6) NOT NULL,
    consumed_at TIMESTAMP(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_reset_token_hash (token_hash),
    KEY idx_password_reset_user_active (user_id, consumed_at, expires_at),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_password_reset_expiry CHECK (expires_at > created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identity_hash BINARY(32) NOT NULL,
    ip_hash BINARY(32) NULL,
    succeeded BOOLEAN NOT NULL DEFAULT FALSE,
    attempted_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_auth_attempts_identity_time (identity_hash, attempted_at),
    KEY idx_auth_attempts_ip_time (ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
    code VARCHAR(32) NOT NULL,
    description VARCHAR(160) NOT NULL,
    is_system BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    role_code VARCHAR(32) NOT NULL,
    assigned_by_user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    assigned_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (user_id, role_code),
    KEY idx_user_roles_role (role_code, user_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_code) REFERENCES roles (code) ON DELETE RESTRICT,
    CONSTRAINT fk_user_roles_assigner FOREIGN KEY (assigned_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS financial_transactions (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    transaction_type VARCHAR(16) NOT NULL,
    name VARCHAR(160) NOT NULL,
    category VARCHAR(100) NOT NULL,
    amount_hnl DECIMAL(15,2) NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'HNL',
    occurred_on DATE NOT NULL,
    occurred_at TIMESTAMP(6) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'confirmed',
    source_type VARCHAR(24) NOT NULL DEFAULT 'manual',
    recurrence VARCHAR(24) NOT NULL DEFAULT 'one-time',
    is_quick_expense BOOLEAN NOT NULL DEFAULT FALSE,
    notes TEXT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    deleted_at TIMESTAMP(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_financial_transactions_owner (id, user_id),
    KEY idx_financial_transactions_user_date (user_id, occurred_on, id),
    KEY idx_financial_transactions_user_status (user_id, status, transaction_type),
    KEY idx_financial_transactions_user_category (user_id, category, occurred_on),
    CONSTRAINT fk_financial_transactions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_financial_transactions_type CHECK (transaction_type IN ('income', 'expense')),
    CONSTRAINT chk_financial_transactions_amount CHECK (amount_hnl > 0.00),
    CONSTRAINT chk_financial_transactions_currency CHECK (currency = 'HNL'),
    CONSTRAINT chk_financial_transactions_status CHECK (status IN ('confirmed', 'planned', 'voided')),
    CONSTRAINT chk_financial_transactions_source CHECK (source_type IN ('manual', 'quick-expense', 'reminder-payment', 'import')),
    CONSTRAINT chk_financial_transactions_recurrence CHECK (recurrence IN ('one-time', 'daily', 'weekly', 'biweekly', 'monthly'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS budgets (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    category VARCHAR(100) NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    limit_hnl DECIMAL(15,2) NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'HNL',
    color VARCHAR(32) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_budgets_user_category_period (user_id, category, period_start, period_end),
    KEY idx_budgets_user_period (user_id, period_start, period_end),
    CONSTRAINT fk_budgets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_budgets_limit CHECK (limit_hnl > 0.00),
    CONSTRAINT chk_budgets_currency CHECK (currency = 'HNL'),
    CONSTRAINT chk_budgets_period CHECK (period_end >= period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS savings_goals (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(160) NOT NULL,
    label VARCHAR(120) NULL,
    target_hnl DECIMAL(15,2) NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'HNL',
    target_date DATE NULL,
    preferred_frequency VARCHAR(24) NULL,
    twice_monthly_day_one TINYINT UNSIGNED NULL,
    twice_monthly_day_two TINYINT UNSIGNED NULL,
    color VARCHAR(32) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    archived_at TIMESTAMP(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_savings_goals_owner (id, user_id),
    KEY idx_savings_goals_user_status (user_id, status, target_date),
    CONSTRAINT fk_savings_goals_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_savings_goals_target CHECK (target_hnl > 0.00),
    CONSTRAINT chk_savings_goals_currency CHECK (currency = 'HNL'),
    CONSTRAINT chk_savings_goals_frequency CHECK (preferred_frequency IS NULL OR preferred_frequency IN ('daily', 'weekly', 'biweekly', 'twice-monthly', 'monthly')),
    CONSTRAINT chk_savings_goals_day_one CHECK (twice_monthly_day_one IS NULL OR twice_monthly_day_one BETWEEN 1 AND 31),
    CONSTRAINT chk_savings_goals_day_two CHECK (twice_monthly_day_two IS NULL OR twice_monthly_day_two BETWEEN 1 AND 31),
    CONSTRAINT chk_savings_goals_status CHECK (status IN ('active', 'completed', 'archived'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goal_contributions (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    goal_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount_hnl DECIMAL(15,2) NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'HNL',
    contributed_on DATE NOT NULL,
    idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    notes VARCHAR(500) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_goal_contributions_user_key (user_id, idempotency_key),
    KEY idx_goal_contributions_goal_date (goal_id, contributed_on, id),
    KEY idx_goal_contributions_user_date (user_id, contributed_on, id),
    CONSTRAINT fk_goal_contributions_goal_owner FOREIGN KEY (goal_id, user_id) REFERENCES savings_goals (id, user_id) ON DELETE CASCADE,
    CONSTRAINT chk_goal_contributions_amount CHECK (amount_hnl > 0.00),
    CONSTRAINT chk_goal_contributions_currency CHECK (currency = 'HNL')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reminders (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    parent_reminder_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    name VARCHAR(160) NOT NULL,
    category VARCHAR(100) NOT NULL,
    expected_amount_hnl DECIMAL(15,2) NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'HNL',
    due_date DATE NOT NULL,
    first_payment_date DATE NULL,
    recurrence VARCHAR(24) NOT NULL DEFAULT 'one-time',
    twice_monthly_day_one TINYINT UNSIGNED NULL,
    twice_monthly_day_two TINYINT UNSIGNED NULL,
    custom_interval_days SMALLINT UNSIGNED NULL,
    reminder_days_before SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    status VARCHAR(16) NOT NULL DEFAULT 'upcoming',
    notes VARCHAR(1000) NULL,
    is_credit_card BOOLEAN NOT NULL DEFAULT FALSE,
    card_nickname VARCHAR(120) NULL,
    masked_identifier VARCHAR(32) NULL,
    bank_name VARCHAR(120) NULL,
    closing_date DATE NULL,
    statement_date DATE NULL,
    minimum_payment_hnl DECIMAL(15,2) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    completed_at TIMESTAMP(6) NULL,
    archived_at TIMESTAMP(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reminders_owner (id, user_id),
    KEY idx_reminders_user_due (user_id, status, due_date, id),
    KEY idx_reminders_parent_owner (parent_reminder_id, user_id),
    CONSTRAINT fk_reminders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_reminders_parent_owner FOREIGN KEY (parent_reminder_id, user_id) REFERENCES reminders (id, user_id) ON DELETE RESTRICT,
    CONSTRAINT chk_reminders_amount CHECK (expected_amount_hnl > 0.00),
    CONSTRAINT chk_reminders_currency CHECK (currency = 'HNL'),
    CONSTRAINT chk_reminders_recurrence CHECK (recurrence IN ('one-time', 'weekly', 'every-15-days', 'twice-monthly', 'monthly', 'custom')),
    CONSTRAINT chk_reminders_day_one CHECK (twice_monthly_day_one IS NULL OR twice_monthly_day_one BETWEEN 1 AND 31),
    CONSTRAINT chk_reminders_day_two CHECK (twice_monthly_day_two IS NULL OR twice_monthly_day_two BETWEEN 1 AND 31),
    CONSTRAINT chk_reminders_custom_interval CHECK (recurrence <> 'custom' OR custom_interval_days > 0),
    CONSTRAINT chk_reminders_days_before CHECK (reminder_days_before <= 365),
    CONSTRAINT chk_reminders_status CHECK (status IN ('upcoming', 'today', 'overdue', 'paid', 'archived')),
    CONSTRAINT chk_reminders_minimum_payment CHECK (minimum_payment_hnl IS NULL OR minimum_payment_hnl >= 0.00)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reminder_payments (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reminder_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    transaction_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount_hnl DECIMAL(15,2) NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'HNL',
    status VARCHAR(16) NOT NULL DEFAULT 'confirmed',
    idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    paid_at TIMESTAMP(6) NOT NULL,
    reversed_at TIMESTAMP(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_reminder_payments_reminder (reminder_id),
    UNIQUE KEY uq_reminder_payments_transaction (transaction_id),
    UNIQUE KEY uq_reminder_payments_user_key (user_id, idempotency_key),
    KEY idx_reminder_payments_user_paid (user_id, paid_at, id),
    CONSTRAINT fk_reminder_payments_reminder_owner FOREIGN KEY (reminder_id, user_id) REFERENCES reminders (id, user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_reminder_payments_transaction_owner FOREIGN KEY (transaction_id, user_id) REFERENCES financial_transactions (id, user_id) ON DELETE RESTRICT,
    CONSTRAINT chk_reminder_payments_amount CHECK (amount_hnl > 0.00),
    CONSTRAINT chk_reminder_payments_currency CHECK (currency = 'HNL'),
    CONSTRAINT chk_reminder_payments_status CHECK (status IN ('confirmed', 'reversed')),
    CONSTRAINT chk_reminder_payments_reversal CHECK ((status = 'confirmed' AND reversed_at IS NULL) OR (status = 'reversed' AND reversed_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_preferences (
    user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    morning_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    evening_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    payments_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    closing_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    due_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    savings_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    budgets_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    goals_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    spending_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    morning_time TIME NOT NULL DEFAULT '07:00:00',
    evening_time TIME NOT NULL DEFAULT '20:30:00',
    timezone VARCHAR(64) NOT NULL DEFAULT 'America/Tegucigalpa',
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (user_id),
    CONSTRAINT fk_notification_preferences_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind VARCHAR(24) NOT NULL,
    title VARCHAR(180) NOT NULL,
    body VARCHAR(1000) NOT NULL,
    tone VARCHAR(16) NOT NULL DEFAULT 'info',
    dedupe_key VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    dismissed_at TIMESTAMP(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notifications_user_dedupe (user_id, dedupe_key),
    KEY idx_notifications_user_created (user_id, dismissed_at, created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_notifications_kind CHECK (kind IN ('morning', 'evening', 'payments', 'closing', 'due', 'savings', 'budgets', 'goals', 'spending')),
    CONSTRAINT chk_notifications_tone CHECK (tone IN ('info', 'warning', 'success'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS idempotency_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation_scope VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash BINARY(32) NOT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'processing',
    resource_type VARCHAR(80) NULL,
    resource_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    response_status SMALLINT UNSIGNED NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    completed_at TIMESTAMP(6) NULL,
    expires_at TIMESTAMP(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_idempotency_user_scope_key (user_id, operation_scope, idempotency_key),
    KEY idx_idempotency_expiry (expires_at, state),
    CONSTRAINT fk_idempotency_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_idempotency_state CHECK (state IN ('processing', 'completed', 'failed')),
    CONSTRAINT chk_idempotency_expiry CHECK (expires_at > created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    subject_user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    metadata_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_audit_logs_actor_time (actor_user_id, created_at),
    KEY idx_audit_logs_subject_time (subject_user_id, created_at),
    KEY idx_audit_logs_entity (entity_type, entity_id, created_at),
    CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_logs_subject FOREIGN KEY (subject_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_audit_logs_metadata_json CHECK (metadata_json IS NULL OR JSON_VALID(metadata_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO roles (code, description, is_system) VALUES
    ('member', 'RUMBO HN account holder', TRUE),
    ('admin', 'RUMBO HN administrator with separately authorized access', TRUE);

CREATE OR REPLACE VIEW v_user_transaction_totals AS
SELECT
    user_id,
    CAST(COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount_hnl ELSE 0.00 END), 0.00) AS DECIMAL(15,2)) AS income_hnl,
    CAST(COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount_hnl ELSE 0.00 END), 0.00) AS DECIMAL(15,2)) AS expenses_hnl
FROM financial_transactions
WHERE status = 'confirmed' AND deleted_at IS NULL
GROUP BY user_id;

CREATE OR REPLACE VIEW v_user_savings_totals AS
SELECT
    user_id,
    CAST(COALESCE(SUM(amount_hnl), 0.00) AS DECIMAL(15,2)) AS allocated_savings_hnl
FROM goal_contributions
GROUP BY user_id;

CREATE OR REPLACE VIEW v_user_pending_obligations AS
SELECT
    user_id,
    CAST(COALESCE(SUM(expected_amount_hnl), 0.00) AS DECIMAL(15,2)) AS pending_obligations_hnl
FROM reminders
WHERE status IN ('upcoming', 'today', 'overdue') AND archived_at IS NULL
GROUP BY user_id;

CREATE OR REPLACE VIEW v_user_financial_summary AS
SELECT
    u.id AS user_id,
    p.opening_balance_hnl,
    CAST(COALESCE(t.income_hnl, 0.00) AS DECIMAL(15,2)) AS income_hnl,
    CAST(COALESCE(t.expenses_hnl, 0.00) AS DECIMAL(15,2)) AS expenses_hnl,
    CAST(p.opening_balance_hnl + COALESCE(t.income_hnl, 0.00) - COALESCE(t.expenses_hnl, 0.00) AS DECIMAL(15,2)) AS balance_hnl,
    CAST(p.opening_balance_hnl + COALESCE(t.income_hnl, 0.00) - COALESCE(t.expenses_hnl, 0.00) - COALESCE(s.allocated_savings_hnl, 0.00) AS DECIMAL(15,2)) AS available_balance_hnl,
    CAST(COALESCE(s.allocated_savings_hnl, 0.00) AS DECIMAL(15,2)) AS allocated_savings_hnl,
    CAST(COALESCE(o.pending_obligations_hnl, 0.00) AS DECIMAL(15,2)) AS pending_obligations_hnl,
    CAST(p.opening_balance_hnl + COALESCE(t.income_hnl, 0.00) - COALESCE(t.expenses_hnl, 0.00) - COALESCE(o.pending_obligations_hnl, 0.00) AS DECIMAL(15,2)) AS projected_balance_hnl
FROM users u
INNER JOIN user_profiles p ON p.user_id = u.id
LEFT JOIN v_user_transaction_totals t ON t.user_id = u.id
LEFT JOIN v_user_savings_totals s ON s.user_id = u.id
LEFT JOIN v_user_pending_obligations o ON o.user_id = u.id
WHERE u.status IN ('pending', 'active', 'locked');

INSERT IGNORE INTO schema_migrations (version, description)
VALUES ('001', 'Initial RUMBO HN secure financial schema');
