<?php declare(strict_types=1);
/**
 * Tela CONQUISTAS (referência 09-conquistas.png).
 *
 * $data: title, progress_title, done, total, sort_label, sorts[], categories_title, categories[],
 *        filter{}, status_labels{}, items[], active_category, selected, pagination{}, detail{}, sort
 * Escopo da view: $page, $player, $nav, $server, $data, $flash, $csrf.
 */

$cats     = (array)($data['categories'] ?? []);
$items    = (array)($data['items'] ?? []);
$labels   = (array)($data['status_labels'] ?? []);
$detail   = (array)($data['detail'] ?? []);
$sorts    = (array)($data['sorts'] ?? []);
$filter   = (array)($data['filter'] ?? []);
$pag      = (array)($data['pagination'] ?? []);
$catSlug  = (string)($data['active_category'] ?? 'todas');
$sortSlug = (string)($data['sort'] ?? 'recentes');
$selected = (array)($data['selected'] ?? ($items[0] ?? []));
$selSlug  = (string)($selected['slug'] ?? '');

$done  = (int)($data['done'] ?? 0);
$total = (int)($data['total'] ?? 1);
$pct   = $total > 0 ? (int)floor(100 * $done / $total) : 0;

$base    = 'conquistas' . ($catSlug !== 'todas' ? '/' . $catSlug : '');
$sortNow = 'MAIS RECENTES';
foreach ($sorts as $s) {
    if (($s['slug'] ?? '') === $sortSlug) { $sortNow = (string)$s['label']; }
}

/** Rótulo de estado do card / do detalhe. */
$stateLabel = static function (array $a) use ($labels): string {
    return (string)($labels[(string)($a['status'] ?? 'locked')] ?? '');
};
?>
<header class="page-head">
  <h1 class="page-title"><?= e((string)($data['title'] ?? 'CONQUISTAS')) ?></h1>
</header>

