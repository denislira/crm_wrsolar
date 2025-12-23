<?php
require_once __DIR__ . '/../includes/config.php';

$equipes = ['Marketing','Vendas','Atendimento','Técnica','Financeiro'];
$titulos = [
    'Enviar proposta comercial',
    'Agendar visita técnica',
    'Revisar campanha de anúncios',
    'Atualizar cadastro de cliente',
    'Preparar relatório financeiro',
    'Treinamento de equipe',
    'Responder dúvidas do cliente',
    'Configurar sistema de vendas',
    'Planejar ação promocional',
    'Auditar contratos recentes'
];
$responsaveis = ['Maria Silva','João Pereira','Ana Costa','Pedro Oliveira','Mariana Santos','Lucas Rodrigues','Camila Almeida','Rafael Souza','Beatriz Lima','Fernando Gomes'];
$descricoes = [
    'Tarefa importante para o ciclo de vendas.',
    'Necessário contato com cliente antes.',
    'Ajustar orçamento conforme feedback.',
    'Reunião marcada para amanhã.',
    'Documentos anexados no sistema.',
    'Aguardando aprovação do gestor.',
    'Prioridade alta.',
    'Verificar pendências.',
    'Enviar e-mail de confirmação.',
    'Finalizar checklist.'
];
$statuses = ['Pendente','Em andamento','Concluída'];

$userStmt = $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1');
$user = $userStmt->fetch(PDO::FETCH_ASSOC);
$userId = $user ? $user['id'] : 1;

$pdo->exec('DELETE FROM team_tasks');

$insert = $pdo->prepare('INSERT INTO team_tasks (user_id, equipe, titulo, descricao, status, responsavel, data_vencimento) VALUES (?, ?, ?, ?, ?, ?, ?)');
for ($i=0; $i<10; $i++) {
    $eq = $equipes[$i%count($equipes)];
    $titulo = $titulos[$i];
    $desc = $descricoes[$i];
    $resp = $responsaveis[$i];
    $status = $statuses[$i%count($statuses)];
    $data_venc = date('Y-m-d', strtotime("+$i days"));
    $insert->execute([$userId, $eq, $titulo, $desc, $status, $resp, $data_venc]);
}

echo "Inseridos 10 registros em team_tasks.\n";
