<?php
// ============================================================
//  Pós-venda — Gestão de Receita Recorrente
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/permissions.php';

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
    if (!in_array('plan_value',     $cols)) $pdo->exec("ALTER TABLE pos_venda ADD COLUMN plan_value DECIMAL(12,2) DEFAULT NULL");
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
        $projId      = intval($_POST['project_id'] ?? 0);
        $clientName  = trim($_POST['client_name']      ?? '');
        $instDate      = $_POST['installation_date']     ?: null;
        $nextMaint     = $_POST['next_maintenance']      ?: null;
        $planValueRaw = trim((string)($_POST['plan_value'] ?? ''));
        $warrantyMonths= isset($_POST['warranty_months']) && trim($_POST['warranty_months']) !== '' ? max(1, intval($_POST['warranty_months'])) : 12;
        $notes         = trim($_POST['notes']            ?? '');
        $perf        = (isset($_POST['performance_pct']) && $_POST['performance_pct'] !== '') ? floatval($_POST['performance_pct']) : null;
        $clientType  = trim($_POST['client_type']      ?? 'Degustação');
        $lastCheckup = $_POST['last_checkup']          ?: null;
        $stage       = trim($_POST['stage']            ?? '');
        $clientStatus= trim($_POST['client_status']    ?? '');
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

        // update projetos.client_status
        if ($projId && $hasClientStatus) {
            $pdo->prepare('UPDATE projetos SET client_status=?, updated_at=NOW() WHERE id=? AND user_id=?')
                ->execute([$clientStatus, $projId, $_SESSION['user_id']]);
        }

        if ($projId) {
            $pdo->prepare('UPDATE projetos SET moved_to_post_sale = 1, updated_at = NOW() WHERE id = ? AND user_id = ?')
                ->execute([$projId, $_SESSION['user_id']]);
        }

        // upsert pos_venda
        $ex = $pdo->prepare('SELECT id FROM pos_venda WHERE project_id=? AND user_id=? LIMIT 1');
        $ex->execute([$projId, $_SESSION['user_id']]);
        $exId = $ex->fetchColumn();

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

        if ($exId) {
            $updateFields = ['installation_date=?','next_maintenance=?','warranty_end=?','notes=?'];
            $updateParams = [$instDate,$nextMaint,$warranty,$notes];
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
            $updateFields[] = 'updated_at=NOW()';
            $updateParams[] = $exId;
            $pdo->prepare('UPDATE pos_venda SET ' . implode(',', $updateFields) . ' WHERE id=?')
               ->execute($updateParams);
        } else {
            if (!$clientName && $projId) {
                $r = $pdo->prepare('SELECT client_name FROM projetos WHERE id=? LIMIT 1');
                $r->execute([$projId]); $clientName = $r->fetchColumn() ?: '';
            }
            $cols = ['user_id','project_id','client_name','installation_date','next_maintenance','warranty_end','notes'];
            $holders = ['?','?','?','?','?','?','?'];
            $params = [$_SESSION['user_id'],$projId,$clientName,$instDate,$nextMaint,$warranty,$notes];
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
            $cols[] = 'created_at'; $holders[] = 'NOW()';
            $cols[] = 'updated_at'; $holders[] = 'NOW()';
            $sql = 'INSERT INTO pos_venda (' . implode(',', $cols) . ') VALUES (' . implode(',', $holders) . ')';
            $pdo->prepare($sql)->execute($params);
        }
        echo json_encode(['success'=>true]); exit;
    }

    if ($action === 'schedule_maintenance') {
        $pvId = intval($_POST['pv_id'] ?? 0);
        $date = trim((string)($_POST['maintenance_date'] ?? ''));

        if ($pvId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            echo json_encode(['success'=>false,'message'=>'Dados inválidos']);
            exit;
        }

        $pdo->prepare('UPDATE pos_venda SET next_maintenance=?, updated_at=NOW() WHERE id=? AND user_id=?')
            ->execute([$date, $pvId, $_SESSION['user_id']]);

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
        $pdo->prepare('UPDATE pos_venda SET stage=?, updated_at=NOW() WHERE id=? AND user_id=?')
            ->execute([$stage ?: null, $pvId, $_SESSION['user_id']]);
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
        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'];
        echo json_encode(['success'=>true,'link'=>$base.'/indicacao/'.$token,'token'=>$token]);
        exit;
    }

    if ($action === 'get_pv_details') {
        $pvId = intval($_POST['pv_id'] ?? 0);
        if ($pvId <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido']);
            exit;
        }

        $stmt = $pdo->prepare(
            "SELECT pv.*, 
                    p.id AS proj_id,
                    p.lead_id,
                    p.status AS proj_status,
                    p.address,
                    p.proposal_value,
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

        $leadMovements = [];
        if ($leadId > 0) {
            try {
                $mvStmt = $pdo->prepare(
                    'SELECT id, from_status, to_status, note, changed_by, is_alert, created_at
                     FROM lead_movements
                     WHERE lead_id = ? AND user_id = ?
                     ORDER BY created_at DESC
                     LIMIT 30'
                );
                $mvStmt->execute([$leadId, $_SESSION['user_id']]);
                $leadMovements = $mvStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $leadMovements = [];
            }
        }

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

        usort($postSaleHistory, static function ($a, $b) {
            return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
        });

        echo json_encode([
            'success' => true,
            'details' => $details,
            'history' => [
                'lead_movements' => $leadMovements,
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
    'p.address',
    'p.proposal_value',
    'p.id AS proj_id',
    'l.id AS lead_id',
    'l.name AS lead_name',
    'l.phone AS lead_phone',
    'l.email AS lead_email',
    'l.cidade AS lead_city',
    'l.source AS lead_source',
    'pv.stage AS stage'
];
$stmt = $pdo->prepare(
    'SELECT pv.*, ' . implode(', ', $projetoSelect) . "
    FROM pos_venda pv
    LEFT JOIN projetos p ON pv.project_id = p.id
    LEFT JOIN leads l ON l.id = p.lead_id
    ORDER BY pv.installation_date DESC
"
);
$stmt->execute([]);
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
$projStmt = $pdo->prepare("SELECT id, client_name FROM projetos WHERE user_id=? AND status IN ('Fechado','Finalizado','Homologado','Concluído','Concluido') ORDER BY client_name ASC");
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
.pv-kanban-col    { width:360px; min-width:360px; max-width:360px; flex-shrink:0; background:#f8fafc; border-radius:14px; box-shadow:0 1px 8px rgba(0,0,0,.05); display:flex; flex-direction:column; max-height:72vh; }
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
.pv-kanban-col-body { padding:1rem; overflow:auto; min-height:120px; }
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
</style>

<div class="d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main class="flex-grow-1 p-4">

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
                <div class="btn-group btn-group-sm" role="group" aria-label="Toggle view">
                    <button type="button" id="pvViewKanbanBtn" class="btn btn-primary active">Kanban</button>
                    <button type="button" id="pvViewTableBtn" class="btn btn-outline-secondary">Tabela</button>
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
        <input type="hidden" name="project_id" id="pvProjectId">
        <input type="hidden" name="client_name" id="pvClientNameHidden">

        <!-- Project picker (only for new) -->
        <div class="col-12" id="pvProjectPickerWrap">
          <label class="form-label fw-semibold">Projeto</label>
          <select class="form-select" id="pvProjectSelect">
            <option value="">— Selecione o projeto —</option>
            <?php foreach ($projetosDisponiveis as $prj): ?>
                        <option value="<?= $prj['id'] ?>" data-name="<?= htmlspecialchars($prj['client_name'],ENT_QUOTES) ?>">
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

<!-- Modal: Detalhes do Lead e Histórico do Pós-venda -->
<div class="modal fade" id="pvDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="pvDetailsTitle">Detalhes do Cliente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div id="pvDetailsLoading" class="text-muted small">Carregando detalhes...</div>
                <div id="pvDetailsContent" class="d-none">
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-lg-6">
                            <div class="border rounded p-3 h-100 bg-light">
                                <h6 class="mb-2">Dados do Lead</h6>
                                <div class="small" id="pvLeadDetailsGrid"></div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-6">
                            <div class="border rounded p-3 h-100 bg-light">
                                <h6 class="mb-2">Dados do Pós-venda</h6>
                                <div class="small" id="pvPostSaleDetailsGrid"></div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-lg-4">
                            <div class="border rounded p-3 h-100">
                                <h6 class="mb-2">Histórico do Pós-venda</h6>
                                <div id="pvHistoryPostSale" class="small"></div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-4">
                            <div class="border rounded p-3 h-100">
                                <h6 class="mb-2">Movimentações do Lead</h6>
                                <div id="pvHistoryLead" class="small"></div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-4">
                            <div class="border rounded p-3 h-100">
                                <h6 class="mb-2">Lembretes do Projeto</h6>
                                <div id="pvHistoryReminders" class="small"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    // ── helpers ──
    const $  = id => document.getElementById(id);
    const bsModal = id => new bootstrap.Modal($(id));
    const posVendas = <?= json_encode($posVendas, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    let posVendaStages = [];
    let posVendaClientTypes = [];
    let posVendaAccessStatuses = [];
    let currentView = 'table';
    let currentSearch = '';
    let currentContractFilter = 'all';
    let _limpezaPvId = null;

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
                <div class="pv-kanban-col-body" data-stage="${escapeHtml(stage.name)}"></div>
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
                const phoneDigits = String(pv.lead_phone || '').replace(/\D/g, '');
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
                            <span class="k">Plano</span>
                            <span class="v">${escapeHtml(getPlanLabel(pv))}</span>
                            <span class="k">Telefone</span>
                            <span class="v">${phoneDigits ? `<a href="tel:${phoneDigits}" style="color:inherit;text-decoration:none;">${escapeHtml(formatPhoneBR(pv.lead_phone))}</a>` : '\u2014'}</span>
                            <span class="k">Instala\u00e7\u00e3o</span>
                            <span class="v">${escapeHtml(formatDateBR(pv.installation_date))}</span>
                        </div>
                        ${healthSection}
                        ${warrantySection}
                    </div>
                    <div class="pv-card-footer">
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

        const leadRows = [
            ['Lead ID', details.lead_id || details.lead_id_join || pv.lead_id || '—'],
            ['Nome', details.lead_name || pv.lead_name || pv.client_name || '—'],
            ['Telefone', formatPhoneBR(details.lead_phone || pv.lead_phone || '')],
            ['E-mail', details.lead_email || pv.lead_email || '—'],
            ['Cidade', details.lead_city || pv.lead_city || '—'],
            ['Origem', details.lead_source || pv.lead_source || '—'],
            ['Criado em', formatDateTimeBR(details.lead_created_at)],
        ];

        const postSaleRows = [
            ['Projeto ID', details.proj_id || pv.proj_id || pv.project_id || '—'],
            ['Valor do Plano', formatCurrencyBRL(details.plan_value ?? pv.plan_value ?? 0)],
            ['Estágio', details.stage || pv.stage || 'Sem estágio'],
            ['Status', details.client_status || pv.client_status || 'Assinante'],
            ['Plano', details.payment_type || pv.payment_type || details.contract || pv.contract || 'Nenhum'],
            ['Status Pagamento', details.payment_status || pv.payment_status || '—'],
            ['Instalação', formatDateBR(details.installation_date || pv.installation_date)],
            ['Próx. manutenção', formatDateBR(details.next_maintenance || pv.next_maintenance)],
            ['Garantia até', formatDateBR(details.warranty_end || pv.warranty_end)],
            ['Atualizado em', formatDateTimeBR(details.updated_at || pv.updated_at)],
        ];

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

        const leadTimeline = (history.lead_movements || []).map(item => {
            const from = String(item.from_status || '').trim();
            const to = String(item.to_status || '').trim();
            return {
                title: from || to ? `${from || '—'} -> ${to || '—'}` : 'Movimentação do lead',
                date: formatDateTimeBR(item.created_at),
                note: [item.note, item.changed_by ? `por ${item.changed_by}` : ''].filter(Boolean).join(' | ')
            };
        });

        const remindersTimeline = (history.reminders || []).map(item => ({
            title: item.title || 'Lembrete',
            date: formatDateTimeBR(item.remind_at || item.created_at),
            note: [item.message || '', item.status ? `status: ${item.status}` : ''].filter(Boolean).join(' | ')
        }));

        $('pvHistoryPostSale').innerHTML = renderDetailList(postSaleTimeline, 'Nenhum evento de pós-venda disponível.');
        $('pvHistoryLead').innerHTML = renderDetailList(leadTimeline, 'Nenhuma movimentação de lead encontrada.');
        $('pvHistoryReminders').innerHTML = renderDetailList(remindersTimeline, 'Nenhum lembrete cadastrado para este projeto.');
    }

    async function onKanbanCardClick(event){
        const blocked = event.target.closest('.pv-card-footer, button, a, .pv-edit-row, .pv-link-btn, .pv-schedule-btn, .pv-history-btn');
        if (blocked) return;

        const card = event.currentTarget;
        const pvId = card.dataset.pvId;
        const pv = getPosVendaById(pvId);
        if (!pv) return;

        $('pvDetailsLoading').classList.remove('d-none');
        $('pvDetailsContent').classList.add('d-none');
        $('pvDetailsTitle').textContent = 'Detalhes: ' + (pv.client_name || 'Cliente');
        bsModal('pvDetailsModal').show();

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
        $('pvProjectId').value = pv.project_id || '';
        $('pvClientNameHidden').value = pv.client_name || '';
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
            $('pvLinkInput').value = window.location.origin + '/indicacao/' + token;
        }

        $('pvLinkCopied').classList.add('d-none');
        bsModal('pvLinkModal').show();
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
            column.addEventListener('dragover', e => { e.preventDefault(); column.classList.add('border','border-primary'); });
            column.addEventListener('dragleave', () => { column.classList.remove('border','border-primary'); });
            column.addEventListener('drop', async e => {
                e.preventDefault(); column.classList.remove('border','border-primary');
                if (!draggedId) return;
                const targetStage = column.dataset.stage || '';
                const form = new FormData();
                form.append('action','update_stage');
                form.append('pv_id', draggedId);
                form.append('stage', targetStage);
                await fetch('pos-venda.php', { method:'POST', body: form });
                const stageField = posVendas.find(v => String(v.id) === String(draggedId));
                if (stageField) stageField.stage = targetStage;
                renderView();
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

    $('pvViewKanbanBtn').addEventListener('click', () => { currentView = 'kanban'; renderView(); });
    $('pvViewTableBtn').addEventListener('click', () => { currentView = 'table'; renderView(); });
    $('pvFilterAllBtn').addEventListener('click', () => { currentContractFilter = 'all'; renderView(); });
    $('pvFilterExpiredBtn').addEventListener('click', () => { currentContractFilter = 'expired'; renderView(); });
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

    // ── Open: New record ──
    $('pvNewBtn').addEventListener('click', ()=>{
        $('pvForm').reset();
        $('pvProjectId').value = '';
        $('pvClientNameHidden').value = '';
        $('pvProjectPickerWrap').classList.remove('d-none');
        $('pvWarrantyMonths').value = 12;
        $('pvProposalValue').value = '';
        populateClientTypeSelect();
        populateClientStatusSelect('Assinante');
        $('pvModalTitle').textContent = 'Novo Registro Pós-venda';
        bsModal('pvModal').show();
    });

    // project select → populate hidden fields
    $('pvProjectSelect').addEventListener('change', ()=>{
        const opt = $('pvProjectSelect').selectedOptions[0];
        $('pvProjectId').value = opt?.value || '';
        $('pvClientNameHidden').value = opt?.dataset?.name || '';
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
<script src="/WRCRM/assets/js/sla_check.js"></script>

<?php include 'includes/footer.php'; ?>
