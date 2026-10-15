<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Não autorizado']);
    exit;
}
include '../includes/config.php';
include_once '../includes/avatar_image.php';

$user_id = (int) $_SESSION['user_id'];

function wrcrm_ensure_ai_bot_mode_column(PDO $pdo): void
{
    // Estrutura criada exclusivamente pelas migrations manuais.
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Método não permitido']);
    exit;
}

$nome_completo = $_POST['nome_completo'] ?? null;
$biografia = $_POST['biografia'] ?? null;
$email = $_POST['email'] ?? null;
$aiBotMode = $_POST['ai_bot_mode'] ?? null;

try {
    wrcrm_ensure_ai_bot_mode_column($pdo);
    $latest = null;
    $fields = [];
    $params = [];
    if (!is_null($nome_completo)) { $fields[] = 'nome_completo = ?'; $params[] = $nome_completo; }
    if (!is_null($biografia)) { $fields[] = 'biografia = ?'; $params[] = $biografia; }
    if (!is_null($email)) { $fields[] = 'email = ?'; $params[] = $email; }
    if (!is_null($aiBotMode)) {
        if ($aiBotMode === 'speech') {
            $aiBotMode = 'body';
        }
        $allowed = ['none', 'head', 'body', 'chatbot1', 'chatbot3'];
        $aiBotMode = in_array($aiBotMode, $allowed, true) ? $aiBotMode : 'chatbot3';
        $fields[] = 'ai_bot_mode = ?';
        $params[] = $aiBotMode;
    }

    if (!empty($fields)) {
        $params[] = $user_id;
        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Refresh session values so frontend header/sidebar reflects changes
        try {
            $r = $pdo->prepare('SELECT username, email, nome_completo, biografia, ai_bot_mode FROM users WHERE id = ? LIMIT 1');
            $r->execute([$user_id]);
            $latest = $r->fetch(PDO::FETCH_ASSOC);
            if ($latest) {
                if (!empty($latest['username'])) $_SESSION['username'] = $latest['username'];
                if (!empty($latest['email'])) $_SESSION['email'] = $latest['email'];
                if (!empty($latest['nome_completo'])) $_SESSION['name'] = $latest['nome_completo'];
            }
        } catch (Exception $e) { /* ignore */ }
    }

    // handle avatar via same logic as other endpoints
    if (!empty($_FILES['avatar']) && isset($_FILES['avatar']['tmp_name']) && file_exists($_FILES['avatar']['tmp_name'])) {
        $file = $_FILES['avatar'];
        if (!empty($file['error']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('O upload da foto não foi concluído corretamente.');
        }
        $targetName = wrcrm_save_avatar_image($file['tmp_name'], $user_id);
        $u = $pdo->prepare('UPDATE users SET avatar = ? WHERE id = ?');
        $u->execute([$targetName, $user_id]);
        if (!$latest) {
            $r = $pdo->prepare('SELECT username, email, nome_completo, biografia, ai_bot_mode FROM users WHERE id = ? LIMIT 1');
            $r->execute([$user_id]);
            $latest = $r->fetch(PDO::FETCH_ASSOC);
        }
        echo json_encode([
            'success'=>true,
            'message'=>'Perfil atualizado',
            'avatar'=>$targetName,
            'profile'=>$latest
        ]);
        exit;
    }

    if (!$latest) {
        $r = $pdo->prepare('SELECT username, email, nome_completo, biografia, ai_bot_mode FROM users WHERE id = ? LIMIT 1');
        $r->execute([$user_id]);
        $latest = $r->fetch(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'success'=>true,
        'message'=>'Perfil atualizado',
        'profile'=>$latest
    ]);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>'Erro: '.$e->getMessage()]);
}

?>
