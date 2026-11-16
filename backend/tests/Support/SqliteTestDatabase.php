<?php

declare(strict_types=1);

namespace Rumbo\Backend\Tests\Support;

use PDO;

final class SqliteTestDatabase
{
    public static function create(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec(<<<'SQL'
CREATE TABLE users (
    id TEXT PRIMARY KEY,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    status TEXT NOT NULL,
    email_verified_at TEXT NULL,
    last_login_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE user_profiles (
    user_id TEXT PRIMARY KEY,
    display_name TEXT NOT NULL,
    timezone TEXT NOT NULL,
    locale TEXT NOT NULL,
    opening_balance_hnl DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    onboarding_completed INTEGER NOT NULL DEFAULT 0,
    trial_started_at TEXT NULL,
    trial_ends_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE roles (
    code TEXT PRIMARY KEY,
    description TEXT NOT NULL,
    is_system INTEGER NOT NULL
);
CREATE TABLE user_roles (
    user_id TEXT NOT NULL,
    role_code TEXT NOT NULL,
    assigned_by_user_id TEXT NULL,
    assigned_at TEXT NOT NULL,
    PRIMARY KEY (user_id, role_code),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_code) REFERENCES roles(code)
);
CREATE TABLE auth_sessions (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    token_hash BLOB NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    last_seen_at TEXT NULL,
    expires_at TEXT NOT NULL,
    revoked_at TEXT NULL,
    ip_hash BLOB NULL,
    user_agent_hash BLOB NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE password_reset_tokens (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    token_hash BLOB NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    consumed_at TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE auth_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    identity_hash BLOB NOT NULL,
    ip_hash BLOB NULL,
    succeeded INTEGER NOT NULL,
    attempted_at TEXT NOT NULL
);
CREATE TABLE financial_transactions (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    transaction_type TEXT NOT NULL,
    name TEXT NOT NULL,
    category TEXT NOT NULL,
    amount_hnl DECIMAL(15,2) NOT NULL,
    currency TEXT NOT NULL,
    occurred_on TEXT NOT NULL,
    occurred_at TEXT NULL,
    status TEXT NOT NULL,
    source_type TEXT NOT NULL,
    recurrence TEXT NOT NULL,
    is_quick_expense INTEGER NOT NULL,
    notes TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE budgets (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    category TEXT NOT NULL,
    period_start TEXT NOT NULL,
    period_end TEXT NOT NULL,
    limit_hnl DECIMAL(15,2) NOT NULL,
    currency TEXT NOT NULL,
    color TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, category, period_start, period_end),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE savings_goals (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    name TEXT NOT NULL,
    label TEXT NULL,
    target_hnl DECIMAL(15,2) NOT NULL,
    currency TEXT NOT NULL,
    target_date TEXT NULL,
    preferred_frequency TEXT NULL,
    twice_monthly_day_one INTEGER NULL,
    twice_monthly_day_two INTEGER NULL,
    color TEXT NULL,
    status TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    archived_at TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE idempotency_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id TEXT NOT NULL,
    operation_scope TEXT NOT NULL,
    idempotency_key TEXT NOT NULL,
    request_hash BLOB NOT NULL,
    state TEXT NOT NULL,
    resource_type TEXT NULL,
    resource_id TEXT NULL,
    response_status INTEGER NULL,
    created_at TEXT NOT NULL,
    completed_at TEXT NULL,
    expires_at TEXT NOT NULL,
    UNIQUE (user_id, operation_scope, idempotency_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE goal_contributions (
    id TEXT PRIMARY KEY,
    goal_id TEXT NOT NULL,
    user_id TEXT NOT NULL,
    amount_hnl DECIMAL(15,2) NOT NULL,
    currency TEXT NOT NULL,
    contributed_on TEXT NOT NULL,
    idempotency_key TEXT NOT NULL,
    notes TEXT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (user_id, idempotency_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE reminders (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    parent_reminder_id TEXT NULL,
    name TEXT NOT NULL DEFAULT 'Recordatorio',
    category TEXT NOT NULL DEFAULT 'General',
    expected_amount_hnl DECIMAL(15,2) NOT NULL,
    currency TEXT NOT NULL DEFAULT 'HNL',
    due_date TEXT NOT NULL DEFAULT '2026-01-01',
    first_payment_date TEXT NULL,
    recurrence TEXT NOT NULL DEFAULT 'one-time',
    twice_monthly_day_one INTEGER NULL,
    twice_monthly_day_two INTEGER NULL,
    custom_interval_days INTEGER NULL,
    reminder_days_before INTEGER NOT NULL DEFAULT 3,
    status TEXT NOT NULL,
    notes TEXT NULL,
    is_credit_card INTEGER NOT NULL DEFAULT 0,
    card_nickname TEXT NULL,
    masked_identifier TEXT NULL,
    bank_name TEXT NULL,
    closing_date TEXT NULL,
    statement_date TEXT NULL,
    minimum_payment_hnl DECIMAL(15,2) NULL,
    created_at TEXT NOT NULL DEFAULT '2026-01-01 00:00:00.000000',
    updated_at TEXT NOT NULL DEFAULT '2026-01-01 00:00:00.000000',
    completed_at TEXT NULL,
    archived_at TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE reminder_payments (
    id TEXT PRIMARY KEY,
    reminder_id TEXT NOT NULL,
    user_id TEXT NOT NULL,
    transaction_id TEXT NOT NULL,
    amount_hnl DECIMAL(15,2) NOT NULL,
    currency TEXT NOT NULL,
    status TEXT NOT NULL,
    idempotency_key TEXT NOT NULL,
    paid_at TEXT NOT NULL,
    reversed_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (reminder_id),
    UNIQUE (transaction_id),
    UNIQUE (user_id, idempotency_key),
    FOREIGN KEY (reminder_id) REFERENCES reminders(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (transaction_id) REFERENCES financial_transactions(id)
);
CREATE TABLE notification_preferences (
    user_id TEXT PRIMARY KEY,
    morning_enabled INTEGER NOT NULL DEFAULT 1,
    evening_enabled INTEGER NOT NULL DEFAULT 1,
    payments_enabled INTEGER NOT NULL DEFAULT 1,
    closing_enabled INTEGER NOT NULL DEFAULT 1,
    due_enabled INTEGER NOT NULL DEFAULT 1,
    savings_enabled INTEGER NOT NULL DEFAULT 1,
    budgets_enabled INTEGER NOT NULL DEFAULT 1,
    goals_enabled INTEGER NOT NULL DEFAULT 1,
    spending_enabled INTEGER NOT NULL DEFAULT 0,
    morning_time TEXT NOT NULL DEFAULT '07:00:00',
    evening_time TEXT NOT NULL DEFAULT '20:30:00',
    timezone TEXT NOT NULL DEFAULT 'America/Tegucigalpa',
    created_at TEXT NOT NULL DEFAULT '2026-01-01 00:00:00.000000',
    updated_at TEXT NOT NULL DEFAULT '2026-01-01 00:00:00.000000',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE notifications (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    kind TEXT NOT NULL,
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    tone TEXT NOT NULL,
    dedupe_key TEXT NOT NULL,
    created_at TEXT NOT NULL,
    read_at TEXT NULL,
    dismissed_at TEXT NULL,
    UNIQUE (user_id, dedupe_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_user_id TEXT NULL,
    subject_user_id TEXT NULL,
    action TEXT NOT NULL,
    entity_type TEXT NULL,
    entity_id TEXT NULL,
    request_id TEXT NOT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (subject_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE TABLE schema_migrations (
    version TEXT PRIMARY KEY,
    description TEXT NOT NULL,
    applied_at TEXT NOT NULL DEFAULT '2026-01-01 00:00:00.000000'
);
INSERT INTO roles (code, description, is_system) VALUES
    ('member', 'Member', 1),
    ('admin', 'Administrator', 1);
INSERT INTO schema_migrations (version, description) VALUES
    ('001', 'Initial schema'),
    ('002', 'Notification read state');
CREATE VIEW v_user_transaction_totals AS
SELECT user_id,
       CAST(COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount_hnl ELSE 0.00 END), 0.00) AS DECIMAL(15,2)) AS income_hnl,
       CAST(COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount_hnl ELSE 0.00 END), 0.00) AS DECIMAL(15,2)) AS expenses_hnl
FROM financial_transactions
WHERE status = 'confirmed' AND deleted_at IS NULL
GROUP BY user_id;
CREATE VIEW v_user_savings_totals AS
SELECT user_id, CAST(COALESCE(SUM(amount_hnl), 0.00) AS DECIMAL(15,2)) AS allocated_savings_hnl
FROM goal_contributions GROUP BY user_id;
CREATE VIEW v_user_pending_obligations AS
SELECT user_id, CAST(COALESCE(SUM(expected_amount_hnl), 0.00) AS DECIMAL(15,2)) AS pending_obligations_hnl
FROM reminders WHERE status IN ('upcoming', 'today', 'overdue') AND archived_at IS NULL GROUP BY user_id;
CREATE VIEW v_user_financial_summary AS
SELECT u.id AS user_id,
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
SQL);
        return $pdo;
    }
}
