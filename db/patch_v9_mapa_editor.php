<?php declare(strict_types=1);

/**
 * Patch v9 — CATÁLOGO DE PONTOS e a arte nova do mundo (retrato).
 *
 *   php db/patch_v9_mapa_editor.php              aplica no banco local
 *   php db/patch_v9_mapa_editor.php --dry-run    só mostra o que faria
 *   php db/patch_v9_mapa_editor.php --sql        escreve db/backups/patch_v9_<data>.sql
 *
 * TRÊS COISAS ACONTECEM AQUI:
 *
 * 1. `map_point_types` — o catálogo de ícones pré-fabricados que o painel arrasta para o mapa.
 *    Até agora o tipo de um marcador era uma constante em PHP (MapController::LAYER_OF): para
 *    acrescentar "cafeteria" era preciso editar código. O catálogo tira isso do código e põe
 *    no banco, que é onde o operador alcança. Os tipos que o JOGO conhece de nascença entram
 *    marcados como `is_system` e não podem ser apagados — apagar "extração" não removeria um
 *    ícone, removeria uma regra de jogo.
 *
 * 2. `maps.meta.world_image` — cada mapa passa a dizer QUAL arte ele desenha. Era constante no
 *    cromo (app/Data/mapa.php); o editor precisa saber sobre qual imagem está posicionando, e
 *    um dia haverá mais de uma ilha.
 *
 * 3. Os marcadores do mapa 1 são recriados sobre a arte nova. A ilha mudou de paisagem E de
 *    orientação (era 1672x941 deitada, agora é 941x1672 em pé), então toda coordenada antiga
 *    caía na água. Cada marcador também passa a apontar para o seu tipo no catálogo.
 *
 * `map_markers` e `map_point_types` são CATÁLOGO, não dado de jogador: apagar e reinserir o
 * conjunto do mapa 1 é seguro (db/guard.php protege users/player_*) e mantém o patch idempotente.
 */

require __DIR__ . '/cli_only.php';
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$DRY = in_array('--dry-run', $argv, true);
$SQL = in_array('--sql', $argv, true);
$out = [];
$log = static function (string $m) use ($DRY, $SQL): void {
    echo ($DRY ? '[dry] ' : ($SQL ? '[sql] ' : '')) . $m . "\n";
};
$lit = static function (mixed $v): string {
    if ($v === null) { return 'NULL'; }
    if (is_bool($v)) { return $v ? '1' : '0'; }
    if (is_int($v) || is_float($v)) { return (string)$v; }
    if (is_array($v)) { $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$v) . "'";
};

echo "=== Patch v9 — catálogo de pontos + arte nova do mundo ===\n\n";

/* =========================================================================================
   1) TABELA DO CATÁLOGO
   ========================================================================================= */

/**
 * `icon` guarda a ORIGEM junto com o nome, num prefixo, em vez de três colunas quase sempre
 * nulas: `glyph:` é um dos desenhos vetoriais da tela do mapa, `symbol:` é um Material Symbol
 * (a fonte que o resto da HUD já carrega, então cafeteria/shopping saem de graça) e `image:`
 * é uma arte enviada pelo painel. Um campo, um validador, um renderizador.
 */
