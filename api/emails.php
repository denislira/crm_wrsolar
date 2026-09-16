<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Não autorizado']); exit; }
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/email_center.php';

try { wrcrm_email_ensure_schema($pdo); } catch (Throwable $e) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Não foi possível preparar o módulo de e-mails.']); exit; }
$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

function email_json(array $data, int $status = 200): void { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function email_owned(PDO $pdo, int $id, int $userId): array {
    $stmt=$pdo->prepare('SELECT * FROM crm_emails WHERE id=? AND user_id=?'); $stmt->execute([$id,$userId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC); if (!$row) email_json(['success'=>false,'message'=>'Mensagem não encontrada.'],404); return $row;
}
function email_attachments(PDO $pdo, int $emailId, int $userId): array {
    $stmt=$pdo->prepare('SELECT a.* FROM crm_email_attachments a JOIN crm_emails e ON e.id=a.email_id WHERE a.email_id=? AND e.user_id=? ORDER BY a.id');
    $stmt->execute([$emailId,$userId]); return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function email_account_payload(PDO $pdo, int $userId): array {
    return ['success'=>true,'account'=>wrcrm_email_public_account($pdo,$userId)];
}

try {
    if ($action === 'account') {
        email_json(email_account_payload($pdo,$userId));
    }
    if ($action === 'save_account') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') email_json(['success'=>false,'message'=>'Método não permitido.'],405);
        if (empty($_SESSION['email_csrf']) || !hash_equals($_SESSION['email_csrf'], (string)($_POST['csrf_token'] ?? ''))) email_json(['success'=>false,'message'=>'Sessão expirada. Atualize a página e tente novamente.'],419);
        $smtp = [
            'host'=>trim((string)($_POST['host']??'')),
            'port'=>(int)($_POST['port']??0),
            'secure'=>trim((string)($_POST['secure']??'')),
            'user'=>trim((string)($_POST['user']??'')),
            'pass'=>(string)($_POST['pass']??''),
            'from_email'=>trim((string)($_POST['from_email']??'')),
            'from_name'=>trim((string)($_POST['from_name']??'')),
            'auth'=>isset($_POST['auth']) && in_array((string)$_POST['auth'], ['1','true','on'], true) ? 1 : 0,
        ];
        $from = $smtp['from_email'] ?: $smtp['user'];
        if ($from !== '' && !filter_var($from,FILTER_VALIDATE_EMAIL)) email_json(['success'=>false,'message'=>'Informe um e-mail remetente válido.'],422);
        if (!wrcrm_email_save_user_smtp($pdo,$userId,$smtp)) email_json(['success'=>false,'message'=>'Não foi possível salvar o e-mail do perfil.'],500);
        wrcrm_email_save_user_scope($pdo,$userId,(string)($_POST['preferred_scope'] ?? 'user'));
        email_json(['success'=>true,'message'=>'E-mail do perfil salvo.','account'=>wrcrm_email_public_account($pdo,$userId)]);
    }
    if ($action === 'list') {
        $folder = $_GET['folder'] ?? 'sent';
        if (!in_array($folder,['sent','draft','failed','trash'],true)) $folder='sent';
        $search=trim((string)($_GET['q']??''));
        $sql='SELECT e.*, (SELECT COUNT(*) FROM crm_email_attachments a WHERE a.email_id=e.id) attachment_count FROM crm_emails e WHERE e.user_id=? AND e.status=?';
        $params=[$userId,$folder];
        if ($search!=='') { $sql.=' AND (e.recipient_to LIKE ? OR e.subject LIKE ? OR e.body_html LIKE ?)'; $like='%'.$search.'%'; array_push($params,$like,$like,$like); }
        $sql.=' ORDER BY COALESCE(e.sent_at,e.updated_at) DESC LIMIT 200';
        $stmt=$pdo->prepare($sql); $stmt->execute($params);
        email_json(['success'=>true,'messages'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($action === 'get') {
        $mail=email_owned($pdo,(int)($_GET['id']??0),$userId);
        $mail['attachments']=email_attachments($pdo,(int)$mail['id'],$userId);
        foreach ($mail['attachments'] as &$a) $a['download_url']='api/emails.php?action=attachment&id='.(int)$a['id'];
        email_json(['success'=>true,'message'=>$mail]);
    }
    if ($action === 'contacts') {
        $q=trim((string)($_GET['q']??'')); if (mb_strlen($q)<2) email_json(['success'=>true,'contacts'=>[]]);
        $like='%'.$q.'%'; $all=[];
        foreach ([['leads','name'],['customers','name'],['pos_venda','client_name']] as [$table,$name]) {
            try { $st=$pdo->prepare("SELECT {$name} name,email FROM {$table} WHERE email IS NOT NULL AND email<>'' AND ({$name} LIKE ? OR email LIKE ?) ORDER BY id DESC LIMIT 15"); $st->execute([$like,$like]); $all=array_merge($all,$st->fetchAll(PDO::FETCH_ASSOC)); } catch(Throwable $ignored) {}
        }
        $seen=[]; $contacts=[]; foreach($all as $c){$key=strtolower($c['email']);if(!isset($seen[$key])){$seen[$key]=1;$contacts[]=$c;}if(count($contacts)>=20)break;}
        email_json(['success'=>true,'contacts'=>$contacts]);
    }
    if ($action === 'contacts_list') {
        $q=trim((string)($_GET['q']??'')); $like='%'.$q.'%'; $all=[];
        foreach ([['leads','name','Lead'],['customers','name','Cliente'],['pos_venda','client_name','Pós-venda']] as [$table,$name,$source]) {
            try { $st=$pdo->prepare("SELECT id, {$name} name, email, phone FROM {$table} WHERE email IS NOT NULL AND email<>''" . ($q!=='' ? " AND ({$name} LIKE ? OR email LIKE ?)" : '') . " ORDER BY {$name} ASC LIMIT 500"); $st->execute($q!==''?[$like,$like]:[]); foreach($st->fetchAll(PDO::FETCH_ASSOC) as $c){$c['source']=$source;$all[]=$c;} } catch(Throwable $ignored) {}
        }
        $seen=[]; $contacts=[]; foreach($all as $c){$key=strtolower(trim($c['email']));if(isset($seen[$key]))continue;$seen[$key]=1;$contacts[]=$c;}
        usort($contacts,static fn($a,$b)=>strnatcasecmp($a['name']??'',$b['name']??'')); email_json(['success'=>true,'contacts'=>$contacts]);
    }
    if ($action === 'attachment') {
        $st=$pdo->prepare('SELECT a.*,e.user_id FROM crm_email_attachments a JOIN crm_emails e ON e.id=a.email_id WHERE a.id=? AND e.user_id=?');
        $st->execute([(int)($_GET['id']??0),$userId]); $a=$st->fetch(PDO::FETCH_ASSOC); if(!$a) email_json(['success'=>false,'message'=>'Anexo não encontrado.'],404);
        $path=wrcrm_email_attachment_dir($userId,(int)$a['email_id']).'/'.basename($a['stored_name']); if(!is_file($path)) email_json(['success'=>false,'message'=>'Arquivo não encontrado.'],404);
        header_remove('Content-Type'); header('Content-Type: '.$a['mime_type']); header('Content-Length: '.filesize($path)); header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($a['original_name'])); readfile($path); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') email_json(['success'=>false,'message'=>'Método não permitido.'],405);
    if (empty($_SESSION['email_csrf']) || !hash_equals($_SESSION['email_csrf'], (string)($_POST['csrf_token'] ?? ''))) email_json(['success'=>false,'message'=>'Sessão expirada. Atualize a página e tente novamente.'],419);
    if ($action === 'save' || $action === 'send') {
        $id=(int)($_POST['id']??0); $existing=$id?email_owned($pdo,$id,$userId):null;
        if ($existing && !in_array($existing['status'],['draft','failed'],true)) email_json(['success'=>false,'message'=>'Somente rascunhos ou mensagens com falha podem ser editados.'],409);
        $data=[
            'recipient_to'=>trim((string)($_POST['to']??'')), 'recipient_cc'=>trim((string)($_POST['cc']??'')),
            'recipient_bcc'=>trim((string)($_POST['bcc']??'')), 'reply_to'=>trim((string)($_POST['reply_to']??'')),
            'subject'=>mb_substr(trim((string)($_POST['subject']??'')),0,998), 'body_html'=>wrcrm_email_clean_html((string)($_POST['body_html']??''))
        ];
        foreach(['recipient_to','recipient_cc','recipient_bcc'] as $field) if($data[$field]!=='') wrcrm_email_addresses($data[$field]);
        if ($data['reply_to']!=='' && !filter_var($data['reply_to'],FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('O endereço de resposta é inválido.');
        $pdo->beginTransaction();
        if ($existing) {
            $st=$pdo->prepare("UPDATE crm_emails SET recipient_to=?,recipient_cc=?,recipient_bcc=?,reply_to=?,subject=?,body_html=?,status='draft',error_message=NULL WHERE id=? AND user_id=?");
            $st->execute([$data['recipient_to'],$data['recipient_cc'],$data['recipient_bcc'],$data['reply_to'],$data['subject'],$data['body_html'],$id,$userId]);
        } else {
            $st=$pdo->prepare('INSERT INTO crm_emails (user_id,recipient_to,recipient_cc,recipient_bcc,reply_to,subject,body_html) VALUES (?,?,?,?,?,?,?)');
            $st->execute([$userId,$data['recipient_to'],$data['recipient_cc'],$data['recipient_bcc'],$data['reply_to'],$data['subject'],$data['body_html']]); $id=(int)$pdo->lastInsertId();
        }
        if (!empty($_FILES['attachments'])) wrcrm_email_store_uploads($pdo,$userId,$id,$_FILES['attachments']);
        $pdo->commit();
        if ($action==='save') email_json(['success'=>true,'message'=>'Rascunho salvo.','id'=>$id]);
        $account = wrcrm_email_public_account($pdo,$userId);
        $smtp = $account['active_scope'] === 'user' ? wrcrm_email_get_user_smtp($pdo,$userId) : wrcrm_email_get_system_smtp();
        if (empty($smtp['host'])) throw new RuntimeException($account['active_scope'] === 'user' ? 'Configure o SMTP do seu perfil antes de enviar.' : 'Configure o SMTP do sistema antes de enviar.');
        $fromEmail = trim($smtp['from_email'] ?: $smtp['user']);
        if (!$fromEmail || !filter_var($fromEmail,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Configure um remetente válido para enviar.');
        $fromName = trim($smtp['from_name'] ?: $account['active_name'] ?: 'WRCRM');
        $st=$pdo->prepare('UPDATE crm_emails SET from_email=?,from_name=?,smtp_scope=? WHERE id=? AND user_id=?');
        $st->execute([$fromEmail,$fromName,$account['active_scope'],$id,$userId]);
        $mail=email_owned($pdo,$id,$userId); $attachments=email_attachments($pdo,$id,$userId);
        foreach($attachments as &$a) $a['path']=wrcrm_email_attachment_dir($userId,$id).'/'.$a['stored_name'];
        try { wrcrm_send_composed_email($mail,$attachments,$smtp); $st=$pdo->prepare("UPDATE crm_emails SET status='sent',sent_at=NOW(),error_message=NULL WHERE id=? AND user_id=?"); $st->execute([$id,$userId]); email_json(['success'=>true,'message'=>'E-mail enviado com sucesso.','id'=>$id]); }
        catch(Throwable $e){$st=$pdo->prepare("UPDATE crm_emails SET status='failed',error_message=? WHERE id=? AND user_id=?");$st->execute([mb_substr($e->getMessage(),0,2000),$id,$userId]);throw $e;}
    }
    if (in_array($action,['trash','restore','delete_attachment'],true)) {
        $id=(int)($_POST['id']??0);
        if($action==='delete_attachment'){
            $st=$pdo->prepare('SELECT a.* FROM crm_email_attachments a JOIN crm_emails e ON e.id=a.email_id WHERE a.id=? AND e.user_id=? AND e.status IN (\'draft\',\'failed\')');$st->execute([$id,$userId]);$a=$st->fetch(PDO::FETCH_ASSOC);if(!$a)email_json(['success'=>false,'message'=>'Anexo não encontrado.'],404);
            $path=wrcrm_email_attachment_dir($userId,(int)$a['email_id']).'/'.basename($a['stored_name']);if(is_file($path))@unlink($path);$pdo->prepare('DELETE FROM crm_email_attachments WHERE id=?')->execute([$id]);email_json(['success'=>true,'message'=>'Anexo removido.']);
        }
        $mail=email_owned($pdo,$id,$userId);
        if($action==='trash'){$prev=$mail['status']==='trash'?($mail['previous_status']?:'draft'):$mail['status'];$st=$pdo->prepare("UPDATE crm_emails SET previous_status=?,status='trash',deleted_at=NOW() WHERE id=? AND user_id=?");$st->execute([$prev,$id,$userId]);email_json(['success'=>true,'message'=>'Mensagem movida para a lixeira.']);}
        $restore=in_array($mail['previous_status'],['draft','sent','failed'],true)?$mail['previous_status']:'draft';$st=$pdo->prepare('UPDATE crm_emails SET status=?,previous_status=NULL,deleted_at=NULL WHERE id=? AND user_id=?');$st->execute([$restore,$id,$userId]);email_json(['success'=>true,'message'=>'Mensagem restaurada.']);
    }
    email_json(['success'=>false,'message'=>'Ação inválida.'],400);
} catch (InvalidArgumentException $e) { if($pdo->inTransaction())$pdo->rollBack(); email_json(['success'=>false,'message'=>$e->getMessage()],422); }
catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); error_log('[WRCRM EMAIL] '.$e->getMessage()); email_json(['success'=>false,'message'=>$e->getMessage()],500); }
