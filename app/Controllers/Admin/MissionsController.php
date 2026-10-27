<?php declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\GameRules;
use App\Core\Request;
use App\Core\Response;
use App\Repo\MissionRepo;

/**
 * Tela MISSÕES do painel: /admin/missoes.
 *
 * Governa: `missions` (catálogo) e `mission_rewards` (o que a missão paga). Lê, sem escrever,
 * `player_missions` — porque é ela que decide se uma exclusão é permitida.
 *
 * TRÊS DECISÕES QUE EXPLICAM O RESTO DO ARQUIVO
 * ---------------------------------------------
 * 1. A tela é UMA rota. Lista, formulário e confirmação de exclusão são estados de
 *    /admin/missoes (?nova, ?editar=<id>, ?excluir=<id>), não páginas diferentes. O painel
 *    não carrega JavaScript próprio, então um modal seria um <dialog> que só abre com script:
 *    um estado por query string funciona com o JS desligado, sobrevive ao F5, dá para mandar
 *    o link para outra pessoa e é conferível no `curl`.
 *
 * 2. Erro de validação NÃO redireciona. O POST de salvar renderiza a própria tela de volta,
 *    com o que o operador digitou e uma mensagem por campo. Redirecionar exigiria carregar o
 *    formulário inteiro na sessão — e, quando a sessão perdesse, o operador perderia o texto
 *    do objetivo que acabou de escrever. Sucesso, aí sim, redireciona (POST-redirect-GET).
 *
 * 3. Exclusão é bloqueada, não avisada, quando há jogador em campo. `player_missions` tem
 *    ON DELETE CASCADE: apagar a missão apaga o progresso de quem a aceitou. A tela diz
 *    quantos são e oferece a saída real — marcar como BLOQUEADA tira do quadro sem destruir
 *    nada.
 *
 * Vocabulário: origem, facção, faixa de reputação e teto de esquadrão saem de GameRules;
 * tipo, dificuldade, atividade humana e espécie de recompensa saem de
 * app/Data/admin-missoes.php. Quem une isso ao que o banco já guarda é MissionRepo.
 */
final class MissionsController extends AdminController
{
    /** Teto de caracteres por coluna, igual ao db/schema.sql. */
    private const LIMITES = [
        'slug' => 64, 'name' => 120, 'giver' => 80, 'region' => 80,
        'difficulty' => 16, 'objective' => 400, 'image' => 160, 'human_activity' => 16,
        'requires_faction' => 16, 'requires_tier' => 24,
    ];

    /** Quantas linhas de recompensa o formulário oferece. Cinco cobre a missão mais rica hoje (4). */
    private const LINHAS_RECOMPENSA = 6;

