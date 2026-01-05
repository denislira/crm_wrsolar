(function(){
    // Enhanced Kanban implementation (totals, inactivity alerts, movement timeline)
    const apiBase = 'includes/leads_api.php';
    let allLeads = [];
    const STALLED_DAYS_DEFAULT = 7;

    function $(sel){return document.querySelector(sel)}
    function $all(sel){return Array.from(document.querySelectorAll(sel))}

    // Field adapter: maps common field keys to multiple possible modal input IDs
    const FIELD_MAP = {
        leadId: ['#leadId','#lead-id'],
        leadName: ['#leadName','#lead-name'],
        leadPhone: ['#leadPhone','#lead-phone'],
        leadSource: ['#leadSource','#lead-source'],
        leadEmail: ['#leadEmail','#lead-email'],
        leadCpf: ['#leadCpf','#lead-cpf-cnpj','##lead-cpf'],
        leadStatus: ['#leadStatus','#lead-status'],
        leadStage: ['#leadStage','#lead-stage'],
        leadConsumo: ['#leadConsumo','#lead-consumo'],
        leadEstimativa: ['#leadEstimativa','#lead-estimativa-kwh'],
        leadAnexos: ['#leadAnexos','#lead-anexos'],
        leadNotes: ['#leadNotes','#lead-notes'],
        leadForm: ['#leadForm','form#leadForm'],
        leadModalTitle: ['#leadModalTitle','#leadModalTitle']
    };
    function F(key){
        const arr = FIELD_MAP[key] || [];
        for (let s of arr){ try { const el = document.querySelector(s); if (el) return el; } catch(e){} }
        return null;
    }

    function escapeText(s){ return s==null? '': String(s); }

    // color utilities: convert hex to rgb and pick readable text color (black/white)
    function hexToRgb(hex){
        if (!hex) return null;
        const m = String(hex).replace('#','').trim();
        if (m.length === 3) {
            const r = parseInt(m[0]+m[0],16), g = parseInt(m[1]+m[1],16), b = parseInt(m[2]+m[2],16);
            return {r,g,b};
        } else if (m.length === 6) {
            const r = parseInt(m.slice(0,2),16), g = parseInt(m.slice(2,4),16), b = parseInt(m.slice(4,6),16);
            return {r,g,b};
        }
        return null;
    }
    function readableTextColor(hex){
        const rgb = hexToRgb(hex);
        if (!rgb) return '';
        const brightness = (rgb.r * 299 + rgb.g * 587 + rgb.b * 114) / 1000;
        return brightness > 125 ? '#000' : '#fff';
    }

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
        try {
            console.log('Fetching leads from:', apiBase + '?action=list');
            const res = await fetch(apiBase + '?action=list');
            console.log('Fetch leads response status:', res.status);
            if (!res.ok) throw new Error('Falha ao carregar leads');
            const json = await res.json();
            console.log('Leads loaded:', json.length);
            allLeads = json.map(l => ({...l, score: l.score ?? computeScore(l)}));
            renderAll();
        } catch (err) {
            console.error('fetchLeads error:', err);
            throw err;
        }
    }

    let REMINDER_TEMPLATES = [];
    async function fetchReminderTemplates(){
        try{
            const res = await fetch('includes/reminder_templates_api.php?action=list');
            if (!res.ok) return;
            const rows = await res.json();
            REMINDER_TEMPLATES = Array.isArray(rows) ? rows : [];
            const sel = document.getElementById('reminderTemplateSelect'); if (!sel) return;
            sel.innerHTML = '<option value="">-- nenhum --</option>';
            REMINDER_TEMPLATES.forEach(t=>{
                const o = document.createElement('option'); o.value = t.id; o.textContent = t.name; sel.appendChild(o);
            });
        } catch(e){ console.warn('Failed loading reminder templates', e); }
    }

    // Stages loaded from DB (funil_stages)
    let STAGES = [];
    async function fetchStages(){
        try{
            const res = await fetch('includes/funil_stages_api.php?action=list'); if (!res.ok) throw new Error('Falha ao carregar estágios');
            const json = await res.json();
            STAGES = json.map(s => ({ id: String(s.id), name: s.name || s.stage_name || 'Sem nome', color: s.color || s.stage_color || '#6c757d', card_color: s.card_color || null }));
            buildColumns();
            populateStatusSelect();
        } catch (e) {
            console.error(e);
            STAGES = [{ id: '0', name: 'Novo', color:'#6c757d', card_color: null }];
            buildColumns();
            populateStatusSelect();
        }
    }

    function populateStatusSelect(){
        const sel = document.querySelector('#lead-status') || F('leadStatus') || document.querySelector('#leadStatus');
        if (!sel) return;
        sel.innerHTML = ''; // clear
        const def = document.createElement('option'); def.value = ''; def.textContent = 'Novo'; sel.appendChild(def);
        STAGES.forEach(s=>{
            const o = document.createElement('option'); o.value = s.id; o.textContent = s.name; sel.appendChild(o);
        });
    }

    function buildColumns(){
        const wrap = document.getElementById('kanbanWrap'); if(!wrap) return;
        wrap.innerHTML = '';
        STAGES.forEach(s=>{
            const colWrap = document.createElement('div'); colWrap.className = 'kanban-column'; colWrap.dataset.stageId = s.id; colWrap.dataset.stageName = s.name;
            // expose colors to the DOM for later use
            if (s.color) colWrap.dataset.color = s.color;
            if (s.card_color) colWrap.dataset.cardColor = s.card_color;
            // normalize generate task flag (support different API shapes)
            s.generate_task = (typeof s.generate_task_on_enter !== 'undefined') ? Number(s.generate_task_on_enter) : (typeof s.generate_task !== 'undefined' ? Number(s.generate_task) : 0);
            const header = document.createElement('div'); header.className='kanban-header';
            // name + count
            const titleHtml = document.createElement('span'); titleHtml.className = 'kanban-title'; titleHtml.textContent = s.name;
            const countBadge = document.createElement('span'); countBadge.className = 'badge bg-light text-muted ms-2'; countBadge.id = 'count-' + s.id; countBadge.textContent = '0';
            const sumDiv = document.createElement('div'); sumDiv.id = 'sum-' + s.id; sumDiv.className = 'small text-muted stage-sum';
            header.appendChild(titleHtml); header.appendChild(countBadge); header.appendChild(sumDiv);
            // compact, discreet indicator for "Criar tarefa ao entrar"
            if (s.generate_task) {
                const ind = document.createElement('i');
                ind.className = 'fa fa-tasks task-indicator ms-2';
                ind.title = 'Cria tarefa ao entrar';
                header.appendChild(ind);
            }
            // apply a thin colored stripe on top of the column for better cross-theme visibility
            if (s.color) {
                const stripe = document.createElement('div');
                stripe.className = 'kanban-top-stripe';
                stripe.style.background = s.color;
                colWrap.appendChild(stripe);
            }
            const content = document.createElement('div'); content.className='column-content'; content.id = 'col-' + s.id;
            colWrap.appendChild(header); colWrap.appendChild(content);
            wrap.appendChild(colWrap);
        });
        const loading = document.getElementById('kanbanLoading'); if (loading) loading.remove();
    }

    function toCurrency(v){ return 'R$ ' + (Number(v)||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); }

    function formatDateBR(dt){
        if (!dt) return '—';
        try { const d = new Date(String(dt).replace(' ', 'T')); return d.toLocaleDateString('pt-BR'); } catch(e){ return String(dt); }
    }
    function daysSince(dt){ if (!dt) return null; try { const d = new Date(String(dt).replace(' ', 'T')); const diff = Date.now() - d.getTime(); return Math.floor(diff / (1000*60*60*24)); } catch(e){ return null; } }

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

        const company = document.createElement('div'); company.className='lead-meta'; company.textContent = 'Fonte: ' + (lead.source || lead.client_name || lead.company || '—');
        const meta = document.createElement('div'); meta.className='lead-meta';
        const value = document.createElement('span'); value.className='lead-value'; value.textContent = toCurrency(lead.proposal_value || lead.estimativa_projeto_kwh || lead.value || 0);
        const owner = document.createElement('span'); owner.className='lead-owner'; owner.textContent = lead.responsavel || '';
        const score = document.createElement('span'); score.className = 'badge-score ' + (lead.score>=80?'hot':(lead.score>=50?'warm':'cold')); score.textContent = lead.score;
        meta.appendChild(value); meta.appendChild(owner); meta.appendChild(score);
        el.appendChild(head); el.appendChild(company); el.appendChild(meta);

        // created date and days active
        const createdText = formatDateBR(lead.created_at || lead.createdAt || lead.created);
        const daysActive = daysSince(lead.created_at || lead.createdAt || lead.created);
        const noMovement = leadUpdatedDaysAgo(lead);
        const createdDiv = document.createElement('div'); createdDiv.className = 'lead-created small text-muted mt-1';
        createdDiv.textContent = 'Criado: ' + createdText + (daysActive !== null ? (' • ' + daysActive + ' dias') : '');
        // days without movement badge
        const daysBox = document.createElement('span'); daysBox.className = 'lead-days-box'; daysBox.textContent = (noMovement !== Infinity && noMovement !== null) ? (noMovement + ' dias') : '—';
        daysBox.title = 'Dias sem movimento';
        createdDiv.appendChild(daysBox);
        el.appendChild(createdDiv);

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
        el.addEventListener('dragend', ()=>{
            // ensure dragging class removed and border restored
            el.classList.remove('dragging');
            el.style.borderLeft = el.dataset.originalBorder || '4px solid transparent';
            el.dataset._prevBorder = '';
        });
        return el;
    }

    function getViewMode(){ return localStorage.getItem('leadsView') || 'kanban'; }
    function setViewMode(mode){
        localStorage.setItem('leadsView', mode);
        const kanban = document.getElementById('kanbanWrap');
        const list = document.getElementById('listWrap');
        const btn = document.getElementById('toggleViewBtn');
        if (mode === 'grid') {
            if (kanban) kanban.classList.add('d-none');
            if (list) list.classList.remove('d-none');
            if (btn) btn.innerHTML = '<i class="fa fa-columns"></i>';
        } else {
            if (kanban) kanban.classList.remove('d-none');
            if (list) list.classList.add('d-none');
            if (btn) btn.innerHTML = '<i class="fa fa-list"></i>';
        }
    }

    function renderGrid(){
        const container = document.getElementById('leadsTableContainer'); if (!container) return;
        container.innerHTML = '';
        const table = document.createElement('table'); table.className = 'table table-sm table-hover';
        const thead = document.createElement('thead'); thead.innerHTML = '<tr><th></th><th>Nome</th><th>Fonte</th><th>Status</th><th>Valor</th><th>Responsável</th><th>Score</th><th>Criado</th><th></th></tr>';
        const tbody = document.createElement('tbody');
        // rows
        allLeads.forEach(lead => {
            const tr = document.createElement('tr'); tr.dataset.id = lead.id;
            const chkTd = document.createElement('td'); chkTd.innerHTML = '<input class="lead-select" type="checkbox">';
            const nameTd = document.createElement('td'); nameTd.textContent = lead.name || '(sem nome)';
            const compTd = document.createElement('td'); compTd.textContent = lead.source || lead.client_name || lead.company || '—';
            const statusTd = document.createElement('td'); statusTd.textContent = lead.status || '';
            const valTd = document.createElement('td'); valTd.textContent = toCurrency(lead.proposal_value || lead.estimativa_projeto_kwh || lead.value || 0);
            const ownerTd = document.createElement('td'); ownerTd.textContent = lead.responsavel || '';
            const scoreTd = document.createElement('td'); scoreTd.innerHTML = '<span class="badge-score ' + (lead.score>=80?'hot':(lead.score>=50?'warm':'cold')) + '">' + (lead.score||0) + '</span>';
            const createdTd = document.createElement('td'); createdTd.className='small text-muted'; createdTd.textContent = formatDateBR(lead.created_at || lead.createdAt || lead.created);
            const actTd = document.createElement('td');
            const openBtn = document.createElement('button'); openBtn.className='btn btn-sm btn-outline-secondary'; openBtn.type='button'; openBtn.textContent='Abrir';
            openBtn.addEventListener('click', ()=> openPanel(lead.id));
            actTd.appendChild(openBtn);

            tr.appendChild(chkTd); tr.appendChild(nameTd); tr.appendChild(compTd); tr.appendChild(statusTd); tr.appendChild(valTd); tr.appendChild(ownerTd); tr.appendChild(scoreTd); tr.appendChild(createdTd); tr.appendChild(actTd);
            tbody.appendChild(tr);
        });
        table.appendChild(thead); table.appendChild(tbody); container.appendChild(table);
        // ensure the list container mirrors the global theme class so tables adopt dark styling
        const prefersDark = document.body.classList.contains('theme-dark') || document.body.classList.contains('dark-mode');
        container.classList.toggle('theme-dark', prefersDark);
        // observe body class changes once so the container stays in sync
        if (!window.__leadsThemeObserverSetup) {
            const bodyObserver = new MutationObserver(()=>{
                const isDark = document.body.classList.contains('theme-dark') || document.body.classList.contains('dark-mode');
                const c = document.getElementById('leadsTableContainer'); if (c) c.classList.toggle('theme-dark', isDark);
            });
            bodyObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
            window.__leadsThemeObserverSetup = true;
        }
    }

    function renderAll(){
        // switch to grid view if requested
        if (getViewMode() === 'grid') { renderGrid(); return; }
        clearColumns();
        // compute sums per stage id
        const sums = {};
        STAGES.forEach(s=> sums[s.id] = 0);

        allLeads.forEach(l=>{
            const stageKey = l.stage_id ? String(l.stage_id) : (STAGES.find(s=>s.name === (l.status||'')) || {id:'0'}).id;
            const col = document.getElementById('col-' + stageKey);
            if (col) {
                const card = makeCard(l);
                // determine colors: prefer DOM dataset, then STAGES data
                const colWrap = col.closest('.kanban-column');
                let cardColor = colWrap?.dataset?.cardColor || '';
                let headerColor = colWrap?.dataset?.color || '';
                if ((!cardColor || !headerColor) && STAGES && STAGES.length) {
                    const stageObj = STAGES.find(s => String(s.id) === String(stageKey));
                    if (stageObj) {
                        cardColor = cardColor || (stageObj.card_color || '');
                        headerColor = headerColor || (stageObj.color || '');
                    }
                }
                if (!headerColor) headerColor = '#6c757d';
                if (cardColor) {
                    card.style.backgroundColor = cardColor;
                    card.style.color = readableTextColor(cardColor);
                }
                card.style.borderLeft = headerColor ? ('6px solid ' + headerColor) : '6px solid transparent';
                card.dataset.originalBorder = card.style.borderLeft || '6px solid transparent';
                col.appendChild(card);
            }
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
        const wrap = document.getElementById('kanbanWrap');
        if (!wrap) return;

        // delegated handlers on the wrap so empty column areas accept drops
        wrap.addEventListener('dragover', (e)=>{
            e.preventDefault(); e.dataTransfer.dropEffect='move';
            const colWrap = e.target.closest('.kanban-column');
            if (!colWrap) return;
            const colContent = colWrap.querySelector('.column-content');
            colWrap.classList.add('drag-over-column');
            const dragging = document.querySelector('.lead-card.dragging');
            if (dragging) {
                const colColor = colWrap?.dataset?.color || '#6c757d';
                if (colColor && !dragging.dataset._prevBorder) dragging.dataset._prevBorder = dragging.style.borderLeft || '';
                if (colColor) dragging.style.borderLeft = '4px solid ' + colColor;
            }
        });

        wrap.addEventListener('dragleave', (e)=>{
            const colWrap = e.target.closest('.kanban-column');
            if (!colWrap) return;
            colWrap.classList.remove('drag-over-column');
            const dragging = document.querySelector('.lead-card.dragging');
            if (dragging) {
                dragging.style.borderLeft = dragging.dataset._prevBorder || dragging.dataset.originalBorder || '4px solid transparent';
                dragging.dataset._prevBorder = '';
            }
        });

        wrap.addEventListener('drop', async (e)=>{
            e.preventDefault();
            const colWrap = e.target.closest('.kanban-column');
            if (!colWrap) return;
            colWrap.classList.remove('drag-over-column');
            const colContent = colWrap.querySelector('.column-content');
            const id = e.dataTransfer.getData('text/plain');
            const stageId = colWrap?.dataset?.stageId;
            const stageName = colWrap?.dataset?.stageName;
            try {
                await updateStatus(id, stageName, { stage_id: stageId });
                const item = allLeads.find(x=>String(x.id)===String(id)); if (item) { item.status = stageName; item.stage_id = stageId; item.updated_at = (new Date()).toISOString(); }
                const dragging = document.querySelector('.lead-card.dragging');
                if (dragging) {
                    const newColor = container?.dataset?.color || '#6c757d';
                    dragging.style.borderLeft = '6px solid ' + newColor;
                    dragging.dataset._prevBorder = '';
                }
                renderAll();
                flashFeedback(colContent, true);
            } catch(err){ flashFeedback(colContent, false); console.error(err); }
        });

        // cleanup
        document.addEventListener('dragend', ()=>{
            const d = document.querySelector('.lead-card.dragging');
            if (d) {
                d.classList.remove('dragging');
                d.style.borderLeft = d.dataset.originalBorder || '4px solid transparent';
                d.dataset._prevBorder = '';
            }
            // remove any lingering overlays
            $all('.kanban-column.drag-over-column').forEach(c=>c.classList.remove('drag-over-column'));
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
        const company = document.createElement('div'); company.textContent = 'Fonte: ' + (lead.source || lead.client_name || lead.company || '—');
        const email = document.createElement('div'); email.innerHTML = 'Email: ' + (lead.email? `<a href="mailto:${encodeURIComponent(lead.email)}">${lead.email}</a>` : '—');
        const phone = document.createElement('div'); phone.innerHTML = 'Telefone: ' + (lead.phone? `<a href="tel:${encodeURIComponent(lead.phone)}">${lead.phone}</a>` : '—');
        const value = document.createElement('div'); value.textContent = 'Valor estimado: ' + toCurrency(lead.proposal_value || lead.estimativa_projeto_kwh || lead.value || 0);
        const createdText = formatDateBR(lead.created_at || lead.createdAt || lead.created);
        const daysActive = daysSince(lead.created_at || lead.createdAt || lead.created);
        const createdDiv = document.createElement('div'); createdDiv.className = 'small text-muted'; createdDiv.textContent = 'Criado: ' + createdText + (daysActive !== null ? (' • ' + daysActive + ' dias') : '');
        const notes = document.createElement('div'); notes.className='mt-3'; notes.textContent = 'Notas: ' + (lead.notes || '—');
            const btns = document.createElement('div'); btns.className='mt-3 d-flex gap-2';
            // compact reminders placeholder (filled after panel open)
            const remindersWrap = document.createElement('div');
            remindersWrap.className = 'mb-3'; remindersWrap.id = 'leadReminders';
            p.appendChild(remindersWrap);
                // create reminder button (icon + label) and wire click to open modal
                const reminderBtn = document.createElement('button');
                reminderBtn.className = 'btn btn-sm btn-outline-info';
                reminderBtn.type = 'button';
                reminderBtn.innerHTML = '<i class="fa fa-clock"></i> Lembrete';
                reminderBtn.addEventListener('click', (e)=>{
                    e.stopPropagation();
                    const leadId = lead.id || '';
                    const leadIdInput = document.getElementById('reminderLeadId'); if (leadIdInput) leadIdInput.value = leadId;
                    const reminderMessage = document.getElementById('reminderMessage'); if (reminderMessage) reminderMessage.value = '';
                    const reminderDate = document.getElementById('reminderDate'); if (reminderDate) reminderDate.value = '';
                    const reminderTime = document.getElementById('reminderTime'); if (reminderTime) reminderTime.value = '';
                    const saveCb = document.getElementById('saveAsTemplateCheckbox'); if (saveCb) { saveCb.checked = false; }
                    const saveWrap = document.getElementById('saveTemplateNameWrap'); if (saveWrap) saveWrap.style.display = 'none';
                    // close details panel first so modal appears on top
                    closePanel();
                    const modalEl = document.getElementById('reminderModal');
                    const m = new bootstrap.Modal(modalEl);
                    // ensure modal is a child of body to avoid stacking/z-index issues
                    try { if (modalEl && modalEl.parentNode !== document.body) document.body.appendChild(modalEl); } catch(e){}
                    // small delay to allow panel hide animation/remove stacking context
                    setTimeout(()=>{ console.debug('Showing reminder modal'); m.show(); }, 120);
                    // populate templates when opening
                    fetchReminderTemplates().then(()=>{
                        const sel = document.getElementById('reminderTemplateSelect'); if (!sel) return;
                        sel.value = '';
                        sel.addEventListener('change', ()=>{
                            const id = sel.value; if (!id) return;
                            const tmpl = REMINDER_TEMPLATES.find(x=>String(x.id)===String(id)); if (!tmpl) return;
                            const msgEl = document.getElementById('reminderMessage'); if (msgEl) msgEl.value = tmpl.message || '';
                            // compute default date
                            const days = Number(tmpl.default_days_offset || 0);
                            const dt = new Date(); dt.setDate(dt.getDate() + days);
                            const y = dt.getFullYear(); const mth = String(dt.getMonth()+1).padStart(2,'0'); const d = String(dt.getDate()).padStart(2,'0');
                            const time = tmpl.default_time ? tmpl.default_time.substring(0,5) : '';
                            const dateEl = document.getElementById('reminderDate'); if (dateEl) dateEl.value = `${y}-${mth}-${d}`;
                            const timeEl = document.getElementById('reminderTime'); if (timeEl) timeEl.value = time;
                        }, {once:true});
                    });
                });
        const editBtn = document.createElement('button'); editBtn.className='btn btn-sm btn-outline-primary'; editBtn.type='button'; editBtn.textContent='Editar';
        editBtn.addEventListener('click', (e)=>{ e.stopPropagation(); populateLeadForm(lead); });
        const whatsappBtn = document.createElement('a'); whatsappBtn.className='btn btn-sm btn-outline-success'; whatsappBtn.href = lead.phone? 'https://wa.me/'+lead.phone.replace(/\D/g,''):'#'; whatsappBtn.target='_blank'; whatsappBtn.textContent='WhatsApp';
        const proposalBtn = document.createElement('button'); proposalBtn.className='btn btn-sm btn-primary'; proposalBtn.type='button'; proposalBtn.textContent='Enviar proposta';
        proposalBtn.classList.add('d-none');
        btns.appendChild(reminderBtn); btns.appendChild(editBtn); btns.appendChild(whatsappBtn); btns.appendChild(proposalBtn);

        // Movement timeline
        const timelineWrap = document.createElement('div'); timelineWrap.className = 'mt-3'; timelineWrap.innerHTML = '<h6>Histórico de movimentações</h6><div id="timeline"></div>';
        p.appendChild(title); p.appendChild(status); p.appendChild(company); p.appendChild(email); p.appendChild(phone); p.appendChild(value); p.appendChild(createdDiv); p.appendChild(notes); p.appendChild(btns); p.appendChild(timelineWrap);

            // load compact reminders for this lead
            try { fetchRemindersForLead(id); } catch(e){ console.warn('failed loading reminders', e); }

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

    // fetch and render a compact reminders block inside the lead details panel
    function fetchRemindersForLead(leadId) {
        const wrap = document.getElementById('leadReminders'); if (!wrap) return;
        wrap.innerHTML = '<strong>Lembretes:</strong> carregando...';
        fetch('includes/reminders_api.php?action=list&lead_id=' + encodeURIComponent(leadId))
            .then(r => r.json())
            .then(rows => {
                wrap.innerHTML = '';
                const header = document.createElement('div'); header.className = 'd-flex align-items-center mb-1';
                const title = document.createElement('strong'); title.textContent = 'Lembretes:';
                const badge = document.createElement('span'); badge.className = 'badge bg-secondary ms-2'; badge.textContent = rows.length;
                header.appendChild(title); header.appendChild(badge);
                const reminderBtn = document.createElement('button'); reminderBtn.className='btn btn-sm btn-outline-info'; reminderBtn.type='button'; reminderBtn.innerHTML = '<i class="fa fa-clock"></i> Lembrete';
                wrap.appendChild(header);
                if (!rows || rows.length === 0) { const empty = document.createElement('div'); empty.className='text-muted small'; empty.textContent='Nenhum lembrete para este lead.'; wrap.appendChild(empty); return; }
                const list = document.createElement('div'); list.className = 'list-group list-group-flush';
                rows.slice(0,3).forEach(r=>{
                    const it = document.createElement('div'); it.className='list-group-item py-1 d-flex align-items-start';
                    const left = document.createElement('div'); left.className='small text-muted me-2'; left.style.minWidth='120px'; left.textContent = new Date(r.remind_at).toLocaleString();
                    const mid = document.createElement('div'); mid.className='small text-truncate'; mid.style.maxWidth='220px'; mid.textContent = r.message;
                    const actions = document.createElement('div'); actions.className='ms-auto';
                    const editBtn = document.createElement('button'); editBtn.className='btn btn-sm btn-link p-0'; editBtn.textContent='Editar';
                    editBtn.onclick = ()=> { editReminder(r.id); };
                    actions.appendChild(editBtn);
                    it.appendChild(left); it.appendChild(mid); it.appendChild(actions); list.appendChild(it);
                });
                if (rows.length > 3) { const more = document.createElement('div'); more.className='small text-muted mt-1'; more.textContent='Ver todos em Integração → Lembretes'; list.appendChild(more); }
                wrap.appendChild(list);
            }).catch(err=>{ wrap.innerHTML = '<div class="text-danger small">Erro ao carregar lembretes</div>'; console.error(err); });
    }

    function openReminderModalForLead(leadId){
        document.getElementById('reminderId').value = '';
        document.getElementById('reminderLeadId').value = leadId;
        const msg = document.getElementById('reminderMessage'); if (msg) msg.value='';
        const dateEl = document.getElementById('reminderDate'); if (dateEl) dateEl.value = (new Date()).toISOString().slice(0,10);
        const timeEl = document.getElementById('reminderTime'); if (timeEl) timeEl.value = (new Date()).toTimeString().slice(0,5);
        const sel = document.getElementById('reminderTemplateSelect'); if (sel) sel.value='';
        const saveCb = document.getElementById('saveAsTemplateCheckbox'); if (saveCb) saveCb.checked = false;
        const saveWrap = document.getElementById('saveTemplateNameWrap'); if (saveWrap) saveWrap.style.display = 'none';
        const modalEl = document.getElementById('reminderModal'); if (modalEl) new bootstrap.Modal(modalEl).show();
    }

    function editReminder(reminderId){
        fetch('includes/reminders_api.php?action=get&id=' + encodeURIComponent(reminderId)).then(r=>r.json()).then(r=>{
            if (!r || !r.id) return;
            document.getElementById('reminderId').value = r.id;
            document.getElementById('reminderLeadId').value = r.lead_id;
            const msg = document.getElementById('reminderMessage'); if (msg) msg.value = r.message || '';
            try { const dt = new Date(r.remind_at); document.getElementById('reminderDate').value = dt.toISOString().slice(0,10); document.getElementById('reminderTime').value = dt.toTimeString().slice(0,5); } catch(e){}
            const sel = document.getElementById('reminderTemplateSelect'); if (sel) sel.value = r.template_id || '';
            const modalEl = document.getElementById('reminderModal'); if (modalEl) new bootstrap.Modal(modalEl).show();
        }).catch(err=>console.error(err));
    }

    function populateLeadForm(lead){
        if (!lead) return;
        // close the details panel when opening the edit modal
        closePanel();
        const idEl = F('leadId') || $('#leadId'); if (idEl) idEl.value = lead.id || '';
        const nameEl = F('leadName') || $('#leadName'); if (nameEl) nameEl.value = lead.name || '';
        const phoneEl = F('leadPhone') || $('#leadPhone'); if (phoneEl) phoneEl.value = lead.phone || '';
        const srcEl = F('leadSource') || $('#leadSource'); if (srcEl) srcEl.value = lead.source || '';
        const emailEl = F('leadEmail') || $('#leadEmail'); if (emailEl) emailEl.value = lead.email || '';
        const cpfEl = F('leadCpf') || $('#leadCpf'); if (cpfEl) cpfEl.value = lead.cpf_cnpj || lead.cpf || '';
        const statusEl = F('leadStatus') || $('#leadStatus');
        if (statusEl) {
            if (statusEl.tagName === 'SELECT') {
                if (lead.stage_id && statusEl.querySelector(`option[value="${lead.stage_id}"]`)) {
                    statusEl.value = lead.stage_id;
                } else {
                    // try matching by visible text
                    let matched = false;
                    for (let i=0;i<statusEl.options.length;i++){
                        if (statusEl.options[i].text === (lead.status || '')) { statusEl.selectedIndex = i; matched = true; break; }
                    }
                    if (!matched) statusEl.value = '';
                }
            } else {
                statusEl.value = lead.status || '';
            }
        }
        const stageEl = F('leadStage') || $('#leadStage'); if (stageEl) stageEl.value = lead.stage_id || '';
        const consumoEl = F('leadConsumo') || $('#leadConsumo'); if (consumoEl) consumoEl.value = lead.consumo_cliente || '';
        const estEl = F('leadEstimativa') || $('#leadEstimativa'); if (estEl) estEl.value = lead.estimativa_projeto_kwh || '';
        const notesEl = F('leadNotes') || $('#leadNotes'); if (notesEl) notesEl.value = lead.notes || '';
        // reset file input
        const f = F('leadAnexos') || $('#leadAnexos'); if (f) f.value = '';
        const m = new bootstrap.Modal($('#leadModal'));
        const titleEl = F('leadModalTitle') || $('#leadModalTitle'); if (titleEl) titleEl.textContent = 'Editar Lead';
        m.show();
    }

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
        // initialize view toggle (kanban / grid)
        const toggleBtn = $('#toggleViewBtn');
        if (toggleBtn) {
            // apply saved view
            setViewMode(localStorage.getItem('leadsView') || 'kanban');
            toggleBtn.addEventListener('click', ()=>{
                const next = getViewMode() === 'grid' ? 'kanban' : 'grid';
                setViewMode(next);
                renderAll();
            });
        }
        $('#closeLeadPanel').addEventListener('click', closePanel);
        $('#newLeadBtn').addEventListener('click', ()=>{ 
            const m = new bootstrap.Modal($('#leadModal')); 
            $('#leadModalTitle').textContent='Novo Lead'; 
            $('#leadForm').reset(); 
            const idEl = F('leadId') || $('#lead-id'); 
            if (idEl) idEl.value = '';
            m.show(); 
        });
        
        // Add event listener to clean up backdrop when modal is hidden
        $('#leadModal').addEventListener('hidden.bs.modal', function () {
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(bd => bd.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        });
        
        (F('leadForm') || $('#leadForm')).addEventListener('submit', async (e)=>{
            e.preventDefault();
            console.log('Form submit triggered');
            const idEl = F('leadId') || $('#lead-id');
            const id = idEl ? idEl.value : '';
            console.log('Lead ID:', id);
            // Use FormData to support file uploads (anexos)
            const fd = new FormData();
            const nameValue = (F('leadName')||$('#lead-name')).value || '';
            const emailValue = (F('leadEmail')||$('#lead-email')).value || '';
            const phoneValue = (F('leadPhone')||$('#lead-phone')).value || '';
            const cpfValue = (F('leadCpf')||$('#lead-cpf-cnpj')) ? (F('leadCpf')||$('#lead-cpf-cnpj')).value : '';
            const sourceValue = (F('leadSource')||$('#lead-source')) ? (F('leadSource')||$('#lead-source')).value : 'web';
            const statusEl = (F('leadStatus')||$('#lead-status'));
            const statusValue = statusEl ? statusEl.value : 'Novo';
            const stageVal = (F('leadStage')||$('#lead-stage')) ? (F('leadStage')||$('#lead-stage')).value : '';
            const notesValue = (F('leadNotes')||$('#lead-notes')) ? (F('leadNotes')||$('#lead-notes')).value : '';
            const consumoValue = (F('leadConsumo')||$('#lead-consumo')) ? (F('leadConsumo')||$('#lead-consumo')).value : '';
            const estimativaValue = (F('leadEstimativa')||$('#lead-estimativa-kwh')) ? (F('leadEstimativa')||$('#lead-estimativa-kwh')).value : '';
            
            console.log('Form values:', {nameValue, emailValue, phoneValue, cpfValue, sourceValue, statusValue, stageVal, notesValue, consumoValue, estimativaValue});
            console.log('Status element:', statusEl, 'Status value:', statusValue);
            
            fd.append('name', nameValue);
            fd.append('email', emailValue);
            fd.append('phone', phoneValue);
            fd.append('cpf_cnpj', cpfValue);
            fd.append('source', sourceValue);
            fd.append('status', statusValue);
            if (stageVal) fd.append('stage_id', stageVal);
            else if (statusValue) fd.append('stage_id', statusValue); // Fallback: use status as stage_id if stage not set
            fd.append('notes', notesValue);
            fd.append('consumo_cliente', consumoValue);
            fd.append('estimativa_projeto_kwh', estimativaValue);
            fd.append('action', id? 'update' : 'add');
            if (id) fd.append('id', id);
            // append files
            const filesEl = (F('leadAnexos')||$('#leadAnexos'));
            if (filesEl && filesEl.files && filesEl.files.length) {
                for (let i=0;i<filesEl.files.length;i++) {
                    fd.append('anexos[]', filesEl.files[i]);
                }
            }

            try {
                console.log('Sending request to:', apiBase);
                console.log('Action:', id ? 'update' : 'add', 'ID:', id);
                const res = await fetch(apiBase, { method: 'POST', body: fd });
                console.log('Response status:', res.status);
                const txt = await res.text();
                console.log('Response text:', txt);
                let payload = null;
                try { payload = JSON.parse(txt); } catch(e) { 
                    console.warn('Failed to parse JSON:', e); 
                    payload = null; 
                }
                if (!res.ok || (payload && payload.error)) {
                    const msg = (payload && (payload.error || payload.message)) || txt || 'Erro ao salvar';
                    console.error('Save failed', res.status, msg);
                    return alert('Falha ao salvar: ' + msg);
                }
                console.log('Save successful, reloading leads...');
                try {
                    await fetchLeads();
                } catch (reloadErr) {
                    console.warn('Failed to reload leads, will continue anyway:', reloadErr);
                }
                const modalInst = bootstrap.Modal.getInstance($('#leadModal')) || new bootstrap.Modal($('#leadModal'));
                modalInst.hide();
                // Force remove backdrop if it persists
                setTimeout(() => {
                    const backdrops = document.querySelectorAll('.modal-backdrop');
                    backdrops.forEach(bd => bd.remove());
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                    document.body.style.paddingRight = '';
                }, 300);
            } catch (err) { 
                console.error('Fetch error:', err); 
                alert('Falha ao salvar: ' + (err.message || err)); 
            }
        });

        // reminder form submit (supports create + update)
        const reminderForm = document.getElementById('reminderForm');
        if (reminderForm) {
            // toggle visibility for optional template name field
            const saveCheckbox = document.getElementById('saveAsTemplateCheckbox');
            const saveWrap = document.getElementById('saveTemplateNameWrap');
            if (saveCheckbox && saveWrap) {
                saveCheckbox.addEventListener('change', ()=>{ saveWrap.style.display = saveCheckbox.checked ? 'block' : 'none'; });
            }

            reminderForm.addEventListener('submit', async (e)=>{
                e.preventDefault();
                const reminderId = (document.getElementById('reminderId') || {}).value || '';
                const leadId = (document.getElementById('reminderLeadId') || {}).value;
                const message = (document.getElementById('reminderMessage') || {}).value;
                const date = (document.getElementById('reminderDate') || {}).value;
                const time = (document.getElementById('reminderTime') || {}).value || '00:00';
                if (!leadId || !message || !date) return alert('Preencha data e mensagem');
                const dt = date + ' ' + time;
                try {
                    let templateId = (document.getElementById('reminderTemplateSelect') || {}).value || '';

                    // if user chose to save as template, create it first and use returned id
                    const saveAsTemplate = (document.getElementById('saveAsTemplateCheckbox') || {}).checked;
                    if (saveAsTemplate) {
                        const tplNameInput = document.getElementById('saveTemplateName');
                        let tplName = tplNameInput && tplNameInput.value ? tplNameInput.value.trim() : '';
                        if (!tplName) tplName = message.length > 40 ? message.substring(0,40) + '...' : message.substring(0,40);
                        // compute days offset from today
                        const today = new Date(); today.setHours(0,0,0,0);
                        const target = new Date(date + 'T00:00:00');
                        const msPerDay = 1000*60*60*24;
                        const daysOffset = Math.round((target.getTime() - today.getTime())/msPerDay);
                        const defaultTime = (time ? (time.length===5 ? time + ':00' : time) : '09:00:00');
                        try {
                            const tplBody = new URLSearchParams();
                            tplBody.append('name', tplName);
                            tplBody.append('message', message);
                            tplBody.append('default_days_offset', String(daysOffset));
                            tplBody.append('default_time', defaultTime);
                            tplBody.append('channel', 'in-app');
                            const tplRes = await fetch('includes/reminder_templates_api.php?action=create', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: tplBody.toString() });
                            const tplJson = await tplRes.json();
                            if (tplRes.ok && tplJson && tplJson.id) {
                                templateId = String(tplJson.id);
                                // refresh templates list so select is up-to-date
                                try { await fetchReminderTemplates(); } catch(e){}
                            } else {
                                console.warn('Failed creating template', tplJson);
                                alert('Não foi possível salvar modelo, continuando sem modelo.');
                            }
                        } catch(tplErr) { console.error('Template create error', tplErr); alert('Erro ao criar modelo'); }
                    }

                    const body = new URLSearchParams();
                    if (reminderId) {
                        body.append('action','update'); body.append('id', reminderId);
                        body.append('message', message); body.append('datetime', dt);
                        if (templateId) body.append('template_id', templateId);
                    } else {
                        body.append('action','add'); body.append('lead_id', leadId); body.append('message', message); body.append('datetime', dt);
                        if (templateId) body.append('template_id', templateId);
                    }
                    const res = await fetch('includes/reminders_api.php', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString() });
                    const json = await res.json();
                    if (!res.ok || json.error) return alert('Falha ao salvar lembrete: ' + (json && json.error ? json.error : 'erro'));
                    bootstrap.Modal.getInstance(document.getElementById('reminderModal')).hide();
                    // refresh details panel reminders and global leads
                    try { fetchLeads(); } catch(e){}
                    try { fetchRemindersForLead(leadId); } catch(e){}
                    alert(reminderId ? 'Lembrete atualizado' : 'Lembrete criado');
                } catch (err) { console.error(err); alert('Falha ao salvar lembrete'); }
            });
        }

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

        // close details panel when clicking outside of it
        document.addEventListener('click', (e)=>{
            const panel = $('#leadDetailsPanel'); if (!panel || panel.classList.contains('hidden')) return;
            const modal = document.querySelector('#leadModal');
            // if click is inside panel or inside modal or on a lead card, do nothing
            if (panel.contains(e.target)) return;
            if (modal && modal.contains(e.target)) return;
            if (e.target.closest && e.target.closest('.lead-card')) return;
            closePanel();
        });

        // Horizontal scroll: drag with mouse + scroll wheel
        setupHorizontalScroll();
    }

    function setupHorizontalScroll() {
        const wrap = $('#kanbanWrap');
        if (!wrap) return;

        let isDown = false;
        let startX, scrollLeft;

        // Drag to scroll
        wrap.addEventListener('mousedown', (e) => {
            // Only drag if clicking on the wrap itself (not on cards or inputs)
            if (e.target.closest('.lead-card') || e.target.closest('input') || e.target.closest('button') || e.target.closest('select')) return;
            
            isDown = true;
            wrap.classList.add('dragging');
            wrap.style.cursor = 'grabbing';
            startX = e.pageX - wrap.offsetLeft;
            scrollLeft = wrap.scrollLeft;
            e.preventDefault();
        });

        wrap.addEventListener('mouseleave', () => {
            isDown = false;
            wrap.classList.remove('dragging');
            wrap.style.cursor = '';
        });

        wrap.addEventListener('mouseup', () => {
            isDown = false;
            wrap.classList.remove('dragging');
            wrap.style.cursor = '';
        });

        wrap.addEventListener('mousemove', (e) => {
            if (!isDown) return;
            e.preventDefault();
            const x = e.pageX - wrap.offsetLeft;
            const walk = (x - startX) * 2; // scroll-speed multiplier
            wrap.scrollLeft = scrollLeft - walk;
        });
    }

    // initial
    document.addEventListener('DOMContentLoaded', async ()=>{
        try{
            await fetchStages(); await fetchLeads(); setupDragDrop(); setupHandlers();
            // ensure view mode applied after initial data load
            renderAll();
        }catch(err){ console.error(err); alert('Erro inicial: '+err.message); }
    });

})();
