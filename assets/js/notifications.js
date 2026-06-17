(function(){
    const POLL_INTERVAL = 30000; // 30s
    const notified = new Set();
    let noticeTimer = null;

    function $(sel){ return document.querySelector(sel); }

    function toastContainer(){
        let c = document.getElementById('toastContainer');
        if (!c) {
            c = document.createElement('div');
            c.id = 'toastContainer';
            c.className = 'position-fixed bottom-0 end-0 p-3';
            c.style.zIndex = 1060;
            document.body.appendChild(c);
        }
        return c;
    }

    function noticeContainer(){
        let c = document.getElementById('globalNoticeContainer');
        if (!c) {
            c = document.createElement('div');
            c.id = 'globalNoticeContainer';
            c.style.cssText = [
                'position:fixed',
                'top:72px',
                'left:50%',
                'transform:translateX(-50%)',
                'width:min(720px,calc(100vw - 24px))',
                'z-index:1070',
                'pointer-events:none'
            ].join(';');
            document.body.appendChild(c);
        }
        return c;
    }

    function showGlobalNotice(message, type = 'info', timeout = 3200){
        const container = noticeContainer();
        container.innerHTML = '';
        const notice = document.createElement('div');
        const palette = {
            success: { border: '#86efac', bg: '#f0fdf4', fg: '#166534', icon: 'fa-circle-check' },
            danger: { border: '#fca5a5', bg: '#fef2f2', fg: '#991b1b', icon: 'fa-triangle-exclamation' },
            warning: { border: '#fcd34d', bg: '#fffbeb', fg: '#92400e', icon: 'fa-triangle-exclamation' },
            info: { border: '#93c5fd', bg: '#eff6ff', fg: '#1d4ed8', icon: 'fa-circle-info' }
        };
        const tone = palette[type] || palette.info;
        notice.className = 'shadow-sm';
        notice.style.cssText = [
            'pointer-events:auto',
            'display:flex',
            'align-items:flex-start',
            'gap:.75rem',
            'padding:.9rem 1rem',
            'border-radius:1rem',
            'border:1px solid ' + tone.border,
            'background:' + tone.bg,
            'color:' + tone.fg,
            'box-shadow:0 18px 40px rgba(15,23,42,.12)',
            'font-weight:600',
            'line-height:1.35'
        ].join(';');
        notice.innerHTML = `
            <div style="flex:0 0 auto;margin-top:.1rem;"><i class="fa-solid ${tone.icon}"></i></div>
            <div style="flex:1 1 auto;">${escapeHtml(String(message || ''))}</div>
            <button type="button" class="btn-close ms-2" aria-label="Fechar"></button>
        `;
        container.appendChild(notice);
        const closeBtn = notice.querySelector('button');
        const close = () => {
            if (noticeTimer) {
                clearTimeout(noticeTimer);
                noticeTimer = null;
            }
            try { notice.remove(); } catch (e) {}
            if (container.childElementCount === 0) container.innerHTML = '';
        };
        if (closeBtn) closeBtn.addEventListener('click', close);
        if (timeout !== 0) {
            noticeTimer = setTimeout(close, timeout);
        }
        return notice;
    }

    function flashGlobalNotice(message, type = 'info', timeout = 3200){
        try {
            sessionStorage.setItem('globalNoticeFlash', JSON.stringify({
                message: String(message || ''),
                type: type || 'info',
                timeout: timeout
            }));
        } catch (e) {}
        return showGlobalNotice(message, type, timeout);
    }

    function showToast(rem){
        const container = toastContainer();
        const id = 'toast-rem-' + rem.id;
        const toast = document.createElement('div');
        toast.className = 'toast';
        toast.id = id;
        toast.role = 'alert';
        toast.ariaLive = 'assertive';
        toast.ariaAtomic = 'true';
        toast.innerHTML = `
            <div class="toast-header">
                <strong class="me-auto">Lembrete</strong>
                <small class="text-muted ms-2">${new Date(rem.remind_at).toLocaleTimeString()}</small>
                <button type="button" class="btn-close ms-2 mb-1" data-bs-dismiss="toast" aria-label="Fechar"></button>
            </div>
            <div class="toast-body">${escapeHtml(rem.message)}</div>
        `;
        container.appendChild(toast);
        const bs = new bootstrap.Toast(toast, { delay: 10000 });
        bs.show();
        // remove after hidden
        toast.addEventListener('hidden.bs.toast', ()=>{ try{ toast.remove(); }catch(e){} });
    }

    function escapeHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    window.showGlobalNotice = showGlobalNotice;
    window.showAppNotice = showGlobalNotice;
    window.flashGlobalNotice = flashGlobalNotice;

    document.addEventListener('DOMContentLoaded', ()=>{
        try {
            const raw = sessionStorage.getItem('globalNoticeFlash');
            if (!raw) return;
            sessionStorage.removeItem('globalNoticeFlash');
            const data = JSON.parse(raw);
            if (data && data.message) {
                setTimeout(()=>showGlobalNotice(data.message, data.type || 'info', data.timeout ?? 3200), 50);
            }
        } catch (e) {}
    });

    async function markSent(id){
        try{
            const body = new URLSearchParams(); body.append('action','mark_sent'); body.append('id', id);
            const res = await fetch('includes/reminders_api.php', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString() });
            const j = await res.json();
            return j && j.ok;
        } catch(e){ console.warn('markSent error', e); return false; }
    }

    async function showNotificationFor(rem){
        if (!rem || !rem.id) return;
        // try Web Notifications
        if (window.Notification) {
            if (Notification.permission === 'granted') {
                try {
                    const n = new Notification('Lembrete', { body: rem.message, tag: 'reminder-' + rem.id, data: { id: rem.id } });
                    n.onclick = function(){ window.focus(); n.close(); };
                } catch(e){ showToast(rem); }
            } else if (Notification.permission === 'default') {
                try {
                    const p = await Notification.requestPermission();
                    if (p === 'granted') {
                        const n = new Notification('Lembrete', { body: rem.message, tag: 'reminder-' + rem.id, data: { id: rem.id } });
                        n.onclick = function(){ window.focus(); n.close(); };
                    } else {
                        showToast(rem);
                    }
                } catch(e){ showToast(rem); }
            } else {
                showToast(rem);
            }
        } else {
            showToast(rem);
        }
    }

    function updateBellList(rows){
        const countEl = $('#reminderBellCount');
        const listEl = $('#reminderBellList');
        if (countEl) countEl.textContent = String(rows.length || 0);
        if (!listEl) return;
        if (!rows || rows.length === 0) { listEl.innerHTML = '<div class="small text-muted px-2">Nenhum lembrete pendente.</div>'; return; }
        listEl.innerHTML = '';
        rows.slice(0,6).forEach(r=>{
            const it = document.createElement('div'); it.className = 'd-flex align-items-start gap-2 py-1';
            const left = document.createElement('div'); left.className = 'small text-muted'; left.style.minWidth='120px'; left.textContent = new Date(r.remind_at).toLocaleString();
            const mid = document.createElement('div'); mid.className = 'small text-truncate'; mid.style.maxWidth='140px'; mid.textContent = r.message;
            it.appendChild(left); it.appendChild(mid);
            listEl.appendChild(it);
        });
    }

    async function poll(){
        try{
            const res = await fetch('includes/reminders_api.php?action=list&status=pending');
            if (!res.ok) return;
            const rows = await res.json();
            if (!Array.isArray(rows)) return;
            // show only today's reminders in the bell dropdown
            const now = new Date();
            const todayRows = rows.filter(r=>{
                try{ const d = new Date(r.remind_at); return d.getFullYear()===now.getFullYear() && d.getMonth()===now.getMonth() && d.getDate()===now.getDate(); }catch(e){ return false; }
            });
            updateBellList(todayRows);
            // still process notifications for all pending reminders (in case some are due now)
            for (const r of rows) {
                try{
                    const remDate = new Date(r.remind_at);
                    if (remDate <= now && !notified.has(String(r.id))) {
                        // show and mark
                        await showNotificationFor(r);
                        const ok = await markSent(r.id);
                        if (ok) notified.add(String(r.id));
                    }
                } catch(e){ console.error('reminder process error', e); }
            }
        } catch(e){ console.warn('poll error', e); }
    }

    document.addEventListener('DOMContentLoaded', ()=>{
        // initial load
        poll();
        // periodic
        setInterval(poll, POLL_INTERVAL);
        // quick permission hint when user clicks bell: request permission if default
        const bell = document.getElementById('reminderBellBtn');
        if (bell) {
            bell.addEventListener('click', ()=>{
                if (window.Notification && Notification.permission === 'default') {
                    Notification.requestPermission().then(p=>{ /* no-op */ }).catch(()=>{});
                }
            });
        }
    });
})();
