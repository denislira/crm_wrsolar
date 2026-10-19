<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Não autenticado']); exit; }
session_write_close();

$mode = (string)($_GET['mode'] ?? 'grid');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(10, (int)($_GET['limit'] ?? 25)));
$offset = ($page - 1) * $limit;
$where = ['l.deleted = 0']; $params = [];
$search = trim((string)($_GET['search'] ?? ''));
if ($search !== '') { $where[] = '(l.name LIKE ? OR l.email LIKE ? OR l.phone LIKE ? OR l.source LIKE ? OR l.cidade LIKE ?)'; $like='%'.$search.'%'; array_push($params,$like,$like,$like,$like,$like); }
$city = trim((string)($_GET['city'] ?? ''));
if ($city !== '') { $where[]='l.cidade = ?'; $params[]=$city; }
$scoreFilter = (string)($_GET['score'] ?? '');
if (in_array($scoreFilter,['hot','warm','cold'],true)) {
    $scoreSql='((l.email IS NOT NULL AND l.email<>\'\')*20+(l.phone IS NOT NULL AND l.phone<>\'\')*20+(l.consumo_cliente IS NOT NULL AND l.consumo_cliente<>\'\')*15+(l.estimativa_projeto_kwh IS NOT NULL AND l.estimativa_projeto_kwh<>\'\')*10+(CHAR_LENGTH(COALESCE(l.notes,\'\'))>40)*10+(l.source IS NOT NULL AND l.source<>\'\')*10)';
    if($scoreFilter==='hot')$where[]="$scoreSql >= 80";
    elseif($scoreFilter==='warm')$where[]="$scoreSql >= 50 AND $scoreSql < 80";
    else $where[]="$scoreSql < 50";
}
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom)) { $where[]='DATE(COALESCE(l.data_inicio,l.created_at)) >= ?'; $params[]=$dateFrom; }
$dateTo = trim((string)($_GET['date_to'] ?? ''));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo)) { $where[]='DATE(COALESCE(l.data_inicio,l.created_at)) <= ?'; $params[]=$dateTo; }
$gridFilters = json_decode((string)($_GET['grid_filters'] ?? '{}'), true);
if (is_array($gridFilters)) {
    foreach (['name'=>'l.name','source'=>'l.source','phone'=>'l.phone'] as $key=>$column) {
        $value=trim((string)($gridFilters[$key]??'')); if($value!==''){ $where[]="$column LIKE ?"; $params[]='%'.$value.'%'; }
    }
    if (!empty($gridFilters['status'])) {
        $statuses=is_array($gridFilters['status'])?$gridFilters['status']:[$gridFilters['status']];
        $statuses=array_values(array_filter(array_map('strval',$statuses)));
        if($statuses){
            $ids=array_values(array_filter($statuses,static fn($status)=>ctype_digit($status)));
            if($ids){$where[]='l.stage_id IN ('.implode(',',array_fill(0,count($ids),'?')).')'; array_push($params,...array_map('intval',$ids));}
        }
    }
    // Header filters are applied before pagination, so they search the full dataset.
    if (isset($gridFilters['score']) && trim((string)$gridFilters['score']) !== '') {
        $scoreText = trim((string)$gridFilters['score']);
        if (ctype_digit($scoreText)) {
            $scoreSql = '((l.email IS NOT NULL AND l.email<>\'\')*20+(l.phone IS NOT NULL AND l.phone<>\'\')*20+(l.consumo_cliente IS NOT NULL AND l.consumo_cliente<>\'\')*15+(l.estimativa_projeto_kwh IS NOT NULL AND l.estimativa_projeto_kwh<>\'\')*10+(CHAR_LENGTH(COALESCE(l.notes,\'\'))>40)*10+(l.source IS NOT NULL AND l.source<>\'\')*10)';
            $where[] = "$scoreSql = ?";
            $params[] = (int)$scoreText;
        }
    }
    if (isset($gridFilters['valor']) && trim((string)$gridFilters['valor']) !== '') {
        $value = trim((string)$gridFilters['valor']);
        $where[] = 'CAST(COALESCE(l.orcamento_value,0) AS CHAR) LIKE ?';
        $params[] = '%' . $value . '%';
    }
    foreach (['criado'=>'COALESCE(l.data_inicio,l.created_at)','ultimo_contato'=>'l.ultimo_contato'] as $key=>$column) {
        $range=$gridFilters[$key]??null; if(!is_array($range)) continue;
        if(!empty($range['from'])){$where[]="DATE($column) >= ?";$params[]=$range['from'];}
        if(!empty($range['to'])){$where[]="DATE($column) <= ?";$params[]=$range['to'];}
    }
}
$stage = (string)($_GET['stage_id'] ?? '');
if ($mode === 'stage') {
    if ($stage === 'sem_status' || $stage === '0') $where[]='(l.stage_id IS NULL OR l.stage_id = 0)';
    elseif (ctype_digit($stage)) { $where[]='l.stage_id = ?'; $params[]=(int)$stage; }
}
$whereSql = implode(' AND ', $where);
$fields = 'l.id,l.user_id,l.name,l.cidade,l.email,l.phone,l.cpf_cnpj,l.source,l.status,l.stage_id,l.notes,l.consumo_cliente,l.estimativa_projeto_kwh,l.orcamento_value,l.envio_proposta,l.ultimo_contato,l.forma_pagamento,l.created_at,l.data_inicio,l.updated_at,EXISTS(SELECT 1 FROM projetos p WHERE p.lead_id=l.id) AS has_project,(SELECT COUNT(*) FROM leads_attachments a WHERE a.lead_id=l.id) AS anexos_count';
$photoColumn = $pdo->query("SHOW COLUMNS FROM leads LIKE 'whatsapp_profile_image'");
$fields .= $photoColumn->fetch() ? ',l.whatsapp_profile_image' : ',NULL AS whatsapp_profile_image';

