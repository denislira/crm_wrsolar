CREATE TABLE IF NOT EXISTS teams (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  description TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Seed example teams
INSERT INTO teams (name, description) VALUES
('Marketing','Equipe de marketing'),
('Vendas','Equipe de vendas'),
('Atendimento','Equipe de atendimento'),
('Técnica','Equipe técnica'),
('Financeiro','Equipe financeiro')
ON DUPLICATE KEY UPDATE name = name;