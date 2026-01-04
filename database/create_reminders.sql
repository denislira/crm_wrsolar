-- create_reminders.sql
CREATE TABLE IF NOT EXISTS reminders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lead_id INT NOT NULL,
  message TEXT NOT NULL,
  remind_at DATETIME NOT NULL,
  template_id INT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  created_by INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  executed_at DATETIME NULL,
  INDEX (lead_id), INDEX (status), INDEX (remind_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
