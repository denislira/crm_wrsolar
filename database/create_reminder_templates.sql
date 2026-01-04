-- create_reminder_templates.sql
CREATE TABLE IF NOT EXISTS reminder_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  default_days_offset INT DEFAULT 0,
  default_time TIME DEFAULT '09:00:00',
  channel VARCHAR(50) DEFAULT 'in-app',
  active TINYINT(1) DEFAULT 1,
  created_by INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sample templates
INSERT INTO reminder_templates (name, message, default_days_offset, default_time, channel) VALUES
('Ligar em 3 dias', 'Ligar para {{lead.name}} sobre a proposta. Telefone: {{lead.phone}}', 3, '10:00:00', 'in-app'),
('Enviar proposta agora', 'Enviar proposta para {{lead.name}} — Fonte: {{lead.source}}', 0, '09:00:00', 'in-app');
