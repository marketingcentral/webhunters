<?php declare(strict_types=1);

/**
 * Patch ADITIVO v4 — SAFE ZONE (papéis civis entre operações).
 *
 *   php db/patch_v4_safezone.php              aplica no banco local
 *   php db/patch_v4_safezone.php --dry-run    só mostra o que faria
 *   php db/patch_v4_safezone.php --sql        NÃO toca no banco: escreve db/backups/patch_v4_<data>.sql
 *                                               para importar pelo phpMyAdmin do host
 *
 * Mesmo contrato do patch v3: não apaga nada e pode rodar quantas vezes quiser. `db/seed.php`
 * e `db/migrate.php` continuam proibidos em banco com jogador real (ver db/guard.php).
 *
 * O que cria:
 *   1. `safe_zones` — os perímetros protegidos. Três são governamentais (uma por Base
 *      Governamental do patch v3) e uma é a ZONA FRANCA, tolerada pela Frontier: é a única
 *      que aceita Sobreviventes, porque nenhuma base do governo responde por quem perdeu a
 *      bandeira (Bíblia §6).
 *   2. `player_safe_roles` — o papel civil que cada jogador assumiu, com turno e contador de
 *      atendimentos. Um papel ativo por vez, como o contrato de facção.
 *   3. um marcador de mapa por zona conhecida, para a Safe Zone aparecer no Mapa do Mundo.
 *
 * O catálogo de papéis (COMERCIANTE, FERRAMENTEIRO, INSTRUTOR, MÉDICO DA ZONA, TRANSPORTADOR,
 * ANALISTA ICARUS) vive em App\Core\GameRules::SAFE_ROLES — é regra, não conteúdo editável.
 */

require __DIR__ . '/cli_only.php';
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$DRY = in_array('--dry-run', $argv, true);
$SQL = in_array('--sql', $argv, true);

/** Mesmo executor do patch v3 (banco ou arquivo .sql idempotente). */
final class PatchSZ
{
    /** @var list<string> */
    public array $sql = [];
    public int $feitos = 0;

    public function __construct(private bool $dry, private bool $toSql) {}

    private function log(string $m): void
    {
        echo ($this->dry ? '[dry] ' : ($this->toSql ? '[sql] ' : '')) . $m . "\n";
    }

