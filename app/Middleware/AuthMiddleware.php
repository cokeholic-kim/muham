<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Services\AuthService;
use App\Services\SessionService;
use RuntimeException;

final class AuthMiddleware
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function requireUser(): array
    {
        $userId = SessionService::userId();

        if ($userId === null) {
            throw new RuntimeException('로그인이 필요합니다.');
        }

        $user = $this->authService->findById($userId);

        if ($user === null) {
            SessionService::logout();
            throw new RuntimeException('세션 사용자를 찾을 수 없습니다.');
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    public function requireRole(string $role): array
    {
        $user = $this->requireUser();

        if (($user['role'] ?? '') !== $role) {
            throw new RuntimeException('접근 권한이 없습니다.');
        }

        return $user;
    }
}
