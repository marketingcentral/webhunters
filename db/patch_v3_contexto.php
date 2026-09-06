<?php declare(strict_types=1);

/**
 * Patch ADITIVO v3 — mundo persistente PvPvE (PRD v3 + Bíblia v5.1).
 *
 *   php db/patch_v3_contexto.php              aplica no banco local
 *   php db/patch_v3_contexto.php --dry-run    só mostra o que faria
 *   php db/patch_v3_contexto.php --sql        NÃO toca no banco: escreve db/backups/patch_v3_<data>.sql
 *                                              para importar pelo phpMyAdmin do host
 *
 * Não apaga nada, pode rodar quantas vezes quiser. Existe porque db/seed.php e db/migrate.php
 * são destrutivos (apagam contas — ver db/guard.php): mudança de esquema/catálogo num banco com
 * jogador real entra por aqui.
 *
 * O que faz:
 *   1. players: colunas de estado operacional (HUNTER | MERCENARY | SURVIVOR), patente, função
 *   2. player_factions (contrato/reputação com Aegis e Frontier) e government_bases (Alpha/Bravo/
 *      Charlie, capacidade configurável) — tabelas novas
 *   3. missions: origem (hunter_command | aegis | frontier | icarus | world), ato da campanha,
 *      requisito de contrato/reputação, esquadrão 1–4 (adeus "jogadores máx. 5"), atividade humana
 *   4. conteúdo: Resistência sai (vira Hunter Command), SINAL PERDIDO reescrito como Ato I,
 *      novas missões por origem, regiões ganham capacidade/infestação/atividade/extrações tipadas,
 *      marcadores canônicos da Floresta Verde, boletins de campo
 *
 * Modo --sql: gera os mesmos passos em SQL puro e idempotente. ALTER TABLE não tem "IF NOT
 * EXISTS" no MySQL, então cada coluna sai guardada por information_schema + PREPARE/EXECUTE.
 */

require __DIR__ . '/cli_only.php';
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$DRY = in_array('--dry-run', $argv, true);
$SQL = in_array('--sql', $argv, true);

/**
 * Executor com dois destinos: o banco (com checagens em PHP) ou um arquivo .sql (com as
 * mesmas checagens em SQL). Toda mutação do patch passa por aqui.
 */
final class Patch
{
    /** @var list<string> */
    public array $sql = [];
    public int $feitos = 0;

    public function __construct(private bool $dry, private bool $toSql) {}

    private function log(string $m): void
    {
        echo ($this->dry ? '[dry] ' : ($this->toSql ? '[sql] ' : '')) . $m . "\n";
    }

    /** Literal SQL a partir de um valor PHP. */
    public static function lit(mixed $v): string
    {
        if ($v === null) { return 'NULL'; }
        if (is_int($v) || is_float($v)) { return (string)$v; }
        if (is_bool($v)) { return $v ? '1' : '0'; }
        if (is_array($v)) { $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$v) . "'";
    }

    /** Substitui :nome pelos literais, para o arquivo .sql ficar autocontido. */
    private function inline(string $sql, array $params): string
    {
        foreach ($params as $k => $v) {
            $sql = preg_replace('/:' . preg_quote((string)$k, '/') . '\b/', self::lit($v), $sql) ?? $sql;
        }
        return $sql;
    }

    public function run(string $sql, array $params, string $motivo): void
    {
        $this->log($motivo);
        $this->feitos++;
        if ($this->toSql) { $this->sql[] = $this->inline($sql, $params) . ';'; return; }
        if ($this->dry)   { return; }
        Database::run($sql, $params);
    }

    public function addColumn(string $table, string $col, string $ddl): void
    {
        if ($this->toSql) {
            $this->log("$table.$col (se não existir)");
            $alter = self::lit("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
            $this->sql[] = "SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND COLUMN_NAME = '$col');";
            $this->sql[] = "SET @s = IF(@c = 0, $alter, 'SELECT 1');";
            $this->sql[] = 'PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;';
            return;
        }
        $existe = Database::value(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => $table, 'c' => $col]
        );
        if ((int)$existe > 0) { return; }
        $this->log("$table: + coluna $col");
        $this->feitos++;
        if (!$this->dry) { Database::run("ALTER TABLE `$table` ADD COLUMN `$col` $ddl"); }
    }

