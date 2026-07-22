<?php
session_start();
include '../includes/config.php';
$storageDir = __DIR__ . '/../storage';
$settingsPath = $storageDir . '/settings.json';
$smtp = [];
$hasPass = false;
if (file_exists($settingsPath)) {
    $raw = @file_get_contents($settingsPath);
    $all = $raw ? json_decode($raw, true) : [];
    if (isset($all['smtp']) && is_array($all['smtp'])) {
        $smtp = $all['smtp'];
        $hasPass = !empty($smtp['pass']);
    }
}
if (!empty($smtp['pass'])) $smtp['pass'] = '';
header('Content-Type: application/json');
echo json_encode(['success' => true, 'smtp' => $smtp, 'has_password' => $hasPass]);
