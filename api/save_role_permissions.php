<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/permissions.php';

if (empty($_SESSION['user_id']) || !hasPermission('configuracoes')) {
    echo json_encode(['success' => false, 'message' => 'Acesso negado.']);
    exit;
}

$role_id = isset($_POST['role_id']) ? intval($_POST['role_id']) : 0;
$submitted = isset($_POST['permissions']) ? $_POST['permissions'] : (isset($_POST['permissions[]']) ? $_POST['permissions[]'] : []);
if (!is_array($submitted)) {
    // try to extract when multiple fields named permissions[] are present
    $submitted = array_values((array)$submitted);
}

if (!$role_id) {
    echo json_encode(['success' => false, 'message' => 'role_id inválido']);
    exit;
}

try {
    // fetch existing screens
    $stmt = $pdo->query('SELECT DISTINCT screen FROM role_permissions');
    $dbScreens = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $all = array_unique(array_merge($dbScreens, $submitted));

    $pdo->beginTransaction();
    $upd = $pdo->prepare('UPDATE role_permissions SET allowed = ? WHERE role_id = ? AND screen = ?');
    $ins = $pdo->prepare('INSERT INTO role_permissions (role_id, screen, allowed) VALUES (?, ?, ?)');

    foreach ($all as $screen) {
        $screen = trim($screen);
        if ($screen === '') continue;
        $isAllowed = in_array($screen, $submitted) ? 1 : 0;
        $upd->execute([$isAllowed, $role_id, $screen]);
        if ($upd->rowCount() === 0) {
            // insert new mapping
            $ins->execute([$role_id, $screen, $isAllowed]);
        }
    }
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Permissões salvas.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Erro ao salvar permissões.']);
}

?>