    public function createTable(string $name, string $ddl): void
    {
        $stmt = "CREATE TABLE IF NOT EXISTS `$name` (\n$ddl\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if ($this->toSql) { $this->log("tabela $name (se não existir)"); $this->sql[] = $stmt . ';'; return; }
        $existe = Database::value('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t', ['t' => $name]);
        if ((int)$existe > 0) { return; }
        $this->log("+ tabela $name");
        $this->feitos++;
        if (!$this->dry) { Database::run($stmt); }
    }

    /** INSERT que não duplica: em SQL puro vira INSERT … SELECT … WHERE NOT EXISTS. */
    public function insertUnless(string $table, array $row, string $whereUnique, array $uniqueParams, string $motivo): void
    {
        $cols = array_keys($row);
        $colList = '`' . implode('`, `', $cols) . '`';
        $vals = implode(', ', array_map(fn($c) => ':' . $c, $cols));
        $sql = "INSERT INTO `$table` ($colList) SELECT $vals FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `$table` WHERE $whereUnique)";
        $this->run($sql, $row + $uniqueParams, $motivo);
    }
}

$p = new Patch($DRY, $SQL);
$j = static fn(array $a): string => json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

echo "=== Patch v3 — mundo persistente ===\n\n";

// ============================================================ 1) players: estado operacional
$p->addColumn('players', 'operational_state', "VARCHAR(16) NOT NULL DEFAULT 'HUNTER'");
$p->addColumn('players', 'hunter_rank',       "VARCHAR(24) NOT NULL DEFAULT 'RECRUTA'");
$p->addColumn('players', 'hunter_role',       'VARCHAR(16) NULL');
$p->addColumn('players', 'base_pref',         'VARCHAR(16) NULL');
$p->addColumn('players', 'survivor_since',    'DATETIME NULL');

// Resistência deixa de existir como organização. Quem era "Resistência" é Hunter Command.
$p->run("UPDATE players SET clan = 'Hunter Command' WHERE clan = 'Resistência' OR clan = 'Resistencia'", [],
    'players: clan Resistência -> Hunter Command');
// Classes antigas viram as quatro funções oficiais; hunter_role nasce da classe.
foreach (['ASSALTO' => 'ASSAULT', 'BATEDOR' => 'RASTREADOR', 'SUPORTE' => 'MEDICAL', 'FRANCO-ATIRADOR' => 'SNIPER', 'MÉDICO' => 'MEDICAL'] as $de => $para) {
    $p->run("UPDATE players SET class = :para, hunter_role = :para2 WHERE class = :de", ['para' => $para, 'para2' => $para, 'de' => $de],
        "players: classe $de -> $para");
}
$p->run("UPDATE players SET hunter_role = class WHERE hunter_role IS NULL AND class IN ('ASSAULT','SNIPER','MEDICAL','RASTREADOR')", [],
    'players: hunter_role a partir da classe');
$p->run("UPDATE players SET class_icon = CASE class WHEN 'ASSAULT' THEN 'class-assault' WHEN 'SNIPER' THEN 'class-sniper' WHEN 'MEDICAL' THEN 'class-support' WHEN 'RASTREADOR' THEN 'class-scout' ELSE class_icon END", [],
    'players: ícone coerente com a função');

// ================================================================ 2) tabelas novas
$p->createTable('player_factions', <<<SQL
  user_id         INT UNSIGNED NOT NULL,
  faction         VARCHAR(16)  NOT NULL,                 -- aegis | frontier
  reputation      INT NOT NULL DEFAULT 0,
  tier            VARCHAR(24)  NULL,                     -- RECRUTADO / OPERADOR / CONFIÁVEL / VETERANO
  contract_active TINYINT(1)   NOT NULL DEFAULT 0,       -- só UMA linha ativa por jogador (regra na aplicação)
  locked_until    DATETIME     NULL,                     -- bloqueio após traição; valor vem do backend
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, faction),
  CONSTRAINT fk_pf_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
SQL);

$p->createTable('government_bases', <<<SQL
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug       VARCHAR(16)  NOT NULL,                      -- alpha | bravo | charlie
  name       VARCHAR(60)  NOT NULL,
  map_id     INT UNSIGNED NULL,                          -- região onde fica (maps kind=region)
  capacity   TINYINT UNSIGNED NOT NULL DEFAULT 4,        -- Hunters ativos por base: MUDE AQUI, não no código
  status     VARCHAR(16)  NOT NULL DEFAULT 'OPERACIONAL',-- OPERACIONAL | LOTADA | EM RISCO | ISOLADA | SOB ATAQUE
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gb_slug (slug),
  CONSTRAINT fk_gb_map FOREIGN KEY (map_id) REFERENCES maps(id) ON DELETE SET NULL
SQL);

