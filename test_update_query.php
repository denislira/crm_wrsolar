<?php
require 'includes/config.php';

// Test UPDATE query similar to what's in leads_api.php
echo "Testing UPDATE query...\n";

$testData = [
    'id' => 1, // Assuming lead ID 1 exists
    'name' => 'Test Lead',
    'cidade' => 'Test City',
    'email' => 'test@example.com',
    'phone' => '1234567890',
    'cpf_cnpj' => '123456789',
    'source' => 'Test Source',
    'status' => '1',
    'stage_id' => 1,
    'notes' => 'Test notes',
    'consumo_cliente' => 100,
    'estimativa_projeto_kwh' => 50,
    'orcamento_value' => 5000,
    'envio_proposta' => null,
    'ultimo_contato' => null,
    'forma_pagamento' => null,
    'data_inicio' => null
];

$params = [
    $testData['name'],
    $testData['cidade'],
    $testData['email'],
    $testData['phone'],
    $testData['cpf_cnpj'],
    $testData['source'],
    $testData['status'],
    $testData['stage_id'],
    $testData['notes'],
    $testData['consumo_cliente'],
    $testData['estimativa_projeto_kwh'],
    $testData['orcamento_value'],
    $testData['envio_proposta'],
    $testData['ultimo_contato'],
    $testData['forma_pagamento'],
    $testData['data_inicio'],
    $testData['id']
];

$sql = 'UPDATE leads SET name=?, cidade=?, email=?, phone=?, cpf_cnpj=?, source=?, status=?, stage_id=?, notes=?, consumo_cliente=?, estimativa_projeto_kwh=?, orcamento_value=?, envio_proposta=?, ultimo_contato=?, forma_pagamento=?, data_inicio=?, updated_at=NOW() WHERE id=?';

try {
    $stmt = $pdo->prepare($sql);
    echo "SQL prepared successfully\n";
    echo "SQL: " . $sql . "\n";
    echo "Params count: " . count($params) . "\n";
    
    // Try to execute (in a transaction so we can rollback)
    $pdo->beginTransaction();
    $stmt->execute($params);
    $pdo->rollBack();
    echo "SUCCESS: Query would work!\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
