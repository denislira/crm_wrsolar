<?php
// Ensure session started and user is authenticated
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Load PDO connection
require_once __DIR__ . '/includes/config.php';

// Fetch leads for current user
$stmt = $pdo->prepare('SELECT * FROM leads WHERE user_id = ? ORDER BY id DESC');
$stmt->execute([$_SESSION['user_id']]);
$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch funil stages to populate Status select (if any)
try {
    $colCheck = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funil_stages'");
    $colCheck->execute();
    $existingCols = $colCheck->fetchAll(PDO::FETCH_COLUMN);
    $nameCol = in_array('name', $existingCols) ? 'name' : (in_array('stage_name', $existingCols) ? 'stage_name' : 'name');
    $positionCol = in_array('position', $existingCols) ? 'position' : (in_array('stage_order', $existingCols) ? 'stage_order' : 'position');

    $sql = "SELECT id, {$nameCol} AS name FROM funil_stages WHERE user_id = ? ORDER BY COALESCE({$positionCol}, id) ASC";
    $stagesStmt = $pdo->prepare($sql);
    $stagesStmt->execute([$_SESSION['user_id']]);
    $funilStages = $stagesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $funilStages = [];
}

include 'includes/header.php';
?>
<div class="d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main class="flex-grow-1 p-4">
        <div id="leads" class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Gestão de Leads</h1>
        <div class="d-flex gap-2">
            <a href="import_leads.php" class="btn btn-outline-secondary">Importar CSV</a>
            <button id="open-add-lead" class="btn btn-primary">+ Novo Lead</button>
        </div>
    </div>

        <div class="card">
                <div class="card-body p-0">
                        <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                                <tr>
                                                        <th>Nome</th>
                                                        <th>Contato</th>
                                                        <th>CPF/CNPJ</th>
                                                        <th>Consumo (R$)</th>
                                                        <th>Estimativa (kWh)</th>
                                                        <th>Status</th>
                                                        <th>Anexos</th>
                                                        <th></th>
                                                </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach($leads as $lead): ?>
                                                <tr>
                                                        <td>
                                                            <?= htmlspecialchars($lead['name']) ?>
                                                            <?php if (!empty($lead['notes'])): ?>
                                                                <br><small class="text-muted"><?= htmlspecialchars(substr($lead['notes'], 0, 50)) ?><?= strlen($lead['notes']) > 50 ? '...' : '' ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?= htmlspecialchars($lead['email'] ?? '') ?><br>
                                                            <small><?= htmlspecialchars($lead['phone'] ?? '') ?></small>
                                                        </td>
                                                        <td><?= htmlspecialchars($lead['cpf_cnpj'] ?? '-') ?></td>
                                                        <td><?= $lead['consumo_cliente'] ? (is_numeric($lead['consumo_cliente']) ? 'R$ ' . number_format($lead['consumo_cliente'], 2, ',', '.') : $lead['consumo_cliente']) : '-' ?></td>
                                                        <td><?= $lead['estimativa_projeto_kwh'] ? (is_numeric($lead['estimativa_projeto_kwh']) ? number_format($lead['estimativa_projeto_kwh'], 2, ',', '.') . ' kWh' : $lead['estimativa_projeto_kwh']) : '-' ?></td>
                                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($lead['status']) ?></span></td>
                                                        <td class="text-center">
                                                            <?php if (!empty($lead['anexos_filename'])): ?>
                                                                <a href="includes/leads_api.php?action=download_anexo&id=<?= $lead['id'] ?>" class="btn btn-sm btn-outline-primary" title="Download: <?= htmlspecialchars($lead['anexos_filename']) ?>">
                                                                    <i class="fa-regular fa-circle-down"></i>
                                                                </a>
                                                            <?php else: ?>
                                                                -
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-end">
                                                                <button class="btn btn-sm btn-outline-secondary edit-lead-btn" data-id="<?= $lead['id'] ?>">Editar</button>
                                                                <button class="btn btn-sm btn-outline-danger delete-lead-btn" data-id="<?= $lead['id'] ?>">Excluir</button>
                                                        </td>
                                                </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                </table>
                        </div>
                </div>
        </div>
</div>

