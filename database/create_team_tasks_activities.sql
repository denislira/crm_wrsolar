-- Migration: create_team_tasks_activities.sql
-- Run with: mysql -u user -p database_name < create_team_tasks_activities.sql

CREATE TABLE IF NOT EXISTS team_tasks_activities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT DEFAULT NULL,
  action VARCHAR(50) NOT NULL,
  user_id INT DEFAULT NULL,
  username VARCHAR(150) DEFAULT NULL,
  details TEXT,
  equipe VARCHAR(150) DEFAULT NULL,
  titulo VARCHAR(255) DEFAULT NULL,
  responsavel VARCHAR(150) DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
