-- RUMBO HN B01-L04
-- Forward-only administrator identity. Customer accounts continue to require email.

ALTER TABLE users
    MODIFY email VARCHAR(254) NULL,
    ADD COLUMN username VARCHAR(64) NULL AFTER email;

ALTER TABLE users
    ADD UNIQUE KEY uq_users_username (username);

INSERT INTO schema_migrations (version, description)
VALUES ('003', 'Optional username identity for controlled administrator access');
