<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/financeiro_lib.php';
require_once __DIR__.'/includes/project_kit_schema.php';
ensureProjectKitDetailsColumn($pdo);
checkAccessOrRedirect('financeiro');
$id=(int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id<1) { header('Location: financeiro.php'); exit; }
$userId=(int)$_SESSION['user_id'];
$projectKitKeys=['kwh_mes','kwp','caracteristicas_kit','quant_paineis','paineis_w','marca_paineis','inversor_w','marca_inversor','estrutura','acessorios','imoveis_adicionais'];
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    fin_check_token();
    $pdo->beginTransaction();
    $lock=$pdo->prepare('SELECT * FROM financeiro_vendas WHERE id=? FOR UPDATE');
    $lock->execute([$id]); $sale=$lock->fetch(PDO::FETCH_ASSOC);
    if (!$sale) throw new RuntimeException('Venda não encontrada.');
    $action=(string)($_POST['action']??'');
    if ($action==='salvar') {
      $previous=fin_detail($pdo,$id); $fields=$previous;
      foreach(fin_field_map() as $key=>$definition) {
        if ($definition[1]==='calculated') continue;
        if (!empty($sale['project_id']) && in_array($key,$projectKitKeys,true)) continue;
        $raw=trim((string)($_POST['campos'][$key]??''));
        if (in_array($definition[1],['money','number'],true)) {
          $value=fin_number($raw);
          if ($value!==null && $value<0) throw new InvalidArgumentException($definition[0].' não pode ser negativo.');
        } elseif ($definition[1]==='date') $value=fin_date($raw);
        else $value=$raw!==''?mb_substr($raw,0,10000):null;
        $fields[$key]=$value;
      }
      if (empty($sale['project_id']) && (float)($fields['quant_paineis']??0)>0 && (float)($fields['paineis_w']??0)>0) {
        $fields['kwp']=round((float)$fields['quant_paineis']*(float)$fields['paineis_w']/1000,3);
        $fields['kwh_mes']=round($fields['kwp']/8*1000,2);
      }
      if (trim((string)($fields['cliente']??''))==='') throw new InvalidArgumentException('Informe o cliente.');
      $amount=$fields['valor_venda']??null;
      $existingTitles=$pdo->prepare("SELECT COUNT(*) FROM financeiro_titulos WHERE venda_id=? AND natureza='receber' AND cancelado_em IS NULL");
      $existingTitles->execute([$id]);
      if ((int)$existingTitles->fetchColumn()>0 && round((float)$amount,2)!==round((float)$sale['valor_negociado'],2)) throw new InvalidArgumentException('O valor da venda não pode ser alterado após gerar parcelas. Ajuste os títulos antes.');
      if ($sale['status']==='aceito' && ($amount===null || $amount<=0)) throw new InvalidArgumentException('Venda aceita deve ter valor positivo.');
      if ($sale['status']==='aceito' && trim((string)($fields['forma_pagamento']??''))==='') throw new InvalidArgumentException('Venda aceita deve manter a forma de pagamento.');
      $update=$pdo->prepare('UPDATE financeiro_vendas SET client_name=?,cpf_cnpj=?,phone=?,email=?,cidade=?,vendedor=?,valor_negociado=?,forma_pagamento=?,observacoes=?,data_venda=COALESCE(?,data_venda) WHERE id=?');
      $update->execute([$fields['cliente'],$fields['cpf_cnpj'],$fields['telefone'],$fields['email'],$fields['cidade'],$fields['vendedor_indicador'],$amount,$fields['forma_pagamento'],$fields['observacao'],$fields['data_negociacao'],$id]);
      $save=$pdo->prepare('INSERT INTO financeiro_detalhes(venda_id,campos) VALUES(?,?) ON DUPLICATE KEY UPDATE campos=VALUES(campos)');
      $save->execute([$id,json_encode($fields,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
      fin_audit($pdo,'venda',$id,'campos_atualizados',['anteriores'=>$previous,'atuais'=>$fields],$userId);
      $_SESSION['fin_notice']='Dados da venda salvos.';
    } elseif ($action==='aceitar') {
      $fields=fin_detail($pdo,$id);
      $amount=($fields['valor_venda']??null)!==null ? (float)$fields['valor_venda'] : (float)$sale['valor_negociado'];
      $payment=trim((string)($fields['forma_pagamento']??$sale['forma_pagamento']??''));
      if (trim((string)$sale['client_name'])==='' || $amount<=0 || $payment==='') throw new InvalidArgumentException('Informe cliente, valor da venda e forma de pagamento antes de aceitar.');
      $pdo->prepare("UPDATE financeiro_vendas SET status='aceito',valor_negociado=?,forma_pagamento=?,activated_at=COALESCE(activated_at,NOW()) WHERE id=?")->execute([$amount,$payment,$id]);
      fin_audit($pdo,'venda',$id,'aceita',['valor'=>$amount,'forma_pagamento'=>$payment],$userId);
      $_SESSION['fin_notice']='Venda aceita. Agora você pode criar o plano de pagamento.';
    } elseif ($action==='revisar') {
      $pdo->prepare("UPDATE financeiro_vendas SET status='em_revisao' WHERE id=?")->execute([$id]);
      fin_audit($pdo,'venda',$id,'em_revisao',['motivo'=>mb_substr(trim((string)($_POST['motivo']??'')),0,500)],$userId);
      $_SESSION['fin_notice']='Venda marcada para revisão.';
    } elseif ($action==='plano') {
      if ($sale['status']!=='aceito') throw new RuntimeException('Aceite a venda antes de criar parcelas.');
      $check=$pdo->prepare("SELECT COUNT(*) FROM financeiro_titulos WHERE venda_id=? AND natureza='receber' AND cancelado_em IS NULL");
      $check->execute([$id]);
      if ((int)$check->fetchColumn()>0) throw new RuntimeException('Esta venda já possui parcelas.');
      $amount=round((float)$sale['valor_negociado'],2);
      $entry=fin_number($_POST['entrada']??'0')??0; $n=(int)($_POST['parcelas']??0);
      if ($amount<=0 || $entry<0 || $entry>$amount || $n<0 || $n>120 || ($n===0 && $entry<$amount) || ($n>0 && $entry==$amount)) throw new InvalidArgumentException('Entrada ou quantidade de parcelas inválida.');
      $first=fin_date($_POST['primeiro_vencimento']??'');
      if (!$first) throw new InvalidArgumentException('Informe o primeiro vencimento.');
      $bank=trim((string)($_POST['banco']??''));
      $insert=$pdo->prepare("INSERT INTO financeiro_titulos(venda_id,natureza,categoria,descricao,contraparte,banco,parcela_numero,parcelas_total,vencimento,valor,criado_por) VALUES(?,'receber','venda',?,?,?,?,?,?,?,?)");
      if ($entry>0) $insert->execute([$id,'Entrada - '.$sale['client_name'],$sale['client_name'],$bank?:null,0,$n,$first,$entry,$userId]);
      $remaining=(int)round(($amount-$entry)*100); $base=$n>0?intdiv($remaining,$n):0; $rest=$n>0?$remaining%$n:0;
      $start=new DateTimeImmutable($first);
      for($i=1;$i<=$n;$i++) {
        $month=$start->modify('first day of this month')->modify('+'.($i-1).' months');
        $day=min((int)$start->format('d'),(int)$month->format('t'));
        $due=$month->setDate((int)$month->format('Y'),(int)$month->format('m'),$day);
        $insert->execute([$id,'Parcela '.$i.'/'.$n.' - '.$sale['client_name'],$sale['client_name'],$bank?:null,$i,$n,$due->format('Y-m-d'),($base+($i<=$rest?1:0))/100,$userId]);
      }
      fin_audit($pdo,'venda',$id,'plano_criado',['entrada'=>$entry,'parcelas'=>$n,'primeiro_vencimento'=>$first],$userId);
      $_SESSION['fin_notice']='Plano de pagamento criado.';
    } else throw new RuntimeException('Ação inválida.');
    $pdo->commit(); header('Location: financeiro_venda.php?id='.$id); exit;
  } catch(Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error=$e->getMessage(); }
}
$stmt=$pdo->prepare('SELECT fv.*,p.status AS project_status,p.projeto AS project_size,p.kit_details AS project_kit_details,p.address AS project_address,p.logistics_delivery_date,p.closed_date AS project_closed_date,p.contract AS project_contract,pv.id AS pos_venda_id,pv.installation_date,pv.next_maintenance,pv.warranty_end,pv.last_checkup,pv.kit AS pos_kit,pv.marca AS pos_marca,pv.equipment AS pos_equipment,pv.address AS pos_address,pv.phone AS pos_phone,pv.email AS pos_email,pv.cpf AS pos_cpf,pv.birth_date AS pos_birth_date,pv.project_notes AS pos_project_notes FROM financeiro_vendas fv LEFT JOIN projetos p ON p.id=fv.project_id LEFT JOIN pos_venda pv ON pv.project_id=p.id WHERE fv.id=?');
$stmt->execute([$id]); $sale=$stmt->fetch(PDO::FETCH_ASSOC);
if (!$sale) { header('Location: financeiro.php'); exit; }
$fields=fin_detail($pdo,$id);
$fields+=['cliente'=>$sale['client_name'],'cpf_cnpj'=>$sale['cpf_cnpj'],'telefone'=>$sale['phone'],'email'=>$sale['email'],'cidade'=>$sale['cidade'],'vendedor_indicador'=>$sale['vendedor'],'valor_venda'=>$sale['valor_negociado'],'forma_pagamento'=>$sale['forma_pagamento'],'observacao'=>$sale['observacoes']];
$projectKitDetails=json_decode((string)($sale['project_kit_details']??''),true);
if (!is_array($projectKitDetails)) $projectKitDetails=[];
foreach ([
  'cpf_cnpj' => $sale['pos_cpf'] ?? null,
  'telefone' => $sale['pos_phone'] ?? null,
  'email' => $sale['pos_email'] ?? null,
  'nascimento' => $sale['pos_birth_date'] ?? null,
  'local' => $sale['project_address'] ?? ($sale['pos_address'] ?? null),
  'caracteristicas_kit' => $sale['pos_equipment'] ?? ($sale['project_size'] ?? null),
  'kwp' => $sale['pos_kit'] ?? null,
  'marca_paineis' => $sale['pos_marca'] ?? null,
  'marca_inversor' => $sale['pos_marca'] ?? null,
  'entrega_kit' => $sale['logistics_delivery_date'] ?? null,
  'inicio_instalacao' => $sale['installation_date'] ?? null,
  'termino_instalacao' => $sale['installation_date'] ?? null,
  'ultima_manutencao' => $sale['last_checkup'] ?? ($sale['next_maintenance'] ?? null),
  'dados_projeto' => $sale['project_closed_date'] ?? null,
  'observacao' => $sale['pos_project_notes'] ?? ($sale['project_contract'] ?? null),
] as $key => $fallback) {
  if (($fields[$key] ?? null) === null || trim((string)$fields[$key]) === '') {
    $fields[$key] = $fallback;
  }
}
if (!empty($sale['project_id'])) {
  foreach ($projectKitKeys as $key) {
    $value=$projectKitDetails[$key] ?? null;
    if (($value===null || trim((string)$value)==='') && $key==='kwh_mes') $value=$sale['project_size'] ?? null;
    $fields[$key]=$value;
  }
}
$totals=fin_sale_totals($pdo,$id,$fields,(float)($fields['valor_venda']??$sale['valor_negociado']??0));
$titles=$pdo->prepare("SELECT t.*,COALESCE(SUM(b.valor-b.juros+b.desconto),0) AS quitado FROM financeiro_titulos t LEFT JOIN financeiro_baixas b ON b.titulo_id=t.id WHERE t.venda_id=? AND t.natureza='receber' AND t.cancelado_em IS NULL GROUP BY t.id ORDER BY t.vencimento,t.id");
$titles->execute([$id]); $titles=$titles->fetchAll(PDO::FETCH_ASSOC);
$notice=$_SESSION['fin_notice']??null; unset($_SESSION['fin_notice']);
include __DIR__.'/includes/header.php'; include __DIR__.'/includes/sidebar.php';
?>
<style>
.fin-sale-page {
  --fin-border: rgba(15, 23, 42, .14);
  --fin-border-strong: rgba(11, 106, 193, .24);
  --fin-soft-blue: #eef6ff;
  --fin-label: #334155;
}
.fin-sale-page .sale-summary-card,
.fin-sale-form,
.fin-sale-page #fin-sale-form,
.fin-side-card {
  border: 1px solid rgba(15, 23, 42, .1) !important;
  border-radius: 10px;
  overflow: hidden;
}
.fin-sale-page #fin-sale-form > .card-header {
  background: linear-gradient(135deg, #fff 0%, #eef6ff 100%) !important;
  border-bottom: 1px solid var(--fin-border);
  padding: 1rem 1.25rem;
}
.fin-sale-page #fin-sale-form > .card-header strong {
  display: flex;
  align-items: center;
  gap: .5rem;
  color: #0f172a;
}
.fin-sale-form-header {
  background: linear-gradient(135deg, #fff 0%, #eef6ff 100%);
  border-bottom: 1px solid var(--fin-border);
  padding: 1rem 1.25rem;
}
.fin-sale-form-header strong,
.fin-side-card .card-header strong {
  display: flex;
  align-items: center;
  gap: .5rem;
  color: #0f172a;
}
.fin-sale-section,
.fin-sale-page #fin-sale-form fieldset {
  border: 1px solid var(--fin-border);
  border-radius: 8px;
  padding: 1rem;
  margin-bottom: 1rem;
  background: #fff;
  box-shadow: 0 6px 18px rgba(15, 23, 42, .035);
}
.fin-sale-section legend,
.fin-sale-page #fin-sale-form fieldset legend {
  float: none;
  width: auto;
  display: inline-flex;
  align-items: center;
  gap: .5rem;
  margin: 0 0 .85rem;
  padding: .35rem .7rem;
  border: 1px solid var(--fin-border-strong);
  border-radius: 999px;
  background: var(--fin-soft-blue);
  color: #075293;
  font-size: .88rem;
  font-weight: 700;
}
.fin-field label,
.fin-sale-page #fin-sale-form label {
  color: var(--fin-label);
  margin-bottom: .35rem;
}
.fin-field .form-control,
.fin-sale-page #fin-sale-form .form-control {
  min-height: 42px;
  border: 1px solid var(--fin-border) !important;
  border-radius: 8px;
  background: #fff;
  color: #0f172a;
}
.fin-field .form-control:hover,
.fin-sale-page #fin-sale-form .form-control:hover {
  border-color: rgba(11, 106, 193, .34) !important;
}
.fin-field .form-control:focus,
.fin-sale-page #fin-sale-form .form-control:focus {
  border-color: var(--blue-700) !important;
  box-shadow: 0 0 0 .2rem rgba(11, 106, 193, .11) !important;
}
.fin-field .form-control[readonly],
.fin-sale-page #fin-sale-form .form-control[readonly] {
  background: #f1f5f9 !important;
  border-style: dashed !important;
  color: #475569;
  font-weight: 700;
}
.fin-side-card .card-header {
  border-bottom: 1px solid var(--fin-border);
  background: #fff;
}
body.theme-dark .fin-sale-page {
  --fin-border: rgba(255,255,255,.14);
  --fin-border-strong: rgba(96, 165, 250, .38);
  --fin-soft-blue: rgba(59, 130, 246, .12);
  --fin-label: #cbd5e1;
}
body.theme-dark .fin-sale-form-header,
body.theme-dark .fin-sale-section,
body.theme-dark .fin-sale-page #fin-sale-form > .card-header,
body.theme-dark .fin-sale-page #fin-sale-form fieldset,
body.theme-dark .fin-side-card .card-header {
  background: rgba(255,255,255,.03) !important;
}

