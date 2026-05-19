<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use PDO;
use RuntimeException;

final class AuditLogService
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, string|null> $request
     */
    public function record(
        ?int $actorUserId,
        ?int $targetUserId,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $before = null,
        ?array $after = null,
        array $request = []
    ): int {
        return Database::transaction(
            fn (PDO $pdo): int => $this->recordInTransaction(
                $pdo,
                $actorUserId,
                $targetUserId,
                $action,
                $entityType,
                $entityId,
                $before,
                $after,
                $request
            )
        );
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, string|null> $request
     */
    public function recordInTransaction(
        PDO $pdo,
        ?int $actorUserId,
        ?int $targetUserId,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $before = null,
        ?array $after = null,
        array $request = []
    ): int {
        $createdAt = date('Y-m-d H:i:s');
        $beforeJson = $this->encodeJson($before);
        $afterJson = $this->encodeJson($after);
        $prevHash = $this->latestHash($pdo);
        $requestIp = $request['request_ip'] ?? null;
        $userAgent = $request['user_agent'] ?? null;
        $requestId = $request['request_id'] ?? null;

        $hash = $this->hash([
            'actor_user_id' => $actorUserId,
            'target_user_id' => $targetUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_json' => $beforeJson,
            'after_json' => $afterJson,
            'request_ip' => $requestIp,
            'user_agent' => $userAgent,
            'request_id' => $requestId,
            'prev_hash' => $prevHash,
            'created_at' => $createdAt,
        ]);

        $statement = $pdo->prepare(
            'INSERT INTO audit_logs (
                actor_user_id,
                target_user_id,
                action,
                entity_type,
                entity_id,
                before_json,
                after_json,
                request_ip,
                user_agent,
                request_id,
                prev_hash,
                hash,
                created_at
            ) VALUES (
                :actor_user_id,
                :target_user_id,
                :action,
                :entity_type,
                :entity_id,
                :before_json,
                :after_json,
                :request_ip,
                :user_agent,
                :request_id,
                :prev_hash,
                :hash,
                :created_at
            )'
        );

        $statement->execute([
            'actor_user_id' => $actorUserId,
            'target_user_id' => $targetUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_json' => $beforeJson,
            'after_json' => $afterJson,
            'request_ip' => $requestIp,
            'user_agent' => $userAgent,
            'request_id' => $requestId,
            'prev_hash' => $prevHash,
            'hash' => $hash,
            'created_at' => $createdAt,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function encodeJson(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $normalized = $this->normalizeForHash($payload);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('감사 로그 JSON 인코딩에 실패했습니다.');
        }

        return $json;
    }

    private function latestHash(PDO $pdo): ?string
    {
        $statement = $pdo->query('SELECT hash FROM audit_logs ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return isset($row['hash']) ? (string)$row['hash'] : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hash(array $payload): string
    {
        $json = json_encode($this->normalizeForHash($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('감사 로그 해시 생성에 실패했습니다.');
        }

        return hash('sha256', $json);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function normalizeForHash(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($this->isList($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeForHash($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeForHash($item);
        }

        return $value;
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
