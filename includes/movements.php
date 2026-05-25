<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function ensure_movement_tables(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS project_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            user_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            from_status VARCHAR(100) DEFAULT NULL,
            to_status VARCHAR(100) DEFAULT NULL,
            from_client_status VARCHAR(50) DEFAULT NULL,
            to_client_status VARCHAR(50) DEFAULT NULL,
            note TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_project_movements_project (project_id),
            INDEX idx_project_movements_user (user_id),
            FOREIGN KEY (project_id) REFERENCES projetos(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS pos_venda_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pos_venda_id INT NOT NULL,
            project_id INT DEFAULT NULL,
            user_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            from_stage VARCHAR(255) DEFAULT NULL,
            to_stage VARCHAR(255) DEFAULT NULL,
            note TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pos_venda_movements_pv (pos_venda_id),
            INDEX idx_pos_venda_movements_project (project_id),
            INDEX idx_pos_venda_movements_user (user_id),
            FOREIGN KEY (pos_venda_id) REFERENCES pos_venda(id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projetos(id) ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Exception $e) {
        @file_put_contents(__DIR__ . '/../logs/movements.log', '[' . date('Y-m-d H:i:s') . "] ensure_movement_tables failed: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    }
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
