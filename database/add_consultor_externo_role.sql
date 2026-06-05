-- Add 'consultor_externo' role if it does not already exist
INSERT INTO roles (name)
SELECT 'consultor_externo'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE name = 'consultor_externo');

-- Optionally run this in your MySQL client or via phpMyAdmin:
-- mysql -u root -p crm < database/add_consultor_externo_role.sql
