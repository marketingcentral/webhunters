<?php declare(strict_types=1);

/**
 * Patch v11 — BESTIÁRIO: o que cada jogador já viu das criaturas do enredo v6.
 *
 *   php db/patch_v11_bestiario.php              aplica no banco local
 *   php db/patch_v11_bestiario.php --dry-run    só mostra o que faria
 *   php db/patch_v11_bestiario.php --sql        escreve db/backups/patch_v11_<data>.sql
 *
 * O catálogo das criaturas NÃO está no banco: vive em app/Data/criaturas.php, que é o mesmo
 * texto da landing e da Bíblia v6 e muda junto com o enredo. O banco guarda só o que é do
 * jogador — quantas vezes avistou, quantas abateu e se já entregou amostra ao Icarus. Uma
 * linha por (jogador, criatura); `creature_slug` casa com `lista[*].slug` do catálogo.
 *
 * Por que slug e não chave estrangeira: uma criatura que sair do catálogo não pode apagar
 * o histórico de ninguém, e uma que entrar não precisa de migração.
 *
 * Só cria a tabela (IF NOT EXISTS). Não toca em users/players nem em nenhuma linha existente.
 */

require __DIR__ . '/cli_only.php';
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$DRY = in_array('--dry-run', $argv, true);
$SQL = in_array('--sql', $argv, true);

echo "=== Patch v11 — bestiário por jogador ===\n\n";

$ddl = <<<'SQL'
CREATE TABLE IF NOT EXISTS `player_bestiary` (
  `user_id`       INT UNSIGNED      NOT NULL,
  `creature_slug` VARCHAR(40)       NOT NULL,
  `sightings`     INT UNSIGNED      NOT NULL DEFAULT 0,
  `kills`         INT UNSIGNED      NOT NULL DEFAULT 0,
  `studied`       TINYINT(1)        NOT NULL DEFAULT 0,
  `first_seen_at` DATETIME          NULL,
  `updated_at`    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `creature_slug`),
  KEY `ix_bestiary_slug` (`creature_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

if ($SQL) {
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    $arq = $dir . '/patch_v11_' . date('Y-m-d_His') . '.sql';
    file_put_contents(
        $arq,
        "-- Extract Hunters — patch v11 (tabela player_bestiary). Idempotente e sem tocar em dado.\n"
        . '-- Gerado por db/patch_v11_bestiario.php em ' . date('c') . "\n\n"
        . $ddl . ";\n"
    );
    echo "1 instrução em $arq\n";
    return;
}

$existe = (int)Database::value(
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
    ['t' => 'player_bestiary']
);

if ($existe > 0) {
    echo "player_bestiary já existe — nada a fazer.\n";
} elseif ($DRY) {
    echo "[dry] + tabela player_bestiary\n";
} else {
    Database::run($ddl);
    echo "+ tabela player_bestiary\n";
}
