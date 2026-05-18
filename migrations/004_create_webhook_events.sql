CREATE TABLE IF NOT EXISTS webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(120) NOT NULL,
    source_ip VARCHAR(45) NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 0,
    period_from DATE NULL,
    period_to DATE NULL,
    payload_json JSON NULL,
    result ENUM('success', 'rejected', 'failed') NOT NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_webhook_events_request_id (request_id),
    KEY idx_webhook_events_source_ip (source_ip),
    KEY idx_webhook_events_result_created (result, created_at),
    KEY idx_webhook_events_period (period_from, period_to),
    KEY idx_webhook_events_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
