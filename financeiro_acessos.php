<?php
declare(strict_types=1);
if (session_status()===PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/financeiro_lib.php';
checkAccessOrRedirect('configuracoes');
header('Location: configuracoes.php#permissions');
exit;
if (!isDirector()) { http_response_code(403); exit('Acesso restrito à direção.'); }
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 try {
  fin_check_token(); $id=(int)($_POST['user_id']??0); $allowed=($_POST['allowed']??'0')==='1'?1:0;
  if (!$id) throw new InvalidArgumentException('Usuário inválido.');
  $pdo->beginTransaction();
  $stmt=$pdo->prepare('SELECT id,role_id,financeiro_permitido FROM users WHERE id=? FOR UPDATE'); $stmt->execute([$id]); $user=$stmt->fetch(PDO::FETCH_ASSOC);
  if (!$user) throw new RuntimeException('Usuário não encontrado.');
  if ($allowed) {
   $roleId=(int)$user['role_id'];
   $exists=$pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id=? AND screen='financeiro'"); $exists->execute([$roleId]);
   if ((int)$exists->fetchColumn()) $pdo->prepare("UPDATE role_permissions SET allowed=1 WHERE role_id=? AND screen='financeiro'")->execute([$roleId]);
   else $pdo->prepare("INSERT INTO role_permissions(role_id,screen,allowed) VALUES(?,'financeiro',1)")->execute([$roleId]);
  }
  $pdo->prepare('UPDATE users SET financeiro_permitido=? WHERE id=?')->execute([$allowed,$id]);
  fin_audit($pdo,'usuario',$id,'acesso_financeiro',['anterior'=>(int)$user['financeiro_permitido'],'novo'=>$allowed],(int)$_SESSION['user_id']);
  $pdo->commit(); $_SESSION['fin_notice']='Acesso atualizado. O usuário deverá entrar novamente para atualizar a sessão.';
  header('Location: financeiro_acessos.php'); exit;
 } catch(Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error=$e->getMessage(); }
}
$users=$pdo->query('SELECT u.id,u.username,u.nome_completo,u.email,u.role_id,u.financeiro_permitido,rp.allowed AS perfil_permitido FROM users u LEFT JOIN role_permissions rp ON rp.role_id=u.role_id AND rp.screen=\'financeiro\' ORDER BY u.nome_completo,u.username')->fetchAll(PDO::FETCH_ASSOC);
$notice=$_SESSION['fin_notice']??null; unset($_SESSION['fin_notice']);
include __DIR__.'/includes/header.php'; include __DIR__.'/includes/sidebar.php';
?>
<main class="main-content-scroll py-4"><div class="container-fluid"><h1 class="h3">Acesso individual ao Financeiro</h1><p class="text-muted">Somente pessoas marcadas aqui e com permissão de perfil conseguem abrir o Financeiro. Ao permitir uma pessoa, o perfil dela é habilitado, mas os demais usuários do mesmo perfil continuam bloqueados até serem marcados individualmente.</p><div class="mb-3"><a class="btn btn-outline-secondary" href="financeiro.php">Voltar ao Financeiro</a></div><?php if($notice): ?><div class="alert alert-success"><?= fin_h($notice) ?></div><?php endif; ?><?php if($error): ?><div class="alert alert-danger"><?= fin_h($error) ?></div><?php endif; ?><div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Usuário</th><th>E-mail</th><th>Perfil #</th><th>Acesso individual</th><th>Ação</th></tr></thead><tbody><?php foreach($users as $user): ?><tr><td><?= fin_h($user['nome_completo']?:$user['username']) ?></td><td><?= fin_h($user['email']) ?></td><td><?= (int)$user['role_id'] ?></td><td><?= $user['financeiro_permitido']&&$user['perfil_permitido']?'<span class="badge text-bg-success">Permitido</span>':'<span class="badge text-bg-secondary">Bloqueado</span>' ?></td><td><form method="post"><input type="hidden" name="csrf" value="<?= fin_h(fin_token()) ?>"><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>"><input type="hidden" name="allowed" value="<?= $user['financeiro_permitido']?'0':'1' ?>"><button class="btn btn-sm <?= $user['financeiro_permitido']?'btn-outline-danger':'btn-outline-success' ?>"><?= $user['financeiro_permitido']?'Revogar':'Permitir' ?></button></form></td></tr><?php endforeach; ?></tbody></table></div></div></div></main>
<?php include __DIR__.'/includes/footer.php'; ?>
