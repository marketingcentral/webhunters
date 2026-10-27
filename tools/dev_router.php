<?php declare(strict_types=1);

/**
 * Router do servidor embutido do PHP, para desenvolver sem o Apache do WAMP.
 *
 *   php -S localhost:8080 -t . tools/dev_router.php
 *
 * Serve arquivo estático que exista (assets, media, modelos) e joga o resto no
 * front controller, como o .htaccess faz em produção. Só para desenvolvimento:
 * não vai para o deploy (tools/ está na lista NEGAR de tools/deploy.mjs).
 */

$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/..' . urldecode($uri);

// arquivo real (e não um diretório) sai direto, com o Content-Type do servidor
if ($uri !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/../index.php';
