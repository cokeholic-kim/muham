CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NULL,
    target_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    request_ip VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    request_id VARCHAR(120) NULL,
    prev_hash CHAR(64) NULL,
    hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_logs_actor
        FOREIGN KEY (actor_user_id) REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT fk_audit_logs_target
        FOREIGN KEY (target_user_id) REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    KEY idx_audit_logs_actor_created (actor_user_id, created_at),
    KEY idx_audit_logs_target_created (target_user_id, created_at),
    KEY idx_audit_logs_entity (entity_type, entity_id),
    KEY idx_audit_logs_request_id (request_id),
    KEY idx_audit_logs_hash (hash),
    KEY idx_audit_logs_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
