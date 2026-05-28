<?php
// ============================================================
//  Pós-venda — Gestão de Receita Recorrente
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/movements.php';

checkAccessOrRedirect('pos-venda');

// ── Auto-migration: extra columns ─────────────────────────
try {
    $cols = $pdo->query("SHOW COLUMNS FROM pos_venda")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('client_type',    $cols)) $pdo->exec("ALTER TABLE pos_venda ADD COLUMN client_type     VARCHAR(50)  DEFAULT 'Degustação'");
    if (!in_array('performance_pct',$cols)) $pdo->exec("ALTER TABLE pos_venda ADD COLUMN performance_pct DECIMAL(5,1) DEFAULT NULL");
    if (!in_array('referral_token', $cols)) $pdo->exec("ALTER TABLE pos_venda ADD COLUMN referral_token  VARCHAR(64)  DEFAULT NULL");
    if (!in_array('last_checkup',   $cols)) $pdo->exec("ALTER TABLE pos_venda ADD COLUMN last_checkup    DATE         DEFAULT NULL");
    if (!in_array('stage',          $cols)) $pdo->exec("ALTER TABLE pos_venda ADD COLUMN stage          VARCHAR(255) DEFAULT NULL");
    if (!in_array('warranty_months',$cols)) $pdo->exec("ALTER TABLE pos_venda ADD COLUMN warranty_months SMALLINT UNSIGNED DEFAULT 12");
    if (!in_array('cpf',            $cols)) $pdo->exec("ALTER TABLE pos_venda ADD COLUMN cpf VARCHAR(20) DEFAULT NULL");
    if (!in_array('birth_date',     $cols)) $pdo->exec("ALTER TABLE pos_venda ADD COLUMN birth_date DATE DEFAULT NULL");
    if (!in_array('plan_value',     $cols)) $pdo->exec("ALTER TABLE pos_venda ADD COLUMN plan_value DECIMAL(12,2) DEFAULT NULL");
    if (!in_array('equipment',      $cols)) $pdo->exec("ALTER TABLE pos_venda ADD COLUMN equipment VARCHAR(255) DEFAULT NULL");
    if (!in_array('phone',          $cols)) $pdo->exec("ALTER TABLE pos_venda ADD COLUMN phone VARCHAR(50) DEFAULT NULL");
    if (!in_array('email',          $cols)) $pdo->exec("ALTER TABLE pos_venda ADD COLUMN email VARCHAR(255) DEFAULT NULL");
    if (!in_array('address',        $cols)) $pdo->exec("ALTER TABLE pos_venda ADD COLUMN address TEXT DEFAULT NULL");
    if ($pdo->query("SHOW TABLES LIKE 'pos_venda_referrals'")->fetchColumn() === false) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pos_venda_referrals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pos_venda_id INT NOT NULL,
            user_id INT NOT NULL,
            referral_token VARCHAR(64) NOT NULL,
            indicator_name VARCHAR(255) NOT NULL,
            indicator_phone VARCHAR(50) DEFAULT NULL,
            indicator_email VARCHAR(255) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            transferred_to_kanban TINYINT(1) NOT NULL DEFAULT 0,
            promoted_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (pos_venda_id) REFERENCES pos_venda(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY ux_pos_venda_referrals_token (referral_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if ($pdo->query("SHOW TABLES LIKE 'pos_venda_referrals'")->fetchColumn() !== false) {
        $refCols = $pdo->query("SHOW COLUMNS FROM pos_venda_referrals")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('transferred_to_kanban', $refCols, true)) {
            $pdo->exec("ALTER TABLE pos_venda_referrals ADD COLUMN transferred_to_kanban TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('promoted_at', $refCols, true)) {
            $pdo->exec("ALTER TABLE pos_venda_referrals ADD COLUMN promoted_at DATETIME DEFAULT NULL");
        }
    }
} catch (Exception $e) { /* ignore */ }

$schema = [
    'pos_venda' => [],
    'projetos' => []
];
try {
    $schema['pos_venda'] = $pdo->query("SHOW TABLES LIKE 'pos_venda'")->fetchAll(PDO::FETCH_COLUMN) ? $pdo->query("SHOW COLUMNS FROM pos_venda")->fetchAll(PDO::FETCH_COLUMN) : [];
    $schema['projetos'] = $pdo->query("SHOW TABLES LIKE 'projetos'")->fetchAll(PDO::FETCH_COLUMN) ? $pdo->query("SHOW COLUMNS FROM projetos")->fetchAll(PDO::FETCH_COLUMN) : [];
} catch (Exception $e) { /* ignore */ }

$hasClientStatus   = in_array('client_status',   $schema['projetos'], true);
$hasPaymentStatus  = in_array('payment_status',  $schema['projetos'], true);
$hasPaymentType    = in_array('payment_type',    $schema['projetos'], true);
$hasContract       = in_array('contract',        $schema['projetos'], true);
$hasReferralToken  = in_array('referral_token',  $schema['pos_venda'], true);
$hasPerformancePct = in_array('performance_pct', $schema['pos_venda'], true);
$hasLastCheckup    = in_array('last_checkup',    $schema['pos_venda'], true);
$hasClientType     = in_array('client_type',     $schema['pos_venda'], true);
$hasProjectStatusChangedAt = in_array('status_changed_at', $schema['projetos'], true);

$missingSchemaMessage = '';
if (empty($schema['pos_venda'])) {
    $missingSchemaMessage = 'A tabela pos_venda não existe no banco de dados. Execute a migração da base.';
} elseif (empty($schema['projetos'])) {
    $missingSchemaMessage = 'A tabela projetos não existe no banco de dados. Verifique a instalação do CRM.';
}

if ($missingSchemaMessage) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $missingSchemaMessage]);
        exit;
    }
    include 'includes/header.php';
    echo '<div class="container py-5"><div class="alert alert-danger">' . htmlspecialchars($missingSchemaMessage) . '</div></div>';
    include 'includes/footer.php';
    exit;
}

try {
    $c = $pdo->query("SHOW COLUMNS FROM projetos LIKE 'client_status'")->fetchAll();
    if (empty($c)) $pdo->exec("ALTER TABLE projetos ADD COLUMN client_status VARCHAR(50) DEFAULT 'Assinante'");
    $movedCol = $pdo->query("SHOW COLUMNS FROM projetos LIKE 'moved_to_post_sale'")->fetchAll();
    if (empty($movedCol)) $pdo->exec("ALTER TABLE projetos ADD COLUMN moved_to_post_sale TINYINT(1) NOT NULL DEFAULT 0");
} catch (Exception $e) { /* ignore */ }

