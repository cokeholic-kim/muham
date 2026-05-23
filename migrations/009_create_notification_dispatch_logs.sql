CREATE TABLE IF NOT EXISTS notification_dispatch_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    notification_setting_id BIGINT UNSIGNED NOT NULL,
    trigger_type ENUM('manual') NOT NULL,
    period_from DATE NULL,
    period_to DATE NULL,
    channel ENUM('telegram', 'discord') NOT NULL,
    result ENUM('success', 'failed', 'skipped', 'rate_limited') NOT NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notification_dispatch_user_trigger_created (user_id, trigger_type, created_at),
    KEY idx_notification_dispatch_setting_created (notification_setting_id, created_at),
    KEY idx_notification_dispatch_result_created (result, created_at),
    CONSTRAINT fk_notification_dispatch_user_id
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_notification_dispatch_setting_id
        FOREIGN KEY (notification_setting_id) REFERENCES notification_settings (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
