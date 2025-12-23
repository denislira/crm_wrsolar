<?php
// Testa o endpoint includes/leads_api.php?action=list via include (sem fazer HTTP)
// Este teste cria um lead temporário, chama o endpoint e então remove o lead.
require_once __DIR__ . '/../includes/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) $_SESSION['user_id'] = 1; // ajuste conforme necessário

// Inserir lead temporário via PDO
$stmt = $pdo->prepare('INSERT INTO leads (user_id, name, email, phone, source, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())');
$stmt->execute([$_SESSION['user_id'], 'Teste API', 'teste@example.com', '0000', 'unittest', 'Novo']);
$insertId = $pdo->lastInsertId();

// Chama o endpoint list
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['action'] = 'list';
ob_start();
require_once __DIR__ . '/../includes/leads_api.php';
$output = ob_get_clean();

// Limpar lead de teste
$stmt = $pdo->prepare('DELETE FROM leads WHERE id = ? AND user_id = ?');
$stmt->execute([$insertId, $_SESSION['user_id']]);

// Verifica se é JSON válido e se contém nosso lead temporário
$data = json_decode($output, true);
if (json_last_error() === JSON_ERROR_NONE) {
    $found = false;
    foreach ($data as $row) {
        if (isset($row['id']) && $row['id'] == $insertId) { $found = true; break; }
    }
    if ($found) {
        echo "leads_api OK: encontrou o lead temporário (id={$insertId})\n";
    } else {
        echo "leads_api WARNING: resposta válida, mas não encontrou o lead temporário (pode ter sido escrito em outra base).\n";
        echo "Resposta (início): " . substr($output,0,300) . "\n";
    }
} else {
    echo "leads_api ERROR: resposta não é JSON válida\n";
    echo $output;
}
