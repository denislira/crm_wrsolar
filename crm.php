<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SolarCRM - Gestão e Análise para Energia Solar</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .kanban-column { min-height: 400px; }
        .kanban-card { cursor: grab; transition: background-color 0.2s; }
        .kanban-card:hover { background-color: #fef9c3; }
        .kanban-card:active { cursor: grabbing; }
        .dragging { opacity: 0.5; border: 2px dashed #fbbf24; }
        .drag-over { background-color: #fef3c7; }
        .sidebar-scroll::-webkit-scrollbar { display: none; }
        .sidebar-scroll { -ms-overflow-style: none; scrollbar-width: none; }
        .nav-link.active { background-color: #fbbf24; color: #422006; }
        .empty-state {
            text-align: center;
            padding: 4rem 1rem;
            border: 2px dashed #e5e7eb;
            border-radius: 0.5rem;
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">

    <div id="app-container" class="flex h-screen bg-gray-100">
        <!-- Sidebar -->
        <aside class="w-64 bg-gray-800 text-white flex flex-col fixed h-full z-20">
            <div class="px-6 py-4 border-b border-gray-700">
                <div class="flex items-center space-x-3">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="#f59e0b" stroke="white" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                    <h1 class="text-xl font-bold text-white">SolarCRM</h1>
                </div>
            </div>
            <nav class="flex-1 overflow-y-auto sidebar-scroll">
                <ul class="py-4">
                    <li><a href="#dashboard" class="nav-link flex items-center px-6 py-3 text-gray-300 font-medium hover:bg-gray-700 hover:text-white rounded-r-full mr-4"><i data-lucide="bar-chart-3" class="w-5 h-5 mr-3"></i>Relatórios</a></li>
                    <li><a href="#visualizacao" class="nav-link flex items-center px-6 py-3 text-gray-300 font-medium hover:bg-gray-700 hover:text-white rounded-r-full mr-4"><i data-lucide="pie-chart" class="w-5 h-5 mr-3"></i>Visualização</a></li>
                    <li><a href="#leads" class="nav-link flex items-center px-6 py-3 text-gray-300 font-medium hover:bg-gray-700 hover:text-white rounded-r-full mr-4"><i data-lucide="users" class="w-5 h-5 mr-3"></i>Leads</a></li>
                    <li><a href="#funil" class="nav-link flex items-center px-6 py-3 text-gray-300 font-medium hover:bg-gray-700 hover:text-white rounded-r-full mr-4"><i data-lucide="filter" class="w-5 h-5 mr-3"></i>Funil de Vendas</a></li>
                    <li><a href="#projetos" class="nav-link flex items-center px-6 py-3 text-gray-300 font-medium hover:bg-gray-700 hover:text-white rounded-r-full mr-4"><i data-lucide="folder-kanban" class="w-5 h-5 mr-3"></i>Projetos</a></li>
                    <li><a href="#pos-venda" class="nav-link flex items-center px-6 py-3 text-gray-300 font-medium hover:bg-gray-700 hover:text-white rounded-r-full mr-4"><i data-lucide="shield-check" class="w-5 h-5 mr-3"></i>Pós-venda</a></li>
                </ul>
            </nav>
            <div class="px-4 py-3 border-t border-gray-700">
                <p class="text-xs text-gray-400">Seu User ID (para colaboração):</p>
                <p id="userIdDisplay" class="text-xs text-gray-300 break-words"></p>
            </div>
        </aside>

        <!-- Main content -->
        <main class="flex-1 ml-64 p-6 md:p-8 overflow-y-auto">
            
            <!-- Views -->
            <div id="dashboard" class="view hidden">
                <h1 class="text-3xl font-bold mb-6 text-gray-800">Relatórios e Análises</h1>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div class="bg-white p-6 rounded-lg shadow-md"><h3 class="text-gray-500 font-semibold">Leads Ativos</h3><p id="total-leads" class="text-3xl font-bold mt-2">0</p></div>
                    <div class="bg-white p-6 rounded-lg shadow-md"><h3 class="text-gray-500 font-semibold">Projetos em Andamento</h3><p id="total-projetos" class="text-3xl font-bold mt-2">0</p></div>
                    <div class="bg-white p-6 rounded-lg shadow-md"><h3 class="text-gray-500 font-semibold">Valor em Negociação</h3><p id="valor-negociacao" class="text-3xl font-bold mt-2">R$ 0,00</p></div>
                    <div class="bg-white p-6 rounded-lg shadow-md"><h3 class="text-gray-500 font-semibold">Projetos Finalizados</h3><p id="projetos-finalizados" class="text-3xl font-bold mt-2">0</p></div>
                </div>
            </div>

            <div id="visualizacao" class="view hidden">
                <h1 class="text-3xl font-bold mb-6 text-gray-800">Visualização de Dados</h1>
                 <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <h3 class="font-semibold mb-4">Funil de Conversão de Projetos</h3>
                        <canvas id="funnelChart"></canvas>
                    </div>
                     <div class="bg-white p-6 rounded-lg shadow-md">
                        <h3 class="font-semibold mb-4">Origem dos Leads</h3>
                        <canvas id="leadSourceChart"></canvas>
                    </div>
                     <div class="bg-white p-6 rounded-lg shadow-md col-span-1 lg:col-span-2">
                        <h3 class="font-semibold mb-4">Vendas Mensais (Projetos Fechados)</h3>
                        <canvas id="salesChart"></canvas>
                    </div>
                </div>
            </div>

            <div id="leads" class="view hidden">
                 <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-bold">Gestão de Leads</h1>
                    <button id="add-lead-btn" class="bg-amber-500 text-white font-semibold px-4 py-2 rounded-lg hover:bg-amber-600 flex items-center shadow-sm"><i data-lucide="plus" class="w-5 h-5 mr-2"></i>Novo Lead</button>
                </div>
                <div class="bg-white p-6 rounded-lg shadow-md">
                    <div id="leads-table-container" class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="border-b">
                                    <th class="py-2 px-2">Nome</th><th>Contato</th><th>Fonte</th><th>Status</th><th>Ações</th>
                                </tr>
                            </thead>
                            <tbody id="leads-table-body">
                                <!-- Leads will be injected here -->
                            </tbody>
                        </table>
                        <div id="leads-empty-state" class="hidden empty-state">
                            <i data-lucide="user-plus" class="mx-auto w-12 h-12 text-gray-400"></i>
                            <h3 class="mt-4 text-lg font-semibold">Nenhum lead encontrado</h3>
                            <p class="text-gray-500">Adicione um novo lead para começar a gerenciar suas oportunidades.</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="funil" class="view hidden">
                 <h1 class="text-3xl font-bold mb-6">Funil de Vendas</h1>
                 <div id="kanban-container" class="flex overflow-x-auto space-x-4 pb-4">
                    <!-- Kanban columns will be injected here -->
                 </div>
            </div>

            <div id="projetos" class="view hidden">
                <h1 class="text-3xl font-bold mb-6">Clientes e Projetos</h1>
                <div class="bg-white p-6 rounded-lg shadow-md">
                     <div id="projects-table-container" class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="border-b">
                                    <th class="py-2 px-2">Cliente</th><th>Endereço</th><th>Valor</th><th>Status</th><th>Ações</th>
                                </tr>
                            </thead>
                            <tbody id="projects-table-body">
                                <!-- Projects will be injected here -->
                            </tbody>
                        </table>
                         <div id="projects-empty-state" class="hidden empty-state">
                            <i data-lucide="folder-plus" class="mx-auto w-12 h-12 text-gray-400"></i>
                            <h3 class="mt-4 text-lg font-semibold">Nenhum projeto encontrado</h3>
                            <p class="text-gray-500">Converta um lead em projeto para começar.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div id="pos-venda" class="view hidden">
                <h1 class="text-3xl font-bold mb-6">Acompanhamento Pós-venda</h1>
                <div class="bg-white p-6 rounded-lg shadow-md">
                     <div id="pos-venda-table-container" class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="border-b">
                                    <th class="py-2 px-2">Cliente</th><th>Instalação</th><th>Próx. Manutenção</th><th>Garantia</th><th>Ações</th>
                                </tr>
                            </thead>
                            <tbody id="pos-venda-table-body">
                                <?php include 'includes/header.php'; ?>

                                <div class="d-flex">
                                    <?php include 'includes/sidebar.php'; ?>
                                    <main class="flex-grow-1 p-4">
                                        <div class="container-fluid">
                                            <!-- Views -->
                                            <div id="dashboard" class="mb-4">
                                                <h1 class="h4 mb-3">Relatórios e Análises</h1>
                                                <div class="row g-3">
                                                    <div class="col-12 col-md-6 col-lg-3"><div class="card p-3 card-shadow"> <div class="text-muted">Leads Ativos</div><div id="total-leads" class="h5">0</div></div></div>
                                                    <div class="col-12 col-md-6 col-lg-3"><div class="card p-3 card-shadow"> <div class="text-muted">Projetos em Andamento</div><div id="total-projetos" class="h5">0</div></div></div>
                                                    <div class="col-12 col-md-6 col-lg-3"><div class="card p-3 card-shadow"> <div class="text-muted">Valor em Negociação</div><div id="valor-negociacao" class="h5">R$ 0,00</div></div></div>
                                                    <div class="col-12 col-md-6 col-lg-3"><div class="card p-3 card-shadow"> <div class="text-muted">Projetos Finalizados</div><div id="projetos-finalizados" class="h5">0</div></div></div>
                                                </div>
                                            </div>

                                            <div id="visualizacao" class="mb-4">
                                                <h2 class="h5 mb-3">Visualização de Dados</h2>
                                                <div class="row g-3">
                                                    <div class="col-12 col-lg-6"><div class="card p-3 card-shadow"><canvas id="funnelChart"></canvas></div></div>
                                                    <div class="col-12 col-lg-6"><div class="card p-3 card-shadow"><canvas id="leadSourceChart"></canvas></div></div>
                                                    <div class="col-12"><div class="card p-3 card-shadow mt-3"><canvas id="salesChart"></canvas></div></div>
                                                </div>
                                            </div>

                                            <div id="leads" class="mb-4">
                                                <div class="d-flex justify-content-between align-items-center mb-3">
                                                    <h2 class="h5 mb-0">Gestão de Leads</h2>
                                                    <button id="add-lead-btn" class="btn btn-primary">Novo Lead</button>
                                                </div>
                                                <div class="card card-shadow p-3">
                                                    <div id="leads-table-container" class="table-responsive"></div>
                                                </div>
                                            </div>

                                            <div id="funil" class="mb-4">
                                                <h2 class="h5 mb-3">Funil de Vendas</h2>
                                                <div id="kanban-container" class="d-flex overflow-auto gap-3 pb-3"></div>
                                            </div>

                                            <div id="projetos" class="mb-4">
                                                <h2 class="h5 mb-3">Clientes e Projetos</h2>
                                                <div class="card card-shadow p-3">
                                                    <div id="projects-table-container" class="table-responsive"></div>
                                                </div>
                                            </div>

                                            <div id="pos-venda" class="mb-4">
                                                <h2 class="h5 mb-3">Acompanhamento Pós-venda</h2>
                                                <div class="card card-shadow p-3">
                                                    <div id="pos-venda-table-container" class="table-responsive"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </main>
                                </div>

                                <?php include 'includes/footer.php'; ?>
            } else {
                emptyState.classList.add('hidden');
                tableBody.innerHTML = leads.map(lead => `
                    <tr class="border-b hover:bg-gray-50">
                        <td class="py-3 px-2 font-medium">${lead.name}</td>
                        <td class="py-3 px-2 text-sm text-gray-600">${lead.email || ''}<br>${lead.phone || ''}</td>
                        <td class="py-3 px-2">${lead.source}</td>
                        <td class="py-3 px-2"><span class="px-2 py-1 text-xs font-semibold rounded-full ${lead.status === 'Convertido' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'}">${lead.status}</span></td>
                        <td class="py-3 px-2 flex items-center space-x-2">
                            ${lead.status !== 'Convertido' ? `<button class="convert-lead-btn bg-green-500 text-white text-xs px-2 py-1 rounded hover:bg-green-600" data-id='${lead.id}'>Converter</button>` : ''}
                            <button class="edit-lead-btn" data-id='${lead.id}'><i data-lucide="edit" class="w-4 h-4 text-gray-500 hover:text-amber-600"></i></button>
                            <button class="delete-lead-btn" data-id='${lead.id}'><i data-lucide="trash-2" class="w-4 h-4 text-red-500 hover:text-red-700"></i></button>
                        </td>
                    </tr>
                `).join('');
            }
            lucide.createIcons();
        }
        
        function renderProjects(projects) {
            const tableBody = document.getElementById('projects-table-body');
            const emptyState = document.getElementById('projects-empty-state');
            if (projects.length === 0) {
                tableBody.innerHTML = '';
                emptyState.classList.remove('hidden');
            } else {
                emptyState.classList.add('hidden');
                tableBody.innerHTML = projects.map(p => `
                     <tr class="border-b hover:bg-gray-50">
                        <td class="py-3 px-2 font-medium">${p.clientName}</td>
                        <td class="py-3 px-2 text-sm text-gray-600">${p.address || 'N/A'}</td>
                        <td class="py-3 px-2">R$ ${parseFloat(p.proposalValue || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</td>
                        <td class="py-3 px-2"><span class="px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">${p.status}</span></td>
                        <td class="py-3 px-2 flex items-center space-x-2">
                            <button class="edit-project-btn" data-id='${p.id}'><i data-lucide="edit" class="w-4 h-4 text-gray-500 hover:text-amber-600"></i></button>
                            <button class="delete-project-btn" data-id='${p.id}'><i data-lucide="trash-2" class="w-4 h-4 text-red-500 hover:text-red-700"></i></button>
                        </td>
                    </tr>
                `).join('');
            }
            lucide.createIcons();
        }
        
        function renderFunil(projects) {
            const funilContainer = document.getElementById('kanban-container');
            funilContainer.innerHTML = FUNNEL_STAGES.map(stage => `
                <div class="kanban-column flex-shrink-0 w-72 bg-gray-100 rounded-lg p-3" data-stage="${stage}">
                    <h3 class="font-semibold mb-3 text-gray-700 flex justify-between">${stage} <span class="bg-gray-200 text-gray-600 text-xs font-bold px-2 py-1 rounded-full">${projects.filter(p => p.status === stage).length}</span></h3>
                    <div class="space-y-3 column-content">
                        ${projects.filter(p => p.status === stage).map(p => `
                            <div class="kanban-card bg-white p-4 rounded-md shadow-sm border" draggable="true" data-id="${p.id}">
                                <p class="font-semibold text-sm">${p.clientName}</p>
                                <p class="text-xs text-gray-500 mt-1">R$ ${parseFloat(p.proposalValue || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</p>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `).join('');
            setupDragAndDrop();
        }

        function renderPosVenda(projects, posVendaData) {
            const tableBody = document.getElementById('pos-venda-table-body');
            const emptyState = document.getElementById('pos-venda-empty-state');
            const finalizados = projects.filter(p => p.status === 'Finalizado');
            if(finalizados.length === 0) {
                tableBody.innerHTML = '';
                emptyState.classList.remove('hidden');
            } else {
                 emptyState.classList.add('hidden');
                 tableBody.innerHTML = finalizados.map(p => {
                    const data = posVendaData.find(pv => pv.projectId === p.id) || {};
                    return `
                        <tr class="border-b hover:bg-gray-50">
                            <td class="py-3 px-2 font-medium">${p.clientName}</td>
                            <td class="py-3 px-2 text-sm">${data.installationDate || 'Não definida'}</td>
                            <td class="py-3 px-2 text-sm">${data.nextMaintenance || 'Não definida'}</td>
                            <td class="py-3 px-2 text-sm">${data.warrantyEndDate || 'Não definida'}</td>
                            <td class="py-3 px-2">
                                 <button class="edit-pos-venda-btn p-2 rounded-md hover:bg-gray-200" data-project-id='${p.id}' data-client-name='${p.clientName}'><i data-lucide="calendar-plus" class="w-5 h-5 text-gray-600"></i></button>
                            </td>
                        </tr>
                    `
                }).join('');
            }
            lucide.createIcons();
        }

        function updateDashboard(leads, projects) {
            const activeLeads = leads.filter(l => l.status !== 'Convertido' && l.status !== 'Perdido');
            const activeProjects = projects.filter(p => p.status !== 'Finalizado' && p.status !== 'Perdido');
            const negotiationValue = activeProjects.reduce((sum, p) => sum + parseFloat(p.proposalValue || 0), 0);
            const finishedProjects = projects.filter(p => p.status === 'Finalizado').length;

            document.getElementById('total-leads').textContent = activeLeads.length;
            document.getElementById('total-projetos').textContent = activeProjects.length;
            document.getElementById('valor-negociacao').textContent = `R$ ${negotiationValue.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}`;
            document.getElementById('projetos-finalizados').textContent = finishedProjects;
        }
        
        let chartInstances = {};
        function renderVisualizacoes(projects, leads) {
            // Funnel Chart
            const funnelCounts = FUNNEL_STAGES.reduce((acc, stage) => {
                acc[stage] = projects.filter(p => p.status === stage).length;
                return acc;
            }, {});
            renderChart('funnelChart', 'bar', {
                labels: FUNNEL_STAGES,
                datasets: [{
                    label: '# de Projetos',
                    data: Object.values(funnelCounts),
                    backgroundColor: 'rgba(251, 191, 36, 0.6)',
                    borderColor: 'rgba(251, 191, 36, 1)',
                    borderWidth: 1
                }]
            }, { indexAxis: 'y' });

            // Lead Source Chart
            const leadSourceCounts = leads.reduce((acc, lead) => {
                acc[lead.source] = (acc[lead.source] || 0) + 1;
                return acc;
            }, {});
            renderChart('leadSourceChart', 'doughnut', {
                labels: Object.keys(leadSourceCounts),
                datasets: [{
                    label: 'Origem dos Leads',
                    data: Object.values(leadSourceCounts),
                    backgroundColor: ['#f59e0b', '#facc15', '#fbbf24', '#d97706'],
                }]
            });
            
            // Sales Chart
            const salesByMonth = projects.filter(p => p.status === 'Fechado' && p.closedDate)
                .reduce((acc, p) => {
                    const month = new Date(p.closedDate).toLocaleString('default', { month: 'short', year: '2-digit' });
                    acc[month] = (acc[month] || 0) + parseFloat(p.proposalValue || 0);
                    return acc;
                }, {});
            const sortedMonths = Object.keys(salesByMonth).sort((a,b) => new Date(`1 ${a}`) - new Date(`1 ${b}`));
            renderChart('salesChart', 'line', {
                labels: sortedMonths,
                datasets: [{
                    label: 'Valor Fechado (R$)',
                    data: sortedMonths.map(m => salesByMonth[m]),
                    fill: false,
                    borderColor: 'rgb(245, 158, 11)',
                    tension: 0.1
                }]
            });
        }
        
        function renderChart(canvasId, type, data, options = {}) {
            const ctx = document.getElementById(canvasId).getContext('2d');
            if (chartInstances[canvasId]) {
                chartInstances[canvasId].destroy();
            }
            chartInstances[canvasId] = new Chart(ctx, { type, data, options });
        }
        
        // --- SPA NAVIGATION ---
        const navLinks = document.querySelectorAll('.nav-link');
        const views = document.querySelectorAll('.view');
        function navigate(hash) {
            views.forEach(view => view.classList.add('hidden'));
            const activeView = document.querySelector(hash);
            if(activeView) activeView.classList.remove('hidden');

            navLinks.forEach(link => {
                link.classList.toggle('active', link.getAttribute('href') === hash);
            });
        }
        window.addEventListener('hashchange', () => navigate(window.location.hash));
        
        // --- MODALS LOGIC ---
        const leadModal = document.getElementById('lead-modal');
        const projectModal = document.getElementById('project-modal');
        const posVendaModal = document.getElementById('pos-venda-modal');
        
        function openModal(modal) { modal.classList.remove('hidden'); }
        function closeModal(modal) { modal.classList.add('hidden'); }
        
        document.getElementById('add-lead-btn').addEventListener('click', () => {
            document.getElementById('lead-form').reset();
            document.getElementById('lead-id').value = '';
            document.getElementById('lead-modal-title').textContent = 'Novo Lead';
            openModal(leadModal);
        });
        
        [document.getElementById('cancel-lead'), document.getElementById('cancel-lead-x')].forEach(el => el.addEventListener('click', () => closeModal(leadModal)));
        [document.getElementById('cancel-project'), document.getElementById('cancel-project-x')].forEach(el => el.addEventListener('click', () => closeModal(projectModal)));
        [document.getElementById('cancel-pos-venda'), document.getElementById('cancel-pos-venda-x')].forEach(el => el.addEventListener('click', () => closeModal(posVendaModal)));
        
        // --- BUSINESS LOGIC (CRUD) ---
        // (This section remains largely the same, with one key addition)
        
        // Drag and Drop Logic
        let draggedItemId = null;
        function setupDragAndDrop() {
            // ... (same as before)
            const columns = document.querySelectorAll('.kanban-column .column-content');
            columns.forEach(column => {
                column.addEventListener('drop', async (e) => {
                    e.preventDefault();
                    column.parentElement.classList.remove('drag-over');
                    const newStage = column.parentElement.dataset.stage;
                    if (draggedItemId && newStage) {
                        const projectRef = doc(projectsCollection, draggedItemId);
                        const updateData = { status: newStage };
                        // **KEY ADDITION**: Set closedDate when moving to 'Fechado'
                        if(newStage === 'Fechado') {
                            updateData.closedDate = new Date().toISOString();
                        }
                        await updateDoc(projectRef, updateData);
                        draggedItemId = null;
                    }
                });
                // ... (other drag events)
            });
        }
        
        // Initial setup for modals and form submissions (same as before, not repeated for brevity)
        // ...
        
        // --- INITIALIZATION ---
        document.addEventListener('DOMContentLoaded', () => {
            initAuth();
            lucide.createIcons();
            const projectStatusSelect = document.getElementById('project-status');
            projectStatusSelect.innerHTML = FUNNEL_STAGES.map(s => `<option value="${s}">${s}</option>`).join('');
            
            // Set initial page
            if (!window.location.hash) {
                window.location.hash = '#dashboard';
            }
            navigate(window.location.hash);
        });

    </script>
    <script>
    // Paste the full CRUD and event listener logic from the previous script here.
    // This includes:
    // - lead-form submit event
    // - leadsTableBody click event (edit, delete, convert)
    // - project-form submit event
    // - projectsTableBody click event
    // - posVendaTableBody click event
    // - pos-venda-form submit event
    // - setupDragAndDrop full implementation
    
    // (Pasting the logic here to keep the thought process clean, it is identical to previous version with the exception of the closedDate addition which is shown above)

    const leadModal = document.getElementById('lead-modal');
    const projectModal = document.getElementById('project-modal');
    const posVendaModal = document.getElementById('pos-venda-modal');
    const db = getFirestore(); // Assume db is initialized in module script
    const leadsCollection = () => collection(db, `/artifacts/${appId}/users/${userId}/leads`);
    const projectsCollection = () => collection(db, `/artifacts/${appId}/users/${userId}/projects`);
    const posVendaCollection = () => collection(db, `/artifacts/${appId}/users/${userId}/pos-venda`);

    document.getElementById('lead-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = document.getElementById('lead-id').value;
        const leadData = {
            name: document.getElementById('lead-name').value,
            email: document.getElementById('lead-email').value,
            phone: document.getElementById('lead-phone').value,
            source: document.getElementById('lead-source').value,
            status: document.getElementById('lead-status').value,
        };

        try {
            if (id) {
                await setDoc(doc(leadsCollection(), id), leadData, { merge: true });
            } else {
                await addDoc(leadsCollection(), leadData);
            }
            closeModal(leadModal);
        } catch (error) { console.error("Error saving lead: ", error); }
    });

    document.getElementById('leads-table-body').addEventListener('click', async (e) => {
        const btn = e.target.closest('button');
        if(!btn) return;
        const id = btn.dataset.id;
        
        if (btn.classList.contains('edit-lead-btn')) {
            const leadData = allLeads.find(l => l.id === id);
            if (leadData) {
                document.getElementById('lead-id').value = id;
                document.getElementById('lead-name').value = leadData.name;
                document.getElementById('lead-email').value = leadData.email;
                document.getElementById('lead-phone').value = leadData.phone;
                document.getElementById('lead-source').value = leadData.source;
                document.getElementById('lead-status').value = leadData.status;
                document.getElementById('lead-modal-title').textContent = 'Editar Lead';
                openModal(leadModal);
            }
        } else if (btn.classList.contains('delete-lead-btn')) {
            if (confirm('Tem certeza que deseja excluir este lead?')) {
                await deleteDoc(doc(leadsCollection(), id));
            }
        } else if (btn.classList.contains('convert-lead-btn')) {
            const leadData = allLeads.find(l => l.id === id);
            if (leadData) {
                const projectData = {
                    clientName: leadData.name,
                    clientEmail: leadData.email,
                    clientPhone: leadData.phone,
                    status: 'Prospecção',
                    leadId: id
                };
                await addDoc(projectsCollection(), projectData);
                await updateDoc(doc(leadsCollection(), id), { status: 'Convertido' });
            }
        }
    });

    document.getElementById('project-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = document.getElementById('project-id').value;
        const projectData = {
            clientName: document.getElementById('project-clientName').value,
            clientEmail: document.getElementById('project-clientEmail').value,
            clientPhone: document.getElementById('project-clientPhone').value,
            address: document.getElementById('project-address').value,
            proposalValue: document.getElementById('project-proposalValue').value,
            status: document.getElementById('project-status').value,
        };
        if (id) {
            await setDoc(doc(projectsCollection(), id), projectData, { merge: true });
        }
        closeModal(projectModal);
    });

    document.getElementById('projects-table-body').addEventListener('click', async (e) => {
        const btn = e.target.closest('button');
        if(!btn) return;
        const id = btn.dataset.id;

        if (btn.classList.contains('edit-project-btn')) {
             const projectData = allProjects.find(p => p.id === id);
             if (projectData) {
                document.getElementById('project-form').reset();
                document.getElementById('project-id').value = id;
                document.getElementById('project-clientName').value = projectData.clientName;
                document.getElementById('project-clientEmail').value = projectData.clientEmail;
                document.getElementById('project-clientPhone').value = projectData.clientPhone;
                document.getElementById('project-address').value = projectData.address;
                document.getElementById('project-proposalValue').value = projectData.proposalValue;
                document.getElementById('project-status').value = projectData.status;
                document.getElementById('project-modal-title').textContent = 'Editar Projeto';
                openModal(projectModal);
             }
        } else if (btn.classList.contains('delete-project-btn')) {
             if (confirm('Tem certeza que deseja excluir este projeto?')) {
                await deleteDoc(doc(projectsCollection(), id));
            }
        }
    });

    document.getElementById('pos-venda-table-body').addEventListener('click', async (e) => {
        const btn = e.target.closest('button');
        if(!btn || !btn.classList.contains('edit-pos-venda-btn')) return;
        
        const projectId = btn.dataset.projectId;
        const clientName = btn.dataset.clientName;
        
        const existingData = allPosVenda.find(pv => pv.projectId === projectId);

        document.getElementById('pos-venda-form').reset();
        document.getElementById('pos-venda-projectId').value = projectId;
        document.getElementById('pos-venda-clientName').textContent = clientName;

        if (existingData) {
            document.getElementById('pos-venda-id').value = existingData.id;
            document.getElementById('pos-venda-installationDate').value = existingData.installationDate || '';
            document.getElementById('pos-venda-nextMaintenance').value = existingData.nextMaintenance || '';
            document.getElementById('pos-venda-warrantyEndDate').value = existingData.warrantyEndDate || '';
        } else {
            document.getElementById('pos-venda-id').value = '';
        }
        openModal(posVendaModal);
    });

    document.getElementById('pos-venda-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = document.getElementById('pos-venda-id').value;
        const data = {
            projectId: document.getElementById('pos-venda-projectId').value,
            installationDate: document.getElementById('pos-venda-installationDate').value,
            nextMaintenance: document.getElementById('pos-venda-nextMaintenance').value,
            warrantyEndDate: document.getElementById('pos-venda-warrantyEndDate').value,
        };
        if (id) {
            await setDoc(doc(posVendaCollection(), id), data, { merge: true });
        } else {
            await addDoc(posVendaCollection(), data);
        }
        closeModal(posVendaModal);
    });

    let draggedItemId = null;
    function setupDragAndDrop() {
        const cards = document.querySelectorAll('.kanban-card');
        const columns = document.querySelectorAll('.kanban-column .column-content');

        cards.forEach(card => {
            card.addEventListener('dragstart', (e) => {
                draggedItemId = e.target.dataset.id;
                setTimeout(() => e.target.classList.add('dragging'), 0);
            });
            card.addEventListener('dragend', (e) => e.target.classList.remove('dragging'));
        });

        columns.forEach(column => {
            column.addEventListener('dragover', (e) => {
                e.preventDefault();
                column.parentElement.classList.add('drag-over');
            });
            column.addEventListener('dragleave', () => column.parentElement.classList.remove('drag-over'));
            column.addEventListener('drop', async (e) => {
                e.preventDefault();
                column.parentElement.classList.remove('drag-over');
                const newStage = column.parentElement.dataset.stage;
                if (draggedItemId && newStage) {
                    const projectRef = doc(projectsCollection(), draggedItemId);
                    const updateData = { status: newStage };
                    if(newStage === 'Fechado') {
                        updateData.closedDate = new Date().toISOString();
                    }
                    await updateDoc(projectRef, updateData);
                    draggedItemId = null;
                }
            });
        });
    }

    </script>
</body>
</html>
