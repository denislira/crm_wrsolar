<?php
declare(strict_types=1);

function fin_import_key(string $value): string {
 $value=mb_strtolower(trim($value),'UTF-8');
 $value=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value) ?: $value;
 return trim((string)preg_replace('/[^a-z0-9]+/','_',strtolower($value)),'_');
}
function fin_read_import(string $path,string $name): array {
 $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
 if ($ext==='csv') {
  $handle=fopen($path,'rb'); if (!$handle) throw new RuntimeException('Não foi possível abrir o CSV.');
  $sample=fgets($handle)?:''; rewind($handle);
  $delimiter=substr_count($sample,';')>=substr_count($sample,',')?';':',';
  $rows=[];
  while (($line=fgetcsv($handle,0,$delimiter,'"',''))!==false) {
   $rows[]=array_map(static function($v){ $v=(string)$v; if (!mb_check_encoding($v,'UTF-8')) $v=mb_convert_encoding($v,'UTF-8','Windows-1252'); return trim($v); },$line);
   if (count($rows)>5001) break;
  }
  fclose($handle);
  if ($rows) $rows[0][0]=preg_replace('/^\xEF\xBB\xBF/','',$rows[0][0]);
  return $rows;
 }
 if ($ext!=='xlsx') throw new RuntimeException('Use CSV ou XLSX. O formato XLS antigo não é suportado.');
 if (!class_exists('ZipArchive')) throw new RuntimeException('Extensão ZIP do PHP não disponível para XLSX.');
 $zip=new ZipArchive(); if ($zip->open($path)!==true) throw new RuntimeException('XLSX inválido.');
 try {
  $shared=[]; $xml=$zip->getFromName('xl/sharedStrings.xml');
  if ($xml!==false) { $doc=new DOMDocument(); if (!@$doc->loadXML($xml,LIBXML_NONET)) throw new RuntimeException('XLSX inválido.'); $xp=new DOMXPath($doc); foreach($xp->query('//*[local-name()="si"]') as $si) { $str=''; foreach($xp->query('.//*[local-name()="t"]',$si) as $t) $str.=$t->textContent; $shared[]=$str; } }
  $xml=$zip->getFromName('xl/worksheets/sheet1.xml'); if ($xml===false) throw new RuntimeException('Primeira aba não encontrada. Exporte a aba desejada como CSV.');
  $doc=new DOMDocument(); if (!@$doc->loadXML($xml,LIBXML_NONET)) throw new RuntimeException('Aba XLSX inválida.'); $xp=new DOMXPath($doc); $rows=[];
  foreach($xp->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
   $out=[];
   foreach($xp->query('./*[local-name()="c"]',$row) as $cell) {
    $ref=$cell->attributes->getNamedItem('r')?->nodeValue??''; preg_match('/^[A-Z]+/',$ref,$m); $letters=$m[0]??''; $col=0; foreach(str_split($letters) as $c) $col=$col*26+ord($c)-64; if ($col<1 || $col>250) continue;
    $type=$cell->attributes->getNamedItem('t')?->nodeValue??''; $v=$xp->query('./*[local-name()="v"]',$cell)->item(0)?->textContent??'';
    if ($type==='s') $v=$shared[(int)$v]??'';
    elseif ($type==='inlineStr') $v=$xp->query('.//*[local-name()="t"]',$cell)->item(0)?->textContent??'';
    $out[$col-1]=trim($v);
   }
   if ($out) { $rows[]=array_replace(array_fill(0,max(array_keys($out))+1,''),$out); if (count($rows)>5001) break; }
  }
  return $rows;
 } finally { $zip->close(); }
}
function fin_import_headers(array $row): array { $headers=[]; foreach($row as $i=>$h) { $key=fin_import_key((string)$h); if ($key!=='' && !isset($headers[$key])) $headers[$key]=(int)$i; } return $headers; }
function fin_import_cell(array $row,array $headers,array $aliases): ?string { foreach($aliases as $alias) { $key=fin_import_key($alias); if (isset($headers[$key])) { $value=trim((string)($row[$headers[$key]]??'')); if ($value!=='') return $value; } } return null; }
function fin_import_date(?string $raw): ?string {
 if ($raw===null) return null;
 if (is_numeric($raw) && (float)$raw>1000 && (float)$raw<100000) return (new DateTimeImmutable('1899-12-30'))->modify('+'.((int)$raw).' days')->format('Y-m-d');
 return fin_date($raw);
}
function fin_import_optional_date(?string $raw): ?string { try { return fin_import_date($raw); } catch(InvalidArgumentException $e) { return null; } }
function fin_import_preview(array $session): array {
 $rows=$session['rows']; $headers=fin_import_headers($rows[0]); $preview=[];
 foreach(array_slice($rows,1,10) as $i=>$row) {
  $preview[]=['line'=>$i+2,'name'=>fin_import_cell($row,$headers,['cliente','nome','razao social','contraparte','fornecedor','favorecido'])??'', 'description'=>fin_import_cell($row,$headers,['descricao','historico','numero','categoria','tipo da venda'])??'', 'value'=>fin_import_cell($row,$headers,['valor venda','valor','valor total','valor negociado'])??'', 'date'=>fin_import_cell($row,$headers,['vencimento','data vencimento','emissao','data da negociacao','data'])??''];
 }
 return $preview;
}
function fin_commit_import(PDO $pdo,array $session,int $userId): array {
 $kind=$session['kind']; $rows=$session['rows']; $headers=fin_import_headers(array_shift($rows));
 if ($kind==='vendas' && !array_intersect(['cliente','nome','razao_social'],array_keys($headers))) throw new RuntimeException('Cabeçalho Cliente ou Nome não encontrado.');
 if ($kind==='vendas' && !array_intersect(['valor_venda','valor_negociado','valor_total','valor'],array_keys($headers))) throw new RuntimeException('Cabeçalho de valor da venda não encontrado.');
 if (in_array($kind,['receber','pagar'],true) && !array_intersect(['vencimento','data_vencimento','data_do_vencimento'],array_keys($headers))) throw new RuntimeException('Cabeçalho Vencimento não encontrado.');
 if ($kind==='notas' && !array_intersect(['numero','numero_nota','nf'],array_keys($headers))) throw new RuntimeException('Cabeçalho Número da nota não encontrado.');
 $inserted=0; $skipped=0; $pdo->beginTransaction();
 try {
  $check=$pdo->prepare('SELECT COUNT(*) FROM financeiro_importacoes WHERE sha256=? AND tipo=?'); $check->execute([$session['hash'],$kind]); if ((int)$check->fetchColumn()) throw new RuntimeException('Arquivo já importado.');
  foreach($rows as $line=>$row) {
   if (!array_filter($row,static fn($v)=>trim((string)$v)!=='')) { $skipped++; continue; }
   try {
    if ($kind==='vendas') {
     $orderState=mb_strtoupper((string)(fin_import_cell($row,$headers,['data do pedido'])??''));
     if (str_contains($orderState,'CANCELADO')) { $skipped++; continue; }
     $client=fin_import_cell($row,$headers,['cliente','nome','razao social']);
     $amount=fin_number(fin_import_cell($row,$headers,['valor venda','valor negociado','valor total','valor'])??'');
     if (!$client || $amount===null || $amount<=0) { $skipped++; continue; }
     $cpf=fin_import_cell($row,$headers,['cpf / cnpj','cpf cnpj','cpf','cnpj']);
     $payment=fin_import_cell($row,$headers,['forma de pagamento','forma pagamento']);
     $date=fin_import_optional_date(fin_import_cell($row,$headers,['data da negociacao','data venda','data']));
     if ($cpf) { $dup=$pdo->prepare("SELECT COUNT(*) FROM financeiro_vendas WHERE origem='importacao' AND cpf_cnpj=? AND valor_negociado=? AND (data_venda <=> ?)"); $dup->execute([$cpf,$amount,$date]); if ((int)$dup->fetchColumn()) { $skipped++; continue; } }
     $stmt=$pdo->prepare("INSERT INTO financeiro_vendas(origem,status,client_name,cpf_cnpj,phone,email,cidade,vendedor,valor_negociado,forma_pagamento,data_venda,observacoes) VALUES('importacao','aguardando_conferencia',?,?,?,?,?,?,?,?,?,?)");
     $stmt->execute([$client,$cpf,fin_import_cell($row,$headers,['telefone','celular']),fin_import_cell($row,$headers,['e-mail','email']),fin_import_cell($row,$headers,['cidade']),fin_import_cell($row,$headers,['vendedor / indicador','vendedor']),$amount,$payment,$date,fin_import_cell($row,$headers,['observacao','observacoes'])]);
     $id=(int)$pdo->lastInsertId(); $fields=[];
     foreach(fin_field_map() as $key=>$def) {
      if ($def[1]==='calculated') continue;
      $raw=fin_import_cell($row,$headers,[$def[0],str_replace('_',' ',$key)]);
      if ($raw===null) continue;
      $fields[$key]=in_array($def[1],['money','number'],true)?fin_number($raw):($def[1]==='date'?fin_import_optional_date($raw):$raw);
     }
     if (isset($headers['data_da_negociacao'],$headers['cliente']) && count($row)>=61) {
      $fields['monitoramento_cliente']=trim((string)($row[56]??''))?:null;
      $fields['monitoramento_telefone']=trim((string)($row[57]??''))?:null;
      $fields['monitoramento_login']=trim((string)($row[58]??''))?:null;
      $fields['monitoramento_status']=trim((string)($row[60]??''))?:null;
     }
     $fields['cliente']=$client; $fields['valor_venda']=$amount;
     $pdo->prepare('INSERT INTO financeiro_detalhes(venda_id,campos) VALUES(?,?)')->execute([$id,json_encode($fields,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
     fin_audit($pdo,'venda',$id,'importada',['arquivo'=>$session['name'],'linha'=>$line+2],$userId);
    } elseif ($kind==='notas') {
     $type=fin_import_key(fin_import_cell($row,$headers,['tipo','tipo nota'])??'');
     $type=match($type){'entrada','nf_entrada'=>'entrada','saida','nf_saida'=>'saida','direta','nf_direta'=>'direta',default=>''};
     $number=fin_import_cell($row,$headers,['numero','numero nota','nf']);
     $party=fin_import_cell($row,$headers,['contraparte','cliente','fornecedor','favorecido']);
     $emission=fin_import_optional_date(fin_import_cell($row,$headers,['emissao','data emissao','data']));
     $amount=fin_number(fin_import_cell($row,$headers,['valor','valor nota','valor total'])??'');
     if (!$type || !$number || !$party || !$emission || $amount===null || $amount<=0) { $skipped++; continue; }
     $stmt=$pdo->prepare('INSERT INTO financeiro_notas(tipo,numero,emissao,contraparte,valor,observacao,criado_por) VALUES(?,?,?,?,?,?,?)');
     $stmt->execute([$type,mb_substr($number,0,100),$emission,mb_substr($party,0,255),$amount,fin_import_cell($row,$headers,['observacao','observacoes']),$userId]);
     fin_audit($pdo,'nota',(int)$pdo->lastInsertId(),'importada',['arquivo'=>$session['name'],'linha'=>$line+2],$userId);
    } else {
     $description=fin_import_cell($row,$headers,['descricao','historico','cliente','fornecedor','contraparte']);
     $amount=fin_number(fin_import_cell($row,$headers,['valor','valor total','valor boleto','valor a pagar','valor a receber'])??'');
     $due=fin_import_date(fin_import_cell($row,$headers,['vencimento','data vencimento','data do vencimento']));
     if (!$description || $amount===null || $amount<=0 || !$due) { $skipped++; continue; }
     $party=fin_import_cell($row,$headers,['contraparte','cliente','fornecedor','favorecido']);
     $category=fin_import_cell($row,$headers,['categoria','tipo','grupo'])??($kind==='pagar'?'despesas_diversas':'outros');
     $stmt=$pdo->prepare('INSERT INTO financeiro_titulos(natureza,categoria,descricao,contraparte,documento,banco,vencimento,valor,criado_por) VALUES(?,?,?,?,?,?,?,?,?)');
     $stmt->execute([$kind,mb_substr($category,0,60),mb_substr($description,0,255),$party,fin_import_cell($row,$headers,['documento','boleto','numero']),fin_import_cell($row,$headers,['banco']),$due,$amount,$userId]);
     fin_audit($pdo,'titulo',(int)$pdo->lastInsertId(),'importado',['arquivo'=>$session['name'],'linha'=>$line+2],$userId);
    }
    $inserted++;
   } catch(InvalidArgumentException $e) { $skipped++; }
  }
  $stmt=$pdo->prepare('INSERT INTO financeiro_importacoes(nome_arquivo,sha256,tipo,linhas,inseridas,ignoradas,usuario_id) VALUES(?,?,?,?,?,?,?)');
  $stmt->execute([$session['name'],$session['hash'],$kind,count($rows),$inserted,$skipped,$userId]);
  $pdo->commit(); return ['inserted'=>$inserted,'skipped'=>$skipped];
 } catch(Throwable $e) { $pdo->rollBack(); throw $e; }
}
