<?php declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repo\AdminPlayersRepo;

/**
 * Tela ADMIN · JOGADORES (/admin/jogadores).
 *
 * Governa `users` (papel e situação), `bans` (aplicar e revogar) e mostra, sem editar, o que
 * as tabelas player_* dizem sobre a conta.
 *
 * Uma tela, dois modos, uma rota: sem `?id=` é a LISTA; com `?id=` é o DOSSIÊ de uma conta.
 * Não é economia de arquivo — é que a rota registrada em app/routes.php é uma só
 * (`GET /admin/jogadores`, sem segmento de id) e inventar um segundo caminho aqui exigiria
 * mexer no arquivo de rotas, que é ponto de conflito entre agentes.
 *
 * ONDE ESTÁ CADA COISA:
 *   - SQL ................ App\Repo\AdminPlayersRepo
 *   - texto/rótulo ....... app/Data/admin-jogadores.php
 *   - regra de quem pode . aqui (e SÓ aqui)
 *   - desenho ............ app/Views/pages/admin-jogadores.php
 *
 * AS TRÊS REGRAS QUE ESTE ARQUIVO EXISTE PARA GARANTIR:
 *
 *   1. Ninguém age sobre a própria conta. Um administrador que consegue se rebaixar, se
 *      suspender ou se banir transforma um clique errado num chamado de suporte no banco.
 *
 *   2. Ninguém age sobre igual ou superior (AdminPlayersRepo::podeAgirSobre). Só proibir a
 *      auto-ação seria contornável em dois passos: eu rebaixo o outro admin e ele não me
 *      rebaixa. Autoridade é estritamente decrescente.
 *
 *   3. `bans` e `users.status` NUNCA discordam. Por isso o formulário de situação oferece só
 *      "Ativa" e "Suspensa": "Banida" é consequência de uma punição registrada, não um valor
 *      que se escolhe num <select>. Quem quer banir usa o formulário de banimento, que grava
 *      o motivo, o prazo e o autor; quem quer liberar revoga a punição.
 *
 * password_hash não é lido, não é escrito e não chega à view — ver o cabeçalho do repo.
 */
final class PlayersController extends AdminController
{
    /** Motivo de banimento: curto demais não é motivo, é rabisco. */
    private const MOTIVO_MIN = 8;
    private const MOTIVO_MAX = 500;

    /** Teto do prazo de um banimento temporário. Acima disso, o honesto é usar permanente. */
    private const BAN_MAX_DIAS = 3650;

