<?php declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Repo\AchievementRepo;

/**
 * Tela Conquistas do painel: /admin/conquistas.
 *
 * Governa `achievements` e `achievement_rewards`, e LÊ `player_achievements` — é dessa
 * terceira tabela que sai a única regra de negócio real da tela: conquista que já entrou no
 * histórico de um jogador não se apaga.
 *
 * COMO A TELA SE ORGANIZA
 * -----------------------
 * Uma rota GET só. O que a coluna da direita mostra depende da URL, e não de JavaScript:
 *
 *   /admin/conquistas                -> lista + painel de categorias
 *   /admin/conquistas?novo=1         -> lista + formulário em branco
 *   /admin/conquistas?editar=7       -> lista + formulário preenchido
 *   /admin/conquistas?excluir=7      -> lista + confirmação (ou o bloqueio, com o motivo)
 *
 * A confirmação de exclusão é uma TELA, não um confirm() do navegador: ela mostra quantos
 * jogadores têm registro na conquista antes de perguntar, e continua funcionando com o
 * JavaScript desligado. Nenhuma exclusão acontece por GET — o botão da confirmação é um POST.
 *
 * ONDE MORA CADA COISA
 * --------------------
 *   SQL .................. App\Repo\AchievementRepo
 *   texto e rótulo ....... app/Data/admin-conquistas.php
 *   validação ............ aqui, no SERVIDOR (o formulário HTML não valida nada sozinho)
 *   desenho .............. app/Views/pages/admin-conquistas.php
 *
 * Se um número da tela estiver errado, o defeito está no repositório. Se uma palavra estiver
 * errada, está no Data. Aqui só se decide.
 */
final class AchievementsController extends AdminController
{
    /** Linhas por página. A tabela ainda rola por dentro do cartão; isto é o limite da consulta. */
    private const POR_PAGINA = 20;

