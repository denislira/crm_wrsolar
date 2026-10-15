<div class="d-flex flex-wrap gap-2 mb-4" role="navigation" aria-label="Módulos do Financeiro">
  <a class="btn btn-sm <?= ($financeiroView ?? '') === 'entradas' ? 'btn-primary' : 'btn-outline-primary' ?>" href="financeiro.php">Vendas e conferência</a>
  <a class="btn btn-sm <?= ($financeiroView ?? '') === 'visao' ? 'btn-primary' : 'btn-outline-primary' ?>" href="financeiro_modulo.php?view=visao">Visão geral</a>
  <a class="btn btn-sm <?= ($financeiroView ?? '') === 'receber' ? 'btn-primary' : 'btn-outline-primary' ?>" href="financeiro_modulo.php?view=receber">Contas a receber</a>
  <a class="btn btn-sm <?= ($financeiroView ?? '') === 'pagar' ? 'btn-primary' : 'btn-outline-primary' ?>" href="financeiro_modulo.php?view=pagar">Contas a pagar</a>
  <a class="btn btn-sm <?= ($financeiroView ?? '') === 'resumo' ? 'btn-primary' : 'btn-outline-primary' ?>" href="financeiro_modulo.php?view=resumo">Fluxo e resumo</a>
  <a class="btn btn-sm <?= ($financeiroView ?? '') === 'notas' ? 'btn-primary' : 'btn-outline-primary' ?>" href="financeiro_modulo.php?view=notas">Notas fiscais</a>
  <a class="btn btn-sm <?= ($financeiroView ?? '') === 'importar' ? 'btn-primary' : 'btn-outline-primary' ?>" href="financeiro_modulo.php?view=importar">Importar Excel/CSV</a>
  <?php if (function_exists('isDirector') && isDirector() && hasPermission('configuracoes')): ?><a class="btn btn-sm btn-outline-secondary" href="financeiro_acessos.php">Permissões de usuários</a><?php endif; ?>
</div>
