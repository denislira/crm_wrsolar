<?php
declare(strict_types=1);

function fin_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function fin_money($value): string { return 'R$ ' . number_format((float)$value, 2, ',', '.'); }
function fin_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['fin_csrf'])) $_SESSION['fin_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['fin_csrf'];
}
function fin_check_token(): void {
    if (empty($_POST['csrf']) || !hash_equals(fin_token(), (string)$_POST['csrf'])) {
        throw new RuntimeException('Sessão expirada. Atualize a página.');
    }
}
function fin_number($value): ?float {
    $raw = trim((string)$value);
    if ($raw === '') return null;
    $raw = preg_replace('/[^0-9,.-]/', '', $raw);
    if (str_contains($raw, ',')) $raw = str_replace(',', '.', str_replace('.', '', $raw));
    elseif (substr_count($raw, '.') > 1) $raw = str_replace('.', '', $raw);
    if (!is_numeric($raw)) throw new InvalidArgumentException('Valor numérico inválido.');
    return round((float)$raw, 2);
}
function fin_date($value): ?string {
    $raw = trim((string)$value);
    if ($raw === '') return null;
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $raw)) {
        $dt = DateTimeImmutable::createFromFormat('!d/m/Y', $raw);
        if ($dt && $dt->format('d/m/Y') === $raw) return $dt->format('Y-m-d');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
        $day = substr($raw, 0, 10);
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if ($dt && $dt->format('Y-m-d') === $day) return $day;
    }
    throw new InvalidArgumentException('Data inválida: ' . $raw);
}
function fin_fields(): array {
    return [
      'Negociação e cliente' => [
        'data_negociacao'=>['DATA DA NEGOCIAÇÃO','date'], 'data_pedido'=>['DATA DO PEDIDO','text'],
        'tipo_venda'=>['TIPO DA VENDA','text'], 'fornecedor'=>['FORNECEDOR','text'],
        'cliente'=>['CLIENTE','text'], 'cpf_cnpj'=>['CPF / CNPJ','text'],
        'nascimento'=>['DATA DE NASCIMENTO / FUNDAÇÃO','date'], 'telefone'=>['TELEFONE','text'],
        'email'=>['E-MAIL','text'], 'local'=>['LOCAL','text'], 'bairro'=>['BAIRRO','text'],
        'cidade'=>['CIDADE','text'], 'localizacao'=>['LOCALIZAÇÃO FIXA','text'],
      ],
      'Kit e projeto' => [
        'kwh_mes'=>['KWH/M','number'], 'kwp'=>['KWP','number'], 'caracteristicas_kit'=>['CARACTERÍSTICAS DO KIT','text'],
        'quant_paineis'=>['QUANT. PAINÉIS','number'], 'paineis_w'=>['PAINÉIS (W)','number'],
        'marca_paineis'=>['MARCA DOS PAINÉIS','text'], 'inversor_w'=>['INVERSOR (W)','text'],
        'marca_inversor'=>['MARCA DO INVERSOR','text'], 'estrutura'=>['ESTRUTURA','text'],
        'acessorios'=>['ACESSÓRIOS','text'], 'imoveis_adicionais'=>['IMÓVEIS ADICIONAIS BENEFICIADOS','text'],
      ],
      'Custos e venda' => [
        'preco_wp'=>['PREÇO (WP)','calculated'], 'custo_acessorios'=>['CUSTO (ACESSÓRIOS)','money'],
        'custo_kit'=>['CUSTO (KIT)','money'], 'custo_total'=>['CUSTO TOTAL','calculated'],
        'valor_venda'=>['VALOR VENDA','money'], 'lucro_bruto'=>['LUCRO BRUTO','calculated'],
        'margem_bruta'=>['MARGEM BRUTA','calculated'], 'observacao'=>['OBSERVAÇÃO','textarea'],
        'vendedor_indicador'=>['VENDEDOR / INDICADOR','text'], 'comissao'=>['COMISSÃO','money'],
      ],
      'Pagamento' => [
        'forma_pagamento'=>['FORMA DE PAGAMENTO','text'], 'forma_negociacao'=>['FORMA DA NEGOCIÇÃO','text'],
        'pago_direto'=>['VALOR PAGO: ESPÉCIE / TRANSFERÊNCIA / PIX','calculated'],
        'pago_fornecedor'=>['VALOR PAGO: FORNECEDOR - NFE','calculated'],
        'pago_integrador'=>['VALOR PAGO: INTEGRADOR - NFS','calculated'],
        'pago_cartao'=>['VALOR PAGO: CHEQUES / CARTÃO','calculated'],
        'pago_bens'=>['VALOR PAGO: BENS','calculated'],
        'valor_receber'=>['VALOR A RECEBER','calculated'],
      ],
      'Notas fiscais' => [
        'data_nf_entrada'=>['DATA NF ENTRADA','date'], 'nf_entrada'=>['NF ENTRADA','text'],
        'nf_saida'=>['NF SAÍDA - NORMAL','text'], 'data_nf_saida'=>['DATA NF SAÍDA','date'],
      ],
      'Execução e monitoramento' => [
        'dados_projeto'=>['DADOS DO PROJETO','date'], 'entrega_kit'=>['ENTREGA DO KIT','date'],
        'aprovacao_tecnica'=>['APROVAÇÃO TÉCNICA / PARECER','date'], 'solicitacao_vistoria'=>['SOLICITAÇÃO DE VISTORIA','date'],
        'inicio_instalacao'=>['INÍCIO DA INSTALAÇÃO','date'], 'termino_instalacao'=>['TÉRMINO DA INSTALAÇÃO','date'],
        'inicio_geracao'=>['INÍCIO DA GERAÇÃO','date'], 'tempo_execucao'=>['TEMPO DE EXECUÇÃO','calculated'],
        'ultima_manutencao'=>['ÚLTIMA MANUTENÇÃO','date'], 'tipo_monitoramento'=>['TIPO DO MONITORAMENTO','text'],
        'monitoramento_cliente'=>['CLIENTE (MONITORAMENTO)','text'], 'monitoramento_telefone'=>['TELEFONE (MONITORAMENTO)','text'],
        'monitoramento_login'=>['LOGIN (MONITORAMENTO)','text'], 'monitoramento_status'=>['STATUS (MONITORAMENTO)','text'],
      ],
    ];
}
function fin_field_map(): array {
    $map = [];
    foreach (fin_fields() as $group) foreach ($group as $key=>$definition) $map[$key] = $definition;
    return $map;
}
function fin_detail(PDO $pdo, int $saleId): array {
    $stmt = $pdo->prepare('SELECT campos FROM financeiro_detalhes WHERE venda_id = ?');
    $stmt->execute([$saleId]);
    $result = json_decode((string)$stmt->fetchColumn(), true);
    return is_array($result) ? $result : [];
}
function fin_audit(PDO $pdo, string $entity, int $id, string $action, array $data, int $userId): void {
    $stmt = $pdo->prepare('INSERT INTO financeiro_auditoria (entidade,entidade_id,acao,dados,usuario_id) VALUES (?,?,?,?,?)');
    $stmt->execute([$entity,$id,$action,json_encode($data,JSON_UNESCAPED_UNICODE),$userId]);
}
function fin_sale_totals(PDO $pdo, int $saleId, array $details, float $saleValue): array {
    $costKit = (float)($details['custo_kit'] ?? 0);
    $costAccessories = (float)($details['custo_acessorios'] ?? 0);
    $cost = $costKit + $costAccessories;
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(b.valor - b.juros + b.desconto),0) FROM financeiro_baixas b JOIN financeiro_titulos t ON t.id=b.titulo_id WHERE t.venda_id=? AND t.natureza='receber' AND t.cancelado_em IS NULL");
    $stmt->execute([$saleId]);
    $received = (float)$stmt->fetchColumn();
    $methods=['direct'=>0.0,'card'=>0.0,'goods'=>0.0,'supplier'=>0.0,'integrator'=>0.0];
    $stmt=$pdo->prepare("SELECT t.natureza,t.categoria,b.meio,COALESCE(SUM(b.valor),0) caixa FROM financeiro_baixas b JOIN financeiro_titulos t ON t.id=b.titulo_id WHERE t.venda_id=? AND t.cancelado_em IS NULL GROUP BY t.natureza,t.categoria,b.meio");
    $stmt->execute([$saleId]);
    foreach($stmt as $row) {
        $value=(float)$row['caixa']; $method=mb_strtolower((string)$row['meio']);
        if ($row['natureza']==='receber') {
            if (str_contains($method,'cart') || str_contains($method,'cheque')) $methods['card']+=$value;
            elseif (str_contains($method,'ben')) $methods['goods']+=$value;
            else $methods['direct']+=$value;
        } elseif ($row['categoria']==='fornecedor') $methods['supplier']+=$value;
        elseif ($row['categoria']==='integrador') $methods['integrator']+=$value;
    }
    $profit = $saleValue - $cost;
    $execution=null;
    if (!empty($details['dados_projeto']) && !empty($details['inicio_geracao'])) {
        try { $execution=(new DateTimeImmutable((string)$details['dados_projeto']))->diff(new DateTimeImmutable((string)$details['inicio_geracao']))->days; } catch(Throwable $e) { }
    }
    return ['cost'=>$cost,'profit'=>$profit,'margin'=>$cost > 0 ? $profit/$cost*100 : null,'received'=>$received,'balance'=>max(0,$saleValue-$received),'price_wp'=>((float)($details['kwp']??0)>0 ? $costKit/((float)$details['kwp']*1000) : null),'methods'=>$methods,'execution'=>$execution];
}
function fin_title_rows(PDO $pdo, string $kind, int $limit=100): array {
    $stmt=$pdo->prepare("SELECT t.*,v.client_name,COALESCE(SUM(b.valor-b.juros+b.desconto),0) AS quitado FROM financeiro_titulos t LEFT JOIN financeiro_vendas v ON v.id=t.venda_id LEFT JOIN financeiro_baixas b ON b.titulo_id=t.id WHERE t.natureza=? AND t.cancelado_em IS NULL GROUP BY t.id ORDER BY t.vencimento ASC,t.id DESC LIMIT {$limit}");
    $stmt->execute([$kind]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
