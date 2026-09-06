<?php declare(strict_types=1);

/**
 * Aplica db/schema.sql (dropa e recria todas as tabelas). Uso:
 *   "C:/wamp64/bin/php/php8.3.28/php.exe" db/migrate.php
 * Conexão: app/db.local.php se existir, senão root@127.0.0.1 sem senha, banco extracthunters.
 */

require __DIR__ . '/cli_only.php';
require __DIR__ . '/../app/Core/Config.php';
require __DIR__ . '/../app/Core/Database.php';

use App\Core\Config;
use App\Core\Database;

Config::boot(dirname(__DIR__));

// Garante que o banco existe antes de conectar nele.
$cfg = (static function (): array {
    $f = dirname(__DIR__) . '/app/db.local.php';
    $d = ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'extracthunters', 'user' => 'root', 'pass' => ''];
    return is_file($f) ? array_replace($d, require $f) : $d;
})();

$boot = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};charset=utf8mb4", $cfg['user'], $cfg['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$boot->exec("CREATE DATABASE IF NOT EXISTS `{$cfg['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$sql = file_get_contents(__DIR__ . '/schema.sql');
if ($sql === false) { fwrite(STDERR, "schema.sql não encontrado\n"); exit(1); }

$pdo = Database::pdo();

require __DIR__ . '/guard.php';
guard_dados_do_jogador($pdo, $argv, 'db/migrate.php');

$pdo->exec($sql);

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo count($tables) . " tabelas criadas: " . implode(', ', $tables) . "\n";