foreach ([
    ['alpha',   'BASE HUNTER ALPHA',   'OPERACIONAL', 1],
    ['bravo',   'BASE HUNTER BRAVO',   'OPERACIONAL', 2],
    ['charlie', 'BASE HUNTER CHARLIE', 'EM RISCO',    3],
] as [$slug, $nome, $status, $ord]) {
    $p->insertUnless('government_bases',
        ['slug' => $slug, 'name' => $nome, 'map_id' => 1, 'capacity' => 4, 'status' => $status, 'sort_order' => $ord],
        'slug = :u_slug', ['u_slug' => $slug], "government_bases: $nome");
}

// ================================================================ 3) missions: origem e requisitos
$p->addColumn('missions', 'origin',           "VARCHAR(24) NOT NULL DEFAULT 'hunter_command'");
$p->addColumn('missions', 'act',              'TINYINT UNSIGNED NULL');
$p->addColumn('missions', 'requires_faction', 'VARCHAR(16) NULL');   // aegis | frontier | any
$p->addColumn('missions', 'requires_tier',    'VARCHAR(24) NULL');
$p->addColumn('missions', 'requires_rep',     'INT NULL');
$p->addColumn('missions', 'squad_min',        'TINYINT UNSIGNED NOT NULL DEFAULT 1');
$p->addColumn('missions', 'squad_max',        'TINYINT UNSIGNED NOT NULL DEFAULT 4');
$p->addColumn('missions', 'human_activity',   'VARCHAR(16) NULL');   // IMPROVÁVEL | PROVÁVEL | DETECTADA

$p->run("UPDATE missions SET giver = 'BASE VIGIA · HUNTER COMMAND' WHERE giver LIKE '%RESIST%'", [],
    'missions: quem dá a missão deixa de ser a Resistência');
$p->run('UPDATE missions SET players_max = 4 WHERE players_max IS NULL OR players_max > 4', [],
    'missions: nenhuma missão pede mais de 4 (esquadrão Hunter)');

