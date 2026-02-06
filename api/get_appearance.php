<?php
session_start();
include '../includes/config.php';
$storageDir = __DIR__ . '/../storage';
$settingsPath = $storageDir . '/settings.json';
$appearance = [];
if (file_exists($settingsPath)) {
    $raw = @file_get_contents($settingsPath);
    $appearance = $raw ? json_decode($raw, true) : [];
}
header('Content-Type: application/json');
echo json_encode(['success' => true, 'appearance' => $appearance]);
