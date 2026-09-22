<?php
// Simple auto-select configuration by request host.
// If running on localhost use local XAMPP credentials, otherwise use production creds.

date_default_timezone_set('America/Sao_Paulo');

$reqHost = strtolower($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
$reqHost = preg_replace('/:\d+$/', '', $reqHost);

if ($reqHost === 'localhost' || $reqHost === '127.0.0.1') {
    $host = 'localhost';
    $dbname = 'crmwrsolare';
    $username = 'root';
    $password = '1234';
} else {
    $host = 'crmwrsolare.mysql.dbaas.com.br';
    $dbname = 'crmwrsolare';
    $username = 'crmwrsolare';
    $password = 'CRMsolare22@';
}

// Use TCP for localhost to avoid socket issues
$connectHost = ($host === 'localhost') ? '127.0.0.1' : $host;

try {
    $pdo = new PDO("mysql:host={$connectHost};dbname={$dbname};charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8mb4");
    $pdo->exec("SET time_zone = '-03:00'");
} catch (PDOException $e) {
    die("Erro na conexÃ£o: " . $e->getMessage());
}

?>
