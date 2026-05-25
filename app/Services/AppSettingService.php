<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Database\Database;
use InvalidArgumentException;
use PDO;

final class AppSettingService
{
    private const SUPPORT_MAX_PER_HOUR = 'support.max_per_hour';
    private const SUPPORT_MAX_PER_DAY = 'support.max_per_day';
    private const DEFAULT_SUPPORT_MAX_PER_HOUR = 3;
    private const DEFAULT_SUPPORT_MAX_PER_DAY = 10;

    /**
     * @return array{maxPerHour: int, maxPerDay: int}
     */
    public function supportRateLimits(): array
    {
        return [
            'maxPerHour' => $this->intValue(
                self::SUPPORT_MAX_PER_HOUR,
                $this->envInt('SUPPORT_MAX_PER_HOUR', self::DEFAULT_SUPPORT_MAX_PER_HOUR)
            ),
            'maxPerDay' => $this->intValue(
                self::SUPPORT_MAX_PER_DAY,
                $this->envInt('SUPPORT_MAX_PER_DAY', self::DEFAULT_SUPPORT_MAX_PER_DAY)
            ),
        ];
    }

    /**
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $payload
     * @return array{before: array{maxPerHour: int, maxPerDay: int}, after: array{maxPerHour: int, maxPerDay: int}}
     */
    public function updateSupportRateLimits(array $admin, array $payload): array
    {
        $maxPerHour = $this->limitValue($payload['supportMaxPerHour'] ?? null, '시간당 문의 제한');
        $maxPerDay = $this->limitValue($payload['supportMaxPerDay'] ?? null, '일일 문의 제한');

        if ($maxPerHour > 0 && $maxPerDay > 0 && $maxPerHour > $maxPerDay) {
            throw new InvalidArgumentException('시간당 문의 제한은 일일 문의 제한보다 클 수 없습니다.');
        }

        $before = $this->supportRateLimits();
        $adminId = (int)$admin['id'];

        Database::transaction(function (PDO $pdo) use ($maxPerHour, $maxPerDay, $adminId): void {
            $this->upsert($pdo, self::SUPPORT_MAX_PER_HOUR, (string)$maxPerHour, '일반 사용자가 1시간에 등록할 수 있는 문의 수', $adminId);
            $this->upsert($pdo, self::SUPPORT_MAX_PER_DAY, (string)$maxPerDay, '일반 사용자가 하루에 등록할 수 있는 문의 수', $adminId);
        });

        return [
            'before' => $before,
            'after' => $this->supportRateLimits(),
        ];
    }

    private function intValue(string $key, int $default): int
    {
        $row = Database::fetchOne(
            'SELECT setting_value FROM app_settings WHERE setting_key = :setting_key LIMIT 1',
            ['setting_key' => $key]
        );

        if ($row === null) {
            return $default;
        }

        $value = trim((string)$row['setting_value']);

        if ($value === '' || !ctype_digit($value)) {
            return $default;
        }

        return min(1000, (int)$value);
    }

    private function envInt(string $key, int $default): int
    {
        $value = trim(Env::get($key, (string)$default));

        if ($value === '' || !ctype_digit($value)) {
            return $default;
        }

        return min(1000, (int)$value);
    }

    private function limitValue(mixed $value, string $label): int
    {
        if (!is_string($value) || trim($value) === '' || !ctype_digit(trim($value))) {
            throw new InvalidArgumentException($label . '은 0 이상의 정수여야 합니다.');
        }

        $limit = (int)trim($value);

        if ($limit > 1000) {
            throw new InvalidArgumentException($label . '은 1000회를 넘을 수 없습니다.');
        }

        return $limit;
    }

    private function upsert(PDO $pdo, string $key, string $value, string $description, int $adminId): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO app_settings (
                setting_key,
                setting_value,
                description,
                updated_by
            ) VALUES (
                :setting_key,
                :setting_value,
                :description,
                :updated_by
            )
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                description = VALUES(description),
                updated_by = VALUES(updated_by)'
        );
        $statement->execute([
            'setting_key' => $key,
            'setting_value' => $value,
            'description' => $description,
            'updated_by' => $adminId,
        ]);
    }
}
