<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/includes/config.php';
$pageTitle = 'Personalizar Funil (Kanban)';
include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/leads_gestao.css">
<div class="d-flex">
  <?php include 'includes/sidebar.php'; ?>
  <main class="flex-grow-1 p-4">
    <div class="container-fluid">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0">Personalização do Funil</h1>
        <?php $isAdmin = true; ?>
        <div class="small text-muted">Você pode editar o funil (Edição liberada para todos)</div>
      </div>
      <script>const IS_ADMIN = true;</script>

      <div class="row">
        <div class="col-lg-4 mb-3">
          <div class="card p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <strong>Etapas</strong>
              <div><button id="addStageBtn" class="btn btn-sm btn-primary">+ Nova Etapa</button></div>
            </div>
            <div id="stagesList" class="stages-list" style="min-height:400px;">
              <!-- list populated by JS -->
            </div>
          </div>
        </div>
        <div class="col-lg-8">
          <div id="stageEditor" class="card p-3">
            <div id="editorContent" class="d-none">
              <h5 id="editorTitle">Editar etapa</h5>
              <form id="stageForm">
                <input type="hidden" id="stageId">
                <div class="row g-2">
                  <div class="col-md-6"><label class="form-label">Nome <i class="fa fa-info-circle ms-1 text-muted" data-bs-toggle="tooltip" title="Nome exibido da etapa no funil."></i></label><input id="stageName" class="form-control"></div>
                  <div class="col-md-3"><label class="form-label">Cor da coluna <i class="fa fa-info-circle ms-1 text-muted" data-bs-toggle="tooltip" title="Cor da coluna do kanban, usada nos indicadores e bordas."></i></label><input id="stageColor" type="color" class="form-control form-control-color" value="#6c757d"></div>
                  <div class="col-md-3 d-none"><label class="form-label">Cor do card <i class="fa fa-info-circle ms-1 text-muted" data-bs-toggle="tooltip" title="Cor de fundo dos cartões (campo oculto)."></i></label><input id="stageCardColor" type="color" class="form-control form-control-color" value="#ffffff"></div>
                </div>
                <div class="row g-2 mt-2">
                  <div class="col-md-4"><label class="form-label">SLA (dias) <i class="fa fa-info-circle ms-1 text-muted" data-bs-toggle="tooltip" title="Dias para SLA dessa etapa; usado em alertas de atraso."></i></label><input id="stageSla" type="number" class="form-control" min="0"></div>
                  <div class="col-md-4"><label class="form-label">Tipo final <i class="fa fa-info-circle ms-1 text-muted" data-bs-toggle="tooltip" title="Marca a etapa como Ganhou/Perdido ou Ativa."></i></label>
                    <select id="stageFinalType" class="form-select"><option value="none">Ativa</option><option value="won">Ganhou</option><option value="lost">Perdido</option></select>
                  </div>
                  <div class="col-md-4"><label class="form-label">Soma do pipeline <i class="fa fa-info-circle ms-1 text-muted" data-bs-toggle="tooltip" title="Se sim, oportunidades nesta etapa entram no cálculo do Valor no pipeline."></i></label><select id="stageForecast" class="form-select"><option value="1">Sim</option><option value="0">Não</option></select></div>
                </div>

                <div class="mt-3 d-flex gap-2 align-items-center">
                  <div class="form-check">
                    <input id="generateTask" class="form-check-input" type="checkbox">
                    <label class="form-check-label small">Gerar tarefa ao entrar <i class="fa fa-info-circle ms-1 text-muted" data-bs-toggle="tooltip" title="Cria uma tarefa automática ao mover para essa etapa."></i></label>
                  </div>
                  <div class="form-check">
                    <input id="alertInactivity" class="form-check-input" type="checkbox">
                    <label class="form-check-label small">Alertar por inatividade <i class="fa fa-info-circle ms-1 text-muted" data-bs-toggle="tooltip" title="Envia alerta se o lead ficar inativo por muito tempo."></i></label>
                  </div>
                  <div class="form-check">
                    <input id="blockAdvance" class="form-check-input" type="checkbox">
                    <label class="form-check-label small">Bloquear avanço sem ação <i class="fa fa-info-circle ms-1 text-muted" data-bs-toggle="tooltip" title="Impede mover a etapa sem ações obrigatórias."></i></label>
                  </div>
                  <div class="form-check">
                    <input id="allowProjectCreation" class="form-check-input" type="checkbox">
                    <label class="form-check-label small">Permitir criar projeto <i class="fa fa-info-circle ms-1 text-muted" data-bs-toggle="tooltip" title="Mostra botão de criar projeto no lead para esta etapa."></i></label>
                  </div>
                </div>

                <div class="mt-3 d-none">
                  <label class="form-label">Campos obrigatórios (JSON array) <i class="fa fa-info-circle ms-1 text-muted" data-bs-toggle="tooltip" title="JSON array com campos que devem estar preenchidos (ex: [\"email\"])."></i></label>
                  <textarea id="requiredFields" class="form-control" rows="2" placeholder='ex: ["email","phone"]'></textarea>
                </div>

                <div class="mt-3 d-flex gap-2">
                  <button id="saveStage" class="btn btn-primary">Salvar</button>
                  <button id="deleteStage" class="btn btn-outline-danger">Excluir</button>
                  <div id="saveMsg" class="ms-2 small text-success" style="display:none">Salvo</div>
                </div>

                <hr>
                <div>
                  <h6>Preview</h6>
                  <div id="stagePreview" style="padding:12px;border-radius:6px;border:1px solid #e6e6e6;">
                    <!-- preview box -->
                  </div>
                </div>
              </form>
            </div>
            <div id="noEditor" class="p-3 text-center text-muted">Selecione uma etapa à esquerda para visualizar e editar suas configurações.</div>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>

<script src="assets/js/funil_config.js"></script>
<style>
  /* Visual affordance for stage rows reorder */
  .stages-row { cursor: default; }
  .stages-row .drag-handle { display: inline-flex; align-items: center; justify-content: center; width:1.6rem; }
  .stages-row.dragging { opacity: 0.5; }
  .stages-row:hover .drag-handle { color: #495057; }
</style>
<?php include 'includes/footer.php'; ?>