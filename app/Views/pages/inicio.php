<?php declare(strict_types=1);
/**
 * Tela INÍCIO — referência "01-Menu-do-jogo-inicio.png" (1672x941).
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf.
 * $data: brand, menu, season, contract, news_panel, news_pages (+ news do controller).
 * CSS: assets/css/pages/inicio.css   JS: assets/js/pages/inicio.js + assets/js/three/hero3d.js
 */

$brand     = (array)($data['brand'] ?? []);
$menu      = (array)($data['menu'] ?? []);
$season    = (array)($data['season'] ?? []);
$contract  = (array)($data['contract'] ?? []);
$newsHead  = (array)($data['news_panel'] ?? []);
$newsPages = (array)($data['news_pages'] ?? []);

/* ---- passe de temporada ---------------------------------------------- */
$sXp     = (int)($season['xp'] ?? 0);
$sXpNext = max(1, (int)($season['xp_next'] ?? 1));
$sPct    = round(min(100, max(0, $sXp / $sXpNext * 100)), 2);
$sRewards = (array)($season['rewards'] ?? []);
if ($sRewards === []) {
    $sRewards = [['level' => (int)($season['level'] ?? 1), 'name' => (string)($season['title'] ?? ''), 'tier' => '', 'image' => (string)($season['image'] ?? 'ui/season-pass.jpg'), 'pos' => '52% 26%']];
}

$seasonSlides = [];
foreach ($sRewards as $r) {
    ob_start(); ?>
<figure class="season__slide">
  <img class="season__img" src="<?= e(media((string)($r['image'] ?? 'ui/season-pass.jpg'))) ?>" alt="<?= e((string)($r['name'] ?? '')) ?>" decoding="async" style="object-position:<?= e((string)($r['pos'] ?? '52% 26%')) ?>">
  <figcaption class="season__cap">
    <span class="season__cap-lvl num">NÍVEL <?= e((string)($r['level'] ?? '')) ?></span>
    <span class="season__cap-name"><?= e((string)($r['name'] ?? '')) ?></span>
    <span class="season__cap-tier"><?= e((string)($r['tier'] ?? '')) ?></span>
  </figcaption>
</figure>
<?php $seasonSlides[] = (string)ob_get_clean();
}

/* ---- contrato ativo --------------------------------------------------- */
$cDone  = (int)($contract['progress'] ?? 0);
$cTotal = max(1, (int)($contract['total'] ?? 1));
$cPct   = round(min(100, max(0, $cDone / $cTotal * 100)), 2);

/* ---- notícias: 3 páginas de 3 itens ----------------------------------- */
$newsSlides = [];
foreach ($newsPages as $pageItems) {
    ob_start(); ?>
<ul class="news__list">
<?php foreach ((array)$pageItems as $n): ?>
  <li class="news__row">
    <a class="news__item" href="<?= e(url((string)($n['route'] ?? 'noticias'))) ?>">
      <span class="news__thumb"><img src="<?= e(media((string)($n['image'] ?? 'news/news-atualizacao.jpg'))) ?>" alt="" loading="lazy" decoding="async"></span>
      <span class="news__txt">
        <strong class="news__title"><?= e((string)($n['title'] ?? '')) ?></strong>
        <span class="news__sum"><?= e((string)($n['summary'] ?? '')) ?></span>
        <span class="news__date num"><?= e((string)($n['date'] ?? '')) ?></span>
      </span>
    </a>
  </li>
<?php endforeach; ?>
</ul>
<?php $newsSlides[] = (string)ob_get_clean();
}
?>
<div class="inicio">

  <!-- ============================ coluna esquerda ============================ -->
  <section class="inicio__left" aria-label="Menu principal">
    <div class="inicio__logo" data-boot>
      <?php component('logo', ['variant' => 'hero']) ?>
    </div>

    <nav class="inicio__menu" aria-label="Ações">
