<?php
// Ensure session and auth
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

require_once __DIR__ . '/includes/config.php';
include 'includes/header.php';
?>
<div class="d-flex">
  <?php include 'includes/sidebar.php'; ?>
  <main class="flex-grow-1 p-4">
    <div id="funil" class="container-fluid position-relative">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Funil de Vendas</h1>
        <div>
          <button id="open-manage-stages" class="btn btn-outline-secondary">Gerenciar Estágios</button>
        </div>
      </div>

      <div id="kanban-scroll-wrapper" class="w-100" style="overflow-x:auto; max-width:100vw;">
        <div id="kanban-container" class="d-flex gap-3 pb-3" style="min-width:100%;"></div>
      </div>

      <!-- Floating helper -->
      <p class="text-muted small mt-3">Arraste os cards entre colunas para atualizar o status.</p>

      <!-- Actions (moved into header) -->
    </div>

<!-- Modal (reused by leads.php if present) -->
<div class="modal fade" id="leadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="leadModalTitle">Novo Lead</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body">
        <form id="leadForm">
          <input type="hidden" id="lead-id">
          <div class="mb-3">
            <label class="form-label">Nome</label>
            <input id="lead-name" class="form-control" required>
          </div>
          <div class="row">
            <div class="mb-3 col-md-6">
              <label class="form-label">Email</label>
              <input id="lead-email" class="form-control" type="email">
            </div>
            <div class="mb-3 col-md-6">
              <label class="form-label">Telefone</label>
              <input id="lead-phone" class="form-control" type="tel">
            </div>
          </div>
          <div class="row">
            <div class="mb-3 col-md-6">
              <label class="form-label">Fonte</label>
              <input id="lead-source" class="form-control">
            </div>
            <div class="mb-3 col-md-6">
              <label class="form-label">Status</label>
              <select id="lead-status" class="form-select">
                <option>Novo</option>
                <option>Qualificado</option>
                <option>Contato Realizado</option>
                <option>Perdido</option>
              </select>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button id="save-lead" type="button" class="btn btn-primary">Salvar</button>
      </div>
    </div>
  </div>
</div>

<script>
let STAGES = [];

async function fetchStages(){
  const res = await fetch('includes/funil_stages_api.php?action=list');
  if(!res.ok) return [];
  let data = await res.json();
  // If no stages exist yet, create sensible defaults
  if(!data || data.length === 0){
    const defaults = ['Novo','Qualificado','Contato Realizado','Prospecção','Visita Técnica','Proposta Enviada','Negociação','Fechado','Perdido'];
    for(const name of defaults){
      await fetch('includes/funil_stages_api.php?action=add', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ name }) });
    }
    const r2 = await fetch('includes/funil_stages_api.php?action=list');
    data = r2.ok ? await r2.json() : [];
  }
  STAGES = data.map(d => ({ id: d.id, name: d.name, position: parseInt(d.position), color: d.color || '#6c757d' }));
  return STAGES.map(s => s.name);
}

async function fetchLeads(){
  const res = await fetch('includes/leads_api.php?action=list');
  if(!res.ok) return [];
  return res.json();
}

function createColumn(stageObj){
  const col = document.createElement('div');
  col.className = 'card flex-shrink-0';
  col.style.width = '18rem';
  col.style.flex = '0 0 auto';
  col.innerHTML = `
    <div class="card-body d-flex flex-column p-2" style="border-radius:0.75rem;">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="mb-0 stage-title" style="color:${stageObj.color}; font-weight:600">${stageObj.name}</h6>
        <span class="badge stage-count" style="background:${stageObj.color}; color:#fff;">0</span>
      </div>
      <div class="list-group list-group-flush column-content" data-stage="${stageObj.name}" style="min-height:120px"></div>
    </div>
  `;
  return col;
}

function renderKanban(leads){
  const container = document.getElementById('kanban-container');
  container.innerHTML = '';
  STAGES.forEach(stageObj => {
    const items = leads.filter(l => l.status === stageObj.name || (l.stage_id && l.stage_id == stageObj.id));
    const col = createColumn(stageObj);
    const badge = col.querySelector('.stage-count');
    badge.textContent = items.length;
    const list = col.querySelector('.column-content');
    items.forEach(it => {
      const card = document.createElement('div');
      card.className = 'list-group-item list-group-item-action mb-2 lead-card';
      card.draggable = true;
      card.dataset.id = it.id;
      card.style.background = '#fff';
      card.style.borderLeft = '6px solid ' + (stageObj.color || '#6c757d');
      card.style.color = '#212529';
      card.innerHTML = `<div class="fw-semibold">${escapeHtml(it.name)}</div><div class="small text-muted">${escapeHtml(it.email||'')} ${escapeHtml(it.phone||'')}</div>`;
      list.appendChild(card);
    });
    container.appendChild(col);
  });
  setupDragAndDrop();
}

