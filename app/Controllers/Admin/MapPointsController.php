<?php declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Repo\MapPointsRepo;

/**
 * Tela CRIAR PONTOS NO MAPA do painel: /admin/mapas/pontos.
 *
 * Governa `map_point_types`, o catálogo de ícones pré-fabricados que o editor de mapa
 * arrasta para cima da arte.
 *
 * POR QUE ESTA TELA EXISTE, SEPARADA DO EDITOR
 * São duas tarefas com ritmos diferentes. Cadastrar "Café" acontece uma vez; posicionar
 * cafés no mundo acontece toda semana. Misturar as duas obrigaria quem só quer mover um
 * ícone a passar por um formulário de cadastro, e quem só quer criar um tipo a abrir um mapa
 * que não vai editar. Aqui se define O QUE EXISTE; no editor se decide ONDE FICA.
 *
 * TRÊS DECISÕES QUE EXPLICAM O CÓDIGO
 *
 * 1. `kind` continua existindo, e o ponto não o substitui. `map_markers.kind` é o vocabulário
 *    que o JOGO entende: é ele que decide a camada do filtro, se a extração fica bloqueada
 *    para Sobrevivente, se a base pulsa quando está sob ataque. O ponto escolhe o DESENHO e a
 *    COR, e diz qual kind gravar. Por isso um ponto novo é sempre obrigado a apontar para um
 *    kind já conhecido: ícone inventado é bem-vindo, comportamento inventado não.
 *
 * 2. Ponto de sistema não se apaga. Os tipos que nascem com o jogo (extração, base Hunter,
 *    ninho Veyra) têm regra em código atrás deles. O painel edita rótulo, cor e desenho —
 *    mas o botão de excluir não existe para eles, e o servidor recusa mesmo que alguém poste
 *    direto, porque apagar não removeria um ícone, removeria uma regra.
 *
 * 3. Validação NÃO redireciona: redesenha a tela com 422 e com o que foi digitado ainda nos
 *    campos. É o mesmo contrato das outras telas do painel (ver MapsController).
 */
