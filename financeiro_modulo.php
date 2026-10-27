<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/financeiro_lib.php';
require_once __DIR__.'/includes/financeiro_import.php';
checkAccessOrRedirect('financeiro');
$view=(string)($_GET['view']??'visao');
if (!in_array($view,['visao','receber','pagar','resumo','importar','notas'],true)) $view='visao';
$financeiroView=$view;
$error=''; $userId=(int)$_SESSION['user_id'];
if ($view==='importar' && $_SERVER['REQUEST_METHOD']==='POST') {
 try {
  fin_check_token();
  $importAction=(string)($_POST['action']??'');
  if ($importAction==='preview') {
   if (empty($_FILES['arquivo']['tmp_name']) || ($_FILES['arquivo']['error']??1)!==UPLOAD_ERR_OK) throw new RuntimeException('Selecione um arquivo válido.');
   $kind=(string)($_POST['tipo']??'');
   if (!in_array($kind,['vendas','receber','pagar','notas'],true)) throw new RuntimeException('Tipo de importação inválido.');
   $file=$_FILES['arquivo'];
   if (($file['size']??0)>10*1024*1024) throw new RuntimeException('Arquivo acima de 10 MB.');
   $rows=fin_read_import((string)$file['tmp_name'],(string)$file['name']);
   $headers=fin_import_headers($rows[0]??[]);
   if (isset($headers['senha'])) { foreach($rows as &$row) $row[$headers['senha']]=''; unset($row); }
   if (count($rows)<2) throw new RuntimeException('Arquivo sem linhas de dados.');
   if (count($rows)>5001) throw new RuntimeException('Importe no máximo 5.000 linhas por arquivo.');
   $hash=hash_file('sha256',(string)$file['tmp_name']);
   $already=$pdo->prepare('SELECT COUNT(*) FROM financeiro_importacoes WHERE sha256=? AND tipo=?'); $already->execute([$hash,$kind]);
   if ((int)$already->fetchColumn()) throw new RuntimeException('Este arquivo já foi importado para esta categoria.');
   $_SESSION['fin_import']=['hash'=>$hash,'name'=>basename((string)$file['name']),'kind'=>$kind,'rows'=>$rows,'created'=>time()];
  } elseif ($importAction==='confirm') {
   $session=$_SESSION['fin_import']??null;
   if (!$session || time()-$session['created']>3600) throw new RuntimeException('Prévia expirada. Envie o arquivo novamente.');
   $result=fin_commit_import($pdo,$session,$userId);
   unset($_SESSION['fin_import']);
   $_SESSION['fin_notice']='Importação concluída: '.$result['inserted'].' registros criados e '.$result['skipped'].' ignorados.';
   header('Location: financeiro_modulo.php?view=importar'); exit;
  } elseif ($importAction==='discard') unset($_SESSION['fin_import']);
  else throw new RuntimeException('Ação inválida.');
 } catch(Throwable $e) { $error=$e->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $view!=='importar') {
 try {
  fin_check_token();
  $action=(string)($_POST['action']??'');
  $pdo->beginTransaction();
  if ($action==='titulo') {
   $natureza=(string)($_POST['natureza']??'');
   if (!in_array($natureza,['receber','pagar'],true)) throw new InvalidArgumentException('Natureza inválida.');
   $categoria=trim((string)($_POST['categoria']??''));
   $descricao=trim((string)($_POST['descricao']??''));
   $contraparte=trim((string)($_POST['contraparte']??''));
   $vencimento=fin_date($_POST['vencimento']??'');
   $valor=fin_number($_POST['valor']??'');
   $saleId=(int)($_POST['venda_id']??0);
   if ($categoria==='' || $descricao==='' || !$vencimento || $valor===null || $valor<=0) throw new InvalidArgumentException('Preencha categoria, descrição, vencimento e valor positivo.');
   if ($saleId) {
    $check=$pdo->prepare("SELECT valor_negociado FROM financeiro_vendas WHERE id=? AND status='aceito' FOR UPDATE"); $check->execute([$saleId]);
    $saleAmount=$check->fetchColumn();
    if ($saleAmount===false) throw new InvalidArgumentException('Vincule apenas uma venda aceita.');
    if ($natureza==='receber') { $sum=$pdo->prepare("SELECT COALESCE(SUM(valor),0) FROM financeiro_titulos WHERE venda_id=? AND natureza='receber' AND cancelado_em IS NULL"); $sum->execute([$saleId]); if (round((float)$sum->fetchColumn()+$valor,2)>round((float)$saleAmount,2)) throw new InvalidArgumentException('A soma dos títulos a receber não pode exceder o valor da venda.'); }
   }
   $stmt=$pdo->prepare('INSERT INTO financeiro_titulos(venda_id,natureza,categoria,descricao,contraparte,documento,banco,vencimento,valor,criado_por) VALUES(?,?,?,?,?,?,?,?,?,?)');
   $stmt->execute([$saleId?:null,$natureza,mb_substr($categoria,0,60),mb_substr($descricao,0,255),mb_substr($contraparte,0,255)?:null,mb_substr(trim((string)($_POST['documento']??'')),0,100)?:null,mb_substr(trim((string)($_POST['banco']??'')),0,120)?:null,$vencimento,$valor,$userId]);
   $newId=(int)$pdo->lastInsertId(); fin_audit($pdo,'titulo',$newId,'criado',['natureza'=>$natureza,'valor'=>$valor,'venda_id'=>$saleId],$userId);
   $_SESSION['fin_notice']='Lançamento criado.';
   $view=$natureza;
  } elseif ($action==='baixa') {
   $titleId=(int)($_POST['titulo_id']??0);
   $stmt=$pdo->prepare('SELECT * FROM financeiro_titulos WHERE id=? AND cancelado_em IS NULL FOR UPDATE'); $stmt->execute([$titleId]); $title=$stmt->fetch(PDO::FETCH_ASSOC);
   if (!$title) throw new RuntimeException('Título não encontrado.');
   $cash=fin_number($_POST['valor']??''); $interest=fin_number($_POST['juros']??'0')??0; $discount=fin_number($_POST['desconto']??'0')??0;
   $date=fin_date($_POST['data_pagamento']??'');
   $applied=round(($cash??0)-$interest+$discount,2);
   $paid=$pdo->prepare('SELECT COALESCE(SUM(valor-juros+desconto),0) FROM financeiro_baixas WHERE titulo_id=?'); $paid->execute([$titleId]);
   $remaining=round((float)$title['valor']-(float)$paid->fetchColumn(),2);
   if (!$date || $cash===null || $cash<=0 || $interest<0 || $discount<0 || $applied<=0 || $applied>$remaining+0.001) throw new InvalidArgumentException('Baixa inválida ou acima do saldo. Valor é o caixa movimentado; juros e desconto ajustam o principal quitado.');
   $stmt=$pdo->prepare('INSERT INTO financeiro_baixas(titulo_id,data_pagamento,valor,juros,desconto,meio,observacao,criado_por) VALUES(?,?,?,?,?,?,?,?)');
   $stmt->execute([$titleId,$date,$cash,$interest,$discount,mb_substr(trim((string)($_POST['meio']??'')),0,60)?:null,mb_substr(trim((string)($_POST['observacao']??'')),0,10000)?:null,$userId]);
   fin_audit($pdo,'titulo',$titleId,'baixa',['caixa'=>$cash,'juros'=>$interest,'desconto'=>$discount,'principal'=>$applied],$userId);
   $_SESSION['fin_notice']='Baixa registrada. Saldo restante: '.fin_money(max(0,$remaining-$applied)).'.'; $view=$title['natureza'];
  } elseif ($action==='cancelar') {
   $titleId=(int)($_POST['titulo_id']??0);
   $stmt=$pdo->prepare('SELECT id,natureza FROM financeiro_titulos WHERE id=? AND cancelado_em IS NULL FOR UPDATE'); $stmt->execute([$titleId]); $title=$stmt->fetch(PDO::FETCH_ASSOC);
   if (!$title) throw new RuntimeException('Título não encontrado.');
   $check=$pdo->prepare('SELECT COUNT(*) FROM financeiro_baixas WHERE titulo_id=?'); $check->execute([$titleId]);
   if ((int)$check->fetchColumn()) throw new RuntimeException('Título com baixas não pode ser cancelado.');
   $pdo->prepare('UPDATE financeiro_titulos SET cancelado_em=NOW() WHERE id=?')->execute([$titleId]);
   fin_audit($pdo,'titulo',$titleId,'cancelado',['motivo'=>mb_substr(trim((string)($_POST['motivo']??'')),0,500)],$userId);
   $_SESSION['fin_notice']='Título cancelado.'; $view=$title['natureza'];
  } elseif ($action==='nota') {
   $tipo=(string)($_POST['tipo']??''); if (!in_array($tipo,['entrada','saida','direta'],true)) throw new InvalidArgumentException('Tipo de nota inválido.');
   $number=trim((string)($_POST['numero']??'')); $party=trim((string)($_POST['contraparte']??'')); $date=fin_date($_POST['emissao']??''); $value=fin_number($_POST['valor']??'');
   if ($number==='' || $party==='' || !$date || $value===null || $value<=0) throw new InvalidArgumentException('Preencha número, contraparte, emissão e valor positivo.');
   $saleId=(int)($_POST['venda_id']??0); $titleId=(int)($_POST['titulo_id']??0);
   $stmt=$pdo->prepare('INSERT INTO financeiro_notas(venda_id,titulo_id,tipo,numero,emissao,contraparte,valor,observacao,criado_por) VALUES(?,?,?,?,?,?,?,?,?)');
   $stmt->execute([$saleId?:null,$titleId?:null,$tipo,mb_substr($number,0,100),$date,mb_substr($party,0,255),$value,mb_substr(trim((string)($_POST['observacao']??'')),0,10000)?:null,$userId]);
   fin_audit($pdo,'nota',(int)$pdo->lastInsertId(),'criada',['tipo'=>$tipo,'numero'=>$number,'valor'=>$value],$userId);
   $_SESSION['fin_notice']='Nota fiscal cadastrada.'; $view='notas';
  } else throw new InvalidArgumentException('Ação inválida.');
  $pdo->commit();
  header('Location: financeiro_modulo.php?view='.$view.($action==='baixa'?'&titulo='.$titleId:'')); exit;
 } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error=$e->getMessage(); }
}

