<?php declare(strict_types=1);
/**
 * Tabs — component('tabs', $props)
 *
 * props:
 *  items  : array  lista de abas: ['label'=>'TRATAMENTO', 'href'=>url('saude'), 'active'=>bool,
 *                  'panel'=>'tratamento' (data-tab => mostra [data-tab-panel="tratamento"] via hud.js),
 *                  'icon'=>'medical_services', 'count'=>'12', 'attrs'=>[]]
 *  active : string  alternativa: label/panel da aba ativa
 *  fill   : bool    .tabs--fill (abas dividem a largura)
 *  pills  : bool    .tabs--pills
 *  class  : string
 *  label  : string  aria-label da <nav>
 *
 * Abas com 'href' navegam (server-side); abas com 'panel' trocam painéis no cliente.
 */
$p = $props ?? [];
$items = (array)($p['items'] ?? []);
$cls = 'tabs';
if (!empty($p['fill'])) { $cls .= ' tabs--fill'; }
if (!empty($p['pills'])) { $cls .= ' tabs--pills'; }
if (!empty($p['class'])) { $cls .= ' ' . $p['class']; }
$activeKey = (string)($p['active'] ?? '');
?>
<nav class="<?= e($cls) ?>" aria-label="<?= e((string)($p['label'] ?? 'Abas')) ?>">
<?php foreach ($items as $it):
    $label = (string)($it['label'] ?? '');
    $panel = (string)($it['panel'] ?? '');
    $isActive = !empty($it['active']) || ($activeKey !== '' && ($activeKey === $label || $activeKey === $panel));
    $href = (string)($it['href'] ?? '');
    $a = '';
    if ($panel !== '') { $a .= ' data-tab="' . e($panel) . '"'; }
    foreach (($it['attrs'] ?? []) as $k => $v) {
        if (preg_match('/^[a-zA-Z][\w:-]*$/', (string)$k)) { $a .= ' ' . e($k) . '="' . e((string)$v) . '"'; }
    }
?>
  <?php // aba que navega = <a>; aba que só troca painel no cliente = <button> (sem href="#") ?>
  <?php if ($href !== ''): ?>
  <a class="tab<?= $isActive ? ' is-active' : '' ?>" href="<?= e($href) ?>"<?= $a ?><?= $isActive ? ' aria-current="page"' : '' ?>><?php
    if (!empty($it['icon'])) { echo icon((string)$it['icon']); }
    echo e($label);
    if (isset($it['count']) && $it['count'] !== '') { ?> <span class="tab__count num"><?= e((string)$it['count']) ?></span><?php }
  ?></a>
  <?php else: ?>
  <button type="button" class="tab<?= $isActive ? ' is-active' : '' ?>"<?= $a ?> aria-selected="<?= $isActive ? 'true' : 'false' ?>"><?php
    if (!empty($it['icon'])) { echo icon((string)$it['icon']); }
    echo e($label);
    if (isset($it['count']) && $it['count'] !== '') { ?> <span class="tab__count num"><?= e((string)$it['count']) ?></span><?php }
  ?></button>
  <?php endif; ?>
<?php endforeach; ?>
</nav>