$DDL = <<<'SQL'
CREATE TABLE IF NOT EXISTS map_point_types (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(48)  NOT NULL,
  label       VARCHAR(60)  NOT NULL,
  category    VARCHAR(32)  NOT NULL,
  kind        VARCHAR(24)  NOT NULL,
  icon        VARCHAR(180) NOT NULL DEFAULT 'symbol:place',
  color       VARCHAR(16)  NOT NULL DEFAULT 'white',
  layer       VARCHAR(24)  NULL,
  show_label  TINYINT(1)   NOT NULL DEFAULT 0,
  description VARCHAR(255) NULL,
  is_system   TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mpt_slug (slug),
  KEY idx_mpt_cat (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

/**
 * [slug, rótulo, categoria, kind, icon, cor, rótulo no mapa?, is_system, descrição]
 *
 * `kind` é o que vai para map_markers.kind — o vocabulário que o jogo já sabe desenhar e
 * filtrar. Vários pontos civis compartilham o mesmo kind de propósito: o que distingue um
 * café de um restaurante é o ÍCONE e o nome, não uma regra nova no MapController.
 */
$TIPOS = [
    // --- operacional (nascem com o jogo) ------------------------------------------------
    ['mission-objective', 'Objetivo de missão',  'operacional', 'mission',    'glyph:mission',      'gold',   1, 1, 'Ponto de uma missão já cadastrada. O editor puxa o nome e os detalhes da própria missão.'],
    ['hunter-base',    'Base Hunter',            'operacional', 'gov_base',   'glyph:gov-base',     'lime',   1, 1, 'Base governamental: até 4 Hunters, estado visível sem vagas exatas.'],
    ['field-base',     'Base de campo',          'operacional', 'field_base', 'glyph:field-base',   'muted',  0, 1, 'Base construída por jogador. O dono define se aparece como minha, do esquadrão ou desconhecida.'],
    ['safe-zone',      'Safe Zone',              'operacional', 'safe_zone',  'glyph:safe-zone',    'lime',   1, 1, 'Perímetro civil: mercado, oficina e trégua entre operações.'],
    ['aegis-site',     'Instalação Aegis',       'operacional', 'aegis',      'glyph:aegis',        'cyan',   1, 1, 'Posto fortificado da Aegis. O status muda conforme o contrato do jogador.'],
    ['frontier-site',  'Acampamento Frontier',   'operacional', 'frontier',   'glyph:frontier',     'amber',  1, 1, 'Ponto de apoio da Frontier em rota oculta.'],
    ['extraction',     'Ponto de extração',      'operacional', 'extraction', 'glyph:extraction',   'lime',   0, 1, 'Saída do setor. Bloqueada para Sobreviventes.'],
    ['outpost',        'Posto avançado',         'operacional', 'base',       'glyph:base',         'lime',   0, 1, 'Posição militar menor, sem capacidade de extração.'],
    ['camp',           'Acampamento',            'operacional', 'camp',       'glyph:camp',         'lime',   0, 1, 'Ocupação temporária.'],
    ['radio-tower',    'Torre de rádio',         'operacional', 'radio',      'glyph:radio',        'white',  1, 1, 'Retransmissor: intercepta sinais e amplia o alcance do esquadrão.'],
    ['village',        'Vila / complexo',        'operacional', 'village',    'glyph:village',      'white',  1, 1, 'Assentamento civil, ocupado ou abandonado.'],

    // --- hostil (Veyra) -----------------------------------------------------------------
    ['veyra-nest',     'Ninho Veyra',            'hostil', 'nest',         'glyph:nest',         'red',    1, 1, 'Foco de nascimento. Combate prolongado aqui eleva a infestação do setor.'],
    ['veyra-colony',   'Colônia Veyra',          'hostil', 'colony',       'glyph:nest',         'red',    1, 1, 'Colônia madura: as castas daqui compartilham respostas.'],
    ['veyra-core',     'Núcleo Negro',           'hostil', 'core',         'glyph:nest',         'purple', 1, 1, 'Onde a rede guarda memória. Objetivo de missão de Ciência.'],
    ['veyra-surge',    'Surto',                  'hostil', 'surge',        'glyph:nest',         'red',    1, 1, 'Resposta ativa da rede. Temporário e móvel.'],
    ['contaminated',   'Área contaminada',       'hostil', 'contaminated', 'glyph:contaminated', 'purple', 0, 1, 'Esporos no ar: bioexposição sobe enquanto o operador permanece.'],
    ['human-signal',   'Atividade humana',       'hostil', 'human_activity', 'glyph:human',      'amber',  0, 1, 'Sinal temporário e de posição aproximada. Desenha um raio, não um ponto.'],

    // --- logística ----------------------------------------------------------------------
    ['convoy',         'Comboio',                'logistica', 'convoy',          'glyph:convoy', 'cyan',  0, 1, 'Janela logística: carga em movimento.'],
    ['supply-drop',    'Entrega aérea',          'logistica', 'supply_drop',     'glyph:convoy', 'cyan',  0, 1, 'Queda programada de suprimento.'],
    ['abandoned-cargo','Carga abandonada',       'logistica', 'abandoned_cargo', 'glyph:convoy', 'amber', 0, 1, 'Sobrou de um comboio que não chegou.'],
    ['resupply',       'Reabastecimento',        'logistica', 'resupply',        'glyph:convoy', 'cyan',  0, 1, 'Ponto de recomposição de munição e consumíveis.'],

    // --- combustível --------------------------------------------------------------------
    ['fuel-station',   'Posto de gasolina',      'combustivel', 'fuel', 'glyph:fuel',              'amber', 0, 0, 'Combustível para veículos. Ponto de parada óbvio — e por isso vigiado.'],
    ['fuel-depot',     'Depósito de combustível','combustivel', 'fuel', 'symbol:propane_tank',     'amber', 0, 0, 'Estoque em volume. Alvo de alto valor.'],
    ['charger',        'Carregador elétrico',    'combustivel', 'fuel', 'symbol:ev_station',       'cyan',  0, 0, 'Recarga de equipamento e veículo elétrico.'],

    // --- alimentação --------------------------------------------------------------------
    ['cafe',           'Café',                   'alimentacao', 'market', 'symbol:local_cafe',   'gold',  0, 0, 'Cafeteria em pé. Ponto de encontro dentro de zona segura.'],
    ['restaurant',     'Restaurante',            'alimentacao', 'market', 'symbol:restaurant',   'gold',  0, 0, 'Refeição quente: recupera resistência entre operações.'],
    ['diner',          'Lanchonete',             'alimentacao', 'market', 'symbol:lunch_dining', 'gold',  0, 0, 'Comida rápida, barata e sem perguntas.'],
    ['bar',            'Bar',                    'alimentacao', 'market', 'symbol:local_bar',    'gold',  0, 0, 'Onde a informação circula antes de virar contrato.'],
    ['water-point',    'Ponto de água',          'alimentacao', 'loot',   'symbol:water_drop',   'cyan',  0, 0, 'Água potável verificada.'],

    // --- comércio -----------------------------------------------------------------------
    ['market',         'Mercado',                'comercio', 'market', 'glyph:market',           'gold',  0, 0, 'Compra e venda de mantimentos.'],
    ['mall',           'Shopping',               'comercio', 'market', 'symbol:local_mall',      'gold',  1, 0, 'Complexo comercial: muitas lojas, muitos andares, muito lugar para emboscada.'],
    ['convenience',    'Loja de conveniência',   'comercio', 'market', 'symbol:storefront',      'gold',  0, 0, 'Estoque pequeno, sempre aberto.'],
    ['pharmacy',       'Farmácia',               'comercio', 'market', 'symbol:local_pharmacy',  'lime',  0, 0, 'Medicamentos e material de curativo.'],
    ['workshop',       'Oficina',                'comercio', 'market', 'symbol:build',           'white', 0, 0, 'Reparo de arma, armadura e veículo.'],
    ['bank',           'Casa de câmbio',         'comercio', 'market', 'symbol:payments',        'gold',  0, 0, 'Converte créditos operacionais.'],

    // --- saúde --------------------------------------------------------------------------
    ['hospital',       'Hospital de campanha',   'saude', 'hospital', 'glyph:hospital',       'white', 0, 0, 'Tratamento completo, inclusive de contaminação Veyra.'],
    ['medic-post',     'Posto médico',           'saude', 'hospital', 'symbol:medical_services','lime', 0, 0, 'Primeiros socorros e estabilização.'],
    ['decontamination','Descontaminação',        'saude', 'hospital', 'symbol:sanitizer',     'purple',0, 0, 'Remove bioexposição acumulada.'],

    // --- outros -------------------------------------------------------------------------
    ['world-event',    'Evento de mundo',        'outros', 'event', 'glyph:event',        'purple', 0, 1, 'Acontecimento com janela de tempo.'],
    ['resources',      'Área de recursos',       'outros', 'loot',  'glyph:loot',         'white',  0, 1, 'Material de construção e componentes.'],
    ['vehicle',        'Veículo',                'outros', 'loot',  'symbol:directions_car', 'white', 0, 0, 'Transporte utilizável, se ainda tiver combustível.'],
    ['landmark',       'Ponto de referência',    'outros', 'loot',  'symbol:flag',        'white',  1, 0, 'Marco visual para orientação e ponto de encontro.'],
];

$log('map_point_types: criar tabela se não existir');
$log('map_point_types: garantir ' . count($TIPOS) . ' tipos de ponto no catálogo');

/* =========================================================================================
   2) ARTE NOVA — coordenadas medidas sobre assets/img/bg/map-world.webp (941x1672, retrato)
   =========================================================================================

   Geografia da arte, em fração da largura/altura, para quem for reposicionar depois:
     montanhas nevadas   x .40-.85  y .02-.20      complexo militar NE  x .78-.86  y .14-.19
     cânion vermelho (O) x .04-.36  y .23-.45      refinaria (O)        x .15-.34  y .35-.43
     lago central        x .37-.65  y .33-.44      pântano (L)          x .67-.94  y .34-.47
     cidade + porto      x .38-.88  y .50-.72      arquipélago (SO)     x .03-.30  y .60-.82
     costa SE            x .66-.97  y .73-.93      floresta leste       x .72-.96  y .20-.35
*/
$MARCADORES = [
    // --- POIs nomeados ------------------------------------------------------------------
    ['frontier',   'frontier-site', 'BASE FRONTIER',                0.120, 0.360, 'right', 'amber',  ['faction' => 'frontier', 'tag' => 'ÚLTIMA PARADA']],
    ['gov_base',   'hunter-base',   'BASE VIGIA · HUNTER COMMAND',  0.620, 0.250, 'right', 'lime',   ['base' => 'alpha', 'status' => 'OPERACIONAL']],
    ['gov_base',   'hunter-base',   'BASE HUNTER BRAVO',            0.820, 0.165, 'right', 'lime',   ['base' => 'bravo', 'status' => 'OPERACIONAL']],
    ['gov_base',   'hunter-base',   'BASE HUNTER CHARLIE',          0.720, 0.665, 'right', 'lime',   ['base' => 'charlie', 'status' => 'EM RISCO']],
    ['aegis',      'aegis-site',    'FORTALEZA AEGIS',              0.250, 0.395, 'right', 'cyan',   ['faction' => 'aegis', 'tag' => 'DISCIPLINA SOBRE O CAOS']],
    ['village',    'village',       'COMPLEXO DE PESQUISA',         0.895, 0.310, 'right', 'white',  ['tag' => 'PROJETOS ESQUECIDOS']],
    ['village',    'village',       'VILA ABANDONADA',              0.440, 0.470, 'right', 'white',  []],
    ['radio',      'radio-tower',   'ESTAÇÃO DE RÁDIO',             0.700, 0.472, 'right', 'white',  ['tag' => 'SINAIS AINDA VIVEM']],
    ['nest',       'veyra-nest',    'NINHO VEYRA NORTE',            0.300, 0.220, 'right', 'red',    ['caste' => 'burrowers', 'pulse' => true, 'tag' => 'CONTÁGIO EM EXPANSÃO']],
    ['colony',     'veyra-colony',  'NINHO VEYRA SUL',              0.800, 0.410, 'right', 'red',    ['caste' => 'runners', 'pulse' => true, 'tag' => 'RAÍZES MAIS PROFUNDAS',
                                                                                                     'risk' => ['x' => 80.0, 'y' => 41.0, 'rx' => 6.5]]],
    ['convoy',     'abandoned-cargo','COMBOIO ABATIDO',             0.200, 0.300, 'right', 'cyan',   ['event' => 'abandoned_cargo', 'tag' => 'SAQUE É PERIGO']],
    ['extraction', 'extraction',    'PLATAFORMA DE EXTRAÇÃO ALPHA', 0.930, 0.400, 'below', 'lime',   ['narrow' => true]],
    ['extraction', 'extraction',    'PLATAFORMA DE EXTRAÇÃO BRAVO', 0.820, 0.800, 'below', 'lime',   ['narrow' => true]],

    // --- Safe Zones ---------------------------------------------------------------------
    ['safe_zone',  'safe-zone',     'SAFE ZONE VIGIA',              0.565, 0.288, 'below', 'lime',   ['zone' => 'vigia']],
    ['safe_zone',  'safe-zone',     'ZONA FRANCA',                  0.280, 0.545, 'below', 'lime',   ['zone' => 'franca']],
    ['safe_zone',  'safe-zone',     'ENTREPOSTO CENTRAL',           0.600, 0.585, 'below', 'lime',   ['zone' => 'entreposto']],

    // --- serviços civis dentro e ao redor das zonas seguras -------------------------------
    ['fuel',       'fuel-station',  'POSTO DE COMBUSTÍVEL',         0.360, 0.325, false, 'amber', []],
    ['fuel',       'fuel-station',  'POSTO DE COMBUSTÍVEL',         0.665, 0.625, false, 'amber', []],
    ['market',     'market',        'MERCADO',                      0.555, 0.615, false, 'gold',  []],
    ['market',     'market',        'MERCADO',                      0.470, 0.635, false, 'gold',  []],
    ['market',     'cafe',          'CAFÉ DO PORTO',                0.508, 0.668, false, 'gold',  []],
    ['market',     'mall',          'CENTRO COMERCIAL',             0.640, 0.560, false, 'gold',  []],
    ['hospital',   'hospital',      'HOSPITAL DE CAMPANHA',         0.625, 0.545, false, 'white', []],
    ['hospital',   'hospital',      'HOSPITAL DE CAMPANHA',         0.235, 0.412, false, 'white', []],

    // --- postos, acampamentos e base de campo ---------------------------------------------
    ['base',       'outpost',       'POSTO AVANÇADO NORTE',         0.420, 0.115, false, 'lime',  []],
    ['camp',       'camp',          'ACAMPAMENTO LENHADOR',         0.200, 0.165, false, 'lime',  []],
    ['camp',       'camp',          'ACAMPAMENTO DOS CAÇADORES',    0.855, 0.335, false, 'lime',  []],
    ['field_base', 'field-base',    'BASE DE CAMPO DESCONHECIDA',   0.155, 0.680, false, 'muted', ['owner' => 'unknown']],

    // --- Veyra / atividade humana ---------------------------------------------------------
    ['contaminated', 'contaminated', 'ÁREA CONTAMINADA · SPORECASTERS', 0.755, 0.440, false, 'purple', ['caste' => 'sporecasters']],
    // `radius` (% da largura do mapa): sinal humano é POSIÇÃO APROXIMADA, então o mapa desenha
    // a área de incerteza em vez de cravar o ponto de onde veio o tiro.
    ['human_activity', 'human-signal', 'TIROS DETECTADOS',          0.530, 0.605, false, 'amber', ['signal' => 'shots', 'ttl_min' => 25, 'narrow' => true, 'radius' => 3.0]],
    ['human_activity', 'human-signal', 'TRANSMISSÃO INTERCEPTADA',  0.310, 0.290, false, 'amber', ['signal' => 'transmission', 'ttl_min' => 40, 'narrow' => true, 'radius' => 3.8]],

    // --- pontos de extração sem rótulo -----------------------------------------------------
    ['extraction', 'extraction', 'PONTO DE EXTRAÇÃO',               0.090, 0.330, false, 'lime', []],
    ['extraction', 'extraction', 'PONTO DE EXTRAÇÃO',               0.500, 0.090, false, 'lime', []],
    ['extraction', 'extraction', 'PONTO DE EXTRAÇÃO',               0.780, 0.235, false, 'lime', []],
    ['extraction', 'extraction', 'PONTO DE EXTRAÇÃO',               0.400, 0.545, false, 'lime', []],
    ['extraction', 'extraction', 'PONTO DE EXTRAÇÃO',               0.455, 0.695, false, 'lime', []],
    ['extraction', 'extraction', 'PONTO DE EXTRAÇÃO',               0.905, 0.600, false, 'lime', []],

    // --- recursos e eventos ----------------------------------------------------------------
    ['loot',  'resources',   'ÁREA DE RECURSOS', 0.345, 0.245, false, 'white',  []],
    ['loot',  'resources',   'ÁREA DE RECURSOS', 0.665, 0.345, false, 'white',  []],
    ['loot',  'resources',   'ÁREA DE RECURSOS', 0.260, 0.630, false, 'white',  []],
    ['loot',  'resources',   'ÁREA DE RECURSOS', 0.745, 0.775, false, 'white',  []],
    ['event', 'world-event', 'EVENTO DE MUNDO',  0.530, 0.385, false, 'purple', ['pulse' => true]],
    ['event', 'world-event', 'EVENTO DE MUNDO',  0.880, 0.875, false, 'purple', ['pulse' => true]],
];

/** slug estável a partir do nome + posição (o `id` do meta que a view usa). */
$slug = static function (string $nome, float $x, float $y): string {
    $s = strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $nome) ?: $nome));
    return trim($s, '-') . '-' . (int)round($x * 1000) . (int)round($y * 1000);
};

