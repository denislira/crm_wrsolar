-- Migration: Add delete_leads_permanent permission
-- This allows admins to control which roles can permanently delete leads from trash

-- Add delete_leads_permanent permission for Diretor (role_id=1)
INSERT INTO role_permissions (role_id, screen, allowed) 
SELECT 1, 'delete_leads_permanent', 1 
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE role_id = 1 AND screen = 'delete_leads_permanent');

-- Add delete_leads_permanent permission for other roles (disabled by default)
INSERT INTO role_permissions (role_id, screen, allowed) 
SELECT 2, 'delete_leads_permanent', 0 
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE role_id = 2 AND screen = 'delete_leads_permanent');

INSERT INTO role_permissions (role_id, screen, allowed) 
SELECT 3, 'delete_leads_permanent', 0 
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE role_id = 3 AND screen = 'delete_leads_permanent');

INSERT INTO role_permissions (role_id, screen, allowed) 
SELECT 4, 'delete_leads_permanent', 0 
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE role_id = 4 AND screen = 'delete_leads_permanent');

-- For any future roles
INSERT INTO role_permissions (role_id, screen, allowed) 
SELECT r.id, 'delete_leads_permanent', 
  CASE WHEN r.id = 1 THEN 1 ELSE 0 END
FROM roles r
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE role_id = r.id AND screen = 'delete_leads_permanent');
