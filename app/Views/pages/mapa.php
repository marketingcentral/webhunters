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
$filters  = (array)($data['filters'] ?? []);
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

/* --- glifos dos marcadores / da legenda (viewBox 24x24, currentColor) ------ */
$GLYPHS = [
    'extraction' => '<path d="M8.6 4.6h10.8v10.8h-2.9V9.5L6.9 19.2 4.8 17.1 14.5 7.4H8.6z"/><path d="m3.4 12.4 2.4 2.4-2.2 2.2-2.4-2.4z" opacity=".85"/>',
    'base'       => '<path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" d="M12 2.6 4.8 5.5v5.9c0 4.3 3 8.3 7.2 9.4 4.2-1.1 7.2-5.1 7.2-9.4V5.5z"/><path d="m12 7.4 1.5 3.1 3.4.5-2.5 2.4.6 3.4-3-1.6-3 1.6.6-3.4-2.5-2.4 3.4-.5z"/>',
    'camp'       => '<path d="M12 1.4c.4 2.5-.9 3.7-2.2 5.1C8.4 8 7.6 9.5 7.6 11.2a4.4 4.4 0 0 0 8.8 0c0-1.6-.5-3-1.6-4.2-.4 1-1 1.5-1.7 1.8.4-2.7-.1-5.2-1.1-7.4Z"/><path fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" d="M4.8 17.4 19.2 21.6M19.2 17.4 4.8 21.6"/>',
    'loot'       => '<path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" d="M12 2.4 20.8 7v10L12 21.6 3.2 17V7z"/><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" d="M3.6 7.2 12 11.7l8.4-4.5M12 11.9v9.4"/><path d="M8.2 13.1v3.2h1.9v-3.2zM13.9 13.1v3.2h1.9v-3.2z"/>',
    'event'      => '<path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" d="M12 2.6 21.4 12 12 21.4 2.6 12z"/><path d="M12 7.4 16.6 12 12 16.6 7.4 12z"/>',
    'risk'       => '<rect x="3" y="5" width="18" height="14" fill="none" stroke="currentColor" stroke-width="1.5"/><g stroke="currentColor" stroke-width="1.7" fill="none"><path d="M3 10 8 5M3 15 13 5M4 19 18 5M9 19 21 7M14 19 21 12M19 19 21 17"/></g>',
    'enemy'      => '<path d="M12 2.2c-4.3 0-7.5 3.1-7.5 7.3 0 2.3 1 4.2 2.7 5.5v2.6c0 .6.5 1.1 1.1 1.1h7.4c.6 0 1.1-.5 1.1-1.1v-2.6c1.7-1.3 2.7-3.2 2.7-5.5 0-4.2-3.2-7.3-7.5-7.3ZM8.9 8.4a1.9 1.9 0 1 1 0 3.8 1.9 1.9 0 0 1 0-3.8Zm6.2 0a1.9 1.9 0 1 1 0 3.8 1.9 1.9 0 0 1 0-3.8ZM12 13.4l1.1 2.1h-2.2z"/><path d="M8.6 19.1h1.7v2.7H8.6zM11.2 19.1h1.6v2.7h-1.6zM13.7 19.1h1.7v2.7h-1.7z"/>',
    'radio'      => '<path d="M9.9 8.5h4.2l3.9 12.9h-2.5l-.8-2.9h-5.4l-.8 2.9H6zM10.8 15.9h2.4L12 11.6z"/><path d="M5.6 2.4 7 3.8a5.9 5.9 0 0 0 0 4.4L5.6 9.6a7.8 7.8 0 0 1 0-7.2ZM18.4 2.4a7.8 7.8 0 0 1 0 7.2L17 8.2a5.9 5.9 0 0 0 0-4.4z" opacity=".9"/><path d="M8.6 4.6 9.9 5.9a2.6 2.6 0 0 0 0 .4L8.6 7.6a4 4 0 0 1 0-3ZM15.4 4.6a4 4 0 0 1 0 3L14.1 6.3a2.6 2.6 0 0 0 0-.4z" opacity=".9"/>',
    'village'    => '<path d="M10.4 2.6h3.2v2.6h3.1v16.2h-3.9v-4.2h-1.6v4.2H6.5V5.2h3.9zM8.6 7.6v1.9h1.9V7.6zm4.9 0v1.9h1.9V7.6zM8.6 11.4v1.9h1.9v-1.9zm4.9 0v1.9h1.9v-1.9z"/><path d="M2.6 9.9h3.1v11.5H2.6zM18.3 9.9h3.1v11.5h-3.1z"/>',
    'enemy-legend' => '<path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" d="M8.4 2.4h7.2l4.6 4.7v9.8L15.6 21.6H8.4L3.8 16.9V7.1z"/><path d="M12 6.2a4.6 4.6 0 0 0-4.6 4.5c0 1.4.6 2.6 1.6 3.4v1.5c0 .4.3.7.7.7h4.6c.4 0 .7-.3.7-.7v-1.5c1-.8 1.6-2 1.6-3.4A4.6 4.6 0 0 0 12 6.2Zm-1.9 3.8a1.2 1.2 0 1 1 0 2.3 1.2 1.2 0 0 1 0-2.3Zm3.8 0a1.2 1.2 0 1 1 0 2.3 1.2 1.2 0 0 1 0-2.3Z"/>',
    'locked'     => '<path d="M12 2.2A4.6 4.6 0 0 0 7.4 6.8v2.1H6.2c-.7 0-1.2.5-1.2 1.2v10.5c0 .7.5 1.2 1.2 1.2h11.6c.7 0 1.2-.5 1.2-1.2V10.1c0-.7-.5-1.2-1.2-1.2h-1.2V6.8A4.6 4.6 0 0 0 12 2.2Zm0 2.2a2.4 2.4 0 0 1 2.4 2.4v2.1H9.6V6.8A2.4 2.4 0 0 1 12 4.4Z"/>',
    /* PRD v3 §23 — mundo persistente */
    // Base Hunter (governamental): escudo com chevrons de patente
    'gov-base'   => '<path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" d="M12 2.6 4.8 5.5v5.9c0 4.3 3 8.3 7.2 9.4 4.2-1.1 7.2-5.1 7.2-9.4V5.5z"/><path fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" d="m8.6 10.2 3.4-2.6 3.4 2.6M8.6 14.2l3.4-2.6 3.4 2.6"/>',
    // Instalação Aegis: bastião/blindagem
    'aegis'      => '<path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" d="M3.4 20.6V9.2l3.2-2.4V4.2h3.2v2.6h1.2V4.2h3.2v2.6h1.2V4.2h3.2v2.6l-.6 2.4v11.4z"/><path d="M9.6 13.2h4.8v7.4H9.6z"/>',
    // Acampamento Frontier: tenda + trilha
    'frontier'   => '<path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" d="M12 3.4 2.8 19.6h18.4z"/><path d="M12 9.2 8.4 19.6h7.2z"/><path fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-dasharray="1.6 1.8" d="M3 22h18"/>',
    // Base de campo: casa simples com antena
    'field-base' => '<path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" d="M4 12.4 12 5.6l8 6.8V20.4H4z"/><path d="M10 14h4v6.4h-4z"/><path fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" d="M16.5 3v5.2M15 4.4h3"/>',
    // Ninho / colônia Veyra: núcleo com filamentos
    'nest'       => '<circle cx="12" cy="12" r="3.2"/><path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" d="M12 8.8V3.2M12 15.2v5.6M8.8 12H3.2M15.2 12h5.6M9.7 9.7 5.8 5.8M14.3 14.3l3.9 3.9M14.3 9.7l3.9-3.9M9.7 14.3l-3.9 3.9"/>',
    // Área contaminada: esporos
    'contaminated' => '<circle cx="8.2" cy="9" r="2.6"/><circle cx="15.6" cy="8" r="2"/><circle cx="12.4" cy="15.4" r="3"/><circle cx="6.6" cy="16.2" r="1.6"/><circle cx="17.8" cy="14.6" r="1.5"/>',
    // Atividade humana: alvo de sinal
    'human'      => '<circle cx="12" cy="12" r="2.4"/><circle cx="12" cy="12" r="6" fill="none" stroke="currentColor" stroke-width="1.6"/><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" d="M12 2.6v3M12 18.4v3M2.6 12h3M18.4 12h3"/>',
    // Safe Zone: portão protegido (perímetro civil)
    'safe-zone'  => '<path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" d="M3.6 20.4V9.6L12 4.2l8.4 5.4v10.8z"/><path d="M12 8.2 8.4 9.6v3c0 2.2 1.5 4.2 3.6 4.8 2.1-.6 3.6-2.6 3.6-4.8v-3z"/>',
    // Janela logística: comboio/caminhão
    'convoy'     => '<path d="M2.6 7h10.8v8.2H2.6z"/><path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" d="M13.4 9.4h4.4l3.6 3.4v2.4h-8z"/><circle cx="6.4" cy="17.4" r="2"/><circle cx="16.8" cy="17.4" r="2"/>',
];