// ── AJAX / POST handler ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    if ($action === 'save_pv') {
        $id            = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $projId        = isset($_POST['project_id']) && trim((string)($_POST['project_id'] ?? '')) !== '' ? intval($_POST['project_id']) : 0;
        $clientName    = trim($_POST['client_name'] ?? '');
        $clientPhone   = trim($_POST['phone'] ?? '');
        $clientEmail   = trim($_POST['email'] ?? '');
        $clientAddress = trim($_POST['address'] ?? '');
        $instDate      = $_POST['installation_date']     ?: null;
        $nextMaint     = $_POST['next_maintenance']      ?: null;
        $projectKwhRaw = trim((string)($_POST['project_kwh'] ?? ''));
        $equipment     = trim((string)($_POST['equipment'] ?? ''));
        $planValueRaw  = trim((string)($_POST['plan_value'] ?? ''));
        $warrantyMonths= isset($_POST['warranty_months']) && trim($_POST['warranty_months']) !== '' ? max(1, intval($_POST['warranty_months'])) : 12;
        $notes         = trim($_POST['notes']            ?? '');
        $perf          = (isset($_POST['performance_pct']) && $_POST['performance_pct'] !== '') ? floatval($_POST['performance_pct']) : null;
        $clientType    = trim($_POST['client_type']      ?? 'Degustação');
        $lastCheckup   = $_POST['last_checkup']          ?: null;
        $stage         = trim($_POST['stage']            ?? '');
        $clientStatus  = trim($_POST['client_status']    ?? '');
        if ($clientType === '') $clientType = 'Degustação';
        if ($clientStatus === '') $clientStatus = 'Assinante';

        $planValue = null;
        if ($planValueRaw !== '') {
            $sanitized = preg_replace('/\s|R\$/iu', '', $planValueRaw);
            if (strpos($sanitized, ',') !== false && strpos($sanitized, '.') !== false) {
                if (strrpos($sanitized, ',') > strrpos($sanitized, '.')) {
                    $sanitized = str_replace('.', '', $sanitized);
                    $sanitized = str_replace(',', '.', $sanitized);
                } else {
                    $sanitized = str_replace(',', '', $sanitized);
                }
            } elseif (strpos($sanitized, ',') !== false) {
                $sanitized = str_replace('.', '', $sanitized);
                $sanitized = str_replace(',', '.', $sanitized);
            }
            $planValue = is_numeric($sanitized) ? number_format((float)$sanitized, 2, '.', '') : null;
        }

        $existingPv = null;
        if ($id) {
            $existingStmt = $pdo->prepare('SELECT * FROM pos_venda WHERE id=? AND user_id=? LIMIT 1');
            $existingStmt->execute([$id, $_SESSION['user_id']]);
            $existingPv = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $previousProjectMoved = null;
        if ($projId) {
            $prevProjectStmt = $pdo->prepare('SELECT moved_to_post_sale FROM projetos WHERE id=? LIMIT 1');
            $prevProjectStmt->execute([$projId]);
            $previousProjectMoved = intval($prevProjectStmt->fetchColumn() ?: 0);
        }

        // update projetos.client_status
        if ($projId && $hasClientStatus) {
            $pdo->prepare('UPDATE projetos SET client_status=?, updated_at=NOW() WHERE id=? AND user_id=?')
                ->execute([$clientStatus, $projId, $_SESSION['user_id']]);
        }

        if ($projId) {
            $projectKwh = $projectKwhRaw !== '' ? str_replace(',', '.', $projectKwhRaw) : null;
            $pdo->prepare('UPDATE projetos SET projeto=?, updated_at=NOW() WHERE id=? AND user_id=?')
                ->execute([$projectKwh, $projId, $_SESSION['user_id']]);
        }

        if ($projId) {
            $pdo->prepare('UPDATE projetos SET moved_to_post_sale = 1, updated_at = NOW() WHERE id = ? AND user_id = ?')
                ->execute([$projId, $_SESSION['user_id']]);
            if ($previousProjectMoved === 0) {
                log_project_movement(
                    $pdo,
                    $projId,
                    $_SESSION['user_id'],
                    'moved_to_post_sale',
                    null,
                    null,
                    null,
                    null,
                    'Projeto enviado para pós-venda via registro de pós-venda.'
                );
            }
        }

        // upsert pos_venda by project_id only when a project is linked
        $exId = 0;
        if ($projId) {
            $ex = $pdo->prepare('SELECT id FROM pos_venda WHERE project_id=? AND user_id=? LIMIT 1');
            $ex->execute([$projId, $_SESSION['user_id']]);
            $exId = $ex->fetchColumn();
        }

        $warrantyStart = date('Y-m-d');
        if ($exId) {
            $createdAtStmt = $pdo->prepare('SELECT created_at FROM pos_venda WHERE id=? LIMIT 1');
            $createdAtStmt->execute([$exId]);
            $createdAt = $createdAtStmt->fetchColumn();
            if ($createdAt) {
                $warrantyStart = $createdAt;
            }
        }
        $warranty = date('Y-m-d', strtotime('+' . $warrantyMonths . ' months', strtotime($warrantyStart)));

        if ($id) {
            $updateFields = ['client_name=?','installation_date=?','next_maintenance=?','warranty_end=?','notes=?','phone=?','email=?','cpf=?','birth_date=?','address=?'];
            $updateParams = [$clientName,$instDate,$nextMaint,$warranty,$notes,$clientPhone ?: null,$clientEmail ?: null,$cpf ?: null,$birthDate,$clientAddress ?: null];
            if ($hasPerformancePct) {
                $updateFields[] = 'performance_pct=?';
                $updateParams[] = $perf;
            }
            if ($hasClientType) {
                $updateFields[] = 'client_type=?';
                $updateParams[] = $clientType;
            }
            if ($hasLastCheckup) {
                $updateFields[] = 'last_checkup=?';
                $updateParams[] = $lastCheckup;
            }
            $updateFields[] = 'stage=?';
            $updateParams[] = $stage ?: null;
            $updateFields[] = 'warranty_months=?';
            $updateParams[] = $warrantyMonths;
            $updateFields[] = 'plan_value=?';
            $updateParams[] = $planValue;
            $updateFields[] = 'equipment=?';
            $updateParams[] = ($equipment !== '' ? $equipment : null);
            $updateFields[] = 'project_id=?';
            $updateParams[] = $projId ?: null;
            $updateFields[] = 'updated_at=NOW()';
            $updateParams[] = $id;
            $updateParams[] = $_SESSION['user_id'];
            $pdo->prepare('UPDATE pos_venda SET ' . implode(',', $updateFields) . ' WHERE id=? AND user_id=?')
               ->execute($updateParams);

            if ($existingPv) {
                if (($existingPv['stage'] ?? '') !== $stage) {
                    log_pos_venda_movement(
                        $pdo,
                        $id,
                        $projId ?: null,
                        $_SESSION['user_id'],
                        'stage_changed',
                        trim((string)$existingPv['stage']),
                        trim((string)$stage),
                        'Estágio do pós-venda alterado.'
                    );
                }
                $pvChangedFields = [];
                $fieldsToCheck = [
                    'client_name' => $clientName,
                    'phone' => $clientPhone,
                    'email' => $clientEmail,
                    'cpf' => $cpf,
                    'birth_date' => $birthDate,
                    'address' => $clientAddress,
                    'installation_date' => $instDate,
                    'next_maintenance' => $nextMaint,
                    'warranty_end' => $warranty,
                    'notes' => $notes,
                    'performance_pct' => $perf,
                    'client_type' => $clientType,
                    'last_checkup' => $lastCheckup,
                    'warranty_months' => $warrantyMonths,
                    'plan_value' => $planValue,
                    'equipment' => ($equipment !== '' ? $equipment : null),
                    'project_id' => $projId ?: null,
                ];
                foreach ($fieldsToCheck as $field => $value) {
                    if (($existingPv[$field] ?? null) != $value && $field !== 'stage') {
                        $pvChangedFields[] = $field;
                    }
                }
                if (!empty($pvChangedFields)) {
                    log_pos_venda_movement(
                        $pdo,
                        $id,
                        $projId ?: null,
                        $_SESSION['user_id'],
                        'updated',
                        trim((string)$existingPv['stage']),
                        trim((string)$stage),
                        'Campos alterados: ' . implode(', ', $pvChangedFields)
                    );
                }
            }
        } else {
            if (!$clientName && $projId) {
                $r = $pdo->prepare('SELECT client_name FROM projetos WHERE id=? LIMIT 1');
                $r->execute([$projId]); $clientName = $r->fetchColumn() ?: '';
            }
            $cols = ['user_id','project_id','client_name','installation_date','next_maintenance','warranty_end','notes','phone','email','cpf','birth_date','address'];
            $holders = ['?','?','?','?','?','?','?','?','?','?','?','?'];
            $params = [$_SESSION['user_id'],$projId ?: null,$clientName,$instDate,$nextMaint,$warranty,$notes,$clientPhone ?: null,$clientEmail ?: null,$cpf ?: null,$birthDate,$clientAddress ?: null];
            if ($hasPerformancePct) {
                $cols[] = 'performance_pct'; $holders[] = '?'; $params[] = $perf;
            }
            if ($hasClientType) {
                $cols[] = 'client_type'; $holders[] = '?'; $params[] = $clientType;
            }
            if ($hasLastCheckup) {
                $cols[] = 'last_checkup'; $holders[] = '?'; $params[] = $lastCheckup;
            }
            $cols[] = 'stage'; $holders[] = '?'; $params[] = $stage ?: null;
            $cols[] = 'warranty_months'; $holders[] = '?'; $params[] = $warrantyMonths;
            $cols[] = 'plan_value'; $holders[] = '?'; $params[] = $planValue;
            $cols[] = 'equipment'; $holders[] = '?'; $params[] = ($equipment !== '' ? $equipment : null);
            $cols[] = 'created_at'; $holders[] = 'NOW()';
            $cols[] = 'updated_at'; $holders[] = 'NOW()';
            $sql = 'INSERT INTO pos_venda (' . implode(',', $cols) . ') VALUES (' . implode(',', $holders) . ')';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $newPvId = (int)$pdo->lastInsertId();
            log_pos_venda_movement(
                $pdo,
                $newPvId,
                $projId ?: null,
                $_SESSION['user_id'],
                'created',
                null,
                $stage ?: null,
                'Registro de pós-venda criado.'
            );
        }
        echo json_encode(['success'=>true]); exit;
    }

    if ($action === 'delete_pv') {
        $id = isset($_POST['pv_id']) ? intval($_POST['pv_id']) : 0;
        $password = trim((string)($_POST['password'] ?? ''));

        if ($id <= 0 || $password === '') {
            echo json_encode(['success' => false, 'message' => 'ID e senha são obrigatórios.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$_SESSION['user_id']]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify($password, $hash)) {
            echo json_encode(['success' => false, 'message' => 'Senha incorreta.']);
            exit;
        }

        $deleteStmt = $pdo->prepare('DELETE FROM pos_venda WHERE id = ? AND user_id = ?');
        $deleteStmt->execute([$id, $_SESSION['user_id']]);
        if ($deleteStmt->rowCount() === 0) {
            echo json_encode(['success' => false, 'message' => 'Registro não encontrado ou sem permissão.']);
            exit;
        }

        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'schedule_maintenance') {
        $pvId = intval($_POST['pv_id'] ?? 0);
        $date = trim((string)($_POST['maintenance_date'] ?? ''));

        if ($pvId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            echo json_encode(['success'=>false,'message'=>'Dados inválidos']);
            exit;
        }

        $prevStmt = $pdo->prepare('SELECT next_maintenance FROM pos_venda WHERE id=? AND user_id=? LIMIT 1');
        $prevStmt->execute([$pvId, $_SESSION['user_id']]);
        $prevNext = $prevStmt->fetchColumn();

        $pdo->prepare('UPDATE pos_venda SET next_maintenance=?, updated_at=NOW() WHERE id=? AND user_id=?')
            ->execute([$date, $pvId, $_SESSION['user_id']]);

        log_pos_venda_movement(
            $pdo,
            $pvId,
            null,
            $_SESSION['user_id'],
            'maintenance_scheduled',
            $prevNext ?: null,
            $date,
            'Manutenção programada para ' . $date
        );

        echo json_encode(['success'=>true]);
        exit;
    }

    if ($action === 'update_stage') {
        $pvId = intval($_POST['pv_id'] ?? 0);
        $stage = trim($_POST['stage'] ?? '');
        if ($pvId <= 0) {
            echo json_encode(['success'=>false,'message'=>'ID inválido']);
            exit;
        }
        $prevStmt = $pdo->prepare('SELECT stage, project_id FROM pos_venda WHERE id=? AND user_id=? LIMIT 1');
        $prevStmt->execute([$pvId, $_SESSION['user_id']]);
        $prevRow = $prevStmt->fetch(PDO::FETCH_ASSOC);

        $pdo->prepare('UPDATE pos_venda SET stage=?, updated_at=NOW() WHERE id=? AND user_id=?')
            ->execute([$stage ?: null, $pvId, $_SESSION['user_id']]);

        log_pos_venda_movement(
            $pdo,
            $pvId,
            $prevRow['project_id'] ?? null,
            $_SESSION['user_id'],
            'stage_changed',
            trim((string)($prevRow['stage'] ?? '')),
            trim((string)$stage),
            'Estágio do pós-venda alterado.'
        );

        echo json_encode(['success'=>true]);
        exit;
    }

    if ($action === 'gen_referral') {
        if (!$hasReferralToken) {
            echo json_encode(['success'=>false,'message'=>'Recurso de indicação não disponível no banco de dados.']);
            exit;
        }
        $pvId  = intval($_POST['pv_id'] ?? 0);
        $token = bin2hex(random_bytes(16));
        $pdo->prepare('UPDATE pos_venda SET referral_token=? WHERE id=? AND user_id=?')
           ->execute([$token, $pvId, $_SESSION['user_id']]);
        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        echo json_encode(['success'=>true,'link'=>$base.'/indicacao.php?token='.$token,'token'=>$token]);
        exit;
    }

    if ($action === 'list_referrals') {
        $stmt = $pdo->prepare(
            'SELECT r.id, r.pos_venda_id, r.indicator_name, r.indicator_phone, r.indicator_email, r.notes, r.created_at, pv.client_name AS pv_client_name
             FROM pos_venda_referrals r
             LEFT JOIN pos_venda pv ON pv.id = r.pos_venda_id
             ORDER BY r.created_at DESC'
        );
        $stmt->execute([]);
        $referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'referrals' => $referrals]);
        exit;
    }

    if ($action === 'get_pv_details') {
        $pvId = intval($_POST['pv_id'] ?? 0);
        if ($pvId <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido']);
            exit;
        }

        $projectStatusChangedSelect = $hasProjectStatusChangedAt ? 'p.status_changed_at' : 'NULL AS status_changed_at';
        $stmt = $pdo->prepare(
            "SELECT pv.*, 
                    p.id AS proj_id,
                    p.client_name AS proj_client_name,
                    p.lead_id,
                    p.status AS proj_status,
                {$projectStatusChangedSelect},
                    p.address AS proj_address,
                    p.proposal_value,
                    p.projeto AS project_kwh,
                    p.payment_type,
                    p.payment_status,
                    p.contract,
                    p.closed_date,
                    p.created_at AS proj_created_at,
                    p.updated_at AS proj_updated_at,
                    l.id AS lead_id_join,
                    l.name AS lead_name,
                    l.phone AS lead_phone,
                    l.email AS lead_email,
                    l.cpf_cnpj AS lead_cpf,
                    l.cidade AS lead_city,
                    l.source AS lead_source,
                    l.notes AS lead_notes,
                    l.observacao AS lead_observacao,
                    l.created_at AS lead_created_at
             FROM pos_venda pv
             LEFT JOIN projetos p ON p.id = pv.project_id
             LEFT JOIN leads l ON l.id = p.lead_id
             WHERE pv.id = ? AND pv.user_id = ?
             LIMIT 1"
        );
        $stmt->execute([$pvId, $_SESSION['user_id']]);
        $details = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$details) {
            echo json_encode(['success' => false, 'message' => 'Registro não encontrado']);
            exit;
        }

        $leadId = intval($details['lead_id'] ?? $details['lead_id_join'] ?? 0);
        $projectId = intval($details['project_id'] ?? 0);

        $reminders = [];
        if ($projectId > 0) {
            try {
                $remStmt = $pdo->prepare(
                    'SELECT id, title, message, remind_at, status, created_at
                     FROM reminders
                     WHERE project_id = ?
                     ORDER BY remind_at DESC, created_at DESC
                     LIMIT 30'
                );
                $remStmt->execute([$projectId]);
                $reminders = $remStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $reminders = [];
            }
        }

        $postSaleHistory = [];
        $projectHistory = [];

        if (!empty($details['proj_created_at'])) {
            $projectHistory[] = [
                'kind' => 'project',
                'date' => $details['proj_created_at'],
                'title' => 'Projeto criado',
                'note' => 'Cadastro inicial do projeto.'
            ];
        }
        if (!empty($details['status_changed_at'])) {
            $projectHistory[] = [
                'kind' => 'project',
                'date' => $details['status_changed_at'],
                'title' => 'Mudança de status no projeto',
                'note' => 'Status atual: ' . ($details['proj_status'] ?: '—')
            ];
        }
        if (!empty($details['closed_date']) && $details['closed_date'] !== '0000-00-00') {
            $projectHistory[] = [
                'kind' => 'project',
                'date' => $details['closed_date'],
                'title' => 'Projeto fechado',
                'note' => 'Projeto marcado como concluído/fechado.'
            ];
        }
        if (!empty($details['proj_updated_at'])) {
            $projectHistory[] = [
                'kind' => 'project',
                'date' => $details['proj_updated_at'],
                'title' => 'Projeto atualizado',
                'note' => 'Última atualização registrada no projeto.'
            ];
        }

        foreach ($reminders as $reminder) {
            $projectHistory[] = [
                'kind' => 'project',
                'date' => $reminder['remind_at'] ?: $reminder['created_at'],
                'title' => 'Lembrete do projeto',
                'note' => trim(($reminder['title'] ?: 'Lembrete') . ' | ' . ($reminder['message'] ?: ''))
            ];
        }

        if (!empty($projectId)) {
            try {
                $movStmt = $pdo->prepare('SELECT action, from_status, to_status, from_client_status, to_client_status, note, created_at FROM project_movements WHERE project_id = ? ORDER BY created_at DESC');
                $movStmt->execute([$projectId]);
                $projectMovements = $movStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($projectMovements as $move) {
                    $title = 'Movimentação do projeto';
                    if ($move['action'] === 'status_changed') {
                        $title = 'Status do projeto alterado';
                    } elseif ($move['action'] === 'client_status_changed') {
                        $title = 'Status de acesso alterado';
                    } elseif ($move['action'] === 'moved_to_post_sale') {
                        $title = 'Projeto enviado para pós-venda';
                    } elseif ($move['action'] === 'project_updated') {
                        $title = 'Projeto atualizado';
                    }
                    $note = trim(($move['note'] ?? '') . (
                        !empty($move['from_status']) || !empty($move['to_status']) ? ' ' . trim(sprintf('De %s para %s.', $move['from_status'] ?: '—', $move['to_status'] ?: '—')) : ''
                    ));
                    $projectHistory[] = [
                        'kind' => 'project',
                        'date' => $move['created_at'],
                        'title' => $title,
                        'note' => $note
                    ];
                }
            } catch (Exception $e) {
                // ignore
            }
        }

        usort($projectHistory, static function ($a, $b) {
            return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
        });

        if (!empty($details['created_at'])) {
            $postSaleHistory[] = [
                'kind' => 'pv',
                'date' => $details['created_at'],
                'title' => 'Cliente entrou no pós-venda',
                'note' => 'Card criado na área de pós-venda.'
            ];
        }
        if (!empty($details['updated_at'])) {
            $postSaleHistory[] = [
                'kind' => 'pv',
                'date' => $details['updated_at'],
                'title' => 'Registro atualizado',
                'note' => 'Última atualização do cadastro de pós-venda.'
            ];
        }
        if (!empty($details['next_maintenance']) && $details['next_maintenance'] !== '0000-00-00') {
            $postSaleHistory[] = [
                'kind' => 'pv',
                'date' => $details['next_maintenance'],
                'title' => 'Manutenção programada',
                'note' => 'Próxima manutenção registrada no pós-venda.'
            ];
        }
        if (!empty($details['last_checkup']) && $details['last_checkup'] !== '0000-00-00') {
            $postSaleHistory[] = [
                'kind' => 'pv',
                'date' => $details['last_checkup'],
                'title' => 'Check-up realizado',
                'note' => 'Data do último check-up registrada no pós-venda.'
            ];
        }
        if (!empty($details['warranty_end']) && $details['warranty_end'] !== '0000-00-00') {
            $postSaleHistory[] = [
                'kind' => 'pv',
                'date' => $details['warranty_end'],
                'title' => 'Fim da garantia',
                'note' => 'Data prevista de encerramento da garantia/assinatura.'
            ];
        }

        try {
            $pvMovStmt = $pdo->prepare('SELECT action, from_stage, to_stage, note, created_at FROM pos_venda_movements WHERE pos_venda_id = ? ORDER BY created_at DESC');
            $pvMovStmt->execute([$pvId]);
            $pvMovements = $pvMovStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pvMovements as $move) {
                $title = 'Movimentação do pós-venda';
                if ($move['action'] === 'stage_changed') {
                    $title = 'Estágio do pós-venda alterado';
                } elseif ($move['action'] === 'maintenance_scheduled') {
                    $title = 'Manutenção agendada';
                } elseif ($move['action'] === 'created') {
                    $title = 'Registro criado';
                } elseif ($move['action'] === 'updated') {
                    $title = 'Registro atualizado';
                }
                $note = trim(($move['note'] ?? '') . (
                    !empty($move['from_stage']) || !empty($move['to_stage']) ? ' ' . trim(sprintf('De %s para %s.', $move['from_stage'] ?: '—', $move['to_stage'] ?: '—')) : ''
                ));
                $postSaleHistory[] = [
                    'kind' => 'pv',
                    'date' => $move['created_at'],
                    'title' => $title,
                    'note' => $note
                ];
            }
        } catch (Exception $e) {
            // ignore
        }

        usort($postSaleHistory, static function ($a, $b) {
            return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
        });

        echo json_encode([
            'success' => true,
            'details' => $details,
            'history' => [
                'project' => $projectHistory,
                'reminders' => $reminders,
                'post_sale' => $postSaleHistory,
            ],
        ]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}

// ── Fetch data ─────────────────────────────────────────────
$projetoSelect = [
    'p.client_name AS proj_client_name',
    'p.status AS proj_status',
    'p.closed_date',
    $hasClientStatus ? "COALESCE(p.client_status, 'Assinante') AS client_status" : "'Assinante' AS client_status",
    $hasPaymentStatus ? 'p.payment_status' : 'NULL AS payment_status',
    $hasPaymentType ? 'p.payment_type' : 'NULL AS payment_type',
    $hasContract ? 'p.contract' : 'NULL AS contract',
    'p.lead_id',
    'p.address AS proj_address',
    'p.proposal_value',
    'p.projeto AS project_kwh',
    'p.id AS proj_id',
    'l.id AS lead_id',
    'l.name AS lead_name',
    'l.phone AS lead_phone',
    'l.email AS lead_email',
    'l.cpf_cnpj AS lead_cpf',
    'l.cidade AS lead_city',
    'l.source AS lead_source',
    'pv.stage AS stage'
];
$stmt = $pdo->prepare(
    'SELECT pv.*, ' . implode(', ', $projetoSelect) . "
    FROM pos_venda pv
    LEFT JOIN projetos p ON pv.project_id = p.id
    LEFT JOIN leads l ON l.id = p.lead_id
    WHERE pv.user_id = ?
    ORDER BY pv.installation_date DESC
"
);
$stmt->execute([$_SESSION['user_id']]);
$posVendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$teamTasksAvailable = false;
try {
    $teamTasksAvailable = (bool)$pdo->query("SHOW TABLES LIKE 'team_tasks'")->fetchColumn();
} catch (Exception $e) { /* ignore */ }