// Ato I, reescrito com o canon: a estação não caiu por um único motivo.
$p->run("UPDATE missions SET origin = 'hunter_command', act = 1, human_activity = 'DETECTADA', type = 'principal',
            objective = 'Restaure a comunicação da estação militar ao norte. Reative o terminal e proteja o técnico durante a sincronização. Há corpos com marcas de tiros humanos: alguém chegou antes.'
         WHERE slug = 'sinal-perdido'", [], 'missions: SINAL PERDIDO vira Ato I (Coro, interferência humana)');
$p->run("UPDATE missions SET origin = 'hunter_command', human_activity = 'IMPROVÁVEL' WHERE slug = 'suprimentos-medicos'", [], 'missions: SUPRIMENTOS MÉDICOS -> Hunter Command');
$p->run("UPDATE missions SET origin = 'hunter_command', human_activity = 'PROVÁVEL' WHERE slug = 'cacada-ao-cacador'", [], 'missions: CAÇADA AO CAÇADOR -> Hunter Command');
$p->run("UPDATE missions SET origin = 'aegis', requires_faction = 'aegis', human_activity = 'PROVÁVEL',
            objective = 'Recupere a carga do comboio Aegis emboscado na estrada leste e leve-a até a Base Alfa. Outros operadores podem ter chegado antes.'
         WHERE slug = 'carga-valiosa'", [], 'missions: CARGA VALIOSA vira contrato Aegis');
$p->run("UPDATE missions SET origin = 'hunter_command', act = 4, human_activity = 'DETECTADA' WHERE slug = 'tecnologia-recuperada'", [], 'missions: TECNOLOGIA RECUPERADA -> Ato IV');

$novas = [
    ['slug' => 'arquivos-vesper',   'name' => 'ARQUIVOS VESPER',   'giver' => 'HUNTER COMMAND · LICENÇA CINZA', 'region' => 'MONTANHAS GÉLIDAS', 'type' => 'principal', 'difficulty' => 'DIFÍCIL', 'duration_min' => 55, 'players_max' => 4, 'level_req' => 20, 'target' => 4, 'locked' => 1, 'sort_order' => 6,
     'origin' => 'hunter_command', 'act' => 2, 'requires_faction' => 'any', 'human_activity' => 'PROVÁVEL',
     'objective' => 'Os arquivos do Projeto Vesper estão sob uma instalação Axiom congelada. O acesso oficial foi destruído: só um contrato com a Aegis (componentes) ou com a Frontier (rede de mineração) abre caminho.',
     'image' => 'ui/mission-sinal-perdido.jpg'],
    ['slug' => 'escolta-blindada',  'name' => 'ESCOLTA BLINDADA',  'giver' => 'BASE ALFA · AEGIS', 'region' => 'FLORESTA VERDE', 'type' => 'contrato', 'difficulty' => 'MÉDIA', 'duration_min' => 30, 'players_max' => 4, 'level_req' => 5, 'target' => 3, 'locked' => 0, 'sort_order' => 7,
     'origin' => 'aegis', 'act' => null, 'requires_faction' => 'aegis', 'human_activity' => 'PROVÁVEL',
     'objective' => 'Escolte o comboio Aegis da Base Alfa até a ponte leste. Blindados fazem ruído — a Veyra e outros operadores vão ouvir.',
     'image' => 'ui/mission-sinal-perdido.jpg'],
    ['slug' => 'arsenal-submerso',  'name' => 'ARSENAL SUBMERSO',  'giver' => 'BASE ALFA · AEGIS', 'region' => 'PÂNTANO CINZENTO', 'type' => 'contrato', 'difficulty' => 'DIFÍCIL', 'duration_min' => 40, 'players_max' => 4, 'level_req' => 12, 'target' => 4, 'locked' => 1, 'sort_order' => 8,
     'origin' => 'aegis', 'act' => null, 'requires_faction' => 'aegis', 'requires_tier' => 'OPERADOR', 'human_activity' => 'PROVÁVEL',
     'objective' => 'Recupere as caixas de armamento Aegis afundadas no canal norte. A água contaminada esconde esporos e o que mais estiver embaixo.',
     'image' => 'ui/mission-sinal-perdido.jpg'],
    ['slug' => 'rota-silenciosa',   'name' => 'ROTA SILENCIOSA',   'giver' => 'ACAMPAMENTO DOS CAÇADORES · FRONTIER', 'region' => 'PÂNTANO CINZENTO', 'type' => 'contrato', 'difficulty' => 'MÉDIA', 'duration_min' => 35, 'players_max' => 4, 'level_req' => 8, 'target' => 1, 'locked' => 0, 'sort_order' => 9,
     'origin' => 'frontier', 'act' => null, 'requires_faction' => 'frontier', 'human_activity' => 'IMPROVÁVEL',
     'objective' => 'Guie o informante pelos túneis secos até o Acampamento dos Caçadores sem ser detectado. Nenhum tiro, nenhuma transmissão.',
     'image' => 'ui/mission-sinal-perdido.jpg'],
    ['slug' => 'testemunha',        'name' => 'TESTEMUNHA',        'giver' => 'ACAMPAMENTO DOS CAÇADORES · FRONTIER', 'region' => 'FLORESTA VERDE', 'type' => 'contrato', 'difficulty' => 'DIFÍCIL', 'duration_min' => 45, 'players_max' => 4, 'level_req' => 15, 'target' => 1, 'locked' => 1, 'sort_order' => 10,
     'origin' => 'frontier', 'act' => null, 'requires_faction' => 'frontier', 'requires_tier' => 'CONFIÁVEL', 'human_activity' => 'DETECTADA',
     'objective' => 'Localize e retire a testemunha escondida na Vila Abandonada. Ela viu o que aconteceu antes da Germinação — e não é a única que a procura.',
     'image' => 'ui/mission-sinal-perdido.jpg'],
    ['slug' => 'amostra-zero',      'name' => 'AMOSTRA ZERO',      'giver' => 'INSTITUTO ICARUS', 'region' => 'FLORESTA VERDE', 'type' => 'contrato', 'difficulty' => 'MÉDIA', 'duration_min' => 30, 'players_max' => 4, 'level_req' => 3, 'target' => 3, 'locked' => 0, 'sort_order' => 11,
     'origin' => 'icarus', 'act' => null, 'requires_faction' => null, 'human_activity' => 'IMPROVÁVEL',
     'objective' => 'Uma nova forma de organismo foi encontrada próximo ao rio. Colete três amostras e extraia com o material intacto — Icarus precisa do tecido vivo.',
     'image' => 'ui/mission-sinal-perdido.jpg'],
    ['slug' => 'surto-setor-norte', 'name' => 'SURTO NO SETOR NORTE', 'giver' => 'EVENTO DE MUNDO', 'region' => 'FLORESTA VERDE', 'type' => 'emergencia', 'difficulty' => 'EXTREMO', 'duration_min' => 25, 'players_max' => 4, 'level_req' => 1, 'target' => 5, 'locked' => 0, 'sort_order' => 12,
     'origin' => 'world', 'act' => null, 'requires_faction' => null, 'human_activity' => 'DETECTADA',
     'objective' => 'A atividade Veyra disparou perto do Posto Avançado Norte. Contenha o surto antes que alcance a Base Vigia. Hunters, Mercenários e Sobreviventes vão convergir.',
     'image' => 'ui/mission-sinal-perdido.jpg'],
];
foreach ($novas as $m) {
    $m += ['requires_tier' => null, 'requires_rep' => null, 'squad_min' => 1, 'squad_max' => 4];
    $p->insertUnless('missions', $m, 'slug = :u_slug', ['u_slug' => $m['slug']], "missions: + {$m['name']}");
}

// Recompensas das novas missões (por slug; só se a missão ainda não tiver recompensa).
$recompensas = [
    'arquivos-vesper'   => [['xp', 9000], ['credits', 6500], ['intel', 1]],
    'escolta-blindada'  => [['xp', 4200], ['credits', 3800], ['rep_aegis', 250]],
    'arsenal-submerso'  => [['xp', 6800], ['credits', 5200], ['rep_aegis', 400]],
    'rota-silenciosa'   => [['xp', 4600], ['credits', 3200], ['rep_frontier', 250]],
    'testemunha'        => [['xp', 7400], ['credits', 4900], ['rep_frontier', 450], ['access', 1]],
    'amostra-zero'      => [['xp', 3600], ['credits', 2400], ['intel', 1]],
    'surto-setor-norte' => [['xp', 5000], ['credits', 3000], ['points', 3]],
];
foreach ($recompensas as $slug => $lista) {
    foreach ($lista as [$kind, $amount]) {
        $p->run(
            "INSERT INTO mission_rewards (mission_id, kind, amount)
             SELECT m.id, :kind, :amount FROM missions m
              WHERE m.slug = :slug
                AND NOT EXISTS (SELECT 1 FROM mission_rewards r WHERE r.mission_id = m.id AND r.kind = :kind2)",
            ['kind' => $kind, 'amount' => $amount, 'slug' => $slug, 'kind2' => $kind],
            "mission_rewards: $slug +$kind"
        );
    }
}

// ============================================================ 4) regiões: mundo persistente
// `info` inteiro é reescrito por região: sai "TEMPO DO CICLO"/"JOGADORES MÁX.", entram
// MUNDO: PERSISTENTE e CAPACIDADE OPERACIONAL 20. Extrações ganham tipo canônico.
$regioes = [
    'regiao-floresta-verde' => [
        'level' => '1-10', 'clima' => 'Variável', 'clima_icon' => 'partly_cloudy_day', 'infest' => 42, 'veyra' => 'MODERADA', 'humana' => 'ELEVADA', 'setor' => 'ATIVO',
        'ext' => [['PLATAFORMA NORTE', 'governamental', 'active'], ['ROTA SUL', 'publica', 'active'], ['TRANSPORTE AEGIS · PONTE LESTE', 'aegis', 'conditional'], ['ROTA FRONTIER · RIO OESTE', 'frontier', 'inactive']],
    ],
    'regiao-pantano-cinzento' => [
        'level' => '10-20', 'clima' => 'Neblina', 'clima_icon' => 'foggy', 'infest' => 58, 'veyra' => 'ELEVADA', 'humana' => 'MODERADA', 'setor' => 'ATIVO',
        'ext' => [['EVAC HUNTER · PLATAFORMA NORTE', 'governamental', 'active'], ['BALSA DO PÂNTANO', 'publica', 'conditional'], ['ROTA FRONTIER · TÚNEIS SECOS', 'frontier', 'active']],
    ],
    'regiao-montanhas-gelidas' => [
        'level' => '20-30', 'clima' => 'Nevasca', 'clima_icon' => 'weather_snowy', 'infest' => 35, 'veyra' => 'MODERADA', 'humana' => 'ELEVADA', 'setor' => 'DISPUTADO',
        'ext' => [['TELEFÉRICO DO PICO', 'publica', 'active'], ['TRANSPORTE AEGIS · HELIPONTO LESTE', 'aegis', 'conditional'], ['ROTA FRONTIER · GALERIA DE MINERAÇÃO', 'frontier', 'conditional']],
    ],
    'regiao-deserto-abandonado' => [
        'level' => '30-40', 'clima' => 'Tempestade', 'clima_icon' => 'air', 'infest' => 47, 'veyra' => 'MODERADA', 'humana' => 'CRÍTICA', 'setor' => 'GUERRA ECONÔMICA',
        'ext' => [['OÁSIS DO NORTE', 'publica', 'active'], ['COMBOIO AEGIS', 'aegis', 'inactive'], ['ROTA FRONTIER · CANAL SECO', 'frontier', 'conditional']],
    ],
    'regiao-zona-industrial' => [
        'level' => '40+', 'clima' => 'Chuva ácida', 'clima_icon' => 'rainy', 'infest' => 88, 'veyra' => 'CRÍTICA', 'humana' => 'CRÍTICA', 'setor' => 'NÚCLEO NEGRO',
        'ext' => [['EVAC HUNTER · TÚNEL DE CARGA', 'governamental', 'conditional'], ['PÁTIO FERROVIÁRIO', 'publica', 'inactive'], ['CLANDESTINA · GALERIA 7', 'clandestina', 'conditional']],
    ],
];
foreach ($regioes as $slug => $r) {
    $info = [
        ['cls' => 'lime', 'label' => 'NÍVEL RECOMENDADO', 'value' => $r['level']],
        ['label' => 'CAPACIDADE OPERACIONAL', 'value' => '20', 'tip' => 'Até 12 Hunters e 8 Mercenários. Sobreviventes ocupam vagas existentes.'],
        ['icon' => $r['clima_icon'], 'label' => 'CLIMA ATUAL', 'value' => $r['clima']],
        ['label' => 'MUNDO', 'value' => 'PERSISTENTE', 'cls' => 'lime'],
    ];
    $ext = array_map(static fn(array $e): array => ['name' => $e[0], 'type' => $e[1], 'state' => $e[2],
        'status' => match ($e[2]) { 'active' => 'ATIVA', 'conditional' => 'CONDICIONAL', default => 'INATIVA' }], $r['ext']);
    $p->run(
        "UPDATE maps SET meta = JSON_SET(COALESCE(meta, JSON_OBJECT()),
            '$.persistent', TRUE,
            '$.capacity', CAST(:cap AS JSON),
            '$.infestation', :inf,
            '$.veyra_activity', :vey,
            '$.human_activity', :hum,
            '$.weather', :clima,
            '$.sector_state', :setor,
            '$.info', CAST(:info AS JSON),
            '$.extractions', CAST(:ext AS JSON))
         WHERE slug = :slug AND kind = 'region'",
        ['cap' => $j(['total' => 20, 'hunters' => 12, 'mercenaries' => 8]), 'inf' => $r['infest'], 'vey' => $r['veyra'], 'hum' => $r['humana'],
         'clima' => $r['clima'], 'setor' => $r['setor'], 'info' => $j($info), 'ext' => $j($ext), 'slug' => $slug],
        "maps: região $slug -> mundo persistente"
    );
}

// Mapas de partida viram regiões operacionais do JOGAR: mesma leitura (infestação/atividade).
$operacionais = [
    'mapa-vale-do-eco' => [38, 'MODERADA'], 'mapa-base-artica' => [61, 'ELEVADA'], 'mapa-porto-industrial' => [55, 'CRÍTICA'],
    'mapa-cidade-em-ruinas' => [83, 'ELEVADA'], 'mapa-fabrica-abandonada' => [29, 'BAIXA'], 'mapa-deserto-seco' => [46, 'MODERADA'],
    'mapa-favela' => [52, 'ELEVADA'], 'mapa-complexo-militar' => [67, 'CRÍTICA'],
];
foreach ($operacionais as $slug => [$inf, $hum]) {
    $p->run(
        "UPDATE maps SET meta = JSON_SET(COALESCE(meta, JSON_OBJECT()), '$.persistent', TRUE, '$.infestation', :inf, '$.human_activity', :hum,
                '$.capacity', CAST(:cap AS JSON))
         WHERE (slug = :slug OR slug = :slug2) AND kind = 'match'",
        ['inf' => $inf, 'hum' => $hum, 'cap' => $j(['total' => 20, 'hunters' => 12, 'mercenaries' => 8]), 'slug' => $slug, 'slug2' => substr($slug, 5)],
        "maps: $slug -> região operacional (infestação $inf%)"
    );
}

