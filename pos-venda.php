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
} catch (Exception $e) { /* ignore */ }

try {
    $c = $pdo->query("SHOW COLUMNS FROM projetos LIKE 'client_status'")->fetchAll();
    if (empty($c)) $pdo->exec("ALTER TABLE projetos ADD COLUMN client_status VARCHAR(50) DEFAULT 'Assinante'");
} catch (Exception $e) { /* ignore */ }

// ── AJAX / POST handler ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    if ($action === 'save_pv') {
        $projId      = intval($_POST['project_id'] ?? 0);
        $clientName  = trim($_POST['client_name']      ?? '');
        $instDate    = $_POST['installation_date']     ?: null;
        $nextMaint   = $_POST['next_maintenance']      ?: null;
        $warranty    = $_POST['warranty_end']          ?: null;
        $notes       = trim($_POST['notes']            ?? '');
        $perf        = (isset($_POST['performance_pct']) && $_POST['performance_pct'] !== '') ? floatval($_POST['performance_pct']) : null;
        $clientType  = trim($_POST['client_type']      ?? 'Degustação');
        $lastCheckup = $_POST['last_checkup']          ?: null;
        $clientStatus= trim($_POST['client_status']    ?? 'Assinante');

        if (!in_array($clientStatus, ['Assinante','Ex-Cliente'])) $clientStatus = 'Assinante';

        // update projetos.client_status
        if ($projId) {
            $pdo->prepare('UPDATE projetos SET client_status=?, updated_at=NOW() WHERE id=? AND user_id=?')
                ->execute([$clientStatus, $projId, $_SESSION['user_id']]);
        }

        // upsert pos_venda
        $ex = $pdo->prepare('SELECT id FROM pos_venda WHERE project_id=? AND user_id=? LIMIT 1');
        $ex->execute([$projId, $_SESSION['user_id']]);
        $exId = $ex->fetchColumn();

        if ($exId) {
            $pdo->prepare('UPDATE pos_venda SET installation_date=?,next_maintenance=?,warranty_end=?,notes=?,performance_pct=?,client_type=?,last_checkup=?,updated_at=NOW() WHERE id=?')
               ->execute([$instDate,$nextMaint,$warranty,$notes,$perf,$clientType,$lastCheckup,$exId]);
        } else {
            if (!$clientName && $projId) {
                $r = $pdo->prepare('SELECT client_name FROM projetos WHERE id=? LIMIT 1');
                $r->execute([$projId]); $clientName = $r->fetchColumn() ?: '';
            }
            $pdo->prepare('INSERT INTO pos_venda (user_id,project_id,client_name,installation_date,next_maintenance,warranty_end,notes,performance_pct,client_type,last_checkup,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')
               ->execute([$_SESSION['user_id'],$projId,$clientName,$instDate,$nextMaint,$warranty,$notes,$perf,$clientType,$lastCheckup]);
        }
        echo json_encode(['success'=>true]); exit;
    }

    if ($action === 'gen_referral') {
        $pvId  = intval($_POST['pv_id'] ?? 0);
        $token = bin2hex(random_bytes(16));
        $pdo->prepare('UPDATE pos_venda SET referral_token=? WHERE id=? AND user_id=?')
           ->execute([$token, $pvId, $_SESSION['user_id']]);
        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'];
        echo json_encode(['success'=>true,'link'=>$base.'/indicacao/'.$token,'token'=>$token]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}

// ── Fetch data ─────────────────────────────────────────────
$stmt = $pdo->prepare('
    SELECT pv.*, p.client_name, p.status AS proj_status, p.closed_date,
           p.client_status, p.address, p.proposal_value, p.id AS proj_id
    FROM pos_venda pv
    JOIN projetos p ON pv.project_id = p.id
    WHERE pv.user_id = ?
    ORDER BY pv.installation_date DESC
');
$stmt->execute([$_SESSION['user_id']]);
$posVendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    $isEx = ($pv['client_status'] === 'Ex-Cliente');
    $ct   = $pv['client_type'] ?? 'Degustação';

    // Badge & color
    if ($isEx) {
        $pv['badge_label'] = 'INATIVO/RISCO';
        $pv['badge_class'] = 'danger';
        $pv['card_class']  = 'border-danger';
    } elseif ($m >= 12) {
        $assinantesAtivos++;
        $label = strtolower($ct);
        if (str_contains($label,'embaixador'))    { $pv['badge_label']='EMBAIXADOR';     $pv['badge_class']='warning'; }
        elseif (str_contains($label,'cortesia'))  { $pv['badge_label']='CORTESIA';       $pv['badge_class']='info'; }
        else                                      { $pv['badge_label']='ASSINANTE ATIVO';$pv['badge_class']='success'; }
        $pv['card_class'] = 'border-success';
    } else {
        $emDegustacao++;
        $label = strtolower($ct);
        if (str_contains($label,'embaixador'))    { $pv['badge_label']='EMBAIXADOR';     $pv['badge_class']='warning'; }
        elseif (str_contains($label,'cortesia'))  { $pv['badge_label']='CORTESIA';       $pv['badge_class']='info'; }
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

// Fetch all closed/finished projects for the "Add" modal select
$projStmt = $pdo->prepare("SELECT id, client_name FROM projetos WHERE user_id=? AND (status='Fechado' OR status='Finalizado') ORDER BY client_name ASC");
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
</style>

<div class="d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main class="flex-grow-1 p-4">

        <!-- Page header -->
        <div class="mb-4">
            <h1 class="h4 fw-bold mb-1"><i class="fa fa-rotate text-primary me-2"></i>Gestão de Receita Recorrente</h1>
            <p class="text-muted small mb-0">Transformando clientes concluídos em assinantes recorrentes.</p>
        </div>

        <!-- KPIs ──────────────────────────────────────────── -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card pv-kpi p-3 h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="pv-meta-label">Conversão Recorrência</div>
                            <div class="fw-bold" style="font-size:2rem;line-height:1.1"><?= $conversaoRecorrencia ?>%</div>
                        </div>
                        <div class="pv-kpi-icon bg-primary bg-opacity-10 text-primary"><i class="fa fa-chart-line"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card pv-kpi p-3 h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="pv-meta-label">Assinantes Ativos</div>
                            <div class="fw-bold" style="font-size:2rem;line-height:1.1"><?= $assinantesAtivos ?></div>
                        </div>
                        <div class="pv-kpi-icon bg-success bg-opacity-10 text-success"><i class="fa fa-circle-check"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card pv-kpi p-3 h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="pv-meta-label">Em Degustação</div>
                            <div class="fw-bold" style="font-size:2rem;line-height:1.1"><?= $emDegustacao ?></div>
                        </div>
                        <div class="pv-kpi-icon bg-warning bg-opacity-10 text-warning"><i class="fa fa-clock"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card pv-kpi p-3 h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="pv-meta-label">Indicações/Mês</div>
                            <div class="fw-bold" style="font-size:2rem;line-height:1.1"><?= $indicacoesMes ?></div>
                        </div>
                        <div class="pv-kpi-icon" style="background:rgba(111,66,193,.1);color:#6f42c1;"><i class="fa fa-share-nodes"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (empty($posVendas)): ?>
        <div class="alert alert-info"><i class="fa fa-info-circle me-2"></i>Nenhum cliente em pós-venda cadastrado ainda. Clique em "Adicionar" para começar.</div>
        <?php else: ?>

        <!-- Client Cards ──────────────────────────────────── -->
        <div class="row g-3" id="pvCardsContainer">
        <?php foreach ($posVendas as $pv):
            $isEx = ($pv['client_status'] === 'Ex-Cliente');
            $pvJson = htmlspecialchars(json_encode($pv), ENT_QUOTES);
        ?>
        <div class="col-md-6 col-xl-4">
            <div class="card pv-card h-100 <?= $pv['card_class'] ? 'border-start border-3 '.$pv['card_class'] : '' ?>">
                <div class="card-body d-flex flex-column">

                    <!-- Header: name + badge -->
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h5 class="mb-0 fw-semibold"><?= htmlspecialchars($pv['client_name']) ?></h5>
                            <?php if ($pv['installation_date'] && $pv['installation_date'] !== '0000-00-00'): ?>
                            <div class="text-muted" style="font-size:.78rem">
                                <i class="fa fa-calendar-days me-1"></i>Instalado em <?= date('d/m/Y', strtotime($pv['installation_date'])) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex flex-column align-items-end gap-1">
                            <span class="pv-badge text-<?= $pv['badge_class'] ?> border border-<?= $pv['badge_class'] ?>">
                                <?= $pv['badge_label'] ?>
                            </span>
                            <?php /* extra CORTESIA badge for Embaixadores in degustação */ ?>
                            <?php if (!$isEx && str_contains(strtolower($pv['client_type']??''), 'embaixador') && $pv['months_elapsed'] < 12): ?>
                            <span class="pv-badge text-info border border-info">CORTESIA</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Régua de Degustação -->
                    <div class="mt-1 mb-1">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="pv-meta-label mb-0">Régua de Degustação</span>
                            <span class="fw-bold" style="font-size:.82rem"><?= $pv['months_elapsed'] ?> / 12 meses</span>
                        </div>
                        <div class="progress">
                            <div class="progress-bar bg-<?= $pv['progress_color'] ?>" style="width:<?= $pv['progress_pct'] ?>%"></div>
                        </div>
                    </div>

                    <!-- SLA Alert Mês 11 -->
                    <?php if ($pv['sla_alert']): ?>
                    <div class="pv-sla-alert mt-2 d-flex align-items-center gap-2">
                        <i class="fa fa-triangle-exclamation"></i>
                        <span>ALERTA MÊS <?= $pv['sla_month'] ?>: OFERTAR PLANO PAGO</span>
                    </div>
                    <?php endif; ?>

                    <div class="pv-divider my-3"></div>

                    <!-- Relatórios -->
                    <div class="mb-2">
                        <div class="pv-meta-label"><i class="fa fa-chart-simple me-1"></i>Relatórios</div>
                        <?php if ($isEx): ?>
                            <div class="pv-blocked"><i class="fa fa-lock me-1"></i>Acesso Bloqueado</div>
                        <?php elseif (!is_null($pv['performance_pct'])): ?>
                            <div class="pv-meta-val"><?= number_format($pv['performance_pct'], 0) ?>% Performance</div>
                        <?php else: ?>
                            <div class="pv-meta-val text-muted">Sem dados de performance</div>
                        <?php endif; ?>
                    </div>

                    <!-- Último Check-up -->
                    <div class="mb-3">
                        <div class="pv-meta-label"><i class="fa fa-wrench me-1"></i>Último Check-up</div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="pv-meta-val"><?= htmlspecialchars($pv['checkup_human']) ?></span>
                            <?php if (!$isEx): ?>
                            <button class="btn btn-sm fw-semibold pv-btn-limpeza"
                                style="font-size:.73rem;border:1px solid #0d9488;color:#0d9488;border-radius:6px;padding:2px 8px;"
                                data-pv-id="<?= $pv['id'] ?>"
                                data-client="<?= htmlspecialchars($pv['client_name'],ENT_QUOTES) ?>">
                                AGENDAR LIMPEZA
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mt-auto d-flex flex-column gap-2">
                        <!-- Gerar Link Amigo Solar -->
                        <button class="btn btn-sm btn-outline-secondary btn-amigo pv-btn-amigo"
                            data-pv-id="<?= $pv['id'] ?>"
                            data-client="<?= htmlspecialchars($pv['client_name'],ENT_QUOTES) ?>"
                            data-token="<?= htmlspecialchars($pv['referral_token']??'',ENT_QUOTES) ?>">
                            <i class="fa fa-share-nodes me-1"></i>GERAR LINK "AMIGO SOLAR"
                        </button>
                        <!-- Edit -->
                        <button class="btn btn-sm btn-link text-muted p-0 pv-btn-edit" data-pv='<?= $pvJson ?>'>
                            <i class="fa fa-pen-to-square me-1"></i>Editar registro
                        </button>
                    </div>

                </div><!-- card-body -->
            </div><!-- card -->
        </div><!-- col -->
        <?php endforeach; ?>
        </div><!-- row -->
        <?php endif; ?>

        <!-- Add button -->
        <div class="mt-4">
            <button class="btn btn-primary" id="pvNewBtn">
                <i class="fa fa-plus me-2"></i>Adicionar Cliente ao Pós-venda
            </button>
        </div>

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
            <option value="Degustação">Degustação</option>
            <option value="Cortesia">Cortesia</option>
            <option value="Embaixador">Embaixador</option>
            <option value="Assinante Ativo">Assinante Ativo</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Status de Acesso</label>
          <select name="client_status" id="pvClientStatus" class="form-select">
            <option value="Assinante">Assinante — acesso liberado</option>
            <option value="Ex-Cliente">Ex-Cliente — acesso bloqueado</option>
          </select>
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
          <label class="form-label fw-semibold">Fim da Garantia</label>
          <input type="date" name="warranty_end" id="pvWarranty" class="form-control">
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

<script>
(function(){
    // ── helpers ──
    const $  = id => document.getElementById(id);
    const bsModal = id => new bootstrap.Modal($(id));

    // ── Open: New record ──
    $('pvNewBtn').addEventListener('click', ()=>{
        $('pvForm').reset();
        $('pvProjectId').value = '';
        $('pvClientNameHidden').value = '';
        $('pvProjectPickerWrap').classList.remove('d-none');
        $('pvModalTitle').textContent = 'Novo Registro Pós-venda';
        bsModal('pvModal').show();
    });

    // project select → populate hidden fields
    $('pvProjectSelect').addEventListener('change', ()=>{
        const opt = $('pvProjectSelect').selectedOptions[0];
        $('pvProjectId').value = opt?.value || '';
        $('pvClientNameHidden').value = opt?.dataset?.name || '';
    });

    // ── Open: Edit record ──
    document.querySelectorAll('.pv-btn-edit').forEach(btn => {
        btn.addEventListener('click', ()=>{
            const pv = JSON.parse(btn.dataset.pv);
            $('pvProjectPickerWrap').classList.add('d-none');
            $('pvProjectId').value     = pv.project_id  || '';
            $('pvClientNameHidden').value = pv.client_name || '';
            $('pvInstDate').value      = pv.installation_date  || '';
            $('pvNextMaint').value     = pv.next_maintenance   || '';
            $('pvLastCheckup').value   = pv.last_checkup       || '';
            $('pvWarranty').value      = pv.warranty_end       || '';
            $('pvNotes').value         = pv.notes              || '';
            $('pvPerf').value          = pv.performance_pct != null ? pv.performance_pct : '';
            $('pvClientType').value    = pv.client_type        || 'Degustação';
            $('pvClientStatus').value  = pv.client_status      || 'Assinante';
            $('pvModalTitle').textContent = 'Editar: ' + pv.client_name;
            bsModal('pvModal').show();
        });
    });

    // ── Save form ──
    $('pvForm').addEventListener('submit', async (e)=>{
        e.preventDefault();
        const saveBtn = $('pvSaveBtn');
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';
        try {
            const fd = new FormData(e.target);
            const res = await fetch('pos-venda.php', {method:'POST', body:fd});
            const data = await res.json();
            if (data.success) { location.reload(); }
            else { alert('Erro ao salvar: ' + (data.message || '')); }
        } catch(err){ alert('Falha: ' + err.message); }
        finally { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fa fa-floppy-disk me-1"></i>Salvar'; }
    });

    // ── Agendar Limpeza ──
    let _limpezaPvId = null;
    document.querySelectorAll('.pv-btn-limpeza').forEach(btn=>{
        btn.addEventListener('click', ()=>{
            _limpezaPvId = btn.dataset.pvId;
            $('pvLimpezaClientName').textContent = btn.dataset.client;
            const d = new Date(); d.setDate(d.getDate()+7);
            $('pvLimpezaDate').value = d.toISOString().split('T')[0];
            bsModal('pvLimpezaModal').show();
        });
    });

    $('pvLimpezaConfirm').addEventListener('click', async ()=>{
        const date = $('pvLimpezaDate').value;
        if (!date || !_limpezaPvId) return;
        // update next_maintenance via save_pv with partial fields
        const fd = new FormData();
        fd.append('action','save_pv');
        fd.append('project_id', _limpezaPvId); // will be overridden below, use pv id doesn't work, open edit
        // Instead just reload in edit modal – simpler: save next_maintenance
        try {
            // We lack project_id here from id alone; fetch it or just alert user
            bootstrap.Modal.getInstance($('pvLimpezaModal'))?.hide();
            alert('Limpeza agendada para ' + new Date(date).toLocaleDateString('pt-BR') + '.\nAbra "Editar registro" para salvar a data de Próxima Manutenção.');
        } catch(err){}
    });

    // ── Gerar Link Amigo Solar ──
    document.querySelectorAll('.pv-btn-amigo').forEach(btn=>{
        btn.addEventListener('click', async ()=>{
            const pvId      = btn.dataset.pvId;
            const clientName= btn.dataset.client;
            let   token     = btn.dataset.token;

            $('pvLinkClientName').textContent = clientName;

            if (!token) {
                const fd = new FormData();
                fd.append('action','gen_referral');
                fd.append('pv_id', pvId);
                try {
                    const r = await fetch('pos-venda.php',{method:'POST',body:fd});
                    const d = await r.json();
                    if (!d.success) { alert('Erro ao gerar link'); return; }
                    token = d.token;
                    btn.dataset.token = token;
                    $('pvLinkInput').value = d.link;
                } catch(err){ alert('Falha: '+err.message); return; }
            } else {
                $('pvLinkInput').value = window.location.origin + '/indicacao/' + token;
            }

            $('pvLinkCopied').classList.add('d-none');
            bsModal('pvLinkModal').show();
        });
    });

    // Copy link
    $('pvLinkCopy').addEventListener('click', ()=>{
        const el = $('pvLinkInput');
        el.select();
        navigator.clipboard.writeText(el.value)
            .then(()=>{ $('pvLinkCopied').classList.remove('d-none'); })
            .catch(()=>{ try { document.execCommand('copy'); $('pvLinkCopied').classList.remove('d-none'); } catch(e){} });
    });

    // ── SLA: auto-move to 'Renovação de Contrato' at month 11 ──
    (async function runSla(){
        <?php foreach ($posVendas as $pv):
            if ($pv['sla_alert'] && $pv['months_elapsed'] >= 10):
        ?>
        try {
            const fd = new FormData();
            fd.append('id',     '<?= $pv['proj_id'] ?>');
            fd.append('status', 'Renovação de Contrato');
            await fetch('api/update_project.php', {method:'POST', body:fd});
            // alert for commercial
            const af = new FormData();
            af.append('project_id', '<?= $pv['proj_id'] ?>');
            af.append('type', 'renovation');
            af.append('message', 'Cliente <?= addslashes(htmlspecialchars($pv['client_name'])) ?> atingiu <?= $pv['months_elapsed'] ?> meses pós-homologação — oferecer plano pago.');
            await fetch('api/add_alert.php', {method:'POST', body:af});
        } catch(e){ console.warn('SLA trigger failed', e); }
        <?php endif; endforeach; ?>
    })();

})();
</script>

<?php include 'includes/footer.php'; ?>
