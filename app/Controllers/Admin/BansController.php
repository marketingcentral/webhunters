<?php declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Repo\BanRepo;

/**
 * Tela Banimentos do painel: /admin/banimentos.
 *
 * Governa: `bans` (o histórico) e `users.status` (o estado atual da conta). O controller não
 * escreve nem uma nem outra diretamente — quem grava é App\Repo\BanRepo, que mantém as duas
 * em uma transação só. Aqui ficam três coisas, e só três:
 *
 *   1. QUEM PODE — punir é o poder mais afiado do painel, então há um degrau de papel:
 *      ninguém se pune, o dono do jogo é intocável, e conta com papel administrativo só é
 *      punida pelo dono. Sem isso, um admin recém-promovido derruba os colegas e o dono.
 *   2. O QUE É VÁLIDO — validação inteira no SERVIDOR, com mensagem por campo. O formulário
 *      é HTML puro (sem JS nesta tela): o `required` do navegador é conveniência, não guarda.
 *   3. PARA ONDE VOLTA — todo POST termina em redirect (PRG) para a listagem com os MESMOS
 *      filtros que o operador estava usando; F5 depois de punir não pune de novo.
 *
 * O painel de formulário/confirmação é escolhido pela URL (?painel=novo|revogar|remover), e
 * não por JavaScript: a confirmação de uma punição precisa ser um endereço que se possa
 * abrir, revisar e fechar — não um estado que só existe enquanto a aba estiver aberta.
 */
final class BansController extends AdminController
{
    /** Motivo: curto demais não explica nada; a coluna é TEXT, o limite é editorial. */
    private const MOTIVO_MIN = 8;
    private const MOTIVO_MAX = 500;

    /** Prazo máximo de uma punição temporária. Além disso, o tipo honesto é "definitivo". */
    private const PRAZO_MAX_ANOS = 10;

    /** Onde o formulário recusado guarda erros e valores até a próxima renderização. */
    private const SESSAO_FORM = 'adm_bans_form';

