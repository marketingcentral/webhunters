<?php declare(strict_types=1);

/**
 * Layout AUTH (login, criar-conta, recuperar-senha, 404/erros). Mesmo <head> do HUD,
 * sem topbar; <main class="auth-main"> centralizado e footer mínimo (partial se existir).
 */

use App\Core\Config;
use App\Core\View;

$slug   = preg_replace('/[^a-z0-9_-]/', '', (string)($page['slug'] ?? 'auth')) ?: 'auth';
$title  = trim((string)($page['title'] ?? ''));
$title  = $title !== '' ? $title . ' · ' . Config::APP_NAME : Config::APP_NAME;
$three  = is_array($page['three'] ?? null) ? $page['three'] : [];
$assets = Config::assets();
$nonce  = nonce();

/* spa.css entra aqui só pelo preloader: este casco não faz navegação sem recarga (não tem
   topbar), mas sair do login ainda é uma espera longa, e ela merece a mesma animação. */
$styles = ['fonts/fonts.css', 'css/tokens.css', 'css/base.css', 'css/hud.css', 'css/components.css',
           'css/animations.css', 'css/topbar.css', 'css/footer.css', 'css/logo.css', 'css/spa.css',
           'css/pages/' . $slug . '.css'];
foreach ((array)($page['css'] ?? []) as $extra) {
    $extra = ltrim((string)$extra, '/');
    $extra = str_starts_with($extra, 'css/') ? $extra : 'css/' . $extra;
    if (!in_array($extra, $styles, true)) {
        $styles[] = $extra;
    }
}

$scripts = ['js/carousel.js'];
if (!empty($three['atmosphere'])) { $scripts[] = 'js/three/atmosphere.js'; }
if (!empty($three['hero']))       { $scripts[] = 'js/three/hero3d.js'; }
if (!empty($three['map']))        { $scripts[] = 'js/three/map3d.js'; }
$scripts[] = 'js/pages/' . $slug . '.js';
foreach ((array)($page['js'] ?? []) as $extra) {
    $extra = ltrim((string)$extra, '/');
    $extra = str_starts_with($extra, 'js/') ? $extra : 'js/' . $extra;
    if (!in_array($extra, $scripts, true)) {
        $scripts[] = $extra;
    }
}

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
<meta name="robots" content="noindex">
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
<body class="page-<?= e($slug) ?> is-auth" data-page="<?= e($slug) ?>" data-base="<?= e(Config::baseUrl()) ?>" data-nonce="<?= e($nonce) ?>">
<?php partial('background') ?>
<div class="hud hud--auth">
<main class="auth-main" id="main">
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
<?php if (\App\Core\View::exists('partials', 'footer')): ?>
<?php partial('footer', ['minimal' => true]) ?>
<?php else: ?>
<!-- partials/footer.php ainda não existe -->
<?php endif; ?>
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
