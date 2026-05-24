CREATE TABLE IF NOT EXISTS support_inquiries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    subject VARCHAR(120) NOT NULL,
    message TEXT NOT NULL,
    image_filename VARCHAR(255) NULL,
    image_mime VARCHAR(80) NULL,
    image_size INT UNSIGNED NULL,
    discord_sent TINYINT(1) NOT NULL DEFAULT 0,
    discord_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_support_inquiries_user_created (user_id, created_at),
    KEY idx_support_inquiries_created (created_at),
    KEY idx_support_inquiries_discord_sent (discord_sent, created_at),
    CONSTRAINT fk_support_inquiries_user_id
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
