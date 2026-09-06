<?php declare(strict_types=1);

/**
 * Trava de segurança compartilhada por db/migrate.php e db/seed.php.
 *
 * Os DOIS são destrutivos: migrate faz DROP+CREATE de tudo e seed faz TRUNCATE em `users`
 * e `players`. Contas criadas de verdade pelo /criar-conta somem em qualquer um dos dois.
 * Já perdi as contas de um usuário assim — daí esta trava existir.
 *
 * Contas do seed não contam: elas voltam sozinhas na próxima execução.
 */

/** Contas que o próprio seed cria (podem ser destruídas à vontade). */
const CONTAS_DO_SEED = ['HUNTER_07'];

/**
 * Aborta se houver conta criada fora do seed, a menos que venha `--force`.
 * Com `--force`, salva antes um backup JSON de `users` + todas as tabelas `player_*`.
 *
 * @param string[] $argv        argumentos da linha de comando
 * @param string   $comando     nome do script, só para a mensagem
 */
function guard_dados_do_jogador(PDO $pdo, array $argv, string $comando): void
{
    $forcar = in_array('--force', $argv, true);

    if (!$pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn()) {
        return;                                   // banco novo: nada a perder
    }

    $in = implode(',', array_fill(0, count(CONTAS_DO_SEED), '?'));
    $st = $pdo->prepare("SELECT id, username, created_at FROM users WHERE username NOT IN ($in) ORDER BY id");
    $st->execute(CONTAS_DO_SEED);
    $reais = $st->fetchAll(PDO::FETCH_ASSOC);

    if ($reais === []) {
        return;
    }

    if (!$forcar) {
        fwrite(STDERR, "\nABORTADO: existem " . count($reais) . " conta(s) criada(s) fora do seed.\n");
        foreach ($reais as $u) {
            fwrite(STDERR, "  #{$u['id']} {$u['username']} (criada em {$u['created_at']})\n");
        }
        fwrite(STDERR,
            "\n$comando apaga os dados dos jogadores — estas contas seriam perdidas.\n" .
            "  • Alterar o esquema sem perder contas: escreva um ALTER TABLE avulso.\n" .
            "  • Recriar mesmo assim:                 php $comando --force  (faz backup antes)\n" .
            "  • Restaurar um backup:                 php db/restore.php <arquivo.json>\n\n");
        exit(1);
    }

    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    $arquivo = $dir . '/pre-' . basename($comando, '.php') . '-' . date('Ymd-His') . '.json';

    $dump = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $t = (string)$t;
        if ($t !== 'users' && !str_starts_with($t, 'player_')) { continue; }
        $dump[$t] = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
    }
    file_put_contents($arquivo, json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $linhas = array_sum(array_map('count', $dump));
    echo "Backup antes de destruir: $arquivo (" . count($dump) . " tabelas, $linhas linhas)\n";
}
