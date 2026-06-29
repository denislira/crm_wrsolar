<?php
// Simple auto-select configuration by request host.
// If running on localhost use local XAMPP credentials, otherwise use production creds.

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
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS lead_update_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            user_id INT DEFAULT NULL,
            updated_field VARCHAR(100) NOT NULL,
            old_value TEXT DEFAULT NULL,
            new_value TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lead_update_logs_created_user (created_at, user_id),
            INDEX idx_lead_update_logs_lead (lead_id),
            INDEX idx_lead_update_logs_field (updated_field)
        )");
    } catch (Exception $e) {
        // Ignore bootstrap failures so the app can keep loading.
    }
} catch (PDOException $e) {
    die("Erro na conexÃ£o: " . $e->getMessage());
}

?>
