<?php declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Thin PDO wrapper (singleton). Credentials come from app/db.local.php when present
 * (git-ignored, blocked by .htaccess like the rest of app/), otherwise fall back to
 * the WAMP default: local MySQL, user root, no password, database extracthunters.
 *
 * Only the application tier ever touches this — never a view. Every query is
 * parameterised; callers pass bound values, never string-interpolated SQL.
 */
final class Database
{
    private static ?PDO $pdo = null;

    /** @return array{host:string,port:int,name:string,user:string,pass:string,charset:string} */
    private static function config(): array
    {
        $defaults = [
            'host'    => '127.0.0.1',
            'port'    => 3306,
            'name'    => 'extracthunters',
            'user'    => 'root',
            'pass'    => '',
            'charset' => 'utf8mb4',
        ];
        $file = Config::root() . '/app/db.local.php';
        if (is_file($file)) {
            /** @var array<string,mixed> $override */
            $override = require $file;
            return array_replace($defaults, array_intersect_key($override, $defaults));
        }
        return $defaults;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $c = self::config();
        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset={$c['charset']}";
        try {
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Falha ao conectar ao banco de dados.', 0, $e);
        }
        return self::$pdo;
    }

    /** True when the schema is installed (players table exists). */
    public static function ready(): bool
    {
        try {
            self::pdo()->query('SELECT 1 FROM players LIMIT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @param array<string,mixed> $params @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $params @return list<array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @param array<string,mixed> $params */
    public static function value(string $sql, array $params = []): mixed
    {
        return self::run($sql, $params)->fetchColumn();
    }

    /** Insert one row into $table from an associative array; returns the new id. */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $ph   = array_map(static fn(string $c): string => ':' . $c, $cols);
        $sql  = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', $ph) . ')';
        self::run($sql, $data);
        return (int)self::pdo()->lastInsertId();
    }

    public static function beginTransaction(): void { self::pdo()->beginTransaction(); }
    public static function commit(): void { self::pdo()->commit(); }
    public static function rollBack(): void { if (self::pdo()->inTransaction()) { self::pdo()->rollBack(); } }

    /** @param callable():mixed $fn */
    public static function transaction(callable $fn): mixed
    {
        self::beginTransaction();
        try {
            $result = $fn();
            self::commit();
            return $result;
        } catch (\Throwable $e) {
            self::rollBack();
            throw $e;
        }
    }
}
