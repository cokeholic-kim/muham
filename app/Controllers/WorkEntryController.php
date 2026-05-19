<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\WorkEntryService;
use InvalidArgumentException;

final class WorkEntryController
{
    public function __construct(
        private readonly WorkEntryService $workEntryService,
        private readonly AuthMiddleware $authMiddleware
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $entry = $this->workEntryService->create($this->authMiddleware->requireUser(), $payload);

        return [
            'status' => 201,
            'body' => [
                'message' => '근무 기록이 생성되었습니다.',
                'entry' => $entry,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function list(array $query): array
    {
        return [
            'status' => 200,
            'body' => [
                'entries' => $this->workEntryService->list($this->authMiddleware->requireUser(), $query),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function summary(array $query): array
    {
        return [
            'status' => 200,
            'body' => [
                'summary' => $this->workEntryService->summary($this->authMiddleware->requireUser(), $query),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        return [
            'status' => 200,
            'body' => [
                'message' => '근무 기록이 수정되었습니다.',
                'entry' => $this->workEntryService->update($this->authMiddleware->requireUser(), $id, $payload),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        return [
            'status' => 200,
            'body' => [
                'message' => '근무 기록이 삭제되었습니다.',
                'entry' => $this->workEntryService->delete($this->authMiddleware->requireUser(), $id),
            ],
        ];
    }

    public static function routeId(string $path, string $prefix): ?int
    {
        if (!str_starts_with($path, $prefix)) {
            return null;
        }

        $id = substr($path, strlen($prefix));

        if ($id === '' || !ctype_digit($id) || (int)$id < 1) {
            throw new InvalidArgumentException('유효한 근무 기록 ID가 필요합니다.');
        }

        return (int)$id;
    }
}
