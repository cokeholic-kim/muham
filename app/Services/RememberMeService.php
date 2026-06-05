<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Database\Database;
use PDO;
use Throwable;

final class RememberMeService
{
    private const COOKIE_NAME = 'muham_remember';
    private const TOKEN_TTL_SECONDS = 2592000;

    /**
     * @param array<string, mixed> $user
     */
    public function issueToken(array $user): void
    {
        $selector = bin2hex(random_bytes(16));
        $token = $this->randomToken();

        Database::statement(
            'INSERT INTO remember_tokens (
                user_id,
                selector,
                token_hash,
                expires_at
            ) VALUES (
                :user_id,
                :selector,
                :token_hash,
                DATE_ADD(NOW(), INTERVAL 30 DAY)
            )',
            [
                'user_id' => (int)$user['id'],
                'selector' => $selector,
                'token_hash' => $this->tokenHash($token),
            ]
        );

        $this->setCookie($selector, $token);
    }

    public function attemptAutoLogin(AuthService $authService, AuditLogService $auditLogService, array $requestContext): bool
    {
        if (SessionService::userId() !== null) {
            return false;
        }

        $cookie = $this->cookieParts();

        if ($cookie === null) {
            return false;
        }

        [$selector, $token] = $cookie;

        try {
            return Database::transaction(function (PDO $pdo) use ($selector, $token, $authService, $auditLogService, $requestContext): bool {
                $statement = $pdo->prepare(
                    'SELECT id, user_id, token_hash, expires_at
                    FROM remember_tokens
                    WHERE selector = :selector
                    LIMIT 1
                    FOR UPDATE'
                );
                $statement->execute(['selector' => $selector]);
                $rememberToken = $statement->fetch(PDO::FETCH_ASSOC);

                if (!is_array($rememberToken) || $this->isExpired((string)$rememberToken['expires_at'])) {
                    $this->deleteTokenBySelector($pdo, $selector);
                    $this->clearCookie();
                    return false;
                }

                if (!hash_equals((string)$rememberToken['token_hash'], $this->tokenHash($token))) {
                    $this->deleteTokenBySelector($pdo, $selector);
                    $this->clearCookie();
                    return false;
                }

                $user = $authService->findById((int)$rememberToken['user_id']);

                if ($user === null) {
                    $this->deleteTokenBySelector($pdo, $selector);
                    $this->clearCookie();
                    return false;
                }

                SessionService::login((int)$user['id'], (string)$user['role']);
                $this->rotateToken($pdo, (int)$rememberToken['id']);
                $auditLogService->recordInTransaction(
                    $pdo,
                    (int)$user['id'],
                    (int)$user['id'],
                    'remember_login',
                    'user',
                    (int)$user['id'],
                    null,
                    [
                        'id' => (int)$user['id'],
                        'email' => (string)$user['email'],
                        'role' => (string)$user['role'],
                        'result' => 'success',
                    ],
                    $requestContext
                );

                return true;
            });
        } catch (Throwable) {
            $this->clearCookie();
            return false;
        }
    }

    public function clearCurrentToken(): void
    {
        $cookie = $this->cookieParts();

        if ($cookie !== null) {
            [$selector] = $cookie;

            try {
                Database::statement(
                    'DELETE FROM remember_tokens WHERE selector = :selector',
                    ['selector' => $selector]
                );
            } catch (Throwable) {
            }
        }

        $this->clearCookie();
    }

    public function revokeUserTokens(int $userId): int
    {
        $statement = Database::statement(
            'DELETE FROM remember_tokens WHERE user_id = :user_id',
            ['user_id' => $userId]
        );

        return $statement->rowCount();
    }

    public function activeTokenCount(int $userId): int
    {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS count
            FROM remember_tokens
            WHERE user_id = :user_id
              AND expires_at > NOW()',
            ['user_id' => $userId]
        );

        return (int)($row['count'] ?? 0);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function cookieParts(): ?array
    {
        $value = $_COOKIE[self::COOKIE_NAME] ?? null;

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $parts = explode(':', $value, 2);

        if (count($parts) !== 2 || !ctype_xdigit($parts[0]) || strlen($parts[0]) !== 32 || $parts[1] === '') {
            return null;
        }

        return [$parts[0], $parts[1]];
    }

    private function rotateToken(PDO $pdo, int $id): void
    {
        $selector = bin2hex(random_bytes(16));
        $token = $this->randomToken();
        $statement = $pdo->prepare(
            'UPDATE remember_tokens
            SET selector = :selector,
                token_hash = :token_hash,
                expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY),
                last_used_at = NOW()
            WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'selector' => $selector,
            'token_hash' => $this->tokenHash($token),
        ]);

        $this->setCookie($selector, $token);
    }

    private function deleteTokenBySelector(PDO $pdo, string $selector): void
    {
        $statement = $pdo->prepare('DELETE FROM remember_tokens WHERE selector = :selector');
        $statement->execute(['selector' => $selector]);
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function isExpired(string $expiresAt): bool
    {
        $timestamp = strtotime($expiresAt) ?: 0;

        return $timestamp <= time();
    }

    private function setCookie(string $selector, string $token): void
    {
        setcookie(
            self::COOKIE_NAME,
            $selector . ':' . $token,
            [
                'expires' => time() + self::TOKEN_TTL_SECONDS,
                'path' => '/',
                'domain' => '',
                'secure' => $this->secureCookie(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        $_COOKIE[self::COOKIE_NAME] = $selector . ':' . $token;
    }

    private function clearCookie(): void
    {
        setcookie(
            self::COOKIE_NAME,
            '',
            [
                'expires' => time() - 42000,
                'path' => '/',
                'domain' => '',
                'secure' => $this->secureCookie(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        unset($_COOKIE[self::COOKIE_NAME]);
    }

    private function secureCookie(): bool
    {
        return Env::get('APP_ENV') === 'production'
            || ($_SERVER['HTTPS'] ?? '') === 'on'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
