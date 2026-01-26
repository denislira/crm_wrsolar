<?php
header('Content-Type: text/plain');
$secretFile = __DIR__ . '/migrations.secret';
$token = $_GET['token'] ?? null;
if (!file_exists($secretFile)) { echo "migrations.secret not found. Copy migrations.secret.sample and set a secret token.\n"; exit; }
$expected = trim(file_get_contents($secretFile));
if (!$expected || $token !== $expected) { http_response_code(403); echo "Forbidden: invalid token\n"; exit; }
require_once __DIR__ . '/../../includes/config.php';
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_methods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        code VARCHAR(100) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "payment_methods table ensured\n";
} catch (Exception $e) { echo "payment_methods error: " . $e->getMessage() . "\n"; }

// seed common methods if empty
try {
    $c = $pdo->prepare('SELECT COUNT(*) FROM payment_methods');
    $c->execute();
    $cnt = (int)$c->fetchColumn();
    if ($cnt === 0) {
        $methods = ['Boleto','Cartão de Crédito','Pix','Transferência Bancária','Débito Automático'];
        $ins = $pdo->prepare('INSERT INTO payment_methods (name, code) VALUES (?,?)');
        foreach ($methods as $m) { $ins->execute([$m, null]); }
        echo "Seeded default payment methods\n";
    } else {
        echo "payment_methods already seeded ($cnt)\n";
    }
} catch (Exception $e) { echo "seed error: " . $e->getMessage() . "\n"; }

echo "done\n";
