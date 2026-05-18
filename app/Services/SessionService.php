<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Env;

final class SessionService
{
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
        session_regenerate_id(true);

        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = $role;
        $_SESSION['authenticated_at'] = time();
    }

    public static function logout(): void
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

    public static function userId(): ?int
    {
        self::start();

        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    public static function userRole(): ?string
    {
        self::start();

        return isset($_SESSION['user_role']) ? (string)$_SESSION['user_role'] : null;
    }

    private static function isHttps(): bool
    {
        return (
            ($_SERVER['HTTPS'] ?? '') === 'on' ||
            ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        );
    }
}
