<?php declare(strict_types=1);

/**
 * Patch v5 — MAPA DO MUNDO sobre a nova ilha.
 *
 *   php db/patch_v5_mapa_mundo.php              aplica no banco local
 *   php db/patch_v5_mapa_mundo.php --dry-run    só mostra o que faria
 *   php db/patch_v5_mapa_mundo.php --sql        escreve db/backups/patch_v5_<data>.sql
 *
 * A arte do mapa mudou (assets/img/bg/map-world.jpg): a ilha tem outra geografia, então as
 * coordenadas antigas caíam no lugar errado. Este patch recria o conjunto de marcadores do
 * mapa 1 nas posições medidas sobre a arte nova (referência 1672x941) e acrescenta os POIs e
 * as taglines do exemplo (Base Frontier, Complexo de Pesquisa, plataformas Alpha/Bravo...).
 *
 * `map_markers` é CATÁLOGO, não dado de jogador: apagar e reinserir o conjunto do mapa 1 é
 * seguro (o guard de db/guard.php protege users/player_*) e deixa o patch idempotente.
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
    if (is_int($v) || is_float($v)) { return (string)$v; }
    if (is_array($v)) { $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$v) . "'";
};

echo "=== Patch v5 — marcadores sobre a nova ilha ===\n\n";

/**
 * [kind, nome, x, y, lado do rótulo (false|'below'|'right'), cor, extras]
 * x/y em 0..1 sobre a arte nova. `tag` é o dizer que aparece sob o nome.
 */
