<?php declare(strict_types=1);
/**
 * Tela MAPAS — grade completa dos 8 mapas de partida + painel de detalhe.
 * Âncora visual: 02-jogar-solo-multplayer.png (mesma linguagem dos cards de mapa).
 *
 * $data: title, tag, intro, grid_title, detail_title, cta, cta_href, alt_cta, alt_href,
 *        filters_all, rotation_label, rotation_secs, labels[], items[], modes[], mode_icons[].
 * Escopo da view: $page, $player, $nav, $server, $data, $flash, $csrf.
 *
 * Os brasões dos modos são símbolos SVG locais (defs abaixo) — a sprite compartilhada
 * só traz silhuetas maciças e o card pede contorno heráldico. Ids prefixados mp-.
 */
$items  = is_array($data['items'] ?? null) ? array_values($data['items']) : [];
$modes  = is_array($data['modes'] ?? null) ? array_values($data['modes']) : [];
$labels = is_array($data['labels'] ?? null) ? $data['labels'] : [];
$mIcons = is_array($data['mode_icons'] ?? null) ? $data['mode_icons'] : [];

/** Ids permitidos dos símbolos locais (nada vindo do mock entra cru no href). */
$syms = ['mp-skull', 'mp-shield', 'mp-target'];
$sym  = static function (string $mode) use ($mIcons, $syms): string {
    $id = (string)($mIcons[$mode] ?? '');
    return in_array($id, $syms, true) ? $id : 'mp-skull';
};
/** Chave estável do modo para o filtro (sem acento, sem depender do texto). */
$modeKey = static function (string $mode) use ($modes): string {
    $i = array_search($mode, $modes, true);
    return $i === false ? 'x' : 'm' . (string)$i;
};

/* mapa selecionado inicialmente (primeiro com selected, senão o primeiro) */
$sel = 0;
foreach ($items as $i => $m) { if (!empty($m['selected'])) { $sel = (int)$i; break; } }
$cur   = $items[$sel] ?? [];
$total = count($items);

/* contagem por modo (chips de filtro) */
$counts = [];
foreach ($items as $m) { $k = (string)($m['mode'] ?? ''); $counts[$k] = (int)($counts[$k] ?? 0) + 1; }

$lbl = static fn(string $k, string $d): string => (string)($labels[$k] ?? $d);
?>
<svg class="mapas-defs" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <!-- caveira (MATA-MATA EM EQUIPE) -->
    <symbol id="mp-skull" viewBox="0 0 24 24">
      <path fill="currentColor" fill-rule="evenodd" d="M12 1.9c-4.6 0-8.2 3.4-8.2 7.8 0 2.7 1.1 4.8 2.9 6.2v1.9c0 .7.6 1.3 1.3 1.3h8c.7 0 1.3-.6 1.3-1.3v-1.9c1.8-1.4 2.9-3.5 2.9-6.2 0-4.4-3.6-7.8-8.2-7.8ZM8.2 7.8a2.3 2.3 0 1 0 0 4.6 2.3 2.3 0 0 0 0-4.6Zm7.6 0a2.3 2.3 0 1 0 0 4.6 2.3 2.3 0 0 0 0-4.6ZM12 12.5l-1.2 2.3h2.4L12 12.5Zm-1.55 3.7h.9v3.2h-.9Zm2.2 0h.9v3.2h-.9Z"/>
      <path fill="currentColor" d="M6.6 20.1h10.8v1.5H6.6z" opacity=".5"/>
    </symbol>
    <!-- escudo + estandarte (DOMINAÇÃO) -->
    <symbol id="mp-shield" viewBox="0 0 24 24">
      <path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" d="M12 1.9 21 5v6.8c0 5-3.7 9.3-9 11-5.3-1.7-9-6-9-11V5Z"/>
      <path fill="currentColor" d="M11.1 6.2h1.7v11.4h-1.7z"/>
      <path fill="currentColor" d="M13.5 6.7h4.6l-1.5 2.2 1.5 2.2h-4.6z"/>
    </symbol>
    <!-- retículo (ZONA DE CONFLITO) -->
    <symbol id="mp-target" viewBox="0 0 24 24">
      <circle cx="12" cy="12" r="7.4" fill="none" stroke="currentColor" stroke-width="1.8"/>
      <circle cx="12" cy="12" r="2.4" fill="currentColor"/>
      <path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M12 1.8v3.4M12 18.8v3.4M1.8 12h3.4M18.8 12h3.4"/>
    </symbol>
  </defs>
</svg>

