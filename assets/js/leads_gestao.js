(function(){
    // Enhanced Kanban implementation (totals, inactivity alerts, movement timeline)
    const apiBase = 'includes/leads_api.php';
    let allLeads = [];
    // grid sorting state: `key` is one of 'name','source','status' or null; dir is 1 (asc) or -1 (desc)
    let GRID_SORT_BY = null;
    let GRID_SORT_DIR = 1;
    let GRID_PAGE = 1;
    const GRID_PAGE_SIZE = 10;
    let GRID_FILTERS = {};
    // current filter values
    let CURRENT_SEARCH = '';
    let CURRENT_SCORE_FILTER = '';
    let CURRENT_CIDADE_FILTER = '';
    let CURRENT_STALLED_ONLY = false;

    function getFilteredLeads(){
        let filtered = allLeads.slice(); // copy
        // apply search
        if (CURRENT_SEARCH) {
            const v = CURRENT_SEARCH.toLowerCase();
            filtered = filtered.filter(l => (l.name||'').toLowerCase().includes(v) || (l.client_name||'').toLowerCase().includes(v));
        }
        // apply score filter
        if (CURRENT_SCORE_FILTER) {
            const map = {hot: l=>l.score>=80, warm: l=>l.score>=50 && l.score<80, cold: l=>l.score<50};
            filtered = filtered.filter(map[CURRENT_SCORE_FILTER]);
        }
        // apply cidade filter
        if (CURRENT_CIDADE_FILTER) {
            filtered = filtered.filter(l => l.cidade === CURRENT_CIDADE_FILTER);
        }
        // apply stalled only
        if (CURRENT_STALLED_ONLY) {
            const thresh = Number(localStorage.getItem('stalledDays')||STALLED_DAYS_DEFAULT);
            filtered = filtered.filter(l => leadUpdatedDaysAgo(l) >= thresh);
        }
        return filtered;
    }
    // maintain selected lead ids across pagination/views
    let SELECTED_LEADS = new Set();
    const STALLED_DAYS_DEFAULT = 7;
    // prevent double-submit of the lead form
    let leadFormSubmitting = false;

    function $(sel){return document.querySelector(sel)}
    function $all(sel){return Array.from(document.querySelectorAll(sel))}

    function setPreloaderVisible(visible, text){
        const el = document.getElementById('leadsPreloader');
        if (!el) return;
        const txt = document.getElementById('leadsPreloaderText');
        if (typeof text === 'string' && txt) txt.textContent = text;
        el.classList.toggle('hidden', !visible);
    }
    function showPreloader(text){ setPreloaderVisible(true, text || 'Carregando...'); }
    function hidePreloader(){ setPreloaderVisible(false); }
    function nextPaint(){
        return new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    }

    // Field adapter: maps common field keys to multiple possible modal input IDs
    const FIELD_MAP = {
        leadId: ['#leadId','#lead-id'],
        leadName: ['#leadName','#lead-name'],
        leadCity: ['#leadCity','#lead-city','#lead-city'],
        leadPhone: ['#leadPhone','#lead-phone'],
        leadSource: ['#leadSource','#lead-source'],
        leadEmail: ['#leadEmail','#lead-email'],
        leadCpf: ['#leadCpf','#lead-cpf-cnpj','##lead-cpf'],
        leadStatus: ['#leadStatus','#lead-status'],
        leadStage: ['#leadStage','#lead-stage'],
        leadConsumo: ['#leadConsumo','#lead-consumo'],
        leadEstimativa: ['#leadEstimativa','#lead-estimativa-kwh'],
        leadOrcamento: ['#leadOrcamento','#lead-orcamento'],
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

    function toggleGridSort(key){
        if (!key) return;
        if (GRID_SORT_BY === key) {
            GRID_SORT_DIR = -GRID_SORT_DIR; // toggle
        } else {
            GRID_SORT_BY = key; GRID_SORT_DIR = 1;
        }
        GRID_PAGE = 1; // reset to first page on sort
        // re-render grid view
        try { renderGrid(); } catch(e){ console.warn('toggleGridSort render failed', e); }
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

    // Trash functions
    async function loadTrashedModal(){
        try {
            const res = await fetch(apiBase + '?action=list_trash');
            if (!res.ok) throw new Error('Erro ao carregar lixeira');
            const trashedLeads = await res.json();
            renderTrashedTable(trashedLeads);
        } catch (err) {
            console.error(err);
            const tbody = $('#trashedTableBody');
            if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="text-danger">Erro ao carregar</td></tr>';
        }
    }

    function renderTrashedTable(leads){
        const tbody = $('#trashedTableBody');
        if (!tbody) return;
        tbody.innerHTML = leads.map(l => `
            <tr>
                <td>${escapeText(l.name)}</td>
                <td>${escapeText(l.email || '')}</td>
                <td>${escapeText(l.phone || '')}</td>
                <td>${escapeText(l.status || '')}</td>
                <td>${l.deleted_at ? new Date(l.deleted_at).toLocaleDateString('pt-BR') : ''}</td>
                <td>
                    <button class="btn btn-sm btn-outline-success me-1" onclick="restoreLead(${l.id})">Restaurar</button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deletePermanent(${l.id})">Excluir</button>
                </td>
            </tr>
        `).join('');
    }

    async function restoreLead(id) {
        if (!confirm('Restaurar este lead?')) return;
        try {
            const formData = new FormData();
            formData.append('id', id);
            const res = await fetch(apiBase + '?action=restore', { method: 'POST', body: formData });
            if (res.ok) {
                await loadTrashedModal();
                updateTrashedCount();
            } else {
                alert('Erro ao restaurar lead');
            }
        } catch (err) {
            console.error(err);
            alert('Erro ao restaurar lead');
        }
    }

    async function deletePermanent(id){
        if (!confirm('Excluir permanentemente este lead? Esta ação não pode ser desfeita.')) return;
        try {
            const formData = new FormData();
            formData.append('id', id);
            const res = await fetch(apiBase + '?action=delete_permanent', { method: 'POST', body: formData });
            if (res.ok) {
                await loadTrashedModal();
                updateTrashedCount();
            } else {
                alert('Erro ao excluir lead');
            }
        } catch (err) {
            console.error(err);
            alert('Erro ao excluir lead');
        }
    }

    // Input masking utilities for phone and CPF/CNPJ
    function maskPhoneDigits(digits){
        if (!digits) return '';
        if (digits.length <= 2) return digits;
        if (digits.length <= 6) return `(${digits.slice(0,2)}) ${digits.slice(2)}`;
        if (digits.length <= 10) return `(${digits.slice(0,2)}) ${digits.slice(2,6)}-${digits.slice(6)}`;
        return `(${digits.slice(0,2)}) ${digits.length===11?digits.slice(2,7):digits.slice(2,6)}-${digits.slice(digits.length-4)}`;
    }

    function maskCpfCnpjDigits(digits){
        if (!digits) return '';
        if (digits.length <= 11) {
            // CPF: 000.000.000-00
            const p1 = digits.slice(0,3);
            const p2 = digits.slice(3,6);
            const p3 = digits.slice(6,9);
            const p4 = digits.slice(9,11);
            return [p1,p2,p3].filter(Boolean).join('.') + (p4 ? ('-' + p4) : '');
        } else {
            // CNPJ: 00.000.000/0000-00
            const p1 = digits.slice(0,2);
            const p2 = digits.slice(2,5);
            const p3 = digits.slice(5,8);
            const p4 = digits.slice(8,12);
            const p5 = digits.slice(12,14);
            return [p1,p2,p3].filter(Boolean).join('.') + (p4 ? ('/' + p4) : '') + (p5 ? ('-' + p5) : '');
        }
    }

    // set caret preserving: compute number of digits before original caret, then place caret after same count in formatted value
    function setCaretByDigitIndex(el, formatted, digitsBefore){
        try{
            let di = 0; let pos = 0;
            for (; pos < formatted.length; pos++){
                if (/\d/.test(formatted.charAt(pos))) di++;
                if (di === digitsBefore) { pos++; break; }
            }
            if (di < digitsBefore) pos = formatted.length;
            el.setSelectionRange(pos,pos);
        }catch(e){}
    }

    function formatAndSetCaret(el, formatter){
        const raw = el.value || '';
        const sel = el.selectionStart || raw.length;
        // count digits before cursor
        const left = raw.slice(0, sel);
        const digitsBefore = (left.match(/\d/g) || []).length;
        const digits = (raw.match(/\d/g) || []).join('');
        const formatted = formatter(digits);
        el.value = formatted;
        // place caret
        setCaretByDigitIndex(el, formatted, digitsBefore);
    }

    function attachMaskHandlers(){
        const phoneEls = Array.from(document.querySelectorAll('#lead-phone, #leadPhone'));
        const cpfEls = Array.from(document.querySelectorAll('#lead-cpf-cnpj, #leadCpf'));
        const cityEls = Array.from(document.querySelectorAll('#lead-city, #leadCity'));
        const emailEls = Array.from(document.querySelectorAll('#lead-email, #leadEmail'));
        phoneEls.forEach(el=>{
            try { el.placeholder = '(00) 90000-0000'; el.setAttribute('inputmode','tel'); } catch(e){}
            el.addEventListener('input', (e)=>{ formatAndSetCaret(el, maskPhoneDigits); });
            el.addEventListener('blur', ()=>{ formatAndSetCaret(el, maskPhoneDigits); });
        });
        cpfEls.forEach(el=>{
            el.addEventListener('input', (e)=>{ formatAndSetCaret(el, maskCpfCnpjDigits); });
            el.addEventListener('blur', ()=>{ formatAndSetCaret(el, maskCpfCnpjDigits); });
        });
        // Capitalize first letter of city on blur
        cityEls.forEach(el=>{
            el.addEventListener('blur', (e)=>{
                try{
                    const v = (el.value || '').trim();
                    if (!v) return;
                    el.value = v.charAt(0).toUpperCase() + v.slice(1);
                }catch(e){}
            });
        });
        // Email placeholder and inputmode
        emailEls.forEach(el=>{
            try { el.placeholder = 'seunome@exemplo.com'; el.setAttribute('inputmode','email'); } catch(e){}
        });
    }

    async function fetchLeads(){
        try {
            console.log('Fetching leads from:', apiBase + '?action=list');
            const res = await fetch(apiBase + '?action=list');
            console.log('Fetch leads response status:', res.status);
            if (!res.ok) throw new Error('Falha ao carregar leads');
            const text = await res.text();
            let json;
            try {
                json = JSON.parse(text);
            } catch (e) {
                console.error('Failed to parse JSON:', e);
                console.log('Response text:', text);
                throw e;
            }
            if (json.error) {
                throw new Error(json.error);
            }
            console.log('Leads loaded:', json.length);
            allLeads = json.map(l => ({...l, score: l.score ?? computeScore(l)}));
            // populate cidade filter
            const cidades = [...new Set(allLeads.map(l => l.cidade).filter(c => c && c.trim()))].sort();
            const filterCidade = document.getElementById('filterCidade');
            if (filterCidade) {
                filterCidade.innerHTML = '<option value="">Todas cidades</option>';
                cidades.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c;
                    opt.textContent = c;
                    filterCidade.appendChild(opt);
                });
            }
            // compute presence of SEM STATUS (stage_id === 0 or null)
            const prevSem = SEMSTATUS_PRESENT;
            SEMSTATUS_PRESENT = allLeads.some(x => x.stage_id == null || Number(x.stage_id) === 0);
            // if presence changed and stages already loaded, rebuild columns
            if (prevSem !== SEMSTATUS_PRESENT && STAGES && STAGES.length) {
                try { buildColumns(); } catch(e){}
            }
            renderAll();
            updateTrashedCount();
        } catch (err) {
            console.error('fetchLeads error:', err);
            throw err;
        }
    }

    async function updateTrashedCount(){
        try {
            const res = await fetch(apiBase + '?action=list_trash');
            if (!res.ok) return;
            const trashed = await res.json();
            const count = trashed.length;
            const el = $('#kpiTrashed');
            if (el) el.textContent = count;
        } catch (err) {
            console.warn('Failed to load trashed count', err);
        }
    }

    // --- Anúncios integration: fetch and render in modal ---
    async function fetchAnuncios(){
        try {
            const res = await fetch(apiBase + '?action=list_anuncios');
            if (!res.ok) throw new Error('Falha ao carregar anúncios');
            const rows = await res.json();
            const countEl = document.getElementById('anunciosCount'); if (countEl) countEl.textContent = (rows && rows.length) ? String(rows.length) : '0';
            // also update the kanban column header badge if present
            const colCountEl = document.getElementById('count-anuncios'); if (colCountEl) colCountEl.textContent = (rows && rows.length) ? String(rows.length) : '0';
            // Determine whether anuncios column should be shown
            const prev = ANUNCIOS_PRESENT;
            ANUNCIOS_PRESENT = !!(rows && rows.length);
            // also update KPI small card if present
            try { renderAnunciosKpi(Array.isArray(rows) ? rows : []); } catch(e){}
            // If visibility changed and stages are loaded, rebuild columns and re-render
            if (prev !== ANUNCIOS_PRESENT && STAGES && STAGES.length) {
                try { buildColumns(); renderAll(); } catch(e) { console.warn('Failed to rebuild columns after anuncios change', e); }
            }
            return Array.isArray(rows) ? rows : [];
        } catch(e){ console.warn('fetchAnuncios failed', e); return []; }
    }

    function renderAnunciosKpi(rows){
        const countEl = document.getElementById('anunciosKpiCount');
        if (countEl) countEl.textContent = (rows && rows.length) ? String(rows.length) : '0';
        // we intentionally do NOT show names here; only the count is displayed
    }

    function makeAnuncioItem(an){
        const it = document.createElement('div'); it.className = 'list-group-item d-flex justify-content-between align-items-start anuncio-item';
        it.draggable = true; it.dataset.anuncioId = an.id;
        const left = document.createElement('div'); left.className = 'flex-grow-1';
        const title = document.createElement('div'); title.className = 'fw-bold'; title.textContent = an.name || an.nome || (an.contact_name || '(sem nome)');
        const sub = document.createElement('div'); sub.className = 'small text-muted';
        let subParts = [];
        if (an.source) subParts.push('Fonte: ' + an.source);
        else if (an.cidade) subParts.push('Cidade: ' + an.cidade);
        if (an.phone) subParts.push(an.phone);
        if (an.email) subParts.push(an.email);
        if (an.utm_origem) subParts.push('UTM: ' + an.utm_origem);
        if (an.utm_campanha) subParts.push('Campanha: ' + an.utm_campanha);
        const created = an.created_at || an.data_criacao || an.created || an.createdAt || null;
        if (created) subParts.push('Criado: ' + created);
        sub.textContent = subParts.join(' • ');
        left.appendChild(title); left.appendChild(sub);
        const actions = document.createElement('div'); actions.className = 'd-flex gap-2 align-items-center';
        // Stage select
        const stageSelect = document.createElement('select'); stageSelect.className = 'form-select form-select-sm'; stageSelect.style.width = 'auto'; stageSelect.style.minWidth = '120px';
        stageSelect.innerHTML = '<option value="">-- Escolher Estágio --</option>';
        if (STAGES && STAGES.length) {
            STAGES.forEach(s => {
                const opt = document.createElement('option'); opt.value = s.id; opt.textContent = s.name;
                stageSelect.appendChild(opt);
            });
        }
        actions.appendChild(stageSelect);
        const addBtn = document.createElement('button'); addBtn.className = 'btn btn-sm btn-primary'; addBtn.type='button'; addBtn.textContent = 'Adicionar ao Kanban';
        addBtn.addEventListener('click', async ()=>{
            const selectedStage = stageSelect.value;
            if (!selectedStage) { alert('Por favor, selecione um estágio.'); return; }
            addBtn.disabled = true; addBtn.textContent = 'Adicionando...';
            try {
                const fd = new FormData(); fd.append('action','promote_anuncio'); fd.append('id', an.id); fd.append('stage_id', selectedStage);
                const r = await fetch(apiBase, { method:'POST', body: fd });
                const j = await r.json(); if (!r.ok || j.error) throw new Error(j.error || 'Erro');
                await fetchLeads(); // reload kanban
                it.remove(); // remove the item from the list
                const modalEl = document.getElementById('anunciosModal'); if (modalEl) new bootstrap.Modal(modalEl).hide();
            } catch(err){ console.error(err); alert('Falha ao adicionar: ' + (err.message||err)); }
            addBtn.disabled = false; addBtn.textContent = 'Adicionar ao Kanban';
        });
        actions.appendChild(addBtn);
        it.appendChild(left); it.appendChild(actions);

        it.addEventListener('dragstart', (e)=>{
            e.dataTransfer.setData('text/plain', 'anuncio:' + an.id);
            e.dataTransfer.effectAllowed = 'copyMove';
            it.classList.add('dragging');
        });
        it.addEventListener('dragend', ()=> it.classList.remove('dragging'));
        return it;
    }

    function makeAnuncioCard(an){
        const el = document.createElement('div'); el.className = 'lead-card anuncio-card'; el.draggable = true; el.dataset.anuncioId = an.id;
        // Minimal card: title + source + badge
        const head = document.createElement('div'); head.className = 'd-flex align-items-center justify-content-between';
        const left = document.createElement('div'); left.className = 'd-flex align-items-center';
        const title = document.createElement('div'); title.className = 'title'; title.textContent = an.name || an.nome || an.contact_name || '(sem nome)';
        left.appendChild(title);
        head.appendChild(left);
        const meta = document.createElement('div'); meta.className = 'small text-muted ms-2'; meta.textContent = an.source || (an.cidade?an.cidade:'');
        const badge = document.createElement('span'); badge.className = 'badge bg-secondary ms-2'; badge.textContent = '';
        head.appendChild(meta);
        el.appendChild(head);
        const sub = document.createElement('div'); sub.className = 'small text-muted mt-1';
        let subLines = [];
        if (an.phone) subLines.push(an.phone);
        if (an.email) subLines.push(an.email);
        if (an.utm_origem) subLines.push('UTM: ' + an.utm_origem);
        if (an.utm_campanha) subLines.push('Campanha: ' + an.utm_campanha);
        const created = an.created_at || an.data_criacao || an.created || an.createdAt || null;
        if (created) subLines.push('Criado: ' + created);
        sub.textContent = subLines.join(' • ');
        el.appendChild(sub);

        el.addEventListener('dragstart', (e)=>{ e.dataTransfer.setData('text/plain', 'anuncio:' + an.id); e.dataTransfer.effectAllowed = 'copyMove'; el.classList.add('dragging'); });
        el.addEventListener('dragend', ()=> el.classList.remove('dragging'));
        // click to open modal visualizar
        el.addEventListener('click', ()=> showAnunciosModal());
        return el;
    }

    async function showAnunciosModal(){
        const modalEl = document.getElementById('anunciosModal'); if (!modalEl) return;
        const wrap = document.getElementById('anunciosList'); if (!wrap) return;
        wrap.innerHTML = '<div class="text-muted small">Carregando...</div>';
        const rows = await fetchAnuncios();
        wrap.innerHTML = '';
        if (!rows || rows.length === 0) { wrap.innerHTML = '<div class="text-muted small">Nenhum lead de anúncio encontrado.</div>'; }
        rows.forEach(r=> wrap.appendChild(makeAnuncioItem(r)));
        // show modal
        const m = new bootstrap.Modal(modalEl); m.show();
        // ensure close buttons work
        setTimeout(() => {
            const closeBtns = modalEl.querySelectorAll('[data-bs-dismiss="modal"], .btn-close');
            closeBtns.forEach(btn => {
                btn.addEventListener('click', () => m.hide());
            });
        }, 100);
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
    let APP_APPEARANCE = null;

    async function loadAppearance(){
        if (APP_APPEARANCE !== null) return APP_APPEARANCE;
        try {
            const r = await fetch('api/get_appearance.php');
            if (!r.ok) return null;
            const j = await r.json();
            APP_APPEARANCE = j && j.appearance ? j.appearance : {};
            return APP_APPEARANCE;
        } catch(e){ return null; }
    }

    function applyLeadModalPrimaryColor(color){
        if (!color) return;
        try {
            const modalEl = document.getElementById('leadModal');
            if (!modalEl) return;
            const header = modalEl.querySelector('.modal-header');
            if (header) {
                // remove bootstrap light bg which may override visuals
                try { header.classList.remove('bg-light'); } catch(e){}
                header.style.backgroundColor = color;
                // choose readable text color
                try {
                    const rgb = color.replace('#','');
                    let r,g,b;
                    if (rgb.length === 3) { r = parseInt(rgb[0]+rgb[0],16); g = parseInt(rgb[1]+rgb[1],16); b = parseInt(rgb[2]+rgb[2],16); }
                    else { r = parseInt(rgb.slice(0,2),16); g = parseInt(rgb.slice(2,4),16); b = parseInt(rgb.slice(4,6),16); }
                    const brightness = (r*299 + g*587 + b*114) / 1000;
                    header.style.color = brightness > 125 ? '#000' : '#fff';
                    // also set bottom border to a slightly darker shade for separation
                    try { header.style.borderBottom = '1px solid ' + color; } catch(e){}
                } catch(e) { header.style.color = '#fff'; }
            }
            // set border color for inputs inside the modal
            const inputs = modalEl.querySelectorAll('input, textarea, select');
            inputs.forEach(i=>{ try { i.style.borderColor = color; } catch(e){} });
        } catch(e){}
    }
    // Statuses (separate table) - UI should load these independently from stages
    let STATUSES = [];
    
    // Helper function to get stage name by stage_id
    function getStageName(stageId) {
        if (!stageId) return '';
        const stage = STAGES.find(s => String(s.id) === String(stageId));
        return stage ? stage.name : '';
    }
    // whether the Anúncios column should be shown (only when there are rows in leads_anuncios)
    let ANUNCIOS_PRESENT = false;
    // Sem Status column: present when there are leads with stage_id === 0
    let SEMSTATUS_PRESENT = false;
    // user preference whether to show Sem Status column (persisted)
    let SEMSTATUS_SHOWN = (localStorage.getItem('showSemStatus') !== '0');
    // user preference whether to show Anuncios column (persisted)
    let ANUNCIOS_SHOWN = (localStorage.getItem('showAnuncios') !== '0');
    async function fetchStages(){
        try{
            const res = await fetch('includes/funil_stages_api.php?action=list'); if (!res.ok) throw new Error('Falha ao carregar estágios');
            const json = await res.json();
            STAGES = json.map(s => ({ id: String(s.id), name: s.name || s.stage_name || 'Sem nome', color: s.color || s.stage_color || '#6c757d', card_color: s.card_color || null, include_in_forecast: (typeof s.include_in_forecast !== 'undefined') ? Number(s.include_in_forecast) : ((typeof s.include_in_pipeline !== 'undefined') ? Number(s.include_in_pipeline) : 1), final_type: s.final_type || null, allow_project_creation: s.allow_project_creation || 0 }));
            buildColumns();
            populateStageSelect();
            // also refresh status select so it reflects STAGES (status will store stage names)
            try { populateStatusSelect(); } catch(e){}
        } catch (e) {
            console.error(e);
            STAGES = [{ id: '0', name: 'Novo', color:'#6c757d', card_color: null }];
            buildColumns();
            populateStageSelect();
            try { populateStatusSelect(); } catch(e){}
        }
    }

    async function fetchStatuses(){
        try{
            const res = await fetch('includes/statuses_api.php?action=list'); if (!res.ok) throw new Error('Falha ao carregar status');
            const json = await res.json();
            STATUSES = Array.isArray(json) ? json.map(s => ({ id: String(s.id), name: s.name, user_id: s.user_id ?? null })) : [];
            populateStatusSelect();
        } catch (e) {
            console.error('fetchStatuses failed', e);
            STATUSES = [{ id: '0', name: 'Novo' }];
            populateStatusSelect();
        }
    }

    function populateStatusSelect(){
        const sel = document.querySelector('#lead-status') || F('leadStatus') || document.querySelector('#leadStatus');
        if (!sel) return;
        // clear only options previously added as 'status' or everything (fallback)
        Array.from(sel.options).forEach(opt => opt.remove());
        // Use STAGES names as the values for the status select (no fallback to STATUSES)
        sel.innerHTML = '<option value="">-- Selecionar --</option>';
        if (STAGES && STAGES.length) {
            STAGES.forEach(s=>{
                const o = document.createElement('option'); o.value = s.name; o.textContent = s.name; o.dataset.source = 'stage'; sel.appendChild(o);
            });
        }
    }

    function populateStageSelect(){
        const sel = document.querySelector('#lead-stage') || F('leadStage') || document.querySelector('#leadStage');
        if (!sel) return;
        // preserve placeholder then remove previous stage options
        sel.innerHTML = '<option value="">-- Escolher estágio --</option>';
        STAGES.forEach(s=>{
            const o = document.createElement('option'); o.value = s.id; o.textContent = s.name; o.dataset.source = 'stage'; sel.appendChild(o);
        });

        // update Sem Status count if present
        try {
            const semCol = document.getElementById('col-sem_status');
            const semCountEl = document.getElementById('count-sem_status');
            if (semCountEl) semCountEl.textContent = (semCol ? String((semCol.children || []).length) : '0');
        } catch(e){ }
        // no cleanup here: status select should reflect STAGES when available
    }

    // Status manager UI functions
    function renderStatusList() {
        const wrap = document.getElementById('statusList'); if (!wrap) return;
        wrap.innerHTML = '';
        STATUSES.forEach(s => {
            const item = document.createElement('div'); item.className = 'list-group-item d-flex align-items-center';
            const nameDiv = document.createElement('div'); nameDiv.className = 'flex-grow-1'; nameDiv.textContent = s.name;
            const actions = document.createElement('div'); actions.className = 'btn-group btn-group-sm';
            const editBtn = document.createElement('button'); editBtn.className = 'btn btn-outline-primary'; editBtn.textContent = 'Editar';
            const delBtn = document.createElement('button'); delBtn.className = 'btn btn-outline-danger'; delBtn.textContent = 'Excluir';
            editBtn.addEventListener('click', ()=>{ const newName = prompt('Nome do status', s.name); if (newName && newName.trim()!=='' && newName.trim()!==s.name) { updateStatusEntry(s.id, newName.trim()); } });
            delBtn.addEventListener('click', ()=>{ if (!confirm('Excluir este status?')) return; deleteStatusEntry(s.id); });
            actions.appendChild(editBtn); actions.appendChild(delBtn);
            item.appendChild(nameDiv); item.appendChild(actions); wrap.appendChild(item);
        });
    }

    async function addStatusEntry(name){
        try{
            const body = new URLSearchParams(); body.append('action','add'); body.append('name', name);
            const res = await fetch('includes/statuses_api.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString() });
            const json = await res.json(); if (!res.ok || json.error) throw new Error(json.error || 'Erro');
            await fetchStatuses(); renderStatusList();
            alert('Status adicionado');
        } catch(e){ alert('Falha ao adicionar status: ' + (e.message||e)); }
    }

    async function updateStatusEntry(id, name){
        try{
            const body = new URLSearchParams(); body.append('action','update'); body.append('id', id); body.append('name', name);
            const res = await fetch('includes/statuses_api.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString() });
            const json = await res.json(); if (!res.ok || json.error) throw new Error(json.error || 'Erro');
            await fetchStatuses(); renderStatusList(); populateStatusSelect();
            alert('Status atualizado');
        } catch(e){ alert('Falha ao atualizar status: ' + (e.message||e)); }
    }

    async function deleteStatusEntry(id){
        try{
            const body = new URLSearchParams(); body.append('action','delete'); body.append('id', id);
            const res = await fetch('includes/statuses_api.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString() });
            const json = await res.json(); if (!res.ok || json.error) throw new Error(json.error || 'Erro');
            await fetchStatuses(); renderStatusList(); populateStatusSelect();
            alert('Status excluído');
        } catch(e){ alert('Falha ao excluir status: ' + (e.message||e)); }
    }

    async function createProjectFromLead(leadId, leadName){
        try {
            console.log('createProjectFromLead - leadId:', leadId, 'type:', typeof leadId);
            
            if (!leadId) {
                alert('Lead ID não encontrado. Não é possível criar o projeto.');
                return;
            }
            
            const projectName = prompt('Nome do projeto:', leadName || '');
            if (!projectName || projectName.trim() === '') return;
            
            const formData = new FormData();
            formData.append('client_name', projectName.trim());
            formData.append('status', 'Prospecção');
            formData.append('lead_id', String(leadId));
            
            console.log('Enviando FormData - lead_id:', formData.get('lead_id'));
            
            const res = await fetch('api/add_project.php', { method: 'POST', body: formData });
            const json = await res.json();
            
            console.log('Resposta da API:', json);
            
            if (json.success) {
                alert('Projeto "' + projectName + '" criado com sucesso!');
                // Optionally refresh the page or update UI
                try { await fetchLeads(); } catch(e) {}
            } else {
                alert('Erro ao criar projeto: ' + (json.message || 'Erro desconhecido'));
            }
        } catch(e) {
            console.error('Erro ao criar projeto:', e);
            alert('Falha ao criar projeto: ' + (e.message || e));
        }
    }

    function buildColumns(){
        const wrap = document.getElementById('kanbanWrap'); if(!wrap) return;
        wrap.innerHTML = '';
        // Insert Sem Status column on the LEFT if present and user enabled
        if (SEMSTATUS_PRESENT && SEMSTATUS_SHOWN) {
            try {
                const ssWrap = document.createElement('div'); ssWrap.className = 'kanban-column'; ssWrap.dataset.stageId = 'sem_status'; ssWrap.dataset.stageName = 'Sem Status';
                ssWrap.dataset.color = '#6c757d';
                const ssHeader = document.createElement('div'); ssHeader.className = 'kanban-header';
                const ssTitle = document.createElement('span'); ssTitle.className = 'kanban-title'; ssTitle.textContent = 'Sem Status';
                const ssCount = document.createElement('span'); ssCount.className = 'badge bg-light text-muted ms-2'; ssCount.id = 'count-sem_status'; ssCount.textContent = '0';
                ssHeader.appendChild(ssTitle); ssHeader.appendChild(ssCount);
                const ssContent = document.createElement('div'); ssContent.className = 'column-content'; ssContent.id = 'col-sem_status';
                ssWrap.appendChild(ssHeader); ssWrap.appendChild(ssContent);
                wrap.appendChild(ssWrap);
            } catch(e){ console.warn('failed creating Sem Status column', e); }
        }
        // Insert Anúncios column next (if present and user enabled)
        if (ANUNCIOS_PRESENT && ANUNCIOS_SHOWN) {
            try {
                const anWrap = document.createElement('div'); anWrap.className = 'kanban-column'; anWrap.dataset.stageId = 'anuncios'; anWrap.dataset.stageName = 'Anúncios';
                anWrap.dataset.color = '#0d6efd';
                const anHeader = document.createElement('div'); anHeader.className = 'kanban-header';
                const anTitle = document.createElement('span'); anTitle.className = 'kanban-title'; anTitle.textContent = 'Anúncios';
                const anCount = document.createElement('span'); anCount.className = 'badge bg-light text-muted ms-2'; anCount.id = 'count-anuncios'; anCount.textContent = '0';
                anHeader.appendChild(anTitle); anHeader.appendChild(anCount);
                const anContent = document.createElement('div'); anContent.className = 'column-content'; anContent.id = 'col-anuncios';
                anWrap.appendChild(anHeader); anWrap.appendChild(anContent);
                wrap.appendChild(anWrap);
            } catch(e){ console.warn('failed creating anuncios column', e); }
        }
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
        // Insert 'Sem Status' column for leads with stage_id === 0 if present and user allows it
        if (SEMSTATUS_PRESENT && SEMSTATUS_SHOWN) {
            try {
                const ssWrap = document.createElement('div'); ssWrap.className = 'kanban-column'; ssWrap.dataset.stageId = 'sem_status'; ssWrap.dataset.stageName = 'Sem Status';
                ssWrap.dataset.color = '#6c757d';
                const ssHeader = document.createElement('div'); ssHeader.className = 'kanban-header';
                const ssTitle = document.createElement('span'); ssTitle.className = 'kanban-title'; ssTitle.textContent = 'Sem Status';
                const ssCount = document.createElement('span'); ssCount.className = 'badge bg-light text-muted ms-2'; ssCount.id = 'count-sem_status'; ssCount.textContent = '0';
                ssHeader.appendChild(ssTitle); ssHeader.appendChild(ssCount);
                const ssContent = document.createElement('div'); ssContent.className = 'column-content'; ssContent.id = 'col-sem_status';
                ssWrap.appendChild(ssHeader); ssWrap.appendChild(ssContent);
                // append at the end (after stages)
                wrap.appendChild(ssWrap);
            } catch(e){ console.warn('failed creating Sem Status column', e); }
        }
        const loading = document.getElementById('kanbanLoading'); if (loading) loading.remove();
        
        // Set up top scrollbar synchronization
        syncTopScrollbar();
    }

    function syncTopScrollbar() {
        const wrap = document.getElementById('kanbanWrap');
        const topScrollbar = document.getElementById('topScrollbar');
        const topContent = document.getElementById('topScrollbarContent');
        
        if (!wrap || !topScrollbar || !topContent) return;
        
        // Set the width of the top scrollbar content to match kanban content
        const updateTopScrollbarWidth = () => {
            try { topContent.style.width = wrap.scrollWidth + 'px'; } catch(e) {}
        };

        // Always update width when called (content may have changed)
        updateTopScrollbarWidth();

        // Avoid duplicating observers/listeners on rebuilds
        if (topScrollbar.dataset.synced === '1') return;
        topScrollbar.dataset.synced = '1';

        // Update on resize (best effort)
        if (typeof ResizeObserver !== 'undefined') {
            try {
                const resizeObserver = new ResizeObserver(updateTopScrollbarWidth);
                resizeObserver.observe(wrap);
            } catch(e) {}
        } else {
            window.addEventListener('resize', updateTopScrollbarWidth);
        }

        // Sync scroll positions
        let syncingTop = false;
        let syncingBottom = false;

        topScrollbar.addEventListener('scroll', () => {
            if (syncingBottom) return;
            syncingTop = true;
            wrap.scrollLeft = topScrollbar.scrollLeft;
            setTimeout(() => syncingTop = false, 10);
        });

        wrap.addEventListener('scroll', () => {
            if (syncingTop) return;
            syncingBottom = true;
            topScrollbar.scrollLeft = wrap.scrollLeft;
            setTimeout(() => syncingBottom = false, 10);
        });
    }

    function toCurrency(v){ return 'R$ ' + (Number(v)||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); }

    function formatDateBR(dt){
        if (!dt) return '—';
        try { const d = new Date(String(dt).replace(' ', 'T')); return d.toLocaleDateString('pt-BR'); } catch(e){ return String(dt); }
    }
    function formatDateCompact(dt){
        if (!dt) return '—';
        try {
            const d = new Date(String(dt).replace(' ', 'T'));
            const day = String(d.getDate()).padStart(2,'0');
            const mon = String(d.getMonth()+1).padStart(2,'0');
            const now = new Date();
            if (d.getFullYear() === now.getFullYear()) return `${day}/${mon}`;
            return `${day}/${mon}/${String(d.getFullYear()).slice(-2)}`;
        } catch(e){ return formatDateBR(dt); }
    }
    function daysSince(dt){ if (!dt) return null; try { const d = new Date(String(dt).replace(' ', 'T')); const diff = Date.now() - d.getTime(); return Math.floor(diff / (1000*60*60*24)); } catch(e){ return null; } }

    function renderKpis(){
        const active = allLeads.filter(l=>!['Perdido','Ganhou'].includes(l.status));
        const hot = allLeads.filter(l=>l.score>=80).length;
        // totalValue: sum only leads whose stage is configured to be included in forecast/pipeline
        let totalValue = 0;
        allLeads.forEach(l=>{
            try {
                const val = parseFloat(l.orcamento_value||0) || 0;
                // determine stage object for this lead
                let stageObj = null;
                if (typeof l.stage_id !== 'undefined' && l.stage_id !== null && String(l.stage_id) !== '0' && STAGES && STAGES.length) {
                    stageObj = STAGES.find(s => String(s.id) === String(l.stage_id));
                }
                if (!stageObj && STAGES && STAGES.length) {
                    // fallback: match by status name
                    stageObj = STAGES.find(s => s.name === (l.status || ''));
                }
                // include when stageObj indicates inclusion; default to include (for backward compatibility)
                const include = stageObj ? (Number(stageObj.include_in_forecast || 0) === 1) : true;
                if (include) totalValue += val;
            } catch(e) { /* ignore lead parse errors */ }
        });
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

    function updateBulkDeleteVisibility(){
        const selected = getSelectedLeadIds();
        const btn = $('#bulkDeleteBtn');
        if (btn) btn.classList.toggle('d-none', selected.length === 0);
        const uncheckBtn = $('#bulkUncheckBtn');
        if (uncheckBtn) uncheckBtn.classList.toggle('d-none', selected.length <= 1);
        const printBtn = $('#printSelectedBtn');
        if (printBtn) printBtn.classList.toggle('d-none', selected.length === 0);
        // update header select-all checkbox state for visible rows
        try {
            const selectAll = document.getElementById('selectAllVisible');
            if (selectAll) {
                const visible = Array.from(document.querySelectorAll('#leadsTableContainer tbody .lead-select'));
                const checkedCount = visible.filter(c => c.checked).length;
                if (visible.length === 0) { selectAll.checked = false; selectAll.indeterminate = false; }
                else if (checkedCount === 0) { selectAll.checked = false; selectAll.indeterminate = false; }
                else if (checkedCount === visible.length) { selectAll.checked = true; selectAll.indeterminate = false; }
                else { selectAll.checked = false; selectAll.indeterminate = true; }
            }
        } catch(e) { /* ignore */ }
        // update selected count indicator in list view if present
        try {
            const listCount = document.getElementById('listSelectedCount');
            if (listCount) listCount.textContent = 'Selecionados: ' + String(selected.length);
        } catch(e) {}
    }

    function makeCard(lead, stageObj){
        const el = document.createElement('div'); el.className='lead-card'; el.draggable = true; el.dataset.id = lead.id;
        // selection checkbox for bulk actions
        const chk = document.createElement('input'); chk.type='checkbox'; chk.className = 'lead-select me-2'; chk.title = 'Selecionar para ações em massa';
        try { chk.checked = SELECTED_LEADS.has(String(lead.id)); } catch(e){}
        chk.addEventListener('change', (e)=>{
            e.stopPropagation();
            try {
                if (chk.checked) SELECTED_LEADS.add(String(lead.id)); else SELECTED_LEADS.delete(String(lead.id));
            } catch(e){}
            el.classList.toggle('selected', chk.checked);
            // set border-left to column color when selected, but preserve original left border
            const colWrap = el.closest('.kanban-column');
            const colColor = colWrap?.dataset?.color || '#6c757d';
            if (chk.checked) {
                if (!el.dataset._selectedPrevBorder) el.dataset._selectedPrevBorder = el.style.borderLeft || '';
                try { el.style.setProperty('--selected-color', colColor); } catch(e) {}
                try { el.style.setProperty('border-left', '8px solid ' + colColor, 'important'); } catch(e) { el.style.borderLeft = '8px solid ' + colColor; }
            } else {
                const restore = el.dataset._selectedPrevBorder || el.dataset.originalBorder || '7px solid transparent';
                try { el.style.setProperty('border-left', restore, 'important'); } catch(e) { el.style.borderLeft = restore; }
                try { el.style.removeProperty('--selected-color'); } catch(e) {}
                delete el.dataset._selectedPrevBorder;
            }
            updateBulkDeleteVisibility();
        });
        // prevent clicks/keyboard on the checkbox from bubbling and triggering card click
        chk.addEventListener('click', (e)=>{ e.stopPropagation(); });
        chk.addEventListener('keydown', (e)=>{ if (e.key === ' ' || e.key === 'Spacebar' || e.key === 'Enter') e.stopPropagation(); });
        const head = document.createElement('div'); head.className = 'd-flex align-items-center justify-content-between';
        const left = document.createElement('div'); left.className = 'd-flex align-items-center'; left.appendChild(chk);
        const title = document.createElement('div'); title.className='title'; title.textContent = escapeText(lead.name || '(sem nome)');
        left.appendChild(title);
        
        // Add create project button in the header if stage allows it
        console.log('makeCard - Lead:', lead.id, lead.name, 'StageObj:', stageObj);
        if (stageObj) {
            console.log('==> Stage:', stageObj.name, 'ID:', stageObj.id, 'allow_project_creation:', stageObj.allow_project_creation, 'Type:', typeof stageObj.allow_project_creation);
            // Check for truthy value (1, '1', true)
            if (stageObj.allow_project_creation == 1 || stageObj.allow_project_creation === true || stageObj.allow_project_creation === '1') {
                console.log('✓ CRIANDO BOTÃO DE PROJETO!');
                const createProjBtn = document.createElement('button');
                createProjBtn.className = 'btn btn-sm btn-success ms-2';
                createProjBtn.innerHTML = '<i class="fa fa-plus" style="font-size: 8px; margin-right: 2px;"></i><span style="font-size: 9px;">Projeto</span>';
                createProjBtn.title = 'Criar Projeto';
                createProjBtn.type = 'button';
                createProjBtn.style.padding = '1px 4px';
                createProjBtn.style.fontSize = '9px';
                createProjBtn.style.display = 'inline-flex';
                createProjBtn.style.alignItems = 'center';
                createProjBtn.style.height = '18px';
                createProjBtn.style.lineHeight = '1';
                createProjBtn.style.backgroundColor = '#28a745';
                createProjBtn.style.color = '#fff';
                createProjBtn.style.border = 'none';
                createProjBtn.style.borderRadius = '2px';
                createProjBtn.style.whiteSpace = 'nowrap';
                createProjBtn.style.gap = '2px';
                createProjBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    console.log('Botão clicado! Lead completo:', lead);
                    console.log('Lead ID:', lead.id, 'Type:', typeof lead.id);
                    console.log('Nome:', lead.name);
                    const leadIdToUse = lead.id || lead.lead_id || lead.ID;
                    if (!leadIdToUse) {
                        alert('ID do lead não encontrado. Não é possível criar projeto.');
                        return;
                    }
                    createProjectFromLead(leadIdToUse, lead.name);
                });
                left.appendChild(createProjBtn);
                console.log('Botão adicionado ao card!');
            } else {
                console.log('✗ Condição não atendida - valor:', stageObj.allow_project_creation);
            }
        } else {
            console.log('✗ stageObj é null/undefined');
        }
        
        head.appendChild(left);

        const company = document.createElement('div'); company.className='lead-meta'; company.textContent = 'Fonte: ' + (lead.source || lead.client_name || lead.company || '—');
        const meta = document.createElement('div'); meta.className='lead-meta';
        const value = document.createElement('span'); value.className='lead-value'; value.textContent = toCurrency(lead.orcamento_value || 0);
        const owner = document.createElement('span'); owner.className='lead-owner'; owner.textContent = lead.responsavel || '';
        const score = document.createElement('span'); score.className = 'badge-score ' + (lead.score>=80?'hot':(lead.score>=50?'warm':'cold')); score.textContent = lead.score;
        meta.appendChild(value); meta.appendChild(owner); meta.appendChild(score);
        // Add paperclip download icon to the right of the badge-score when an attachment exists
        if (lead.anexos_files && lead.anexos_files.length) {
            const filenames = lead.anexos_files.map(f => f.filename).join(', ');
            const first = lead.anexos_files[0];
            const clipLink = document.createElement('a');
            clipLink.href = 'includes/leads_api.php?action=download_anexo&id=' + encodeURIComponent(lead.id) + '&file_id=' + encodeURIComponent(first.attachment_id);
            clipLink.target = '_blank'; clipLink.rel = 'noopener';
            const clip = document.createElement('i'); clip.className = 'fa fa-paperclip ms-2 text-muted small lead-clip-icon';
            clip.title = filenames || 'Anexo disponível';
            clipLink.appendChild(clip);
            meta.appendChild(clipLink);
        } else if (lead.anexos_filename) {
            const clipLink = document.createElement('a');
            clipLink.href = 'includes/leads_api.php?action=download_anexo&id=' + encodeURIComponent(lead.id);
            clipLink.target = '_blank'; clipLink.rel = 'noopener';
            const clip = document.createElement('i'); clip.className = 'fa fa-paperclip ms-2 text-muted small lead-clip-icon';
            clip.title = lead.anexos_filename || 'Anexo disponível';
            clipLink.appendChild(clip);
            meta.appendChild(clipLink);
        }
        el.appendChild(head); el.appendChild(company); el.appendChild(meta);

        // created date and days active
        const createdRaw = (lead.data_inicio || lead.created_at || lead.createdAt || lead.created);
        const createdText = document.body.classList.contains('kanban-compact') ? formatDateCompact(createdRaw) : formatDateBR(createdRaw);
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
        const semStatusBtn = document.getElementById('toggleSemStatusBtn');
        const anunciosBtn = document.getElementById('toggleAnunciosBtn');
        // support two modes: 'kanban' and 'list' (table)
        if (mode === 'kanban') {
            if (kanban) kanban.classList.remove('d-none');
            if (list) list.classList.add('d-none');
            if (semStatusBtn) semStatusBtn.classList.remove('d-none');
            if (anunciosBtn) anunciosBtn.classList.remove('d-none');
            if (btn) btn.innerHTML = '<i class="fa fa-list"></i>';
        } else if (mode === 'list') {
            GRID_PAGE = 1;
            if (kanban) kanban.classList.add('d-none');
            if (list) list.classList.remove('d-none');
            if (semStatusBtn) semStatusBtn.classList.add('d-none');
            if (anunciosBtn) anunciosBtn.classList.add('d-none');
            if (btn) btn.innerHTML = '<i class="fa fa-th-list"></i>';
        }
    }

    function renderGrid(){
        const container = document.getElementById('leadsTableContainer'); if (!container) return;
        container.innerHTML = '';
        const table = document.createElement('table'); table.className = 'table table-sm table-hover';
            const thead = document.createElement('thead');
            // build header with clickable sortable columns (compact arrow indicator)
            const headerRow = document.createElement('tr');
            const thEmpty = document.createElement('th');
            // Select-all checkbox for visible rows
            try {
                const selectAll = document.createElement('input');
                selectAll.type = 'checkbox';
                selectAll.id = 'selectAllVisible';
                selectAll.title = 'Selecionar todas as linhas visíveis';
                selectAll.className = 'form-check-input';
                // Make checkbox more visible: larger + accent color + slight border
                try {
                    selectAll.style.accentColor = '#000000';
                } catch(e){}
                selectAll.style.width = '18px';
                selectAll.style.height = '18px';
                selectAll.style.opacity = '1';
                selectAll.style.border = '1px solid rgba(0,0,0,0.25)';
                selectAll.style.marginTop = '0.12rem';
                selectAll.classList.add('me-2');
                selectAll.addEventListener('change', (e) => {
                    const checked = !!selectAll.checked;
                    // only affect visible rows in this table (current page)
                    const visibleChecks = container.querySelectorAll('tbody .lead-select');
                    visibleChecks.forEach(chk => {
                        try {
                            chk.checked = checked;
                            // toggle row selected class if inside a tr
                            const tr = chk.closest('tr'); if (tr) tr.classList.toggle('selected', checked);
                            chk.dispatchEvent(new Event('change', { bubbles: true }));
                        } catch(e){}
                    });
                    updateBulkDeleteVisibility();
                });
                thEmpty.appendChild(selectAll);
            } catch(e) { /* ignore UI add errors */ }
            headerRow.appendChild(thEmpty);
            // create sortable header with filter dropdown trigger
            function makeSortableHeader(key, label) {
                const th = document.createElement('th'); th.style.cursor = 'pointer'; th.style.position = 'relative';
                const span = document.createElement('span'); span.textContent = label; span.className = 'sortable-col-label';
                const ind = document.createElement('span'); ind.className = 'sort-indicator ms-1'; ind.style.fontSize = '0.8rem'; ind.style.opacity = '0.8';
                if (GRID_SORT_BY === key) ind.textContent = GRID_SORT_DIR === 1 ? '▲' : '▼';
                th.appendChild(span); th.appendChild(ind);
                th.addEventListener('click', (e)=>{ e.stopPropagation(); toggleGridSort(key); });

                const caret = document.createElement('span');
                caret.className = 'ms-2 filter-caret filter-icon half';
                caret.style.cursor = 'pointer';
                caret.title = 'Filtro';
                caret.innerHTML = '<i class="fa-solid fa-filter" aria-hidden="true"></i>';
                if (GRID_FILTERS && GRID_FILTERS[key]) caret.classList.add('active');
                caret.addEventListener('click', (e)=>{ e.stopPropagation(); openHeaderFilter(th, key); });
                th.appendChild(caret);
                return th;
            }

            // open a small filter popup for a header
            function openHeaderFilter(th, key) {
                // close existing
                const existing = document.querySelector('.header-filter-popup'); if (existing) existing.remove();
                const popup = document.createElement('div'); popup.className = 'header-filter-popup card p-2 shadow';
                popup.style.position = 'absolute'; popup.style.zIndex = 2000; popup.style.minWidth = '200px';
                // build input depending on key
                if (key === 'status') {
                    const sel = document.createElement('select'); sel.className = 'form-select form-select-sm';
                    const optAll = document.createElement('option'); optAll.value = ''; optAll.textContent = 'Todos'; sel.appendChild(optAll);
                    const uniqueStatuses = [...new Set(allLeads.map(l=>getStageName(l.stage_id)).filter(s=>s))];
                    uniqueStatuses.forEach(s => { const o = document.createElement('option'); o.value = s; o.textContent = s; if ((GRID_FILTERS[key]||'') === s) o.selected = true; sel.appendChild(o); });
                    popup.appendChild(sel);
                    const btns = document.createElement('div'); btns.className = 'd-flex gap-2 mt-2';
                    const apply = document.createElement('button'); apply.className = 'btn btn-sm btn-primary flex-grow-1'; apply.textContent = 'Aplicar';
                    const clear = document.createElement('button'); clear.className = 'btn btn-sm btn-outline-secondary flex-grow-1'; clear.textContent = 'Limpar';
                    apply.addEventListener('click', ()=>{ GRID_FILTERS[key] = sel.value; GRID_PAGE = 1; popup.remove(); renderGrid(); });
                    clear.addEventListener('click', ()=>{ delete GRID_FILTERS[key]; GRID_PAGE = 1; popup.remove(); renderGrid(); });
                    btns.appendChild(apply); btns.appendChild(clear); popup.appendChild(btns);
                } else if (key === 'criado' || key === 'ultimo_contato') {
                    const from = document.createElement('input'); from.type = 'date'; from.className = 'form-control form-control-sm'; from.placeholder = 'De';
                    const to = document.createElement('input'); to.type = 'date'; to.className = 'form-control form-control-sm mt-2'; to.placeholder = 'Até';
                    if (GRID_FILTERS[key]) { from.value = GRID_FILTERS[key].from || ''; to.value = GRID_FILTERS[key].to || ''; }
                    popup.appendChild(from); popup.appendChild(to);
                    const btns = document.createElement('div'); btns.className = 'd-flex gap-2 mt-2';
                    const apply = document.createElement('button'); apply.className = 'btn btn-sm btn-primary flex-grow-1'; apply.textContent = 'Aplicar';
                    const clear = document.createElement('button'); clear.className = 'btn btn-sm btn-outline-secondary flex-grow-1'; clear.textContent = 'Limpar';
                    apply.addEventListener('click', ()=>{
                        GRID_FILTERS[key] = { from: from.value || '', to: to.value || '' };
                        GRID_PAGE = 1; popup.remove(); renderGrid();
                    });
                    clear.addEventListener('click', ()=>{ delete GRID_FILTERS[key]; GRID_PAGE = 1; popup.remove(); renderGrid(); });
                    btns.appendChild(apply); btns.appendChild(clear); popup.appendChild(btns);
                } else {
                    const input = document.createElement('input'); input.type = 'text'; input.className = 'form-control form-control-sm'; input.placeholder = 'Filtrar...';
                    input.value = GRID_FILTERS[key] || '';
                    popup.appendChild(input);
                    const btns = document.createElement('div'); btns.className = 'd-flex gap-2 mt-2';
                    const apply = document.createElement('button'); apply.className = 'btn btn-sm btn-primary flex-grow-1'; apply.textContent = 'Aplicar';
                    const clear = document.createElement('button'); clear.className = 'btn btn-sm btn-outline-secondary flex-grow-1'; clear.textContent = 'Limpar';
                    apply.addEventListener('click', ()=>{ GRID_FILTERS[key] = input.value.trim(); GRID_PAGE = 1; popup.remove(); renderGrid(); });
                    clear.addEventListener('click', ()=>{ delete GRID_FILTERS[key]; GRID_PAGE = 1; popup.remove(); renderGrid(); });
                    btns.appendChild(apply); btns.appendChild(clear); popup.appendChild(btns);
                }
                document.body.appendChild(popup);
                // position
                const r = th.getBoundingClientRect(); popup.style.left = (r.left + window.scrollX) + 'px'; popup.style.top = (r.bottom + window.scrollY + 6) + 'px';
                // close on outside click
                const onDocClick = (ev)=>{ if (!popup.contains(ev.target) && !th.contains(ev.target)) { popup.remove(); document.removeEventListener('click', onDocClick); } };
                setTimeout(()=> document.addEventListener('click', onDocClick), 0);
            }
            headerRow.appendChild(makeSortableHeader('name','Nome'));
            headerRow.appendChild(makeSortableHeader('source','Fonte'));
            headerRow.appendChild(makeSortableHeader('status','Status'));
            // remaining headers (sortable as requested)
            headerRow.appendChild(makeSortableHeader('phone','Telefone'));
            headerRow.appendChild(makeSortableHeader('valor','Valor'));
            headerRow.appendChild(makeSortableHeader('score','Score'));
            headerRow.appendChild(makeSortableHeader('criado','Criado'));
            headerRow.appendChild(makeSortableHeader('ultimo_contato','Último Contato'));
            const thAct = document.createElement('th'); headerRow.appendChild(thAct);
            thead.appendChild(headerRow);
        const tbody = document.createElement('tbody');
        // Selected count indicator (shows total selected across pages)
        try {
            const selectedWrap = document.createElement('div');
            selectedWrap.id = 'listSelectedCount';
            selectedWrap.className = 'mb-2 text-muted fw-semibold fs-5';
            selectedWrap.textContent = 'Selecionados: ' + String(getSelectedLeadIds().length);
            container.appendChild(selectedWrap);
        } catch(e) { /* ignore */ }
        // rows (apply filters first, then sort if requested)
        let rows = getFilteredLeads();
        // apply GRID_FILTERS
        rows = rows.filter(lead=>{
            try {
                for (const k in GRID_FILTERS) {
                    if (!Object.prototype.hasOwnProperty.call(GRID_FILTERS,k)) continue;
                    const f = GRID_FILTERS[k]; if (f === null || f === undefined || f === '') continue;
                    if (k === 'status') {
                        if (getStageName(lead.stage_id) !== f) return false;
                    } else if (k === 'criado' || k === 'ultimo_contato') {
                        const dt = (k === 'criado') ? (lead.data_inicio || lead.created_at || lead.createdAt || lead.created) : (lead.ultimo_contato);
                        if (!dt) return false;
                        // Extract date portion (YYYY-MM-DD) to avoid timezone shifts when creating Date objects
                        const raw = String(dt || '');
                        const m = raw.match(/(\d{4}-\d{2}-\d{2})/);
                        const dtDateStr = m ? m[1] : raw.slice(0,10);
                        if (!dtDateStr || dtDateStr.length < 8) return false;
                        // input date fields from <input type=date> are already in YYYY-MM-DD format
                        const fromStr = f.from || null;
                        const toStr = f.to || null;
                        if (fromStr) {
                            if (dtDateStr < fromStr) return false;
                        }
                        if (toStr) {
                            if (dtDateStr > toStr) return false;
                        }
                    } else {
                        const val = (k === 'valor') ? String(toCurrency(lead.proposal_value || lead.estimativa_projeto_kwh || lead.value || 0)).toLowerCase() : String((lead[k] || lead.source || lead.client_name || lead.company || '')).toLowerCase();
                        if (!val.includes(String(f).toLowerCase())) return false;
                    }
                }
            } catch(e){ return true }
            return true;
        });
        if (GRID_SORT_BY) {
            const key = GRID_SORT_BY;
            function getSortValue(item){
                try {
                    if (key === 'name') return String(item.name || '').toLowerCase();
                    if (key === 'source') return String(item.source || item.client_name || item.company || '').toLowerCase();
                    if (key === 'status') return String(getStageName(item.stage_id) || '').toLowerCase();
                    if (key === 'phone') return String(item.phone || '').toLowerCase();
                    if (key === 'score') return Number(item.score || computeScore(item) || 0);
                    if (key === 'valor' || key === 'value') return Number(item.orcamento_value || item.proposal_value || item.value || 0) || 0;
                    if (key === 'criado') {
                        const dt = item.data_inicio || item.created_at || item.createdAt || item.created || null;
                        const t = dt ? (new Date(String(dt).replace(' ', 'T')).getTime() || 0) : 0;
                        return Number(t);
                    }
                    if (key === 'ultimo_contato') {
                        const dt = item.ultimo_contato || null;
                        const t = dt ? (new Date(String(dt).replace(' ', 'T')).getTime() || 0) : 0;
                        return Number(t);
                    }
                    return String(item[key] || '').toLowerCase();
                } catch(e){ return '' }
            }
            rows.sort((a,b)=>{
                const va = getSortValue(a); const vb = getSortValue(b);
                if (typeof va === 'number' && typeof vb === 'number') {
                    return (va - vb) * GRID_SORT_DIR;
                }
                const sa = String(va).toLowerCase(); const sb = String(vb).toLowerCase();
                if (sa < sb) return -1 * GRID_SORT_DIR;
                if (sa > sb) return 1 * GRID_SORT_DIR;
                return 0;
            });
        }
        // pagination
        const totalPages = Math.ceil(rows.length / GRID_PAGE_SIZE);
        const start = (GRID_PAGE - 1) * GRID_PAGE_SIZE;
        const end = start + GRID_PAGE_SIZE;
        const pageRows = rows.slice(start, end);
        // render only pageRows
        pageRows.forEach(lead => {
            const tr = document.createElement('tr'); tr.dataset.id = lead.id;
            const chkTd = document.createElement('td'); chkTd.innerHTML = '<input class="lead-select" type="checkbox">';
            const chk = chkTd.querySelector('input');
            try { chk.checked = SELECTED_LEADS.has(String(lead.id)); } catch(e){}
            // ensure row selection toggles visual selected state and updates bulk controls
            chk.addEventListener('change', (e) => {
                try {
                    if (chk.checked) SELECTED_LEADS.add(String(lead.id)); else SELECTED_LEADS.delete(String(lead.id));
                } catch(e){}
                try { tr.classList.toggle('selected', !!chk.checked); } catch(_){ }
                updateBulkDeleteVisibility();
            });
            const nameTd = document.createElement('td'); nameTd.textContent = (lead.name || '(sem nome)').length > 20 ? (lead.name || '(sem nome)').substring(0, 20) + '...' : (lead.name || '(sem nome)');
            const compTd = document.createElement('td'); compTd.textContent = lead.source || lead.client_name || lead.company || '—';
            const statusTd = document.createElement('td'); statusTd.textContent = getStageName(lead.stage_id) || '';
            const phoneTd = document.createElement('td'); phoneTd.textContent = lead.phone || '';
            const valTd = document.createElement('td'); valTd.textContent = toCurrency(lead.orcamento_value || lead.proposal_value || lead.estimativa_projeto_kwh || lead.value || 0);
            const scoreTd = document.createElement('td'); scoreTd.innerHTML = '<span class="badge-score ' + (lead.score>=80?'hot':(lead.score>=50?'warm':'cold')) + '">' + (lead.score||0) + '</span>';
            const createdTd = document.createElement('td'); createdTd.className='small text-muted'; createdTd.textContent = formatDateBR(lead.data_inicio || lead.created_at || lead.createdAt || lead.created);
            const updatedTd = document.createElement('td'); updatedTd.className='small text-muted'; updatedTd.textContent = formatDateBR(lead.ultimo_contato);
            const actTd = document.createElement('td'); actTd.className='small';
            const openBtn = document.createElement('button');
            openBtn.className='btn btn-sm btn-outline-secondary p-1';
            openBtn.type='button';
            openBtn.innerHTML = '<i class="fa fa-eye" aria-hidden="true"></i>';
            openBtn.title = 'Abrir';
            openBtn.setAttribute('aria-label','Abrir');
            openBtn.addEventListener('click', ()=> openPanel(lead.id));
            actTd.appendChild(openBtn);

            tr.appendChild(chkTd); tr.appendChild(nameTd); tr.appendChild(compTd); tr.appendChild(statusTd); tr.appendChild(phoneTd); tr.appendChild(valTd); tr.appendChild(scoreTd); tr.appendChild(createdTd); tr.appendChild(updatedTd); tr.appendChild(actTd);
            tbody.appendChild(tr);
        });
        table.appendChild(thead); table.appendChild(tbody); container.appendChild(table);
        // pagination controls
        if (totalPages > 1) {
            const paginationDiv = document.createElement('div'); paginationDiv.className = 'd-flex justify-content-center mt-3';
            const nav = document.createElement('nav');
            const ul = document.createElement('ul'); ul.className = 'pagination pagination-sm';
            // previous button
            const prevLi = document.createElement('li'); prevLi.className = 'page-item' + (GRID_PAGE === 1 ? ' disabled' : '');
            const prevA = document.createElement('a'); prevA.className = 'page-link'; prevA.href = '#'; prevA.textContent = 'Anterior';
            prevA.addEventListener('click', (e) => { e.preventDefault(); if (GRID_PAGE > 1) { GRID_PAGE--; renderGrid(); } });
            prevLi.appendChild(prevA); ul.appendChild(prevLi);
            // page numbers (show up to 5 pages)
            const startPage = Math.max(1, GRID_PAGE - 2);
            const endPage = Math.min(totalPages, GRID_PAGE + 2);
            for (let p = startPage; p <= endPage; p++) {
                const li = document.createElement('li'); li.className = 'page-item' + (p === GRID_PAGE ? ' active' : '');
                const a = document.createElement('a'); a.className = 'page-link'; a.href = '#'; a.textContent = p;
                a.addEventListener('click', (e) => { e.preventDefault(); GRID_PAGE = p; renderGrid(); });
                li.appendChild(a); ul.appendChild(li);
            }
            // next button
            const nextLi = document.createElement('li'); nextLi.className = 'page-item' + (GRID_PAGE === totalPages ? ' disabled' : '');
            const nextA = document.createElement('a'); nextA.className = 'page-link'; nextA.href = '#'; nextA.textContent = 'Próximo';
            nextA.addEventListener('click', (e) => { e.preventDefault(); if (GRID_PAGE < totalPages) { GRID_PAGE++; renderGrid(); } });
            nextLi.appendChild(nextA); ul.appendChild(nextLi);
            nav.appendChild(ul); paginationDiv.appendChild(nav); container.appendChild(paginationDiv);
        }
        updateBulkDeleteVisibility();
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
        console.log('=== renderAll iniciado ===');
        console.log('STAGES:', STAGES);
        console.log('Total de STAGES:', STAGES ? STAGES.length : 0);
        if (STAGES && STAGES.length > 0) {
            STAGES.forEach((s, idx) => {
                console.log(`Stage ${idx}:`, s.id, s.name, 'allow_project_creation:', s.allow_project_creation);
            });
        }
        // Always refresh KPIs/top cards so totals are correct when switching views
        try { renderKpis(); } catch(e) { console.warn('renderKpis failed', e); }
        // switch to alternate views if requested: 'list' => table
        const vm = getViewMode();
        if (vm === 'list') { renderGrid(); return; }
        clearColumns();
        const filtered = getFilteredLeads();
        console.log('Filtered leads:', filtered.length);
        // compute sums per stage id
        const sums = {};
        STAGES.forEach(s=> sums[s.id] = 0);

        filtered.forEach(l=>{
            let stageKey = '0';
            if (typeof l.stage_id === 'undefined' || l.stage_id === null || Number(l.stage_id) === 0) {
                stageKey = 'sem_status';
            } else if (l.stage_id) {
                stageKey = String(l.stage_id);
            } else {
                stageKey = (STAGES.find(s=>s.name === (l.status||'')) || {id:'0'}).id;
            }
            const col = document.getElementById('col-' + stageKey);
            if (col) {
                // determine colors: prefer DOM dataset, then STAGES data
                const colWrap = col.closest('.kanban-column');
                let cardColor = colWrap?.dataset?.cardColor || '';
                let headerColor = colWrap?.dataset?.color || '';
                // Always try to find stageObj for button logic
                let stageObj = null;
                if (STAGES && STAGES.length) {
                    stageObj = STAGES.find(s => String(s.id) === String(stageKey));
                    if (stageObj) {
                        cardColor = cardColor || (stageObj.card_color || '');
                        headerColor = headerColor || (stageObj.color || '');
                    }
                }
                // Create card with stage object for button logic
                const card = makeCard(l, stageObj);
                if (!headerColor) headerColor = '#6c757d';
                if (cardColor) {
                    card.style.backgroundColor = cardColor;
                    card.style.color = readableTextColor(cardColor);
                }
                card.style.borderLeft = headerColor ? ('6px solid ' + headerColor) : '6px solid transparent';
                card.dataset.originalBorder = card.style.borderLeft || '6px solid transparent';
                col.appendChild(card);
            }
            const val = parseFloat(l.orcamento_value || 0) || 0;
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
        updateBulkDeleteVisibility();
        // populate Anúncios column (fetch latest and render as cards)
        try {
            fetchAnuncios().then(rows=>{
                try {
                    const col = document.getElementById('col-anuncios');
                    if (!col) return;
                    col.innerHTML = '';
                    if (rows && rows.length) {
                        rows.forEach(r=>{
                            const card = makeAnuncioCard(r);
                            col.appendChild(card);
                        });
                    } else {
                        const empty = document.createElement('div'); empty.className='p-3 small text-muted'; empty.textContent = 'Nenhum lead de anúncio.'; col.appendChild(empty);
                    }
                    // update Anúncios fixed column count badge if present
                    const fixedCount = document.getElementById('anunciosCount'); if (fixedCount) fixedCount.textContent = (rows && rows.length) ? String(rows.length) : '0';
                    const kpiCount = document.getElementById('anunciosKpiCount'); if (kpiCount) kpiCount.textContent = (rows && rows.length) ? String(rows.length) : '0';
                    const colBadge = document.getElementById('count-anuncios'); if (colBadge) colBadge.textContent = (rows && rows.length) ? String(rows.length) : '0';
                } catch(e){ console.warn('renderAnuncios failed', e); }
            }).catch(()=>{});
        } catch(e) { /* ignore */ }
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
            const raw = e.dataTransfer.getData('text/plain') || '';
            const stageId = colWrap?.dataset?.stageId;
            const stageName = colWrap?.dataset?.stageName;
            try {
                // Prevent dropping existing lead cards into the Anúncios column
                if (stageId === 'anuncios' && !raw.startsWith('anuncio:')) {
                    flashFeedback(colContent, false);
                    return;
                }
                // Prevent dropping any cards into 'Sem Status' column
                if (stageId === 'sem_status') {
                    flashFeedback(colContent, false);
                    return;
                }
                // Support dragging existing lead cards (id) or anuncio items prefixed with 'anuncio:'
                if (raw.startsWith('anuncio:')) {
                    const anId = raw.split(':',2)[1];
                    // promote anuncio via API (server will create lead)
                    const fd = new FormData(); fd.append('action','promote_anuncio'); fd.append('id', anId); fd.append('stage_id', stageId || '');
                    const res = await fetch(apiBase, { method: 'POST', body: fd });
                    const json = await res.json(); if (!res.ok || json.error) throw new Error(json.error || 'Falha ao promover anúncio');
                    // reload leads and provide feedback
                    await fetchLeads();
                    // refresh anuncios list and rebuild columns if necessary
                    try { await fetchAnuncios(); } catch(e){}
                    flashFeedback(colContent, true);
                } else {
                    const id = raw;
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
                }
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
        const status = document.createElement('div'); status.className='mb-2 small text-muted';
        (async ()=>{
            try {
                let statusLabel = lead.status || 'Novo';
                // If status is an ID, try to resolve via loaded STATUSES or fetch them
                if (statusLabel && String(statusLabel).match(/^\d+$/)) {
                    let s = STATUSES.find(x=>String(x.id)===String(statusLabel));
                    if (!s) {
                        try { await fetchStatuses(); } catch(e){}
                        s = STATUSES.find(x=>String(x.id)===String(statusLabel));
                    }
                    if (s) statusLabel = s.name;
                }
                status.textContent = 'Status: ' + (statusLabel || 'Novo');
            } catch(e) { status.textContent = 'Status: ' + (lead.status||'Novo'); }
        })();
        const company = document.createElement('div'); company.textContent = 'Fonte: ' + (lead.source || lead.client_name || lead.company || '—');
        const email = document.createElement('div'); email.innerHTML = 'Email: ' + (lead.email? `<a href="mailto:${encodeURIComponent(lead.email)}">${lead.email}</a>` : '—');
        const phone = document.createElement('div'); phone.innerHTML = 'Telefone: ' + (lead.phone? `<a href="tel:${encodeURIComponent(lead.phone)}">${lead.phone}</a>` : '—');
        const city = document.createElement('div'); city.textContent = 'Cidade: ' + (lead.cidade || lead.city || '—');
        // attachment link in detail panel
        const anexosDiv = document.createElement('div'); anexosDiv.className = 'mt-2';
        if (lead.anexos_files && lead.anexos_files.length) {
            anexosDiv.appendChild(document.createTextNode('Anexos: '));
            const list = document.createElement('div'); list.className = 'anexos-list';
            lead.anexos_files.forEach(f => {
                const a = document.createElement('a');
                a.href = 'includes/leads_api.php?action=download_anexo&id=' + encodeURIComponent(lead.id) + '&file_id=' + encodeURIComponent(f.attachment_id);
                a.target = '_blank'; a.rel = 'noopener'; a.className = 'd-block';
                a.innerHTML = escapeText(f.filename) + ' <i class="fa fa-paperclip ms-1"></i>';
                list.appendChild(a);
            });
            anexosDiv.appendChild(list);
        } else if (lead.anexos_filename) {
            const an = document.createElement('a');
            an.href = 'includes/leads_api.php?action=download_anexo&id=' + encodeURIComponent(lead.id);
            an.target = '_blank'; an.rel = 'noopener';
            an.className = 'anexo-link';
            an.innerHTML = `${escapeText(lead.anexos_filename)} <i class="fa fa-paperclip ms-1"></i>`;
            anexosDiv.appendChild(document.createTextNode('Anexo: '));
            anexosDiv.appendChild(an);
        } else {
            anexosDiv.textContent = 'Anexo: —';
        }
        const value = document.createElement('div'); value.textContent = 'Valor estimado: ' + toCurrency(lead.orcamento_value || 0);
        const createdText = formatDateBR(lead.created_at || lead.createdAt || lead.created);
        const daysActive = daysSince(lead.created_at || lead.createdAt || lead.created);
        const createdDiv = document.createElement('div'); createdDiv.className = 'small text-muted'; createdDiv.textContent = 'Criado: ' + createdText + (daysActive !== null ? (' • ' + daysActive + ' dias') : '');
        const notes = document.createElement('div'); notes.className='mt-3'; notes.textContent = 'Notas: ' + (lead.notes || '—');
            const btns = document.createElement('div'); btns.className='mt-3 d-flex gap-2';
            // compact reminders placeholder (filled after panel open)
            const remindersWrap = document.createElement('div');
            remindersWrap.className = 'mt-3 mb-3'; remindersWrap.id = 'leadReminders';
            // prepare columns
            const leftCol = document.createElement('div'); leftCol.className = 'lead-col';
            const rightCol = document.createElement('div'); rightCol.className = 'lead-col';
                // create reminder button (icon + label) and wire click to open modal
                const reminderBtn = document.createElement('button');
                reminderBtn.className = 'btn btn-sm btn-outline-info';
                reminderBtn.type = 'button';
                reminderBtn.innerHTML = '<i class="fa fa-clock"></i>';
                reminderBtn.title = 'Lembrete';
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
        const taskBtn = document.createElement('button');
        taskBtn.className = 'btn btn-sm btn-outline-warning';
        taskBtn.type = 'button';
        taskBtn.innerHTML = '<i class="fa fa-tasks"></i>';
        taskBtn.title = 'Adicionar Tarefa';
        taskBtn.addEventListener('click', async (e)=>{
            e.stopPropagation();
            // Obter lista de usuários
            let users = [];
            try {
                const res = await fetch('includes/leads_api.php?action=get_users');
                users = await res.json();
            } catch (err) {
                console.error('Erro ao obter usuários:', err);
            }
            // Criar modal para adicionar tarefa
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'addTaskModal';
            const options = users.map(u => `<option value="${u.username}">${u.username}</option>`).join('');
            modal.innerHTML = `
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header" style="background-color: #0b6ac1; color: white;">
                            <h5 class="modal-title">Adicionar Tarefa para Lead</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <form id="addTaskForm">
                                <div class="mb-3">
                                    <label for="taskTitle" class="form-label">Título</label>
                                    <input type="text" class="form-control" id="taskTitle" value="${(lead.name || 'Sem nome').charAt(0).toUpperCase() + (lead.name || 'Sem nome').slice(1)}, ${lead.cidade || ''}, ${lead.phone || ''}" style="border-color: #0b6ac1;" required>
                                </div>
                                <div class="mb-3">
                                    <label for="taskDescription" class="form-label">Descrição</label>
                                    <textarea class="form-control" id="taskDescription" rows="3" style="border-color: #0b6ac1;"></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="taskResponsavel" class="form-label">Responsável</label>
                                    <select class="form-select" id="taskResponsavel" style="border-color: #0b6ac1;">
                                        <option value="">Selecione...</option>
                                        ${options}
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="taskDueDate" class="form-label">Data de Vencimento</label>
                                    <input type="date" class="form-control" id="taskDueDate" value="${new Date(Date.now() + 7*24*60*60*1000).toISOString().split('T')[0]}" style="border-color: #0b6ac1;" required>
                                </div>
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="button" class="btn btn-primary" id="saveTaskBtn">Salvar Tarefa</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();

            // Pre-selecionar responsável se o lead tiver
            if (lead.responsavel) {
                const select = document.getElementById('taskResponsavel');
                const option = Array.from(select.options).find(opt => opt.value === lead.responsavel);
                if (option) option.selected = true;
            }

            // Event listener para salvar
            document.getElementById('saveTaskBtn').addEventListener('click', async () => {
                const title = document.getElementById('taskTitle').value.trim();
                const description = document.getElementById('taskDescription').value.trim();
                const responsavel = document.getElementById('taskResponsavel').value;
                const dueDate = document.getElementById('taskDueDate').value;
                if (!title || !dueDate) {
                    alert('Preencha título e data de vencimento.');
                    return;
                }
                try {
                    let userId = window.userId;
                    if (!userId) {
                        const res = await fetch('includes/leads_api.php?action=get_user_id');
                        const json = await res.json();
                        userId = json.user_id;
                    }
                    const mod = await import('./team_tasks.js');
                    const addTask = mod.addTask;
                    const taskData = {
                        user_id: userId,
                        titulo: title,
                        descricao: description,
                        status: 'Pendente',
                        responsavel: responsavel,
                        data_vencimento: dueDate,
                        lead_id: lead.id
                    };
                    const result = await addTask(taskData);
                    if (result.success) {
                        alert('Tarefa criada com sucesso!');
                        bsModal.hide();
                        modal.remove();
                    } else {
                        alert('Erro ao criar tarefa: ' + (result.error || 'Desconhecido'));
                    }
                } catch (err) {
                    console.error('Erro ao adicionar tarefa:', err);
                    alert('Erro ao adicionar tarefa.');
                }
            });

            // Remover modal do DOM ao fechar
            modal.addEventListener('hidden.bs.modal', () => modal.remove());
        });
        const editBtn = document.createElement('button'); editBtn.className='btn btn-sm btn-outline-primary'; editBtn.type='button'; editBtn.innerHTML='<i class="fa fa-edit"></i>'; editBtn.title='Editar';
        editBtn.addEventListener('click', (e)=>{ e.stopPropagation(); populateLeadForm(lead); });
        const deleteBtn = document.createElement('button'); deleteBtn.className='btn btn-sm btn-outline-danger'; deleteBtn.type='button'; deleteBtn.innerHTML='<i class="fa fa-trash"></i>'; deleteBtn.title='Mover para lixeira';
        deleteBtn.addEventListener('click', async (e)=>{
            e.stopPropagation();
            if (!confirm('Mover este lead para a lixeira?')) return;
            try {
                const formData = new FormData();
                formData.append('id', lead.id);
                const res = await fetch(apiBase + '?action=delete', { method: 'POST', body: formData });
                if (res.ok) {
                    closePanel();
                    await fetchLeads();
                } else {
                    alert('Erro ao mover lead para lixeira');
                }
            } catch (err) {
                console.error(err);
                alert('Erro ao mover lead para lixeira');
            }
        });
        const whatsappBtn = document.createElement('a'); whatsappBtn.className='btn btn-sm btn-outline-success'; whatsappBtn.href = lead.phone? 'https://wa.me/'+lead.phone.replace(/\D/g,''):'#'; whatsappBtn.target='_blank'; whatsappBtn.innerHTML='<i class="fa-brands fa-whatsapp"></i>'; whatsappBtn.title='WhatsApp';
        const proposalBtn = document.createElement('button'); proposalBtn.className='btn btn-sm btn-primary'; proposalBtn.type='button'; proposalBtn.innerHTML='<i class="fa fa-paper-plane"></i>'; proposalBtn.title='Enviar proposta';
        proposalBtn.classList.add('d-none');
        btns.appendChild(reminderBtn); btns.appendChild(taskBtn); btns.appendChild(editBtn); btns.appendChild(deleteBtn); btns.appendChild(whatsappBtn); btns.appendChild(proposalBtn);

        // Movement timeline
        const timelineWrap = document.createElement('div'); timelineWrap.className = 'my-5'; timelineWrap.innerHTML = '<h6>Histórico de movimentações</h6><div id="timeline"></div>';
        // assemble columns: left = basic info + notes + actions, right = reminders + timeline
        leftCol.appendChild(title); leftCol.appendChild(status); leftCol.appendChild(createdDiv); leftCol.appendChild(company); leftCol.appendChild(email); leftCol.appendChild(phone); leftCol.appendChild(city); leftCol.appendChild(value); leftCol.appendChild(notes);
        if (typeof anexosDiv !== 'undefined' && anexosDiv) leftCol.appendChild(anexosDiv);
        leftCol.appendChild(btns);
            rightCol.appendChild(remindersWrap); rightCol.appendChild(timelineWrap); 
            // append columns directly to detail content so CSS grid works
            p.appendChild(leftCol); p.appendChild(rightCol);

            // load compact reminders for this lead
            try { fetchRemindersForLead(id); } catch(e){ console.warn('failed loading reminders', e); }

        // fetch and render movements
        const timeline = timelineWrap.querySelector('#timeline'); timeline.innerHTML = '<div class="small text-muted">Carregando...</div>';
        const moves = await fetchMovements(id);
        // ensure movements render newest-first (decrescente)
        try { if (moves && moves.length) moves.sort((a,b)=> new Date(b.created_at) - new Date(a.created_at)); } catch(e) { /* ignore sort errors */ }
        if (moves && moves.length) {
            timeline.innerHTML = '';
            // fetch users mapping to resolve consultant names (changed_by)
            let usersMap = {};
            try {
                const ur = await fetch('includes/leads_api.php?action=get_users');
                if (ur.ok) {
                    const us = await ur.json();
                    if (Array.isArray(us)) us.forEach(u=> usersMap[String(u.id)] = u.username || (u.email||('user:'+u.id)) );
                }
            } catch(e) { /* ignore user fetch errors */ }

            // load appearance to pick primary color for movement title styling
            let primaryColor = null;
            try {
                const app = await loadAppearance();
                primaryColor = (app && (app.primary_color || app.primary || app.color_primary)) ? (app.primary_color || app.primary || app.color_primary) : null;
            } catch(e) { primaryColor = null; }

            moves.forEach(m=>{
                const item = document.createElement('div'); item.className = 'movement-row';
                const ts = document.createElement('div'); ts.className='small text-muted'; ts.textContent = new Date(m.created_at).toLocaleString();
                const txt = document.createElement('div');
                // main movement text: if there is a from/to status show arrow, otherwise show only note
                let main = '';
                const hasFromTo = (m.from_status && String(m.from_status).trim() !== '') || (m.to_status && String(m.to_status).trim() !== '');
                if (hasFromTo) {
                    main = `${m.from_status || '—'} → ${m.to_status || '—'}`;
                }
                // include note if present
                if (m.note) {
                    // detect 'Notas atualizadas' prefix separated by ' | ' and style it
                    const noteStr = String(m.note || '');
                    const parts = noteStr.split(' | ');
                    let titlePart = null;
                    let restPart = null;
                    if (parts.length > 1 && /notas\s*atualizad/i.test(parts[0])) {
                        titlePart = parts.shift();
                        restPart = parts.join(' | ');
                    } else {
                        restPart = noteStr;
                    }

                    if (main) {
                        // keep main (from->to) and append note text
                        if (titlePart) {
                            main += ' — ';
                            txt.textContent = main;
                            const titleSpan = document.createElement('span');
                            titleSpan.textContent = titlePart;
                            titleSpan.style.fontWeight = '600';
                            if (primaryColor) titleSpan.style.color = primaryColor;
                            txt.appendChild(titleSpan);
                            if (restPart) txt.appendChild(document.createTextNode(' | ' + restPart));
                        } else {
                            txt.textContent = main + ' — ' + restPart;
                        }
                    } else {
                        // no main: show styled title (if any) then rest
                        if (titlePart) {
                            const titleSpan = document.createElement('span');
                            titleSpan.textContent = titlePart;
                            titleSpan.style.fontWeight = '600';
                            if (primaryColor) titleSpan.style.color = primaryColor;
                            txt.appendChild(titleSpan);
                            if (restPart) txt.appendChild(document.createTextNode(' | ' + restPart));
                        } else {
                            txt.textContent = restPart;
                        }
                    }
                } else {
                    txt.textContent = main;
                }

                item.appendChild(ts); item.appendChild(txt);

                // show consultant/changed_by if available
                try {
                    const changer = m.changed_by || m.user_id || null;
                    if (changer) {
                        const who = usersMap[String(changer)] || String(changer);
                        const whoEl = document.createElement('div'); whoEl.className = 'small text-muted mt-1'; whoEl.textContent = 'Consultor: ' + who;
                        item.appendChild(whoEl);
                    }
                } catch(e) {}

                timeline.appendChild(item);
            });
        } else {
            timeline.innerHTML = '<div class="small text-muted">Nenhuma movimentação registrada</div>';
        }

        const panel = $('#leadDetailsPanel'); panel.classList.remove('hidden');
        // add margin to kanban when panel is open
        const kanbanWrap = $('#kanbanWrap'); if (kanbanWrap) kanbanWrap.classList.add('panel-open');
        // if panel already expanded, ensure two-column class present
        const detail = document.getElementById('leadDetailContent');
        if (panel.classList.contains('expanded')) { if (detail) detail.classList.add('columns-2'); }
    }

    function closePanel(){ 
        $('#leadDetailsPanel').classList.add('hidden'); 
        // remove margin from kanban when panel is closed
        const kanbanWrap = $('#kanbanWrap'); if (kanbanWrap) kanbanWrap.classList.remove('panel-open');
    }

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
        const cityEl = F('leadCity') || $('#leadCity') || $('#lead-city'); if (cityEl) cityEl.value = lead.cidade || lead.city || '';
        const statusEl = F('leadStatus') || $('#leadStatus');
        if (statusEl) {
            if (statusEl.tagName === 'SELECT') {
                // Try to set status by the lead's stage_id (STAGES may load asynchronously).
                const setStatusFromStage = (attempt = 0) => {
                    if (Array.isArray(STAGES) && STAGES.length) {
                        let matched = false;
                        try {
                            if (lead.stage_id != null && lead.stage_id !== '') {
                                const stage = STAGES.find(s => String(s.id) === String(lead.stage_id));
                                if (stage) { statusEl.value = stage.name; matched = true; }
                            }
                        } catch (e) { console.warn('populateLeadForm stage match failed', e); }
                        if (!matched) statusEl.value = lead.status || '';
                    } else if (attempt < 12) {
                        // wait a bit for STAGES to be available (total ~1.8s)
                        setTimeout(() => setStatusFromStage(attempt + 1), 150);
                    } else {
                        // fallback to stored status text
                        statusEl.value = lead.status || '';
                    }
                };
                setStatusFromStage();
            } else {
                statusEl.value = lead.status || '';
            }
        }
        const ultimoContatoEl = document.getElementById('lead-ultimo-contato'); if (ultimoContatoEl) ultimoContatoEl.value = lead.ultimo_contato ? lead.ultimo_contato.substring(0,10) : '';
        // Data de Entrada (data_inicio) - show value and keep editable when opening edit modal
        try {
            const createdEl = document.getElementById('lead-created-at');
            const createdVal = lead.data_inicio || lead.created_at || lead.createdAt || lead.data_criacao || lead.data_criado || '';
            if (createdEl) {
                createdEl.value = createdVal ? String(createdVal).substring(0,10) : '';
                // Ensure the field is editable so user can choose any date
                createdEl.disabled = false;
                createdEl.readOnly = false;
            }
        } catch(e) { console.warn('Failed setting created_at in form', e); }
        // set payment method (ensure options are loaded)
        loadPaymentMethods().then(() => {
            const formaEl = document.getElementById('lead-forma-pagamento');
            if (formaEl) formaEl.value = lead.forma_pagamento || '';
        });
        const stageEl = F('leadStage') || $('#leadStage'); if (stageEl) { try { stageEl.remove(); } catch(e){ stageEl.style.display = 'none'; } }
        const consumoEl = F('leadConsumo') || $('#leadConsumo'); if (consumoEl) consumoEl.value = lead.consumo_cliente || '';
        const estEl = F('leadEstimativa') || $('#leadEstimativa'); if (estEl) estEl.value = lead.estimativa_projeto_kwh || '';
        const orcEl = F('leadOrcamento') || $('#leadOrcamento'); if (orcEl) {
            let val = parseFloat(lead.orcamento_value || 0).toFixed(2);
            val = val.replace('.', ',');
            val = val.replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1.');
            orcEl.value = val;
        }
        const notesEl = F('leadNotes') || $('#leadNotes'); if (notesEl) notesEl.value = lead.notes || '';
        // reset file input
        const f = F('leadAnexos') || $('#leadAnexos'); if (f) f.value = '';
        // render existing attachments UI
        try { renderExistingAttachments(lead); } catch(e){ console.warn('Failed rendering attachments', e); }
        const m = new bootstrap.Modal($('#leadModal'));
        const titleEl = F('leadModalTitle') || $('#leadModalTitle'); if (titleEl) titleEl.textContent = 'Editar Lead';
        // apply configured primary color (if any)
        try { loadAppearance().then(a=>{ const color = (a && (a.primary_color || a.primary || a.color_primary)) ? (a.primary_color||a.primary||a.color_primary) : null; if (color) applyLeadModalPrimaryColor(color); }); } catch(e){}
        m.show();
    }

    // Render existing attachments list inside the edit form and wire delete handlers
    function renderExistingAttachments(lead) {
        const prevWrap = document.getElementById('existingAnexosWrap'); if (prevWrap) prevWrap.remove();
        const fileInput = document.getElementById('lead-anexos');
        if (!fileInput) return;
        try {
            const wrap = document.createElement('div'); wrap.id = 'existingAnexosWrap'; wrap.className = 'mt-2 mb-2';
            const lbl = document.createElement('div'); lbl.className = 'small text-muted mb-1'; lbl.textContent = (lead.anexos_files && lead.anexos_files.length) ? 'Anexos:' : (lead.anexos_filename ? 'Anexo:' : 'Anexos:');
            wrap.appendChild(lbl);

            if (lead.anexos_files && lead.anexos_files.length) {
                lead.anexos_files.forEach(f => {
                    const row = document.createElement('div'); row.className = 'd-flex align-items-center mb-1';
                    const link = document.createElement('a'); link.href = 'includes/leads_api.php?action=download_anexo&id=' + encodeURIComponent(lead.id) + '&file_id=' + encodeURIComponent(f.attachment_id); link.target = '_blank'; link.rel = 'noopener';
                    link.textContent = f.filename; link.className = 'me-2';
                    const delBtn = document.createElement('button'); delBtn.type = 'button'; delBtn.className = 'btn btn-sm btn-outline-danger delete-anexo-btn'; delBtn.textContent = 'Excluir';
                    delBtn.dataset.fileId = f.attachment_id; delBtn.dataset.leadId = lead.id;
                    delBtn.addEventListener('click', async (e) => {
                        e.preventDefault();
                        if (!confirm('Excluir este anexo?')) return;
                        try {
                            const fd = new FormData(); fd.append('file_id', e.currentTarget.dataset.fileId); fd.append('lead_id', e.currentTarget.dataset.leadId);
                            const res = await fetch('includes/leads_api.php?action=delete_attachment', { method: 'POST', body: fd });
                            const txt = await res.text(); let payload = null; try { payload = JSON.parse(txt); } catch(_) { payload = null; }
                            if (!res.ok || (payload && payload.error)) { alert('Falha ao excluir: ' + ((payload && (payload.error||payload.message)) || txt)); return; }
                            // refresh attachments for this lead
                            const g = await fetch('includes/leads_api.php?action=get&id=' + encodeURIComponent(lead.id));
                            if (g.ok) {
                                const newLead = await g.json();
                                renderExistingAttachments(newLead);
                                // keep in-memory list up to date
                                const idx = allLeads.findIndex(l => String(l.id) === String(lead.id));
                                if (idx >= 0) allLeads[idx] = { ...allLeads[idx], ...newLead };
                                // also update detail panel if open
                                const detailId = document.querySelector('#leadDetailsPanel')?.classList.contains('hidden') ? null : lead.id;
                                if (detailId) {
                                    // if details panel open for same lead, re-open to refresh
                                    const openId = document.querySelector('#leadDetailContent')?.querySelector('h4')?.textContent;
                                }
                                // clear file input to avoid accidental re-uploads
                                try { const fileInput = document.getElementById('lead-anexos'); if (fileInput) fileInput.value = ''; } catch(e) {}
                            }
                        } catch (err) { console.error(err); alert('Erro ao excluir anexo'); }
                    });
                    row.appendChild(link); row.appendChild(delBtn); wrap.appendChild(row);
                });
            } else if (lead.anexos_filename) {
                const link = document.createElement('a'); link.href = 'includes/leads_api.php?action=download_anexo&id=' + encodeURIComponent(lead.id); link.target = '_blank'; link.rel = 'noopener'; link.textContent = lead.anexos_filename; link.className = 'd-block'; wrap.appendChild(link);
            }

            if (fileInput && fileInput.parentNode) fileInput.parentNode.appendChild(wrap);
        } catch (e) { console.warn('renderExistingAttachments failed', e); }
    }

    function getSelectedLeadIds(){
        try { return Array.from(SELECTED_LEADS); } catch(e) { return []; }
    }

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
            toggleBtn.addEventListener('click', async ()=>{
                // cycle views: kanban -> list -> kanban
                const order = ['kanban','list'];
                const cur = getViewMode() || 'kanban';
                const idx = order.indexOf(cur);
                const next = order[(idx + 1) % order.length];

                showPreloader('Carregando visualização...');
                setViewMode(next);
                await nextPaint();
                renderAll();
                await nextPaint();
                hidePreloader();
            });
        }
        const closeBtn = $('#closeLeadPanel');
        if (closeBtn) closeBtn.addEventListener('click', closePanel);
        const expandBtn = document.getElementById('expandLeadPanelBtn');
        if (expandBtn) {
            expandBtn.addEventListener('click', ()=>{
                const panel = document.getElementById('leadDetailsPanel');
                const detail = document.getElementById('leadDetailContent');
                const kanbanWrap = document.getElementById('kanbanWrap');
                if (!panel) return;
                const expanded = panel.classList.toggle('expanded');
                if (detail) {
                    detail.classList.toggle('columns-2', expanded);
                }
                if (kanbanWrap) {
                    kanbanWrap.classList.toggle('panel-expanded', expanded);
                }
                expandBtn.setAttribute('aria-pressed', expanded ? 'true' : 'false');
                expandBtn.textContent = expanded ? '⇤' : '⇔';
            });
        }
        const newLeadBtn = $('#newLeadBtn');
        if (newLeadBtn) {
            newLeadBtn.addEventListener('click', ()=>{ 
            const m = new bootstrap.Modal($('#leadModal')); 
            $('#leadModalTitle').textContent='Novo Lead'; 
            $('#leadForm').reset(); 
            const idEl = F('leadId') || $('#lead-id'); 
            if (idEl) idEl.value = '';
            // Clear existing attachments display for new lead
            const prevWrap = document.getElementById('existingAnexosWrap'); if (prevWrap) prevWrap.remove();
            // Ensure Data de Entrada is editable for new leads and default to today
            try {
                const createdEl = document.getElementById('lead-created-at');
                const now = new Date().toISOString().slice(0,10);
                if (createdEl) { createdEl.disabled = false; createdEl.readOnly = false; createdEl.value = now; }
            } catch(e) { }
            // set current date for ultimo_contato when creating new lead
            try {
                const now2 = new Date().toISOString().slice(0,10);
                const ultimoEl = document.getElementById('lead-ultimo-contato');
                if (ultimoEl) ultimoEl.value = now2;
            } catch(e) {}
            // set default status to 'Sem Contato' when opening new lead modal
            try {
                const statusSel = F('leadStatus') || $('#leadStatus') || $('#lead-status');
                if (statusSel) {
                    let found = false;
                    for (let i=0;i<statusSel.options.length;i++){
                        if (statusSel.options[i].text === 'Sem Contrato') { statusSel.selectedIndex = i; found = true; break; }
                    }
                    // if 'Sem Contrato' not found, default to first existing stage if available
                    if (!found && statusSel.options.length > 0) statusSel.selectedIndex = 0;
                }
            } catch(e) { /* ignore */ }
            // apply configured primary color (if any) for new lead modal
            try { loadAppearance().then(a=>{ const color = (a && (a.primary_color || a.primary || a.color_primary)) ? (a.primary_color||a.primary||a.color_primary) : null; if (color) applyLeadModalPrimaryColor(color); }); } catch(e){}
            m.show(); 
        });
        }

        // Immediate attachments upload button (inside lead modal)
        const uploadAnexosBtn = document.getElementById('upload-anexos-now');
        if (uploadAnexosBtn) {
            uploadAnexosBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                const idEl = F('leadId') || $('#lead-id');
                const leadId = idEl ? idEl.value : '';
                if (!leadId) { alert('Salve o lead antes de enviar anexos.'); return; }
                const fileInput = document.getElementById('lead-anexos');
                if (!fileInput || !fileInput.files || fileInput.files.length === 0) { alert('Selecione arquivos para enviar.'); return; }
                const origHtml = uploadAnexosBtn.innerHTML;
                try {
                    uploadAnexosBtn.disabled = true; uploadAnexosBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Enviando...';
                    const fd = new FormData(); fd.append('id', leadId);
                    for (let i=0;i<fileInput.files.length;i++) fd.append('anexos[]', fileInput.files[i]);
                    const res = await fetch(apiBase + '?action=upload_attachment', { method: 'POST', body: fd });
                    const txt = await res.text(); let payload = null; try { payload = JSON.parse(txt); } catch(_) { payload = null; }
                    if (!res.ok || (payload && payload.error)) { alert('Falha ao enviar anexos: ' + ((payload && (payload.error||payload.message)) || txt)); return; }
                    // Refresh attachments UI for this lead
                    const g = await fetch(apiBase + '?action=get&id=' + encodeURIComponent(leadId));
                    if (g.ok) {
                        const newLead = await g.json();
                        try { renderExistingAttachments(newLead); } catch(_) {}
                        // update in-memory list
                        try { const idx = allLeads.findIndex(l => String(l.id) === String(leadId)); if (idx >= 0) allLeads[idx] = { ...allLeads[idx], ...newLead }; } catch(_) {}
                        // clear file input
                        fileInput.value = '';
                    }
                } catch (err) { console.error(err); alert('Erro ao enviar anexos'); }
                finally { uploadAnexosBtn.disabled = false; uploadAnexosBtn.innerHTML = origHtml; try { fileInput.value = ''; } catch(e) {} }
            });
        }

        // Anúncios UI: Visualizar modal button and fixed column visibility
        try {
            const adsBtn = document.getElementById('anunciosVisualizarBtn');
            const adsCol = document.getElementById('anunciosFixedColumn');
            if (adsBtn) {
                adsBtn.addEventListener('click', (e)=>{ e.preventDefault(); showAnunciosModal(); });
            }
            // load count and show fixed column when there are anuncios
            (async ()=>{
                try {
                    const rows = await fetchAnuncios();
                    if (rows && rows.length && adsCol) adsCol.classList.remove('d-none');
                } catch(e){ /* ignore */ }
            })();
        } catch(e){ console.warn('ads UI setup failed', e); }

        // Sem Status toggle button
        try {
            const semBtn = document.getElementById('toggleSemStatusBtn');
            if (semBtn) {
                // initialize visual state
                semBtn.classList.toggle('active', SEMSTATUS_SHOWN);
                semBtn.addEventListener('click', ()=>{
                    SEMSTATUS_SHOWN = !SEMSTATUS_SHOWN;
                    localStorage.setItem('showSemStatus', SEMSTATUS_SHOWN ? '1' : '0');
                    semBtn.classList.toggle('active', SEMSTATUS_SHOWN);
                    try { buildColumns(); renderAll(); } catch(e){ console.warn('Failed toggling Sem Status column', e); }
                });
            }
        } catch(e){ console.warn('sem status toggle setup failed', e); }

        // Anuncios toggle button
        try {
            const anBtn = document.getElementById('toggleAnunciosBtn');
            if (anBtn) {
                // initialize visual state
                anBtn.classList.toggle('active', ANUNCIOS_SHOWN);
                anBtn.addEventListener('click', ()=>{
                    ANUNCIOS_SHOWN = !ANUNCIOS_SHOWN;
                    localStorage.setItem('showAnuncios', ANUNCIOS_SHOWN ? '1' : '0');
                    anBtn.classList.toggle('active', ANUNCIOS_SHOWN);
                    try { buildColumns(); renderAll(); } catch(e){ console.warn('Failed toggling Anuncios column', e); }
                });
            }
        } catch(e){ console.warn('anuncios toggle setup failed', e); }

        // attach input masks for phone and CPF/CNPJ
        try { attachMaskHandlers(); } catch(e){ console.warn('attachMaskHandlers failed', e); }

        // Kanban-only toggle: hide/show all page chrome and make kanban fill view
        try {
            const kanbanBtn = document.getElementById('kanbanOnlyBtn');
            const kanbanIcon = document.getElementById('kanbanOnlyIcon');
            const compactBtn = document.getElementById('kanbanCompactBtn');
            const compactIcon = document.getElementById('kanbanCompactIcon');
            // Keep references to original place so we can restore
            let _kanbanBtnOriginalParent = null;
            let _kanbanBtnOriginalNext = null;
            let _compactBtnOriginalParent = null;
            let _compactBtnOriginalNext = null;
            const applyState = (on) => {
                const kanbanWrap = document.getElementById('kanbanWrap');
                if (on) {
                        // compute an absolute path for the kanban background image based on current page
                        try {
                            const basePath = window.location.pathname.replace(/\/[^\/]*$/, '') || '';
                            const imgPath = (basePath === '' ? '' : basePath) + '/assets/img/kanban4.jpg';
                            document.body.style.setProperty('--kanban-bg-image', `url('${imgPath}')`);
                        } catch (e) { /* ignore if styling fails */ }
                    document.body.classList.add('kanban-only');
                    if (kanbanIcon) { kanbanIcon.classList.remove('fa-expand-arrows-alt'); kanbanIcon.classList.add('fa-compress-arrows-alt'); kanbanBtn.title = 'Restaurar layout'; }
                    // move the header buttons into the kanban area so they're visually above the kanban
                    try {
                        if (kanbanWrap) {
                            // keep kanbanWrap positioned if other code relies on it
                            kanbanWrap.style.position = kanbanWrap.style.position || 'relative';
                        }
                        // move buttons to the viewport as fixed floating controls
                        if (kanbanBtn) {
                            if (!_kanbanBtnOriginalParent) {
                                _kanbanBtnOriginalParent = kanbanBtn.parentNode;
                                _kanbanBtnOriginalNext = kanbanBtn.nextSibling;
                            }
                            // compute vertical alignment using original toolbar position
                            try {
                                const toolbarEl = _kanbanBtnOriginalParent || document.querySelector('.d-flex.gap-2.align-items-center') || document.querySelector('.page-header') || document.body;
                                const rect = toolbarEl.getBoundingClientRect();
                                const btnHeight = kanbanBtn.offsetHeight || 34;
                                const OFFSET_Y = 72; // nudge down a bit more so buttons sit slightly lower
                                let computedTop = Math.round(rect.top + (rect.height/2) - (btnHeight/2) + OFFSET_Y);
                                // ensure buttons are at least slightly below the toolbar bottom
                                const minBelow = Math.round(rect.bottom + 8);
                                if (computedTop < minBelow) computedTop = minBelow;
                                kanbanBtn.style.position = 'fixed';
                                kanbanBtn.style.top = computedTop + 'px';
                                kanbanBtn.style.right = '12px';
                                kanbanBtn.style.zIndex = '2200';
                                kanbanBtn.style.margin = '0';
                                // append to kanbanWrap so DOM stays under kanban parent, fallback to body
                                try { (kanbanWrap || document.body).appendChild(kanbanBtn); } catch(e) { document.body.appendChild(kanbanBtn); }
                            } catch (errTop) {
                                // fallback to a slightly lower fixed position
                                kanbanBtn.style.position = 'fixed';
                                kanbanBtn.style.top = '140px';
                                kanbanBtn.style.right = '12px';
                                kanbanBtn.style.zIndex = '2200';
                                kanbanBtn.style.margin = '0';
                                document.body.appendChild(kanbanBtn);
                            }
                        }
                        if (compactBtn) {
                            if (!_compactBtnOriginalParent) {
                                _compactBtnOriginalParent = compactBtn.parentNode;
                                _compactBtnOriginalNext = compactBtn.nextSibling;
                            }
                            try {
                                const toolbarEl = _compactBtnOriginalParent || _kanbanBtnOriginalParent || document.querySelector('.d-flex.gap-2.align-items-center') || document.body;
                                const rect = toolbarEl.getBoundingClientRect();
                                const btnHeight = compactBtn.offsetHeight || 34;
                                const OFFSET_Y = 72;
                                let computedTop = Math.round(rect.top + (rect.height/2) - (btnHeight/2) + OFFSET_Y);
                                const minBelow = Math.round(rect.bottom + 8);
                                if (computedTop < minBelow) computedTop = minBelow;
                                compactBtn.style.position = 'fixed';
                                compactBtn.style.top = computedTop + 'px';
                                // place compact to the left of the kanbanBtn (offset by approx 56px)
                                compactBtn.style.right = '56px';
                                compactBtn.style.zIndex = '2200';
                                compactBtn.style.margin = '0';
                                try { (kanbanWrap || document.body).appendChild(compactBtn); } catch(e) { document.body.appendChild(compactBtn); }
                            } catch (errTop) {
                                compactBtn.style.position = 'fixed';
                                compactBtn.style.top = '140px';
                                compactBtn.style.right = '56px';
                                compactBtn.style.zIndex = '2200';
                                compactBtn.style.margin = '0';
                                document.body.appendChild(compactBtn);
                            }
                        }
                    } catch (e) { console.warn('failed moving kanban/compact buttons into kanbanWrap', e); }
                    // ensure internal column scroll positions start at top when entering Kanban-only
                    try {
                        const cols = document.querySelectorAll('#kanbanWrap .column-content');
                        cols.forEach(c => { try { c.scrollTop = 0; c.scrollLeft = 0; } catch(e){} });
                    } catch(e) { /* ignore */ }
                } else {
                    document.body.classList.remove('kanban-only');
                    if (kanbanIcon) { kanbanIcon.classList.remove('fa-compress-arrows-alt'); kanbanIcon.classList.add('fa-expand-arrows-alt'); kanbanBtn.title = 'Mostrar somente Kanban'; }
                    // restore the buttons to original location
                    try {
                        if (kanbanBtn && _kanbanBtnOriginalParent) {
                            // clear fixed styles
                            kanbanBtn.style.position = '';
                            kanbanBtn.style.top = '';
                            kanbanBtn.style.right = '';
                            kanbanBtn.style.zIndex = '';
                            kanbanBtn.style.margin = '';
                            if (_kanbanBtnOriginalNext && _kanbanBtnOriginalNext.parentNode === _kanbanBtnOriginalParent) {
                                _kanbanBtnOriginalParent.insertBefore(kanbanBtn, _kanbanBtnOriginalNext);
                            } else {
                                _kanbanBtnOriginalParent.appendChild(kanbanBtn);
                            }
                            _kanbanBtnOriginalParent = null;
                            _kanbanBtnOriginalNext = null;
                        }
                        if (compactBtn && _compactBtnOriginalParent) {
                            compactBtn.style.position = '';
                            compactBtn.style.top = '';
                            compactBtn.style.right = '';
                            compactBtn.style.zIndex = '';
                            compactBtn.style.margin = '';
                            if (_compactBtnOriginalNext && _compactBtnOriginalNext.parentNode === _compactBtnOriginalParent) {
                                _compactBtnOriginalParent.insertBefore(compactBtn, _compactBtnOriginalNext);
                            } else {
                                _compactBtnOriginalParent.appendChild(compactBtn);
                            }
                            _compactBtnOriginalParent = null;
                            _compactBtnOriginalNext = null;
                        }
                    } catch (e) { console.warn('failed restoring kanban/compact buttons to header', e); }
                    // restore the button to its original place (styles cleared above)
                }
                try { localStorage.setItem('kanbanOnly', on ? '1' : '0'); } catch(e){}
            };
            // Compact mode toggle: reduce paddings/column widths
            const applyCompact = (onCompact) => {
                try {
                    document.body.classList.toggle('kanban-compact', onCompact);
                    if (compactIcon) {
                        // explicitly set icon and title so users see a "restore" affordance
                        if (onCompact) {
                            compactIcon.classList.remove('fa-compress');
                            compactIcon.classList.add('fa-expand');
                            compactBtn.title = 'Restaurar visual compacto';
                        } else {
                            compactIcon.classList.remove('fa-expand');
                            compactIcon.classList.add('fa-compress');
                            compactBtn.title = 'Compactar Kanban';
                        }
                    }
                    try { localStorage.setItem('kanbanCompact', onCompact ? '1' : '0'); } catch(e){}
                } catch(e){ console.warn('applyCompact failed', e); }
            };
            if (kanbanBtn) {
                const saved = localStorage.getItem('kanbanOnly') === '1';
                applyState(saved);
                kanbanBtn.addEventListener('click', () => { const now = !document.body.classList.contains('kanban-only'); applyState(now); setTimeout(()=>{ window.dispatchEvent(new Event('resize')); }, 120); });
                // init compact button
                if (compactBtn) {
                    const savedCompact = localStorage.getItem('kanbanCompact') === '1';
                    applyCompact(savedCompact);
                    compactBtn.addEventListener('click', ()=>{ const now = !document.body.classList.contains('kanban-compact'); applyCompact(now); setTimeout(()=>{ window.dispatchEvent(new Event('resize')); }, 120); });
                }
            }
        } catch(e){ console.warn('kanbanOnly init failed', e); }
        
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
            // prevent duplicate submits
            if (leadFormSubmitting) {
                console.warn('Lead form submission blocked: already submitting');
                return;
            }
            leadFormSubmitting = true;
            const saveBtn = document.getElementById('save-lead');
            let _saveBtnHtml = null;
            if (saveBtn) {
                try { _saveBtnHtml = saveBtn.innerHTML; } catch(e) {}
                try { saveBtn.disabled = true; saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Salvando...'; } catch(e) {}
            }
            console.log('Form submit triggered');
            const idEl = F('leadId') || $('#lead-id');
            const id = idEl ? idEl.value : '';
            console.log('Lead ID:', id);
            
            const nameValue = (F('leadName')||$('#lead-name')).value || '';
            const emailValue = (F('leadEmail')||$('#lead-email')).value || '';
            const phoneValue = (F('leadPhone')||$('#lead-phone')).value || '';
            const cityValue = (F('leadCity')||$('#lead-city')||$('#leadCity')) ? (F('leadCity')||$('#lead-city')||$('#leadCity')).value : '';
            const cpfValue = (F('leadCpf')||$('#lead-cpf-cnpj')) ? (F('leadCpf')||$('#lead-cpf-cnpj')).value : '';
            const sourceValue = (F('leadSource')||$('#lead-source')) ? (F('leadSource')||$('#lead-source')).value : 'web';
            const statusEl = (F('leadStatus')||$('#lead-status'));
            const statusValue = statusEl ? statusEl.value : '';
            // Find stage_id based on status name
            let stageIdValue = '';
            if (statusValue && STAGES && STAGES.length) {
                const stage = STAGES.find(s => s.name === statusValue);
                if (stage) stageIdValue = stage.id;
            }
            const notesValue = (F('leadNotes')||$('#lead-notes')) ? (F('leadNotes')||$('#lead-notes')).value : '';
            const consumoValue = (F('leadConsumo')||$('#lead-consumo')) ? (F('leadConsumo')||$('#lead-consumo')).value : '';
            const estimativaValue = (F('leadEstimativa')||$('#lead-estimativa-kwh')) ? (F('leadEstimativa')||$('#lead-estimativa-kwh')).value : '';
            const orcamentoValue = (F('leadOrcamento')||$('#lead-orcamento')) ? (F('leadOrcamento')||$('#lead-orcamento')).value.replace(/\./g, '').replace(',', '.') : '';
            const ultimoContatoValue = document.getElementById('lead-ultimo-contato').value;
            const formattedUltimoContato = ultimoContatoValue ? ultimoContatoValue + ' 00:00:00' : '';
            const createdAtValue = (document.getElementById('lead-created-at') || { value: '' }).value;
            const formaPagamentoValue = (document.getElementById('lead-forma-pagamento')||{value:''}).value || '';
            
            console.log('Form values:', {nameValue, emailValue, phoneValue, cpfValue, sourceValue, statusValue, stageIdValue, notesValue, consumoValue, estimativaValue, orcamentoValue, formattedUltimoContato});
            console.log('Status element:', statusEl, 'Status value:', statusValue, 'Stage ID:', stageIdValue);
            
            // Check if files are present
            const filesEl = (F('leadAnexos')||$('#leadAnexos'));
            const hasFiles = filesEl && filesEl.files && filesEl.files.length > 0;
            
            let body, headers = {};
            
            // Always use FormData for consistency
            const fd = new FormData();
            fd.append('name', nameValue);
            fd.append('email', emailValue);
            fd.append('cidade', cityValue);
            fd.append('phone', phoneValue);
            fd.append('cpf_cnpj', cpfValue);
            fd.append('source', sourceValue);
            fd.append('status', statusValue);
            fd.append('stage_id', stageIdValue);
            fd.append('notes', notesValue);
            fd.append('consumo_cliente', consumoValue);
            fd.append('estimativa_projeto_kwh', estimativaValue);
            fd.append('orcamento_value', orcamentoValue);
            fd.append('ultimo_contato', formattedUltimoContato);
            fd.append('forma_pagamento', formaPagamentoValue);
            // include created_at only when creating a new lead
            if (!id && createdAtValue) fd.append('created_at', createdAtValue + ' 00:00:00');
            // Always include data_inicio (Data de Entrada) when present so updates keep the chosen date
            if (createdAtValue) {
                console.log('Data de Entrada (data_inicio) to send:', createdAtValue);
                fd.append('data_inicio', createdAtValue);
                // also append created_at for compatibility so the server receives the exact timestamp (useful for debugging)
                try { fd.append('created_at', createdAtValue + ' 00:00:00'); } catch(e) {}
            }
            if (id) fd.append('id', id);
            
            // Append files if present
            if (hasFiles) {
                for (let i=0;i<filesEl.files.length;i++) {
                    fd.append('anexos[]', filesEl.files[i]);
                    console.log('Appending file:', filesEl.files[i].name, filesEl.files[i].size, 'bytes');
                }
            }
            
            body = fd;
            // Don't set Content-Type header, browser will set it with boundary

            try {
                const action = id ? 'update' : 'add';
                const url = apiBase + '?action=' + action;
                console.log('Sending request to:', url, hasFiles ? '(with files)' : '(no files)');
                console.log('Action:', action, 'ID:', id);
                const res = await fetch(url, { method: 'POST', headers, body });
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
                    alert('Falha ao salvar: ' + msg);
                    return;
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
                // clear file input after closing modal to avoid leftover files
                try { const fileInput = document.getElementById('lead-anexos'); if (fileInput) fileInput.value = ''; } catch(e) {}
            } catch (err) { 
                console.error('Fetch error:', err); 
                alert('Falha ao salvar: ' + (err.message || err)); 
            } finally {
                // re-enable save button and clear submitting flag
                leadFormSubmitting = false;
                const saveBtnFinal = document.getElementById('save-lead');
                if (saveBtnFinal) {
                    try { saveBtnFinal.disabled = false; } catch(e) {}
                    try { if (_saveBtnHtml) saveBtnFinal.innerHTML = _saveBtnHtml; } catch(e) {}
                }
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

        $('#searchInput').addEventListener('input', (e)=>{
            CURRENT_SEARCH = e.target.value.toLowerCase();
            renderAll();
        });

        $('#filterScore').addEventListener('change', (e)=>{
            CURRENT_SCORE_FILTER = e.target.value;
            renderAll();
        });

        $('#filterCidade').addEventListener('change', (e)=>{
            CURRENT_CIDADE_FILTER = e.target.value;
            renderAll();
        });

        // stalled toggle
        const stalledBtn = $('#stalledToggle'); if (stalledBtn) {
            stalledBtn.addEventListener('click', ()=>{
                CURRENT_STALLED_ONLY = stalledBtn.classList.toggle('active');
                stalledBtn.textContent = CURRENT_STALLED_ONLY ? 'Somente parados' : 'Leads parados';
                renderAll();
            });
        }

        // populate bulk stages when modal opens
        const bulkBtn = $('#bulkActionsBtn'); if (bulkBtn) {
            bulkBtn.addEventListener('click', ()=> populateBulkStages());
        }

        // print selected leads
        const printBtn = $('#printSelectedBtn'); if (printBtn) {
            printBtn.addEventListener('click', ()=>{
                const selectedIds = getSelectedLeadIds();
                if (selectedIds.length === 0) { alert('Selecione pelo menos um lead para imprimir.'); return; }
                const selectedLeads = allLeads.filter(l => selectedIds.includes(String(l.id)));
                printSelectedLeads(selectedLeads);
            });
        }

        // print selected leads function
        function printSelectedLeads(leads) {
            const printWindow = window.open('', '_blank');
            const html = `
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Relatório de Leads Selecionados</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { text-align: center; }
        .lead-card { border: 1px solid #ddd; margin-bottom: 20px; padding: 15px; page-break-inside: avoid; }
        .lead-header { font-weight: bold; margin-bottom: 10px; }
        .lead-detail { margin: 5px 0; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <h1>Relatório de Leads Selecionados</h1>
    <p>Total: ${leads.length} leads</p>
    ${leads.map(lead => `
        <div class="lead-card">
            <div class="lead-header">${lead.name || 'Sem nome'}</div>
            <div class="lead-detail"><strong>Email:</strong> ${lead.email || '-'}</div>
            <div class="lead-detail"><strong>Telefone:</strong> ${lead.phone || '-'}</div>
            <div class="lead-detail"><strong>Cidade:</strong> ${lead.cidade || '-'}</div>
            <div class="lead-detail"><strong>Fonte:</strong> ${lead.source || '-'}</div>
            <div class="lead-detail"><strong>Status:</strong> ${lead.status || '-'}</div>
            <div class="lead-detail"><strong>Valor:</strong> R$ ${lead.orcamento_value ? parseFloat(lead.orcamento_value).toFixed(2) : '0.00'}</div>
            <div class="lead-detail"><strong>Score:</strong> ${lead.score || 0}</div>
            <div class="lead-detail"><strong>Criado:</strong> ${lead.created_at ? new Date(lead.created_at).toLocaleDateString('pt-BR') : '-'}</div>
            <div class="lead-detail"><strong>Último Contato:</strong> ${lead.ultimo_contato ? new Date(lead.ultimo_contato).toLocaleDateString('pt-BR') : '-'}</div>
            <div class="lead-detail"><strong>Observações:</strong> ${lead.notes || '-'}</div>
        </div>
    `).join('')}
    <button class="no-print" onclick="window.print()">Imprimir</button>
</body>
</html>
            `;
            printWindow.document.write(html);
            printWindow.document.close();
        }

        // Manage statuses modal
        // remove manage status button from insert/edit modal (business uses Status as funil names)
        try { const manageBtn = document.getElementById('manageStatusBtn'); if (manageBtn) manageBtn.remove(); } catch(e){}
        const addStatusBtn = document.getElementById('addStatusBtn');
        if (addStatusBtn) {
            addStatusBtn.addEventListener('click', ()=>{
                const name = (document.getElementById('newStatusName')||{}).value || '';
                if (!name.trim()) return alert('Informe o nome do status');
                addStatusEntry(name.trim());
                document.getElementById('newStatusName').value = '';
            });
        }

        // uncheck all selected leads
        const uncheckBtn = $('#bulkUncheckBtn'); if (uncheckBtn) {
            uncheckBtn.addEventListener('click', ()=>{
                // clear persisted selections and uncheck all visible checkboxes
                try { SELECTED_LEADS.clear(); } catch(e){}
                $all('.lead-select').forEach(chk => {
                    try { chk.checked = false; const tr = chk.closest('tr'); if (tr) tr.classList.remove('selected'); const card = chk.closest('.lead-card'); if (card) card.classList.remove('selected'); } catch(e){}
                });
                updateBulkDeleteVisibility();
            });
        }

        // bulk apply
        const bulkApply = $('#bulkApply'); if (bulkApply) {
            bulkApply.addEventListener('click', async ()=>{
                const ids = getSelectedLeadIds(); if (!ids.length) return alert('Nenhum lead selecionado');
                const stageId = $('#bulkTargetStage').value; const assign = $('#bulkAssign').value;
                const deleteChecked = $('#bulkDeleteCheck').checked;
                if (!deleteChecked && !stageId) return alert('Escolha uma etapa alvo ou marque para excluir');
                bulkApply.disabled = true; bulkApply.textContent = 'Aplicando...';
                try {
                    if (deleteChecked) {
                        for (let id of ids) {
                            const formData = new FormData();
                            formData.append('id', id);
                            const res = await fetch(apiBase + '?action=delete', { method: 'POST', body: formData });
                            if (!res.ok) throw new Error('Erro ao excluir ' + id);
                        }
                    } else {
                        for (let id of ids) {
                            await updateStatus(id, '', {stage_id: stageId});
                            // optionally implement assignment
                        }
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
        showPreloader('Carregando dados...');
        try{
            await fetchStatuses(); await fetchStages(); await fetchLeads();
            // fetch anuncios and render KPI/card
            try { const ads = await fetchAnuncios(); if (ads && ads.length) { const adsCol = document.getElementById('anunciosFixedColumn'); if (adsCol) adsCol.classList.remove('d-none'); } } catch(e){}
            setupDragDrop(); setupHandlers();
            const trashedModal = $('#trashedModal');
            if (trashedModal) {
                trashedModal.addEventListener('show.bs.modal', loadTrashedModal);
            }
            await loadPaymentMethods();
            // ensure view mode applied after initial data load
            renderAll();
            await nextPaint();
            const bulkDeleteBtn = $('#bulkDeleteBtn');
            if (bulkDeleteBtn) {
                bulkDeleteBtn.addEventListener('click', async () => {
                    const ids = getSelectedLeadIds();
                    if (!ids.length) return;
                    if (!confirm(`Mover ${ids.length} lead(s) selecionado(s) para a lixeira?`)) return;
                    try {
                        for (const id of ids) {
                            const formData = new FormData();
                            formData.append('id', id);
                            const res = await fetch(apiBase + '?action=delete', { method: 'POST', body: formData });
                            if (!res.ok) throw new Error('Erro ao mover ' + id + ' para lixeira');
                        }
                        await fetchLeads();
                    } catch (err) {
                        console.error(err);
                        alert('Erro ao mover leads para lixeira');
                    }
                });
            }
        }catch(err){ console.error(err); alert('Erro inicial: '+err.message); }
        finally { hidePreloader(); }
    });

    // Load payment methods and populate select
    async function loadPaymentMethods(){
        const sel = document.getElementById('lead-forma-pagamento');
        if (!sel) return;
        const current = sel.value || '';
        try {
            const res = await fetch('includes/payment_methods_api.php?action=list');
            if (!res.ok) return;
            const data = await res.json();
            sel.innerHTML = '<option value="">-- selecione --</option>';
            data.forEach(d=>{
                const o = document.createElement('option');
                o.value = String(d.id);
                o.textContent = d.name;
                sel.appendChild(o);
            });
            if (current) sel.value = current;
        } catch (e) {
            console.warn('Failed loading payment methods', e);
        }
    }

    // Trash functions
    async function showTrashView() {
        $('#kanbanWrap').classList.add('d-none');
        $('#trashWrap').classList.remove('d-none');
        await loadTrash();
    }

    function showKanbanView() {
        $('#trashWrap').classList.add('d-none');
        $('#kanbanWrap').classList.remove('d-none');
    }

    async function loadTrash() {
        try {
            const res = await fetch(apiBase + '?action=list_trash');
            if (!res.ok) throw new Error('Erro ao carregar lixeira');
            const trash = await res.json();
            renderTrash(trash);
        } catch (err) {
            console.error(err);
            alert('Erro ao carregar lixeira');
        }
    }

    function renderTrash(trash) {
        const tbody = $('#trashTableBody');
        tbody.innerHTML = trash.map(lead => `
            <tr>
                <td>${escapeText(lead.name)}</td>
                <td>${escapeText(lead.email || '')}</td>
                <td>${escapeText(lead.phone || '')}</td>
                <td>${escapeText(lead.status)}</td>
                <td>${formatDateBR(lead.deleted_at)}</td>
                <td>
                    <button class="btn btn-sm btn-outline-success" onclick="restoreLead(${lead.id})">Restaurar</button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deletePermanent(${lead.id})">Excluir</button>
                </td>
            </tr>
        `).join('');
    }

    async function restoreLead(id) {
        if (!confirm('Restaurar este lead?')) return;
        try {
            const formData = new FormData();
            formData.append('id', id);
            const res = await fetch(apiBase + '?action=restore', { method: 'POST', body: formData });
            if (res.ok) {
                await loadTrash();
            } else {
                alert('Erro ao restaurar lead');
            }
        } catch (err) {
            console.error(err);
            alert('Erro ao restaurar lead');
        }
    }

    // Make functions global for onclick
    window.restoreLead = restoreLead;
    window.deletePermanent = deletePermanent;

})();
