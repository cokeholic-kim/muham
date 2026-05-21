CREATE TABLE IF NOT EXISTS webhook_request_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(120) NULL,
    path VARCHAR(255) NOT NULL,
    method VARCHAR(16) NOT NULL,
    source_ip VARCHAR(45) NOT NULL,
    headers_json JSON NULL,
    body_sha256 CHAR(64) NOT NULL,
    raw_body MEDIUMTEXT NULL,
    payload_json JSON NULL,
    parse_status ENUM('parsed', 'empty', 'invalid_json') NOT NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_webhook_request_logs_request_id (request_id),
    KEY idx_webhook_request_logs_source_ip (source_ip),
    KEY idx_webhook_request_logs_parse_status (parse_status, created_at),
    KEY idx_webhook_request_logs_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
