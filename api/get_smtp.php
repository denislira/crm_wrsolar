<?php
session_start();
include '../includes/config.php';
include '../includes/settings_storage.php';
$smtp = [];
$hasPass = false;
$all = wrcrm_load_settings(true);
if (isset($all['smtp']) && is_array($all['smtp'])) {
    $smtp = $all['smtp'];
    $hasPass = !empty($smtp['pass']);
}
if (!empty($smtp['pass'])) $smtp['pass'] = '';
header('Content-Type: application/json');
echo json_encode(['success' => true, 'smtp' => $smtp, 'has_password' => $hasPass]);