<div class="cq">

  <!-- ====================== CATEGORIAS ====================== -->
  <section class="panel cq-cats" data-boot>
    <h2 class="cq-cats__title"><?= e((string)($data['categories_title'] ?? 'CATEGORIAS')) ?></h2>
    <nav class="cq-cats__list" aria-label="<?= e((string)($data['categories_title'] ?? 'Categorias')) ?>">
      <?php foreach ($cats as $c): ?>
      <?php $on = ((string)$c['slug'] === $catSlug); ?>
      <a class="cat-row<?= $on ? ' is-active' : '' ?>" href="<?= e(url('conquistas/' . (string)$c['slug'])) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
        <?= icon((string)$c['icon'], 'cat-row__ic') ?>
        <span class="cat-row__label"><?= e((string)$c['label']) ?></span>
        <span class="cat-row__meta num"><?= e((string)$c['done']) ?><i>/</i><?= e((string)$c['total']) ?></span>
      </a>
      <?php endforeach; ?>
    </nav>
    <button class="cq-filter" type="button" data-filter aria-pressed="false"
            data-label-off="<?= e((string)($filter['label'] ?? 'FILTRAR')) ?>"
            data-label-on="<?= e((string)($filter['label_on'] ?? 'MOSTRAR TODAS')) ?>">
      <span class="cq-filter__key num"><?= e((string)($filter['key'] ?? 'R')) ?></span>
      <span class="cq-filter__label"><?= e((string)($filter['label'] ?? 'FILTRAR')) ?></span>
      <?= icon((string)($filter['icon'] ?? 'filter_alt'), 'cq-filter__ic') ?>
    </button>
  </section>

  <!-- ====================== CENTRO ====================== -->
  <div class="cq__main">

    <header class="cq-head" data-boot>
      <div class="cq-prog">
        <div class="cq-prog__top">
          <span class="cq-prog__title"><?= e((string)($data['progress_title'] ?? 'PROGRESSO GERAL')) ?></span>
          <span class="cq-prog__val num"><?= e((string)$done) ?>/<?= e((string)$total) ?> (<?= e((string)$pct) ?>%)</span>
        </div>
        <?php component('progress', ['pct' => $pct, 'anim' => true, 'class' => 'cq-prog__bar']) ?>
      </div>

      <div class="cq-sort" data-sort>
        <button class="cq-sort__btn" type="button" aria-haspopup="listbox" aria-expanded="false">
          <span class="cq-sort__text"><?= e((string)($data['sort_label'] ?? 'ORDENAR:')) ?> <b><?= e($sortNow) ?></b></span>
          <?= icon('expand_more', 'cq-sort__chev') ?>
        </button>
        <div class="cq-sort__menu" role="listbox" hidden>
          <?php foreach ($sorts as $s): ?>
          <a class="cq-sort__opt<?= ((string)$s['slug'] === $sortSlug) ? ' is-active' : '' ?>" role="option"
             aria-selected="<?= ((string)$s['slug'] === $sortSlug) ? 'true' : 'false' ?>"
             href="<?= e(url($base, ['ordem' => (string)$s['slug']])) ?>"><?= e((string)$s['label']) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    </header>

    <div class="cq-grid" data-stagger>
      <?php foreach ($items as $a): ?>
      <?php
        $st  = (string)($a['status'] ?? 'locked');
        $on  = ((string)$a['slug'] === $selSlug);
        $col = (string)($a['color'] ?? 'muted');
      ?>
      <button class="ach ach--<?= e($st) ?><?= $on ? ' is-selected' : '' ?>" type="button"
              data-ach="<?= e((string)$a['slug']) ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>">
        <?php component('hex', ['size' => 'md', 'color' => $col, 'icon' => (string)$a['icon'], 'class' => 'ach__hex', 'flat' => $st !== 'done']) ?>
        <span class="ach__title"><?= e((string)$a['title']) ?></span>
        <span class="ach__desc"><?= e((string)$a['desc']) ?></span>
        <span class="ach__foot">
          <?php if ($st === 'done'): ?>
          <b class="ach__state"><?= e($stateLabel($a)) ?></b>
          <i class="ach__date num"><?= e((string)($a['date'] ?? '')) ?></i>
          <?php elseif ($st === 'progress'): ?>
          <span class="ach__meter">
            <?php component('progress', ['value' => (int)$a['cur'], 'max' => (int)$a['max'], 'anim' => true, 'class' => 'ach__meter-bar']) ?>
            <i class="ach__meter-val num"><?= e((string)$a['cur']) ?>/<?= e((string)$a['max']) ?></i>
          </span>
          <?php else: ?>
          <b class="ach__state ach__state--locked"><?= e($stateLabel($a)) ?></b>
          <?php endif; ?>
        </span>
      </button>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ====================== PAGINAÇÃO ====================== -->
  <nav class="cq-pager" aria-label="Paginação das conquistas">
    <?php
      $cur   = (int)($pag['current'] ?? 1);
      $pages = (int)($pag['pages'] ?? 1);
      $vis   = (array)($pag['visible'] ?? [1]);
    ?>
    <?php if ($cur > 1): ?>
    <a class="pg pg--arrow" href="<?= e(url($base, ['ordem' => $sortSlug, 'pagina' => $cur - 1])) ?>" aria-label="Página anterior"><?= icon('chevron_left') ?></a>
    <?php else: ?>
    <span class="pg pg--arrow is-off" aria-hidden="true"><?= icon('chevron_left') ?></span>
    <?php endif; ?>

    <?php foreach ($vis as $n): ?>
    <?php $isCur = ((int)$n === $cur); ?>
    <a class="pg num<?= $isCur ? ' is-active' : '' ?>" href="<?= e(url($base, ['ordem' => $sortSlug, 'pagina' => (int)$n])) ?>"<?= $isCur ? ' aria-current="page"' : '' ?>><?= e((string)$n) ?></a>
    <?php endforeach; ?>

    <?php if ($pages > count($vis) + 1): ?>
    <span class="pg pg--gap" aria-hidden="true">…</span>
    <?php endif; ?>
    <?php if ($pages > count($vis)): ?>
    <a class="pg num" href="<?= e(url($base, ['ordem' => $sortSlug, 'pagina' => $pages])) ?>"><?= e((string)$pages) ?></a>
    <?php endif; ?>

    <a class="pg pg--arrow" href="<?= e(url($base, ['ordem' => $sortSlug, 'pagina' => min($pages, $cur + 1)])) ?>" aria-label="Próxima página"><?= icon('chevron_right') ?></a>
  </nav>

  <!-- ====================== DETALHE ====================== -->
  <aside class="panel cq-detail" data-boot>
    <div class="cq-detail__stack">
      <?php foreach ($items as $a): ?>
      <?php
        $st  = (string)($a['status'] ?? 'locked');
        $on  = ((string)$a['slug'] === $selSlug);
        $col = (string)($a['color'] ?? 'muted');
        $rw  = (array)($a['rewards'] ?? []);
        $it  = (array)($a['reward_item'] ?? []);
        $stateKey = $st === 'done' ? 'done_label' : ($st === 'progress' ? 'progress_label' : 'locked_label');
      ?>
      <article class="det det--<?= e($st) ?><?= $on ? ' is-on' : '' ?>" data-detail="<?= e((string)$a['slug']) ?>"<?= $on ? '' : ' hidden' ?>>

        <header class="det__head">
          <?php component('hex', ['size' => 'xl', 'color' => $col, 'icon' => (string)$a['icon'], 'class' => 'det__hex', 'flat' => true]) ?>
          <span class="det__id">
            <h2 class="det__title"><?= e((string)$a['title']) ?></h2>
            <span class="det__state"><?= e((string)($detail[$stateKey] ?? '')) ?></span>
          </span>
        </header>

        <p class="det__desc"><?= e((string)$a['desc']) ?></p>

        <hr class="divider det__rule">

        <h3 class="det__section"><?= e((string)($detail['rewards_title'] ?? 'RECOMPENSAS')) ?></h3>
        <div class="det__rewards">
          <?php foreach ($rw as $r): ?>
          <?php $t = (string)($r['type'] ?? 'xp'); ?>
          <span class="rw rw--<?= e($t) ?>">
            <span class="rw__badge"><?php if ($t === 'xp'): ?><span class="rw__xp">XP</span><?php else: ?><?= svg($t === 'cash' ? 'coin-cash' : 'coin-points', 'rw__ic') ?><?php endif; ?></span>
            <span class="rw__val num"><?= e((string)($r['text'] ?? '')) ?></span>
          </span>
          <?php endforeach; ?>
        </div>

        <?php if ($it !== []): ?>
        <div class="det__item">
          <span class="det__item-media"><img class="det__item-img" src="<?= e(media((string)$it['image'])) ?>" alt="" loading="lazy" decoding="async"></span>
          <span class="det__item-text">
            <strong class="det__item-name"><?= e((string)$it['name']) ?></strong>
            <small class="det__item-desc"><?= e((string)$it['desc']) ?></small>
          </span>
        </div>
        <?php endif; ?>

        <?php if ($st === 'done'): ?>
        <h3 class="det__section"><?= e((string)($detail['unlocked_title'] ?? 'DESBLOQUEADA EM')) ?></h3>
        <p class="det__date num"><?= e((string)($a['unlocked_at'] ?? '')) ?></p>
        <?php elseif ($st === 'progress'): ?>
        <h3 class="det__section"><?= e((string)($detail['progress_title'] ?? 'PROGRESSO ATUAL')) ?></h3>
        <div class="det__meter">
          <?php component('progress', ['value' => (int)$a['cur'], 'max' => (int)$a['max'], 'anim' => true, 'size' => 'thick']) ?>
          <span class="det__meter-val num"><?= e((string)$a['cur']) ?><small>/<?= e((string)$a['max']) ?></small></span>
        </div>
        <?php else: ?>
        <h3 class="det__section"><?= e((string)($detail['locked_title'] ?? 'REQUISITO')) ?></h3>
        <p class="det__date det__date--locked"><?= e((string)($detail['locked_text'] ?? '')) ?></p>
        <?php endif; ?>

        <hr class="divider det__rule det__rule--tail">

        <button class="det__cta" type="button" data-track aria-pressed="false"
                data-label-off="<?= e((string)($detail['cta']['label'] ?? 'ACOMPANHAR')) ?>"
                data-label-on="<?= e((string)($detail['cta']['label_on'] ?? 'ACOMPANHANDO')) ?>">
          <?= icon((string)($detail['cta']['icon'] ?? 'star'), 'fill det__cta-ic') ?>
          <span class="det__cta-label"><?= e((string)($detail['cta']['label'] ?? 'ACOMPANHAR')) ?></span>
        </button>

      </article>
      <?php endforeach; ?>
    </div>
  </aside>

</div>
