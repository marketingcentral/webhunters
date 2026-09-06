<?php declare(strict_types=1);
/**
 * Progress bar — component('progress', $props)
 *
 * props:
 *  value  : int|float   valor atual (default 0)
 *  max    : int|float   máximo (default 100) — percentual = value/max
 *  pct    : int|float   alternativa: percentual direto (0..100), ignora value/max
 *  color  : 'lime'|'cyan'|'amber'|'gold'|'red'|'purple'|'white' (default lime)
 *  size   : 'thin'|'thick'|'xl'|'' (default '' = .3rem)
 *  anim   : bool        .progress--anim (preenche no load)
 *  seg    : bool        ticks segmentados
 *  flat   : bool        sem glow
 *  label  : string      se presente, envolve numa .progress-row com cabeçalho "LABEL   value/max"
 *  show   : bool        mostrar número (default true quando label presente); formato num()
 *  suffix : string      texto após o valor (ex.: '%'); se vazio e max!=100, mostra "value/max"
 *  class  : string
 *  attrs  : array
 */
$p = $props ?? [];
$value = (float)($p['value'] ?? 0);
$max = (float)($p['max'] ?? 100);
$pct = isset($p['pct']) ? (float)$p['pct'] : ($max > 0 ? ($value / $max) * 100 : 0);
$pct = max(0.0, min(100.0, $pct));
$colors = ['cyan', 'amber', 'gold', 'red', 'purple', 'white'];
$cls = 'progress';
if (in_array($p['color'] ?? '', $colors, true)) { $cls .= ' progress--' . $p['color']; }
if (in_array($p['size'] ?? '', ['thin', 'thick', 'xl'], true)) { $cls .= ' progress--' . $p['size']; }
if (!empty($p['anim'])) { $cls .= ' progress--anim'; }
if (!empty($p['seg'])) { $cls .= ' progress--seg'; }
if (!empty($p['flat'])) { $cls .= ' progress--flat'; }
if (!empty($p['class'])) { $cls .= ' ' . $p['class']; }
$attrs = '';
foreach (($p['attrs'] ?? []) as $k => $v) {
    if (preg_match('/^[a-zA-Z][\w:-]*$/', (string)$k)) { $attrs .= ' ' . e($k) . '="' . e((string)$v) . '"'; }
}
$pctStr = rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') . '%';
$bar = '<div class="' . e($cls) . '" style="--v:' . e($pctStr) . '" role="progressbar" aria-valuemin="0" aria-valuemax="' . e((string)$max) . '" aria-valuenow="' . e((string)$value) . '"' . $attrs . '><div class="progress__bar"></div></div>';
?>
<?php if (!empty($p['label'])): ?>
<div class="progress-row">
  <div class="progress-row__head">
    <span class="progress-row__label"><?= e((string)$p['label']) ?></span>
<?php if ($p['show'] ?? true): ?>
    <span class="progress-row__val num"><?php
      if (isset($p['suffix']) && $p['suffix'] !== '') { echo e(num($value)) . e((string)$p['suffix']); }
      else { echo e(num($value)) . '<small>/' . e(num($max)) . '</small>'; }
    ?></span>
<?php endif; ?>
  </div>
  <?= $bar ?>
</div>
<?php else: ?>
<?= $bar ?>
<?php endif; ?>
