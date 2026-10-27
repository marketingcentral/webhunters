<?php declare(strict_types=1);

/**
 * Patch v10 — ENREDO DO METEORO (Bíblia v6.0) no conteúdo que mora no banco.
 *
 *   php db/patch_v10_enredo_meteoro.php              aplica no banco local
 *   php db/patch_v10_enredo_meteoro.php --dry-run    só mostra o que faria (e quantas linhas)
 *   php db/patch_v10_enredo_meteoro.php --sql        NÃO toca no banco: escreve
 *                                                    db/backups/patch_v10_<data>.sql para
 *                                                    importar pelo phpMyAdmin de produção
 *
 * POR QUE EXISTE
 * O enredo mudou. A ameaça deixou de ser uma "semente" achada numa perfuração (a Germinação,
 * a Veyra como ecossistema que terraforma, as castas runners/burrowers/…/colossi) e virou o
 * meteoro VESPER, que caiu na ilha em 14/out/2058 trazendo a Veyra — um FUNGO que infecta
 * pessoas vivas, animais e o solo. As criaturas agora são as 15 de app/Data/criaturas.php.
 * O código já fala a língua nova; este patch alinha o que está gravado no banco:
 *
 *   map_markers      meta.caste antiga -> slug de criatura nova, e o sufixo do rótulo que
 *                    nomeava a casta (· SPORECASTERS -> · SOPRADORES). A escolha é pelo LUGAR
 *                    do marcador (habitat da criatura no Bestiário), não tradução literal.
 *   map_point_types  descrições dos tipos hostis (ninho, colônia, Núcleo Negro, surto,
 *                    área contaminada, descontaminação).
 *   missions         objetivos que citavam Germinação, Projeto Vesper como origem, "organismo".
 *   news             boletins que falavam de movimento subterrâneo (era a casta escavadora).
 *   safe_zones       regra "A Veyra não entra" em meta.rules.
 *   achievements     "organismos hostis" -> infectados.
 *
 * REGRAS DO PATCH
 *   - Só UPDATE de texto/meta de CONTEÚDO, por slug ou pela chave estável `meta.id` do
 *     marcador. Nunca toca users/players/player_* e nunca apaga linha.
 *   - Cada UPDATE é guardado por um trecho do texto ANTIGO (LIKE/JSON_SEARCH): roda quantas
 *     vezes quiser, e o que o admin já reescreveu à mão no painel fica como está.
 *   - Slugs de missão/notícia NÃO mudam (arquivos-vesper, boletim-coro-k07): são referência
 *     de player_missions e de link. Só o texto muda.
 *   - Rodar de novo o patch v9 (que recria os marcadores do mapa 1 e o catálogo de pontos com
 *     o texto antigo) desfaz parte disto: depois de um v9, rode este v10 outra vez.
 *
 * Por que `meta.caste` continua com esse nome: é chave técnica lida por WorldMap. Hoje ela
 * guarda o slug da criatura (GameRules::creature()); GameRules::LEGACY_CASTES ainda traduz a
 * grafia antiga se algum banco não passar por aqui.
 */

require __DIR__ . '/cli_only.php';
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$DRY = in_array('--dry-run', $argv, true);
$SQL = in_array('--sql', $argv, true);

$lit = static function (mixed $v): string {
    if ($v === null) { return 'NULL'; }
    if (is_bool($v)) { return $v ? '1' : '0'; }
    if (is_int($v) || is_float($v)) { return (string)$v; }
    if (is_array($v)) { $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$v) . "'";
};
/** :nome -> literal, para o .sql sair autocontido (nomes mais longos primeiro). */
$inline = static function (string $sql, array $params) use ($lit): string {
    uksort($params, static fn($a, $b) => strlen((string)$b) <=> strlen((string)$a));
    foreach ($params as $k => $v) {
        $sql = preg_replace('/:' . preg_quote((string)$k, '/') . '\b/', str_replace(['\\', '$'], ['\\\\', '\\$'], $lit($v)), $sql) ?? $sql;
    }
    return $sql;
};

echo "=== Patch v10 — enredo do meteoro (Bíblia v6.0) ===\n\n";

/**
 * Cada passo: [tabela, SET, WHERE, params, motivo]. O WHERE já inclui a guarda do texto
 * antigo, então SELECT COUNT(*) … WHERE dá exatamente as linhas que o UPDATE vai mexer.
 * Parâmetros com nomes distintos: o PDO do projeto usa prepare nativo (sem repetir :nome).
 */
