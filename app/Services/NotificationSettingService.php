<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use DateTimeImmutable;
use InvalidArgumentException;

final class NotificationSettingService
{
    private const PERIOD_TYPES = ['previous_month', 'current_month', 'previous_7_days', 'custom'];

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    public function findForUser(array $user): ?array
    {
        return $this->normalize(Database::fetchOne(
            'SELECT
                id,
                user_id,
                channel,
                telegram_bot_token,
                telegram_chat_id,
                discord_webhook_url,
                summary_period_type,
                custom_period_from,
                custom_period_to,
                monthly_send_day,
                is_active,
                created_at,
                updated_at
            FROM notification_settings
            WHERE user_id = :user_id
            LIMIT 1',
            ['user_id' => (int)$user['id']]
        ));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveForUser(array $user, array $payload): array
    {
        $existing = $this->findForUser($user);
        $userId = (int)$user['id'];
        $channel = $this->channel($payload['channel'] ?? null);
        $summaryPeriodType = $this->summaryPeriodType($payload['summaryPeriodType'] ?? null);
        $customPeriodFrom = null;
        $customPeriodTo = null;
        $monthlySendDay = $this->monthlySendDay($payload['monthlySendDay'] ?? null);
        $isActive = isset($payload['isActive']) ? 1 : 0;

        if ($summaryPeriodType === 'custom') {
            $customPeriodFrom = $this->dateValue($payload['customPeriodFrom'] ?? null, 'customPeriodFrom');
            $customPeriodTo = $this->dateValue($payload['customPeriodTo'] ?? null, 'customPeriodTo');

            if ($customPeriodFrom > $customPeriodTo) {
                throw new InvalidArgumentException('직접 지정 시작일은 종료일보다 늦을 수 없습니다.');
            }
        }

        $telegramBotToken = $channel === 'telegram'
            ? $this->secretValue($payload['telegramBotToken'] ?? null, $existing['telegram_bot_token'] ?? null, 'telegramBotToken')
            : null;
        $telegramChatId = $channel === 'telegram'
            ? $this->secretValue($payload['telegramChatId'] ?? null, $existing['telegram_chat_id'] ?? null, 'telegramChatId')
            : null;
        $discordWebhookUrl = $channel === 'discord'
            ? $this->urlValue($payload['discordWebhookUrl'] ?? null, $existing['discord_webhook_url'] ?? null)
            : null;

        if ($existing === null) {
            Database::statement(
                'INSERT INTO notification_settings (
                    user_id,
                    channel,
                    telegram_bot_token,
                    telegram_chat_id,
                    discord_webhook_url,
                    summary_period_type,
                    custom_period_from,
                    custom_period_to,
                    monthly_send_day,
                    is_active
                ) VALUES (
                    :user_id,
                    :channel,
                    :telegram_bot_token,
                    :telegram_chat_id,
                    :discord_webhook_url,
                    :summary_period_type,
                    :custom_period_from,
                    :custom_period_to,
                    :monthly_send_day,
                    :is_active
                )',
                [
                    'user_id' => $userId,
                    'channel' => $channel,
                    'telegram_bot_token' => $telegramBotToken,
                    'telegram_chat_id' => $telegramChatId,
                    'discord_webhook_url' => $discordWebhookUrl,
                    'summary_period_type' => $summaryPeriodType,
                    'custom_period_from' => $customPeriodFrom,
                    'custom_period_to' => $customPeriodTo,
                    'monthly_send_day' => $monthlySendDay,
                    'is_active' => $isActive,
                ]
            );
        } else {
            Database::statement(
                'UPDATE notification_settings
                SET channel = :channel,
                    telegram_bot_token = :telegram_bot_token,
                    telegram_chat_id = :telegram_chat_id,
                    discord_webhook_url = :discord_webhook_url,
                    summary_period_type = :summary_period_type,
                    custom_period_from = :custom_period_from,
                    custom_period_to = :custom_period_to,
                    monthly_send_day = :monthly_send_day,
                    is_active = :is_active
                WHERE user_id = :user_id',
                [
                    'user_id' => $userId,
                    'channel' => $channel,
                    'telegram_bot_token' => $telegramBotToken,
                    'telegram_chat_id' => $telegramChatId,
                    'discord_webhook_url' => $discordWebhookUrl,
                    'summary_period_type' => $summaryPeriodType,
                    'custom_period_from' => $customPeriodFrom,
                    'custom_period_to' => $customPeriodTo,
                    'monthly_send_day' => $monthlySendDay,
                    'is_active' => $isActive,
                ]
            );
        }

        return $this->findForUser($user) ?? throw new InvalidArgumentException('저장된 정기 발송 설정을 찾을 수 없습니다.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dueForDate(string $date): array
    {
        $day = (int)date('j', strtotime($this->dateValue($date, 'date')));

        return array_map(
            fn (array $row): array => $this->normalize($row) ?? $row,
            Database::fetchAll(
                'SELECT
                    ns.id,
                    ns.user_id,
                    ns.channel,
                    ns.telegram_bot_token,
                    ns.telegram_chat_id,
                    ns.discord_webhook_url,
                    ns.summary_period_type,
                    ns.custom_period_from,
                    ns.custom_period_to,
                    ns.monthly_send_day,
                    ns.is_active,
                    ns.created_at,
                    ns.updated_at
                FROM notification_settings ns
                WHERE ns.is_active = :is_active
                  AND ns.monthly_send_day = :monthly_send_day
                ORDER BY ns.id ASC',
                [
                    'is_active' => 1,
                    'monthly_send_day' => $day,
                ]
            )
        );
    }

    /**
     * @param array<string, mixed> $setting
     * @return array{0: string, 1: string}
     */
    public function resolvePeriod(array $setting, string $triggerDate): array
    {
        $date = new DateTimeImmutable($this->dateValue($triggerDate, 'triggerDate'));
        $type = (string)($setting['summary_period_type'] ?? 'previous_month');

        if ($type === 'previous_month') {
            $base = $date->modify('first day of previous month');

            return [$base->format('Y-m-01'), $base->format('Y-m-t')];
        }

        if ($type === 'current_month') {
            return [$date->format('Y-m-01'), $date->format('Y-m-d')];
        }

        if ($type === 'previous_7_days') {
            return [$date->modify('-6 days')->format('Y-m-d'), $date->format('Y-m-d')];
        }

        if ($type === 'custom') {
            $from = $this->dateValue($setting['custom_period_from'] ?? null, 'custom_period_from');
            $to = $this->dateValue($setting['custom_period_to'] ?? null, 'custom_period_to');

            if ($from > $to) {
                throw new InvalidArgumentException('직접 지정 시작일은 종료일보다 늦을 수 없습니다.');
            }

            return [$from, $to];
        }

        throw new InvalidArgumentException('요약 기간 기준이 올바르지 않습니다.');
    }

    public function mask(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 4) . str_repeat('*', 8) . substr($value, -4);
    }

    private function channel(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['telegram', 'discord'], true)) {
            throw new InvalidArgumentException('전송 채널을 선택해야 합니다.');
        }

