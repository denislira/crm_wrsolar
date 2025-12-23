<?php
require_once __DIR__ . '/../includes/config.php';
// ensure session started early to avoid headers warning
if (session_status() === PHP_SESSION_NONE) session_start();

// create a test lead
$pdo->beginTransaction();
$ins = $pdo->prepare('INSERT INTO leads (user_id, name, email, phone, source, status, stage_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
$userIdStmt = $pdo->query('SELECT id FROM users LIMIT 1'); $userId = $userIdStmt->fetchColumn() ?: 1;
$ins->execute([$userId, 'API Test Lead', 'apitest@example.com', '11999999999', 'test', 'Novo', null,]);
$leadId = $pdo->lastInsertId();
$pdo->commit();

echo "Created lead id=$leadId\n";

// set session user
$_SESSION['user_id'] = $userId;

// find two stage ids to move between (detect name column)
$colStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funil_stages'");
$cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
$nameCol = in_array('name', $cols) ? 'name' : (in_array('stage_name', $cols) ? 'stage_name' : null);
if (!$nameCol) { echo "No name/stage_name column found on funil_stages\n"; exit; }
$st = $pdo->prepare(sprintf('SELECT id, %s AS name FROM funil_stages WHERE user_id = ? ORDER BY COALESCE(position,id) ASC LIMIT 2', $nameCol));
$st->execute([$userId]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) < 1) { echo "No stages found to test\n"; exit; }
$toStage = $rows[0];
$secondStage = $rows[1] ?? $rows[0];

// 1) set initial stage
$_POST = ['action'=>'update_status','id'=>$leadId,'status'=>$toStage['name'],'stage_id'=>$toStage['id']];
$_REQUEST = array_merge($_REQUEST, $_POST);
ob_start(); include __DIR__ . '/../includes/leads_api.php'; $out = ob_get_clean();
echo "API set initial stage response: $out\n";

// 2) move to next stage to trigger movement logging
$_POST = ['action'=>'update_status','id'=>$leadId,'status'=>$secondStage['name'],'stage_id'=>$secondStage['id']];
$_REQUEST = array_merge($_REQUEST, $_POST);
ob_start(); include __DIR__ . '/../includes/leads_api.php'; $out2 = ob_get_clean();
echo "API moved stage response: $out2\n";

// check movement log
$m = $pdo->prepare('SELECT * FROM lead_movements WHERE lead_id = ? ORDER BY created_at ASC'); $m->execute([$leadId]); $moves = $m->fetchAll(PDO::FETCH_ASSOC);
if ($moves) {
    echo "Movements recorded:\n";
    foreach ($moves as $mv) { echo json_encode($mv, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . "\n"; }
} else {
    echo "No movements recorded.\n";
}
