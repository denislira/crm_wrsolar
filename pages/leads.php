<div id="leads" class="view hidden">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">Gestão de Leads</h1>
        <button id="add-lead-btn" class="bg-amber-500 text-white font-semibold px-4 py-2 rounded-lg hover:bg-amber-600 flex items-center shadow-sm">
            <i data-lucide="plus" class="w-5 h-5 mr-2"></i>Novo Lead
        </button>
    </div>
    <div class="bg-white p-6 rounded-lg shadow-md">
        <div id="leads-table-container" class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b">
                        <th class="py-2 px-2">Nome</th>
                        <th>Contato</th>
                        <th>Fonte</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody id="leads-table-body"></tbody>
            </table>
            <div id="leads-empty-state" class="hidden empty-state">
                <i data-lucide="user-plus" class="mx-auto w-12 h-12 text-gray-400"></i>
                <h3 class="mt-4 text-lg font-semibold">Nenhum lead encontrado</h3>
                <p class="text-gray-500">Adicione um novo lead para começar a gerenciar suas oportunidades.</p>
            </div>
        </div>
    </div>
</div>
