<?php declare(strict_types=1);
/**
 * Panel — component('panel', $props)
 *
 * props:
 *  title   : string        título (uppercase via CSS). Vazio = sem header
 *  icon    : string        ligadura Material Symbols antes do título (opcional)
 *  meta    : string        texto pequeno à direita do título (ex.: '1 / 4') — escapado
 *  action  : array|null    ['label'=>'VER TODOS','href'=>url('x'),'icon'=>'add','lime'=>bool]
 *  body    : string        HTML JÁ RENDERIZADO (confiável — a página monta com ob_start/ob_get_clean)
 *  foot    : string        HTML já renderizado do rodapé (.panel__foot), opcional
 *  hot     : bool          .panel--hot (borda lime + glow)
 *  hover   : bool          .panel--hover
 *  flush   : bool          .panel--flush (body sem padding)
 *  grow    : bool          .panel--grow (flex:1 dentro de .col)
 *  solid   : bool          .panel--solid
 *  tight   : bool          body com padding reduzido
 *  class   : string        classes extras
 *  id      : string
 *  attrs   : array         atributos extras
 *  tag     : string        'section' (default) | 'div' | 'article' | 'aside'
 *
 * Uso típico sem 'body': escreva o markup direto (ver SPEC §7). Este componente é
 * um atalho para painéis simples.
 */
$p = $props ?? [];
$tag = in_array($p['tag'] ?? 'section', ['section', 'div', 'article', 'aside', 'form'], true) ? $p['tag'] : 'section';
$cls = 'panel';
foreach (['hot', 'hover', 'flush', 'grow', 'solid'] as $m) { if (!empty($p[$m])) { $cls .= ' panel--' . $m; } }
if (!empty($p['class'])) { $cls .= ' ' . $p['class']; }
$attrs = '';
if (!empty($p['id'])) { $attrs .= ' id="' . e($p['id']) . '"'; }
foreach (($p['attrs'] ?? []) as $k => $v) {
    if (preg_match('/^[a-zA-Z][\w:-]*$/', (string)$k)) { $attrs .= ' ' . e($k) . '="' . e((string)$v) . '"'; }
}
$action = $p['action'] ?? null;
$bodyCls = 'panel__body' . (!empty($p['tight']) ? ' panel__body--tight' : '') . (!empty($p['body_class']) ? ' ' . $p['body_class'] : '');
?>
<<?= $tag ?> class="<?= e($cls) ?>"<?= $attrs ?>>
<?php if (!empty($p['title']) || $action): ?>
  <header class="panel__head">
    <h2 class="panel__title"><?php if (!empty($p['icon'])) { echo icon((string)$p['icon']); } ?><?= e((string)($p['title'] ?? '')) ?><?php if (!empty($p['meta'])): ?> <small class="num"><?= e((string)$p['meta']) ?></small><?php endif; ?></h2>
<?php if (is_array($action) && !empty($action['label'])): ?>
    <a class="panel__action<?= !empty($action['lime']) ? ' panel__action--lime' : '' ?>" href="<?= e($action['href'] ?? '#') ?>"><?= e((string)$action['label']) ?><?php if (!empty($action['icon'])) { echo icon((string)$action['icon']); } ?></a>
<?php endif; ?>
  </header>
<?php endif; ?>
  <div class="<?= e($bodyCls) ?>"><?= $p['body'] ?? '' ?></div>
<?php if (!empty($p['foot'])): ?>
  <footer class="panel__foot"><?= $p['foot'] ?></footer>
<?php endif; ?>
</<?= $tag ?>>
