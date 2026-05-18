<?php
declare(strict_types=1);

namespace App\Database;

use App\Config\Env;
use PDO;
use Throwable;

final class HealthCheck
{
    /**
     * @return array<string, string>
     */
    public static function run(): array
    {
        $status = 'failed';
        $message = '';
        $dbVersion = '';

        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                Env::get('DB_HOST', 'mysql'),
                Env::get('DB_PORT', '3306'),
                Env::get('DB_DATABASE', 'muham_worktime'),
                Env::get('DB_CHARSET', 'utf8mb4')
            );

            $pdo = new PDO($dsn, Env::get('DB_USERNAME', 'muham'), Env::get('DB_PASSWORD', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $stmt = $pdo->query('SELECT VERSION() AS version, DATABASE() AS database_name');
            $row = $stmt === false ? [] : $stmt->fetch();

            $status = 'ok';
            $dbVersion = (string)($row['version'] ?? '');
            $message = 'PDO MySQL connection is ready.';
        } catch (Throwable $e) {
            $message = $e->getMessage();
        }

        return [
            'status' => $status,
            'message' => $message,
            'php_version' => PHP_VERSION,
            'pdo_loaded' => extension_loaded('pdo') ? 'yes' : 'no',
            'pdo_mysql_loaded' => extension_loaded('pdo_mysql') ? 'yes' : 'no',
            'database_host' => Env::get('DB_HOST', 'mysql') . ':' . Env::get('DB_PORT', '3306'),
            'database_name' => Env::get('DB_DATABASE', 'muham_worktime'),
            'mysql_version' => $dbVersion !== '' ? $dbVersion : '-',
            'timezone' => date_default_timezone_get(),
            'checked_at' => date('Y-m-d H:i:s'),
        ];
    }
}
