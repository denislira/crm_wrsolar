(function(){
  const api = 'includes/funil_stages_api.php';
  let stages = [];

  function $(s){return document.querySelector(s)}
  function $all(s){return Array.from(document.querySelectorAll(s))}

  async function load(){
    const res = await fetch(api + '?action=list'); if(!res.ok) return;
    stages = await res.json(); renderList();
  }

  function renderList(){
    const list = $('#stagesList'); list.innerHTML = '';
    stages.forEach(s=>{
      const row = document.createElement('div'); row.className='d-flex align-items-center justify-content-between p-2 mb-2 border rounded stages-row';
      row.dataset.id = s.id;
      row.innerHTML = `<div class="d-flex align-items-center gap-2"><i class="fa ${s.icon||'fa-circle'}" style="color:${s.color||'#6c757d'}"></i><div><div style="font-weight:600">${s.name}</div><div class="small text-muted">${s.position}</div></div></div><div class="d-flex gap-2"><button class="btn btn-sm btn-outline-secondary edit-stage">Editar</button></div>`;
      list.appendChild(row);
      row.querySelector('.edit-stage').addEventListener('click', ()=> selectStage(s.id));
      // drag handling
      row.draggable = true; row.addEventListener('dragstart', e => { e.dataTransfer.setData('text/plain', s.id); row.classList.add('dragging'); });
      row.addEventListener('dragend', e => row.classList.remove('dragging'));
    });

    // enable drop reorder
    list.addEventListener('dragover', e=> e.preventDefault());
    list.addEventListener('drop', async e=>{
      e.preventDefault(); const id = e.dataTransfer.getData('text/plain'); const target = e.target.closest('.stages-row');
      if(!target) return; const id2 = target.dataset.id; reorder(id, id2);
    });
  }

  async function reorder(dragId, dropId){
    // Build new order based on current list order by DOM
    const ids = Array.from($('#stagesList').children).map((n,i)=>({ id: n.dataset.id, position: i+1 }));
    await fetch(api + '?action=reorder', { method: 'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ positions: ids }) });
    // quick refresh
    await load();
  }

  function selectStage(id){
    const s = stages.find(x=>String(x.id)===String(id)); if(!s) return; $('#noEditor').classList.add('d-none'); $('#editorContent').classList.remove('d-none');
    $('#stageId').value = s.id; $('#stageName').value = s.name; $('#stageColor').value = s.color || '#6c757d'; $('#stageCardColor').value = s.card_color || '#ffffff'; $('#stageIcon').value = s.icon || '';
    $('#stageSla').value = s.sla_days || '';
    $('#stageFinalType').value = s.final_type || 'none'; $('#stageForecast').value = (typeof s.include_in_forecast !== 'undefined') ? s.include_in_forecast : 1;
    $('#generateTask').checked = !!s.generate_task_on_enter; $('#alertInactivity').checked = !!s.alert_on_inactivity; $('#blockAdvance').checked = !!s.block_advance;
    $('#requiredFields').value = s.required_fields || '';
    renderPreview(s);
  }

  function renderPreview(s){
    const p = $('#stagePreview'); p.innerHTML = '';
    const col = document.createElement('div');
    col.style.padding = '8px';
    col.style.borderRadius = '12px';
    col.style.background = '#fff';
    col.style.border = '1px solid rgba(11,26,49,0.06)';
    // top line representing stage color
    col.style.borderTop = '6px solid ' + (s.color || '#6c757d');
    col.style.overflow = 'hidden';
    col.innerHTML = `<div style="font-weight:700;padding:8px 6px">${s.name} <span class="small text-muted" style="float:right">0</span></div>`;
    const card = document.createElement('div');
    card.style.marginTop = '8px'; card.style.padding = '10px'; card.style.borderRadius = '10px';
    card.style.borderLeft = '4px solid ' + (s.color || '#6c757d');
    card.style.background = s.card_color || '#fff'; card.style.color = '#111';
    card.innerHTML = 'Ex: Nome do lead — <small class="text-muted">R$ 10.000</small>';
    col.appendChild(card);
    p.appendChild(col);
  }

  async function saveStage(){
    const id = $('#stageId').value; if(!id) return alert('Selecione uma etapa');
    const payload = { action:'update', id, name: $('#stageName').value, color: $('#stageColor').value, card_color: $('#stageCardColor').value, icon: $('#stageIcon').value, sla_days: $('#stageSla').value, final_type: $('#stageFinalType').value, include_in_forecast: $('#stageForecast').value, generate_task_on_enter: $('#generateTask').checked?1:0, alert_on_inactivity: $('#alertInactivity').checked?1:0, block_advance: $('#blockAdvance').checked?1:0, required_fields: parseRequiredFields() };
    try{
      const res = await fetch(api + '?action=update', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
      const json = await res.json(); if (json.error) throw new Error(json.error || 'Erro');
      $('#saveMsg').style.display = 'inline'; setTimeout(()=> $('#saveMsg').style.display='none', 2000);
      await load(); selectStage(id);
    }catch(e){ alert('Falha ao salvar: ' + e.message); }
  }

  function parseRequiredFields(){
    const txt = $('#requiredFields').value.trim(); if(!txt) return null; try{ const parsed = JSON.parse(txt); return parsed; }catch(e){ alert('JSON inválido em Campos obrigatórios'); throw e; }
  }

  async function deleteStage(){
    if (!confirm('Tem certeza que deseja excluir esta etapa? Caso existam leads associados, será necessário confirmar.')) return;
    const id = $('#stageId').value; try{ const res = await fetch(api + '?action=delete', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id }) });
      if (res.status === 409) { const body = await res.json(); if (confirm('Existem ' + body.leads + ' leads associados. Deseja forçar a exclusão?')) { await fetch(api + '?action=delete', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id, force:1 }) }); await load(); $('#noEditor').classList.remove('d-none'); $('#editorContent').classList.add('d-none'); } return; }
      const json = await res.json(); if (json.error) throw new Error(json.error||'Erro'); await load(); $('#noEditor').classList.remove('d-none'); $('#editorContent').classList.add('d-none');
    }catch(e){ alert('Falha ao excluir: ' + e.message); }
  }

  document.addEventListener('DOMContentLoaded', ()=>{
    load();
    if (typeof IS_ADMIN !== 'undefined' && !IS_ADMIN) {
      // disable editing controls
      $('#addStageBtn').disabled = true; $('#saveStage').disabled = true; $('#deleteStage').disabled = true;
      $('#stagesList').classList.add('text-muted');
    } else {
      $('#addStageBtn').addEventListener('click', async ()=>{
        const name = prompt('Nome da nova etapa'); if (!name) return; const res = await fetch(api + '?action=add', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ name }) }); const j = await res.json(); if (res.ok) { await load(); selectStage(j.id); } else { alert('Erro ao criar: ' + (j.error||JSON.stringify(j))); }
      });
      $('#saveStage').addEventListener('click', e=>{ e.preventDefault(); saveStage(); });
      $('#deleteStage').addEventListener('click', e=>{ e.preventDefault(); deleteStage(); });
    }

    // autorun preview updates
    ['#stageName','#stageColor','#stageCardColor','#stageIcon'].forEach(sel=>{ document.addEventListener('input', (ev)=>{ if (ev.target && ev.target.matches(sel)) { const id = $('#stageId').value; const s = stages.find(x=>String(x.id)===String(id)); if (s) { s[ev.target.id.replace('stage','').toLowerCase()] = ev.target.value; renderPreview(s); } } }); });
  });

})();