ALTER TABLE notification_dispatch_logs
    MODIFY result ENUM('pending', 'success', 'failed', 'skipped', 'rate_limited') NOT NULL;
