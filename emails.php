<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/email_center.php';
if (empty($_SESSION['email_csrf'])) $_SESSION['email_csrf'] = bin2hex(random_bytes(32));
try { wrcrm_email_ensure_schema($pdo); } catch (Throwable $e) { $emailSetupError = $e->getMessage(); }
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="assets/css/email_center.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/email_center.css') ?>">
<div class="d-flex">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="flex-grow-1 email-page">
    <div class="email-shell">
      <aside class="email-folders">
        <button class="btn btn-primary email-compose" id="newEmailBtn"><i class="fa-solid fa-pen me-2"></i>Nova mensagem</button>
        <nav aria-label="Pastas de e-mail">
          <button class="email-folder active" data-folder="sent"><i class="fa-regular fa-paper-plane"></i><span>Enviados</span></button>
          <button class="email-folder" data-folder="draft"><i class="fa-regular fa-file-lines"></i><span>Rascunhos</span><b id="draftCount"></b></button>
          <button class="email-folder" data-folder="failed"><i class="fa-solid fa-triangle-exclamation"></i><span>Falhas</span></button>
          <button class="email-folder" data-folder="trash"><i class="fa-regular fa-trash-can"></i><span>Lixeira</span></button>
          <button class="email-folder" data-folder="contacts"><i class="fa-regular fa-address-book"></i><span>Contatos</span></button>
        </nav>
        <div class="email-phase-note"><i class="fa-solid fa-circle-info"></i><span>Caixa de entrada e spam estarão disponíveis após configurar o recebimento IMAP.</span></div>
      </aside>
      <section class="email-content">
        <header class="email-content-header">
          <div><h1>E-mails</h1><p id="folderSubtitle">Mensagens enviadas pelo CRM</p></div>
          <button class="email-account-pill" id="emailAccountBtn" type="button" title="Configurar e-mail do perfil">
            <i class="fa-regular fa-envelope"></i>
            <span id="emailAccountText">Carregando conta...</span>
          </button>
          <div class="email-search"><i class="fa-solid fa-magnifying-glass"></i><input id="emailSearch" type="search" placeholder="Pesquisar e-mails"></div>
          <button class="btn btn-light" id="refreshEmails" title="Atualizar"><i class="fa-solid fa-rotate"></i></button>
        </header>
        <?php if (!empty($emailSetupError)): ?><div class="alert alert-danger m-3">Não foi possível preparar o módulo: <?= htmlspecialchars($emailSetupError) ?></div><?php endif; ?>
        <div id="emailLoading" class="email-state"><span class="spinner-border spinner-border-sm"></span> Carregando mensagens...</div>
        <div id="emailEmpty" class="email-state d-none"><i class="fa-regular fa-envelope-open"></i><strong>Nenhuma mensagem aqui</strong><span>As mensagens desta pasta aparecerão nesta área.</span></div>
        <div id="emailList" class="email-list"></div>
        <div id="contactsList" class="contacts-list d-none"></div>
      </section>
    </div>
  </main>
</div>

<div class="email-compose-window d-none" id="composeWindow" role="dialog" aria-modal="true" aria-labelledby="composeTitle">
  <div class="compose-header"><strong id="composeTitle">Nova mensagem</strong><div><button type="button" id="minimizeCompose" title="Minimizar"><i class="fa-solid fa-minus"></i></button><button type="button" id="closeCompose" title="Fechar"><i class="fa-solid fa-xmark"></i></button></div></div>
  <form id="composeForm" enctype="multipart/form-data">
    <input type="hidden" name="id" id="emailId">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['email_csrf']) ?>">
    <div class="compose-address"><label>Para</label><input name="to" id="emailTo" autocomplete="off" placeholder="nome@empresa.com" required><button type="button" id="toggleCopies">Cc Cco</button><div id="contactSuggestions" class="contact-suggestions d-none"></div></div>
    <div id="copyFields" class="d-none">
      <div class="compose-address"><label>Cc</label><input name="cc" id="emailCc" placeholder="Cópia"></div>
      <div class="compose-address"><label>Cco</label><input name="bcc" id="emailBcc" placeholder="Cópia oculta"></div>
      <div class="compose-address"><label>Responder</label><input name="reply_to" id="emailReplyTo" type="email" placeholder="Endereço de resposta (opcional)"></div>
    </div>
    <input class="compose-subject" name="subject" id="emailSubject" placeholder="Assunto" required maxlength="998">
    <div class="compose-toolbar" role="toolbar">
      <button type="button" data-command="bold" title="Negrito"><b>B</b></button><button type="button" data-command="italic" title="Itálico"><i>I</i></button><button type="button" data-command="underline" title="Sublinhado"><u>U</u></button>
      <span></span><button type="button" data-command="insertUnorderedList" title="Lista"><i class="fa-solid fa-list-ul"></i></button><button type="button" data-command="insertOrderedList" title="Lista numerada"><i class="fa-solid fa-list-ol"></i></button>
      <button type="button" data-command="justifyLeft"><i class="fa-solid fa-align-left"></i></button><button type="button" data-command="justifyCenter"><i class="fa-solid fa-align-center"></i></button><button type="button" data-command="createLink"><i class="fa-solid fa-link"></i></button><button type="button" data-command="removeFormat"><i class="fa-solid fa-eraser"></i></button>
    </div>
    <div id="emailBody" class="compose-body" contenteditable="true" data-placeholder="Escreva sua mensagem..."></div>
    <div id="existingAttachments" class="compose-files"></div>
    <div id="selectedFiles" class="compose-files"></div>
    <input class="d-none" type="file" name="attachments[]" id="emailAttachments" multiple accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip,.rar">
    <footer class="compose-footer">
      <button type="submit" class="btn btn-primary" id="sendEmailBtn"><i class="fa-regular fa-paper-plane me-1"></i>Enviar</button>
      <button type="button" class="btn btn-light" id="attachEmailBtn" title="Anexar arquivos"><i class="fa-solid fa-paperclip"></i></button>
      <span class="compose-hint">Máx. 10 MB por arquivo e 15 MB no total</span>
      <button type="button" class="btn btn-outline-secondary ms-auto" id="saveDraftBtn">Salvar rascunho</button>
      <button type="button" class="btn btn-outline-danger d-none" id="trashDraftBtn" title="Mover para lixeira"><i class="fa-regular fa-trash-can"></i></button>
    </footer>
  </form>
