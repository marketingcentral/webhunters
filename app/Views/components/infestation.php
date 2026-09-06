<?php declare(strict_types=1);
/**
 * Medidor de infestação Veyra — component('infestation', $props)   (VeyraInfestationMeter, PRD §37)
 *
 * Barra de `progress` + rótulo da escala de GameRules::INFESTATION (BAIXA … CRÍTICA), para a
 * mesma leitura aparecer igual na Home, no Jogar, no Mapa e no Lobby.
 *
 * props:
 *  value   : int     0–100
 *  label   : string  cabeçalho (default 'INFESTAÇÃO VEYRA'); '' esconde
 *  compact : bool    só número + rótulo, sem barra (cards apertados)
 *  class   : string
 */

use App\Core\GameRules;

$p     = $props ?? [];
$pct   = max(0, min(100, (int)($p['value'] ?? 0)));
$esc   = GameRules::infestation($pct);
$head  = array_key_exists('label', $p) ? (string)$p['label'] : 'INFESTAÇÃO VEYRA';
$cls   = trim('infest infest--' . e($esc['color']) . (!empty($p['compact']) ? ' infest--compact' : '') . ' ' . (string)($p['class'] ?? ''));
?>
<div class="<?= $cls ?>" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string)$pct) ?>" aria-label="<?= e($head !== '' ? $head : 'Infestação Veyra') ?>">
<?php if ($head !== ''): ?>
  <span class="infest__label"><?= e($head) ?></span>
<?php endif; ?>
  <span class="infest__val num"><?= e((string)$pct) ?><small>%</small></span>
  <span class="infest__state"><?= e($esc['label']) ?></span>
<?php if (empty($p['compact'])): ?>
  <?php component('progress', ['value' => $pct, 'max' => 100, 'color' => $esc['color'] === 'lime' ? '' : $esc['color'], 'anim' => true, 'class' => 'infest__bar']) ?>
<?php endif; ?>
</div>