body.theme-dark .fin-sale-page .fin-field .form-control,
body.theme-dark .fin-sale-page #fin-sale-form .form-control,
body.theme-dark .fin-sale-page #fin-sale-form .form-select {
  background: rgba(15,23,42,.72) !important;
  color: #e6eef8 !important;
  border-color: rgba(255,255,255,.14) !important;
}

body.theme-dark .fin-sale-page .fin-field .form-control[readonly],
body.theme-dark .fin-sale-page #fin-sale-form .form-control[readonly] {
  background: rgba(255,255,255,.08) !important;
  color: #cbd5e1 !important;
}

body.theme-dark .fin-sale-page #fin-sale-form fieldset legend,
body.theme-dark .fin-sale-page #fin-sale-form label,
body.theme-dark .fin-sale-page .fin-sale-section legend {
  color: #dbeafe !important;
}

body.theme-dark .fin-sale-page .card,
body.theme-dark .fin-sale-page .card-body,
body.theme-dark .fin-sale-page .card-header,
body.theme-dark .fin-sale-page .table,
body.theme-dark .fin-sale-page .table > :not(caption) > * > * {
  background-color: transparent;
  color: #e6eef8;
  border-color: rgba(255,255,255,.1);
}

body.theme-dark .fin-sale-page .card {
  background-color: rgba(255,255,255,.04) !important;
}
body.theme-dark .fin-sale-form-header strong,
body.theme-dark .fin-sale-page #fin-sale-form > .card-header strong,
body.theme-dark .fin-side-card .card-header strong,
body.theme-dark .fin-field .form-control,
body.theme-dark .fin-sale-page #fin-sale-form .form-control {
  color: #e6eef8;
}
</style>
<main class="main-content-scroll py-4 fin-sale-page"><div class="container-fluid">
 <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h1 class="h3 mb-1">Venda #<?= $id ?> · <?= fin_h($sale['client_name']) ?></h1><p class="text-muted mb-0">Origem: <?= fin_h($sale['origem']) ?> · Situação: <?= fin_h($sale['status']) ?> · Projeto CRM: <?= $sale['project_id'] ? '#'.(int)$sale['project_id'] : 'não vinculado' ?></p></div><a class="btn btn-outline-secondary" href="financeiro.php">Voltar às entradas</a></div>
 <?php $financeiroView='entradas'; include __DIR__.'/includes/financeiro_nav.php'; ?>
 <?php if($notice): ?><div class="alert alert-success"><?= fin_h($notice) ?></div><?php endif; ?>
 <?php if($error): ?><div class="alert alert-danger"><?= fin_h($error) ?></div><?php endif; ?>
 <div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">Valor da venda</small><div class="h4 mb-0"><?= fin_money($fields['valor_venda']??$sale['valor_negociado']??0) ?></div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">Custo total</small><div class="h4 mb-0"><?= fin_money($totals['cost']) ?></div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">Lucro bruto · margem sobre custo</small><div class="h4 mb-0"><?= fin_money($totals['profit']) ?> <small class="fs-6"><?= $totals['margin']===null?'—':number_format($totals['margin'],1,',','.').'%'; ?></small></div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">Recebido · saldo</small><div class="h4 mb-0"><?= fin_money($totals['received']) ?> / <?= fin_money($totals['balance']) ?></div></div></div></div>
 </div>
 <div class="row g-3"><div class="col-xl-9"><form method="post" id="fin-sale-form" class="card border-0 shadow-sm"><input type="hidden" name="csrf" value="<?= fin_h(fin_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="salvar"><div class="card-header bg-white"><strong>Cadastro da venda · campos da planilha de controle</strong></div><div class="card-body">
 <?php foreach(fin_fields() as $groupName=>$group): ?><fieldset class="mb-4"><legend class="h6 border-bottom pb-2 mb-3"><?= fin_h($groupName) ?></legend><?php if($groupName==='Kit e projeto' && !empty($sale['project_id'])): ?><div class="small text-muted mb-3">Dados sincronizados do Projeto #<?= (int)$sale['project_id'] ?>. Edite-os na tela de Projetos.</div><?php endif; ?><div class="row g-3">
 <?php foreach($group as $key=>$def): $value=$fields[$key]??null; $type=$def[1]; $isProjectKitField=!empty($sale['project_id'])&&in_array($key,$projectKitKeys,true); if($type==='calculated'){ $value=match($key){'custo_total'=>$totals['cost'],'lucro_bruto'=>$totals['profit'],'margem_bruta'=>$totals['margin']===null?'':number_format($totals['margin'],2,',','.'),'valor_receber'=>$totals['balance'],'preco_wp'=>$totals['price_wp'],'pago_direto'=>$totals['methods']['direct'],'pago_cartao'=>$totals['methods']['card'],'pago_bens'=>$totals['methods']['goods'],'pago_fornecedor'=>$totals['methods']['supplier'],'pago_integrador'=>$totals['methods']['integrator'],'tempo_execucao'=>$totals['execution'],default=>''}; } ?>
 <div class="col-md-6 col-lg-4"><label class="form-label small fw-semibold" for="f_<?= fin_h($key) ?>"><?= fin_h($def[0]) ?></label>
 <?php if($type==='textarea'): ?><textarea id="f_<?= fin_h($key) ?>" class="form-control" name="campos[<?= fin_h($key) ?>]" rows="2"><?= fin_h($value) ?></textarea></div>
 <?php else: ?><input id="f_<?= fin_h($key) ?>" class="form-control <?= ($type==='calculated'||$isProjectKitField)?'bg-light':'' ?>" <?= ($type==='calculated'||$isProjectKitField)?'readonly':'' ?> <?= $type==='calculated'?'':'name="campos['.fin_h($key).']"' ?> type="<?= $type==='date'?'date':'text' ?>" inputmode="<?= in_array($type,['money','number'],true)?'decimal':'text' ?>" value="<?= fin_h($type==='date' && $value?substr((string)$value,0,10):$value) ?>"></div>
 <?php endif; endforeach; ?></div></fieldset><?php endforeach; ?>
 <div class="alert alert-info small">A senha de monitoramento da planilha não é armazenada. Valores recebidos e saldo vêm das baixas registradas.</div>
 <button class="btn btn-primary" type="submit">Salvar dados da venda</button></div></form></div>
 <div class="col-xl-3"><div class="card border-0 shadow-sm mb-3"><div class="card-header bg-white"><strong>Conferência</strong></div><div class="card-body"><p class="small text-muted">Revise cliente, valor e forma de pagamento antes do aceite.</p><form method="post" class="d-grid gap-2"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="csrf" value="<?= fin_h(fin_token()) ?>"><?php if($sale['status']!=='aceito'): ?><button class="btn btn-success" name="action" value="aceitar">Aceitar no Financeiro</button><?php endif; ?><input class="form-control" name="motivo" maxlength="500" placeholder="Motivo da revisão"><button class="btn btn-outline-warning" name="action" value="revisar">Marcar para revisão</button></form></div></div>
 <?php if($sale['status']==='aceito' && !$titles): ?><div class="card border-0 shadow-sm"><div class="card-header bg-white"><strong>Plano de pagamento</strong></div><div class="card-body"><form method="post" class="d-grid gap-2"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="csrf" value="<?= fin_h(fin_token()) ?>"><input type="hidden" name="action" value="plano"><label class="form-label mb-0">Entrada (R$)</label><input class="form-control" name="entrada" inputmode="decimal" value="0"><label class="form-label mb-0">Parcelas após entrada</label><input class="form-control" name="parcelas" type="number" min="0" max="120" value="1"><label class="form-label mb-0">Primeiro vencimento</label><input class="form-control" name="primeiro_vencimento" type="date" required><label class="form-label mb-0">Banco</label><input class="form-control" name="banco" maxlength="120"><button class="btn btn-primary mt-2">Gerar parcelas</button></form></div></div><?php endif; ?>
 </div></div>
 <?php if($titles): ?><div class="card border-0 shadow-sm mt-4"><div class="card-header bg-white"><strong>Parcelas e recebimentos</strong></div><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Parcela</th><th>Vencimento</th><th>Valor</th><th>Recebido</th><th>Saldo</th><th></th></tr></thead><tbody><?php foreach($titles as $title): ?><tr><td><?= fin_h($title['descricao']) ?></td><td><?= fin_h(date('d/m/Y',strtotime($title['vencimento']))) ?></td><td><?= fin_money($title['valor']) ?></td><td><?= fin_money($title['quitado']) ?></td><td><?= fin_money(max(0,(float)$title['valor']-(float)$title['quitado'])) ?></td><td><a class="btn btn-sm btn-outline-primary" href="financeiro_modulo.php?view=receber&titulo=<?= (int)$title['id'] ?>">Abrir</a></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
