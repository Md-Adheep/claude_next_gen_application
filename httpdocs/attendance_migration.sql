-- ─────────────────────────────────────────────
-- Attendance Table — run once in phpMyAdmin
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS attendance (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id  INT UNSIGNED NOT NULL,
  course_id   INT UNSIGNED NOT NULL,
  session_date DATE NOT NULL,
  session     ENUM('Morning','Afternoon','Evening','Full Day') NOT NULL DEFAULT 'Full Day',
  status      ENUM('Present','Absent','Late') NOT NULL,
  note        VARCHAR(255) DEFAULT NULL,
  marked_by   INT UNSIGNED DEFAULT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_att (student_id, course_id, session_date, session),
  CONSTRAINT fk_att_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_course  FOREIGN KEY (course_id)  REFERENCES courses(id)  ON DELETE CASCADE,
  CONSTRAINT fk_att_user    FOREIGN KEY (marked_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_att_course_date ON attendance(course_id, session_date);
CREATE INDEX idx_att_student     ON attendance(student_id);
