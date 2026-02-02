-- Migration to add trash functionality to leads table
-- Adds deleted flag and deleted_at timestamp

ALTER TABLE leads
ADD COLUMN deleted BOOLEAN DEFAULT FALSE AFTER updated_at,
ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER deleted;

-- Index for performance on deleted queries
CREATE INDEX idx_leads_deleted ON leads (deleted);
CREATE INDEX idx_leads_deleted_at ON leads (deleted_at);