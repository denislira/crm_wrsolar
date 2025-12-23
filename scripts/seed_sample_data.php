<?php
// Seeder for CRM sample data
// Run with: C:\xampp\php\php.exe scripts\seed_sample_data.php

require_once __DIR__ . '/../includes/config.php';

function random_phone() {
    $n = '';
    for ($i=0;$i<10;$i++) $n .= rand(0,9);
    return preg_replace('/(\d{2})(\d{4})(\d{4})/', '$1$2$3', $n);
}

try {
    $pdo->beginTransaction();

    // Ensure there is at least one user
    $stmt = $pdo->query("SELECT id FROM users LIMIT 1");
    $user = $stmt->fetch(PDO::FETCH_COLUMN);
    if (!$user) {
        $pw = password_hash('password', PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO users (username,password,email) VALUES (?,?,?)')->execute(['seed_user', $pw, 'seed@example.com']);
        $user = $pdo->lastInsertId();
        echo "Created seed user id=$user\n";
    }

    $userId = (int)$user;

    // Ensure funil_stages defaults exist for this user
    $stages = ['Novo','Contato','Qualificado','Proposta','Negociação','Fechado','Perdido'];
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM funil_stages WHERE user_id = ?');
    $stmt->execute([$userId]);
    $count = (int)$stmt->fetchColumn();
    if ($count === 0) {
        // detect columns to support legacy schemas
        $colStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funil_stages'");
        $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
        $nameCol = in_array('name', $cols) ? 'name' : (in_array('stage_name', $cols) ? 'stage_name' : 'name');
        $positionCol = in_array('position', $cols) ? 'position' : (in_array('stage_order', $cols) ? 'stage_order' : 'position');
        $colorCol = in_array('color', $cols) ? 'color' : (in_array('stage_color', $cols) ? 'stage_color' : 'color');
        $insSql = sprintf('INSERT INTO funil_stages (user_id, %s, %s, %s, created_at) VALUES (?, ?, ?, ?, NOW())', $nameCol, $positionCol, $colorCol);
        $ins = $pdo->prepare($insSql);
        $pos = 1;
        foreach ($stages as $s) {
            $color = sprintf('#%06X', mt_rand(0,0xFFFFFF));
            $ins->execute([$userId, $s, $pos++, $color]);
        }
        echo "Inserted default stages\n";
    }

    // Create activity_log table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        message TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Insert sample leads (120)
    // detect funil_stages name/position column
    $colStmt2 = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funil_stages'");
    $cols2 = $colStmt2->fetchAll(PDO::FETCH_COLUMN);
    $nameCol2 = in_array('name', $cols2) ? 'name' : (in_array('stage_name', $cols2) ? 'stage_name' : 'name');
    $positionCol2 = in_array('position', $cols2) ? 'position' : (in_array('stage_order', $cols2) ? 'stage_order' : 'position');
    $statusesStmt = $pdo->prepare("SELECT id, {$nameCol2} AS name FROM funil_stages WHERE user_id = ? ORDER BY COALESCE({$positionCol2}, id) ASC");
    $statusesStmt->execute([$userId]);
    $statusRows = $statusesStmt->fetchAll(PDO::FETCH_ASSOC);
    $statusNames = array_column($statusRows, 'name');
    $statusIds = array_column($statusRows, 'id');

    $leadIns = $pdo->prepare('INSERT INTO leads (user_id, name, email, phone, cpf_cnpj, source, status, stage_id, notes, consumo_cliente, estimativa_projeto_kwh, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');

    $domains = ['gmail.com','hotmail.com','empresa.com','client.com'];
    for ($i=1;$i<=120;$i++) {
        $name = "Lead Teste #" . $i;
        $email = 'lead'.$i.'@'. $domains[array_rand($domains)];
        $phone = random_phone();
        $cpf = str_pad((string)rand(10000000,99999999),11,'0',STR_PAD_LEFT);
        $src = ['Site','Indicação','Feira','Campanha'][array_rand([0,1,2,3])];
        $idx = array_rand($statusNames);
        $status = $statusNames[$idx];
        $stageId = $statusIds[$idx] ?? null;
        $notes = 'Teste de amostra para populacao.\nGerado automaticamente.';
        $consumo = rand(100,800);
        $estimativa = rand(1000,5000);
        $leadIns->execute([$userId, $name, $email, $phone, $cpf, $src, $status, $stageId, $notes, $consumo, $estimativa]);
    }
    echo "Inserted 120 leads\n";

    // Insert some projects for reporting
    $projIns = $pdo->prepare('INSERT INTO projetos (user_id, client_name, address, proposal_value, status, lead_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())');
    for ($i=1;$i<=30;$i++) {
        $client = 'Cliente '.$i;
        $addr = 'Endereço ' . $i;
        $value = rand(5000,60000);
        $status = ['Prospecção','Em Execução','Concluído'][array_rand([0,1,2])];
        $leadId = rand(1,120);
        $projIns->execute([$userId,$client,$addr,$value,$status,$leadId]);
    }
    echo "Inserted 30 projects\n";

    // Insert activity log entries (200)
    $actIns = $pdo->prepare('INSERT INTO activity_log (user_id, message, created_at) VALUES (?, ?, ?)');
    $actions = [
        'concluiu uma venda para',
        'enviou proposta para',
        'atualizou status do lead',
        'adicionou novo lead',
        'comentou no projeto',
        'anexou documento para'
    ];
    for ($i=0;$i<200;$i++) {
        $who = ['Maria','Andre','Lucas','Bruna','Carla','Renato'][array_rand([0,1,2,3,4,5])];
        $action = $actions[array_rand($actions)];
        $target = 'Cliente ' . rand(1,120);
        $msg = sprintf('%s %s %s', $who, $action, $target);
        $ts = date('Y-m-d H:i:s', strtotime('-' . rand(0,90) . ' days -' . rand(0,86400) . ' seconds'));
        $actIns->execute([$userId, $msg, $ts]);
    }
    echo "Inserted 200 activity log entries\n";

    $pdo->commit();
    echo "Seeding complete.\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

?>