$passos = [];

/* ---------------------------------------------------------------- 1) marcadores: pelo lugar
   [slug da região (maps.slug), meta.id, rótulo novo ou null = manter, criatura nova, porquê] */
$MARCADORES = [
    ['regiao-pantano-cinzento', 'ninho-das-sanguessugas', 'NINHO DAS SANGUESSUGAS · MATRIZ', 'matriz',
        'listeners -> matriz: ninho no pântano é uma Matriz (habitat pântano)'],
    ['regiao-montanhas-gelidas', 'bunker-norte', 'BÚNKER NORTE · COLÔNIA DE VÉUS', 'veu',
        'mimics -> véu: armadilha escondida em túnel/bunker (habitat montanhas)'],
    ['regiao-deserto-abandonado', 'forte-das-dunas', 'FORTE DAS DUNAS · COLÔNIA DE VIGIAS', 'vigia',
        'bulwarks -> vigia: forte com muralha e ponto alto (habitat deserto)'],
    ['regiao-zona-industrial', 'complexo-omega', null, 'amalgama',
        'colossi -> amálgama: a resposta extrema, no Núcleo Negro'],
    ['regiao-floresta-verde', 'ninho-veyra-norte-300220', null, 'errante',
        'burrowers -> errante: Errantes em volta do ninho (habitat floresta)'],
    ['regiao-floresta-verde', 'ninho-veyra-sul-800410', null, 'febril',
        'runners -> febril: perseguição (habitat floresta)'],
    ['regiao-floresta-verde', 'area-contaminada-sporecasters-755440', 'ÁREA CONTAMINADA · SOPRADORES', 'soprador',
        'sporecasters -> soprador: negação de área por gás (habitat floresta)'],
];
foreach ($MARCADORES as [$mapa, $mid, $rotulo, $cria, $porque]) {
    $where = "map_id = (SELECT id FROM maps WHERE slug = :mapa) AND JSON_UNQUOTE(JSON_EXTRACT(meta, '$.id')) = :mid"
           . " AND (NOT (JSON_UNQUOTE(JSON_EXTRACT(meta, '$.caste')) <=> :cria_w)"
           . ($rotulo !== null ? ' OR NOT (label <=> :rot_w)' : '') . ')';
    $params = ['mapa' => $mapa, 'mid' => $mid, 'cria_w' => $cria, 'cria' => $cria];
    if ($rotulo !== null) {
        $set = "label = :rot, meta = JSON_SET(meta, '$.caste', :cria, '$.name', :rot_n)";
        $params += ['rot' => $rotulo, 'rot_n' => $rotulo, 'rot_w' => $rotulo];
    } else {
        $set = "meta = JSON_SET(meta, '$.caste', :cria)";
    }
    $passos[] = ['map_markers', $set, $where, $params, "marcador $mid: $porque"];
}

/* ------------------------------------------- 2) marcadores: rede de segurança por casta antiga
   O que sobrar com casta antiga (marcador criado à mão em produção, por exemplo) vai para o
   equivalente genérico de GameRules::LEGACY_CASTES, e o sufixo do rótulo vira o plural novo. */
$CASTAS = [
    // antiga        nova          rótulo antigo   plural novo
    ['runners',      'febril',     'RUNNERS',      'FEBRIS'],
    ['burrowers',    'rastejante', 'BURROWERS',    'RASTEJANTES'],
    ['sporecasters', 'semeador',   'SPORECASTERS', 'SEMEADORES'],
    ['bulwarks',     'couracado',  'BULWARKS',     'COURAÇADOS'],
    ['harvesters',   'matriz',     'HARVESTERS',   'MATRIZES'],
    ['listeners',    'vigia',      'LISTENERS',    'VIGIAS'],
    ['mimics',       'eco',        'MIMICS',       'ECOS'],
    ['colossi',      'amalgama',   'COLOSSI',      'AMÁLGAMAS'],
];
foreach ($CASTAS as [$velha, $nova, $up, $plural]) {
    $passos[] = ['map_markers', "meta = JSON_SET(meta, '$.caste', :nova)",
        "JSON_UNQUOTE(JSON_EXTRACT(meta, '$.caste')) = :velha",
        ['nova' => $nova, 'velha' => $velha], "marcadores restantes com casta $velha -> $nova"];
    $passos[] = ['map_markers',
        "label = REPLACE(label, :up, :plural),"
        . " meta = IF(JSON_CONTAINS_PATH(meta, 'one', '$.name'),"
        . " JSON_SET(meta, '$.name', REPLACE(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.name')), :up_n, :plural_n)), meta)",
        'label LIKE :like',
        ['up' => $up, 'plural' => $plural, 'up_n' => $up, 'plural_n' => $plural, 'like' => '%' . $up . '%'],
        "rótulos com '$up' -> '$plural'"];
}

