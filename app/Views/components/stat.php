<?php declare(strict_types=1);
/**
 * Stat — component('stat', $props)
 *
 * props:
 *  label    : string        'SAÚDE'
 *  value    : int|float|string  valor principal (formatado com num() se numérico)
 *  max      : int|float     se presente mostra <small>/max</small>
 *  unit     : string        sufixo (ex.: '°C', 'kg', 'dB', '%') — mostrado em <small>
 *  dec      : int           casas decimais (default 0)
 *  icon     : string        ligadura Material Symbols
 *  svg      : string        id do sprite
 *  color    : 'lime'|'cyan'|'amber'|'red'|'gold'|'purple'|'white' (default lime)
 *  progress : bool|float    true = barra value/max; número = percentual
 *  caption  : string        legenda pequena abaixo (ex.: 'Totalmente saudável')
 *  variant  : ''|'inline'|'tile'|'hero'
 *  class    : string
 *  attrs    : array
 */
$p = $props ?? [];
$colors = ['cyan', 'amber', 'red', 'gold', 'purple', 'white'];
$cls = 'stat';
if (in_array($p['color'] ?? '', $colors, true)) { $cls .= ' stat--' . $p['color']; }
if (in_array($p['variant'] ?? '', ['inline', 'tile', 'hero'], true)) { $cls .= ' stat--' . $p['variant']; }
if (!empty($p['class'])) { $cls .= ' ' . $p['class']; }
$attrs = '';
foreach (($p['attrs'] ?? []) as $k => $v) {
    if (preg_match('/^[a-zA-Z][\w:-]*$/', (string)$k)) { $attrs .= ' ' . e($k) . '="' . e((string)$v) . '"'; }
}
$dec = (int)($p['dec'] ?? 0);
$raw = $p['value'] ?? '';
$valStr = is_numeric($raw) ? num((float)$raw, $dec) : (string)$raw;
$progress = $p['progress'] ?? null;
$pct = null;
if ($progress === true && isset($p['max']) && (float)$p['max'] > 0 && is_numeric($raw)) { $pct = ((float)$raw / (float)$p['max']) * 100; }
elseif (is_numeric($progress)) { $pct = (float)$progress; }
$progColor = in_array($p['color'] ?? '', ['cyan', 'amber', 'red', 'gold', 'purple', 'white'], true) ? $p['color'] : 'lime';
?>
<div class="<?= e($cls) ?>"<?= $attrs ?>>
<?php if (!empty($p['svg'])): ?>
  <span class="stat__icon"><?= svg((string)$p['svg']) ?></span>
<?php elseif (!empty($p['icon'])): ?>
  <span class="stat__icon ms" aria-hidden="true"><?= e((string)$p['icon']) ?></span>
<?php endif; ?>
  <span class="stat__label"><?= e((string)($p['label'] ?? '')) ?></span>
  <span class="stat__val num"><?= e($valStr) ?><?php
    if (isset($p['max'])) { ?><small>/<?= e(num((float)$p['max'])) ?></small><?php }
    if (!empty($p['unit'])) { ?><small><?= e((string)$p['unit']) ?></small><?php }
  ?></span>
<?php if ($pct !== null): ?>
  <div class="stat__bar"><?php component('progress', ['pct' => $pct, 'color' => $progColor, 'anim' => true]) ?></div>
<?php endif; ?>
<?php if (!empty($p['caption'])): ?>
  <span class="stat__cap"><?= e((string)$p['caption']) ?></span>
<?php endif; ?>
</div>
