-- Adiciona metadados para armazenar novos anexos fora do banco.
ALTER TABLE leads_attachments ADD COLUMN filepath VARCHAR(1024) DEFAULT NULL;
ALTER TABLE leads_attachments ADD COLUMN file_size BIGINT UNSIGNED DEFAULT NULL;
