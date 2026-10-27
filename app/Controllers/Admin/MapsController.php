<?php declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\GameRules;
use App\Core\Request;
use App\Core\Response;
use App\Repo\MapsRepo;

/**
 * Tela MAPAS do painel: /admin/mapas.
 *
 * Governa `maps` (regiões do mundo persistente e mapas de partida) e os `map_markers` de cada
 * um. É a tela que decide o que o jogador vê no Mapa do Mundo — o que existe, onde fica, o
 * que está bloqueado e qual level da Unreal cada mapa carrega.
 *
 * TRÊS DECISÕES QUE EXPLICAM O CÓDIGO INTEIRO:
 *
 * 1. `meta` nunca é substituído, é MESCLADO. A coluna é JSON livre e o jogo evolui: hoje o
 *    painel conhece capacidade, infestação, atividade, extrações e evento; amanhã um patch
 *    grava uma chave que este arquivo não tem campo para editar. Salvar por substituição
 *    apagaria essa chave em silêncio no primeiro "Salvar" que alguém desse. Então o que o
 *    formulário conhece é reescrito, e o resto do JSON volta intacto — a tela até diz quantas
 *    chaves ela está preservando sem entender, para o operador não achar que sumiram.
 *
 * 2. `meta.info` é DERIVADO, não digitado. Aquelas quatro linhas do painel de INFORMAÇÕES da
 *    região (nível, capacidade, clima, mundo) são exatamente os campos estruturados que este
 *    formulário edita. Se fossem campos independentes, mudar o clima aqui deixaria o jogo
 *    mostrando o clima velho — dois lugares dizendo coisas diferentes sobre o mesmo mundo.
 *    Elas são reconstruídas a cada salvamento; qualquer linha extra que o operador tenha
 *    posto por fora sobrevive no fim da lista.
 *
 * 3. Validação NÃO redireciona: ela redesenha a tela com status 422 e com o que foi digitado
 *    ainda nos campos. Um formulário de mapa tem trinta campos, incluindo uma tabela de
 *    extrações — devolver "corrija o slug" junto com o formulário em branco seria pedir para
 *    o operador digitar tudo de novo.
 *
 * Excluir tem confirmação SERVIDA PELO SERVIDOR (um passo em GET que descreve a consequência
 * exata), e não um confirm() de JavaScript: a CSP do projeto proíbe script inline, e uma
 * confirmação que depende de JS carregar é uma confirmação que às vezes não acontece.
 */
final class MapsController extends AdminController
{
    private const POR_PAGINA = 20;

