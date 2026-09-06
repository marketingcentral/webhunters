<?php declare(strict_types=1);

namespace App\Core;

/**
 * Immutable snapshot of the current HTTP request. Controllers never touch
 * $_GET/$_POST directly: everything goes through this object.
 */
final class Request
{
    /** @param array<string, mixed> $query @param array<string, mixed> $body @param array<string, string> $server */
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $server,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        $uri  = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?? '/');
        $path = rawurldecode($path);

        // Strip the application base (e.g. /extracthunters/) so routes are root-relative.
        $base = rtrim(Config::baseUrl(), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        // Tolerate a direct hit on index.php and trailing slashes.
        $path = preg_replace('#^/index\.php#', '', $path) ?? $path;
        $path = '/' . trim($path, '/');
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;

        /** @var array<string, string> $server */
        $server = array_filter($_SERVER, 'is_string');

        return new self($method, $path, $_GET, $_POST, $server);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    /** Query-string value, always a trimmed string (or default). */
    public function query(string $key, ?string $default = null): ?string
    {
        $v = $this->query[$key] ?? null;
        return is_scalar($v) ? trim((string)$v) : $default;
    }

    /** POST body value, always a trimmed string (or default). */
    public function input(string $key, ?string $default = null): ?string
    {
        $v = $this->body[$key] ?? null;
        return is_scalar($v) ? trim((string)$v) : $default;
    }

    /** POST body value as an array of strings (for multi-selects/checkbox groups). */
    public function inputArray(string $key): array
    {
        $v = $this->body[$key] ?? [];
        if (!is_array($v)) {
            return [];
        }
        return array_values(array_map(static fn($x) => is_scalar($x) ? trim((string)$x) : '', $v));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->body + $this->query;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? $default;
    }

    public function ip(): string
    {
        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    /**
     * A navegação sem recarga (assets/js/spa.js) pede só os fragmentos da página.
     *
     * É estático de propósito: o Controller monta a resposta sem receber a Request, e
     * inventar um caminho até lá só para ler um cabeçalho seria pior que ler $_SERVER aqui.
     * Cabeçalho próprio (e não X-Requested-With) para não colidir com o fetch de dados
     * das páginas, que já usa Accept: application/json.
     */
    public static function wantsPartial(): bool
    {
        return ($_SERVER['HTTP_X_EH_PARTIAL'] ?? '') === '1';
    }

    public function isAjax(): bool
    {
        return strtolower($this->header('X-Requested-With', '') ?? '') === 'xmlhttprequest';
    }
}
