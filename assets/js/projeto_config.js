(function(){
  const api = 'includes/projeto_stages_api.php';
  const checklistApi = 'includes/project_checklists_api.php';
  const paymentApi = 'includes/payment_methods_api.php';
  const paymentCode = 2;
  let stages = [];
  let checklistItems = { technical: [], document: [] };
  let paymentMethods = [];

  function $(selector) {
    return document.querySelector(selector);
  }

  function showStatus(message, kind) {
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

  async function loadStages() {
    try {
      const res = await fetch(`${api}?action=list`);
      if (!res.ok) throw new Error('Falha ao carregar etapas');
      stages = await res.json();
      renderStages();
    } catch (err) {
      console.error(err);
      showStatus('Erro ao carregar etapas', 'error');
    }
  }

  async function loadChecklistItems() {
    try {
      const [techRes, docRes] = await Promise.all([
        fetch(`${checklistApi}?action=list&type=technical`),
        fetch(`${checklistApi}?action=list&type=document`)
      ]);
      if (techRes.ok) checklistItems.technical = await techRes.json();
      if (docRes.ok) checklistItems.document = await docRes.json();
      renderChecklistList('technical');
      renderChecklistList('document');
    } catch (err) {
      console.error(err);
      showStatus('Erro ao carregar checklists', 'error');
    }
  }

  async function loadPaymentMethods() {
    try {
      const res = await fetch(`${paymentApi}?action=list&code=${encodeURIComponent(String(paymentCode))}`);
      if (!res.ok) throw new Error('Falha ao carregar formas de pagamento');
      paymentMethods = await res.json();
      renderPaymentMethodsList();
    } catch (err) {
      console.error(err);
      showStatus('Erro ao carregar formas de pagamento', 'error');
    }
  }

  function renderStages() {
    const list = $('#stagesList');
    if (!list) return;
    list.innerHTML = '';
    if (!stages.length) {
      list.innerHTML = '<div style="text-align:center;padding:2rem;color:#94a3b8;font-size:.85rem;"><i class="fa fa-layer-group" style="font-size:2rem;display:block;margin-bottom:.75rem;color:#cbd5e1;"></i>Nenhuma etapa criada ainda.</div>';
      return;
    }

    stages.forEach(stage => {
      const row = document.createElement('div');
      row.className = 'stages-row';
      row.dataset.id = stage.id;
      row.innerHTML = `
        <div class="d-flex align-items-center gap-2" style="min-width:0;">
          <span class="drag-handle" title="Arrastar para reordenar"><i class="fa fa-grip-lines"></i></span>
          <span class="stage-dot" style="background:${stage.color || '#6c757d'};box-shadow:0 0 0 2px ${stage.color || '#6c757d'}33;"></span>
          <div style="min-width:0;">
            <div class="stage-name">${stage.name}</div>
            <div class="stage-pos d-flex gap-1 flex-wrap mt-1">
              <span class="badge bg-secondary" style="font-size:.62rem;">#${stage.position || '-'}</span>
              ${Number(stage.is_initial) === 1 ? '<span class="badge bg-primary" style="font-size:.62rem;">Inicio</span>' : ''}
            </div>
          </div>
        </div>
        <div class="d-flex gap-2 align-items-center flex-shrink-0">
          <button class="btn-edit-stage edit-stage">Editar</button>
        </div>
      `;
      row.querySelector('.edit-stage').addEventListener('click', () => selectStage(stage.id));
      row.draggable = true;
      row.addEventListener('dragstart', e => { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', stage.id); row.classList.add('dragging'); });
      row.addEventListener('dragend', () => row.classList.remove('dragging'));
      list.appendChild(row);
    });

    list.addEventListener('dragover', e => e.preventDefault());
    list.addEventListener('drop', async e => {
      e.preventDefault();
      const dragId = e.dataTransfer.getData('text/plain');
      const target = e.target.closest('.stages-row');
      if (!target) return;
      await reorderStages(dragId, target.dataset.id);
    });
  }

  async function renderChecklistList(type) {
    const container = $(type === 'technical' ? '#technicalList' : '#docList');
    if (!container) return;
    const items = checklistItems[type] || [];
    container.innerHTML = '';

    if (!items.length) {
      container.innerHTML = `<div style="text-align:center;padding:1.5rem;color:#94a3b8;font-size:.85rem;">Nenhum item cadastrado para ${type === 'technical' ? 'checklist técnico' : 'gestão documental'}.</div>`;
      return;
    }

    items.forEach(item => {
      const row = document.createElement('div');
      row.className = 'stages-row';
      row.dataset.id = item.id;
      row.innerHTML = `
        <div class="d-flex align-items-center gap-2" style="min-width:0;">
          <span class="drag-handle" title="Arrastar para reordenar"><i class="fa fa-grip-lines"></i></span>
          <div style="min-width:0;">
            <div class="stage-name">${item.name}</div>
            <div class="stage-pos d-flex gap-1 flex-wrap mt-1">
              <span class="badge bg-secondary" style="font-size:.62rem;">#${item.position || '-'}</span>
            </div>
          </div>
        </div>
        <div class="d-flex gap-2 align-items-center flex-shrink-0">
          <button class="btn-edit-stage edit-item" data-type="${type}" data-id="${item.id}">Editar</button>
          <button class="btn-edit-stage delete-item" data-type="${type}" data-id="${item.id}">Excluir</button>
        </div>
      `;
      row.querySelector('.edit-item').addEventListener('click', () => editChecklistItem(type, item.id));
      row.querySelector('.delete-item').addEventListener('click', () => deleteChecklistItem(type, item.id));
      row.draggable = true;
      row.addEventListener('dragstart', e => { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', item.id); row.classList.add('dragging'); });
      row.addEventListener('dragend', () => row.classList.remove('dragging'));
      container.appendChild(row);
    });

    container.addEventListener('dragover', e => e.preventDefault());
    container.addEventListener('drop', async e => {
      e.preventDefault();
      const dragId = e.dataTransfer.getData('text/plain');
      const target = e.target.closest('.stages-row');
      if (!target) return;
      await reorderChecklistItems(type, dragId, target.dataset.id);
    });
  }

  function renderPaymentMethodsList() {
    const container = $('#paymentMethodsList');
    if (!container) return;

    const items = Array.isArray(paymentMethods) ? paymentMethods : [];
    container.innerHTML = '';

    if (!items.length) {
      container.innerHTML = '<div style="text-align:center;padding:1.5rem;color:#94a3b8;font-size:.85rem;">Nenhuma forma de pagamento cadastrada.</div>';
      return;
    }

    items.forEach(item => {
      const row = document.createElement('div');
      row.className = 'stages-row';
      row.dataset.id = item.id;
      row.innerHTML = `
        <div class="d-flex align-items-center gap-2" style="min-width:0;">
          <span class="drag-handle" title="Item de pagamento"><i class="fa fa-circle"></i></span>
          <div style="min-width:0;">
            <div class="stage-name">${item.name || ''}</div>
          </div>
        </div>
        <div class="d-flex gap-2 align-items-center flex-shrink-0">
          <button class="btn-edit-stage edit-payment" data-id="${item.id}">Editar</button>
          <button class="btn-edit-stage delete-payment" data-id="${item.id}">Excluir</button>
        </div>
      `;

      row.querySelector('.edit-payment').addEventListener('click', () => editPaymentMethod(item.id));
      row.querySelector('.delete-payment').addEventListener('click', () => deletePaymentMethod(item.id));
      container.appendChild(row);
    });
  }

  async function addStage() {
    const name = prompt('Nome da nova etapa');
    if (!name) return;
    try {
      const res = await fetch(`${api}?action=add`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ name: name.trim(), color: '#6c757d', card_color: '#ffffff' })
      });
      const json = await res.json();
      if (!res.ok || json.error) throw new Error(json.error || 'Falha ao criar etapa');
      await loadStages();
      showStatus('Etapa criada com sucesso', 'success');
    } catch (err) {
      showStatus(err.message, 'error');
    }
  }

  async function saveStage() {
    const id = $('#stageId').value;
    if (!id) return showStatus('Selecione uma etapa para salvar', 'error');
    const name = $('#stageName').value.trim();
    if (!name) return showStatus('Nome da etapa é obrigatório', 'error');
    try {
      const res = await fetch(`${api}?action=update`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id, name, is_initial: $('#stageIsInitial').checked ? 1 : 0, color: $('#stageColor').value, card_color: $('#stageCardColor') ? $('#stageCardColor').value : '#ffffff' })
      });
      const json = await res.json();
      if (!res.ok || json.error) throw new Error(json.error || 'Falha ao salvar etapa');
      await loadStages();
      showStatus('Etapa salva com sucesso', 'success');
    } catch (err) {
      showStatus(err.message, 'error');
    }
  }

  async function deleteStage() {
    const id = $('#stageId').value;
    if (!id) return;
    if (!confirm('Deseja excluir esta etapa?')) return;
    try {
      const res = await fetch(`${api}?action=delete`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id })
      });
      const json = await res.json();
      if (!res.ok || json.error) throw new Error(json.error || 'Falha ao excluir etapa');
      await loadStages();
      $('#noEditor').classList.remove('d-none');
      $('#editorContent').classList.add('d-none');
      showStatus('Etapa excluída com sucesso', 'success');
    } catch (err) {
      showStatus(err.message, 'error');
    }
  }

  function selectStage(id) {
    const stage = stages.find(item => String(item.id) === String(id));
    if (!stage) return;
    $('#noEditor').classList.add('d-none');
    $('#editorContent').classList.remove('d-none');
    $('#stageId').value = stage.id;
    $('#stageName').value = stage.name;
    $('#stageColor').value = stage.color || '#6c757d';
    const hexLabel = $('#stageColorHex');
    if (hexLabel) hexLabel.textContent = $('#stageColor').value;
    $('#stageIsInitial').checked = Number(stage.is_initial) === 1;
    renderPreview(stage);
  }

  async function reorderStages(dragId, dropId) {
    if (!dragId || !dropId || dragId === dropId) return;
    const items = Array.from($('#stagesList').children);
    const dragEl = items.find(node => node.dataset.id === String(dragId));
    const dropEl = items.find(node => node.dataset.id === String(dropId));
    if (!dragEl || !dropEl) return;
    const order = items.map((node, index) => ({ id: parseInt(node.dataset.id, 10), position: index + 1 }));
    try {
      const res = await fetch(`${api}?action=reorder`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ positions: order })
      });
      const json = await res.json();
      if (!res.ok || json.error) throw new Error(json.error || 'Falha ao reordenar');
      await loadStages();
    } catch (err) {
      showStatus(err.message, 'error');
    }
  }

  async function addChecklistItem(type) {
    const name = prompt(`Nome do novo item de ${type === 'technical' ? 'checklist técnico' : 'gestão documental'}`);
    if (!name) return;
    try {
      const res = await fetch(`${checklistApi}?action=add&type=${encodeURIComponent(type)}`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ type, name: name.trim() })
      });
      const json = await res.json();
      if (!res.ok || json.error) throw new Error(json.error || 'Falha ao cadastrar item');
      await loadChecklistItems();
      showStatus('Item cadastrado com sucesso', 'success');
    } catch (err) {
      showStatus(err.message, 'error');
    }
  }

  async function editChecklistItem(type, id) {
    const item = (checklistItems[type] || []).find(x => String(x.id) === String(id));
    if (!item) return;
    const name = prompt('Editar nome do item', item.name);
    if (!name) return;
    try {
      const res = await fetch(`${checklistApi}?action=update`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id, name: name.trim() })
      });
      const json = await res.json();
      if (!res.ok || json.error) throw new Error(json.error || 'Falha ao atualizar item');
      await loadChecklistItems();
      showStatus('Item atualizado com sucesso', 'success');
    } catch (err) {
      showStatus(err.message, 'error');
    }
  }

  async function deleteChecklistItem(type, id) {
    if (!confirm('Excluir item?')) return;
    try {
      const res = await fetch(`${checklistApi}?action=delete`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id })
      });
      const json = await res.json();
      if (!res.ok || json.error) throw new Error(json.error || 'Falha ao excluir item');
      await loadChecklistItems();
      showStatus('Item excluído com sucesso', 'success');
    } catch (err) {
      showStatus(err.message, 'error');
    }
  }

  async function reorderChecklistItems(type, dragId, dropId) {
    if (!dragId || !dropId || dragId === dropId) return;
    const items = checklistItems[type] || [];
    const draggedIndex = items.findIndex(x => String(x.id) === String(dragId));
    const dropIndex = items.findIndex(x => String(x.id) === String(dropId));
    if (draggedIndex === -1 || dropIndex === -1 || draggedIndex === dropIndex) return;
    const reordered = items.slice();
    const [moved] = reordered.splice(draggedIndex, 1);
    reordered.splice(dropIndex, 0, moved);
    const positions = reordered.map((item, index) => ({ id: item.id, position: index + 1 }));
    try {
      const res = await fetch(`${checklistApi}?action=reorder`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ positions })
      });
      const json = await res.json();
      if (!res.ok || json.error) throw new Error(json.error || 'Falha ao ordenar items');
      await loadChecklistItems();
    } catch (err) {
      showStatus(err.message, 'error');
    }
  }

  async function addPaymentMethod() {
    const name = prompt('Nome da nova forma de pagamento');
    if (!name) return;
    try {
      const body = new URLSearchParams({ name: name.trim() });
      const res = await fetch(`${paymentApi}?action=add&code=${encodeURIComponent(String(paymentCode))}`, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body.toString()
      });
      const json = await res.json();
      if (!res.ok || json.error) throw new Error(json.error || 'Falha ao cadastrar forma de pagamento');
      await loadPaymentMethods();
      showStatus('Forma de pagamento cadastrada com sucesso', 'success');
    } catch (err) {
      showStatus(err.message, 'error');
    }
  }

  async function editPaymentMethod(id) {
    const item = (paymentMethods || []).find(x => String(x.id) === String(id));
    if (!item) return;
    const name = prompt('Editar forma de pagamento', item.name || '');
    if (!name) return;

    try {
      const body = new URLSearchParams({ id: String(id), name: name.trim() });
      const res = await fetch(`${paymentApi}?action=update&code=${encodeURIComponent(String(paymentCode))}`, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body.toString()
      });
      const json = await res.json();
      if (!res.ok || json.error) throw new Error(json.error || 'Falha ao atualizar forma de pagamento');
      await loadPaymentMethods();
      showStatus('Forma de pagamento atualizada com sucesso', 'success');
    } catch (err) {
      showStatus(err.message, 'error');
    }
  }

  async function deletePaymentMethod(id) {
    if (!confirm('Excluir forma de pagamento?')) return;
    try {
      const body = new URLSearchParams({ id: String(id) });
      const res = await fetch(`${paymentApi}?action=delete&code=${encodeURIComponent(String(paymentCode))}`, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body.toString()
      });
      const json = await res.json();
      if (!res.ok || json.error) throw new Error(json.error || 'Falha ao excluir forma de pagamento');
      await loadPaymentMethods();
      showStatus('Forma de pagamento excluída com sucesso', 'success');
    } catch (err) {
      showStatus(err.message, 'error');
    }
  }

  function renderPreview(stage) {
    const preview = $('#stagePreview');
    if (!preview) return;
    const isDark = document.body.classList.contains('theme-dark') || document.documentElement.getAttribute('data-theme') === 'dark';
    preview.innerHTML = '';
    const box = document.createElement('div');
    box.style.padding = '8px';
    box.style.borderRadius = '12px';
    box.style.borderTop = '6px solid ' + (stage.color || '#6c757d');
    box.style.border = isDark ? '1px solid rgba(230,238,248,0.04)' : '1px solid rgba(11,26,49,0.06)';
    box.style.background = isDark ? 'rgba(230,238,248,0.02)' : '#fff';
    box.innerHTML = `<div style="font-weight:700;padding:8px 6px">${stage.name} <span class="small text-muted" style="float:right">0</span></div>`;
    const card = document.createElement('div');
    card.style.marginTop = '8px';
    card.style.padding = '10px';
    card.style.borderRadius = '10px';
    card.style.borderLeft = '4px solid ' + (stage.color || '#6c757d');
    card.style.background = isDark ? '#071427' : '#fff';
    card.style.color = isDark ? '#e6eef8' : '#111';
    card.innerHTML = 'Projeto exemplo — <small class="text-muted">R$ 10.000</small>';
    box.appendChild(card);
    preview.appendChild(box);
  }

  document.addEventListener('DOMContentLoaded', () => {
    loadStages();
    loadChecklistItems();
    loadPaymentMethods();
    const addStageBtn = $('#addStageBtn');
    const addTechBtn = $('#addTechnicalItemBtn');
    const addDocBtn = $('#addDocItemBtn');
    const addPaymentBtn = $('#addPaymentMethodBtnConfig');
    const saveStageBtn = $('#saveStage');
    const deleteStageBtn = $('#deleteStage');

    if (addStageBtn) addStageBtn.addEventListener('click', e => { e.preventDefault(); addStage(); });
    if (addTechBtn) addTechBtn.addEventListener('click', e => { e.preventDefault(); addChecklistItem('technical'); });
    if (addDocBtn) addDocBtn.addEventListener('click', e => { e.preventDefault(); addChecklistItem('document'); });
    if (addPaymentBtn) addPaymentBtn.addEventListener('click', e => { e.preventDefault(); addPaymentMethod(); });
    if (saveStageBtn) saveStageBtn.addEventListener('click', e => { e.preventDefault(); saveStage(); });
    if (deleteStageBtn) deleteStageBtn.addEventListener('click', e => { e.preventDefault(); deleteStage(); });

    document.addEventListener('input', function(e) {
      if (e.target && e.target.id === 'stageColor') {
        const hex = $('#stageColorHex');
        if (hex) hex.textContent = e.target.value;
      }
    });
  });
})();
