<?php
$leads = $pdo->query("SELECT * FROM leads ORDER BY id DESC")->fetchAll();
?>
<div id="leads">
    <h1 class="text-3xl font-bold mb-6">Gestão de Leads</h1>
    <table class="w-full text-left">
        <thead><tr><th>Nome</th><th>Contato</th><th>Fonte</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach($leads as $lead): ?>
            <tr>
                <td><?= htmlspecialchars($lead['name']) ?></td>
                <td><?= htmlspecialchars($lead['email'].' '.$lead['phone']) ?></td>
                <td><?= htmlspecialchars($lead['source']) ?></td>
                <td><?= htmlspecialchars($lead['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
