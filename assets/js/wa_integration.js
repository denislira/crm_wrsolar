document.addEventListener('DOMContentLoaded', function() {
    const statusEl = document.getElementById('waStatus');
    const qrContainer = document.getElementById('waQrContainer');
    const qrImage = document.getElementById('waQrImage');
    const btnGenerate = document.getElementById('btnGenerateQr');
    const btnRefresh = document.getElementById('btnRefreshWa');
    const btnDisconnect = document.getElementById('btnDisconnectWa');
    const autoCreateLeads = document.getElementById('waAutoCreateLeads');
    const leadCaptureMode = document.getElementById('waLeadCaptureMode');
    const reopenAfterDays = document.getElementById('waReopenAfterDays');
    const reopenAfterDaysWrap = document.getElementById('waReopenAfterDaysWrap');
    const btnSaveSettings = document.getElementById('btnSaveWaSettings');
    const settingsFeedback = document.getElementById('waSettingsFeedback');
    let lastStatus = { connected: false };
    let statusErrorShown = false;

    const integrationScript = Array.from(document.scripts).find((script) =>
        /\/assets\/js\/wa_integration\.js(?:\?|$)/.test(script.src)
    );
    const scriptPath = integrationScript ? new URL(integrationScript.src, window.location.href).pathname : '';
    const baseRoot = scriptPath ? scriptPath.replace(/\/assets\/js\/wa_integration\.js$/, '') : '';
    const apiPath = (p) => baseRoot + '/api/' + p;

    async function fetchJson(url, options = {}) {
        const res = await fetch(url, options);
        const body = await res.text();
        let data;
        try {
            data = JSON.parse(body);
        } catch (e) {
            throw new Error('Resposta invalida da API (HTTP ' + res.status + ') em ' + url);
        }
        if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
        return data;
    }

    async function fetchAndSetImage(url) {
        try {
            const res = await fetch(url, { cache: 'no-store' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const blob = await res.blob();
            if (!blob || !blob.size) throw new Error('empty blob');
            if (qrImage._objectUrl) URL.revokeObjectURL(qrImage._objectUrl);
            const objUrl = URL.createObjectURL(blob);
            qrImage._objectUrl = objUrl;
            qrImage.src = objUrl;
            qrImage.style.display = 'block';
            qrContainer.classList.remove('d-none');
            return true;
        } catch (err) {
            console.error('wa_integration: fetchAndSetImage error', err);
            return false;
        }
    }

    function syncCaptureModeUi() {
        if (!reopenAfterDaysWrap || !leadCaptureMode) return;
        reopenAfterDaysWrap.classList.toggle('d-none', leadCaptureMode.value !== 'closed_after_days');
    }

    async function saveWaSettings() {
        [autoCreateLeads, leadCaptureMode, reopenAfterDays, btnSaveSettings].forEach(el => { if (el) el.disabled = true; });
        if (settingsFeedback) settingsFeedback.textContent = 'Salvando...';
        try {
            const body = new URLSearchParams({
                auto_create_leads: autoCreateLeads && autoCreateLeads.checked ? '1' : '0',
                lead_capture_mode: leadCaptureMode ? leadCaptureMode.value : 'new_only',
                reopen_after_days: reopenAfterDays ? reopenAfterDays.value : '30'
            });
            await fetchJson(apiPath('wa_save_settings.php'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body
            });
            if (settingsFeedback) settingsFeedback.textContent = 'Configuracoes salvas.';
        } finally {
            [autoCreateLeads, leadCaptureMode, reopenAfterDays, btnSaveSettings].forEach(el => { if (el) el.disabled = false; });
        }
    }

    async function loadStatus() {
        try {
            const data = await fetchJson(apiPath('wa_status.php'), { cache: 'no-store' });
            lastStatus = data;
            statusErrorShown = false;
            if (data.connected) {
                statusEl.innerText = 'Conectado - ' + (data.info || 'online');
                qrContainer.classList.add('d-none');
                btnDisconnect.classList.remove('d-none');
            } else {
                statusEl.innerText = data.info ? 'Nao conectado - ' + data.info : 'Nao conectado';
                btnDisconnect.classList.add('d-none');
                const prevHint = document.getElementById('waQrHint');
                if (prevHint) prevHint.remove();

                if (data.qr_data_uri) {
                    qrImage.src = data.qr_data_uri;
                    qrImage.style.display = 'block';
                    qrContainer.classList.remove('d-none');
                } else if (data.qr_image_url) {
                    const success = await fetchAndSetImage(data.qr_image_url);
                    if (!success) {
                        qrContainer.classList.add('d-none');
                        let hint = document.getElementById('waQrHint');
                        if (!hint) {
                            hint = document.createElement('div');
                            hint.id = 'waQrHint';
                            hint.className = 'small text-muted mt-2';
                            document.getElementById('waStatusCard').appendChild(hint);
                        }
                        hint.textContent = 'QR nao disponivel. Clique em Obter QR Code para gerar um novo.';
                    }
                } else if (data.qr) {
                    const success = await fetchAndSetImage(data.qr);
                    if (!success) {
                        let linkEl = document.getElementById('waQrLink');
                        if (!linkEl) {
                            linkEl = document.createElement('a');
                            linkEl.id = 'waQrLink';
                            linkEl.target = '_blank';
                            linkEl.className = 'd-block small mt-2';
                            document.getElementById('waStatusCard').appendChild(linkEl);
                        }
                        linkEl.href = data.qr;
                        linkEl.textContent = 'Abrir QR em nova aba';
                    }
                } else {
                    qrContainer.classList.add('d-none');
                }
            }
            if (autoCreateLeads && typeof data.auto_create_leads !== 'undefined') autoCreateLeads.checked = !!data.auto_create_leads;
            if (leadCaptureMode && data.lead_capture_mode) leadCaptureMode.value = data.lead_capture_mode;
            if (reopenAfterDays && data.reopen_after_days) reopenAfterDays.value = data.reopen_after_days;
            syncCaptureModeUi();
        } catch (e) {
            statusEl.innerText = 'WhatsApp indisponivel: ' + e.message;
            if (!statusErrorShown) console.error('wa_integration:', e);
            statusErrorShown = true;
        }
    }

    async function generateQr() {
        btnGenerate.disabled = true;
        try {
            const data = await fetchJson(apiPath('wa_generate_qr.php'), { method: 'POST' });
            if (!data.success) {
                alert(data.message || 'Nao foi possivel obter o QR Code');
                await loadStatus();
                return;
            }
            statusEl.innerText = data.message || 'Gerando QR real pelo Baileys...';
            await loadStatus();
        } catch (e) {
            alert('Erro ao obter o QR Code');
            console.error(e);
        } finally {
            btnGenerate.disabled = false;
        }
    }

    async function refreshWa() {
        if (lastStatus && lastStatus.connected) {
            await loadStatus();
            return;
        }
        await generateQr();
    }

    async function disconnect() {
        if (!confirm('Desconectar WhatsApp?')) return;
        btnDisconnect.disabled = true;
        try {
            const data = await fetchJson(apiPath('wa_disconnect.php'), { method: 'POST' });
            alert(data.message || 'Desconectado');
            await loadStatus();
        } catch (e) {
            alert('Erro ao desconectar');
            console.error(e);
        } finally {
            btnDisconnect.disabled = false;
        }
    }

    btnGenerate.addEventListener('click', generateQr);
    btnRefresh.addEventListener('click', refreshWa);
    btnDisconnect.addEventListener('click', disconnect);
    if (autoCreateLeads) {
        autoCreateLeads.addEventListener('change', function() {
            if (settingsFeedback) settingsFeedback.textContent = 'Alteracao pendente. Clique em salvar.';
        });
    }
    if (leadCaptureMode) {
        leadCaptureMode.addEventListener('change', function() {
            syncCaptureModeUi();
            if (settingsFeedback) settingsFeedback.textContent = 'Alteracao pendente. Clique em salvar.';
        });
    }
    if (reopenAfterDays) {
        reopenAfterDays.addEventListener('input', function() {
            if (settingsFeedback) settingsFeedback.textContent = 'Alteracao pendente. Clique em salvar.';
        });
    }
    if (btnSaveSettings) {
        btnSaveSettings.addEventListener('click', async function() {
            try { await saveWaSettings(); } catch (e) { if (settingsFeedback) settingsFeedback.textContent = 'Erro ao salvar.'; alert('Erro ao salvar configuracao do WhatsApp.'); console.error(e); }
        });
    }

    let polling = null;
    function startPolling() {
        if (!polling) polling = setInterval(loadStatus, 5000);
    }
    function stopPolling() {
        if (polling) {
            clearInterval(polling);
            polling = null;
        }
    }

    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') startPolling();
        else stopPolling();
    });

    syncCaptureModeUi();
    loadStatus();
    startPolling();
});
