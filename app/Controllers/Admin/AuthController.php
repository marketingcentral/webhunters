<?php declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;
use App\Repo\AdminRepo;

/**
 * Login do painel administrativo — porta separada da do jogo.
 *
 * COMO O DONO ENTRA
 * -----------------
 * Não existe senha de administrador. O patch db/patch_v6_admin.php promoveu a conta
 * `extracthunters` a role='owner' mexendo SÓ na coluna role: o password_hash ficou
 * intacto. Ou seja, o dono entra em /admin/login com o MESMO usuário e a MESMA senha
 * que usa para jogar. Nenhuma senha é criada, gravada ou impressa por este arquivo.
 *
 * Para dar acesso a mais alguém, o caminho é o banco (ou, quando existir, a tela
 * "Permissões"): UPDATE users SET role='admin' WHERE username='...'. A pessoa continua
 * usando a senha dela.
 *
 * DUAS SESSÕES NO MESMO COOKIE
 * ----------------------------
 * $_SESSION['user_id']  -> jogador logado na HUD
 * $_SESSION['admin_id'] -> operador logado no painel
 * São independentes de propósito: entrar no painel não loga no jogo, e sair do painel
 * (POST /admin/sair) não derruba o jogo. Quem valida a segunda é AdminController.
 *
 * O QUE NUNCA SAI DAQUI
 * ---------------------
 * A resposta de falha é sempre a MESMA frase, no mesmo status: senha errada, conta
 * inexistente e conta sem papel administrativo são indistinguíveis de fora. Só o
 * admin_audit_log sabe a diferença.
 */
final class AuthController extends Controller
{
    /** Única mensagem de falha — não diferencia conta inexistente de conta sem acesso. */
    private const ERRO_GENERICO = 'Usuário ou senha inválidos.';

    /** Hash descartável: gasta o tempo de um bcrypt real quando a conta não existe. */
    private const HASH_FANTASMA = '$2y$12$naoExisteEstaContaXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX';

    /** Tentativas sem espera; a partir da 4ª a porta fecha por um tempo crescente. */
    private const TENTATIVAS_LIVRES = 3;

    /** Espera (segundos) da 4ª falha em diante; a última se repete. */
    private const ESPERAS = [15, 45, 120, 300];

    /** Janela em que as falhas do mesmo IP são contadas juntas. */
    private const JANELA_MIN = 15;

    /** @param array<string, string> $params */
    public function login(Request $request, array $params = []): Response
    {
        if (!$request->isPost() && (int)($_SESSION['admin_id'] ?? 0) > 0) {
            return $this->redirect('admin');
        }

        $ip      = $request->ip();
        $erro    = null;
        $usuario = '';
        $status  = 200;

        if ($request->isPost()) {
            $this->csrf($request);
            $usuario = trim(mb_substr((string)$request->input('user', ''), 0, 60));
            $senha   = (string)$request->input('password', '');
            $espera  = $this->esperaRestante($ip);

            if ($espera > 0) {
                AdminRepo::registrar(null, 'login_bloqueado', 'admin', $usuario !== '' ? $usuario : null,
                    'Tentativa durante o bloqueio por excesso de falhas.', ['espera_s' => $espera], $ip);
                $erro   = 'Muitas tentativas seguidas. Tente novamente em ' . $this->porExtenso($espera) . '.';
                $status = 429;
            } elseif ($usuario === '' || $senha === '') {
                $erro   = 'Informe o usuário e a senha.';
                $status = 422;
            } else {
                try {
                    $conta = AdminRepo::porLogin($usuario);

                    if ($conta === null || !password_verify($senha, (string)$conta['password_hash'])) {
                        if ($conta === null) {
                            // sem isto o tempo de resposta denuncia quais contas existem
                            password_verify($senha, self::HASH_FANTASMA);
                        }
                        $this->registrarFalha($ip, $usuario, $conta !== null ? (int)$conta['id'] : null,
                            'senha_incorreta', 'Senha incorreta no painel administrativo.');
                        $erro   = self::ERRO_GENERICO;
                        $status = 422;
                    } elseif (!AdminRepo::podeEntrar($conta)) {
                        // A senha estava certa: isto é um jogador (ou um admin banido) batendo
                        // na porta do painel. A tela não conta isso — o log conta.
                        // O resumo fica em UMA frase fechada: ele é lido na linha do tempo do
                        // dashboard, que corta em duas linhas. Papel e situação viram meta.
                        $this->registrarFalha($ip, $usuario, (int)$conta['id'], 'sem_acesso',
                            'Senha correta em conta sem acesso ao painel.',
                            ['role' => (string)$conta['role'], 'status' => (string)$conta['status']]);
                        $erro   = self::ERRO_GENERICO;
                        $status = 422;
                    } else {
                        return $this->entrar($conta, $request);
                    }
                } catch (\Throwable $e) {
                    error_log('[extracthunters] admin/login: ' . $e->getMessage());
                    $erro   = 'Não foi possível verificar suas credenciais agora. Tente novamente em instantes.';
                    $status = 503;
                }
            }
        }

        $page = $this->page('admin-login', 'Acesso restrito', [
            'slug'  => 'admin-login',
            'nav'   => '',
            'back'  => null,
            'three' => ['atmosphere' => false],
        ]);

        return $this->render('admin-login', $page, [
            'erro'    => $erro,
            'usuario' => $usuario,
        ], 'admin-auth', $status);
    }

