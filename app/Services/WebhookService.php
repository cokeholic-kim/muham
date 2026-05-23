<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Database\Database;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class WebhookService
{
    private const MANUAL_DISPATCH_LIMIT = 3;

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly TelegramService $telegramService,
        private readonly DiscordService $discordService,
        private readonly NotificationSettingService $notificationSettingService,
        /** @var array<string, string|null> */
        private readonly array $requestContext
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handleWorkSummary(array $payload, string $sourceIp, ?string $secret): array
    {
        if (!isset($payload['userId']) && !isset($payload['from']) && !isset($payload['to'])) {
            return $this->handleScheduledDispatch($payload, $sourceIp, $secret);
        }

        $requestId = $this->stringValue($payload, 'requestId', 120);
        $periodFrom = $this->dateValue($payload['from'] ?? null, 'from');
        $periodTo = $this->dateValue($payload['to'] ?? null, 'to');
        $userId = $this->positiveInt($payload['userId'] ?? null, 'userId');
        $telegramChatId = isset($payload['telegramChatId']) && is_string($payload['telegramChatId'])
            ? trim($payload['telegramChatId'])
            : null;

        if ($periodFrom > $periodTo) {
            return $this->reject($payload, $requestId, $sourceIp, $periodFrom, $periodTo, 'from은 to보다 늦을 수 없습니다.');
        }

        if (!$this->isAllowedIp($sourceIp)) {
            return $this->reject($payload, $requestId, $sourceIp, $periodFrom, $periodTo, '허용되지 않은 IP입니다.');
        }

        if (!$this->isActivePeriod(date('Y-m-d'))) {
            return $this->reject($payload, $requestId, $sourceIp, $periodFrom, $periodTo, '웹훅 유효 기간이 아닙니다.');
        }

        if (!hash_equals(Env::required('WEBHOOK_SHARED_SECRET'), (string)$secret)) {
            return $this->reject($payload, $requestId, $sourceIp, $periodFrom, $periodTo, '웹훅 secret이 올바르지 않습니다.');
        }

        if ($this->requestExists($requestId)) {
            $this->auditLogService->record(
                null,
                $userId,
                'webhook_rejected',
                'webhook_event',
                null,
                null,
                [
                    'request_id' => $requestId,
                    'reason' => '이미 처리된 requestId입니다.',
                    'source_ip' => $sourceIp,
                ],
                $this->requestContext
            );

            return [
                'result' => 'rejected',
                'message' => '이미 처리된 requestId입니다.',
                'requestId' => $requestId,
            ];
        }

        return Database::transaction(function (PDO $pdo) use ($payload, $requestId, $sourceIp, $periodFrom, $periodTo, $userId, $telegramChatId): array {
            $this->assertRequestIdIsNew($pdo, $requestId);
            $summary = $this->summary($pdo, $userId, $periodFrom, $periodTo);
            $message = $this->message($summary);
            $telegramResult = $this->telegramService->sendMessage($message, $telegramChatId ?: $summary['telegram_chat_id']);
            $result = $telegramResult['sent'] ? 'success' : 'failed';
            $errorMessage = $telegramResult['error'];

            $this->insertWebhookEvent(
                $pdo,
                $requestId,
                $sourceIp,
                true,
                $periodFrom,
                $periodTo,
                $payload,
                $result,
                $errorMessage
            );

            $auditAfter = [
                'request_id' => $requestId,
                'result' => $result,
                'telegram_sent' => $telegramResult['sent'],
                'telegram_skipped' => $telegramResult['skipped'],
                'summary' => $summary,
            ];
            $this->auditLogService->recordInTransaction(
                $pdo,
                null,
                $userId,
                'webhook_summary',
                'webhook_event',
                null,
                null,
                $auditAfter,
                $this->requestContext
            );

            return [
                'result' => $result,
                'message' => $result === 'success' ? '근무 요약을 텔레그램으로 발송했습니다.' : '근무 요약은 생성했지만 텔레그램 발송에 실패했습니다.',
                'requestId' => $requestId,
                'summary' => $summary,
                'telegram' => $telegramResult,
            ];
        });
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function dispatchManualNotification(array $user): array
    {
        $userId = (int)$user['id'];
        $setting = $this->notificationSettingService->findForUser($user);

        if ($setting === null) {
            return [
                'result' => 'skipped',
                'message' => '정기 발송 설정을 먼저 저장해야 합니다.',
            ];
        }

        if ((int)$setting['is_active'] !== 1) {
            return [
                'result' => 'skipped',
                'message' => '정기 발송 설정이 비활성화되어 있습니다.',
            ];
        }

        $prepared = Database::transaction(function (PDO $pdo) use ($userId, $setting): array {
            $this->lockNotificationSetting($pdo, $userId, (int)$setting['id']);

            if ($this->manualDispatchCount($pdo, $userId) >= self::MANUAL_DISPATCH_LIMIT) {
                $this->insertNotificationDispatchLog(
                    $pdo,
                    $userId,
                    (int)$setting['id'],
                    null,
                    null,
                    (string)$setting['channel'],
                    'rate_limited',
                    '1시간 내 수동 발송 횟수를 초과했습니다.'
                );
                $this->auditLogService->recordInTransaction(
                    $pdo,
                    $userId,
                    $userId,
                    'manual_notification_rate_limited',
                    'notification_dispatch',
                    (int)$setting['id'],
                    null,
                    [
                        'limit' => self::MANUAL_DISPATCH_LIMIT,
                        'window_minutes' => 60,
                        'channel' => (string)$setting['channel'],
                    ],
                    $this->requestContext
                );

                return [
                    'result' => 'rate_limited',
                    'message' => '수동 발송은 사용자별 1시간에 3회까지만 가능합니다.',
                ];
            }

            [$periodFrom, $periodTo] = $this->notificationSettingService->resolvePeriod($setting, date('Y-m-d'));
            $summary = $this->summary($pdo, $userId, $periodFrom, $periodTo);
            $message = $this->message($summary);
            $logId = $this->insertNotificationDispatchLog(
                $pdo,
                $userId,
                (int)$setting['id'],
                $periodFrom,
                $periodTo,
                (string)$setting['channel'],
                'pending',
                null
            );

            return [
                'result' => 'pending',
                'logId' => $logId,
                'setting' => $setting,
                'summary' => $summary,
                'messageText' => $message,
                'periodFrom' => $periodFrom,
                'periodTo' => $periodTo,
            ];
        });

        if (($prepared['result'] ?? '') !== 'pending') {
            return $prepared;
        }

        $delivery = $this->sendBySetting($prepared['setting'], (string)$prepared['messageText']);
        $result = $delivery['sent'] ? 'success' : ($delivery['skipped'] ? 'skipped' : 'failed');

        Database::transaction(function (PDO $pdo) use ($userId, $prepared, $delivery, $result): void {
            $this->updateNotificationDispatchLog($pdo, (int)$prepared['logId'], $result, $delivery['error']);
            $this->auditLogService->recordInTransaction(
                $pdo,
                $userId,
                $userId,
                'manual_notification_summary',
                'notification_dispatch',
                (int)$prepared['logId'],
                null,
                [
                    'result' => $result,
                    'channel' => (string)$prepared['setting']['channel'],
                    'period_from' => (string)$prepared['periodFrom'],
                    'period_to' => (string)$prepared['periodTo'],
                    'sent' => $delivery['sent'],
                    'skipped' => $delivery['skipped'],
                    'error' => $delivery['error'],
                    'summary' => $prepared['summary'],
                ],
                $this->requestContext
            );
        });

        return [
            'result' => $result,
            'message' => $this->manualDispatchMessage($result),
            'periodFrom' => (string)$prepared['periodFrom'],
            'periodTo' => (string)$prepared['periodTo'],
            'delivery' => $delivery,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function handleScheduledDispatch(array $payload, string $sourceIp, ?string $secret): array
    {
        $triggerDate = isset($payload['triggerDate'])
            ? $this->dateValue($payload['triggerDate'], 'triggerDate')
            : date('Y-m-d');
        $requestId = $this->optionalStringValue($payload, 'requestId', 120)
            ?? 'scheduled-work-summary-' . $triggerDate;

        if (!$this->isAllowedIp($sourceIp)) {
            return $this->reject($payload, $requestId, $sourceIp, null, null, '허용되지 않은 IP입니다.');
        }

        if (!$this->isActivePeriod(date('Y-m-d'))) {
            return $this->reject($payload, $requestId, $sourceIp, null, null, '웹훅 유효 기간이 아닙니다.');
        }

        if (!hash_equals(Env::required('WEBHOOK_SHARED_SECRET'), (string)$secret)) {
            return $this->reject($payload, $requestId, $sourceIp, null, null, '웹훅 secret이 올바르지 않습니다.');
        }

        if ($this->requestExists($requestId)) {
            $this->auditLogService->record(
                null,
                null,
                'webhook_rejected',
                'webhook_event',
                null,
                null,
                [
                    'request_id' => $requestId,
                    'reason' => '이미 처리된 requestId입니다.',
                    'source_ip' => $sourceIp,
                    'trigger_date' => $triggerDate,
                ],
                $this->requestContext
            );

            return [
                'result' => 'rejected',
                'message' => '이미 처리된 requestId입니다.',
                'requestId' => $requestId,
            ];
        }

        return Database::transaction(function (PDO $pdo) use ($payload, $requestId, $sourceIp, $triggerDate): array {
            $this->assertRequestIdIsNew($pdo, $requestId);
            $settings = $this->notificationSettingService->dueForDate($triggerDate);
            $deliveries = [];
            $sentCount = 0;
            $failedCount = 0;
            $skippedCount = 0;

            foreach ($settings as $setting) {
                [$periodFrom, $periodTo] = $this->notificationSettingService->resolvePeriod($setting, $triggerDate);
                $summary = $this->summary($pdo, (int)$setting['user_id'], $periodFrom, $periodTo);
                $message = $this->message($summary);
                $delivery = $this->sendBySetting($setting, $message);

                if ($delivery['sent']) {
                    $sentCount++;
                } elseif ($delivery['skipped']) {
                    $skippedCount++;
                } else {
                    $failedCount++;
                }

                $deliveries[] = [
                    'setting_id' => (int)$setting['id'],
                    'user_id' => (int)$setting['user_id'],
                    'channel' => (string)$setting['channel'],
                    'summary_period_type' => (string)$setting['summary_period_type'],
                    'period_from' => $periodFrom,
                    'period_to' => $periodTo,
                    'sent' => $delivery['sent'],
                    'skipped' => $delivery['skipped'],
                    'error' => $delivery['error'],
                    'summary' => $summary,
                ];
            }

            $result = $failedCount > 0 ? 'failed' : 'success';
            $errorMessage = $failedCount > 0
                ? sprintf('%d개 정기 발송에 실패했습니다.', $failedCount)
                : null;

            $this->insertWebhookEvent(
                $pdo,
                $requestId,
                $sourceIp,
                true,
                null,
                null,
                $payload + ['triggerDate' => $triggerDate],
                $result,
                $errorMessage
            );

            $auditAfter = [
                'request_id' => $requestId,
                'trigger_date' => $triggerDate,
                'result' => $result,
                'due_count' => count($settings),
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'skipped_count' => $skippedCount,
                'deliveries' => $deliveries,
            ];
            $this->auditLogService->recordInTransaction(
                $pdo,
                null,
                null,
                'webhook_scheduled_summary',
                'webhook_event',
                null,
                null,
                $auditAfter,
                $this->requestContext
            );

            return [
                'result' => $result,
                'message' => sprintf('정기 발송 대상 %d건을 처리했습니다.', count($settings)),
                'requestId' => $requestId,
                'triggerDate' => $triggerDate,
                'dueCount' => count($settings),
                'sentCount' => $sentCount,
                'failedCount' => $failedCount,
                'skippedCount' => $skippedCount,
                'deliveries' => $deliveries,
            ];
        });
    }

    /**
     * @param array<string, mixed> $setting
     * @return array{sent: bool, skipped: bool, error: string|null}
     */
    private function sendBySetting(array $setting, string $message): array
    {
        if ($setting['channel'] === 'telegram') {
            return $this->telegramService->sendMessage(
                $message,
                isset($setting['telegram_chat_id']) ? (string)$setting['telegram_chat_id'] : null,
                isset($setting['telegram_bot_token']) ? (string)$setting['telegram_bot_token'] : null
            );
        }

        if ($setting['channel'] === 'discord') {
            return $this->discordService->sendMessage((string)$setting['discord_webhook_url'], $message);
        }

        return [
            'sent' => false,
            'skipped' => true,
            'error' => 'Unsupported notification channel.',
        ];
    }

    private function lockNotificationSetting(PDO $pdo, int $userId, int $settingId): void
    {
        $statement = $pdo->prepare(
            'SELECT id
            FROM notification_settings
            WHERE id = :id
              AND user_id = :user_id
            LIMIT 1
            FOR UPDATE'
        );
        $statement->execute([
            'id' => $settingId,
            'user_id' => $userId,
        ]);

        if ($statement->fetch(PDO::FETCH_ASSOC) === false) {
            throw new RuntimeException('정기 발송 설정을 찾을 수 없습니다.');
        }
    }

    private function manualDispatchCount(PDO $pdo, int $userId): int
    {
        $statement = $pdo->prepare(
            'SELECT id
            FROM notification_dispatch_logs
            WHERE user_id = :user_id
              AND trigger_type = :trigger_type
              AND result IN (:pending, :success, :failed, :skipped)
              AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            FOR UPDATE'
        );
        $statement->execute([
            'user_id' => $userId,
            'trigger_type' => 'manual',
            'pending' => 'pending',
            'success' => 'success',
            'failed' => 'failed',
            'skipped' => 'skipped',
        ]);

        return count($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function insertNotificationDispatchLog(
        PDO $pdo,
        int $userId,
        int $settingId,
        ?string $periodFrom,
        ?string $periodTo,
        string $channel,
        string $result,
        ?string $errorMessage
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO notification_dispatch_logs (
                user_id,
                notification_setting_id,
                trigger_type,
                period_from,
                period_to,
                channel,
                result,
                error_message
            ) VALUES (
                :user_id,
                :notification_setting_id,
                :trigger_type,
                :period_from,
                :period_to,
                :channel,
                :result,
                :error_message
            )'
        );
        $statement->execute([
            'user_id' => $userId,
            'notification_setting_id' => $settingId,
            'trigger_type' => 'manual',
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'channel' => $channel,
            'result' => $result,
            'error_message' => $errorMessage,
        ]);

        return (int)$pdo->lastInsertId();
    }

    private function updateNotificationDispatchLog(PDO $pdo, int $id, string $result, ?string $errorMessage): void
    {
        $statement = $pdo->prepare(
            'UPDATE notification_dispatch_logs
            SET result = :result,
                error_message = :error_message
            WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'result' => $result,
            'error_message' => $errorMessage,
        ]);
    }

    private function manualDispatchMessage(string $result): string
    {
        if ($result === 'success') {
            return '근무 요약을 발송했습니다.';
        }

        if ($result === 'skipped') {
            return '발송 설정이 부족해 메시지를 보내지 않았습니다.';
        }

        return '근무 요약은 생성했지만 메시지 발송에 실패했습니다.';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function reject(
        array $payload,
        string $requestId,
        string $sourceIp,
        ?string $periodFrom,
        ?string $periodTo,
        string $reason
    ): array {
        try {
            Database::transaction(function (PDO $pdo) use ($payload, $requestId, $sourceIp, $periodFrom, $periodTo, $reason): void {
                $this->insertWebhookEvent($pdo, $requestId, $sourceIp, false, $periodFrom, $periodTo, $payload, 'rejected', $reason);
                $this->auditLogService->recordInTransaction(
                    $pdo,
                    null,
                    null,
                    'webhook_rejected',
                    'webhook_event',
                    null,
                    null,
                    [
                        'request_id' => $requestId,
                        'reason' => $reason,
                        'source_ip' => $sourceIp,
                    ],
                    $this->requestContext
                );
            });
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'Duplicate entry')) {
                throw $e;
            }
        }

        return [
            'result' => 'rejected',
            'message' => $reason,
            'requestId' => $requestId,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insertWebhookEvent(
        PDO $pdo,
        string $requestId,
        string $sourceIp,
        bool $allowed,
        ?string $periodFrom,
        ?string $periodTo,
        array $payload,
        string $result,
        ?string $errorMessage
    ): void {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payloadJson === false) {
            throw new RuntimeException('웹훅 payload JSON 인코딩에 실패했습니다.');
        }

        $statement = $pdo->prepare(
            'INSERT INTO webhook_events (
                request_id,
                source_ip,
                allowed,
                period_from,
                period_to,
                payload_json,
                result,
                error_message
            ) VALUES (
                :request_id,
                :source_ip,
                :allowed,
                :period_from,
                :period_to,
                :payload_json,
                :result,
                :error_message
            )'
        );

        $statement->execute([
            'request_id' => $requestId,
            'source_ip' => $sourceIp,
            'allowed' => $allowed ? 1 : 0,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'payload_json' => $payloadJson,
            'result' => $result,
            'error_message' => $errorMessage,
        ]);
    }

    private function assertRequestIdIsNew(PDO $pdo, string $requestId): void
    {
        $statement = $pdo->prepare('SELECT id FROM webhook_events WHERE request_id = :request_id LIMIT 1 FOR UPDATE');
        $statement->execute(['request_id' => $requestId]);

        if ($statement->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new RuntimeException('이미 처리된 requestId입니다.');
        }
    }

    private function requestExists(string $requestId): bool
    {
        return Database::fetchOne(
            'SELECT id FROM webhook_events WHERE request_id = :request_id LIMIT 1',
            ['request_id' => $requestId]
        ) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(PDO $pdo, int $userId, string $from, string $to): array
    {
        $statement = $pdo->prepare(
            'SELECT
                u.id,
                u.email,
                u.name,
                u.telegram_chat_id,
                COUNT(w.id) AS total_entries,
                COUNT(DISTINCT w.work_date) AS total_work_days,
                COALESCE(SUM(TIMESTAMPDIFF(MINUTE, w.start_at, w.end_at)), 0) AS gross_minutes,
                COALESCE(SUM(w.break_minutes), 0) AS break_minutes,
                COALESCE(SUM(w.work_minutes), 0) AS work_minutes
            FROM users u
            LEFT JOIN work_entries w
              ON w.user_id = u.id
             AND w.status = :status
             AND w.deleted_at IS NULL
             AND w.work_date BETWEEN :from_date AND :to_date
            WHERE u.id = :user_id
            GROUP BY u.id, u.email, u.name, u.telegram_chat_id'
        );
        $statement->execute([
            'status' => 'active',
            'from_date' => $from,
            'to_date' => $to,
            'user_id' => $userId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new RuntimeException('대상 사용자를 찾을 수 없습니다.');
        }

        return [
            'user_id' => (int)$row['id'],
            'email' => (string)$row['email'],
            'name' => (string)$row['name'],
            'telegram_chat_id' => $row['telegram_chat_id'] !== null ? (string)$row['telegram_chat_id'] : null,
            'from' => $from,
            'to' => $to,
            'total_entries' => (int)$row['total_entries'],
            'total_work_days' => (int)$row['total_work_days'],
            'gross_minutes' => (int)$row['gross_minutes'],
            'break_minutes' => (int)$row['break_minutes'],
            'work_minutes' => (int)$row['work_minutes'],
            'daily_entries' => $this->dailyEntries($pdo, $userId, $from, $to),
        ];
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function message(array $summary): string
    {
        $message = sprintf(
            "[근무시간 요약]\n대상 사용자: %s <%s>\n대상 기간: %s ~ %s\n총 근무일: %d일\n총 기록: %d건\n총 근무시간: %s\n총 휴게시간: %s\n실제 근무시간: %s\n마지막 정리 시각: %s",
            $summary['name'],
            $summary['email'],
            $summary['from'],
            $summary['to'],
            $summary['total_work_days'],
            $summary['total_entries'],
            $this->formatMinutes((int)$summary['gross_minutes']),
            $this->formatMinutes((int)$summary['break_minutes']),
            $this->formatMinutes((int)$summary['work_minutes']),
            date('Y-m-d H:i:s')
        );

        return $message . "\n\n[근거 기록]\n" . $this->detailLines($summary['daily_entries'] ?? []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dailyEntries(PDO $pdo, int $userId, string $from, string $to): array
    {
        $statement = $pdo->prepare(
            'SELECT
                work_date,
                start_at,
                end_at,
                break_minutes,
                work_minutes
            FROM work_entries
            WHERE user_id = :user_id
              AND status = :status
              AND deleted_at IS NULL
              AND work_date BETWEEN :from_date AND :to_date
            ORDER BY work_date ASC, start_at ASC, id ASC'
        );
        $statement->execute([
            'user_id' => $userId,
            'status' => 'active',
            'from_date' => $from,
            'to_date' => $to,
        ]);

        $days = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $date = (string)$row['work_date'];

            if (!isset($days[$date])) {
                $days[$date] = [
                    'date' => $date,
                    'sessions' => [],
                    'work_minutes' => 0,
                    'break_minutes' => 0,
                ];
            }

            $days[$date]['sessions'][] = [
                'start' => substr((string)$row['start_at'], 11, 5),
                'end' => substr((string)$row['end_at'], 11, 5),
                'work_minutes' => (int)$row['work_minutes'],
                'break_minutes' => (int)$row['break_minutes'],
            ];
            $days[$date]['work_minutes'] += (int)$row['work_minutes'];
            $days[$date]['break_minutes'] += (int)$row['break_minutes'];
        }

        return array_values($days);
    }

    /**
     * @param mixed $dailyEntries
     */
    private function detailLines(mixed $dailyEntries): string
    {
        if (!is_array($dailyEntries) || $dailyEntries === []) {
            return '조회된 근무 기록이 없습니다.';
        }

        $lines = [];

        foreach ($dailyEntries as $day) {
            if (!is_array($day)) {
                continue;
            }

            $date = (string)($day['date'] ?? '');
            $sessions = is_array($day['sessions'] ?? null) ? $day['sessions'] : [];
            $sessionTexts = [];

            foreach ($sessions as $session) {
                if (!is_array($session)) {
                    continue;
                }

                $sessionTexts[] = sprintf(
                    '%s ~ %s',
                    $this->trimTime((string)($session['start'] ?? '')),
                    $this->trimTime((string)($session['end'] ?? ''))
                );
            }

            if ($date === '' || $sessionTexts === []) {
                continue;
            }

            $lines[] = sprintf(
                '%s %s %s ( %s )',
                $this->formatShortDate($date),
                $this->weekday($date),
                implode(' , ', $sessionTexts),
                $this->formatHours((int)($day['work_minutes'] ?? 0))
            );
        }

        return $lines === [] ? '조회된 근무 기록이 없습니다.' : implode("\n", $lines);
    }

    private function formatShortDate(string $date): string
    {
        $timestamp = strtotime($date);

        if ($timestamp === false) {
            return $date;
        }

        return date('n/j', $timestamp);
    }

    private function weekday(string $date): string
    {
        $timestamp = strtotime($date);

        if ($timestamp === false) {
            return '';
        }

        return ['일', '월', '화', '수', '목', '금', '토'][(int)date('w', $timestamp)];
    }

    private function trimTime(string $time): string
    {
        if (preg_match('/^0?(\d{1,2}):00$/', $time, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^0(\d:\d{2})$/', $time, $matches) === 1) {
            return $matches[1];
        }

        return $time;
    }

    private function formatHours(int $minutes): string
    {
        if ($minutes % 60 === 0) {
            return (string)intdiv($minutes, 60) . '시간';
        }

        $hours = $minutes / 60;
        $text = rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');

        return $text . '시간';
    }

    private function isAllowedIp(string $sourceIp): bool
    {
        $allowedIps = array_filter(array_map('trim', explode(',', Env::required('WEBHOOK_ALLOWED_IPS'))));
        return in_array($sourceIp, $allowedIps, true);
    }

    private function isActivePeriod(string $today): bool
    {
        return $today >= Env::required('WEBHOOK_ACTIVE_FROM') && $today <= Env::required('WEBHOOK_ACTIVE_TO');
    }

    private function stringValue(array $payload, string $key, int $maxLength): string
    {
        $value = $payload[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s 값이 필요합니다.', $key));
        }

        $value = trim($value);

        if (strlen($value) > $maxLength) {
            throw new InvalidArgumentException(sprintf('%s는 %d자 이하여야 합니다.', $key, $maxLength));
        }

        return $value;
    }

    private function optionalStringValue(array $payload, string $key, int $maxLength): ?string
    {
        $value = $payload[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s 값이 올바르지 않습니다.', $key));
        }

        $value = trim($value);

        if (strlen($value) > $maxLength) {
            throw new InvalidArgumentException(sprintf('%s는 %d자 이하여야 합니다.', $key, $maxLength));
        }

        return $value;
    }

    private function dateValue(mixed $value, string $key): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s 값이 필요합니다.', $key));
        }

        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException(sprintf('%s는 YYYY-MM-DD 형식이어야 합니다.', $key));
        }

        return $value;
    }

    private function positiveInt(mixed $value, string $key): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int)$value;
        }

        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException(sprintf('%s는 양의 정수여야 합니다.', $key));
        }

        return $value;
    }

    private function formatMinutes(int $minutes): string
    {
        return sprintf('%d시간 %02d분', intdiv($minutes, 60), $minutes % 60);
    }
}