// ================================================================ 5) marcadores canônicos
$p->run(
    "UPDATE map_markers SET kind = 'gov_base', label = 'BASE VIGIA · HUNTER COMMAND',
            meta = JSON_SET(COALESCE(meta, JSON_OBJECT()), '$.name', 'BASE VIGIA · HUNTER COMMAND', '$.glyph', 'gov-base', '$.color', 'lime', '$.base', 'alpha', '$.status', 'OPERACIONAL')
     WHERE label LIKE '%RESIST%' OR JSON_UNQUOTE(JSON_EXTRACT(meta, '$.name')) LIKE '%RESIST%'", [],
    'map_markers: Base da Resistência Vigia -> Base Vigia · Hunter Command');

/* Bíblia §2: o inimigo é a Veyra. "Bases inimigas" humanas viram estruturas Veyra com casta,
   preservando posição, zona de risco e o glifo já desenhado (mapeado pela view). */
$veyra = [
    // label atual             => [kind,     novo nome,                             casta]
    'BASE INIMIGA ALFA'        => ['colony', 'COLÔNIA · RUNNERS',                   'runners'],
    'NINHO DAS SANGUESSUGAS'   => ['nest',   'NINHO DAS SANGUESSUGAS · LISTENERS',  'listeners'],
    'BÚNKER NORTE'             => ['colony', 'BÚNKER NORTE · COLÔNIA MIMICS',       'mimics'],
    'FORTE DAS DUNAS'          => ['colony', 'FORTE DAS DUNAS · COLÔNIA BULWARKS',  'bulwarks'],
    'COMPLEXO OMEGA'           => ['core',   'COMPLEXO OMEGA · NÚCLEO NEGRO',       'colossi'],
];
foreach ($veyra as $atual => [$kind, $nome, $casta]) {
    $p->run(
        "UPDATE map_markers SET kind = :kind, label = :nome,
                meta = JSON_SET(COALESCE(meta, JSON_OBJECT()), '$.name', :nome2, '$.caste', :casta, '$.glyph', 'enemy', '$.color', 'red')
          WHERE kind = 'enemy' AND label = :atual",
        ['kind' => $kind, 'nome' => $nome, 'nome2' => $nome, 'casta' => $casta, 'atual' => $atual],
        "map_markers: $atual -> $nome (Veyra)"
    );
}

