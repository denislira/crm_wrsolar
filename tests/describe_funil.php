<?php
require __DIR__ . '/../includes/config.php';
try {
    $stmt = $pdo->query("DESCRIBE funil_stages");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "funil_stages structure:\n";
    foreach($rows as $r) {
        echo $r['Field'] . " - " . $r['Type'] . " - " . $r['Null'] . " - " . $r['Default'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>