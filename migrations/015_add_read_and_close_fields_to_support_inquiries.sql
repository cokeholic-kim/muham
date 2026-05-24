ALTER TABLE support_inquiries
    ADD COLUMN user_read_at DATETIME NULL AFTER answered_at,
    ADD COLUMN closed_by BIGINT UNSIGNED NULL AFTER user_read_at,
    ADD COLUMN closed_at DATETIME NULL AFTER closed_by,
    ADD KEY idx_support_inquiries_user_status_read (user_id, status, user_read_at),
    ADD CONSTRAINT fk_support_inquiries_closed_by
        FOREIGN KEY (closed_by) REFERENCES users (id)
        ON DELETE SET NULL;
