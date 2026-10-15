<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/financeiro_crm_sync.php';

$sql = "SELECT l.id
        FROM leads l
        INNER JOIN funil_stages fs ON fs.id = l.stage_id
        WHERE COALESCE(l.deleted, 0) = 0
          AND (COALESCE(fs.is_conversion, 0) = 1 OR COALESCE(fs.final_type, 'none') = 'won')";

$leadIds = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
foreach ($leadIds as $leadId) {
    financeiro_sincronizar_lead_ganho($pdo, (int) $leadId);
}

echo 'Entradas sincronizadas: ' . count($leadIds) . PHP_EOL;
