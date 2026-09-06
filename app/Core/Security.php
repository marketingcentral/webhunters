<?php declare(strict_types=1);

namespace App\Core;

/**
 * Sessions, CSRF, CSP nonce and security headers.
 */
final class Security
{
    private static ?string $nonce = null;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.gc_maxlifetime', '7200');

        session_name(Config::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => Config::baseUrl(),
            'domain'   => '',
            'secure'   => Config::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        if (empty($_SESSION['_created'])) {
            $_SESSION['_created'] = time();
        }
        // Periodic id rotation (every 30 min) mitigates fixation on long sessions.
        if (time() - (int)$_SESSION['_created'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['_created'] = time();
        }
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['_created'] = time();
        }
    }

    public static function destroySession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'],
        ]);
        session_destroy();
    }

    /** Per-request CSP nonce (base64, 128 bits). */
    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        }
        return self::$nonce;
    }

    public static function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::startSession();
        }
        if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::csrfToken()) . '">';
    }

    /**
     * Validates the CSRF token of a POST request (field `_csrf` or header X-CSRF-Token)
     * plus a same-origin check. Throws HttpException(419) on failure.
     */
    public static function checkCsrf(?Request $request = null): void
    {
        $request ??= Request::fromGlobals();
        if (!$request->isPost()) {
            return;
        }
        $sent = $request->input('_csrf') ?? $request->header('X-CSRF-Token') ?? '';
        $real = (string)($_SESSION['_csrf'] ?? '');

        if ($sent === '' || $real === '' || !hash_equals($real, $sent)) {
            throw new HttpException(419, 'Sessão expirada ou token inválido.');
        }

        // Defence in depth: Origin/Referer must be same host when present.
        $origin = $request->header('Origin') ?? $request->header('Referer');
        if ($origin) {
            $host = parse_url($origin, PHP_URL_HOST);
            $self = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
            if ($host !== null && $host !== false && $self !== '' && strcasecmp((string)$host, $self) !== 0) {
                throw new HttpException(419, 'Origem da requisição inválida.');
            }
        }
    }

    /** Sends the CSP (with the per-request nonce) and the static security headers. */
    public static function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        $n = self::nonce();
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$n}'",
            "style-src 'self' 'nonce-{$n}'",
            // SPEC §7 mandates style attributes (progress `style="--v:62%"`, bg `object-position`);
            // nonces cannot cover attributes, so only *attributes* are allowed inline. <style> and
            // <script> elements still require the nonce; no script may ever run inline without it.
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self'",
            "connect-src 'self'",
            "worker-src 'self' blob:",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
        header_remove('X-Powered-By');
        header('Content-Security-Policy: ' . $csp, true);
        header('X-Content-Type-Options: nosniff', true);
        header('X-Frame-Options: DENY', true);
        header('Referrer-Policy: strict-origin-when-cross-origin', true);
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()', true);
        header('Cross-Origin-Opener-Policy: same-origin', true);
        header('Cross-Origin-Resource-Policy: same-origin', true);
        if (Config::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains', true);
        }
    }
}