/* ---------------------------------------------------------------- 3) textos por slug
   [tabela, slug, coluna, guarda (LIKE do texto antigo), texto novo] */
$TEXTOS = [
    // catálogo de pontos do mapa (painel /admin/mapas/pontos e legenda)
    ['map_point_types', 'veyra-nest', 'description', 'Foco de nascimento%',
        'Matriz ativa: alimenta a colônia em volta e atrai Errantes. Combate prolongado aqui eleva a infestação; destruí-la faz o fungo recuar.'],
    ['map_point_types', 'veyra-colony', 'description', '%castas%',
        'Colônia madura: micélio no chão, Véus e Sopradores. Os pulsos do Coro passam por aqui.'],
    ['map_point_types', 'veyra-core', 'description', '%rede guarda memória%',
        'O corpo do meteoro Vesper, enterrado sob a Zona Industrial. Centro do Coro e objetivo de missão de Ciência.'],
    ['map_point_types', 'veyra-surge', 'description', '%Resposta ativa da rede%',
        'Infestação no limite: infectados em massa e risco de Amálgama. Temporário e móvel.'],
    ['map_point_types', 'contaminated', 'description', '%bioexposição%',
        'Nuvem densa de esporo: a contaminação sobe enquanto o operador fica ali sem máscara.'],
    ['map_point_types', 'decontamination', 'description', '%bioexposição%',
        'Reduz a contaminação acumulada.'],

    // missões (slug intacto; só o texto)
    ['missions', 'arquivos-vesper', 'objective', '%arquivos do Projeto Vesper%',
        'Os arquivos da Axiom sobre os Fragmentos Vesper estão numa instalação congelada nas montanhas: o que a empresa sabia antes do primeiro ataque. O acesso oficial foi destruído; só um contrato com a Aegis (componentes) ou com a Frontier (rede de mineração) abre caminho.'],
    ['missions', 'escolta-blindada', 'objective', '%a Veyra e outros operadores%',
        'Escolte o comboio Aegis da Base Alfa até a ponte leste. Blindado faz barulho — todo infectado do setor e outros operadores vão ouvir.'],
    ['missions', 'testemunha', 'objective', '%Germinação%',
        'Localize e retire a testemunha escondida na Vila Abandonada. Ela recolhia fragmentos para a Axiom e viu o que a empresa escondeu antes do primeiro ataque — e não é a única que a procura.'],
    ['missions', 'amostra-zero', 'objective', '%nova forma de organismo%',
        'Uma planta que não existe em nenhum catálogo brotou na margem do rio, onde o micélio cobriu o chão. Colete três amostras e extraia com o material intacto — o Icarus precisa do tecido vivo.'],
    ['missions', 'surto-setor-norte', 'objective', '%atividade Veyra disparou%',
        'A infestação disparou perto do Posto Avançado Norte e os Errantes estão se juntando. Contenha o surto antes que alcance a Base Vigia. Hunters, Mercenários e Sobreviventes vão convergir.'],

    // boletins (slug intacto)
    ['news', 'boletim-coro-k07', 'title', 'ATIVIDADE BIOLÓGICA%',
        'O CORO SE AGITA NO NORTE'],
    ['news', 'boletim-coro-k07', 'excerpt', '%movimentação subterrânea%',
        'Sensores do Icarus registram pulsos no micélio perto do Setor K-07, mais fortes a cada noite. O padrão bate com o que a estação registrou antes de silenciar.'],
    ['news', 'boletim-coro-k07', 'body', '%movimentação subterrânea%',
        'Sensores do Icarus registram pulsos no micélio perto do Setor K-07, mais fortes a cada noite. O padrão bate com o que a estação registrou antes de silenciar.'],
    ['news', 'boletim-janela-logistica', 'excerpt', '%a Veyra ouve motores%',
        'Um comboio de reabastecimento cruza o setor nas próximas horas. Carga previsível atrai gente previsível — e todo infectado do setor ouve motor.'],
    ['news', 'boletim-janela-logistica', 'body', '%a Veyra ouve motores%',
        'Um comboio de reabastecimento cruza o setor nas próximas horas. Carga previsível atrai gente previsível — e todo infectado do setor ouve motor.'],

    // conquistas
    ['achievements', 'cacador-experiente', 'description', '%organismos hostis%',
        'Elimine 100 infectados.'],
];
foreach ($TEXTOS as [$tab, $slug, $col, $guarda, $novo]) {
    $passos[] = [$tab, "`$col` = :novo", "slug = :slug AND `$col` LIKE :guarda",
        ['novo' => $novo, 'slug' => $slug, 'guarda' => $guarda], "$tab.$col [$slug]"];
}

