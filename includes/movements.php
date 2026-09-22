<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function ensure_movement_tables(PDO $pdo): void
{
    // Estrutura criada exclusivamente pelas migrations manuais.
}

function log_project_movement(PDO $pdo, int $projectId, int $userId, string $action, ?string $fromStatus = null, ?string $toStatus = null, ?string $fromClientStatus = null, ?string $toClientStatus = null, ?string $note = null): void
{
    try {
        ensure_movement_tables($pdo);
        $stmt = $pdo->prepare('INSERT INTO project_movements (project_id, user_id, action, from_status, to_status, from_client_status, to_client_status, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $projectId,
            $userId,
            $action,
            $fromStatus,
            $toStatus,
            $fromClientStatus,
            $toClientStatus,
            $note,
        ]);
    } catch (Exception $e) {
        @file_put_contents(__DIR__ . '/../logs/movements.log', '[' . date('Y-m-d H:i:s') . "] log_project_movement failed: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    }
}

function log_pos_venda_movement(PDO $pdo, int $posVendaId, ?int $projectId, int $userId, string $action, ?string $fromStage = null, ?string $toStage = null, ?string $note = null): void
{
    try {
        ensure_movement_tables($pdo);
        $stmt = $pdo->prepare('INSERT INTO pos_venda_movements (pos_venda_id, project_id, user_id, action, from_stage, to_stage, note) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $posVendaId,
            $projectId,
            $userId,
            $action,
            $fromStage,
            $toStage,
            $note,
        ]);
    } catch (Exception $e) {
        @file_put_contents(__DIR__ . '/../logs/movements.log', '[' . date('Y-m-d H:i:s') . "] log_pos_venda_movement failed: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    }
}
