<?php declare(strict_types=1);

/**
 * Patch ADITIVO v7 — ÍNDICE DA TELA DE LOGS (/admin/logs).
 *
 *   php db/patch_v7_logs.php              aplica no banco local
 *   php db/patch_v7_logs.php --dry-run    só mostra o que faria
 *   php db/patch_v7_logs.php --sql        NÃO toca no banco: escreve db/backups/patch_v7_<data>.sql
 *
 * NÃO CRIA, NÃO APAGA E NÃO ALTERA UMA ÚNICA LINHA DE DADO. O patch inteiro é um índice.
 * Pode rodar quantas vezes quiser: se o índice já existir, ele não faz nada.
 *
 * POR QUE ELE EXISTE
 * ------------------
 * db/patch_v6_admin.php criou admin_audit_log com dois índices: (admin_id, created_at) e
 * (created_at). Eles cobrem "o que esta pessoa fez" e "o que aconteceu neste período" — os
 * dois filtros que o dashboard usa.
 *
 * A tela de logs acrescentou um terceiro recorte que o dashboard não tinha: FILTRAR POR AÇÃO
 * e continuar ordenando pela data ("todas as falhas de login, da mais recente para a mais
 * antiga"). Sem índice, isso é varredura da tabela inteira mais ordenação em memória a cada
 * página — invisível nos 11 registros de hoje, e o tipo de coisa que só aparece quando a
 * trilha tem meio milhão de linhas e alguém precisa dela com urgência. A coluna `action`
 * também é a que alimenta o <select> de ações e o resumo lateral (GROUP BY action).
 *
 * `created_at` entra JUNTO no índice (e não sozinha em outro) porque é assim que o MySQL
 * resolve o WHERE e o ORDER BY na mesma leitura, sem filesort.
 *
 * O que NÃO tem índice, de propósito: a busca livre (`q`) varre `summary` com LIKE '%...%',
 * que nenhum índice B-tree cobre. Quando a trilha ficar grande de verdade, a resposta certa
 * é FULLTEXT em summary — não um índice comum que o otimizador ignoraria.
 */

require __DIR__ . '/cli_only.php';
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$DRY = in_array('--dry-run', $argv, true);
$SQL = in_array('--sql', $argv, true);

const TABELA = 'admin_audit_log';
const INDICE = 'ix_audit_action';
const COLUNAS = '(`action`, `created_at`)';

echo "=== Patch v7 — índice da tela de logs ===\n\n";

$ddl = 'ALTER TABLE `' . TABELA . '` ADD INDEX `' . INDICE . '` ' . COLUNAS;

if ($SQL) {
    // ALTER TABLE não tem IF NOT EXISTS no MySQL: no arquivo .sql a guarda é feita à mão
    // com information_schema, para o import pelo phpMyAdmin poder repetir sem erro.
    $linhas = [
        "SET @i = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . TABELA . "' AND INDEX_NAME = '" . INDICE . "');",
        "SET @s = IF(@i = 0, '" . $ddl . "', 'SELECT 1');",
        'PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;',
    ];
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    $arq = $dir . '/patch_v7_' . date('Y-m-d_His') . '.sql';
    file_put_contents(
        $arq,
        "-- Extract Hunters — patch v7 (índice da tela /admin/logs). Idempotente e sem tocar em dado.\n"
        . '-- Gerado por db/patch_v7_logs.php em ' . date('c') . "\n\n"
        . implode("\n", $linhas) . "\n"
    );
    echo count($linhas) . " instruções em $arq\n";
    return;
}

$existeTabela = (int)Database::value(
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
    ['t' => TABELA]
);
if ($existeTabela === 0) {
    // Sem a tabela, o lugar de começar é o v6 — este patch não a recria pela metade.
    echo "A tabela " . TABELA . " não existe. Rode antes: php db/patch_v6_admin.php\n";
    exit(1);
}

$existe = (int)Database::value(
    'SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i',
    ['t' => TABELA, 'i' => INDICE]
);

if ($existe > 0) {
    echo 'índice ' . INDICE . " já existe — nada a fazer.\n";
} elseif ($DRY) {
    echo '[dry] ' . TABELA . ': + índice ' . INDICE . ' ' . COLUNAS . "\n";
} else {
    Database::run($ddl);
    echo TABELA . ': + índice ' . INDICE . ' ' . COLUNAS . "\n";
}

// Conferência: rodar de novo tem de imprimir exatamente estas mesmas linhas.
if (!$DRY) {
    echo "\níndices em " . TABELA . ":\n";
    foreach (Database::all('SHOW INDEX FROM `' . TABELA . '`') as $ix) {
        printf("  %-18s seq %d  %s\n", (string)$ix['Key_name'], (int)$ix['Seq_in_index'], (string)$ix['Column_name']);
    }
    printf("\nregistros na trilha: %d (nenhum tocado por este patch)\n", (int)Database::value('SELECT COUNT(*) FROM `' . TABELA . '`'));
}
