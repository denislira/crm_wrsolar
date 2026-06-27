<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nao autorizado']);
    exit;
}

require_once dirname(__DIR__) . '/includes/config.php';

$userId = (int) $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'summary';
$chatCutoff = date('Y-m-d H:i:s', strtotime('-48 hours'));

function chatEnsureTables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS internal_chat_conversations (
          id INT AUTO_INCREMENT PRIMARY KEY,
          type ENUM('global', 'direct') NOT NULL DEFAULT 'direct',
          created_by INT NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS internal_chat_participants (
          conversation_id INT NOT NULL,
          user_id INT NOT NULL,
          last_read_message_id INT DEFAULT NULL,
          joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (conversation_id, user_id),
          INDEX idx_internal_chat_participants_user (user_id),
          FOREIGN KEY (conversation_id) REFERENCES internal_chat_conversations(id) ON DELETE CASCADE,
          FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS internal_chat_reads (
          conversation_id INT NOT NULL,
          user_id INT NOT NULL,
          last_read_message_id INT DEFAULT NULL,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (conversation_id, user_id),
          INDEX idx_internal_chat_reads_user (user_id),
          FOREIGN KEY (conversation_id) REFERENCES internal_chat_conversations(id) ON DELETE CASCADE,
          FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS internal_chat_messages (
          id INT AUTO_INCREMENT PRIMARY KEY,
          conversation_id INT NOT NULL,
          sender_id INT NOT NULL,
          body TEXT NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_internal_chat_messages_conversation (conversation_id, id),
          FOREIGN KEY (conversation_id) REFERENCES internal_chat_conversations(id) ON DELETE CASCADE,
          FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    try {
        $stmt = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'internal_chat_conversations' AND COLUMN_NAME = 'type' LIMIT 1");
        $columnType = strtolower((string) $stmt->fetchColumn());
        if ($columnType && strpos($columnType, 'global') === false) {
            $pdo->exec("ALTER TABLE internal_chat_conversations MODIFY COLUMN type ENUM('global', 'direct') NOT NULL DEFAULT 'direct'");
        }
    } catch (Throwable $e) {}

    $stmt = $pdo->prepare("SELECT id FROM internal_chat_conversations WHERE type = 'global' ORDER BY id ASC LIMIT 1");
    $stmt->execute();
    $globalId = (int) $stmt->fetchColumn();
    if ($globalId <= 0) {
        $creatorId = 1;
        try {
            $checkCreator = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
            $fallbackCreator = (int) $checkCreator->fetchColumn();
            if ($fallbackCreator > 0) $creatorId = $fallbackCreator;
        } catch (Throwable $e) {}

        $pdo->prepare("INSERT INTO internal_chat_conversations (type, created_by) VALUES ('global', ?)")->execute([$creatorId]);
        $globalId = (int) $pdo->lastInsertId();
        try {
            $pdo->prepare('INSERT INTO internal_chat_reads (conversation_id, user_id, last_read_message_id) SELECT ?, id, 0 FROM users')->execute([$globalId]);
        } catch (Throwable $e) {}
    }
}

function chatJson($payload): void {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function chatUserColumns(PDO $pdo): array {
    static $columns = null;
    if ($columns !== null) return $columns;

    $columns = ['nome_completo' => false, 'avatar' => false, 'email' => false];
    try {
        $stmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
        $available = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($columns as $column => $_) {
            $columns[$column] = in_array($column, $available, true);
        }
    } catch (Throwable $e) {
        $columns['email'] = true;
    }
    return $columns;
}

function chatUserExpr(array $columns, string $alias, string $column, string $fallback = "''"): string {
    return !empty($columns[$column]) ? "COALESCE({$alias}.{$column}, '')" : $fallback;
}

function chatUserSelectSql(array $columns, string $alias = 'u', string $prefix = ''): string {
    $name = chatUserExpr($columns, $alias, 'nome_completo');
    $avatar = chatUserExpr($columns, $alias, 'avatar');
    $email = chatUserExpr($columns, $alias, 'email');
    return "{$alias}.id AS {$prefix}id, {$alias}.username AS {$prefix}username, {$name} AS {$prefix}nome_completo, {$avatar} AS {$prefix}avatar, {$email} AS {$prefix}email";
}

function chatStringLength(string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function chatConversationAccess(PDO $pdo, int $conversationId, int $userId): bool {
    $stmt = $pdo->prepare("SELECT type FROM internal_chat_conversations WHERE id = ? LIMIT 1");
    $stmt->execute([$conversationId]);
    if (($stmt->fetchColumn() ?: '') === 'global') return true;
    $stmt = $pdo->prepare('SELECT 1 FROM internal_chat_participants WHERE conversation_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$conversationId, $userId]);
    return (bool) $stmt->fetchColumn();
}

function chatDirectConversation(PDO $pdo, int $userId, int $otherUserId): int {
    if ($otherUserId <= 0 || $otherUserId === $userId) {
        throw new InvalidArgumentException('Usuario invalido');
    }

    $checkUser = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
    $checkUser->execute([$otherUserId]);
    if (!$checkUser->fetchColumn()) {
        throw new InvalidArgumentException('Usuario nao encontrado');
    }

    $stmt = $pdo->prepare("
        SELECT c.id
        FROM internal_chat_conversations c
        JOIN internal_chat_participants p1 ON p1.conversation_id = c.id AND p1.user_id = ?
        JOIN internal_chat_participants p2 ON p2.conversation_id = c.id AND p2.user_id = ?
        WHERE c.type = 'direct'
        LIMIT 1
    ");
    $stmt->execute([$userId, $otherUserId]);
    $existing = $stmt->fetchColumn();
    if ($existing) return (int) $existing;

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare("INSERT INTO internal_chat_conversations (type, created_by) VALUES ('direct', ?)");
        $insert->execute([$userId]);
        $conversationId = (int) $pdo->lastInsertId();

        $part = $pdo->prepare('INSERT INTO internal_chat_participants (conversation_id, user_id) VALUES (?, ?)');
        $part->execute([$conversationId, $userId]);
        $part->execute([$conversationId, $otherUserId]);
        $read = $pdo->prepare('INSERT INTO internal_chat_reads (conversation_id, user_id, last_read_message_id) VALUES (?, ?, NULL)');
        $read->execute([$conversationId, $userId]);
        $read->execute([$conversationId, $otherUserId]);

        $pdo->commit();
        return $conversationId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

try {
    chatEnsureTables($pdo);
    $userColumns = chatUserColumns($pdo);

    if ($action === 'users') {
        $q = trim((string) ($_GET['q'] ?? ''));
        $params = [$userId];
        $where = 'u.id <> ?';
        if ($q !== '') {
            $searchParts = ['u.username LIKE ?'];
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $params[] = $like;
            if (!empty($userColumns['email'])) {
                $searchParts[] = 'u.email LIKE ?';
                $params[] = $like;
            }
            if (!empty($userColumns['nome_completo'])) {
                $searchParts[] = 'u.nome_completo LIKE ?';
                $params[] = $like;
            }
            $where .= ' AND (' . implode(' OR ', $searchParts) . ')';
        }
        $orderName = !empty($userColumns['nome_completo']) ? "COALESCE(NULLIF(u.nome_completo, ''), u.username)" : 'u.username';
        $stmt = $pdo->prepare('SELECT ' . chatUserSelectSql($userColumns, 'u') . " FROM users u WHERE {$where} ORDER BY {$orderName} ASC LIMIT 30");
        $stmt->execute($params);
        chatJson(['success' => true, 'users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'start') {
        $otherUserId = (int) ($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
        $conversationId = chatDirectConversation($pdo, $userId, $otherUserId);
        chatJson(['success' => true, 'conversation_id' => $conversationId]);
    }

    if ($action === 'summary') {
        $globalStmt = $pdo->prepare("SELECT id, type, updated_at FROM internal_chat_conversations WHERE type = 'global' ORDER BY id ASC LIMIT 1");
        $globalStmt->execute();
        $globalConversation = $globalStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $conversations = [];
        $privateStmt = $pdo->prepare("
            SELECT
                c.id,
                c.type,
                c.updated_at,
                op.user_id AS other_user_id,
                ou.username AS other_username,
                " . chatUserExpr($userColumns, 'ou', 'nome_completo') . " AS other_nome_completo,
                " . chatUserExpr($userColumns, 'ou', 'avatar') . " AS other_avatar,
                m.id AS last_message_id,
                m.body AS last_message,
                m.created_at AS last_message_at,
                m.sender_id AS last_sender_id,
                COALESCE(unread.total, 0) AS unread_count
            FROM internal_chat_conversations c
            JOIN internal_chat_participants me ON me.conversation_id = c.id AND me.user_id = ?
            JOIN internal_chat_participants op ON op.conversation_id = c.id AND op.user_id <> ?
            JOIN users ou ON ou.id = op.user_id
            LEFT JOIN internal_chat_messages m ON m.id = (
                SELECT id FROM internal_chat_messages
                WHERE conversation_id = c.id AND created_at >= ?
                ORDER BY id DESC
                LIMIT 1
            )
            LEFT JOIN (
                SELECT r.conversation_id, COUNT(msg.id) AS total
                FROM internal_chat_reads r
                JOIN internal_chat_messages msg ON msg.conversation_id = r.conversation_id
                WHERE r.user_id = ? AND msg.sender_id <> ? AND msg.id > COALESCE(r.last_read_message_id, 0) AND msg.created_at >= ?
                GROUP BY r.conversation_id
            ) unread ON unread.conversation_id = c.id
            WHERE c.type = 'direct'
            ORDER BY COALESCE(m.created_at, c.updated_at) DESC
            LIMIT 29
        ");
        $privateStmt->execute([$userId, $userId, $chatCutoff, $userId, $userId, $chatCutoff]);
        $conversations = $privateStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$globalConversation) {
            $globalConversation = ['id' => 0, 'type' => 'global', 'updated_at' => date('Y-m-d H:i:s')];
        } else {
            $globalConversation['type'] = 'global';
            $globalConversation['other_user_id'] = 0;
            $globalConversation['other_username'] = 'Sala geral';
            $globalConversation['other_nome_completo'] = 'Sala geral';
            $globalConversation['other_avatar'] = '';
            $globalConversation['last_message_id'] = null;
            $globalConversation['last_message'] = null;
            $globalConversation['last_message_at'] = null;
            $globalConversation['last_sender_id'] = null;
            $globalConversation['unread_count'] = 0;
        }
        array_unshift($conversations, $globalConversation);
        $totalUnread = array_sum(array_map(fn($row) => (int) $row['unread_count'], $conversations));
        chatJson(['success' => true, 'conversations' => $conversations, 'unread_total' => $totalUnread]);
    }

    if ($action === 'messages') {
        $conversationId = (int) ($_GET['conversation_id'] ?? 0);
        if (!chatConversationAccess($pdo, $conversationId, $userId)) {
            http_response_code(403);
            chatJson(['success' => false, 'message' => 'Acesso negado']);
        }
        $afterId = max(0, (int) ($_GET['after_id'] ?? 0));
        $sql = "
            SELECT m.id, m.conversation_id, m.sender_id, m.body, m.created_at, u.username, " . chatUserExpr($userColumns, 'u', 'avatar') . " AS avatar
            FROM internal_chat_messages m
            JOIN users u ON u.id = m.sender_id
            WHERE m.conversation_id = ? AND m.created_at >= ?
        ";
        $params = [$conversationId, $chatCutoff];
        if ($afterId > 0) {
            $sql .= ' AND m.id > ?';
            $params[] = $afterId;
        }
        $sql .= ' ORDER BY m.id ASC LIMIT 120';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $lastId = 0;
        foreach ($messages as $message) {
            $lastId = max($lastId, (int) $message['id']);
        }
        if ($lastId > 0) {
            $mark = $pdo->prepare('INSERT INTO internal_chat_reads (conversation_id, user_id, last_read_message_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), VALUES(last_read_message_id))');
            $mark->execute([$conversationId, $userId, $lastId]);
        }

        chatJson(['success' => true, 'messages' => $messages]);
    }

    if ($action === 'send') {
        $conversationId = (int) ($_POST['conversation_id'] ?? 0);
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '') {
            http_response_code(422);
            chatJson(['success' => false, 'message' => 'Mensagem vazia']);
        }
        if (chatStringLength($body) > 2000) {
            http_response_code(422);
            chatJson(['success' => false, 'message' => 'Mensagem muito longa']);
        }
        if (!chatConversationAccess($pdo, $conversationId, $userId)) {
            http_response_code(403);
            chatJson(['success' => false, 'message' => 'Acesso negado']);
        }
        $stmt = $pdo->prepare('INSERT INTO internal_chat_messages (conversation_id, sender_id, body) VALUES (?, ?, ?)');
        $stmt->execute([$conversationId, $userId, $body]);
        $messageId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE internal_chat_conversations SET updated_at = NOW() WHERE id = ?')->execute([$conversationId]);
        $pdo->prepare('INSERT INTO internal_chat_reads (conversation_id, user_id, last_read_message_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), VALUES(last_read_message_id))')->execute([$conversationId, $userId, $messageId]);
        chatJson(['success' => true, 'message_id' => $messageId]);
    }

    http_response_code(400);
    chatJson(['success' => false, 'message' => 'Acao invalida']);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    chatJson(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    chatJson(['success' => false, 'message' => 'Erro interno']);
}
