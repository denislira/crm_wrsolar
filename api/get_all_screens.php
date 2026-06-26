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
    $stmt = $pdo->query('SELECT DISTINCT screen FROM role_permissions ORDER BY screen');
    $screens = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($screens)) {
        // sensible defaults when DB has no entries yet
        $screens = ['consultoria_externa','dashboard','projetos','pos-venda','relatorios','leads_gestao','integracao-equipes','fila_demandas','funil_config','configuracoes'];
    }
    echo json_encode(['success' => true, 'screens' => $screens]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro ao buscar screens.']);
}

?>
