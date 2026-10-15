<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function passwordReply(int $status, string $message, bool $success = false): void {
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) passwordReply(401, 'Não autenticado');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') passwordReply(405, 'Método não permitido');

$token = (string)($_POST['csrf_token'] ?? '');
if ($token === '' || !hash_equals((string)($_SESSION['profile_password_csrf'] ?? ''), $token)) passwordReply(403, 'Sessão expirada. Recarregue a página.');

$current = (string)($_POST['current_password'] ?? '');
$new = (string)($_POST['new_password'] ?? '');
$confirmation = (string)($_POST['confirm_password'] ?? '');
if ($current === '' || $new === '' || $confirmation === '') passwordReply(422, 'Preencha todos os campos.');
if (mb_strlen($new, 'UTF-8') < 12) passwordReply(422, 'A nova senha deve ter pelo menos 12 caracteres.');
if ($new !== $confirmation) passwordReply(422, 'A confirmação não corresponde à nova senha.');

require_once __DIR__ . '/../includes/config.php';
try {
    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $stored = $stmt->fetchColumn();
    if (!$stored || !password_verify($current, (string)$stored)) passwordReply(422, 'Senha atual incorreta.');
    if (password_verify($new, (string)$stored)) passwordReply(422, 'Escolha uma senha diferente da atual.');

    $update = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
    $update->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
    session_regenerate_id(true);
    $_SESSION['profile_password_csrf'] = bin2hex(random_bytes(32));
    echo json_encode(['success' => true, 'message' => 'Senha alterada com sucesso.', 'csrf_token' => $_SESSION['profile_password_csrf']], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[change_my_password] ' . $e->getMessage());
    passwordReply(500, 'Não foi possível alterar a senha.');
}
