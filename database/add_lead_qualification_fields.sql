-- Migration: add qualification, lost reason, Speed-to-Lead and kWp fields to leads table
-- Run once against the `crm` database.

ALTER TABLE leads
  ADD COLUMN IF NOT EXISTS disqualification_reason VARCHAR(255) DEFAULT NULL COMMENT 'Motivo da desqualificação',
  ADD COLUMN IF NOT EXISTS lost_reason VARCHAR(255) DEFAULT NULL COMMENT 'Motivo de perda no fechamento',
  ADD COLUMN IF NOT EXISTS is_sql TINYINT(1) DEFAULT 0 COMMENT '1 = Sales Qualified Lead',
  ADD COLUMN IF NOT EXISTS first_contact_at DATETIME DEFAULT NULL COMMENT 'Data/hora do primeiro contato',
  ADD COLUMN IF NOT EXISTS kwp DECIMAL(8,2) DEFAULT NULL COMMENT 'Tamanho do sistema em kWp',
  ADD COLUMN IF NOT EXISTS payment_type ENUM('avista','financiado','') DEFAULT '' COMMENT 'Forma de pagamento: à vista ou financiamento';

-- Index for Speed-to-Lead queries
CREATE INDEX IF NOT EXISTS idx_leads_first_contact ON leads (first_contact_at);
CREATE INDEX IF NOT EXISTS idx_leads_is_sql ON leads (is_sql);
