-- Base da primeira etapa do modulo Financeiro: entradas vindas do CRM.
CREATE TABLE IF NOT EXISTS financeiro_vendas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lead_id INT DEFAULT NULL,
  project_id INT DEFAULT NULL,
  crm_stage_id INT DEFAULT NULL,
  owner_user_id INT DEFAULT NULL,
  origem VARCHAR(30) NOT NULL DEFAULT 'crm',
  status VARCHAR(40) NOT NULL DEFAULT 'aguardando_projeto',
  client_name VARCHAR(255) NOT NULL,
  cpf_cnpj VARCHAR(20) DEFAULT NULL,
  phone VARCHAR(50) DEFAULT NULL,
  email VARCHAR(255) DEFAULT NULL,
  cidade VARCHAR(255) DEFAULT NULL,
  vendedor VARCHAR(255) DEFAULT NULL,
  valor_negociado DECIMAL(12,2) DEFAULT NULL,
  forma_pagamento VARCHAR(255) DEFAULT NULL,
  data_venda DATETIME DEFAULT NULL,
  observacoes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  activated_at DATETIME DEFAULT NULL,
  cancelled_at DATETIME DEFAULT NULL,
  UNIQUE KEY ux_financeiro_vendas_lead (lead_id),
  UNIQUE KEY ux_financeiro_vendas_project (project_id),
  KEY idx_financeiro_vendas_status (status),
  KEY idx_financeiro_vendas_origem (origem),
  KEY idx_financeiro_vendas_owner (owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A nova tela fica desmarcada para todos os perfis até ser liberada em Configurações.
INSERT INTO role_permissions (role_id, screen, allowed)
SELECT r.id, 'financeiro', 0
FROM roles r
WHERE NOT EXISTS (
  SELECT 1 FROM role_permissions rp
  WHERE rp.role_id = r.id AND rp.screen = 'financeiro'
);
