<?php declare(strict_types=1);
/**
 * Inline sprite fallback — partial('icons')
 *
 * Optional. Inlines assets/icons/sprite.svg (display:none) so that
 * <svg class="ic"><use href="#id"/></svg> works without an external fetch
 * (useful under strict CSP or file:// previews). svg() helper normally points
 * at the external sprite; both work when this partial is included.
 */
$spritePath = dirname(__DIR__, 3) . '/assets/icons/sprite.svg';
if (is_file($spritePath)) {
    $svg = (string)file_get_contents($spritePath);
    // strip xml prolog / comments; keep the <svg> element
    $svg = preg_replace('/<\?xml[^>]*\?>/', '', $svg) ?? $svg;
    $svg = preg_replace('/<!--.*?-->/s', '', $svg) ?? $svg;
    echo trim($svg), "\n";
}
