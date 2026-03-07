// SLA check: move projects to 'Renovação de Contrato' at 11 months after homologation (closed_date)
(function(){
    async function runSlaCheck(){
        try {
            const res = await fetch('/WRCRM/api/get_projects.php');
            if (!res.ok) return;
            const payload = await res.json();
            if (!payload.success || !Array.isArray(payload.data)) return;
            const projects = payload.data;
            const now = new Date();
            for (const p of projects) {
                const closed = p.closed_date || p.closedDate || null;
                if (!closed) continue;
                const d = new Date(closed);
                if (isNaN(d.getTime())) continue;
                const months = (now.getFullYear() - d.getFullYear()) * 12 + (now.getMonth() - d.getMonth());
                // when reaching 11 months -> trigger move to 'Renovação de Contrato'
                if (months >= 11 && String(p.status || '').trim() !== 'Renovação de Contrato') {
                    // update project status
                    try {
                        const form = new URLSearchParams();
                        form.append('id', String(p.id));
                        form.append('status', 'Renovação de Contrato');
                        // send partial update (update_project accepts partial fields)
                        const up = await fetch('/WRCRM/api/update_project.php', { method: 'POST', body: form });
                        if (up.ok) {
                            // create alert for commercial
                            try {
                                const alertForm = new URLSearchParams();
                                alertForm.append('project_id', String(p.id));
                                alertForm.append('type', 'renovation');
                                alertForm.append('message', `Projeto ${p.client_name || p.id} atingiu 11 meses pós-homologação.`);
                                await fetch('/WRCRM/api/add_alert.php', { method: 'POST', body: alertForm });
                            } catch(e) { console.warn('Failed creating alert', e); }
                        }
                    } catch(e){ console.warn('Failed updating project status for SLA', e); }
                }
            }
        } catch (e) { console.warn('SLA check failed', e); }
    }

    // Run on load and once per day while page is open
    document.addEventListener('DOMContentLoaded', ()=>{
        runSlaCheck();
        // schedule daily run
        try { setInterval(runSlaCheck, 1000 * 60 * 60 * 24); } catch(e){}
    });
})();
