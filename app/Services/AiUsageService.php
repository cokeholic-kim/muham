<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Database\Database;
use PDO;
use RuntimeException;

final class AiUsageService
{
    private const PROVIDERS = ['gemini', 'openai', 'anthropic'];
    private const FEATURE_WORK_ENTRY_IMPORT = 'work_entry_import';

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function statusForUser(array $user): array
    {
        $enabled = (int)($user['ai_enabled'] ?? 0) === 1;
        $dailyLimit = max(0, (int)($user['ai_daily_limit'] ?? 0));
        $usedToday = $this->usageCount((int)$user['id']);
        $provider = $this->provider();
        $model = $this->model($provider);

        return [
            'enabled' => $enabled,
            'dailyLimit' => $dailyLimit,
            'usedToday' => $usedToday,
            'remainingToday' => max(0, $dailyLimit - $usedToday),
            'provider' => $provider,
            'model' => $model,
            'configured' => trim(Env::get('AI_API_KEY')) !== '',
            'usable' => $enabled && $dailyLimit > 0 && $usedToday < $dailyLimit && trim(Env::get('AI_API_KEY')) !== '',
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array{usageId: int, provider: string, model: string, apiKey: string}
     */
    public function reserveWorkEntryImport(array $user, string $inputText): array
    {
        $userId = (int)$user['id'];
        $provider = $this->provider();
        $model = $this->model($provider);
        $apiKey = trim(Env::get('AI_API_KEY'));

        if ($apiKey === '') {
            throw new RuntimeException('서버 AI API Key가 설정되어 있지 않습니다.');
        }

        return Database::transaction(function (PDO $pdo) use ($user, $userId, $provider, $model, $apiKey, $inputText): array {
            $this->lockUser($pdo, $userId);
            $enabled = (int)($user['ai_enabled'] ?? 0) === 1;
            $dailyLimit = max(0, (int)($user['ai_daily_limit'] ?? 0));
            $inputHash = hash('sha256', $inputText);

            if (!$enabled) {
                $this->insertUsageLog($pdo, $userId, $provider, $model, $inputHash, 'disabled', 'AI 변환 권한이 없습니다.');
                throw new RuntimeException('AI 변환 권한이 없습니다.');
            }

            if ($dailyLimit < 1) {
                $this->insertUsageLog($pdo, $userId, $provider, $model, $inputHash, 'rate_limited', 'AI 일일 사용 한도가 0회입니다.');
                throw new RuntimeException('AI 일일 사용 한도가 없습니다.');
            }

            if ($this->usageCountInTransaction($pdo, $userId) >= $dailyLimit) {
                $this->insertUsageLog($pdo, $userId, $provider, $model, $inputHash, 'rate_limited', 'AI 일일 사용 한도를 초과했습니다.');
                throw new RuntimeException('AI 일일 사용 한도를 초과했습니다.');
            }

            $usageId = $this->insertUsageLog($pdo, $userId, $provider, $model, $inputHash, 'pending', null);

            return [
                'usageId' => $usageId,
                'provider' => $provider,
                'model' => $model,
                'apiKey' => $apiKey,
            ];
        });
    }

    public function markResult(int $usageId, string $result, ?string $errorMessage): void
    {
        if (!in_array($result, ['success', 'failed'], true)) {
            throw new RuntimeException('AI 사용 결과 상태가 올바르지 않습니다.');
        }

        Database::statement(
            'UPDATE ai_usage_logs
            SET result = :result,
                error_message = :error_message
            WHERE id = :id',
            [
                'id' => $usageId,
                'result' => $result,
                'error_message' => $errorMessage,
            ]
        );
    }

    public function defaultModel(string $provider): string
    {
        return match ($provider) {
            'openai' => 'gpt-4o-mini',
            'anthropic' => 'claude-3-5-sonnet-latest',
            default => 'gemini-2.0-flash',
        };
    }

    private function provider(): string
    {
        $provider = trim(Env::get('AI_PROVIDER', 'gemini'));

        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new RuntimeException('AI_PROVIDER 설정이 올바르지 않습니다.');
        }

        return $provider;
    }

    private function model(string $provider): string
    {
        $model = trim(Env::get('AI_MODEL'));

        return $model !== '' ? substr($model, 0, 120) : $this->defaultModel($provider);
    }

    private function usageCount(int $userId): int
    {
        return Database::transaction(fn (PDO $pdo): int => $this->usageCountInTransaction($pdo, $userId));
    }

    private function usageCountInTransaction(PDO $pdo, int $userId): int
    {
        $statement = $pdo->prepare(
            'SELECT id
            FROM ai_usage_logs
            WHERE user_id = :user_id
              AND feature = :feature
              AND result IN (:pending, :success, :failed)
              AND created_at >= CURDATE()
            FOR UPDATE'
        );
        $statement->execute([
            'user_id' => $userId,
            'feature' => self::FEATURE_WORK_ENTRY_IMPORT,
            'pending' => 'pending',
            'success' => 'success',
            'failed' => 'failed',
        ]);

        return count($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function lockUser(PDO $pdo, int $userId): void
    {
        $statement = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
        $statement->execute(['id' => $userId]);

        if ($statement->fetch(PDO::FETCH_ASSOC) === false) {
            throw new RuntimeException('사용자를 찾을 수 없습니다.');
        }
    }

    private function insertUsageLog(
        PDO $pdo,
        int $userId,
        string $provider,
        string $model,
        string $inputHash,
        string $result,
        ?string $errorMessage
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO ai_usage_logs (
                user_id,
                provider,
                model,
                feature,
                input_sha256,
                result,
                error_message
            ) VALUES (
                :user_id,
                :provider,
                :model,
                :feature,
                :input_sha256,
                :result,
                :error_message
            )'
        );
        $statement->execute([
            'user_id' => $userId,
            'provider' => $provider,
            'model' => $model,
            'feature' => self::FEATURE_WORK_ENTRY_IMPORT,
            'input_sha256' => $inputHash,
            'result' => $result,
            'error_message' => $errorMessage,
        ]);

        return (int)$pdo->lastInsertId();
    }
}
