<?php declare(strict_types=1);

/**
 * Layout HUD (SPEC §4). Scope: $page, $player, $nav, $server, $data, $flash, $csrf, $content.
 * Every stylesheet/script/partial is guarded with file_exists so the layout never breaks
 * while other owners are still writing their files.
 */

use App\Core\Config;
use App\Core\View;

$assets   = Config::assets();
$nonce    = nonce();
$pa       = View::pageAssets(is_array($page ?? null) ? $page : []);
$slug     = $pa['slug'];
$title    = trim((string)($page['title'] ?? ''));
$title    = $title !== '' ? $title . ' · ' . Config::APP_NAME : Config::APP_NAME;

/* Ordem do contrato: casco primeiro, css da página por último (ela precisa vencer). */
$styles  = $pa['shellCss'];
$scripts = array_merge($pa['shellJs'], $pa['onceJs'], $pa['pageJs']);

$importmap = View::importMap();

$preloadFonts = ['barlow-condensed-5.woff2', 'barlow-2.woff2', 'share-tech-mono-0.woff2', 'material-symbols-0.woff2', 'anton-2.woff2'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e(($page['description'] ?? '') ?: 'Extract Hunters - sobreviva, extraia, evolua.') ?>">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#070907">
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta name="csrf-token" content="<?= e($csrf ?? '') ?>">
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
<?php /* CSS da página: marcado para o spa.js poder trocá-lo na navegação sem recarga. */ ?>
<?php foreach ($pa['pageCss'] as $css): ?>
<?php if (View::assetExists($css)): ?>
<link rel="stylesheet" href="<?= e(asset($css)) ?>" data-spa-css="<?= e(asset($css)) ?>">
<?php endif; ?>
<?php endforeach; ?>
</head>
<body class="page-<?= e($slug) ?>" data-page="<?= e($slug) ?>" data-base="<?= e(Config::baseUrl()) ?>" data-nonce="<?= e($nonce) ?>">
<?php partial('background') ?>
<div class="hud">
<?php partial('topbar') ?>
<main class="hud-main" id="main">
<?php if (!empty($flash) && is_array($flash)): ?>
<div class="flash-stack" role="status" aria-live="polite">
<?php foreach ($flash as $kind => $msg): ?>
<?php if (is_string($msg) && $msg !== ''): ?>
<div class="flash flash--<?= e(preg_replace('/[^a-z0-9_-]/', '', (string)$kind) ?: 'info') ?>" data-flash data-autohide="<?= $kind === 'error' ? 9000 : 5000 ?>">
<?= icon($kind === 'error' ? 'warning' : 'check_circle', 'flash__icon') ?><span class="flash__text"><?= e($msg) ?></span>
</div>
<?php endif; ?>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?= $content ?>
</main>
<?php partial('footer') ?>
</div>
<div class="page-transition" aria-hidden="true"></div>
<script type="importmap" nonce="<?= e($nonce) ?>"><?= $importmap ?></script>
<?php if (is_file($assets . '/js/hud.js')): ?>
<script type="module" nonce="<?= e($nonce) ?>" src="<?= e(asset('js/hud.js')) ?>"></script>
<?php else: ?>
<!-- assets/js/hud.js ainda não existe -->
<?php endif; ?>
<?php foreach ($scripts as $js): ?>
<?php if (is_file($assets . '/' . $js)): ?>
<script type="module" nonce="<?= e($nonce) ?>" src="<?= e(asset($js)) ?>"></script>
<?php else: ?>
<!-- assets/<?= e($js) ?> ainda não existe -->
<?php endif; ?>
<?php endforeach; ?>
</body>
</html>
