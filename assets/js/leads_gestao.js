(function(){
    // Enhanced Kanban implementation (totals, inactivity alerts, movement timeline)
    const apiBase = 'includes/leads_api.php';
    let allLeads = [];
    const STALLED_DAYS_DEFAULT = 7;

    function $(sel){return document.querySelector(sel)}
    function $all(sel){return Array.from(document.querySelectorAll(sel))}

    function escapeText(s){ return s==null? '': String(s); }

    function computeScore(lead){
        // heuristic score 0-100
        let score = 0;
        if (lead.email) score += 20;
        if (lead.phone) score += 20;
        if (lead.consumo_cliente) score += 15;
        if (lead.estimativa_projeto_kwh) score += 10;
        if (lead.notes && lead.notes.length>40) score += 10;
        if (lead.source) score += 10;
        score = Math.min(100, score + (Math.random()*8|0));
        return Math.round(score);
    }

    async function fetchLeads(){
        const res = await fetch(apiBase + '?action=list');
        if (!res.ok) throw new Error('Falha ao carregar leads');
        const json = await res.json();
        allLeads = json.map(l => ({...l, score: l.score ?? computeScore(l)}));
        renderAll();
    }

    // Stages loaded from DB (funil_stages)
    let STAGES = [];
    async function fetchStages(){
        try{
            const res = await fetch('includes/funil_stages_api.php?action=list'); if (!res.ok) throw new Error('Falha ao carregar estágios');
            const json = await res.json();
            STAGES = json.map(s => ({ id: String(s.id), name: s.name || s.stage_name || 'Sem nome', color: s.color || s.stage_color || '#6c757d', card_color: s.card_color || null }));
            buildColumns();
        } catch (e) {
            console.error(e);
            STAGES = [{ id: '0', name: 'Novo', color:'#6c757d', card_color: null }];
            buildColumns();
        }
    }

    function buildColumns(){
        const wrap = document.getElementById('kanbanWrap'); if(!wrap) return;
        wrap.innerHTML = '';
        STAGES.forEach(s=>{
            const colWrap = document.createElement('div'); colWrap.className = 'kanban-column'; colWrap.dataset.stageId = s.id; colWrap.dataset.stageName = s.name;
            const header = document.createElement('div'); header.className='kanban-header'; header.innerHTML = `${s.name} <span class="badge bg-light text-muted" id="count-${s.id}">0</span> <div id="sum-${s.id}" class="small text-muted stage-sum"></div>`;
            const content = document.createElement('div'); content.className='column-content'; content.id = 'col-' + s.id;
            colWrap.appendChild(header); colWrap.appendChild(content);
            wrap.appendChild(colWrap);
        });
        const loading = document.getElementById('kanbanLoading'); if (loading) loading.remove();
    }
    function toCurrency(v){ return 'R$ ' + (Number(v)||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); }

    function renderKpis(){
        const active = allLeads.filter(l=>!['Perdido','Ganhou'].includes(l.status));
        const hot = allLeads.filter(l=>l.score>=80).length;
        const totalValue = allLeads.reduce((s,l)=>s + (parseFloat(l.proposal_value||l.estimativa_projeto_kwh||l.value||0) || 0), 0);
        const conv = allLeads.length ? (allLeads.filter(l=>l.status==='Ganhou').length / allLeads.length * 100).toFixed(1) : '0.0';
        $('#kpiActive').textContent = active.length;
        $('#kpiHot').textContent = hot;
        $('#kpiValue').textContent = toCurrency(totalValue);
        $('#kpiConv').textContent = conv + '%';
        // pipeline total
        const pipelineTotal = $('#pipelineTotal'); if (pipelineTotal) pipelineTotal.textContent = toCurrency(totalValue);
    }

    function clearColumns(){ $all('.column-content').forEach(c=>c.innerHTML=''); }

    function leadUpdatedDaysAgo(lead){
        if (!lead.updated_at) return Infinity;
        const updated = new Date(lead.updated_at.replace(' ','T'));
        const diffMs = Date.now() - updated.getTime();
        return Math.floor(diffMs / (1000*60*60*24));
    }

    function makeCard(lead){
        const el = document.createElement('div'); el.className='lead-card'; el.draggable = true; el.dataset.id = lead.id;
        // selection checkbox for bulk actions
        const chk = document.createElement('input'); chk.type='checkbox'; chk.className = 'lead-select me-2'; chk.title = 'Selecionar para ações em massa';
        chk.addEventListener('click', (e)=>{ e.stopPropagation(); el.classList.toggle('selected', chk.checked); });
        const head = document.createElement('div'); head.className = 'd-flex align-items-center justify-content-between';
        const left = document.createElement('div'); left.className = 'd-flex align-items-center'; left.appendChild(chk);
        const title = document.createElement('div'); title.className='title'; title.textContent = escapeText(lead.name || '(sem nome)'); left.appendChild(title);
        head.appendChild(left);

        const company = document.createElement('div'); company.className='lead-meta'; company.textContent = (lead.client_name || lead.company || '—');
        const meta = document.createElement('div'); meta.className='lead-meta';
        const value = document.createElement('span'); value.className='lead-value'; value.textContent = toCurrency(lead.proposal_value || lead.estimativa_projeto_kwh || lead.value || 0);
        const owner = document.createElement('span'); owner.className='lead-owner'; owner.textContent = lead.responsavel || '';
        const score = document.createElement('span'); score.className = 'badge-score ' + (lead.score>=80?'hot':(lead.score>=50?'warm':'cold')); score.textContent = lead.score;
        meta.appendChild(value); meta.appendChild(owner); meta.appendChild(score);
        el.appendChild(head); el.appendChild(company); el.appendChild(meta);

        // inactivity indicator
        const days = leadUpdatedDaysAgo(lead);
        if (days !== Infinity && days >= (Number(localStorage.getItem('stalledDays') || STALLED_DAYS_DEFAULT))) {
            const warn = document.createElement('span'); warn.className='stalled-icon'; warn.title = 'Sem interação há ' + days + ' dias'; warn.textContent = '⚠️';
            title.appendChild(warn);
            el.classList.add('stalled');
        }

        // events
        el.addEventListener('click', (e)=>{ openPanel(lead.id); });
        el.addEventListener('dragstart', (e)=>{ e.dataTransfer.setData('text/plain', lead.id); e.dataTransfer.effectAllowed='move'; setTimeout(()=>el.classList.add('dragging'),0); });
        el.addEventListener('dragend', ()=>el.classList.remove('dragging'));
        return el;
    }

    function renderAll(){
        clearColumns();
        // compute sums per stage id
        const sums = {};
        STAGES.forEach(s=> sums[s.id] = 0);

        allLeads.forEach(l=>{
            const stageKey = l.stage_id ? String(l.stage_id) : (STAGES.find(s=>s.name === (l.status||'')) || {id:'0'}).id;
            const col = document.getElementById('col-' + stageKey);
            if (col) col.appendChild(makeCard(l));
            const val = parseFloat(l.proposal_value || l.estimativa_projeto_kwh || l.value || 0) || 0;
            sums[stageKey] = (sums[stageKey] || 0) + val;
        });

        STAGES.forEach(s=>{
            const countEl = document.getElementById('count-' + s.id);
            if (countEl) {
                const cnt = (document.getElementById('col-' + s.id) || {children:[]}).children.length;
                countEl.textContent = cnt;
            }
            const sumEl = document.getElementById('sum-' + s.id);
            if (sumEl) sumEl.textContent = toCurrency(sums[s.id] || 0);
            const colWrap = document.querySelector(`[data-stage-id='${s.id}']`);
            if (colWrap) colWrap.classList.remove('largest-stage');
        });

        // highlight largest stage by value
        const largest = Object.keys(sums).reduce((mx,k)=> sums[k] > (sums[mx]||0) ? k : mx, Object.keys(sums)[0]);
        if (largest) {
            const el = document.querySelector(`[data-stage-id='${largest}']`);
            if (el) el.classList.add('largest-stage');
        }

        renderKpis();
    }

    function setupDragDrop(){
        $all('.column-content').forEach(col=>{
            col.addEventListener('dragover', (e)=>{ e.preventDefault(); col.classList.add('drag-over'); e.dataTransfer.dropEffect='move'; });
            col.addEventListener('dragleave', ()=>col.classList.remove('drag-over'));
            col.addEventListener('drop', async (e)=>{
                e.preventDefault(); col.classList.remove('drag-over');
                const id = e.dataTransfer.getData('text/plain');
                const container = col.closest('.kanban-column');
                const stageId = container?.dataset?.stageId;
                const stageName = container?.dataset?.stageName;
                try {
                    await updateStatus(id, stageName, { stage_id: stageId });
                    const item = allLeads.find(x=>String(x.id)===String(id)); if (item) { item.status = stageName; item.stage_id = stageId; item.updated_at = (new Date()).toISOString(); }
                    renderAll();
                    flashFeedback(col, true);
                } catch(err){ flashFeedback(col, false); console.error(err); }
            });
        });
    }

    async function updateStatus(id, status, opts = {}){
        const body = new URLSearchParams(); body.append('action','update_status'); body.append('id', id); body.append('status', status);
        if (opts.stage_id) body.append('stage_id', opts.stage_id);
        if (opts.changed_by) body.append('changed_by', opts.changed_by);
        const res = await fetch(apiBase, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString()});
        if (!res.ok) throw new Error('update failed');
        const json = await res.json(); if (json.error) throw new Error(json.error);
        return json;
    }

    function flashFeedback(el, ok){
        const prev = el.style.boxShadow;
        el.style.boxShadow = ok? '0 6px 18px rgba(59,178,115,0.16)':'0 6px 18px rgba(239,68,68,0.12)';
        setTimeout(()=> el.style.boxShadow = prev, 700);
    }

    async function fetchMovements(leadId){
        const res = await fetch(apiBase + '?action=movements&lead_id=' + encodeURIComponent(leadId));
        if (!res.ok) return [];
        try { return await res.json(); } catch(e){ return []; }
    }

    async function openPanel(id){
        const lead = allLeads.find(l=>String(l.id)===String(id)); if (!lead) return;
        const p = $('#leadDetailContent'); p.innerHTML = '';
        const title = document.createElement('h4'); title.textContent = lead.name || '(sem nome)';
        const status = document.createElement('div'); status.className='mb-2 small text-muted'; status.textContent = 'Status: ' + (lead.status||'Novo');
        const company = document.createElement('div'); company.textContent = 'Empresa: ' + (lead.client_name|| lead.company|| '—');
        const email = document.createElement('div'); email.innerHTML = 'Email: ' + (lead.email? `<a href="mailto:${encodeURIComponent(lead.email)}">${lead.email}</a>` : '—');
        const phone = document.createElement('div'); phone.innerHTML = 'Telefone: ' + (lead.phone? `<a href="tel:${encodeURIComponent(lead.phone)}">${lead.phone}</a>` : '—');
        const value = document.createElement('div'); value.textContent = 'Valor estimado: ' + toCurrency(lead.proposal_value || lead.estimativa_projeto_kwh || lead.value || 0);
        const notes = document.createElement('div'); notes.className='mt-3'; notes.textContent = 'Notas: ' + (lead.notes || '—');
        const btns = document.createElement('div'); btns.className='mt-3 d-flex gap-2';
        const callBtn = document.createElement('a'); callBtn.className='btn btn-sm btn-outline-primary'; callBtn.href = lead.phone? 'tel:'+lead.phone:'#'; callBtn.textContent='Ligar';
        const whatsappBtn = document.createElement('a'); whatsappBtn.className='btn btn-sm btn-outline-success'; whatsappBtn.href = lead.phone? 'https://wa.me/'+lead.phone.replace(/\D/g,''):'#'; whatsappBtn.target='_blank'; whatsappBtn.textContent='WhatsApp';
        const proposalBtn = document.createElement('button'); proposalBtn.className='btn btn-sm btn-primary'; proposalBtn.textContent='Enviar proposta';
        btns.appendChild(callBtn); btns.appendChild(whatsappBtn); btns.appendChild(proposalBtn);

        // Movement timeline
        const timelineWrap = document.createElement('div'); timelineWrap.className = 'mt-3'; timelineWrap.innerHTML = '<h6>Histórico de movimentações</h6><div id="timeline"></div>';
        p.appendChild(title); p.appendChild(status); p.appendChild(company); p.appendChild(email); p.appendChild(phone); p.appendChild(value); p.appendChild(notes); p.appendChild(btns); p.appendChild(timelineWrap);

        // fetch and render movements
        const timeline = timelineWrap.querySelector('#timeline'); timeline.innerHTML = '<div class="small text-muted">Carregando...</div>';
        const moves = await fetchMovements(id);
        if (moves && moves.length) {
            timeline.innerHTML = '';
            moves.forEach(m=>{
                const item = document.createElement('div'); item.className = 'movement-row';
                const ts = document.createElement('div'); ts.className='small text-muted'; ts.textContent = new Date(m.created_at).toLocaleString();
                const txt = document.createElement('div'); txt.textContent = `${m.from_status || '—'} → ${m.to_status || '—'}` + (m.note ? ` — ${m.note}` : '');
                item.appendChild(ts); item.appendChild(txt); timeline.appendChild(item);
            });
        } else {
            timeline.innerHTML = '<div class="small text-muted">Nenhuma movimentação registrada</div>';
        }

        const panel = $('#leadDetailsPanel'); panel.classList.remove('hidden');
    }

    function closePanel(){ $('#leadDetailsPanel').classList.add('hidden'); }

    function getSelectedLeadIds(){ return Array.from(document.querySelectorAll('.lead-select:checked')).map(c=>c.closest('.lead-card')?.dataset?.id).filter(Boolean); }

    async function populateBulkStages(){
        const sel = $('#bulkTargetStage'); if (!sel) return;
        sel.innerHTML = '<option value="">Escolher etapa</option>';
        try{
            const res = await fetch('includes/funil_stages_api.php?action=list'); if (!res.ok) return;
            const rows = await res.json(); rows.forEach(r=>{ const o = document.createElement('option'); o.value = r.id; o.textContent = r.name; sel.appendChild(o); });
        } catch(e) { console.warn('Failed to load stages', e); }
    }

    function setupHandlers(){
        $('#closeLeadPanel').addEventListener('click', closePanel);
        $('#newLeadBtn').addEventListener('click', ()=>{ const m = new bootstrap.Modal($('#leadModal')); $('#leadModalTitle').textContent='Novo Lead'; $('#leadForm').reset(); m.show(); });
        $('#leadForm').addEventListener('submit', async (e)=>{
            e.preventDefault(); const id = $('#leadId').value; const data = new URLSearchParams();
            data.append('name', $('#leadName').value); data.append('email',$('#leadEmail').value); data.append('phone',$('#leadPhone').value); data.append('source','web'); data.append('status','Novo');
            data.append('action', id? 'update':'add'); if (id) data.append('id', id);
            try{ const res = await fetch(apiBase, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: data.toString()}); const json = await res.json(); if (json.error) throw new Error(json.error); fetchLeads(); new bootstrap.Modal($('#leadModal')).hide(); }catch(err){ alert('Falha ao salvar: ' + err.message); }
        });

        $('#searchInput').addEventListener('input', (e)=>{ const v = e.target.value.toLowerCase(); if (!v) {renderAll();return;} allLeads = allLeads.sort(); // noop to keep reference
            const filtered = allLeads.filter(l => (l.name||'').toLowerCase().includes(v) || (l.client_name||'').toLowerCase().includes(v)); clearColumns(); filtered.forEach(l=>{ const stageKey = l.stage_id ? String(l.stage_id) : (STAGES.find(s=>s.name === (l.status||''))||{id:'0'}).id; const col = document.getElementById('col-' + stageKey); if(col) col.appendChild(makeCard(l)); });
        });

        $('#filterScore').addEventListener('change', (e)=>{
            const v = e.target.value; if (!v) { renderAll(); return; } const map = {hot: l=>l.score>=80, warm: l=>l.score>=50 && l.score<80, cold: l=>l.score<50};
            clearColumns(); allLeads.filter(map[v]).forEach(l=>{ const stageKey = l.stage_id ? String(l.stage_id) : (STAGES.find(s=>s.name === (l.status||''))||{id:'0'}).id; const col = document.getElementById('col-' + stageKey); if(col) col.appendChild(makeCard(l)); });
        });

        // stalled toggle
        const stalledBtn = $('#stalledToggle'); if (stalledBtn) {
            stalledBtn.addEventListener('click', ()=>{
                const only = stalledBtn.classList.toggle('active'); stalledBtn.textContent = only ? 'Somente parados' : 'Leads parados';
                if (only) {
                    clearColumns(); const thresh = Number(localStorage.getItem('stalledDays')||STALLED_DAYS_DEFAULT);
                    allLeads.filter(l=> leadUpdatedDaysAgo(l) >= thresh).forEach(l=>{ const stageKey = l.stage_id ? String(l.stage_id) : (STAGES.find(s=>s.name === (l.status||''))||{id:'0'}).id; const col = document.getElementById('col-' + stageKey); if(col) col.appendChild(makeCard(l)); });
                } else { renderAll(); }
            });
        }

        // populate bulk stages when modal opens
        const bulkBtn = $('#bulkActionsBtn'); if (bulkBtn) {
            bulkBtn.addEventListener('click', ()=> populateBulkStages());
        }

        // bulk apply
        const bulkApply = $('#bulkApply'); if (bulkApply) {
            bulkApply.addEventListener('click', async ()=>{
                const ids = getSelectedLeadIds(); if (!ids.length) return alert('Nenhum lead selecionado');
                const stageId = $('#bulkTargetStage').value; const assign = $('#bulkAssign').value;
                if (!stageId) return alert('Escolha uma etapa alvo');
                bulkApply.disabled = true; bulkApply.textContent = 'Aplicando...';
                try {
                    for (let id of ids) {
                        await updateStatus(id, '', {stage_id: stageId});
                        // optionally implement assignment by calling update with data (left simple here)
                    }
                    await fetchLeads(); $('#bulkModal').querySelector('[data-bs-dismiss]')?.click();
                    alert('Ação concluída');
                } catch (e) { console.error(e); alert('Falha ao aplicar: ' + e.message); }
                bulkApply.disabled = false; bulkApply.textContent = 'Aplicar';
            });
        }

        // dark mode
        const darkBtn = $('#darkToggle'); if (darkBtn) {
            const apply = (on)=>{ document.body.classList.toggle('dark-mode', !!on); localStorage.setItem('darkMode', !!on ? '1' : '0'); darkBtn.textContent = !!on ? 'Modo Claro' : 'Modo Escuro'; };
            apply(localStorage.getItem('darkMode') === '1');
            darkBtn.addEventListener('click', ()=> apply(!(localStorage.getItem('darkMode') === '1')) );
        }

        // close panel on escape
        document.addEventListener('keydown', (e)=>{ if (e.key==='Escape') closePanel(); });
    }

    // initial
    document.addEventListener('DOMContentLoaded', async ()=>{
        try{ await fetchStages(); await fetchLeads(); setupDragDrop(); setupHandlers(); }catch(err){ console.error(err); alert('Erro inicial: '+err.message); }
    });

})();
