<?php declare(strict_types=1);
/**
 * List row — component('row', $props)
 *
 * props:
 *  title   : string   texto principal (uppercase via CSS)
 *  sub     : string   texto secundário
 *  icon    : string   ligadura Material Symbols (.row__icon)
 *  svg     : string   id do sprite (prioridade sobre icon)
 *  img     : string   caminho em assets/img (via img()) — avatar redondo; 'img_sq'=>true para quadrado
 *  hex     : array    props do componente hex para usar como ícone (ex.: ['icon'=>'skull','size'=>'sm'])
 *  meta    : string   valor à direita (.row__meta.num) ex.: '8/15'
 *  meta_class : string classes extras no meta (ex.: 'lime')
 *  chev    : bool     chevron à direita
 *  lock    : bool     cadeado à direita (+ .is-locked)
 *  active  : bool     .is-active
 *  href    : string   se presente, <a>; senão <div> (ou <button> se 'button'=>true)
 *  size    : 'lg'|'sm'|''
 *  flat    : bool     .row--flat
 *  value   : string   data-value (grupos de seleção)
 *  ctrl    : string   HTML já renderizado do controle à direita (toggle/stepper/range) — troca meta
 *  tip     : string   data-tip
 *  class   : string
 *  attrs   : array
 */
$p = $props ?? [];
$cls = 'row';
if (in_array($p['size'] ?? '', ['lg', 'sm'], true)) { $cls .= ' row--' . $p['size']; }
if (!empty($p['flat'])) { $cls .= ' row--flat'; }
if (!empty($p['active'])) { $cls .= ' is-active'; }
if (!empty($p['lock'])) { $cls .= ' is-locked'; }
if (!empty($p['class'])) { $cls .= ' ' . $p['class']; }
$attrs = '';
if (isset($p['value'])) { $attrs .= ' data-value="' . e((string)$p['value']) . '"'; }
if (!empty($p['tip'])) { $attrs .= ' data-tip="' . e((string)$p['tip']) . '"'; }
if (!empty($p['active'])) { $attrs .= ' aria-current="true"'; }
foreach (($p['attrs'] ?? []) as $k => $v) {
    if (preg_match('/^[a-zA-Z][\w:-]*$/', (string)$k)) { $attrs .= ' ' . e($k) . '="' . e((string)$v) . '"'; }
}
$tag = !empty($p['href']) ? 'a' : (!empty($p['button']) ? 'button' : 'div');
if ($tag === 'a') { $attrs .= ' href="' . e((string)$p['href']) . '"'; }
if ($tag === 'button') { $attrs .= ' type="button"'; }
?>
<<?= $tag ?> class="<?= e($cls) ?>"<?= $attrs ?>>
<?php if (!empty($p['hex']) && is_array($p['hex'])): ?>
  <span class="row__icon"><?php component('hex', $p['hex']) ?></span>
<?php elseif (!empty($p['img'])): ?>
  <img class="row__img<?= !empty($p['img_sq']) ? ' row__img--sq' : '' ?>" src="<?= e(img((string)$p['img'])) ?>" alt="" loading="lazy">
<?php elseif (!empty($p['svg'])): ?>
  <?= svg((string)$p['svg'], 'row__icon') ?>
<?php elseif (!empty($p['icon'])): ?>
  <span class="row__icon ms" aria-hidden="true"><?= e((string)$p['icon']) ?></span>
<?php endif; ?>
  <span class="row__text"><strong><?= e((string)($p['title'] ?? '')) ?></strong><?php if (!empty($p['sub'])): ?><small><?= e((string)$p['sub']) ?></small><?php endif; ?></span>
<?php if (!empty($p['ctrl'])): ?>
  <span class="row__ctrl"><?= $p['ctrl'] ?></span>
<?php elseif (isset($p['meta']) && $p['meta'] !== ''): ?>
  <span class="row__meta num<?= !empty($p['meta_class']) ? ' ' . e((string)$p['meta_class']) : '' ?>"><?= e((string)$p['meta']) ?></span>
<?php endif; ?>
<?php if (!empty($p['lock'])): ?>
  <span class="ms row__lock" aria-hidden="true">lock</span>
<?php elseif (!empty($p['chev'])): ?>
  <span class="ms row__chev" aria-hidden="true">chevron_right</span>
<?php endif; ?>
</<?= $tag ?>>
