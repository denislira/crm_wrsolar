<?php

function wrcrm_db_settings_ensure(PDO $pdo): void
{
    // Estrutura criada exclusivamente pelas migrations manuais.
}

function wrcrm_db_setting_get(PDO $pdo, string $key): ?array
{
    wrcrm_db_settings_ensure($pdo);
    $stmt = $pdo->prepare("SELECT setting_value, is_secret, updated_by, updated_at FROM crm_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    $decoded = json_decode((string)$row['setting_value'], true);
    return [
        'value' => is_array($decoded) ? $decoded : $row['setting_value'],
        'is_secret' => (int)($row['is_secret'] ?? 0),
        'updated_by' => $row['updated_by'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function wrcrm_db_setting_set(PDO $pdo, string $key, $value, bool $isSecret = false, ?int $userId = null): bool
{
    wrcrm_db_settings_ensure($pdo);
    $encoded = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $stmt = $pdo->prepare("
        INSERT INTO crm_settings (setting_key, setting_value, is_secret, updated_by)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            is_secret = VALUES(is_secret),
            updated_by = VALUES(updated_by),
            updated_at = CURRENT_TIMESTAMP
    ");
    return $stmt->execute([$key, $encoded, $isSecret ? 1 : 0, $userId]);
}
