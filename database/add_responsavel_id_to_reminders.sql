-- Migration: adicionar coluna responsavel_id à tabela reminders
-- Adiciona coluna, popula com created_by quando aplicável, e cria índice + foreign key

START TRANSACTION;

-- 1) adicionar coluna (se já existir, a instrução falhará; revise antes de rodar em produção)
ALTER TABLE `reminders`
  ADD COLUMN `responsavel_id` INT NULL AFTER `template_id`;

-- 2) popular a coluna com o criador quando fizer sentido (opcional)
UPDATE `reminders` SET `responsavel_id` = `created_by` WHERE `responsavel_id` IS NULL AND `created_by` IS NOT NULL;

-- 3) índice para consultas eficientes
ALTER TABLE `reminders`
  ADD INDEX `idx_reminders_responsavel_id` (`responsavel_id`);

-- 4) foreign key para integridade referencial
ALTER TABLE `reminders`
  ADD CONSTRAINT `fk_reminders_responsavel` FOREIGN KEY (`responsavel_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

COMMIT;

-- Observações:
-- - Faça backup antes de aplicar em produção.
-- - Se sua versão do MySQL não aceitar as instruções acima sem checagens, adapte removendo a foreign key temporariamente (SET FOREIGN_KEY_CHECKS=0) ou adicionando verificações.
