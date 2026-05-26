<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/config.php';
header('Content-Type: text/html; charset=utf-8');

$token = trim((string)($_REQUEST['token'] ?? ''));
$indicatorName = trim((string)($_POST['indicator_name'] ?? ''));
$indicatorPhone = trim((string)($_POST['indicator_phone'] ?? ''));
$indicatorEmail = trim((string)($_POST['indicator_email'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));
$error = '';
$success = '';
$submitted = false;
$clientName = '';
$posVendaId = null;
$userId = null;

if ($token === '') {
    $error = 'Link de indicação inválido ou ausente.';
} else {
    $stmt = $pdo->prepare('SELECT id, client_name, user_id FROM pos_venda WHERE referral_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $pv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pv) {
        $error = 'Link de indicação inválido ou expirado.';
    } else {
        $posVendaId = intval($pv['id']);
        $clientName = trim((string)$pv['client_name']);
        $userId = intval($pv['user_id']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if ($indicatorName === '') {
        $error = 'Por favor, informe o nome do indicador.';
    } elseif ($indicatorPhone === '') {
        $error = 'Por favor, informe o telefone do indicador.';
    } else {
        $duplicateStmt = $pdo->prepare('SELECT id FROM pos_venda_referrals WHERE referral_token = ? LIMIT 1');
        $duplicateStmt->execute([$token]);
        if ($duplicateStmt->fetch()) {
            $error = 'Esta indicação já foi enviada anteriormente.';
        } else {
            $insert = $pdo->prepare('INSERT INTO pos_venda_referrals (pos_venda_id, user_id, referral_token, indicator_name, indicator_phone, indicator_email, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $insert->execute([
                $posVendaId,
                $userId,
                $token,
                $indicatorName,
                $indicatorPhone,
                $indicatorEmail !== '' ? $indicatorEmail : null,
                $notes !== '' ? $notes : null,
            ]);
            $success = 'Sua indicação foi recebida com sucesso. Obrigado!';
            $submitted = true;
        }
    }
}

function e($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Indicação de Cliente</title>
    <style>
        body { background:#f4f5f7; color:#1f2937; font-family:Arial,Helvetica,sans-serif; margin:0; padding:0; }
        .page-wrap { max-width:540px; margin:3rem auto; padding:1.5rem; background:#fff; border-radius:18px; box-shadow:0 18px 60px rgba(15,23,42,0.08); }
        h1 { margin:0 0 .75rem; font-size:1.7rem; }
        p { margin:.75rem 0; line-height:1.6; }
        .alert { border-radius:12px; padding:1rem 1rem; margin:1rem 0; }
        .alert-error { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
        .alert-success { background:#d1fae5; color:#065f46; border:1px solid #a7f3d0; }
        .form-group { margin-bottom:1rem; }
        label { display:block; margin-bottom:.4rem; font-weight:600; }
        input, textarea { width:100%; border:1px solid #d1d5db; border-radius:12px; padding:.75rem 1rem; font-size:1rem; background:#f8fafc; }
        textarea { min-height:120px; resize:vertical; }
        button { display:inline-flex; align-items:center; justify-content:center; gap:.5rem; padding:.8rem 1.1rem; border:none; border-radius:999px; background:#2563eb; color:#fff; font-size:1rem; cursor:pointer; }
        button:disabled { opacity:.65; cursor:not-allowed; }
        .meta { font-size:.95rem; color:#475569; }
        .footer { margin-top:1.5rem; font-size:.9rem; color:#64748b; }
    </style>
</head>
<body>
<div class="page-wrap">
    <h1>Indicação de cliente</h1>
    <?php if ($clientName !== ''): ?>
        <p class="meta">Indicação para o cliente <strong><?= e($clientName) ?></strong>.</p>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if (!$submitted && $error === ''): ?>
        <form method="post" action="indicacao.php">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <div class="form-group">
                <label for="indicator_name">Nome do indicador</label>
                <input id="indicator_name" name="indicator_name" type="text" value="<?= e($indicatorName) ?>" required>
            </div>
            <div class="form-group">
                <label for="indicator_phone">Telefone</label>
                <input id="indicator_phone" name="indicator_phone" type="text" value="<?= e($indicatorPhone) ?>" required>
            </div>
            <div class="form-group">
                <label for="indicator_email">E-mail</label>
                <input id="indicator_email" name="indicator_email" type="email" value="<?= e($indicatorEmail) ?>">
            </div>
            <div class="form-group">
                <label for="notes">Observações</label>
                <textarea id="notes" name="notes"><?= e($notes) ?></textarea>
            </div>
            <button type="submit">Enviar indicação</button>
        </form>
    <?php endif; ?>

    <div class="footer">
        <p>Se você recebeu este link de indicação, preencha os dados para que a equipe possa tratar o contato.</p>
    </div>
</div>
</body>
</html>
