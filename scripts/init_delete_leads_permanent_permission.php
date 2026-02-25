<?php
/**
 * Script to initialize delete_leads_permanent permission
 * Run this once to add the permission to all existing roles
 * Access: http://localhost/WRCRM/scripts/init_delete_leads_permanent_permission.php
 */

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 1) {
    die('Acesso negado. Apenas o Diretor pode executar este script.');
}

include __DIR__ . '/../includes/config.php';

try {
    $pdo->beginTransaction();
    
    // Get all existing roles
    $stmt = $pdo->query('SELECT id FROM roles');
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($roles)) {
        throw new Exception('Nenhum papel encontrado no banco de dados.');
    }
    
    $updates = 0;
    $inserts = 0;
    
    $upd = $pdo->prepare('UPDATE role_permissions SET allowed = ? WHERE role_id = ? AND screen = ?');
    $ins = $pdo->prepare('INSERT INTO role_permissions (role_id, screen, allowed) VALUES (?, ?, ?)');
    
    foreach ($roles as $roleId) {
        // Check if permission exists
        $check = $pdo->prepare('SELECT id FROM role_permissions WHERE role_id = ? AND screen = ?');
        $check->execute([$roleId, 'delete_leads_permanent']);
        
        if ($check->fetch()) {
            // Update existing (set to 1 only for role_id=1, otherwise 0)
            $allowed = ($roleId == 1) ? 1 : 0;
            $upd->execute([$allowed, $roleId, 'delete_leads_permanent']);
            $updates++;
        } else {
            // Insert new
            $allowed = ($roleId == 1) ? 1 : 0;
            $ins->execute([$roleId, 'delete_leads_permanent', $allowed]);
            $inserts++;
        }
    }
    
    $pdo->commit();
    
    echo '<div style="padding: 20px; font-family: Arial; max-width: 600px; margin: 50px auto; border: 1px solid #ddd; border-radius: 5px; background: #f9f9f9;">';
    echo '<h2 style="color: #28a745;">✓ Sucesso</h2>';
    echo '<p>Permissão "delete_leads_permanent" foi inicializada.</p>';
    echo '<ul>';
    echo '<li>' . $updates . ' permissão(ões) atualizada(s)</li>';
    echo '<li>' . $inserts . ' permissão(ões) adicionada(s)</li>';
    echo '</ul>';
    echo '<p><strong>Status:</strong></p>';
    echo '<ul>';
    
    // Show status for each role
    $stmt = $pdo->query('SELECT r.id, r.name, COALESCE(rp.allowed, 0) as allowed FROM roles r LEFT JOIN role_permissions rp ON r.id = rp.role_id AND rp.screen = "delete_leads_permanent" ORDER BY r.id');
    $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($statuses as $row) {
        $status = $row['allowed'] ? '✓ Habilitado' : '✗ Desabilitado';
        echo '<li>' . htmlspecialchars($row['name']) . ': ' . $status . '</li>';
    }
    
    echo '</ul>';
    echo '<p style="margin-top: 20px; color: #666; font-size: 0.9em;"><strong>Próximas etapas:</strong></p>';
    echo '<ol>';
    echo '<li>Acesse <strong>Configurações > Permissões</strong></li>';
    echo '<li>Selecione um papel (gerente, supervisor, etc.)</li>';
    echo '<li>Procure por "Excluir leads permanentemente" na lista de permissões</li>';
    echo '<li>Marque a caixa se esse papel deve ter essa permissão</li>';
    echo '<li>Clique em "Salvar alterações"</li>';
    echo '</ol>';
    echo '</div>';
    
} catch (Exception $e) {
    echo '<div style="padding: 20px; font-family: Arial; max-width: 600px; margin: 50px auto; border: 1px solid #dc3545; border-radius: 5px; background: #f8d7da;">';
    echo '<h2 style="color: #dc3545;">✗ Erro</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</div>';
}
?>
