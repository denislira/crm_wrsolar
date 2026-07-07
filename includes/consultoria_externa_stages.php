<?php

function ce_stage_defaults(): array {
    return [
        ['Captacao Tecnica', 1, '#3b82f6', '#ffffff', 'fa-house-signal', 1, 0],
        ['Aguardando Orcamento', 2, '#f59e0b', '#ffffff', 'fa-file-invoice-dollar', 0, 0],
        ['Processo Bancario', 3, '#8b5cf6', '#ffffff', 'fa-building-columns', 0, 0],
        ['Contrato Gerado', 4, '#10b981', '#ffffff', 'fa-file-signature', 0, 0],
    ];
}

function ce_stage_owner_id(): int {
    return 0;
}

function ce_ensure_stage_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS consultoria_externa_itens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        client_name VARCHAR(255) NOT NULL,
        phone VARCHAR(50) DEFAULT NULL,
        cidade VARCHAR(255) DEFAULT NULL,
        source VARCHAR(255) DEFAULT NULL,
        status VARCHAR(100) DEFAULT NULL,
        value DECIMAL(12,2) DEFAULT 0.00,
        notes TEXT DEFAULT NULL,
        stage_key VARCHAR(50) DEFAULT 'captacao_tecnica',
        stage_id INT DEFAULT NULL,
        exported_to_internal_queue TINYINT(1) NOT NULL DEFAULT 0,
        exported_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted TINYINT(1) NOT NULL DEFAULT 0,
        deleted_at DATETIME DEFAULT NULL,
        INDEX idx_ce_user (user_id),
        INDEX idx_ce_stage_key (stage_key),
        INDEX idx_ce_stage_id (stage_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS consultoria_externa_stages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        position INT NOT NULL DEFAULT 0,
        color VARCHAR(7) DEFAULT '#6c757d',
        card_color VARCHAR(7) DEFAULT '#ffffff',
        icon VARCHAR(50) DEFAULT 'fa-layer-group',
        is_initial TINYINT(1) NOT NULL DEFAULT 0,
        export_to_internal_queue TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_ce_stage_user (user_id),
        INDEX idx_ce_stage_position (position)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS consultoria_interna_demandas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        external_item_id INT NOT NULL,
        external_stage_id INT DEFAULT NULL,
        external_user_id INT NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'pending',
        accepted_by INT DEFAULT NULL,
        accepted_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ce_external_item (external_item_id),
        INDEX idx_ce_demand_stage (external_stage_id),
        INDEX idx_ce_demand_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS consultoria_interna_demandas_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        demand_id INT NOT NULL,
        user_id INT NOT NULL,
        filename VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        mimetype VARCHAR(120) DEFAULT NULL,
        file_size INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ce_demand_attachment_demand (demand_id),
        INDEX idx_ce_demand_attachment_user (user_id),
        FOREIGN KEY (demand_id) REFERENCES consultoria_interna_demandas(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $demandCols = [];
    try {
        $demandCols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultoria_interna_demandas'")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $demandCols = [];
    }

    $legacyDemandCols = [
        'client_name' => 'VARCHAR(255) DEFAULT NULL',
        'phone' => 'VARCHAR(50) DEFAULT NULL',
        'cidade' => 'VARCHAR(255) DEFAULT NULL',
        'source' => 'VARCHAR(255) DEFAULT NULL',
        'value' => 'DECIMAL(12,2) DEFAULT 0.00',
        'notes' => 'TEXT DEFAULT NULL',
    ];
    foreach ($legacyDemandCols as $colName => $definition) {
        if (in_array($colName, $demandCols, true)) {
            try {
                $pdo->exec("ALTER TABLE consultoria_interna_demandas MODIFY COLUMN {$colName} {$definition}");
            } catch (Throwable $e) {
                // Existing deployments may keep legacy columns; the queue no longer depends on them.
            }
        }
    }

    $itemCols = [];
    try {
        $itemCols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultoria_externa_itens'")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $itemCols = [];
    }

    if ($itemCols && !in_array('stage_id', $itemCols, true)) {
        $pdo->exec("ALTER TABLE consultoria_externa_itens ADD COLUMN stage_id INT DEFAULT NULL AFTER stage_key");
    }
    if ($itemCols && !in_array('exported_to_internal_queue', $itemCols, true)) {
        $pdo->exec("ALTER TABLE consultoria_externa_itens ADD COLUMN exported_to_internal_queue TINYINT(1) NOT NULL DEFAULT 0 AFTER stage_id");
    }
    if ($itemCols && !in_array('exported_at', $itemCols, true)) {
        $pdo->exec("ALTER TABLE consultoria_externa_itens ADD COLUMN exported_at DATETIME DEFAULT NULL AFTER exported_to_internal_queue");
    }
}

function ce_seed_default_stages(PDO $pdo, ?int $userId = null): void {
    $ownerId = $userId ?? ce_stage_owner_id();
    $stmt = $pdo->query('SELECT COUNT(*) FROM consultoria_externa_stages');
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $insert = $pdo->prepare('INSERT INTO consultoria_externa_stages (user_id, name, position, color, card_color, icon, is_initial, export_to_internal_queue) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach (ce_stage_defaults() as $stage) {
        $insert->execute([$ownerId, $stage[0], $stage[1], $stage[2], $stage[3], $stage[4], $stage[5], $stage[6]]);
    }
}

function ce_list_stages(PDO $pdo, ?int $userId = null): array {
    ce_ensure_stage_tables($pdo);
    ce_seed_default_stages($pdo, ce_stage_owner_id());
    $stmt = $pdo->query('SELECT id, name, position, color, card_color, icon, is_initial, export_to_internal_queue FROM consultoria_externa_stages ORDER BY position ASC, id ASC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ce_initial_stage_id(PDO $pdo, ?int $userId = null): ?int {
    $stmt = $pdo->query('SELECT id FROM consultoria_externa_stages ORDER BY is_initial DESC, position ASC, id ASC LIMIT 1');
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

function ce_legacy_stage_key_to_position(?string $stageKey): int {
    $map = [
        'captacao_tecnica' => 1,
        'aguardando_orcamento' => 2,
        'processo_bancario' => 3,
        'contrato_gerado' => 4,
    ];
    return $map[(string) $stageKey] ?? 1;
}

function ce_resolve_stage_id(PDO $pdo, ?int $userId, $stageId, ?string $stageKey = null): ?int {
    if ((int) $stageId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM consultoria_externa_stages WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $stageId]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
    }

    $position = ce_legacy_stage_key_to_position($stageKey);
    $stmt = $pdo->prepare('SELECT id FROM consultoria_externa_stages ORDER BY ABS(position - ?) ASC, position ASC, id ASC LIMIT 1');
    $stmt->execute([$position]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : ce_initial_stage_id($pdo, ce_stage_owner_id());
}

function ce_resolve_global_stage_id(PDO $pdo, $stageId): ?int {
    if ((int) $stageId <= 0) {
        return ce_initial_stage_id($pdo, ce_stage_owner_id());
    }

    $stmt = $pdo->prepare('SELECT id FROM consultoria_externa_stages WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $stageId]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : ce_initial_stage_id($pdo, ce_stage_owner_id());
}

function ce_export_item_if_needed(PDO $pdo, int $itemId, int $userId): void {
    $stmt = $pdo->prepare('SELECT c.*, s.export_to_internal_queue FROM consultoria_externa_itens c LEFT JOIN consultoria_externa_stages s ON s.id = c.stage_id WHERE c.id = ? AND c.user_id = ? LIMIT 1');
    $stmt->execute([$itemId, $userId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item || (int)($item['export_to_internal_queue'] ?? 0) !== 1 || (int)($item['exported_to_internal_queue'] ?? 0) === 1) {
        return;
    }

    $insert = $pdo->prepare('INSERT INTO consultoria_interna_demandas (external_item_id, external_stage_id, external_user_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE external_stage_id = VALUES(external_stage_id), external_user_id = VALUES(external_user_id), updated_at = NOW()');
    $insert->execute([
        (int)$item['id'],
        $item['stage_id'] ? (int)$item['stage_id'] : null,
        $userId,
    ]);

    $update = $pdo->prepare('UPDATE consultoria_externa_itens SET exported_to_internal_queue = 1, exported_at = NOW() WHERE id = ? AND user_id = ?');
    $update->execute([$itemId, $userId]);
}
