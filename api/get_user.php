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

$id = $_GET['id'] ?? '';

if (empty($id)) {
    echo json_encode(['success' => false, 'message' => 'ID obrigatório']);
    exit;
}

try {
    // include avatar column if present
    $cols = ['id','username','email','nome_completo','biografia','role_id','team_id','role_level','avatar'];
    $available = [];
    try {
        $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
        $colStmt->execute();
        $allCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cols as $c) if (in_array($c, $allCols)) $available[] = $c;
    } catch (Exception $e) {
        $available = ['id','username','email','role_id','team_id','role_level'];
    }
    if (empty($available)) $available = ['id','username','email'];
    $select = implode(', ', $available);
    $stmt = $pdo->prepare("SELECT $select FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Usuário não encontrado']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
}
?>
