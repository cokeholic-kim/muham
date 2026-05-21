CREATE TABLE IF NOT EXISTS user_ai_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    provider ENUM('gemini', 'openai', 'anthropic') NOT NULL DEFAULT 'gemini',
    model VARCHAR(120) NOT NULL,
    api_key_ciphertext TEXT NOT NULL,
    api_key_hint VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_ai_settings_user_id (user_id),
    CONSTRAINT fk_user_ai_settings_user_id
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
