<?php
$totalLeads = $pdo->query("SELECT COUNT(*) FROM leads WHERE status NOT IN ('Convertido','Perdido')")->fetchColumn();
$totalProjetos = $pdo->query("SELECT COUNT(*) FROM projetos WHERE status NOT IN ('Finalizado','Perdido')")->fetchColumn();
$valorNegociacao = $pdo->query("SELECT SUM(proposal_value) FROM projetos WHERE status NOT IN ('Finalizado','Perdido')")->fetchColumn();
$projetosFinalizados = $pdo->query("SELECT COUNT(*) FROM projetos WHERE status='Finalizado'")->fetchColumn();
?>
<div id="dashboard">
    <h1 class="text-3xl font-bold mb-6">Relatórios e Análises</h1>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="bg-white p-6 rounded-lg shadow-md"><h3>Leads Ativos</h3><p><?= $totalLeads ?></p></div>
        <div class="bg-white p-6 rounded-lg shadow-md"><h3>Projetos em Andamento</h3><p><?= $totalProjetos ?></p></div>
        <div class="bg-white p-6 rounded-lg shadow-md"><h3>Valor em Negociação</h3><p>R$ <?= number_format($valorNegociacao,2,',','.') ?></p></div>
        <div class="bg-white p-6 rounded-lg shadow-md"><h3>Projetos Finalizados</h3><p><?= $projetosFinalizados ?></p></div>
    </div>
</div>
