-- ============================================================
--  NextGen Technologies — Database Schema
--  Engine: MySQL 5.7+ / MariaDB 10.3+
--  Run:  mysql -u root -p < schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS nextgen_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE nextgen_db;

-- ─────────────────────────────────────────────
-- 1. USERS
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  username    VARCHAR(80)  NOT NULL UNIQUE,
  email       VARCHAR(180) NOT NULL UNIQUE,
  password    VARCHAR(255) NOT NULL,
  role        ENUM('Admin','User','Staff') NOT NULL DEFAULT 'User',
  department  VARCHAR(100) DEFAULT NULL,
  status      ENUM('Active','Inactive')   NOT NULL DEFAULT 'Active',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 2. COURSES
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS courses (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(150)  NOT NULL,
  category     VARCHAR(80)   DEFAULT NULL,
  emoji        VARCHAR(10)   DEFAULT '📚',
  duration     VARCHAR(60)   DEFAULT NULL,
  fee          DECIMAL(10,2) DEFAULT 0.00,
  max_students SMALLINT UNSIGNED DEFAULT 30,
  instructor   VARCHAR(120)  DEFAULT NULL,
  description  TEXT          DEFAULT NULL,
  color        VARCHAR(20)   DEFAULT '#7C3AED',
  status       ENUM('Active','Inactive','Draft') NOT NULL DEFAULT 'Active',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 3. STUDENTS
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS students (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name      VARCHAR(80)  NOT NULL,
  last_name       VARCHAR(80)  NOT NULL,
  email           VARCHAR(180) NOT NULL UNIQUE,
  phone           VARCHAR(20)  DEFAULT NULL,
  gender          ENUM('Male','Female','Other') DEFAULT NULL,
  dob             DATE         DEFAULT NULL,
  nationality     VARCHAR(60)  DEFAULT NULL,
  qualification   VARCHAR(120) DEFAULT NULL,
  address_line1   VARCHAR(200) DEFAULT NULL,
  address_line2   VARCHAR(200) DEFAULT NULL,
  city            VARCHAR(80)  DEFAULT NULL,
  state           VARCHAR(80)  DEFAULT NULL,
  pincode         VARCHAR(10)  DEFAULT NULL,
  id_number       VARCHAR(60)  DEFAULT NULL,
  occupation      VARCHAR(80)  DEFAULT NULL,
  company         VARCHAR(120) DEFAULT NULL,
  experience_yrs  TINYINT UNSIGNED DEFAULT 0,
  known_skills    VARCHAR(255) DEFAULT NULL,
  prior_certs     VARCHAR(255) DEFAULT NULL,
  referral_source VARCHAR(80)  DEFAULT NULL,
  notes           TEXT         DEFAULT NULL,
  submitted_by    INT UNSIGNED DEFAULT NULL,
  status          ENUM('Pending','Approved','Rejected','Completed') NOT NULL DEFAULT 'Pending',
  registered_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_student_user FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 4. ENROLLMENTS
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS enrollments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id  INT UNSIGNED NOT NULL,
  course_id   INT UNSIGNED NOT NULL,
  mode        ENUM('Online','Offline','Hybrid') DEFAULT 'Offline',
  start_date  DATE     DEFAULT NULL,
  progress    TINYINT UNSIGNED DEFAULT 0,
  status      ENUM('Active','Completed','Dropped') NOT NULL DEFAULT 'Active',
  enrolled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_enroll (student_id, course_id),
  CONSTRAINT fk_en_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_en_course  FOREIGN KEY (course_id)  REFERENCES courses(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 5. CERTIFICATES
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS certificates (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id      INT UNSIGNED NOT NULL,
  course_id       INT UNSIGNED NOT NULL,
  cert_type       VARCHAR(80)  NOT NULL DEFAULT 'Certificate of Completion',
  grade           VARCHAR(40)  DEFAULT NULL,
  issue_date      DATE         NOT NULL,
  organisation    VARCHAR(150) DEFAULT 'NextGen Technologies',
  org_unit        VARCHAR(150) DEFAULT NULL,
  country         CHAR(2)      DEFAULT 'IN',
  state           VARCHAR(80)  DEFAULT NULL,
  city            VARCHAR(80)  DEFAULT NULL,
  director_name   VARCHAR(120) DEFAULT NULL,
  custom_message  TEXT         DEFAULT NULL,
  issued_by       INT UNSIGNED DEFAULT NULL,
  delivery_status ENUM('Pending','Sent','Failed') NOT NULL DEFAULT 'Pending',
  sent_at         TIMESTAMP NULL DEFAULT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cert_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_cert_course  FOREIGN KEY (course_id)  REFERENCES courses(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_cert_by      FOREIGN KEY (issued_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 6. NOTICES
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notices (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(200)                        NOT NULL,
  body        TEXT                                DEFAULT NULL,
  priority    ENUM('Low','Normal','High')         NOT NULL DEFAULT 'Normal',
  target      ENUM('All','Admin','User')          NOT NULL DEFAULT 'All',
  created_by  INT UNSIGNED                        DEFAULT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_notice_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 7. ACTIVITY LOG
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS activity_log (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED DEFAULT NULL,
  action      VARCHAR(100) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  ip_address  VARCHAR(45)  DEFAULT NULL,
  logged_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 8. ATTENDANCE
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS attendance (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id   INT UNSIGNED NOT NULL,
  course_id    INT UNSIGNED NOT NULL,
  session_date DATE NOT NULL,
  session      ENUM('Morning','Afternoon','Evening','Full Day') NOT NULL DEFAULT 'Full Day',
  status       ENUM('Present','Absent','Late') NOT NULL,
  note         VARCHAR(255) DEFAULT NULL,
  marked_by    INT UNSIGNED DEFAULT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_att (student_id, course_id, session_date, session),
  CONSTRAINT fk_att_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_course  FOREIGN KEY (course_id)  REFERENCES courses(id)  ON DELETE CASCADE,
  CONSTRAINT fk_att_user    FOREIGN KEY (marked_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 9. SYSTEM SETTINGS
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS system_settings (
  `key`      VARCHAR(60)  NOT NULL PRIMARY KEY,
  `value`    TEXT         DEFAULT NULL,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO system_settings (`key`, `value`) VALUES
  ('app_name',       'NextGen Technologies'),
  ('smtp_host',      ''),
  ('smtp_port',      '587'),
  ('smtp_secure',    'tls'),
  ('smtp_username',  ''),
  ('smtp_password',  ''),
  ('smtp_from',      ''),
  ('smtp_from_name', 'NextGen Technologies');

-- ─────────────────────────────────────────────
-- INDEXES
-- ─────────────────────────────────────────────
CREATE INDEX idx_students_status ON students(status);
CREATE INDEX idx_students_sub    ON students(submitted_by);
CREATE INDEX idx_enroll_student  ON enrollments(student_id);
CREATE INDEX idx_enroll_course   ON enrollments(course_id);
CREATE INDEX idx_cert_student    ON certificates(student_id);
CREATE INDEX idx_cert_delivery   ON certificates(delivery_status);
CREATE INDEX idx_courses_status  ON courses(status);
CREATE INDEX idx_users_role      ON users(role);
CREATE INDEX idx_att_course_date ON attendance(course_id, session_date);
CREATE INDEX idx_att_student     ON attendance(student_id);

-- ─────────────────────────────────────────────
-- SEED: ONE default admin (password: Admin@123)
-- Change password immediately after first login.
-- ─────────────────────────────────────────────
INSERT INTO users (name, username, email, password, role, department, status)
VALUES (
  'Admin',
  'admin',
  'admin@nextgen.com',
  '$2y$12$Lh8WvTmPk9dN2QoR3sX7uO4jGZwMc6BfE1yKI0aHP5eDqiVnJFlt.',
  'Admin',
  'Management',
  'Active'
);
-- Note: hash above = bcrypt of "Admin@123"
-- To regenerate: php -r "echo password_hash('Admin@123', PASSWORD_BCRYPT, ['cost'=>12]);"
