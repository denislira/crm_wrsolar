CREATE TABLE IF NOT EXISTS whatsapp_archived_contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  phone_normalized VARCHAR(40) NOT NULL,
  lead_id INT DEFAULT NULL,
  lead_name VARCHAR(255) DEFAULT NULL,
  archived_by INT DEFAULT NULL,
  archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_whatsapp_archived_phone (phone_normalized),
  KEY idx_whatsapp_archived_lead (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
