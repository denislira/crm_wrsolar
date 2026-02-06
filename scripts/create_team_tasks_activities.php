<?php
// Script para criar a tabela team_tasks_activities usando a conexão PDO de includes/config.php
require_once __DIR__ . '/../includes/config.php';
try {
    $sql = "CREATE TABLE IF NOT EXISTS team_tasks_activities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT DEFAULT NULL,
        action VARCHAR(50) NOT NULL,
        user_id INT DEFAULT NULL,
        username VARCHAR(150) DEFAULT NULL,
        details TEXT,
        equipe VARCHAR(150) DEFAULT NULL,
        titulo VARCHAR(255) DEFAULT NULL,
        responsavel VARCHAR(150) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec($sql);
    echo "team_tasks_activities table created or already exists.\n";
} catch (Exception $e) {
    echo "Error creating table: " . $e->getMessage() . "\n";
}
