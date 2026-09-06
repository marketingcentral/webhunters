<?php declare(strict_types=1);

/**
 * Global view helpers (SPEC §3). Loaded once by app/bootstrap.php.
 */

use App\Core\Config;
use App\Core\Security;
use App\Core\View;

if (!function_exists('e')) {
    /** HTML-escape any scalar for safe output. Arrays/objects become an empty string. */
    function e(mixed $v): string
    {
        if ($v === null || is_array($v) || (is_object($v) && !$v instanceof Stringable)) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? '1' : '';
        }
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /** BASE_URL + friendly path (+ optional query, rawurlencoded). url('mapa/floresta-verde', ['x' => 1]) */
    function url(string $path = '', array $q = []): string
    {
        $path = ltrim($path, '/');
        $out  = Config::baseUrl() . $path;
        if ($q !== []) {
            $out .= '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
        }
        return $out;
    }
}

if (!function_exists('asset')) {
    /**
     * BASE_URL/assets/{path}?v={filemtime}. Directories (e.g. importmap prefixes) and
     * missing files get no version. Pass $version = false to skip cache-busting
     * (needed for font preloads that must match fonts.css URLs byte-for-byte).
     */
    function asset(string $path, bool $version = true): string
    {
        static $cache = [];
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $key  = $path . ($version ? '#v' : '');
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $url  = Config::baseUrl() . 'assets/' . $path;
        if ($version && !str_ends_with($path, '/')) {
            $file = Config::assets() . '/' . $path;
            if (is_file($file)) {
                $mtime = @filemtime($file);
                if ($mtime !== false) {
                    $url .= '?v=' . $mtime;
                }
            }
        }
        return $cache[$key] = $url;
    }
}

if (!function_exists('img')) {
    function img(string $path): string
    {
        return asset('img/' . ltrim($path, '/'));
    }
}

if (!function_exists('media')) {
    /**
     * URL de mídia dinâmica (conteúdo vindo do banco): BASE_URL/media/{path}?v={filemtime}.
     * As linhas do catálogo guardam caminhos como "items/ak103.png"; a view resolve com media().
     * Cai de volta para assets/img/ quando o arquivo ainda não foi copiado para /media.
     */
    function media(string $path): string
    {
        static $cache = [];
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (isset($cache[$path])) {
            return $cache[$path];
        }
        $root = Config::root();
        $file = $root . '/media/' . $path;
        if (is_file($file)) {
            $mtime = @filemtime($file);
            return $cache[$path] = Config::baseUrl() . 'media/' . $path . ($mtime !== false ? '?v=' . $mtime : '');
        }
        // fallback: mídia ainda vive em assets/img/
        return $cache[$path] = img($path);
    }
}

if (!function_exists('icon')) {
    /** Material Symbols Outlined ligature: <span class="ms {class}" aria-hidden="true">{name}</span> */
    function icon(string $name, string $class = '', array $attrs = []): string
    {
        $name  = preg_replace('/[^a-z0-9_]/', '', strtolower($name)) ?? '';
        $cls   = trim('ms ' . $class);
        $extra = '';
        foreach ($attrs as $k => $v) {
            $k = preg_replace('/[^a-zA-Z0-9_:-]/', '', (string)$k) ?? '';
            if ($k === '' || $k === 'class') {
                continue;
            }
            $extra .= ' ' . $k . '="' . e($v) . '"';
        }
        if (!isset($attrs['aria-label']) && !isset($attrs['role'])) {
            $extra .= ' aria-hidden="true"';
        }
        return '<span class="' . e($cls) . '"' . $extra . '>' . e($name) . '</span>';
    }
}

if (!function_exists('svg')) {
    /** Sprite glyph: <svg class="ic {class}"><use href="…/icons/sprite.svg#{id}"/></svg> */
    function svg(string $id, string $class = ''): string
    {
        $id  = preg_replace('/[^a-z0-9_-]/', '', strtolower($id)) ?? '';
        $cls = trim('ic ' . $class);
        return '<svg class="' . e($cls) . '" aria-hidden="true" focusable="false"><use href="'
            . e(asset('icons/sprite.svg') . '#' . $id) . '"/></svg>';
    }
}

