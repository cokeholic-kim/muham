ALTER TABLE users
    ADD COLUMN ai_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER telegram_chat_id,
    ADD COLUMN ai_daily_limit SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER ai_enabled,
    ADD KEY idx_users_ai_enabled (ai_enabled);
