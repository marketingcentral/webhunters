<?php declare(strict_types=1);
/**
 * Item slot — component('slot', $props)
 *
 * props:
 *  name   : string   nome do item (canto superior esquerdo) — 'name_right'=>true para o canto direito
 *  sub    : string   texto mono pequeno sob o nome (ex.: calibre '7.62x39')
 *  img    : string   caminho em assets/img (ex.: 'items/ak103.png') via img()
 *  alt    : string
 *  count  : string   contador mono no canto inferior direito (ex.: '30/30')
 *  meta   : string   texto mono canto superior direito (ex.: '02')
 *  key    : string   tecla de atalho (caixinha .slot__key, canto superior esquerdo)
 *  wide   : bool     .slot--wide (2 colunas)
 *  tall   : bool     .slot--tall (2 linhas)
 *  empty  : bool     .slot--empty (tracejado)
 *  color  : 'lime'|'amber'|'cyan'|'red'|'white'  cor do contador (default lime)
 *  active : bool     .is-active
 *  href   : string   <a> em vez de <div>
 *  tip    : string   data-tip
 *  value  : string   data-value
 *  class  : string
 *  attrs  : array
 */
$p = $props ?? [];
$cls = 'slot';
if (!empty($p['wide'])) { $cls .= ' slot--wide'; }
if (!empty($p['tall'])) { $cls .= ' slot--tall'; }
if (!empty($p['empty'])) { $cls .= ' slot--empty'; }
if (!empty($p['active'])) { $cls .= ' is-active'; }
if (in_array($p['color'] ?? '', ['amber', 'cyan', 'red', 'white'], true)) { $cls .= ' slot--' . $p['color']; }
if (!empty($p['class'])) { $cls .= ' ' . $p['class']; }
$attrs = '';
if (!empty($p['tip'])) { $attrs .= ' data-tip="' . e((string)$p['tip']) . '"'; }
if (isset($p['value'])) { $attrs .= ' data-value="' . e((string)$p['value']) . '"'; }
foreach (($p['attrs'] ?? []) as $k => $v) {
    if (preg_match('/^[a-zA-Z][\w:-]*$/', (string)$k)) { $attrs .= ' ' . e($k) . '="' . e((string)$v) . '"'; }
}
$tag = !empty($p['href']) ? 'a' : 'div';
if ($tag === 'a') { $attrs .= ' href="' . e((string)$p['href']) . '"'; }
?>
<<?= $tag ?> class="<?= e($cls) ?>"<?= $attrs ?>>
<?php if (isset($p['key']) && $p['key'] !== ''): ?>
  <span class="slot__key num"><?= e((string)$p['key']) ?></span>
<?php endif; ?>
<?php if (!empty($p['name'])): ?>
  <span class="slot__name<?= !empty($p['name_right']) ? ' slot__name--r' : '' ?>"><?= e((string)$p['name']) ?></span>
<?php endif; ?>
<?php if (!empty($p['sub'])): ?>
  <span class="slot__sub"><?= e((string)$p['sub']) ?></span>
<?php endif; ?>
<?php if (isset($p['meta']) && $p['meta'] !== ''): ?>
  <span class="slot__meta num"><?= e((string)$p['meta']) ?></span>
<?php endif; ?>
<?php if (!empty($p['img']) && empty($p['empty'])): ?>
  <img class="slot__img" src="<?= e(img((string)$p['img'])) ?>" alt="<?= e((string)($p['alt'] ?? $p['name'] ?? '')) ?>" loading="lazy" draggable="false">
<?php endif; ?>
<?php if (isset($p['count']) && $p['count'] !== ''): ?>
  <span class="slot__count num"><?= e((string)$p['count']) ?></span>
<?php endif; ?>
</<?= $tag ?>>