    // ================================================================================ GET

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        return $this->tela($request);
    }

    // =============================================================================== POST

    /**
     * Cria ou atualiza uma missão e o conjunto inteiro das suas recompensas.
     * @param array<string, string> $params
     */
    public function salvar(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $id      = (int)($request->input('id') ?? 0);
        $antes   = $id > 0 ? MissionRepo::porId($id) : null;
        if ($id > 0 && $antes === null) {
            flash('error', 'Missão não encontrada: ela pode ter sido excluída em outra aba.');
            return $this->redirect('admin/missoes');
        }

        [$dados, $recompensas, $erros] = $this->validar($request, $id);

        if ($erros !== []) {
            // Volta o formulário com o que foi digitado — nada de perder o objetivo escrito.
            return $this->tela($request, [
                'modo'        => $id > 0 ? 'editar' : 'nova',
                'id'          => $id,
                'valores'     => $dados,
                'recompensas' => $recompensas,
                'erros'       => $erros,
            ], 422);
        }

        $novoId = MissionRepo::salvar($dados, $recompensas, $id > 0 ? $id : null);

        // A auditoria guarda o DIFF, não o registro inteiro: um dump de 22 colunas a cada
        // salvamento torna a tela de logs ilegível justo quando ela mais importa.
        $mudou = $antes === null ? [] : $this->diferencas($antes, $dados);
        $this->auditar(
            $antes === null ? 'missao_criada' : 'missao_editada',
            'missao',
            $novoId,
            ($antes === null ? 'Missão criada: ' : 'Missão editada: ') . $dados['name'],
            [
                'slug'        => $dados['slug'],
                'recompensas' => count($recompensas),
            ] + ($mudou !== [] ? ['mudou' => $mudou] : [])
        );

        flash('ok', $antes === null
            ? 'Missão "' . $dados['name'] . '" criada.'
            : 'Missão "' . $dados['name'] . '" atualizada.');

        return $this->redirect('admin/missoes', $this->filtrosDaUrl($request) + ['destaque' => (string)$novoId]);
    }

    /**
     * Exclui uma missão — e recusa quando há jogador em campo com ela.
     * @param array<string, string> $params
     */
    public function excluir(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $id     = (int)($request->input('id') ?? 0);
        $missao = MissionRepo::porId($id);

        if ($missao === null) {
            flash('error', 'Missão não encontrada: ela pode já ter sido excluída.');
            return $this->redirect('admin/missoes');
        }

        // A checagem é refeita AQUI, e não só na tela de confirmação: entre abrir a
        // confirmação e clicar em excluir, um jogador pode ter aceitado a missão.
        $uso = MissionRepo::usoPorJogadores($id);
        if ($uso['em_campo'] > 0) {
            $this->auditar('missao_exclusao_bloqueada', 'missao', $id,
                'Exclusão recusada: ' . $uso['em_campo'] . ' jogador(es) em campo.',
                ['slug' => (string)$missao['slug'], 'em_campo' => $uso['em_campo']]);
            flash('error', sprintf(
                'A missão "%s" não foi excluída: %d jogador(es) estão com ela em campo agora.',
                (string)$missao['name'],
                $uso['em_campo']
            ));
            return $this->redirect('admin/missoes', ['editar' => (string)$id]);
        }

        $recompensas = MissionRepo::recompensasDe([$id])[$id] ?? [];
        MissionRepo::excluir($id);

        $this->auditar('missao_excluida', 'missao', $id,
            'Missão excluída: ' . (string)$missao['name'],
            [
                'slug'             => (string)$missao['slug'],
                'tipo'             => (string)$missao['type'],
                'origem'           => (string)$missao['origin'],
                'recompensas'      => count($recompensas),
                'registros_jogador'=> $uso['total'],
            ]);

        flash('ok', 'Missão "' . (string)$missao['name'] . '" excluída.');
        return $this->redirect('admin/missoes', $this->filtrosDaUrl($request));
    }

    // ============================================================================== tela

    /**
     * Monta a tela inteira. É o mesmo método para o GET da lista e para o POST que voltou
     * com erro — a diferença viaja em $forcar.
     *
     * @param array<string,mixed> $forcar
     */
    private function tela(Request $request, array $forcar = [], int $status = 200): Response
    {
        $txt   = data_mock('admin-missoes');
        $vocab = MissionRepo::vocabulario();

        /* Os filtros são lidos do POST TAMBÉM: quando o formulário volta com erro de
           validação, a URL é /admin/missoes/salvar e a query string ficou para trás — sem
           isto, o operador perderia a busca e a página em que estava justamente no momento
           em que precisa voltar para elas. O formulário reenvia os filtros em campos ocultos. */
        $par = static fn(string $k): string => (string)($request->query($k) ?? $request->input($k) ?? '');

        $filtros = [
            'q'           => $par('q'),
            'tipo'        => $this->naLista($par('tipo'), $vocab['tipos']),
            'origem'      => $this->naLista($par('origem'), $vocab['origens']),
            'dificuldade' => $this->naLista($par('dificuldade'), $vocab['dificuldades']),
            'estado'      => $this->naLista($par('estado'), (array)($txt['estados'] ?? [])),
            'ordem'       => $this->naLista($par('ordem'), (array)($txt['ordenacoes'] ?? [])) ?: 'ordem',
            'pagina'      => max(1, (int)($par('pagina') ?: 1)),
        ];

        $lista = MissionRepo::listar($filtros);
        $ids   = array_map(static fn(array $l): int => (int)$l['id'], $lista['linhas']);
        $recs  = MissionRepo::recompensasDe($ids);

        foreach ($lista['linhas'] as $i => $l) {
            $lista['linhas'][$i]['premios'] = $recs[(int)$l['id']] ?? [];
        }

        // ---- qual painel está aberto: lista pura, formulário ou confirmação de exclusão
        $modo = (string)($forcar['modo'] ?? '');
        if ($modo === '') {
            if ($request->query('nova') !== null)          { $modo = 'nova'; }
            elseif ((int)($request->query('editar') ?? 0) > 0)  { $modo = 'editar'; }
            elseif ((int)($request->query('excluir') ?? 0) > 0) { $modo = 'excluir'; }
        }

        $edicao = null;
        $alvo   = null;

        if ($modo === 'nova' || $modo === 'editar') {
            $id      = (int)($forcar['id'] ?? $request->query('editar') ?? 0);
            $original= $id > 0 ? MissionRepo::porId($id) : null;
            if ($modo === 'editar' && $original === null) {
                $modo = '';   // a missão sumiu (outra aba): a lista se explica sozinha
            } else {
                $premios = [];
                if (isset($forcar['recompensas'])) {
                    $premios = (array)$forcar['recompensas'];
                } elseif ($original !== null) {
                    // A missão em edição pode estar fora da página atual da lista — daí a
                    // busca própria, e não $recs, que só cobre as linhas visíveis.
                    $premios = MissionRepo::recompensasDe([(int)$original['id']])[(int)$original['id']] ?? [];
                }
                $edicao = [
                    'id'          => $original !== null ? (int)$original['id'] : 0,
                    'valores'     => isset($forcar['valores'])
                        ? (array)$forcar['valores']
                        : $this->valoresDe($original),
                    'recompensas' => $premios,
                    'erros'       => (array)($forcar['erros'] ?? []),
                    'uso'         => $original !== null ? MissionRepo::usoPorJogadores((int)$original['id']) : null,
                ];
            }
        } elseif ($modo === 'excluir') {
            $id    = (int)($request->query('excluir') ?? 0);
            $alvoM = MissionRepo::porId($id);
            if ($alvoM === null) {
                $modo = '';
            } else {
                $alvo = [
                    'missao'      => $alvoM,
                    'uso'         => MissionRepo::usoPorJogadores($id),
                    'recompensas' => MissionRepo::recompensasDe([$id])[$id] ?? [],
                ];
            }
        }

        $page = $this->adminPage(
            'missoes',
            (string)($txt['titulo'] ?? 'Missões'),
            (string)($txt['subtitulo'] ?? '')
        );

        return $this->render($page['slug'], $page, [
            'cromo'      => $txt,
            'vocab'      => $vocab,
            'resumo'     => MissionRepo::resumo(),
            'lista'      => $lista,
            'filtros'    => $filtros,
            'modo'       => $modo,
            'edicao'     => $edicao,
            'alvo'       => $alvo,
            'itens'      => MissionRepo::itens(),
            'sugestoes'  => MissionRepo::sugestoes(),
            'squad_max'  => GameRules::SQUAD_MAX,
            'destaque'   => (int)($request->query('destaque') ?? 0),
            'linhas_recompensa' => self::LINHAS_RECOMPENSA,
        ], 'admin', $status);
    }

    // ========================================================================= validação

    /**
     * Lê o POST, sanea e valida. Devolve SEMPRE os três: os dados prontos para o banco, as
     * recompensas e os erros por campo. Com erros, os dados voltam para reencher o formulário
     * — por isso a saneação acontece antes da validação, e não depois.
     *
     * @return array{0:array<string,mixed>, 1:list<array{kind:string,amount:int,item_id:?int}>, 2:array<string,string>}
     */
    private function validar(Request $request, int $id): array
    {
        $txt   = data_mock('admin-missoes');
        $vocab = MissionRepo::vocabulario();
        $erros = [];

        $texto = fn(string $campo): string => mb_substr((string)($request->input($campo) ?? ''), 0, self::LIMITES[$campo] ?? 255);

        // ---------------------------------------------------------------- identidade
        $slug = mb_strtolower($texto('slug'));
        $slug = trim(preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '', '-');
        $nome = $texto('name');

        if ($slug === '') {
            $erros['slug'] = 'Informe o slug — é ele que liga a missão ao progresso do jogador.';
        } elseif (mb_strlen($slug) < 3) {
            $erros['slug'] = 'O slug precisa de pelo menos 3 caracteres.';
        } elseif (MissionRepo::slugEmUso($slug, $id)) {
            $erros['slug'] = 'Já existe outra missão com este slug.';
        }
        if ($nome === '') {
            $erros['name'] = 'Informe o nome que o jogador lê no quadro de contratos.';
        }

        // ------------------------------------------------------------------- operação
        $tipo = (string)($request->input('type') ?? '');
        if (!isset($vocab['tipos'][$tipo])) {
            $erros['type'] = 'Escolha um tipo de contrato.';
            $tipo = array_key_first($vocab['tipos']) ?? 'contrato';
        }
        $origem = (string)($request->input('origin') ?? '');
        if (!isset($vocab['origens'][$origem])) {
            $erros['origin'] = 'Escolha de quem vem esta missão.';
            $origem = 'hunter_command';
        }
        $dificuldade = (string)($request->input('difficulty') ?? '');
        if ($dificuldade !== '' && !isset($vocab['dificuldades'][$dificuldade])) {
            $erros['difficulty'] = 'Dificuldade desconhecida.';
            $dificuldade = '';
        }
        $atividade = (string)($request->input('human_activity') ?? '');
        if ($atividade !== '' && !isset($vocab['atividades'][$atividade])) {
            $erros['human_activity'] = 'Escala desconhecida.';
            $atividade = '';
        }

        $duracao = $this->inteiro($request, 'duration_min', 1, 600, $erros, 'A duração vai de 1 a 600 minutos.');
        $nivel   = $this->inteiro($request, 'level_req', 0, 999, $erros, 'O nível mínimo vai de 0 a 999.');
        $meta    = $this->inteiro($request, 'target', 0, 32767, $erros, 'A meta vai de 0 a 32767.');
        $ordem   = $this->inteiro($request, 'sort_order', 0, 65535, $erros, 'A ordem vai de 0 a 65535.') ?? 0;
        $ato     = $this->inteiro($request, 'act', 1, 4, $erros, 'O ato da campanha vai de 1 a 4.');

        // Ato é conceito de campanha. Guardá-lo numa diária cria um dado que ninguém lê e
        // que aparece como ruído na próxima edição — melhor recusar do que gravar calado.
        if ($ato !== null && $tipo !== 'principal') {
            $erros['act'] = 'Ato só existe em missão do tipo PRINCIPAL.';
        }

        // ----------------------------------------------------------------- esquadrão
        $teto  = GameRules::SQUAD_MAX;
        $sqMin = $this->inteiro($request, 'squad_min', 1, $teto, $erros, 'O mínimo do esquadrão vai de 1 a ' . $teto . '.') ?? 1;
        $sqMax = $this->inteiro($request, 'squad_max', 1, $teto, $erros, 'Esquadrão Hunter tem no máximo ' . $teto . ' operadores.') ?? $teto;
        if ($sqMin > $sqMax) {
            $erros['squad_min'] = 'O mínimo não pode ser maior que o máximo.';
        }

        // ---------------------------------------------------------------- requisitos
        $faccao = (string)($request->input('requires_faction') ?? '');
        if ($faccao !== '' && !isset($vocab['faccoes'][$faccao])) {
            $erros['requires_faction'] = 'Facção desconhecida.';
            $faccao = '';
        }
        $faixa = (string)($request->input('requires_tier') ?? '');
        if ($faixa !== '' && !isset($vocab['faixas'][$faixa])) {
            $erros['requires_tier'] = 'Faixa de reputação desconhecida.';
            $faixa = '';
        }
        $rep = $this->inteiro($request, 'requires_rep', 0, 999999, $erros, 'A reputação mínima vai de 0 a 999999.');

        // Faixa e reputação são requisitos DE UMA FACÇÃO. Sem facção exigida, o lado do
        // jogador nem lê esses campos (ver MissionsController::monta): gravá-los seria
        // deixar no banco um requisito que não vale — e que reaparece se alguém exigir
        // uma facção meses depois.
        if ($faccao === '' && ($faixa !== '' || $rep !== null)) {
            $erros['requires_faction'] = 'Escolha a facção: faixa e reputação são requisitos dela.';
        }

        $dados = [
            'slug'         => $slug,
            'name'         => $nome,
            'giver'        => $this->ouNulo($texto('giver')),
            'region'       => $this->ouNulo($texto('region')),
            'type'         => $tipo,
            'difficulty'   => $this->ouNulo($dificuldade),
            'duration_min' => $duracao,
            // `players_max` é a coluna legada que a HUD ainda lê (limitada por SQUAD_MAX).
            // O painel não a expõe: ela é o teto do esquadrão, e dois campos para o mesmo
            // número só existem para divergir.
            'players_max'  => $sqMax,
            'level_req'    => $nivel,
            'objective'    => $this->ouNulo($texto('objective')),
            'target'       => $meta,
            'image'        => $this->ouNulo($texto('image')),
            'locked'       => $request->input('locked') !== null ? 1 : 0,
            'sort_order'   => $ordem,
            'origin'       => $origem,
            'act'          => $tipo === 'principal' ? $ato : null,
            'requires_faction' => $this->ouNulo($faccao),
            'requires_tier'    => $faccao !== '' ? $this->ouNulo($faixa) : null,
            'requires_rep'     => $faccao !== '' ? $rep : null,
            'squad_min'    => $sqMin,
            'squad_max'    => $sqMax,
            'human_activity' => $this->ouNulo($atividade),
        ];

        return [$dados, ...$this->recompensasDoPost($request, $vocab, $erros)];
    }

    /**
     * Lê as linhas de recompensa do POST. Linha sem espécie escolhida é linha vazia — o
     * formulário sempre oferece linhas a mais, e linha em branco não é erro.
     *
     * @param array<string,mixed> $vocab @param array<string,string> $erros
     * @return array{0:list<array{kind:string,amount:int,item_id:?int}>, 1:array<string,string>}
     */
    private function recompensasDoPost(Request $request, array $vocab, array $erros): array
    {
        $especies = (array)($vocab['recompensas'] ?? []);
        $kinds    = $request->inputArray('rec_kind');
        $amounts  = $request->inputArray('rec_amount');
        $itens    = $request->inputArray('rec_item');

        $catalogo = [];
        foreach (MissionRepo::itens() as $it) {
            $catalogo[(int)$it['id']] = true;
        }

        $out  = [];
        $usadas = [];
        foreach ($kinds as $i => $kind) {
            $kind = (string)$kind;
            if ($kind === '') {
                continue;
            }
            $campo = 'rec_' . $i;
            if (!isset($especies[$kind])) {
                $erros[$campo] = 'Espécie de recompensa desconhecida.';
                continue;
            }
            $forma  = (string)($especies[$kind]['forma'] ?? 'quantia');
            $itemId = (int)($itens[$i] ?? 0);
            $qtd    = (int)($amounts[$i] ?? 0);

            if ($forma === 'item') {
                if ($itemId <= 0 || !isset($catalogo[$itemId])) {
                    $erros[$campo] = 'Escolha o item do catálogo que esta recompensa entrega.';
                    continue;
                }
                // Um item é UM item: a coluna `amount` não é quantidade de itens aqui, e
                // deixá-la variar criaria uma recompensa que a HUD não sabe desenhar.
                $out[] = ['kind' => $kind, 'amount' => 1, 'item_id' => $itemId];
                continue;
            }

            // Acesso e intel existem ou não existem — não têm quantia.
            if ($forma === 'unico') {
                $qtd = 1;
            } elseif ($qtd <= 0) {
                $erros[$campo] = 'Informe uma quantidade maior que zero.';
                continue;
            }

            // Duas linhas da mesma espécie viram dois selos iguais no cartão do jogador:
            // é sempre engano de digitação, nunca intenção.
            if (isset($usadas[$kind])) {
                $erros[$campo] = 'Esta espécie já foi usada em outra linha.';
                continue;
            }
            $usadas[$kind] = true;
            $out[] = ['kind' => $kind, 'amount' => $qtd, 'item_id' => null];
        }

        return [$out, $erros];
    }

    /** Inteiro opcional dentro de uma faixa. Fora dela, erro por campo (e null nos dados). */
    private function inteiro(Request $request, string $campo, int $min, int $max, array &$erros, string $msg): ?int
    {
        $bruto = (string)($request->input($campo) ?? '');
        if ($bruto === '') {
            return null;
        }
        if (!preg_match('/^-?\d+$/', $bruto)) {
            $erros[$campo] = $msg;
            return null;
        }
        $n = (int)$bruto;
        if ($n < $min || $n > $max) {
            $erros[$campo] = $msg;
            return null;
        }
        return $n;
    }

    /** String vazia vira NULL: a coluna é anulável e '' seria um valor a mais significando nada. */
    private function ouNulo(string $v): ?string
    {
        return $v === '' ? null : $v;
    }

    /** O valor está entre as chaves da lista? Senão, '' (filtro ignorado). */
    private function naLista(string $v, array $lista): string
    {
        return $v !== '' && isset($lista[$v]) ? $v : '';
    }

    /** Valores iniciais do formulário a partir da linha do banco (ou o padrão do jogo). */
    private function valoresDe(?array $m): array
    {
        if ($m === null) {
            return [
                'slug' => '', 'name' => '', 'giver' => null, 'region' => null,
                'type' => 'contrato', 'difficulty' => null, 'duration_min' => null,
                'level_req' => null, 'objective' => null, 'target' => null, 'image' => null,
                'locked' => 0, 'sort_order' => 0, 'origin' => 'hunter_command', 'act' => null,
                'requires_faction' => null, 'requires_tier' => null, 'requires_rep' => null,
                'squad_min' => 1, 'squad_max' => GameRules::SQUAD_MAX, 'human_activity' => null,
            ];
        }
        return $m;
    }

    /**
     * O que mudou entre a linha antiga e a nova — só os nomes das colunas, para o log ficar
     * legível. O valor antigo continua recuperável na própria trilha de auditoria anterior.
     *
     * @return list<string>
     */
    private function diferencas(array $antes, array $depois): array
    {
        $mudou = [];
        foreach ($depois as $col => $novo) {
            $velho = $antes[$col] ?? null;
            if ((string)$velho !== (string)$novo) {
                $mudou[] = $col;
            }
        }
        return $mudou;
    }

    /** Os filtros da listagem, para o redirect devolver o operador exatamente onde ele estava. */
    private function filtrosDaUrl(Request $request): array
    {
        $out = [];
        foreach (['q', 'tipo', 'origem', 'dificuldade', 'estado', 'ordem', 'pagina'] as $k) {
            $v = (string)($request->input($k) ?? $request->query($k) ?? '');
            if ($v !== '') {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