<?php foreach ($menu as $item): ?>
<?php
      $mSlug  = (string)($item['slug'] ?? '');
      /* o emblema de JOGAR SOLO da referência (duas cristas, 38x37 de tinta) não
         existe no Material Symbols nem no sprite: é desenhado aqui. */
      $mIcon  = ($item['emblem'] ?? '') === 'mountain'
        ? '<span class="menu-btn__icon menu-btn__icon--emblem" aria-hidden="true">'
          . '<svg viewBox="0 0 38 37" class="ic"><path fill="currentColor" fill-rule="evenodd"'
          . ' d="M23.6 1.8 37.6 35.6H12.8Z M10.2 12.4 19.4 35.6H.6Z M23.6 9.2 27.6 18.4 25.2 16.6 23.4 19 21.4 16.4 19.5 18.2Z"/></svg></span>'
        : icon((string)($item['icon'] ?? 'chevron_right'), 'menu-btn__icon' . (!empty($item['icon_fill']) ? ' fill' : ''));
      $mInner = $mIcon
        . '<span class="menu-btn__text"><strong>' . e((string)($item['title'] ?? '')) . '</strong><small>' . e((string)($item['desc'] ?? '')) . '</small></span>'
        . icon('chevron_right', 'menu-btn__chev');
      $mCls = 'menu-btn menu-btn--' . e($mSlug) . (!empty($item['active']) ? ' is-active' : '');
?>
<?php if (($item['href'] ?? null) === null): ?>
      <form class="inicio__logout" method="post" action="<?= e(url('sair')) ?>">
        <?= csrf_field() ?>
        <button class="<?= $mCls ?>" type="submit" data-boot><?= $mInner ?></button>
      </form>
<?php else: ?>
      <a class="<?= $mCls ?>" href="<?= e(url((string)$item['href'])) ?>" data-boot><?= $mInner ?></a>
<?php endif; ?>
<?php endforeach; ?>
    </nav>
  </section>

  <!-- ============================ centro (cena) ==============================
       O soldado 3D foi desligado nesta tela (ver notas): em qualquer opacidade
       mensurável ele desenha um segundo personagem que a referência não tem,
       e onde o soldado da foto está a coluna direita já o cobre. Sem canvas,
       hero3d.js sai limpo (o `lobby` continua usando o módulo). -->
  <div class="inicio__stage" aria-hidden="true"></div>

  <!-- ============================ coluna direita ============================= -->
  <aside class="inicio__right" aria-label="Resumo do caçador">

    <!-- passe de temporada -->
    <section class="panel panel--hot season">
      <div class="season__art">
        <?php component('carousel', ['slides' => $seasonSlides, 'interval' => 9000, 'over' => true, 'fixed' => true, 'label' => 'Recompensas do passe de temporada']) ?>
      </div>
      <div class="season__body">
        <?php component('hex', ['size' => 'lg', 'color' => 'lime', 'text' => (string)($season['level'] ?? ''), 'class' => 'season__hex']) ?>
        <div class="season__info">
          <h2 class="season__title"><?= e((string)($season['title'] ?? 'PASSE DE TEMPORADA')) ?></h2>
          <p class="season__sub"><?= e((string)($season['subtitle'] ?? '')) ?></p>
          <div class="progress progress--anim season__bar" style="--v:<?= e((string)$sPct) ?>%" role="progressbar" aria-label="Progresso do passe" aria-valuemin="0" aria-valuemax="<?= e((string)$sXpNext) ?>" aria-valuenow="<?= e((string)$sXp) ?>"><div class="progress__bar"></div></div>
          <p class="season__xp num"><span class="lime" data-count="<?= e((string)$sXp) ?>" data-dur="1200"><?= e(num($sXp)) ?></span> / <?= e(num($sXpNext)) ?> XP</p>
        </div>
        <a class="season__link" href="<?= e(url((string)($season['href'] ?? 'loja'))) ?>"><span class="sr-only"><?= e((string)($season['title'] ?? 'Passe de temporada')) ?></span></a>
      </div>
      <span class="season__clock" data-countdown="<?= e((string)($season['ends_at'] ?? '')) ?>" aria-hidden="true"></span>
    </section>

    <!-- contrato ativo -->
    <section class="panel contract">
      <header class="panel__head">
        <h2 class="panel__title"><?= e((string)($contract['title'] ?? 'CONTRATO ATIVO')) ?></h2>
        <a class="panel__action" href="<?= e(url((string)($contract['href'] ?? 'missoes'))) ?>"><?= e((string)($contract['action'] ?? 'VER TODOS')) ?><?= icon('add') ?></a>
      </header>
      <div class="panel__body contract__body">
        <a class="contract__mission" href="<?= e(url((string)($contract['href'] ?? 'missoes'))) ?>">
          <span class="contract__icon">
            <svg class="contract__ring" viewBox="0 0 48 48" aria-hidden="true">
              <circle cx="24" cy="24" r="17.2" fill="none" stroke="currentColor" stroke-width="1.7"/>
              <path fill="none" stroke="currentColor" stroke-width="2.2" d="M24 1.6V6.6M24 41.4v5M1.6 24h5M41.4 24h5M37.6 10.4l3.4-3.4M7 41l3.4-3.4M10.4 10.4 7 7M37.6 37.6l3.4 3.4"/>
            </svg>
            <?= icon('park', 'contract__glyph') ?>
          </span>
          <span class="contract__txt">
            <strong class="contract__name"><?= e((string)($contract['name'] ?? '')) ?></strong>
            <small class="contract__desc"><?= e((string)($contract['desc'] ?? '')) ?></small>
            <span class="progress progress--anim contract__bar" style="--v:<?= e((string)$cPct) ?>%" role="progressbar" aria-label="Progresso do contrato" aria-valuemin="0" aria-valuemax="<?= e((string)$cTotal) ?>" aria-valuenow="<?= e((string)$cDone) ?>"><span class="progress__bar"></span></span>
          </span>
          <span class="contract__count num"><?= e((string)$cDone) ?> / <?= e((string)$cTotal) ?></span>
        </a>
        <div class="contract__reward">
          <p class="contract__label"><?= e((string)($contract['reward_label'] ?? 'RECOMPENSA')) ?></p>
          <ul class="contract__rewards">
