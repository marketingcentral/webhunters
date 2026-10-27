<?php declare(strict_types=1);

namespace App\Core;

use App\Repo\AdminSettingsRepo;

/**
 * MODO MANUTENÇÃO — a chave `maintenance_enabled` de /admin/configuracoes, aplicada de verdade.
 *
 * POR QUE EXISTE
 * A tabela `admin_settings` guardava `maintenance_enabled` e `maintenance_message` desde o
 * patch v6, e NADA no jogo lia essas duas linhas: ligar a manutenção no painel não fechava
 * coisa nenhuma. Era um interruptor pintado na parede.
 *
 * O QUE FICA DE PÉ COM A MANUTENÇÃO LIGADA
 *   - /admin e /admin/login  — senão o operador desligaria a manutenção por onde? Fechar a
 *     porta por fora com a chave lá dentro é o jeito clássico de transformar uma manutenção
 *     de 10 minutos numa madrugada de suporte;
 *   - /login, /sair e /recuperar-senha — para o operador conseguir entrar e sair;
 *   - qualquer conta com papel administrativo (`users.role` admin/owner), que precisa ver o
 *     jogo como ele está durante a janela.
 * Todo o resto do jogo responde 503 com a mensagem escrita no painel.
 *
 * CUSTO
 * Uma consulta a cada TTL segundos por sessão (o mesmo carimbo usado por AccountGuard), e
 * não uma por requisição: o interruptor muda uma vez por semana, no máximo.
 */
final class Maintenance
{
    /** Segundos entre duas leituras da chave para a mesma sessão. */
    public const TTL_SEGUNDOS = 30;

    /** Mensagem usada quando o painel não escreveu nenhuma. */
    public const PADRAO = 'MANUTENÇÃO PROGRAMADA. VOLTAMOS EM BREVE.';

    /**
     * Caminhos que continuam abertos durante a manutenção (primeiro segmento da rota).
     *
     * `api` entra aqui pelo MESMO motivo que `admin`: é por ela que a manutenção se desliga.
     * PATCH /api/v1/servers/maintenance é o que um script de deploy usa para fechar o jogo
     * antes de subir a build e reabrir depois — se a manutenção bloqueasse a própria API, o
     * script ligaria o modo e perderia, no mesmo instante, a única forma que tem de desligá-lo.
     * Fechar a porta por fora com a chave lá dentro, de novo.
     *
     * A API não fica desprotegida por causa disso: ela exige chave com escopo em toda rota —
     * o que está aberto durante a janela é a AUTENTICAÇÃO, não o conteúdo.
     */
    private const LIVRES = ['', 'login', 'sair', 'recuperar-senha', 'admin', 'api'];

    /**
     * Interrompe a requisição quando a manutenção está ligada e quem pede não é da operação.
     *
     * Não devolve Response: lança HttpException(503), que o front controller já sabe
     * transformar na página de erro do jogo (ErrorsController::fromException).
     */
    public static function vigiar(Request $request): void
    {
        $primeiro = explode('/', trim($request->path(), '/'))[0] ?? '';
        if (in_array($primeiro, self::LIVRES, true)) {
            return;
        }

        [$ligada, $mensagem] = self::estado();
        if (!$ligada) {
            return;
        }

        // Operação passa: quem pode desligar a manutenção precisa poder olhar o jogo durante ela.
        if (self::ehOperacao()) {
            return;
        }

        throw new HttpException(503, $mensagem);
    }

    /**
     * Estado do interruptor, carimbado na sessão por TTL_SEGUNDOS.
     *
     * @return array{0:bool,1:string}
     */
    public static function estado(): array
    {
        $agora = time();
        $visto = (int)($_SESSION['_maint_seen'] ?? 0);
        if ($visto > 0 && $agora - $visto < self::TTL_SEGUNDOS && isset($_SESSION['_maint_on'])) {
            return [(bool)$_SESSION['_maint_on'], (string)($_SESSION['_maint_msg'] ?? self::PADRAO)];
        }

        try {
            $ligada   = (bool)AdminSettingsRepo::valor('maintenance_enabled', false);
            $mensagem = trim((string)AdminSettingsRepo::valor('maintenance_message', '')) ?: self::PADRAO;
        } catch (\Throwable $e) {
            // Banco fora do ar não pode LIGAR a manutenção sozinho: a falha já vai aparecer
            // por outro caminho, e um 503 aqui esconderia a causa real.
            error_log('[extracthunters] Maintenance: ' . $e->getMessage());
            return [false, self::PADRAO];
        }

        $_SESSION['_maint_seen'] = $agora;
        $_SESSION['_maint_on']   = $ligada;
        $_SESSION['_maint_msg']  = $mensagem;
        return [$ligada, $mensagem];
    }

    /** A sessão atual é de alguém da operação (papel admin/owner)? */
    private static function ehOperacao(): bool
    {
        if ((int)($_SESSION['admin_id'] ?? 0) > 0) {
            return true;
        }
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return false;
        }
        try {
            $papel = (string)Database::value('SELECT role FROM users WHERE id = :id', ['id' => $uid]);
        } catch (\Throwable) {
            return false;
        }
        return in_array($papel, ['admin', 'owner'], true);
    }
}