$notice=$_SESSION['fin_notice']??null; unset($_SESSION['fin_notice']);
$stats=$pdo->query("SELECT natureza,COUNT(*) quantidade,COALESCE(SUM(valor),0) total FROM financeiro_titulos WHERE cancelado_em IS NULL GROUP BY natureza")->fetchAll(PDO::FETCH_ASSOC);
$statMap=['receber'=>['quantidade'=>0,'total'=>0],'pagar'=>['quantidade'=>0,'total'=>0]]; foreach ($stats as $s) $statMap[$s['natureza']]=$s;
$cashStats=$pdo->query("SELECT t.natureza,COALESCE(SUM(b.valor),0) caixa,COALESCE(SUM(b.valor-b.juros+b.desconto),0) principal FROM financeiro_baixas b JOIN financeiro_titulos t ON t.id=b.titulo_id WHERE t.cancelado_em IS NULL GROUP BY t.natureza")->fetchAll(PDO::FETCH_ASSOC);
$cashMap=['receber'=>['caixa'=>0,'principal'=>0],'pagar'=>['caixa'=>0,'principal'=>0]]; foreach ($cashStats as $s) $cashMap[$s['natureza']]=$s;
$filter=(string)($_GET['situacao']??'aberto'); if (!in_array($filter,['aberto','quitado','todos'],true)) $filter='aberto';
$search=trim((string)($_GET['q']??'')); $page=max(1,(int)($_GET['page']??1)); $perPage=25;
$titleRows=[]; $totalRows=0; $selected=null; $settlements=[];
$receberDashboard=['vendas'=>0,'recebido'=>0,'a_receber'=>0,'taxa'=>0,'ativos'=>0,'pendentes'=>0]; $receberPrioridades=[];
if ($view==='receber' || $view==='pagar') {
 $where="t.natureza=? AND t.cancelado_em IS NULL"; $params=[$view];
 if ($search!=='') { $where.=' AND (t.descricao LIKE ? OR t.contraparte LIKE ? OR t.documento LIKE ? OR v.client_name LIKE ?)'; $like='%'.$search.'%'; array_push($params,$like,$like,$like,$like); }
 $having=$filter==='aberto'?' HAVING saldo>0.005':($filter==='quitado'?' HAVING saldo<=0.005':'');
 $base=" FROM (SELECT t.*,v.client_name,v.cidade,COALESCE(SUM(b.valor-b.juros+b.desconto),0) quitado,ROUND(t.valor-COALESCE(SUM(b.valor-b.juros+b.desconto),0),2) saldo FROM financeiro_titulos t LEFT JOIN financeiro_vendas v ON v.id=t.venda_id LEFT JOIN financeiro_baixas b ON b.titulo_id=t.id WHERE $where GROUP BY t.id $having) x";
 $count=$pdo->prepare('SELECT COUNT(*)'.$base); $count->execute($params); $totalRows=(int)$count->fetchColumn();
 $pages=max(1,(int)ceil($totalRows/$perPage)); $page=min($page,$pages);
 $list=$pdo->prepare('SELECT x.*'.$base.' ORDER BY x.vencimento ASC,x.id DESC LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage)); $list->execute($params); $titleRows=$list->fetchAll(PDO::FETCH_ASSOC);
 $selectedId=(int)($_GET['titulo']??0);
 if ($selectedId) {
  $stmt=$pdo->prepare("SELECT t.*,COALESCE(SUM(b.valor-b.juros+b.desconto),0) quitado FROM financeiro_titulos t LEFT JOIN financeiro_baixas b ON b.titulo_id=t.id WHERE t.id=? AND t.natureza=? AND t.cancelado_em IS NULL GROUP BY t.id"); $stmt->execute([$selectedId,$view]); $selected=$stmt->fetch(PDO::FETCH_ASSOC);
  if ($selected) { $stmt=$pdo->prepare('SELECT * FROM financeiro_baixas WHERE titulo_id=? ORDER BY data_pagamento DESC,id DESC'); $stmt->execute([$selectedId]); $settlements=$stmt->fetchAll(PDO::FETCH_ASSOC); }
 }
}
$receberDashboard['vendas']=(float)$pdo->query("SELECT COALESCE(SUM(valor_negociado),0) FROM financeiro_vendas WHERE status='aceito'")->fetchColumn();
if ($view==='receber') {
 $receberDashboard['recebido']=(float)$pdo->query("SELECT COALESCE(SUM(b.valor-b.juros+b.desconto),0) FROM financeiro_baixas b JOIN financeiro_titulos t ON t.id=b.titulo_id WHERE t.natureza='receber' AND t.cancelado_em IS NULL")->fetchColumn();
 $receberDashboard['a_receber']=max(0,$receberDashboard['vendas']-$receberDashboard['recebido']);
 $receberDashboard['taxa']=$receberDashboard['vendas']>0?($receberDashboard['recebido']/$receberDashboard['vendas']*100):0;
 $receberDashboard['ativos']=(int)$pdo->query("SELECT COUNT(DISTINCT venda_id) FROM financeiro_titulos WHERE natureza='receber' AND cancelado_em IS NULL AND venda_id IS NOT NULL")->fetchColumn();
 $receberDashboard['pendentes']=(int)$pdo->query("SELECT COUNT(*) FROM (SELECT t.id, ROUND(t.valor-COALESCE(SUM(b.valor-b.juros+b.desconto),0),2) saldo FROM financeiro_titulos t LEFT JOIN financeiro_baixas b ON b.titulo_id=t.id WHERE t.natureza='receber' AND t.cancelado_em IS NULL GROUP BY t.id HAVING saldo>0.005) x")->fetchColumn();
 $receberPrioridades=$pdo->query("SELECT t.contraparte, v.client_name, v.forma_pagamento, v.data_venda, t.vencimento, ROUND(t.valor-COALESCE(SUM(b.valor-b.juros+b.desconto),0),2) saldo, DATEDIFF(CURDATE(),COALESCE(v.data_venda,t.vencimento)) dias FROM financeiro_titulos t LEFT JOIN financeiro_vendas v ON v.id=t.venda_id LEFT JOIN financeiro_baixas b ON b.titulo_id=t.id WHERE t.natureza='receber' AND t.cancelado_em IS NULL GROUP BY t.id HAVING saldo>0.005 ORDER BY (DATEDIFF(CURDATE(),t.vencimento)>0) DESC,DATEDIFF(CURDATE(),t.vencimento) DESC,saldo DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
}
$period=(string)($_GET['mes']??date('Y-m')); if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$period)) $period=date('Y-m');
$periodStart=$period.'-01'; $periodEnd=(new DateTimeImmutable($periodStart))->modify('+1 month')->format('Y-m-d');
$monthCash=[]; $monthDue=[]; $byCategory=[];
if (in_array($view,['visao','resumo'],true)) {
 $stmt=$pdo->prepare('SELECT t.natureza,COALESCE(SUM(b.valor),0) total FROM financeiro_baixas b JOIN financeiro_titulos t ON t.id=b.titulo_id WHERE t.cancelado_em IS NULL AND b.data_pagamento>=? AND b.data_pagamento<? GROUP BY t.natureza'); $stmt->execute([$periodStart,$periodEnd]); foreach ($stmt as $row) $monthCash[$row['natureza']]=$row['total'];
 $stmt=$pdo->prepare('SELECT x.natureza,COALESCE(SUM(x.saldo),0) total FROM (SELECT t.id,t.natureza,t.valor-COALESCE(SUM(b.valor-b.juros+b.desconto),0) saldo FROM financeiro_titulos t LEFT JOIN financeiro_baixas b ON b.titulo_id=t.id WHERE t.cancelado_em IS NULL AND t.vencimento>=? AND t.vencimento<? GROUP BY t.id) x GROUP BY x.natureza'); $stmt->execute([$periodStart,$periodEnd]); foreach ($stmt as $row) $monthDue[$row['natureza']]=$row['total'];
 $stmt=$pdo->prepare('SELECT t.natureza,t.categoria,COALESCE(SUM(b.valor),0) total FROM financeiro_baixas b JOIN financeiro_titulos t ON t.id=b.titulo_id WHERE t.cancelado_em IS NULL AND b.data_pagamento>=? AND b.data_pagamento<? GROUP BY t.natureza,t.categoria ORDER BY t.natureza,t.categoria'); $stmt->execute([$periodStart,$periodEnd]); $byCategory=$stmt->fetchAll(PDO::FETCH_ASSOC);
}
$notes=[]; if ($view==='notas') $notes=$pdo->query('SELECT n.*,v.client_name FROM financeiro_notas n LEFT JOIN financeiro_vendas v ON v.id=n.venda_id ORDER BY n.emissao DESC,n.id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
include __DIR__.'/includes/header.php'; include __DIR__.'/includes/sidebar.php';
?>
<main class="main-content-scroll py-4"><div class="container-fluid">
 <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h1 class="h3 mb-1">Financeiro</h1><p class="text-muted mb-0">Contas, recebimentos, pagamentos e documentos integrados às vendas.</p></div></div>
 <?php include __DIR__.'/includes/financeiro_nav.php'; ?>
 <?php if($notice): ?><div class="alert alert-success"><?= fin_h($notice) ?></div><?php endif; ?>
 <?php if($error): ?><div class="alert alert-danger"><?= fin_h($error) ?></div><?php endif; ?>
 <?php if($view==='visao' || $view==='resumo'): ?>
  <form method="get" class="row g-2 align-items-end mb-3"><input type="hidden" name="view" value="<?= fin_h($view) ?>"><div class="col-auto"><label class="form-label">Competência</label><input class="form-control" type="month" name="mes" value="<?= fin_h($period) ?>"></div><div class="col-auto"><button class="btn btn-outline-primary">Filtrar</button></div></form>
  <div class="row g-3 mb-4">
   <?php foreach(['receber'=>'Receber','pagar'=>'Pagar'] as $kind=>$label): ?><div class="col-lg-3 col-sm-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Em aberto · <?= $label ?></div><div class="h4 mb-1"><?= fin_money(max(0,(float)$statMap[$kind]['total']-(float)$cashMap[$kind]['principal'])) ?></div><small><?= (int)$statMap[$kind]['quantidade'] ?> títulos</small></div></div></div><?php endforeach; ?>
   <div class="col-lg-3 col-sm-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Entradas no mês</div><div class="h4 mb-1 text-success"><?= fin_money($monthCash['receber']??0) ?></div><small>Baixas efetivas</small></div></div></div>
   <div class="col-lg-3 col-sm-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Saídas no mês</div><div class="h4 mb-1 text-danger"><?= fin_money($monthCash['pagar']??0) ?></div><small>Baixas efetivas</small></div></div></div>
  </div>
  <div class="row g-3"><div class="col-lg-6"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h5">Fluxo do mês</h2><table class="table"><tbody><tr><th>Entradas realizadas</th><td><?= fin_money($monthCash['receber']??0) ?></td></tr><tr><th>Saídas realizadas</th><td><?= fin_money($monthCash['pagar']??0) ?></td></tr><tr class="table-light"><th>Saldo realizado</th><td><?= fin_money(($monthCash['receber']??0)-($monthCash['pagar']??0)) ?></td></tr><tr><th>A receber com vencimento no mês</th><td><?= fin_money(max(0,$monthDue['receber']??0)) ?></td></tr><tr><th>A pagar com vencimento no mês</th><td><?= fin_money(max(0,$monthDue['pagar']??0)) ?></td></tr></tbody></table><small class="text-muted">Previsto considera saldo aberto dos títulos com vencimento no mês; realizado considera data da baixa.</small></div></div></div><div class="col-lg-6"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h5">Movimento por categoria</h2><div class="table-responsive"><table class="table"><thead><tr><th>Tipo</th><th>Categoria</th><th>Caixa</th></tr></thead><tbody><?php foreach($byCategory as $row): ?><tr><td><?= fin_h($row['natureza']) ?></td><td><?= fin_h($row['categoria']) ?></td><td><?= fin_money($row['total']) ?></td></tr><?php endforeach; ?><?php if(!$byCategory): ?><tr><td colspan="3" class="text-muted">Sem baixas no período.</td></tr><?php endif; ?></tbody></table></div></div></div></div></div>
 <?php elseif($view==='receber' || $view==='pagar'): ?>
  <?php if($view==='receber'): ?><div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3"><div><h2 class="h4 mb-1">Recebimentos</h2><p class="text-muted mb-0">Acompanhe o que já entrou, o que está pendente e as prioridades de cobrança.</p></div><button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#novoTitulo">Novo lançamento</button></div><div class="row g-3 mb-4"><div class="col-xl-3 col-sm-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted">Total vendido</div><div class="h4 mb-0"><?= fin_money($receberDashboard['vendas']) ?></div><small class="text-muted">Vendas aceitas</small></div></div></div><div class="col-xl-3 col-sm-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted">Recebido</div><div class="h4 mb-2 text-success"><?= fin_money($receberDashboard['recebido']) ?></div><div class="progress" style="height:7px"><div class="progress-bar bg-success" style="width:<?= min(100,$receberDashboard['taxa']) ?>%"></div></div></div></div></div><div class="col-xl-3 col-sm-6"><div class="card border-0 shadow-sm h-100 border-start border-3 border-warning"><div class="card-body"><div class="small text-muted">A receber</div><div class="h4 mb-0"><?= fin_money($receberDashboard['a_receber']) ?></div><small class="text-muted"><?= $receberDashboard['pendentes'] ?> títulos pendentes</small></div></div></div><div class="col-xl-3 col-sm-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted">Taxa de recebimento</div><div class="h4 mb-0"><?= number_format($receberDashboard['taxa'],0,',','.') ?>%</div><small class="text-muted"><?= $receberDashboard['ativos'] ?> vendas com recebimentos</small></div></div></div></div><div class="card border-0 shadow-sm mb-4"><div class="card-body p-0"><div class="px-3 pt-3"><h3 class="h6 mb-1">Prioridades de cobrança</h3><p class="small text-muted mb-2">Clientes com saldo pendente, ordenados pelos vencimentos mais antigos.</p></div><div class="list-group list-group-flush"><?php foreach($receberPrioridades as $priority): ?><div class="list-group-item px-3 py-3 d-flex justify-content-between align-items-center gap-3"><div><div class="fw-semibold text-uppercase"><?= fin_h(mb_strtoupper((string)($priority['contraparte']?:$priority['client_name']?:'Cliente não informado'),'UTF-8')) ?></div><div class="small text-muted"><?= fin_h($priority['forma_pagamento']?:'Forma de pagamento não informada') ?> · negociado há <?= max(0,(int)$priority['dias']) ?> dias</div></div><strong class="text-nowrap"><?= fin_money($priority['saldo']) ?></strong></div><?php endforeach; ?><?php if(!$receberPrioridades): ?><div class="px-3 py-4 text-muted">Nenhuma cobrança pendente encontrada.</div><?php endif; ?></div></div></div><?php endif; ?>
  <div class="collapse mb-3 <?= $error && ($_POST['action']??'')==='titulo'?'show':'' ?>" id="novoTitulo"><div class="card border-0 shadow-sm"><form method="post" class="card-body row g-3"><input type="hidden" name="csrf" value="<?= fin_h(fin_token()) ?>"><input type="hidden" name="action" value="titulo"><input type="hidden" name="natureza" value="<?= fin_h($view) ?>"><div class="col-md-3"><label class="form-label">Categoria</label><select class="form-select" name="categoria" required><?php foreach(($view==='pagar'?['kit','fornecedor','integrador','comissao','rateio','despesas_diversas','impostos','outros']:['venda','boleto','servico','outros']) as $cat): ?><option value="<?= fin_h($cat) ?>"><?= fin_h(ucwords(str_replace('_',' ',$cat))) ?></option><?php endforeach; ?></select></div><div class="col-md-5"><label class="form-label">Descrição</label><input class="form-control" name="descricao" maxlength="255" required></div><div class="col-md-4"><label class="form-label"><?= $view==='receber'?'Cliente':'Fornecedor / favorecido' ?></label><input class="form-control" name="contraparte" maxlength="255"></div><div class="col-md-3"><label class="form-label">Vencimento</label><input class="form-control" name="vencimento" type="date" required></div><div class="col-md-3"><label class="form-label">Valor (R$)</label><input class="form-control" name="valor" inputmode="decimal" required></div><div class="col-md-3"><label class="form-label">Venda aceita # (opcional)</label><input class="form-control" name="venda_id" type="number" min="1"></div><div class="col-md-3"><label class="form-label">Documento / boleto</label><input class="form-control" name="documento"></div><div class="col-md-3"><label class="form-label">Banco</label><input class="form-control" name="banco"></div><div class="col-12"><button class="btn btn-primary">Salvar lançamento</button></div></form></div></div>
  <form method="get" id="finance-filter-form" class="row g-2 mb-3"><input type="hidden" name="view" value="<?= fin_h($view) ?>"><div class="col-md-6"><input class="form-control" name="q" value="<?= fin_h($search) ?>" placeholder="Buscar descrição, cliente, documento"></div><div class="col-md-3"><select class="form-select" name="situacao"><option value="aberto" <?= $filter==='aberto'?'selected':'' ?>>Em aberto</option><option value="quitado" <?= $filter==='quitado'?'selected':'' ?>>Quitados</option><option value="todos" <?= $filter==='todos'?'selected':'' ?>>Todos</option></select></div><div class="col-md-3"><button class="btn btn-outline-primary">Filtrar</button></div></form>
  <div class="card border-0 shadow-sm mb-3 finance-table"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Cliente</th><th>Negociação</th><th>Venda</th><th>Recebido</th><th>A receber</th><th>Situação</th></tr></thead><tbody><?php foreach($titleRows as $row): ?><?php $rowBalance=max(0,(float)$row['saldo']); $rowReceived=max(0,(float)$row['quitado']); $rowOpen=$rowBalance>0.005; ?><tr class="finance-row-click" onclick="window.location.href=this.dataset.href" data-href="financeiro_modulo.php?view=<?= fin_h($view) ?>&titulo=<?= (int)$row['id'] ?>"><td><div class="fw-semibold text-uppercase"><?= fin_h(mb_strtoupper((string)($row['contraparte']?:$row['client_name']?:'Cliente não informado'),'UTF-8')) ?></div><?php if(!empty($row['cidade']??'')): ?><div class="small text-muted"><?= fin_h($row['cidade']) ?></div><?php endif; ?></td><td><?= fin_h($row['descricao']) ?></td><td><?= fin_money($row['valor']) ?></td><td><?= fin_money($rowReceived) ?></td><td class="fw-semibold"><?= fin_money($rowBalance) ?></td><?php $rowStatus=$rowOpen ? ($rowReceived>0.005?'Parcial':'Em aberto') : 'Quitado'; $rowStatusClass=$rowStatus==='Em aberto'?'finance-status-open':($rowStatus==='Parcial'?'finance-status-partial':'finance-status-paid'); ?><td><span class="finance-status <?= $rowStatusClass ?>"><?= $rowStatus ?></span></td></tr><?php endforeach; ?><?php if(!$titleRows): ?><tr><td colspan="6" class="text-muted text-center py-4">Nenhum título encontrado.</td></tr><?php endif; ?></tbody></table></div></div>
  <?php if($totalRows>$perPage): ?><nav><ul class="pagination"><?php for($i=1;$i<=$pages;$i++): ?><li class="page-item <?= $i===$page?'active':'' ?>"><a class="page-link" href="?<?= fin_h(http_build_query(['view'=>$view,'q'=>$search,'situacao'=>$filter,'page'=>$i])) ?>"><?= $i ?></a></li><?php endfor; ?></ul></nav><?php endif; ?>
  <?php if($selected): $balance=max(0,(float)$selected['valor']-(float)$selected['quitado']); ?><div class="card border-0 shadow-sm"><div class="card-body"><h3 class="h5">Título #<?= (int)$selected['id'] ?> · <?= fin_h($selected['descricao']) ?></h3><p>Valor <?= fin_money($selected['valor']) ?> · Quitado <?= fin_money($selected['quitado']) ?> · <strong>Saldo <?= fin_money($balance) ?></strong></p><?php if($balance>0.005): ?><form method="post" class="row g-2 align-items-end mb-3"><input type="hidden" name="csrf" value="<?= fin_h(fin_token()) ?>"><input type="hidden" name="action" value="baixa"><input type="hidden" name="titulo_id" value="<?= (int)$selected['id'] ?>"><div class="col-md-2"><label class="form-label">Data</label><input class="form-control" type="date" name="data_pagamento" value="<?= date('Y-m-d') ?>" required></div><div class="col-md-2"><label class="form-label">Caixa movimentado</label><input class="form-control" name="valor" value="<?= fin_h(number_format($balance,2,',','.')) ?>" inputmode="decimal" required></div><div class="col-md-2"><label class="form-label">Juros</label><input class="form-control" name="juros" value="0" inputmode="decimal"></div><div class="col-md-2"><label class="form-label">Desconto</label><input class="form-control" name="desconto" value="0" inputmode="decimal"></div><div class="col-md-2"><label class="form-label">Meio</label><select class="form-select" name="meio"><option>PIX</option><option>Transferência</option><option>Boleto</option><option>Dinheiro</option><option>Cartão</option><option>Cheque</option><option>Bens</option><option>Outro</option></select></div><div class="col-md-2"><button class="btn btn-success w-100">Registrar baixa</button></div><div class="col-12"><input class="form-control" name="observacao" placeholder="Observação opcional"></div></form><small class="text-muted">Principal quitado = caixa movimentado − juros + desconto. É possível registrar baixas parciais.</small><?php endif; ?><h4 class="h6 mt-4">Histórico de baixas</h4><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Data</th><th>Caixa</th><th>Juros</th><th>Desconto</th><th>Meio</th><th>Observação</th></tr></thead><tbody><?php foreach($settlements as $item): ?><tr><td><?= fin_h(date('d/m/Y',strtotime($item['data_pagamento']))) ?></td><td><?= fin_money($item['valor']) ?></td><td><?= fin_money($item['juros']) ?></td><td><?= fin_money($item['desconto']) ?></td><td><?= fin_h($item['meio']) ?></td><td><?= fin_h($item['observacao']) ?></td></tr><?php endforeach; ?><?php if(!$settlements): ?><tr><td colspan="6" class="text-muted">Ainda não há baixas.</td></tr><?php endif; ?></tbody></table></div><?php if(!$settlements): ?><form method="post" onsubmit="return confirm('Cancelar este título?')"><input type="hidden" name="csrf" value="<?= fin_h(fin_token()) ?>"><input type="hidden" name="action" value="cancelar"><input type="hidden" name="titulo_id" value="<?= (int)$selected['id'] ?>"><input class="form-control form-control-sm d-inline-block w-auto" name="motivo" placeholder="Motivo do cancelamento"><button class="btn btn-sm btn-outline-danger">Cancelar título</button></form><?php endif; ?></div></div><?php endif; ?>
 <?php elseif($view==='notas'): ?>
  <h2 class="h5">Notas fiscais</h2><div class="card border-0 shadow-sm mb-3"><form method="post" class="card-body row g-3"><input type="hidden" name="csrf" value="<?= fin_h(fin_token()) ?>"><input type="hidden" name="action" value="nota"><div class="col-md-2"><label class="form-label">Tipo</label><select class="form-select" name="tipo"><option value="entrada">Entrada</option><option value="saida">Saída</option><option value="direta">Direta</option></select></div><div class="col-md-2"><label class="form-label">Número</label><input class="form-control" name="numero" required></div><div class="col-md-2"><label class="form-label">Emissão</label><input class="form-control" type="date" name="emissao" required></div><div class="col-md-3"><label class="form-label">Contraparte</label><input class="form-control" name="contraparte" required></div><div class="col-md-3"><label class="form-label">Valor</label><input class="form-control" name="valor" inputmode="decimal" required></div><div class="col-md-2"><label class="form-label">Venda #</label><input class="form-control" name="venda_id" type="number" min="1"></div><div class="col-md-2"><label class="form-label">Título #</label><input class="form-control" name="titulo_id" type="number" min="1"></div><div class="col-md-6"><label class="form-label">Observação</label><input class="form-control" name="observacao"></div><div class="col-md-2 align-self-end"><button class="btn btn-primary w-100">Registrar nota</button></div></form></div><div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Emissão</th><th>Tipo</th><th>Número</th><th>Contraparte</th><th>Valor</th><th>Venda</th></tr></thead><tbody><?php foreach($notes as $n): ?><tr><td><?= fin_h(date('d/m/Y',strtotime($n['emissao']))) ?></td><td><?= fin_h($n['tipo']) ?></td><td><?= fin_h($n['numero']) ?></td><td><?= fin_h($n['contraparte']) ?></td><td><?= fin_money($n['valor']) ?></td><td><?= $n['venda_id']?'<a href="financeiro_venda.php?id='.(int)$n['venda_id'].'">#'.(int)$n['venda_id'].'</a>':'—' ?></td></tr><?php endforeach; ?><?php if(!$notes): ?><tr><td colspan="6" class="text-muted">Nenhuma nota cadastrada.</td></tr><?php endif; ?></tbody></table></div></div>
 <?php elseif($view==='importar'): ?>
  <?php include __DIR__.'/includes/financeiro_import_view.php'; ?>
 <?php endif; ?>
</div><style>
.finance-row-click{cursor:pointer}.finance-row-click:hover{background:rgba(13,110,253,.06)}.finance-status{display:inline-block;padding:.28rem .7rem;border-radius:999px;font-size:.75rem;font-weight:600;text-decoration:none;white-space:nowrap}.finance-status-open{background:#54251f;color:#ff8b78}.finance-status-partial{background:#59450a;color:#ffd15c}.finance-status-paid{background:#073f32;color:#2ee09b}.finance-status:hover{filter:brightness(1.12);color:inherit}
</style><script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('finance-filter-form');
  let table = document.querySelector('.finance-table');
  if (!form || !table) return;
  let timer;
  async function updateFinanceTable(pushHistory) {
    const params = new URLSearchParams(new FormData(form));
    const url = `${window.location.pathname}?${params.toString()}`;
    table.classList.add('opacity-50');
    try {
      const response = await fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}});
      if (!response.ok) throw new Error('Falha ao carregar os resultados');
      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const updated = doc.querySelector('.finance-table');
      if (!updated) throw new Error('Tabela não encontrada');
      table.replaceWith(updated);
      table = updated;
      table.scrollIntoView({behavior: 'smooth', block: 'start'});
      if (pushHistory) history.pushState({}, '', url);
    } catch (error) {
      form.submit();
    }
  }
  form.addEventListener('submit', function (event) {
    event.preventDefault();
    updateFinanceTable(true);
  });
  const search = form.querySelector('[name="q"]');
  if (search) search.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(() => updateFinanceTable(true), 350);
  });
  window.addEventListener('popstate', () => window.location.reload());
});
</script></main>
<?php include __DIR__.'/includes/footer.php'; ?>

