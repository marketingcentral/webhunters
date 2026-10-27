<?php declare(strict_types=1);

/**
 * Layout LANDING — a página de divulgação na raiz do site (/).
 *
 * É o único casco do app que ROLA e que é PÚBLICO. Por isso ele não carrega base.css nem
 * hud.css (os dois travam o corpo na altura da janela, porque a HUD do jogo é uma tela única
 * de 1672x941) e não tem topbar, rodapé do servidor nem preloader de navegação. O que ele
 * compartilha com o jogo é o que faz a página PARECER o jogo: as fontes, os tokens de cor, a
 * moldura de HUD e — literalmente — o mapa (css/pages/mapa.css + js/three/map3d.js, o mesmo
 * palco da tela /mapa, desenhado pelo parcial partials/map-stage.php).
 *
 * `robots` aqui é INDEX: é a única página do domínio que deve aparecer numa busca.
 */

use App\Core\Config;
use App\Core\View;

$slug   = 'landing';
$title  = trim((string)($page['title'] ?? '')) ?: Config::APP_NAME;
$assets = Config::assets();
$nonce  = nonce();
$desc   = (string)(($page['description'] ?? '') ?: 'Extract Hunters - sobreviva, extraia, evolua.');

$styles  = ['fonts/fonts.css', 'css/tokens.css', 'css/pages/mapa.css', 'css/pages/' . $slug . '.css'];
$scripts = ['js/three/map3d.js', 'js/pages/' . $slug . '.js'];

$importmap    = View::importMap();
$preloadFonts = ['barlow-condensed-5.woff2', 'barlow-2.woff2', 'share-tech-mono-0.woff2', 'material-symbols-0.woff2', 'anton-2.woff2'];
$og           = img('bg/home-hero-wide.jpg');
?>
<!DOCTYPE html>
<html lang="pt-BR" class="page-<?= e($slug) ?>" data-estado="hunter">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#070907">
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta name="robots" content="index, follow">
<meta property="og:type" content="website">
<meta property="og:title" content="EXTRACT HUNTERS">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:image" content="<?= e($og) ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" type="image/png" href="<?= e(img('logo/emblem.png')) ?>">
<?php foreach ($preloadFonts as $font): ?>
<?php if (is_file($assets . '/fonts/' . $font)): ?>
<link rel="preload" as="font" type="font/woff2" crossorigin href="<?= e(asset('fonts/' . $font, false)) ?>">
<?php endif; ?>
<?php endforeach; ?>
<?php if (!empty($page['bg']) && is_file($assets . '/img/' . ltrim((string)$page['bg'], '/'))): ?>
<link rel="preload" as="image" href="<?= e(img((string)$page['bg'])) ?>">
<?php endif; ?>
<?php foreach ($styles as $css): ?>
<?php if (is_file($assets . '/' . $css)): ?>
<link rel="stylesheet" href="<?= e(asset($css)) ?>">
<?php else: ?>
<!-- assets/<?= e($css) ?> ainda não existe -->
<?php endif; ?>
<?php endforeach; ?>
</head>
<body class="page-<?= e($slug) ?>" data-page="<?= e($slug) ?>" data-base="<?= e(Config::baseUrl()) ?>" data-nonce="<?= e($nonce) ?>">
<?= $content ?>
<script type="importmap" nonce="<?= e($nonce) ?>"><?= $importmap ?></script>
<?php foreach ($scripts as $js): ?>
<?php if (is_file($assets . '/' . $js)): ?>
<script type="module" nonce="<?= e($nonce) ?>" src="<?= e(asset($js)) ?>"></script>
<?php else: ?>
<!-- assets/<?= e($js) ?> ainda não existe -->
<?php endif; ?>
<?php endforeach; ?>
</body>
</html>
