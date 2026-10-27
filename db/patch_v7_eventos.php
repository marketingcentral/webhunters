<?php declare(strict_types=1);

/**
 * Patch ADITIVO v7 — TELA EVENTOS DO PAINEL (/admin/eventos).
 *
 *   php db/patch_v7_eventos.php              aplica no banco local
 *   php db/patch_v7_eventos.php --dry-run    só mostra o que faria
 *   php db/patch_v7_eventos.php --sql        NÃO toca no banco: escreve db/backups/patch_v7_<data>.sql
 *
 * Mesmo contrato dos patches v3/v4/v5/v6: não apaga nada, pode rodar quantas vezes quiser.
 *
 * O QUE MUDA — uma coisa só:
 *   `system_notices.user_id` passa a aceitar NULL.
 *
 * POR QUÊ: a tabela nasceu como "avisos do painel lateral POR JOGADOR" (a coluna é NOT NULL
 * e tem FK para users). O painel administrativo precisa também do aviso GLOBAL — "manutenção
 * programada", "evento começa em 2h" —, que não pertence a ninguém. As duas alternativas
 * eram piores:
 *   - uma tabela nova só para o aviso global duplicaria colunas, leitura e regra de ordenação;
 *   - materializar uma linha por jogador transformaria um aviso em N linhas, e editar o texto
 *     depois viraria um UPDATE em massa que precisa acertar exatamente as mesmas N.
 * NULL em user_id é o significado literal: "este aviso não é de um jogador".
 *
 * A FK continua de pé — MySQL não valida FK em valor NULL, então `ON DELETE CASCADE` segue
 * valendo para o aviso de jogador e o aviso global sobrevive à remoção de qualquer conta.
 *
 * EFEITO NO JOGO: nenhum. App\Repo\MessageRepo::notices() lê `WHERE user_id = :u`, então
 * continua entregando exatamente os mesmos avisos de hoje. O aviso global fica GUARDADO e
 * visível no painel, e passa a ser ENTREGUE quando aquela consulta incluir `OR user_id IS
 * NULL` — arquivo de outro dono, por isso não é tocado aqui. A tela /admin/eventos diz isso
 * na cara do operador em vez de deixar parecer que já está no ar.
 */

require __DIR__ . '/cli_only.php';
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$DRY = in_array('--dry-run', $argv, true);
$SQL = in_array('--sql', $argv, true);

const TABELA = 'system_notices';
const COLUNA = 'user_id';
const DDL    = 'INT UNSIGNED NULL';

$rotulo = ($DRY ? '[dry] ' : ($SQL ? '[sql] ' : ''));

/* MySQL não tem "ALTER ... MODIFY IF"; a idempotência é conferida no catálogo. No modo --sql
   a mesma pergunta vai embutida no arquivo, porque o .sql será importado num banco cujo
   estado não dá para inspecionar daqui. */
$alter = 'ALTER TABLE `' . TABELA . '` MODIFY COLUMN `' . COLUNA . '` ' . DDL;

if ($SQL) {
    $lit = "'" . str_replace("'", "''", $alter) . "'";
    $linhas = [
        "SET @n = (SELECT IS_NULLABLE FROM information_schema.COLUMNS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . TABELA . "' AND COLUMN_NAME = '" . COLUNA . "');",
        "SET @s = IF(@n = 'NO', $lit, 'SELECT 1');",
        'PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;',
    ];
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    $arq = $dir . '/patch_v7_' . date('Y-m-d_His') . '.sql';
    file_put_contents(
        $arq,
        "-- Extract Hunters — patch v7 (tela Eventos do painel). Idempotente: pode importar mais de uma vez.\n"
        . "-- system_notices.user_id passa a aceitar NULL = aviso global (não é de nenhum jogador).\n"
        . '-- Gerado por db/patch_v7_eventos.php em ' . date('c') . "\n\n"
        . implode("\n", $linhas) . "\n"
    );
    echo $rotulo . count($linhas) . " instruções em $arq\n";
    return;
}

$nulavel = (string)Database::value(
    'SELECT IS_NULLABLE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
    ['t' => TABELA, 'c' => COLUNA]
);

if ($nulavel === '') {
    fwrite(STDERR, "Tabela " . TABELA . " não existe neste banco. Rode db/patch_v3_contexto.php antes.\n");
    exit(1);
}

if ($nulavel === 'YES') {
    echo $rotulo . TABELA . '.' . COLUNA . " já aceita NULL — nada a fazer.\n";
} else {
    echo $rotulo . TABELA . '.' . COLUNA . " -> " . DDL . " (aviso global)\n";
    if (!$DRY) {
        Database::run($alter, []);
    }
}

if (!$DRY) {
    // Conferência: rodar de novo tem de imprimir exatamente estes mesmos números.
    printf(
        "system_notices: %d avisos (%d globais, %d de jogador) | nulável=%s\n",
        (int)Database::value('SELECT COUNT(*) FROM system_notices'),
        (int)Database::value('SELECT COUNT(*) FROM system_notices WHERE user_id IS NULL'),
        (int)Database::value('SELECT COUNT(*) FROM system_notices WHERE user_id IS NOT NULL'),
        (string)Database::value(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => TABELA, 'c' => COLUNA]
        )
    );
}
