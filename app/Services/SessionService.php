<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Database\Database;
use Throwable;

final class SessionService
{
    private const ABSOLUTE_TIMEOUT_SECONDS = 43200;
    private const IDLE_TIMEOUT_SECONDS = 7200;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secureCookie = Env::get('APP_ENV') === 'production' || self::isHttps();

        session_name('muham_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secureCookie,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    public static function login(int $userId, string $role): void
    {
        self::start();
        self::deleteCurrentDatabaseSession();
        session_regenerate_id(true);

        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = $role;
        $_SESSION['authenticated_at'] = time();
        $_SESSION['last_seen_at'] = time();
        $_SESSION['session_id_hash'] = self::sessionIdHash();

        self::insertDatabaseSession($userId, $role);
    }

    public static function logout(): void
    {
        self::start();
        self::deleteCurrentDatabaseSession();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]
            );
        }

        session_destroy();
    }

    public static function userId(): ?int
    {
        self::start();

        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        if (self::isExpired() || !self::hasValidDatabaseSession()) {
            self::destroySessionOnly();
            return null;
        }

        $_SESSION['last_seen_at'] = time();

        return (int)$_SESSION['user_id'];
    }

    public static function userRole(): ?string
    {
        if (self::userId() === null) {
            return null;
        }

        return isset($_SESSION['user_role']) ? (string)$_SESSION['user_role'] : null;
    }

    public static function revokeUserSessions(int $userId): int
    {
        $statement = Database::statement(
            'DELETE FROM sessions WHERE user_id = :user_id',
            ['user_id' => $userId]
        );

        return $statement->rowCount();
    }

    public static function activeSessionCount(int $userId): int
    {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS count
            FROM sessions
            WHERE user_id = :user_id
              AND expires_at > NOW()
              AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)',
            ['user_id' => $userId]
        );

        return (int)($row['count'] ?? 0);
    }

    private static function isExpired(): bool
    {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }

        $now = time();
        $authenticatedAt = isset($_SESSION['authenticated_at']) ? (int)$_SESSION['authenticated_at'] : $now;
        $lastSeenAt = isset($_SESSION['last_seen_at']) ? (int)$_SESSION['last_seen_at'] : $authenticatedAt;

        return ($now - $authenticatedAt) > self::ABSOLUTE_TIMEOUT_SECONDS
            || ($now - $lastSeenAt) > self::IDLE_TIMEOUT_SECONDS;
    }

    private static function hasValidDatabaseSession(): bool
    {
        $sessionHash = self::sessionIdHash();
        $userId = (int)($_SESSION['user_id'] ?? 0);

        if ($sessionHash === '' || $userId < 1) {
            return false;
        }

        try {
            if (!isset($_SESSION['session_id_hash'])) {
                $_SESSION['session_id_hash'] = $sessionHash;
                self::insertDatabaseSession($userId, (string)($_SESSION['user_role'] ?? 'user'));
                return true;
            }

            $session = Database::fetchOne(
                'SELECT id, user_id, role, expires_at, last_seen_at
                FROM sessions
                WHERE session_id_hash = :session_id_hash
                  AND user_id = :user_id
                LIMIT 1',
                [
                    'session_id_hash' => $sessionHash,
                    'user_id' => $userId,
                ]
            );

            if ($session === null || self::isDatabaseSessionExpired($session)) {
                self::deleteCurrentDatabaseSession();
                return false;
            }

            Database::statement(
                'UPDATE sessions
                SET last_seen_at = NOW()
                WHERE id = :id',
                ['id' => (int)$session['id']]
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $session
     */
    private static function isDatabaseSessionExpired(array $session): bool
    {
        $now = time();
        $expiresAt = strtotime((string)($session['expires_at'] ?? '')) ?: 0;
        $lastSeenAt = strtotime((string)($session['last_seen_at'] ?? '')) ?: 0;

        return $expiresAt <= $now || ($now - $lastSeenAt) > self::IDLE_TIMEOUT_SECONDS;
    }

    private static function insertDatabaseSession(int $userId, string $role): void
    {
        Database::statement(
            'INSERT INTO sessions (
                user_id,
                role,
                session_id_hash,
                ip_address,
                user_agent,
                last_seen_at,
                expires_at
            ) VALUES (
                :user_id,
                :role,
                :session_id_hash,
                :ip_address,
                :user_agent,
                NOW(),
                DATE_ADD(NOW(), INTERVAL 12 HOUR)
            )',
            [
                'user_id' => $userId,
                'role' => $role,
                'session_id_hash' => self::sessionIdHash(),
                'ip_address' => self::clientIp(),
                'user_agent' => self::userAgent(),
            ]
        );
    }

    private static function deleteCurrentDatabaseSession(): void
    {
        $sessionHash = self::sessionIdHash();

        if ($sessionHash === '') {
            return;
        }

        try {
            Database::statement(
                'DELETE FROM sessions WHERE session_id_hash = :session_id_hash',
                ['session_id_hash' => $sessionHash]
            );
        } catch (Throwable) {
        }
    }

    private static function destroySessionOnly(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]
            );
        }

        session_destroy();
    }

    private static function sessionIdHash(): string
    {
        $sessionId = session_id();

        return $sessionId === '' ? '' : hash('sha256', $sessionId);
    }

    private static function clientIp(): ?string
    {
        $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        $ip = trim(explode(',', $ip)[0] ?? '');

        return $ip === '' ? null : substr($ip, 0, 45);
    }

    private static function userAgent(): ?string
    {
        $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

        return $userAgent === '' ? null : substr($userAgent, 0, 255);
    }

    private static function isHttps(): bool
    {
        return (
            ($_SERVER['HTTPS'] ?? '') === 'on' ||
            ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        );
    }
}
