<?php
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Não autorizado']); exit; }
include '../includes/config.php';
include '../includes/permissions.php';
if (!hasPermission('configuracoes')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Acesso negado']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Método não permitido']); exit; }
$id = $_POST['id'] ?? '';
if (empty($id)) { echo json_encode(['success'=>false,'message'=>'ID obrigatório']); exit; }
try {
    $stmt = $pdo->prepare('DELETE FROM teams WHERE id = ?');
    $stmt->execute([$id]);
    echo json_encode(['success'=>true,'message'=>'Equipe excluída']);
} catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
