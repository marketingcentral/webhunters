<?php declare(strict_types=1);
/**
 * Carousel — component('carousel', $props)
 *
 * props:
 *  slides   : string[]  HTML JÁ RENDERIZADO de cada slide (confiável; a página monta via ob_start)
 *  interval : int       ms de autoplay (default 5000; 0 = sem autoplay)
 *  dots     : bool      mostrar dots (default true)
 *  arrows   : bool      botões prev/next (default false)
 *  over     : bool      dots sobre o conteúdo (.carousel--dots-over)
 *  fixed    : bool      data-height="fixed" (não ajusta altura ao slide)
 *  id       : string
 *  class    : string
 *  label    : string    aria-label
 *
 * carousel.js: autoplay, dots, prev/next, pausa em hover/foco, swipe, loop.
 */
$p = $props ?? [];
$slides = (array)($p['slides'] ?? []);
$cls = 'carousel';
if (!empty($p['over'])) { $cls .= ' carousel--dots-over'; }
if (!empty($p['class'])) { $cls .= ' ' . $p['class']; }
?>
<div class="<?= e($cls) ?>" data-carousel data-interval="<?= (int)($p['interval'] ?? 5000) ?>"<?= !empty($p['fixed']) ? ' data-height="fixed"' : '' ?><?= !empty($p['id']) ? ' id="' . e((string)$p['id']) . '"' : '' ?> aria-label="<?= e((string)($p['label'] ?? 'Carrossel')) ?>" tabindex="0">
  <div class="carousel__track">
<?php foreach ($slides as $s): ?>
    <div class="carousel__slide"><?= $s ?></div>
<?php endforeach; ?>
  </div>
<?php if (!empty($p['arrows'])): ?>
  <button class="carousel__prev ms" type="button" aria-label="Anterior">chevron_left</button>
  <button class="carousel__next ms" type="button" aria-label="Próximo">chevron_right</button>
<?php endif; ?>
<?php if ($p['dots'] ?? true): ?>
  <div class="carousel__dots" aria-hidden="true"></div>
<?php endif; ?>
</div>