</div></main>
<script>
(() => {
 const form=document.getElementById('fin-sale-form'); if(!form) return;
 const value=id=>{const raw=(document.getElementById('f_'+id)?.value||'').replace(/[^0-9,.-]/g,''); const n=raw.includes(',')?Number(raw.replace(/\./g,'').replace(',','.')):Number(raw); return Number.isFinite(n)?n:0;};
 const set=(id,n,percent=false)=>{const el=document.getElementById('f_'+id); if(el) el.value=Number.isFinite(n)?n.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2})+(percent?'%':''):'';};
 const recalc=()=>{if(value('quant_paineis')>0&&value('paineis_w')>0){const power=value('quant_paineis')*value('paineis_w')/1000; const kwp=document.getElementById('f_kwp'); const kwh=document.getElementById('f_kwh_mes'); if(kwp) kwp.value=power.toFixed(3).replace('.',','); if(kwh) kwh.value=(power/8*1000).toFixed(2).replace('.',',');} const cost=value('custo_kit')+value('custo_acessorios'); const sale=value('valor_venda'); const profit=sale-cost; set('custo_total',cost); set('lucro_bruto',profit); set('margem_bruta',cost>0?profit/cost*100:NaN,true); const kwp=value('kwp'); set('preco_wp',kwp>0?value('custo_kit')/(kwp*1000):NaN);};
 ['custo_kit','custo_acessorios','valor_venda','kwp','quant_paineis','paineis_w'].forEach(id=>document.getElementById('f_'+id)?.addEventListener('input',recalc));
})();
</script>
<?php include __DIR__.'/includes/footer.php'; ?>
