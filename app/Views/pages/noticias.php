<?php declare(strict_types=1);
/**
 * Tela NOTÍCIAS — /noticias (listagem) e /noticias/{slug} (artigo).
 * Não há referência dedicada: âncora de estilo em 01-Menu-do-jogo-inicio.png
 * (painéis frios, títulos brancos 400, datas em mono, glow lime discreto).
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf.
 * $data: title, subtitle, featured, items[], agenda[], links[], article|null.
 * CSS: assets/css/pages/noticias.css   JS: assets/js/pages/noticias.js
 */

$items   = array_values((array)($data['items'] ?? []));
$article = is_array($data['article'] ?? null) ? $data['article'] : null;
$agenda  = (array)($data['agenda'] ?? []);
$links   = (array)($data['links'] ?? []);

/* destaque: item marcado em `featured`, senão o primeiro */
$featSlug = (string)($data['featured'] ?? '');
$feat = null;
foreach ($items as $it) {
    if ((string)($it['slug'] ?? '') === $featSlug) { $feat = $it; break; }
}
if ($feat === null) { $feat = $items[0] ?? null; }

/* grade: tudo que não é o destaque */
$rest = [];
foreach ($items as $it) {
    if ($feat !== null && (string)($it['slug'] ?? '') === (string)($feat['slug'] ?? '')) { continue; }
    $rest[] = $it;
}

/* outras notícias (coluna direita do artigo) */
$others = [];
if ($article !== null) {
    foreach ($items as $it) {
        if ((string)($it['slug'] ?? '') === (string)($article['slug'] ?? '')) { continue; }
        $others[] = $it;
    }
    $others = array_slice($others, 0, 4);
}

/* ---- coluna direita: agenda + atalhos (compartilhada pelas duas vistas) ---- */
ob_start(); ?>
<ul class="agenda" data-stagger>
<?php foreach ($agenda as $ev): ?>
  <li class="agenda__item">
    <a class="agenda__link" href="<?= e(url((string)($ev['href'] ?? 'noticias'))) ?>">
      <span class="agenda__icon agenda__icon--<?= e((string)($ev['color'] ?? 'lime')) ?>"><?= icon((string)($ev['icon'] ?? 'schedule')) ?></span>
      <span class="agenda__txt">
        <strong class="agenda__label"><?= e((string)($ev['label'] ?? '')) ?></strong>
        <small class="agenda__sub"><?= e((string)($ev['sub'] ?? '')) ?></small>
      </span>
      <span class="agenda__clock num <?= e((string)($ev['color'] ?? 'lime')) ?>" data-countdown="<?= e((string)($ev['at'] ?? '')) ?>">--:--:--</span>
    </a>
  </li>
<?php endforeach; ?>
</ul>
<?php $agendaBody = (string)ob_get_clean();

ob_start(); ?>
<ul class="shortcuts" data-stagger>
<?php foreach ($links as $lk): ?>
  <li class="shortcuts__item">
    <a class="row shortcuts__row" href="<?= e(url((string)($lk['href'] ?? 'inicio'))) ?>">
      <?= icon((string)($lk['icon'] ?? 'chevron_right'), 'row__icon') ?>
      <span class="row__text"><strong><?= e((string)($lk['label'] ?? '')) ?></strong><small><?= e((string)($lk['sub'] ?? '')) ?></small></span>
      <?= icon('chevron_right', 'row__chev') ?>
    </a>
  </li>
<?php endforeach; ?>
</ul>
<?php $linksBody = (string)ob_get_clean(); ?>

