<?php declare(strict_types=1);

/**
 * Trava: os scripts de manutenção do banco só rodam pela LINHA DE COMANDO.
 *
 * Motivo real: em produção `db/` estava sendo servido pelo Apache e
 * `https://.../db/migrate.php` respondia 200 — qualquer pessoa (ou um crawler) podia
 * dropar o banco inteiro abrindo uma URL. O que impediu o estrago foi um acidente:
 * `$argv` não existe sob Apache e o guard quebrava antes do DROP.
 *
 * O `.htaccess` bloqueia `db/`, mas bloqueio de servidor é configuração — some num
 * host mal configurado, numa migração de painel ou se alguém trocar o arquivo.
 * Esta trava vive no código e viaja junto com ele.
 *
 * Inclua como PRIMEIRA linha executável de todo script destrutivo em db/.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("404\n");
}

// Sob CLI o PHP sempre popula $argv; se não populou, o register_argc_argv está desligado
// e as travas que leem flags (--force, --dry-run) ficariam cegas.
if (!isset($argv) || !is_array($argv)) {
    fwrite(STDERR, "register_argc_argv está desligado no php.ini do CLI; as flags não seriam lidas.\n");
    exit(1);
}
