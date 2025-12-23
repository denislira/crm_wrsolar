-- Script para atualizar a tabela leads com os novos campos
-- Execute este script no seu banco de dados MySQL

-- Adicionar novos campos na tabela leads
ALTER TABLE leads 
ADD COLUMN cpf_cnpj VARCHAR(20) DEFAULT NULL AFTER phone,
ADD COLUMN notes TEXT DEFAULT NULL AFTER status,
ADD COLUMN consumo_cliente DECIMAL(10,2) DEFAULT NULL AFTER notes,
ADD COLUMN estimativa_projeto_kwh DECIMAL(10,2) DEFAULT NULL AFTER consumo_cliente,
ADD COLUMN anexos LONGBLOB DEFAULT NULL AFTER estimativa_projeto_kwh,
ADD COLUMN anexos_filename VARCHAR(255) DEFAULT NULL AFTER anexos,
ADD COLUMN anexos_mimetype VARCHAR(100) DEFAULT NULL AFTER anexos_filename;

-- Criar tabela para configuração das colunas do funil
CREATE TABLE IF NOT EXISTS funil_stages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  stage_name VARCHAR(100) NOT NULL,
  stage_order INT DEFAULT 0,
  stage_color VARCHAR(7) DEFAULT '#6c757d',
  is_active BOOLEAN DEFAULT TRUE,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY unique_user_stage (user_id, stage_name)
);