<?php
include 'includes/config.php';

$username = 'denis';
$password = 'denis';
$email = 'denis@crm.com';

// Gerar hash da senha
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Inserir usuário
$stmt = $pdo->prepare('INSERT INTO users (username, password, email) VALUES (?, ?, ?)');
$stmt->execute([$username, $hashed_password, $email]);

echo "Usuário criado com sucesso! Hash: " . $hashed_password;
?>