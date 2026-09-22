-- Índices para os fluxos de gestão de leads e relatórios.
-- Execute uma vez no banco de produção. Os comandos são idempotentes via scripts/apply_performance_indexes.php.
ALTER TABLE leads ADD INDEX idx_leads_deleted_created (deleted, created_at);
ALTER TABLE leads ADD INDEX idx_leads_data_inicio (data_inicio);
ALTER TABLE leads ADD INDEX idx_leads_stage (stage_id);
ALTER TABLE leads ADD INDEX idx_leads_source (source);
ALTER TABLE leads ADD INDEX idx_leads_deleted_data_inicio (deleted, data_inicio);
ALTER TABLE leads ADD INDEX idx_leads_source_data_inicio (source, data_inicio);
ALTER TABLE leads ADD INDEX idx_leads_stage_data_inicio (stage_id, data_inicio);
ALTER TABLE activity_log ADD INDEX idx_activity_log_created (created_at);
ALTER TABLE lead_movements ADD INDEX idx_lead_movements_created_lead (created_at, lead_id);
ALTER TABLE team_tasks ADD INDEX idx_team_tasks_created_responsavel (created_at, responsavel_id);
ALTER TABLE leads_attachments ADD INDEX idx_leads_attachments_lead (lead_id);
ALTER TABLE projetos ADD INDEX idx_projetos_lead (lead_id);
