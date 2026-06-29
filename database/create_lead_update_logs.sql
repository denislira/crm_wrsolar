CREATE TABLE IF NOT EXISTS lead_update_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lead_id INT NOT NULL,
  user_id INT DEFAULT NULL,
  updated_field VARCHAR(100) NOT NULL,
  old_value TEXT DEFAULT NULL,
  new_value TEXT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_lead_update_logs_created_user (created_at, user_id),
  INDEX idx_lead_update_logs_lead (lead_id),
  INDEX idx_lead_update_logs_field (updated_field)
);