$marcadores = [
    // Bases governamentais (Bíblia §3): três, com estado; sem vagas exatas no mapa.
    ['gov_base',       'BASE HUNTER BRAVO',                   0.7150, 0.2650, ['glyph' => 'gov-base', 'color' => 'lime',   'base' => 'bravo',   'status' => 'OPERACIONAL', 'label' => true]],
    ['gov_base',       'BASE HUNTER CHARLIE',                 0.2350, 0.7200, ['glyph' => 'gov-base', 'color' => 'lime',   'base' => 'charlie', 'status' => 'EM RISCO', 'label' => true]],
    // Facções (POIs canônicos da Floresta Verde)
    ['aegis',          'BASE ALFA · AEGIS',                   0.8000, 0.5600, ['glyph' => 'aegis',    'color' => 'cyan',   'faction' => 'aegis',    'label' => true]],
    ['frontier',       'ACAMPAMENTO DOS CAÇADORES · FRONTIER',0.1550, 0.4300, ['glyph' => 'frontier', 'color' => 'amber',  'faction' => 'frontier', 'label' => true]],
    // Base de campo de outro jogador: dono não revelado (PRD §23.5)
    ['field_base',     'BASE DE CAMPO DESCONHECIDA',          0.5600, 0.8200, ['glyph' => 'field-base', 'color' => 'muted', 'owner' => 'unknown']],
    // Veyra
    ['nest',           'NINHO · BURROWERS',                   0.4700, 0.2100, ['glyph' => 'nest',        'color' => 'red',    'caste' => 'burrowers', 'pulse' => true]],
    ['contaminated',   'ÁREA CONTAMINADA · SPORECASTERS',     0.6300, 0.6900, ['glyph' => 'contaminated','color' => 'purple', 'caste' => 'sporecasters']],
    // Atividade humana: aproximada e com expiração (PRD §23.6)
    ['human_activity', 'TIROS DETECTADOS',                    0.5200, 0.4600, ['glyph' => 'human', 'color' => 'amber', 'signal' => 'shots', 'ttl_min' => 25, 'narrow' => true]],
    ['human_activity', 'TRANSMISSÃO INTERCEPTADA',            0.3400, 0.5700, ['glyph' => 'human', 'color' => 'amber', 'signal' => 'transmission', 'ttl_min' => 40, 'narrow' => true]],
    // Ciclo de mundo: recurso voltando com motivo
    ['convoy',         'COMBOIO AEGIS · JANELA LOGÍSTICA',    0.7400, 0.4200, ['glyph' => 'convoy', 'color' => 'cyan', 'event' => 'convoy', 'label' => true]],
];
foreach ($marcadores as [$kind, $label, $x, $y, $meta]) {
    $p->run(
        "INSERT INTO map_markers (map_id, kind, label, x, y, meta)
         SELECT 1, :kind, :label, :x, :y, :meta FROM DUAL
          WHERE NOT EXISTS (SELECT 1 FROM map_markers WHERE map_id = 1 AND label = :label2)",
        ['kind' => $kind, 'label' => $label, 'x' => $x, 'y' => $y, 'meta' => $j($meta + ['name' => $label]), 'label2' => $label],
        "map_markers: + $label"
    );
}

