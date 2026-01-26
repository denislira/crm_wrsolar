<?php
header('Content-Type: text/plain');
$secretFile = __DIR__ . '/migrations.secret';
$token = $_GET['token'] ?? null;
if (!file_exists($secretFile)) { echo "migrations.secret not found. Copy migrations.secret.sample and set a secret token.\n"; exit; }
$expected = trim(file_get_contents($secretFile));
if (!$expected || $token !== $expected) { http_response_code(403); echo "Forbidden: invalid token\n"; exit; }
require_once __DIR__ . '/../../includes/config.php';
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS lead_movements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NOT NULL,
        user_id INT NOT NULL,
        from_stage_id INT NULL,
        to_stage_id INT NULL,
        from_status VARCHAR(255) NULL,
        to_status VARCHAR(255) NULL,
        changed_by INT NULL,
        note TEXT NULL,
        is_alert TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (lead_id), INDEX (user_id), INDEX (from_stage_id), INDEX (to_stage_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "lead_movements table ensured\n";
} catch (Exception $e) { echo "lead_movements error: " . $e->getMessage() . "\n"; }

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS leads_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NOT NULL,
        user_id INT NOT NULL,
        filename VARCHAR(255) NULL,
        mimetype VARCHAR(255) NULL,
        data LONGBLOB NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (lead_id), INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "leads_attachments table ensured\n";
} catch (Exception $e) { echo "leads_attachments error: " . $e->getMessage() . "\n"; }

echo "done\n";
