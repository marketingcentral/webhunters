<?php declare(strict_types=1);
/**
 * Range slider — component('range', $props)
 *
 * props:
 *  name   : string
 *  id     : string
 *  min    : int|float (default 0)
 *  max    : int|float (default 100)
 *  step   : int|float (default 1)
 *  value  : int|float (default 50)
 *  suffix : string    texto após o valor no .range__val (default '%')
 *  dec    : int       casas decimais na exibição (default 0)
 *  wide   : bool      .range--wide (100%)
 *  show   : bool      mostrar .range__val (default true)
 *  label  : string    aria-label
 *  class  : string
 *  attrs  : array
 *
 * Renderiza <span class="range-wrap"><input class="range"> <span class="range__val num"></span></span>.
 * hud.js atualiza o valor e a variável --v (preenchimento do trilho).
 */
$p = $props ?? [];
$min = (float)($p['min'] ?? 0);
$max = (float)($p['max'] ?? 100);
$step = (float)($p['step'] ?? 1);
$value = (float)($p['value'] ?? 50);
$suffix = (string)($p['suffix'] ?? '%');
$dec = (int)($p['dec'] ?? 0);
$pct = $max > $min ? (($value - $min) / ($max - $min)) * 100 : 0;
$fmt = static fn(float $n): string => $n === floor($n) && $dec === 0 ? (string)(int)$n : rtrim(rtrim(number_format($n, max($dec, 2), '.', ''), '0'), '.');
$cls = 'range';
if (!empty($p['wide'])) { $cls .= ' range--wide'; }
if (!empty($p['class'])) { $cls .= ' ' . $p['class']; }
$attrs = ' data-suffix="' . e($suffix) . '"' . ($dec ? ' data-dec="' . $dec . '"' : '');
if (!empty($p['name'])) { $attrs .= ' name="' . e((string)$p['name']) . '"'; }
if (!empty($p['id'])) { $attrs .= ' id="' . e((string)$p['id']) . '"'; }
if (!empty($p['label'])) { $attrs .= ' aria-label="' . e((string)$p['label']) . '"'; }
foreach (($p['attrs'] ?? []) as $k => $v) {
    if (preg_match('/^[a-zA-Z][\w:-]*$/', (string)$k)) { $attrs .= ' ' . e($k) . '="' . e((string)$v) . '"'; }
}
?>
<span class="range-wrap<?= !empty($p['wide']) ? ' w-full' : '' ?>">
  <input type="range" class="<?= e($cls) ?>" min="<?= e($fmt($min)) ?>" max="<?= e($fmt($max)) ?>" step="<?= e($fmt($step)) ?>" value="<?= e($fmt($value)) ?>" style="--v:<?= e(number_format($pct, 2, '.', '')) ?>%"<?= $attrs ?>>
<?php if ($p['show'] ?? true): ?>
  <span class="range__val num"><?= e(num($value, $dec)) . e($suffix) ?></span>
<?php endif; ?>
</span>