final class MapPointsController extends AdminController
{
    /** Cores que o CSS do mapa sabe pintar (assets/css/pages/mapa.css: .mk--<cor>). */
    private const CORES = ['white', 'lime', 'cyan', 'amber', 'gold', 'red', 'purple', 'muted'];

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        return $this->tela($request);
    }

    /** @param array<string, string> $params */
    public function salvar(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $id    = (int)$request->input('id', '0');
        $antes = $id > 0 ? MapPointsRepo::porId($id) : null;
        if ($id > 0 && $antes === null) {
            return $this->voltar('Ponto não encontrado — nada foi alterado.', 'error');
        }

        $form  = $this->formDoPost($request);
        $erros = $this->validar($form, $id, $antes);
        if ($erros !== []) {
            return $this->tela($request, ['form' => $form, 'erros' => $erros, 'edicao' => true, 'alvo' => $antes], 422);
        }

        $dados = [
            'slug'        => $form['slug'],
            'label'       => $form['label'],
            'category'    => $form['category'],
            'kind'        => $form['kind'],
            'icon'        => $form['icon_origem'] . ':' . $form['icon_valor'],
            'color'       => $form['color'],
            'layer'       => $form['layer'] !== '' ? $form['layer'] : null,
            'show_label'  => $form['show_label'] ? 1 : 0,
            'description' => $form['description'] !== '' ? $form['description'] : null,
            'sort_order'  => $form['sort_order'],
        ];

        if ($antes !== null) {
            MapPointsRepo::atualizar($id, $dados);
            $this->auditar('ponto_mapa_editado', 'map_point_type', $id, 'Ponto "' . $form['label'] . '" atualizado.',
                ['antes' => $this->resumir($antes), 'depois' => $dados]);
            return $this->voltar('Ponto "' . $form['label'] . '" atualizado.');
        }

        $novo = MapPointsRepo::criar($dados);
        $this->auditar('ponto_mapa_criado', 'map_point_type', $novo, 'Ponto "' . $form['label'] . '" criado.', $dados);
        return $this->voltar('Ponto "' . $form['label'] . '" criado e já disponível na paleta do editor.');
    }

    /** @param array<string, string> $params */
    public function excluir(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $id   = (int)$request->input('id', '0');
        $alvo = $id > 0 ? MapPointsRepo::porId($id) : null;
        if ($alvo === null) {
            return $this->voltar('Ponto não encontrado — nada foi excluído.', 'error');
        }
        if (!empty($alvo['is_system'])) {
            // O formulário nem oferece o botão; esta é a recusa para quem posta direto.
            return $this->voltar('"' . $alvo['label'] . '" é um ponto do jogo e não pode ser excluído.', 'error');
        }

        $usos = MapPointsRepo::usos()[(string)$alvo['slug']] ?? 0;
        MapPointsRepo::excluir($id);
        $this->auditar('ponto_mapa_excluido', 'map_point_type', $id, 'Ponto "' . $alvo['label'] . '" excluído.',
            ['ponto' => $this->resumir($alvo), 'marcadores_afetados' => $usos]);

        $extra = $usos > 0
            ? ' ' . $usos . ' marcador(es) continuam no mapa, agora desenhados pelo tipo "' . $alvo['kind'] . '".'
            : '';
        return $this->voltar('Ponto "' . $alvo['label'] . '" excluído.' . $extra);
    }

    // =====================================================================================
    // TELA
    // =====================================================================================

    /**
     * @param array<string,mixed> $over
     */
    private function tela(Request $request, array $over = [], int $status = 200): Response
    {
        $cromo = data_mock('admin-mapas-pontos');

        $filtros = [
            'q'   => mb_substr((string)($request->query('q') ?? ''), 0, 60),
            'cat' => (string)($request->query('cat') ?? ''),
        ];

        $novo = ($request->query('novo') ?? $request->input('novo')) === '1';
        $alvo = $over['alvo'] ?? null;
        if ($alvo === null && !$novo) {
            $idUrl = (int)($request->query('id') ?? 0);
            $alvo  = $idUrl > 0 ? MapPointsRepo::porId($idUrl) : null;
        }
        if (!empty($over['edicao']) && $alvo === null) {
            $novo = true;
        }
        $edicao = $novo || $alvo !== null;

        $usos  = MapPointsRepo::usos();
        $todos = MapPointsRepo::todos();

        $dados = [
            'cromo'      => $cromo,
            'filtros'    => $filtros,
            'categorias' => MapPointsRepo::CATEGORIAS,
            'cores'      => self::CORES,
            'kinds'      => $this->kinds(),
            'usos'       => $usos,
            'grupos'     => $this->agrupar($todos, $filtros, $usos),
            'total'      => count($todos),
            'modo'       => $edicao ? 'editor' : 'lista',
            'novo'       => $novo,
            'alvo'       => $alvo,
            'erros'      => (array)($over['erros'] ?? []),
            'form'       => isset($over['form']) && is_array($over['form'])
                ? $over['form']
                : $this->formDoBanco($alvo, $todos),
            'excluir'    => $this->confirmacao($request, $usos),
            'filtrando'  => $filtros['q'] !== '' || $filtros['cat'] !== '',
        ];

        $page = $this->adminPage(
            'mapas-pontos',
            (string)$cromo['titulo'],
            $edicao
                ? ($alvo !== null ? 'Editando "' . (string)$alvo['label'] . '"' : 'Novo ponto')
                : count($todos) . ' ponto(s) no catálogo'
        );
        $page['actions'] = $edicao
            ? '<a class="adm-btn adm-btn--ghost" href="' . e(url('admin/mapas/pontos')) . '">Voltar ao catálogo</a>'
            : '<a class="adm-btn adm-btn--ghost" href="' . e(url('admin/mapas/editor')) . '">Abrir o editor de mapa</a>'
              . '<a class="adm-btn adm-btn--primary" href="' . e(url('admin/mapas/pontos', ['novo' => '1'])) . '">Novo ponto</a>';

        return $this->render($page['slug'], $page, $dados, 'admin', $status);
    }

    /**
     * Passo de confirmação de exclusão, na URL (?excluir=N) e não num confirm() de
     * JavaScript: a CSP do projeto proíbe script inline, e confirmação que depende de JS
     * carregar é confirmação que às vezes não acontece.
     *
     * @param array<string,int> $usos
     * @return array<string,mixed>|null
     */
    private function confirmacao(Request $request, array $usos): ?array
    {
        $id = (int)($request->query('excluir') ?? 0);
        if ($id <= 0) {
            return null;
        }
        $alvo = MapPointsRepo::porId($id);
        if ($alvo === null || !empty($alvo['is_system'])) {
            return null;
        }
        return [
            'ponto'    => $alvo,
            'usos'     => $usos[(string)$alvo['slug']] ?? 0,
            'impedido' => false,
        ];
    }

    /**
     * Catálogo agrupado por categoria, já filtrado e com a contagem de uso.
     * O filtro é aplicado AQUI e não no SQL porque a tabela tem dezenas de linhas: uma
     * consulta por tecla digitada custaria mais do que percorrer o array.
     *
     * @param list<array<string,mixed>> $todos
     * @param array<string,string> $f
     * @param array<string,int> $usos
     * @return list<array{key:string,label:string,items:list<array<string,mixed>>}>
     */
    private function agrupar(array $todos, array $f, array $usos): array
    {
        $q = mb_strtolower(trim($f['q']));
        $out = [];
        foreach (MapPointsRepo::porCategoria() as $g) {
            if ($f['cat'] !== '' && $g['key'] !== $f['cat']) { continue; }
            $itens = [];
            foreach ($g['items'] as $t) {
                if ($q !== '' && !str_contains(mb_strtolower((string)$t['label'] . ' ' . $t['slug'] . ' ' . $t['kind']), $q)) {
                    continue;
                }
                $t['usos'] = $usos[(string)$t['slug']] ?? 0;
                $itens[] = $t;
            }
            if ($itens === []) { continue; }
            $out[] = ['key' => $g['key'], 'label' => $g['label'], 'items' => $itens];
        }
        return $out;
    }

    /**
     * `kind` oferecidos no <select>: o catálogo que o jogo sabe desenhar (o mesmo de
     * app/Data/admin-mapas.php, que já é a lista de MapController::LAYER_OF).
     * @return array<string,string>
     */
    private function kinds(): array
    {
        $out = [];
        foreach ((array)(data_mock('admin-mapas')['marcadores'] ?? []) as $kind => $info) {
            $out[(string)$kind] = (string)($info['label'] ?? $kind) . ' · camada ' . (string)($info['camada'] ?? '');
        }
        return $out;
    }

    // =====================================================================================
    // FORMULÁRIO
    // =====================================================================================

    /**
     * @param array<string,mixed>|null $alvo
     * @param list<array<string,mixed>> $todos
     * @return array<string,mixed>
     */
    private function formDoBanco(?array $alvo, array $todos): array
    {
        if ($alvo === null) {
            // Ponto novo entra no fim da fila: a ordem do catálogo é a ordem da paleta, e
            // ninguém espera que criar um café reordene a paleta inteira.
            $ultimo = 0;
            foreach ($todos as $t) { $ultimo = max($ultimo, (int)$t['sort_order']); }
            return [
                'slug' => '', 'label' => '', 'category' => 'comercio', 'kind' => 'market',
                'icon_origem' => 'symbol', 'icon_valor' => 'place', 'color' => 'gold',
                'layer' => '', 'show_label' => false, 'description' => '',
                'sort_order' => $ultimo + 10,
            ];
        }
        [$origem, $valor] = MapPointsRepo::icone((string)$alvo['icon']);
        return [
            'slug'        => (string)$alvo['slug'],
            'label'       => (string)$alvo['label'],
            'category'    => (string)$alvo['category'],
            'kind'        => (string)$alvo['kind'],
            'icon_origem' => $origem,
            'icon_valor'  => $valor,
            'color'       => (string)$alvo['color'],
            'layer'       => (string)($alvo['layer'] ?? ''),
            'show_label'  => (bool)$alvo['show_label'],
            'description' => (string)($alvo['description'] ?? ''),
            'sort_order'  => (int)$alvo['sort_order'],
        ];
    }

    /** @return array<string,mixed> */
    private function formDoPost(Request $request): array
    {
        $rotulo = trim((string)$request->input('label', ''));
        $slug   = trim((string)$request->input('slug', ''));
        $origem = trim((string)$request->input('icon_origem', 'symbol'));

        return [
            // Slug em branco é preenchido a partir do rótulo: quem cadastra "Café do Porto"
            // não deveria precisar saber que existe uma chave técnica por baixo.
            'slug'        => mb_substr($slug !== '' ? $this->slugificar($slug) : $this->slugificar($rotulo), 0, 48),
            'label'       => mb_substr($rotulo, 0, 60),
            'category'    => trim((string)$request->input('category', '')),
            'kind'        => trim((string)$request->input('kind', '')),
            'icon_origem' => $origem,
            /* Os três campos de ícone chegam SEMPRE (a tela os mantém no DOM mesmo escondidos),
               e o servidor lê só o que corresponde à origem escolhida. É o que permite que a
               tela funcione sem JavaScript sem inventar um quarto campo oculto: sem script os
               três aparecem, com script dois somem, e o POST é idêntico nos dois casos. */
            'icon_valor'  => mb_substr(trim((string)$request->input(match ($origem) {
                'glyph' => 'icon_glyph',
                'image' => 'icon_image',
                default => 'icon_symbol',
            }, '')), 0, 160),
            'color'       => trim((string)$request->input('color', 'white')),
            'layer'       => mb_substr(trim((string)$request->input('layer', '')), 0, 24),
            'show_label'  => (string)$request->input('show_label', '') === '1',
            'description' => mb_substr(trim((string)$request->input('description', '')), 0, 255),
            'sort_order'  => max(0, min(9999, (int)$request->input('sort_order', '0'))),
        ];
    }

    /**
     * @param array<string,mixed> $f
     * @param array<string,mixed>|null $antes
     * @return array<string,string> campo => mensagem
     */
    private function validar(array $f, int $id, ?array $antes): array
    {
        $cromo = data_mock('admin-mapas-pontos');
        $e = [];

        if ($f['label'] === '')  { $e['label'] = 'Dê um nome ao ponto — é o que aparece na paleta do editor.'; }
        if ($f['slug'] === '')   { $e['slug']  = 'A chave não pode ficar vazia (use letras, números e hífen).'; }
        elseif (MapPointsRepo::slugEmUso($f['slug'], $id)) {
            $e['slug'] = 'Já existe um ponto com a chave "' . $f['slug'] . '".';
        }

        if (!isset(MapPointsRepo::CATEGORIAS[$f['category']])) {
            $e['category'] = 'Escolha uma categoria da lista.';
        }
        if (!isset($this->kinds()[$f['kind']])) {
            // Ícone novo o painel aceita; COMPORTAMENTO novo, não: o jogo não saberia
            // desenhar nem filtrar um kind que ele nunca ouviu falar.
            $e['kind'] = 'Escolha um tipo que o jogo já sabe desenhar.';
        }
        if (!in_array($f['color'], self::CORES, true)) {
            $e['color'] = 'Cor fora da paleta do mapa.';
        }
        if (!in_array($f['icon_origem'], MapPointsRepo::ORIGENS_ICONE, true)) {
            $e['icon_valor'] = 'Origem de ícone desconhecida.';
        } elseif ($f['icon_valor'] === '') {
            $e['icon_valor'] = 'Informe qual ícone este ponto desenha.';
        } elseif ($f['icon_origem'] === 'glyph' && !isset(((array)$cromo['glifos'])[$f['icon_valor']])) {
            $e['icon_valor'] = 'Esse vetor não existe na tela do mapa.';
        } elseif ($f['icon_origem'] === 'symbol' && !preg_match('/^[a-z0-9_]{2,40}$/', $f['icon_valor'])) {
            $e['icon_valor'] = 'Nome de Material Symbol inválido (só letras minúsculas, números e _).';
        } elseif ($f['icon_origem'] === 'image' && !preg_match('#^[A-Za-z0-9._/-]{3,160}$#', $f['icon_valor'])) {
            $e['icon_valor'] = 'Caminho de imagem inválido.';
        }

        if ($antes !== null && !empty($antes['is_system']) && $f['slug'] !== (string)$antes['slug']) {
            // A chave de um ponto de sistema está escrita nos marcadores semeados por patch;
            // renomear aqui deixaria todos eles apontando para o vazio.
            $e['slug'] = 'A chave de um ponto do jogo não pode mudar — os marcadores existentes apontam para ela.';
        }

        return $e;
    }

    private function slugificar(string $v): string
    {
        $v = iconv('UTF-8', 'ASCII//TRANSLIT', $v) ?: $v;
        $v = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $v));
        return trim($v, '-');
    }

    /**
     * @param array<string,mixed> $t
     * @return array<string,mixed>
     */
    private function resumir(array $t): array
    {
        return [
            'slug' => $t['slug'], 'label' => $t['label'], 'category' => $t['category'],
            'kind' => $t['kind'], 'icon' => $t['icon'], 'color' => $t['color'],
        ];
    }

    private function voltar(string $msg, string $tipo = 'ok'): Response
    {
        flash($tipo, $msg);
        return $this->redirect('admin/mapas/pontos');
    }
}
