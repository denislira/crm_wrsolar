<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/includes/config.php';
$pageTitle = 'Personalizar Funil (Kanban)';
include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/leads_gestao.css">

<style>
/* ── Funil Config — Modern Design ─────────────────────────────── */
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

/* Panel cards */
.fc-panel {
  background: #fff;
  border-radius: 18px;
  box-shadow: 0 2px 16px rgba(0,0,0,0.06);
  border: 1px solid #e8edf5;
  overflow: hidden;
}
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

/* Stage list rows */
.stages-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: .65rem .9rem;
  margin-bottom: .5rem;
  border-radius: 12px;
  border: 1.5px solid #e8edf5;
  background: #fafbfd;
  cursor: default;
  transition: all .18s;
}
.stages-row:hover { border-color: #2563eb; background: #f0f6ff; box-shadow: 0 2px 8px rgba(37,99,235,0.08); }
.stages-row.dragging { opacity: 0.45; }
.stages-row .drag-handle { cursor: grab; color: #c0cce0; transition: color .15s; width: 1.4rem; display: inline-flex; align-items: center; justify-content: center; }
.stages-row:hover .drag-handle { color: #2563eb; }
.stages-row .stage-dot { width: 11px; height: 11px; border-radius: 50%; flex-shrink: 0; }
.stages-row .stage-name { font-weight: 600; font-size: .88rem; color: #1e293b; }
.stages-row .stage-pos { font-size: .73rem; color: #94a3b8; }

/* Edit btn */
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
}
.btn-edit-stage:hover { background: #2563eb; color: #fff; border-color: #2563eb; }

/* Editor form sections */
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

/* Toggle switches */
.fc-toggle-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: .5rem .75rem;
}
.fc-toggle-item {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: .5rem .75rem;
  border-radius: 10px;
  border: 1.5px solid #e8edf5;
  background: #fff;
  cursor: pointer;
  transition: all .15s;
  user-select: none;
}
.fc-toggle-item:hover { border-color: #93c5fd; background: #f0f6ff; }
.fc-toggle-item input[type=checkbox] { display: none; }
.fc-toggle-switch {
  width: 36px; height: 20px; border-radius: 10px;
  background: #cbd5e1; flex-shrink: 0;
  position: relative; transition: background .2s;
}
.fc-toggle-switch::after {
  content: ''; position: absolute; width: 14px; height: 14px;
  border-radius: 50%; background: #fff; top: 3px; left: 3px;
  transition: left .2s; box-shadow: 0 1px 3px rgba(0,0,0,.2);
}
.fc-toggle-item input:checked ~ .fc-toggle-info .fc-toggle-switch { background: #2563eb; }
.fc-toggle-item:has(input:checked) .fc-toggle-switch { background: #2563eb; }
.fc-toggle-item:has(input:checked) .fc-toggle-switch::after { left: 19px; }
.fc-toggle-item:has(input:checked) { border-color: #93c5fd; background: #eff6ff; }
.fc-toggle-label { font-size: .8rem; font-weight: 600; color: #374151; line-height: 1.3; }
.fc-toggle-desc { font-size: .72rem; color: #94a3b8; line-height: 1.2; margin-top: 1px; }
.fc-toggle-icon { font-size: .95rem; width: 22px; text-align: center; flex-shrink: 0; }

/* Report toggles (special) */
.fc-toggle-item.type-conversion:has(input:checked) { border-color: #6ee7b7; background: #f0fdf4; }
.fc-toggle-item.type-conversion:has(input:checked) .fc-toggle-switch { background: #10b981; }
.fc-toggle-item.type-qualification:has(input:checked) { border-color: #93c5fd; background: #eff6ff; }
.fc-toggle-item.type-qualification:has(input:checked) .fc-toggle-switch { background: #2563eb; }

/* Form controls */
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
input[type=number].fc-form-control { -moz-appearance: textfield; appearance: textfield; }

/* Action bar */
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
.fc-save-msg { color: #10b981; font-size: .8rem; font-weight: 600; display: none; align-items: center; gap: 4px; }
.fc-save-msg i { font-size: 1rem; }

/* Empty state */
.fc-empty {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  padding: 3.5rem 2rem; text-align: center; color: #94a3b8;
}
.fc-empty i { font-size: 3rem; margin-bottom: 1rem; color: #cbd5e1; }
.fc-empty p { font-size: .9rem; margin: 0; }

/* Add stage btn */
.btn-add-stage {
  background: linear-gradient(135deg, #2563eb, #1d4ed8);
  color: #fff; border: none; padding: .4rem 1rem; border-radius: 9px;
  font-weight: 600; font-size: .8rem; cursor: pointer; transition: all .15s;
  display: flex; align-items: center; gap: 5px;
}
.btn-add-stage:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(37,99,235,.3); }

/* Preview card */
.fc-preview-wrap { margin-top: .75rem; }
.fc-preview-label { font-size: .73rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #94a3b8; margin-bottom: .5rem; }

/* ── dark mode ──────────────────── */
[data-theme="dark"] .fc-panel,
body.theme-dark .fc-panel { background: #0f1e35; border-color: rgba(230,238,248,.06); box-shadow: 0 2px 16px rgba(0,0,0,.3); }
[data-theme="dark"] .fc-panel-header,
body.theme-dark .fc-panel-header { background: #0b1827; border-color: rgba(230,238,248,.06); }
[data-theme="dark"] .fc-section,
body.theme-dark .fc-section { background: #0b1827; border-color: rgba(230,238,248,.06); }
[data-theme="dark"] .fc-toggle-item,
body.theme-dark .fc-toggle-item { background: #0f1e35; border-color: rgba(230,238,248,.08); }
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
[data-theme="dark"] .fc-toggle-label,
body.theme-dark .fc-toggle-label { color: #e6eef8; }
</style>

<div class="d-flex">
  <?php include 'includes/sidebar.php'; ?>
  <main class="flex-grow-1 fc-page">
    <?php $isAdmin = true; ?>
    <script>const IS_ADMIN = true;</script>

    <!-- Header -->
    <div class="fc-header">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <h1><i class="fa fa-sliders-h me-2"></i>Personalização do Funil</h1>
          <div class="subtitle">Configure etapas, comportamento e métricas do seu funil de vendas</div>
        </div>
      </div>
    </div>

    <div class="container-fluid px-4 pb-5">
      <div class="row g-4">

        <!-- ── Coluna esquerda: lista de etapas ── -->
        <div class="col-lg-4">
          <div class="fc-panel">
            <div class="fc-panel-header">
              <div class="fc-panel-title"><i class="fa fa-layer-group"></i> Etapas do Funil</div>
              <button id="addStageBtn" class="btn-add-stage"><i class="fa fa-plus"></i> Nova Etapa</button>
            </div>
            <div class="fc-panel-body">
              <div id="stagesList" style="min-height:380px;">
                <!-- list populated by JS -->
              </div>
            </div>
          </div>
        </div>

        <!-- ── Coluna direita: editor ── -->
        <div class="col-lg-8">
          <div class="fc-panel" id="stageEditor">

            <!-- Empty state -->
            <div id="noEditor">
              <div class="fc-empty">
                <i class="fa fa-mouse-pointer"></i>
                <p>Selecione uma etapa ao lado para visualizar e editar suas configurações.</p>
              </div>
            </div>

            <!-- Editor content -->
            <div id="editorContent" class="d-none">
              <div class="fc-panel-header">
                <div class="fc-panel-title"><i class="fa fa-edit"></i> <span id="editorTitle">Editar etapa</span></div>
              </div>
              <div class="fc-panel-body">
                <form id="stageForm">
                  <input type="hidden" id="stageId">

                  <!-- Identidade -->
                  <div class="fc-section">
                    <div class="fc-section-title"><i class="fa fa-tag"></i> Identidade</div>
                    <div class="row g-3">
                      <div class="col-md-7">
                        <label class="fc-form-label">Nome da etapa</label>
                        <input id="stageName" class="fc-form-control" placeholder="Ex: Proposta Enviada">
                      </div>
                      <div class="col-md-2 d-none">
                        <input id="stageCardColor" type="color" class="fc-form-control" value="#ffffff">
                      </div>
                      <div class="col-md-5">
                        <label class="fc-form-label">Cor da coluna</label>
                        <div class="d-flex align-items-center gap-2">
                          <input id="stageColor" type="color" class="fc-form-control" value="#6c757d" style="width:52px;flex-shrink:0;">
                          <span id="stageColorHex" class="small text-muted">#6c757d</span>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Configurações -->
                  <div class="fc-section">
                    <div class="fc-section-title"><i class="fa fa-cog"></i> Configurações</div>
                    <div class="row g-3">
                      <div class="col-md-4">
                        <label class="fc-form-label">SLA (dias)</label>
                        <input id="stageSla" type="number" class="fc-form-control" min="0" placeholder="0">
                      </div>
                      <div class="col-md-4">
                        <label class="fc-form-label">Tipo final <i class="fa fa-info-circle text-muted" data-bs-toggle="tooltip" title="Defina se a etapa encerra o funil e se o resultado é ganho ou perdido."></i></label>
                        <select id="stageFinalType" class="fc-form-control">
                          <option value="none">Continuar (não final)</option>
                          <option value="won">Fechamento: Venda concluída</option>
                          <option value="lost">Fechamento: Venda perdida</option>
                        </select>
                      </div>
                      <div class="col-md-4">
                        <label class="fc-form-label">Soma do pipeline</label>
                        <select id="stageForecast" class="fc-form-control">
                          <option value="1">Sim</option>
                          <option value="0">Não</option>
                        </select>
                      </div>
                    </div>
                  </div>

                  <!-- Automações -->
                  <div class="fc-section">
                    <div class="fc-section-title"><i class="fa fa-bolt"></i> Automações & Regras</div>
                    <div class="fc-toggle-grid">

                      <label class="fc-toggle-item">
                        <input id="generateTask" type="checkbox">
                        <span class="fc-toggle-icon text-warning"><i class="fa fa-tasks"></i></span>
                        <div>
                          <div class="fc-toggle-label">Gerar tarefa</div>
                          <div class="fc-toggle-desc">Ao entrar na etapa</div>
                        </div>
                        <span class="fc-toggle-switch ms-auto"></span>
                      </label>

                      <label class="fc-toggle-item">
                        <input id="alertInactivity" type="checkbox">
                        <span class="fc-toggle-icon text-danger"><i class="fa fa-bell"></i></span>
                        <div>
                          <div class="fc-toggle-label">Alerta inatividade</div>
                          <div class="fc-toggle-desc">Leads parados por SLA</div>
                        </div>
                        <span class="fc-toggle-switch ms-auto"></span>
                      </label>

                      <label class="fc-toggle-item">
                        <input id="blockAdvance" type="checkbox">
                        <span class="fc-toggle-icon text-secondary"><i class="fa fa-lock"></i></span>
                        <div>
                          <div class="fc-toggle-label">Bloquear avanço</div>
                          <div class="fc-toggle-desc">Sem ação obrigatória</div>
                        </div>
                        <span class="fc-toggle-switch ms-auto"></span>
                      </label>

                      <label class="fc-toggle-item">
                        <input id="allowProjectCreation" type="checkbox">
                        <span class="fc-toggle-icon text-info"><i class="fa fa-project-diagram"></i></span>
                        <div>
                          <div class="fc-toggle-label">Criar projeto</div>
                          <div class="fc-toggle-desc">Botão visível no lead</div>
                        </div>
                        <span class="fc-toggle-switch ms-auto"></span>
                      </label>

                    </div>
                  </div>

                  <!-- Relatórios -->
                  <div class="fc-section" style="border-color:#dbeafe;background:linear-gradient(135deg,#f0f6ff,#fafbfd);">
                    <div class="fc-section-title" style="color:#1d4ed8;"><i class="fa fa-chart-bar"></i> Comportamento nos Relatórios</div>
                    <div class="fc-toggle-grid">

                      <label class="fc-toggle-item type-conversion">
                        <input id="isConversion" type="checkbox">
                        <span class="fc-toggle-icon" style="color:#10b981;"><i class="fa fa-trophy"></i></span>
                        <div>
                          <div class="fc-toggle-label">Venda Concluída</div>
                          <div class="fc-toggle-desc">Conta como "Leads Fechados"</div>
                        </div>
                        <span class="fc-toggle-switch ms-auto"></span>
                      </label>

                      <label class="fc-toggle-item type-qualification">
                        <input id="isQualification" type="checkbox">
                        <span class="fc-toggle-icon" style="color:#2563eb;"><i class="fa fa-filter"></i></span>
                        <div>
                          <div class="fc-toggle-label">Qualificação (SQL)</div>
                          <div class="fc-toggle-desc">MQL → SQL no relatório</div>
                        </div>
                        <span class="fc-toggle-switch ms-auto"></span>
                      </label>

                    </div>
                  </div>

                  <!-- JSON required fields (hidden) -->
                  <div class="mt-2 d-none">
                    <textarea id="requiredFields" class="fc-form-control" rows="2" placeholder='ex: ["email","phone"]'></textarea>
                  </div>

                  <!-- Ações -->
                  <div class="fc-actions">
                    <button id="saveStage" type="button" class="btn-fc-save"><i class="fa fa-save"></i> Salvar alterações</button>
                    <button id="deleteStage" type="button" class="btn-fc-delete"><i class="fa fa-trash me-1"></i> Excluir</button>
                    <div id="saveMsg" class="fc-save-msg"><i class="fa fa-check-circle"></i> Salvo com sucesso!</div>
                  </div>

                  <!-- Preview -->
                  <div class="fc-preview-wrap mt-4">
                    <div class="fc-section-title"><i class="fa fa-eye"></i> Pré-visualização</div>
                    <div id="stagePreview"></div>
                  </div>

                </form>
              </div><!-- /fc-panel-body -->
            </div><!-- /editorContent -->

          </div><!-- /fc-panel -->
        </div><!-- /col -->

      </div><!-- /row -->
    </div><!-- /container -->
  </main>
</div>

<script src="assets/js/funil_config.js"></script>
<script>
// Atualiza hex label ao mudar cor
document.addEventListener('input', function(e){
  if (e.target && e.target.id === 'stageColor') {
    const hex = document.getElementById('stageColorHex');
    if (hex) hex.textContent = e.target.value;
  }
});
// saveMsg flex
document.addEventListener('DOMContentLoaded', function(){
  const msg = document.getElementById('saveMsg');
  if (msg) {
    const orig = msg.style.display;
    Object.defineProperty(msg.style, '_display', { get: function(){ return this.display; }, set: function(v){ this.display = v === 'inline' ? 'flex' : v; } });
  }
});
</script>
<?php include 'includes/footer.php'; ?>
