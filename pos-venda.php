<?php
$posVendas = $pdo->query("SELECT p.client_name, pv.* FROM pos_venda pv JOIN projetos p ON pv.project_id=p.id")->fetchAll();
?>
<div id="pos-venda">
    <h1 class="text-3xl font-bold mb-6">Acompanhamento Pós-venda</h1>
    <table class="w-full text-left">
        <thead><tr><th>Cliente</th><th>Instalação</th><th>Próx. Manutenção</th><th>Garantia</th></tr></thead>
        <tbody>
        <?php foreach($posVendas as $pv): ?>
            <tr>
                <td><?= htmlspecialchars($pv['client_name']) ?></td>
                <td><?= htmlspecialchars($pv['installation_date']) ?></td>
                <td><?= htmlspecialchars($pv['next_maintenance']) ?></td>
                <td><?= htmlspecialchars($pv['warranty_end']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
