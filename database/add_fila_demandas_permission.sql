INSERT INTO role_permissions (role_id, screen, allowed)
SELECT r.id, 'fila_demandas', 0
FROM roles r
WHERE NOT EXISTS (
  SELECT 1
  FROM role_permissions rp
  WHERE rp.role_id = r.id
    AND rp.screen = 'fila_demandas'
);
