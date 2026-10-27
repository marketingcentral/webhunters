<?php declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Repo\MapPointsRepo;
use App\Repo\MapsRepo;
use App\Repo\MissionRepo;

/**
 * Tela EDITAR MAPA do painel: /admin/mapas/editor.
 *
 * É o lugar onde se COMPÕE o mapa: a arte de um lado, a paleta de ícones pré-fabricados do
 * outro, e o operador arrasta um para cima da outra. Nenhum número digitado.
 *
 * POR QUE ESTA TELA EXISTE
 * Marcador sempre foi uma linha de tabela com dois decimais: `x=0.6280`, `y=0.7120`. Para pôr
 * um posto de gasolina na entrada do porto era preciso adivinhar a fração, salvar, abrir o
 * jogo, ver que ficou no mar, e repetir. O trabalho é espacial e estava sendo feito por
 * digitação. Aqui a coordenada continua sendo a mesma fração no banco — o que muda é que
 * ela é PRODUZIDA por um gesto sobre a própria arte.
 *
 * QUATRO DECISÕES QUE EXPLICAM O CÓDIGO
 *
 * 1. GRAVAÇÃO POR DIFERENÇA, não por apagar-e-reinserir. O editor manda o conjunto inteiro,
 *    mas o servidor casa por `id`: quem já existia é ATUALIZADO, quem sumiu do conjunto é
 *    apagado, quem chegou sem id é criado. Apagar tudo e reinserir seria mais curto de
 *    escrever e trocaria todos os ids a cada salvamento — e ids que mudam sozinhos são a
 *    forma mais silenciosa de quebrar um log de auditoria e qualquer referência futura.
 *
 * 2. O `meta` de cada marcador é MESCLADO com o que estava gravado. A coluna é JSON livre e o
 *    jogo evolui: hoje o editor conhece cor, rótulo, raio e vínculo com missão; amanhã um
 *    patch grava `caste` ou `ttl_min`, que esta tela não tem campo para editar. Substituir o
 *    JSON apagaria essas chaves no primeiro "Salvar" que alguém desse sobre um marcador
 *    semeado por patch.
 *
 * 3. O POST é um FORMULÁRIO comum com um campo JSON, e não um corpo `application/json`. Assim
 *    o CSRF, o `Request::input()` e o tratamento de erro são exatamente os mesmos das outras
 *    treze telas do painel — um segundo caminho de requisição significaria um segundo lugar
 *    onde esquecer a checagem de sessão.
 *
 * 4. O editor NÃO cria missão. Arrastar uma missão da paleta fixa no terreno uma missão que já
 *    existe (`meta.mission_id`) e herda o nome dela. Editar objetivo, recompensa e requisito
 *    continua sendo tarefa de /admin/missoes: duas telas escrevendo na mesma missão seria
 *    duas verdades sobre a mesma missão.
 */
final class MapEditorController extends AdminController
{
    /** Teto de marcadores por mapa. Não é regra de jogo: é freio contra um POST absurdo. */
    private const MAX_MARCADORES = 400;

    /** Casas decimais de `map_markers.x/y` — a coluna é DECIMAL(6,4). */
    private const COORD_CASAS = 4;

    /** Arte usada quando o mapa ainda não declarou a sua (`maps.meta.world_image`). */
    private const ARTE_PADRAO = 'bg/map-world.webp';

