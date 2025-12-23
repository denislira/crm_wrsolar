<?php
// Reset leads: delete all leads and insert 10 realistic entries
require_once __DIR__ . '/../includes/config.php';

try {
    $pdo->beginTransaction();

    // Delete all leads
    $deleted = $pdo->exec('DELETE FROM leads');

    // Ensure there is at least one user; use first user or create admin
    $userStmt = $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1');
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $password = password_hash('1234', PASSWORD_DEFAULT);
        $ins = $pdo->prepare('INSERT INTO users (username, password, email) VALUES (?, ?, ?)');
        $ins->execute(['admin', $password, 'admin@example.com']);
        $userId = $pdo->lastInsertId();
        echo "Created test user id={$userId}\n";
    } else {
        $userId = $user['id'];
    }

    // 10 realistic Brazilian/Portuguese names
    $names = [
        'Maria Silva', 'João Pereira', 'Ana Costa', 'Pedro Oliveira', 'Mariana Santos',
        'Lucas Rodrigues', 'Camila Almeida', 'Rafael Souza', 'Beatriz Lima', 'Fernando Gomes'
    ];

    $sources = ['Site', 'Indicação', 'WhatsApp', 'Facebook', 'Instagram', 'Feira', 'Parceiro', 'Telefone'];

    $insert = $pdo->prepare('INSERT INTO leads (user_id, name, email, phone, source, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())');

    $count = 0;
    foreach ($names as $n) {
        $slug = preg_replace('/[^a-z0-9]+/i', '.', strtolower($n));
        $email = $slug . '@example.com';
        // simple fake phone
        $phone = '+55' . rand(11,99) . rand(90000,99999) . rand(1000,9999);
        $source = $sources[array_rand($sources)];
        $status = 'Novo';
        $insert->execute([$userId, $n, $email, $phone, $source, $status]);
        $count++;
    }

    $pdo->commit();
    echo "Deleted leads: {$deleted}\n";
    echo "Inserted leads: {$count}\n";
    exit(0);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
