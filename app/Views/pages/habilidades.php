<?php declare(strict_types=1);
/**
 * Tela HABILIDADES (referência 07-habilidades.png).
 *
 * $data: title, points_label, points, redistribute, reset_category, unlocked_label, level_label,
 *        categories[], tiers[], trees[], tree (4 x 5 nós), active_category, category,
 *        selected_by_category[], selected.
 * Escopo da view: $page, $player, $nav, $server, $data, $flash, $csrf.
 */

$cats     = (array)($data['categories'] ?? []);
$active   = (string)($data['active_category'] ?? 'combate');
$category = (array)($data['category'] ?? []);
$tiers    = (array)($data['tiers'] ?? ['I', 'II', 'III', 'IV']);
$tree     = (array)($data['tree'] ?? []);
$sel      = (array)($data['selected'] ?? []);

/* --- geometria da árvore (px da referência 1672x941) ----------------------- */
$NW = 84.0; $NH = 81.0; $CP = 150.5; $RP = 155.6667;
$rows = max(1, count($tree));
$cols = 5;
$vbW  = ($cols - 1) * $CP + $NW;                 // 686
$vbH  = ($rows - 1) * $RP + $NH;                 // 548
$colX = static fn(int $i): float => $i * $CP;
$ctrX = static fn(int $i): float => $i * $CP + 42.0;
$rowY = static fn(int $j): float => $j * $RP;

$stateOf = static function (array $tree, int $j, int $i): string {
    return (string)($tree[$j][$i]['state'] ?? 'locked');
};
$fmt = static fn(float $n): string => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');

/* --- conectores SVG (setas horizontais, trilhos e descidas verticais) ------ */
$off = []; $on = []; $rail = [];
$AH = 6.4; $AW = 3.2;                              // ponta de seta (~20% menor que antes)
$arrowR = static function (float $x1, float $x2, float $y) use ($fmt, $AH, $AW): string {
    $tip = $x2; $b = $x2 - $AH;
    return '<line x1="' . $fmt($x1) . '" y1="' . $fmt($y) . '" x2="' . $fmt($b + 1) . '" y2="' . $fmt($y) . '"/>'
         . '<polygon points="' . $fmt($tip) . ',' . $fmt($y) . ' ' . $fmt($b) . ',' . $fmt($y - $AW) . ' ' . $fmt($b) . ',' . $fmt($y + $AW) . '"/>';
};
$arrowD = static function (float $x, float $y1, float $y2) use ($fmt, $AH, $AW): string {
    $tip = $y2; $b = $y2 - $AH;
    return '<line x1="' . $fmt($x) . '" y1="' . $fmt($y1) . '" x2="' . $fmt($x) . '" y2="' . $fmt($b + 1) . '"/>'
         . '<polygon points="' . $fmt($x) . ',' . $fmt($tip) . ' ' . $fmt($x - $AW) . ',' . $fmt($b) . ' ' . $fmt($x + $AW) . ',' . $fmt($b) . '"/>';
};

