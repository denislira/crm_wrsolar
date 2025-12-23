-- create_lead_movements.sql
-- Creates table to record immutable lead movements for audit and metrics

CREATE TABLE IF NOT EXISTS lead_movements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lead_id INT NOT NULL,
  user_id INT NOT NULL,
  from_stage_id INT NULL,
  to_stage_id INT NULL,
  from_status VARCHAR(255) NULL,
  to_status VARCHAR(255) NULL,
  changed_by INT NULL,
  note TEXT NULL,
  is_alert TINYINT(1) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (lead_id),
  INDEX (user_id),
  INDEX (from_stage_id),
  INDEX (to_stage_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
