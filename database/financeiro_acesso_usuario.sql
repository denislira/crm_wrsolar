-- Acesso individual ao Financeiro, além da permissão do perfil.
ALTER TABLE users ADD COLUMN financeiro_permitido TINYINT(1) NOT NULL DEFAULT 0;
-- Preserva o acesso dos diretores que já tinham a permissão de Financeiro.
UPDATE users u JOIN role_permissions rp ON rp.role_id=u.role_id AND rp.screen='financeiro' AND rp.allowed=1
SET u.financeiro_permitido=1 WHERE u.role_id=1;