function escapeHtml(s){ return (s||'').toString().replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;'); }

function setupDragAndDrop(){
  const cards = document.querySelectorAll('.list-group-item');
  const columns = document.querySelectorAll('.column-content');
  let draggedId = null;

  cards.forEach(c=>{
    c.addEventListener('dragstart', e=>{ draggedId = c.dataset.id; c.classList.add('opacity-50'); });
    c.addEventListener('dragend', e=>{ c.classList.remove('opacity-50'); draggedId = null; });
  });

  columns.forEach(col => {
    col.addEventListener('dragover', e=>{ e.preventDefault(); col.classList.add('border','border-primary'); });
    col.addEventListener('dragleave', e=>{ col.classList.remove('border','border-primary'); });
    col.addEventListener('drop', async e=>{
      e.preventDefault(); col.classList.remove('border','border-primary');
      const newStage = col.dataset.stage;
      if(!draggedId) return;
      await fetch('includes/leads_api.php?action=update_status', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id: draggedId, status: newStage }) });
      loadAndRender();
    });
  });
}

async function loadAndRender(){
  await fetchStages();
  const leads = await fetchLeads();
  renderKanban(leads);
}

// Stage manager UI
function openStagesManager(){
  renderStagesManager();
  const el = document.getElementById('stagesManagerModal');
  const modal = new bootstrap.Modal(el);
  modal.show();
}

async function renderStagesManager(){
  const list = document.getElementById('stages-list');
  const res = await fetch('includes/funil_stages_api.php?action=list');
  const stages = res.ok ? await res.json() : [];
  STAGES = stages.map(s=>({id:s.id, name:s.name, position: parseInt(s.position), color: s.color || '#6c757d'}));
  list.innerHTML = '';
  STAGES.forEach((s, idx) =>{
    const row = document.createElement('div');
    row.className = 'd-flex align-items-center mb-2 gap-2';
    row.innerHTML = `
      <input class="form-control form-control-sm stage-name" data-id="${s.id}" value="${escapeHtml(s.name)}">
      <input type="color" class="form-control form-control-sm stage-color" data-id="${s.id}" value="${s.color || '#6c757d'}" style="width:3rem;">
      <div class="btn-group">
        <button class="btn btn-sm btn-outline-secondary move-up">▲</button>
        <button class="btn btn-sm btn-outline-secondary move-down">▼</button>
        <button class="btn btn-sm btn-danger ms-2 delete-stage">Excluir</button>
      </div>
    `;
    list.appendChild(row);

    row.querySelector('.delete-stage').addEventListener('click', async ()=>{
      if(!confirm('Excluir estágio "'+s.name+'"?')) return;
      await fetch('includes/funil_stages_api.php?action=delete', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id: s.id }) });
      await renderStagesManager();
      loadAndRender();
    });

    row.querySelector('.move-up').addEventListener('click', async ()=>{ await swapStagePosition(idx, idx-1); });
    row.querySelector('.move-down').addEventListener('click', async ()=>{ await swapStagePosition(idx, idx+1); });
    row.querySelector('.stage-name').addEventListener('blur', async (ev)=>{
      const newName = ev.target.value.trim();
      const color = row.querySelector('.stage-color').value;
      if(newName && (newName !== s.name || color !== s.color)){
        await fetch('includes/funil_stages_api.php?action=update', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id: s.id, name: newName, color }) });
        await renderStagesManager(); loadAndRender();
      }
    });
    row.querySelector('.stage-color').addEventListener('input', async (ev)=>{
      const color = ev.target.value;
      const name = row.querySelector('.stage-name').value.trim();
      await fetch('includes/funil_stages_api.php?action=update', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id: s.id, name, color }) });
      await renderStagesManager(); loadAndRender();
    });
  });
}