<?php foreach ((array)($contract['rewards'] ?? []) as $rw): ?>
<?php $rwType = (string)($rw['type'] ?? ''); $rwVal = (int)($rw['value'] ?? 0); ?>
            <li class="contract__reward-item" title="<?= e((string)($rw['label'] ?? '')) ?>">
<?php if ($rwType === 'xp'): ?>
              <span class="rw rw--xp" aria-hidden="true">XP</span>
<?php elseif ($rwType === 'cash'): ?>
              <span class="rw rw--cash" aria-hidden="true">$</span>
<?php else: ?>
              <span class="rw rw--rep" aria-hidden="true"><?= icon('handshake') ?></span>
<?php endif; ?>
              <span class="contract__reward-val num" data-count="<?= e((string)$rwVal) ?>" data-dur="1000"><?= e(num($rwVal)) ?></span>
              <span class="sr-only"><?= e((string)($rw['label'] ?? '')) ?></span>
            </li>
<?php endforeach; ?>
          </ul>
        </div>
      </div>
    </section>

    <!-- notícias -->
    <section class="panel news">
      <header class="panel__head">
        <h2 class="panel__title"><?= e((string)($newsHead['title'] ?? 'NOTÍCIAS')) ?></h2>
        <a class="panel__action" href="<?= e(url((string)($newsHead['href'] ?? 'noticias'))) ?>"><?= e((string)($newsHead['action'] ?? 'VER TODAS')) ?><?= icon('add') ?></a>
      </header>
      <div class="panel__body news__body">
        <?php component('carousel', ['slides' => $newsSlides, 'interval' => 7500, 'fixed' => true, 'dots' => false, 'class' => 'news__car', 'label' => 'Notícias']) ?>
      </div>
    </section>

  </aside>
</div>