// ================================================================ 6) boletins de campo
foreach ([
    ['boletim-coro-k07',       'ATIVIDADE BIOLÓGICA AUMENTA NO NORTE', 'Relatórios do Icarus indicam movimentação subterrânea próxima ao Setor K-07. O padrão bate com o que a estação registrou antes de silenciar.', 'BOLETIM', 1],
    ['boletim-aegis-ponte',    'AEGIS REFORÇA A PONTE LESTE',          'Blindados foram vistos cruzando a estrada leste da Floresta Verde. Mercenários sob contrato Aegis relatam patrulhas dobradas.', 'BOLETIM', 2],
    ['boletim-janela-logistica','PRÓXIMA JANELA LOGÍSTICA CONFIRMADA', 'Um comboio de reabastecimento cruza o setor nas próximas horas. Carga previsível atrai gente previsível — e a Veyra ouve motores.', 'BOLETIM', 3],
] as [$slug, $titulo, $excerpt, $cat, $ord]) {
    $p->insertUnless('news',
        ['slug' => $slug, 'title' => $titulo, 'category' => $cat, 'excerpt' => $excerpt, 'body' => $excerpt, 'image' => 'news/news-atualizacao.jpg', 'published' => date('Y-m-d'), 'sort_order' => $ord],
        'slug = :u_slug', ['u_slug' => $slug], "news: + boletim $titulo");
}

