<?php
// Ensure session and auth
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php'); exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/project_post_sale_automation.php';

checkAccessOrRedirect('projetos');

// Safe migration for payment method relation in projects.
try {
    $col = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projetos' AND COLUMN_NAME = 'payment_method_id'");
    $col->execute();
    if (!$col->fetchColumn()) {
        $pdo->exec("ALTER TABLE projetos ADD COLUMN payment_method_id INT DEFAULT NULL");
    }
} catch (Exception $e) {
    // Ignore migration errors and keep backward compatibility with payment_type fallback.
}

// Aplicar automação configurada por coluna antes de listar, para projetos elegíveis sumirem do kanban.
runProjectPostSaleAutomation($pdo, (int) $_SESSION['user_id']);

// Exibir projetos com dados do lead vinculado (fonte de verdade para telefone, kWh e orçamento)
$stmt = $pdo->prepare('SELECT p.*, pm.name AS payment_method_name, COALESCE(pm.name, p.payment_type) AS payment_type_effective, l.phone AS lead_phone, l.orcamento_value AS lead_orcamento_value, l.estimativa_projeto_kwh AS lead_kwh, COALESCE(l.orcamento_value, p.proposal_value) AS proposal_value_effective, COALESCE(l.estimativa_projeto_kwh, p.projeto) AS projeto_effective FROM projetos p LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id AND pm.code = 2 LEFT JOIN leads l ON l.id = p.lead_id LEFT JOIN pos_venda pv ON pv.project_id = p.id WHERE pv.id IS NULL ORDER BY p.id DESC');
$stmt->execute();
$projetos = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>
<div class="d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main class="flex-grow-1 p-4">
        <style>
            .kanban-scroll-wrap {
                overflow-x: auto;
                overflow-y: hidden;
                padding-bottom: 0.75rem;
                margin-left: -0.75rem;
                margin-right: -0.75rem;
                background: transparent;
                cursor: grab;
                user-select: none;
            }
            .kanban-scroll-wrap.scrolling { cursor: grabbing; }
            #kanbanBoard {
                flex-wrap: nowrap;
                width: max-content;
                background: transparent;
            }
            #kanbanBoard > .kanban-column {
                flex: 0 0 320px;
                max-width: 320px;
            }
            .top-kpi-col { flex: 0 0 auto; }
            .top-kpi-card {
                min-width: 170px;
                padding: 0.9rem !important;
            }
            .top-kpi-card .small {
                font-size: 0.72rem;
            }
            .top-kpi-card .h4 {
                font-size: 1.1rem;
            }
            .top-kpi-card i {
                font-size: 1.35rem !important;
                margin-top: 0.35rem;
            }
            .top-info-toggle {
                width: 42px;
                height: 42px;
                border: 1px solid #dbe4f0;
                border-radius: 999px;
                background: rgba(255,255,255,0.92);
                color: #1d4ed8;
                box-shadow: 0 10px 24px rgba(15,23,42,0.10);
                display: inline-flex;
                align-items: center;
                justify-content: center;
                transition: transform .18s ease, box-shadow .18s ease, background-color .18s ease;
            }
            .top-info-toggle:hover {
                transform: translateY(-1px);
                box-shadow: 0 14px 30px rgba(15,23,42,0.15);
                background: #ffffff;
            }
            .top-info-toggle i {
                font-size: 1rem;
            }
            .project-header-icon-btn {
                width: 42px;
                height: 42px;
                border-radius: 999px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border: 1px solid transparent;
                box-shadow: 0 10px 24px rgba(15,23,42,0.10);
                transition: transform .18s ease, box-shadow .18s ease, background-color .18s ease, color .18s ease;
            }
            .project-header-icon-btn:hover {
                transform: translateY(-1px);
                box-shadow: 0 14px 30px rgba(15,23,42,0.15);
            }
            .project-header-icon-btn i {
                font-size: 1rem;
            }
            .project-config-btn {
                background: #eff6ff;
                border-color: #93c5fd;
                color: #1d4ed8;
            }
            .project-config-btn:hover {
                background: #dbeafe;
                border-color: #60a5fa;
                color: #1e40af;
            }
            .project-filter-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 999px;
                width: 42px;
                height: 42px;
                padding: 0;
                font-weight: 600;
                border-width: 1px;
                box-shadow: 0 6px 18px rgba(15,23,42,0.08);
                transition: transform .18s ease, box-shadow .18s ease, background-color .18s ease, color .18s ease;
            }
            .project-filter-btn:hover {
                transform: translateY(-1px);
                box-shadow: 0 10px 22px rgba(15,23,42,0.12);
            }
            .project-filter-btn i {
                font-size: 0.92rem;
            }
            .project-filter-btn-clear {
                background: #fff7ed;
                border-color: #fdba74;
                color: #c2410c;
            }
            .project-filter-btn-clear:hover {
                background: #ffedd5;
                color: #9a3412;
                border-color: #fb923c;
            }
            .project-filter-btn-compact {
                background: #ecfdf5;
                border-color: #86efac;
                color: #15803d;
            }
            .project-filter-btn-compact:hover {
                background: #dcfce7;
                color: #166534;
                border-color: #4ade80;
            }
            body.hide-project-top-info .project-top-section {
                display: none !important;
            }
            body.hide-project-top-info .top-info-toggle {
                background: #1d4ed8;
                color: #ffffff;
                border-color: #1d4ed8;
            }
            .stage-card { border: 1px solid #e3e9ef; }
            .board-column { min-height: 400px; max-height: calc(100vh - 360px); overflow-y: auto; background: #f8fafc; border-radius: 0 0 .25rem .25rem; }
            .card-project { cursor: grab; border-left: 4px solid #0b6ac1; }
            .card-project .project-contract { font-size: 0.75rem; }
            .card-project.compact { margin-bottom: 0.35rem; }
            .compact-cards .card-project { margin-bottom: 0.35rem; }
            .compact-cards .card-project .card-body { padding: 0.55rem; }
            .compact-cards .card-project .project-title { font-size: 0.82rem; margin-bottom: 0.25rem; }
            .compact-cards .card-project .compact-hide { display: none !important; }
            .compact-cards .card-project .text-muted.small { font-size: 0.68rem; }
            .compact-cards .card-project .d-flex.align-items-center.gap-2.mb-1 { gap: 0.35rem; }
            .compact-cards .card-project .progress { height: 4px; }
            .compact-cards .card-project .btn-sm { padding: 0.25rem 0.45rem; font-size: 0.68rem; }
            .compact-cards .card-project .badge { font-size: 0.65rem; padding: 0.2rem 0.35rem; }
            .card-project .progress { height: 5px; }
            .btn-xs { font-size: 0.68rem; }
            .board-column.drop-target { border: 2px dashed #0d6efd; background: #e7f1ff; }
            .modal-content { border-radius: 1.2rem; overflow: hidden; box-shadow: 0 32px 100px rgba(15,23,42,.15); }
            .modal-header { background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; border-bottom: none; }
            .modal-header .modal-title { color: #fff; font-weight: 700; letter-spacing: -.02em; }
            .modal-body { background: #f8fbff; padding: 1.75rem; }
            .modal-footer { background: #f8fbff; border-top: none; }
            .modal-content .form-control,
            .modal-content .form-select,
            .modal-content textarea.form-control { border-radius: .9rem; border: 1px solid #dbe4f0; background: #ffffff; box-shadow: inset 0 1px 2px rgba(15,23,42,0.04); }
            .modal-content .form-label { font-weight: 600; color: #334155; }
            .modal-content .btn-primary { background: #1d4ed8; border-color: #1d4ed8; }
            .modal-content .btn-primary:hover { background: #2563eb; border-color: #2563eb; }
            .modal-content .btn-secondary { background: #e2e8f0; color: #102a43; border-color: #cbd5e1; }
            .file-dropzone {
                position: relative;
                border: 2px dashed #cbd5e1;
                border-radius: 1rem;
                background: #f8faff;
                padding: 1rem;
                transition: border-color .2s ease, background-color .2s ease;
                cursor: pointer;
                min-height: 96px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .file-dropzone.dragover {
                border-color: #2563eb;
                background: rgba(59,130,246,.08);
            }
            .file-dropzone-content { text-align: center; pointer-events: none; }
            .file-dropzone-input {
                position: absolute;
                inset: 0;
                width: 100%;
                height: 100%;
                opacity: 0;
                cursor: pointer;
            }
            .file-attachment-item {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.75rem;
                padding: 0.35rem 0.5rem;
                border-radius: 0.75rem;
                background: #ffffff;
                border: 1px solid #e2e8f0;
                margin-bottom: 0.35rem;
            }
            .file-attachment-item a { color: #1d4ed8; text-decoration: none; }
            .file-attachment-item button { border: none; background: #f8fafc; color: #475569; padding: 0.25rem 0.45rem; border-radius: 0.65rem; cursor: pointer; }
            .file-attachment-item button:hover { background: #e2e8f0; }
        </style>
        <div id="projetos">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h1 class="h4 mb-0">Projetos</h1>
                <div class="d-flex gap-2">
                    <button type="button" id="toggleTopInfo" class="btn top-info-toggle" title="Ocultar informacoes do topo" aria-label="Ocultar informacoes do topo" aria-pressed="false">
                        <i class="fa fa-eye-slash" aria-hidden="true"></i>
                    </button>
                    <a href="projeto_config.php" class="btn btn-sm project-header-icon-btn project-config-btn" title="Configurar Colunas do Projeto" aria-label="Configurar Colunas do Projeto"><i class="fa fa-sliders-h" aria-hidden="true"></i></a>
                    <button id="btnNovoProjeto" class="btn btn-primary btn-sm">Novo Projeto</button>
                </div>
            </div>
            <!-- KPI inicial + limpeza de status para quadros Kanban -->
            <?php
            $kanbanStages = [];
            try {
                $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projeto_stages'");
                $colStmt->execute();
                $projCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
                $nameCol = in_array('name', $projCols, true) ? 'name' : (in_array('stage_name', $projCols, true) ? 'stage_name' : 'name');
                $orderCol = in_array('position', $projCols, true) ? 'position' : 'id';

                $stagesStmt = $pdo->prepare("SELECT {$nameCol} AS name, color, card_color FROM projeto_stages ORDER BY COALESCE({$orderCol}, id) ASC");
                $stagesStmt->execute();
                $stageRows = $stagesStmt->fetchAll(PDO::FETCH_ASSOC);
                $kanbanStages = [];
                $stageStyles = [];
                foreach ($stageRows as $row) {
                    $name = trim($row['name']);
                    if ($name === '') continue;
                    $kanbanStages[] = $name;
                    $stageStyles[$name] = [
                        'color' => !empty($row['color']) ? $row['color'] : '#6c757d',
                        'card_color' => !empty($row['card_color']) ? $row['card_color'] : '#ffffff',
                    ];
                }
            } catch (Exception $e) {
                $kanbanStages = [];
                $stageStyles = [];
            }
            $stageProjects = [];
            foreach ($kanbanStages as $stageName) {
                $stageProjects[$stageName] = [];
            }
            foreach ($projetos as $p) {
                if (in_array($p['status'], $kanbanStages, true)) {
                    $stage = $p['status'];
                } else {
                    // se o status estiver fora das etapas existentes, coloca em primeiro estágio caso exista
                    $stage = !empty($kanbanStages) ? $kanbanStages[0] : null;
                }
                if ($stage !== null) {
                    $stageProjects[$stage][] = $p;
                }
            }
            $total = count($projetos);
            $concluidos = count(array_filter($projetos, fn($p) => $p['status'] === 'Concluído'));
            $pendentes = $total - $concluidos;
            $proposalSum = array_sum(array_map(static function($proj) {
                $raw = $proj['proposal_value'] ?? 0;
                if ($raw === null || $raw === '') return 0.0;
                if (is_numeric($raw)) return (float)$raw;

                $value = trim((string)$raw);
                $value = preg_replace('/[^0-9,.-]/', '', $value);

                if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
                    // If comma is the last separator, assume BR format: 1.234,56
                    if (strrpos($value, ',') > strrpos($value, '.')) {
                        $value = str_replace('.', '', $value);
                        $value = str_replace(',', '.', $value);
                    } else {
                        // US format: 1,234.56
                        $value = str_replace(',', '', $value);
                    }
                } elseif (strpos($value, ',') !== false) {
                    // Only comma present: treat as decimal separator.
                    $value = str_replace('.', '', $value);
                    $value = str_replace(',', '.', $value);
                }

                return is_numeric($value) ? (float)$value : 0.0;
            }, $projetos));
            $avg = $total ? ($proposalSum / $total) : 0;
            ?>
            <div class="row g-2 mb-4 justify-content-center project-top-section">
                <div class="col-auto top-kpi-col">
                    <div class="card top-kpi-card text-center shadow-sm">
                        <div class="small text-muted">Total de Projetos</div>
                        <div class="h4 mb-0"><?= $total ?></div>
                        <i class="fa fa-folder-open fa-2x text-primary"></i>
                    </div>
                </div>
                <div class="col-auto top-kpi-col">
                    <div class="card top-kpi-card text-center shadow-sm">
                        <div class="small text-muted">Aguardando Pagamento</div>
                        <div class="h4 mb-0"><?= $pendentes ?></div>
                        <i class="fa fa-clock fa-2x text-danger"></i>
                    </div>
                </div>
                <div class="col-auto top-kpi-col">
                    <div class="card top-kpi-card text-center shadow-sm">
                        <div class="small text-muted">Financeiro Aprovado</div>
                        <div class="h4 mb-0"><?= $concluidos ?></div>
                        <i class="fa fa-check-circle fa-2x text-success"></i>
                    </div>
                </div>
                <div class="col-auto top-kpi-col">
                    <div class="card top-kpi-card text-center shadow-sm">
                        <div class="small text-muted">Total em R$</div>
                        <div class="h4 mb-0">R$ <?= number_format($proposalSum, 2, ',', '.') ?></div>
                        <i class="fa fa-coins fa-2x text-info"></i>
                    </div>
                </div>
                <div class="col-auto top-kpi-col">
                    <div class="card top-kpi-card text-center shadow-sm">
                        <div class="small text-muted">Ticket Médio</div>
                        <div class="h4 mb-0">R$ <?= number_format($avg, 2, ',', '.') ?></div>
                        <i class="fa fa-dollar-sign fa-2x text-warning"></i>
                    </div>
                </div>
            </div>
            <!-- Filtros -->
            <div class="d-flex gap-2 mb-3 flex-wrap justify-content-center project-top-section">
                <div class="btn-group" role="group" aria-label="Filtro de proprietário">
                    <button type="button" id="btnTodosProjetos" class="btn btn-outline-primary btn-sm active">Todos os Projetos</button>
                    <button type="button" id="btnMeusProjetos" class="btn btn-outline-secondary btn-sm">Meus Projetos</button>
                </div>
            </div>
            <div class="d-flex gap-2 mb-4 flex-wrap justify-content-center align-items-center project-top-section">
                <input type="search" id="filtroBusca" class="form-control form-control-sm" style="max-width:250px;" placeholder="Buscar cliente, projeto, contrato, telefone, pagamento...">
                <select id="filtroStatus" class="form-select form-select-sm w-auto">
                    <option value="all">Todos os Status</option>
                    <?php foreach ($kanbanStages as $stageOption): ?>
                        <option value="<?= htmlspecialchars(strtolower($stageOption)) ?>"><?= htmlspecialchars($stageOption) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filtroPaymentStatus" class="form-select form-select-sm w-auto">
                    <option value="all">Todos os Pagamentos</option>
                    <option value="pago">Pago</option>
                    <option value="pendente">Pendente</option>
                </select>
                <select id="filtroPaymentType" class="form-select form-select-sm w-auto">
                    <option value="">Todos os tipos</option>
                </select>
                <button id="btnLimparFiltros" class="btn btn-sm project-filter-btn project-filter-btn-clear" title="Limpar filtros" aria-label="Limpar filtros"><i class="fa fa-eraser" aria-hidden="true"></i></button>
                <button id="btnCompactCards" class="btn btn-sm project-filter-btn project-filter-btn-compact" title="Compactar cards" aria-label="Compactar cards"><i class="fa fa-compress-alt" aria-hidden="true"></i></button>
            </div>
            <!-- Kanban por estágio -->
            <?php
                $stageCount = max(1, count($kanbanStages));
                $colWidth = intval(100 / $stageCount);
            ?>
            <div class="kanban-scroll-wrap">
                <div class="row g-3" id="kanbanBoard">
                <?php if (empty($kanbanStages)): ?>
                    <div class="col-12">
                        <div class="alert alert-warning">Nenhuma etapa de projeto configurada. Crie em <a href="projeto_config.php">Configurar Colunas do Projeto</a>.</div>
                    </div>
                <?php endif; ?>
                <?php foreach ($kanbanStages as $stage): ?>
                    <?php $stageColor = $stageStyles[$stage]['color'] ?? '#6c757d'; ?>
                    <?php $stageCardColor = $stageStyles[$stage]['card_color'] ?? '#ffffff'; ?>
                    <div class="col-12 kanban-column">
                        <div class="card h-100 stage-card" style="background: <?= htmlspecialchars($stageCardColor) ?>; border-color: <?= htmlspecialchars($stageColor) ?>;">
                            <div class="card-header d-flex justify-content-between align-items-center" style="background: <?= htmlspecialchars($stageColor) ?>; color: #fff; border-bottom: 1px solid rgba(0,0,0,0.08);">
                                <strong class="mb-0"><?= htmlspecialchars($stage) ?></strong>
                                <span class="badge" style="background: rgba(255,255,255,0.2); color: #fff; font-size:80%;"><?= count($stageProjects[$stage]) ?></span>
                            </div>
                            <div class="card-body p-2 board-column" data-stage="<?= $stage ?>" data-stage-color="<?= htmlspecialchars($stageColor) ?>">
                                <?php foreach ($stageProjects[$stage] as $p): ?>
                                    <?php $projectStageColor = $stageColor; ?>
                                    <div class="card mb-2 card-project shadow-sm" data-id="<?= $p['id'] ?>" data-user-id="<?= $p['user_id'] ?>" data-status="<?= htmlspecialchars(strtolower($p['status'] ?? '')) ?>" data-payment-type="<?= htmlspecialchars(strtolower($p['payment_type_effective'] ?? '')) ?>" data-payment-status="<?= htmlspecialchars(strtolower($paymentStatus)) ?>" data-client-name="<?= htmlspecialchars(strtolower($p['client_name'] ?? '')) ?>" data-contract="<?= htmlspecialchars(strtolower($p['contract'] ?? '')) ?>" data-lead-phone="<?= htmlspecialchars(strtolower($p['lead_phone'] ?? '')) ?>" draggable="true" style="border-left:4px solid <?= htmlspecialchars($projectStageColor) ?>; border-color: <?= htmlspecialchars($projectStageColor) ?>;">
                                        <div class="card-body p-2">
                                            <?php
                                                $paymentStatus = isset($p['payment_status']) && $p['payment_status'] !== ''
                                                    ? $p['payment_status']
                                                    : ($p['status'] === 'Concluído' ? 'Pago' : 'Pendente');
                                                $paymentBadge = $paymentStatus === 'Pago' ? 'bg-success' : 'bg-danger';
                                            ?>
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <span class="badge project-id-badge" style="background: <?= htmlspecialchars($projectStageColor) ?>; color:#fff; font-size:80%;">#<?= $p['id'] ?></span>
                                                <span class="badge <?= $paymentBadge ?>" style="font-size:70%;"><?= htmlspecialchars($paymentStatus) ?></span>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                                <h6 class="project-title mb-0" style="font-size:0.95rem;"><?= htmlspecialchars($p['client_name']) ?></h6>
                                                <?php if (!empty($p['lead_id'])): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-lead-details flex-shrink-0" data-lead-id="<?= $p['lead_id'] ?>" style="font-size:0.7rem; padding:0.2rem 0.45rem; border-width:1px; min-width:auto;">
                                                        <i class="fa fa-link" aria-hidden="true"></i> <?= $p['lead_id'] ?>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                            <?php $kwhValue = !empty($p['projeto_effective']) ? $p['projeto_effective'] : null; ?>
                                            <div class="text-muted small mb-1 compact-hide">Valor do projeto: R$ <?= number_format((float)($p['proposal_value_effective'] ?? $p['proposal_value'] ?? 0), 2, ',', '.') ?></div>
                                            <div class="text-muted small mb-1 compact-hide"><?= $kwhValue !== null ? htmlspecialchars($kwhValue) . ' kwh' : 'Não informado' ?></div>
                                            <div class="text-muted small mb-1 compact-hide">Telefone: <strong><?= !empty($p['lead_phone']) ? htmlspecialchars($p['lead_phone']) : 'Não informado' ?></strong></div>
                                            <div class="text-muted small mb-1 compact-hide">Forma de Pagto: <strong><?= !empty($p['payment_type_effective']) ? htmlspecialchars($p['payment_type_effective']) : (!empty($p['contract']) ? htmlspecialchars($p['contract']) : 'Não informado') ?></strong></div>

                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <?php
                                                    $today = time();
                                                    $dueDays = isset($p['due_days']) && intval($p['due_days']) > 0 ? intval($p['due_days']) : 30;
                                                    $startedAt = !empty($p['closed_date']) ? strtotime($p['closed_date']) : (isset($p['created_at']) ? strtotime($p['created_at']) : $today);
                                                    $elapsed = max(0, floor(($today - $startedAt) / 86400));
                                                    $remaining = max(0, $dueDays - $elapsed);
                                                    $progressValue = $dueDays > 0 ? intval(min(100, max(0, ($elapsed / $dueDays) * 100))) : 0;
                                                    $deadlineStatus = $remaining > 0 ? "{$remaining} dia" . ($remaining !== 1 ? 's' : '') . ' restantes' : 'Prazo vencido';
                                                ?>
                                                <small class="text-muted"><?= $deadlineStatus ?></small>
                                                <strong class="text-muted"><?= $progressValue ?>%</strong>
                                            </div>
                                            <div class="progress mt-0" style="height:8px;">
                                                <div class="progress-bar" role="progressbar" style="width: <?= $progressValue ?>%; background-color: <?= htmlspecialchars($projectStageColor) ?>;" aria-valuenow="<?= $progressValue ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>

                                            <div class="d-flex gap-1 mt-2 flex-wrap">
                                                <?php foreach ($kanbanStages as $stageLabel):
                                                    $normalized = preg_replace('/\s+/u', ' ', trim($stageLabel));
                                                    $words = explode(' ', $normalized);
                                                    if (count($words) === 1) {
                                                        $abbr = mb_strtoupper(mb_substr($normalized, 0, 3, 'UTF-8'), 'UTF-8');
                                                    } else {
                                                        $abbr = '';
                                                        foreach ($words as $word) {
                                                            if ($word === '') continue;
                                                            $abbr .= mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8');
                                                            if (mb_strlen($abbr, 'UTF-8') >= 3) break;
                                                        }
                                                        if (mb_strlen($abbr, 'UTF-8') === 1) {
                                                            $abbr = mb_strtoupper(mb_substr($normalized, 0, 3, 'UTF-8'), 'UTF-8');
                                                        }
                                                    }
                                                    if (mb_strlen($abbr, 'UTF-8') > 3) {
                                                        $abbr = mb_substr($abbr, 0, 3, 'UTF-8');
                                                    }
                                                    $isActiveStage = $p['status'] === $stageLabel;
                                                    $badgeStageColor = $stageStyles[$stageLabel]['color'] ?? '#0d6efd';
                                                    $badgeStyle = $isActiveStage ? 'background:' . htmlspecialchars($badgeStageColor) . ';color:#fff;' : 'background:#d8d8d8;color:#6c757d;';
                                                ?>
                                                    <span class="badge abbr-stage-badge" data-stage="<?= htmlspecialchars($stageLabel) ?>" style="font-size:70%; <?= $badgeStyle ?>"><?= htmlspecialchars($abbr) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="d-flex flex-wrap gap-1 mt-2">
                                                <button class="btn btn-sm btn-outline-primary btn-edit" data-id="<?= $p['id'] ?>" title="Editar"><i class="fa fa-edit"></i></button>
                                                <button class="btn btn-sm btn-outline-danger btn-delete" data-id="<?= $p['id'] ?>" title="Excluir"><i class="fa fa-trash"></i></button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>

            <!-- Painel de detalhes do lead (relacionado ao projeto) -->
            <aside id="leadDetailsPanel" class="lead-panel hidden">
                <div class="lead-panel-inner">
                    <button id="closeLeadPanel" class="btn btn-sm btn-light close-panel" title="Fechar">✕</button>
                    <div id="leadDetailContent" class="p-3"></div>
                </div>
            </aside>

        </div>
    </main>
</div>

<?php include 'includes/footer.php'; ?>

<div class="modal fade" id="modalProjeto" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Projeto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form id="formProjeto">
                <div class="modal-body">
                    <input type="hidden" name="id" id="proj_id">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Cliente</label>
                            <input class="form-control" name="client_name" id="proj_client_name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Valor Proposta</label>
                            <input class="form-control" name="proposal_value" id="proj_proposal_value" placeholder="0,00">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Kwh (projeto)</label>
                            <input class="form-control" name="projeto" id="proj_projeto" placeholder="Ex: 4500">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Endereço</label>
                            <input class="form-control" name="address" id="proj_address">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Telefone do Lead</label>
                            <input type="text" class="form-control" id="proj_lead_phone" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="proj_status">
                                <?php foreach ($kanbanStages as $stageOption): ?>
                                    <option value="<?= htmlspecialchars($stageOption) ?>"><?= htmlspecialchars($stageOption) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Forma de Pagamento</label>
                            <div class="input-group">
                                <select class="form-select" name="payment_method_id" id="proj_payment_type">
                                    <option value="">Selecione...</option>
                                </select>
                                <button type="button" class="btn btn-outline-primary" id="btnAddPaymentType" title="Cadastrar forma de pagamento">+</button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status do Pagamento</label>
                            <select class="form-select" name="payment_status" id="proj_payment_status">
                                <option value="">Automático</option>
                                <option value="Pendente">Pendente</option>
                                <option value="Pago">Pago</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Data de fechamento</label>
                            <input type="date" class="form-control" name="closed_date" id="proj_closed_date">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Prazo (dias)</label>
                            <select class="form-select" name="due_days" id="proj_due_days">
                                <option value="30">30 dias</option>
                                <option value="60">60 dias</option>
                                <option value="90">90 dias</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Contrato / Observações</label>
                            <textarea class="form-control" name="contract" id="proj_contract" rows="3"></textarea>
                        </div>
                    </div>

                    <ul class="nav nav-tabs" id="projetoTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tab-logistica" data-bs-toggle="tab" data-bs-target="#content-logistica" type="button" role="tab">Logística</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-tecnica" data-bs-toggle="tab" data-bs-target="#content-tecnica" type="button" role="tab">Técnica</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-gestao" data-bs-toggle="tab" data-bs-target="#content-gestao" type="button" role="tab">Gestão Documental</button>
                        </li>
                    </ul>
                    <div class="tab-content p-3 border border-top-0 rounded-bottom bg-white">
                        <div class="tab-pane fade show active" id="content-logistica" role="tabpanel">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Código de Rastreio</label>
                                    <input class="form-control" name="logistics_tracking_code" id="proj_logistics_tracking_code">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Data Prevista de Entrega</label>
                                    <input type="date" class="form-control" name="logistics_delivery_date" id="proj_logistics_delivery_date">
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="content-tecnica" role="tabpanel">
                            <div class="mb-3">
                                <label class="form-label">Fotos da vistoria (URLs ou referência)</label>
                                <textarea class="form-control" name="inspection_photos" id="proj_inspection_photos" rows="3" placeholder="https://.../foto1.jpg\nhttps://.../foto2.jpg"></textarea>
                            </div>
                            <div id="proj_technical_items" class="mb-3"></div>
                        </div>
                        <div class="tab-pane fade" id="content-gestao" role="tabpanel">
                            <div id="proj_docs_items"></div>
                            <div class="mt-3">
                                <label class="form-label">Anexar arquivo</label>
                                <div id="proj_doc_dropzone" class="file-dropzone">
                                    <div class="file-dropzone-content">
                                        <p class="mb-1">Arraste e solte um arquivo aqui ou clique para selecionar</p>
                                        <small class="text-muted">Aceita qualquer tipo de arquivo. Tamanho máximo 10 MB.</small>
                                    </div>
                                    <input class="form-control form-control-sm file-dropzone-input" type="file" id="proj_doc_file">
                                </div>
                                <div class="small text-muted mt-2">Arquivos são incluídos automaticamente após seleção ou soltar. Tamanho máximo: 10 MB.</div>
                                <div id="proj_doc_attachments_list" class="mt-2"></div>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="technical_checklist" id="proj_technical_checklist">
                    <input type="hidden" name="docs_checklist" id="proj_docs_checklist">
                    <input type="hidden" name="doc_attachments" id="proj_doc_attachments">
                </div>
                <div class="modal-footer">
                    <button type="button" id="btnExcluirProjeto" class="btn btn-outline-danger me-auto">Excluir</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    (function(){
        const modalEl = document.getElementById('modalProjeto');
        const bsModal = new bootstrap.Modal(modalEl);

        const btnExcluirProjeto = document.getElementById('btnExcluirProjeto');
        const toggleTopInfoBtn = document.getElementById('toggleTopInfo');
        const sessionUserId = "<?= $_SESSION['user_id'] ?>";
        const paymentMethodsApi = 'includes/payment_methods_api.php';
        const paymentMethodsCode = 2;
        const projPaymentType = document.getElementById('proj_payment_type');
        const btnAddPaymentType = document.getElementById('btnAddPaymentType');

        const checklistApi = 'includes/project_checklists_api.php';
        const projectChecklistOptions = { technical: [], document: [] };
        let loadedChecklistOwnerId = null;

        document.getElementById('btnNovoProjeto').addEventListener('click', async ()=>{
            document.getElementById('formProjeto').reset();
            document.getElementById('proj_id').value = '';
            document.getElementById('proj_due_days').value = '30';
            btnExcluirProjeto.style.display = 'none';
            await loadPaymentMethodsSelect('');
            await loadProjectChecklistOptions(sessionUserId);
            renderProjectChecklistChoices('technical', []);
            renderProjectChecklistChoices('document', []);
            bsModal.show();
        });

        async function loadPaymentMethodsSelect(selectedValue = '') {
            if (!projPaymentType) return;
            const selected = String(selectedValue || '');
            try {
                const res = await fetch(paymentMethodsApi + '?action=list&code=' + encodeURIComponent(String(paymentMethodsCode)));
                if (!res.ok) throw new Error('Falha ao carregar formas de pagamento');
                const methods = await res.json();

                populatePaymentTypeFilter(methods);

                projPaymentType.innerHTML = '<option value="">Selecione...</option>';
                (Array.isArray(methods) ? methods : []).forEach(m => {
                    const name = String(m.name || '').trim();
                    if (!name) return;
                    const opt = document.createElement('option');
                    opt.value = String(m.id || '');
                    opt.textContent = name;
                    projPaymentType.appendChild(opt);
                });

                if (selected) {
                    // Prefer selecting by id; fallback to name for legacy records.
                    const byId = Array.from(projPaymentType.options).find(opt => opt.value === selected);
                    if (byId) {
                        projPaymentType.value = byId.value;
                        return;
                    }

                    const byName = Array.from(projPaymentType.options).find(opt => opt.textContent === selected);
                    if (byName) {
                        projPaymentType.value = byName.value;
                    }
                }
            } catch (err) {
                console.error('Falha ao carregar formas de pagamento:', err);
            }
        }

        function populatePaymentTypeFilter(methods) {
            if (!filtroPaymentType) return;
            const current = String(filtroPaymentType.value || '');
            filtroPaymentType.innerHTML = '<option value="">Todos os tipos</option>';

            (Array.isArray(methods) ? methods : []).forEach(m => {
                const name = String(m.name || '').trim();
                if (!name) return;
                const option = document.createElement('option');
                option.value = name.toLowerCase();
                option.textContent = name;
                filtroPaymentType.appendChild(option);
            });

            if (current && Array.from(filtroPaymentType.options).some(opt => opt.value === current)) {
                filtroPaymentType.value = current;
            }
        }

        if (btnAddPaymentType) {
            btnAddPaymentType.addEventListener('click', async () => {
                const name = prompt('Informe o nome da nova forma de pagamento:');
                if (!name || !name.trim()) return;
                const normalized = name.trim();
                btnAddPaymentType.disabled = true;
                try {
                    const body = new URLSearchParams({ name: normalized });
                    const res = await fetch(paymentMethodsApi + '?action=add&code=' + encodeURIComponent(String(paymentMethodsCode)), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString()
                    });
                    const json = await res.json();
                    if (!res.ok || json.error) {
                        throw new Error(json.error || 'Falha ao cadastrar forma de pagamento');
                    }
                    await loadPaymentMethodsSelect(normalized);
                    applyFilters();
                } catch (err) {
                    alert('Erro ao cadastrar forma de pagamento: ' + (err.message || err));
                } finally {
                    btnAddPaymentType.disabled = false;
                }
            });
        }

        btnExcluirProjeto.addEventListener('click', async ()=>{
            const projectId = document.getElementById('proj_id').value;
            if (!projectId) return;
            if (!confirm('Deseja excluir este projeto?')) return;
            const f = new FormData();
            f.append('id', projectId);
            const res = await fetch('api/delete_project.php', { method: 'POST', body: f });
            const j = await res.json();
            if (j.success) {
                bsModal.hide();
                location.reload();
            } else {
                alert(j.message || 'Erro ao excluir projeto');
            }
        });

        async function loadProjectChecklistOptions(ownerUserId, projectId = null) {
            const resolvedOwnerUserId = String(ownerUserId || sessionUserId);
            if (loadedChecklistOwnerId === resolvedOwnerUserId) {
                return;
            }

            try {
                const ownerParam = '&owner_user_id=' + encodeURIComponent(resolvedOwnerUserId);
                const projectParam = projectId ? '&project_id=' + encodeURIComponent(String(projectId)) : '';
                const [techRes, docRes] = await Promise.all([
                    fetch(`${checklistApi}?action=list&type=technical${projectParam}${ownerParam}`),
                    fetch(`${checklistApi}?action=list&type=document${projectParam}${ownerParam}`)
                ]);
                if (techRes.ok) projectChecklistOptions.technical = await techRes.json();
                if (docRes.ok) projectChecklistOptions.document = await docRes.json();
                loadedChecklistOwnerId = resolvedOwnerUserId;
            } catch (err) {
                console.error('Falha ao carregar opções de checklist:', err);
            }
        }

        function normalizeTextKey(value) {
            return String(value || '').toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
        }

        function normalizeChecklistValue(raw, items) {
            if (!raw) return [];
            let decoded = raw;
            if (typeof raw === 'string') {
                try { decoded = JSON.parse(raw); } catch (_) { decoded = raw; }
            }
            if (Array.isArray(decoded)) {
                return decoded.map(v => parseInt(v, 10)).filter(Number.isInteger);
            }
            if (typeof decoded === 'object') {
                const selected = [];
                const lookup = items.reduce((acc, item) => {
                    acc[normalizeTextKey(item.name)] = item.id;
                    return acc;
                }, {});
                Object.keys(decoded).forEach(key => {
                    if (!isNaN(key)) {
                        const id = parseInt(key, 10);
                        if (Number.isInteger(id)) selected.push(id);
                        return;
                    }
                    const slug = normalizeTextKey(key);
                    if (lookup[slug]) selected.push(lookup[slug]);
                });
                return Array.from(new Set(selected));
            }
            return [];
        }

        function renderProjectChecklistChoices(type, selectedIds) {
            const items = projectChecklistOptions[type] || [];
            const container = document.getElementById(type === 'technical' ? 'proj_technical_items' : 'proj_docs_items');
            if (!container) return;
            container.innerHTML = '';
            if (!items.length) {
                container.innerHTML = `<div class="small text-muted">Nenhum item configurado para ${type === 'technical' ? 'checklist técnico' : 'gestão documental'}.</div>`;
                return;
            }
            items.forEach(item => {
                const checked = selectedIds.includes(item.id) ? 'checked' : '';
                const row = document.createElement('div');
                row.className = 'form-check mb-2';
                row.innerHTML = `
                    <input class="form-check-input" type="checkbox" id="proj_${type}_item_${item.id}" value="${item.id}" ${checked}>
                    <label class="form-check-label" for="proj_${type}_item_${item.id}">${item.name}</label>
                `;
                container.appendChild(row);
            });
        }

        function getSelectedChecklistIds(type) {
            const container = document.getElementById(type === 'technical' ? 'proj_technical_items' : 'proj_docs_items');
            if (!container) return [];
            return Array.from(container.querySelectorAll('input[type="checkbox"]'))
                .filter(input => input.checked)
                .map(input => parseInt(input.value, 10))
                .filter(Number.isInteger);
        }

        function renderProjectChecklistSelections(project) {
            const techSelected = normalizeChecklistValue(project.technical_checklist, projectChecklistOptions.technical);
            const docSelected = normalizeChecklistValue(project.docs_checklist, projectChecklistOptions.document);
            renderProjectChecklistChoices('technical', techSelected);
            renderProjectChecklistChoices('document', docSelected);
        }

        function renderProjectChecklistDefaults() {
            renderProjectChecklistChoices('technical', []);
            renderProjectChecklistChoices('document', []);
        }

        // Enable drag-and-drop on project cards
        document.querySelectorAll('.card-project').forEach(card => {
            card.addEventListener('dragstart', (e) => {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', card.dataset.id);
                card.classList.add('dragging');
            });
            card.addEventListener('dragend', () => {
                card.classList.remove('dragging');
            });
        });

        function updateStageCounts(){
            document.querySelectorAll('.board-column').forEach(c => {
                const stage = c.dataset.stage;
                const counter = document.getElementById('count-' + stage);
                if (counter) counter.textContent = c.querySelectorAll('.card-project').length;
            });
        }

        // Enable drop targets on board columns
        document.querySelectorAll('.board-column').forEach(col => {
            col.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                col.classList.add('drop-target');
            });
            col.addEventListener('dragleave', () => {
                col.classList.remove('drop-target');
            });
            col.addEventListener('drop', async (e) => {
                e.preventDefault();
                col.classList.remove('drop-target');
                const projectId = e.dataTransfer.getData('text/plain');
                if (!projectId) return;
                const targetStatus = col.dataset.stage;
                if (!targetStatus) return;

                try {
                    const f = new FormData();
                    f.append('id', projectId);
                    f.append('status', targetStatus);
                    const res = await fetch('api/update_project.php', { method: 'POST', body: f });
                    const j = await res.json();
                    if (!j.success) throw new Error(j.message || 'Erro ao atualizar status');

                    const card = document.querySelector(`.card-project[data-id="${projectId}"]`);
                    if (card) {
                        const statusBadge = card.querySelector('.status-badge');
                        if (statusBadge) {
                            statusBadge.textContent = targetStatus;
                            statusBadge.className = 'badge status-badge ' + (targetStatus === 'Concluído' ? 'bg-success' : (targetStatus === 'Atrasado' ? 'bg-danger' : 'bg-warning'));
                        }
                        col.appendChild(card);
                    }
                    updateStageCounts();
                } catch (err) {
                    console.error(err);
                    alert('Falha ao mover projeto: ' + (err.message || err));
                }
            });
        });

        // Upload de arquivo de documento
        const uploadProjectFile = async (file) => {
            const projectId = document.getElementById('proj_id').value;
            if (!projectId) {
                alert('Grave o projeto primeiro antes de anexar arquivos.');
                return;
            }

            const maxSize = 10 * 1024 * 1024;
            if (file.size > maxSize) {
                alert('Arquivo muito grande. O tamanho máximo permitido é 10 MB.');
                return;
            }

            const formData = new FormData();
            formData.append('project_id', projectId);
            formData.append('doc_file', file);

            const res = await fetch('api/upload_project_attachment.php', { method: 'POST', body: formData });
            const j = await res.json();
            if (!j.success) {
                alert(j.message || 'Erro ao fazer upload do arquivo');
                return;
            }

            const attachmentsInput = document.getElementById('proj_doc_attachments');
            const current = attachmentsInput.value ? JSON.parse(attachmentsInput.value) : [];
            current.push(j.attachment);
            renderAttachmentsList(current);
        };

        const attachmentsInput = document.getElementById('proj_doc_attachments');
        const attachmentsList = document.getElementById('proj_doc_attachments_list');

        const renderAttachmentsList = (attachments) => {
            attachmentsInput.value = JSON.stringify(attachments);
            attachmentsList.innerHTML = attachments.length ? attachments.map((a, index) => `
                <div class="file-attachment-item" data-attachment-index="${index}">
                    <div class="text-truncate"><a href="${a.path}" target="_blank">${a.name}</a> <small class="text-muted">(${a.uploaded_at})</small></div>
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-delete-attachment" data-attachment-index="${index}">Excluir</button>
                </div>
            `).join('') : '<div class="small text-muted">Nenhum arquivo anexado.</div>';
        };

        document.getElementById('proj_doc_file').addEventListener('change', async (e)=>{
            const fileInput = e.target;
            if (!fileInput.files || !fileInput.files.length) return;
            await uploadProjectFile(fileInput.files[0]);
            fileInput.value = '';
        });

        attachmentsList.addEventListener('click', async (e) => {
            const btn = e.target.closest('.btn-delete-attachment');
            if (!btn) return;
            const index = parseInt(btn.dataset.attachmentIndex, 10);
            const attachments = attachmentsInput.value ? JSON.parse(attachmentsInput.value) : [];
            if (Number.isNaN(index) || index < 0 || index >= attachments.length) return;
            attachments.splice(index, 1);
            renderAttachmentsList(attachments);
        });

        const projDropzone = document.getElementById('proj_doc_dropzone');
        if (projDropzone) {
            projDropzone.addEventListener('dragover', (e) => {
                e.preventDefault();
                projDropzone.classList.add('dragover');
            });
            projDropzone.addEventListener('dragleave', () => {
                projDropzone.classList.remove('dragover');
            });
            projDropzone.addEventListener('drop', async (e) => {
                e.preventDefault();
                projDropzone.classList.remove('dragover');
                const files = e.dataTransfer.files;
                if (!files || !files.length) return;
                await uploadProjectFile(files[0]);
            });
        }

        // Delegate card buttons
        document.getElementById('kanbanBoard').addEventListener('click', async (e)=>{
            const edit = e.target.closest('.btn-edit');
            const del = e.target.closest('.btn-delete');
            const complete = e.target.closest('.btn-mark-complete');
            const delayed = e.target.closest('.btn-mark-delayed');
            if (edit) {
                const id = edit.getAttribute('data-id');
                const res = await fetch('api/get_project.php?id='+encodeURIComponent(id));
                const j = await res.json();
                if (j.success) {
                    const p = j.data;
                    document.getElementById('proj_id').value = p.id;
                    document.getElementById('proj_client_name').value = p.client_name;
                    document.getElementById('proj_proposal_value').value = p.proposal_value;
                    document.getElementById('proj_address').value = p.address;
                    document.getElementById('proj_lead_phone').value = p.lead_phone || '';
                    document.getElementById('proj_status').value = p.status;
                    document.getElementById('proj_closed_date').value = p.closed_date ? p.closed_date.split(' ')[0] : '';
                    document.getElementById('proj_due_days').value = (p.due_days ? p.due_days : 30);
                    document.getElementById('proj_contract').value = p.contract || '';
                    document.getElementById('proj_projeto').value = p.projeto || '';
                    await loadPaymentMethodsSelect(p.payment_method_id || p.payment_type || '');
                    document.getElementById('proj_payment_status').value = p.payment_status || '';
                    document.getElementById('proj_logistics_tracking_code').value = p.logistics_tracking_code || '';
                    document.getElementById('proj_logistics_delivery_date').value = p.logistics_delivery_date ? p.logistics_delivery_date.split(' ')[0] : '';
                    document.getElementById('proj_inspection_photos').value = p.inspection_photos || '';

                    await loadProjectChecklistOptions(p.user_id || sessionUserId, p.id);
                    renderProjectChecklistSelections(p);
                    document.getElementById('proj_technical_checklist').value = p.technical_checklist || '';
                    document.getElementById('proj_docs_checklist').value = p.docs_checklist || '';
                    const attachments = p.doc_attachments ? JSON.parse(p.doc_attachments) : [];
                    renderAttachmentsList(attachments);

                    btnExcluirProjeto.style.display = 'inline-block';
                    bsModal.show();
                } else alert(j.message || 'Erro ao carregar projeto');
            }

            if (del) {
                const id = del.getAttribute('data-id');
                if (!confirm('Confirma exclusão do projeto #' + id + '?')) return;
                const f = new FormData(); f.append('id', id);
                const res = await fetch('api/delete_project.php', { method: 'POST', body: f });
                const j = await res.json();
                if (j.success) location.reload(); else alert(j.message || 'Erro ao excluir');
            }

            if (complete) {
                const id = complete.getAttribute('data-id');
                if (!confirm('Marcar como concluído?')) return;
                const f = new FormData();
                f.append('id', id);
                f.append('status', 'Concluído');
                f.append('closed_date', new Date().toISOString().split('T')[0]);
                const res = await fetch('api/update_project.php', { method: 'POST', body: f });
                const j = await res.json();
                if (j.success) location.reload(); else alert(j.message || 'Erro ao atualizar');
            }

            if (delayed) {
                const id = delayed.getAttribute('data-id');
                if (!confirm('Marcar como atrasado?')) return;
                const f = new FormData();
                f.append('id', id);
                f.append('status', 'Atrasado');
                const res = await fetch('api/update_project.php', { method: 'POST', body: f });
                const j = await res.json();
                if (j.success) location.reload(); else alert(j.message || 'Erro ao atualizar');
            }
        });

        // Drag-and-drop para movimentar cards entre colunas de status
        document.querySelectorAll('.board-column').forEach(col => {
            col.addEventListener('dragover', e => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; col.classList.add('drop-target'); });
            col.addEventListener('dragleave', () => { col.classList.remove('drop-target'); });
            col.addEventListener('drop', async e => {
                e.preventDefault(); col.classList.remove('drop-target');
                const projectId = e.dataTransfer.getData('text/plain');
                if (!projectId) return;
                const targetStatus = col.dataset.stage;
                if (!targetStatus) return;
                try {
                    const f = new FormData();
                    f.append('id', projectId);
                    f.append('status', targetStatus);
                    const res = await fetch('api/update_project.php', { method: 'POST', body: f });
                    const j = await res.json();
                    if (!j.success) throw new Error(j.message || 'Erro ao mover projeto');

                    const card = document.querySelector(`.card-project[data-id="${projectId}"]`);
                    if (card) {
                        const statusBadge = card.querySelector('.status-badge');
                        if (statusBadge) {
                            statusBadge.textContent = targetStatus;
                            statusBadge.className = 'badge status-badge ' + (targetStatus === 'Concluído' ? 'bg-success' : (targetStatus === 'Atrasado' ? 'bg-danger' : 'bg-warning'));
                        }
                        const targetColor = col.dataset.stageColor || null;
                        if (targetColor) {
                            card.style.borderLeft = '4px solid ' + targetColor;
                            card.style.borderColor = targetColor;
                            const progressBar = card.querySelector('.progress-bar');
                            if (progressBar) {
                                progressBar.style.backgroundColor = targetColor;
                            }
                            const projectIdBadge = card.querySelector('.project-id-badge');
                            if (projectIdBadge) {
                                projectIdBadge.style.backgroundColor = targetColor;
                                projectIdBadge.style.color = '#fff';
                            }
                        }
                        card.querySelectorAll('.abbr-stage-badge').forEach(badge => {
                            const stageName = badge.dataset.stage;
                            if (stageName === targetStatus) {
                                const activeColor = col.dataset.stageColor || '#0d6efd';
                                badge.style.background = activeColor;
                                badge.style.color = '#fff';
                            } else {
                                badge.style.background = '#d8d8d8';
                                badge.style.color = '#6c757d';
                            }
                        });
                        col.appendChild(card);
                    }

                    updateStageCounts();
                    applyFilters();
                } catch(err) {
                    console.error(err);
                    alert('Falha ao mover projeto: ' + (err.message || err));
                }
            });
        });

        const kanbanScrollWrap = document.querySelector('.kanban-scroll-wrap');
        if (kanbanScrollWrap) {
            let isDraggingScroll = false;
            let startX = 0;
            let startScroll = 0;

            kanbanScrollWrap.addEventListener('mousedown', (e) => {
                if (e.button !== 0) return;
                if (e.target.closest('.card-project, .file-dropzone, button, input, textarea, select, a')) return;
                isDraggingScroll = true;
                kanbanScrollWrap.classList.add('scrolling');
                startX = e.pageX - kanbanScrollWrap.offsetLeft;
                startScroll = kanbanScrollWrap.scrollLeft;
            });

            document.addEventListener('mousemove', (e) => {
                if (!isDraggingScroll) return;
                e.preventDefault();
                const x = e.pageX - kanbanScrollWrap.offsetLeft;
                const walk = (x - startX) * 1.5;
                kanbanScrollWrap.scrollLeft = startScroll - walk;
            });

            document.addEventListener('mouseup', () => {
                if (!isDraggingScroll) return;
                isDraggingScroll = false;
                kanbanScrollWrap.classList.remove('scrolling');
            });
        }

        const btnCompactCards = document.getElementById('btnCompactCards');
        const compactStorageKey = 'projetosCompactCards';
        const topInfoStorageKey = 'projetosHideTopInfo';
        const setCompactView = (enabled) => {
            document.body.classList.toggle('compact-cards', enabled);
            if (btnCompactCards) {
                btnCompactCards.innerHTML = enabled
                    ? '<i class="fa fa-expand-alt" aria-hidden="true"></i>'
                    : '<i class="fa fa-compress-alt" aria-hidden="true"></i>';
                const compactTitle = enabled ? 'Expandir cards' : 'Compactar cards';
                btnCompactCards.title = compactTitle;
                btnCompactCards.setAttribute('aria-label', compactTitle);
            }
            try { localStorage.setItem(compactStorageKey, enabled ? '1' : '0'); } catch (e) { /* ignore */ }
        };
        const setTopInfoVisibility = (hidden) => {
            document.body.classList.toggle('hide-project-top-info', hidden);
            if (toggleTopInfoBtn) {
                toggleTopInfoBtn.innerHTML = hidden
                    ? '<i class="fa fa-eye" aria-hidden="true"></i>'
                    : '<i class="fa fa-eye-slash" aria-hidden="true"></i>';
                toggleTopInfoBtn.title = hidden ? 'Mostrar informacoes do topo' : 'Ocultar informacoes do topo';
                toggleTopInfoBtn.setAttribute('aria-label', hidden ? 'Mostrar informacoes do topo' : 'Ocultar informacoes do topo');
                toggleTopInfoBtn.setAttribute('aria-pressed', hidden ? 'true' : 'false');
            }
            try { localStorage.setItem(topInfoStorageKey, hidden ? '1' : '0'); } catch (e) { /* ignore */ }
        };
        if (btnCompactCards) {
            btnCompactCards.addEventListener('click', () => setCompactView(!document.body.classList.contains('compact-cards')));
            setCompactView(localStorage.getItem(compactStorageKey) === '1');
        }
        if (toggleTopInfoBtn) {
            toggleTopInfoBtn.addEventListener('click', () => {
                setTopInfoVisibility(!document.body.classList.contains('hide-project-top-info'));
            });
            setTopInfoVisibility(localStorage.getItem(topInfoStorageKey) === '1');
        }

        const currentUserId = "<?= $_SESSION['user_id'] ?>";
        const filtroBusca = document.getElementById('filtroBusca');
        const filtroStatus = document.getElementById('filtroStatus');
        const filtroPaymentStatus = document.getElementById('filtroPaymentStatus');
        const filtroPaymentType = document.getElementById('filtroPaymentType');
        const btnTodosProjetos = document.getElementById('btnTodosProjetos');
        const btnMeusProjetos = document.getElementById('btnMeusProjetos');
        const btnLimparFiltros = document.getElementById('btnLimparFiltros');
        let userFilter = 'all';

        const syncBoardColumns = () => {
            document.querySelectorAll('.board-column').forEach(col => {
                const visibleCards = Array.from(col.querySelectorAll('.card-project')).filter(card => card.style.display !== 'none').length;
                // Keep empty columns visible so cards can be dragged into them.
                col.style.display = '';
                const headerBadge = col.closest('.kanban-column')?.querySelector('.card-header .badge');
                if (headerBadge) headerBadge.textContent = visibleCards;
            });
        };

        const applyFilters = () => {
            const txt = filtroBusca.value.trim().toLowerCase();
            const statusFilter = filtroStatus.value;
            const paymentStatusFilter = filtroPaymentStatus.value;
            const paymentTypeFilter = filtroPaymentType.value.trim().toLowerCase();

            document.querySelectorAll('.card-project').forEach(card => {
                const ownerId = card.dataset.userId;
                const clientName = (card.dataset.clientName || '').toLowerCase();
                const projectId = (card.dataset.id || '').toString().toLowerCase();
                const contract = (card.dataset.contract || '').toLowerCase();
                const leadPhone = (card.dataset.leadPhone || '').toLowerCase();
                const paymentType = (card.dataset.paymentType || '').toLowerCase();
                const paymentStatus = (card.dataset.paymentStatus || '').toLowerCase();
                const status = (card.dataset.status || '').toLowerCase();

                const byUser = userFilter === 'all' || ownerId === currentUserId;
                const byText = txt === '' || clientName.includes(txt) || projectId.includes(txt) || contract.includes(txt) || leadPhone.includes(txt) || paymentType.includes(txt) || paymentStatus.includes(txt) || status.includes(txt);
                const byStatus = statusFilter === 'all' || statusFilter === '' || status === statusFilter;
                const byPaymentStatus = paymentStatusFilter === 'all' || paymentStatusFilter === '' || paymentStatus === paymentStatusFilter;
                const byPaymentType = paymentTypeFilter === '' || paymentType.includes(paymentTypeFilter);

                card.style.display = (byUser && byText && byStatus && byPaymentStatus && byPaymentType) ? 'block' : 'none';
            });

            syncBoardColumns();
        };

        btnTodosProjetos.addEventListener('click', () => {
            userFilter = 'all';
            btnTodosProjetos.classList.add('active');
            btnMeusProjetos.classList.remove('active');
            applyFilters();
        });

        btnMeusProjetos.addEventListener('click', () => {
            userFilter = 'mine';
            btnTodosProjetos.classList.remove('active');
            btnMeusProjetos.classList.add('active');
            applyFilters();
        });

        btnLimparFiltros.addEventListener('click', () => {
            filtroBusca.value = '';
            filtroStatus.value = 'all';
            filtroPaymentStatus.value = 'all';
            filtroPaymentType.value = '';
            userFilter = 'all';
            btnTodosProjetos.classList.add('active');
            btnMeusProjetos.classList.remove('active');
            applyFilters();
        });

        filtroBusca.addEventListener('input', applyFilters);
        filtroStatus.addEventListener('change', applyFilters);
        filtroPaymentStatus.addEventListener('change', applyFilters);
        filtroPaymentType.addEventListener('change', applyFilters);

        document.getElementById('leadDetailsPanel').addEventListener('click', (e)=>{
            if (e.target && e.target.id === 'closeLeadPanel') {
                document.getElementById('leadDetailsPanel').classList.add('hidden');
            }
        });

        document.getElementById('kanbanBoard').addEventListener('click', async (e)=>{
            const btn = e.target.closest('.btn-lead-details');
            if (!btn) return;
            const leadId = btn.dataset.leadId;
            if (!leadId) return;
            await showLeadDetails(leadId);
        });

        async function fetchLeadMovements(leadId) {
            try {
                const movementRes = await fetch('includes/leads_api.php?action=movements&lead_id=' + encodeURIComponent(leadId));
                if (!movementRes.ok) return '<div class="small text-danger">Falha ao carregar movimentações.</div>';
                const moves = await movementRes.json();
                if (!Array.isArray(moves) || moves.length === 0) {
                    return '<div class="small text-muted">Nenhuma movimentação registrada.</div>';
                }
                const rows = moves.slice().reverse().map(m => {
                    const createdAt = m.created_at ? new Date(m.created_at) : null;
                    const when = createdAt && !Number.isNaN(createdAt.getTime()) ? createdAt.toLocaleString('pt-BR') : (m.created_at || '—');
                    const fromTo = (m.from_status || m.from_stage_id || '').trim();
                    const to = (m.to_status || m.to_stage_id || '').trim();
                    const note = m.note ? `<div class="mt-1">${m.note}</div>` : '';
                    const changedBy = m.changed_by ? `<div class="small text-muted">Usuário: ${m.changed_by}</div>` : '';
                    let movementText = '';
                    if (fromTo && to) movementText = `<strong>${fromTo} → ${to}</strong>`;
                    else if (to) movementText = `<strong>${to}</strong>`;
                    else if (fromTo) movementText = `<strong>${fromTo}</strong>`;

                    return `<div class="border rounded p-2 mb-2">
                        <div class="small text-muted">${when}</div>
                        <div>${movementText}</div>
                        ${note}
                        ${changedBy}
                    </div>`;
                });
                return rows.join('');
            } catch (err) {
                console.error(err);
                return '<div class="small text-danger">Erro ao carregar movimentações.</div>';
            }
        }

        async function showLeadDetails(leadId) {
            try {
                const res = await fetch('includes/leads_api.php?action=get&id=' + encodeURIComponent(leadId));
                if (!res.ok) throw new Error('Lead não encontrado');
                const lead = await res.json();
                const content = document.getElementById('leadDetailContent');
                if (!content) return;

                const leadEmail = lead.email ? `<a href=\"mailto:${encodeURIComponent(lead.email)}\">${lead.email}</a>` : '—';
                const leadPhone = lead.phone ? `<a href=\"tel:${encodeURIComponent(lead.phone)}\">${lead.phone}</a>` : '—';
                const leadCity = lead.cidade || lead.city || '—';
                const leadStatus = lead.status || '—';
                const leadSource = lead.source || '—';
                const leadValue = lead.orcamento_value ? 'R$ ' + Number(lead.orcamento_value).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2}) : '—';

                content.innerHTML = `
                    <h4>${lead.name || '(Sem nome)'}</h4>
                    <div class=\"mb-2 small text-muted\">Lead #${lead.id} • Status: ${leadStatus}</div>
                    <dl class=\"row\">
                      <dt class=\"col-5\">Email</dt><dd class=\"col-7\">${leadEmail}</dd>
                      <dt class=\"col-5\">Telefone</dt><dd class=\"col-7\">${leadPhone}</dd>
                      <dt class=\"col-5\">Cidade</dt><dd class=\"col-7\">${leadCity}</dd>
                      <dt class=\"col-5\">Origem</dt><dd class=\"col-7\">${leadSource}</dd>
                      <dt class=\"col-5\">Valor estimado</dt><dd class=\"col-7\">${leadValue}</dd>
                    </dl>
                    <div class=\"mt-2\"><strong>Observações</strong><div class=\"text-muted small\">${lead.notes || '—'}</div></div>
                    <div class=\"mt-3\"><a href=\"leads_gestao.php?lead_id=${lead.id}\" class=\"btn btn-sm btn-outline-primary\">Abrir lead no Gestão de Leads</a></div>                    <div class="mt-4">
                        <h6>Histórico de movimentações</h6>
                        <div id="leadMovementTimeline" class="small text-muted">Carregando histórico...</div>
                    </div>                `;

                document.getElementById('leadDetailsPanel').classList.remove('hidden');
                document.getElementById('leadMovementTimeline').innerHTML = await fetchLeadMovements(leadId);
            } catch (err) {
                alert('Erro ao carregar detalhes do lead: ' + err.message);
            }
        }

        loadPaymentMethodsSelect('').finally(() => applyFilters());

        document.getElementById('formProjeto').addEventListener('submit', async (ev)=>{
            ev.preventDefault();
            document.getElementById('proj_technical_checklist').value = JSON.stringify(getSelectedChecklistIds('technical'));
            document.getElementById('proj_docs_checklist').value = JSON.stringify(getSelectedChecklistIds('document'));

            const form = ev.target;
            const data = new FormData(form);
            const id = document.getElementById('proj_id').value;
            const url = id ? 'api/update_project.php' : 'api/add_project.php';
            const res = await fetch(url, { method: 'POST', body: data });
            const j = await res.json();
            if (j.success) location.reload(); else alert(j.message || 'Erro ao salvar');
        });
    })();
</script>
