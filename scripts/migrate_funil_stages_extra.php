<?php
require __DIR__ . '/../includes/config.php';
if (php_sapi_name() !== 'cli') { echo "Run from CLI: php scripts/migrate_funil_stages_extra.php\n"; exit; }
$cols = [
    'card_color' => "VARCHAR(32) DEFAULT NULL",
    'icon' => "VARCHAR(64) DEFAULT NULL",
    'is_final' => "TINYINT(1) DEFAULT 0",
    'final_type' => "ENUM('none','won','lost') DEFAULT 'none'",
    'generate_task_on_enter' => "TINYINT(1) DEFAULT 0",
    'alert_on_inactivity' => "TINYINT(1) DEFAULT 0",
    'required_fields' => "TEXT DEFAULT NULL",
    'sla_days' => "INT DEFAULT NULL",
    'block_advance' => "TINYINT(1) DEFAULT 0",
    'include_in_forecast' => "TINYINT(1) DEFAULT 1",
    'is_qualification' => "TINYINT(1) DEFAULT 0",
    'is_conversion' => "TINYINT(1) DEFAULT 0",
    'track_time_in_stage' => "TINYINT(1) DEFAULT 0"
];
try {
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funil_stages'");
    $stmt->execute(); $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cols as $name => $def) {
        if (!in_array($name, $existing)) {
            echo "Adding column: $name...\n";
            $pdo->exec("ALTER TABLE funil_stages ADD COLUMN $name $def");
        } else {
            echo "Column exists: $name\n";
        }
    }
    // create history table if missing
    $stmt2 = $pdo->prepare("SHOW TABLES LIKE 'funil_stages_history'"); $stmt2->execute(); if ($stmt2->rowCount() === 0) {
        echo "Creating table funil_stages_history...\n";
        $pdo->exec("CREATE TABLE funil_stages_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            stage_id INT NULL,
            user_id INT NULL,
            action VARCHAR(50) NOT NULL,
            changes JSON DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (stage_id), INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } else {
        echo "Table funil_stages_history already exists\n";
    }
    echo "Done.\n";
} catch(Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
