<?php declare(strict_types=1);

/**
 * Patch ADITIVO v7 — TELA BANIMENTOS (/admin/banimentos).
 *
 *   php db/patch_v7_banimentos.php              aplica no banco local
 *   php db/patch_v7_banimentos.php --dry-run    só mostra o que faria
 *   php db/patch_v7_banimentos.php --sql        escreve db/backups/patch_v7_<data>.sql
 *
 * Mesmo contrato dos patches v3..v6: só ACRESCENTA, roda quantas vezes quiser, não toca em
 * nenhuma linha de jogador. `db/migrate.php` e `db/seed.php` continuam proibidos aqui.
 *
 * O que falta em `bans` (criada pelo patch v6) para a tela existir de verdade:
 *
 *   1. `revoke_reason` — a tabela já guarda revoked_at/revoked_by, mas não guarda POR QUE a
 *      punição caiu. Sem essa coluna a revogação vira um carimbo anônimo: seis meses depois
 *      ninguém sabe se o ban foi derrubado por recurso aceito, engano de alvo ou acordo. O
 *      motivo da PUNIÇÃO está em `reason`; o motivo do PERDÃO precisava do par dele.
 *
 *   2. `ix_bans_estado` — a listagem filtra por estado, e estado é derivado de
 *      (revoked_at, expires_at). Sem índice, o filtro "ativos" varre a tabela inteira; a
 *      tabela de punições só cresce.
 */

require __DIR__ . '/cli_only.php';
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$DRY = in_array('--dry-run', $argv, true);
$SQL = in_array('--sql', $argv, true);

$prefixo = $DRY ? '[dry] ' : ($SQL ? '[sql] ' : '');
/** @var list<string> $script Linhas do arquivo .sql, quando --sql. */
$script = [];
$feitos = 0;

/** Existe? é consulta ao information_schema — em --sql a guarda vai para dentro do arquivo. */
$temColuna = static function (string $tabela, string $coluna): bool {
    return (int)Database::value(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
        ['t' => $tabela, 'c' => $coluna]
    ) > 0;
};

$temIndice = static function (string $tabela, string $indice): bool {
    return (int)Database::value(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i',
        ['t' => $tabela, 'i' => $indice]
    ) > 0;
};

/** Envelope condicional para o arquivo .sql: MySQL não tem ADD COLUMN IF NOT EXISTS. */
$guardado = static function (string $consultaContagem, string $ddl): array {
    return [
        'SET @c = (' . $consultaContagem . ');',
        "SET @s = IF(@c = 0, '" . str_replace("'", "\\'", $ddl) . "', 'SELECT 1');",
        'PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;',
    ];
};

echo "=== Patch v7 — tela de banimentos ===\n\n";

// A tabela nasce no v6. Sem ela, este patch não tem o que acrescentar — e criar aqui uma
// segunda versão de `bans` seria duas fontes da verdade para o mesmo esquema.
if (!$SQL) {
    $temBans = (int)Database::value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bans'"
    ) > 0;
    if (!$temBans) {
        fwrite(STDERR, "A tabela `bans` não existe. Rode antes: php db/patch_v6_admin.php\n");
        exit(1);
    }
}

// ---------------------------------------------------------------- 1) bans.revoke_reason
$ddlColuna = 'ALTER TABLE `bans` ADD COLUMN `revoke_reason` TEXT NULL AFTER `revoked_by`';
if ($SQL) {
    echo $prefixo . "bans.revoke_reason (se não existir)\n";
    $script = array_merge($script, $guardado(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bans' AND COLUMN_NAME = 'revoke_reason'",
        $ddlColuna
    ));
    $feitos++;
} elseif (!$temColuna('bans', 'revoke_reason')) {
    echo $prefixo . "bans: + coluna revoke_reason\n";
    $feitos++;
    if (!$DRY) { Database::run($ddlColuna); }
} else {
    echo "bans.revoke_reason já existe — nada a fazer\n";
}

// ---------------------------------------------------------------- 2) bans.ix_bans_estado
$ddlIndice = 'ALTER TABLE `bans` ADD INDEX `ix_bans_estado` (`revoked_at`, `expires_at`)';
if ($SQL) {
    echo $prefixo . "bans.ix_bans_estado (se não existir)\n";
    $script = array_merge($script, $guardado(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bans' AND INDEX_NAME = 'ix_bans_estado'",
        $ddlIndice
    ));
    $feitos++;
} elseif (!$temIndice('bans', 'ix_bans_estado')) {
    echo $prefixo . "bans: + índice ix_bans_estado\n";
    $feitos++;
    if (!$DRY) { Database::run($ddlIndice); }
} else {
    echo "bans.ix_bans_estado já existe — nada a fazer\n";
}

// ---------------------------------------------------------------- saída
if ($SQL) {
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    $arquivo = $dir . '/patch_v7_' . date('Ymd_His') . '.sql';
    file_put_contents(
        $arquivo,
        "-- Extract Hunters — patch v7 (banimentos). Idempotente: pode reimportar.\n"
        . implode("\n", $script) . "\n"
    );
    echo "\nArquivo escrito: $arquivo\n";
    exit(0);
}

echo "\n" . ($DRY ? 'Nada foi gravado (--dry-run). ' : '') . "Passos: $feitos\n";

if (!$DRY) {
    // Prova curta de que o esquema está de pé, no formato que a tela vai consultar.
    $linha = Database::one(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN revoked_at IS NOT NULL THEN 1 ELSE 0 END) AS revogados,
                SUM(CASE WHEN revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW()) THEN 1 ELSE 0 END) AS ativos
           FROM bans"
    );
    printf(
        "bans: %d no total | %d ativos | %d revogados\n",
        (int)($linha['total'] ?? 0),
        (int)($linha['ativos'] ?? 0),
        (int)($linha['revogados'] ?? 0)
    );
}