/* ---------------------------------------------------------------- 4) safe_zones.meta.rules */
$REGRA_VELHA = 'A Veyra não entra: o perímetro é mantido pela guarnição da base.';
$REGRA_NOVA  = 'Infectado não passa da cerca: o perímetro é mantido pela guarnição da base.';
$passos[] = ['safe_zones',
    "meta = JSON_SET(meta, JSON_UNQUOTE(JSON_SEARCH(meta, 'one', :velha_s, NULL, '$.rules')), :nova)",
    "JSON_SEARCH(meta, 'one', :velha_w, NULL, '$.rules') IS NOT NULL",
    ['velha_s' => $REGRA_VELHA, 'nova' => $REGRA_NOVA, 'velha_w' => $REGRA_VELHA],
    'safe_zones.meta.rules: "A Veyra não entra" -> "Infectado não passa da cerca"'];

/* =========================================================================================
   APLICAÇÃO
   ========================================================================================= */

if ($SQL) {
    $out = [];
    foreach ($passos as [$tab, $set, $where, $params, $motivo]) {
        echo "[sql] $motivo\n";
        $out[] = '-- ' . $motivo;
        $out[] = $inline("UPDATE `$tab` SET $set WHERE $where", $params) . ';';
    }
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    $arquivo = $dir . '/patch_v10_' . date('Y-m-d_His') . '.sql';
    $cab = "-- Extract Hunters — patch v10 (enredo do meteoro, Bíblia v6.0)\n-- Gerado em " . date('c')
         . "\n-- Só UPDATE de conteúdo, guardado pelo texto antigo: IDEMPOTENTE, não apaga nada,"
         . "\n-- não toca users/players/player_*. Pode importar mais de uma vez.\nSET NAMES utf8mb4;\n\n";
    file_put_contents($arquivo, $cab . implode("\n", $out) . "\n");
    echo "\n" . count($passos) . " instruções em $arquivo\n";
    return;
}

/** Só os parâmetros que aparecem no trecho (prepare nativo recusa parâmetro sobrando). */
$so = static function (string $sql, array $params): array {
    return array_filter($params, static fn($k) => preg_match('/:' . preg_quote((string)$k, '/') . '\b/', $sql) === 1, ARRAY_FILTER_USE_KEY);
};

$porTabela = [];
$total = 0;
$executar = static function () use ($passos, $so, $DRY, &$porTabela, &$total): void {
    foreach ($passos as [$tab, $set, $where, $params, $motivo]) {
        $n = (int)Database::value("SELECT COUNT(*) FROM `$tab` WHERE $where", $so($where, $params));
        if ($n === 0) { continue; }
        echo ($DRY ? '[dry] ' : '') . "$motivo — $n linha(s)\n";
        $sql = "UPDATE `$tab` SET $set WHERE $where";
        Database::run($sql, $so($sql, $params));
        $porTabela[$tab] = ($porTabela[$tab] ?? 0) + $n;
        $total += $n;
    }
};

// O dry-run EXECUTA dentro de uma transação e desfaz no fim: só assim a contagem de um passo
// enxerga o que os passos anteriores já mudaram (o marcador do passo 1 não reaparece no 2).
if ($DRY) {
    Database::beginTransaction();
    try {
        $executar();
    } finally {
        Database::rollBack();
    }
} else {
    Database::transaction($executar);
}

echo "\nPatch v10 " . ($DRY ? '(dry-run, nada gravado) ' : '') . "— $total linha(s) atualizada(s)";
echo $porTabela ? ': ' . implode(', ', array_map(static fn($t, $n) => "$t $n", array_keys($porTabela), $porTabela)) : '';
echo ".\n";
