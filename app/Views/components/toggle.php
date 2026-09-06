<?php declare(strict_types=1);
/**
 * Toggle — component('toggle', $props)
 *
 * props:
 *  name     : string   name do checkbox (value="1")
 *  id       : string
 *  checked  : bool
 *  on       : string   rótulo quando ligado  (default 'ATIVADO')
 *  off      : string   rótulo quando desligado (default 'DESATIVADO')
 *  label    : bool     mostrar rótulo (default true). false = só o track
 *  lime     : bool     rótulo lime quando ligado (.toggle--lime)
 *  right    : bool     rótulo à direita do track (.toggle--label-right)
 *  sm       : bool     .toggle--sm
 *  disabled : bool
 *  class    : string
 *  attrs    : array    atributos extras no <label>
 *
 * hud.js troca o texto do rótulo (on/off) ao mudar.
 */
$p = $props ?? [];
$on = (string)($p['on'] ?? 'ATIVADO');
$off = (string)($p['off'] ?? 'DESATIVADO');
$checked = !empty($p['checked']);
$cls = 'toggle';
if (!empty($p['lime'])) { $cls .= ' toggle--lime'; }
if (!empty($p['right'])) { $cls .= ' toggle--label-right'; }
if (!empty($p['sm'])) { $cls .= ' toggle--sm'; }
if (!empty($p['class'])) { $cls .= ' ' . $p['class']; }
$attrs = ' data-on="' . e($on) . '" data-off="' . e($off) . '"';
foreach (($p['attrs'] ?? []) as $k => $v) {
    if (preg_match('/^[a-zA-Z][\w:-]*$/', (string)$k)) { $attrs .= ' ' . e($k) . '="' . e((string)$v) . '"'; }
}
?>
<label class="<?= e($cls) ?>"<?= $attrs ?>>
  <input type="checkbox" value="1"<?= !empty($p['name']) ? ' name="' . e((string)$p['name']) . '"' : '' ?><?= !empty($p['id']) ? ' id="' . e((string)$p['id']) . '"' : '' ?><?= $checked ? ' checked' : '' ?><?= !empty($p['disabled']) ? ' disabled' : '' ?> role="switch" aria-checked="<?= $checked ? 'true' : 'false' ?>">
  <span class="toggle__track" aria-hidden="true"></span>
<?php if ($p['label'] ?? true): ?>
  <span class="toggle__label"><?= e($checked ? $on : $off) ?></span>
<?php endif; ?>
</label>
