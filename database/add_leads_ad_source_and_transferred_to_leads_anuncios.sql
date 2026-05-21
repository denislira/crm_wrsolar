-- Migration: add ad-origin flag to leads and transfer marker to leads_anuncios

ALTER TABLE leads
  ADD COLUMN is_ad_source TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE leads_anuncios
  ADD COLUMN transferred_to_kanban TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN promoted_at DATETIME NULL,
  ADD COLUMN lead_id INT NULL;
