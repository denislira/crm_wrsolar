<?php
header('Content-Type: text/plain');
$secretFile = __DIR__ . '/migrations.secret';
$token = $_GET['token'] ?? null;
if (!file_exists($secretFile)) { echo "migrations.secret not found. Copy migrations.secret.sample and set a secret token.\n"; exit; }
$expected = trim(file_get_contents($secretFile));
if (!$expected || $token !== $expected) { http_response_code(403); echo "Forbidden: invalid token\n"; exit; }
require_once __DIR__ . '/add_leads_columns.php';
require_once __DIR__ . '/create_attachments_and_movements.php';

echo "All migrations attempted.\n";
