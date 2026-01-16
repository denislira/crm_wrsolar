<?php
// Migration: add team_id and role_level to users table (nullable by default)
require_once __DIR__ . '/../includes/config.php';
try {
    // Add team_id column (nullable)
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS team_id INT DEFAULT NULL;");
    // Add role_level column (integer, default 0: regular user)
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS role_level TINYINT DEFAULT 0;");
    echo "Altered users: added team_id (nullable) and role_level (default 0).\n";
    exit(0);
} catch (Exception $e) {
    echo "Erro ao alterar users: " . $e->getMessage() . "\n";
    exit(1);
}