// ================================================================ 7) conquistas: sem Resistência
$p->run("UPDATE achievements SET name = 'CADEIA DE COMANDO', category = 'hunter', target = 25,
            description = 'Complete 25 operações Hunter seguindo sua função designada.'
         WHERE slug = 'lider-da-resistencia'", [], 'achievements: LÍDER DA RESISTÊNCIA -> CADEIA DE COMANDO');
$p->run("UPDATE achievements SET description = REPLACE(REPLACE(description, 'da facção da resistência', 'do Hunter Command'), 'inimigos', 'organismos hostis')
         WHERE description LIKE '%resist%' OR description LIKE '%inimigos%'", [], 'achievements: vocabulário do mundo persistente');

// ================================================================ saída
if ($SQL) {
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    $arquivo = $dir . '/patch_v3_' . date('Y-m-d_His') . '.sql';
    $cabecalho = "-- Extract Hunters — patch v3 (mundo persistente PvPvE)\n-- Gerado em " . date('c') . "\n-- ADITIVO e IDEMPOTENTE: pode importar mais de uma vez. Nada é apagado.\nSET NAMES utf8mb4;\n\n";
    file_put_contents($arquivo, $cabecalho . implode("\n", $p->sql) . "\n");
    echo "\n" . count($p->sql) . " instruções em $arquivo\n";
} else {
    echo "\nPatch concluído" . ($DRY ? ' (dry-run, nada gravado)' : '') . " — {$p->feitos} passo(s).\n";
}
