<?php declare(strict_types=1);

namespace App\Core;

/**
 * PORTA DA FRENTE DO JOGO — nenhuma tela ou rota de dado responde a visitante anônimo.
 *
 * POR QUE ESTA CLASSE EXISTE
 * current_user_id() tinha um atalho de QA: sem sessão, ele devolvia o MENOR id de `users`
 * ("usuário demo") para a HUD sempre renderizar dado real. O atalho não era limitado a
 * localhost e nada exigia login nas rotas, então, em qualquer ambiente, um visitante sem
 * cookie nenhum recebia:
 *   - GET /hud/dados  -> o arsenal REAL do jogador de menor id (arma, pente, munição);
 *   - GET /perfil     -> o perfil daquele jogador, com nome e números;
 *   - GET /inventario -> o inventário dele;
 *   - e um _csrf válido de qualquer página, o que abria também o caminho de escrita
 *     (ShopController::buy só recusava id <= 0, e o id emprestado é > 0).
 * Efeito colateral grave: derrubar um banido deixava de valer muita coisa — ele não voltava
 * à conta DELE (AccountGuard faz isso certo), mas digitava /inicio e recebia a HUD inteira
 * funcionando como o jogador #1.
 *
 * POR QUE AQUI, E NÃO NO CONTROLLER
 * Mesmo motivo de App\Core\AccountGuard: a checagem tem de valer para TODA requisição, e
 * espalhá-la por 24 controllers é garantir que o próximo controller nasça sem ela. O único
 * ponto por onde tudo passa antes de qualquer controller é o front controller (index.php).
 *
 * DIVISÃO COM AccountGuard
 *   - AccountGuard cuida de quem TEM sessão e não deveria mais ter (banido/suspenso/apagado);
 *   - LoginGuard cuida de quem NÃO TEM sessão e quer entrar assim mesmo.
 */
final class LoginGuard
{
    /**
     * Rotas do jogo abertas a quem ainda não entrou (primeiro segmento do caminho).
     * Lista FECHADA de propósito: rota nova nasce protegida, e só entra aqui quem escrever
     * o nome dela aqui sabendo o que está abrindo.
     */
    private const PUBLICOS = ['', 'login', 'criar-conta', 'recuperar-senha', 'sair'];

    /**
     * Áreas que têm porteiro PRÓPRIO e não podem ser mandadas para o /login do jogador:
     *   - 'admin' -> sessão de operador (App\Controllers\Admin\AdminController::exigirAdmin);
     *   - 'api'   -> chave com escopo no cabeçalho Authorization (/api/v1), sem sessão nenhuma.
     * Mandar um cliente de API para o formulário de login do jogo devolveria HTML 302 no lugar
     * do 401 em JSON que ele sabe ler.
     */
    private const PORTEIRO_PROPRIO = ['admin', 'api'];

    /**
     * Devolve null quando a requisição pode seguir, ou a Response que manda entrar.
     */
    public static function vigiar(Request $request): ?Response
    {
        if (is_authenticated()) {
            return null;
        }

        $caminho  = trim($request->path(), '/');
        $primeiro = explode('/', $caminho)[0] ?? '';

        if (in_array($primeiro, self::PUBLICOS, true) || in_array($primeiro, self::PORTEIRO_PROPRIO, true)) {
            return null;
        }

        $msg = 'Entre na sua conta para continuar.';

        // A navegação sem recarga (assets/js/spa.js) e a HUD dentro da Unreal falam JSON: um
        // 302 para /login viraria HTML injetado no meio da tela. Mesmo critério de
        // AccountGuard::derrubar(), para os dois lados responderem igual ao mesmo cliente.
        if (self::querJson($request, $caminho)) {
            return Response::json(
                ['ok' => false, 'error' => 'sem_sessao', 'message' => $msg, 'redirect' => url('login')],
                401
            );
        }

        flash('error', $msg);
        return Response::redirect(url('login'));
    }

    /** Cliente que espera JSON: SPA, fetch da HUD in-game e as rotas `.../dados`. */
    private static function querJson(Request $request, string $caminho): bool
    {
        return Request::wantsPartial()
            || $request->isAjax()
            || $caminho === 'hud'
            || str_starts_with($caminho, 'hud/')
            || str_ends_with($caminho, '/dados');
    }
}
