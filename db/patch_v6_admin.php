<?php declare(strict_types=1);

/**
 * Patch ADITIVO v6 — PAINEL ADMINISTRATIVO (esquema).
 *
 *   php db/patch_v6_admin.php              aplica no banco local
 *   php db/patch_v6_admin.php --dry-run    só mostra o que faria
 *   php db/patch_v6_admin.php --sql        NÃO toca no banco: escreve db/backups/patch_v6_<data>.sql
 *                                            para importar pelo phpMyAdmin do host
 *
 * Mesmo contrato dos patches v3/v4/v5: não apaga nada de jogador e pode rodar quantas vezes
 * quiser. `db/migrate.php` e `db/seed.php` continuam proibidos em banco com conta real (db/guard.php).
 *
 * O que cria:
 *   1. `users`: role (player|admin|owner), status (active|banned|suspended) e last_ip.
 *      A conta `extracthunters` vira owner — é o dono. Nenhum password_hash é tocado.
 *   2. `bans`      — histórico de punição, com quem aplicou, quem revogou e prazo.
 *   3. `admin_audit_log` — trilha de auditoria; é o que a tela "Logs do Sistema" lê.
 *   4. `admin_settings` — parâmetros do jogo editáveis sem deploy (chave/valor tipado).
 *   5. `admin_metrics_daily` — série diária que alimenta os gráficos de 7 e 30 dias.
 *
 * Sem FOREIGN KEY em admin_id/user_id de propósito: o log de auditoria e o histórico de bans
 * precisam sobreviver à remoção da conta envolvida — apagar o admin não pode apagar a prova
 * do que ele fez. A integridade fica na aplicação.
 */

require __DIR__ . '/cli_only.php';
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$DRY = in_array('--dry-run', $argv, true);
$SQL = in_array('--sql', $argv, true);

/** Mesmo executor dos patches v3/v4: dois destinos — o banco ou um arquivo .sql idempotente. */
final class PatchAdmin
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

    /** ALTER TABLE não tem IF NOT EXISTS no MySQL: em .sql sai guardado por information_schema. */
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
        $vals = implode(', ', array_map(static fn($c) => ':' . $c, $cols));
        $sql = "INSERT INTO `$table` ($colList) SELECT $vals FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `$table` WHERE $whereUnique)";
        $this->run($sql, $row + $uniqueParams, $motivo);
    }
}

$p = new PatchAdmin($DRY, $SQL);

echo "=== Patch v6 — painel administrativo ===\n\n";

// ==================================================== 1) users: papel, situação e último IP
$p->addColumn('users', 'role',    "VARCHAR(16) NOT NULL DEFAULT 'player'");   // player | admin | owner
$p->addColumn('users', 'status',  "VARCHAR(16) NOT NULL DEFAULT 'active'");   // active | banned | suspended
$p->addColumn('users', 'last_ip', 'VARCHAR(45) NULL');                        // 45 = IPv6 com mapeamento IPv4

/**
 * A conta do dono. O UPDATE é condicionado a `role <> 'owner'` para não gravar à toa na
 * segunda execução, e mexe só na coluna role: senha e e-mail ficam intactos.
 */
const CONTA_DONO = 'extracthunters';

$promover = "UPDATE users SET role = 'owner' WHERE username = :u AND role <> 'owner'";
$temRole = !$SQL && (int)Database::value(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'"
) > 0;

// No --dry-run a coluna ainda não existe, então nem dá para consultar o papel atual: o passo
// é apenas anunciado. No --sql o UPDATE já se protege sozinho pelo `role <> 'owner'`.
if ($SQL || !$temRole) {
    $p->run($promover, ['u' => CONTA_DONO], 'users: ' . CONTA_DONO . ' -> role=owner');
} else {
    $jaDono = (int)Database::value(
        'SELECT COUNT(*) FROM users WHERE username = :u AND role = :r',
        ['u' => CONTA_DONO, 'r' => 'owner']
    );
    if ($jaDono === 0) {
        $p->run($promover, ['u' => CONTA_DONO], 'users: ' . CONTA_DONO . ' -> role=owner');
    }
}

