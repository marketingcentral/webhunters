<?php declare(strict_types=1);

namespace App\Core;

/**
 * Static configuration derived from the environment (no .env is read on purpose:
 * the front-end has no database and secrets must never reach the web tier).
 */
final class Config
{
    public const APP_NAME    = 'Extract Hunters';
    public const SESSION_NAME = 'EHSESSID';

    /** @var array<string, mixed> */
    private static array $values = [];

    public static function boot(string $rootDir): void
    {
        $root = rtrim(str_replace('\\', '/', $rootDir), '/');

        $script  = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $baseUrl = rtrim(str_replace('\\', '/', dirname($script)), '/') . '/';

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) === '443')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        $host  = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $debug = in_array(preg_replace('/:\d+$/', '', $host), ['localhost', '127.0.0.1', '::1'], true);

        self::$values = [
            'root'       => $root,
            'app'        => $root . '/app',
            'views'      => $root . '/app/Views',
            'data'       => $root . '/app/Data',
            'assets'     => $root . '/assets',
            'base_url'   => $baseUrl,
            'https'      => $https,
            'debug'      => $debug,
            'app_name'   => self::APP_NAME,
            'locale'     => 'pt_BR',
            'timezone'   => 'America/Sao_Paulo',
        ];

        if (!defined('BASE_URL')) {
            define('BASE_URL', $baseUrl);
        }
        if (!defined('ROOT_DIR')) {
            define('ROOT_DIR', $root);
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$values[$key] ?? $default;
    }

    public static function root(): string
    {
        return (string)self::$values['root'];
    }

    public static function views(): string
    {
        return (string)self::$values['views'];
    }

    public static function data(): string
    {
        return (string)self::$values['data'];
    }

    public static function assets(): string
    {
        return (string)self::$values['assets'];
    }

    public static function baseUrl(): string
    {
        return (string)self::$values['base_url'];
    }

    public static function isHttps(): bool
    {
        return (bool)self::$values['https'];
    }

    public static function debug(): bool
    {
        return (bool)self::$values['debug'];
    }
}
