<?php

if (!function_exists('runProjectPostSaleAutomation')) {
    function runProjectPostSaleAutomation(PDO $pdo, int $userId): int
    {
        try {
            $hasPosVenda = (bool) $pdo->query("SHOW TABLES LIKE 'pos_venda'")->fetchColumn();
            $hasProjetos = (bool) $pdo->query("SHOW TABLES LIKE 'projetos'")->fetchColumn();
            $hasStages = (bool) $pdo->query("SHOW TABLES LIKE 'projeto_stages'")->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }

        if (!$hasPosVenda || !$hasProjetos || !$hasStages) {
            return 0;
        }

        // Verify new columns exist before using them
        try {
            $colCheck = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projeto_stages'");
            $colCheck->execute();
            $existingCols = $colCheck->fetchAll(PDO::FETCH_COLUMN);
            
            $hasPostSaleEnabled = in_array('post_sale_enabled', $existingCols, true);
            $hasPostSaleDays = in_array('post_sale_days', $existingCols, true);
            
            // If columns don't exist, create them
            if (!$hasPostSaleEnabled) {
                $pdo->exec("ALTER TABLE projeto_stages ADD COLUMN post_sale_enabled TINYINT(1) NOT NULL DEFAULT 0");
            }
            if (!$hasPostSaleDays) {
                $pdo->exec("ALTER TABLE projeto_stages ADD COLUMN post_sale_days INT NOT NULL DEFAULT 90");
            }
        } catch (Exception $e) {
            return 0;
        }

        $stmt = $pdo->prepare(
            'SELECT p.id, p.client_name, p.status, p.closed_date, p.created_at,
                    ps.post_sale_enabled, ps.post_sale_days
             FROM projetos p
             JOIN projeto_stages ps ON ps.name COLLATE utf8mb4_unicode_ci = p.status COLLATE utf8mb4_unicode_ci
             LEFT JOIN pos_venda pv ON pv.project_id = p.id
             WHERE p.user_id = ?
               AND pv.id IS NULL
               AND COALESCE(ps.post_sale_enabled, 0) = 1'
        );
        $stmt->execute([$userId]);
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$projects) {
            return 0;
        }

        $insert = $pdo->prepare(
            'INSERT INTO pos_venda (user_id, project_id, client_name, installation_date, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
        );

        $migrated = 0;
        $now = new DateTimeImmutable('now');

        foreach ($projects as $project) {
            $daysThreshold = max(1, (int) ($project['post_sale_days'] ?? 90));

            $baseDateRaw = $project['closed_date'] ?: $project['created_at'];
            if (empty($baseDateRaw)) {
                continue;
            }

            try {
                $baseDate = new DateTimeImmutable((string) $baseDateRaw);
            } catch (Exception $e) {
                continue;
            }

            $elapsedDays = (int) $baseDate->diff($now)->format('%r%a');
            if ($elapsedDays < $daysThreshold) {
                continue;
            }

            $installationDate = $baseDate->format('Y-m-d');
            $notes = sprintf(
                'Migrado automaticamente de Projetos para Pós-venda após %d dias.',
                $daysThreshold
            );

            try {
                $insert->execute([
                    $userId,
                    (int) $project['id'],
                    (string) ($project['client_name'] ?? ''),
                    $installationDate,
                    $notes,
                ]);
                $migrated++;
            } catch (Exception $e) {
                continue;
            }
        }

        return $migrated;
    }
}