$MARCADORES = [
    // --- POIs nomeados do exemplo -----------------------------------------
    ['frontier',   'BASE FRONTIER',                0.185, 0.130, 'right', 'amber',  ['faction' => 'frontier', 'tag' => 'ÚLTIMA PARADA']],
    ['gov_base',   'BASE VIGIA · HUNTER COMMAND',  0.368, 0.108, 'right', 'lime',   ['base' => 'alpha', 'status' => 'OPERACIONAL']],
    ['gov_base',   'BASE HUNTER BRAVO',            0.718, 0.085, 'right', 'lime',   ['base' => 'bravo', 'status' => 'OPERACIONAL']],
    ['nest',       'NINHO VEYRA NORTE',            0.498, 0.227, 'right', 'red',    ['caste' => 'burrowers', 'pulse' => true, 'tag' => 'CONTÁGIO EM EXPANSÃO']],
    ['village',    'COMPLEXO DE PESQUISA',         0.856, 0.247, 'right', 'white',  ['tag' => 'PROJETOS ESQUECIDOS']],
    ['village',    'VILA ABANDONADA',              0.211, 0.321, 'right', 'white',  []],
    ['radio',      'ESTAÇÃO DE RÁDIO',             0.599, 0.374, 'right', 'white',  ['tag' => 'SINAIS AINDA VIVEM']],
    ['aegis',      'FORTALEZA AEGIS',              0.785, 0.476, 'right', 'cyan',   ['faction' => 'aegis', 'tag' => 'DISCIPLINA SOBRE O CAOS']],
    ['colony',     'NINHO VEYRA SUL',              0.617, 0.537, 'right', 'red',    ['caste' => 'runners', 'pulse' => true, 'tag' => 'RAÍZES MAIS PROFUNDAS',
                                                                                     'risk' => ['x' => 61.7, 'y' => 53.7, 'rx' => 7.5]]],
    ['convoy',     'COMBOIO ABATIDO',              0.181, 0.523, 'right', 'cyan',   ['event' => 'abandoned_cargo', 'tag' => 'SAQUE É PERIGO']],
    ['extraction', 'PLATAFORMA DE EXTRAÇÃO ALPHA', 0.916, 0.491, 'below', 'lime',   ['narrow' => true]],
    ['gov_base',   'BASE HUNTER CHARLIE',          0.205, 0.704, 'right', 'lime',   ['base' => 'charlie', 'status' => 'EM RISCO']],
    ['extraction', 'PLATAFORMA DE EXTRAÇÃO BRAVO', 0.812, 0.852, 'below', 'lime',   ['narrow' => true]],

    // --- Safe Zone (patch v4) ---------------------------------------------
    ['safe_zone',  'SAFE ZONE VIGIA',              0.420, 0.190, 'below', 'lime',   ['zone' => 'vigia']],
    ['safe_zone',  'ZONA FRANCA',                  0.150, 0.455, 'below', 'lime',   ['zone' => 'franca']],

    ['safe_zone',  'ENTREPOSTO CENTRAL',           0.470, 0.440, 'below', 'lime',   ['zone' => 'entreposto']],

    // --- serviços civis dentro e ao redor das zonas seguras -----------------
    ['fuel',       'POSTO DE COMBUSTÍVEL',         0.352, 0.470, false, 'amber', []],
    ['fuel',       'POSTO DE COMBUSTÍVEL',         0.628, 0.712, false, 'amber', []],
    ['market',     'MERCADO',                       0.247, 0.345, false, 'gold',  []],
    ['market',     'MERCADO',                       0.585, 0.735, false, 'gold',  []],
    ['hospital',   'HOSPITAL DE CAMPANHA',          0.398, 0.148, false, 'white', []],
    ['hospital',   'HOSPITAL DE CAMPANHA',          0.646, 0.762, false, 'white', []],

    // --- postos, acampamentos e base de campo ------------------------------
    ['base',       'POSTO AVANÇADO NORTE',         0.128, 0.255, false  , 'lime',   []],
    ['camp',       'ACAMPAMENTO LENHADOR',         0.300, 0.155, false  , 'lime',   []],
    ['camp',       'ACAMPAMENTO DOS CAÇADORES',    0.432, 0.720, false  , 'lime',   []],
    ['field_base', 'BASE DE CAMPO DESCONHECIDA',   0.545, 0.800, false,   'muted',  ['owner' => 'unknown']],

    // --- Veyra / atividade humana ------------------------------------------
    ['contaminated','ÁREA CONTAMINADA · SPORECASTERS', 0.660, 0.690, false, 'purple', ['caste' => 'sporecasters']],
    // `radius` (em % da largura do mapa): sinal humano é POSIÇÃO APROXIMADA, então o mapa
    // mostra uma área de incerteza em vez de cravar o ponto exato de onde veio o tiro.
    ['human_activity', 'TIROS DETECTADOS',         0.535, 0.455, false  , 'amber',  ['signal' => 'shots', 'ttl_min' => 25, 'narrow' => true, 'radius' => 3.4]],
    ['human_activity', 'TRANSMISSÃO INTERCEPTADA', 0.285, 0.580, false  , 'amber',  ['signal' => 'transmission', 'ttl_min' => 40, 'narrow' => true, 'radius' => 4.2]],

    // --- pontos de extração sem rótulo (as torres do exemplo) ---------------
    ['extraction', 'PONTO DE EXTRAÇÃO',            0.117, 0.330, false, 'lime', []],
    ['extraction', 'PONTO DE EXTRAÇÃO',            0.126, 0.450, false, 'lime', []],
    ['extraction', 'PONTO DE EXTRAÇÃO',            0.538, 0.168, false, 'lime', []],
    ['extraction', 'PONTO DE EXTRAÇÃO',            0.691, 0.319, false, 'lime', []],
    ['extraction', 'PONTO DE EXTRAÇÃO',            0.341, 0.537, false, 'lime', []],
    ['extraction', 'PONTO DE EXTRAÇÃO',            0.402, 0.690, false, 'lime', []],

    // --- recursos e eventos -------------------------------------------------
    ['loot',       'ÁREA DE RECURSOS',             0.560, 0.290, false, 'white', []],
    ['loot',       'ÁREA DE RECURSOS',             0.735, 0.210, false, 'white', []],
    ['loot',       'ÁREA DE RECURSOS',             0.265, 0.400, false, 'white', []],
    ['loot',       'ÁREA DE RECURSOS',             0.690, 0.780, false, 'white', []],
    ['event',      'EVENTO DE MUNDO',              0.470, 0.640, false, 'purple', ['pulse' => true]],
    ['event',      'EVENTO DE MUNDO',              0.880, 0.700, false, 'purple', ['pulse' => true]],
];

