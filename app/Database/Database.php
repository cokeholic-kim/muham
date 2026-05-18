<?php
declare(strict_types=1);

namespace App\Database;

use App\Config\Env;
use PDO;
use PDOStatement;
use Throwable;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            Env::required('DB_HOST'),
            Env::required('DB_PORT'),
            Env::required('DB_DATABASE'),
            Env::required('DB_CHARSET')
        );

        self::$connection = new PDO(
            $dsn,
            Env::required('DB_USERNAME'),
            Env::required('DB_PASSWORD'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        return self::$connection;
    }

    /**
     * @param array<string|int, mixed> $parameters
     */
    public static function statement(string $sql, array $parameters = []): PDOStatement
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute($parameters);

        return $statement;
    }

    /**
     * @param array<string|int, mixed> $parameters
     * @return array<string, mixed>|null
     */
    public static function fetchOne(string $sql, array $parameters = []): ?array
    {
        $row = self::statement($sql, $parameters)->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string|int, mixed> $parameters
     * @return array<int, array<string, mixed>>
     */
    public static function fetchAll(string $sql, array $parameters = []): array
    {
        return self::statement($sql, $parameters)->fetchAll();
    }

    /**
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     * @throws Throwable
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();

        if ($pdo->inTransaction()) {
            return $callback($pdo);
        }

        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}