        return $value;
    }

    private function dateValue(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s 값이 필요합니다.', $field));
        }

        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException(sprintf('%s는 YYYY-MM-DD 형식이어야 합니다.', $field));
        }

        return $value;
    }

    private function monthlySendDay(mixed $value): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int)$value;
        }

        if (!is_int($value) || $value < 1 || $value > 31) {
            throw new InvalidArgumentException('발송일은 1일부터 31일 사이여야 합니다.');
        }

        return $value;
    }

    private function summaryPeriodType(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::PERIOD_TYPES, true)) {
            throw new InvalidArgumentException('요약 기간 기준을 선택해야 합니다.');
        }

        return $value;
    }

    private function secretValue(mixed $value, mixed $existing, string $field): string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        if (is_string($existing) && trim($existing) !== '') {
            return trim($existing);
        }

        throw new InvalidArgumentException(sprintf('%s 값이 필요합니다.', $field));
    }

    private function urlValue(mixed $value, mixed $existing): string
    {
        $url = is_string($value) && trim($value) !== ''
            ? trim($value)
            : (is_string($existing) ? trim($existing) : '');

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Discord webhook URL이 올바르지 않습니다.');
        }

        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) {
            throw new InvalidArgumentException('Discord webhook URL이 올바르지 않습니다.');
        }

        return $url;
    }

    /**
     * @param array<string, mixed>|null $setting
     * @return array<string, mixed>|null
     */
    private function normalize(?array $setting): ?array
    {
        if ($setting === null) {
            return null;
        }

        $setting['id'] = (int)$setting['id'];
        $setting['user_id'] = (int)$setting['user_id'];
        $setting['monthly_send_day'] = (int)$setting['monthly_send_day'];
        $setting['is_active'] = (int)$setting['is_active'];
        $setting['summary_period_type'] = is_string($setting['summary_period_type'] ?? null)
            ? $setting['summary_period_type']
            : 'previous_month';

        return $setting;
    }
}
