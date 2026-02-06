-- Adicionar coluna responsavel_id à tabela team_tasks
ALTER TABLE team_tasks ADD COLUMN responsavel_id INT DEFAULT NULL;
ALTER TABLE team_tasks ADD FOREIGN KEY (responsavel_id) REFERENCES users(id) ON DELETE SET NULL;
