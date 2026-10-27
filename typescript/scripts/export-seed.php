<?php declare(strict_types=1);

/**
 * Exporta o CONTEÚDO do jogo (catálogo) do MySQL LOCAL para typescript/seed/conteudo.sql,
 * em SQLite, pronto para o D1:
 *
 *   C:/wamp64/bin/php/php8.3.28/php.exe typescript/scripts/export-seed.php
 *   cd typescript && npx wrangler d1 execute DB --local --file=seed/conteudo.sql
 *
 * SÓ LEITURA: abre a conexão em modo READ ONLY e faz apenas SELECT. Nunca rode db/migrate.php
 * nem db/seed.php para "atualizar" o MySQL antes daqui: eles apagam contas reais.
 *
 * As credenciais vêm de app/db.local.php pela própria App\Core\Database e NUNCA são copiadas
 * para typescript/. O .sql gerado entra no repositório: é conteúdo, não segredo — por isso o
 * script ABORTA se a saída tiver cara de e-mail, hash de senha ou token.
 *
 * Contas e dados de jogador ficam SEMPRE de fora (ver $EXCLUIDAS). Coluna de conteúdo que
 * aponta para uma conta (updated_by, created_by...) sai como NULL.
 *
 * Por que UPSERT (INSERT ... ON CONFLICT DO UPDATE) e não INSERT OR REPLACE: no SQLite o
 * REPLACE APAGA a linha antiga antes de inserir, e o DELETE dispara os ON DELETE CASCADE.
 * Reaplicar o seed num D1 com jogadores apagaria player_items, player_missions, shop_offers...
 * (testado no D1 local). O upsert atualiza a linha no lugar e não dispara cascata.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("404\n");
}

$raiz = dirname(__DIR__, 2);
require $raiz . '/app/Core/Config.php';
require $raiz . '/app/Core/Database.php';
\App\Core\Config::boot($raiz);

use App\Core\Database;

/**
 * Tabelas de CONTEÚDO, na ordem das FOREIGN KEYs (pai antes de filho).
 * tabela => filtro WHERE opcional (null = todas as linhas).
 */
$CONTEUDO = [
    'items'               => null,
    'equipment_slots'     => null,
    'maps'                => null,
    'map_point_types'     => null,
    'map_markers'         => null,
    'missions'            => null,
    'mission_rewards'     => null,
    'achievements'        => null,
    'achievement_rewards' => null,
    'shop_offers'         => null,
    'skills'              => null,
    'news'                => null,
    'seasons'             => null,
    'season_tiers'        => null,
    'challenges'          => null,
    'challenge_bonuses'   => null,
    'government_bases'    => null,
    'safe_zones'          => null,
    'faq'                 => null,
    'support_categories'  => null,
    'server_regions'      => null,
    'server_services'     => null,
    'maintenance_windows' => null,
    'admin_settings'      => null,
    // Só os avisos GLOBAIS (patch v7_eventos). Aviso com user_id é de um jogador: fica de fora.
    'system_notices'      => 'user_id IS NULL',
];

/** Nunca exportadas: contas, estado de jogador, mensagens, punições, auditoria, API. */
$EXCLUIDAS = [
    'users', 'players', 'friends', 'squad_history', 'message_threads', 'message_entries',
    'support_tickets', 'bans', 'admin_audit_log', 'admin_metrics_daily', 'api_keys', 'api_request_log',
];
$ehExcluida = static fn(string $t): bool => in_array($t, $EXCLUIDAS, true) || str_starts_with($t, 'player_');

/** Colunas que apontam para uma conta: o valor vira NULL no seed. */
$COLUNAS_DE_CONTA = '/^(user_id|admin_id|owner_id|created_by|updated_by|revoked_by|author_id)$/';

/** Teto por INSERT: 50 linhas e ~90 KB (o D1 limita o tamanho de cada statement). */
const LOTE_LINHAS = 50;
const LOTE_BYTES  = 90_000;

$pdo = Database::pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Trava de segurança: a partir daqui qualquer escrita nesta conexão dá erro no próprio MySQL.
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->exec('START TRANSACTION READ ONLY');

$existentes = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

foreach (array_keys($CONTEUDO) as $t) {
    if ($ehExcluida($t)) {
        fwrite(STDERR, "ERRO: $t está na lista de conteúdo e na de excluídas.\n");
        exit(1);
    }
    if (!in_array($t, $existentes, true)) {
        fwrite(STDERR, "ERRO: tabela de conteúdo $t não existe no MySQL local.\n");
        exit(1);
    }
}
$semClasse = array_values(array_filter($existentes, static fn($t) => !array_key_exists($t, $CONTEUDO) && !$ehExcluida($t)));
if ($semClasse !== []) {
    // Tabela nova de patch futuro: decida se é conteúdo ou dado de jogador antes de exportar.
    fwrite(STDERR, 'AVISO: tabelas sem classificação (não exportadas): ' . implode(', ', $semClasse) . "\n");
}

$ident = static fn(string $n): string => '"' . str_replace('"', '""', $n) . '"';

