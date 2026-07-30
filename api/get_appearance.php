<?php
session_start();
include '../includes/config.php';
include '../includes/settings_storage.php';

$appearance = wrcrm_load_settings(true);
header('Content-Type: application/json');
echo json_encode(['success' => true, 'appearance' => $appearance]);
