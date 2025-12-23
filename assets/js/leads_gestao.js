(function(){
    // Simple, dependency-free implementation for the leads management UI
    const apiBase = 'includes/leads_api.php';
    let allLeads = [];

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

    function toCurrency(v){ return 'R$ ' + (Number(v)||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); }

    function renderKpis(){
        const active = allLeads.filter(l=>!['Perdido','Ganhou'].includes(l.status));
        const hot = allLeads.filter(l=>l.score>=80).length;
        const totalValue = allLeads.reduce((s,l)=>s + (parseFloat(l.estimativa_projeto_kwh||0) || 0), 0);
        const conv = allLeads.length ? (allLeads.filter(l=>l.status==='Ganhou').length / allLeads.length * 100).toFixed(1) : '0.0';
        $('#kpiActive').textContent = active.length;
        $('#kpiHot').textContent = hot;
        $('#kpiValue').textContent = toCurrency(totalValue);
        $('#kpiConv').textContent = conv + '%';
    }

    function clearColumns(){ $all('.column-content').forEach(c=>c.innerHTML=''); }

    function makeCard(lead){
        const el = document.createElement('div'); el.className='lead-card'; el.draggable = true; el.dataset.id = lead.id;
        const title = document.createElement('div'); title.className='title'; title.textContent = escapeText(lead.name || '(sem nome)');
        const company = document.createElement('div'); company.className='lead-meta'; company.textContent = (lead.client_name || lead.company || '—');
        const meta = document.createElement('div'); meta.className='lead-meta';
        const value = document.createElement('span'); value.textContent = toCurrency(lead.proposal_value || lead.estimativa_projeto_kwh || 0);
        const owner = document.createElement('span'); owner.textContent = lead.responsavel || '';
        const score = document.createElement('span'); score.className = 'badge-score ' + (lead.score>=80?'hot':(lead.score>=50?'warm':'cold')); score.textContent = lead.score;
        meta.appendChild(value); meta.appendChild(owner); meta.appendChild(score);
        el.appendChild(title); el.appendChild(company); el.appendChild(meta);

        // events
        el.addEventListener('click', (e)=>{ openPanel(lead.id); });
        el.addEventListener('dragstart', (e)=>{ e.dataTransfer.setData('text/plain', lead.id); e.dataTransfer.effectAllowed='move'; setTimeout(()=>el.classList.add('dragging'),0); });
        el.addEventListener('dragend', ()=>el.classList.remove('dragging'));
        return el;
    }

    function renderAll(){
        clearColumns();
        const stages = ['Novo','Contato Feito','Proposta Enviada','Negociação','Ganhou','Perdeu'];
        stages.forEach(s=>{
            const col = document.getElementById('col-' + s);
            const rows = allLeads.filter(l=> (l.status||'Novo') === s );
            rows.forEach(r=> col.appendChild(makeCard(r)));
            const countEl = document.getElementById('count-' + s);
            if (countEl) countEl.textContent = rows.length;
        });
        renderKpis();
    }

    function setupDragDrop(){
        $all('.column-content').forEach(col=>{
            col.addEventListener('dragover', (e)=>{ e.preventDefault(); col.classList.add('drag-over'); e.dataTransfer.dropEffect='move'; });
            col.addEventListener('dragleave', ()=>col.classList.remove('drag-over'));
            col.addEventListener('drop', async (e)=>{
                e.preventDefault(); col.classList.remove('drag-over');
                const id = e.dataTransfer.getData('text/plain');
                const stage = col.parentElement.dataset.stage;
                try {
                    await updateStatus(id, stage);
                    // optimistic local update
                    const item = allLeads.find(x=>String(x.id)===String(id)); if (item) item.status = stage;
                    renderAll();
                    flashFeedback(col, true);
                } catch(err){ flashFeedback(col, false); console.error(err); }
            });
        });
    }

    async function updateStatus(id, status){
        const body = new URLSearchParams(); body.append('action','update_status'); body.append('id', id); body.append('status', status);
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

    function openPanel(id){
        const lead = allLeads.find(l=>String(l.id)===String(id)); if (!lead) return;
        const p = $('#leadDetailContent'); p.innerHTML = '';
        const title = document.createElement('h4'); title.textContent = lead.name || '(sem nome)';
        const status = document.createElement('div'); status.className='mb-2 small text-muted'; status.textContent = 'Status: ' + (lead.status||'Novo');
        const company = document.createElement('div'); company.textContent = 'Empresa: ' + (lead.client_name|| lead.company|| '—');
        const email = document.createElement('div'); email.innerHTML = 'Email: ' + (lead.email? `<a href="mailto:${encodeURIComponent(lead.email)}">${lead.email}</a>` : '—');
        const phone = document.createElement('div'); phone.innerHTML = 'Telefone: ' + (lead.phone? `<a href="tel:${encodeURIComponent(lead.phone)}">${lead.phone}</a>` : '—');
        const value = document.createElement('div'); value.textContent = 'Valor estimado: ' + toCurrency(lead.proposal_value || lead.estimativa_projeto_kwh || 0);
        const notes = document.createElement('div'); notes.className='mt-3'; notes.textContent = 'Notas: ' + (lead.notes || '—');
        const btns = document.createElement('div'); btns.className='mt-3 d-flex gap-2';
        const callBtn = document.createElement('a'); callBtn.className='btn btn-sm btn-outline-primary'; callBtn.href = lead.phone? 'tel:'+lead.phone:'#'; callBtn.textContent='Ligar';
        const whatsappBtn = document.createElement('a'); whatsappBtn.className='btn btn-sm btn-outline-success'; whatsappBtn.href = lead.phone? 'https://wa.me/'+lead.phone.replace(/\D/g,''):'#'; whatsappBtn.target='_blank'; whatsappBtn.textContent='WhatsApp';
        const proposalBtn = document.createElement('button'); proposalBtn.className='btn btn-sm btn-primary'; proposalBtn.textContent='Enviar proposta';
        btns.appendChild(callBtn); btns.appendChild(whatsappBtn); btns.appendChild(proposalBtn);

        // Upload documents
        const uploadForm = document.createElement('form'); uploadForm.enctype='multipart/form-data'; uploadForm.innerHTML = `\n            <div class="mt-3"><label class="form-label">Enviar documento</label><input name="anexos" type="file" class="form-control form-control-sm"></div>\n            <div class="mt-2"><button class="btn btn-sm btn-secondary" type="submit">Enviar</button></div>`;
        uploadForm.addEventListener('submit', async (e)=>{
            e.preventDefault(); const fd = new FormData(); fd.append('action','update'); fd.append('id', lead.id);
            const f = uploadForm.querySelector('input[type=file]'); if (f.files.length===0) return alert('Escolha um arquivo'); fd.append('anexos[]', f.files[0]);
            try{
                const res = await fetch(apiBase, {method:'POST', body: fd}); const json = await res.json(); if (json.error) throw new Error(json.error);
                alert('Arquivo enviado'); await fetchLeads(); openPanel(lead.id);
            }catch(err){ alert('Falha ao enviar: ' + err.message); }
        });

        p.appendChild(title); p.appendChild(status); p.appendChild(company); p.appendChild(email); p.appendChild(phone); p.appendChild(value); p.appendChild(notes); p.appendChild(btns); p.appendChild(uploadForm);
        const panel = $('#leadDetailsPanel'); panel.classList.remove('hidden');
    }

    function closePanel(){ $('#leadDetailsPanel').classList.add('hidden'); }

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
            const filtered = allLeads.filter(l => (l.name||'').toLowerCase().includes(v) || (l.client_name||'').toLowerCase().includes(v)); clearColumns(); filtered.forEach(l=>{ const col = document.getElementById('col-' + (l.status||'Novo')); if(col) col.appendChild(makeCard(l)); });
        });

        $('#filterScore').addEventListener('change', (e)=>{
            const v = e.target.value; if (!v) { renderAll(); return; } const map = {hot: l=>l.score>=80, warm: l=>l.score>=50 && l.score<80, cold: l=>l.score<50};
            clearColumns(); allLeads.filter(map[v]).forEach(l=>{ const col = document.getElementById('col-' + (l.status||'Novo')); if(col) col.appendChild(makeCard(l)); });
        });

        // close panel on escape
        document.addEventListener('keydown', (e)=>{ if (e.key==='Escape') closePanel(); });
    }

    // initial
    document.addEventListener('DOMContentLoaded', async ()=>{
        try{ await fetchLeads(); setupDragDrop(); setupHandlers(); }catch(err){ console.error(err); alert('Erro inicial: '+err.message); }
    });

})();
