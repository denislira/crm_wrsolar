<?php

require_once __DIR__ . '/movements.php';

if (!function_exists('runProjectPostSaleAutomation')) {
    function runProjectPostSaleAutomation(PDO $pdo, int $userId): int
    {
        $stageNameCol = 'name';

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

            $stageNameCol = in_array('name', $existingCols, true)
                ? 'name'
                : (in_array('stage_name', $existingCols, true) ? 'stage_name' : 'name');
            
            $hasPostSaleEnabled = in_array('post_sale_enabled', $existingCols, true);
            $hasPostSaleTargetStage = in_array('post_sale_target_stage_id', $existingCols, true);

            // If columns don't exist, create them
            if (!$hasPostSaleEnabled) {
                $pdo->exec("ALTER TABLE projeto_stages ADD COLUMN post_sale_enabled TINYINT(1) NOT NULL DEFAULT 0");
            }
            if (!$hasPostSaleTargetStage) {
                $pdo->exec("ALTER TABLE projeto_stages ADD COLUMN post_sale_target_stage_id INT DEFAULT NULL");
            }

            $projectColCheck = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projetos' AND COLUMN_NAME = 'status_changed_at'");
            $projectColCheck->execute();
            if (!$projectColCheck->fetchColumn()) {
                $pdo->exec("ALTER TABLE projetos ADD COLUMN status_changed_at DATETIME DEFAULT NULL AFTER status");
                $pdo->exec("UPDATE projetos SET status_changed_at = COALESCE(updated_at, created_at) WHERE status_changed_at IS NULL");
            }

            $dueDaysColCheck = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projetos' AND COLUMN_NAME = 'due_days'");
            $dueDaysColCheck->execute();
            if (!$dueDaysColCheck->fetchColumn()) {
                $pdo->exec("ALTER TABLE projetos ADD COLUMN due_days INT DEFAULT 30 AFTER closed_date");
            }

            $movedFlagColCheck = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projetos' AND COLUMN_NAME = 'moved_to_post_sale'");
            $movedFlagColCheck->execute();
            if (!$movedFlagColCheck->fetchColumn()) {
                $pdo->exec("ALTER TABLE projetos ADD COLUMN moved_to_post_sale TINYINT(1) NOT NULL DEFAULT 0 AFTER status_changed_at");
            }
        } catch (Exception $e) {
            return 0;
        }

        $stmt = $pdo->prepare(
                          "SELECT p.id, p.client_name, p.status, p.status_changed_at, p.closed_date, p.created_at, p.updated_at, p.due_days, p.contract,
                          ps.post_sale_enabled,
                          ps.post_sale_target_stage_id,
                          pvst.name AS post_sale_target_stage_name
             FROM projetos p
                  JOIN (
                      SELECT
                          TRIM({$stageNameCol}) COLLATE utf8mb4_unicode_ci AS stage_name,
                          MAX(COALESCE(post_sale_enabled, 0)) AS post_sale_enabled,
                          COALESCE(
                              MAX(CASE WHEN COALESCE(post_sale_enabled, 0) = 1 THEN post_sale_target_stage_id END),
                              MAX(post_sale_target_stage_id)
                          ) AS post_sale_target_stage_id
                      FROM projeto_stages
                      GROUP BY TRIM({$stageNameCol}) COLLATE utf8mb4_unicode_ci
                  ) ps ON ps.stage_name = TRIM(p.status) COLLATE utf8mb4_unicode_ci
                  LEFT JOIN pos_venda_stages pvst ON pvst.id = ps.post_sale_target_stage_id
             LEFT JOIN pos_venda pv ON pv.project_id = p.id
             WHERE p.user_id = ?
               AND pv.id IS NULL
               AND COALESCE(ps.post_sale_enabled, 0) = 1"
        );
        $stmt->execute([$userId]);
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$projects) {
            return 0;
        }

        $insert = $pdo->prepare(
            'INSERT INTO pos_venda (user_id, project_id, client_name, installation_date, project_notes, stage, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $markMoved = $pdo->prepare('UPDATE projetos SET moved_to_post_sale = 1, updated_at = NOW() WHERE id = ? AND user_id = ?');

        $migrated = 0;
        $now = new DateTimeImmutable('now');

        foreach ($projects as $project) {
            $daysThreshold = max(1, (int) ($project['due_days'] ?? 30));

            // Priority: when the project entered current column; fallback to older fields for legacy rows.
            $baseDateRaw = $project['closed_date'] ?: $project['created_at'] ?: $project['status_changed_at'] ?: $project['updated_at'];
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
            $migrationNote = sprintf(
                'Migrado automaticamente de Projetos para Pós-venda após %d dias (prazo do card).',
                $daysThreshold
            );

            $projectContract = isset($project['contract']) && trim((string)$project['contract']) !== '' ? trim((string)$project['contract']) : null;
            if ($projectContract !== null) {
                $notes = $projectContract . "\n\n" . $migrationNote;
            } else {
                $notes = $migrationNote;
            }

            try {
                $insert->execute([
                    $userId,
                    (int) $project['id'],
                    (string) ($project['client_name'] ?? ''),
                    $installationDate,
                    ($projectContract !== null ? $projectContract : $migrationNote),
                    $project['post_sale_target_stage_name'] ?: null,
                ]);
                $newPvId = (int) $pdo->lastInsertId();
                $markMoved->execute([(int) $project['id'], $userId]);
                log_project_movement(
                    $pdo,
                    (int) $project['id'],
                    $userId,
                    'moved_to_post_sale',
                    null,
                    null,
                    null,
                    null,
                    'Projeto migrado automaticamente para pós-venda.'
                );
                log_pos_venda_movement(
                    $pdo,
                    $newPvId,
                    (int) $project['id'],
                    $userId,
                    'created',
                    null,
                    $project['post_sale_target_stage_name'] ?: null,
                    'Migrado automaticamente de Projetos para Pós-venda.'
                );
                $migrated++;
            } catch (Exception $e) {
                continue;
            }
        }

        return $migrated;
    }
}