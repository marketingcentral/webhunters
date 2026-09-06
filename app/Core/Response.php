<?php declare(strict_types=1);

namespace App\Core;

/**
 * Tiny value object for an HTTP response. Security headers are appended on send().
 */
final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8'] + $headers);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public static function json(array $payload, int $status = 200): self
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new self($json, $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /** Redirect to an application path (use url()) or an absolute same-origin URL. 303 for POST -> GET. */
    public static function redirect(string $location, int $status = 303): self
    {
        // Only same-origin relative/absolute-path locations are allowed (no open redirects).
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $location)) {
            $location = url('inicio');
        }
        return new self('', $status, ['Location' => $location]);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** Reason phrases: Apache (mod_php) turns unknown codes into 500 unless a full status line is given. */
    private const REASON = [
        200 => 'OK', 201 => 'Created', 204 => 'No Content', 301 => 'Moved Permanently', 302 => 'Found',
        303 => 'See Other', 304 => 'Not Modified', 307 => 'Temporary Redirect', 400 => 'Bad Request',
        401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed',
        410 => 'Gone', 419 => 'Page Expired', 422 => 'Unprocessable Content', 429 => 'Too Many Requests',
        500 => 'Internal Server Error', 503 => 'Service Unavailable',
    ];

    public function send(): void
    {
        if (!headers_sent()) {
            $protocol = (string)($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1');
            if (!preg_match('#^HTTP/\d(\.\d)?$#', $protocol)) {
                $protocol = 'HTTP/1.1';
            }
            header(sprintf('%s %d %s', $protocol, $this->status, self::REASON[$this->status] ?? 'Unknown'), true, $this->status);
            Security::sendHeaders();
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
            if (!isset($this->headers['Cache-Control'])) {
                header('Cache-Control: no-store, max-age=0', true);
            }
        }
        echo $this->body;
    }
}
