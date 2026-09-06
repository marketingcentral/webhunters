<?php declare(strict_types=1);

/**
 * Layout OVERLAY — HUD in-game.
 *
 * Difere do layout `hud` em três coisas que importam quando a página vira overlay do jogo:
 *   1. fundo TRANSPARENTE (nada de background, vinheta ou canvas do three.js);
 *   2. sem topbar/rodapé/flash — só o conteúdo da página;
 *   3. `pointer-events: none` no corpo, para o clique atravessar para o jogo. Quem precisar
 *      receber clique reativa em si mesmo (o plugin ainda checa o alfa do pixel).
 *
 * Escopo: $page, $player, $data, $csrf, $content.
 */

use App\Core\Config;

$slug   = preg_replace('/[^a-z0-9_-]/', '', (string)($page['slug'] ?? 'hud')) ?: 'hud';
$title  = trim((string)($page['title'] ?? ''));
$title  = $title !== '' ? $title . ' · ' . Config::APP_NAME : Config::APP_NAME;
$assets = Config::assets();
$nonce  = nonce();

// Só o essencial: tokens (cores/tipografia) e o css da própria página. Sem hud.css,
// topbar.css nem components.css — eles pintam fundos que quebrariam a transparência.
$styles = ['fonts/fonts.css', 'css/tokens.css', 'css/overlay.css', 'css/pages/' . $slug . '.css'];

$scripts = ['js/pages/' . $slug . '.js'];

$preloadFonts = ['barlow-condensed-5.woff2', 'share-tech-mono-0.woff2'];
?>
<!DOCTYPE html>
<html lang="pt-BR" class="overlay">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="color-scheme" content="dark">
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta name="csrf-token" content="<?= e($csrf ?? '') ?>">
<?php foreach ($preloadFonts as $font): ?>
<?php if (is_file($assets . '/fonts/' . $font)): ?>
<link rel="preload" as="font" type="font/woff2" crossorigin href="<?= e(asset('fonts/' . $font, false)) ?>">
<?php endif; ?>
<?php endforeach; ?>
<?php foreach ($styles as $css): ?>
<?php if (is_file($assets . '/' . $css)): ?>
<link rel="stylesheet" href="<?= e(asset($css)) ?>">
<?php endif; ?>
<?php endforeach; ?>
</head>
<body class="overlay-body page-<?= e($slug) ?>" data-page="<?= e($slug) ?>" data-base="<?= e(Config::baseUrl()) ?>">
<?= $content ?>
<?php foreach ($scripts as $js): ?>
<?php if (is_file($assets . '/' . $js)): ?>
<script type="module" nonce="<?= e($nonce) ?>" src="<?= e(asset($js)) ?>"></script>
<?php endif; ?>
<?php endforeach; ?>
</body>
</html>