/* --- glifos das regiões (coluna esquerda) --------------------------------- */
$REGION_GLYPHS = [
    'pine'     => '<path d="M12 1.6 17.4 9h-2.2l4 5.6h-2.5L21 20.2h-7.6V23h-2.8v-2.8H3l4.3-5.6H4.8L8.8 9H6.6z"/>',
    'swamp'    => '<path d="M11.2 9.6h1.6V23h-1.6z"/><path d="M12 1.2c1.6 1.9 2.3 4 2 6.4-1.6-.6-2.5-2.7-2-6.4ZM4.4 4.2c2.4.4 4.1 1.7 5.1 3.9-1.7.5-3.6-.9-5.1-3.9ZM19.6 4.2c-1.5 3-3.4 4.4-5.1 3.9 1-2.2 2.7-3.5 5.1-3.9ZM2.6 10.4c2.5-.3 4.4.4 5.8 2.2-1.8 1-4 .3-5.8-2.2ZM21.4 10.4c-1.8 2.5-4 3.2-5.8 2.2 1.4-1.8 3.3-2.5 5.8-2.2Z"/>',
    'mountain' => '<path d="m8.6 4.4 5 8.2-2.1 2.6-1.8-1.9-2.4 2.9-2.1-1.6L1.2 20h11.2z"/><path d="m16.4 8.2 6.4 11.8h-8.3l-2.1-3.6 2.2-2.9 1.5 1.7 2-2.4-1.9-1.5z" opacity=".85"/>',
    'cactus'   => '<path fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round" d="M12 20.2V4.2"/><path fill="none" stroke="currentColor" stroke-width="2.7" stroke-linecap="round" stroke-linejoin="round" d="M12 13.2H7.6V7.4M12 11h4.4V5.4"/><path d="M5.4 20.2h13.2v2.4H5.4z"/>',
    'factory'  => '<path d="M2.4 21.6V9.4l5.6 3.4V9.4l5.6 3.4V6.2h1.6l.6-4h2.4l.6 4h1.6v15.4zM5.4 15.6v3.2h2.8v-3.2zm5.2 0v3.2h2.8v-3.2zm5.2 0v3.2h2.8v-3.2z"/>',
];