if (!function_exists('component')) {
    /** Includes app/Views/components/{name}.php with $props (+ extracted keys, + view scope). */
    function component(string $name, array $props = []): void
    {
        View::include('components', $name, $props + ['props' => $props]);
    }
}

if (!function_exists('partial')) {
    /** Includes app/Views/partials/{name}.php with the view scope + $vars. */
    function partial(string $name, array $vars = []): void
    {
        View::include('partials', $name, $vars);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Security::csrfField();
    }
}

if (!function_exists('nonce')) {
    function nonce(): string
    {
        return Security::nonce();
    }
}

if (!function_exists('flash')) {
    /**
     * flash('ok', 'Mensagem enviada.')  -> stores for the next request
     * flash('ok')                       -> returns and consumes (null if none)
     */
    function flash(string $key, ?string $msg = null): ?string
    {
        static $consumed = [];
        if ($msg !== null) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                Security::startSession();
            }
            $_SESSION['_flash'][$key] = $msg;
            return null;
        }
        if (isset($_SESSION['_flash'][$key])) {
            $consumed[$key] = (string)$_SESSION['_flash'][$key];
            unset($_SESSION['_flash'][$key]);
            if (empty($_SESSION['_flash'])) {
                unset($_SESSION['_flash']);
            }
        }
        return $consumed[$key] ?? null;
    }
}

if (!function_exists('flash_all')) {
    /** Consumes every pending flash message: ['ok' => '...', 'error' => '...']. */
    function flash_all(): array
    {
        $out = [];
        foreach (array_keys($_SESSION['_flash'] ?? []) as $key) {
            $out[$key] = flash((string)$key);
        }
        return $out;
    }
}

if (!function_exists('active')) {
    /** ' is-active' when the current $page['slug'] (or $page['nav']) equals $slug. */
    function active(string $slug): string
    {
        $page = View::page();
        $cur  = (string)($page['nav'] ?? $page['slug'] ?? '');
        return $cur === $slug ? ' is-active' : '';
    }
}

if (!function_exists('num')) {
    /** pt-BR number: num(125500) = "125.500", num(2.5, 1) = "2,5" */
    function num(int|float $n, int $dec = 0): string
    {
        return number_format((float)$n, $dec, ',', '.');
    }
}

if (!function_exists('attr_class')) {
    /** Joins class names, dropping empties: attr_class('btn', $hot ? 'btn--hot' : '') */
    function attr_class(string ...$classes): string
    {
        return implode(' ', array_filter(array_map('trim', $classes), static fn($c) => $c !== ''));
    }
}

if (!function_exists('data_mock')) {
    /** Loads app/Data/{name}.php (returns array). Cached per request. */
    function data_mock(string $name): array
    {
        static $cache = [];
        if (!preg_match('/^[a-z0-9_-]+$/', $name)) {
            return [];
        }
        if (!isset($cache[$name])) {
            $file = Config::data() . '/' . $name . '.php';
            $cache[$name] = is_file($file) ? (array)(require $file) : [];
        }
        return $cache[$name];
    }
}

if (!function_exists('current_user_id')) {
    /**
     * ID do usuário logado (sessão). Sem login, cai para o usuário demo (menor id) para que
     * a HUD sempre renderize dados reais do banco durante navegação/QA. Retorna 0 se o banco
     * ainda não estiver instalado.
     */
    function current_user_id(): int
    {
        $sess = (int)($_SESSION['user_id'] ?? 0);
        if ($sess > 0) {
            return $sess;
        }
        static $demo = null;
        if ($demo === null) {
            try {
                $demo = \App\Core\Database::ready()
                    ? (int)\App\Core\Database::value('SELECT id FROM users ORDER BY id LIMIT 1')
                    : 0;
            } catch (\Throwable) { $demo = 0; }
        }
        return (int)$demo;
    }
}

if (!function_exists('is_authenticated')) {
    /** Verdadeiro só quando há login real na sessão (não o fallback demo). */
    function is_authenticated(): bool
    {
        return (int)($_SESSION['user_id'] ?? 0) > 0;
    }
}
