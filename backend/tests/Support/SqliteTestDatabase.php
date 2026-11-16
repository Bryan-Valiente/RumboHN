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
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE reminders (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    expected_amount_hnl DECIMAL(15,2) NOT NULL,
    status TEXT NOT NULL,
    archived_at TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
INSERT INTO roles (code, description, is_system) VALUES
    ('member', 'Member', 1),
    ('admin', 'Administrator', 1);
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