$createAutoTask = function ($token, $title, $description, $dueDate, $team = 'Administrativo') use ($pdo, $teamTasksAvailable) {
    if (!$teamTasksAvailable) return;
    if (!$dueDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) return;

    try {
        $marker = '[' . $token . ']';
        $exists = $pdo->prepare('SELECT id FROM team_tasks WHERE user_id = ? AND descricao LIKE ? LIMIT 1');
        $exists->execute([$_SESSION['user_id'], '%' . $marker . '%']);
        if ($exists->fetchColumn()) return;

        $ins = $pdo->prepare('INSERT INTO team_tasks (user_id, equipe, titulo, descricao, status, responsavel, data_vencimento, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $ins->execute([
            $_SESSION['user_id'],
            $team,
            $title,
            $description . ' ' . $marker,
            'Pendente',
            $_SESSION['username'] ?? null,
            $dueDate,
        ]);
    } catch (Exception $e) { /* ignore */ }
};

// ── KPIs + card enrichment ─────────────────────────────────
$now = new DateTime();
$assinantesAtivos = 0;
$emDegustacao     = 0;
$indicacoesMes    = 0;

foreach ($posVendas as &$pv) {
    $instDt = (!empty($pv['installation_date']) && $pv['installation_date'] !== '0000-00-00')
        ? new DateTime($pv['installation_date']) : null;

    $diff = $instDt ? $instDt->diff($now) : null;
    $pv['months_elapsed'] = $diff ? ($diff->y * 12 + $diff->m) : 0;
    $m  = $pv['months_elapsed'];

    // At month 12+, recurring status is automatic based on payment status.
    $shouldStatus = null;
    if ($m >= 12) {
        $pay = strtolower(trim((string)($pv['payment_status'] ?? '')));
        $shouldStatus = ($pay === 'pago') ? 'Assinante' : 'Ex-Cliente';
    }

    if ($shouldStatus && $pv['client_status'] !== $shouldStatus) {
        $pdo->prepare('UPDATE projetos SET client_status=?, updated_at=NOW() WHERE id=? AND user_id=?')
            ->execute([$shouldStatus, $pv['proj_id'], $_SESSION['user_id']]);
        $pv['client_status'] = $shouldStatus;

        if ($shouldStatus === 'Ex-Cliente' && !is_null($pv['performance_pct'])) {
            // Inactive clients lose access to performance reporting data.
            $pdo->prepare('UPDATE pos_venda SET performance_pct=NULL, updated_at=NOW() WHERE id=? AND user_id=?')
                ->execute([$pv['id'], $_SESSION['user_id']]);
            $pv['performance_pct'] = null;
        }
    }

    $isEx = ($pv['client_status'] === 'Ex-Cliente');
    $payStatus = strtolower(trim((string)($pv['payment_status'] ?? '')));
    $pv['payment_ok'] = ($payStatus === 'pago');
    $ct   = $pv['client_type'] ?? 'Degustação';

    // Amigo Solar trigger after 90 days from installation.
    $daysElapsed = $instDt ? (int)$instDt->diff($now)->format('%a') : 0;
    $pv['amigo_eligible'] = (!$isEx && $daysElapsed >= 90);

    if ($pv['amigo_eligible'] && empty($pv['referral_token']) && $instDt) {
        $amigoDate = (clone $instDt)->modify('+90 days')->format('Y-m-d');
        $amigoToken = 'AUTO_AMIGO90_' . (int)$pv['id'];
        $createAutoTask(
            $amigoToken,
            'Contato Amigo Solar: ' . $pv['client_name'],
            'Cliente elegivel para programa de indicacoes (90 dias de funcionamento). Oferecer brinde/desconto por indicacao.',
            $amigoDate,
            'Comercial'
        );
    }

    // Preventive maintenance trigger every 6 months from last check-up (or installation when absent).
    $checkBase = null;
    if (!empty($pv['last_checkup']) && $pv['last_checkup'] !== '0000-00-00') {
        $checkBase = new DateTime($pv['last_checkup']);
    } elseif ($instDt) {
        $checkBase = clone $instDt;
    }
    if ($checkBase && !$isEx) {
        $checkDue = (clone $checkBase)->modify('+6 months');
        if ($now >= $checkDue) {
            $checkToken = 'AUTO_CHECKUP_' . (int)$pv['id'] . '_' . $checkDue->format('Ymd');
            $createAutoTask(
                $checkToken,
                'Lembrete: agendar limpeza tecnica - ' . $pv['client_name'],
                'Entrar em contato com o cliente para agendar limpeza/check-up preventivo de 6 meses.',
                $checkDue->format('Y-m-d'),
                'Administrativo'
            );
        }
    }

    // Annual renewal trigger on installation anniversary.
    if ($instDt && !$isEx) {
        $annivMonth = (int)$instDt->format('m');
        $annivDay = (int)$instDt->format('d');
        $currentYear = (int)$now->format('Y');
        $annivCurrent = new DateTime($currentYear . '-' . str_pad((string)$annivMonth, 2, '0', STR_PAD_LEFT) . '-01');
        $maxDay = (int)$annivCurrent->format('t');
        $annivCurrent->setDate($currentYear, $annivMonth, min($annivDay, $maxDay));

        $yearsElapsed = $currentYear - (int)$instDt->format('Y');
        if ($yearsElapsed >= 1 && $now >= $annivCurrent) {
            $renewToken = 'AUTO_RENOV_' . (int)$pv['id'] . '_' . $currentYear;
            $createAutoTask(
                $renewToken,
                'Gerar OS de visita tecnica preventiva - ' . $pv['client_name'],
                'Gerar ordem de servico de renovacao anual para visita tecnica preventiva.',
                $annivCurrent->format('Y-m-d'),
                'Tecnica'
            );
        }
    }

    // Annual maintenance only allowed for paid customers after degustation period.
    $pv['maintenance_locked'] = (!$isEx && $m >= 12 && !$pv['payment_ok']);

    // Badge & color
    if ($isEx) {
        $pv['badge_label'] = 'EX-CLIENTE';
        $pv['badge_class'] = 'danger';
        $pv['card_class']  = 'border-danger';
    } elseif ($m >= 12) {
        $assinantesAtivos++;
        $label = strtolower($ct);
        if (strpos($label,'embaixador') !== false)    { $pv['badge_label']='EMBAIXADOR';     $pv['badge_class']='warning'; }
        else                                      { $pv['badge_label']='ASSINANTE ATIVO';$pv['badge_class']='success'; }
        $pv['card_class'] = 'border-success';
    } else {
        $emDegustacao++;
        $label = strtolower($ct);
        if (strpos($label,'embaixador') !== false)    { $pv['badge_label']='EMBAIXADOR';     $pv['badge_class']='warning'; }
        elseif (strpos($label,'cortesia') !== false)  { $pv['badge_label']='CORTESIA';       $pv['badge_class']='info'; }
        else                                      { $pv['badge_label']='EM DEGUSTAÇÃO';  $pv['badge_class']='secondary'; }
        $pv['card_class'] = ($m >= 10) ? 'border-warning' : '';
    }

    // Progress bar (cap at 100% visually)
    $pv['progress_pct']   = min(100, round(($m / 12) * 100));
    $pv['progress_color'] = ($m >= 12) ? 'success' : (($m >= 10) ? 'warning' : 'primary');

    // SLA alert: in 11th or 12th month, approaching renewal
    $pv['sla_alert'] = (!$isEx && $m >= 10 && $m < 12);
    $pv['sla_month'] = $m + 1;

    // Last check-up human
    $pv['checkup_human'] = 'Não realizado';
    if (!empty($pv['last_checkup']) && $pv['last_checkup'] !== '0000-00-00') {
        $ld   = new DateTime($pv['last_checkup']);
        $ldif = $ld->diff($now);
        $tm = $ldif->y * 12 + $ldif->m;
        $pv['checkup_human'] = $tm === 0 ? 'Este mês' : ($tm === 1 ? 'Há 1 mês' : "Há {$tm} meses");
    }

    // Referrals count
    if (!empty($pv['referral_token'])) $indicacoesMes++;
}
unset($pv);

$total = count($posVendas);
$conversaoRecorrencia = $total > 0 ? round(($assinantesAtivos / $total) * 100) : 0;

