<?php

require_once __DIR__ . '/db_settings.php';

function wrcrm_default_settings(): array
{
    return [
        'primary_color' => '#0b6ac1',
        'primary_dark' => '#073b6b',
        'green' => '#4bbf4b',
        'yellow' => '#ffd24a',
        'sidebar_text_color' => '#ffffff',
        'navbar_bg_color' => '#ffffff',
    ];
}

function wrcrm_settings_path(): string
{
    return __DIR__ . '/../storage/settings.json';
}

function wrcrm_ensure_settings_file(): void
{
    $settingsPath = wrcrm_settings_path();
    $storageDir = dirname($settingsPath);

    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0755, true);
    }

    if (!file_exists($settingsPath)) {
        @file_put_contents($settingsPath, json_encode(wrcrm_default_settings(), JSON_PRETTY_PRINT));
    }
}

function wrcrm_load_settings(bool $ensureFile = true): array
{
    global $pdo;
    if ($ensureFile) {
        wrcrm_ensure_settings_file();
    }

    $settingsPath = wrcrm_settings_path();
    if (!file_exists($settingsPath)) {
        return wrcrm_default_settings();
    }

    $raw = @file_get_contents($settingsPath);
    $settings = $raw ? json_decode($raw, true) : [];
    $settings = array_merge(wrcrm_default_settings(), is_array($settings) ? $settings : []);

    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            foreach (['appearance', 'smtp', 'notifications'] as $key) {
                $db = wrcrm_db_setting_get($pdo, $key);
                if ($db && is_array($db['value'])) {
                    if ($key === 'appearance') {
                        $settings = array_merge($settings, $db['value']);
                    } else {
                        $settings[$key] = $db['value'];
                    }
                    continue;
                }

                if ($key === 'appearance') {
                    $appearance = [];
                    foreach (array_keys(wrcrm_default_settings()) as $appearanceKey) {
                        if (array_key_exists($appearanceKey, $settings)) $appearance[$appearanceKey] = $settings[$appearanceKey];
                    }
                    foreach (['logo', 'logo_collapsed', 'login_background'] as $assetKey) {
                        if (array_key_exists($assetKey, $settings)) $appearance[$assetKey] = $settings[$assetKey];
                    }
                    wrcrm_db_setting_set($pdo, 'appearance', $appearance, false, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
                } elseif (isset($settings[$key]) && is_array($settings[$key])) {
                    wrcrm_db_setting_set($pdo, $key, $settings[$key], $key === 'smtp', isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
                }
            }
        } catch (Throwable $e) {}
    }

    return $settings;
}

function wrcrm_save_settings(array $settings): bool
{
    global $pdo;
    $ok = true;

    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            $appearance = [];
            foreach (array_keys(wrcrm_default_settings()) as $appearanceKey) {
                if (array_key_exists($appearanceKey, $settings)) $appearance[$appearanceKey] = $settings[$appearanceKey];
            }
            foreach (['logo', 'logo_collapsed', 'login_background'] as $assetKey) {
                if (array_key_exists($assetKey, $settings)) $appearance[$assetKey] = $settings[$assetKey];
            }
            $ok = wrcrm_db_setting_set($pdo, 'appearance', $appearance, false, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null) && $ok;
            if (isset($settings['smtp']) && is_array($settings['smtp'])) {
                $ok = wrcrm_db_setting_set($pdo, 'smtp', $settings['smtp'], true, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null) && $ok;
            }
            if (isset($settings['notifications']) && is_array($settings['notifications'])) {
                $ok = wrcrm_db_setting_set($pdo, 'notifications', $settings['notifications'], false, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null) && $ok;
            }
            return $ok;
        } catch (Throwable $e) {
            return false;
        }
    }

    $dir = dirname(wrcrm_settings_path());
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return @file_put_contents(wrcrm_settings_path(), json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}
