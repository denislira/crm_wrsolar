<?php
// Quick CLI test to simulate adding a funil stage as a logged-in user
// Run: php tests\funil_api_cli_test.php
if (session_status() === PHP_SESSION_NONE) session_start();
// set a user id that exists in your users table (1 is common for local test)
$_SESSION['user_id'] = 1;
// simulate POST data
$_POST = ['name' => 'Teste CLI Stage'];
// set action
$_REQUEST['action'] = 'add';

// Capture output
ob_start();
require __DIR__ . '/../includes/funil_stages_api.php';
$out = ob_get_clean();
echo "API output:\n" . $out . "\n";
?>