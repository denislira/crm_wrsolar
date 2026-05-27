<?php
// ============================================================
//  Histórico completo do cliente — gerador de relatório HTML
//  Acessado via: api/client_history_pdf.php?pv_id=X
//  Imprime automaticamente ao abrir (window.print())
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403); exit('Acesso negado.');
}

require_once __DIR__ . '/../includes/config.php';

$pvId = intval($_GET['pv_id'] ?? 0);
if ($pvId <= 0) { http_response_code(400); exit('ID inválido.'); }

// ── 1. Pós-venda ────────────────────────────────────────────
$pvStmt = $pdo->prepare("
    SELECT pv.*,
           p.id            AS proj_id,
           p.status        AS proj_status,
           p.address,
           p.proposal_value,
           p.payment_type,
           p.payment_status,
           p.contract,
           p.closed_date,
           p.due_days,
           p.created_at    AS proj_created_at,
           l.id            AS lead_id,
           l.name          AS lead_name,
           l.phone,
           l.email,
           l.cidade,
           l.source,
           l.notes         AS lead_notes,
           l.observacao    AS lead_observacao,
           l.created_at    AS lead_created_at
    FROM pos_venda pv
    LEFT JOIN projetos p ON p.id = pv.project_id
    LEFT JOIN leads    l ON l.id = p.lead_id
    WHERE pv.id = ?
    LIMIT 1
");
$pvStmt->execute([$pvId]);
$pv = $pvStmt->fetch(PDO::FETCH_ASSOC);

if (!$pv) { http_response_code(404); exit('Registro não encontrado.'); }

$leadId  = $pv['lead_id']  ?? null;
$projId  = $pv['proj_id']  ?? null;

// ── 2. Movimentações do Lead ─────────────────────────────────
$leadMovements = [];
if ($leadId) {
    $stmt = $pdo->prepare("
        SELECT lm.*
        FROM lead_movements lm
        WHERE lm.lead_id = ?
        ORDER BY lm.created_at ASC
    ");
    $stmt->execute([$leadId]);
    $leadMovements = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── 3. Projeto — fases / observações (project checklists) ────
$checklists = [];
try {
    $stmt = $pdo->prepare("
        SELECT pci.*, pc.title AS checklist_title
        FROM project_checklist_items pci
        LEFT JOIN project_checklists pc ON pc.id = pci.checklist_id
        WHERE pci.project_id = ?
        ORDER BY pc.id, pci.id
    ");
    $stmt->execute([$projId]);
    $checklists = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* tabela pode não existir */ }

// ── 4. Reminders do projeto ──────────────────────────────────
$reminders = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM reminders
        WHERE project_id = ?
        ORDER BY remind_at ASC
    ");
    $stmt->execute([$projId]);
    $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── helpers ──────────────────────────────────────────────────
function h(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function dateBR(?string $d): string {
    if (!$d || $d === '0000-00-00') return '—';
    $p = explode(' ', $d)[0];
    $parts = explode('-', $p);
    return count($parts) === 3 ? "{$parts[2]}/{$parts[1]}/{$parts[0]}" : $d;
}
function dtBR(?string $d): string {
    if (!$d) return '—';
    $parts = explode(' ', $d);
    return dateBR($parts[0]) . (isset($parts[1]) ? ' ' . substr($parts[1], 0, 5) : '');
}
function money(?string $v): string {
    $n = floatval(str_replace(['.', ','], ['', '.'], (string)($v ?? '0')));
    return 'R$ ' . number_format($n, 2, ',', '.');
}

$clientName = h($pv['client_name'] ?: $pv['lead_name']);
$now = date('d/m/Y H:i');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Histórico — <?= $clientName ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #1a1a1a; background: #fff; }
  @page { margin: 18mm 14mm; }
  @media print {
    body { font-size: 11px; }
    .no-print { display: none !important; }
    section { page-break-inside: avoid; }
  }

  /* ── Cover bar ── */
  .cover { background: #1e3a5f; color: #fff; padding: 18px 22px 14px; border-radius: 0 0 10px 10px; margin-bottom: 18px; }
  .cover h1 { font-size: 17px; font-weight: 700; margin-bottom: 4px; }
  .cover .sub { font-size: 11px; opacity: .75; }
  .cover .meta { margin-top: 8px; font-size: 11px; opacity: .85; display: flex; gap: 24px; flex-wrap: wrap; }

  /* ── Sections ── */
  section { margin-bottom: 16px; border: 1px solid #dde3ed; border-radius: 8px; overflow: hidden; }
  section h2 { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
               background: #f1f5fb; border-bottom: 1px solid #dde3ed; padding: 7px 12px; color: #1e3a5f; }
  .sec-body { padding: 10px 12px; }

  /* ── Grid de campos ── */
  .field-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px 12px; }
  .field { }
  .field .label { font-size: 9px; text-transform: uppercase; letter-spacing: .05em; color: #64748b; font-weight: 700; margin-bottom: 2px; }
  .field .val { font-size: 12px; color: #111; word-break: break-word; }

  /* ── Observations text ── */
  .obs-block { background: #f8fafc; border-left: 3px solid #3b82f6; padding: 7px 10px; border-radius: 0 6px 6px 0;
               font-size: 11px; color: #334155; line-height: 1.5; white-space: pre-wrap; }

  /* ── Timeline ── */
  .timeline { list-style: none; }
  .timeline li { display: flex; gap: 10px; padding: 6px 0; border-bottom: 1px solid #f1f5f9; }
  .timeline li:last-child { border-bottom: none; }
  .tl-dot { width: 10px; height: 10px; border-radius: 50%; background: #3b82f6; flex-shrink: 0; margin-top: 3px; }
  .tl-dot.alert { background: #ef4444; }
  .tl-date { width: 110px; flex-shrink: 0; font-size: 10px; color: #64748b; padding-top: 1px; }
  .tl-content .from-to { font-size: 10px; color: #475569; }
  .tl-content .note { font-size: 11px; color: #1e293b; margin-top: 2px; }
  .tl-user { font-size: 10px; color: #94a3b8; margin-top: 2px; }

  /* ── Checklist ── */
  .chk-item { display: flex; gap: 8px; align-items: flex-start; padding: 4px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; }
  .chk-item:last-child { border-bottom: none; }
  .chk-box { width: 13px; height: 13px; border: 1.5px solid #94a3b8; border-radius: 3px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
  .chk-box.done { background: #22c55e; border-color: #22c55e; color: #fff; font-size: 9px; }

  /* ── Reminders ── */
  .rem-item { padding: 5px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; display: flex; gap: 10px; }
  .rem-item:last-child { border-bottom: none; }
  .rem-date { width: 90px; flex-shrink: 0; color: #64748b; }
  .rem-status { padding: 1px 7px; border-radius: 999px; font-size: 9px; font-weight: 700; }
  .rem-status.done { background: #dcfce7; color: #14532d; }
  .rem-status.pend { background: #fef3c7; color: #92400e; }

  /* ── Footer ── */
  .footer { margin-top: 18px; text-align: center; font-size: 9px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 8px; }

  /* ── Print button ── */
  .print-bar { position: fixed; top: 0; left: 0; right: 0; background: #1e3a5f; color: #fff;
               padding: 8px 16px; display: flex; align-items: center; gap: 10px; z-index: 999; }
  .print-bar button { background: #3b82f6; color: #fff; border: none; border-radius: 6px;
                      padding: 5px 16px; font-size: 12px; font-weight: 700; cursor: pointer; }
  .print-bar button:hover { background: #2563eb; }
  .print-spacer { height: 44px; }
</style>
</head>
<body>
<div class="no-print print-bar">
  <button onclick="window.print()">⬇ Baixar / Imprimir PDF</button>
  <span style="font-size:11px; opacity:.8">Clique em "Baixar / Imprimir PDF", selecione "Salvar como PDF" na janela de impressão e clique em Salvar.</span>
</div>
<div class="no-print print-spacer"></div>

<!-- ══ CAPA ══ -->
<div class="cover">
  <h1>Histórico Completo — <?= $clientName ?></h1>
  <div class="sub">Gerado em <?= $now ?> · WR CRM</div>
  <div class="meta">
    <span>📞 <?= h($pv['phone']) ?: '—' ?></span>
    <span>✉ <?= h($pv['email']) ?: '—' ?></span>
    <span>📍 <?= h($pv['cidade']) ?: '—' ?></span>
  </div>
</div>

<!-- ══ 1. LEAD ══ -->
<section>
  <h2>1. Dados do Lead</h2>
  <div class="sec-body">
    <div class="field-grid">
      <div class="field"><div class="label">Nome</div><div class="val"><?= h($pv['lead_name']) ?></div></div>
      <div class="field"><div class="label">Telefone</div><div class="val"><?= h($pv['phone']) ?: '—' ?></div></div>
      <div class="field"><div class="label">E-mail</div><div class="val"><?= h($pv['email']) ?: '—' ?></div></div>
      <div class="field"><div class="label">Cidade</div><div class="val"><?= h($pv['cidade']) ?: '—' ?></div></div>
      <div class="field"><div class="label">Origem</div><div class="val"><?= h($pv['source']) ?: '—' ?></div></div>
      <div class="field"><div class="label">Cadastrado em</div><div class="val"><?= dtBR($pv['lead_created_at']) ?></div></div>
    </div>

    <?php if (!empty($pv['lead_notes']) || !empty($pv['lead_observacao'])): ?>
    <div style="margin-top:10px;">
      <div class="field"><div class="label">Observações / Notas do Lead</div></div>
      <div class="obs-block" style="margin-top:4px;"><?= h(trim($pv['lead_notes'] . "\n" . $pv['lead_observacao'])) ?></div>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- ══ 2. PÓS-VENDA ══ -->
<section>
  <h2>2. Pós-venda</h2>
  <div class="sec-body">
    <div class="field-grid">
      <div class="field"><div class="label">Estágio Pós-venda</div><div class="val"><?= h($pv['stage']) ?: '—' ?></div></div>
      <div class="field"><div class="label">Tipo de Cliente</div><div class="val"><?= h($pv['client_type']) ?: '—' ?></div></div>
      <div class="field"><div class="label">Performance</div><div class="val"><?= $pv['performance_pct'] !== null ? h($pv['performance_pct']) . '%' : '—' ?></div></div>
      <div class="field"><div class="label">Data de Instalação</div><div class="val"><?= dateBR($pv['installation_date']) ?></div></div>
      <div class="field"><div class="label">Próx. Manutenção</div><div class="val"><?= dateBR($pv['next_maintenance']) ?></div></div>
      <div class="field"><div class="label">Fim da Garantia</div><div class="val"><?= dateBR($pv['warranty_end']) ?></div></div>
      <div class="field"><div class="label">Último Check-up</div><div class="val"><?= dateBR($pv['last_checkup']) ?></div></div>
      <div class="field"><div class="label">Cadastrado em</div><div class="val"><?= dtBR($pv['created_at']) ?></div></div>
      <div class="field"><div class="label">Atualizado em</div><div class="val"><?= dtBR($pv['updated_at']) ?></div></div>
    </div>

    <?php if (!empty($pv['notes'])): ?>
    <div style="margin-top:10px;">
      <div class="field"><div class="label">Notas do Pós-venda</div></div>
      <div class="obs-block" style="margin-top:4px;"><?= h($pv['notes']) ?></div>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- ══ 3. MOVIMENTAÇÕES DO LEAD ══ -->
<section>
  <h2>3. Movimentações no Funil de Vendas</h2>
  <div class="sec-body">
  <?php if (empty($leadMovements)): ?>
    <p style="color:#94a3b8; font-size:11px;">Nenhuma movimentação registrada.</p>
  <?php else: ?>
    <ul class="timeline">
    <?php foreach ($leadMovements as $mv): ?>
      <li>
        <div class="tl-dot <?= $mv['is_alert'] ? 'alert' : '' ?>"></div>
        <div class="tl-date"><?= dtBR($mv['created_at']) ?></div>
        <div class="tl-content">
          <?php
            $from = h($mv['from_status']);
            $to   = h($mv['to_status']);
            if ($from || $to):
          ?>
          <div class="from-to"><?= $from ?> → <?= $to ?></div>
          <?php endif; ?>
          <?php if (!empty($mv['note'])): ?>
          <div class="note"><?= h($mv['note']) ?></div>
          <?php endif; ?>
          <div class="tl-user">por <?= h($mv['changed_by'] ?: '—') ?></div>
        </div>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  </div>
</section>

<!-- ══ 4. PROJETO ══ -->
<section>
  <h2>4. Projeto</h2>
  <div class="sec-body">
    <div class="field-grid">
      <div class="field"><div class="label">ID Projeto</div><div class="val">#<?= h($projId) ?></div></div>
      <div class="field"><div class="label">Status</div><div class="val"><?= h($pv['proj_status']) ?: '—' ?></div></div>
      <div class="field"><div class="label">Valor Proposta</div><div class="val"><?= money($pv['proposal_value']) ?></div></div>
      <div class="field"><div class="label">Endereço</div><div class="val"><?= h($pv['address']) ?: '—' ?></div></div>
      <div class="field"><div class="label">Forma de Pagamento</div><div class="val"><?= h($pv['payment_type']) ?: '—' ?></div></div>
      <div class="field"><div class="label">Status Pgto</div><div class="val"><?= h($pv['payment_status']) ?: '—' ?></div></div>
      <div class="field"><div class="label">Contrato</div><div class="val"><?= h($pv['contract']) ?: '—' ?></div></div>
      <div class="field"><div class="label">Data Fechamento</div><div class="val"><?= dateBR($pv['closed_date']) ?></div></div>
      <div class="field"><div class="label">Criado em</div><div class="val"><?= dtBR($pv['proj_created_at']) ?></div></div>
    </div>

    <?php if (!empty($checklists)): ?>
    <div style="margin-top:12px;">
      <div class="field" style="margin-bottom:6px;"><div class="label">Checklist do Projeto</div></div>
      <?php foreach ($checklists as $ci): ?>
      <div class="chk-item">
        <div class="chk-box <?= $ci['completed'] ? 'done' : '' ?>"><?= $ci['completed'] ? '✓' : '' ?></div>
        <div>
          <?php if (!empty($ci['checklist_title'])): ?>
          <span style="color:#64748b; font-size:10px;"><?= h($ci['checklist_title']) ?> · </span>
          <?php endif; ?>
          <?= h($ci['description'] ?? $ci['item'] ?? '') ?>
          <?php if (!empty($ci['completed_at'])): ?>
          <span style="color:#94a3b8; font-size:10px;"> (<?= dtBR($ci['completed_at']) ?>)</span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- ══ 5. LEMBRETES / AGENDAMENTOS ══ -->
<?php if (!empty($reminders)): ?>
<section>
  <h2>5. Lembretes & Agendamentos</h2>
  <div class="sec-body">
    <?php foreach ($reminders as $rem): ?>
    <div class="rem-item">
      <div class="rem-date"><?= dtBR($rem['remind_at'] ?? $rem['due_date'] ?? null) ?></div>
      <div style="flex:1"><?= h($rem['title'] ?? $rem['message'] ?? $rem['notes'] ?? '') ?></div>
      <span class="rem-status <?= !empty($rem['completed']) ? 'done' : 'pend' ?>">
        <?= !empty($rem['completed']) ? 'Concluído' : 'Pendente' ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<div class="footer">WR CRM · Relatório gerado em <?= $now ?> · Documento gerado automaticamente — não requer assinatura.</div>

<script>
// Auto-print quando a página carrega completamente
window.addEventListener('load', function() {
    setTimeout(function() { window.print(); }, 800);
});
</script>
</body>
</html>
