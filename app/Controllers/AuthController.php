<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\AuditLogService;
use App\Services\AuthService;
use App\Services\LoginAttemptService;
use App\Services\RememberMeService;
use App\Services\SessionService;
use InvalidArgumentException;
use RuntimeException;

final class AuthController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly AuthMiddleware $authMiddleware,
        private readonly AuditLogService $auditLogService,
        private readonly LoginAttemptService $loginAttemptService,
        private readonly RememberMeService $rememberMeService,
        /** @var array<string, string|null> */
        private readonly array $requestContext
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
        $this->auditLogService->record(
            (int)$user['id'],
            (int)$user['id'],
            'signup',
            'user',
            (int)$user['id'],
            null,
            [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name'],
                'role' => $user['role'],
            ],
            $this->requestContext
        );

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
        $email = $this->stringValue($payload, 'email');
        $sourceIp = (string)($this->requestContext['request_ip'] ?? '');

        try {
            $this->loginAttemptService->assertAllowed($email, $sourceIp);
        } catch (RuntimeException $e) {
            $this->auditLogService->record(
                null,
                null,
                'login_blocked',
                'user',
                null,
                null,
                [
                    'email' => strtolower(trim($email)),
                    'result' => 'blocked',
                    'reason' => $e->getMessage(),
                ],
                $this->requestContext
            );

            throw $e;
        }

        try {
            $user = $this->authService->login($email, $this->stringValue($payload, 'password'));
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->loginAttemptService->recordFailure($email, $sourceIp, $e->getMessage());
            $this->auditLogService->record(
                null,
                null,
                'login_failed',
                'user',
                null,
                null,
                [
                    'email' => strtolower(trim($email)),
                    'result' => 'failed',
                    'reason' => $e->getMessage(),
                ],
                $this->requestContext
            );

            throw $e;
        }

        $this->loginAttemptService->recordSuccess($email, $sourceIp);
        SessionService::login((int)$user['id'], (string)$user['role']);

        if ($this->rememberRequested($payload)) {
            $this->rememberMeService->issueToken($user);
        }

        $this->auditLogService->record(
            (int)$user['id'],
            (int)$user['id'],
            'login',
            'user',
            (int)$user['id'],
            null,
            [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $user['role'],
                'result' => 'success',
                'remember' => $this->rememberRequested($payload),
            ],
            $this->requestContext
        );

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
        $user = null;
        $userId = SessionService::userId();

        if ($userId !== null) {
            $user = $this->authService->findById($userId);
        }

        if ($user !== null) {
            $this->auditLogService->record(
                (int)$user['id'],
                (int)$user['id'],
                'logout',
                'user',
                (int)$user['id'],
                null,
                [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'result' => 'success',
                ],
                $this->requestContext
            );
        }

        $this->rememberMeService->clearCurrentToken();
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

    /**
     * @param array<string, mixed> $payload
     */
    private function rememberRequested(array $payload): bool
    {
        return isset($payload['rememberMe']) && (string)$payload['rememberMe'] === '1';
    }
}
