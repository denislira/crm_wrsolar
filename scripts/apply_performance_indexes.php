<?php
// Execute via CLI or browser autenticado para aplicar os índices sem falhar
// caso uma instalação já possua algum deles.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execute este script via CLI.');
}
require_once __DIR__ . '/../includes/config.php';

$indexes = [
    ['leads', 'idx_leads_deleted_created', '(`deleted`, `created_at`)'],
    ['leads', 'idx_leads_data_inicio', '(`data_inicio`)'],
    ['leads', 'idx_leads_stage', '(`stage_id`)'],
    ['leads', 'idx_leads_source', '(`source`)'],
    ['leads', 'idx_leads_deleted_data_inicio', '(`deleted`, `data_inicio`)'],
    ['leads', 'idx_leads_source_data_inicio', '(`source`, `data_inicio`)'],
    ['leads', 'idx_leads_stage_data_inicio', '(`stage_id`, `data_inicio`)'],
    ['activity_log', 'idx_activity_log_created', '(`created_at`)'],
    ['lead_movements', 'idx_lead_movements_created_lead', '(`created_at`, `lead_id`)'],
    ['team_tasks', 'idx_team_tasks_created_responsavel', '(`created_at`, `responsavel_id`)'],
    ['leads_attachments', 'idx_leads_attachments_lead', '(`lead_id`)'],
    ['projetos', 'idx_projetos_lead', '(`lead_id`)'],
];

foreach ($indexes as [$table, $name, $columns]) {
    preg_match_all('/`([^`]+)`/', $columns, $columnMatches);
    $columnNames = array_values(array_unique($columnMatches[1] ?? []));
    if (empty($columnNames)) {
        echo "Ignorado (definicao invalida): {$table}.{$name}\n";
        continue;
    }
    $columnPlaceholders = implode(',', array_fill(0, count($columnNames), '?'));
    $columnCheck = $pdo->prepare(
        "SELECT COUNT(DISTINCT COLUMN_NAME) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN ({$columnPlaceholders})"
    );
    $columnCheck->execute(array_merge([$table], $columnNames));
    if ((int)$columnCheck->fetchColumn() !== count($columnNames)) {
        echo "Ignorado (coluna ausente): {$table}.{$name}\n";
        continue;
    }
    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $check->execute([$table, $name]);
    if ((int)$check->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$name}` {$columns}");
        echo "Criado: {$table}.{$name}\n";
    } else {
        echo "Já existe: {$table}.{$name}\n";
    }
}
