-- update_funil_stages_extra.sql
-- Add extra columns to support Kanban customization and behavior rules
ALTER TABLE funil_stages
  ADD COLUMN IF NOT EXISTS card_color VARCHAR(32) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS icon VARCHAR(64) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS is_final TINYINT(1) DEFAULT 0, -- 1 = final (Ganhou/Perdido)
  ADD COLUMN IF NOT EXISTS final_type ENUM('none','won','lost') DEFAULT 'none',
  ADD COLUMN IF NOT EXISTS generate_task_on_enter TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS alert_on_inactivity TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS required_fields TEXT DEFAULT NULL, -- JSON array of field keys
  ADD COLUMN IF NOT EXISTS sla_days INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS block_advance TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS include_in_forecast TINYINT(1) DEFAULT 1,
  ADD COLUMN IF NOT EXISTS is_qualification TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS is_conversion TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS track_time_in_stage TINYINT(1) DEFAULT 0;

-- History table to track modifications to the stages
CREATE TABLE IF NOT EXISTS funil_stages_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stage_id INT NULL,
  user_id INT NULL,
  action VARCHAR(50) NOT NULL, -- add, update, delete, reorder
  changes JSON DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (stage_id), INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
