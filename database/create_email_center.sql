CREATE TABLE IF NOT EXISTS crm_emails (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
  recipient_to TEXT NOT NULL, recipient_cc TEXT DEFAULT NULL, recipient_bcc TEXT DEFAULT NULL,
  reply_to VARCHAR(255) DEFAULT NULL, subject VARCHAR(998) NOT NULL DEFAULT '', body_html MEDIUMTEXT DEFAULT NULL,
  status ENUM('draft','sent','failed','trash') NOT NULL DEFAULT 'draft',
  previous_status ENUM('draft','sent','failed') DEFAULT NULL, error_message TEXT DEFAULT NULL,
  sent_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_crm_emails_user_status (user_id, status, updated_at),
  CONSTRAINT fk_crm_emails_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_email_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY, email_id INT NOT NULL,
  original_name VARCHAR(255) NOT NULL, stored_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(150) NOT NULL DEFAULT 'application/octet-stream', file_size INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_crm_email_attachments_email (email_id),
  CONSTRAINT fk_crm_email_attachments_email FOREIGN KEY (email_id) REFERENCES crm_emails(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
