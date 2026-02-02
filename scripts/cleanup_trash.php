<?php
// cleanup_trash.php - Permanently delete leads that have been in trash for more than 30 days
// Run this script periodically, e.g., via cron job

require __DIR__ . '/../includes/config.php';

if (php_sapi_name() !== 'cli') {
    echo "Run from CLI: php scripts/cleanup_trash.php\n";
    exit;
}

try {
    // Delete leads where deleted=1 and deleted_at < 30 days ago
    $stmt = $pdo->prepare("DELETE FROM leads WHERE deleted = 1 AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    $deletedCount = $stmt->rowCount();
    echo "Deleted $deletedCount trashed leads older than 30 days.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>