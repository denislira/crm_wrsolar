ALTER TABLE pos_venda_stages
  ADD COLUMN sla_renewal_target TINYINT(1) NOT NULL DEFAULT 0 AFTER card_color;