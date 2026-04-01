-- Migration script: add tabela para etapas de projetos

CREATE TABLE IF NOT EXISTS projeto_stages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  position INT NOT NULL DEFAULT 0,
  color VARCHAR(7) DEFAULT '#6c757d',
  card_color VARCHAR(7) DEFAULT '#ffffff',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projeto_stages_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stage_id INT NULL,
  user_id INT NULL,
  action VARCHAR(50) NOT NULL,
  changes JSON DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (stage_id),
  INDEX (user_id),
  FOREIGN KEY (stage_id) REFERENCES projeto_stages(id) ON DELETE SET NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
