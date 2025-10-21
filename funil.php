<?php
$stages = ['Prospecção','Visita Técnica','Proposta Enviada','Negociação','Fechado','Instalação','Finalizado','Perdido'];
?>
<div id="funil" class="flex space-x-4">
<?php foreach($stages as $stage): ?>
    <div class="kanban-column">
        <h3><?= $stage ?></h3>
        <div class="column-content">
        <?php
        $cards = $pdo->prepare("SELECT * FROM projetos WHERE status=?");
        $cards->execute([$stage]);
        while($c = $cards->fetch()):
        ?>
            <div class="kanban-card"><?= htmlspecialchars($c['client_name']) ?></div>
        <?php endwhile; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>