    /* ===================================================================== leitura */

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }

        // Punição temporária não tem quem a encerre: não existe agendador no projeto. Quem
        // percebe que o prazo venceu é esta abertura de tela. A conta volta ao ar aqui e a
        // trilha de auditoria registra a volta — devolver acesso sem deixar rastro seria
        // exatamente o tipo de mudança silenciosa que o log existe para impedir.
        $voltaram = BanRepo::reconciliar();
        foreach ($voltaram as $v) {
            $this->auditar(
                'ban_expirado',
                'user',
                $v['id'],
                'Prazo de punição vencido: ' . $v['username'] . ' voltou ao ar.',
                ['de' => $v['de'], 'para' => $v['para']]
            );
        }
        if ($voltaram !== []) {
            // flash() grava na sessão e Controller::render consome na MESMA requisição — o
            // aviso aparece agora, junto da tabela que acabou de mudar por causa dele.
            flash('info', sprintf(
                (string)(data_mock('admin-banimentos')['avisos']['reconciliado'] ?? '%d conta(s) voltaram ao ar.'),
                count($voltaram)
            ));
        }

        $filtros = $this->filtrosDaUrl($request);
        $lista   = BanRepo::listar($filtros);
        $contas  = BanRepo::contagens();
        $cromo   = data_mock('admin-banimentos');

        $page = $this->adminPage(
            'banimentos',
            (string)($cromo['titulo'] ?? 'Banimentos'),
            (string)($cromo['subtitulo'] ?? '')
        );

        return $this->render($page['slug'], $page, [
            'cromo'     => $cromo,
            'lista'     => $lista,
            'contagens' => $contas,
            'filtros'   => $filtros,
            'tipos'     => BanRepo::TIPOS,
            'painel'      => $this->painel($request),
            'form'        => $this->lembrado(),
            'sugestoes'   => BanRepo::contasParaEscolha(),
            'eu'          => $this->admin(),
            'pode_apagar' => $this->ehDono(),

            // A casca lê estas duas: os números do topo do painel são os desta tela.
            'admin_kpis' => [
                ['icon' => 'gavel',      'label' => 'Punições em vigor', 'value' => num($contas['ativo']),  'tone' => 'red'],
                ['icon' => 'person_off', 'label' => 'Contas fora do ar', 'value' => num($contas['contas']), 'tone' => 'amber'],
                ['icon' => 'history',    'label' => 'Histórico',         'value' => num($contas['total']),  'tone' => 'cyan'],
            ],
        ]);
    }

    /* ===================================================================== escrita */

    /**
     * Duas ações entram por aqui porque a rota registrada é uma só: `acao=aplicar` cria a
     * punição, `acao=revogar` a derruba. Separar em dois endpoints exigiria mexer em
     * app/routes.php, que é ponto de conflito entre as telas do painel.
     *
     * @param array<string, string> $params
     */
    public function salvar(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        return match ((string)$request->input('acao', '')) {
            'aplicar' => $this->aplicar($request),
            'revogar' => $this->revogarPunicao($request),
            default   => $this->voltar($request, 'error', 'Ação desconhecida — nada foi alterado.'),
        };
    }

    /**
     * Apaga uma linha do histórico. Duas travas, e as duas devolvem a razão por escrito:
     *
     *   - punição EM VIGOR não é apagada: sumir com a linha deixaria a conta bloqueada sem
     *     nenhum registro dizendo por quê, e o próximo operador não teria o que revogar;
     *   - só o DONO apaga: para um administrador comum, o caminho de desfazer é revogar, que
     *     preserva a prova. Apagar prova é decisão de dono.
     *
     * O que a linha dizia vai inteiro para o `meta` da auditoria antes do DELETE: o histórico
     * da conta perde a punição, a trilha do painel não.
     *
     * @param array<string, string> $params
     */
    public function excluir(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $id  = (int)$request->input('id', '0');
        $ban = BanRepo::porId($id);

        if ($ban === null) {
            return $this->voltar($request, 'error', 'Esse registro de punição não existe mais.');
        }
        if (!$this->ehDono()) {
            return $this->voltar($request, 'error', 'Apagar histórico de punição é exclusivo do dono do jogo.');
        }
        if ((string)$ban['estado'] === 'ativo') {
            return $this->voltar(
                $request,
                'error',
                'A punição de ' . (string)$ban['alvo_nome'] . ' está em vigor. Revogue antes de apagar.'
            );
        }

        if (!BanRepo::remover($id)) {
            return $this->voltar($request, 'error', 'Não foi possível apagar o registro. Recarregue a tela e tente de novo.');
        }

        $this->auditar(
            'ban_removido',
            'ban',
            $id,
            'Registro de punição de ' . (string)$ban['alvo_nome'] . ' apagado do histórico.',
            [
                'user_id'    => (int)$ban['user_id'],
                'conta'      => (string)$ban['alvo_nome'],
                'tipo'       => (string)$ban['kind'],
                'motivo'     => (string)($ban['reason'] ?? ''),
                'aplicado_em'=> (string)$ban['created_at'],
                'expira_em'  => $ban['expires_at'],
                'revogado_em'=> $ban['revoked_at'],
                'estado'     => (string)$ban['estado'],
            ]
        );

        return $this->voltar($request, 'ok', sprintf(
            (string)(data_mock('admin-banimentos')['avisos']['removido'] ?? 'Registro #%d apagado.'),
            $id
        ));
    }

    /* ===================================================================== ações */

    private function aplicar(Request $request): Response
    {
        $bruto = [
            'alvo'   => trim((string)$request->input('alvo', '')),
            'tipo'   => (string)$request->input('tipo', ''),
            'prazo'  => trim((string)$request->input('prazo', '')),
            'motivo' => trim((string)$request->input('motivo', '')),
        ];
        $erros = [];

        /* --- alvo -------------------------------------------------------- */
        $alvo = null;
        if ($bruto['alvo'] === '') {
            $erros['alvo'] = 'Informe a conta que será punida.';
        } else {
            $alvo = BanRepo::usuarioPorLogin($bruto['alvo']);
            if ($alvo === null) {
                $erros['alvo'] = 'Nenhuma conta com esse usuário, e-mail ou ID.';
            } elseif ((int)$alvo['id'] === $this->adminId()) {
                $erros['alvo'] = 'Você não pode punir a própria conta.';
            } elseif ((string)$alvo['role'] === 'owner') {
                $erros['alvo'] = 'A conta do dono do jogo não pode ser punida pelo painel.';
            } elseif ((string)$alvo['role'] === 'admin' && !$this->ehDono()) {
                $erros['alvo'] = 'Punir uma conta administrativa é exclusivo do dono do jogo.';
            } elseif (($vigente = BanRepo::vigenteDe((int)$alvo['id'])) !== null) {
                $erros['alvo'] = 'Essa conta já tem uma punição em vigor (registro #'
                    . (int)$vigente['id'] . '). Revogue a atual antes de aplicar outra.';
            }
        }

        /* --- tipo -------------------------------------------------------- */
        if (!isset(BanRepo::TIPOS[$bruto['tipo']])) {
            $erros['tipo'] = 'Escolha o tipo de punição.';
        }

        /* --- prazo ------------------------------------------------------- */
        $expiraEm = null;
        $temPrazo = isset(BanRepo::TIPOS[$bruto['tipo']]) && BanRepo::TIPOS[$bruto['tipo']]['prazo'];
        if ($temPrazo) {
            if ($bruto['prazo'] === '') {
                $erros['prazo'] = 'Punição com prazo precisa de uma data de término.';
            } else {
                // O <input type="datetime-local"> manda 2026-09-20T14:30 (às vezes com :ss).
                $ts = strtotime(str_replace('T', ' ', $bruto['prazo']));
                if ($ts === false) {
                    $erros['prazo'] = 'Data inválida.';
                } elseif ($ts <= time()) {
                    $erros['prazo'] = 'O prazo precisa estar no futuro — no passado a punição já nasceria vencida.';
                } elseif ($ts > strtotime('+' . self::PRAZO_MAX_ANOS . ' years')) {
                    $erros['prazo'] = 'Acima de ' . self::PRAZO_MAX_ANOS . ' anos, use "Definitivo" — é o que isso significa.';
                } else {
                    $expiraEm = date('Y-m-d H:i:s', $ts);
                }
            }
        }

        /* --- motivo ------------------------------------------------------ */
        $erroMotivo = $this->validarMotivo($bruto['motivo']);
        if ($erroMotivo !== null) {
            $erros['motivo'] = $erroMotivo;
        }

        if ($erros !== [] || $alvo === null) {
            return $this->recusar($request, 'novo', $bruto, $erros);
        }

        $banId = BanRepo::aplicar(
            (int)$alvo['id'],
            $this->adminId(),
            $bruto['tipo'],
            mb_substr($bruto['motivo'], 0, self::MOTIVO_MAX),
            $expiraEm
        );

        $this->auditar(
            'ban_aplicado',
            'ban',
            $banId,
            'Punição aplicada a ' . (string)$alvo['username'] . ' ('
                . BanRepo::TIPOS[$bruto['tipo']]['rotulo'] . ').',
            [
                'user_id'   => (int)$alvo['id'],
                'conta'     => (string)$alvo['username'],
                'tipo'      => $bruto['tipo'],
                'expira_em' => $expiraEm,
                'motivo'    => mb_substr($bruto['motivo'], 0, self::MOTIVO_MAX),
            ]
        );

        return $this->voltar($request, 'ok', sprintf(
            (string)(data_mock('admin-banimentos')['avisos']['aplicado'] ?? 'Punição aplicada a %s.'),
            (string)$alvo['username']
        ));
    }

    private function revogarPunicao(Request $request): Response
    {
        $id     = (int)$request->input('id', '0');
        $motivo = trim((string)$request->input('motivo', ''));
        $ban    = BanRepo::porId($id);

        if ($ban === null) {
            return $this->voltar($request, 'error', 'Esse registro de punição não existe mais.');
        }
        if ((string)$ban['estado'] === 'revogado') {
            return $this->voltar($request, 'error', 'Essa punição já havia sido revogada por '
                . (string)($ban['revogador_nome'] ?? 'outro operador') . '.');
        }
        if ((string)$ban['alvo_papel'] === 'admin' && !$this->ehDono()) {
            return $this->voltar($request, 'error', 'Punição de conta administrativa só é revogada pelo dono do jogo.');
        }

        $erroMotivo = $this->validarMotivo($motivo);
        if ($erroMotivo !== null) {
            return $this->recusar($request, 'revogar', ['id' => (string)$id, 'motivo' => $motivo], ['motivo' => $erroMotivo]);
        }

        // Um prazo já vencido não bloqueia mais ninguém: revogar aqui seria carimbar um
        // perdão em cima de algo que a própria data já encerrou, e o histórico passaria a
        // creditar ao operador uma soltura que não foi dele.
        if ((string)$ban['estado'] === 'expirado') {
            return $this->voltar($request, 'error', 'Essa punição já venceu por prazo — não há o que revogar.');
        }

        if (!BanRepo::revogar($id, $this->adminId(), mb_substr($motivo, 0, self::MOTIVO_MAX))) {
            return $this->voltar($request, 'error', 'A punição mudou de estado enquanto você escrevia. Recarregue a tela.');
        }

        $this->auditar(
            'ban_revogado',
            'ban',
            $id,
            'Punição de ' . (string)$ban['alvo_nome'] . ' revogada.',
            [
                'user_id' => (int)$ban['user_id'],
                'conta'   => (string)$ban['alvo_nome'],
                'tipo'    => (string)$ban['kind'],
                'motivo'  => mb_substr($motivo, 0, self::MOTIVO_MAX),
            ]
        );

        return $this->voltar($request, 'ok', sprintf(
            (string)(data_mock('admin-banimentos')['avisos']['revogado'] ?? 'Punição de %s revogada.'),
            (string)$ban['alvo_nome']
        ));
    }

    /* ===================================================================== apoio */

    /** @return string|null a frase de erro, ou null quando o motivo serve */
    private function validarMotivo(string $motivo): ?string
    {
        $n = mb_strlen($motivo);
        if ($n === 0) {
            return 'Escreva o motivo — é o que vai constar no histórico da conta.';
        }
        if ($n < self::MOTIVO_MIN) {
            return 'Muito curto: descreva em pelo menos ' . self::MOTIVO_MIN . ' caracteres.';
        }
        if ($n > self::MOTIVO_MAX) {
            return 'Passou de ' . self::MOTIVO_MAX . ' caracteres (você escreveu ' . $n . ').';
        }
        return null;
    }

    /** Papel que autoriza as decisões irreversíveis desta tela. */
    private function ehDono(): bool
    {
        return (string)($this->admin()['role'] ?? '') === 'owner';
    }

    /**
     * Filtros vindos da URL, já normalizados. Nada daqui chega ao SQL como texto: o repo
     * confere estado, tipo e ordem contra as próprias listas brancas.
     *
     * @return array{busca:string,estado:string,tipo:string,ordem:string,pagina:int}
     */
    private function filtrosDaUrl(Request $request): array
    {
        $estado = (string)$request->query('estado', '');
        $tipo   = (string)$request->query('tipo', '');
        $ordem  = (string)$request->query('ordem', '');

        return [
            'busca'  => trim(mb_substr((string)$request->query('q', ''), 0, 80)),
            'estado' => in_array($estado, BanRepo::ESTADOS, true) ? $estado : '',
            'tipo'   => isset(BanRepo::TIPOS[$tipo]) ? $tipo : '',
            'ordem'  => in_array($ordem, BanRepo::ordensValidas(), true) ? $ordem : 'recente',
            'pagina' => max(1, (int)$request->query('p', '1')),
        ];
    }

    /**
     * Qual painel abre acima da tabela e com que dado. Sai null quando a URL não pede nenhum
     * — ou quando pede um que não faz sentido (registro apagado, punição já encerrada): um
     * formulário de revogação sobre um ban revogado é uma armadilha, não uma tela.
     *
     * @return array{modo:string,ban:array<string,mixed>|null,bloqueio:string}|null
     */
    private function painel(Request $request): ?array
    {
        $modo = (string)$request->query('painel', '');
        if (!in_array($modo, ['novo', 'revogar', 'remover'], true)) {
            // Formulário recusado reabre sozinho: sem isso o operador voltaria para a lista
            // sem ver o que estava errado no que ele digitou.
            $lembrado = $this->lembrado();
            if ($lembrado['erros'] !== []) {
                return ['modo' => (string)$lembrado['modo'], 'ban' => $this->banDoFormulario($lembrado), 'bloqueio' => ''];
            }
            return null;
        }

        if ($modo === 'novo') {
            return ['modo' => 'novo', 'ban' => null, 'bloqueio' => ''];
        }

        $ban = BanRepo::porId((int)$request->query('id', '0'));
        if ($ban === null) {
            return null;
        }

        $cromo = data_mock('admin-banimentos');
        if ($modo === 'revogar') {
            return (string)$ban['estado'] === 'ativo'
                ? ['modo' => 'revogar', 'ban' => $ban, 'bloqueio' => '']
                : null;
        }

        // remover: o painel abre mesmo bloqueado — a razão da recusa é o conteúdo dele.
        $bloqueio = '';
        if (!$this->ehDono()) {
            $bloqueio = (string)($cromo['remover']['bloqueio']['papel'] ?? '');
        } elseif ((string)$ban['estado'] === 'ativo') {
            $bloqueio = (string)($cromo['remover']['bloqueio']['ativa'] ?? '');
        }
        return ['modo' => 'remover', 'ban' => $ban, 'bloqueio' => $bloqueio];
    }

    /** @param array{valores:array<string,string>} $lembrado */
    private function banDoFormulario(array $lembrado): ?array
    {
        $id = (int)($lembrado['valores']['id'] ?? 0);
        return $id > 0 ? BanRepo::porId($id) : null;
    }

    /**
     * Recusa o formulário sem perder o que foi digitado: erros e valores vão para a sessão e
     * a resposta é um redirect (PRG). Recarregar a página de erro não reenvia POST nenhum.
     *
     * @param array<string,string> $valores @param array<string,string> $erros
     */
    private function recusar(Request $request, string $modo, array $valores, array $erros): Response
    {
        $_SESSION[self::SESSAO_FORM] = ['modo' => $modo, 'valores' => $valores, 'erros' => $erros];
        flash('error', count($erros) === 1
            ? 'Corrija o campo destacado abaixo.'
            : 'Corrija os ' . count($erros) . ' campos destacados abaixo.');

        return $this->voltar($request);
    }

    /**
     * Lê e CONSOME o formulário recusado. Consumir importa: sem isso o erro reapareceria na
     * próxima visita à tela, muito depois de ter sido corrigido.
     *
     * @return array{modo:string,valores:array<string,string>,erros:array<string,string>}
     */
    private function lembrado(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $bruto = $_SESSION[self::SESSAO_FORM] ?? null;
        unset($_SESSION[self::SESSAO_FORM]);

        return $cache = [
            'modo'    => is_array($bruto) ? (string)($bruto['modo'] ?? '') : '',
            'valores' => is_array($bruto) && is_array($bruto['valores'] ?? null) ? $bruto['valores'] : [],
            'erros'   => is_array($bruto) && is_array($bruto['erros'] ?? null) ? $bruto['erros'] : [],
        ];
    }

    /**
     * Volta para a listagem preservando busca, filtros, ordem e página — os campos ocultos
     * `f_*` do formulário. Recuperar isso do POST, e não do Referer, é o que garante que o
     * operador caia exatamente na linha em que estava depois de punir alguém.
     */
    private function voltar(Request $request, ?string $tom = null, ?string $mensagem = null): Response
    {
        if ($tom !== null && $mensagem !== null) {
            flash($tom, $mensagem);
        }

        $q = [];
        foreach (['q' => 'f_q', 'estado' => 'f_estado', 'tipo' => 'f_tipo', 'ordem' => 'f_ordem', 'p' => 'f_p'] as $destino => $campo) {
            $valor = trim((string)$request->input($campo, ''));
            if ($valor !== '') {
                $q[$destino] = mb_substr($valor, 0, 80);
            }
        }

        return $this->redirect('admin/banimentos', $q);
    }
}
