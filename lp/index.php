<?php declare(strict_types=1);

/**
 * /lp — endereço antigo da página de divulgação.
 *
 * A landing passou a ser a RAIZ do site (rota '/', App\Controllers\LandingController, layout
 * layouts/landing.php). Esta pasta fica só para quem guardou o link antigo: o `.htaccess`
 * para de reescrever ao encontrar um diretório real, então o DirectoryIndex chega aqui e
 * este arquivo manda de volta para a raiz com um 301 — o navegador e os buscadores aprendem
 * o endereço novo e param de pedir este.
 *
 * Os arquivos que viviam aqui (css/lp.css, js/lp.js, img/) foram para dentro do app:
 * assets/css/pages/landing.css, assets/js/pages/landing.js e assets/img/lp/.
 */

$base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/lp/index.php'))), '/');
header('Location: ' . ($base === '' ? '/' : $base . '/'), true, 301);
header('Cache-Control: public, max-age=86400');
exit;
