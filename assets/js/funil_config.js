(function(){
  const api = 'includes/funil_stages_api.php';
  let stages = [];

  function $(s){return document.querySelector(s)}
  function $all(s){return Array.from(document.querySelectorAll(s))}
  function asBool(v){
    return v === 1 || v === '1' || v === true || v === 'true';
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
      const row = document.createElement('div'); row.className='stages-row';
      row.dataset.id = s.id;
      const badges = [];
      if (asBool(s.is_conversion) || (s.final_type && String(s.final_type).toLowerCase() === 'won')) {
        badges.push('<span style="background:#d1fae5;color:#065f46;font-size:.67rem;font-weight:700;padding:1px 6px;border-radius:20px;"><i class="fa fa-trophy" style="font-size:.6rem;"></i> Venda Concluída</span>');
      }
      if (s.final_type && String(s.final_type).toLowerCase() === 'lost') {
        badges.push('<span style="background:#fee2e2;color:#b91c1c;font-size:.67rem;font-weight:700;padding:1px 6px;border-radius:20px;"><i class="fa fa-times-circle" style="font-size:.6rem;"></i> Venda Perdida</span>');
      }
      if (asBool(s.is_qualification)) {
        badges.push('<span style="background:#dbeafe;color:#1e40af;font-size:.67rem;font-weight:700;padding:1px 6px;border-radius:20px;"><i class="fa fa-filter" style="font-size:.6rem;"></i> SQL</span>');
      }
      row.innerHTML = `
        <div class="d-flex align-items-center gap-2" style="min-width:0;">
          <span class="drag-handle" title="Arrastar para reordenar"><i class="fa fa-grip-lines"></i></span>
          <span class="stage-dot" style="background:${s.color||'#6c757d'};box-shadow:0 0 0 2px ${s.color||'#6c757d'}33;"></span>
          <div style="min-width:0;">
            <div class="stage-name">${s.name}</div>
            <div class="stage-pos d-flex gap-1 flex-wrap mt-1">${badges.join('')}</div>
          </div>
        </div>
        <div class="d-flex gap-2 align-items-center flex-shrink-0">
          <span class="stage-pos me-1">#${s.position}</span>
          <button class="btn-edit-stage edit-stage">Editar</button>
        </div>
      `;
      list.appendChild(row);
      row.querySelector('.edit-stage').addEventListener('click', ()=> selectStage(s.id));
      // drag handling
      row.draggable = true;
      row.addEventListener('dragstart', e => { e.dataTransfer.setData('text/plain', s.id); row.classList.add('dragging'); });
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
    // Find the dragged element and drop target in the DOM
    const list = $('#stagesList');
    const draggedEl = Array.from(list.children).find(n => n.dataset.id === String(dragId));
    const dropEl = Array.from(list.children).find(n => n.dataset.id === String(dropId));
    
    if (!draggedEl || !dropEl || draggedEl === dropEl) return;
    
    // Physically move the dragged element in the DOM to the drop position
    const allChildren = Array.from(list.children);
    const dragIndex = allChildren.indexOf(draggedEl);
    const dropIndex = allChildren.indexOf(dropEl);
    
    if (dragIndex < dropIndex) {
      // Moving down: insert after drop target
      dropEl.parentNode.insertBefore(draggedEl, dropEl.nextSibling);
    } else {
      // Moving up: insert before drop target
      dropEl.parentNode.insertBefore(draggedEl, dropEl);
    }
    
    // Now build new order based on updated DOM
    const ids = Array.from(list.children).map((n,i)=>({ id: parseInt(n.dataset.id,10), position: i+1 }));
    const payload = { positions: ids };
    try {
      console.debug('reorder payload', payload);
      const res = await fetch(api + '?action=reorder', { method: 'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
      let json;
      try { json = await res.json(); } catch(e){ json = { raw: 'non-json response' }; }
      if (!res.ok) {
        console.error('Reorder failed', res.status, json);
        alert('Falha ao reordenar: ' + (json.error || JSON.stringify(json)));
      } else {
        console.debug('Reorder ok', json);
      }
    } catch (err) {
      console.error('Network error during reorder', err);
      alert('Erro de rede ao reordenar. Veja console.');
    }
    // quick refresh
    await load();
  }

  function selectStage(id){
    const s = stages.find(x=>String(x.id)===String(id)); if(!s) return; $('#noEditor').classList.add('d-none'); $('#editorContent').classList.remove('d-none');
      $('#stageId').value = s.id; $('#stageName').value = s.name; $('#stageColor').value = s.color || '#6c757d'; $('#stageCardColor').value = s.card_color || '#ffffff';
    $('#stageSla').value = s.sla_days || '';
    $('#stageFinalType').value = s.final_type || 'none'; $('#stageForecast').value = (typeof s.include_in_forecast !== 'undefined') ? s.include_in_forecast : 1;
    $('#generateTask').checked = asBool(s.generate_task_on_enter); $('#alertInactivity').checked = asBool(s.alert_on_inactivity); $('#blockAdvance').checked = asBool(s.block_advance); $('#allowProjectCreation').checked = asBool(s.allow_project_creation);
    $('#isConversion').checked = asBool(s.is_conversion); $('#isQualification').checked = asBool(s.is_qualification);
    $('#requiredFields').value = s.required_fields || '';

    // Apply stage color to edit panel border
    const editorPanel = document.getElementById('stageEditor');
    const stageColor = s.color || '#6c757d';
    if (editorPanel) {
      editorPanel.style.border = `2px solid ${stageColor}`;
      editorPanel.style.boxShadow = `0 0 12px ${stageColor}33`;
    }

    renderPreview(s);
  }

  function renderPreview(s){
    const p = $('#stagePreview'); p.innerHTML = '';
    const col = document.createElement('div');
    col.style.padding = '8px';
    col.style.borderRadius = '12px';
    const isDark = document.body.classList.contains('theme-dark') || document.documentElement.getAttribute('data-theme') === 'dark';
    col.style.background = isDark ? 'rgba(230,238,248,0.02)' : '#fff';
    const borderColor = s.color || '#6c757d';
    col.style.border = isDark ? '1px solid rgba(230,238,248,0.04)' : '1px solid rgba(11,26,49,0.06)';
    // top line representing stage color
    col.style.borderTop = '6px solid ' + borderColor;
    col.style.boxShadow = `0 0 0 1px ${borderColor}33`;
    col.style.overflow = 'hidden';
    col.innerHTML = `<div style="font-weight:700;padding:8px 6px">${s.name} <span class="small text-muted" style="float:right">0</span></div>`;
    const card = document.createElement('div');
    card.style.marginTop = '8px'; card.style.padding = '10px'; card.style.borderRadius = '10px';
    card.style.borderLeft = '4px solid ' + (s.color || '#6c757d');
    card.style.background = s.card_color || (isDark ? '#071427' : '#fff');
    card.style.color = isDark ? '#e6eef8' : '#111';
    card.innerHTML = 'Ex: Nome do lead — <small class="text-muted">R$ 10.000</small>';
    col.appendChild(card);
    p.appendChild(col);
  }

  async function saveStage(){
    const id = $('#stageId').value; if(!id) return alert('Selecione uma etapa');
    const payload = { action:'update', id, name: $('#stageName').value, color: $('#stageColor').value, card_color: $('#stageCardColor').value, sla_days: $('#stageSla').value, final_type: $('#stageFinalType').value, include_in_forecast: $('#stageForecast').value, generate_task_on_enter: $('#generateTask').checked?1:0, alert_on_inactivity: $('#alertInactivity').checked?1:0, block_advance: $('#blockAdvance').checked?1:0, allow_project_creation: $('#allowProjectCreation').checked?1:0, is_conversion: $('#isConversion').checked?1:0, is_qualification: $('#isQualification').checked?1:0, required_fields: parseRequiredFields() };
    try{
      const res = await fetch(api + '?action=update', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
      const json = await res.json(); if (json.error) throw new Error(json.error || 'Erro');
      const msg = $('#saveMsg'); msg.style.display = 'flex'; setTimeout(()=> msg.style.display='none', 2500);
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
      // autorun preview updates
      ['#stageName','#stageColor','#stageCardColor'].forEach(sel=>{ document.addEventListener('input', (ev)=>{ if (ev.target && ev.target.matches(sel)) { const id = $('#stageId').value; const s = stages.find(x=>String(x.id)===String(id)); if (s) { s[ev.target.id.replace('stage','').toLowerCase()] = ev.target.value; renderPreview(s); } } }); });

    // Initialize Bootstrap tooltips if available
    try{
      const triggers = Array.from(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
      if (triggers.length && window.bootstrap && typeof window.bootstrap.Tooltip === 'function') {
        triggers.forEach(el=> new bootstrap.Tooltip(el));
      }
    }catch(e){ console.warn('Tooltip init failed', e); }
  });

})();