/** slug estável a partir do nome + posição (o `id` do meta que a view usa). */
$slug = static function (string $nome, float $x, float $y): string {
    $s = strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $nome) ?: $nome));
    return trim($s, '-') . '-' . (int)round($x * 1000) . (int)round($y * 1000);
};

$linhas = [];
foreach ($MARCADORES as [$kind, $nome, $x, $y, $lado, $cor, $extra]) {
    $meta = array_merge([
        'id'    => $slug($nome, $x, $y),
        'name'  => $nome,
        'glyph' => null,          // a view resolve pelo kind quando null
        'color' => $cor,
        'label' => $lado,
    ], $extra);
    if ($meta['glyph'] === null) { unset($meta['glyph']); }
    $linhas[] = ['kind' => $kind, 'label' => $nome, 'x' => $x, 'y' => $y, 'meta' => $meta];
}

/* A zona nova precisa existir também em `safe_zones`, senão o marcador aponta para nada. */
$ZONA = [
    'slug' => 'entreposto', 'name' => 'ENTREPOSTO CENTRAL', 'kind' => 'free', 'base_slug' => null,
    'map_id' => 1, 'region' => 'PÂNTANO CINZENTO', 'status' => 'ABERTA', 'occupancy' => 'MODERADA',
    'capacity' => 20, 'protection' => 'SEM PERÍMETRO · TRÉGUA COMBINADA',
    'description' => 'Entroncamento de estradas onde comboios param antes de cruzar o pântano. Ninguém manda, mas todo mundo precisa: combustível, comida e um médico.',
    'meta' => json_encode([
        'rules' => ['Ninguém guarda sua arma: a trégua aqui é comercial.', 'Quem atira perde o direito de voltar a comprar.', 'Aceita operador sem bandeira.'],
        'services' => ['POSTO DE COMBUSTÍVEL', 'MERCADO', 'HOSPITAL DE CAMPANHA', 'OFICINA'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'sort_order' => 2,
];

$log('map_markers do mapa 1: apagar conjunto antigo (catálogo, não dado de jogador)');
$log('map_markers: inserir ' . count($linhas) . ' marcadores nas posições da arte nova');

if ($SQL) {
    $cols = implode(', ', array_keys($ZONA));
    $vals = implode(', ', array_map($lit, array_values($ZONA)));
    $out[] = "INSERT INTO safe_zones ($cols) SELECT $vals FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM safe_zones WHERE slug = " . $lit($ZONA['slug']) . ");";
    $out[] = 'DELETE FROM map_markers WHERE map_id = 1;';
    foreach ($linhas as $l) {
        $out[] = sprintf(
            'INSERT INTO map_markers (map_id, kind, label, x, y, meta) VALUES (1, %s, %s, %s, %s, %s);',
            $lit($l['kind']), $lit($l['label']), $lit($l['x']), $lit($l['y']), $lit($l['meta'])
        );
    }
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    $file = $dir . '/patch_v5_' . date('Y-m-d_His') . '.sql';
    file_put_contents($file, "-- Patch v5 — marcadores do mapa do mundo sobre a nova ilha\n" . implode("\n", $out) . "\n");
    echo "\n" . count($out) . " instruções em $file\n";
    return;
}

if (!$DRY) {
    Database::transaction(static function () use ($linhas, $ZONA): void {
        $existe = (int)Database::value('SELECT COUNT(*) FROM safe_zones WHERE slug = :s', ['s' => $ZONA['slug']]);
        if ($existe === 0) {
            $cols = implode(', ', array_keys($ZONA));
            $vals = ':' . implode(', :', array_keys($ZONA));
            Database::run("INSERT INTO safe_zones ($cols) VALUES ($vals)", $ZONA);
        }
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

echo "\nPatch v5 concluído — " . count($linhas) . " marcador(es).\n";
