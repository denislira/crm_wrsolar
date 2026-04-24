<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/permissions.php';
checkAccessOrRedirect('projetos');

$pageTitle = 'Personalizar Projetos (Kanban)';
include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/leads_gestao.css">
<style>
/* ── Projeto Config — Modern Design ─────────────────────────────── */
.fc-page { background: #f0f4fa; min-height: 100vh; }
.fc-header {
  background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
  padding: 1.5rem 2rem;
  border-radius: 0 0 24px 24px;
  margin-bottom: 2rem;
  box-shadow: 0 4px 24px rgba(37,99,235,0.18);
}
.fc-header h1 { color: #fff; font-size: 1.45rem; font-weight: 700; margin: 0; letter-spacing: -.3px; }
.fc-header .subtitle { color: rgba(255,255,255,0.72); font-size: .82rem; margin-top: 2px; }
.fc-panel { background: #fff; border-radius: 18px; box-shadow: 0 2px 16px rgba(0,0,0,0.06); border: 1px solid #e8edf5; overflow: hidden; }
.fc-panel-header {
  padding: .9rem 1.25rem;
  border-bottom: 1px solid #f0f4fa;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #fafbfd;
}
.fc-panel-header .fc-panel-title {
  font-weight: 700;
  font-size: .9rem;
  color: #1e3a5f;
  display: flex;
  align-items: center;
  gap: 7px;
}
.fc-panel-header .fc-panel-title i { color: #2563eb; }
.fc-panel-body { padding: 1.1rem 1.25rem; }
.stages-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: .65rem .9rem;
  margin-bottom: .5rem;
  border-radius: 12px;
  border: 1.5px solid #e8edf5;
  background: #fafbfd;
  cursor: pointer;
  transition: all .18s;
}
.stages-row:hover { border-color: #2563eb; background: #f0f6ff; box-shadow: 0 2px 8px rgba(37,99,235,0.08); }
.stages-row.dragging { opacity: 0.45; }
.stages-row .drag-handle { cursor: grab; color: #c0cce0; transition: color .15s; width: 1.4rem; display: inline-flex; align-items: center; justify-content: center; }
.stages-row:hover .drag-handle { color: #2563eb; }
.stages-row .stage-dot { width: 11px; height: 11px; border-radius: 50%; flex-shrink: 0; }
.stages-row .stage-name { font-weight: 600; font-size: .88rem; color: #1e293b; }
.stages-row .stage-pos { font-size: .73rem; color: #94a3b8; }
.btn-edit-stage {
  border: 1.5px solid #dbeafe;
  background: #eff6ff;
  color: #2563eb;
  font-size: .75rem;
  font-weight: 600;
  padding: 3px 12px;
  border-radius: 7px;
  transition: all .15s;
  white-space: nowrap;
  cursor: pointer;
}
.btn-edit-stage:hover { background: #2563eb; color: #fff; border-color: #2563eb; }
.fc-section {
  padding: 1rem 1.2rem;
  border-radius: 14px;
  margin-bottom: 1rem;
  border: 1.5px solid #e8edf5;
  background: #fafbfd;
}
.fc-section-title {
  font-size: .78rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: #64748b;
  margin-bottom: .8rem;
  display: flex;
  align-items: center;
  gap: 6px;
}
.fc-section-title i { font-size: .85rem; }
.fc-form-label { font-size: .78rem; font-weight: 600; color: #475569; margin-bottom: .35rem; }
.fc-form-control {
  border: 1.5px solid #e2e8f0; border-radius: 9px;
  padding: .45rem .75rem; font-size: .85rem;
  transition: border-color .15s, box-shadow .15s;
  background: #fff; width: 100%;
}
.fc-form-control:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.1); outline: none; }
select.fc-form-control { appearance: none; background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e"); background-repeat: no-repeat; background-position: right .6rem center; background-size: 1rem; padding-right: 2rem; }
input[type=color].fc-form-control { padding: .25rem .4rem; height: 40px; cursor: pointer; }
.fc-actions {
  display: flex; align-items: center; gap: .75rem;
  padding-top: .75rem;
  border-top: 1.5px solid #f0f4fa;
}
.btn-fc-save {
  background: linear-gradient(135deg, #2563eb, #1d4ed8);
  color: #fff; border: none; padding: .5rem 1.5rem;
  border-radius: 10px; font-weight: 700; font-size: .85rem;
  cursor: pointer; transition: all .15s; box-shadow: 0 2px 8px rgba(37,99,235,.25);
  display: flex; align-items: center; gap: 6px;
}
.btn-fc-save:hover { transform: translateY(-1px); box-shadow: 0 4px 16px rgba(37,99,235,.35); }
.btn-fc-delete {
  background: #fff; color: #ef4444; border: 1.5px solid #fecaca;
  padding: .45rem 1.1rem; border-radius: 10px; font-weight: 600; font-size: .85rem;
  cursor: pointer; transition: all .15s;
}
.btn-fc-delete:hover { background: #fef2f2; border-color: #ef4444; }
.fc-empty {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  padding: 3.5rem 2rem; text-align: center; color: #94a3b8;
}
.fc-empty i { font-size: 3rem; margin-bottom: 1rem; color: #cbd5e1; }
.fc-empty p { font-size: .9rem; margin: 0; }
.btn-add-stage {
  background: linear-gradient(135deg, #2563eb, #1d4ed8);
  color: #fff; border: none; padding: .4rem 1rem; border-radius: 9px;
  font-weight: 600; font-size: .8rem; cursor: pointer; transition: all .15s;
  display: flex; align-items: center; gap: 5px;
}
.btn-add-stage:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(37,99,235,.3); }
.fc-preview-wrap { margin-top: .75rem; }
.fc-preview-label { font-size: .73rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #94a3b8; margin-bottom: .5rem; }
[data-theme="dark"] .fc-panel,
body.theme-dark .fc-panel { background: #0f1e35; border-color: rgba(230,238,248,.06); box-shadow: 0 2px 16px rgba(0,0,0,.3); }
[data-theme="dark"] .fc-panel-header,
body.theme-dark .fc-panel-header { background: #0b1827; border-color: rgba(230,238,248,.06); }
[data-theme="dark"] .fc-section,
body.theme-dark .fc-section { background: #0b1827; border-color: rgba(230,238,248,.06); }
[data-theme="dark"] .stages-row,
body.theme-dark .stages-row { background: #0b1827; border-color: rgba(230,238,248,.06); }
[data-theme="dark"] .stages-row:hover,
body.theme-dark .stages-row:hover { background: #122040; border-color: #2563eb; }
[data-theme="dark"] .fc-form-control,
body.theme-dark .fc-form-control { background: #0b1827; border-color: rgba(230,238,248,.1); color: #e6eef8; }
[data-theme="dark"] .stages-row .stage-name,
body.theme-dark .stages-row .stage-name { color: #e6eef8; }
[data-theme="dark"] .fc-page,
body.theme-dark .fc-page { background: #071427; }
</style>
<div class="d-flex">
  <?php include 'includes/sidebar.php'; ?>
  <main class="flex-grow-1 fc-page">
    <div class="fc-header">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <h1><i class="fa fa-sliders-h me-2"></i>Personalização de etapas de projeto</h1>
          <div class="subtitle">Adicione/edite/Reordene etapas para o Kanban de Projetos</div>
        </div>        <div>
          <a href="projetos.php" class="btn btn-outline-light btn-sm">Voltar para Projetos</a>
        </div>      </div>
    </div>

    <div class="container-fluid px-4 pb-5">
      <div class="row g-4">
        <div class="col-lg-4">
          <div class="fc-panel">
            <div class="fc-panel-header">
              <div class="fc-panel-title"><i class="fa fa-layer-group"></i> Etapas do Projeto</div>
              <button id="addStageBtn" class="btn-add-stage"><i class="fa fa-plus"></i> Nova Etapa</button>
            </div>
            <div class="fc-panel-body">
              <div id="stagesList" style="min-height:380px;"></div>
            </div>
          </div>
        </div>
        <div class="col-lg-8">
          <div class="fc-panel" id="stageEditor">
            <div id="noEditor">
              <div class="fc-empty">
                <i class="fa fa-mouse-pointer"></i>
                <p>Selecione uma etapa ao lado para visualizar e editar suas configurações.</p>
              </div>
            </div>
            <div id="editorContent" class="d-none">
              <div class="fc-panel-header">
                <div class="fc-panel-title"><i class="fa fa-edit"></i> <span id="editorTitle">Editar etapa</span></div>
              </div>
              <div class="fc-panel-body">
                <form id="stageForm">
                  <input type="hidden" id="stageId">
                  <div class="fc-section">
                    <div class="fc-section-title"><i class="fa fa-tag"></i> Identidade</div>
                    <div class="row g-3">
                      <div class="col-md-7">
                        <label class="fc-form-label">Nome da etapa</label>
                        <input id="stageName" class="fc-form-control" placeholder="Ex: Documentação">
                      </div>
                      <div class="col-md-5">
                        <label class="fc-form-label">Cor da coluna</label>
                        <div class="d-flex align-items-center gap-2">
                          <input id="stageColor" type="color" class="fc-form-control" value="#6c757d" style="width:52px;flex-shrink:0;">
                          <span id="stageColorHex" class="small text-muted">#6c757d</span>
                        </div>
                      </div>
                      <div class="col-12">
                        <div class="form-check mt-1">
                          <input class="form-check-input" type="checkbox" id="stageIsInitial">
                          <label class="form-check-label" for="stageIsInitial">
                            Definir como etapa inicial do fluxo
                          </label>
                        </div>
                        <small class="text-muted">Quando um projeto for criado a partir de lead (+ Projeto), ele sera enviado para esta etapa.</small>
                      </div>
                    </div>
                  </div>
                  <div class="fc-actions">
                    <button id="saveStage" type="button" class="btn-fc-save"><i class="fa fa-save"></i> Salvar alterações</button>
                    <button id="deleteStage" type="button" class="btn-fc-delete"><i class="fa fa-trash me-1"></i> Excluir</button>
                  </div>
                  <div class="fc-preview-wrap mt-4">
                    <div class="fc-section-title"><i class="fa fa-eye"></i> Pré-visualização</div>
                    <div id="stagePreview"></div>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <div class="row g-4 mt-3">
            <div class="col-lg-6">
              <div class="fc-panel">
                <div class="fc-panel-header">
                  <div class="fc-panel-title"><i class="fa fa-wrench"></i> Checklist Técnico</div>
                  <button id="addTechnicalItemBtn" class="btn-add-stage"><i class="fa fa-plus"></i> Novo item</button>
                </div>
                <div class="fc-panel-body">
                  <div id="technicalList" style="min-height:220px"></div>
                </div>
              </div>
            </div>
            <div class="col-lg-6">
              <div class="fc-panel">
                <div class="fc-panel-header">
                  <div class="fc-panel-title"><i class="fa fa-file-alt"></i> Gestão Documental</div>
                  <button id="addDocItemBtn" class="btn-add-stage"><i class="fa fa-plus"></i> Novo item</button>
                </div>
                <div class="fc-panel-body">
                  <div id="docList" style="min-height:220px"></div>
                </div>
              </div>
            </div>
            <div class="col-lg-6">
              <div class="fc-panel">
                <div class="fc-panel-header">
                  <div class="fc-panel-title"><i class="fa fa-credit-card"></i> Formas de Pagamento</div>
                  <button id="addPaymentMethodBtnConfig" class="btn-add-stage"><i class="fa fa-plus"></i> Novo item</button>
                </div>
                <div class="fc-panel-body">
                  <div id="paymentMethodsList" style="min-height:220px"></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>
<script src="assets/js/projeto_config.js"></script>
<script>
// Atualizar hex label e preview ao mudar cor
document.addEventListener('input', function(e){
  if (e.target && e.target.id === 'stageColor') {
    const hex = document.getElementById('stageColorHex');
    if (hex) hex.textContent = e.target.value;
    // Atualizar preview em tempo real
    const previewBar = document.querySelector('#stagePreview .progress-bar');
    if (previewBar) previewBar.style.borderTopColor = e.target.value;
  }
});
// Light/dark mode support
document.addEventListener('DOMContentLoaded', function(){
  if (document.body.classList.contains('theme-dark') || document.documentElement.getAttribute('data-theme') === 'dark') {
    document.querySelector('.fc-page').style.background = '#071427';
  }
});
</script>
<?php include 'includes/footer.php'; ?>