$linhas = [];
foreach ($MARCADORES as [$kind, $tipo, $nome, $x, $y, $lado, $cor, $extra]) {
    $linhas[] = [
        'kind'  => $kind,
        'label' => $nome,
        'x'     => $x,
        'y'     => $y,
        'meta'  => array_merge([
            'id'         => $slug($nome, $x, $y),
            'name'       => $nome,
            'point_type' => $tipo,     // liga o marcador ao catálogo: ícone e cor saem de lá
            'color'      => $cor,
            'label'      => $lado,
        ], $extra),
    ];
}

$log('maps.meta.world_image: apontar as 5 regiões para a arte nova (bg/map-world.webp)');
$log('map_markers do mapa 1: apagar conjunto antigo e inserir ' . count($linhas) . ' na arte retrato');

/* =========================================================================================
   3) APLICAÇÃO
   ========================================================================================= */

$ARTE = 'bg/map-world.webp';

if ($SQL) {
    $out[] = $DDL . ';';
    foreach ($TIPOS as $i => [$s, $lbl, $cat, $kind, $ic, $cor, $lab, $sys, $desc]) {
        $out[] = sprintf(
            'INSERT INTO map_point_types (slug, label, category, kind, icon, color, show_label, description, is_system, sort_order)'
            . ' VALUES (%s, %s, %s, %s, %s, %s, %d, %s, %d, %d)'
            . ' ON DUPLICATE KEY UPDATE label=VALUES(label), category=VALUES(category), kind=VALUES(kind),'
            . ' icon=VALUES(icon), color=VALUES(color), show_label=VALUES(show_label),'
            . ' description=VALUES(description), is_system=VALUES(is_system), sort_order=VALUES(sort_order);',
            $lit($s), $lit($lbl), $lit($cat), $lit($kind), $lit($ic), $lit($cor), $lab, $lit($desc), $sys, ($i + 1) * 10
        );
    }
    $out[] = "UPDATE maps SET meta = JSON_SET(COALESCE(meta, '{}'), '$.world_image', " . $lit($ARTE) . ") WHERE kind = 'region';";
    $out[] = 'DELETE FROM map_markers WHERE map_id = 1;';
    foreach ($linhas as $l) {
        $out[] = sprintf(
            'INSERT INTO map_markers (map_id, kind, label, x, y, meta) VALUES (1, %s, %s, %s, %s, %s);',
            $lit($l['kind']), $lit($l['label']), $lit($l['x']), $lit($l['y']), $lit($l['meta'])
        );
    }
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    $file = $dir . '/patch_v9_' . date('Y-m-d_His') . '.sql';
    file_put_contents($file, "-- Patch v9 — catálogo de pontos do mapa + arte nova (retrato)\n" . implode("\n", $out) . "\n");
    echo "\n" . count($out) . " instruções em $file\n";
    return;
}

