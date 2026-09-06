<?php declare(strict_types=1);

namespace App\Core;

/**
 * Thrown anywhere in the request cycle to short-circuit into an HTTP error page
 * (404 not found, 405 method not allowed, 419 CSRF/session expired, ...).
 */
final class HttpException extends \RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $status,
        string $message = '',
        private readonly array $headers = [],
    ) {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
