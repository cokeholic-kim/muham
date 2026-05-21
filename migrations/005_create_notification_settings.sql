CREATE TABLE IF NOT EXISTS notification_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    channel ENUM('telegram', 'discord') NOT NULL,
    telegram_bot_token VARCHAR(255) NULL,
    telegram_chat_id VARCHAR(120) NULL,
    discord_webhook_url TEXT NULL,
    summary_period_type ENUM('previous_month', 'current_month', 'previous_7_days', 'custom') NOT NULL DEFAULT 'previous_month',
    custom_period_from DATE NULL,
    custom_period_to DATE NULL,
    monthly_send_day TINYINT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notification_settings_user_id (user_id),
    KEY idx_notification_settings_due (is_active, monthly_send_day),
    CONSTRAINT fk_notification_settings_user_id
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