    /** Coordenada de marcador: 0..1 com 4 casas, que é o que `map_markers.x/y` guarda. */
    private const COORD_CASAS = 4;

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        return $this->tela($request);
    }

    /**
     * Único destino de escrita da tela (POST /admin/mapas/salvar). O campo `acao` diz qual
     * dos três formulários chegou — o cadastro do mapa, a grade de marcadores ou a linha de
     * marcador novo.
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
            'mapa'          => $this->salvarMapa($request),
            'marcadores'    => $this->salvarMarcadores($request),
            'marcador_novo' => $this->criarMarcador($request),
            default         => $this->voltar('Ação desconhecida — nada foi alterado.', 'error'),
        };
    }

    /** @param array<string, string> $params */
    public function excluir(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        return match ((string)$request->input('alvo', '')) {
            'mapa'     => $this->excluirMapa($request),
            'marcador' => $this->excluirMarcador($request),
            default    => $this->voltar('Ação desconhecida — nada foi excluído.', 'error'),
        };
    }

    // =====================================================================================
    // ESCRITA
    // =====================================================================================

    private function salvarMapa(Request $request): Response
    {
        $id      = (int)$request->input('id', '0');
        $atual   = $id > 0 ? MapsRepo::porId($id) : null;
        if ($id > 0 && $atual === null) {
            return $this->voltar('Esse mapa não existe mais — alguém pode tê-lo excluído.', 'error');
        }

        $form  = $this->formDoPost($request);
        $erros = $this->validarMapa($form, $id);

        if ($erros !== []) {
            // 422 e não 200: a resposta é a MESMA tela, mas o pedido não foi aceito.
            return $this->tela($request, [
                'form'   => $form,
                'erros'  => $erros,
                'mapa'   => $atual,
                'edicao' => true,
            ], 422);
        }

        $metaAntes = is_array($atual['meta_arr'] ?? null) ? $atual['meta_arr'] : [];
        $meta      = $this->montarMeta($form, $metaAntes);

        $dados = [
            'slug'         => $form['slug'],
            'name'         => $form['name'],
            'kind'         => $form['kind'],
            'mode'         => $form['mode'] !== '' ? $form['mode'] : null,
            'players'      => $form['players'] !== '' ? $form['players'] : null,
            'level_min'    => $form['level_min'] !== '' ? (int)$form['level_min'] : null,
            'level_max'    => $form['level_max'] !== '' ? (int)$form['level_max'] : null,
            'locked'       => $form['locked'] ? 1 : 0,
            'image'        => $form['image'] !== '' ? $form['image'] : null,
            'description'  => $form['description'] !== '' ? $form['description'] : null,
            'unreal_level' => $form['unreal_level'] !== '' ? $form['unreal_level'] : null,
            'sort_order'   => (int)$form['sort_order'],
            'meta'         => $meta === [] ? null : $this->json($meta),
        ];

        if ($id > 0) {
            MapsRepo::atualizar($id, $dados);
            $this->auditar(
                'mapa_editado',
                'map',
                $id,
                'Mapa "' . $form['name'] . '" atualizado.',
                ['slug' => $form['slug'], 'campos' => $this->diferencas($atual ?? [], $dados)]
            );
            flash('ok', 'Mapa "' . $form['name'] . '" salvo.');
        } else {
            $id = MapsRepo::criar($dados);
            $this->auditar('mapa_criado', 'map', $id, 'Mapa "' . $form['name'] . '" criado.', ['slug' => $form['slug'], 'kind' => $form['kind']]);
            flash('ok', 'Mapa "' . $form['name'] . '" criado. Agora ele já pode receber marcadores.');
        }

        return $this->redirect('admin/mapas', ['id' => $id]);
    }

    /**
     * Grade de marcadores: N linhas de uma vez.
     *
     * É um formulário só, e não um por linha, porque acertar coordenada é trabalho
     * COMPARATIVO — mexe-se em três marcadores olhando a posição relativa entre eles e
     * salva-se o conjunto. Salvar linha a linha obrigaria a recarregar a tela entre cada
     * ajuste e perder o que já estava digitado nas outras.
     */
    private function salvarMarcadores(Request $request): Response
    {
        $mapId = (int)$request->input('id', '0');
        $mapa  = MapsRepo::porId($mapId);
        if ($mapa === null) {
            return $this->voltar('Esse mapa não existe mais.', 'error');
        }

        $enviados = $this->arrayDoPost($request, 'mk');
        $atuais   = [];
        foreach (MapsRepo::marcadores($mapId) as $m) {
            $atuais[(int)$m['id']] = $m;
        }

        $erros = [];
        $prontos = [];
        foreach ($enviados as $chave => $linha) {
            $mid = (int)$chave;
            if (!isset($atuais[$mid]) || !is_array($linha)) {
                continue; // linha de um marcador que sumiu entre a leitura e o envio
            }
            $campo = $this->marcadorDoPost($linha);
            foreach ($this->validarMarcador($campo) as $c => $msg) {
                $erros['mk.' . $mid . '.' . $c] = $msg;
            }
            $prontos[$mid] = $campo;
        }

        if ($erros !== []) {
            return $this->tela($request, [
                'erros'      => $erros,
                'mk_post'    => $enviados,
                'mapa'       => $mapa,
                'edicao'     => true,
            ], 422);
        }

        $mudados = 0;
        foreach ($prontos as $mid => $campo) {
            $antes = $atuais[$mid];
            $novo  = $this->dadosMarcador($campo, is_array($antes['meta_arr'] ?? null) ? $antes['meta_arr'] : []);
            if ($this->marcadorIgual($antes, $novo)) {
                continue; // não gasta UPDATE nem linha de auditoria por linha intocada
            }
            MapsRepo::atualizarMarcador($mid, $novo);
            $mudados++;
        }

        if ($mudados === 0) {
            flash('info', 'Nenhuma alteração nos marcadores — nada foi gravado.');
        } else {
            $this->auditar(
                'marcadores_editados',
                'map',
                $mapId,
                $mudados . ' marcador(es) de "' . (string)$mapa['name'] . '" atualizado(s).',
                ['mapa' => (string)$mapa['slug'], 'quantidade' => $mudados]
            );
            flash('ok', $mudados === 1 ? '1 marcador atualizado.' : $mudados . ' marcadores atualizados.');
        }

        return $this->redirect('admin/mapas', ['id' => $mapId]);
    }

    private function criarMarcador(Request $request): Response
    {
        $mapId = (int)$request->input('id', '0');
        $mapa  = MapsRepo::porId($mapId);
        if ($mapa === null) {
            return $this->voltar('Esse mapa não existe mais.', 'error');
        }

        $linha = $this->arrayDoPost($request, 'novo');
        $campo = $this->marcadorDoPost(is_array($linha) ? $linha : []);
        $erros = [];
        foreach ($this->validarMarcador($campo) as $c => $msg) {
            $erros['novo.' . $c] = $msg;
        }

        if ($erros !== []) {
            return $this->tela($request, [
                'erros'     => $erros,
                'novo_post' => $campo,
                'mapa'      => $mapa,
                'edicao'    => true,
            ], 422);
        }

        $novoId = MapsRepo::criarMarcador($mapId, $this->dadosMarcador($campo, []));
        $this->auditar(
            'marcador_criado',
            'map_marker',
            $novoId,
            'Marcador "' . ($campo['label'] !== '' ? $campo['label'] : $campo['kind']) . '" criado em "' . (string)$mapa['name'] . '".',
            ['mapa' => (string)$mapa['slug'], 'kind' => $campo['kind'], 'x' => $campo['x'], 'y' => $campo['y']]
        );
        flash('ok', 'Marcador adicionado a "' . (string)$mapa['name'] . '".');

        return $this->redirect('admin/mapas', ['id' => $mapId]);
    }

    private function excluirMapa(Request $request): Response
    {
        $id   = (int)$request->input('id', '0');
        $mapa = MapsRepo::porId($id);
        if ($mapa === null) {
            return $this->voltar('Esse mapa já não existe.', 'error');
        }

        // Segunda checagem de vínculo, mesmo com a tela de confirmação já tendo feito a
        // primeira: entre ver a confirmação e clicar, uma partida pode ter sido registrada.
        $vinculos = MapsRepo::vinculos($id);
        if ($vinculos !== []) {
            flash('error', 'Este mapa não pode ser excluído: ' . $this->frasePorVinculo($vinculos));
            return $this->redirect('admin/mapas', ['id' => $id]);
        }

        $marcadores = (int)$mapa['marcadores'];
        MapsRepo::excluir($id);
        $this->auditar(
            'mapa_excluido',
            'map',
            $id,
            'Mapa "' . (string)$mapa['name'] . '" excluído com ' . $marcadores . ' marcador(es).',
            ['slug' => (string)$mapa['slug'], 'kind' => (string)$mapa['kind'], 'marcadores' => $marcadores]
        );
        flash('ok', 'Mapa "' . (string)$mapa['name'] . '" excluído' . ($marcadores > 0 ? ' junto com ' . $marcadores . ' marcador(es).' : '.'));

        return $this->redirect('admin/mapas');
    }

    private function excluirMarcador(Request $request): Response
    {
        $mid = (int)$request->input('marcador_id', '0');
        $mk  = MapsRepo::marcador($mid);
        if ($mk === null) {
            return $this->voltar('Esse marcador já não existe.', 'error');
        }
        $mapId = (int)$mk['map_id'];

        MapsRepo::excluirMarcador($mid);
        $this->auditar(
            'marcador_excluido',
            'map_marker',
            $mid,
            'Marcador "' . (string)($mk['label'] ?? $mk['kind']) . '" excluído.',
            ['map_id' => $mapId, 'kind' => (string)$mk['kind'], 'x' => (float)$mk['x'], 'y' => (float)$mk['y']]
        );
        flash('ok', 'Marcador excluído.');

        return $this->redirect('admin/mapas', ['id' => $mapId]);
    }

    // =====================================================================================
    // LEITURA / MONTAGEM DA TELA
    // =====================================================================================

    /**
     * A tela inteira, em dois modos: LISTA (sem ?id) e EDITOR (com ?id, ou ?novo=1).
     *
     * São modos e não painéis lado a lado porque o editor de uma região tem cadastro,
     * estado do setor, extrações e a grade de marcadores. Espremido ao lado de uma tabela
     * de catálogo, nada disso caberia em 1366×768 sem cortar.
     *
     * @param array<string,mixed> $over
     */
    private function tela(Request $request, array $over = [], int $status = 200): Response
    {
        $cromo = data_mock('admin-mapas');

        $filtros = [
            'busca'      => (string)($request->query('busca') ?? $request->input('busca') ?? ''),
            'tipo'       => (string)($request->query('tipo') ?? $request->input('tipo') ?? ''),
            'estado'     => (string)($request->query('estado') ?? $request->input('estado') ?? ''),
            'ordem'      => (string)($request->query('ordem') ?? 'ordem'),
            'dir'        => (string)($request->query('dir') ?? 'asc'),
            'pagina'     => (int)($request->query('pagina') ?? 1),
            'por_pagina' => self::POR_PAGINA,
        ];
        $filtros['busca'] = mb_substr($filtros['busca'], 0, 80);

        $novo   = ($request->query('novo') ?? $request->input('novo')) === '1';
        $mapa   = $over['mapa'] ?? null;
        if ($mapa === null && !$novo) {
            $idUrl = (int)($request->query('id') ?? $request->input('id') ?? 0);
            $mapa  = $idUrl > 0 ? MapsRepo::porId($idUrl) : null;
        }
        // `edicao` é a chave que uma ação de ESCRITA recusada usa para dizer "volte para o
        // editor". Sem ela, o erro de validação de um mapa NOVO caía na lista — o operador
        // perdia os trinta campos que acabou de preencher para ler "corrija o slug".
        if (!empty($over['edicao']) && $mapa === null) {
            $novo = true;
        }
        $edicao = $novo || $mapa !== null;

        $dados = [
            'cromo'   => $cromo,
            'filtros' => $filtros,
            'resumo'  => MapsRepo::resumo(),
            'modo'    => $edicao ? 'editor' : 'lista',
            'novo'    => $novo,
            'mapa'    => $mapa,
            'erros'   => (array)($over['erros'] ?? []),
            'tipos'   => MapsRepo::TIPOS,

            // catálogos de regra do jogo — a tela desenha os <select> a partir daqui
            'extracao_tipos'   => GameRules::EXTRACTION_TYPES,
            'extracao_estados' => GameRules::EXTRACTION_STATES,
            'atividades'       => GameRules::ACTIVITY,
            'capacidade_padrao'=> GameRules::REGION_CAPACITY,
            'marcador_tipos'   => $this->catalogoDeMarcadores($cromo),
        ];

        if (!$edicao) {
            $dados += MapsRepo::listar($filtros);
            $dados['filtrando'] = $filtros['busca'] !== '' || $filtros['tipo'] !== '' || $filtros['estado'] !== '';
        } else {
            $mapaId = $mapa !== null ? (int)$mapa['id'] : 0;

            // Em erro de validação o formulário volta com o que foi DIGITADO; fora disso,
            // com o que está gravado.
            $dados['form']       = isset($over['form']) && is_array($over['form'])
                ? $over['form']
                : $this->formDoBanco($mapa);
            $dados['marcadores'] = $mapaId > 0 ? MapsRepo::marcadores($mapaId) : [];
            $dados['mk_post']    = (array)($over['mk_post'] ?? []);
            $dados['novo_post']  = (array)($over['novo_post'] ?? []);
            $dados['vinculos']   = $mapaId > 0 ? MapsRepo::vinculos($mapaId) : [];
            $dados['vinculo_txt']= $dados['vinculos'] !== [] ? $this->frasePorVinculo($dados['vinculos']) : '';
            $dados['confirmar']  = $this->confirmacao($request, $mapa, $dados['marcadores']);
            $dados['meta_extra'] = $this->chavesPreservadas($mapa, $dados['form']);
        }

        $page = $this->adminPage('mapas', 'Mapas', $this->subtitulo($dados));
        $page['actions'] = $this->acoesDoTopo($dados);

        return $this->render($page['slug'], $page, $dados + [
            'admin_kpis' => $this->kpis($dados['resumo']),
        ], 'admin', $status);
    }

    /**
     * Passo de confirmação de exclusão. Vive na URL (?excluir=mapa | ?excluir=marcador&mk=N)
     * porque assim ele é um ESTADO da tela: sobrevive a um F5, dá para desistir com o botão
     * de voltar do navegador e descreve por escrito o que será perdido.
     *
     * @param array<string,mixed>|null $mapa
     * @param list<array<string,mixed>> $marcadores
     * @return array<string,mixed>|null
     */
    private function confirmacao(Request $request, ?array $mapa, array $marcadores): ?array
    {
        $alvo = (string)($request->query('excluir') ?? '');
        if ($mapa === null || ($alvo !== 'mapa' && $alvo !== 'marcador')) {
            return null;
        }

        if ($alvo === 'mapa') {
            return ['tipo' => 'mapa', 'id' => (int)$mapa['id'], 'nome' => (string)$mapa['name'], 'marcadores' => count($marcadores)];
        }

        $mid = (int)($request->query('mk') ?? 0);
        foreach ($marcadores as $m) {
            if ((int)$m['id'] === $mid) {
                return [
                    'tipo'  => 'marcador',
                    'id'    => $mid,
                    'nome'  => (string)($m['label'] ?? '') !== '' ? (string)$m['label'] : (string)$m['kind'],
                    'kind'  => (string)$m['kind'],
                    'x'     => (float)$m['x'],
                    'y'     => (float)$m['y'],
                ];
            }
        }
        return null;
    }

    /** @param array<string,mixed> $dados */
    private function subtitulo(array $dados): string
    {
        if (($dados['modo'] ?? '') === 'editor') {
            if (!empty($dados['novo'])) {
                return 'Novo mapa';
            }
            $m = (array)($dados['mapa'] ?? []);
            return trim((string)($m['slug'] ?? '')) . ' · ' . ((int)($m['marcadores'] ?? 0)) . ' marcador(es)';
        }
        $r = (array)($dados['resumo'] ?? []);
        return ($r['regioes'] ?? 0) . ' região(ões), ' . ($r['partidas'] ?? 0) . ' mapa(s) de partida e ' . ($r['marcadores'] ?? 0) . ' marcadores';
    }

    /** Botões da topbar. HTML montado aqui porque é isso que o layout `admin` espera. */
    private function acoesDoTopo(array $dados): string
    {
        if (($dados['modo'] ?? '') === 'editor') {
            return '<a class="adm-btn adm-btn--ghost" href="' . e(url('admin/mapas')) . '">'
                . icon('arrow_back') . '<span>Voltar à lista</span></a>';
        }
        return '<a class="adm-btn adm-btn--primary" href="' . e(url('admin/mapas', ['novo' => 1])) . '">'
            . icon('add_location_alt') . '<span>Novo mapa</span></a>';
    }

    /**
     * KPIs da topbar. Trocam os três genéricos da casca por números desta tela — e todos
     * saem de COUNT, nenhum é estimativa.
     * @param array<string,int> $r
     * @return list<array<string,mixed>>
     */
    private function kpis(array $r): array
    {
        return [
            ['icon' => 'public',   'label' => 'Regiões do mundo', 'value' => num($r['regioes'] ?? 0),    'tone' => 'lime'],
            ['icon' => 'stadium',  'label' => 'Mapas de partida', 'value' => num($r['partidas'] ?? 0),   'tone' => 'cyan'],
            ['icon' => 'place',    'label' => 'Marcadores',       'value' => num($r['marcadores'] ?? 0), 'tone' => 'amber'],
        ];
    }

    // =====================================================================================
    // FORMULÁRIO DO MAPA
    // =====================================================================================

    /**
     * Linha do banco -> campos do formulário (inclusive o `meta` aberto em campos).
     * @param array<string,mixed>|null $mapa
     * @return array<string,mixed>
     */
    private function formDoBanco(?array $mapa): array
    {
        $meta = is_array($mapa['meta_arr'] ?? null) ? $mapa['meta_arr'] : [];
        $cap  = is_array($meta['capacity'] ?? null) ? $meta['capacity'] : [];
        $ev   = is_array($meta['event'] ?? null) ? $meta['event'] : [];

        $extracoes = [];
        foreach ((array)($meta['extractions'] ?? []) as $x) {
            if (!is_array($x)) {
                continue;
            }
            $extracoes[] = [
                'name'  => (string)($x['name'] ?? ''),
                'type'  => (string)($x['type'] ?? 'publica'),
                'state' => (string)($x['state'] ?? 'active'),
            ];
        }

        return [
            'id'           => (int)($mapa['id'] ?? 0),
            'slug'         => (string)($mapa['slug'] ?? ''),
            'name'         => (string)($mapa['name'] ?? ''),
            'kind'         => (string)($mapa['kind'] ?? 'region'),
            'mode'         => (string)($mapa['mode'] ?? ''),
            'players'      => (string)($mapa['players'] ?? ''),
            'level_min'    => (string)($mapa['level_min'] ?? ''),
            'level_max'    => (string)($mapa['level_max'] ?? ''),
            'locked'       => (bool)($mapa['locked'] ?? false),
            'image'        => (string)($mapa['image'] ?? ''),
            'description'  => (string)($mapa['description'] ?? ''),
            'unreal_level' => (string)($mapa['unreal_level'] ?? ''),
            'sort_order'   => (string)($mapa['sort_order'] ?? '0'),

            'chave'          => (string)($meta['key'] ?? ''),
            'icone'          => (string)($meta['icon'] ?? ''),
            'nivel_txt'      => (string)($meta['level'] ?? ''),
            'clima'          => (string)($meta['weather'] ?? ''),
            'setor'          => (string)($meta['sector_state'] ?? ''),
            'persistente'    => (bool)($meta['persistent'] ?? true),
            'cap_total'      => (string)($cap['total'] ?? ''),
            'cap_hunters'    => (string)($cap['hunters'] ?? ''),
            'cap_merc'       => (string)($cap['mercenaries'] ?? ''),
            'infestacao'     => (string)($meta['infestation'] ?? ''),
            'veyra'          => (string)($meta['veyra_activity'] ?? ''),
            'humana'         => (string)($meta['human_activity'] ?? ''),
            'extracoes'      => $extracoes,
            'evento_nome'    => (string)($ev['name'] ?? ''),
            'evento_prazo'   => (string)($ev['ends_in'] ?? ''),
            'evento_rotulo'  => (string)($ev['ends_label'] ?? ''),
            'tamanho'        => (string)($meta['size'] ?? ''),
            'bioma'          => (string)($meta['biome'] ?? ''),
            'recorde'        => (string)($meta['best'] ?? ''),
            'recorde_por'    => (string)($meta['best_by'] ?? ''),
        ];
    }

    /**
     * POST -> campos do formulário. Nenhum valor é convertido para o tipo final aqui: o que
     * o operador digitou tem que poder voltar para a tela exatamente como ele digitou quando
     * a validação recusar.
     * @return array<string,mixed>
     */
    private function formDoPost(Request $request): array
    {
        $ex = [];
        foreach ($this->arrayDoPost($request, 'ex') as $linha) {
            if (!is_array($linha)) {
                continue;
            }
            $nome = trim((string)($linha['name'] ?? ''));
            if ($nome === '') {
                continue; // linha em branco é como se remove uma extração
            }
            $ex[] = [
                'name'  => mb_substr($nome, 0, 80),
                'type'  => (string)($linha['type'] ?? 'publica'),
                'state' => (string)($linha['state'] ?? 'active'),
            ];
        }

        return [
            'id'           => (int)$request->input('id', '0'),
            'slug'         => mb_strtolower(mb_substr((string)$request->input('slug', ''), 0, 64)),
            'name'         => mb_substr((string)$request->input('name', ''), 0, 80),
            'kind'         => (string)$request->input('kind', 'region'),
            'mode'         => mb_substr((string)$request->input('mode', ''), 0, 40),
            'players'      => mb_substr((string)$request->input('players', ''), 0, 16),
            'level_min'    => (string)$request->input('level_min', ''),
            'level_max'    => (string)$request->input('level_max', ''),
            'locked'       => $request->input('locked') === '1',
            'image'        => mb_substr((string)$request->input('image', ''), 0, 160),
            'description'  => mb_substr((string)$request->input('description', ''), 0, 400),
            'unreal_level' => mb_substr((string)$request->input('unreal_level', ''), 0, 200),
            'sort_order'   => (string)$request->input('sort_order', '0'),

            'chave'         => mb_substr((string)$request->input('chave', ''), 0, 64),
            'icone'         => mb_substr((string)$request->input('icone', ''), 0, 32),
            'nivel_txt'     => mb_substr((string)$request->input('nivel_txt', ''), 0, 16),
            'clima'         => mb_substr((string)$request->input('clima', ''), 0, 32),
            'setor'         => mb_substr((string)$request->input('setor', ''), 0, 24),
            'persistente'   => $request->input('persistente') === '1',
            'cap_total'     => (string)$request->input('cap_total', ''),
            'cap_hunters'   => (string)$request->input('cap_hunters', ''),
            'cap_merc'      => (string)$request->input('cap_merc', ''),
            'infestacao'    => (string)$request->input('infestacao', ''),
            'veyra'         => (string)$request->input('veyra', ''),
            'humana'        => (string)$request->input('humana', ''),
            'extracoes'     => $ex,
            'evento_nome'   => mb_substr((string)$request->input('evento_nome', ''), 0, 80),
            'evento_prazo'  => mb_substr((string)$request->input('evento_prazo', ''), 0, 24),
            'evento_rotulo' => mb_substr((string)$request->input('evento_rotulo', ''), 0, 32),
            'tamanho'       => mb_substr((string)$request->input('tamanho', ''), 0, 24),
            'bioma'         => mb_substr((string)$request->input('bioma', ''), 0, 60),
            'recorde'       => mb_substr((string)$request->input('recorde', ''), 0, 16),
            'recorde_por'   => mb_substr((string)$request->input('recorde_por', ''), 0, 40),
        ];
    }

    /**
     * Validação do cadastro. Devolve `campo => mensagem` — a tela marca o campo, não joga um
     * alerta genérico no topo.
     *
     * @param array<string,mixed> $f
     * @return array<string,string>
     */
    private function validarMapa(array $f, int $id): array
    {
        $e = [];

        $slug = (string)$f['slug'];
        if ($slug === '') {
            $e['slug'] = 'Informe o identificador.';
        } elseif (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            $e['slug'] = 'Só minúsculas, números e hífen entre eles (ex.: regiao-floresta-verde).';
        } elseif (MapsRepo::slugEmUso($slug, $id)) {
            $e['slug'] = 'Já existe outro mapa com este identificador.';
        }

        if (trim((string)$f['name']) === '') {
            $e['name'] = 'Informe o nome que o jogador vê.';
        }

        if (!isset(MapsRepo::TIPOS[(string)$f['kind']])) {
            $e['kind'] = 'Escolha entre região do mundo e mapa de partida.';
        }

        foreach (['level_min' => 'Nível mínimo', 'level_max' => 'Nível máximo'] as $campo => $rotulo) {
            $v = (string)$f[$campo];
            if ($v === '') {
                continue;
            }
            if (!preg_match('/^\d{1,4}$/', $v) || (int)$v > 999) {
                $e[$campo] = $rotulo . ' deve ser um número de 0 a 999.';
            }
        }
        if (!isset($e['level_min'], $e['level_max'])
            && (string)$f['level_min'] !== '' && (string)$f['level_max'] !== ''
            && (int)$f['level_max'] < (int)$f['level_min']) {
            $e['level_max'] = 'O nível máximo não pode ser menor que o mínimo.';
        }

        $ordem = (string)$f['sort_order'];
        if ($ordem !== '' && (!preg_match('/^\d{1,5}$/', $ordem) || (int)$ordem > 65535)) {
            $e['sort_order'] = 'A ordem é um número inteiro de 0 a 65535.';
        }

        // A imagem é caminho relativo dentro do projeto. Absoluto ou com ".." aponta para
        // fora e nunca resolveria pelo helper media() — recusar aqui é melhor do que uma
        // imagem quebrada só descoberta dentro do jogo.
        $img = (string)$f['image'];
        if ($img !== '' && (str_contains($img, '..') || preg_match('#^([a-z]+:)?//#i', $img) || str_starts_with($img, '/'))) {
            $e['image'] = 'Use um caminho relativo, sem "..", sem barra inicial e sem http://.';
        }

        $ue = (string)$f['unreal_level'];
        if ($ue !== '' && !preg_match('#^[A-Za-z0-9_/\-.]+$#', $ue)) {
            $e['unreal_level'] = 'Caminho de level inválido — use só letras, números, "/", "_", "-" e ".".';
        }

        if ((string)$f['kind'] === 'region') {
            foreach (['cap_total' => 'A capacidade total', 'cap_hunters' => 'A cota de Hunters', 'cap_merc' => 'A cota de Mercenários'] as $campo => $rotulo) {
                $v = (string)$f[$campo];
                if ($v !== '' && (!preg_match('/^\d{1,3}$/', $v) || (int)$v > 200)) {
                    $e[$campo] = $rotulo . ' deve ser um número de 0 a 200.';
                }
            }
            if (!isset($e['cap_total'], $e['cap_hunters'], $e['cap_merc'])
                && (string)$f['cap_total'] !== '' && (string)$f['cap_hunters'] !== '' && (string)$f['cap_merc'] !== ''
                && (int)$f['cap_hunters'] + (int)$f['cap_merc'] > (int)$f['cap_total']) {
                $e['cap_total'] = 'Hunters + Mercenários (' . ((int)$f['cap_hunters'] + (int)$f['cap_merc']) . ') passa da capacidade total.';
            }

            $inf = (string)$f['infestacao'];
            if ($inf !== '' && (!preg_match('/^\d{1,3}$/', $inf) || (int)$inf > 100)) {
                $e['infestacao'] = 'A infestação vai de 0 a 100.';
            }

            foreach (['veyra' => 'Veyra', 'humana' => 'humana'] as $campo => $rotulo) {
                $v = mb_strtoupper(trim((string)$f[$campo]));
                if ($v !== '' && !isset(GameRules::ACTIVITY[$v])) {
                    $e[$campo] = 'Degrau de atividade ' . $rotulo . ' desconhecido.';
                }
            }

            foreach ((array)$f['extracoes'] as $i => $x) {
                if (!isset(GameRules::EXTRACTION_TYPES[(string)$x['type']])) {
                    $e['ex.' . $i . '.type'] = 'Tipo de extração desconhecido.';
                }
                if (!isset(GameRules::EXTRACTION_STATES[(string)$x['state']])) {
                    $e['ex.' . $i . '.state'] = 'Estado de extração desconhecido.';
                }
            }

            if ((string)$f['evento_nome'] === '' && (string)$f['evento_prazo'] !== '') {
                $e['evento_nome'] = 'Um prazo sem nome de evento não aparece no jogo. Dê um nome ou limpe o prazo.';
            }
        }

        return $e;
    }

    /**
     * Campos do formulário -> `maps.meta`, PRESERVANDO o que veio do banco.
     *
     * @param array<string,mixed> $f @param array<string,mixed> $antes
     * @return array<string,mixed>
     */
    private function montarMeta(array $f, array $antes): array
    {
        $meta = $antes;

        $porTexto = static function (array &$m, string $chave, string $valor): void {
            if ($valor === '') {
                unset($m[$chave]);
            } else {
                $m[$chave] = $valor;
            }
        };

        $porTexto($meta, 'icon', (string)$f['icone']);

        if ((string)$f['kind'] === 'region') {
            $porTexto($meta, 'key', (string)$f['chave'] !== '' ? (string)$f['chave'] : (string)preg_replace('/^regiao-/', '', (string)$f['slug']));
            $porTexto($meta, 'level', (string)$f['nivel_txt']);
            $porTexto($meta, 'weather', (string)$f['clima']);
            $porTexto($meta, 'sector_state', mb_strtoupper((string)$f['setor']));
            $meta['persistent'] = (bool)$f['persistente'];

            if ((string)$f['cap_total'] !== '' || (string)$f['cap_hunters'] !== '' || (string)$f['cap_merc'] !== '') {
                $padrao = GameRules::REGION_CAPACITY;
                $meta['capacity'] = [
                    'total'       => (string)$f['cap_total']   !== '' ? (int)$f['cap_total']   : $padrao['total'],
                    'hunters'     => (string)$f['cap_hunters'] !== '' ? (int)$f['cap_hunters'] : $padrao['hunters'],
                    'mercenaries' => (string)$f['cap_merc']    !== '' ? (int)$f['cap_merc']    : $padrao['mercenaries'],
                ];
            }

            // A infestação é gravada como string porque é assim que o banco já tem hoje
            // ("42") e CatalogRepo::regionState faz o cast — mudar o tipo aqui criaria duas
            // formas do mesmo campo convivendo na mesma tabela.
            $porTexto($meta, 'infestation', (string)$f['infestacao']);
            $porTexto($meta, 'veyra_activity', mb_strtoupper((string)$f['veyra']));
            $porTexto($meta, 'human_activity', mb_strtoupper((string)$f['humana']));

            $ex = [];
            foreach ((array)$f['extracoes'] as $x) {
                $estado = (string)$x['state'];
                $ex[] = [
                    'name'   => (string)$x['name'],
                    'type'   => (string)$x['type'],
                    'state'  => $estado,
                    // `status` é o rótulo que o jogo IMPRIME. Ele sai da regra, não da mão do
                    // operador: assim "conditional" nunca aparece escrito CONDICIONAL numa
                    // região e ATIVA em outra.
                    'status' => (string)(GameRules::EXTRACTION_STATES[$estado]['label'] ?? 'ATIVA'),
                ];
            }
            $meta['extractions'] = $ex;

            $meta['event'] = (string)$f['evento_nome'] !== ''
                ? array_filter([
                    'name'       => (string)$f['evento_nome'],
                    'ends_in'    => (string)$f['evento_prazo'],
                    'ends_label' => (string)$f['evento_rotulo'] !== '' ? (string)$f['evento_rotulo'] : 'TERMINA EM',
                ], static fn(string $v): bool => $v !== '')
                : null;

            $meta['info'] = $this->linhasDeInfo($f, is_array($antes['info'] ?? null) ? $antes['info'] : []);
        } else {
            $porTexto($meta, 'size', mb_strtoupper((string)$f['tamanho']));
            $porTexto($meta, 'biome', (string)$f['bioma']);
            $porTexto($meta, 'weather', (string)$f['clima']);
            $porTexto($meta, 'best', (string)$f['recorde']);
            $porTexto($meta, 'best_by', (string)$f['recorde_por']);
            $porTexto($meta, 'infestation', (string)$f['infestacao']);
            $porTexto($meta, 'human_activity', mb_strtoupper((string)$f['humana']));
        }

        return $meta;
    }

    /**
     * As quatro linhas do painel INFORMAÇÕES da região, reconstruídas a partir dos campos
     * estruturados (ver decisão 2 no topo da classe). Linhas de rótulo desconhecido que já
     * existiam no meta são mantidas depois delas.
     *
     * @param array<string,mixed> $f @param list<array<string,mixed>> $antes
     * @return list<array<string,mixed>>
     */
    private function linhasDeInfo(array $f, array $antes): array
    {
        $cap   = GameRules::REGION_CAPACITY;
        $total = (string)$f['cap_total']   !== '' ? (int)$f['cap_total']   : $cap['total'];
        $hun   = (string)$f['cap_hunters'] !== '' ? (int)$f['cap_hunters'] : $cap['hunters'];
        $mer   = (string)$f['cap_merc']    !== '' ? (int)$f['cap_merc']    : $cap['mercenaries'];

        // O ícone do clima acompanha a palavra; sem correspondência fica o genérico.
        $climas = [
            'neblina'  => 'foggy',   'chuva'   => 'rainy',    'nevasca' => 'weather_snowy',
            'neve'     => 'weather_snowy', 'nublado' => 'cloud', 'limpo' => 'clear_day',
            'tempestade' => 'thunderstorm', 'variável' => 'partly_cloudy_day',
        ];
        $climaTxt = (string)$f['clima'];
        $climaIco = 'partly_cloudy_day';
        foreach ($climas as $palavra => $ico) {
            if ($climaTxt !== '' && mb_stripos($climaTxt, $palavra) !== false) {
                $climaIco = $ico;
                break;
            }
        }

        $linhas = [];
        if ((string)$f['nivel_txt'] !== '') {
            $linhas[] = ['label' => 'NÍVEL RECOMENDADO', 'value' => (string)$f['nivel_txt'], 'cls' => 'lime'];
        }
        $linhas[] = [
            'label' => 'CAPACIDADE OPERACIONAL',
            'value' => (string)$total,
            'tip'   => 'Até ' . $hun . ' Hunters e ' . $mer . ' Mercenários. Sobreviventes ocupam vagas existentes.',
        ];
        if ($climaTxt !== '') {
            $linhas[] = ['label' => 'CLIMA ATUAL', 'value' => $climaTxt, 'icon' => $climaIco];
        }
        if ((bool)$f['persistente']) {
            $linhas[] = ['label' => 'MUNDO', 'value' => 'PERSISTENTE', 'cls' => 'lime'];
        }

        $conhecidos = ['NÍVEL RECOMENDADO', 'CAPACIDADE OPERACIONAL', 'CLIMA ATUAL', 'MUNDO'];
        foreach ($antes as $linha) {
            if (is_array($linha) && !in_array(mb_strtoupper((string)($linha['label'] ?? '')), $conhecidos, true)) {
                $linhas[] = $linha;
            }
        }
        return $linhas;
    }

    /**
     * Chaves de `meta` que o formulário NÃO edita e vai devolver intactas ao banco. A tela
     * mostra a contagem para o operador saber que elas continuam lá.
     *
     * @param array<string,mixed>|null $mapa @param array<string,mixed> $form
     * @return list<string>
     */
    private function chavesPreservadas(?array $mapa, array $form): array
    {
        $meta = is_array($mapa['meta_arr'] ?? null) ? $mapa['meta_arr'] : [];
        $editadas = (string)($form['kind'] ?? 'region') === 'region'
            ? ['key', 'icon', 'level', 'weather', 'sector_state', 'persistent', 'capacity',
               'infestation', 'veyra_activity', 'human_activity', 'extractions', 'event', 'info']
            : ['icon', 'size', 'biome', 'weather', 'best', 'best_by', 'infestation', 'human_activity'];

        return array_values(array_diff(array_keys($meta), $editadas));
    }

    // =====================================================================================
    // MARCADORES
    // =====================================================================================

    /**
     * Uma linha da grade, ainda como texto. `x`/`y` aceitam vírgula: o operador digita num
     * teclado brasileiro e "0,4980" é o que ele escreve naturalmente.
     * @param array<string,mixed> $linha
     * @return array<string,string>
     */
    private function marcadorDoPost(array $linha): array
    {
        $txt = static fn(mixed $v, int $max): string => mb_substr(is_scalar($v) ? trim((string)$v) : '', 0, $max);

        return [
            'kind'   => mb_strtolower($txt($linha['kind'] ?? '', 24)),
            'label'  => $txt($linha['label'] ?? '', 80),
            'x'      => str_replace(',', '.', $txt($linha['x'] ?? '', 12)),
            'y'      => str_replace(',', '.', $txt($linha['y'] ?? '', 12)),
            'color'  => mb_strtolower($txt($linha['color'] ?? '', 16)),
            'anchor' => mb_strtolower($txt($linha['anchor'] ?? '', 8)),
        ];
    }

    /**
     * @param array<string,string> $c
     * @return array<string,string> campo => mensagem
     */
    private function validarMarcador(array $c): array
    {
        $e = [];

        if ($c['kind'] === '') {
            $e['kind'] = 'Escolha o tipo.';
        } elseif (!preg_match('/^[a-z0-9_]{2,24}$/', $c['kind'])) {
            $e['kind'] = 'Tipo inválido.';
        }

        foreach (['x' => 'X', 'y' => 'Y'] as $eixo => $rotulo) {
            $v = $c[$eixo];
            if ($v === '') {
                $e[$eixo] = $rotulo . ' é obrigatório.';
            } elseif (!is_numeric($v)) {
                $e[$eixo] = $rotulo . ' precisa ser número.';
            } elseif ((float)$v < 0 || (float)$v > 1) {
                $e[$eixo] = $rotulo . ' vai de 0 a 1.';
            }
        }

        return $e;
    }

    /**
     * Linha validada -> colunas de `map_markers`, com o meta do marcador mesclado.
     *
     * `meta.name` é sincronizado com o rótulo de propósito: MapController lê `meta.name`
     * ANTES de `map_markers.label`, então editar só a coluna deixaria o jogo mostrando o
     * nome antigo — a tela estaria mentindo sobre o que ela mudou.
     *
     * @param array<string,string> $c @param array<string,mixed> $metaAntes
     * @return array<string,mixed>
     */
    private function dadosMarcador(array $c, array $metaAntes): array
    {
        $meta = $metaAntes;

        if ($c['color'] !== '') {
            $meta['color'] = $c['color'];
        } else {
            unset($meta['color']);
        }

        if ($c['anchor'] !== '') {
            $meta['label'] = $c['anchor'];
        } else {
            unset($meta['label']);
        }

        if (array_key_exists('name', $meta) || $c['label'] !== '') {
            $meta['name'] = $c['label'];
        }

        return [
            'kind'  => $c['kind'],
            'label' => $c['label'] !== '' ? $c['label'] : null,
            'x'     => number_format((float)$c['x'], self::COORD_CASAS, '.', ''),
            'y'     => number_format((float)$c['y'], self::COORD_CASAS, '.', ''),
            'meta'  => $meta === [] ? null : $this->json($meta),
        ];
    }

    /**
     * @param array<string,mixed> $antes linha do banco @param array<string,mixed> $novo
     */
    private function marcadorIgual(array $antes, array $novo): bool
    {
        return (string)$antes['kind'] === (string)$novo['kind']
            && (string)($antes['label'] ?? '') === (string)($novo['label'] ?? '')
            && number_format((float)$antes['x'], self::COORD_CASAS, '.', '') === (string)$novo['x']
            && number_format((float)$antes['y'], self::COORD_CASAS, '.', '') === (string)$novo['y']
            && $this->json(is_array($antes['meta_arr'] ?? null) ? $antes['meta_arr'] : []) === (string)($novo['meta'] ?? $this->json([]));
    }

    /**
     * Catálogo do <select> de tipo: o que o painel conhece, mais o que já existe no banco.
     * @param array<string,mixed> $cromo
     * @return array<string,string> kind => rótulo legível
     */
    private function catalogoDeMarcadores(array $cromo): array
    {
        $out = [];
        foreach ((array)($cromo['marcadores'] ?? []) as $kind => $info) {
            $out[(string)$kind] = (string)($info['label'] ?? $kind) . ' · ' . (string)($info['camada'] ?? '');
        }
        foreach (MapsRepo::tiposDeMarcadorEmUso() as $kind) {
            if (!isset($out[$kind])) {
                // Tipo que veio de patch/seed e o painel não cataloga: aparece como está, com
                // o aviso — some do <select> seria pior, viraria perda de dado no primeiro save.
                $out[$kind] = $kind . ' · fora do catálogo';
            }
        }
        return $out;
    }

    // =====================================================================================
    // APOIO
    // =====================================================================================

    /**
     * Lê `campo[chave][sub]` do POST. Request::inputArray() achata array aninhado em string
     * vazia, então a grade de marcadores e a de extrações precisam do corpo cru.
     * @return array<int|string,mixed>
     */
    private function arrayDoPost(Request $request, string $chave): array
    {
        $v = $request->all()[$chave] ?? [];
        return is_array($v) ? $v : [];
    }

    /** @param array<string,int> $vinculos */
    private function frasePorVinculo(array $vinculos): string
    {
        $partes = [];
        if (isset($vinculos['bases'])) {
            $partes[] = $vinculos['bases'] . ' base(s) governamental(is) fincada(s) nele';
        }
        if (isset($vinculos['partidas'])) {
            $partes[] = $vinculos['partidas'] . ' partida(s) já registrada(s) no histórico de jogadores';
        }
        if (isset($vinculos['safe'])) {
            $partes[] = $vinculos['safe'] . ' safe zone(s) ancorada(s) nele';
        }
        return implode('; ', $partes) . '.';
    }

    /**
     * O que mudou entre a linha do banco e o que vai ser gravado — só os NOMES dos campos.
     * Valor não entra na auditoria de propósito: descrição de mapa tem 400 caracteres e
     * `meta` é um JSON inteiro; a trilha ficaria ilegível e a tela de Logs, impraticável.
     *
     * @param array<string,mixed> $antes @param array<string,mixed> $depois
     * @return list<string>
     */
    private function diferencas(array $antes, array $depois): array
    {
        $out = [];
        foreach ($depois as $campo => $valor) {
            $velho = $antes[$campo] ?? null;
            if ((string)$velho !== (string)$valor) {
                $out[] = $campo;
            }
        }
        return $out;
    }

    private function json(array $v): string
    {
        return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function voltar(string $msg, string $tipo = 'ok'): Response
    {
        flash($tipo, $msg);
        return $this->redirect('admin/mapas');
    }
}
