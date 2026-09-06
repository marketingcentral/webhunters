<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Security;
use App\Core\View;

/**
 * Base controller: builds the $page contract (SPEC §4) and renders through View.
 */
abstract class Controller
{
    /** Default background per slug (SPEC §9). */
    private const BG = [
        'login'            => 'bg/login.jpg',
        'criar-conta'      => 'bg/login.jpg',
        'recuperar-senha'  => 'bg/login.jpg',
        'inicio'           => 'bg/home-hero.jpg',
        'jogar'            => 'bg/forest-generic.jpg',
        'lobby'            => 'bg/lobby.jpg',
        'configuracoes'    => 'bg/settings.jpg',
        'inventario'       => 'bg/inventario.jpg',
        'saude'            => 'bg/saude.jpg',
        'habilidades'      => 'bg/forest-generic.jpg',
        'mapa'             => 'bg/forest-generic.jpg',
        'conquistas'       => 'bg/conquistas.jpg',
        'missoes'          => 'bg/forest-generic.jpg',
        'mapas'            => 'bg/jogar-banner.jpg',
        'desafios'         => 'bg/forest-generic.jpg',
        'noticias'         => 'bg/menu.jpg',
        'loja'             => 'bg/menu.jpg',
        'amigos'           => 'bg/lobby.jpg',
        'mensagens'        => 'bg/menu.jpg',
        'perfil'           => 'bg/home-hero.jpg',
        'suporte'          => 'bg/forest-generic.jpg',
        'relatorio-de-bug' => 'bg/forest-generic.jpg',
        '404'              => 'bg/forest-generic.jpg',
    ];

    /** Which topbar item lights up for slugs that are not themselves in the nav. */
    private const NAV_OF = [
        'jogar'    => 'inicio',
        'lobby'    => 'inicio',
        'mapas'    => 'mapa',
        'desafios' => 'missoes',
        'noticias' => 'inicio',
    ];

    /**
     * Builds the $page array with sane defaults; $over wins.
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    protected function page(string $slug, string $title, array $over = []): array
    {
        $back = $slug === 'inicio'
            ? ['label' => 'SAIR', 'href' => null]
            : ['label' => 'VOLTAR', 'href' => url('inicio')];

        $defaults = [
            'slug'    => $slug,
            'title'   => $title,
            'bg'      => self::BG[$slug] ?? 'bg/forest-generic.jpg',
            'bg_pos'  => 'center',
            'nav'     => self::NAV_OF[$slug] ?? $slug,
            'back'    => $back,
            'css'     => [],
            'js'      => [],
            'three'   => ['atmosphere' => true, 'hero' => false, 'map' => false],
            'description' => '',
        ];
        $page = array_replace($defaults, $over);
        $page['three'] = array_replace($defaults['three'], is_array($over['three'] ?? null) ? $over['three'] : []);
        return $page;
    }

    /**
     * Renders pages/{view}.php inside layouts/{layout}.php with the full contract scope.
     *
     * Quando o pedido vem de assets/js/spa.js (navegação sem recarga), o mesmo controller
     * e a mesma view são usados — só o layout muda, para layouts/partial.php, que devolve
     * JSON com os fragmentos. Nenhum controller precisa saber disso.
     *
     * Só o layout 'hud' tem versão parcial: 'auth' e 'overlay' têm outro casco, e o cliente
     * reconhece isso pelo campo `layout` e cai para navegação normal.
     *
     * @param array<string, mixed> $page @param array<string, mixed> $data
     */
    protected function render(string $view, array $page, array $data = [], string $layout = 'hud', int $status = 200): Response
    {
        $parcial = $layout === 'hud' && Request::wantsPartial();

        $html = View::render($parcial ? 'partial' : $layout, $view, [
            'page'   => $page,
            'player' => $this->player(),
            'nav'    => data_mock('nav'),
            'server' => data_mock('server'),
            'data'   => $data,
            'flash'  => flash_all(),
            'csrf'   => Security::csrfToken(),
        ]);

        if ($parcial) {
            // Vary: um proxy/cache não pode servir o JSON para uma navegação normal.
            return (new Response($html, $status, ['Content-Type' => 'application/json; charset=UTF-8']))
                ->withHeader('Vary', 'X-EH-Partial');
        }
        return Response::html($html, $status);
    }


    /**
     * Jogador atual, conciliado por user_id (fallback: usuário demo). Lê do banco; se o banco
     * estiver indisponível cai para o mock estático, mantendo a HUD navegável.
     */
    protected function player(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $uid = current_user_id();
            if ($uid > 0) {
                $p = \App\Repo\PlayerRepo::forUser($uid);
                if ($p !== null) {
                    return $cache = $p;
                }
            }
        } catch (\Throwable) {
            // banco indisponível — usa o mock abaixo
        }
        return $cache = data_mock('player');
    }

    /** ID do jogador atual (0 se o banco não estiver instalado). */
    protected function userId(): int
    {
        return current_user_id();
    }

    protected function isLogged(): bool
    {
        return is_authenticated();
    }

    protected function redirect(string $path, array $q = []): Response
    {
        return Response::redirect(url($path, $q));
    }

    /** Validates an optional route param against a whitelist; null when absent, 404 when unknown. */
    protected function pick(?string $value, array $allowed, ?string $default = null): ?string
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!in_array($value, $allowed, true)) {
            throw new \App\Core\HttpException(404, 'Página não encontrada');
        }
        return $value;
    }

    protected function csrf(Request $request): void
    {
        Security::checkCsrf($request);
    }
}
