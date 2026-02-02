<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/permissions.php';

if (empty($_SESSION['user_id']) || !hasPermission('configuracoes')) {
    echo json_encode(['success' => false, 'message' => 'Acesso negado.']);
    exit;
}

try {
    // gather all distinct screens already known
    $stmt = $pdo->query('SELECT DISTINCT screen FROM role_permissions');
    $screens = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // if no screens found, try to populate a sensible default set
    if (empty($screens)) {
        $screens = ['dashboard','projetos','pos-venda','relatorios','leads_gestao','integracao-equipes','funil_config','configuracoes'];
    }

    // get roles
    $stmt = $pdo->query('SELECT id FROM roles');
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $ins = $pdo->prepare('INSERT INTO role_permissions (role_id, screen, allowed) VALUES (?, ?, ?)');
    $chk = $pdo->prepare('SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND screen = ?');

    $pdo->beginTransaction();
    foreach ($roles as $r) {
        foreach ($screens as $s) {
            $chk->execute([$r, $s]);
            if ($chk->fetchColumn() == 0) {
                $ins->execute([$r, $s, 0]);
            }
        }
    }
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Inicialização concluída.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Erro ao inicializar permissões.']);
}

?>
