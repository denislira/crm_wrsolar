<?php
// Migration: add team_id and role_level to users table (nullable by default)
require_once __DIR__ . '/../includes/config.php';
try {
    // Helper to check column existence
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'users' AND COLUMN_NAME = :col");

    // team_id
    $checkStmt->execute([':db' => $dbname, ':col' => 'team_id']);
    $hasTeamId = (int)$checkStmt->fetchColumn() > 0;
    if (!$hasTeamId) {
        $pdo->exec("ALTER TABLE users ADD COLUMN team_id INT DEFAULT NULL;");
        echo "Added column team_id to users.\n";
    } else {
        echo "Column team_id already exists.\n";
    }

    // role_level
    $checkStmt->execute([':db' => $dbname, ':col' => 'role_level']);
    $hasRole = (int)$checkStmt->fetchColumn() > 0;
    if (!$hasRole) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role_level TINYINT DEFAULT 0;");
        echo "Added column role_level to users.\n";
    } else {
        echo "Column role_level already exists.\n";
    }

    echo "Migration completed.\n";
    exit(0);
} catch (Exception $e) {
    echo "Erro ao alterar users: " . $e->getMessage() . "\n";
    exit(1);
}
