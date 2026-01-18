<?php
require_once __DIR__ . '/../includes/config.php';
try {
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('team_id','role_level')");
    $stmt->execute([':db' => $db]);
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Found columns: " . implode(',', $cols) . "\n";
    if (!in_array('team_id', $cols)) echo "team_id missing\n";
    if (!in_array('role_level', $cols)) echo "role_level missing\n";
} catch (Exception $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
