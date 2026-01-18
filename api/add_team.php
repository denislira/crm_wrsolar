<?php
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Não autorizado']); exit; }
include '../includes/config.php';
include '../includes/permissions.php';
if (!hasPermission('configuracoes')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Acesso negado']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Método não permitido']); exit; }
$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
if ($name === '') { echo json_encode(['success'=>false,'message'=>'Nome obrigatório']); exit; }
try {
    $stmt = $pdo->prepare('INSERT INTO teams (name, description) VALUES (?, ?)');
    $stmt->execute([$name, $description]);
    echo json_encode(['success'=>true,'message'=>'Equipe adicionada']);
} catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