// ==================================================== 2) bans
$p->createTable('bans', <<<'SQL'
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `admin_id` INT UNSIGNED NULL,
  `reason` TEXT NULL,
  `kind` VARCHAR(16) NOT NULL DEFAULT 'temp',
  `expires_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at` DATETIME NULL,
  `revoked_by` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `ix_bans_user` (`user_id`, `created_at`),
  KEY `ix_bans_admin` (`admin_id`)
SQL);

// ==================================================== 3) admin_audit_log
$p->createTable('admin_audit_log', <<<'SQL'
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED NULL,
  `action` VARCHAR(64) NOT NULL,
  `entity` VARCHAR(48) NOT NULL,
  `entity_id` VARCHAR(64) NULL,
  `summary` VARCHAR(255) NOT NULL DEFAULT '',
  `meta` JSON NULL,
  `ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_audit_admin` (`admin_id`, `created_at`),
  KEY `ix_audit_created` (`created_at`)
SQL);

// ==================================================== 4) admin_settings
$p->createTable('admin_settings', <<<'SQL'
  `key` VARCHAR(64) NOT NULL,
  `value` TEXT NULL,
  `kind` VARCHAR(16) NOT NULL DEFAULT 'string',
  `label` VARCHAR(120) NOT NULL DEFAULT '',
  `grupo` VARCHAR(32) NOT NULL DEFAULT 'geral',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` INT UNSIGNED NULL,
  PRIMARY KEY (`key`),
  KEY `ix_settings_grupo` (`grupo`)
SQL);

/**
 * Sementes: os números que hoje vivem em App\Core\GameRules e nas telas. A tabela existe para
 * o dono mexer neles sem deploy — o valor daqui é a fonte quando a chave existir, e a constante
 * do código continua sendo o padrão de fallback.
 *
 * [chave, valor, tipo, rótulo, grupo]
 */
$CONFIGS = [
    ['maintenance_enabled',        '0',     'bool',   'Servidor em manutenção',                     'servidor'],
    ['maintenance_message',        'MANUTENÇÃO PROGRAMADA. VOLTAMOS EM BREVE.', 'string',
                                            'Aviso exibido durante a manutenção',                   'servidor'],
    ['registration_open',          '1',     'bool',   'Criação de contas liberada',                 'servidor'],
    ['region_capacity_total',      '20',    'int',    'Operadores simultâneos por região',          'mundo'],
    ['region_capacity_hunters',    '12',    'int',    'Vagas de Hunter por região (3 bases × 4)',   'mundo'],
    ['region_capacity_mercenaries','8',     'int',    'Vagas de Mercenário por região',             'mundo'],
    ['human_signal_ttl_min',       '25',    'int',    'Validade do sinal de atividade humana (min)','mundo'],
    ['world_event_interval_min',   '90',    'int',    'Intervalo entre eventos de mundo (min)',     'mundo'],
    ['safe_shift_minutes',         '45',    'int',    'Duração do turno na Safe Zone (min)',        'safe_zone'],
    ['squad_max',                  '4',     'int',    'Tamanho máximo do esquadrão',                'partida'],
    ['xp_per_level',               '10000', 'int',    'XP necessário por nível do passe',           'progressao'],
    ['support_sla_hours',          '24',    'int',    'Prazo de resposta padrão do suporte (h)',    'suporte'],
];

foreach ($CONFIGS as [$chave, $valor, $tipo, $rotulo, $grupo]) {
    $p->insertUnless('admin_settings', [
        'key'   => $chave,
        'value' => $valor,
        'kind'  => $tipo,
        'label' => $rotulo,
        'grupo' => $grupo,
    ], '`key` = :chave_uk', ['chave_uk' => $chave], "admin_settings: + $chave");
}

// ==================================================== 5) admin_metrics_daily
$p->createTable('admin_metrics_daily', <<<'SQL'
  `day` DATE NOT NULL,
  `players_online` INT UNSIGNED NOT NULL DEFAULT 0,
  `new_players` INT UNSIGNED NOT NULL DEFAULT 0,
  `sessions` INT UNSIGNED NOT NULL DEFAULT 0,
  `revenue_cents` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `avg_session_sec` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`day`)
SQL);

/**
 * SEMENTE DE DEMONSTRAÇÃO — não é telemetria.
 *
 * O dashboard do admin tem gráfico de 7 e de 30 dias, e gráfico sem dado não dá para revisar.
 * Estes 30 dias são gerados por uma função determinística da própria data (crc32 do dia), então
 * o mesmo dia sempre rende o mesmo número: rodar o patch de novo não redesenha o gráfico.
 * Quando a telemetria real existir, ela escreve nesta mesma tabela e as linhas semeadas são
 * simplesmente sobrescritas dia a dia.
 *
 * Os números são coerentes entre si: sessões puxam pico simultâneo (~16%), cadastros novos
 * (~4%) e receita; fim de semana sobe; há uma tendência leve de crescimento no mês.
 */
$ruido = static function (string $dia, string $campo, int $min, int $max): int {
    $span = max(1, $max - $min + 1);
    return $min + (int)(crc32($dia . '|' . $campo) % $span);
};

$hoje = new DateTimeImmutable('today');
$diasSemente = 0;
for ($i = 29; $i >= 0; $i--) {
    $data = $hoje->modify("-$i days");
    $dia  = $data->format('Y-m-d');

    $fimDeSemana = (int)$data->format('N') >= 6;
    $tendencia   = 1.0 + (29 - $i) * 0.011;               // ~ +32% ao longo do mês
    $sessoes     = (int)round(1180 * $tendencia * ($fimDeSemana ? 1.27 : 1.0)
                              * (1 + $ruido($dia, 'sessoes', -7, 7) / 100));
    $pico        = (int)round($sessoes * (0.150 + $ruido($dia, 'pico', 0, 30) / 1000));
    $novos       = (int)round($sessoes * (0.035 + $ruido($dia, 'novos', 0, 18) / 1000));
    $duracao     = 1500 + $ruido($dia, 'duracao', 0, 900);
    $receita     = $sessoes * (26 + $ruido($dia, 'receita', 0, 28));  // centavos por sessão

    $p->insertUnless('admin_metrics_daily', [
        'day'             => $dia,
        'players_online'  => $pico,
        'new_players'     => $novos,
        'sessions'        => $sessoes,
        'revenue_cents'   => $receita,
        'avg_session_sec' => $duracao,
    ], '`day` = :dia_uk', ['dia_uk' => $dia], "admin_metrics_daily: + $dia ($sessoes sessões, pico $pico)");
    $diasSemente++;
}

// ==================================================== fim
if ($SQL) {
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    $arq = $dir . '/patch_v6_' . date('Y-m-d_His') . '.sql';
    file_put_contents($arq, "-- Extract Hunters — patch v6 (painel administrativo). Idempotente: pode importar mais de uma vez.\n"
        . "-- Gerado por db/patch_v6_admin.php em " . date('c') . "\n"
        . "-- admin_metrics_daily traz $diasSemente dias de SEMENTE DE DEMONSTRAÇÃO (até existir telemetria real).\n\n"
        . implode("\n", $p->sql) . "\n");
    echo "\n" . count($p->sql) . " instruções em $arq\n";
    return;
}

echo "\nPatch v6 concluído — {$p->feitos} passo(s).\n";

if (!$DRY) {
    // Conferência: rodar de novo tem de imprimir exatamente estes mesmos números.
    $dono = Database::one('SELECT id, username, role, status FROM users WHERE username = :u', ['u' => CONTA_DONO]);
    printf(
        "dono: %s | bans: %d | audit: %d | settings: %d | metrics: %d (de %s a %s)\n",
        $dono ? "#{$dono['id']} {$dono['username']} role={$dono['role']} status={$dono['status']}" : 'conta não encontrada',
        (int)Database::value('SELECT COUNT(*) FROM bans'),
        (int)Database::value('SELECT COUNT(*) FROM admin_audit_log'),
        (int)Database::value('SELECT COUNT(*) FROM admin_settings'),
        (int)Database::value('SELECT COUNT(*) FROM admin_metrics_daily'),
        (string)Database::value('SELECT MIN(`day`) FROM admin_metrics_daily'),
        (string)Database::value('SELECT MAX(`day`) FROM admin_metrics_daily')
    );
}