<div class="mapas">

  <header class="mapas-head" data-boot>
    <div class="mapas-head__id">
      <h1 class="page-title"><?= e((string)($data['title'] ?? 'MAPAS')) ?><span class="page-title__tag"><?= e((string)($data['tag'] ?? '')) ?></span></h1>
      <p class="page-subtitle"><?= e((string)($data['intro'] ?? '')) ?></p>
    </div>
    <div class="rotation" aria-live="off">
      <span class="rotation__label"><?= e((string)($data['rotation_label'] ?? 'ROTAÇÃO DE MAPAS EM')) ?></span>
      <span class="rotation__val num" data-countdown="<?= e((string)(int)($data['rotation_secs'] ?? 0)) ?>">--:--:--</span>
    </div>
  </header>

  <div class="layout-2 layout-2--r mapas-body">

    <!-- ===================== GRADE DE MAPAS ===================== -->
    <section class="panel mapas-grid panel--grow" data-boot>
      <header class="panel__head">
        <h2 class="panel__title"><?= e((string)($data['grid_title'] ?? 'SELEÇÃO DE MAPAS')) ?> <small class="num" data-map-count><?= e((string)$total) ?> / <?= e((string)$total) ?></small></h2>
        <div class="mode-filters" data-select-group data-filters>
          <button class="chip mode-chip is-active" type="button" data-select data-filter="all" aria-pressed="true">
            <?= e((string)($data['filters_all'] ?? 'TODOS')) ?><span class="mode-chip__n num"><?= e((string)$total) ?></span>
          </button>
          <?php foreach ($modes as $mode): ?>
          <button class="chip mode-chip" type="button" data-select data-filter="<?= e($modeKey((string)$mode)) ?>" aria-pressed="false">
            <svg class="mp-hex mode-chip__ic" viewBox="0 0 52 60" aria-hidden="true" focusable="false">
              <polygon class="mp-hex__frame" points="26,1.4 50.6,15.7 50.6,44.3 26,58.6 1.4,44.3 1.4,15.7"/>
              <use href="#<?= e($sym((string)$mode)) ?>" x="11" y="15.5" width="30" height="30"></use>
            </svg>
            <?= e((string)$mode) ?><span class="mode-chip__n num"><?= e((string)($counts[(string)$mode] ?? 0)) ?></span>
          </button>
          <?php endforeach; ?>
        </div>
      </header>

      <div class="panel__body">
        <div class="map-grid" data-select-group data-map-grid>
<?php foreach ($items as $i => $mp): ?>
<?php
  $mode = (string)($mp['mode'] ?? '');
  $isSel = (int)$i === $sel;
?>
          <article class="card map-card<?= $isSel ? ' is-selected' : '' ?>"
                   data-value="<?= e((string)($mp['slug'] ?? '')) ?>"
                   data-index="<?= e((string)($i + 1)) ?>"
                   data-mode-key="<?= e($modeKey($mode)) ?>"
                   data-name="<?= e((string)($mp['name'] ?? '')) ?>"
                   data-mode="<?= e($mode) ?>"
                   data-players="<?= e((string)($mp['players'] ?? '')) ?>"
                   data-img="<?= e(media((string)($mp['image'] ?? ''))) ?>"
                   data-sym="<?= e($sym($mode)) ?>"
                   data-size="<?= e((string)($mp['size'] ?? '')) ?>"
                   data-biome="<?= e((string)($mp['biome'] ?? '')) ?>"
                   data-weather="<?= e((string)($mp['weather'] ?? '')) ?>"
                   data-best="<?= e((string)($mp['best'] ?? '')) ?>"
                   data-best-by="<?= e((string)($mp['best_by'] ?? '')) ?>"
                   data-your-best="<?= e((string)($mp['your_best'] ?? '')) ?>"
                   data-desc="<?= e((string)($mp['desc'] ?? '')) ?>"
                   role="button" tabindex="0"
                   aria-pressed="<?= $isSel ? 'true' : 'false' ?>"
                   aria-label="<?= e((string)($mp['name'] ?? '')) ?> — <?= e($mode) ?>">
            <div class="card__media">
              <img src="<?= e(media((string)($mp['image'] ?? ''))) ?>" alt="<?= e((string)($mp['name'] ?? '')) ?>" decoding="async">
            </div>
            <div class="card__body card__body--row">
              <svg class="mp-hex map-card__hex" viewBox="0 0 52 60" aria-hidden="true" focusable="false">
                <polygon class="mp-hex__frame" points="26,1.4 50.6,15.7 50.6,44.3 26,58.6 1.4,44.3 1.4,15.7"/>
                <use href="#<?= e($sym($mode)) ?>" x="11" y="15.5" width="30" height="30"></use>
              </svg>
              <div class="grid min-0 map-card__lines">
                <h3 class="card__title"><?= e((string)($mp['name'] ?? '')) ?></h3>
                <span class="card__sub"><?= e($mode) ?></span>
                <span class="map-card__foot">
                  <span class="card__meta"><?= e((string)($mp['players'] ?? '')) ?></span>
                  <span class="map-card__time" title="<?= e($lbl('best', 'MELHOR TEMPO')) ?>"><?= icon('schedule', 'map-card__time-ic') ?><span class="num"><?= e((string)($mp['best'] ?? '')) ?></span></span>
                </span>
              </div>
            </div>
            <span class="map-card__check" aria-hidden="true"><?= icon('check') ?></span>
          </article>
