<?php declare(strict_types=1);

namespace App\Core;

use App\Repo\BanRepo;

/**
 * PORTA DE ENTRADA DA CONTA — um lugar só decide se um usuário pode estar dentro do jogo.
 *
 * POR QUE ESTA CLASSE EXISTE
 * Antes dela, `users.status` era escrito pelo painel e NUNCA lido pelo jogo: o login só
 * conferia a senha (App\Controllers\AuthController::login -> UserRepo::findByLogin) e
 * is_authenticated() só olhava $_SESSION['user_id']. Resultado: banir não banía. A conta
 * punida entrava normalmente, e quem já estava logado seguia jogando até a sessão expirar.
 *
 * POR QUE AQUI, E NÃO NO CONTROLLER
 * A verificação tem de acontecer ANTES da ação, em TODA requisição autenticada — inclusive
 * nos POST que gravam (comprar na loja, mover item, responder mensagem). Espalhar a checagem
 * por 24 controllers é garantir que o próximo controller nasça sem ela. O único ponto por
 * onde toda requisição passa antes de qualquer controller é o front controller (index.php),
 * e é de lá que vigiar() é chamada. O painel /admin tem a guarda equivalente no construtor
 * de App\Controllers\Admin\AdminController (lá o Router instancia a classe base de todas as
 * telas, então o construtor É o ponto único daquele lado).
 *
 * CUSTO
 * Uma consulta por requisição para todo jogador seria desperdício: o estado da conta muda
 * raríssimas vezes por dia. Então:
 *   - GET  -> consulta no máximo a cada TTL_SEGUNDOS (carimbo em $_SESSION['auth_seen']);
 *   - POST -> consulta SEMPRE, porque POST escreve no mundo e a janela de tolerância que é
 *             aceitável para ler uma tela não é aceitável para gastar créditos de um banido.
 * Na prática: navegação normal custa ~1 consulta a cada 30 s, e a punição chega em no
 * máximo 30 s (imediatamente, se a pessoa tentar fazer qualquer coisa).
 *
 * EXPIRAÇÃO
 * Não há cron neste projeto. Um ban temporário vencido não se desfaz sozinho — quem percebe
 * o vencimento é esta classe, ao autenticar: se a conta está marcada como punida mas não há
 * punição VIGENTE, BanRepo::sincronizar() recalcula `users.status` na hora e a conta entra.
 * A conta paga essa consulta extra só enquanto estiver marcada como punida.
 */
final class AccountGuard
{
    /** Segundos de tolerância entre duas verificações numa navegação por GET. */
    public const TTL_SEGUNDOS = 30;

    /** Situações de `users.status` que impedem jogar. 'active' é a única que libera. */
    public const BLOQUEADAS = ['banned', 'suspended'];

    /**
     * Estado da conta para fins de acesso, já com a expiração tratada.
     *
     * @return array{bloqueado:bool,status:string,kind:?string,reason:?string,expires_at:?string}
     */
    public static function estado(int $userId): array
    {
        $livre = ['bloqueado' => false, 'status' => 'active', 'kind' => null, 'reason' => null, 'expires_at' => null];
        if ($userId <= 0) {
            return $livre;
        }

        $linha = self::consultar($userId);
        if ($linha === null) {
            // Conta apagada com a sessão aberta: não é "liberado", é "não existe mais".
            return ['bloqueado' => true, 'status' => 'gone', 'kind' => null, 'reason' => null, 'expires_at' => null];
        }

        $status = (string)($linha['status'] ?? 'active');
        if (!in_array($status, self::BLOQUEADAS, true)) {
            return $livre;
        }

        // Marcada como punida: só continua bloqueada se houver punição VIGENTE. Sem nenhuma,
        // o prazo venceu (ou alguém mexeu no banco por fora) — recalcula e devolve a conta.
        if ($linha['kind'] === null) {
            $novo = BanRepo::sincronizar($userId);
            if (!in_array($novo, self::BLOQUEADAS, true)) {
                return $livre;
            }
            // sincronizar() manteve o bloqueio: releitura para pegar a punição que o
            // primeiro SELECT não viu (corrida com outra requisição, por exemplo).
            $linha = self::consultar($userId) ?? $linha;
            $status = (string)($linha['status'] ?? $novo);
        }

        return [
            'bloqueado'  => true,
            'status'     => $status,
            'kind'       => $linha['kind'] !== null ? (string)$linha['kind'] : null,
            'reason'     => $linha['reason'] !== null ? (string)$linha['reason'] : null,
            'expires_at' => $linha['expires_at'] !== null ? (string)$linha['expires_at'] : null,
        ];
    }