async function swapStagePosition(i, j){
  if(j < 0 || j >= STAGES.length) return;
  const positions = STAGES.map(s=>({ id: s.id, position: s.position }));
  // swap positions in local array
  const tmp = STAGES[i].position; STAGES[i].position = STAGES[j].position; STAGES[j].position = tmp;
  // sort by new position then send to API
  const payload = { positions: STAGES.map(s => ({ id: s.id, position: s.position })) };
  await fetch('includes/funil_stages_api.php?action=reorder', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
  await renderStagesManager(); loadAndRender();
}

document.addEventListener('DOMContentLoaded', ()=>{
  loadAndRender();
  const leadModalEl = document.getElementById('leadModal');
  const leadModal = new bootstrap.Modal(leadModalEl);

  // Save lead handler (used by modal on both Funil and Leads pages)
  document.getElementById('save-lead').addEventListener('click', async ()=>{
    const id = document.getElementById('lead-id').value;
    const statusSelect = document.getElementById('lead-status');
    const selectedValue = statusSelect.value;
    const selectedText = statusSelect.options[statusSelect.selectedIndex]?.text || selectedValue;

    const payload = {
      name: document.getElementById('lead-name').value,
      email: document.getElementById('lead-email').value,
      phone: document.getElementById('lead-phone').value,
      source: document.getElementById('lead-source').value,
      status: selectedText
    };

    if (selectedValue && !isNaN(parseInt(selectedValue,10))) payload.stage_id = selectedValue;

    const action = id ? 'update' : 'add';
    try {
      const res = await fetch('includes/leads_api.php?action='+action, { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(Object.assign(payload, id?{id}:{})) });
      const txt = await res.text();
      let payloadResp;
      try { payloadResp = JSON.parse(txt); } catch(e){ payloadResp = { raw: txt }; }
      if (res.ok) {
        leadModal.hide();
        loadAndRender();
      } else {
        console.error('Save lead (funil) failed', res.status, payloadResp);
        alert('Erro ao salvar lead: ' + (payloadResp.error || payloadResp.message || JSON.stringify(payloadResp)));
      }
    } catch (err) {
      console.error('Network or unexpected error saving lead (funil)', err);
      alert('Erro ao salvar lead (network). Veja console.');
    }
  });

  document.getElementById('open-manage-stages').addEventListener('click', openStagesManager);

  document.getElementById('add-stage-btn').addEventListener('click', async ()=>{
    const input = document.getElementById('new-stage-name');
    const name = input.value.trim(); if(!name) return; input.value = '';
  const color = '#6c757d';
  await fetch('includes/funil_stages_api.php?action=add', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ name, color }) });
    await renderStagesManager(); loadAndRender();
  });
});
</script>

<!-- Stages manager modal -->
<div class="modal fade" id="stagesManagerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
  <div class="modal-content">
    <div class="modal-header">
    <h5 class="modal-title">Gerenciar Estágios do Funil</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
    </div>
    <div class="modal-body">
    <div class="mb-3 d-flex gap-2">
      <input id="new-stage-name" class="form-control" placeholder="Novo estágio">
      <button id="add-stage-btn" class="btn btn-primary">Adicionar</button>
    </div>
    <div id="stages-list"></div>
    <div class="mt-3 small text-muted">Dê duplo clique ou edite o nome e saia do campo para salvar. Use as setas para ordenar.</div>
    </div>
    <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
    </div>
  </div>
  </div>
</div>

<style>
  /* Kanban scroll wrapper ensures horizontal scroll is always visible and never overflows the page */
  #kanban-scroll-wrapper {
    overflow-x: auto;
    max-width: 100vw;
    padding-bottom: 8px;
  }
  #kanban-container {
    flex-wrap: nowrap;
    min-width: 100%;
    width: max-content;
    overflow-y: hidden;
  }
  #kanban-container .card {
    min-width: 18rem;
    max-width: 18rem;
    min-height: calc(100vh - 140px);
    display: flex;
    flex-direction: column;
  }
  #kanban-container .card .card-body { display:flex; flex-direction:column; flex:1; }
  #kanban-container .column-content { flex:1; overflow:auto; padding:8px; }
  .lead-card { border-radius:0.6rem; box-shadow: 0 6px 18px rgba(15,23,42,0.06); transition: transform .12s ease, box-shadow .12s ease; }
  .lead-card:hover { transform: translateY(-4px); box-shadow: 0 12px 30px rgba(15,23,42,0.12); }
  .stage-title { font-size: 0.95rem; }
  .stage-count { font-weight:600; padding:0.35rem 0.6rem; border-radius:0.5rem; }
  @media (max-width: 991px) {
    #kanban-scroll-wrapper { padding-bottom: 32px; }
    #kanban-container .card { min-width: 14rem; max-width: 14rem; }
  }
  /* Actions are now inline in the header (not fixed) */
  @media (max-width: 991px) {
    /* ensure header buttons wrap nicely on small screens */
    .container-fluid > .d-flex > div { display: block; }
  }
</style>
  </main>
</div>

<?php include 'includes/footer.php'; ?>
