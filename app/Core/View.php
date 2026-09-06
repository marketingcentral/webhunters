<?php declare(strict_types=1);

namespace App\Core;

/**
 * Renders a page view inside a layout. Only the whitelisted keys reach the
 * template scope: page, player, nav, server, data, flash, csrf (+ $content in layouts).
 */
final class View
{
    public const ALLOWED = ['page', 'player', 'nav', 'server', 'data', 'flash', 'csrf'];

    /** @var array<string, mixed> Current view scope, shared with partial()/component(). */
    private static array $scope = [];

    /** @var array<string, mixed> Current $page array (for active(), <title>, etc.). */
    private static array $page = [];

    /**
     * @param string $layout   'hud' | 'auth'  -> app/Views/layouts/{layout}.php
     * @param string $pageView 'inicio' | '_404' -> app/Views/pages/{pageView}.php
     * @param array<string, mixed> $data
     */
    public static function render(string $layout, string $pageView, array $data): string
    {
        self::assertName($layout);
        self::assertName($pageView);

        $scope = array_intersect_key($data, array_flip(self::ALLOWED));
        $scope += ['page' => [], 'player' => [], 'nav' => [], 'server' => [], 'data' => [], 'flash' => [], 'csrf' => ''];

        self::$scope = $scope;
        self::$page  = is_array($scope['page']) ? $scope['page'] : [];

        $pageFile   = Config::views() . '/pages/' . $pageView . '.php';
        $layoutFile = Config::views() . '/layouts/' . $layout . '.php';

        if (!is_file($layoutFile)) {
            throw new \RuntimeException('Layout não encontrado: ' . $layout);
        }

        $content = is_file($pageFile)
            ? self::capture($pageFile, $scope)
            : '<!-- pages/' . e($pageView) . '.php ainda não existe -->';

        return self::capture($layoutFile, $scope + ['content' => $content]);
    }

    /** Include a template with an isolated scope and return its output. */
    public static function capture(string $file, array $vars): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                require $__file;
            })($file, $vars);
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
        return (string)ob_get_clean();
    }

    /** Include a partial/component in place (echoes). Missing files become an HTML comment. */
    public static function include(string $dir, string $name, array $vars = []): void
    {
        if (!preg_match('/^[a-z0-9_-]+$/i', $name)) {
            echo '<!-- nome inválido -->';
            return;
        }
        $file = Config::views() . '/' . $dir . '/' . $name . '.php';
        if (!is_file($file)) {
            echo '<!-- ' . e($dir . '/' . $name . '.php') . ' ainda não existe -->';
            return;
        }
        echo self::capture($file, array_replace(self::$scope, $vars));
    }

    public static function exists(string $dir, string $name): bool
    {
        return preg_match('/^[a-z0-9_-]+$/i', $name) === 1
            && is_file(Config::views() . '/' . $dir . '/' . $name . '.php');
    }

    /**
     * Assets de uma página, separados pelo que a navegação sem recarga precisa saber.
     *
     *  - shellCss / shellJs : o casco. Carregado uma vez e sobrevive à troca de página.
     *  - onceJs             : casco também, mas só quando a página pede. `atmosphere` está
     *                         aqui porque o canvas dele é PRESERVADO na troca; avaliar o
     *                         módulo de novo criaria um segundo renderer no mesmo canvas.
     *  - pageCss / pageJs   : entram e saem a cada navegação.
     *
     * Os caminhos saem sem filtro de existência: cada layout decide o que fazer com o que
     * falta (o layout HUD deixa um comentário no HTML, o parcial simplesmente omite).
     *
     * @param array<string, mixed> $page
     * @return array{slug:string, shellCss:list<string>, pageCss:list<string>, shellJs:list<string>, onceJs:list<string>, pageJs:list<string>}
     */
    public static function pageAssets(array $page): array
    {
        $slug  = preg_replace('/[^a-z0-9_-]/', '', (string)($page['slug'] ?? 'pagina')) ?: 'pagina';
        $three = is_array($page['three'] ?? null) ? $page['three'] : [];

        $shellCss = ['fonts/fonts.css', 'css/tokens.css', 'css/base.css', 'css/hud.css', 'css/components.css',
                     'css/animations.css', 'css/topbar.css', 'css/footer.css', 'css/logo.css', 'css/spa.css'];

        $pageCss = ['css/pages/' . $slug . '.css'];
        foreach ((array)($page['css'] ?? []) as $extra) {
            $extra = ltrim((string)$extra, '/');
            $extra = str_starts_with($extra, 'css/') ? $extra : 'css/' . $extra;
            if (!in_array($extra, $shellCss, true) && !in_array($extra, $pageCss, true)) {
                $pageCss[] = $extra;
            }
        }

        $shellJs = ['js/carousel.js'];
        $onceJs  = !empty($three['atmosphere']) ? ['js/three/atmosphere.js'] : [];

        $pageJs = [];
        if (!empty($three['hero'])) { $pageJs[] = 'js/three/hero3d.js'; }
        if (!empty($three['map']))  { $pageJs[] = 'js/three/map3d.js'; }
        $pageJs[] = 'js/pages/' . $slug . '.js';
        foreach ((array)($page['js'] ?? []) as $extra) {
            $extra = ltrim((string)$extra, '/');
            $extra = str_starts_with($extra, 'js/') ? $extra : 'js/' . $extra;
            if (!in_array($extra, $shellJs, true) && !in_array($extra, $onceJs, true) && !in_array($extra, $pageJs, true)) {
                $pageJs[] = $extra;
            }
        }

        return ['slug' => $slug, 'shellCss' => $shellCss, 'pageCss' => $pageCss,
                'shellJs' => $shellJs, 'onceJs' => $onceJs, 'pageJs' => $pageJs];
    }

    /**
     * O import map dos layouts.
     *
     * Além do `three`, ele reescreve os módulos que são importados por caminho RELATIVO
     * (`./spa.js`, `../unreal.js`, `./carousel.js`). Esses nunca passam por `asset()`, então
     * chegariam ao navegador sem o `?v=<mtime>` — e, com cache ligado no servidor, um deploy
     * levaria até a expiração inteira para alcançar o jogador. Mapear a URL resolvida para a
     * versionada resolve sem tocar em nenhum `import` do código.
     *
     * Chave e valor apontam para o MESMO arquivo, então todo mundo continua compartilhando
     * uma única instância do módulo — o que é essencial no caso do spa.js, que guarda o
     * registro de épocas do ciclo de vida.
     *
     * @return string JSON pronto para <script type="importmap">
     */
    public static function importMap(): string
    {
        $relativos = ['js/spa.js', 'js/unreal.js', 'js/carousel.js'];
        $imports = [
            'three'         => asset('vendor/three/build/three.module.js'),
            'three/addons/' => asset('vendor/three/examples/jsm/', false),
        ];
        foreach ($relativos as $rel) {
            if (self::assetExists($rel)) {
                $imports[asset($rel, false)] = asset($rel);
            }
        }
        return (string)json_encode(['imports' => $imports], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    /** O arquivo existe dentro de /assets? */
    public static function assetExists(string $path): bool
    {
        return is_file(Config::assets() . '/' . ltrim($path, '/'));
    }

    /** @return array<string, mixed> */
    public static function page(): array
    {
        return self::$page;
    }

    /** @return array<string, mixed> */
    public static function scope(): array
    {
        return self::$scope;
    }

    private static function assertName(string $name): void
    {
        if (!preg_match('/^[a-z0-9_-]+$/i', $name)) {
            throw new \InvalidArgumentException('Nome de view inválido: ' . $name);
        }
    }
}
