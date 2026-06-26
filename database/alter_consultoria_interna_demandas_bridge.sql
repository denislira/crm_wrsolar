-- Run this only if consultoria_interna_demandas was created with copied
-- customer fields from the older version. The queue now stores only links
-- and reads customer data from consultoria_externa_itens.

ALTER TABLE consultoria_interna_demandas
  MODIFY COLUMN client_name VARCHAR(255) DEFAULT NULL,
  MODIFY COLUMN phone VARCHAR(50) DEFAULT NULL,
  MODIFY COLUMN cidade VARCHAR(255) DEFAULT NULL,
  MODIFY COLUMN source VARCHAR(255) DEFAULT NULL,
  MODIFY COLUMN value DECIMAL(12,2) DEFAULT 0.00,
  MODIFY COLUMN notes TEXT DEFAULT NULL;
