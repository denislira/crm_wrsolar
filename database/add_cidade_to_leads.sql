-- Migration: adicionar coluna 'cidade' na tabela leads
-- Execute: mysql -u <user> -p < database_name < add_cidade_to_leads.sql

ALTER TABLE leads
  ADD COLUMN cidade VARCHAR(255) DEFAULT NULL AFTER name;

-- Opcional: verificar se a coluna foi adicionada
-- DESCRIBE leads;