</div>

<div class="modal fade" id="emailViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header email-view-header">
        <div class="email-view-heading">
          <span class="email-view-heading-icon"><i class="fa-regular fa-paper-plane"></i></span>
          <div><span class="email-view-eyebrow">Mensagem enviada</span><h5 class="modal-title" id="viewSubject"></h5><small id="viewMeta"></small></div>
        </div>
        <button class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body email-view-modal-body">
        <section class="email-view-recipient-card">
          <span class="email-view-section-icon"><i class="fa-regular fa-user"></i></span>
          <div><span class="email-view-section-label">Detalhes do envio</span><div id="viewRecipients"></div></div>
        </section>
        <section class="email-view-message-card">
          <div class="email-view-message-label"><i class="fa-regular fa-envelope-open"></i> Conteúdo da mensagem</div>
          <div id="viewBody" class="email-view-body"></div>
        </section>
        <section id="viewAttachments" class="email-view-attachments"></section>
      </div>
      <div class="modal-footer email-view-footer" id="viewActions"></div>
    </div>
  </div>
</div>
<div class="modal fade" id="emailAccountModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form class="modal-content" id="emailAccountForm">
      <div class="modal-header">
        <div class="email-account-modal-title"><span><i class="fa-regular fa-envelope"></i></span><div><h5 class="modal-title">E-mail do perfil</h5><small id="emailAccountSummary"></small></div></div>
        <button class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['email_csrf']) ?>">
        <div class="email-account-grid">
          <section class="email-account-section">
            <div class="email-account-section-title"><i class="fa-solid fa-server"></i><div><strong>Servidor SMTP</strong><small>Conexão para o envio das mensagens</small></div></div>
            <div class="email-account-field"><label class="form-label" for="accountPreferredScope">Enviar usando</label><select class="form-select" name="preferred_scope" id="accountPreferredScope"><option value="user">Meu e-mail do perfil</option><option value="system">E-mail do sistema</option></select></div>
            <div class="email-account-field"><label class="form-label" for="accountSmtpHost">Host SMTP</label><input class="form-control" name="host" id="accountSmtpHost" placeholder="smtp.seudominio.com"></div>
            <div class="row g-3">
              <div class="col-6"><div class="email-account-field"><label class="form-label" for="accountSmtpPort">Porta</label><input class="form-control" name="port" id="accountSmtpPort" type="number" placeholder="587"></div></div>
              <div class="col-6"><div class="email-account-field"><label class="form-label" for="accountSmtpSecure">Segurança</label><select class="form-select" name="secure" id="accountSmtpSecure"><option value="">Nenhuma</option><option value="tls">TLS</option><option value="ssl">SSL</option></select></div></div>
            </div>
            <div class="email-auth-switch"><div><strong>Autenticação SMTP</strong><small>Usar usuário e senha no servidor</small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="auth" id="accountSmtpAuth" value="1" checked><label class="visually-hidden" for="accountSmtpAuth">Requer autenticação</label></div></div>
          </section>
          <section class="email-account-section">
            <div class="email-account-section-title"><i class="fa-regular fa-id-card"></i><div><strong>Conta e remetente</strong><small>Identificação exibida nos e-mails</small></div></div>
            <div class="email-account-field"><label class="form-label" for="accountSmtpUser">Usuário SMTP</label><input class="form-control" name="user" id="accountSmtpUser" placeholder="seuemail@dominio.com"></div>
            <div class="email-account-field"><label class="form-label" for="accountSmtpPass">Senha SMTP</label><input class="form-control" name="pass" id="accountSmtpPass" type="password" autocomplete="new-password" placeholder="Deixe em branco para manter"><small class="email-field-hint" id="accountSmtpPassHint"></small></div>
            <div class="email-account-field"><label class="form-label" for="accountSmtpFromEmail">E-mail remetente</label><input class="form-control" name="from_email" id="accountSmtpFromEmail" type="email" placeholder="seuemail@dominio.com"></div>
            <div class="email-account-field"><label class="form-label" for="accountSmtpFromName">Nome do remetente</label><input class="form-control" name="from_name" id="accountSmtpFromName" placeholder="Seu nome ou empresa"></div>
          </section>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit"><i class="fa-regular fa-floppy-disk me-2"></i>Salvar e usar este e-mail</button></div>
    </form>
  </div>
</div>
<div class="toast-container position-fixed bottom-0 end-0 p-3"><div id="emailToast" class="toast text-bg-dark" role="alert"><div class="d-flex"><div class="toast-body" id="emailToastText"></div><button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div></div>
<script src="assets/js/email_center.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/email_center.js') ?>"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
