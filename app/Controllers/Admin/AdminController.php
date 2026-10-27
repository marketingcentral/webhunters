<?php declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Fragmento;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repo\AdminRepo;

/**
 * Base de TODA tela do painel administrativo — a guarda de rota.
 *
 * Como usar numa tela nova:
 *
 *   final class JogadoresController extends AdminController
 *   {
 *       public function index(Request $request, array $params = []): Response
 *       {
 *           if ($bloqueio = $this->exigirAdmin()) { return $bloqueio; }   // primeira linha, sempre
 *           $page = $this->adminPage('jogadores', 'Jogadores', 'Contas, punições e progresso');
 *           return $this->render($page['slug'], $page, ['linhas' => ...]);
 *       }
 *   }
 *
 * O que a base garante:
 *   - a conta é RECARREGADA DO BANCO a cada requisição. Papel e situação mudam (o dono
 *     rebaixa um admin, uma conta é banida) e uma sessão aberta não pode continuar valendo
 *     por causa de um dado copiado no login;
 *   - sem sessão administrativa -> 302 para /admin/login;
 *   - com sessão de jogador comum -> 403 (e não um redirect: quem já está logado no jogo
 *     precisa saber que a porta existe e não é dele, não ser jogado num formulário);
 *   - render() é a rede de segurança: mesmo que uma ação esqueça o exigirAdmin(), nenhuma
 *     tela do painel chega a ser desenhada sem sessão válida. Ações que ESCREVEM (POST) não
 *     têm essa rede — elas gravam antes de renderizar —, então nelas o exigirAdmin() no topo
 *     não é estilo, é a única proteção.
 */
abstract class AdminController extends Controller
{
    /** Minutos de inatividade que encerram a sessão do painel (o login do jogo continua). */
    protected const INATIVIDADE_MIN = 60;

    /** @var array<string,mixed>|null Conta administrativa desta requisição. */
    private ?array $adminAtual = null;

    /** '' = liberado | 'anonimo' = sem sessão | 'proibido' = logado, mas sem acesso. */
    private string $negado = 'anonimo';

    public function __construct()
    {
        $this->resolverSessao();
    }

    /**
     * Devolve a resposta de bloqueio, ou null quando a sessão é válida.
     * Ações concretas usam sempre no formato `if ($b = $this->exigirAdmin()) { return $b; }`.
     */
    final protected function exigirAdmin(): ?Response
    {
        if ($this->adminAtual !== null) {
            return null;
        }
        if ($this->negado === 'proibido') {
            throw new HttpException(403, 'Área restrita à administração.');
        }
        flash('error', 'Faça login para acessar o painel administrativo.');
        return $this->redirect('admin/login');
    }

    /**
     * Conta administrativa logada (id, username, email, role, status, last_login, last_ip).
     * @return array<string,mixed>
     */
    protected function admin(): array
    {
        if ($this->adminAtual === null) {
            // Chegar aqui é bug de quem escreveu a tela, não estado possível do usuário.
            throw new \LogicException('admin() antes da guarda: chame exigirAdmin() no início da ação.');
        }
        return $this->adminAtual;
    }

    protected function adminId(): int
    {
        return (int)$this->admin()['id'];
    }

    /**
     * Uma linha na trilha de auditoria, já assinada pelo administrador logado e pelo IP.
     * Toda ação do painel que MUDA alguma coisa passa por aqui — é o que a tela
     * "Logs do Sistema" lê.
     *
     * @param array<string,mixed> $meta
     */
    protected function auditar(
        string $acao,
        string $entidade = 'sistema',
        string|int|null $entidadeId = null,
        string $resumo = '',
        array $meta = [],
    ): void {
        AdminRepo::registrar(
            $this->adminAtual !== null ? (int)$this->adminAtual['id'] : null,
            $acao,
            $entidade,
            $entidadeId !== null ? (string)$entidadeId : null,
            $resumo,
            $meta,
            $this->ip()
        );
    }

