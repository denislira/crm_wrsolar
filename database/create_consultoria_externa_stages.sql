CREATE TABLE IF NOT EXISTS consultoria_externa_itens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  client_name VARCHAR(255) NOT NULL,
  phone VARCHAR(50) DEFAULT NULL,
  cidade VARCHAR(255) DEFAULT NULL,
  source VARCHAR(255) DEFAULT NULL,
  status VARCHAR(100) DEFAULT NULL,
  value DECIMAL(12,2) DEFAULT 0.00,
  notes TEXT DEFAULT NULL,
  stage_key VARCHAR(50) DEFAULT 'captacao_tecnica',
  stage_id INT DEFAULT NULL,
  exported_to_internal_queue TINYINT(1) NOT NULL DEFAULT 0,
  exported_at DATETIME DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted TINYINT(1) NOT NULL DEFAULT 0,
  deleted_at DATETIME DEFAULT NULL,
  INDEX idx_ce_user (user_id),
  INDEX idx_ce_stage_key (stage_key),
  INDEX idx_ce_stage_id (stage_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consultoria_externa_stages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  position INT NOT NULL DEFAULT 0,
  color VARCHAR(7) DEFAULT '#6c757d',
  card_color VARCHAR(7) DEFAULT '#ffffff',
  icon VARCHAR(50) DEFAULT 'fa-layer-group',
  is_initial TINYINT(1) NOT NULL DEFAULT 0,
  export_to_internal_queue TINYINT(1) NOT NULL DEFAULT 0,
  next_stage_id INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ce_stage_user (user_id),
  INDEX idx_ce_stage_position (position),
  INDEX idx_ce_stage_next_stage (next_stage_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE consultoria_externa_itens
  ADD COLUMN IF NOT EXISTS stage_id INT DEFAULT NULL AFTER stage_key,
  ADD COLUMN IF NOT EXISTS exported_to_internal_queue TINYINT(1) NOT NULL DEFAULT 0 AFTER stage_id,
  ADD COLUMN IF NOT EXISTS exported_at DATETIME DEFAULT NULL AFTER exported_to_internal_queue;

ALTER TABLE consultoria_externa_stages
  ADD COLUMN IF NOT EXISTS next_stage_id INT DEFAULT NULL AFTER export_to_internal_queue;

CREATE TABLE IF NOT EXISTS consultoria_interna_demandas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  external_item_id INT NOT NULL,
  external_stage_id INT DEFAULT NULL,
  external_user_id INT NOT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'pending',
  accepted_by INT DEFAULT NULL,
  accepted_at DATETIME DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_ce_external_item (external_item_id),
  INDEX idx_ce_demand_stage (external_stage_id),
  INDEX idx_ce_demand_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
