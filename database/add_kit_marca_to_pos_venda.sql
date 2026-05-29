-- Add new post-sale fields: kit and marca

ALTER TABLE pos_venda
  ADD COLUMN kit VARCHAR(255) DEFAULT NULL,
  ADD COLUMN marca VARCHAR(255) DEFAULT NULL;
