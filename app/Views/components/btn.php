<?php declare(strict_types=1);
/**
 * Button — component('btn', $props)
 *
 * props:
 *  label    : string   texto (uppercase via CSS)
 *  href     : string   se presente renderiza <a>, senão <button>
 *  type     : 'button'|'submit'|'reset' (default 'button'; só para <button>)
 *  variant  : 'primary'|'gold'|'ghost'|'outline'|'lime'|'danger'|'' (default '' = padrão escuro)
 *  size     : 'lg'|'sm'|'xs'|''
 *  icon     : string   ligadura Material Symbols à esquerda
 *  svg      : string   id do sprite à esquerda (prioridade sobre icon)
 *  chev     : bool     chevron_right à direita (.btn__chev)
 *  block    : bool     largura total (.btn--block)
 *  active   : bool     .is-active
 *  disabled : bool
 *  badge    : string|int   contador (.btn__badge) — ex.: notificações
 *  tip      : string   data-tip (tooltip)
 *  class    : string
 *  id, name, value : string
 *  attrs    : array    atributos extras (data-*, aria-*, target, form...)
 */
$p = $props ?? [];
$variants = ['primary', 'gold', 'ghost', 'outline', 'lime', 'danger'];
$sizes = ['lg', 'sm', 'xs'];
$cls = 'btn';
if (in_array($p['variant'] ?? '', $variants, true)) { $cls .= ' btn--' . $p['variant']; }
if (in_array($p['size'] ?? '', $sizes, true)) { $cls .= ' btn--' . $p['size']; }
if (!empty($p['block'])) { $cls .= ' btn--block'; }
if (!empty($p['active'])) { $cls .= ' is-active'; }
if (empty($p['label']) && (!empty($p['icon']) || !empty($p['svg']))) { $cls .= ' btn--icon'; }
if (!empty($p['class'])) { $cls .= ' ' . $p['class']; }
$attrs = '';
foreach (['id', 'name', 'value', 'form'] as $k) { if (isset($p[$k]) && $p[$k] !== '') { $attrs .= ' ' . $k . '="' . e((string)$p[$k]) . '"'; } }
if (!empty($p['tip'])) { $attrs .= ' data-tip="' . e($p['tip']) . '"'; }
foreach (($p['attrs'] ?? []) as $k => $v) {
    if (preg_match('/^[a-zA-Z][\w:-]*$/', (string)$k)) { $attrs .= ' ' . e($k) . ($v === true ? '' : '="' . e((string)$v) . '"'); }
}
$isLink = !empty($p['href']) && empty($p['disabled']);
// o ?? só protegia a condição do ternário; o ramo verdadeiro relia a chave ausente
$type = (string)($p['type'] ?? 'button');
if (!in_array($type, ['button', 'submit', 'reset'], true)) { $type = 'button'; }
$inner = '';
if (!empty($p['svg'])) { $inner .= svg((string)$p['svg']); }
elseif (!empty($p['icon'])) { $inner .= icon((string)$p['icon']); }
if (!empty($p['label'])) { $inner .= '<span class="btn__label">' . e((string)$p['label']) . '</span>'; }
if (isset($p['badge']) && $p['badge'] !== '' && $p['badge'] !== null) { $inner .= '<span class="btn__badge num">' . e((string)$p['badge']) . '</span>'; }
if (!empty($p['chev'])) { $inner .= icon('chevron_right', 'btn__chev'); }
?>
<?php if ($isLink): ?>
<a class="<?= e($cls) ?>" href="<?= e((string)$p['href']) ?>"<?= $attrs ?>><?= $inner ?></a>
<?php else: ?>
<button class="<?= e($cls) ?>" type="<?= e($type) ?>"<?= !empty($p['disabled']) ? ' disabled' : '' ?><?= $attrs ?>><?= $inner ?></button>
<?php endif; ?>