/** Renderiza um glifo como <svg> inline (conteúdo confiável, definido acima). */
$glyph = static function (array $set, string $id, string $cls = ''): string {
    if (!isset($set[$id])) { return ''; }
    return '<svg class="gl' . ($cls !== '' ? ' ' . e($cls) : '') . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="currentColor">' . $set[$id] . '</svg>';
};

/* --- marcadores: separa zonas de risco ------------------------------------ */
$risks = [];
foreach ($markers as $m) {
    if (!empty($m['risk']) && is_array($m['risk'])) { $risks[] = $m['risk'] + ['layer' => (string)($m['layer'] ?? 'veyra')]; }
}
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
            <span class="rrow__icon"><?= $glyph($REGION_GLYPHS, (string)($r['icon'] ?? 'pine')) ?></span>
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

      <!-- ---- CAMADAS (PRD v3 §23.9: chips, sem novo painel) ---- -->
      <?php if ($filters !== []): ?>
      <div class="mgroup mgroup--layers">
        <h2 class="mgroup__title"><?= e((string)($data['filters_title'] ?? 'CAMADAS')) ?></h2>
        <div class="mlayers" data-select-group data-map-filters role="group" aria-label="<?= e((string)($data['filters_title'] ?? 'CAMADAS')) ?>">
          <?php foreach ($filters as $k => $f): ?>
          <button class="chip mlayer<?= $k === 0 ? ' is-active' : '' ?>" type="button" data-select data-value="<?= e((string)$f['slug']) ?>" aria-pressed="<?= $k === 0 ? 'true' : 'false' ?>"><?= e((string)$f['label']) ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- ---- LEGENDA ---- -->
      <div class="mgroup mgroup--legend" id="mapa-legend">
        <h2 class="mgroup__title"><?= e((string)($data['legend_title'] ?? 'LEGENDA')) ?></h2>
        <ul class="mlegend">
          <?php foreach ($legend as $l): ?>
          <li class="mlegend__i">
            <span class="mlegend__ic mlegend__ic--<?= e((string)($l['color'] ?? 'lime')) ?>"><?= $glyph($GLYPHS, (string)$l['glyph']) ?></span>
            <span class="mlegend__label"><?= e((string)$l['label']) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
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
    <div class="map3d" id="map3d" data-map data-region="<?= e((string)($data['active_region'] ?? '')) ?>">

      <img class="map3d__img" id="map3d-img" src="<?= e(media((string)($data['map_image'] ?? 'bg/map-island.jpg'))) ?>"
           alt="<?= e((string)($data['map_alt'] ?? 'Mapa do mundo')) ?>" width="1376" height="768" decoding="async">
      <canvas class="map3d__canvas" id="map3d-canvas" aria-hidden="true"></canvas>
      <span class="map3d__vignette" aria-hidden="true"></span>

      <div class="map3d__layer" id="map3d-layer">
        <?php foreach ($risks as $i => $rk): ?>
        <span class="mrisk" aria-hidden="true"
              data-risk="<?= e((string)$i) ?>" data-layer="<?= e((string)($rk['layer'] ?? 'veyra')) ?>"
              style="--x:<?= e((string)$rk['x']) ?>%;--y:<?= e((string)$rk['y']) ?>%;--rx:<?= e((string)$rk['rx']) ?>%"></span>
        <?php endforeach; ?>

        <?php foreach ($markers as $m): ?>
        <?php $lab = $m['label'] ?? false; ?>
        <?php $g = isset($GLYPHS[(string)$m['glyph']]) ? (string)$m['glyph'] : 'event'; ?>
        <div class="mk mk--<?= e((string)$m['color']) ?><?= $lab === 'right' ? ' mk--lab-right' : '' ?><?= !empty($m['narrow']) ? ' mk--lab-narrow' : '' ?><?= !empty($m['pulse']) ? ' mk--pulse' : '' ?><?= !empty($m['blocked']) ? ' mk--blocked' : '' ?>"
             data-marker="<?= e((string)$m['id']) ?>" data-x="<?= e((string)$m['x']) ?>" data-y="<?= e((string)$m['y']) ?>"
             data-kind="<?= e((string)($m['kind'] ?? $g)) ?>" data-layer="<?= e((string)($m['layer'] ?? 'eventos')) ?>"<?= !empty($m['expires']) ? ' data-expires="' . e((string)(int)$m['expires']) . '"' : '' ?><?= !empty($m['tip']) ? ' data-tip="' . e((string)$m['tip']) . '"' : '' ?>
             style="--x:<?= e((string)$m['x']) ?>%;--y:<?= e((string)$m['y']) ?>%">
          <span class="mk__glyph mk__glyph--<?= e($g) ?>"><?= $glyph($GLYPHS, $g) ?></span>
          <?php if ($lab): ?>
          <span class="mk__label"><?= e((string)$m['name']) ?><?php if (!empty($m['sub'])): ?><small class="mk__sub mk__sub--<?= e((string)($m['sub_color'] ?? 'muted')) ?>"><?= e((string)$m['sub']) ?></small><?php endif; ?></span>
          <?php else: ?>
          <span class="sr-only"><?= e((string)$m['name']) ?><?= !empty($m['sub']) ? ' — ' . e((string)$m['sub']) : '' ?></span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="map3d__controls">
        <?php foreach ((array)($data['controls'] ?? []) as $c): ?>
        <button class="mctrl" type="button" data-map-action="<?= e((string)$c['action']) ?>"
                aria-label="<?= e((string)$c['label']) ?>" title="<?= e((string)$c['label']) ?>"><?= icon((string)$c['icon']) ?></button>
        <?php endforeach; ?>
      </div>

      <div class="map3d__compass" role="img" aria-label="<?= e((string)($data['compass']['label'] ?? 'Rosa dos ventos')) ?>">
        <span class="map3d__north"><?= e((string)($data['compass']['n'] ?? 'N')) ?></span>
        <svg class="map3d__rose" viewBox="0 0 100 100" aria-hidden="true" focusable="false">
          <defs>
            <linearGradient id="mapRoseA" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0" stop-color="#f2f6ef"/><stop offset="1" stop-color="#8d968a"/>
            </linearGradient>
            <linearGradient id="mapRoseB" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0" stop-color="#4d554b"/><stop offset="1" stop-color="#98a194"/>
            </linearGradient>
          </defs>
          <circle cx="50" cy="50" r="46" fill="none" stroke="rgba(232,237,230,.42)" stroke-width="1.1"/>
          <circle cx="50" cy="50" r="37" fill="none" stroke="rgba(232,237,230,.26)" stroke-width="1"/>
          <g stroke="rgba(232,237,230,.62)" stroke-width="1.5">
            <path d="M50 4.4v7.4M50 95.6v-7.4M4.4 50h7.4M95.6 50h-7.4"/>
          </g>
          <g stroke="rgba(232,237,230,.34)" stroke-width="1.3">
            <path d="m21.5 21.5 4.6 4.6M78.5 21.5l-4.6 4.6M21.5 78.5l4.6-4.6M78.5 78.5l-4.6-4.6"/>
          </g>
          <g fill="url(#mapRoseA)">
            <path d="M50 12 53.18 46.82 50 50Z"/>
            <path d="M50 88 46.82 53.18 50 50Z"/>
            <path d="M88 50 53.18 53.18 50 50Z"/>
            <path d="M12 50 46.82 46.82 50 50Z"/>
            <path d="M65.6 34.4 54.5 50 50 50Z"/>
            <path d="M65.6 65.6 50 54.5 50 50Z"/>
            <path d="M34.4 65.6 45.5 50 50 50Z"/>
            <path d="M34.4 34.4 50 45.5 50 50Z"/>
          </g>
          <g fill="url(#mapRoseB)">
            <path d="M50 12 46.82 46.82 50 50Z"/>
            <path d="M50 88 53.18 53.18 50 50Z"/>
            <path d="M88 50 53.18 46.82 50 50Z"/>
            <path d="M12 50 46.82 53.18 50 50Z"/>
            <path d="M65.6 34.4 50 45.5 50 50Z"/>
            <path d="M65.6 65.6 54.5 50 50 50Z"/>
            <path d="M34.4 65.6 50 54.5 50 50Z"/>
            <path d="M34.4 34.4 45.5 50 50 50Z"/>
          </g>
        </svg>
      </div>

    </div>
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
            <span class="rbase__ic"><?= $glyph($GLYPHS, 'gov-base') ?></span>
            <span class="rbase__n"><?= e((string)$b['name']) ?></span>
            <?php component('badge', ['kind' => 'base', 'value' => (string)$b['status'], 'class' => 'rbase__st']); ?>
          </li>
          <?php endforeach; ?>
          <?php foreach ($sites as $s): ?>
          <li class="rbase rbase--<?= e((string)$s['color']) ?> rbase--<?= e((string)$s['faction']) ?>"<?= $s['tip'] !== '' ? ' data-tip="' . e((string)$s['tip']) . '"' : '' ?>>
            <span class="rbase__ic"><?= $glyph($GLYPHS, (string)$s['faction']) ?></span>
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
                elseif ($st === 'active') { echo $glyph($GLYPHS, 'extraction'); }
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
          <span class="revent__ic"><?= $glyph($GLYPHS, 'event') ?></span>
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
