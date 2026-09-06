<?php declare(strict_types=1);
/**
 * Background stack — partial('background')
 *
 * Reads $page['bg'] (ex.: 'bg/home-hero.jpg' | null) and $page['bg_pos'] (object-position).
 * Emits: .bg > picture > img.bg__img + .bg__overlay + canvas#atmosphere + .bg__grain + .bg__vignette
 * When both .webp and .jpg exist, a <picture> with the webp source is emitted.
 * Also accepts $bg / $bg_pos passed directly via partial('background', ['bg'=>…]).
 */
$bgPath = (string)($bg ?? ($page['bg'] ?? ''));
$bgPos = (string)($bg_pos ?? ($page['bg_pos'] ?? 'center'));
$bgPos = preg_match('/^[a-z0-9% .-]{1,40}$/i', $bgPos) ? $bgPos : 'center';
$imgDir = dirname(__DIR__, 3) . '/assets/img/';
$webp = '';
if ($bgPath !== '' && preg_match('/\.(jpe?g|png)$/i', $bgPath)) {
    $candidate = preg_replace('/\.(jpe?g|png)$/i', '.webp', $bgPath);
    if (is_file($imgDir . $candidate)) { $webp = $candidate; }
}
$hasImg = $bgPath !== '' && is_file($imgDir . $bgPath);
$atmosphere = $page['three']['atmosphere'] ?? true;
?>
<div class="bg<?= $hasImg ? '' : ' bg--plain' ?>" aria-hidden="true" style="--bg-pos:<?= e($bgPos) ?>">
<?php if ($hasImg): ?>
  <picture>
<?php if ($webp !== ''): ?>
    <source srcset="<?= e(img($webp)) ?>" type="image/webp">
<?php endif; ?>
    <img class="bg__img" src="<?= e(img($bgPath)) ?>" alt="" fetchpriority="high" decoding="async" style="object-position:<?= e($bgPos) ?>">
  </picture>
<?php endif; ?>
  <div class="bg__overlay"></div>
<?php if ($atmosphere): ?>
  <canvas id="atmosphere" class="bg__fx"></canvas>
<?php endif; ?>
  <div class="bg__grain"></div>
  <div class="bg__vignette"></div>
</div>