<?php if ($article !== null): /* ====================== ARTIGO ====================== */ ?>
<div class="noticias noticias--article">
  <div class="noticias__grid">

    <article class="panel panel--hot art" data-boot>
      <div class="art__hero">
        <img class="art__img" src="<?= e(media((string)($article['image'] ?? 'news/news-atualizacao.jpg'))) ?>" alt="" decoding="async" style="object-position:<?= e((string)($article['pos'] ?? '50% 45%')) ?>">
        <span class="art__scan" aria-hidden="true"></span>
        <div class="art__hero-txt">
          <div class="art__chips">
            <span class="chip chip--<?= e((string)($article['tag_cls'] ?? 'lime')) ?>"><?= e((string)($article['tag'] ?? '')) ?></span>
            <span class="art__date num"><?= e((string)($article['date'] ?? '')) ?></span>
          </div>
          <h1 class="page-title art__title"><?= e((string)($article['title'] ?? '')) ?></h1>
        </div>
      </div>

      <div class="panel__body art__body">
        <p class="art__lead"><?= e((string)($article['lead'] ?? ($article['summary'] ?? ''))) ?></p>
        <div class="art__meta">
          <span class="art__meta-item"><?= icon('person', 'art__meta-icon') ?><?= e((string)($article['author'] ?? 'EQUIPE EXTRACT HUNTERS')) ?></span>
          <span class="art__meta-sep" aria-hidden="true"></span>
          <span class="art__meta-item"><?= icon('schedule', 'art__meta-icon') ?><span class="num"><?= e((string)($article['read'] ?? 3)) ?></span> MIN DE LEITURA</span>
          <span class="art__meta-sep" aria-hidden="true"></span>
          <span class="art__meta-item art__meta-item--tag"><?= icon('radar', 'art__meta-icon') ?>PATCH <span class="num">8.8.1</span></span>
        </div>

        <div class="scroll-y art__text">
<?php foreach ((array)($article['body'] ?? []) as $p): ?>
          <p><?= e((string)$p) ?></p>
<?php endforeach; ?>
<?php if (!empty($article['highlights'])): ?>
          <div class="box box--hot art__high">
            <h2 class="art__high-title"><?= icon('bolt') ?>PRINCIPAIS MUDANÇAS</h2>
            <ul class="art__high-list">
<?php foreach ((array)$article['highlights'] as $h): ?>
              <li><?= icon('check_circle', 'art__high-icon') ?><span><?= e((string)$h) ?></span></li>
<?php endforeach; ?>
            </ul>
          </div>
<?php endif; ?>
        </div>
      </div>

      <footer class="panel__foot art__foot">
        <a class="btn btn--outline btn--sm" href="<?= e(url('noticias')) ?>"><?= icon('chevron_left') ?>TODAS AS NOTÍCIAS</a>
        <div class="art__foot-right">
          <a class="btn btn--outline btn--sm" href="<?= e(url('missoes')) ?>"><?= icon('swords') ?>MISSÕES</a>
          <a class="btn btn--primary btn--sm" href="<?= e(url('desafios')) ?>"><?= icon('flag') ?>DESAFIOS DO EVENTO<?= icon('chevron_right', 'btn__chev') ?></a>
        </div>
      </footer>
    </article>

    <aside class="noticias__side col" aria-label="Mais notícias">
      <section class="panel news-more" data-boot>
        <header class="panel__head">
          <h2 class="panel__title">OUTRAS NOTÍCIAS</h2>
          <a class="panel__action" href="<?= e(url('noticias')) ?>">VER TODAS<?= icon('add') ?></a>
        </header>
        <div class="panel__body news-more__body">
          <ul class="news-more__list" data-stagger>
<?php foreach ($others as $o): ?>
            <li class="news-more__item">
              <a class="news-more__link" href="<?= e(url('noticias/' . (string)($o['slug'] ?? ''))) ?>">
                <span class="news-more__thumb"><img src="<?= e(media((string)($o['image'] ?? 'news/news-atualizacao.jpg'))) ?>" alt="" loading="lazy" decoding="async"></span>
                <span class="news-more__txt">
                  <strong class="news-more__title"><?= e((string)($o['title'] ?? '')) ?></strong>
                  <span class="news-more__date num"><?= e((string)($o['tag'] ?? '')) ?> · <?= e((string)($o['date'] ?? '')) ?></span>
                </span>
              </a>
            </li>
<?php endforeach; ?>
          </ul>
        </div>
      </section>

      <section class="panel shortcuts-panel" data-boot>
        <header class="panel__head"><h2 class="panel__title">ATALHOS</h2></header>
        <div class="panel__body shortcuts-panel__body"><?= $linksBody ?></div>
      </section>
    </aside>

  </div>
</div>

