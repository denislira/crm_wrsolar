<?php
// Atualiza consumo e estimativa aleatórios para cada lead
require_once __DIR__ . '/../includes/config.php';

function randomConsumo() {
    // Consumo mensal em reais: entre 200 e 5000
    return rand(200, 5000);
}
function randomEstimativa() {
    // Estimativa kWh: entre 150 e 3000
    return rand(150, 3000);
}

$stmt = $pdo->query('SELECT id FROM leads');
$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

$upd = $pdo->prepare('UPDATE leads SET consumo_cliente = ?, estimativa_projeto_kwh = ? WHERE id = ?');
$count = 0;
foreach ($ids as $id) {
    $consumo = randomConsumo();
    $estimativa = randomEstimativa();
    $upd->execute([$consumo, $estimativa, $id]);
    $count++;
}
echo "Atualizados $count leads com consumo e estimativa aleatórios.\n";
