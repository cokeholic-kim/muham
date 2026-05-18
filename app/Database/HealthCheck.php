<?php
declare(strict_types=1);

namespace App\Database;

use App\Config\Env;
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
            $row = Database::fetchOne('SELECT VERSION() AS version, DATABASE() AS database_name');

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
            'database_host' => Env::get('DB_HOST', '-') . ':' . Env::get('DB_PORT', '-'),
            'database_name' => Env::get('DB_DATABASE', '-'),
            'mysql_version' => $dbVersion !== '' ? $dbVersion : '-',
            'timezone' => date_default_timezone_get(),
            'checked_at' => date('Y-m-d H:i:s'),
        ];
    }
}