    public static function lit(mixed $v): string
    {
        if ($v === null) { return 'NULL'; }
        if (is_int($v) || is_float($v)) { return (string)$v; }
        if (is_bool($v)) { return $v ? '1' : '0'; }
        if (is_array($v)) { $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$v) . "'";
    }

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

$p = new PatchSZ($DRY, $SQL);
$j = static fn(array $a): string => json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

echo "=== Patch v4 — Safe Zone ===\n\n";

// ================================================================= 1) tabelas
$p->createTable('safe_zones', <<<'SQL'
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(32) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `kind` VARCHAR(8) NOT NULL DEFAULT 'gov',
  `base_slug` VARCHAR(16) NULL,
  `map_id` INT UNSIGNED NULL,
  `region` VARCHAR(80) NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'ABERTA',
  `occupancy` VARCHAR(16) NOT NULL DEFAULT 'MODERADA',
  `capacity` SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  `protection` VARCHAR(120) NULL,
  `description` TEXT NULL,
  `meta` JSON NULL,
  `sort_order` SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_safe_zones_slug` (`slug`)
SQL);

$p->createTable('player_safe_roles', <<<'SQL'
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `zone_slug` VARCHAR(32) NOT NULL,
  `role` VARCHAR(24) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'ATIVO',
  `started_at` DATETIME NOT NULL,
  `shift_ends_at` DATETIME NULL,
  `served` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_safe_role_user` (`user_id`),
  KEY `ix_safe_role_zone` (`zone_slug`)
SQL);

// ================================================================= 2) as quatro zonas
$servicos = [
    'gov'  => ['MERCADO COBERTO', 'OFICINA', 'ENFERMARIA', 'ALOJAMENTO', 'TERMINAL ICARUS', 'GARAGEM'],
    'free' => ['MERCADO ABERTO', 'OFICINA IMPROVISADA', 'POSTO MÉDICO', 'DORMITÓRIO COLETIVO'],
];
$regras = [
    'gov' => [
        'Armamento pesado fica guardado no arsenal da base enquanto você estiver dentro do perímetro.',
        'Fogo contra outro operador dentro da zona é registrado como conduta e vai ao Relatório de Conduta.',
        'A Veyra não entra: o perímetro é mantido pela guarnição da base.',
    ],
    'free' => [
        'Ninguém guarda sua arma e ninguém garante sua segurança: a trégua aqui é combinada, não imposta.',
        'A Frontier tolera a zona enquanto ela render — e cobra por isso.',
        'É a única zona que aceita quem está sem bandeira.',
    ],
];

$zonas = [
    ['vigia',   'SAFE ZONE VIGIA',   'gov',  'alpha',   1, 'FLORESTA VERDE', 'ABERTA',      'MODERADA', 24,
        'PERÍMETRO ATIVO · GUARNIÇÃO ALPHA',
        'O pátio interno da Base Vigia. Mercado coberto, oficina e enfermaria a poucos passos do portão de saída para a Floresta Verde.'],
    ['bravo',   'SAFE ZONE BRAVO',   'gov',  'bravo',   1, 'FLORESTA VERDE', 'MOVIMENTADA', 'ALTA',     24,
        'PERÍMETRO ATIVO · GUARNIÇÃO BRAVO',
        'A zona mais movimentada da região: quem volta de operação passa por aqui antes de voltar para o mundo.'],
    ['charlie', 'SAFE ZONE CHARLIE', 'gov',  'charlie', 1, 'FLORESTA VERDE', 'EM ALERTA',   'BAIXA',    24,
        'PERÍMETRO SOB PRESSÃO · GUARNIÇÃO CHARLIE',
        'Base isolada pelo avanço da Veyra no setor norte. Serviços reduzidos e movimento fraco enquanto a estrada não reabre.'],
    ['franca',  'ZONA FRANCA',       'free', null,      1, 'FLORESTA VERDE', 'TOLERADA',    'MODERADA', 16,
        'SEM PERÍMETRO · TRÉGUA COMBINADA',
        'Um acampamento tolerado pela Frontier fora do alcance do governo. Aceita quem nenhuma base aceita — inclusive quem perdeu a bandeira.'],
];

foreach ($zonas as [$slug, $nome, $kind, $base, $mapId, $regiao, $status, $ocupacao, $cap, $prot, $desc]) {
    $p->insertUnless('safe_zones', [
        'slug'        => $slug,
        'name'        => $nome,
        'kind'        => $kind,
        'base_slug'   => $base,
        'map_id'      => $mapId,
        'region'      => $regiao,
        'status'      => $status,
        'occupancy'   => $ocupacao,
        'capacity'    => $cap,
        'protection'  => $prot,
        'description' => $desc,
        'meta'        => $j(['services' => $servicos[$kind], 'rules' => $regras[$kind]]),
        'sort_order'  => count($p->sql) + 1,
    ], 'slug = :slug2', ['slug2' => $slug], "safe_zones: + $nome");
}

// ================================================================= 3) no Mapa do Mundo
$marcadores = [
    ['vigia',  'SAFE ZONE VIGIA',  0.4180, 0.4470],
    ['franca', 'ZONA FRANCA',      0.1900, 0.4900],
];
foreach ($marcadores as [$zona, $label, $x, $y]) {
    $p->run(
        "INSERT INTO map_markers (map_id, kind, label, x, y, meta)
         SELECT 1, 'safe_zone', :label, :x, :y, :meta FROM DUAL
          WHERE NOT EXISTS (SELECT 1 FROM map_markers WHERE map_id = 1 AND label = :label2)",
        [
            'label'  => $label,
            'x'      => $x,
            'y'      => $y,
            'meta'   => $j(['name' => $label, 'glyph' => 'safe-zone', 'color' => 'lime', 'zone' => $zona, 'label' => 'below']),
            'label2' => $label,
        ],
        "map_markers: + $label"
    );
}

// ================================================================= fim
if ($SQL) {
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    $arq = $dir . '/patch_v4_' . date('Y-m-d_His') . '.sql';
    file_put_contents($arq, "-- Extract Hunters — patch v4 (Safe Zone). Idempotente: pode importar mais de uma vez.\n"
        . "-- Gerado por db/patch_v4_safezone.php em " . date('c') . "\n\n"
        . implode("\n", $p->sql) . "\n");
    echo "\n" . count($p->sql) . " instruções em $arq\n";
} else {
    echo "\nPatch concluído — {$p->feitos} passo(s).\n";
}