if (!$DRY) {
    Database::run($DDL, []);
    Database::transaction(static function () use ($TIPOS, $linhas, $ARTE): void {
        foreach ($TIPOS as $i => [$s, $lbl, $cat, $kind, $ic, $cor, $lab, $sys, $desc]) {
            Database::run(
                'INSERT INTO map_point_types (slug, label, category, kind, icon, color, show_label, description, is_system, sort_order)'
                . ' VALUES (:slug, :label, :cat, :kind, :icon, :cor, :lab, :desc, :sys, :ord)'
                . ' ON DUPLICATE KEY UPDATE label=VALUES(label), category=VALUES(category), kind=VALUES(kind),'
                . ' icon=VALUES(icon), color=VALUES(color), show_label=VALUES(show_label),'
                . ' description=VALUES(description), is_system=VALUES(is_system), sort_order=VALUES(sort_order)',
                ['slug' => $s, 'label' => $lbl, 'cat' => $cat, 'kind' => $kind, 'icon' => $ic,
                 'cor' => $cor, 'lab' => $lab, 'desc' => $desc, 'sys' => $sys, 'ord' => ($i + 1) * 10]
            );
        }
        Database::run("UPDATE maps SET meta = JSON_SET(COALESCE(meta, '{}'), '$.world_image', :a) WHERE kind = 'region'", ['a' => $ARTE]);
        Database::run('DELETE FROM map_markers WHERE map_id = 1', []);
        foreach ($linhas as $l) {
            Database::run(
                'INSERT INTO map_markers (map_id, kind, label, x, y, meta) VALUES (1, :k, :l, :x, :y, :m)',
                ['k' => $l['kind'], 'l' => $l['label'], 'x' => $l['x'], 'y' => $l['y'],
                 'm' => json_encode($l['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
            );
        }
    });
}

echo "\nPatch v9 concluído — " . count($TIPOS) . " tipo(s) de ponto, " . count($linhas) . " marcador(es).\n";