try {
    if ($mode === 'summary') {
        $sql="SELECT COALESCE(l.stage_id,0) stage_id,COUNT(*) total,COALESCE(SUM(l.orcamento_value),0) value,COALESCE(SUM(CASE WHEN fs.id IS NULL OR fs.include_in_forecast=1 THEN l.orcamento_value ELSE 0 END),0) pipeline_value FROM leads l LEFT JOIN funil_stages fs ON fs.id=l.stage_id WHERE $whereSql GROUP BY COALESCE(l.stage_id,0)";
        $st=$pdo->prepare($sql); $st->execute($params); $stages=$st->fetchAll(PDO::FETCH_ASSOC);
        $total=0; $value=0.0; foreach($stages as $row){$total+=(int)$row['total'];$value+=(float)$row['pipeline_value'];}
        echo json_encode(['success'=>true,'total'=>$total,'value'=>$value,'stages'=>$stages],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR); exit;
    }
    $sortMap=['name'=>'l.name','source'=>'l.source','status'=>'l.status','phone'=>'l.phone','valor'=>'l.orcamento_value','criado'=>'COALESCE(l.data_inicio,l.created_at)','ultimo_contato'=>'l.ultimo_contato'];
    $defaultSort = $mode === 'stage' ? 'l.updated_at' : 'l.created_at';
    $sort=$sortMap[(string)($_GET['sort']??'')]??$defaultSort; $dir=strtolower((string)($_GET['dir']??'desc'))==='asc'?'ASC':'DESC';
    $count=$pdo->prepare("SELECT COUNT(*) FROM leads l WHERE $whereSql"); $count->execute($params); $total=(int)$count->fetchColumn();
    $sql="SELECT $fields FROM leads l WHERE $whereSql ORDER BY $sort $dir, l.id DESC LIMIT $limit OFFSET $offset";
    $st=$pdo->prepare($sql); $st->execute($params); $items=$st->fetchAll(PDO::FETCH_ASSOC);
    foreach($items as &$item){$item['anexos_files']=[];} unset($item);
    echo json_encode(['success'=>true,'items'=>$items,'page'=>$page,'limit'=>$limit,'total'=>$total,'pages'=>(int)ceil($total/$limit),'has_more'=>$offset+count($items)<$total],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
} catch(Throwable $e) { error_log('leads_paged: '.$e->getMessage()); http_response_code(500); echo json_encode(['success'=>false,'error'=>'Falha ao carregar leads']); }
