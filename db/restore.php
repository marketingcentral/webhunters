<?php declare(strict_types=1);

/**
 * Restaura um backup gerado por db/guard.php (users + tabelas player_*).
 *
 *   php db/restore.php db/backups/pre-migrate-20260904-003000.json
 *   php db/restore.php <arquivo> --dry-run     (só mostra o que faria)
 *
 * Faz UPSERT: linhas que já existem são atualizadas, o resto é inserido. Nada é apagado —
 * restaurar nunca destrói o que está no banco agora.
 *
 * O esquema pode ter mudado desde o backup; colunas que não existem mais são ignoradas
 * (com aviso), em vez de derrubar a restauração inteira.
 */

require __DIR__ . '/cli_only.php';
require __DIR__ . '/../app/Core/Config.php';
require __DIR__ . '/../app/Core/Database.php';

use App\Core\Config;
use App\Core\Database;

Config::boot(dirname(__DIR__));

$arquivo = $argv[1] ?? '';
$dryRun  = in_array('--dry-run', $argv, true);
if ($arquivo === '' || !is_file($arquivo)) {
    fwrite(STDERR, "uso: php db/restore.php <arquivo.json> [--dry-run]\n");
    if ($arquivo !== '') { fwrite(STDERR, "arquivo não encontrado: $arquivo\n"); }
    $dir = __DIR__ . '/backups';
    if (is_dir($dir)) {
        $bs = glob($dir . '/*.json') ?: [];
        rsort($bs);
        if ($bs) { fwrite(STDERR, "\nbackups disponíveis:\n" . implode("\n", array_map(static fn($b) => '  ' . $b, array_slice($bs, 0, 10))) . "\n"); }
    }
    exit(1);
}

$dump = json_decode((string)file_get_contents($arquivo), true);
if (!is_array($dump)) { fwrite(STDERR, "backup ilegível: $arquivo\n"); exit(1); }

$pdo = Database::pdo();
$existentes = array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));

// users primeiro: as tabelas player_* têm FK para ela
uksort($dump, static fn(string $a, string $b): int => ($a === 'users' ? 0 : 1) <=> ($b === 'users' ? 0 : 1));

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$total = 0;
foreach ($dump as $tabela => $linhas) {
    if (!in_array($tabela, $existentes, true)) {
        echo "  ignorando `$tabela`: não existe mais no esquema\n";
        continue;
    }
    if (!is_array($linhas) || $linhas === []) { continue; }

    $colunas = array_map(static fn(array $c): string => (string)$c['Field'],
        $pdo->query("SHOW COLUMNS FROM `$tabela`")->fetchAll(PDO::FETCH_ASSOC));

    $sumidas = array_diff(array_keys((array)$linhas[0]), $colunas);
    if ($sumidas) { echo "  `$tabela`: colunas que não existem mais, ignoradas: " . implode(', ', $sumidas) . "\n"; }

    $usar = array_values(array_intersect(array_keys((array)$linhas[0]), $colunas));
    if ($usar === []) { echo "  `$tabela`: nenhuma coluna compatível, pulando\n"; continue; }

    $cols  = implode(', ', array_map(static fn($c) => "`$c`", $usar));
    $marks = implode(', ', array_fill(0, count($usar), '?'));
    $upd   = implode(', ', array_map(static fn($c) => "`$c` = VALUES(`$c`)", $usar));
    $st    = $pdo->prepare("INSERT INTO `$tabela` ($cols) VALUES ($marks) ON DUPLICATE KEY UPDATE $upd");

    $n = 0;
    foreach ($linhas as $linha) {
        if ($dryRun) { $n++; continue; }
        $st->execute(array_map(static fn($c) => $linha[$c] ?? null, $usar));
        $n++;
    }
    echo '  ' . str_pad($tabela, 24) . " $n linha(s)" . ($dryRun ? ' (dry-run)' : '') . "\n";
    $total += $n;
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

echo ($dryRun ? "\nDry-run: " : "\nRestaurado: ") . "$total linha(s) de " . basename($arquivo) . "\n";
