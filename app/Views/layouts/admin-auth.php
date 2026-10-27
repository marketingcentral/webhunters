<?php declare(strict_types=1);

/**
 * Layout ADMIN-AUTH — casca da porta do painel (só /admin/login por enquanto).
 *
 * É separado do layout `auth` do jogo de propósito: a tela de login administrativa não
 * tem cenário 3D, não tem preloader de SPA e não carrega hud.js. Ela é o painel — mesma
 * paleta, mesma sobriedade, mesmos primitivos .adm-* de assets/css/admin.css.
 *
 * $page: 'slug' (admin-login), 'title'. $data é da tela.
 * CSS/JS da tela seguem o slug: assets/css/admin/<slug>.css e assets/js/admin/<slug>.js.
 */

use App\Core\Config;

$assets = Config::assets();
$nonce  = nonce();

$slug  = preg_replace('/[^a-z0-9_-]/', '', (string)($page['slug'] ?? 'admin-login')) ?: 'admin-login';
$titulo = trim((string)($page['title'] ?? 'Acesso restrito'));
$docTitle = ($titulo !== '' ? $titulo . ' · ' : '') . 'Painel · ' . Config::APP_NAME;

$styles = ['fonts/fonts.css', 'css/tokens.css', 'css/base.css', 'css/components.css',
           'css/logo.css', 'css/admin.css', 'css/admin/' . $slug . '.css'];
$scripts = ['js/admin.js', 'js/admin/' . $slug . '.js'];

$preloadFonts = ['barlow-condensed-5.woff2', 'barlow-2.woff2', 'material-symbols-0.woff2'];
?>
<!DOCTYPE html>
<?php /* Mesmo contrato do layout admin: escuro vem do servidor, o script do <head>
         corrige para a escolha salva antes da primeira pintura. A porta do painel
         precisa disso tanto quanto o painel — quem trabalha no claro não quer
         levar uma tela preta na cara toda vez que a sessão expira. */ ?>
<html lang="pt-BR" data-tema="escuro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($docTitle) ?></title>
<script nonce="<?= e($nonce) ?>">
(function(){
  var h = document.documentElement, t;
  try { t = localStorage.getItem('eh.admin.tema'); } catch (e) { t = null; }
  if (t !== 'claro' && t !== 'escuro') {
    t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? 'claro' : 'escuro';
  }
  h.setAttribute('data-tema', t);
})();
</script>
<meta name="color-scheme" content="dark light">
<meta name="theme-color" content="#070907">
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e($csrf ?? '') ?>">
<link rel="icon" type="image/png" href="<?= e(img('logo/emblem.png')) ?>">
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
<body class="adm-body adm-auth-body page-<?= e($slug) ?>" data-page="<?= e($slug) ?>" data-base="<?= e(Config::baseUrl()) ?>" data-nonce="<?= e($nonce) ?>">
<?php /* O mesmo overlay das telas internas (admin.css §1.0): a porta do painel não
         pode ser mais chapada do que o painel. */ ?>
<div class="adm-bg" aria-hidden="true">
  <span class="adm-bg__glow"></span>
  <span class="adm-bg__grid"></span>
  <span class="adm-bg__grain"></span>
  <span class="adm-bg__vig"></span>
</div>
<div class="adm-tema" role="group" aria-label="Tema do painel">
  <button class="adm-tema__opt" type="button" data-adm-tema="escuro"
          aria-pressed="false" title="Modo escuro"><?= icon('dark_mode') ?><span class="sr-only">Modo escuro</span></button>
  <button class="adm-tema__opt" type="button" data-adm-tema="claro"
          aria-pressed="false" title="Modo claro"><?= icon('light_mode') ?><span class="sr-only">Modo claro</span></button>
</div>
<main class="adm-auth" id="main">
<?php if (!empty($flash) && is_array($flash)): ?>
  <div class="adm-flashes adm-auth__flashes" role="status" aria-live="polite">
    <?php foreach ($flash as $kind => $msg): ?>
      <?php if (is_string($msg) && $msg !== ''): ?>
        <?php $k = preg_replace('/[^a-z0-9_-]/', '', (string)$kind) ?: 'info'; ?>
        <div class="adm-flash adm-flash--<?= e($k) ?>" data-flash data-autohide="<?= $k === 'error' ? 9000 : 5000 ?>">
          <?= icon($k === 'error' ? 'error' : ($k === 'ok' ? 'check_circle' : 'info')) ?><span><?= e($msg) ?></span>
          <button class="adm-flash__x" type="button" data-adm-close aria-label="Fechar"><?= icon('close') ?></button>
        </div>
      <?php endif ?>
    <?php endforeach ?>
  </div>
<?php endif ?>
<?= $content ?>
</main>
<?php foreach ($scripts as $js): ?>
<?php if (is_file($assets . '/' . $js)): ?>
<script type="module" nonce="<?= e($nonce) ?>" src="<?= e(asset($js)) ?>"></script>
<?php endif; ?>
<?php endforeach; ?>
</body>
</html>
