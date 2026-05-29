INSERT INTO role_permissions (role_id, screen, allowed)
SELECT r.id, 'consultoria_externa', 1
FROM roles r
WHERE NOT EXISTS (
    SELECT 1
    FROM role_permissions rp
    WHERE rp.role_id = r.id
      AND rp.screen = 'consultoria_externa'
);