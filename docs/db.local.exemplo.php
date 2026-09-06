<?php declare(strict_types=1);

/**
 * MODELO de app/db.local.php — copie para `app/db.local.php` NO SERVIDOR e preencha.
 *
 * Este arquivo vive em docs/ de propósito: docs/ nunca é publicado pelo deploy e é
 * bloqueado por HTTP. O arquivo real, `app/db.local.php`, também está fora do deploy
 * (`tools/deploy.mjs` o exclui por nome) — é assim que a senha do banco fica no servidor
 * sem nunca passar por um arquivo versionado.
 *
 * O efeito colateral disso: num SERVIDOR NOVO ele não existe, e o site sobe sem banco.
 * Foi o que aconteceu na migração de 05/09/2026 — o site respondia, mas login e todas as
 * telas que leem do MySQL davam erro. Criar este arquivo é o primeiro passo em host novo.
 *
 * `App\Core\Database::config()` mescla o que estiver aqui por cima dos padrões, e só
 * aceita estas seis chaves — qualquer outra é ignorada.
 */

return [
    'host'    => '127.0.0.1',      // no aaPanel o MySQL é local; use 127.0.0.1, não 'localhost'
    'port'    => 3306,
    'name'    => 'NOME_DO_BANCO',
    'user'    => 'USUARIO_DO_BANCO',
    'pass'    => 'SENHA_DO_BANCO',
    'charset' => 'utf8mb4',
];