    /* =====================================================================================
       LEITURA
       ===================================================================================== */

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }

        // Sem cron no projeto, "banir por 3 dias" só termina se alguém varrer os vencidos.
        // A abertura da tela é o gatilho mais barato e mais óbvio que existe aqui.
        $liberados = AdminPlayersRepo::liberarVencidos();
        if ($liberados > 0) {
            $this->auditar('ban_expirado', 'user', null,
                $liberados . ' conta(s) reativada(s) por vencimento do banimento.', ['contas' => $liberados]);
        }

        $id = (int)($request->query('id') ?? 0);
        return $id > 0 ? $this->telaDossie($id) : $this->telaLista($request);
    }

    /* =====================================================================================
       ESCRITA
       ===================================================================================== */

    /**
     * Um endpoint, três ações (`acao`): salvar a conta, banir e revogar. As três mexem na
     * MESMA conta e compartilham a checagem de autoridade — separá-las em rotas diferentes
     * duplicaria essa checagem três vezes, que é exatamente onde erro de permissão nasce.
     *
     * @param array<string, string> $params
     */
    public function salvar(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $alvo = $this->alvoOuFalha((int)($request->input('id') ?? 0));

        return match ((string)$request->input('acao')) {
            'conta'   => $this->salvarConta($request, $alvo),
            'banir'   => $this->banir($request, $alvo),
            'revogar' => $this->revogar($request, $alvo),
            default   => $this->voltarParaFicha((int)$alvo['id'], 'error', 'Ação desconhecida.'),
        };
    }

    /**
     * Apagar conta. Não é o caminho normal de moderação e a tela diz isso: só passa conta
     * SEM RASTRO no mundo (nunca jogou, nunca comprou, nunca foi punida). Com rastro, a
     * resposta é um bloqueio que explica o que segura — apagar apagaria a prova.
     *
     * @param array<string, string> $params
     */
    public function excluir(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $alvo = $this->alvoOuFalha((int)($request->input('id') ?? 0));
        $id   = (int)$alvo['id'];

        if (!AdminPlayersRepo::podeAgirSobre($this->admin(), $alvo)) {
            return $this->voltarParaFicha($id, 'error', $this->motivoSemAutoridade($alvo));
        }

        // O BLOQUEIO vem antes da confirmação, e não depois: se a conta não pode ser apagada
        // de jeito nenhum, cobrar o nome digitado primeiro seria pedir esforço para depois
        // dizer não — e a ficha nem desenha o campo de confirmação nesse caso, então o erro
        // não teria onde aparecer.
        $refs = AdminPlayersRepo::referencias($id);
        if ($refs !== []) {
            return $this->voltarParaFicha($id, 'error',
                'Conta não apagada: ela tem ' . $this->listarReferencias($refs) . ' no histórico do jogo.');
        }

        // Confirmação por digitação, e não por "tem certeza?": um diálogo de sim/não é
        // aceito no reflexo. Escrever o nome da conta obriga a olhar QUAL conta é.
        $digitado = trim((string)$request->input('confirmar', ''));
        if ($digitado !== (string)$alvo['username']) {
            return $this->telaDossie($id, [
                'confirmar' => 'Digite exatamente ' . (string)$alvo['username'] . ' para confirmar.',
            ]);
        }

        AdminPlayersRepo::excluirConta($id);
        $this->auditar('jogador_excluido', 'user', $id,
            'Conta ' . (string)$alvo['username'] . ' apagada (sem histórico no jogo).',
            ['username' => $alvo['username'], 'email' => $alvo['email'], 'role' => $alvo['role']]);

        flash('ok', 'Conta ' . (string)$alvo['username'] . ' apagada.');
        return $this->redirect('admin/jogadores');
    }

    /* =====================================================================================
       AÇÕES
       ===================================================================================== */

    /** @param array<string,mixed> $alvo */
    private function salvarConta(Request $request, array $alvo): Response
    {
        $id     = (int)$alvo['id'];
        $papel  = (string)$request->input('papel', '');
        $situac = (string)$request->input('situacao', '');
        $erros  = [];

        if (!AdminPlayersRepo::podeAgirSobre($this->admin(), $alvo)) {
            return $this->voltarParaFicha($id, 'error', $this->motivoSemAutoridade($alvo));
        }

        if (!in_array($papel, AdminPlayersRepo::PAPEIS, true)) {
            $erros['papel'] = 'Escolha um papel válido.';
        } elseif (AdminPlayersRepo::peso($papel) >= AdminPlayersRepo::peso((string)$this->admin()['role'])) {
            // Promover alguém ao seu próprio nível é criar um par capaz de te rebaixar —
            // o mesmo furo da regra 2, pela outra ponta.
            $erros['papel'] = 'Você não pode conceder um papel igual ou superior ao seu.';
        }

        /* 'banned' não é opção do formulário: é resultado de punição (regra 3 no topo).
           Numa conta banida o <select> chega desabilitado, ou seja, o navegador NÃO envia o
           campo — e cobrar um valor que a própria tela impede de escolher travaria a edição
           do papel por tabela. Então: situação preservada, e o papel segue editável. Só quem
           forjou o POST manda 'active'/'suspended' aqui, e esse é recusado com o motivo certo. */
        $banida = (string)$alvo['status'] === 'banned';
        if ($banida) {
            if ($situac !== '' && $situac !== 'banned') {
                $erros['situacao'] = 'Esta conta está banida. Revogue o banimento antes de mudar a situação.';
            }
            $situac = 'banned';
        } elseif (!in_array($situac, ['active', 'suspended'], true)) {
            $erros['situacao'] = 'Escolha entre conta ativa e suspensa.';
        }

        if ($erros !== []) {
            return $this->telaDossie($id, $erros, ['old' => ['papel' => $papel, 'situacao' => $situac]]);
        }

        if ($papel === (string)$alvo['role'] && $situac === (string)$alvo['status']) {
            return $this->voltarParaFicha($id, 'info', 'Nada mudou: papel e situação já eram esses.');
        }

        AdminPlayersRepo::atualizarConta($id, $papel, $situac);
        $this->auditar('jogador_atualizado', 'user', $id,
            'Conta ' . (string)$alvo['username'] . ': ' . $alvo['role'] . '/' . $alvo['status']
            . ' -> ' . $papel . '/' . $situac,
            ['de' => ['role' => $alvo['role'], 'status' => $alvo['status']],
             'para' => ['role' => $papel, 'status' => $situac]]);

        return $this->voltarParaFicha($id, 'ok', 'Conta de ' . (string)$alvo['username'] . ' atualizada.');
    }

    /** @param array<string,mixed> $alvo */
    private function banir(Request $request, array $alvo): Response
    {
        $id     = (int)$alvo['id'];
        $motivo = trim((string)$request->input('motivo', ''));
        $tipo   = (string)$request->input('tipo', 'temp');
        $expira = trim((string)$request->input('expira', ''));
        $erros  = [];

        if (!AdminPlayersRepo::podeAgirSobre($this->admin(), $alvo)) {
            return $this->voltarParaFicha($id, 'error', $this->motivoSemAutoridade($alvo));
        }
        if (AdminPlayersRepo::banAtivo($id) !== null) {
            return $this->voltarParaFicha($id, 'error',
                'Esta conta já tem um banimento em vigor. Revogue o atual antes de aplicar outro.');
        }

        if (mb_strlen($motivo) < self::MOTIVO_MIN) {
            // O motivo é lido por quem revisa a punição meses depois — inclusive pelo próprio
            // jogador, num pedido de revisão. "spam" não sustenta uma decisão.
            $erros['motivo'] = 'Descreva o motivo com pelo menos ' . self::MOTIVO_MIN . ' caracteres.';
        } elseif (mb_strlen($motivo) > self::MOTIVO_MAX) {
            $erros['motivo'] = 'O motivo passa de ' . self::MOTIVO_MAX . ' caracteres.';
        }

        if (!in_array($tipo, AdminPlayersRepo::TIPOS_BAN, true)) {
            $erros['tipo'] = 'Escolha entre temporário e permanente.';
        }

        $quando = null;
        if ($tipo === 'temp') {
            $ts = $expira !== '' ? strtotime($expira) : false;
            if ($ts === false) {
                $erros['expira'] = 'Informe até quando o banimento vale.';
            } elseif ($ts <= time()) {
                $erros['expira'] = 'A data de término precisa estar no futuro.';
            } elseif ($ts > time() + self::BAN_MAX_DIAS * 86400) {
                $erros['expira'] = 'Prazo acima de ' . self::BAN_MAX_DIAS . ' dias: use banimento permanente.';
            } else {
                $quando = date('Y-m-d H:i:s', $ts);
            }
        }

        if ($erros !== []) {
            return $this->telaDossie($id, $erros, [
                'old' => ['motivo' => $motivo, 'tipo' => $tipo, 'expira' => $expira],
            ]);
        }

        $banId = AdminPlayersRepo::aplicarBan($id, $this->adminId(), $motivo, $tipo, $quando);
        $this->auditar('jogador_banido', 'user', $id,
            'Banimento ' . ($tipo === 'perma' ? 'permanente' : 'até ' . (string)$quando)
            . ' em ' . (string)$alvo['username'] . '.',
            ['ban_id' => $banId, 'kind' => $tipo, 'expires_at' => $quando, 'reason' => $motivo]);

        flash('ok', (string)$alvo['username'] . ' foi banido'
            . ($tipo === 'perma' ? ' permanentemente.' : ' até ' . date('d/m/Y H:i', (int)strtotime((string)$quando)) . '.'));
        return $this->redirect('admin/jogadores', ['id' => $id]);
    }

    /** @param array<string,mixed> $alvo */
    private function revogar(Request $request, array $alvo): Response
    {
        $id  = (int)$alvo['id'];
        $ban = (int)($request->input('ban_id') ?? 0);

        if (!AdminPlayersRepo::podeAgirSobre($this->admin(), $alvo)) {
            return $this->voltarParaFicha($id, 'error', $this->motivoSemAutoridade($alvo));
        }

        if (!AdminPlayersRepo::revogarBan($ban, $id, $this->adminId())) {
            // Chega aqui quando dois operadores revogam a mesma punição ao mesmo tempo, ou
            // quando o prazo venceu entre desenhar a tela e apertar o botão.
            return $this->voltarParaFicha($id, 'info', 'Este banimento já não estava mais em vigor.');
        }

        $this->auditar('ban_revogado', 'user', $id,
            'Banimento #' . $ban . ' de ' . (string)$alvo['username'] . ' revogado.', ['ban_id' => $ban]);

        return $this->voltarParaFicha($id, 'ok', (string)$alvo['username'] . ' está liberado para jogar.');
    }

    /* =====================================================================================
       MONTAGEM DAS DUAS TELAS
       ===================================================================================== */

    private function telaLista(Request $request): Response
    {
        $filtros = [
            'q'        => trim(mb_substr((string)($request->query('q') ?? ''), 0, 60)),
            'papel'    => (string)($request->query('papel') ?? ''),
            'situacao' => (string)($request->query('situacao') ?? ''),
            'ordem'    => (string)($request->query('ordem') ?? 'recentes'),
            'pagina'   => max(1, (int)($request->query('p') ?? 1)),
        ];
        // A URL escolhe entre valores conhecidos; o que não está na lista simplesmente não
        // filtra. Um valor inventado não pode gerar erro nem chegar ao SQL.
        foreach (['papel' => AdminPlayersRepo::PAPEIS, 'situacao' => AdminPlayersRepo::SITUACOES] as $k => $ok) {
            if (!in_array($filtros[$k], $ok, true)) {
                $filtros[$k] = '';
            }
        }
        if (!in_array($filtros['ordem'], AdminPlayersRepo::ordens(), true)) {
            $filtros['ordem'] = 'recentes';
        }

        $lista = AdminPlayersRepo::listar($filtros);
        $page  = $this->adminPage('jogadores', 'Jogadores', 'Contas, progresso e sanções');

        return $this->render($page['slug'], $page, [
            'modo'     => 'lista',
            'cromo'    => data_mock('admin-jogadores'),
            'operador' => $this->admin(),
            'filtros'  => $filtros,
            'lista'    => $lista,
            'abas'     => AdminPlayersRepo::contagemPorSituacao(),
            'resumo'   => AdminPlayersRepo::resumo(),
            'filtrado' => $filtros['q'] !== '' || $filtros['papel'] !== '' || $filtros['situacao'] !== '',
        ]);
    }

    /**
     * Dossiê de uma conta. `$erros` e `$foco` existem para o caminho de VALIDAÇÃO FALHA: em
     * vez de redirecionar e perder o que foi digitado, a mesma tela é redesenhada com a
     * mensagem no campo culpado.
     *
     * @param array<string,string> $erros @param array<string,mixed> $extra
     */
    private function telaDossie(int $id, array $erros = [], array $extra = []): Response
    {
        $conta = AdminPlayersRepo::conta($id);
        if ($conta === null) {
            throw new HttpException(404, 'Jogador não encontrado.');
        }

        $operador = $this->admin();
        $page = $this->adminPage('jogadores', (string)($conta['username'] ?? 'Jogador'), 'Ficha completa da conta');

        return $this->render($page['slug'], $page, $extra + [
            'modo'       => 'ficha',
            'cromo'      => data_mock('admin-jogadores'),
            'operador'   => $operador,
            'conta'      => $conta,
            'erros'      => $erros,

            // A view NÃO recalcula permissão: recebe pronto o que pode e o porquê de não poder.
            'pode_agir'  => AdminPlayersRepo::podeAgirSobre($operador, $conta),
            'e_voce'     => (int)$operador['id'] === (int)$conta['id'],
            'papeis_ok'  => $this->papeisConcedeveis(),

            'ban_ativo'  => AdminPlayersRepo::banAtivo($id),
            'bans'       => AdminPlayersRepo::historicoBans($id),
            'inventario' => AdminPlayersRepo::inventario($id),
            'missoes'    => AdminPlayersRepo::missoes($id),
            'conquistas' => AdminPlayersRepo::conquistas($id),
            'faccoes'    => AdminPlayersRepo::faccoes($id),
            'partidas'   => AdminPlayersRepo::partidas($id),
            'referencias'=> AdminPlayersRepo::referencias($id),
        ]);
    }

    /* =====================================================================================
       APOIO
       ===================================================================================== */

    /**
     * Carrega a conta alvo de uma escrita. 404 quando o id não existe: um POST para um id
     * inventado é requisição forjada, não engano de operador — não merece flash gentil.
     *
     * @return array<string,mixed>
     */
    private function alvoOuFalha(int $id): array
    {
        $alvo = AdminPlayersRepo::conta($id);
        if ($alvo === null) {
            throw new HttpException(404, 'Jogador não encontrado.');
        }
        return $alvo;
    }

    /** @return list<string> Papéis que ESTE operador pode conceder (todos abaixo do dele). */
    private function papeisConcedeveis(): array
    {
        $meu = AdminPlayersRepo::peso((string)$this->admin()['role']);
        return array_values(array_filter(
            AdminPlayersRepo::PAPEIS,
            static fn(string $p): bool => AdminPlayersRepo::peso($p) < $meu
        ));
    }

    /** A recusa diz QUAL das duas regras barrou — "não pode" sem motivo vira chamado. */
    private function motivoSemAutoridade(array $alvo): string
    {
        return (int)$this->adminId() === (int)$alvo['id']
            ? 'Você não pode alterar a sua própria conta pelo painel.'
            : 'Sem autoridade sobre ' . (string)$alvo['username'] . ': o painel só age sobre contas de papel inferior ao seu.';
    }

    /** "3 partidas, 1 compra e 2 punições" — a frase do bloqueio de exclusão. */
    private function listarReferencias(array $refs): string
    {
        $rotulos = [
            'partidas'   => ['partida', 'partidas'],
            'compras'    => ['compra', 'compras'],
            'punicoes'   => ['punição', 'punições'],
            'itens'      => ['item', 'itens'],
            'missoes'    => ['missão', 'missões'],
            'conquistas' => ['conquista', 'conquistas'],
        ];
        $partes = [];
        foreach ($refs as $chave => $n) {
            $par = $rotulos[$chave] ?? [$chave, $chave];
            $partes[] = $n . ' ' . ($n === 1 ? $par[0] : $par[1]);
        }
        if (count($partes) > 1) {
            $ultima = array_pop($partes);
            return implode(', ', $partes) . ' e ' . $ultima;
        }
        return $partes[0] ?? 'histórico';
    }

    /** Padrão POST-Redirect-GET: o F5 depois de salvar não repete a escrita. */
    private function voltarParaFicha(int $id, string $tipo, string $msg): Response
    {
        flash($tipo, $msg);
        return $this->redirect('admin/jogadores', ['id' => $id]);
    }
}