<!-- Reuse modal from funil.php if present; fallback simple modal -->
<div class="modal fade" id="leadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
            <div class="modal-header bg-light border-bottom">
                <h5 class="modal-title d-flex align-items-center gap-2" id="leadModalTitle">
                    <i class="fa-regular fa-user-plus text-primary"></i> <span>Novo Lead</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <form id="leadForm" enctype="multipart/form-data">
                    <input type="hidden" id="lead-id">
                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label class="form-label">Nome <i class="fa fa-user text-muted"></i></label>
                                    <input id="lead-name" class="form-control" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Status</label>
                                    <select id="lead-status" class="form-select">
                                        <?php if (!empty($funilStages)): ?>
                                            <?php foreach ($funilStages as $s): ?>
                                                <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="">Novo</option>
                                            <option value="">Qualificado</option>
                                            <option value="">Contato Realizado</option>
                                            <option value="">Perdido</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-body">
                                    <label class="form-label">Email <i class="fa fa-envelope text-muted"></i></label>
                                    <input id="lead-email" class="form-control" type="email">
                                    <label class="form-label mt-2">Telefone <i class="fa fa-phone text-muted"></i></label>
                                    <input id="lead-phone" class="form-control" type="tel">
                                    <label class="form-label mt-2">CPF / CNPJ <i class="fa fa-id-card text-muted"></i></label>
                                    <input id="lead-cpf-cnpj" class="form-control" placeholder="000.000.000-00 ou 00.000.000/0000-00">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-body">
                                    <label class="form-label">Consumo do Cliente (R$) <i class="fa fa-bolt text-warning"></i></label>
                                    <input id="lead-consumo" class="form-control" type="text" placeholder="Ex: 1500,00 ou texto">
                                    <label class="form-label mt-2">Estimativa do Projeto (kWh) <i class="fa fa-solar-panel text-info"></i></label>
                                    <input id="lead-estimativa-kwh" class="form-control" type="text" placeholder="Ex: 500 ou texto">
                                    <label class="form-label mt-2">Envio de Proposta <i class="fa fa-calendar text-muted"></i></label>
                                    <input id="lead-envio-proposta" class="form-control" type="date">
                                    <label class="form-label mt-2">Fonte <i class="fa fa-globe text-muted"></i></label>
                                    <input id="lead-source" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-body">
                                    <label class="form-label">Anexar Arquivos <i class="fa fa-paperclip text-muted"></i></label>
                                    <input id="lead-anexos" class="form-control" type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                    <div class="form-text">Formatos aceitos: PDF, DOC, DOCX, JPG, PNG</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card mb-3 h-100">
                                <div class="card-body d-flex flex-column h-100">
                                    <label class="form-label">Notas de Observação <i class="fa fa-sticky-note text-muted"></i></label>
                                    <textarea id="lead-notes" class="form-control" rows="4" placeholder="Digite suas observações sobre este lead..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button id="save-lead" type="button" class="btn btn-primary"><i class="fa fa-save"></i> Salvar</button>
            </div>
        </div>
    </div>
</div>

