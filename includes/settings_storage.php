<?php

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
    if ($ensureFile) {
        wrcrm_ensure_settings_file();
    }

    $settingsPath = wrcrm_settings_path();
    if (!file_exists($settingsPath)) {
        return wrcrm_default_settings();
    }

    $raw = @file_get_contents($settingsPath);
    $settings = $raw ? json_decode($raw, true) : [];

    return array_merge(wrcrm_default_settings(), is_array($settings) ? $settings : []);
}
