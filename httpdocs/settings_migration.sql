-- ─────────────────────────────────────────────────────────────
-- Run this ONCE in Plesk phpMyAdmin to add the settings table.
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS system_settings (
  `key`      VARCHAR(60)  NOT NULL PRIMARY KEY,
  `value`    TEXT         DEFAULT NULL,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default rows (INSERT IGNORE skips if already exists)
INSERT IGNORE INTO system_settings (`key`, `value`) VALUES
  ('app_name',       'NextGen Technologies'),
  ('smtp_host',      ''),
  ('smtp_port',      '587'),
  ('smtp_secure',    'tls'),
  ('smtp_username',  ''),
  ('smtp_password',  ''),
  ('smtp_from',      ''),
  ('smtp_from_name', 'NextGen Technologies');
