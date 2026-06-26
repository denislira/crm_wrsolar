document.addEventListener('DOMContentLoaded', function() {
    const statusEl = document.getElementById('waStatus');
    const qrContainer = document.getElementById('waQrContainer');
    const qrImage = document.getElementById('waQrImage');
    const btnGenerate = document.getElementById('btnGenerateQr');
    const btnRefresh = document.getElementById('btnRefreshWa');
    const btnDisconnect = document.getElementById('btnDisconnectWa');

    const baseRoot = '/' + (window.location.pathname.split('/')[1] || '');
    const apiPath = (p) => baseRoot + '/api/' + p;

    console.log('wa_integration: loaded');

    // fetch a URL as blob and set to the QR image element. Returns true on success.
    async function fetchAndSetImage(url) {
        try {
            const res = await fetch(url, { cache: 'no-store' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const blob = await res.blob();
            if (!blob || !blob.size) throw new Error('empty blob');
            // revoke previous object URL if present
            if (qrImage._objectUrl) { URL.revokeObjectURL(qrImage._objectUrl); qrImage._objectUrl = null; }
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

    async function loadStatus() {
        try {
            const res = await fetch(apiPath('wa_status.php'));
            const data = await res.json();
            console.log('wa_status:', data);
            if (data.connected) {
                statusEl.innerText = 'Conectado - ' + (data.info || 'online');
                qrContainer.classList.add('d-none');
                btnDisconnect.classList.remove('d-none');
            } else {
                statusEl.innerText = data.info ? 'Não conectado - ' + data.info : 'Não conectado';
                btnDisconnect.classList.add('d-none');
                // remove any previous hint
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
                        hint.textContent = 'QR não disponível. Inicie o wa-service e clique em Atualizar.';
                    }
                } else if (data.qr) {
                    const success = await fetchAndSetImage(data.qr);
                    if (!success) {
                        // fallback: add a clickable link so user can open QR directly
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
                    console.log('wa_status: no qr in response');
                }
            }
        } catch (e) {
            statusEl.innerText = 'Erro ao obter status';
            console.error(e);
        }
    }

    async function generateQr() {
        btnGenerate.disabled = true;
        try {
            const res = await fetch(apiPath('wa_generate_qr.php'), { method: 'POST' });
            const data = await res.json();
            if (!data.success) {
                alert(data.message || 'Não foi possível obter o QRCODE');
                await loadStatus();
                return;
            }
            statusEl.innerText = data.message || 'QR encontrado. Aguarde a leitura no WhatsApp.';
            await loadStatus();
        } catch (e) {
            alert('Erro ao obter o QRCODE');
            console.error(e);
        } finally {
            btnGenerate.disabled = false;
        }
    }

    async function disconnect() {
        if (!confirm('Desconectar WhatsApp?')) return;
        btnDisconnect.disabled = true;
        try {
            const res = await fetch(apiPath('wa_disconnect.php'), { method: 'POST' });
            const data = await res.json();
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
    btnRefresh.addEventListener('click', loadStatus);
    btnDisconnect.addEventListener('click', disconnect);
    // manual QR modal
    const btnManualQr = document.getElementById('btnManualQr');
    if (btnManualQr) btnManualQr.addEventListener('click', () => {
        const m = new bootstrap.Modal(document.getElementById('manualQrModal'));
        m.show();
    });

    document.getElementById('manualQrForm')?.addEventListener('submit', async function(e){
        e.preventDefault();
        const text = document.getElementById('manualQrText').value.trim();
        const url = document.getElementById('manualQrUrl').value.trim();
        if (!text && !url) { alert('Cole o texto do QR ou a URL da imagem.'); return; }
        try {
            const fd = new FormData();
            if (text) fd.append('qr_text', text);
            if (url) fd.append('qr_url', url);
            const res = await fetch(apiPath('wa_save_qr.php'), { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message || 'Salvo');
            if (data.success) {
                // close modal and refresh
                bootstrap.Modal.getInstance(document.getElementById('manualQrModal'))?.hide();
                await loadStatus();
            }
        } catch (e) { console.error(e); alert('Erro ao salvar QR'); }
    });

    // Poll status every 5s while the tab is visible
    let polling = null;
    function startPolling() {
        if (polling) return;
        polling = setInterval(loadStatus, 5000);
    }
    function stopPolling() {
        if (!polling) return;
        clearInterval(polling); polling = null;
    }

    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') startPolling(); else stopPolling();
    });

    // initial
    loadStatus();
    startPolling();
});
