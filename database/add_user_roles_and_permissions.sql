-- Migration to add user roles and permissions

-- Add role_id to users table
ALTER TABLE users ADD COLUMN role_id INT DEFAULT NULL;

-- Create roles table
CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE
);

-- Insert default roles
INSERT INTO roles (name) VALUES ('Diretor'), ('gerente'), ('supervisor'), ('consultor');

-- Create role_permissions table
CREATE TABLE IF NOT EXISTS role_permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_id INT NOT NULL,
  screen VARCHAR(100) NOT NULL,
  allowed BOOLEAN DEFAULT FALSE,
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
);

-- Insert permissions for Diretor (all screens allowed)
INSERT INTO role_permissions (role_id, screen, allowed) VALUES
(1, 'dashboard', TRUE),
(1, 'projetos', TRUE),
(1, 'pos-venda', TRUE),
(1, 'relatorios', TRUE),
(1, 'leads_gestao', TRUE),
(1, 'integracao-equipes', TRUE),
(1, 'funil_config', TRUE),
(1, 'configuracoes', TRUE);

-- For gerente, allow some
INSERT INTO role_permissions (role_id, screen, allowed) VALUES
(2, 'dashboard', TRUE),
(2, 'projetos', TRUE),
(2, 'pos-venda', TRUE),
(2, 'relatorios', TRUE),
(2, 'leads_gestao', TRUE),
(2, 'integracao-equipes', TRUE),
(2, 'funil_config', TRUE);

-- For supervisor, fewer
INSERT INTO role_permissions (role_id, screen, allowed) VALUES
(3, 'dashboard', TRUE),
(3, 'leads_gestao', TRUE),
(3, 'integracao-equipes', TRUE);

-- For consultor, basic
INSERT INTO role_permissions (role_id, screen, allowed) VALUES
(4, 'dashboard', TRUE),
(4, 'leads_gestao', TRUE),
(4, 'integracao-equipes', TRUE);

-- Set the first user (assuming id=1) to Diretor
UPDATE users SET role_id = 1 WHERE id = 1;