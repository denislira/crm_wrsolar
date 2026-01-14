<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

include '../includes/config.php';
include '../includes/permissions.php';

if (!hasPermission('configuracoes')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

$username = $_POST['username'] ?? '';
$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';
$role_id = $_POST['role_id'] ?? '';

if (empty($username) || empty($password) || empty($role_id)) {
    echo json_encode(['success' => false, 'message' => 'Campos obrigatórios: username, password, role_id']);
    exit;
}

$hashed_password = password_hash($password, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare('INSERT INTO users (username, password, email, role_id) VALUES (?, ?, ?, ?)');
    $stmt->execute([$username, $hashed_password, $email, $role_id]);
    echo json_encode(['success' => true, 'message' => 'Usuário adicionado com sucesso']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro ao adicionar usuário: ' . $e->getMessage()]);
}
?>