    /**
     * Opacidade da arte quando o mapa nada diz, e os limites do que se pode gravar.
     *
     * A arte é um render colorido e de alto contraste; o texto da HUD é claro e fino. Sobre a
     * neve ou a areia, rótulo branco some. Recuar a arte sobre o quase-preto do palco devolve
     * contraste a TUDO que está por cima de uma vez, em vez de pôr uma tarja atrás de cada
     * palavra. O piso de 0,15 existe porque abaixo disso não há mais mapa — e um operador que
     * arrastasse o controle até o fim ficaria com uma tela preta sem entender o motivo.
     */
    private const OPACIDADE_PADRAO = 0.65;
    private const OPACIDADE_MIN    = 0.15;

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }

        $mapas = MapsRepo::paraEditor();
        if ($mapas === []) {
            return $this->semMapa();
        }

        $id   = (int)($request->query('mapa') ?? 0);
        $mapa = $id > 0 ? MapsRepo::porId($id) : null;
        if ($mapa === null) {
            $mapa = MapsRepo::porId((int)$mapas[0]['id']);
        }
        if ($mapa === null) {
            return $this->semMapa();
        }

        $mapaId = (int)$mapa['id'];
        $arte   = (string)(($mapa['meta_arr']['world_image'] ?? '') ?: self::ARTE_PADRAO);

        $dados = [
            'cromo'      => data_mock('admin-mapas-editor'),
            'mapas'      => $mapas,
            'mapa'       => $mapa,
            'arte'       => $arte,
            'opacidade'  => $this->opacidade($mapa['meta_arr'] ?? []),
            'marcadores' => $this->marcadoresParaTela($mapaId),
            'paleta'     => MapPointsRepo::porCategoria(),
            'missoes'    => MissionRepo::paraMapa(),
            'kinds'      => $this->kinds(),
            'cores'      => ['white', 'lime', 'cyan', 'amber', 'gold', 'red', 'purple', 'muted'],
            'glifos'     => data_mock('map_glyphs'),
            'max'        => self::MAX_MARCADORES,
        ];

        $page = $this->adminPage(
            'mapas-editor',
            'Editar mapa',
            (string)$mapa['name'] . ' · ' . count($dados['marcadores']) . ' marcador(es)'
        );
        $page['actions'] =
            '<a class="adm-btn adm-btn--ghost" href="' . e(url('admin/mapas/pontos')) . '">Criar pontos</a>'
            . '<a class="adm-btn adm-btn--ghost" href="' . e(url('admin/mapas', ['id' => (string)$mapaId])) . '">Cadastro do mapa</a>';

        return $this->render($page['slug'], $page, $dados);
    }

    /**
     * Grava o conjunto de marcadores do mapa. Ver decisões 1, 2 e 3 no topo.
     *
     * @param array<string, string> $params
     */
    public function salvar(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $mapaId = (int)$request->input('mapa_id', '0');
        $mapa   = $mapaId > 0 ? MapsRepo::porId($mapaId) : null;
        if ($mapa === null) {
            return $this->voltar(0, 'Mapa não encontrado — nada foi alterado.', 'error');
        }

        $bruto = json_decode((string)$request->input('marcadores', '[]'), true);
        if (!is_array($bruto)) {
            return $this->voltar($mapaId, 'Não consegui ler o que o editor enviou — nada foi alterado.', 'error');
        }
        if (count($bruto) > self::MAX_MARCADORES) {
            return $this->voltar($mapaId, 'Limite de ' . self::MAX_MARCADORES . ' marcadores por mapa — nada foi alterado.', 'error');
        }

        $antes  = [];
        foreach (MapsRepo::marcadores($mapaId) as $m) {
            $antes[(int)$m['id']] = $m;
        }

        $kinds     = $this->kinds();
        $tipos     = MapPointsRepo::porSlug();
        $manter    = [];
        $novos     = [];
        $alterados = [];
        $recusados = 0;

        foreach ($bruto as $linha) {
            if (!is_array($linha)) { $recusados++; continue; }
            $preparado = $this->prepararLinha($linha, $kinds, $tipos, $antes);
            if ($preparado === null) {
                $recusados++;
                /* Linha recusada de um marcador que EXISTE não pode virar exclusão. Ausente do
                   conjunto e presente-porém-inválido são coisas diferentes: a primeira é o
                   operador removendo o ponto, a segunda é um dado estragado no caminho. Sem
                   esta linha, um único campo corrompido apagaria o marcador em silêncio —
                   exatamente o que o teste de gravação pegou. */
                $idRecusado = (int)($linha['id'] ?? 0);
                if ($idRecusado > 0 && isset($antes[$idRecusado])) { $manter[] = $idRecusado; }
                continue;
            }

            [$id, $dados] = $preparado;
            if ($id > 0) {
                $manter[] = $id;
                if ($this->mudou($antes[$id], $dados)) { $alterados[$id] = $dados; }
            } else {
                $novos[] = $dados;
            }
        }

        $apagar = array_values(array_diff(array_keys($antes), $manter));

        /* A opacidade viaja no MESMO envio dos marcadores, e não num formulário próprio: ela é
           regulada olhando para a arte, com os pontos por cima — separá-la obrigaria a sair da
           tela onde o efeito se vê. Vai para `maps.meta` por JSON_SET, que preserva todas as
           outras chaves do JSON (capacidade, infestação, extrações…). */
        $opAntes = $this->opacidade($mapa['meta_arr'] ?? []);
        $opNova  = $this->normalizarOpacidade($request->input('opacidade', ''), $opAntes);
        $opMudou = abs($opNova - $opAntes) > 0.0001;
        if ($opMudou) {
            Database::run(
                "UPDATE maps SET meta = JSON_SET(COALESCE(meta, '{}'), '$.map_opacity', :o) WHERE id = :id",
                ['o' => $opNova, 'id' => $mapaId]
            );
            $this->auditar('mapa_opacidade', 'map', $mapaId,
                'Opacidade da arte de "' . (string)$mapa['name'] . '" ajustada.',
                ['antes' => $opAntes, 'depois' => $opNova]);
        }

        if ($alterados === [] && $novos === [] && $apagar === []) {
            if ($opMudou) {
                return $this->voltar($mapaId, 'Opacidade da arte gravada em ' . (int)round($opNova * 100) . '%.');
            }
            return $this->voltar($mapaId, $recusados > 0
                ? 'Nada foi alterado — ' . $recusados . ' marcador(es) chegaram inválidos e foram ignorados.'
                : 'Nada mudou no mapa.', $recusados > 0 ? 'error' : 'ok');
        }

        Database::transaction(static function () use ($alterados, $novos, $apagar, $mapaId): void {
            foreach ($alterados as $id => $d) { MapsRepo::atualizarMarcador((int)$id, $d); }
            foreach ($novos as $d)            { MapsRepo::criarMarcador($mapaId, $d); }
            foreach ($apagar as $id)          { MapsRepo::excluirMarcador((int)$id); }
        });

        $this->auditar('mapa_editor_salvo', 'map', $mapaId,
            'Marcadores de "' . (string)$mapa['name'] . '" atualizados pelo editor.',
            ['criados' => count($novos), 'alterados' => count($alterados), 'excluidos' => count($apagar), 'ignorados' => $recusados]);

        $partes = [];
        if ($novos !== [])     { $partes[] = count($novos) . ' criado(s)'; }
        if ($alterados !== []) { $partes[] = count($alterados) . ' movido(s) ou editado(s)'; }
        if ($apagar !== [])    { $partes[] = count($apagar) . ' removido(s)'; }
        if ($opMudou)          { $partes[] = 'opacidade em ' . (int)round($opNova * 100) . '%'; }
        $msg = 'Mapa salvo: ' . implode(', ', $partes) . '.';
        if ($recusados > 0) { $msg .= ' ' . $recusados . ' marcador(es) inválidos foram ignorados.'; }

        return $this->voltar($mapaId, $msg);
    }

    // =====================================================================================
    // APOIO
    // =====================================================================================

    /**
     * Valida e normaliza uma linha vinda do editor.
     *
     * Devolve `[id, dados]` — id 0 significa marcador novo — ou null quando a linha não
     * merece ir para o banco. Recusar em silêncio (contando) em vez de abortar o salvamento
     * inteiro é deliberado: o operador acabou de mexer em quarenta pontos, e perder os
     * trinta e nove bons por causa de um estragado seria pior do que ignorá-lo e dizer
     * quantos foram ignorados.
     *
     * @param array<string,mixed> $l
     * @param array<string,string> $kinds
     * @param array<string,array<string,mixed>> $tipos
     * @param array<int,array<string,mixed>> $antes
     * @return array{0:int,1:array<string,mixed>}|null
     */
    private function prepararLinha(array $l, array $kinds, array $tipos, array $antes): ?array
    {
        $id = (int)($l['id'] ?? 0);
        if ($id > 0 && !isset($antes[$id])) {
            // Marcador de outro mapa (ou já apagado por outra aba): não é para ser tocado aqui.
            return null;
        }

        $x = (float)($l['x'] ?? -1);
        $y = (float)($l['y'] ?? -1);
        if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
            return null;
        }

        $tipoSlug = (string)($l['point_type'] ?? '');
        $tipo     = $tipos[$tipoSlug] ?? null;

        // O `kind` vem do tipo do catálogo quando há um; o do cliente só é aceito se for do
        // vocabulário do jogo. Um kind inventado entraria no banco e o mapa não saberia
        // desenhá-lo nem em qual camada pô-lo.
        $kind = (string)($l['kind'] ?? '');
        if ($tipo !== null) {
            $kind = (string)$tipo['kind'];
        } elseif (!isset($kinds[$kind])) {
            return null;
        }

        $nome = mb_substr(trim((string)($l['name'] ?? '')), 0, 80);
        if ($nome === '') {
            $nome = $tipo !== null ? (string)$tipo['label'] : $kind;
        }

        /* meta: o que estava gravado, por baixo; o que o editor conhece, por cima. Ver
           decisão 2 no topo — as chaves que esta tela não edita sobrevivem intactas. */
        $meta = $id > 0 ? (array)($antes[$id]['meta_arr'] ?? []) : [];
        $meta['name'] = $nome;
        $meta['id']   = (string)($meta['id'] ?? '') !== '' ? $meta['id'] : $this->slug($nome, $x, $y);

        if ($tipoSlug !== '' && $tipo !== null) { $meta['point_type'] = $tipoSlug; }

        $cor = (string)($l['color'] ?? '');
        if ($cor !== '') { $meta['color'] = $cor; }

        // `label` decide se o nome aparece ao lado do ícone no mapa do jogador.
        $rotulo = (string)($l['label'] ?? '');
        $meta['label'] = in_array($rotulo, ['right', 'below'], true) ? $rotulo : false;

        $raio = (float)($l['radius'] ?? 0);
        if ($raio > 0) { $meta['radius'] = round(min(40, $raio), 2); } else { unset($meta['radius']); }

        $missao = (int)($l['mission_id'] ?? 0);
        if ($missao > 0) { $meta['mission_id'] = $missao; } else { unset($meta['mission_id']); }

        /* Ícone próprio deste marcador. Só entra quando é DIFERENTE do que o tipo do catálogo
           já daria — gravar o mesmo valor criaria uma cópia que deixaria de acompanhar o tipo
           na primeira vez que alguém trocasse o desenho do café no catálogo. */
        $icone = trim((string)($l['icon'] ?? ''));
        if ($icone !== '' && $this->iconeValido($icone) && ($tipo === null || $icone !== (string)$tipo['icon'])) {
            $meta['icon'] = $icone;
        } else {
            unset($meta['icon']);
        }

        return [$id, [
            'kind'  => $kind,
            'label' => $nome,
            'x'     => round($x, self::COORD_CASAS),
            'y'     => round($y, self::COORD_CASAS),
            'meta'  => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]];
    }

    /**
     * Mudou de verdade? Sem isto todo salvamento escreveria as 43 linhas do mapa, e o log de
     * auditoria diria "43 alterados" mesmo quando o operador moveu um ponto só.
     *
     * @param array<string,mixed> $antes linha crua do banco
     * @param array<string,mixed> $novo
     */
    private function mudou(array $antes, array $novo): bool
    {
        $mesmaCoord = static fn(mixed $a, mixed $b): bool =>
            abs((float)$a - (float)$b) < 0.00005;   // meia casa além da precisão da coluna

        return (string)$antes['kind'] !== (string)$novo['kind']
            || (string)($antes['label'] ?? '') !== (string)$novo['label']
            || !$mesmaCoord($antes['x'], $novo['x'])
            || !$mesmaCoord($antes['y'], $novo['y'])
            || json_encode((array)($antes['meta_arr'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
               !== json_encode(json_decode((string)$novo['meta'], true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Marcadores no formato que o editor consome — coordenadas em fração, como no banco, e
     * as chaves de `meta` que a tela sabe editar já promovidas ao primeiro nível.
     *
     * @return list<array<string,mixed>>
     */
    private function marcadoresParaTela(int $mapaId): array
    {
        $out = [];
        foreach (MapsRepo::marcadores($mapaId) as $m) {
            $meta = (array)($m['meta_arr'] ?? []);
            $out[] = [
                'id'         => (int)$m['id'],
                'kind'       => (string)$m['kind'],
                'name'       => (string)($meta['name'] ?? $m['label'] ?? ''),
                'x'          => round((float)$m['x'], self::COORD_CASAS),
                'y'          => round((float)$m['y'], self::COORD_CASAS),
                'point_type' => (string)($meta['point_type'] ?? ''),
                'icon'       => (string)($meta['icon'] ?? ''),   // '' = herda o do tipo
                'color'      => (string)($meta['color'] ?? 'white'),
                'label'      => is_string($meta['label'] ?? null) ? (string)$meta['label'] : '',
                'radius'     => (float)($meta['radius'] ?? 0),
                'mission_id' => (int)($meta['mission_id'] ?? 0),
                // chaves que o editor não edita, só para ele AVISAR que existem antes de salvar
                'extras'     => array_values(array_diff(
                    array_keys($meta),
                    ['name', 'id', 'point_type', 'icon', 'color', 'label', 'radius', 'mission_id', 'glyph']
                )),
            ];
        }
        return $out;
    }

    /**
     * O ícone está no formato `origem:valor` e a origem é uma das três conhecidas?
     *
     * Confere a FORMA, não a existência: um vetor removido da tabela ou uma arte apagada do
     * disco viram o ícone de falta na tela, e não um marcador que some. O que esta checagem
     * impede é gravar texto arbitrário num campo que a view vai interpretar.
     */
    private function iconeValido(string $icon): bool
    {
        [$origem, $valor] = MapPointsRepo::icone($icon);
        return $icon === $origem . ':' . $valor
            && preg_match('#^[A-Za-z0-9._/-]{1,160}$#', $valor) === 1;
    }

    /**
     * Opacidade gravada no mapa, já dentro dos limites.
     * @param array<string,mixed> $meta
     */
    private function opacidade(array $meta): float
    {
        return isset($meta['map_opacity'])
            ? $this->normalizarOpacidade((string)$meta['map_opacity'], self::OPACIDADE_PADRAO)
            : self::OPACIDADE_PADRAO;
    }

    /**
     * Texto do controle -> fração 0,15..1. Valor vazio ou ilegível NÃO vira o padrão: volta o
     * que já estava gravado. Um campo que não chegou (JavaScript desligado, campo renomeado)
     * não pode redefinir em silêncio uma escolha que o operador fez antes.
     */
    private function normalizarOpacidade(?string $bruto, float $atual): float
    {
        $v = (string)$bruto;
        if ($v === '' || !is_numeric($v)) {
            return $atual;
        }
        return round(max(self::OPACIDADE_MIN, min(1.0, (float)$v)), 2);
    }

    /** @return array<string,string> kind => rótulo, o vocabulário que o jogo sabe desenhar. */
    private function kinds(): array
    {
        $out = [];
        foreach ((array)(data_mock('admin-mapas')['marcadores'] ?? []) as $kind => $info) {
            $out[(string)$kind] = (string)($info['label'] ?? $kind);
        }
        return $out;
    }

    private function slug(string $nome, float $x, float $y): string
    {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT', $nome) ?: $nome;
        $s = trim(strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $s)), '-');
        return ($s !== '' ? $s : 'ponto') . '-' . (int)round($x * 1000) . (int)round($y * 1000);
    }

    private function semMapa(): Response
    {
        flash('error', 'Nenhum mapa cadastrado ainda — crie um em Mapas antes de abrir o editor.');
        return $this->redirect('admin/mapas');
    }

    private function voltar(int $mapaId, string $msg, string $tipo = 'ok'): Response
    {
        flash($tipo, $msg);
        return $this->redirect('admin/mapas/editor', $mapaId > 0 ? ['mapa' => (string)$mapaId] : []);
    }
}
