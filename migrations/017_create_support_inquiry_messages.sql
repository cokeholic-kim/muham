CREATE TABLE IF NOT EXISTS support_inquiry_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inquiry_id BIGINT UNSIGNED NOT NULL,
    sender_user_id BIGINT UNSIGNED NOT NULL,
    sender_role ENUM('user', 'admin') NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_support_inquiry_messages_inquiry_created (inquiry_id, created_at, id),
    KEY idx_support_inquiry_messages_sender_created (sender_user_id, created_at),
    CONSTRAINT fk_support_inquiry_messages_inquiry
        FOREIGN KEY (inquiry_id) REFERENCES support_inquiries (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_support_inquiry_messages_sender
        FOREIGN KEY (sender_user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO support_inquiry_messages (
    inquiry_id,
    sender_user_id,
    sender_role,
    message,
    created_at
)
SELECT
    id,
    answered_by,
    'admin',
    admin_reply,
    COALESCE(answered_at, updated_at, created_at)
FROM support_inquiries
WHERE admin_reply IS NOT NULL
  AND TRIM(admin_reply) <> ''
  AND answered_by IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM support_inquiry_messages
      WHERE support_inquiry_messages.inquiry_id = support_inquiries.id
        AND support_inquiry_messages.sender_role = 'admin'
        AND support_inquiry_messages.message = support_inquiries.admin_reply
  );
