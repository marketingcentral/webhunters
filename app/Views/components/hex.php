<?php declare(strict_types=1);
/**
 * Hex badge — component('hex', $props)
 *
 * props:
 *  size   : 'sm'|'md'|'lg'|'xl'|'xxl'          (default 'md')
 *  text   : string|int   texto (número de nível, etc.) — renderizado com .num
 *  icon   : string       ligadura Material Symbols (ex.: 'skull')
 *  svg    : string       id do sprite (ex.: 'emblem-hex') — tem prioridade sobre icon
 *  hot    : bool         borda lime sólida + glow forte
 *  ring   : bool         anel interno duplo (visual das conquistas)
 *  color  : 'lime'|'gold'|'cyan'|'amber'|'red'|'purple'|'muted'|'white' (default lime)
 *  flat   : bool         sem glow
 *  class  : string       classes extras
 *  title  : string       atributo title/aria-label
 *  attrs  : array        atributos extras (chave => valor), escapados
 */
$p = $props ?? [];
$sizes = ['sm', 'md', 'lg', 'xl', 'xxl'];
$colors = ['lime', 'gold', 'cyan', 'amber', 'red', 'purple', 'muted', 'white'];
$size = in_array($p['size'] ?? 'md', $sizes, true) ? $p['size'] : 'md';
$color = in_array($p['color'] ?? 'lime', $colors, true) ? $p['color'] : 'lime';
$cls = 'hex hex--' . $size;
if ($color !== 'lime') { $cls .= ' hex--' . $color; }
if (!empty($p['hot'])) { $cls .= ' hex--hot'; }
if (!empty($p['ring'])) { $cls .= ' hex--ring'; }
if (!empty($p['flat'])) { $cls .= ' hex--flat'; }
if (isset($p['text']) && $p['text'] !== '') { $cls .= ' hex--text'; }
if (!empty($p['class'])) { $cls .= ' ' . $p['class']; }
$attrs = '';
foreach (($p['attrs'] ?? []) as $k => $v) {
    if (preg_match('/^[a-zA-Z][\w:-]*$/', (string)$k)) { $attrs .= ' ' . e($k) . '="' . e((string)$v) . '"'; }
}
if (!empty($p['title'])) { $attrs .= ' title="' . e($p['title']) . '" aria-label="' . e($p['title']) . '"'; }
?>
<span class="<?= e($cls) ?>"<?= $attrs ?>><span class="hex__in"><?php
    if (!empty($p['svg'])) { echo svg((string)$p['svg']); }
    elseif (!empty($p['icon'])) { echo icon((string)$p['icon']); }
    elseif (isset($p['text']) && $p['text'] !== '') { ?><span class="num"><?= e((string)$p['text']) ?></span><?php }
?></span></span>