<script>
// Wire modal buttons (will be initialized in funil if also present)
document.addEventListener('DOMContentLoaded', () => {
    const addBtn = document.getElementById('open-add-lead');
    const leadModalEl = document.getElementById('leadModal');
    if(addBtn && leadModalEl){
        const leadModal = new bootstrap.Modal(leadModalEl);
        addBtn.addEventListener('click', ()=>{
            document.getElementById('leadForm').reset();
            document.getElementById('lead-id').value = '';
            document.getElementById('leadModalTitle').textContent = 'Novo Lead';
            leadModal.show();
        });

        document.getElementById('save-lead').addEventListener('click', async ()=>{
            const id = document.getElementById('lead-id').value;
            const formData = new FormData();
            
            // Adicionar dados básicos
            formData.append('name', document.getElementById('lead-name').value);
            formData.append('email', document.getElementById('lead-email').value);
            formData.append('phone', document.getElementById('lead-phone').value);
            formData.append('cpf_cnpj', document.getElementById('lead-cpf-cnpj').value);
            formData.append('source', document.getElementById('lead-source').value);
            // If the select uses stage IDs as values, send stage_id + status name for robustness
            const statusSelect = document.getElementById('lead-status');
            const selectedValue = statusSelect.value;
            const selectedText = statusSelect.options[statusSelect.selectedIndex]?.text || selectedValue;
            if (selectedValue && !isNaN(parseInt(selectedValue, 10))) {
                formData.append('stage_id', selectedValue);
                formData.append('status', selectedText);
            } else {
                // legacy fallback: no stage ids available, send status as text
                formData.append('status', selectedText || selectedValue);
            }
            formData.append('notes', document.getElementById('lead-notes').value);
            formData.append('consumo_cliente', document.getElementById('lead-consumo').value);
            formData.append('estimativa_projeto_kwh', document.getElementById('lead-estimativa-kwh').value);
            formData.append('envio_proposta', document.getElementById('lead-envio-proposta').value);
            
            // Adicionar arquivos se houver
            const fileInput = document.getElementById('lead-anexos');
            if (fileInput.files.length > 0) {
                for (let i = 0; i < fileInput.files.length; i++) {
                    formData.append('anexos[]', fileInput.files[i]);
                }
            }
            
            if (id) formData.append('id', id);
            
            const action = id ? 'update' : 'add';
            try {
                const res = await fetch('includes/leads_api.php?action='+action, { 
                    method: 'POST', 
                    body: formData 
                });
                const txt = await res.text();
                let payload;
                try { payload = JSON.parse(txt); } catch(e){ payload = { raw: txt }; }
                if (res.ok) {
                    leadModal.hide();
                    location.reload();
                } else {
                    console.error('Save lead failed', res.status, payload);
                    alert('Erro ao salvar lead: ' + (payload.error || payload.message || JSON.stringify(payload)));
                }
            } catch (err) {
                console.error('Network or unexpected error saving lead', err);
                alert('Erro ao salvar lead (network). Veja console.');
            }
        });

        document.querySelectorAll('.edit-lead-btn').forEach(btn=>{
            btn.addEventListener('click', async ()=>{
                try {
                    const id = btn.dataset.id;
                    const res = await fetch(`includes/leads_api.php?action=get&id=${id}`);
                    
                    if (!res.ok) {
                        throw new Error('Erro ao carregar dados do lead');
                    }
                    
                    const lead = await res.json();
                    
                    // Limpar formulário primeiro
                    document.getElementById('leadForm').reset();
                    
                    // Preencher campos
                    document.getElementById('lead-id').value = lead.id || '';
                    document.getElementById('lead-name').value = lead.name || '';
                    document.getElementById('lead-email').value = lead.email || '';
                    document.getElementById('lead-phone').value = lead.phone || '';
                    document.getElementById('lead-cpf-cnpj').value = lead.cpf_cnpj || '';
                    document.getElementById('lead-source').value = lead.source || '';
                    // Set status select by stage_id when available, else match by name, else fallback
                    const statusEl = document.getElementById('lead-status');
                    if (lead.stage_id && statusEl.querySelector(`option[value="${lead.stage_id}"]`)) {
                        statusEl.value = lead.stage_id;
                    } else {
                        // try matching by visible text
                        let matched = false;
                        for (let i=0;i<statusEl.options.length;i++){
                            if (statusEl.options[i].text === (lead.status || '')) { statusEl.selectedIndex = i; matched = true; break; }
                        }
                        if(!matched) statusEl.value = '';
                    }
                    document.getElementById('lead-notes').value = lead.notes || '';
                    document.getElementById('lead-consumo').value = lead.consumo_cliente || '';
                    document.getElementById('lead-estimativa-kwh').value = lead.estimativa_projeto_kwh || '';
                    // envio_proposta may be DATETIME 'YYYY-MM-DD HH:MM:SS' or NULL
                    if (lead.envio_proposta) {
                        document.getElementById('lead-envio-proposta').value = lead.envio_proposta.substring(0,10);
                    } else {
                        document.getElementById('lead-envio-proposta').value = '';
                    }
                    
                    // Mostrar info sobre arquivo anexado, se houver
                    const anexoInfo = document.getElementById('anexo-current-info');
                    if (anexoInfo) anexoInfo.remove(); // Remove info anterior se existir
                    
                    if (lead.anexos_filename) {
                        const infoDiv = document.createElement('div');
                        infoDiv.id = 'anexo-current-info';
                        infoDiv.className = 'alert alert-info mt-2';
                        infoDiv.innerHTML = `<small><i class="fa-regular fa-paperclip"></i> Arquivo atual: ${lead.anexos_filename}</small>`;
                        document.getElementById('lead-anexos').parentNode.appendChild(infoDiv);
                    }
                    
                    document.getElementById('leadModalTitle').textContent = 'Editar Lead';
                    leadModal.show();
                } catch (error) {
                    console.error('Erro ao carregar lead:', error);
                    alert('Erro ao carregar dados do lead. Tente novamente.');
                }
            });
        });

        document.querySelectorAll('.delete-lead-btn').forEach(btn=>{
            btn.addEventListener('click', async ()=>{
                if(!confirm('Excluir lead?')) return;
                const res = await fetch('includes/leads_api.php?action=delete', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id: btn.dataset.id }) });
                if(res.ok) location.reload(); else alert('Erro');
            });
        });
    }
});
</script>
    </main>
</div>

<?php include 'includes/footer.php'; ?>
