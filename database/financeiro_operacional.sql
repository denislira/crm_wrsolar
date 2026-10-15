-- Estrutura operacional do Financeiro. Pode ser executada novamente.
CREATE TABLE IF NOT EXISTS financeiro_detalhes (
  venda_id INT PRIMARY KEY,
  campos JSON NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_fin_detalhes_venda FOREIGN KEY (venda_id) REFERENCES financeiro_vendas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS financeiro_titulos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  venda_id INT NULL,
  natureza ENUM('receber','pagar') NOT NULL,
  categoria VARCHAR(60) NOT NULL,
  descricao VARCHAR(255) NOT NULL,
  contraparte VARCHAR(255) DEFAULT NULL,
  documento VARCHAR(100) DEFAULT NULL,
  banco VARCHAR(120) DEFAULT NULL,
  parcela_numero INT DEFAULT NULL,
  parcelas_total INT DEFAULT NULL,
  vencimento DATE NOT NULL,
  valor DECIMAL(14,2) NOT NULL,
  cancelado_em DATETIME DEFAULT NULL,
  criado_por INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_fin_titulos_vencimento (natureza,vencimento),
  KEY idx_fin_titulos_venda (venda_id),
  CONSTRAINT fk_fin_titulos_venda FOREIGN KEY (venda_id) REFERENCES financeiro_vendas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS financeiro_baixas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  titulo_id INT NOT NULL,
  data_pagamento DATE NOT NULL,
  valor DECIMAL(14,2) NOT NULL,
  juros DECIMAL(14,2) NOT NULL DEFAULT 0,
  desconto DECIMAL(14,2) NOT NULL DEFAULT 0,
  meio VARCHAR(60) DEFAULT NULL,
  observacao TEXT DEFAULT NULL,
  criado_por INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fin_baixas_titulo (titulo_id),
  KEY idx_fin_baixas_data (data_pagamento),
  CONSTRAINT fk_fin_baixas_titulo FOREIGN KEY (titulo_id) REFERENCES financeiro_titulos(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS financeiro_notas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  venda_id INT NULL,
  titulo_id INT NULL,
  tipo ENUM('entrada','saida','direta') NOT NULL,
  numero VARCHAR(100) NOT NULL,
  emissao DATE NOT NULL,
  contraparte VARCHAR(255) NOT NULL,
  valor DECIMAL(14,2) NOT NULL,
  observacao TEXT DEFAULT NULL,
  criado_por INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fin_notas_emissao (emissao),
  CONSTRAINT fk_fin_notas_venda FOREIGN KEY (venda_id) REFERENCES financeiro_vendas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_fin_notas_titulo FOREIGN KEY (titulo_id) REFERENCES financeiro_titulos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS financeiro_auditoria (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  entidade VARCHAR(40) NOT NULL,
  entidade_id INT NOT NULL,
  acao VARCHAR(60) NOT NULL,
  dados JSON DEFAULT NULL,
  usuario_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fin_auditoria_entidade (entidade,entidade_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS financeiro_importacoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome_arquivo VARCHAR(255) NOT NULL,
  sha256 CHAR(64) NOT NULL,
  tipo VARCHAR(40) NOT NULL,
  linhas INT NOT NULL DEFAULT 0,
  inseridas INT NOT NULL DEFAULT 0,
  ignoradas INT NOT NULL DEFAULT 0,
  usuario_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_fin_importacao_hash_tipo (sha256,tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
