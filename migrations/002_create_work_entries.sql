CREATE TABLE IF NOT EXISTS work_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    break_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    work_minutes INT UNSIGNED NOT NULL,
    memo TEXT NULL,
    status ENUM('active', 'corrected', 'deleted') NOT NULL DEFAULT 'active',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_work_entries_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_work_entries_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT fk_work_entries_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    KEY idx_work_entries_user_date (user_id, work_date),
    KEY idx_work_entries_user_status_date (user_id, status, work_date),
    KEY idx_work_entries_start_end (user_id, start_at, end_at),
    KEY idx_work_entries_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