/** Literal SQLite. Strings: aspas simples dobradas (SQLite não usa barra invertida). */
$literal = static function (mixed $v, string $tipoNativo): string {
    if ($v === null) { return 'NULL'; }
    if (is_bool($v)) { return $v ? '1' : '0'; }
    if (is_int($v)) { return (string)$v; }
    if (is_float($v)) { return is_finite($v) ? var_export($v, true) : 'NULL'; }
    $s = (string)$v;
    // DECIMAL chega como string ("1.250"): no SQLite a coluna é REAL, então vai como número.
    if ($tipoNativo === 'NEWDECIMAL' && preg_match('/^-?\d+(\.\d+)?$/', $s)) {
        return $s;
    }
    if (str_contains($s, "\0") || !mb_check_encoding($s, 'UTF-8')) {
        throw new RuntimeException('valor binário/UTF-8 inválido — BLOB não é suportado no seed');
    }
    return "'" . str_replace("'", "''", $s) . "'";
};

$saida = [];
$contagem = [];

foreach ($CONTEUDO as $t => $filtro) {
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
    $stmt = $pdo->query("SELECT * FROM `$t`" . ($filtro ? " WHERE $filtro" : '') . " ORDER BY $ordem");

    $colunas = [];
    $tipos = [];
    for ($i = 0; $i < $stmt->columnCount(); $i++) {
        $meta = $stmt->getColumnMeta($i);
        $colunas[] = $meta['name'];
        $tipos[] = (string)($meta['native_type'] ?? '');
    }
    $anular = array_map(static fn($c) => (bool)preg_match($COLUNAS_DE_CONTA, $c), $colunas);

    $linhas = $stmt->fetchAll(PDO::FETCH_NUM);
    $contagem[$t] = count($linhas);
    $saida[] = "-- ---------------------------------------------------------------- $t (" . count($linhas) . ' linhas)';
    if ($linhas === []) {
        $saida[] = '';
        continue;
    }

    $lista = implode(', ', array_map($ident, $colunas));
    $naoPk = array_values(array_diff($colunas, $pk));
    $conflito = ' ON CONFLICT (' . implode(', ', array_map($ident, $pk)) . ') DO '
        . ($naoPk === []
            ? 'NOTHING'
            : 'UPDATE SET ' . implode(', ', array_map(static fn($c) => $ident($c) . ' = excluded.' . $ident($c), $naoPk)));
    $cabeca = 'INSERT INTO ' . $ident($t) . " ($lista) VALUES\n";

    $lote = [];
    $bytes = 0;
    $fecha = static function () use (&$lote, &$bytes, &$saida, $cabeca, $conflito): void {
        if ($lote === []) { return; }
        $saida[] = $cabeca . implode(",\n", $lote) . "\n" . ltrim($conflito) . ';';
        $lote = [];
        $bytes = 0;
    };
    foreach ($linhas as $linha) {
        $campos = [];
        foreach ($linha as $i => $v) {
            $campos[] = $anular[$i] ? 'NULL' : $literal($v, $tipos[$i]);
        }
        $tupla = '(' . implode(', ', $campos) . ')';
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

$corpo = implode("\n", $saida);

/* Conferência de vazamento: o arquivo vai para o repositório. */
$suspeitas = [
    'e-mail'        => '/[A-Z0-9._%+-]+@[A-Z0-9-]+(\.[A-Z0-9-]+)*\.[A-Z]{2,}/i',
    'hash bcrypt'   => '/\$2[aby]\$\d{2}\$/',
    'hash/token hex'=> '/\b[0-9a-f]{40,}\b/i',
    'token base64'  => '/\b[A-Za-z0-9_\-]{48,}\b/',
];
foreach ($suspeitas as $nome => $re) {
    if (preg_match($re, $corpo, $m)) {
        fwrite(STDERR, "ABORTADO: a saída contém algo com cara de $nome: " . substr($m[0], 0, 12) . "…\n");
        exit(1);
    }
}

$total = array_sum($contagem);
$resumo = implode("\n", array_map(static fn($t, $n) => sprintf('--   %-20s %4d', $t, $n), array_keys($contagem), $contagem));
$cabecalho = <<<TXT
-- Extract Hunters — CONTEÚDO do jogo para o D1 (SQLite). GERADO: não edite à mão.
-- Fonte: MySQL local, só leitura, por typescript/scripts/export-seed.php
--   C:/wamp64/bin/php/php8.3.28/php.exe typescript/scripts/export-seed.php
--   cd typescript && npx wrangler d1 execute DB --local --file=seed/conteudo.sql
-- Sem contas nem dado de jogador. Colunas que apontam para conta saem NULL.
-- UPSERT por chave primária: reaplicar atualiza o catálogo sem apagar nada (sem cascata).
-- Linhas apagadas do catálogo no MySQL NÃO são apagadas no D1 por este arquivo.
-- $total linhas:
$resumo

TXT;

$destino = dirname(__DIR__) . '/seed';
if (!is_dir($destino) && !mkdir($destino, 0775, true) && !is_dir($destino)) {
    fwrite(STDERR, "Não consegui criar $destino\n");
    exit(1);
}
$arquivo = $destino . '/conteudo.sql';
file_put_contents($arquivo, $cabecalho . "\n" . $corpo);

echo 'gerado: ' . $arquivo . ' (' . (int)round(filesize($arquivo) / 1024) . " KB, $total linhas)\n";
foreach ($contagem as $t => $n) {
    printf("  %-20s %4d\n", $t, $n);
}
