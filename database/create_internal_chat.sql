CREATE TABLE IF NOT EXISTS internal_chat_conversations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('direct') NOT NULL DEFAULT 'direct',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS internal_chat_participants (
  conversation_id INT NOT NULL,
  user_id INT NOT NULL,
  last_read_message_id INT DEFAULT NULL,
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (conversation_id, user_id),
  INDEX idx_internal_chat_participants_user (user_id),
  FOREIGN KEY (conversation_id) REFERENCES internal_chat_conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS internal_chat_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  sender_id INT NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_internal_chat_messages_conversation (conversation_id, id),
  FOREIGN KEY (conversation_id) REFERENCES internal_chat_conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
);
