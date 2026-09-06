<?php declare(strict_types=1);
/**
 * Logo (F3).  component('logo', ['variant' => 'hero'|'auth'|'mark', 'href' => url('inicio')|null, 'class' => ''])
 *   hero : marca completa (arte) + tagline com filetes   - Home, topo-esquerda
 *   auth : marca completa (arte), sem tagline            - login/criar-conta/recuperar-senha
 *   mark : só o emblema (caveira+asas)                   - usos pequenos/quadrados
 *
 * hero/auth usam a arte em media/logo/logo-final.png (PNG com alfa + WebP), servida por media().
 * CSS: assets/css/logo.css
 */
$props   = $props ?? [];
$variant = in_array($props['variant'] ?? 'hero', ['hero', 'auth', 'mark'], true) ? $props['variant'] : 'hero';
$href    = $props['href'] ?? null;
$extra   = trim((string)($props['class'] ?? ''));
$tag     = $href ? 'a' : 'div';
$classes = 'logo logo--' . $variant . ($extra !== '' ? ' ' . $extra : '');
$label   = 'Extract Hunters';

// a arte é larga: a versão -sm cobre usos pequenos sem baixar 900KB à toa
$file = $variant === 'hero' ? 'logo/logo-final' : 'logo/logo-final-sm';
?>
<<?= $tag ?> class="<?= e($classes) ?>"<?= $href ? ' href="' . e((string)$href) . '"' : '' ?> role="img" aria-label="<?= e($label) ?>">
<?php if ($variant === 'mark'): ?>
  <span class="logo__emblem" aria-hidden="true"></span>
<?php else: ?>
  <picture class="logo__art">
    <source srcset="<?= e(media($file . '.webp')) ?>" type="image/webp">
    <img src="<?= e(media($file . '.png')) ?>" alt="" decoding="async"
         <?= $variant === 'hero' ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
  </picture>
  <?php if ($variant === 'hero'): ?>
  <span class="logo__tag" aria-hidden="true"><span>Sobreviva. Extraia. Evolua.</span></span>
  <?php endif ?>
<?php endif ?>
</<?= $tag ?>>
