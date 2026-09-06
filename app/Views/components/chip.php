<?php declare(strict_types=1);
/**
 * Chip / Tag — component('chip', $props)
 *
 * props:
 *  label : string
 *  color : 'lime'|'gold'|'cyan'|'amber'|'red'|'purple'|'muted'|'' (default '' = neutro)
 *  icon  : string   ligadura Material Symbols
 *  svg   : string   id do sprite
 *  solid : bool     fundo sólido na cor
 *  dot   : bool     ponto colorido antes do texto
 *  lg    : bool     .chip--lg
 *  class : string
 *  tip   : string   data-tip
 */
$p = $props ?? [];
$cls = 'chip';
if (in_array($p['color'] ?? '', ['lime', 'gold', 'cyan', 'amber', 'red', 'purple', 'muted'], true)) { $cls .= ' chip--' . $p['color']; }
if (!empty($p['solid'])) { $cls .= ' chip--solid'; }
if (!empty($p['dot'])) { $cls .= ' chip--dot'; }
if (!empty($p['lg'])) { $cls .= ' chip--lg'; }
if (!empty($p['class'])) { $cls .= ' ' . $p['class']; }
?>
<span class="<?= e($cls) ?>"<?= !empty($p['tip']) ? ' data-tip="' . e((string)$p['tip']) . '"' : '' ?>><?php
  if (!empty($p['svg'])) { echo svg((string)$p['svg']); }
  elseif (!empty($p['icon'])) { echo icon((string)$p['icon']); }
?><?= e((string)($p['label'] ?? '')) ?></span>
