<?php
// seeds default lead statuses into `lead_statuses` table (global entries user_id = NULL)
require_once __DIR__ . '/../includes/config.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS lead_statuses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        name VARCHAR(255) NOT NULL,
        position INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $defaults = [
        'Novo',
        'Em Contato',
        'Qualificado',
        'Proposta',
        'Negociação',
        'Ganho',
        'Perdido'
    ];

    // compute starting position (max position among global entries)
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) AS mx FROM lead_statuses WHERE user_id IS NULL');
    $stmt->execute(); $mx = (int)$stmt->fetchColumn();

    $ins = $pdo->prepare('INSERT INTO lead_statuses (user_id, name, position) VALUES (NULL, ?, ?)');
    $check = $pdo->prepare('SELECT COUNT(*) FROM lead_statuses WHERE (user_id IS NULL) AND name = ?');
    foreach ($defaults as $i => $name) {
        $check->execute([$name]);
        $exists = (int)$check->fetchColumn();
        if ($exists) continue;
        $mx++; $ins->execute([$name, $mx]);
        echo "Inserted status: $name\n";
    }

    echo "Seeding completed.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