    /**
     * Monta o $page do painel: o slug da VIEW ganha o prefixo `admin-` (para nunca colidir
     * com uma página do jogo — existe `loja` e `missoes` nos dois lados), e o item da
     * sidebar que acende é o slug puro, igual ao de app/Data/admin_nav.php.
     *
     * O CSS e o JS da tela seguem o slug prefixado: assets/css/admin/admin-<slug>.css
     * e assets/js/admin/admin-<slug>.js (o layout carrega sozinho quando existem).
     *
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    protected function adminPage(string $slug, string $titulo, string $subtitulo = '', array $over = []): array
    {
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug)) ?: 'dashboard';

        return $this->page('admin-' . $slug, $titulo, array_replace([
            'slug'     => 'admin-' . $slug,
            'nav'      => $slug,
            'subtitle' => $subtitulo,
            'back'     => null,
            'three'    => ['atmosphere' => false],
        ], $over));
    }

    /**
     * Renderiza no layout `admin` e injeta o que a casca precisa (bloco do administrador e
     * navegação lateral) — a tela só sobrescreve se quiser algo diferente.
     *
     * ATUALIZAÇÃO DE LISTA SEM RECARGA: quando a requisição traz o cabeçalho X-EH-Lista,
     * a MESMA ação, com a MESMA view e os MESMOS filtros, é desenhada por layouts/lista.php,
     * que devolve JSON com só o pedaço marcado da tela. Nenhuma tela do painel precisa saber
     * disso, e nenhuma rota nova foi inventada: é o mesmo GET que o formulário já fazia.
     *
     * Só o layout 'admin' tem essa versão. Quem renderiza noutro casco (a tela de login usa
     * 'admin-auth') cai no caminho normal, e o cliente reconhece a resposta que não é JSON e
     * navega de verdade.
     *
     * @param array<string,mixed> $page @param array<string,mixed> $data
     */
    protected function render(string $view, array $page, array $data = [], string $layout = 'admin', int $status = 200): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $data += [
            'admin'     => $this->blocoAdmin(),
            'admin_nav' => data_mock('admin_nav'),
        ];

        if ($layout !== 'admin' || !Fragmento::pedido()) {
            return parent::render($view, $page, $data, $layout, $status);
        }

        // O corpo já sai pronto de layouts/lista.php; aqui só se corrige o envelope. `Vary`
        // é obrigatório: sem ele um proxy pode guardar o JSON e servi-lo para uma navegação
        // normal, e o operador receberia um punhado de JSON no lugar do painel.
        return parent::render($view, $page, $data, 'lista', $status)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Vary', Fragmento::CABECALHO);
    }

    /** @return array<string,mixed> Bloco do administrador lido por layouts/admin.php. */
    protected function blocoAdmin(): array
    {
        $u = $this->admin();
        return [
            'name'    => (string)$u['username'],
            'role'    => AdminRepo::rotuloPapel((string)$u['role']),
            'env'     => Config::debug() ? 'Ambiente local' : 'Produção',
            'version' => 'v1.0',
            'logout'  => url('admin/sair'),
            'menu'    => [],
        ];
    }

    protected function ip(): string
    {
        return Request::fromGlobals()->ip();
    }

    /**
     * Decide o acesso desta requisição. É construtor, e não middleware, porque o Router
     * instancia o controller e chama a ação direto — não há outro ponto anterior a ela.
     */
    private function resolverSessao(): void
    {
        $id = (int)($_SESSION['admin_id'] ?? 0);
        if ($id <= 0) {
            // Sessão de jogador aberta não é credencial de painel: vira 403, não redirect.
            $this->negado = is_authenticated() ? 'proibido' : 'anonimo';
            return;
        }

        $visto = (int)($_SESSION['admin_seen'] ?? 0);
        if ($visto > 0 && time() - $visto > self::INATIVIDADE_MIN * 60) {
            $this->encerrar($id, 'sessao_expirada', 'Sessão do painel encerrada por inatividade.');
            flash('error', 'Sua sessão no painel expirou por inatividade.');
            $this->negado = 'anonimo';
            return;
        }

        $conta = AdminRepo::porId($id);
        if (!AdminRepo::podeEntrar($conta)) {
            // O papel caiu (ou a conta sumiu/foi banida) com a sessão aberta: derruba na hora.
            $this->encerrar($id, 'acesso_revogado', 'Sessão encerrada: a conta perdeu acesso ao painel.');
            $this->negado = 'proibido';
            return;
        }

        $_SESSION['admin_seen'] = time();
        $this->adminAtual = $conta;
        $this->negado = '';
    }

    private function encerrar(int $id, string $acao, string $resumo): void
    {
        AdminRepo::registrar($id, $acao, 'admin', (string)$id, $resumo, [], $this->ip());
        unset($_SESSION['admin_id'], $_SESSION['admin_seen']);
    }
}
