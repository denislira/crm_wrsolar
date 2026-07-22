document.addEventListener('DOMContentLoaded', function() {
    const statusEl = document.getElementById('waStatus');
    const qrContainer = document.getElementById('waQrContainer');
    const qrImage = document.getElementById('waQrImage');
    const btnGenerate = document.getElementById('btnGenerateQr');
    const btnRefresh = document.getElementById('btnRefreshWa');
    const btnDisconnect = document.getElementById('btnDisconnectWa');
    let lastStatus = { connected: false };

    const integrationScript = Array.from(document.scripts).find((script) =>
        /\/assets\/js\/wa_integration\.js(?:\?|$)/.test(script.src)
    );
    const scriptPath = integrationScript ? new URL(integrationScript.src, window.location.href).pathname : '';
    const baseRoot = scriptPath ? scriptPath.replace(/\/assets\/js\/wa_integration\.js$/, '') : '';
    const apiPath = (p) => baseRoot + '/api/' + p;
    let statusErrorShown = false;

    console.log('wa_integration: loaded');

    async function fetchJson(url, options = {}) {
        const res = await fetch(url, options);
        const body = await res.text();
        let data;
        try {
            data = JSON.parse(body);
        } catch (e) {
            throw new Error('Resposta inválida da API (HTTP ' + res.status + ') em ' + url);
        }
        if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
        return data;
    }

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
            const data = await fetchJson(apiPath('wa_status.php'), { cache: 'no-store' });
            lastStatus = data;
            statusErrorShown = false;
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
                        hint.textContent = 'QR não disponível. Clique em Obter QR Code para gerar um novo.';
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
            statusEl.innerText = 'WhatsApp indisponível: ' + e.message;
            if (!statusErrorShown) console.error('wa_integration:', e);
            statusErrorShown = true;
        }
    }

    async function generateQr() {
        btnGenerate.disabled = true;
        try {
            const data = await fetchJson(apiPath('wa_generate_qr.php'), { method: 'POST' });
            if (!data.success) {
                alert(data.message || 'Não foi possível obter o QRCODE');
                await loadStatus();
                return;
            }
            statusEl.innerText = data.message || 'Gerando QR real pelo Baileys...';
            await loadStatus();
        } catch (e) {
            alert('Erro ao obter o QRCODE');
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