// Fetch all eligible projects for the "Add" modal select
$projStmt = $pdo->prepare("SELECT p.id, p.client_name, p.projeto, p.address AS proj_address, l.phone AS lead_phone, l.email AS lead_email, l.cpf_cnpj AS lead_cpf
    FROM projetos p
    LEFT JOIN leads l ON l.id = p.lead_id
    WHERE p.user_id=? AND p.status IN ('Fechado','Finalizado','Homologado','Concluído','Concluido')
    ORDER BY p.client_name ASC");
$projStmt->execute([$_SESSION['user_id']]);
$projetosDisponiveis = $projStmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>
<style>
/* ── Gestão de Receita Recorrente ───────────────────────── */
.pv-kpi            { border-radius:14px; border:none; box-shadow:0 2px 10px rgba(0,0,0,.07); }
.pv-kpi-icon       { width:46px;height:46px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.1rem; }
.pv-card           { border-radius:14px;box-shadow:0 3px 14px rgba(0,0,0,.08);transition:box-shadow .2s; }
.pv-card:hover     { box-shadow:0 7px 22px rgba(0,0,0,.13); }
.pv-badge          { font-size:.7rem;font-weight:700;letter-spacing:.05em;padding:3px 10px;border-radius:20px;white-space:nowrap; }
.pv-meta-label     { font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:#999;font-weight:700;margin-bottom:2px; }
.pv-meta-val       { font-size:.85rem;color:#444; }
.pv-sla-alert      { background:#fff3cd;border-radius:7px;padding:5px 10px;font-size:.78rem;font-weight:700;color:#856404; }
.pv-blocked        { font-size:.85rem;color:#aaa;font-style:italic; }
.progress          { height:8px;border-radius:4px; }
.btn-amigo         { font-size:.76rem;font-weight:600;letter-spacing:.03em; }
.pv-divider        { border-top:1px solid #f0f0f0; }
.pv-kanban-board   { display:flex; gap:1rem; overflow-x:auto; padding-bottom:0.5rem; }
.pv-kanban-col    { width:360px; min-width:360px; max-width:360px; flex-shrink:0; background:#f8fafc; border-radius:14px; box-shadow:0 1px 8px rgba(0,0,0,.05); display:flex; flex-direction:column; }
.pv-kanban-col-header {
    padding: 0.65rem 0.85rem 0.6rem 0.85rem;
    border-bottom: none;
    background: linear-gradient(90deg, #3b82f6 80%, #2563eb 100%);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: .4rem;
    border-radius: 14px 14px 0 0;
    box-shadow: 0 2px 8px rgba(0,0,0,.04);
    position: relative;
}
.pv-kanban-col-title {
    margin: 0;
    font-size: .98rem;
    font-weight: 700;
    letter-spacing: .01em;
    display: flex;
    align-items: center;
    gap: .4rem;
    color: #fff !important;
}
.pv-kanban-col-body { padding:1rem; overflow-y:auto; min-height:400px; max-height:calc(100vh - 360px); flex:1 1 auto; background:#f8fafc; border-radius:0 0 14px 14px; }
.pv-kanban-col-body.drop-target { border:2px dashed #0d6efd; background:#e7f1ff; }
.pv-kanban-card    { background:#fff; border-radius:12px; border:1px solid #e5e7eb; padding:0; margin-bottom:.75rem; cursor:grab; overflow:hidden; transition: box-shadow .18s; }
.pv-kanban-card:active { cursor:grabbing; }
.pv-kanban-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.10); }
.pv-card-header-strip { padding:.65rem 1rem .55rem; border-bottom:1px solid #f1f5f9; display:flex; justify-content:space-between; align-items:center; gap:.5rem; }
.pv-card-body-inner { padding:.7rem 1rem .6rem; }
.pv-card-footer { padding:.5rem 1rem .6rem; border-top:1px solid #f1f5f9; display:flex; gap:.4rem; flex-wrap:wrap; background:#f8fafc; }
.pv-stage-badge    { display:inline-flex; align-items:center; gap:.35rem; font-size:.75rem; font-weight:700; padding:.35rem .7rem; border-radius:999px; }
.pv-stage-actions  { display:flex; gap:.35rem; flex-wrap:wrap; }
#pvStagesList .list-group-item { display:flex; align-items:center; justify-content:space-between; gap:.75rem; flex-wrap:wrap; }
#pvStagesList .list-group-item .stage-controls { display:flex; gap:.35rem; align-items:center; }
#pvStagesList .stage-sla-toggle { min-width:180px; display:flex; justify-content:flex-end; }
#pvStagesNotice { margin-bottom:1rem; }
.pv-toolbar-search { min-width:260px; }
.pv-kanban-empty { border:1px dashed #cbd5e1; border-radius:12px; padding:1rem; text-align:center; color:#94a3b8; background:#f8fafc; }
.pv-kanban-card-top { display:flex; justify-content:space-between; align-items:flex-start; gap:.75rem; }
.pv-kanban-card-title { font-size:.95rem; font-weight:700; line-height:1.2; margin:0; }
.pv-kanban-meta { font-size:.76rem; color:#64748b; }
.pv-card-id-line { font-size:.78rem; font-weight:700; color:#475569; margin-bottom:.25rem; display:flex; align-items:center; gap:.45rem; flex-wrap:wrap; }
.pv-card-client-name { font-size:1rem; font-weight:800; line-height:1.25; margin:0 0 .55rem; color:#111827; }
.pv-card-kv { display:grid; grid-template-columns:1fr auto; gap:.15rem .8rem; font-size:.82rem; margin-bottom:.5rem; }
.pv-card-kv .k { color:#64748b; font-weight:600; }
.pv-card-kv .v { color:#111827; font-weight:700; text-align:right; }
.pv-expired-chip { border:1px solid #f59e0b; color:#92400e; background:#fef3c7; border-radius:999px; font-size:.66rem; font-weight:700; padding:.12rem .48rem; text-transform:uppercase; }
.pv-health-head { display:flex; justify-content:space-between; align-items:center; font-size:.8rem; margin:.35rem 0 .2rem; }
.pv-health-head .label { color:#334155; font-weight:700; }
.pv-health-head .value { color:#0f172a; font-weight:800; }
.pv-health-bar { height:7px; border-radius:999px; background:#e2e8f0; overflow:hidden; }
.pv-health-bar > span { display:block; height:100%; border-radius:999px; }
.pv-progress-head { display:flex; justify-content:space-between; align-items:center; font-size:.78rem; margin:.45rem 0 .2rem; color:#334155; }
.pv-progress-bar { height:8px; border-radius:999px; background:#e2e8f0; overflow:hidden; }
.pv-progress-bar > span { display:block; height:100%; border-radius:999px; }
.pv-table-search { max-width:320px; }
.pv-contract-filter .btn { min-width:140px; }
.pv-kanban-compact .pv-kanban-col { width:280px; min-width:280px; max-width:280px; }
.pv-kanban-compact .pv-kanban-col-header { padding:.5rem .65rem; }
.pv-kanban-compact .pv-kanban-col-title { font-size:.86rem; }
.pv-kanban-compact .pv-kanban-col-body { padding:.65rem; }
.pv-kanban-compact .pv-kanban-card { border-radius:10px; margin-bottom:.5rem; }
.pv-kanban-compact .pv-card-header-strip { padding:.45rem .65rem .4rem; }
.pv-kanban-compact .pv-card-body-inner { padding:.45rem .65rem; }
.pv-kanban-compact .pv-card-footer { padding:.35rem .65rem .45rem; gap:.3rem; }
.pv-kanban-compact .pv-card-client-name { font-size:.9rem; margin-bottom:.35rem; }
.pv-kanban-compact .pv-card-kv { font-size:.75rem; gap:.1rem .5rem; margin-bottom:.35rem; }
.pv-kanban-compact .pv-health-head,
.pv-kanban-compact .pv-progress-head { font-size:.72rem; }
.pv-kanban-compact .pv-kv-hide-compact { display:none; }
#pvModal .form-control,
#pvModal .form-select,
#pvModal textarea {
    border-color: var(--bs-primary);
}

#pvModal .form-control:focus,
#pvModal .form-select:focus,
#pvModal textarea:focus {
    border-color: var(--bs-primary);
    box-shadow: 0 0 0 .2rem rgba(var(--bs-primary-rgb), .25);
}
.pv-main-with-panel { transition: margin-right .2s ease; }
.pv-main-with-panel.panel-open { margin-right: 430px; }
.pv-details-panel {
    position: fixed;
    right: 0;
    top: 56px;
    width: 420px;
    height: calc(100vh - 56px);
    background: #fff;
    border-left: 1px solid rgba(15,23,42,.08);
    box-shadow: -8px 0 24px rgba(15,23,42,.12);
    z-index: 1200;
    overflow: auto;
    transition: transform .2s ease;
}
.pv-details-panel.hidden { transform: translateX(100%); }
.pv-details-panel-inner { padding: 1rem; }
.pv-details-close { position:absolute; top:8px; right:8px; }
@media (max-width: 992px) {
    .pv-main-with-panel.panel-open { margin-right: 0; }
    .pv-details-panel { width: 100%; top: 56px; height: calc(100vh - 56px); }
}
</style>

<div class="d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main id="pvMainContent" class="flex-grow-1 p-4 pv-main-with-panel">

        <!-- Page header -->
        <div class="mb-4 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
            <div>
                <h1 class="h4 fw-bold mb-1"><i class="fa fa-rotate text-primary me-2"></i>Gestão de Receita Recorrente</h1>
                <p class="text-muted small mb-0">Transformando clientes concluídos em assinantes recorrentes.</p>
            </div>
            <div class="d-flex gap-2 align-items-center ms-md-auto mt-2 mt-md-0">
                <button class="btn btn-primary btn-sm" id="pvNewBtn">
                    <i class="fa fa-plus me-1"></i>Adicionar
                </button>
                <button type="button" id="pvReferralsBtn" class="btn btn-outline-primary btn-sm">Indicações</button>
                <button type="button" id="pvFieldsConfigBtn" class="btn btn-outline-secondary btn-sm">Configurar Campos</button>
                <button type="button" id="pvStagesConfigBtn" class="btn btn-outline-secondary btn-sm">Gerenciar Colunas</button>
            </div>
        </div>

        <!-- KPIs NOVOS -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-2">
                <div class="card pv-kpi p-3 h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="pv-meta-label">Garantia Ativa</div>
                            <div class="fw-bold" style="font-size:1.5rem;line-height:1.1"><?php
                                $garantiasAtivas = 0;
                                foreach ($posVendas as $pv) {
                                    if (!empty($pv['warranty_end']) && $pv['warranty_end'] !== '0000-00-00' && $pv['warranty_end'] >= date('Y-m-d')) {
                                        $garantiasAtivas++;
                                    }
                                }
                                echo $garantiasAtivas;
                            ?></div>
                        </div>
                        <div class="pv-kpi-icon bg-info bg-opacity-10 text-info"><i class="fa fa-shield-halved"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card pv-kpi p-3 h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="pv-meta-label">Planos Pagos</div>
                            <div class="fw-bold" style="font-size:1.5rem;line-height:1.1"><?php
                                $planosPagos = 0;
                                foreach ($posVendas as $pv) {
                                    if (strtolower($pv['payment_status'] ?? '') === 'pago') {
                                        $planosPagos++;
                                    }
                                }
                                echo $planosPagos;
                            ?></div>
                        </div>
                        <div class="pv-kpi-icon bg-success bg-opacity-10 text-success"><i class="fa fa-money-bill-wave"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card pv-kpi p-3 h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="pv-meta-label">Aguardando Renovação</div>
                            <div class="fw-bold" style="font-size:1.5rem;line-height:1.1"><?php
                                $aguardandoRenov = 0;
                                foreach ($posVendas as $pv) {
                                    $m = $pv['months_elapsed'] ?? 0;
                                    $isEx = ($pv['client_status'] ?? '') === 'Ex-Cliente';
                                    if (!$isEx && $m >= 10 && $m < 12) {
                                        $aguardandoRenov++;
                                    }
                                }
                                echo $aguardandoRenov;
                            ?></div>
                        </div>
                        <div class="pv-kpi-icon bg-warning bg-opacity-10 text-warning"><i class="fa fa-hourglass-half"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card pv-kpi p-3 h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="pv-meta-label">Ticket Médio Plano</div>
                            <div class="fw-bold" style="font-size:1.5rem;line-height:1.1"><?php
                                $total = 0; $count = 0;
                                foreach ($posVendas as $pv) {
                                    if (is_numeric($pv['plan_value']) && $pv['plan_value'] > 0) {
                                        $total += $pv['plan_value'];
                                        $count++;
                                    }
                                }
                                echo $count ? 'R$ ' . number_format($total/$count, 2, ',', '.') : 'R$ 0,00';
                            ?></div>
                        </div>
                        <div class="pv-kpi-icon bg-primary bg-opacity-10 text-primary"><i class="fa fa-ticket"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card pv-kpi p-3 h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="pv-meta-label">Meta de Retenção</div>
                            <div class="fw-bold" style="font-size:1.5rem;line-height:1.1">95%</div>
                        </div>
                        <div class="pv-kpi-icon bg-secondary bg-opacity-10 text-secondary"><i class="fa fa-bullseye"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row align-items-center mb-3 g-2">
            <div class="col-12 col-md-auto mb-2 mb-md-0">
                <div class="btn-group btn-group-sm pv-contract-filter" role="group" aria-label="Filtro de contratos">
                    <button type="button" id="pvFilterAllBtn" class="btn btn-primary active">Todos os Contratos</button>
                    <button type="button" id="pvFilterExpiredBtn" class="btn btn-outline-secondary">Apenas Vencidos</button>
                </div>
            </div>
            <div class="col-12 col-md text-center mb-2 mb-md-0">
                <div class="input-group input-group-sm pv-toolbar-search mx-auto" style="max-width:320px;">
                    <span class="input-group-text bg-white"><i class="fa fa-search text-muted"></i></span>
                    <input type="text" id="pvSearchInput" class="form-control" placeholder="Buscar cliente ou estágio...">
                </div>
            </div>
            <div class="col-12 col-md-auto text-md-end">
                <div class="d-flex gap-2 justify-content-md-end">
                    <div class="btn-group btn-group-sm" role="group" aria-label="Ações rápidas do kanban">
                        <button type="button" id="pvClearFiltersBtn" class="btn btn-sm project-filter-btn project-filter-btn-clear" title="Limpar filtros" aria-label="Limpar filtros">
                            <i class="fa fa-eraser" aria-hidden="true"></i>
                        </button>
                        <button type="button" id="pvCompactKanbanBtn" class="btn btn-sm project-filter-btn project-filter-btn-compact" title="Compactar cards do kanban" aria-label="Compactar cards do kanban">
                            <i class="fa fa-compress-alt" id="pvCompactKanbanIcon" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="btn-group btn-group-sm" role="group" aria-label="Toggle view">
                        <button type="button" id="pvViewKanbanBtn" class="btn btn-primary active">Kanban</button>
                        <button type="button" id="pvViewTableBtn" class="btn btn-outline-secondary">Tabela</button>
                    </div>
                </div>
            </div>
        </div>

        <div id="pvStagesNotice" class="alert alert-warning d-none"><i class="fa fa-exclamation-triangle me-2"></i>Não há colunas de pós-venda configuradas. Abra "Gerenciar Colunas" para criar as etapas.</div>

        <div id="pvKanbanWrapper" class="mb-4"></div>
        <div id="pvTableWrapper" class="table-responsive d-none">
            <table class="table table-hover align-middle" id="pvTable">
                <thead class="table-light">
                    <tr>
                        <th>Cliente</th>
                        <th>Estágio</th>
                        <th>Instalação</th>
                        <th>Próx. Manutenção</th>
                        <th>Garantia</th>
                        <th>Status</th>
                        <th>Acesso</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody id="pvTableBody"></tbody>
            </table>
        </div>

        <div id="pvEmptyState" class="alert alert-info d-none"><i class="fa fa-info-circle me-2"></i>Nenhum cliente em pós-venda cadastrado ainda. Clique em "Adicionar" para começar.</div>

        <!-- Add button removido: agora está no topo ao lado do Gerenciar Colunas -->

    </main>

    <aside id="pvDetailsPanel" class="pv-details-panel hidden">
        <div class="pv-details-panel-inner">
            <button id="pvDetailsCloseBtn" type="button" class="btn btn-sm btn-light pv-details-close" title="Fechar">✕</button>
            <h5 class="mb-3 pe-4" id="pvDetailsTitle">Detalhes do Cliente</h5>
            <div id="pvDetailsLoading" class="text-muted small">Carregando detalhes...</div>
            <div id="pvDetailsContent" class="d-none">
                <div class="border rounded p-3 mb-3 bg-light">
                    <h6 class="mb-2">Dados do Pós-venda</h6>
                    <div class="small" id="pvPostSaleDetailsGrid"></div>
                </div>
                <div class="border rounded p-3 mb-3">
                    <h6 class="mb-2">Histórico do Pós-venda</h6>
                    <div id="pvHistoryPostSale" class="small"></div>
                </div>
                <div class="border rounded p-3 mb-3 bg-light" id="pvProjectDetailsSection">
                    <h6 class="mb-2">Dados do Projeto de Origem</h6>
                    <div class="small" id="pvProjectDetailsGrid"></div>
                </div>
                <div class="border rounded p-3 mb-3" id="pvProjectHistorySection">
                    <h6 class="mb-2">Histórico do Projeto</h6>
                    <div id="pvHistoryProject" class="small"></div>
                </div>
                <div class="border rounded p-3 mb-3 bg-light" id="pvLeadDetailsSection">
                    <h6 class="mb-2">Dados do Lead</h6>
                    <div class="small" id="pvLeadDetailsGrid"></div>
                </div>
            </div>
        </div>
    </aside>
</div>

<!-- ════════════════ Modal: Edit / New ════════════════ -->
<div class="modal fade" id="pvModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white py-2">
        <h5 class="modal-title fs-6" id="pvModalTitle">Registro Pós-venda</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="pvForm">
      <div class="modal-body row g-3">
        <input type="hidden" name="action" value="save_pv">
        <input type="hidden" name="id" id="pvId">
        <input type="hidden" name="project_id" id="pvProjectId">

        <div class="col-12">
          <label class="form-label fw-semibold">Cliente</label>
          <input type="text" name="client_name" id="pvClientName" class="form-control" placeholder="Nome do cliente" required>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">CPF</label>
          <input type="text" name="cpf" id="pvCpf" class="form-control" placeholder="000.000.000-00">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Data de Nascimento</label>
          <input type="date" name="birth_date" id="pvBirthDate" class="form-control">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Telefone</label>
          <input type="text" name="phone" id="pvPhone" class="form-control" placeholder="(11) 99999-9999">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">E-mail</label>
          <input type="email" name="email" id="pvEmail" class="form-control" placeholder="email@dominio.com">
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold">Endereço</label>
          <textarea name="address" id="pvAddress" class="form-control" rows="2" placeholder="Endereço do cliente"></textarea>
        </div>

        <!-- Project picker (only for new) -->
        <div class="col-12" id="pvProjectPickerWrap">
          <label class="form-label fw-semibold">Projeto</label>
          <select class="form-select" id="pvProjectSelect">
            <option value="">— Selecione o projeto —</option>
            <?php foreach ($projetosDisponiveis as $prj): ?>
              <option value="<?= $prj['id'] ?>" data-name="<?= htmlspecialchars($prj['client_name'],ENT_QUOTES) ?>" data-kwh="<?= htmlspecialchars((string)($prj['projeto'] ?? ''), ENT_QUOTES) ?>" data-phone="<?= htmlspecialchars((string)($prj['lead_phone'] ?? ''), ENT_QUOTES) ?>" data-email="<?= htmlspecialchars((string)($prj['lead_email'] ?? ''), ENT_QUOTES) ?>" data-cpf="<?= htmlspecialchars((string)($prj['lead_cpf'] ?? ''), ENT_QUOTES) ?>" data-address="<?= htmlspecialchars((string)($prj['proj_address'] ?? ''), ENT_QUOTES) ?>">
                <?= htmlspecialchars($prj['client_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label fw-semibold">Data de Instalação (Homologação)</label>
          <input type="date" name="installation_date" id="pvInstDate" class="form-control">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Tipo de Cliente</label>
                    <select name="client_type" id="pvClientType" class="form-select">
                        <option value="">— Selecione o tipo de cliente —</option>
                    </select>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Estágio no Pós-venda</label>
          <select name="stage" id="pvStage" class="form-select">
            <option value="">— Selecione o estágio —</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Status de Acesso</label>
                    <select name="client_status" id="pvClientStatus" class="form-select">
                        <option value="">— Selecione o status de acesso —</option>
                    </select>
        </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Valor do Plano</label>
                    <input type="text" name="plan_value" id="pvProposalValue" class="form-control" inputmode="numeric" placeholder="R$ 0,00" autocomplete="off">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">kWh do Projeto</label>
                    <input type="text" name="project_kwh" id="pvProjectKwh" class="form-control" inputmode="decimal" placeholder="Ex: 4500">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Equipamento</label>
                    <input type="text" name="equipment" id="pvEquipment" class="form-control" placeholder="Digite o equipamento">
                </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Performance (%)</label>
          <input type="number" name="performance_pct" id="pvPerf" class="form-control" min="0" max="999" step="0.1" placeholder="ex: 98.5">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Último Check-up</label>
          <input type="date" name="last_checkup" id="pvLastCheckup" class="form-control">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Próx. Manutenção</label>
          <input type="date" name="next_maintenance" id="pvNextMaint" class="form-control">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Meses de Garantia</label>
          <input type="number" name="warranty_months" id="pvWarrantyMonths" class="form-control" min="1" max="120" value="12">
          <div class="form-text">Conta a partir da data de criação do card em pós-venda.</div>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold">Notas</label>
          <textarea name="notes" id="pvNotes" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary btn-sm" id="pvSaveBtn"><i class="fa fa-floppy-disk me-1"></i>Salvar</button>
      </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="pvDeleteConfirmModal" tabindex="-1">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white py-2">
        <h5 class="modal-title fs-6">Confirmar exclusão</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="pvDeleteConfirmForm">
      <div class="modal-body">
          <input type="hidden" id="pvDeleteId" name="pv_id">
          <p>Informe a senha do usuário logado para confirmar a exclusão deste card de pós-venda.</p>
          <div class="mb-3">
            <label for="pvDeletePassword" class="form-label">Senha</label>
            <input type="password" id="pvDeletePassword" name="password" class="form-control" placeholder="Senha" required>
          </div>
          <div id="pvDeleteError" class="text-danger small"></div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
      </div>
      </form>
    </div>
  </div>
</div>

<!-- ════════════════ Modal: Configurar Campos (Tipo/Status) ════════════════ -->
<div class="modal fade" id="pvFieldsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white py-2">
                <h5 class="modal-title fs-6">Configurar Campos de Cadastro</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-2">Tipo de Cliente</h6>
                            <div class="d-flex gap-2 mb-3">
                                <input type="text" id="pvClientTypeNameInput" class="form-control" placeholder="Novo tipo de cliente">
                                <button type="button" id="pvClientTypeAddBtn" class="btn btn-primary">Adicionar</button>
                            </div>
                            <div id="pvClientTypeList" class="list-group"></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-2">Status de Acesso</h6>
                            <div class="d-flex gap-2 mb-3">
                                <input type="text" id="pvClientStatusNameInput" class="form-control" placeholder="Novo status de acesso">
                                <button type="button" id="pvClientStatusAddBtn" class="btn btn-primary">Adicionar</button>
                            </div>
                            <div id="pvClientStatusList" class="list-group"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- ════════════════ Modal: Gerenciar Colunas Pós-venda ════════════════ -->
<div class="modal fade" id="pvStagesModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-secondary text-white py-2">
        <h5 class="modal-title fs-6">Colunas de Pós-venda</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex gap-2 mb-3">
          <input type="text" id="pvStageNameInput" class="form-control" placeholder="Nome da coluna">
          <input type="color" id="pvStageColorInput" class="form-control form-control-color" value="#6c757d" title="Cor da coluna">
          <button type="button" id="pvStageAddBtn" class="btn btn-primary">Adicionar</button>
        </div>
                <div class="alert alert-light border small mb-3">
                    Marque uma coluna como destino do SLA de renovação para que clientes com 11 meses pós-homologação sejam movidos automaticamente para ela.
                </div>
        <div id="pvStagesList" class="list-group"></div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<!-- ════════════════ Modal: Referral Link ════════════════ -->
<div class="modal fade" id="pvLinkModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title"><i class="fa fa-share-nodes me-2"></i>Link Amigo Solar</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-2">Compartilhe com <strong id="pvLinkClientName"></strong>:</p>
        <div class="input-group input-group-sm">
          <input type="text" id="pvLinkInput" class="form-control form-control-sm" readonly>
          <button class="btn btn-outline-primary btn-sm" id="pvLinkCopy"><i class="fa fa-copy"></i></button>
        </div>
        <div id="pvLinkCopied" class="text-success small mt-1 d-none"><i class="fa fa-check me-1"></i>Copiado!</div>
      </div>
    </div>
  </div>
</div>

<!-- ════════════════ Modal: Indicações ════════════════ -->
<div class="modal fade" id="pvReferralsModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title fs-6">Indicações recebidas</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="pvReferralsLoading" class="text-center py-4">
          <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Carregando...</span></div>
        </div>
        <div id="pvReferralsEmpty" class="alert alert-light d-none">Nenhuma indicação registrada até agora.</div>
        <div class="table-responsive d-none" id="pvReferralsTableWrap">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Cliente Pós-venda</th>
                <th>Indicador</th>
                <th>Telefone</th>
                <th>E-mail</th>
                <th>Observações</th>
                <th>Data</th>
              </tr>
            </thead>
            <tbody id="pvReferralsTableBody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<!-- ════════════════ Modal: Agendar Limpeza ════════════════ -->
<div class="modal fade" id="pvLimpezaModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title"><i class="fa fa-broom me-2 text-teal"></i>Agendar Limpeza</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small mb-2">Cliente: <strong id="pvLimpezaClientName"></strong></p>
        <label class="form-label small fw-semibold">Data da limpeza</label>
        <input type="date" id="pvLimpezaDate" class="form-control form-control-sm">
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-sm" style="background:#0d9488;color:#fff;" id="pvLimpezaConfirm">Confirmar</button>
      </div>
    </div>
  </div>
</div>

<!-- Preloading overlay: PDF history -->
<div id="pvHistoryOverlay" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,.65); z-index:9999; align-items:center; justify-content:center; flex-direction:column;">
  <div style="background:#fff; border-radius:16px; padding:2rem 2.5rem; text-align:center; max-width:340px;">
    <div style="font-size:2rem; margin-bottom:.5rem;">📄</div>
    <div style="font-weight:700; font-size:1rem; margin-bottom:.25rem;">Gerando histórico PDF…</div>
    <div id="pvHistoryOverlayName" style="font-size:.85rem; color:#64748b; margin-bottom:1rem;"></div>
    <div class="spinner-border text-primary" role="status" style="width:2rem; height:2rem;"></div>
    <div style="font-size:.75rem; color:#94a3b8; margin-top:.75rem;">Uma nova aba será aberta com o relatório.<br>Selecione "Salvar como PDF" para baixar.</div>
  </div>
</div>

<script>
(function(){
    // ── helpers ──
    const $  = id => document.getElementById(id);
    const bsModal = id => {
        const modalEl = $(id);
        if (!modalEl) return null;
        return bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    };
    const posVendas = <?= json_encode($posVendas, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    let posVendaStages = [];
    let posVendaClientTypes = [];
    let posVendaAccessStatuses = [];
    let currentView = 'table';
    let currentSearch = '';
    let currentContractFilter = 'all';
    let isKanbanCompact = localStorage.getItem('pvKanbanCompact') === '1';
    let _limpezaPvId = null;

    function applyKanbanCompactState(){
        $('pvKanbanWrapper').classList.toggle('pv-kanban-compact', isKanbanCompact);
        const icon = $('pvCompactKanbanIcon');
        if (icon) {
            icon.classList.toggle('fa-compress', !isKanbanCompact);
            icon.classList.toggle('fa-expand', isKanbanCompact);
        }
    }

    function normalize(value){
        return String(value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    function getPosVendaById(id){
        return posVendas.find(pv => String(pv.id) === String(id)) || null;
    }

    function parseIsoDate(raw){
        if (!raw) return null;
        const str = String(raw).slice(0, 10);
        if (!/^\d{4}-\d{2}-\d{2}$/.test(str)) return null;
        const d = new Date(str + 'T00:00:00');
        if (Number.isNaN(d.getTime())) return null;
        return d;
    }

    function addMonths(date, months){
        const d = new Date(date.getTime());
        const targetMonth = d.getMonth() + months;
        d.setMonth(targetMonth);
        if (d.getMonth() !== ((targetMonth % 12) + 12) % 12) {
            d.setDate(0);
        }
        return d;
    }

    function getWarrantyProgress(pv){
        const created = parseIsoDate(pv.created_at);
        if (!created) return null;

        let endDate = parseIsoDate(pv.warranty_end);
        if (!endDate) {
            endDate = addMonths(created, 12);
        }

        const today = new Date();
        today.setHours(0,0,0,0);
        const totalDays = Math.max(1, Math.round((endDate.getTime() - created.getTime()) / 86400000));
        const remainingDays = Math.max(0, Math.ceil((endDate.getTime() - today.getTime()) / 86400000));
        const elapsed = Math.max(0, Math.min(totalDays, totalDays - remainingDays));
        const progressPct = Math.min(100, Math.max(0, Math.round((elapsed / totalDays) * 100)));
        return { endDate, totalDays, remainingDays, progressPct };
    }

    function isExpiredContract(pv){
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        const warranty = parseIsoDate(pv.warranty_end);
        const maintenance = parseIsoDate(pv.next_maintenance);
        const byStatus = String(pv.client_status || '').trim() === 'Ex-Cliente';
        const byWarranty = !!(warranty && warranty < today);
        const byMaintenance = !!(maintenance && maintenance < today);

        return byStatus || byWarranty || byMaintenance;
    }

    function filteredPosVendas(){
        const term = normalize(currentSearch).trim();
        let base = posVendas;
        if (currentContractFilter === 'expired') {
            base = base.filter(isExpiredContract);
        }
        if (!term) return base;
        return base.filter(pv => {
            const haystack = [
                pv.client_name,
                pv.stage,
                pv.client_status,
                pv.client_type,
                pv.proj_status,
                pv.address
            ].map(normalize).join(' ');
            return haystack.includes(term);
        });
    }

    async function fetchPosVendaStages(){
        const res = await fetch('includes/pos_venda_stages_api.php?action=list&global=1');
        if (!res.ok) return [];
        const data = await res.json();
        posVendaStages = Array.isArray(data) ? data : [];
        return posVendaStages;
    }

    function buildStageOption(stage){
        return `<option value="${escapeHtml(stage.name)}">${escapeHtml(stage.name)}</option>`;
    }

    async function fetchPosVendaFieldOptions(fieldKey){
        const res = await fetch(`includes/pos_venda_fields_api.php?action=list&field_key=${encodeURIComponent(fieldKey)}`);
        if (!res.ok) return [];
        const data = await res.json();
        return Array.isArray(data) ? data : [];
    }

    function ensureSelectOption(selectEl, value, fallbackLabel){
        const normalizedValue = String(value || '').trim();
        if (!normalizedValue) return;
        const exists = Array.from(selectEl.options).some(opt => String(opt.value).trim() === normalizedValue);
        if (!exists) {
            const option = document.createElement('option');
            option.value = normalizedValue;
            option.textContent = fallbackLabel || normalizedValue;
            selectEl.appendChild(option);
        }
    }

    function populateClientTypeSelect(selectedValue = ''){
        const select = $('pvClientType');
        const optionsHtml = posVendaClientTypes
            .map(item => `<option value="${escapeHtml(item.name)}">${escapeHtml(item.name)}</option>`)
            .join('');
        select.innerHTML = '<option value="">— Selecione o tipo de cliente —</option>' + optionsHtml;
        ensureSelectOption(select, selectedValue);
        select.value = String(selectedValue || '').trim();
    }

    function populateClientStatusSelect(selectedValue = ''){
        const select = $('pvClientStatus');
        const optionsHtml = posVendaAccessStatuses
            .map(item => `<option value="${escapeHtml(item.name)}">${escapeHtml(item.name)}</option>`)
            .join('');
        select.innerHTML = '<option value="">— Selecione o status de acesso —</option>' + optionsHtml;
        ensureSelectOption(select, selectedValue);
        select.value = String(selectedValue || '').trim();
    }

    async function refreshFieldOptions(){
        const [types, statuses] = await Promise.all([
            fetchPosVendaFieldOptions('client_type'),
            fetchPosVendaFieldOptions('client_status')
        ]);
        posVendaClientTypes = types;
        posVendaAccessStatuses = statuses;
        populateClientTypeSelect();
        populateClientStatusSelect();
    }

    async function populateStageSelect(){
        const stages = await fetchPosVendaStages();
        const select = $('pvStage');
        select.innerHTML = '<option value="">— Selecione o estágio —</option>' + stages.map(buildStageOption).join('');
        $('pvStagesNotice').classList.toggle('d-none', stages.length > 0);
        return stages;
    }

    function escapeHtml(value){
        return String(value || '').replace(/[&<>"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[ch]);
    }

    function formatCurrencyBRL(value){
        const raw = String(value ?? '').trim();
        if (!raw) return 'R$ 0,00';

        const sanitized = raw.replace(/\s|R\$/gi, '');
        let normalized = sanitized;

        // Accept both pt-BR (1.234,56) and DB/en (1234.56 or 1,234.56) inputs.
        if (sanitized.includes(',') && sanitized.includes('.')) {
            if (sanitized.lastIndexOf(',') > sanitized.lastIndexOf('.')) {
                normalized = sanitized.replace(/\./g, '').replace(',', '.');
            } else {
                normalized = sanitized.replace(/,/g, '');
            }
        } else if (sanitized.includes(',')) {
            normalized = sanitized.replace(/\./g, '').replace(',', '.');
        }

        const amount = Number(normalized);
        if (!Number.isFinite(amount)) return 'R$ 0,00';
        return amount.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function formatPhoneBR(value){
        const digits = String(value || '').replace(/\D/g, '');
        if (digits.length === 11) {
            return `(${digits.slice(0,2)}) ${digits.slice(2,7)}-${digits.slice(7)}`;
        }
        if (digits.length === 10) {
            return `(${digits.slice(0,2)}) ${digits.slice(2,6)}-${digits.slice(6)}`;
        }
        return value ? String(value) : 'Não informado';
    }

    function formatDateBR(raw){
        if (!raw || raw === '0000-00-00') return '—';
        const parts = String(raw).slice(0,10).split('-');
        if (parts.length !== 3) return raw;
        return `${parts[2]}/${parts[1]}/${parts[0]}`;
    }

    function formatDateTimeBR(raw){
        if (!raw) return '—';
        const str = String(raw).trim();
        if (!str) return '—';
        const normalized = str.replace(' ', 'T');
        const d = new Date(normalized);
        if (!Number.isNaN(d.getTime())) {
            return d.toLocaleString('pt-BR', { hour12: false });
        }
        if (/^\d{4}-\d{2}-\d{2}$/.test(str)) return formatDateBR(str);
        return str;
    }

    function toDecimalStringFromCurrency(value){
        const raw = String(value ?? '').trim();
        if (!raw) return '';
        const sanitized = raw.replace(/\s|R\$/gi, '').replace(/[^\d,.-]/g, '');
        if (!sanitized) return '';
        let normalized = sanitized;
        if (sanitized.includes(',') && sanitized.includes('.')) {
            if (sanitized.lastIndexOf(',') > sanitized.lastIndexOf('.')) {
                normalized = sanitized.replace(/\./g, '').replace(',', '.');
            } else {
                normalized = sanitized.replace(/,/g, '');
            }
        } else if (sanitized.includes(',')) {
            normalized = sanitized.replace(/\./g, '').replace(',', '.');
        }
        const amount = Number(normalized);
        if (!Number.isFinite(amount)) return '';
        return amount.toFixed(2);
    }

    function formatCurrencyInputBRL(value){
        const digits = String(value ?? '').replace(/\D/g, '');
        if (!digits) return '';
        const amount = Number(digits) / 100;
        return amount.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function setProposalInputFromValue(value){
        const input = $('pvProposalValue');
        const decimal = toDecimalStringFromCurrency(value);
        if (!decimal) {
            input.value = '';
            return;
        }
        input.value = Number(decimal).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function getPlanLabel(pv){
        return String(pv.payment_type || pv.contract || '').trim() || 'Nenhum';
    }

    function getPostSaleStatusLabel(pv){
        return isExpiredContract(pv) ? 'Expirado' : String(pv.client_status || 'Ativo').trim();
    }

    function renderStageBadge(name){
        const stage = posVendaStages.find(s => s.name === name);
        const color = stage?.color || '#6c757d';
        return `<span class="pv-stage-badge" style="background:${stage?.card_color || '#fff'}; color:#fff; border:1px solid ${color};">${escapeHtml(name || 'Sem estágio')}</span>`;
    }

    function healthColor(pv){
        const value = Number(pv.performance_pct || 0);
        if (value >= 75) return '#22c55e';
        if (value >= 40) return '#f59e0b';
        return '#f97316';
    }

    function updateKanbanColumnMeta(column){
        if (!column) return;
        const cards = column.querySelectorAll('.pv-kanban-card');
        const count = cards.length;
        const col = column.closest('.pv-kanban-col');
        const countBadge = col?.querySelector('.pv-kanban-col-header span:last-child');
        const subtitle = col?.querySelector('.pv-kanban-col-header small');

        if (countBadge) {
            countBadge.textContent = String(count);
        }
        if (subtitle) {
            subtitle.textContent = `${count} item${count !== 1 ? 's' : ''}`;
        }

        const empty = column.querySelector('.pv-kanban-empty');
        if (count === 0) {
            if (!empty) {
                column.innerHTML = '<div class="pv-kanban-empty">Nenhum item nesta coluna</div>';
            }
        } else if (empty) {
            empty.remove();
        }
    }

    function renderKanban(){
        const wrapper = $('pvKanbanWrapper');
        const items = filteredPosVendas();
        const stageMap = new Map(posVendaStages.map(stage => [stage.name, stage]));
        const showUnassigned = items.some(pv => {
            const stageName = (pv.stage || '').trim();
            return !stageName || !stageMap.has(stageName);
        });
        const stages = [...posVendaStages];
        if (showUnassigned) {
            stages.push({name:'Sem estágio', color:'#6c757d', card_color:'#ffffff'});
        }
        wrapper.innerHTML = '<div class="pv-kanban-board"></div>';
        const board = wrapper.querySelector('.pv-kanban-board');
        stages.forEach(stage => {
            const cards = items.filter(pv => {
                const stageName = (pv.stage || '').trim();
                return stageName === stage.name || (!stageName && stage.name === 'Sem estágio') || (!stageMap.has(stageName) && stage.name === 'Sem estágio');
            });
            const col = document.createElement('div');
            col.className = 'pv-kanban-col';
            col.dataset.stage = stage.name;
            const hdrBg = stage.color || '#3b82f6';
            col.innerHTML = `
                <div class="pv-kanban-col-header" style="background:${hdrBg};">
                    <div style="flex:1; min-width:0;">
                        <div class="pv-kanban-col-title">
                            <i class="fa fa-layer-group me-1" style="font-size:.98em;opacity:.8;"></i>
                            <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(stage.name)}</span>
                        </div>
                        <small style="font-size:.72rem; color:rgba(255,255,255,.75);">${cards.length} item${cards.length !== 1 ? 's' : ''}</small>
                    </div>
                    <span style="background:rgba(255,255,255,0.2); color:#fff; border:1px solid rgba(255,255,255,.4); font-size:.68rem; font-weight:700; padding:.2rem .55rem; border-radius:999px;">${cards.length}</span>
                </div>
                <div class="pv-kanban-col-body" data-stage="${escapeHtml(stage.name)}" data-stage-color="${escapeHtml(stage.color || '#6c757d')}"></div>
            `;
            board.appendChild(col);
            const body = col.querySelector('.pv-kanban-col-body');
            if (!cards.length) {
                body.innerHTML = '<div class="pv-kanban-empty">Nenhum item nesta coluna</div>';
            }
            cards.forEach(pv => {
                const card = document.createElement('div');
                card.className = 'pv-kanban-card';
                card.style.borderColor = stage.color || '#e5e7eb';
                card.style.borderLeftWidth = '3px';
                card.draggable = true;
                card.dataset.pvId = pv.id;
                const healthPct = Math.max(0, Math.min(100, Number(pv.performance_pct || 0)));
                const postSaleStatus = getPostSaleStatusLabel(pv);
                const phoneDigits = String(pv.phone || pv.lead_phone || '').replace(/\D/g, '');
                const waNum = phoneDigits.length >= 10 ? '55' + phoneDigits : '';
                const healthSection = pv.performance_pct != null
                    ? `<div class="pv-health-head">
                            <span class="label">Sa\u00fade do Sistema</span>
                            <span class="value" style="color:${healthColor(pv)}">${Math.round(healthPct)}%</span>
                        </div>
                        <div class="pv-health-bar"><span style="width:${healthPct}%; background:${healthColor(pv)}"></span></div>`
                    : '';
                const warranty = getWarrantyProgress(pv);
                const warrantySection = warranty ? `<div class="pv-progress-head">
                            <span class="label">Garantia / Assinatura</span>
                            <span class="value">${warranty.remainingDays > 0 ? `${warranty.remainingDays} dia${warranty.remainingDays !== 1 ? 's' : ''} restantes` : 'Vencido'}</span>
                        </div>
                        <div class="pv-progress-bar"><span style="width:${Math.max(2, warranty.progressPct)}%; background:${warranty.progressPct >= 90 ? '#22c55e' : (warranty.progressPct >= 70 ? '#f59e0b' : '#3b82f6')}"></span></div>` : '';
                card.innerHTML = `
                    <div class="pv-card-header-strip">
                        <div style="display:flex; align-items:center; gap:.45rem; min-width:0; flex-wrap:wrap;">
                            <span style="font-size:.72rem; font-weight:700; color:#64748b; background:#f1f5f9; border-radius:6px; padding:.1rem .4rem;">#${escapeHtml(String(pv.proj_id || pv.project_id || pv.id))}</span>
                            <span class="pv-expired-chip" style="${isExpiredContract(pv) ? 'border-color:#ef4444;color:#991b1b;background:#fee2e2;' : 'border-color:#22c55e;color:#14532d;background:#dcfce7;'}">${escapeHtml(postSaleStatus)}</span>
                        </div>
                        <span style="font-size:.68rem; font-weight:600; color:#64748b; white-space:nowrap; background:#f1f5f9; padding:.1rem .45rem; border-radius:6px;">${escapeHtml(pv.client_type || 'Degusta\u00e7\u00e3o')}</span>
                    </div>
                    <div class="pv-card-body-inner">
                        <p class="pv-card-client-name">${escapeHtml(pv.client_name)}</p>
                        <div class="pv-card-kv">
                            <span class="k">Valor</span>
                            <span class="v">${escapeHtml(formatCurrencyBRL(pv.plan_value))}</span>
                            <span class="k pv-kv-hide-compact">kWh</span>
                            <span class="v pv-kv-hide-compact">${escapeHtml(String(pv.project_kwh || '—'))}</span>
                            <span class="k pv-kv-hide-compact">Equipamento</span>
                            <span class="v pv-kv-hide-compact">${escapeHtml(String(pv.equipment || '—'))}</span>
                            <span class="k pv-kv-hide-compact">Plano</span>
                            <span class="v pv-kv-hide-compact">${escapeHtml(getPlanLabel(pv))}</span>
                            <span class="k pv-kv-hide-compact">Telefone</span>
                            <span class="v pv-kv-hide-compact">${phoneDigits ? `<a href="tel:${phoneDigits}" style="color:inherit;text-decoration:none;">${escapeHtml(formatPhoneBR(pv.phone || pv.lead_phone))}</a>` : '\u2014'}</span>
                            <span class="k pv-kv-hide-compact">Instala\u00e7\u00e3o</span>
                            <span class="v pv-kv-hide-compact">${escapeHtml(formatDateBR(pv.installation_date))}</span>
                        </div>
                        ${healthSection}
                        ${warrantySection}
                    </div>
                    <div class="pv-card-footer">
                        <button type="button" class="btn btn-sm btn-outline-danger pv-delete-btn" data-pv-id="${pv.id}" style="padding:.3rem .55rem;" title="Excluir"><i class="fa fa-trash"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-primary pv-view-details-btn" data-pv-id="${pv.id}" style="padding:.3rem .55rem;" title="Ver Detalhes"><i class="fa fa-eye"></i></button>
                        <button type="button" class="btn btn-sm btn-primary pv-edit-row" data-pv-id="${pv.id}" style="padding:.3rem .55rem;" title="Editar"><i class="fa fa-pen"></i></button>
                        ${waNum ? `<a href="https://wa.me/${waNum}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success" style="padding:.3rem .55rem;" title="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>` : ''}
                        <button type="button" class="btn btn-sm btn-outline-secondary pv-schedule-btn" data-pv-id="${pv.id}" data-pv-client="${escapeHtml(pv.client_name)}" style="padding:.3rem .55rem;" title="Agendar Limpeza"><i class="fa fa-broom"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-primary pv-link-btn" data-pv-id="${pv.id}" data-pv-client="${escapeHtml(pv.client_name)}" data-pv-token="${escapeHtml(pv.referral_token || '')}" style="padding:.3rem .55rem;" title="Link de Indicação"><i class="fa fa-share-nodes"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-dark pv-history-btn" data-pv-id="${pv.id}" data-pv-client="${escapeHtml(pv.client_name)}" style="padding:.3rem .55rem;" title="Baixar Histórico PDF"><i class="fa fa-download"></i></button>
                    </div>
                `;
                body.appendChild(card);
            });
        });
        setupKanbanDragAndDrop();
        attachDynamicListeners();
    }

    function renderTable(){
        const body = $('pvTableBody');
        const items = filteredPosVendas();
        body.innerHTML = '';
        const rows = items.map(pv => {
            const stageCell = String(pv.stage || '').trim() ? renderStageBadge(pv.stage) : '';
            return `
                <tr>
                    <td>${escapeHtml(pv.client_name)}</td>
                    <td>${stageCell}</td>
                    <td>${formatDateBR(pv.installation_date)}</td>
                    <td>${formatDateBR(pv.next_maintenance)}</td>
                    <td>${formatDateBR(pv.warranty_end)}</td>
                    <td>${escapeHtml(pv.client_status === 'Ex-Cliente' ? 'Ex-Cliente' : (pv.client_status || 'Assinante'))}</td>
                    <td>${escapeHtml(pv.client_status === 'Ex-Cliente' ? 'Bloqueado' : 'Liberado')}</td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-primary pv-edit-row" data-pv-id="${pv.id}">Editar</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary pv-schedule-btn" data-pv-id="${pv.id}" data-pv-client="${escapeHtml(pv.client_name)}">Limpeza</button>
                        <button type="button" class="btn btn-sm btn-outline-success pv-link-btn" data-pv-id="${pv.id}" data-pv-client="${escapeHtml(pv.client_name)}" data-pv-token="${escapeHtml(pv.referral_token || '')}">Link</button>
                    </td>
                </tr>
            `;
        });
        body.innerHTML = rows.join('') || '<tr><td colspan="8" class="text-center text-muted">Nenhum cliente em pós-venda encontrado.</td></tr>';
        attachDynamicListeners();
    }

    function attachDynamicListeners(){
        document.querySelectorAll('.pv-kanban-card').forEach(card => {
            card.removeEventListener('click', onKanbanCardClick);
            card.addEventListener('click', onKanbanCardClick);
        });
        document.querySelectorAll('.pv-edit-row').forEach(btn => {
            btn.removeEventListener('click', onEditRowClick);
            btn.addEventListener('click', onEditRowClick);
        });
        document.querySelectorAll('.pv-schedule-btn').forEach(btn => {
            btn.removeEventListener('click', onScheduleBtnClick);
            btn.addEventListener('click', onScheduleBtnClick);
        });
        document.querySelectorAll('.pv-link-btn').forEach(btn => {
            btn.removeEventListener('click', onLinkBtnClick);
            btn.addEventListener('click', onLinkBtnClick);
        });
        document.querySelectorAll('.pv-history-btn').forEach(btn => {
            btn.removeEventListener('click', onHistoryBtnClick);
            btn.addEventListener('click', onHistoryBtnClick);
        });
        document.querySelectorAll('.pv-delete-btn').forEach(btn => {
            btn.removeEventListener('click', onDeleteBtnClick);
            btn.addEventListener('click', onDeleteBtnClick);
        });
        document.querySelectorAll('.pv-view-details-btn').forEach(btn => {
            btn.removeEventListener('click', onViewDetailsBtnClick);
            btn.addEventListener('click', onViewDetailsBtnClick);
        });
    }

    function renderDetailList(items, emptyMessage){
        if (!Array.isArray(items) || !items.length) {
            return `<div class="text-muted">${escapeHtml(emptyMessage)}</div>`;
        }
        return items.map(item => `
            <div class="border-bottom pb-2 mb-2">
                <div class="fw-semibold">${escapeHtml(item.title || 'Evento')}</div>
                <div class="text-muted">${escapeHtml(item.date || '—')}</div>
                ${item.note ? `<div>${escapeHtml(item.note)}</div>` : ''}
            </div>
        `).join('');
    }

    async function fetchPosVendaDetails(pvId){
        const fd = new FormData();
        fd.append('action', 'get_pv_details');
        fd.append('pv_id', String(pvId));
        const response = await fetch('pos-venda.php', { method: 'POST', body: fd });
        return response.json();
    }

    function renderPosVendaDetails(payload, pv){
        const details = payload?.details || {};
        const history = payload?.history || {};

        $('pvDetailsTitle').textContent = 'Detalhes: ' + (details.client_name || pv.client_name || 'Cliente');

        const projectId = Number(details.proj_id ?? details.proj_id_join ?? pv.proj_id ?? pv.project_id ?? 0);
        const hasProject = projectId > 0;
        const projectRows = [
            ['Projeto ID', hasProject ? projectId : '—'],
            ['Lead ID', details.lead_id || details.lead_id_join || pv.lead_id || '—'],
            ['Cliente', details.proj_client_name || details.client_name || pv.client_name || '—'],
            ['Status no Projeto', details.proj_status || pv.proj_status || '—'],
            ['Valor do Projeto', formatCurrencyBRL(details.proposal_value ?? pv.proposal_value ?? 0)],
            ['kWh do Projeto', details.project_kwh || pv.project_kwh || '—'],
            ['Fechamento', formatDateBR(details.closed_date || pv.closed_date)],
            ['Criado em', formatDateTimeBR(details.proj_created_at)],
            ['Atualizado em', formatDateTimeBR(details.proj_updated_at)],
            ['Endereço', details.proj_address || pv.proj_address || pv.address || '—'],
        ];

        const leadRows = [
            ['Lead ID', details.lead_id || details.lead_id_join || pv.lead_id || '—'],
            ['Nome', details.lead_name || pv.lead_name || pv.client_name || '—'],
            ['Telefone', formatPhoneBR(details.lead_phone || pv.lead_phone || details.phone || pv.phone || '')],
            ['E-mail', details.lead_email || pv.lead_email || details.email || pv.email || '—'],
            ['CPF', details.lead_cpf || pv.lead_cpf || pv.cpf || '—'],
            ['Cidade', details.lead_city || pv.lead_city || '—'],
            ['Origem', details.lead_source || pv.lead_source || '—'],
            ['Criado em', formatDateTimeBR(details.lead_created_at)],
        ];

        const postSaleRows = [
            ['Valor do Plano', formatCurrencyBRL(details.plan_value ?? pv.plan_value ?? 0)],
            ['CPF', details.cpf || pv.cpf || details.lead_cpf || pv.lead_cpf || '—'],
            ['Telefone', details.phone || pv.phone || details.lead_phone || pv.lead_phone || '—'],
            ['E-mail', details.email || pv.email || details.lead_email || pv.lead_email || '—'],
            ['Endereço', details.proj_address || details.address || pv.address || pv.proj_address || '—'],
            ['Equipamento', details.equipment || pv.equipment || '—'],
            ['Estágio', details.stage || pv.stage || 'Sem estágio'],
            ['Status', details.client_status || pv.client_status || 'Assinante'],
            ['Plano', details.payment_type || pv.payment_type || details.contract || pv.contract || 'Nenhum'],
            ['Status Pagamento', details.payment_status || pv.payment_status || '—'],
            ['Instalação', formatDateBR(details.installation_date || pv.installation_date)],
            ['Próx. manutenção', formatDateBR(details.next_maintenance || pv.next_maintenance)],
            ['Garantia até', formatDateBR(details.warranty_end || pv.warranty_end)],
            ['Atualizado em', formatDateTimeBR(details.updated_at || pv.updated_at)],
        ];

        const projectSection = $('pvProjectDetailsSection');
        const projectHistorySection = $('pvProjectHistorySection');
        const leadDetailsSection = $('pvLeadDetailsSection');
        if (hasProject) {
            projectSection.style.display = '';
            projectHistorySection.style.display = '';
            leadDetailsSection.style.display = '';
            $('pvProjectDetailsGrid').innerHTML = projectRows.map(([label, value]) => `
                <div class="d-flex justify-content-between gap-2 border-bottom py-1">
                    <span class="text-muted">${escapeHtml(label)}</span>
                    <span class="text-end">${escapeHtml(String(value ?? '—'))}</span>
                </div>
            `).join('');
        } else {
            projectSection.style.display = 'none';
            projectHistorySection.style.display = 'none';
            leadDetailsSection.style.display = 'none';
            $('pvProjectDetailsGrid').innerHTML = '';
        }

        $('pvLeadDetailsGrid').innerHTML = leadRows.map(([label, value]) => `
            <div class="d-flex justify-content-between gap-2 border-bottom py-1">
                <span class="text-muted">${escapeHtml(label)}</span>
                <span class="text-end">${escapeHtml(String(value ?? '—'))}</span>
            </div>
        `).join('') + ((details.lead_notes || details.lead_observacao) ? `
            <div class="mt-2">
                <div class="text-muted mb-1">Observações do lead</div>
                <div class="p-2 border rounded bg-white" style="white-space:pre-wrap;">${escapeHtml(String((details.lead_notes || '') + '\n' + (details.lead_observacao || '')).trim())}</div>
            </div>
        ` : '');

        $('pvPostSaleDetailsGrid').innerHTML = postSaleRows.map(([label, value]) => `
            <div class="d-flex justify-content-between gap-2 border-bottom py-1">
                <span class="text-muted">${escapeHtml(label)}</span>
                <span class="text-end">${escapeHtml(String(value ?? '—'))}</span>
            </div>
        `).join('') + (details.notes ? `
            <div class="mt-2">
                <div class="text-muted mb-1">Notas do pós-venda</div>
                <div class="p-2 border rounded bg-white" style="white-space:pre-wrap;">${escapeHtml(details.notes)}</div>
            </div>
        ` : '');

        const postSaleTimeline = (history.post_sale || []).map(item => ({
            title: item.title || 'Evento do pós-venda',
            date: formatDateTimeBR(item.date),
            note: item.note || ''
        }));

        const projectTimeline = (history.project || []).map(item => ({
            title: item.title || 'Evento do projeto',
            date: formatDateTimeBR(item.date),
            note: item.note || ''
        }));

        $('pvHistoryProject').innerHTML = renderDetailList(projectTimeline, 'Nenhum histórico de projeto disponível.');
        $('pvHistoryPostSale').innerHTML = renderDetailList(postSaleTimeline, 'Nenhum evento de pós-venda disponível.');
    }

    async function onKanbanCardClick(event){
        const blocked = event.target.closest('.pv-card-footer, button, a, .pv-edit-row, .pv-link-btn, .pv-schedule-btn, .pv-history-btn, .pv-delete-btn');
        if (blocked) return;

        const card = event.currentTarget;
        const pvId = card.dataset.pvId;
        const pv = getPosVendaById(pvId);
        if (!pv) return;

        await openPosVendaDetails(pv);
    }

    async function onViewDetailsBtnClick(event){
        const btn = event.currentTarget;
        const pvId = btn.dataset.pvId;
        const pv = getPosVendaById(pvId);
        if (!pv) return;

        await openPosVendaDetails(pv);
    }

    async function openPosVendaDetails(pv){
        const pvId = pv.id;
        $('pvDetailsLoading').classList.remove('d-none');
        $('pvDetailsContent').classList.add('d-none');
        $('pvDetailsTitle').textContent = 'Detalhes: ' + (pv.client_name || 'Cliente');
        $('pvMainContent').classList.add('panel-open');
        $('pvDetailsPanel').classList.remove('hidden');

        try {
            const payload = await fetchPosVendaDetails(pvId);
            if (!payload || !payload.success) {
                throw new Error(payload?.message || 'Falha ao buscar detalhes.');
            }
            renderPosVendaDetails(payload, pv);
            $('pvDetailsLoading').classList.add('d-none');
            $('pvDetailsContent').classList.remove('d-none');
        } catch (err) {
            $('pvDetailsLoading').innerHTML = `<span class="text-danger">${escapeHtml(err.message || 'Erro ao carregar detalhes.')}</span>`;
        }
    }

    function onDeleteBtnClick(event){
        const pvId = event.currentTarget.dataset.pvId;
        if (!pvId) return;
        $('pvDeleteId').value = pvId;
        $('pvDeletePassword').value = '';
        $('pvDeleteError').textContent = '';
        bsModal('pvDeleteConfirmModal').show();
    }

    async function deletePosVenda(event){
        event.preventDefault();
        const pvId = $('pvDeleteId').value;
        const password = $('pvDeletePassword').value.trim();
        if (!pvId || !password) {
            $('pvDeleteError').textContent = 'Informe a senha para confirmar a exclusão.';
            return;
        }
        const form = new FormData();
        form.append('action', 'delete_pv');
        form.append('pv_id', pvId);
        form.append('password', password);

        try {
            const response = await fetch('pos-venda.php', { method: 'POST', body: form });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Não foi possível excluir o registro.');
            }
            const modal = bsModal('pvDeleteConfirmModal');
            modal?.hide();
            $('pvDeleteError').textContent = '';
            const idx = posVendas.findIndex(v => String(v.id) === String(pvId));
            if (idx !== -1) {
                posVendas.splice(idx, 1);
            }
            renderView();
            if ($('pvDetailsPanel') && $('pvDetailsPanel').classList.contains('pv-details-panel') && !$('pvDetailsPanel').classList.contains('hidden')) {
                closeDetailsPanel();
            }
            alert('Registro de pós-venda excluído com sucesso.');
        } catch (err) {
            $('pvDeleteError').textContent = err.message || 'Erro ao excluir.';
        }
    }

    function closeDetailsPanel(){
        $('pvMainContent').classList.remove('panel-open');
        $('pvDetailsPanel').classList.add('hidden');
    }

    function onHistoryBtnClick(event){
        const btn = event.currentTarget;
        const pvId = btn.dataset.pvId;
        const clientName = btn.dataset.pvClient;

        // show overlay
        const overlay = document.getElementById('pvHistoryOverlay');
        document.getElementById('pvHistoryOverlayName').textContent = clientName;
        overlay.style.display = 'flex';

        // open in new tab — when it loads it auto-prints
        const win = window.open('api/client_history_pdf.php?pv_id=' + pvId, '_blank');

        // hide overlay after a short delay (the new tab handles the rest)
        setTimeout(() => { overlay.style.display = 'none'; }, 3000);

        if (!win) {
            overlay.style.display = 'none';
            alert('Permita pop-ups para este site para baixar o relatório PDF.');
        }
    }

    function onEditRowClick(event){
        const pv = getPosVendaById(event.currentTarget.dataset.pvId);
        if (!pv) return;
        $('pvForm').reset();
        $('pvProjectPickerWrap').classList.add('d-none');
        $('pvId').value = pv.id || '';
        $('pvProjectId').value = pv.project_id || '';
        $('pvClientName').value = pv.client_name || '';
        $('pvCpf').value = pv.cpf || pv.lead_cpf || '';
        $('pvBirthDate').value = pv.birth_date || '';
        $('pvPhone').value = pv.phone || pv.lead_phone || '';
        $('pvEmail').value = pv.email || pv.lead_email || '';
        $('pvAddress').value = pv.address || pv.proj_address || '';
        $('pvInstDate').value = pv.installation_date || '';
        $('pvNextMaint').value = pv.next_maintenance || '';
        $('pvLastCheckup').value = pv.last_checkup || '';
        const months = Number.isFinite(Number(pv.warranty_months)) && pv.warranty_months !== ''
            ? Number(pv.warranty_months)
            : (pv.warranty_end && pv.created_at ? Math.max(1, Math.round((new Date(pv.warranty_end).getTime() - new Date(pv.created_at).getTime()) / 86400000 / 30)) : 12);
        $('pvWarrantyMonths').value = months;
        $('pvNotes').value = pv.notes || '';
        $('pvPerf').value = pv.performance_pct != null ? pv.performance_pct : '';
        setProposalInputFromValue(pv.plan_value || '');
        $('pvProjectKwh').value = pv.project_kwh || '';
        $('pvEquipment').value = pv.equipment || '';
        populateClientTypeSelect(pv.client_type || '');
        populateClientStatusSelect(pv.client_status || 'Assinante');
        $('pvStage').value = pv.stage || '';
        $('pvModalTitle').textContent = 'Editar: ' + pv.client_name;
        bsModal('pvModal').show();
    }

    function onScheduleBtnClick(event){
        const btn = event.currentTarget;
        _limpezaPvId = btn.dataset.pvId;
        $('pvLimpezaClientName').textContent = btn.dataset.pvClient;
        const d = new Date(); d.setDate(d.getDate()+7);
        $('pvLimpezaDate').value = d.toISOString().split('T')[0];
        bsModal('pvLimpezaModal').show();
    }

    async function onLinkBtnClick(event){
        const btn = event.currentTarget;
        const pvId = btn.dataset.pvId;
        const clientName = btn.dataset.pvClient;
        let token = btn.dataset.pvToken || '';

        $('pvLinkClientName').textContent = clientName;

        if (!token) {
            const fd = new FormData();
            fd.append('action', 'gen_referral');
            fd.append('pv_id', pvId);
            try {
                const r = await fetch('pos-venda.php', { method:'POST', body: fd });
                const d = await r.json();
                if (!d.success) { alert('Erro ao gerar link'); return; }
                token = d.token;
                btn.dataset.pvToken = token;
                const pv = getPosVendaById(pvId);
                if (pv) pv.referral_token = token;
                $('pvLinkInput').value = d.link;
            } catch (err) {
                alert('Falha: ' + err.message);
                return;
            }
        } else {
            const basePath = window.location.origin + window.location.pathname.replace(/\/[^/]*$/, '');
            $('pvLinkInput').value = basePath + '/indicacao.php?token=' + encodeURIComponent(token);
        }

        $('pvLinkCopied').classList.add('d-none');
        bsModal('pvLinkModal').show();
    }

    async function loadReferrals(){
        const loading = $('pvReferralsLoading');
        const empty = $('pvReferralsEmpty');
        const wrap = $('pvReferralsTableWrap');
        const body = $('pvReferralsTableBody');
        loading.classList.remove('d-none');
        empty.classList.add('d-none');
        wrap.classList.add('d-none');
        body.innerHTML = '';

        try {
            const fd = new FormData();
            fd.append('action', 'list_referrals');
            const response = await fetch('pos-venda.php', { method:'POST', body: fd });
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.message || 'Falha ao carregar indicações');
            }
            const referrals = Array.isArray(data.referrals) ? data.referrals : [];
            if (!referrals.length) {
                empty.classList.remove('d-none');
            } else {
                wrap.classList.remove('d-none');
                body.innerHTML = referrals.map(ref => `
                    <tr>
                        <td>${escapeHtml(ref.pv_client_name || ('#' + (ref.pos_venda_id || '—')))}</td>
                        <td>${escapeHtml(ref.indicator_name || '—')}</td>
                        <td>${escapeHtml(ref.indicator_phone || '—')}</td>
                        <td>${escapeHtml(ref.indicator_email || '—')}</td>
                        <td>${escapeHtml(ref.notes || '—')}</td>
                        <td>${escapeHtml(formatDateTimeBR(ref.created_at || ''))}</td>
                    </tr>
                `).join('');
            }
        } catch (err) {
            empty.classList.remove('d-none');
            empty.textContent = 'Erro ao carregar indicações: ' + (err.message || '');
        } finally {
            loading.classList.add('d-none');
        }
    }

    function openReferralsModal(){
        bsModal('pvReferralsModal').show();
        loadReferrals();
    }

    function setupKanbanDragAndDrop(){
        const cards = document.querySelectorAll('.pv-kanban-card');
        const columns = document.querySelectorAll('.pv-kanban-col-body');
        let draggedId = null;

        cards.forEach(card=>{
            card.addEventListener('dragstart', ()=>{ draggedId = card.dataset.pvId; card.classList.add('opacity-50'); });
            card.addEventListener('dragend', ()=>{ card.classList.remove('opacity-50'); draggedId = null; });
        });

        columns.forEach(column => {
            column.addEventListener('dragover', e => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                column.classList.add('drop-target');
            });
            column.addEventListener('dragleave', () => { column.classList.remove('drop-target'); });
            column.addEventListener('drop', async e => {
                e.preventDefault(); column.classList.remove('drop-target');
                if (!draggedId) return;
                const targetStage = column.dataset.stage || '';
                const card = document.querySelector(`.pv-kanban-card[data-pv-id="${draggedId}"]`);
                const sourceColumn = card ? card.closest('.pv-kanban-col-body') : null;
                if (!card || !sourceColumn || sourceColumn === column) {
                    draggedId = null;
                    return;
                }
                const form = new FormData();
                form.append('action','update_stage');
                form.append('pv_id', draggedId);
                form.append('stage', targetStage);

                try {
                    const response = await fetch('pos-venda.php', { method:'POST', body: form });
                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        throw new Error(data?.message || 'Falha ao atualizar estágio');
                    }

                    const stageField = posVendas.find(v => String(v.id) === String(draggedId));
                    if (stageField) {
                        stageField.stage = targetStage;
                    }

                    const targetColor = column.dataset.stageColor || '#6c757d';
                    column.querySelector('.pv-kanban-empty')?.remove();
                    column.appendChild(card);
                    card.style.borderColor = targetColor;

                    updateKanbanColumnMeta(sourceColumn);
                    updateKanbanColumnMeta(column);
                } catch (err) {
                    alert('Não foi possível mover o card: ' + (err.message || err));
                } finally {
                    draggedId = null;
                }
            });
        });
    }

    async function renderView(){
        const kanban = $('pvKanbanWrapper');
        const table = $('pvTableWrapper');
        const empty = $('pvEmptyState');
        const hasRows = posVendas.length > 0;
        const hasStages = posVendaStages.length > 0;
        const hasFilteredRows = filteredPosVendas().length > 0;

        $('pvFilterAllBtn').classList.toggle('btn-primary', currentContractFilter === 'all');
        $('pvFilterAllBtn').classList.toggle('btn-outline-secondary', currentContractFilter !== 'all');
        $('pvFilterExpiredBtn').classList.toggle('btn-primary', currentContractFilter === 'expired');
        $('pvFilterExpiredBtn').classList.toggle('btn-outline-secondary', currentContractFilter !== 'expired');

        $('pvViewKanbanBtn').classList.toggle('btn-primary', currentView === 'kanban');
        $('pvViewKanbanBtn').classList.toggle('btn-outline-secondary', currentView !== 'kanban');
        $('pvViewTableBtn').classList.toggle('btn-primary', currentView === 'table');
        $('pvViewTableBtn').classList.toggle('btn-outline-secondary', currentView !== 'table');
        applyKanbanCompactState();
        kanban.classList.toggle('d-none', currentView !== 'kanban');
        table.classList.toggle('d-none', currentView !== 'table');
        empty.classList.toggle('d-none', hasRows || hasStages);
        if (!hasRows && !hasStages) {
            kanban.innerHTML = '';
            $('pvTableBody').innerHTML = '<tr><td colspan="8" class="text-center text-muted">Nenhum cliente em pós-venda encontrado.</td></tr>';
            return;
        }
        if (hasRows && !hasFilteredRows) {
            kanban.innerHTML = '<div class="alert alert-light border">Nenhum resultado encontrado para a busca atual.</div>';
            $('pvTableBody').innerHTML = '<tr><td colspan="8" class="text-center text-muted">Nenhum resultado encontrado para a busca atual.</td></tr>';
            return;
        }
        if (currentView === 'kanban') {
            renderKanban();
        } else {
            renderTable();
        }
    }

    async function openStagesManager(){
        await renderStagesManager();
        bsModal('pvStagesModal').show();
    }

    async function openFieldsManager(){
        await renderFieldOptionsManager();
        bsModal('pvFieldsModal').show();
    }

    async function renderSingleFieldOptions(fieldKey, listElementId){
        const list = $(listElementId);
        const options = await fetchPosVendaFieldOptions(fieldKey);
        list.innerHTML = options.map((item, index) => `
            <div class="list-group-item d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <input type="text" class="form-control form-control-sm pv-field-option-name" data-id="${item.id}" data-field-key="${fieldKey}" value="${escapeHtml(item.name)}" style="min-width:220px;">
                <div class="d-flex gap-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary pv-field-option-up" data-field-key="${fieldKey}" data-index="${index}">▲</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary pv-field-option-down" data-field-key="${fieldKey}" data-index="${index}">▼</button>
                    <button type="button" class="btn btn-sm btn-danger pv-field-option-delete" data-id="${item.id}" data-field-key="${fieldKey}">Excluir</button>
                </div>
            </div>
        `).join('') || '<div class="text-muted">Nenhuma opção cadastrada.</div>';

        list.querySelectorAll('.pv-field-option-name').forEach(input => {
            input.addEventListener('blur', async () => {
                const id = input.dataset.id;
                const targetField = input.dataset.fieldKey;
                const name = input.value.trim();
                if (!name) return;
                await fetch(`includes/pos_venda_fields_api.php?action=update&field_key=${encodeURIComponent(targetField)}`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ id, name })
                });
                await refreshFieldOptions();
            });
        });

        list.querySelectorAll('.pv-field-option-delete').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!confirm('Excluir esta opção?')) return;
                const id = btn.dataset.id;
                const targetField = btn.dataset.fieldKey;
                await fetch(`includes/pos_venda_fields_api.php?action=delete&field_key=${encodeURIComponent(targetField)}`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ id })
                });
                await renderFieldOptionsManager();
                await refreshFieldOptions();
            });
        });

        list.querySelectorAll('.pv-field-option-up, .pv-field-option-down').forEach(btn => {
            btn.addEventListener('click', async () => {
                const targetField = btn.dataset.fieldKey;
                const idx = parseInt(btn.dataset.index, 10);
                const currentItems = await fetchPosVendaFieldOptions(targetField);
                const nextIdx = btn.classList.contains('pv-field-option-up') ? idx - 1 : idx + 1;
                if (nextIdx < 0 || nextIdx >= currentItems.length) return;
                const positions = currentItems.map((item, index) => ({ id: item.id, position: index + 1 }));
                const temp = positions[idx].position;
                positions[idx].position = positions[nextIdx].position;
                positions[nextIdx].position = temp;
                await fetch(`includes/pos_venda_fields_api.php?action=reorder&field_key=${encodeURIComponent(targetField)}`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ positions })
                });
                await renderFieldOptionsManager();
                await refreshFieldOptions();
            });
        });
    }

    async function renderFieldOptionsManager(){
        await renderSingleFieldOptions('client_type', 'pvClientTypeList');
        await renderSingleFieldOptions('client_status', 'pvClientStatusList');
    }

    async function renderStagesManager(){
        const list = $('pvStagesList');
        const stages = await fetchPosVendaStages();
        list.innerHTML = stages.map((stage, index) => `
            <div class="list-group-item">
                <div class="d-flex gap-2 align-items-center flex-grow-1">
                    <input type="text" class="form-control form-control-sm stage-name" data-id="${stage.id}" value="${escapeHtml(stage.name)}">
                    <input type="color" class="form-control form-control-sm stage-color" data-id="${stage.id}" value="${escapeHtml(stage.color || '#6c757d')}" style="width:3rem; padding:0;">
                </div>
                <div class="stage-sla-toggle">
                    <div class="form-check form-switch mb-0">
                        <input type="checkbox" class="form-check-input stage-sla-target" data-id="${stage.id}" ${Number(stage.sla_renewal_target || 0) === 1 ? 'checked' : ''}>
                        <label class="form-check-label small text-muted">SLA 11 meses</label>
                    </div>
                </div>
                <div class="stage-controls">
                    <button type="button" class="btn btn-sm btn-outline-secondary stage-move-up" data-index="${index}">▲</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary stage-move-down" data-index="${index}">▼</button>
                    <button type="button" class="btn btn-sm btn-danger stage-delete" data-id="${stage.id}">Excluir</button>
                </div>
            </div>
        `).join('') || '<div class="text-muted">Nenhuma coluna cadastrada.</div>';

        list.querySelectorAll('.stage-delete').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!confirm('Excluir esta coluna?')) return;
                await fetch('includes/pos_venda_stages_api.php?action=delete', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id: btn.dataset.id }) });
                await renderStagesManager();
                await populateStageSelect();
                renderView();
            });
        });

        list.querySelectorAll('.stage-move-up, .stage-move-down').forEach(btn => {
            btn.addEventListener('click', async () => {
                const idx = parseInt(btn.dataset.index, 10);
                const nextIdx = btn.classList.contains('stage-move-up') ? idx - 1 : idx + 1;
                if (nextIdx < 0 || nextIdx >= stages.length) return;
                const positions = stages.map((stage, index) => ({ id: stage.id, position: index + 1 }));
                const temp = positions[idx].position;
                positions[idx].position = positions[nextIdx].position;
                positions[nextIdx].position = temp;
                positions[idx].id = stages[idx].id;
                positions[nextIdx].id = stages[nextIdx].id;
                await fetch('includes/pos_venda_stages_api.php?action=reorder', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ positions }) });
                await renderStagesManager();
                await populateStageSelect();
                renderView();
            });
        });

        list.querySelectorAll('.stage-name, .stage-color').forEach(input => {
            input.addEventListener('blur', async () => {
                const id = input.dataset.id;
                const row = input.closest('.list-group-item');
                const name = row.querySelector('.stage-name').value.trim();
                const color = row.querySelector('.stage-color').value;
                if (!name) return;
                await fetch('includes/pos_venda_stages_api.php?action=update', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id, name, color, card_color: color }) });
                await populateStageSelect();
                renderView();
            });
        });

        list.querySelectorAll('.stage-sla-target').forEach(input => {
            input.addEventListener('change', async () => {
                const id = input.dataset.id;
                const row = input.closest('.list-group-item');
                const name = row.querySelector('.stage-name').value.trim();
                const color = row.querySelector('.stage-color').value;
                if (!name) {
                    input.checked = false;
                    return;
                }
                await fetch('includes/pos_venda_stages_api.php?action=update', {
                    method:'POST',
                    headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ id, name, color, card_color: color, sla_renewal_target: input.checked })
                });
                await renderStagesManager();
                await populateStageSelect();
                renderView();
            });
        });
    }

    $('pvStageAddBtn').addEventListener('click', async () => {
        const name = $('pvStageNameInput').value.trim();
        const color = $('pvStageColorInput').value || '#6c757d';
        if (!name) { alert('Informe o nome da coluna'); return; }
        await fetch('includes/pos_venda_stages_api.php?action=add', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ name, color, card_color: '#ffffff' }) });
        $('pvStageNameInput').value = '';
        await renderStagesManager();
        await populateStageSelect();
        renderView();
    });

    $('pvClientTypeAddBtn').addEventListener('click', async () => {
        const name = $('pvClientTypeNameInput').value.trim();
        if (!name) { alert('Informe o tipo de cliente'); return; }
        await fetch('includes/pos_venda_fields_api.php?action=add&field_key=client_type', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ name })
        });
        $('pvClientTypeNameInput').value = '';
        await renderFieldOptionsManager();
        await refreshFieldOptions();
    });

    $('pvClientStatusAddBtn').addEventListener('click', async () => {
        const name = $('pvClientStatusNameInput').value.trim();
        if (!name) { alert('Informe o status de acesso'); return; }
        await fetch('includes/pos_venda_fields_api.php?action=add&field_key=client_status', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ name })
        });
        $('pvClientStatusNameInput').value = '';
        await renderFieldOptionsManager();
        await refreshFieldOptions();
    });

    $('pvFieldsConfigBtn').addEventListener('click', openFieldsManager);
    $('pvStagesConfigBtn').addEventListener('click', openStagesManager);
    $('pvDeleteConfirmForm').addEventListener('submit', deletePosVenda);

    $('pvViewKanbanBtn').addEventListener('click', () => { currentView = 'kanban'; renderView(); });
    $('pvViewTableBtn').addEventListener('click', () => { currentView = 'table'; renderView(); });
    const pvReferralsBtn = $('pvReferralsBtn');
    if (pvReferralsBtn) {
        pvReferralsBtn.addEventListener('click', openReferralsModal);
    }
    $('pvFilterAllBtn').addEventListener('click', () => { currentContractFilter = 'all'; renderView(); });
    $('pvFilterExpiredBtn').addEventListener('click', () => { currentContractFilter = 'expired'; renderView(); });
    $('pvClearFiltersBtn').addEventListener('click', () => {
        currentContractFilter = 'all';
        currentSearch = '';
        $('pvSearchInput').value = '';
        renderView();
    });
    $('pvCompactKanbanBtn').addEventListener('click', () => {
        isKanbanCompact = !isKanbanCompact;
        localStorage.setItem('pvKanbanCompact', isKanbanCompact ? '1' : '0');
        applyKanbanCompactState();
        if (currentView === 'kanban') {
            renderKanban();
        }
    });
    $('pvSearchInput').addEventListener('input', (event) => {
        currentSearch = event.target.value || '';
        renderView();
    });

    async function initializePosVenda(){
        const [stages] = await Promise.all([
            populateStageSelect(),
            refreshFieldOptions()
        ]);
        currentView = stages.length > 0 ? 'kanban' : 'table';
        renderView();
    }

    initializePosVenda();
    applyKanbanCompactState();

    $('pvDetailsCloseBtn').addEventListener('click', closeDetailsPanel);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeDetailsPanel();
        }
    });

    // ── Open: New record ──
    $('pvNewBtn').addEventListener('click', ()=>{
        $('pvForm').reset();
        $('pvId').value = '';
        $('pvProjectId').value = '';
        $('pvClientName').value = '';
        $('pvCpf').value = '';
        $('pvBirthDate').value = '';
        $('pvPhone').value = '';
        $('pvEmail').value = '';
        $('pvAddress').value = '';
        $('pvProjectPickerWrap').classList.remove('d-none');
        $('pvWarrantyMonths').value = 12;
        $('pvProposalValue').value = '';
        $('pvProjectKwh').value = '';
        $('pvEquipment').value = '';
        populateClientTypeSelect();
        populateClientStatusSelect('Assinante');
        $('pvStage').value = '';
        $('pvModalTitle').textContent = 'Novo Registro Pós-venda';
        bsModal('pvModal').show();
    });

    // project select → populate client fields
    $('pvProjectSelect').addEventListener('change', ()=>{
        const opt = $('pvProjectSelect').selectedOptions[0];
        $('pvProjectId').value = opt?.value || '';
        $('pvClientName').value = opt?.dataset?.name || '';
        $('pvProjectKwh').value = opt?.dataset?.kwh || '';
        if (!$('pvCpf').value && opt?.dataset?.cpf) {
            $('pvCpf').value = opt.dataset.cpf;
        }
        if (!$('pvPhone').value && opt?.dataset?.phone) {
            $('pvPhone').value = opt.dataset.phone;
        }
        if (!$('pvEmail').value && opt?.dataset?.email) {
            $('pvEmail').value = opt.dataset.email;
        }
        if (!$('pvAddress').value && opt?.dataset?.address) {
            $('pvAddress').value = opt.dataset.address;
        }
    });

    $('pvProposalValue').addEventListener('input', (event) => {
        event.target.value = formatCurrencyInputBRL(event.target.value);
    });

    $('pvProposalValue').addEventListener('blur', (event) => {
        const decimal = toDecimalStringFromCurrency(event.target.value);
        event.target.value = decimal ? Number(decimal).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) : '';
    });

    function computeDefaultWarranty(){
        const instDate = $('pvInstDate').value;
        if (!instDate) return;
        const currentMonths = Number($('pvWarrantyMonths').value);
        if (Number.isFinite(currentMonths) && currentMonths >= 1) return;
        $('pvWarrantyMonths').value = 12;
    }

    $('pvInstDate').addEventListener('change', computeDefaultWarranty);

    // ── Save form ──
    $('pvForm').addEventListener('submit', async (e)=>{
        e.preventDefault();
        const saveBtn = $('pvSaveBtn');
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';
        try {
            const fd = new FormData(e.target);
            fd.set('plan_value', toDecimalStringFromCurrency($('pvProposalValue').value));
            const res = await fetch('pos-venda.php', {method:'POST', body:fd});
            const data = await res.json();
            if (data.success) { location.reload(); }
            else { alert('Erro ao salvar: ' + (data.message || '')); }
        } catch(err){ alert('Falha: ' + err.message); }
        finally { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fa fa-floppy-disk me-1"></i>Salvar'; }
    });

    $('pvLimpezaConfirm').addEventListener('click', async ()=>{
        const date = $('pvLimpezaDate').value;
        if (!date || !_limpezaPvId) return;
        try {
            const fd = new FormData();
            fd.append('action', 'schedule_maintenance');
            fd.append('pv_id', _limpezaPvId);
            fd.append('maintenance_date', date);

            const r = await fetch('pos-venda.php', { method:'POST', body:fd });
            const d = await r.json();
            if (!d.success) {
                alert('Erro ao agendar limpeza: ' + (d.message || ''));
                return;
            }

            bootstrap.Modal.getInstance($('pvLimpezaModal'))?.hide();
            location.reload();
        } catch(err){
            alert('Falha ao agendar limpeza: ' + err.message);
        }
    });

    // Copy link
    $('pvLinkCopy').addEventListener('click', ()=>{
        const el = $('pvLinkInput');
        el.select();
        navigator.clipboard.writeText(el.value)
            .then(()=>{ $('pvLinkCopied').classList.remove('d-none'); })
            .catch(()=>{ try { document.execCommand('copy'); $('pvLinkCopied').classList.remove('d-none'); } catch(e){} });
    });

    // SLA auto-trigger is handled by the global assets/js/sla_check.js script.

})();
</script>
<script src="<?= rtrim(dirname($_SERVER['PHP_SELF']), '/\\') ?: '' ?>/assets/js/sla_check.js"></script>

<?php include 'includes/footer.php'; ?>
