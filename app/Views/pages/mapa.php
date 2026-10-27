<?php declare(strict_types=1);
/**
 * Tela MAPA DO MUNDO (referência 08-mapas.png).
 *
 * $data: title, regions_title, regions[], active_region, region, markers[], legend[],
 *        legend_title, legend_toggle, controls[], map_image, info_title, extractions_title,
 *        event_title, cta_route, cta_mark, compass.
 * Escopo da view: $page, $player, $nav, $server, $data, $flash, $csrf.
 *
 * PRD v3 §23 (mundo persistente): camadas filtráveis (chips), Bases Hunter com estado (sem vagas),
 * instalações de facção com status conforme o contrato, marcadores Veyra/atividade humana/logística,
 * extrações tipadas — e todas BLOQUEADAS quando o jogador é Sobrevivente.
 */

$regions  = (array)($data['regions'] ?? []);
$bases    = (array)($data['bases'] ?? []);
$sites    = (array)($data['faction_sites'] ?? []);
$labels   = (array)($data['labels'] ?? []);
$survivor = !empty($data['survivor']);
$rstate   = is_array(($data['region'] ?? [])['state'] ?? null) ? $data['region']['state'] : [];
$region  = (array)($data['region'] ?? []);
$markers = (array)($data['markers'] ?? []);
$legend  = (array)($data['legend'] ?? []);
$toggle  = (array)($data['legend_toggle'] ?? []);
$ctaR    = (array)($data['cta_route'] ?? []);
$ctaM    = (array)($data['cta_mark'] ?? []);
$event   = is_array($region['event'] ?? null) ? $region['event'] : [];

/* Os glifos dos marcadores/legenda vivem em app/Data/map_glyphs.php e são desenhados por
   map_glyph() (app/Core/helpers.php) — o painel e a página de divulgação usam os mesmos. */

/* --- glifos das regiões (coluna esquerda) --------------------------------- */
$REGION_GLYPHS = [
    'pine'     => '<path d="M12 1.6 17.4 9h-2.2l4 5.6h-2.5L21 20.2h-7.6V23h-2.8v-2.8H3l4.3-5.6H4.8L8.8 9H6.6z"/>',
    'swamp'    => '<path d="M11.2 9.6h1.6V23h-1.6z"/><path d="M12 1.2c1.6 1.9 2.3 4 2 6.4-1.6-.6-2.5-2.7-2-6.4ZM4.4 4.2c2.4.4 4.1 1.7 5.1 3.9-1.7.5-3.6-.9-5.1-3.9ZM19.6 4.2c-1.5 3-3.4 4.4-5.1 3.9 1-2.2 2.7-3.5 5.1-3.9ZM2.6 10.4c2.5-.3 4.4.4 5.8 2.2-1.8 1-4 .3-5.8-2.2ZM21.4 10.4c-1.8 2.5-4 3.2-5.8 2.2 1.4-1.8 3.3-2.5 5.8-2.2Z"/>',
    'mountain' => '<path d="m8.6 4.4 5 8.2-2.1 2.6-1.8-1.9-2.4 2.9-2.1-1.6L1.2 20h11.2z"/><path d="m16.4 8.2 6.4 11.8h-8.3l-2.1-3.6 2.2-2.9 1.5 1.7 2-2.4-1.9-1.5z" opacity=".85"/>',
    'cactus'   => '<path fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round" d="M12 20.2V4.2"/><path fill="none" stroke="currentColor" stroke-width="2.7" stroke-linecap="round" stroke-linejoin="round" d="M12 13.2H7.6V7.4M12 11h4.4V5.4"/><path d="M5.4 20.2h13.2v2.4H5.4z"/>',
    'factory'  => '<path d="M2.4 21.6V9.4l5.6 3.4V9.4l5.6 3.4V6.2h1.6l.6-4h2.4l.6 4h1.6v15.4zM5.4 15.6v3.2h2.8v-3.2zm5.2 0v3.2h2.8v-3.2zm5.2 0v3.2h2.8v-3.2z"/>',
];

