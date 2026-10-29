<?php
declare(strict_types=1);

function ensureProjectKitDetailsColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $checked = true;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM projetos LIKE 'kit_details'");
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec('ALTER TABLE projetos ADD COLUMN kit_details TEXT DEFAULT NULL AFTER projeto');
        }
    } catch (Throwable $e) {
        // The page may continue when the column already exists or schema changes are unavailable.
    }
}
