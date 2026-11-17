-- RUMBO HN B01-L04
-- Forward-only, additive notification read state.
-- Apply only after a verified private backup of the dedicated RUMBO database.

ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS read_at TIMESTAMP(6) NULL AFTER created_at;

CREATE INDEX IF NOT EXISTS idx_notifications_user_read
    ON notifications (user_id, read_at, created_at);

INSERT IGNORE INTO schema_migrations (version, description)
VALUES ('002', 'Notification read state for authenticated in-app center');
