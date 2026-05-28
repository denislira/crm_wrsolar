// SLA check: move post-sales records to the configured renewal stage at 11 months after homologation.
(function(){
    let missingTargetWarned = false;

    const APP_ROOT = (() => {
        const script = document.currentScript;
        if (!script || !script.src) return '';
        return script.src.replace(/\/assets\/js\/sla_check\.js(?:\?.*)?$/, '');
    })();

    async function fetchRenewalTargetStage(){
        const res = await fetch(`${APP_ROOT}/includes/pos_venda_stages_api.php?action=list`);
        if (!res.ok) return null;
        const stages = await res.json();
        if (!Array.isArray(stages)) return null;
        return stages.find(stage => Number(stage.sla_renewal_target || 0) === 1) || null;
    }

    async function runSlaCheck(){
        try {
            const renewalTargetStage = await fetchRenewalTargetStage();
            if (!renewalTargetStage || !renewalTargetStage.name) {
                if (!missingTargetWarned) {
                    console.warn('SLA renewal target stage is not configured.');
                    missingTargetWarned = true;
                }
                return;
            }
            missingTargetWarned = false;

            const res = await fetch(`${APP_ROOT}/api/get_projects.php?for_sla=1`);
            if (!res.ok) return;
            const payload = await res.json();
            if (!payload.success || !Array.isArray(payload.data)) return;
            const projects = payload.data;
            const now = new Date();
            for (const p of projects) {
                const closed = p.closed_date || p.closedDate || p.installation_date || p.installationDate || null;
                if (!closed) continue;
                const d = new Date(closed);
                if (isNaN(d.getTime())) continue;
                const months = (now.getFullYear() - d.getFullYear()) * 12 + (now.getMonth() - d.getMonth());
                const currentStage = String(p.pos_venda_stage || '').trim();
                if (months >= 11 && currentStage !== renewalTargetStage.name && Number(p.pos_venda_id || 0) > 0) {
                    try {
                        const form = new FormData();
                        form.append('action', 'update_stage');
                        form.append('pv_id', String(p.pos_venda_id));
                        form.append('stage', renewalTargetStage.name);
                        const up = await fetch(`${APP_ROOT}/pos-venda.php`, { method: 'POST', body: form });
                        if (up.ok) {
                            try {
                                const alertForm = new URLSearchParams();
                                alertForm.append('project_id', String(p.id));
                                alertForm.append('type', 'renovation');
                                alertForm.append('message', `Projeto ${p.client_name || p.id} atingiu 11 meses pós-homologação e foi movido para ${renewalTargetStage.name}.`);
                                await fetch(`${APP_ROOT}/api/add_alert.php`, { method: 'POST', body: alertForm });
                            } catch(e) { console.warn('Failed creating alert', e); }
                        }
                    } catch(e){ console.warn('Failed updating post-sale stage for SLA', e); }
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
