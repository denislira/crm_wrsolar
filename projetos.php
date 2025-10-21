<?php
$projetos = $pdo->query("SELECT * FROM projetos ORDER BY id DESC")->fetchAll();
?>
<div id="projetos">
    <h1 class="text-3xl font-bold mb-6">Clientes e Projetos</h1>
    <table class="w-full text-left">
        <thead><tr><th>Cliente</th><th>Endereço</th><th>Valor</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach($projetos as $p): ?>
            <tr>
                <td><?= htmlspecialchars($p['client_name']) ?></td>
                <td><?= htmlspecialchars($p['address']) ?></td>
                <td>R$ <?= number_format($p['proposal_value'],2,',','.') ?></td>
                <td><?= htmlspecialchars($p['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
