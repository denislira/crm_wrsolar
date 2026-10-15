-- Executar uma vez no banco usado pelo WRCRM.
ALTER TABLE leads
  ADD COLUMN IF NOT EXISTS whatsapp_profile_image VARCHAR(255) DEFAULT NULL;
