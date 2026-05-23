CREATE TABLE IF NOT EXISTS ai_usage_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    provider ENUM('gemini', 'openai', 'anthropic') NOT NULL,
    model VARCHAR(120) NOT NULL,
    feature VARCHAR(80) NOT NULL,
    input_sha256 CHAR(64) NOT NULL,
    result ENUM('pending', 'success', 'failed', 'rate_limited', 'disabled') NOT NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ai_usage_user_created (user_id, created_at),
    KEY idx_ai_usage_user_result_created (user_id, result, created_at),
    KEY idx_ai_usage_feature_created (feature, created_at),
    CONSTRAINT fk_ai_usage_logs_user_id
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
