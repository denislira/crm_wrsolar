<?php
declare(strict_types=1);

function wa_messages_ensure_table(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_messages (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        phone_normalized VARCHAR(40) NOT NULL,
        message_id VARCHAR(255) DEFAULT NULL,
        direction ENUM('in','out') NOT NULL,
        message_text TEXT NOT NULL,
        media_kind VARCHAR(20) DEFAULT NULL,
        media_mime VARCHAR(100) DEFAULT NULL,
        media_path VARCHAR(500) DEFAULT NULL,
        media_filename VARCHAR(255) DEFAULT NULL,
        sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY ux_whatsapp_message (phone_normalized, message_id),
        KEY idx_whatsapp_phone_date (phone_normalized, sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        'media_kind' => 'VARCHAR(20) DEFAULT NULL',
        'media_mime' => 'VARCHAR(100) DEFAULT NULL',
        'media_path' => 'VARCHAR(500) DEFAULT NULL',
        'media_filename' => 'VARCHAR(255) DEFAULT NULL',
    ] as $column => $definition) {
        $stmt = $pdo->query("SHOW COLUMNS FROM whatsapp_messages LIKE '{$column}'");
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE whatsapp_messages ADD COLUMN {$column} {$definition}");
        }
    }

    $checked = true;
}
