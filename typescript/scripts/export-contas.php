<?php declare(strict_types=1);

/**
 * Exporta as CONTAS do MySQL local (users, players e todas as player_*) para SQL do D1.
 *
 *   C:/wamp64/bin/php/php8.3.28/php.exe typescript/scripts/export-contas.php
 *   cd typescript && npx wrangler d1 execute DB --remote --file=.wrangler/contas.sql
 *
 * DADO PESSOAL: a saída tem e-mail e hash de senha. Por isso ela vai para
 * typescript/.wrangler/contas.sql, que está no .gitignore — nunca para seed/.
 * Os hashes vão como estão: bcrypt $2y$ do PHP valida no Worker (src/core/password.ts).
 *
 * SÓ LEITURA: a conexão abre em READ ONLY. Mesma conexão do app (app/Core/Database.php).
 * UPSERT por chave primária: reaplicar atualiza a conta sem apagar nada (sem cascata).
 */

if (PHP_SAPI !== 'cli') {
    exit("Só pela linha de comando.\n");
}

$raiz = dirname(__DIR__, 2);
require $raiz . '/app/Core/Config.php';
require $raiz . '/app/Core/Database.php';
\App\Core\Config::boot($raiz);

use App\Core\Database;

const LOTE_LINHAS = 50;
const LOTE_BYTES  = 90_000;

$pdo = Database::pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->exec('START TRANSACTION READ ONLY');

$existentes = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

/* Pai antes de filho: users -> players -> player_* (em ordem alfabética). */
$tabelas = ['users', 'players'];
foreach ($existentes as $t) {
    if (str_starts_with($t, 'player_')) {
        $tabelas[] = $t;
    }
}
sort($tabelas);
$tabelas = array_values(array_unique(array_merge(['users', 'players'], $tabelas)));

$ident = static fn(string $n): string => '"' . str_replace('"', '""', $n) . '"';

$literal = static function (mixed $v, string $tipoNativo): string {
    if ($v === null) { return 'NULL'; }
    if (is_bool($v)) { return $v ? '1' : '0'; }
    if (is_int($v)) { return (string)$v; }
    if (is_float($v)) { return is_finite($v) ? var_export($v, true) : 'NULL'; }
    $s = (string)$v;
    if ($tipoNativo === 'NEWDECIMAL' && preg_match('/^-?\d+(\.\d+)?$/', $s)) {
        return $s;
    }
    if (str_contains($s, "\0") || !mb_check_encoding($s, 'UTF-8')) {
        throw new RuntimeException('valor binário/UTF-8 inválido — BLOB não é suportado');
    }
    return "'" . str_replace("'", "''", $s) . "'";
};

/* player_items.parent_id aponta para a própria tabela: a checagem de FK fica para o fim. */
$saida = ['PRAGMA defer_foreign_keys = true;', ''];
$contagem = [];

foreach ($tabelas as $t) {
    $pk = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t) . " AND CONSTRAINT_NAME = 'PRIMARY'
          ORDER BY ORDINAL_POSITION"
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($pk === []) {
        fwrite(STDERR, "ERRO: $t não tem PRIMARY KEY (o upsert precisa dela).\n");
        exit(1);
    }
    $ordem = implode(', ', array_map(static fn($c) => '`' . $c . '`', $pk));
    $stmt = $pdo->query("SELECT * FROM `$t` ORDER BY $ordem");

    $colunas = [];
    $tipos = [];
    for ($i = 0; $i < $stmt->columnCount(); $i++) {
        $meta = $stmt->getColumnMeta($i);
        $colunas[] = $meta['name'];
        $tipos[] = (string)($meta['native_type'] ?? '');
    }
    $linhas = $stmt->fetchAll(PDO::FETCH_NUM);
    $contagem[$t] = count($linhas);
    if ($linhas === []) {
        continue;
    }

    $lista = implode(', ', array_map($ident, $colunas));
    $naoPk = array_values(array_diff($colunas, $pk));
    $conflito = 'ON CONFLICT (' . implode(', ', array_map($ident, $pk)) . ') DO '
        . ($naoPk === []
            ? 'NOTHING'
            : 'UPDATE SET ' . implode(', ', array_map(static fn($c) => $ident($c) . ' = excluded.' . $ident($c), $naoPk)));
    $cabeca = 'INSERT INTO ' . $ident($t) . " ($lista) VALUES\n";

    $lote = [];
    $bytes = 0;
    $fecha = static function () use (&$lote, &$bytes, &$saida, $cabeca, $conflito): void {
        if ($lote === []) { return; }
        $saida[] = $cabeca . implode(",\n", $lote) . "\n" . $conflito . ';';
        $lote = [];
        $bytes = 0;
    };
    foreach ($linhas as $linha) {
        $tupla = '(' . implode(', ', array_map(static fn($v, $tp) => $literal($v, $tp), $linha, $tipos)) . ')';
        if ($lote !== [] && (count($lote) >= LOTE_LINHAS || $bytes + strlen($tupla) > LOTE_BYTES)) {
            $fecha();
        }
        $lote[] = $tupla;
        $bytes += strlen($tupla) + 2;
    }
    $fecha();
    $saida[] = '';
}

$pdo->exec('COMMIT');

$destino = dirname(__DIR__) . '/.wrangler';
if (!is_dir($destino)) {
    mkdir($destino, 0775, true);
}
$arquivo = $destino . '/contas.sql';
file_put_contents($arquivo, "-- Contas do MySQL local (DADO PESSOAL — fora do git). Gerado por scripts/export-contas.php\n" . implode("\n", $saida));

echo 'gerado: ' . $arquivo . ' (' . (int)round(filesize($arquivo) / 1024) . " KB)\n";
foreach ($contagem as $t => $n) {
    printf("  %-22s %5d\n", $t, $n);
}
