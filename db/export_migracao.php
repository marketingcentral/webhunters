<?php declare(strict_types=1);

/**
 * Gera um .sql SEGURO para levar o banco a um servidor novo.
 *
 * Por que não usar db/schema.sql: ele tem 39 `DROP TABLE` — é feito para recriar o banco
 * do zero em desenvolvimento. Importar aquilo num servidor que já tenha jogadores apaga
 * as contas. Este script produz o oposto:
 *
 *   - `CREATE TABLE IF NOT EXISTS`  -> tabela que já existe fica intacta
 *   - `INSERT IGNORE` no catálogo   -> linha que já existe não é sobrescrita
 *   - tabelas de jogador ficam de FORA por padrão (users, player_*)
 *
 * Ou seja: rodar duas vezes não faz diferença, e rodar num banco com dados reais não
 * destrói nada. O preço é que ele também NÃO corrige uma tabela que exista com o
 * esquema errado — para isso, ALTER TABLE avulso, conferido à mão.
 *
 * Uso:
 *   php db/export_migracao.php                 catálogo + esquema (recomendado)
 *   php db/export_migracao.php --com-jogador   inclui users/player_* (só para banco VAZIO)
 *
 * Saída: db/backups/migracao-<data>.sql  (db/backups não sobe no deploy)
 */

require __DIR__ . '/cli_only.php';
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$comJogador = in_array('--com-jogador', $argv, true);

$pdo = Database::pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/** Tabelas por jogador: só entram com --com-jogador, e só fazem sentido em banco vazio. */
$ehDeJogador = static fn(string $t): bool => str_starts_with($t, 'player_') || $t === 'users';

$tabelas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
sort($tabelas);

$destino = __DIR__ . '/backups';
if (!is_dir($destino) && !mkdir($destino, 0775, true) && !is_dir($destino)) {
    fwrite(STDERR, "Não consegui criar db/backups\n");
    exit(1);
}
$arquivo = $destino . '/migracao-' . date('Y-m-d_His') . '.sql';
$fh = fopen($arquivo, 'wb');
if ($fh === false) { fwrite(STDERR, "Não consegui escrever em $arquivo\n"); exit(1); }

$escreve = static function (string $s) use ($fh): void { fwrite($fh, $s); };

$escreve("-- Extract Hunters — migração para servidor novo\n");
$escreve('-- Gerado em ' . date('c') . "\n");
$escreve("-- SEGURO: só CREATE TABLE IF NOT EXISTS e INSERT IGNORE. Nada é apagado.\n");
$escreve('-- Tabelas de jogador: ' . ($comJogador ? 'INCLUÍDAS (use só em banco vazio)' : 'fora') . "\n\n");
$escreve("SET NAMES utf8mb4;\n");
$escreve("SET FOREIGN_KEY_CHECKS = 0;\n\n");

$linhasPorTabela = [];
$totalLinhas = 0;

foreach ($tabelas as $t) {
    $incluiDados = $comJogador || !$ehDeJogador($t);

    /* ---- esquema ---- */
    $ddl = (string)$pdo->query('SHOW CREATE TABLE `' . $t . '`')->fetch(PDO::FETCH_ASSOC)['Create Table'];
    // A única edição no DDL do MySQL: tornar a criação idempotente.
    $ddl = preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $ddl, 1);
    $escreve("-- ---------------------------------------------------------------- $t\n");
    $escreve($ddl . ";\n\n");

    if (!$incluiDados) {
        $escreve("-- (dados de $t omitidos: tabela por jogador)\n\n");
        continue;
    }

    /* ---- dados ---- */
    $linhas = $pdo->query('SELECT * FROM `' . $t . '`')->fetchAll(PDO::FETCH_ASSOC);
    $linhasPorTabela[$t] = count($linhas);
    $totalLinhas += count($linhas);
    if ($linhas === []) { $escreve("-- (sem linhas)\n\n"); continue; }

    $colunas = '`' . implode('`, `', array_keys($linhas[0])) . '`';
    // Lotes de 200: um INSERT gigante estoura o max_allowed_packet do phpMyAdmin.
    foreach (array_chunk($linhas, 200) as $lote) {
        $valores = [];
        foreach ($lote as $linha) {
            $campos = [];
            foreach ($linha as $v) {
                if ($v === null)      { $campos[] = 'NULL'; }
                elseif (is_int($v))   { $campos[] = (string)$v; }
                elseif (is_bool($v))  { $campos[] = $v ? '1' : '0'; }
                else                  { $campos[] = $pdo->quote((string)$v); }
            }
            $valores[] = '(' . implode(', ', $campos) . ')';
        }
        $escreve('INSERT IGNORE INTO `' . $t . "` ($colunas) VALUES\n" . implode(",\n", $valores) . ";\n");
    }
    $escreve("\n");
}

$escreve("SET FOREIGN_KEY_CHECKS = 1;\n");
fclose($fh);

$kb = (int)round(filesize($arquivo) / 1024);
echo "gerado: $arquivo  ({$kb} KB)\n";
echo '  tabelas: ' . count($tabelas) . ($comJogador ? ' (com dados de jogador)' : ' (catálogo apenas)') . "\n";
echo "  linhas de dados: $totalLinhas\n";
arsort($linhasPorTabela);
foreach (array_slice($linhasPorTabela, 0, 8, true) as $t => $n) {
    printf("    %-24s %d\n", $t, $n);
}
