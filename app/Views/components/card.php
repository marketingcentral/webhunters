<?php declare(strict_types=1);
/**
 * Card (mapa / notícia / item / conquista) — component('card', $props)
 *
 * props:
 *  title    : string
 *  sub      : string   linha secundária uppercase (ex.: modo de jogo)
 *  desc     : string   descrição em Barlow (ex.: notícia/conquista)
 *  meta     : string   texto mono (ex.: '6v6', data)
 *  img      : string   caminho em assets/img (via img()); 'alt' opcional
 *  ratio    : string   '16/10' (default) | '4/3' | '1' | '21/9' — custom property --card-ratio
 *  hex      : array    props do hex (badge no corpo, à esquerda do título — visual dos mapas)
 *  icon     : string   ligadura Material Symbols dentro de um hex padrão (atalho para hex)
 *  chip     : array    props do chip (badge sobre a imagem, canto superior esquerdo)
 *  status   : string   texto de status no rodapé (ex.: 'CONCLUÍDA'); status_locked=>true para cinza
 *  date     : string   texto mono à direita do status
 *  progress : array    props do componente progress (rodapé)
 *  selected : bool     .is-selected (borda lime + check)
 *  locked   : bool     .is-locked
 *  hot      : bool     .card--hot
 *  ach      : bool     .card--ach (conquista: hex grande em cima, sem imagem)
 *  h        : bool     .card--h (horizontal)
 *  href     : string   <a> em vez de <article>
 *  value    : string   data-value (grupos de seleção)
 *  body     : string   HTML já renderizado extra no corpo
 *  class    : string
 *  attrs    : array
 */
$p = $props ?? [];
$cls = 'card';
if (!empty($p['selected'])) { $cls .= ' is-selected'; }
if (!empty($p['locked'])) { $cls .= ' is-locked'; }
if (!empty($p['hot'])) { $cls .= ' card--hot'; }
if (!empty($p['ach'])) { $cls .= ' card--ach'; }
if (!empty($p['h'])) { $cls .= ' card--h'; }
if (!empty($p['class'])) { $cls .= ' ' . $p['class']; }
$attrs = '';
if (isset($p['value'])) { $attrs .= ' data-value="' . e((string)$p['value']) . '"'; }
if (!empty($p['ratio']) && preg_match('#^[0-9.]+(/[0-9.]+)?$#', (string)$p['ratio'])) { $attrs .= ' style="--card-ratio:' . e((string)$p['ratio']) . '"'; }
foreach (($p['attrs'] ?? []) as $k => $v) {
    if (preg_match('/^[a-zA-Z][\w:-]*$/', (string)$k)) { $attrs .= ' ' . e($k) . '="' . e((string)$v) . '"'; }
}
$tag = !empty($p['href']) ? 'a' : 'article';
if ($tag === 'a') { $attrs .= ' href="' . e((string)$p['href']) . '"'; }
$hex = $p['hex'] ?? (!empty($p['icon']) ? ['icon' => (string)$p['icon'], 'size' => !empty($p['ach']) ? 'xl' : 'md', 'ring' => !empty($p['ach']), 'hot' => !empty($p['ach']) && !empty($p['selected'])] : null);
?>
<<?= $tag ?> class="<?= e($cls) ?>"<?= $attrs ?><?= !empty($p['selected']) ? ' aria-selected="true"' : '' ?>>
<?php if (!empty($p['img'])): ?>
  <div class="card__media">
    <img src="<?= e(img((string)$p['img'])) ?>" alt="<?= e((string)($p['alt'] ?? $p['title'] ?? '')) ?>" loading="lazy">
<?php if (!empty($p['chip']) && is_array($p['chip'])): ?>
    <span class="card__badge"><?php component('chip', $p['chip']) ?></span>
<?php endif; ?>
  </div>
<?php endif; ?>
  <div class="card__body<?= (is_array($hex) && empty($p['ach'])) ? ' card__body--row' : '' ?>">
<?php if (is_array($hex)): ?>
    <?php component('hex', $hex) ?>
<?php endif; ?>
<?php if (is_array($hex) && empty($p['ach'])): ?>
    <div class="grid gap-1 min-0">
<?php endif; ?>
<?php if (!empty($p['title'])): ?>
      <h3 class="card__title"><?= e((string)$p['title']) ?></h3>
<?php endif; ?>
<?php if (!empty($p['sub'])): ?>
      <span class="card__sub"><?= e((string)$p['sub']) ?></span>
<?php endif; ?>
<?php if (!empty($p['desc'])): ?>
      <p class="card__desc"><?= e((string)$p['desc']) ?></p>
<?php endif; ?>
<?php if (isset($p['meta']) && $p['meta'] !== ''): ?>
      <span class="card__meta num"><?= e((string)$p['meta']) ?></span>
<?php endif; ?>
<?php if (is_array($hex) && empty($p['ach'])): ?>
    </div>
<?php endif; ?>
<?= $p['body'] ?? '' ?>
<?php if (!empty($p['progress']) && is_array($p['progress'])): ?>
    <div class="card__foot"><div class="w-full"><?php component('progress', $p['progress']) ?></div></div>
<?php elseif (!empty($p['status']) || !empty($p['date'])): ?>
    <div class="card__foot">
      <span class="card__status<?= !empty($p['status_locked']) ? ' card__status--locked' : '' ?>"><?= e((string)($p['status'] ?? '')) ?></span>
<?php if (!empty($p['date'])): ?>
      <span class="card__meta num"><?= e((string)$p['date']) ?></span>
<?php endif; ?>
    </div>
<?php endif; ?>
  </div>
</<?= $tag ?>>
