<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class AdminAiUserService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUsers(): array
    {
        $users = Database::fetchAll(
            'SELECT
                u.id,
                u.email,
                u.name,
                u.role,
                u.ai_enabled,
                u.ai_daily_limit,
                u.created_at,
                COALESCE(active_sessions.active_count, 0) AS active_session_count,
                COALESCE(active_remember_tokens.active_count, 0) AS active_remember_token_count,
                COALESCE(today_usage.used_count, 0) AS ai_used_today,
                COALESCE(total_usage.total_count, 0) AS ai_total_usage_count,
                last_usage.last_used_at
            FROM users u
            LEFT JOIN (
                SELECT user_id, COUNT(*) AS active_count
                FROM sessions
                WHERE expires_at > NOW()
                  AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
                GROUP BY user_id
            ) active_sessions ON active_sessions.user_id = u.id
            LEFT JOIN (
                SELECT user_id, COUNT(*) AS active_count
                FROM remember_tokens
                WHERE expires_at > NOW()
                GROUP BY user_id
            ) active_remember_tokens ON active_remember_tokens.user_id = u.id
            LEFT JOIN (
                SELECT user_id, COUNT(*) AS used_count
                FROM ai_usage_logs
                WHERE feature = :today_feature
                  AND result IN (:pending, :success, :failed)
                  AND created_at >= CURDATE()
                GROUP BY user_id
            ) today_usage ON today_usage.user_id = u.id
            LEFT JOIN (
                SELECT user_id, COUNT(*) AS total_count
                FROM ai_usage_logs
                WHERE feature = :total_feature
                GROUP BY user_id
            ) total_usage ON total_usage.user_id = u.id
            LEFT JOIN (
                SELECT user_id, MAX(created_at) AS last_used_at
                FROM ai_usage_logs
                WHERE feature = :last_feature
                GROUP BY user_id
            ) last_usage ON last_usage.user_id = u.id
            ORDER BY u.created_at DESC, u.id DESC',
            [
                'today_feature' => 'work_entry_import',
                'pending' => 'pending',
                'success' => 'success',
                'failed' => 'failed',
                'total_feature' => 'work_entry_import',
                'last_feature' => 'work_entry_import',
            ]
        );

        return array_map(fn (array $user): array => $this->normalizeUser($user), $users);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentUsageLogs(int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $statement = Database::connection()->prepare(
            sprintf(
                'SELECT
                    l.id,
                    l.user_id,
                    u.email,
                    u.name,
                    l.provider,
                    l.model,
                    l.feature,
                    l.result,
                    l.error_message,
                    l.created_at
                FROM ai_usage_logs l
                INNER JOIN users u ON u.id = l.user_id
                ORDER BY l.created_at DESC, l.id DESC
                LIMIT %d',
                $limit
            )
        );
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public function updateUserAccess(int $userId, array $payload): array
    {
        $enabled = isset($payload['aiEnabled']) && (string)$payload['aiEnabled'] === '1' ? 1 : 0;
        $dailyLimit = $this->dailyLimitFromPayload($payload);

        return Database::transaction(function (PDO $pdo) use ($userId, $enabled, $dailyLimit): array {
            $before = $this->findUserForUpdate($pdo, $userId);

            $statement = $pdo->prepare(
                'UPDATE users
                SET ai_enabled = :ai_enabled,
                    ai_daily_limit = :ai_daily_limit
                WHERE id = :id'
            );
            $statement->execute([
                'id' => $userId,
                'ai_enabled' => $enabled,
                'ai_daily_limit' => $dailyLimit,
            ]);

            $after = $this->findUserForUpdate($pdo, $userId);

            return [
                'before' => $this->normalizeUser($before),
                'after' => $this->normalizeUser($after),
            ];
        });
    }

    /**
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public function revokeLoginAccess(int $userId): array
    {
        return Database::transaction(function (PDO $pdo) use ($userId): array {
            $user = $this->findUserForUpdate($pdo, $userId);
            $before = $this->loginAccessState($pdo, $user);

            $statement = $pdo->prepare('DELETE FROM sessions WHERE user_id = :user_id');
            $statement->execute(['user_id' => $userId]);

            $statement = $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id');
            $statement->execute(['user_id' => $userId]);

            return [
                'before' => $before,
                'after' => $this->loginAccessState($pdo, $user),
            ];
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dailyLimitFromPayload(array $payload): int
    {
        $raw = $payload['aiDailyLimit'] ?? null;

        if (!is_string($raw) || trim($raw) === '' || !ctype_digit(trim($raw))) {
            throw new InvalidArgumentException('AI 일일 한도는 0 이상의 정수여야 합니다.');
        }

        $limit = (int)trim($raw);

        if ($limit > 10000) {
            throw new InvalidArgumentException('AI 일일 한도는 10000회를 넘을 수 없습니다.');
        }

        return $limit;
    }

    /**
     * @return array<string, mixed>
     */
    private function findUserForUpdate(PDO $pdo, int $userId): array
    {
        $statement = $pdo->prepare(
            'SELECT id, email, name, role, ai_enabled, ai_daily_limit, created_at, updated_at
            FROM users
            WHERE id = :id
            LIMIT 1
            FOR UPDATE'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($user)) {
            throw new RuntimeException('사용자를 찾을 수 없습니다.');
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function normalizeUser(array $user): array
    {
        $user['id'] = (int)$user['id'];
        $user['ai_enabled'] = (int)($user['ai_enabled'] ?? 0);
        $user['ai_daily_limit'] = (int)($user['ai_daily_limit'] ?? 0);
        $user['ai_used_today'] = (int)($user['ai_used_today'] ?? 0);
        $user['ai_total_usage_count'] = (int)($user['ai_total_usage_count'] ?? 0);
        $user['active_session_count'] = (int)($user['active_session_count'] ?? 0);
        $user['active_remember_token_count'] = (int)($user['active_remember_token_count'] ?? 0);

        return $user;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function loginAccessState(PDO $pdo, array $user): array
    {
        $sessionStatement = $pdo->prepare(
            'SELECT COUNT(*) AS count
            FROM sessions
            WHERE user_id = :user_id'
        );
        $sessionStatement->execute(['user_id' => (int)$user['id']]);
        $sessionRow = $sessionStatement->fetch(PDO::FETCH_ASSOC);
        $sessionCount = is_array($sessionRow) ? (int)($sessionRow['count'] ?? 0) : 0;

        $tokenStatement = $pdo->prepare(
            'SELECT COUNT(*) AS count
            FROM remember_tokens
            WHERE user_id = :user_id'
        );
        $tokenStatement->execute(['user_id' => (int)$user['id']]);
        $tokenRow = $tokenStatement->fetch(PDO::FETCH_ASSOC);
        $tokenCount = is_array($tokenRow) ? (int)($tokenRow['count'] ?? 0) : 0;

        return [
            'id' => (int)$user['id'],
            'email' => (string)$user['email'],
            'role' => (string)$user['role'],
            'session_count' => $sessionCount,
            'remember_token_count' => $tokenCount,
        ];
    }
}
