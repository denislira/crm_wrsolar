<?php
// Migration: Alter leads.forma_pagamento to INT and add FK to payment_methods(id)
// Run this after creating payment_methods table and adding the column.
// Token-protected for security.

$expectedToken = trim(file_get_contents(__DIR__ . '/migrations.secret'));
if (empty($expectedToken)) {
    http_response_code(500);
    echo json_encode(['error' => 'Migration secret not configured']);
    exit;
}

$providedToken = $_GET['token'] ?? '';
if ($providedToken !== $expectedToken) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';

try {
    // Check current type
    $stmt = $pdo->query("DESCRIBE leads forma_pagamento");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentType = strtolower($row['Type'] ?? '');
    if (strpos($currentType, 'int') === false) {
        $pdo->exec("ALTER TABLE leads MODIFY COLUMN forma_pagamento INT NULL");
    }
    // Drop FK if exists
    try {
        $pdo->exec("ALTER TABLE leads DROP FOREIGN KEY fk_leads_forma_pagamento");
    } catch (Exception $e) { /* ignore if not exists */ }
    // Add FK
    $pdo->exec("ALTER TABLE leads ADD CONSTRAINT fk_leads_forma_pagamento FOREIGN KEY (forma_pagamento) REFERENCES payment_methods(id) ON DELETE SET NULL");
    echo json_encode(['success' => true, 'message' => 'Column altered to INT with FK']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>