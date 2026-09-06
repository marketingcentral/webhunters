<?php declare(strict_types=1);
/**
 * Stepper — component('stepper', $props)
 *
 * props:
 *  options : string[]  lista de opções (ex.: ['FÁCIL','NORMAL','MÉDIA','DIFÍCIL','EXTREMO'])
 *  value   : string    opção atual (default: primeira)
 *  name    : string    se presente, adiciona <input type="hidden" name=…> sincronizado pelo hud.js
 *  loop    : bool      volta ao início no fim (default true)
 *  boxed   : bool      .stepper--boxed (com borda)
 *  class   : string
 *  attrs   : array
 *
 * hud.js: cicla as opções (data-options), atualiza .stepper__val e dispara 'stepper:change'.
 */
$p = $props ?? [];
$options = array_values(array_map('strval', (array)($p['options'] ?? [])));
$value = (string)($p['value'] ?? ($options[0] ?? ''));
$cls = 'stepper';
if (!empty($p['boxed'])) { $cls .= ' stepper--boxed'; }
if (!empty($p['class'])) { $cls .= ' ' . $p['class']; }
$attrs = ' data-options="' . e(implode('|', $options)) . '" data-value="' . e($value) . '"';
if (isset($p['loop']) && !$p['loop']) { $attrs .= ' data-loop="false"'; }
foreach (($p['attrs'] ?? []) as $k => $v) {
    if (preg_match('/^[a-zA-Z][\w:-]*$/', (string)$k)) { $attrs .= ' ' . e($k) . '="' . e((string)$v) . '"'; }
}
?>
<div class="<?= e($cls) ?>"<?= $attrs ?>>
  <button class="stepper__btn ms" type="button" aria-label="Anterior">chevron_left</button>
  <span class="stepper__val"><?= e($value) ?></span>
  <button class="stepper__btn ms" type="button" aria-label="Próximo">chevron_right</button>
<?php if (!empty($p['name'])): ?>
  <input type="hidden" name="<?= e((string)$p['name']) ?>" value="<?= e($value) ?>">
<?php endif; ?>
</div>
