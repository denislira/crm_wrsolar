(function(){
  const api = 'includes/projeto_stages_api.php';
  let stages = [];

  function $(s){return document.querySelector(s)}
  function valOr(selector, fallback){
    const el = $(selector);
    return el ? el.value : fallback;
  }

  function showStatus(message, kind){
    const host = $('#stageForm') || $('#stageEditor') || document.body;
    let box = $('#stageStatusMessage');
    if (!box) {
      box = document.createElement('div');
      box.id = 'stageStatusMessage';
      box.style.marginTop = '10px';
      box.style.padding = '10px 12px';
      box.style.borderRadius = '10px';
      box.style.fontSize = '.9rem';
      box.style.fontWeight = '600';
      host.prepend(box);
    }

    if (kind === 'error') {
      box.style.background = 'rgba(220,53,69,.12)';
      box.style.color = '#b02a37';
      box.style.border = '1px solid rgba(220,53,69,.35)';
    } else {
      box.style.background = 'rgba(25,135,84,.12)';
      box.style.color = '#0f5132';
      box.style.border = '1px solid rgba(25,135,84,.35)';
    }

    box.textContent = message;
    window.clearTimeout(showStatus._timer);
    showStatus._timer = window.setTimeout(() => {
      if (box) box.remove();
    }, 3000);
  }

  async function load(){
    const res = await fetch(api + '?action=list'); if(!res.ok) return;
    stages = await res.json(); renderList();
  }

  function renderList(){
    const list = $('#stagesList'); list.innerHTML = '';
    if (!stages.length) {
      list.innerHTML = '<div style="text-align:center;padding:2rem;color:#94a3b8;font-size:.85rem;"><i class="fa fa-layer-group" style="font-size:2rem;display:block;margin-bottom:.75rem;color:#cbd5e1;"></i>Nenhuma etapa criada ainda.</div>';
      return;
    }
    stages.forEach(s=>{
      const row = document.createElement('div'); row.className='stages-row'; row.dataset.id = s.id;
      row.innerHTML = `
        <div class="d-flex align-items-center gap-2" style="min-width:0;">
          <span class="drag-handle" title="Arrastar para reordenar"><i class="fa fa-grip-lines"></i></span>
          <span class="stage-dot" style="background:${s.color||'#6c757d'};box-shadow:0 0 0 2px ${s.color||'#6c757d'}33;"></span>
          <div style="min-width:0;">
            <div class="stage-name">${s.name}</div>
            <div class="stage-pos d-flex gap-1 flex-wrap mt-1">
              <span class="badge bg-secondary" style="font-size:.62rem;">#${s.position || '-'}</span>
              ${Number(s.is_initial) === 1 ? '<span class="badge bg-primary" style="font-size:.62rem;">Inicio</span>' : ''}
            </div>
          </div>
        </div>
        <div class="d-flex gap-2 align-items-center flex-shrink-0">
          <button class="btn-edit-stage edit-stage">Editar</button>
        </div>
      `;
      list.appendChild(row);
      row.querySelector('.edit-stage').addEventListener('click', ()=> selectStage(s.id));
      row.draggable = true;
      row.addEventListener('dragstart', e => { e.dataTransfer.setData('text/plain', s.id); row.classList.add('dragging'); });
      row.addEventListener('dragend', () => row.classList.remove('dragging'));
    });

    list.addEventListener('dragover', e=> e.preventDefault());
    list.addEventListener('drop', async e=>{
      e.preventDefault(); const id = e.dataTransfer.getData('text/plain'); const target = e.target.closest('.stages-row');
      if(!target) return; const id2 = target.dataset.id; reorder(id, id2);
    });
  }

  async function reorder(dragId, dropId){
    const list = $('#stagesList');
    const draggedEl = Array.from(list.children).find(n => n.dataset.id === String(dragId));
    const dropEl = Array.from(list.children).find(n => n.dataset.id === String(dropId));
    if (!draggedEl || !dropEl || draggedEl === dropEl) return;

    const allChildren = Array.from(list.children);
    const dragIndex = allChildren.indexOf(draggedEl);
    const dropIndex = allChildren.indexOf(dropEl);

    if (dragIndex < dropIndex) {
      dropEl.parentNode.insertBefore(draggedEl, dropEl.nextSibling);
    } else {
      dropEl.parentNode.insertBefore(draggedEl, dropEl);
    }

    const ids = Array.from(list.children).map((n,i)=>({ id: parseInt(n.dataset.id,10), position: i+1 }));
    const payload = { positions: ids };
    const res = await fetch(api + '?action=reorder', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
    const json = await res.json();
    if (!res.ok || json.error) {
      showStatus('Falha ao reordenar: ' + (json.error || ''), 'error');
    }
    await load();
  }

  function selectStage(id){
    const s = stages.find(x=>String(x.id)===String(id)); if(!s) return;
    $('#noEditor').classList.add('d-none'); $('#editorContent').classList.remove('d-none');
    $('#stageId').value = s.id;
    $('#stageName').value = s.name;
    $('#stageColor').value = s.color || '#6c757d';
    const hex = $('#stageColorHex');
    if (hex) hex.textContent = $('#stageColor').value;

    const cardColorInput = $('#stageCardColor');
    if (cardColorInput) cardColorInput.value = s.card_color || '#ffffff';
    const stageInitialInput = $('#stageIsInitial');
    if (stageInitialInput) stageInitialInput.checked = Number(s.is_initial) === 1;

    renderPreview(s);
  }

  function renderPreview(s){
    const p = $('#stagePreview'); p.innerHTML = '';
    const col = document.createElement('div'); col.style.padding='8px'; col.style.borderRadius='12px';
    const isDark = document.body.classList.contains('theme-dark') || document.documentElement.getAttribute('data-theme') === 'dark';
    col.style.background = isDark ? 'rgba(230,238,248,0.02)' : '#fff';
    const borderColor = s.color || '#6c757d';
    col.style.border = isDark ? '1px solid rgba(230,238,248,0.04)' : '1px solid rgba(11,26,49,0.06)';
    col.style.borderTop = '6px solid ' + borderColor;
    col.style.boxShadow = `0 0 0 1px ${borderColor}33`;
    col.innerHTML = `<div style="font-weight:700;padding:8px 6px">${s.name} <span class="small text-muted" style="float:right">0</span></div>`;
    const card = document.createElement('div');
    card.style.marginTop = '8px'; card.style.padding = '10px'; card.style.borderRadius = '10px';
    card.style.borderLeft = '4px solid ' + (s.color || '#6c757d');
    card.style.background = s.card_color || (isDark ? '#071427' : '#fff');
    card.style.color = isDark ? '#e6eef8' : '#111';
    card.innerHTML = 'Projeto exemplo — <small class="text-muted">R$ 10.000</small>';
    col.appendChild(card);
    p.appendChild(col);
  }

  async function saveStage(){
    const id = $('#stageId').value;
    if (!id) { showStatus('Selecione uma etapa', 'error'); return; }
    const payload = {
      id,
      name: valOr('#stageName', '').trim(),
      is_initial: $('#stageIsInitial') && $('#stageIsInitial').checked ? 1 : 0,
      color: valOr('#stageColor', '#6c757d').trim(),
      card_color: valOr('#stageCardColor', '#ffffff').trim()
    };
    if (!payload.name) { showStatus('Informe o nome da etapa', 'error'); return; }

    const res = await fetch(api + '?action=update', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
    const json = await res.json();
    if (!res.ok || json.error) { showStatus('Erro ao salvar: ' + (json.error || ''), 'error'); return; }
    await load();
    showStatus('Salvo com sucesso', 'success');
  }

  async function deleteStage(){
    const id = $('#stageId').value;
    if (!id || !confirm('Excluir etapa?')) return;
    const res = await fetch(api + '?action=delete', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id })});
    const json = await res.json();
    if (!res.ok || json.error) { showStatus('Erro ao excluir: ' + (json.error || ''), 'error'); return; }
    await load();
    $('#noEditor').classList.remove('d-none'); $('#editorContent').classList.add('d-none');
    showStatus('Etapa excluida com sucesso', 'success');
  }

  document.addEventListener('DOMContentLoaded', ()=>{
    load();
    $('#addStageBtn').addEventListener('click', async ()=>{
      const name = prompt('Nome da nova etapa');
      if (!name) return;
      const res = await fetch(api + '?action=add', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ name, color:'#6c757d', card_color:'#ffffff' })});
      const json = await res.json();
      if (!res.ok || json.error) { showStatus('Falha ao criar: ' + (json.error || ''), 'error'); return; }
      await load();
      selectStage(json.id);
      showStatus('Etapa criada com sucesso', 'success');
    });

    $('#saveStage').addEventListener('click', e=>{ e.preventDefault(); saveStage(); });
    $('#deleteStage').addEventListener('click', e=>{ e.preventDefault(); deleteStage(); });

    document.addEventListener('input', function(e){
      if (e.target && e.target.id === 'stageColor') {
        const hex = document.getElementById('stageColorHex'); if (hex) hex.textContent = e.target.value;
      }
    });
  });
})();