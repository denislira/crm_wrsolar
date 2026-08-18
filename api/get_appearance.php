<?php
session_start();
include '../includes/config.php';
include '../includes/settings_storage.php';

$appearance = wrcrm_load_settings(true);
$appearance = array_intersect_key($appearance, array_flip([
    'primary_color',
    'primary_dark',
    'green',
    'yellow',
    'sidebar_text_color',
    'navbar_bg_color',
    'logo',
    'logo_collapsed',
    'login_background'
]));
header('Content-Type: application/json');
echo json_encode(['success' => true, 'appearance' => $appearance]);