    /**
     * Texto mostrado a quem foi barrado.
     *
     * O que ele NÃO diz: nada sobre a existência da conta. Esta mensagem só aparece depois
     * de a SENHA ter sido conferida com sucesso (ou para uma sessão já aberta) — quem não
     * sabe a senha continua recebendo o genérico "Usuário ou senha inválidos". Por isso é
     * seguro detalhar aqui: quem lê isto já provou ser o dono da conta.
     *
     * @param array{bloqueado:bool,status:string,kind:?string,reason:?string,expires_at:?string} $estado
     */
    public static function mensagem(array $estado): string
    {
        if ((string)$estado['status'] === 'gone') {
            return 'Esta conta não está mais disponível.';
        }

        $suspensa = (string)$estado['status'] === 'suspended';
        $texto = $suspensa
            ? 'Sua conta está suspensa e não pode entrar no jogo.'
            : 'Sua conta está banida e não pode entrar no jogo.';

        $prazo = (string)($estado['expires_at'] ?? '');
        if ($prazo !== '' && ($ts = strtotime($prazo)) !== false) {
            $texto .= ' O acesso volta em ' . date('d/m/Y \à\s H:i', $ts) . '.';
        } elseif ($estado['kind'] !== null) {
            $texto .= ' A punição não tem prazo para acabar.';
        }

        $motivo = trim((string)($estado['reason'] ?? ''));
        if ($motivo !== '') {
            $texto .= ' Motivo: ' . mb_substr($motivo, 0, 180) . '';
        }
        return $texto . ' Fale com o suporte se discordar.';
    }

    /**
     * Guarda de requisição, chamada UMA vez pelo front controller.
     *
     * Devolve null quando a requisição pode seguir, ou a Response que derruba a sessão.
     * Nunca lança: banco fora do ar não pode virar "todo mundo banido" — nesse caso a
     * falha vai para o log e a requisição segue (o login, esse sim, recusa entrada nova).
     */
    public static function vigiar(Request $request): ?Response
    {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return null;                       // visitante: não há sessão para derrubar
        }

        // /sair precisa funcionar sempre, inclusive para banido: a pessoa tem o direito de
        // encerrar a própria sessão sem ser interceptada por um redirect para /login.
        if (trim($request->path(), '/') === 'sair') {
            return null;
        }

        $agora = time();
        $visto = (int)($_SESSION['auth_seen'] ?? 0);
        if (!$request->isPost() && $visto > 0 && $agora - $visto < self::TTL_SEGUNDOS) {
            return null;                       // verificado há pouco: não gasta consulta
        }

        try {
            $estado = self::estado($uid);
        } catch (\Throwable $e) {
            error_log('[extracthunters] AccountGuard: ' . $e->getMessage());
            return null;
        }

        if (!$estado['bloqueado']) {
            $_SESSION['auth_seen'] = $agora;
            return null;
        }

        return self::derrubar($request, $estado);
    }

    /** Marca a sessão como recém-verificada (chamado logo depois de um login bem-sucedido). */
    public static function carimbar(): void
    {
        $_SESSION['auth_seen'] = time();
    }

    /* ================================================================== apoio */

    /**
     * Situação da conta + a punição vigente mais forte, em UMA consulta.
     *
     * O LEFT JOIN traz o ban só quando ele está em vigor; a ordenação põe o definitivo
     * (expires_at NULL) na frente e, entre os com prazo, o que termina mais tarde — é esse
     * que decide até quando a conta fica fora.
     *
     * @return array<string,mixed>|null
     */
    private static function consultar(int $userId): ?array
    {
        return Database::one(
            'SELECT u.status, b.kind, b.reason, b.expires_at
               FROM users u
               LEFT JOIN bans b
                 ON b.user_id = u.id
                AND b.revoked_at IS NULL
                AND (b.expires_at IS NULL OR b.expires_at > NOW())
              WHERE u.id = :id
              ORDER BY b.expires_at IS NULL DESC, b.expires_at DESC, b.id DESC
              LIMIT 1',
            ['id' => $userId]
        );
    }

    /**
     * Encerra a sessão do jogador e devolve a resposta adequada ao tipo de requisição.
     *
     * A navegação sem recarga (assets/js/spa.js) e a HUD dentro da Unreal falam JSON: um
     * 302 para /login viraria HTML injetado no meio da tela. Para elas vai 401 com o motivo,
     * e o cliente decide o que fazer.
     *
     * @param array{bloqueado:bool,status:string,kind:?string,reason:?string,expires_at:?string} $estado
     */
    private static function derrubar(Request $request, array $estado): Response
    {
        $msg = self::mensagem($estado);

        Security::destroySession();
        Security::startSession();

        $caminho = trim($request->path(), '/');
        $json = Request::wantsPartial()
            || $request->isAjax()
            || str_starts_with($caminho, 'hud')
            || str_ends_with($caminho, '/dados');

        if ($json) {
            return Response::json(['ok' => false, 'error' => 'conta_bloqueada', 'message' => $msg, 'redirect' => url('login')], 401);
        }

        flash('error', $msg);
        return Response::redirect(url('login'));
    }
}
