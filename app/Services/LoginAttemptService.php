<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use PDO;
use RuntimeException;

final class LoginAttemptService
{
    private const EMAIL_FAILURE_LIMIT = 5;
    private const IP_FAILURE_LIMIT = 20;
    private const WINDOW_MINUTES = 10;

    public function assertAllowed(string $email, string $sourceIp): void
    {
        $email = $this->email($email);
        $sourceIp = $this->sourceIp($sourceIp);

        if ($this->failureCount('email = :email', ['email' => $email]) >= self::EMAIL_FAILURE_LIMIT) {
            throw new RuntimeException('로그인 실패 횟수가 많아 10분 후 다시 시도해야 합니다.');
        }

        if ($this->failureCount('source_ip = :source_ip', ['source_ip' => $sourceIp]) >= self::IP_FAILURE_LIMIT) {
            throw new RuntimeException('현재 IP에서 로그인 실패가 많아 10분 후 다시 시도해야 합니다.');
        }
    }

    public function recordFailure(string $email, string $sourceIp, string $reason): void
    {
        Database::statement(
            'INSERT INTO login_attempts (
                email,
                source_ip,
                success,
                failure_reason
            ) VALUES (
                :email,
                :source_ip,
                :success,
                :failure_reason
            )',
            [
                'email' => $this->email($email),
                'source_ip' => $this->sourceIp($sourceIp),
                'success' => 0,
                'failure_reason' => substr($reason, 0, 255),
            ]
        );
    }

    public function recordSuccess(string $email, string $sourceIp): void
    {
        $email = $this->email($email);
        $sourceIp = $this->sourceIp($sourceIp);

        Database::transaction(function (PDO $pdo) use ($email, $sourceIp): void {
            Database::statement(
                'INSERT INTO login_attempts (
                    email,
                    source_ip,
                    success,
                    failure_reason
                ) VALUES (
                    :email,
                    :source_ip,
                    :success,
                    :failure_reason
                )',
                [
                    'email' => $email,
                    'source_ip' => $sourceIp,
                    'success' => 1,
                    'failure_reason' => null,
                ]
            );
            Database::statement(
                'DELETE FROM login_attempts
                WHERE success = :success
                  AND email = :email
                  AND source_ip = :source_ip',
                [
                    'success' => 0,
                    'email' => $email,
                    'source_ip' => $sourceIp,
                ]
            );
        });
    }

    /**
     * @param array<string, string> $parameters
     */
    private function failureCount(string $where, array $parameters): int
    {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS failure_count
            FROM login_attempts
            WHERE success = :success
              AND created_at >= DATE_SUB(NOW(), INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)
              AND ' . $where,
            ['success' => 0] + $parameters
        );

        return (int)($row['failure_count'] ?? 0);
    }

    private function email(string $email): string
    {
        return substr(strtolower(trim($email)), 0, 255);
    }

    private function sourceIp(string $sourceIp): string
    {
        $sourceIp = trim($sourceIp);

        return substr($sourceIp === '' ? 'unknown' : $sourceIp, 0, 45);
    }
}