<?php else: /* ===================== LISTAGEM ===================== */ ?>
<div class="noticias">

  <header class="page-head noticias__head" data-boot>
    <div class="noticias__head-txt">
      <h1 class="page-title"><?= e((string)($data['title'] ?? 'NOTÍCIAS')) ?><small><span class="num"><?= e((string)count($items)) ?></span> PUBLICAÇÕES</small></h1>
      <p class="page-subtitle"><?= e((string)($data['subtitle'] ?? '')) ?></p>
    </div>
    <div class="noticias__legend">
      <span class="noticias__legend-label">CATEGORIAS</span>
      <ul class="noticias__legend-list">
<?php foreach ($items as $lg): ?>
        <li><span class="chip chip--<?= e((string)($lg['tag_cls'] ?? 'lime')) ?> chip--dot"><?= e((string)($lg['tag'] ?? '')) ?></span></li>
<?php endforeach; ?>
      </ul>
    </div>
  </header>

  <div class="noticias__grid">

    <div class="noticias__main">

<?php if ($feat !== null): ?>
      <a class="feat" href="<?= e(url('noticias/' . (string)($feat['slug'] ?? ''))) ?>" data-boot>
        <img class="feat__img" src="<?= e(media((string)($feat['image'] ?? 'news/news-atualizacao.jpg'))) ?>" alt="" decoding="async" style="object-position:<?= e((string)($feat['pos'] ?? '50% 42%')) ?>">
        <span class="feat__scan" aria-hidden="true"></span>
        <span class="feat__flag">EM DESTAQUE</span>
        <div class="feat__body">
          <div class="feat__chips">
            <span class="chip chip--<?= e((string)($feat['tag_cls'] ?? 'lime')) ?>"><?= e((string)($feat['tag'] ?? '')) ?></span>
            <span class="feat__date num"><?= e((string)($feat['date'] ?? '')) ?></span>
          </div>
          <h2 class="feat__title"><?= e((string)($feat['title'] ?? '')) ?></h2>
          <p class="feat__lead"><?= e((string)($feat['lead'] ?? ($feat['summary'] ?? ''))) ?></p>
          <div class="feat__foot">
            <span class="feat__author"><?= icon('person', 'feat__foot-icon') ?><?= e((string)($feat['author'] ?? '')) ?></span>
            <span class="feat__read"><?= icon('schedule', 'feat__foot-icon') ?><span class="num"><?= e((string)($feat['read'] ?? 3)) ?></span> MIN</span>
            <span class="feat__cta">LER MATÉRIA<?= icon('chevron_right') ?></span>
          </div>
        </div>
      </a>
<?php endif; ?>

      <ul class="ncards" data-stagger>
<?php foreach ($rest as $n): ?>
        <li class="ncards__item">
          <a class="ncard" href="<?= e(url('noticias/' . (string)($n['slug'] ?? ''))) ?>">
            <span class="ncard__media">
              <img src="<?= e(media((string)($n['image'] ?? 'news/news-atualizacao.jpg'))) ?>" alt="" loading="lazy" decoding="async" style="object-position:<?= e((string)($n['pos'] ?? '50% 45%')) ?>">
              <span class="chip chip--<?= e((string)($n['tag_cls'] ?? 'lime')) ?> ncard__chip"><?= e((string)($n['tag'] ?? '')) ?></span>
            </span>
            <span class="ncard__body">
              <strong class="ncard__title"><?= e((string)($n['title'] ?? '')) ?></strong>
              <span class="ncard__sum"><?= e((string)($n['summary'] ?? '')) ?></span>
              <span class="ncard__foot">
                <span class="ncard__date num"><?= e((string)($n['date'] ?? '')) ?></span>
                <span class="ncard__cta">LER<?= icon('chevron_right') ?></span>
              </span>
            </span>
          </a>
        </li>
<?php endforeach; ?>
      </ul>

    </div>

    <aside class="noticias__side col" aria-label="Agenda e atalhos">
      <section class="panel agenda-panel" data-boot>
        <header class="panel__head">
          <h2 class="panel__title">AGENDA</h2>
          <span class="panel__meta">AO VIVO<span class="dot agenda-panel__dot"></span></span>
        </header>
        <div class="panel__body agenda-panel__body"><?= $agendaBody ?></div>
      </section>

      <section class="panel shortcuts-panel panel--grow" data-boot>
        <header class="panel__head"><h2 class="panel__title">ATALHOS</h2></header>
        <div class="panel__body shortcuts-panel__body"><?= $linksBody ?></div>
      </section>
    </aside>

  </div>
</div>
<?php endif; ?>
