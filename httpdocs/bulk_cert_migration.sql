-- ============================================================
--  bulk_cert_migration.sql
--  Separate table for bulk certificate records.
--  Does NOT touch students / courses / certificates tables.
--  Run once:  mysql -u root -p nextgen_db < bulk_cert_migration.sql
-- ============================================================

USE nextgen_db;

CREATE TABLE IF NOT EXISTS bulk_cert_records (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id        VARCHAR(30)  NOT NULL,                      -- groups all records from one upload
  cert_code       VARCHAR(30)  NOT NULL UNIQUE,
  student_name    VARCHAR(255) NOT NULL,
  student_email   VARCHAR(255) NOT NULL,
  course_name     VARCHAR(255) NOT NULL DEFAULT 'General Training',
  grade           VARCHAR(100) NOT NULL DEFAULT 'Pass',
  issue_date      DATE         NOT NULL,
  organisation    VARCHAR(255) NOT NULL DEFAULT 'NextGen Technologies',
  director_name   VARCHAR(255) NOT NULL DEFAULT 'Director',
  cert_type       VARCHAR(100) NOT NULL DEFAULT 'Certificate of Completion',
  delivery_status ENUM('Pending','Sent','Failed') NOT NULL DEFAULT 'Pending',
  sent_at         DATETIME     DEFAULT NULL,
  issued_by       INT UNSIGNED DEFAULT NULL,                  -- references users.id (soft ref)
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS idx_bulk_batch  ON bulk_cert_records (batch_id);
CREATE INDEX IF NOT EXISTS idx_bulk_email  ON bulk_cert_records (student_email);
CREATE INDEX IF NOT EXISTS idx_bulk_status ON bulk_cert_records (delivery_status);
