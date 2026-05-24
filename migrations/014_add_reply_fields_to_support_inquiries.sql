ALTER TABLE support_inquiries
    ADD COLUMN status ENUM('open','answered','closed') NOT NULL DEFAULT 'open' AFTER discord_error,
    ADD COLUMN admin_reply TEXT NULL AFTER status,
    ADD COLUMN answered_by BIGINT UNSIGNED NULL AFTER admin_reply,
    ADD COLUMN answered_at DATETIME NULL AFTER answered_by,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER answered_at,
    ADD KEY idx_support_inquiries_status_created (status, created_at),
    ADD CONSTRAINT fk_support_inquiries_answered_by
        FOREIGN KEY (answered_by) REFERENCES users (id)
        ON DELETE SET NULL;
