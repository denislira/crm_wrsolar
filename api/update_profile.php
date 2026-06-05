<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Não autorizado']);
    exit;
}
include '../includes/config.php';

$user_id = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Método não permitido']);
    exit;
}

$nome_completo = $_POST['nome_completo'] ?? null;
$biografia = $_POST['biografia'] ?? null;
$email = $_POST['email'] ?? null;

try {
    $fields = [];
    $params = [];
    if (!is_null($nome_completo)) { $fields[] = 'nome_completo = ?'; $params[] = $nome_completo; }
    if (!is_null($biografia)) { $fields[] = 'biografia = ?'; $params[] = $biografia; }
    if (!is_null($email)) { $fields[] = 'email = ?'; $params[] = $email; }

    if (!empty($fields)) {
        $params[] = $user_id;
        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Refresh session values so frontend header/sidebar reflects changes
        try {
            $r = $pdo->prepare('SELECT username, email, nome_completo FROM users WHERE id = ? LIMIT 1');
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
        $imgInfo = @getimagesize($file['tmp_name']);
        $ext = null;
        $map = ['image/png'=>'.png','image/jpeg'=>'.jpg','image/gif'=>'.gif','image/webp'=>'.webp'];
        if ($imgInfo && !empty($imgInfo['mime']) && isset($map[$imgInfo['mime']])) $ext = $map[$imgInfo['mime']];
        if ($ext === null) {
            $orig = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['png'=>'.png','jpg'=>'.jpg','jpeg'=>'.jpg','gif'=>'.gif','webp'=>'.webp'];
            if (isset($allowed[$orig])) $ext = $allowed[$orig];
        }
        if ($ext !== null) {
            $targetName = 'uploads/avatar_'.$user_id.$ext;
            $targetPath = __DIR__ . '/../' . $targetName;
            if (!is_dir(dirname($targetPath))) @mkdir(dirname($targetPath), 0755, true);
            if (@move_uploaded_file($file['tmp_name'], $targetPath) || @copy($file['tmp_name'], $targetPath)) {
                @chmod($targetPath, 0644);
                $u = $pdo->prepare('UPDATE users SET avatar = ? WHERE id = ?');
                $u->execute([$targetName, $user_id]);
                echo json_encode(['success'=>true,'message'=>'Perfil atualizado','avatar'=>$targetName]);
                exit;
            }
        }
    }

    echo json_encode(['success'=>true,'message'=>'Perfil atualizado']);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>'Erro: '.$e->getMessage()]);
}

?>
