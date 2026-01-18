<?php
require_once __DIR__ . '/../includes/config.php';
try {
    $count = $pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn();
    echo "teams_count={$count}\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
