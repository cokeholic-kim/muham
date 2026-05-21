<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class WorkEntryService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        /** @var array<string, string|null> */
        private readonly array $requestContext
    ) {
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $actor, array $payload): array
    {
        $actorId = (int)$actor['id'];
        $role = (string)$actor['role'];
        $targetUserId = $this->resolveTargetUserId($actor, $payload['userId'] ?? null);
        $entry = $this->buildEntryData($payload, $targetUserId);

        return Database::transaction(function (PDO $pdo) use ($actorId, $role, $targetUserId, $entry): array {
            $this->assertNoOverlap($pdo, $targetUserId, $entry['start_at'], $entry['end_at']);

            $statement = $pdo->prepare(
                'INSERT INTO work_entries (
                    user_id,
                    work_date,
                    start_at,
                    end_at,
                    break_minutes,
                    work_minutes,
                    memo,
                    status,
                    version,
                    created_by,
                    updated_by
                ) VALUES (
                    :user_id,
                    :work_date,
                    :start_at,
                    :end_at,
                    :break_minutes,
                    :work_minutes,
                    :memo,
                    :status,
                    :version,
                    :created_by,
                    :updated_by
                )'
            );

            $statement->execute([
                'user_id' => $targetUserId,
                'work_date' => $entry['work_date'],
                'start_at' => $entry['start_at'],
                'end_at' => $entry['end_at'],
                'break_minutes' => $entry['break_minutes'],
                'work_minutes' => $entry['work_minutes'],
                'memo' => $entry['memo'],
                'status' => 'active',
                'version' => 1,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $created = $this->findByIdForActor($pdo, (int)$pdo->lastInsertId(), $actorId, $role);

            if ($created === null) {
                throw new RuntimeException('생성된 근무 기록을 찾을 수 없습니다.');
            }

            $this->auditLogService->recordInTransaction(
                $pdo,
                $actorId,
                $targetUserId,
                'create_work',
                'work_entry',
                (int)$created['id'],
                null,
                $created,
                $this->requestContext
            );

            return $created;
        });
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<int, array<string, mixed>> $payloads
     * @return array<int, array<string, mixed>>
     */
    public function bulkCreate(array $actor, array $payloads): array
    {
        if ($payloads === []) {
            throw new InvalidArgumentException('저장할 근무 기록이 없습니다.');
        }

        if (count($payloads) > 100) {
            throw new InvalidArgumentException('한 번에 저장할 수 있는 근무 기록은 최대 100건입니다.');
        }

        $actorId = (int)$actor['id'];
        $role = (string)$actor['role'];
        $targetUserId = $this->resolveTargetUserId($actor, null);
        $entries = array_map(
            fn (array $payload): array => $this->buildEntryData($payload, $targetUserId),
            $payloads
        );

        usort($entries, fn (array $a, array $b): int => strcmp((string)$a['start_at'], (string)$b['start_at']));
        $this->assertNoInternalOverlap($entries);

        return Database::transaction(function (PDO $pdo) use ($actorId, $role, $targetUserId, $entries): array {
            $createdEntries = [];

            foreach ($entries as $entry) {
                $this->assertNoOverlap($pdo, $targetUserId, $entry['start_at'], $entry['end_at']);

                $statement = $pdo->prepare(
                    'INSERT INTO work_entries (
                        user_id,
                        work_date,
                        start_at,
                        end_at,
                        break_minutes,
                        work_minutes,
                        memo,
                        status,
                        version,
                        created_by,
                        updated_by
                    ) VALUES (
                        :user_id,
                        :work_date,
                        :start_at,
                        :end_at,
                        :break_minutes,
                        :work_minutes,
                        :memo,
                        :status,
                        :version,
                        :created_by,
                        :updated_by
                    )'
                );

                $statement->execute([
                    'user_id' => $targetUserId,
                    'work_date' => $entry['work_date'],
                    'start_at' => $entry['start_at'],
                    'end_at' => $entry['end_at'],
                    'break_minutes' => $entry['break_minutes'],
                    'work_minutes' => $entry['work_minutes'],
                    'memo' => $entry['memo'],
                    'status' => 'active',
                    'version' => 1,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);

                $created = $this->findByIdForActor($pdo, (int)$pdo->lastInsertId(), $actorId, $role);

                if ($created === null) {
                    throw new RuntimeException('생성된 근무 기록을 찾을 수 없습니다.');
                }

                $createdEntries[] = $created;
            }

            $this->auditLogService->recordInTransaction(
                $pdo,
                $actorId,
                $targetUserId,
                'bulk_import_work',
                'work_entry',
                null,
                null,
                [
                    'count' => count($createdEntries),
                    'entries' => $createdEntries,
                ],
                $this->requestContext
            );

            return $createdEntries;
        });
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $query
     * @return array<int, array<string, mixed>>
     */
    public function list(array $actor, array $query): array
    {
        $targetUserId = $this->resolveTargetUserId($actor, $query['userId'] ?? null);
        [$from, $to] = $this->period($query);

        return Database::fetchAll(
            'SELECT
                id,
                user_id,
                work_date,
                start_at,
                end_at,
                break_minutes,
                work_minutes,
                memo,
                status,
                version,
                created_by,
                updated_by,
                deleted_at,
                created_at,
                updated_at
            FROM work_entries
            WHERE user_id = :user_id
              AND status = :status
              AND deleted_at IS NULL
              AND work_date BETWEEN :from_date AND :to_date
            ORDER BY work_date ASC, start_at ASC, id ASC',
            [
                'user_id' => $targetUserId,
                'status' => 'active',
                'from_date' => $from,
                'to_date' => $to,
            ]
        );
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<int, array<string, mixed>>
     */
    public function recent(array $actor, int $limit = 10): array
    {
        $targetUserId = $this->resolveTargetUserId($actor, null);
        $limit = max(1, min(50, $limit));

        return Database::fetchAll(
            'SELECT
                id,
                user_id,
                work_date,
                start_at,
                end_at,
                break_minutes,
                work_minutes,
                memo,
                status,
                version,
                created_by,
                updated_by,
                deleted_at,
                created_at,
                updated_at
            FROM work_entries
            WHERE user_id = :user_id
              AND status = :status
              AND deleted_at IS NULL
            ORDER BY work_date DESC, start_at DESC, id DESC
            LIMIT ' . $limit,
            [
                'user_id' => $targetUserId,
                'status' => 'active',
            ]
        );
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>|null
     */
    public function find(array $actor, int $id): ?array
    {
        return $this->findByIdForActor(
            Database::connection(),
            $id,
            (int)$actor['id'],
            (string)$actor['role']
        );
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function summary(array $actor, array $query): array
    {
        $entries = $this->list($actor, $query);
        [$from, $to] = $this->period($query);
        $workDates = [];
        $grossMinutes = 0;
        $breakMinutes = 0;
        $workMinutes = 0;

        foreach ($entries as $entry) {
            $workDates[(string)$entry['work_date']] = true;
            $grossMinutes += $this->minutesBetween((string)$entry['start_at'], (string)$entry['end_at']);
            $breakMinutes += (int)$entry['break_minutes'];
            $workMinutes += (int)$entry['work_minutes'];
        }

        return [
            'from' => $from,
            'to' => $to,
            'total_entries' => count($entries),
            'total_work_days' => count($workDates),
            'gross_minutes' => $grossMinutes,
            'break_minutes' => $breakMinutes,
            'work_minutes' => $workMinutes,
        ];
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(array $actor, int $id, array $payload): array
    {
        $actorId = (int)$actor['id'];
        $role = (string)$actor['role'];

        return Database::transaction(function (PDO $pdo) use ($actorId, $role, $id, $payload): array {
            $before = $this->findByIdForActor($pdo, $id, $actorId, $role, true);

            if ($before === null) {
                throw new RuntimeException('근무 기록을 찾을 수 없습니다.');
            }

            $merged = [
                'userId' => $before['user_id'],
                'workDate' => $payload['workDate'] ?? $before['work_date'],
                'startAt' => $payload['startAt'] ?? $before['start_at'],
                'endAt' => $payload['endAt'] ?? $before['end_at'],
                'breakMinutes' => $payload['breakMinutes'] ?? $before['break_minutes'],
                'memo' => array_key_exists('memo', $payload) ? $payload['memo'] : $before['memo'],
            ];
            $entry = $this->buildEntryData($merged, (int)$before['user_id']);

            $this->assertNoOverlap($pdo, (int)$before['user_id'], $entry['start_at'], $entry['end_at'], $id);

            $statement = $pdo->prepare(
                'UPDATE work_entries
                SET work_date = :work_date,
                    start_at = :start_at,
                    end_at = :end_at,
                    break_minutes = :break_minutes,
                    work_minutes = :work_minutes,
                    memo = :memo,
                    version = version + 1,
                    updated_by = :updated_by
                WHERE id = :id'
            );

            $statement->execute([
                'work_date' => $entry['work_date'],
                'start_at' => $entry['start_at'],
                'end_at' => $entry['end_at'],
                'break_minutes' => $entry['break_minutes'],
                'work_minutes' => $entry['work_minutes'],
                'memo' => $entry['memo'],
                'updated_by' => $actorId,
                'id' => $id,
            ]);

            $after = $this->findByIdForActor($pdo, $id, $actorId, $role, true);

            if ($after === null) {
                throw new RuntimeException('수정된 근무 기록을 찾을 수 없습니다.');
            }

            $this->auditLogService->recordInTransaction(
                $pdo,
                $actorId,
                (int)$after['user_id'],
                'update_work',
                'work_entry',
                $id,
                $before,
                $after,
                $this->requestContext
            );

            return $after;
        });
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function delete(array $actor, int $id): array
    {
        $actorId = (int)$actor['id'];
        $role = (string)$actor['role'];

        return Database::transaction(function (PDO $pdo) use ($actorId, $role, $id): array {
            $before = $this->findByIdForActor($pdo, $id, $actorId, $role, true);

            if ($before === null) {
                throw new RuntimeException('근무 기록을 찾을 수 없습니다.');
            }

            $statement = $pdo->prepare(
                'UPDATE work_entries
                SET status = :status,
                    deleted_at = :deleted_at,
                    version = version + 1,
                    updated_by = :updated_by
                WHERE id = :id'
            );

            $statement->execute([
                'status' => 'deleted',
                'deleted_at' => date('Y-m-d H:i:s'),
                'updated_by' => $actorId,
                'id' => $id,
            ]);

            $after = $this->findByIdForActor($pdo, $id, $actorId, $role, true, true);

            if ($after === null) {
                throw new RuntimeException('삭제된 근무 기록을 찾을 수 없습니다.');
            }

            $this->auditLogService->recordInTransaction(
                $pdo,
                $actorId,
                (int)$after['user_id'],
                'delete_work',
                'work_entry',
                $id,
                $before,
                $after,
                $this->requestContext
            );

            return $after;
        });
    }

    /**
     * @param array<string, mixed> $actor
     * @param mixed $requestedUserId
     */
    private function resolveTargetUserId(array $actor, mixed $requestedUserId): int
    {
        if (($actor['role'] ?? '') !== 'admin') {
            if ($requestedUserId !== null && $requestedUserId !== '' && (int)$requestedUserId !== (int)$actor['id']) {
                throw new RuntimeException('접근 권한이 없습니다.');
            }

            return (int)$actor['id'];
        }

        if ($requestedUserId === null || $requestedUserId === '') {
            return (int)$actor['id'];
        }

        if (!is_numeric($requestedUserId) || (int)$requestedUserId < 1) {
            throw new InvalidArgumentException('userId는 양의 정수여야 합니다.');
        }

        return (int)$requestedUserId;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildEntryData(array $payload, int $userId): array
    {
        $workDate = $this->dateValue($payload['workDate'] ?? null, 'workDate');
        $startAt = $this->dateTimeValue($payload['startAt'] ?? null, 'startAt');
        $endAt = $this->dateTimeValue($payload['endAt'] ?? null, 'endAt');
        $breakMinutes = $this->nonNegativeInt($payload['breakMinutes'] ?? 0, 'breakMinutes');
        $totalMinutes = $this->minutesBetween($startAt, $endAt);

        if ($totalMinutes <= 0) {
            throw new InvalidArgumentException('종료 시간은 시작 시간보다 늦어야 합니다.');
        }

        if ($breakMinutes >= $totalMinutes) {
            throw new InvalidArgumentException('휴게 시간은 전체 근무 시간보다 짧아야 합니다.');
        }

        return [
            'user_id' => $userId,
            'work_date' => $workDate,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'break_minutes' => $breakMinutes,
            'work_minutes' => $totalMinutes - $breakMinutes,
            'memo' => $this->nullableString($payload['memo'] ?? null, 2000, 'memo'),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0: string, 1: string}
     */
    private function period(array $query): array
    {
        $from = $this->dateValue($query['from'] ?? null, 'from');
        $to = $this->dateValue($query['to'] ?? null, 'to');

        if ($from > $to) {
            throw new InvalidArgumentException('from은 to보다 늦을 수 없습니다.');
        }

        return [$from, $to];
    }

    private function assertNoOverlap(PDO $pdo, int $userId, string $startAt, string $endAt, ?int $excludeId = null): void
    {
        $sql = 'SELECT id
            FROM work_entries
            WHERE user_id = :user_id
              AND status = :status
              AND deleted_at IS NULL
              AND NOT (end_at <= :start_at OR start_at >= :end_at)';
        $params = [
            'user_id' => $userId,
            'status' => 'active',
            'start_at' => $startAt,
            'end_at' => $endAt,
        ];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $sql .= ' LIMIT 1 FOR UPDATE';
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        if ($statement->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new RuntimeException('같은 시간대의 근무 기록이 이미 있습니다.');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function assertNoInternalOverlap(array $entries): void
    {
        $previous = null;

        foreach ($entries as $entry) {
            if ($previous !== null && (string)$previous['end_at'] > (string)$entry['start_at']) {
                throw new RuntimeException('일괄 입력 목록 안에 겹치는 근무 시간이 있습니다.');
            }

            $previous = $entry;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByIdForActor(
        PDO $pdo,
        int $id,
        int $actorId,
        string $role,
        bool $forUpdate = false,
        bool $includeDeleted = false
    ): ?array {
        $sql = 'SELECT
                id,
                user_id,
                work_date,
                start_at,
                end_at,
                break_minutes,
                work_minutes,
                memo,
                status,
                version,
                created_by,
                updated_by,
                deleted_at,
                created_at,
                updated_at
            FROM work_entries
            WHERE id = :id';
        $params = ['id' => $id];

        if ($role !== 'admin') {
            $sql .= ' AND user_id = :actor_id';
            $params['actor_id'] = $actorId;
        }

        if (!$includeDeleted) {
            $sql .= ' AND deleted_at IS NULL AND status <> :deleted_status';
            $params['deleted_status'] = 'deleted';
        }

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
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

    private function dateTimeValue(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s 값이 필요합니다.', $field));
        }

        $value = str_replace('T', ' ', trim($value));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value)
            ?: DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value);

        if ($date === false) {
            throw new InvalidArgumentException(sprintf('%s는 YYYY-MM-DD HH:MM 형식이어야 합니다.', $field));
        }

        return $date->format('Y-m-d H:i:s');
    }

    private function nonNegativeInt(mixed $value, string $field): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int)$value;
        }

        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException(sprintf('%s는 0 이상의 정수여야 합니다.', $field));
        }

        return $value;
    }

    private function nullableString(mixed $value, int $maxLength, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('%s는 문자열이어야 합니다.', $field));
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (strlen($value) > $maxLength) {
            throw new InvalidArgumentException(sprintf('%s는 %d자 이하여야 합니다.', $field, $maxLength));
        }

        return $value;
    }

    private function minutesBetween(string $startAt, string $endAt): int
    {
        $start = new DateTimeImmutable($startAt);
        $end = new DateTimeImmutable($endAt);

        return (int)(($end->getTimestamp() - $start->getTimestamp()) / 60);
    }
}
