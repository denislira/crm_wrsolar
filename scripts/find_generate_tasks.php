<?php
require_once __DIR__ . '/../includes/config.php';

$stmt = $pdo->query('SELECT * FROM funil_stages ORDER BY id');
$raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
$out = [];
foreach ($raw as $r) {
	$out[] = [
		'id' => $r['id'],
		'name' => ($r['name'] ?? ($r['stage_name'] ?? null)),
		'generate_task_on_enter' => ($r['generate_task_on_enter'] ?? ($r['generate_task'] ?? null))
	];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
