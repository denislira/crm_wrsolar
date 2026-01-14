<?php
include 'includes/config.php';

$username = 'denis';
$password = 'denis';
$email = 'denis@crm.com';

// Gerar hash da senha
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Verificar se é o primeiro usuário
$stmt = $pdo->query('SELECT COUNT(*) as count FROM users');
$row = $stmt->fetch();
$is_first = $row['count'] == 0;
$role_id = $is_first ? 1 : 4; // 1 = Diretor, 4 = consultor

// Inserir usuário
$stmt = $pdo->prepare('INSERT INTO users (username, password, email, role_id) VALUES (?, ?, ?, ?)');
$stmt->execute([$username, $hashed_password, $email, $role_id]);

echo "Usuário criado com sucesso! Hash: " . $hashed_password . " | Papel: " . ($is_first ? 'Diretor' : 'Consultor');
?>