    /** Limites que espelham o esquema (achievements) — validar contra o banco é tarde demais. */
    private const MAX_SLUG = 64;
    private const MAX_NOME = 120;
    private const MAX_DESC = 400;
    private const MAX_ICONE = 48;
    private const MAX_META = 1000000;
    private const MAX_ORDEM = 65535;   // SMALLINT UNSIGNED

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        return $this->tela($request);
    }

    /**
     * Cria ou atualiza. O id vem do corpo do POST: `id=0` (ou ausente) cria.
     *
     * Erro de validação NÃO redireciona — a tela é redesenhada com os valores digitados e uma
     * mensagem por campo, em 422. Redirecionar aqui perderia o que o operador escreveu.
     *
     * @param array<string, string> $params
     */
    public function salvar(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $id     = max(0, (int)($request->input('id') ?? 0));
        $antes  = $id > 0 ? AchievementRepo::porId($id) : null;

        if ($id > 0 && $antes === null) {
            // A conquista sumiu entre abrir o formulário e salvar (outro operador apagou).
            flash('error', 'Esta conquista não existe mais — nada foi gravado.');
            return $this->voltar($request);
        }

        [$valores, $recompensas, $erros] = $this->validar($request, $id);

        if ($erros !== []) {
            return $this->tela($request, [
                'form' => [
                    'modo'        => $id > 0 ? 'editar' : 'novo',
                    'id'          => $id,
                    'valores'     => $valores,
                    'recompensas' => $recompensas,
                    'erros'       => $erros,
                    'uso'         => $id > 0 ? AchievementRepo::uso($id) : null,
                ],
            ], 422);
        }

        if ($id > 0) {
            AchievementRepo::atualizar($id, $valores, $recompensas);
            $this->auditar(
                'conquista_editada',
                'conquista',
                $id,
                sprintf($this->frase('auditoria.editada', 'Editou a conquista "%s".'), $valores['name']),
                ['slug' => $valores['slug'], 'mudou' => $this->diferencas($antes ?? [], $valores, $recompensas)]
            );
            flash('ok', 'Conquista "' . $valores['name'] . '" atualizada.');
        } else {
            $id = AchievementRepo::criar($valores, $recompensas);
            $this->auditar(
                'conquista_criada',
                'conquista',
                $id,
                sprintf($this->frase('auditoria.criada', 'Criou a conquista "%s".'), $valores['name']),
                ['slug' => $valores['slug'], 'categoria' => $valores['category'], 'recompensas' => count($recompensas)]
            );
            flash('ok', 'Conquista "' . $valores['name'] . '" criada.');
        }

        // Volta para a lista com os MESMOS filtros e com a linha salva destacada: sem isso o
        // operador salva e não encontra o que acabou de mexer numa lista de dezenas de linhas.
        return $this->voltar($request, ['destaque' => (string)$id]);
    }

    /**
     * Exclui — se puder.
     *
     * A trava é conferida AQUI, de novo, e não só na tela: o botão desarmado da lista é
     * conforto do operador, não segurança. Quem chegar por POST direto encontra a mesma regra.
     *
     * @param array<string, string> $params
     */
    public function excluir(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $id  = max(0, (int)($request->input('id') ?? 0));
        $row = $id > 0 ? AchievementRepo::porId($id) : null;

        if ($row === null) {
            flash('error', 'Conquista não encontrada — nada foi excluído.');
            return $this->voltar($request);
        }

        $uso = AchievementRepo::uso($id);
        if ($uso['desbloqueios'] > 0) {
            $motivo = $this->motivoDaTrava((string)$row['name'], $uso['desbloqueios']);
            $this->auditar(
                'conquista_exclusao_bloqueada',
                'conquista',
                $id,
                sprintf($this->frase('auditoria.bloqueada', 'Tentou excluir "%s", já desbloqueada por jogador.'), $row['name']),
                ['desbloqueios' => $uso['desbloqueios'], 'progresso' => $uso['progresso']]
            );
            flash('error', $motivo);
            // Devolve para a MESMA confirmação: lá o motivo está escrito por extenso, com os
            // números. Um flash sozinho some em cinco segundos e não explica nada.
            return $this->voltar($request, ['excluir' => (string)$id]);
        }

        AchievementRepo::excluir($id);
        $this->auditar(
            'conquista_excluida',
            'conquista',
            $id,
            sprintf($this->frase('auditoria.excluida', 'Excluiu a conquista "%s".'), $row['name']),
            [
                'slug'        => (string)$row['slug'],
                'categoria'   => (string)$row['category'],
                'recompensas' => count((array)$row['recompensas']),
                // Progresso perdido junto (CASCADE) — o log tem que dizer o tamanho do estrago.
                'progresso_perdido' => $uso['progresso'],
            ]
        );

        flash('ok', 'Conquista "' . (string)$row['name'] . '" excluída.');
        return $this->voltar($request);
    }

    /* =====================================================================================
       MONTAGEM DA TELA
       ===================================================================================== */

    /**
     * Desenha a tela inteira. É usada pelo GET e também pelo POST que falhou na validação —
     * por isso recebe $over: os valores digitados vencem os que estavam no banco.
     *
     * @param array<string,mixed> $over
     */
    private function tela(Request $request, array $over = [], int $status = 200): Response
    {
        $cromo   = data_mock('admin-conquistas');
        $filtros = $this->filtros($request);

        $pagina = AchievementRepo::catalogo($filtros + ['por_pagina' => self::POR_PAGINA]);
        $resumo = AchievementRepo::resumo();
        $cats   = AchievementRepo::categorias();

        // Formulário e confirmação são o MESMO lugar na tela: nunca os dois ao mesmo tempo.
        $form     = $over['form'] ?? $this->formularioDaUrl($request);
        $exclusao = $form === null ? $this->exclusaoDaUrl($request) : null;

        $page = $this->adminPage(
            'conquistas',
            'Conquistas',
            'Metas, categorias e recompensas do catálogo'
        );
        $page['actions'] = $this->botaoNova($filtros, $form);

        return $this->render($page['slug'], $page, [
            'cromo'    => $cromo,
            'resumo'   => $resumo,
            'catalogo' => $pagina,
            'cats'     => $cats,
            'filtros'  => $filtros,
            'form'     => $form,
            'exclusao' => $exclusao,
            'itens'    => $form !== null ? AchievementRepo::itens() : [],
            'destaque' => max(0, (int)($request->query('destaque') ?? 0)),
            // O teto de recompensas é regra do repositório; a view desenha os campos por ele
            // em vez de conhecer a classe.
            'max_recompensas' => AchievementRepo::MAX_RECOMPENSAS,

            // A casca lê estas duas chaves. Nenhum número inventado: os três são medidos.
            'admin_kpis' => [
                ['icon' => 'military_tech', 'label' => 'Conquistas',   'value' => num($resumo['conquistas']), 'tone' => 'lime',
                 'sub'  => 'em ' . num($resumo['categorias']) . ' cat.'],
                ['icon' => 'lock_open',     'label' => 'Desbloqueios', 'value' => num($resumo['desbloqueios']), 'tone' => 'cyan',
                 'sub'  => $resumo['jogadores'] > 0 ? 'por ' . num($resumo['jogadores']) . ' jog.' : null],
                ['icon' => 'hourglass_empty', 'label' => 'Sem uso',    'value' => num($resumo['paradas']), 'tone' => $resumo['paradas'] > 0 ? 'amber' : 'lime'],
            ],
        ], 'admin', $status);
    }

    /**
     * Filtros vindos da URL, já domados: ordenação e estado só valem se estiverem na lista, e
     * a categoria só vale se EXISTIR no banco. Assim `?cat=<script>` não vira nada — nem uma
     * consulta, nem um rótulo na tela.
     *
     * @return array{busca:string,categoria:string,estado:string,ordem:string,pagina:int}
     */
    private function filtros(Request $request): array
    {
        $cromo   = data_mock('admin-conquistas');
        $ordens  = array_keys((array)($cromo['ordens'] ?? []));
        $estados = array_keys((array)($cromo['estados'] ?? []));

        /* A tela também é desenhada por um POST que falhou na validação, e nesse caso a URL é
           /admin/conquistas/salvar — sem query nenhuma. Os filtros viajam então nos campos
           ocultos f_* do próprio formulário, para a lista ao lado continuar mostrando o mesmo
           recorte que o operador tinha aberto. */
        $de = $request->isPost()
            ? static fn(string $k): string => (string)($request->input('f_' . $k) ?? '')
            : static fn(string $k): string => (string)($request->query($k) ?? '');

        $categoria = $de('cat');
        if ($categoria !== '') {
            $existentes = array_column(AchievementRepo::categorias(), 'slug');
            $categoria  = in_array($categoria, $existentes, true) ? $categoria : '';
        }

        $ordem  = $de('ordem');
        $estado = $de('estado');

        return [
            'busca'     => mb_substr($de('q'), 0, 80),
            'categoria' => $categoria,
            'estado'    => in_array($estado, $estados, true) ? $estado : '',
            'ordem'     => in_array($ordem, $ordens, true) ? $ordem : 'ordem',
            'pagina'    => max(1, (int)($de('pagina') !== '' ? $de('pagina') : '1')),
        ];
    }

    /**
     * O formulário pedido pela URL (?novo=1 ou ?editar=N), ou null quando a coluna direita
     * deve mostrar o painel de categorias.
     *
     * @return array<string,mixed>|null
     */
    private function formularioDaUrl(Request $request): ?array
    {
        $id = max(0, (int)($request->query('editar') ?? 0));
        if ($id > 0) {
            $row = AchievementRepo::porId($id);
            if ($row === null) {
                flash('error', 'Conquista não encontrada.');
                return null;
            }
            return [
                'modo'    => 'editar',
                'id'      => $id,
                'valores' => [
                    'slug'        => (string)$row['slug'],
                    'name'        => (string)$row['name'],
                    'category'    => (string)$row['category'],
                    'description' => (string)$row['description'],
                    'icon'        => (string)$row['icon'],
                    'target'      => (string)$row['target'],
                    'sort_order'  => (string)$row['sort_order'],
                ],
                'recompensas' => array_map(static fn(array $r): array => [
                    'kind'    => (string)$r['kind'],
                    'amount'  => (string)$r['amount'],
                    'item_id' => $r['item_id'] === null ? '' : (string)$r['item_id'],
                ], (array)$row['recompensas']),
                'erros' => [],
                'uso'   => AchievementRepo::uso($id),
            ];
        }

        if (($request->query('novo') ?? '') === '') {
            return null;
        }

        // Formulário em branco já vem com a categoria filtrada escolhida e a próxima posição
        // livre dela: criar a décima conquista de "combate" não deveria pedir dois campos que
        // a tela já sabe responder.
        $categoria = (string)$this->filtros($request)['categoria'];
        $categoria = $categoria !== '' ? $categoria : $this->primeiraCategoria();

        return [
            'modo'    => 'novo',
            'id'      => 0,
            'valores' => [
                'slug'        => '',
                'name'        => '',
                'category'    => $categoria,
                'description' => '',
                'icon'        => '',
                'target'      => '1',
                'sort_order'  => (string)AchievementRepo::proximaOrdem($categoria),
            ],
            'recompensas' => [],
            'erros'       => [],
            'uso'         => null,
        ];
    }

    /**
     * A confirmação de exclusão pedida por ?excluir=N — com os números que justificam liberar
     * ou travar. É aqui que a tela deixa de perguntar "tem certeza?" e passa a dizer o que
     * exatamente vai acontecer.
     *
     * @return array<string,mixed>|null
     */
    private function exclusaoDaUrl(Request $request): ?array
    {
        $id = max(0, (int)($request->query('excluir') ?? 0));
        if ($id <= 0) {
            return null;
        }
        $row = AchievementRepo::porId($id);
        if ($row === null) {
            flash('error', 'Conquista não encontrada.');
            return null;
        }

        $uso = AchievementRepo::uso($id);
        return [
            'conquista' => $row,
            'uso'       => $uso,
            'bloqueada' => $uso['desbloqueios'] > 0,
            'motivo'    => $uso['desbloqueios'] > 0
                ? $this->motivoDaTrava((string)$row['name'], $uso['desbloqueios'])
                : '',
        ];
    }

    /* =====================================================================================
       VALIDAÇÃO — no servidor, campo a campo
       ===================================================================================== */

    /**
     * Lê o POST e devolve [valores, recompensas, erros]. Os valores voltam SEMPRE (mesmo
     * inválidos): é com eles que o formulário é redesenhado.
     *
     * @return array{0:array<string,string>,1:list<array<string,mixed>>,2:array<string,mixed>}
     */
    private function validar(Request $request, int $id): array
    {
        $cromo = data_mock('admin-conquistas');
        $msg   = (array)($cromo['erros'] ?? []);
        $erros = [];

        $nome = mb_substr((string)($request->input('name') ?? ''), 0, 200);
        $slug = mb_strtolower(mb_substr((string)($request->input('slug') ?? ''), 0, 100));
        // Slug em branco com nome preenchido não é erro: é o caso comum. A tela gera e mostra
        // o resultado na próxima carga, em vez de devolver o formulário por uma formalidade.
        if ($slug === '' && $nome !== '') {
            $slug = $this->slugificar($nome);
        }

        $categoria = (string)($request->input('category') ?? '');
        $descricao = (string)($request->input('description') ?? '');
        $icone     = mb_strtolower(mb_substr((string)($request->input('icon') ?? ''), 0, 60));
        $meta      = (string)($request->input('target') ?? '');
        $ordem     = (string)($request->input('sort_order') ?? '');

        /* ---- slug: é a chave que o jogo usa; é o campo mais rígido da tela ---- */
        if ($slug === '') {
            $erros['slug'] = (string)($msg['slug_vazio'] ?? 'Informe o slug.');
        } elseif (mb_strlen($slug) > self::MAX_SLUG) {
            $erros['slug'] = (string)($msg['slug_longo'] ?? 'Slug longo demais.');
        } elseif (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            $erros['slug'] = (string)($msg['slug_formato'] ?? 'Formato inválido.');
        } else {
            $dono = AchievementRepo::porSlug($slug, $id);
            if ($dono !== null) {
                $erros['slug'] = sprintf((string)($msg['slug_repetido'] ?? 'Slug em uso por %s.'), (string)$dono['name']);
            }
        }

        /* ---- nome ---- */
        if ($nome === '') {
            $erros['name'] = (string)($msg['nome_vazio'] ?? 'Informe o nome.');
        } elseif (mb_strlen($nome) > self::MAX_NOME) {
            $erros['name'] = (string)($msg['nome_longo'] ?? 'Nome longo demais.');
        }

        /* ---- categoria: do catálogo de rótulos OU já existente no banco ----
           A segunda porta existe para não obrigar quem editar uma conquista de categoria
           antiga a trocar a categoria dela só para conseguir salvar o resto. */
        if ($categoria === '' || !in_array($categoria, $this->categoriasAceitas(), true)) {
            $erros['category'] = (string)($msg['categoria'] ?? 'Escolha uma categoria.');
        }

        /* ---- descrição ---- */
        if (mb_strlen($descricao) > self::MAX_DESC) {
            $erros['description'] = sprintf((string)($msg['descricao'] ?? 'Máximo de 400 (%s).'), num(mb_strlen($descricao)));
        }

        /* ---- ícone: ligadura Material Symbols, o mesmo alfabeto que icon() aceita ---- */
        if ($icone !== '' && (!preg_match('/^[a-z0-9_]+$/', $icone) || mb_strlen($icone) > self::MAX_ICONE)) {
            $erros['icon'] = (string)($msg['icone'] ?? 'Ícone inválido.');
        }

        /* ---- meta e ordem: inteiros, dentro do que a coluna aguenta ---- */
        if (!preg_match('/^\d{1,7}$/', $meta) || (int)$meta < 1 || (int)$meta > self::MAX_META) {
            $erros['target'] = (string)($msg['meta'] ?? 'Meta inválida.');
        }
        if ($ordem === '') {
            $ordem = '0';
        }
        if (!preg_match('/^\d{1,5}$/', $ordem) || (int)$ordem > self::MAX_ORDEM) {
            $erros['sort_order'] = (string)($msg['ordem'] ?? 'Ordem inválida.');
        }

        [$recompensas, $errosRec] = $this->validarRecompensas($request, $msg);
        if ($errosRec !== []) {
            $erros['recompensas'] = $errosRec;
        }

        return [
            [
                'slug'        => $slug,
                'name'        => $nome,
                'category'    => $categoria,
                'description' => $descricao,
                'icon'        => $icone,
                'target'      => $meta,
                'sort_order'  => $ordem,
            ],
            $recompensas,
            $erros,
        ];
    }

    /**
     * As linhas de recompensa. Linha com tipo vazio é slot não usado do formulário e some sem
     * reclamar; linha com tipo preenchido tem que fechar.
     *
     * @param array<string,mixed> $msg
     * @return array{0:list<array<string,mixed>>,1:array<int,string>}
     */
    private function validarRecompensas(Request $request, array $msg): array
    {
        $cromo   = data_mock('admin-conquistas');
        $rotulos = (array)($cromo['recompensas'] ?? []);

        $tipos  = $request->inputArray('rec_kind');
        $vals   = $request->inputArray('rec_amount');
        $itens  = $request->inputArray('rec_item');

        $linhas = [];
        $erros  = [];
        $vistos = [];

        foreach ($tipos as $i => $tipo) {
            $tipo = (string)$tipo;
            if ($tipo === '') {
                continue;
            }

            $valor = (string)($vals[$i] ?? '');
            $item  = (string)($itens[$i] ?? '');
            $rotulo = (string)($rotulos[$tipo]['label'] ?? $tipo);

            if (!in_array($tipo, AchievementRepo::RECOMPENSAS, true)) {
                $erros[$i] = (string)($msg['recompensa_tipo'] ?? 'Tipo inválido.');
                continue;
            }

            if ($tipo === 'item') {
                if (!preg_match('/^\d{1,10}$/', $item) || !AchievementRepo::itemExiste((int)$item)) {
                    $erros[$i] = (string)($msg['recompensa_item'] ?? 'Escolha o item.');
                    continue;
                }
                // Quantidade do item: em branco vale 1, que é o que o jogo entrega hoje.
                $qtd = preg_match('/^\d{1,7}$/', $valor) ? max(1, (int)$valor) : 1;
                $linhas[] = ['kind' => 'item', 'amount' => $qtd, 'item_id' => (int)$item];
                continue;
            }

            // xp/cash/points: um número por tipo. Duas linhas de XP na mesma conquista é
            // ambiguidade, não soma — o jogo leria as duas e ninguém saberia o total.
            if (isset($vistos[$tipo])) {
                $erros[$i] = sprintf((string)($msg['recompensa_dupla'] ?? '%s repetido.'), $rotulo);
                continue;
            }
            // O tipo é marcado ANTES de conferir o valor: com XP inválido na primeira linha e
            // XP de novo na segunda, marcar só no fim esconderia a repetição até a próxima
            // tentativa — o operador consertaria uma coisa para descobrir a outra.
            $vistos[$tipo] = true;

            if (!preg_match('/^\d{1,9}$/', $valor) || (int)$valor <= 0) {
                $erros[$i] = sprintf((string)($msg['recompensa_valor'] ?? 'Valor de %s inválido.'), $rotulo);
                continue;
            }

            $linhas[] = ['kind' => $tipo, 'amount' => (int)$valor, 'item_id' => null];
        }

        if (count($linhas) > AchievementRepo::MAX_RECOMPENSAS) {
            $erros[AchievementRepo::MAX_RECOMPENSAS] = sprintf(
                (string)($msg['recompensa_demais'] ?? 'No máximo %s recompensas.'),
                (string)AchievementRepo::MAX_RECOMPENSAS
            );
            $linhas = array_slice($linhas, 0, AchievementRepo::MAX_RECOMPENSAS);
        }

        // Os valores voltam para a tela do jeito que vieram (inclusive os recusados), senão o
        // operador perde o que digitou na linha que errou.
        $devolver = [];
        foreach ($tipos as $i => $tipo) {
            if ((string)$tipo === '') {
                continue;
            }
            $devolver[] = [
                'kind'    => (string)$tipo,
                'amount'  => (string)($vals[$i] ?? ''),
                'item_id' => (string)($itens[$i] ?? ''),
            ];
        }

        return [$erros === [] ? $linhas : $devolver, $erros];
    }

    /* =====================================================================================
       APOIO
       ===================================================================================== */

    /** Categorias que o formulário aceita: as do catálogo de rótulos mais as já usadas no banco. */
    private function categoriasAceitas(): array
    {
        $cromo = data_mock('admin-conquistas');
        return array_values(array_unique(array_merge(
            array_keys((array)($cromo['categorias'] ?? [])),
            array_column(AchievementRepo::categorias(), 'slug')
        )));
    }

    private function primeiraCategoria(): string
    {
        $cromo = data_mock('admin-conquistas');
        $chaves = array_keys((array)($cromo['categorias'] ?? []));
        return (string)($chaves[0] ?? 'combate');
    }

    /** A frase da trava, com os dois números que o operador precisa para entender o "não". */
    private function motivoDaTrava(string $nome, int $desbloqueios): string
    {
        $cromo = data_mock('admin-conquistas');
        $modelo = (string)($cromo['micro']['trava_excluir']
            ?? 'Não dá para excluir: %s já está no histórico de %s.');
        return sprintf(
            $modelo,
            '"' . $nome . '"',
            num($desbloqueios) . ' jogador' . ($desbloqueios === 1 ? '' : 'es')
        );
    }

    /** Texto de app/Data/admin-conquistas.php por caminho pontilhado, com padrão. */
    private function frase(string $caminho, string $padrao): string
    {
        $no = data_mock('admin-conquistas');
        foreach (explode('.', $caminho) as $parte) {
            if (!is_array($no) || !array_key_exists($parte, $no)) {
                return $padrao;
            }
            $no = $no[$parte];
        }
        return is_string($no) ? $no : $padrao;
    }

    /**
     * O que mudou de fato, para o admin_audit_log — sem isto, "editou a conquista" não conta
     * nada e a trilha de auditoria só serve para saber que alguém passou por ali.
     *
     * @param array<string,mixed> $antes @param array<string,string> $depois
     * @param list<array<string,mixed>> $recompensas
     * @return list<string>
     */
    private function diferencas(array $antes, array $depois, array $recompensas): array
    {
        $mudou = [];
        foreach (['slug', 'name', 'category', 'description', 'icon', 'target', 'sort_order'] as $campo) {
            if ((string)($antes[$campo] ?? '') !== (string)($depois[$campo] ?? '')) {
                $mudou[] = $campo;
            }
        }
        if (count((array)($antes['recompensas'] ?? [])) !== count($recompensas)) {
            $mudou[] = 'recompensas';
        }
        return $mudou;
    }

    /** Botão da topbar. Some quando o formulário de criação JÁ está aberto — abrir de novo é nada. */
    private function botaoNova(array $filtros, ?array $form): string
    {
        if (($form['modo'] ?? '') === 'novo') {
            return '<a class="adm-btn adm-btn--ghost" href="' . e(url('admin/conquistas', $this->querystring($filtros)))
                 . '">' . icon('close') . '<span>Fechar</span></a>';
        }
        return '<a class="adm-btn adm-btn--primary" href="'
             . e(url('admin/conquistas', ["novo" => "1"] + $this->querystring($filtros)))
             . '">' . icon('add') . '<span>Nova conquista</span></a>';
    }

    /** Volta para a lista preservando os filtros que estavam na URL do formulário. */
    private function voltar(Request $request, array $extra = []): Response
    {
        // Os filtros viajam no POST (campos ocultos do formulário) para sobreviver ao redirect.
        $filtros = [
            'busca'     => (string)($request->input('f_q') ?? ''),
            'categoria' => (string)($request->input('f_cat') ?? ''),
            'estado'    => (string)($request->input('f_estado') ?? ''),
            'ordem'     => (string)($request->input('f_ordem') ?? ''),
            'pagina'    => max(1, (int)($request->input('f_pagina') ?? 1)),
        ];
        return $this->redirect("admin/conquistas", $extra + $this->querystring($filtros));
    }

    /**
     * Filtros -> query string, sem os vazios. É o que mantém busca, categoria e página nos
     * links da tabela, do formulário e da paginação.
     *
     * @param array<string,mixed> $f
     * @return array<string,string>
     */
    private function querystring(array $f): array
    {
        $q = [];
        if (($f['busca'] ?? '') !== '')     { $q['q'] = (string)$f['busca']; }
        if (($f['categoria'] ?? '') !== '') { $q['cat'] = (string)$f['categoria']; }
        if (($f['estado'] ?? '') !== '')    { $q['estado'] = (string)$f['estado']; }
        if (($f['ordem'] ?? 'ordem') !== 'ordem' && ($f['ordem'] ?? '') !== '') { $q['ordem'] = (string)$f['ordem']; }
        if ((int)($f['pagina'] ?? 1) > 1)   { $q['pagina'] = (string)(int)$f['pagina']; }
        return $q;
    }

    /** "CAÇADOR EXPERIENTE" -> "cacador-experiente". Sem intl: o projeto não depende dele. */
    private function slugificar(string $texto): string
    {
        $t = mb_strtolower(trim($texto));
        $t = strtr($t, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
        $t = preg_replace('/[^a-z0-9]+/', '-', $t) ?? '';
        return trim($t, '-');
    }
}