?>
<div class="mapa">

  <!-- ====================== COLUNA ESQUERDA ====================== -->
  <aside class="mapa__left">
    <section class="panel mp" data-boot>

      <h1 class="mp__title"><?= e((string)($data['title'] ?? 'MAPA DO MUNDO')) ?></h1>

      <!-- ---- REGIÕES ---- -->
      <div class="mgroup mgroup--regions">
        <h2 class="mgroup__title"><?= e((string)($data['regions_title'] ?? 'REGIÕES')) ?></h2>
        <div class="mgroup__list" data-stagger>
          <?php foreach ($regions as $r): ?>
          <?php $isActive = ((string)$r['slug'] === (string)($data['active_region'] ?? '')); ?>
          <a class="row rrow<?= $isActive ? ' is-active' : '' ?><?= !empty($r['locked']) ? ' is-locked' : '' ?>"
             href="<?= e(url('mapa/' . (string)$r['slug'])) ?>"
             <?= $isActive ? ' aria-current="true"' : '' ?>>
            <span class="rrow__icon"><?= map_glyph((string)($r['icon'] ?? 'pine'), '', $REGION_GLYPHS) ?></span>
            <span class="row__text">
              <strong><?= e((string)$r['name']) ?></strong>
              <small><?= e((string)($data['level_label'] ?? 'NÍVEL RECOMENDADO:')) ?> <span class="num"><?= e((string)$r['level']) ?></span></small>
            </span>
            <?php if (!empty($r['locked'])): ?>
            <span class="row__lock" title="<?= e((string)($data['locked_note'] ?? 'REGIÃO TRAVADA')) ?>"><?= icon('lock', 'fill') ?></span>
            <?php endif; ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ---- CAMADAS (PRD v3 §23.9) — a legenda É o filtro: cada linha liga/desliga
           a sua camada, e TODOS volta a mostrar o mapa inteiro. ---- -->
      <div class="mgroup mgroup--legend" id="mapa-legend">
        <h2 class="mgroup__title"><?= e((string)($data['legend_title'] ?? 'CAMADAS')) ?></h2>
        <div class="mlegend" data-map-filters role="group" aria-label="<?= e((string)($data['legend_title'] ?? 'CAMADAS')) ?>">
          <button class="mlegend__i mlayer mlayer--all is-active" type="button" data-value="todos" aria-pressed="true">
            <span class="mlegend__ic mlegend__ic--lime"><?= icon('done_all') ?></span>
            <span class="mlegend__label"><?= e((string)($data['legend_all'] ?? 'TODOS')) ?></span>
          </button>
          <?php foreach ($legend as $l): ?>
          <button class="mlegend__i mlayer" type="button" data-value="<?= e((string)($l['layer'] ?? '')) ?>" aria-pressed="false">
            <span class="mlegend__ic mlegend__ic--<?= e((string)($l['color'] ?? 'lime')) ?>"><?= map_glyph((string)$l['glyph']) ?></span>
            <span class="mlegend__label"><?= e((string)$l['label']) ?></span>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      <button class="mp__toggle" type="button" data-legend-toggle
              data-label-on="<?= e((string)($toggle['label'] ?? 'OCULTAR LEGENDA')) ?>"
              data-label-off="<?= e((string)($toggle['label_off'] ?? 'MOSTRAR LEGENDA')) ?>"
              aria-controls="mapa-legend" aria-expanded="true">
        <span class="key"><?= e((string)($toggle['key'] ?? 'X')) ?></span>
        <span class="mp__toggle-label"><?= e((string)($toggle['label'] ?? 'OCULTAR LEGENDA')) ?></span>
      </button>

    </section>
  </aside>

  <!-- ====================== MAPA ====================== -->
  <div class="mapa__map">
    <?php /* O palco (#map3d) é compartilhado com a página de divulgação — ver o parcial.
             Só a HUD tem o controle de opacidade, porque só ela tem conta onde gravar. */ ?>
    <?php partial('map-stage', ['markers' => $markers, 'opacidade_form' => true]) ?>
  </div>

  <!-- ====================== COLUNA DIREITA ====================== -->
  <aside class="mapa__right">
    <section class="panel rp" data-boot>

      <h2 class="rp__title"><?= e((string)($region['name'] ?? '')) ?></h2>

      <span class="rp__media">
        <img class="rp__img" src="<?= e(media((string)($region['image'] ?? 'regions/region-floresta-verde.jpg'))) ?>"
             alt="<?= e((string)($region['name'] ?? '')) ?>" decoding="async">
        <span class="rp__shade" aria-hidden="true"></span>
      </span>

      <p class="rp__desc"><?= e((string)($region['desc'] ?? '')) ?></p>

      <!-- INFORMAÇÕES -->
      <section class="rsec">
        <h3 class="rsec__title"><?= e((string)($data['info_title'] ?? 'INFORMAÇÕES')) ?></h3>
        <ul class="rinfo">
          <?php foreach ((array)($region['info'] ?? []) as $it): ?>
          <?php $isNum = (bool)preg_match('/^[0-9]/', (string)$it['value']); ?>
          <li class="rinfo__i">
            <span class="rinfo__k"><?= e((string)$it['label']) ?></span>
            <span class="rinfo__v<?= $isNum ? ' num' : '' ?><?= !empty($it['cls']) ? ' ' . e((string)$it['cls']) : '' ?>"<?= !empty($it['tip']) ? ' data-tip="' . e((string)$it['tip']) . '"' : '' ?>><?= e((string)$it['value']) ?><?php if (!empty($it['icon'])): ?><?= icon((string)$it['icon'], 'rinfo__ic fill') ?><?php endif; ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>

      <!-- ESTADO DO SETOR (PRD §23.2–§23.3: o que está acontecendo agora) -->
      <?php if ($rstate !== []): ?>
      <section class="rsec rsec--state">
        <h3 class="rsec__title"><?= e((string)($data['state_title'] ?? 'ESTADO DO SETOR')) ?></h3>
        <div class="rstate">
          <?php component('infestation', ['value' => (int)($rstate['infestation'] ?? 0), 'label' => (string)($labels['infestation'] ?? 'INFESTAÇÃO VEYRA'), 'class' => 'rstate__infest']); ?>
          <div class="rstate__grid">
            <div class="rstate__row">
              <span class="rstate__k"><?= e((string)($labels['veyra'] ?? 'ATIVIDADE VEYRA')) ?></span>
              <?php component('badge', ['kind' => 'activity', 'value' => (string)($rstate['veyra_activity'] ?? 'MODERADA')]); ?>
            </div>
            <div class="rstate__row">
              <span class="rstate__k"><?= e((string)($labels['human'] ?? 'ATIVIDADE HUMANA')) ?></span>
              <?php component('badge', ['kind' => 'activity', 'value' => (string)($rstate['human_activity'] ?? 'MODERADA')]); ?>
            </div>
          </div>
        </div>
      </section>
      <?php endif; ?>

      <!-- BASES HUNTER (PRD §23.5: estado, nunca vagas exatas) + INSTALAÇÕES DE FACÇÃO (§23.6: status
           conforme o contrato) — uma lista só, para o painel continuar cabendo na altura de referência -->
      <?php if ($bases !== [] || $sites !== []): ?>
      <section class="rsec rsec--bases">
        <h3 class="rsec__title"><?= e((string)($data['bases_title'] ?? 'BASES E INSTALAÇÕES')) ?></h3>
        <ul class="rbases" data-tip="<?= e((string)($data['bases_note'] ?? '')) ?>">
          <?php foreach ($bases as $b): ?>
          <li class="rbase rbase--<?= e((string)$b['color']) ?> rbase--hunter">
            <span class="rbase__ic"><?= map_glyph('gov-base') ?></span>
            <span class="rbase__n"><?= e((string)$b['name']) ?></span>
            <?php component('badge', ['kind' => 'base', 'value' => (string)$b['status'], 'class' => 'rbase__st']); ?>
          </li>
          <?php endforeach; ?>
          <?php foreach ($sites as $s): ?>
          <li class="rbase rbase--<?= e((string)$s['color']) ?> rbase--<?= e((string)$s['faction']) ?>"<?= $s['tip'] !== '' ? ' data-tip="' . e((string)$s['tip']) . '"' : '' ?>>
            <span class="rbase__ic"><?= map_glyph((string)$s['faction']) ?></span>
            <span class="rbase__n"><?= e((string)$s['name']) ?></span>
            <span class="chip chip--<?= e((string)$s['color']) ?> badge rbase__st"><?= e((string)$s['status']) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <!-- EXTRAÇÕES DISPONÍVEIS -->
      <section class="rsec rsec--ext<?= $survivor ? ' rsec--blocked' : '' ?>">
        <h3 class="rsec__title"><?= e((string)($data['extractions_title'] ?? 'EXTRAÇÕES DISPONÍVEIS')) ?></h3>
        <ul class="rext">
          <?php foreach ((array)($region['extractions'] ?? []) as $x): ?>
          <?php $st = (string)($x['state'] ?? 'active'); $tc = (string)($x['type_color'] ?? ''); ?>
          <li class="rext__i rext__i--<?= e($st) ?><?= $tc !== '' ? ' rext__i--t-' . e($tc) : '' ?>"<?= !empty($x['tip']) ? ' data-tip="' . e((string)$x['tip']) . '"' : '' ?>>
            <span class="rext__ic"><?php
                if ($st === 'blocked') { echo icon('lock'); }
                elseif ($st === 'active') { echo map_glyph('extraction'); }
                elseif ($st === 'conditional') { echo icon('adjust'); }
                else { echo icon('close'); }
            ?></span>
            <span class="rext__n"><?= e((string)$x['name']) ?><?php if (!empty($x['type_label'])): ?><small class="rext__t"><?= e((string)$x['type_label']) ?></small><?php endif; ?></span>
            <span class="rext__s"><?= e((string)$x['status']) ?></span>
            <span class="rext__d" aria-hidden="true"></span>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($survivor): ?>
        <p class="rext__warn"><?= icon('block', 'rext__warn-ic') ?><?= e(\App\Core\GameRules::SURVIVOR_EXTRACTION_TIP) ?></p>
        <?php endif; ?>
      </section>

      <!-- EVENTO ATIVO -->
      <section class="rsec rsec--event">
        <h3 class="rsec__title"><?= e((string)($data['event_title'] ?? 'EVENTO ATIVO')) ?></h3>
        <?php if ($event): ?>
        <div class="revent">
          <span class="revent__ic"><?= map_glyph('event') ?></span>
          <span class="revent__name"><?= e((string)$event['name']) ?></span>
          <span class="revent__timer">
            <small class="revent__lab"><?= e((string)($event['ends_label'] ?? 'TERMINA EM')) ?></small>
            <strong class="revent__val num" data-countdown="<?= e((string)$event['ends_in']) ?>"><?= e((string)$event['ends_in']) ?></strong>
          </span>
        </div>
        <?php else: ?>
        <p class="revent revent--none muted">SEM EVENTO ATIVO NESTA REGIÃO.</p>
        <?php endif; ?>
      </section>

      <div class="rp__cta">
        <a class="btn btn--primary btn--block rp__route" href="<?= e(url((string)($ctaR['href'] ?? 'jogar'))) ?>" data-route-cta>
          <span class="key key--btn"><?= e((string)($ctaR['key'] ?? 'F')) ?></span>
          <span class="btn__label"><?= e((string)($ctaR['label'] ?? 'DEFINIR ROTA')) ?></span>
        </a>
        <button class="btn btn--outline btn--block rp__mark" type="button" data-mark-cta
                data-label="<?= e((string)($ctaM['label'] ?? 'MARCAR PONTO')) ?>"
                data-label-on="<?= e((string)($ctaM['label_on'] ?? 'PONTO MARCADO')) ?>"
                aria-pressed="false">
          <?= icon((string)($ctaM['icon'] ?? 'star'), 'fill') ?>
          <span class="btn__label"><?= e((string)($ctaM['label'] ?? 'MARCAR PONTO')) ?></span>
        </button>
      </div>

    </section>
  </aside>

</div>