for ($j = 0; $j < $rows; $j++) {
    $y = $rowY($j) + $NH / 2;
    for ($i = 0; $i < $cols - 1; $i++) {
        $lit = $stateOf($tree, $j, $i) !== 'locked' && $stateOf($tree, $j, $i + 1) !== 'locked';
        $seg = $arrowR($colX($i) + $NW + 6, $colX($i + 1) - 6, $y);
        if ($lit) { $on[] = $seg; } else { $off[] = $seg; }
    }
    if ($j < $rows - 1) {
        $railY = $rowY($j + 1) - 32.0;
        $rail[] = '<line x1="' . $fmt($ctrX(0)) . '" y1="' . $fmt($railY) . '" x2="' . $fmt($ctrX($cols - 1)) . '" y2="' . $fmt($railY) . '"/>';
        for ($i = 0; $i < $cols; $i++) {
            $node  = (array)($tree[$j][$i] ?? []);
            $state = $stateOf($tree, $j, $i);
            $lit   = $state !== 'locked' && $stateOf($tree, $j + 1, $i) !== 'locked';
            $start = (int)($node['cost'] ?? 0) > 0 && $state === 'locked'
                ? $railY
                : $rowY($j) + $NH + ($state === 'progress' ? 15.0 : 5.0);
            $seg = $arrowD($ctrX($i), $start, $rowY($j + 1) - 5.0);
            if ($lit) { $on[] = $seg; } else { $off[] = $seg; }
        }
    }
}
?>
<div class="hab">

  <!-- ====================== CATEGORIAS ====================== -->
  <section class="panel hab-cats" data-boot>
    <header class="panel__head">
      <h2 class="panel__title"><?= e((string)($data['title'] ?? 'CATEGORIAS')) ?></h2>
    </header>
    <div class="panel__body">

      <nav class="cat-list" data-stagger aria-label="Categorias de habilidade">
        <?php foreach ($cats as $c): ?>
        <?php component('row', [
            'title'  => (string)$c['label'],
            'sub'    => (string)$c['desc'],
            'icon'   => (string)$c['icon'],
            'meta'   => $c['unlocked'] . '/' . $c['total'],
            'active' => ((string)$c['slug'] === $active),
            'href'   => url('habilidades/' . (string)$c['slug']),
            'class'  => 'cat-row cat-row--' . (string)$c['slug'],
        ]) ?>
        <?php endforeach; ?>
      </nav>

      <div class="points" data-points="<?= e((string)(int)($data['points'] ?? 0)) ?>">
        <span class="points__label"><?= e((string)($data['points_label'] ?? 'PONTOS DISPONÍVEIS')) ?></span>
        <span class="points__val num" id="sk-points" data-count="<?= e((string)(int)($data['points'] ?? 0)) ?>" data-dur="900">0</span>
        <?php component('hex', ['size' => 'sm', 'color' => 'lime', 'icon' => 'keyboard_double_arrow_up', 'flat' => true, 'class' => 'points__hex']) ?>
      </div>

      <form class="hab-form" method="post" action="<?= e(url('habilidades/redefinir')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="escopo" value="tudo">
        <button class="key-btn key-btn--wide" type="submit"<?= (int)($data['learned_all'] ?? 0) > 0 ? '' : ' disabled aria-disabled="true" title="Nenhuma habilidade aprendida"' ?>>
          <span class="keycap"><?= e((string)($data['redistribute']['key'] ?? 'R')) ?></span>
          <span class="key-btn__label"><?= e((string)($data['redistribute']['label'] ?? 'REDISTRIBUIR')) ?></span>
        </button>
      </form>

    </div>
  </section>

  <!-- ====================== ÁRVORE ====================== -->
  <section class="panel hab-tree" data-boot>
    <header class="tree-head">
      <div class="tree-head__id">
        <h1 class="page-title"><?= e((string)($category['label'] ?? '')) ?></h1>
        <p class="tree-head__sub">
          <span class="num lime"><?= e((string)($category['unlocked'] ?? 0)) ?><i class="num-sep"> / </i><?= e((string)($category['total'] ?? 0)) ?></span>
          <?= e((string)($data['unlocked_label'] ?? 'HABILIDADES DESBLOQUEADAS')) ?>
        </p>
      </div>
      <form class="hab-form" method="post" action="<?= e(url('habilidades/redefinir')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="escopo" value="categoria">
        <input type="hidden" name="categoria" value="<?= e($active) ?>">
        <button class="key-btn key-btn--rev" type="submit"<?= (int)($data['learned'] ?? 0) > 0 ? '' : ' disabled aria-disabled="true" title="Nenhuma habilidade aprendida nesta categoria"' ?>>
          <span class="key-btn__label"><?= e((string)($data['reset_category']['label'] ?? 'REDEFINIR CATEGORIA')) ?></span>
          <span class="keycap"><?= e((string)($data['reset_category']['key'] ?? 'R')) ?></span>
        </button>
      </form>
    </header>

    <div class="tree-wrap">
      <ol class="tier-col" aria-hidden="true">
        <?php foreach ($tiers as $t): ?><li class="tier-col__n"><?= e((string)$t) ?></li><?php endforeach; ?>
      </ol>

      <div class="tree-frame">
        <div class="tree-grid" data-stagger style="--tree-w:<?= e($fmt($vbW / 16)) ?>rem">

          <svg class="tree-links" viewBox="0 0 <?= e($fmt($vbW)) ?> <?= e($fmt($vbH)) ?>" preserveAspectRatio="none" aria-hidden="true" focusable="false">
            <g class="lnk lnk--rail"><?= implode('', $rail) ?></g>
            <g class="lnk lnk--off"><?= implode('', $off) ?></g>
            <g class="lnk lnk--on"><?= implode('', $on) ?></g>
          </svg>

          <?php foreach ($tree as $j => $row): ?>
          <?php foreach ($row as $i => $n): ?>
          <?php
            $state = (string)($n['state'] ?? 'locked');
            $cost  = (int)($n['cost'] ?? 0);
            $isSel = ((string)($n['slug'] ?? '') === (string)($sel['slug'] ?? '')) || !empty($n['selected']);
          ?>
          <button
            class="sk-node sk-node--<?= e($state) ?><?= $isSel ? ' is-selected' : '' ?>"
            type="button"
            data-node="<?= e((string)$n['slug']) ?>"
            data-name="<?= e((string)$n['name']) ?>"
            data-state="<?= e($state) ?>"
            data-cur="<?= e((string)(int)$n['cur']) ?>"
            data-max="<?= e((string)(int)$n['max']) ?>"
            data-cost="<?= e((string)$cost) ?>"
            data-desc="<?= e((string)($n['desc'] ?? '')) ?>"
            data-available="<?= empty($n['available']) ? '0' : '1' ?>"
            title="<?= e((string)$n['name']) ?>"
            aria-pressed="<?= $isSel ? 'true' : 'false' ?>"
            style="grid-area:<?= e((string)($j + 1)) ?>/<?= e((string)($i + 1)) ?>">
            <span class="sk-node__box">
              <?php $ni = (string)$n['icon']; ?>
              <?= str_starts_with($ni, 'sk-') ? svg($ni, 'sk-node__ic sk-node__ic--svg') : icon($ni, 'sk-node__ic') ?>
              <span class="sk-node__count num"><?= e((string)(int)$n['cur']) ?>/<?= e((string)(int)$n['max']) ?></span>
              <?php if ($state !== 'unlocked'): ?>
              <span class="sk-node__lock" aria-hidden="true"><?= icon('lock') ?></span>
              <?php endif; ?>
            </span>
            <span class="sk-node__name sr-only"><?= e((string)$n['name']) ?></span>
            <?php if ($state === 'progress'): ?>
            <span class="sk-node__bar" aria-hidden="true">
              <?php for ($k = 0; $k < (int)$n['max']; $k++): ?>
              <i class="sk-node__seg<?= $k < (int)$n['cur'] ? ' is-on' : '' ?>" style="--k:<?= e((string)$k) ?>"></i>
              <?php endfor; ?>
            </span>
            <?php endif; ?>
            <?php if ($cost > 0 && $state === 'locked'): ?>
            <span class="sk-node__cost">
              <?= svg('coin-points', 'sk-node__cost-ic') ?>
              <span class="num"><?= e((string)$cost) ?></span>
            </span>
            <?php endif; ?>
          </button>
          <?php endforeach; ?>
          <?php endforeach; ?>

        </div>
      </div>
    </div>
  </section>

  <!-- ====================== DETALHE ====================== -->
  <aside class="panel hab-detail" data-boot>
    <header class="panel__head">
      <h2 class="panel__title" id="sk-name"><?= e((string)($sel['name'] ?? '')) ?></h2>
      <p class="sk-level">
        <?= e((string)($data['level_label'] ?? 'NÍVEL')) ?>
        <span class="num" id="sk-level"><?= e((string)($sel['level'] ?? 0)) ?><i class="num-sep"> / </i><?= e((string)($sel['max'] ?? 0)) ?></span>
      </p>
    </header>
    <div class="panel__body">

      <figure class="sk-shot">
        <img class="sk-shot__img" id="sk-img" src="<?= e(media((string)($sel['image'] ?? 'ui/skill-preview.jpg'))) ?>" alt="<?= e((string)($sel['name'] ?? '')) ?>" decoding="async">
        <span class="sk-shot__shade" aria-hidden="true"></span>
      </figure>

      <p class="sk-desc" id="sk-desc"><?php
        $t = e((string)($sel['desc'] ?? ''));
        $h = e((string)($sel['desc_highlight'] ?? ''));
        $p = $h !== '' ? strpos($t, $h) : false;
        echo $p === false ? $t : substr($t, 0, $p) . '<span class="lime">' . $h . '</span>' . substr($t, $p + strlen($h));
      ?></p>

      <h3 class="sk-next-title"><?= e((string)($sel['next_title'] ?? 'PRÓXIMO NÍVEL')) ?></h3>
      <p class="sk-next" id="sk-next"><?php
        $t = e((string)($sel['next'] ?? ''));
        $h = e((string)($sel['next_highlight'] ?? ''));
        $p = $h !== '' ? strpos($t, $h) : false;
        echo $p === false ? $t : substr($t, 0, $p) . '<span class="lime">' . $h . '</span>' . substr($t, $p + strlen($h));
      ?></p>

      <hr class="divider sk-rule">

      <h3 class="sk-cost-title"><?= e((string)($sel['cost_title'] ?? 'CUSTO')) ?></h3>
      <p class="sk-cost">
        <?= svg('coin-points', 'sk-cost__ic') ?>
        <span class="num sk-cost__n" id="sk-cost"><?= e((string)($sel['cost'] ?? 0)) ?></span>
        <span class="sk-cost__label"><?= e((string)($sel['cost_label'] ?? 'PONTO(S) DE HABILIDADE')) ?></span>
      </p>

      <form class="sk-actions" id="sk-form" method="post" action="<?= e(url('habilidades/aprender')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="habilidade" id="sk-slug" value="<?= e((string)($sel['slug'] ?? '')) ?>">
        <?php $canLearn = !empty($sel['can_learn']); $why = (string)($sel['reason'] ?? ''); ?>
        <button class="sk-learn" type="submit" id="sk-learn"<?= $canLearn ? '' : ' disabled aria-disabled="true"' ?>
                title="<?= e($canLearn ? 'Aprender habilidade' : $why) ?>">
          <span class="keycap keycap--fill"><?= e((string)($sel['cta']['key'] ?? 'F')) ?></span>
          <span class="sk-learn__label"><?= e((string)($sel['cta']['label'] ?? 'APRENDER HABILIDADE')) ?></span>
        </button>
        <button class="sk-hold" type="submit" id="sk-hold"<?= $canLearn ? '' : ' disabled aria-disabled="true"' ?>
                title="<?= e($canLearn ? 'Segure para confirmar' : $why) ?>">
          <span class="sk-hold__fill" aria-hidden="true"></span>
          <span class="sk-hold__label"><?= e((string)($sel['cta_hold'] ?? 'SEGURAR PARA APRENDER')) ?></span>
        </button>
        <p class="sk-why" id="sk-why"<?= $canLearn ? ' hidden' : '' ?>><?= e($why) ?></p>
      </form>

    </div>
  </aside>

</div>
