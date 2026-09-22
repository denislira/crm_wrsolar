<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Execute via CLI.'); }
require_once __DIR__ . '/../includes/config.php';

$columns = [
    'filepath' => 'VARCHAR(1024) DEFAULT NULL',
    'file_size' => 'BIGINT UNSIGNED DEFAULT NULL',
];
foreach ($columns as $name => $definition) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads_attachments' AND COLUMN_NAME = ?");
    $q->execute([$name]);
    if ((int)$q->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE leads_attachments ADD COLUMN `{$name}` {$definition}");
        echo "Criado: {$name}\n";
    } else echo "Já existe: {$name}\n";
}
