-- Add `nome_completo` and `biografia` columns to `users` table if they don't exist
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS nome_completo VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS biografia TEXT NULL;

-- Run with: mysql -u root -p crm < database/add_nome_completo_biografia_to_users.sql