<?php endforeach; ?>
        </div>
        <p class="map-grid__empty" data-map-empty hidden><?= e($lbl('empty', 'NENHUM MAPA NESTE MODO')) ?></p>
      </div>
    </section>

    <!-- ===================== DETALHE ===================== -->
    <aside class="col mapas-side">
      <section class="panel panel--hot map-detail" data-boot data-detail>
        <header class="panel__head">
          <h2 class="panel__title"><?= e((string)($data['detail_title'] ?? 'INFORMAÇÕES DO MAPA')) ?></h2>
          <span class="panel__meta num" data-detail-index><?= e(str_pad((string)($sel + 1), 2, '0', STR_PAD_LEFT)) ?> / <?= e(str_pad((string)$total, 2, '0', STR_PAD_LEFT)) ?></span>
        </header>

        <div class="panel__body">
          <div class="detail__media" data-detail-media>
            <img class="detail__img" src="<?= e(media((string)($cur['image'] ?? ''))) ?>" alt="<?= e((string)($cur['name'] ?? '')) ?>" data-detail-img decoding="async">
            <span class="detail__shade" aria-hidden="true"></span>
            <span class="detail__scan" aria-hidden="true"></span>
            <span class="chip chip--lime detail__players num" data-detail-players><?= e((string)($cur['players'] ?? '')) ?></span>
            <span class="detail__over">
              <svg class="mp-hex detail__hex" viewBox="0 0 52 60" aria-hidden="true" focusable="false">
                <polygon class="mp-hex__frame" points="26,1.4 50.6,15.7 50.6,44.3 26,58.6 1.4,44.3 1.4,15.7"/>
                <use href="#<?= e($sym((string)($cur['mode'] ?? ''))) ?>" x="11" y="15.5" width="30" height="30" data-detail-use></use>
              </svg>
              <span class="detail__id">
                <strong class="detail__name" data-detail-name><?= e((string)($cur['name'] ?? '')) ?></strong>
                <span class="detail__mode" data-detail-mode><?= e((string)($cur['mode'] ?? '')) ?></span>
              </span>
            </span>
          </div>

          <p class="detail__desc" data-detail-desc><?= e((string)($cur['desc'] ?? '')) ?></p>

          <div class="detail__stats">
            <div class="dstat">
              <span class="dstat__label"><?= e($lbl('size', 'TAMANHO')) ?></span>
              <span class="dstat__val" data-detail-size><?= e((string)($cur['size'] ?? '')) ?></span>
            </div>
            <div class="dstat">
              <span class="dstat__label"><?= e($lbl('biome', 'BIOMA')) ?></span>
              <span class="dstat__val" data-detail-biome><?= e((string)($cur['biome'] ?? '')) ?></span>
            </div>
            <div class="dstat">
              <span class="dstat__label"><?= e($lbl('weather', 'CLIMA')) ?></span>
              <span class="dstat__val" data-detail-weather><?= e((string)($cur['weather'] ?? '')) ?></span>
            </div>
            <div class="dstat dstat--hot">
              <span class="dstat__label"><?= e($lbl('best', 'MELHOR TEMPO')) ?></span>
              <span class="dstat__val num lime" data-detail-best><?= e((string)($cur['best'] ?? '')) ?></span>
            </div>
          </div>

          <hr class="divider detail__rule">

          <div class="record">
            <span class="record__icon"><?= icon('military_tech', 'fill') ?></span>
            <span class="record__text">
              <span class="record__label"><?= e($lbl('record', 'RECORDE DO SERVIDOR')) ?></span>
              <strong class="record__by" data-detail-best-by><?= e((string)($cur['best_by'] ?? '')) ?></strong>
            </span>
            <span class="record__yours">
              <span class="record__label"><?= e($lbl('yours', 'SEU MELHOR')) ?></span>
              <strong class="record__val num" data-detail-your><?= e((string)($cur['your_best'] ?? '')) ?></strong>
            </span>
          </div>
        </div>

        <footer class="panel__foot detail__foot">
          <?php component('btn', ['label' => (string)($data['cta'] ?? 'JOGAR NESTE MAPA'), 'href' => url((string)($data['cta_href'] ?? 'jogar')), 'variant' => 'primary', 'block' => true, 'icon' => 'play_arrow', 'class' => 'detail__cta']) ?>
          <?php component('btn', ['label' => (string)($data['alt_cta'] ?? 'MAPA TÁTICO'), 'href' => url((string)($data['alt_href'] ?? 'mapa')), 'variant' => 'outline', 'block' => true, 'icon' => 'map', 'class' => 'detail__alt']) ?>
        </footer>
      </section>
    </aside>

  </div>
</div>
