<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\AuthService;
use App\Services\SessionService;
use InvalidArgumentException;
use RuntimeException;

final class AuthController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly AuthMiddleware $authMiddleware
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function signup(array $payload): array
    {
        $user = $this->authService->signup(
            $this->stringValue($payload, 'email'),
            $this->stringValue($payload, 'password'),
            $this->stringValue($payload, 'name')
        );

        SessionService::login((int)$user['id'], (string)$user['role']);

        return [
            'status' => 201,
            'body' => [
                'message' => '회원가입이 완료되었습니다.',
                'user' => $user,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function login(array $payload): array
    {
        $user = $this->authService->login(
            $this->stringValue($payload, 'email'),
            $this->stringValue($payload, 'password')
        );

        SessionService::login((int)$user['id'], (string)$user['role']);

        return [
            'status' => 200,
            'body' => [
                'message' => '로그인되었습니다.',
                'user' => $user,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function logout(): array
    {
        SessionService::logout();

        return [
            'status' => 200,
            'body' => [
                'message' => '로그아웃되었습니다.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function me(): array
    {
        return [
            'status' => 200,
            'body' => [
                'user' => $this->authMiddleware->requireUser(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stringValue(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s 값이 필요합니다.', $key));
        }

        return $value;
    }
}
