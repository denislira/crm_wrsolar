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
        <?php $isAdmin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 1); ?>
        <div class="small text-muted"><?php echo $isAdmin ? 'Você pode editar o funil (Administrador)' : 'Você não tem permissão para editar (somente visualização)'; ?></div>
      </div>
      <script>const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;</script>

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
                  <div class="col-md-6"><label class="form-label">Nome</label><input id="stageName" class="form-control"></div>
                  <div class="col-md-3"><label class="form-label">Cor da coluna</label><input id="stageColor" type="color" class="form-control form-control-color" value="#6c757d"></div>
                  <div class="col-md-3"><label class="form-label">Cor do card</label><input id="stageCardColor" type="color" class="form-control form-control-color" value="#ffffff"></div>
                </div>
                <div class="row g-2 mt-2">
                  <div class="col-md-3"><label class="form-label">Ícone (font-awesome)</label><input id="stageIcon" class="form-control" placeholder="ex: fa-briefcase"></div>
                  <div class="col-md-3"><label class="form-label">SLA (dias)</label><input id="stageSla" type="number" class="form-control" min="0"></div>
                  <div class="col-md-3"><label class="form-label">Tipo final</label>
                    <select id="stageFinalType" class="form-select"><option value="none">Ativa</option><option value="won">Ganhou</option><option value="lost">Perdido</option></select>
                  </div>
                  <div class="col-md-3"><label class="form-label">Incluir no forecast</label><select id="stageForecast" class="form-select"><option value="1">Sim</option><option value="0">Não</option></select></div>
                </div>

                <div class="mt-3 d-flex gap-2 align-items-center">
                  <div class="form-check">
                    <input id="generateTask" class="form-check-input" type="checkbox">
                    <label class="form-check-label small">Gerar tarefa ao entrar</label>
                  </div>
                  <div class="form-check">
                    <input id="alertInactivity" class="form-check-input" type="checkbox">
                    <label class="form-check-label small">Alertar por inatividade</label>
                  </div>
                  <div class="form-check">
                    <input id="blockAdvance" class="form-check-input" type="checkbox">
                    <label class="form-check-label small">Bloquear avanço sem ação</label>
                  </div>
                </div>

                <div class="mt-3">
                  <label class="form-label">Campos obrigatórios (JSON array)</label>
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
<?php include 'includes/footer.php'; ?>