    /**
     * Encerra SÓ a sessão administrativa: $_SESSION['user_id'] continua de pé, então quem
     * estava jogando não é deslogado do jogo por sair do painel.
     *
     * @param array<string, string> $params
     */
    public function logout(Request $request, array $params = []): Response
    {
        $this->csrf($request);

        $id = (int)($_SESSION['admin_id'] ?? 0);
        if ($id > 0) {
            AdminRepo::registrar($id, 'logout', 'admin', (string)$id,
                'Saiu do painel administrativo.', [], $request->ip());
        }
        unset($_SESSION['admin_id'], $_SESSION['admin_seen']);
        Security::regenerate();   // id novo: o antigo não vale mais para nada

        flash('ok', 'Você saiu do painel.');
        return $this->redirect('admin/login');
    }

    /** @param array<string,mixed> $conta */
    private function entrar(array $conta, Request $request): Response
    {
        $id = (int)$conta['id'];

        Security::regenerate();
        $_SESSION['admin_id']   = $id;
        $_SESSION['admin_seen'] = time();
        unset($_SESSION['_csrf']);                                    // token novo com a sessão nova
        unset($_SESSION['admin_falhas'], $_SESSION['admin_espera_ate']);

        AdminRepo::marcarAcesso($id, $request->ip());
        AdminRepo::registrar($id, 'login', 'admin', (string)$id,
            'Entrou no painel administrativo.',
            ['role' => (string)$conta['role'], 'ua' => mb_substr($request->userAgent(), 0, 180)],
            $request->ip());

        flash('ok', 'Painel liberado, ' . (string)$conta['username'] . '.');
        return $this->redirect('admin');
    }

    /**
     * Conta a falha nos dois lugares e arma a espera.
     *
     * A sessão é o contador rápido; o admin_audit_log é o contador honesto, porque apagar
     * o cookie zera o primeiro mas não o segundo. Vale o maior dos dois.
     *
     * @param array<string,mixed> $meta contexto técnico do caso; NUNCA entra no $resumo,
     *                                  que é a frase lida na linha do tempo do dashboard
     */
    private function registrarFalha(string $ip, string $login, ?int $userId, string $motivo, string $resumo, array $meta = []): void
    {
        AdminRepo::registrar($userId, 'login_falhou', 'admin', $login !== '' ? $login : null,
            $resumo, $meta + ['motivo' => $motivo, 'login' => $login], $ip);

        $n = (int)($_SESSION['admin_falhas'] ?? 0) + 1;
        $_SESSION['admin_falhas'] = $n;

        $n = max($n, AdminRepo::falhasRecentes($ip, self::JANELA_MIN)['n']);
        $espera = $this->espera($n);
        $_SESSION['admin_espera_ate'] = $espera > 0 ? time() + $espera : 0;
    }

    /** Segundos que ainda faltam para a porta reabrir (0 = pode tentar). */
    private function esperaRestante(string $ip): int
    {
        $restaSessao = (int)($_SESSION['admin_espera_ate'] ?? 0) - time();

        // O mesmo IP em outra sessão (cookie apagado) continua preso pelo log.
        $falhas    = AdminRepo::falhasRecentes($ip, self::JANELA_MIN);
        $restaIp   = $falhas['ultima'] > 0
            ? $falhas['ultima'] + $this->espera($falhas['n']) - time()
            : 0;

        return max(0, $restaSessao, $restaIp);
    }

    private function espera(int $falhas): int
    {
        if ($falhas <= self::TENTATIVAS_LIVRES) {
            return 0;
        }
        $passo = min($falhas - self::TENTATIVAS_LIVRES, count(self::ESPERAS));
        return self::ESPERAS[$passo - 1];
    }

    private function porExtenso(int $segundos): string
    {
        if ($segundos < 60) {
            return $segundos . ' segundo' . ($segundos === 1 ? '' : 's');
        }
        $min = (int)ceil($segundos / 60);
        return $min . ' minuto' . ($min === 1 ? '' : 's');
    }
}
