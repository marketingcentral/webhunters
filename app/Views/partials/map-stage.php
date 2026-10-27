<?php declare(strict_types=1);
/**
 * PALCO DO MAPA — o <div class="map3d" id="map3d"> com arte, marcadores, controles e bússola.
 *
 * É UM PARCIAL, E NÃO PARTE DE pages/mapa.php, POR UMA RAZÃO SÓ: a página de divulgação (/)
 * desenha o MESMO mapa que o jogador vê na HUD — a mesma arte, os mesmos 43 pontos vindos de
 * `map_markers`, as mesmas camadas, o mesmo zoom (assets/js/three/map3d.js) e o mesmo CSS
 * (assets/css/pages/mapa.css, tudo sob `.page-mapa`). Duplicar o markup faria os dois mapas
 * divergirem na primeira edição; um parcial faz a divergência ser impossível.
 *
 * Variáveis (além do escopo da view):
 *   $markers        list<array> já decorados por App\Core\WorldMap::decorate()
 *   $opacidade_form bool — renderiza o controle de opacidade que grava na CONTA. Só a HUD
 *                   quer isso (precisa de sessão); a landing usa o padrão do painel.
 *   $data           map_image, map_alt, active_region, map_opacity, world_labels, controls,
 *                   compass, map_opacity_padrao, map_opacity_min
 */

$markers = (array)($markers ?? ($data['markers'] ?? []));
$GLYPHS  = data_mock('map_glyphs');

/* --- marcadores: separa zonas de risco ------------------------------------ */
$risks = [];
foreach ($markers as $m) {
    if (!empty($m['risk']) && is_array($m['risk'])) { $risks[] = $m['risk'] + ['layer' => (string)($m['layer'] ?? 'veyra')]; }
}
?>
    <?php /* --map-art-opacity: quanto a arte recua para o texto da HUD ser lido por cima
             dela. Vem do banco (maps.meta.map_opacity), ajustado no editor do painel. */ ?>
    <div class="map3d" id="map3d" data-map data-region="<?= e((string)($data['active_region'] ?? '')) ?>"
         style="--map-art-opacity:<?= e((string)round((float)($data['map_opacity'] ?? .65), 2)) ?>">

      <img class="map3d__img" id="map3d-img" src="<?= e(media((string)($data['map_image'] ?? 'bg/map-island.jpg'))) ?>"
           alt="<?= e((string)($data['map_alt'] ?? 'Mapa do mundo')) ?>" width="941" height="1672" decoding="async">
      <canvas class="map3d__canvas" id="map3d-canvas" aria-hidden="true"></canvas>
      <span class="map3d__vignette" aria-hidden="true"></span>

      <div class="map3d__layer" id="map3d-layer">
        <?php /* nomes das regiões sobre o terreno (arte nova) — entram animados */ ?>
        <?php foreach ((array)($data['world_labels'] ?? []) as $wi => $wl): ?>
        <div class="wlabel" aria-hidden="true"
             style="--x:<?= e((string)$wl['x']) ?>%;--y:<?= e((string)$wl['y']) ?>%;--i:<?= (int)$wi ?>">
          <span class="wlabel__name"><?= e((string)$wl['name']) ?></span>
          <span class="wlabel__tag"><?= e((string)$wl['tag']) ?></span>
        </div>
        <?php endforeach; ?>

        <?php foreach ($risks as $i => $rk): ?>
        <span class="mrisk" aria-hidden="true"
              data-risk="<?= e((string)$i) ?>" data-layer="<?= e((string)($rk['layer'] ?? 'veyra')) ?>"
              style="--x:<?= e((string)$rk['x']) ?>%;--y:<?= e((string)$rk['y']) ?>%;--rx:<?= e((string)$rk['rx']) ?>%"></span>
        <?php endforeach; ?>

        <?php /* Sinal humano é posição APROXIMADA (PRD v3 §23.8): em vez de cravar o ponto,
                 o mapa desenha a área de incerteza, com o pulso indicando que é sinal vivo. */ ?>
        <?php foreach ($markers as $m): ?>
        <?php if (empty($m['radius'])) { continue; } ?>
        <span class="mzone mzone--<?= e((string)($m['kind'] ?? 'humana')) ?>" aria-hidden="true"
              data-layer="<?= e((string)($m['layer'] ?? 'humana')) ?>"
              style="--x:<?= e((string)$m['x']) ?>%;--y:<?= e((string)$m['y']) ?>%;--rx:<?= e((string)$m['radius']) ?>%"></span>
        <?php endforeach; ?>

        <?php foreach ($markers as $m): ?>
        <?php $lab = $m['label'] ?? false; ?>
        <?php $g = isset($GLYPHS[(string)$m['glyph']]) ? (string)$m['glyph'] : 'event'; ?>
        <div class="mk mk--<?= e((string)$m['color']) ?><?= $lab === 'right' ? ' mk--lab-right' : '' ?><?= !empty($m['narrow']) ? ' mk--lab-narrow' : '' ?><?= !empty($m['pulse']) ? ' mk--pulse' : '' ?><?= !empty($m['blocked']) ? ' mk--blocked' : '' ?>"
             data-marker="<?= e((string)$m['id']) ?>" data-x="<?= e((string)$m['x']) ?>" data-y="<?= e((string)$m['y']) ?>"
             data-kind="<?= e((string)($m['kind'] ?? $g)) ?>" data-layer="<?= e((string)($m['layer'] ?? 'eventos')) ?>"<?= !empty($m['expires']) ? ' data-expires="' . e((string)(int)$m['expires']) . '"' : '' ?><?php $dica = trim((string)$m['name'] . (!empty($m['sub']) ? ' · ' . $m['sub'] : '') . (!empty($m['tip']) ? ' — ' . $m['tip'] : '')); ?> data-tip="<?= e($dica) ?>"
             style="--x:<?= e((string)$m['x']) ?>%;--y:<?= e((string)$m['y']) ?>%">
          <span class="mk__glyph mk__glyph--<?= e($g) ?>"><?= map_point_icon($m, $g) ?></span>
          <?php if ($lab): ?>
          <?php /* no máximo DUAS linhas por marcador: nome + um subtítulo (o dizer, ou o
                   status quando não há dizer). O resto vai para o tooltip, senão o mapa
                   vira uma parede de texto. */ ?>
          <span class="mk__label"><?= e((string)$m['name']) ?><?php if (!empty($m['tag'])): ?><small class="mk__tag"><?= e((string)$m['tag']) ?></small><?php elseif (!empty($m['sub'])): ?><small class="mk__sub mk__sub--<?= e((string)($m['sub_color'] ?? 'muted')) ?>"><?= e((string)$m['sub']) ?></small><?php endif; ?></span>
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

        <?php if (!empty($opacidade_form)): ?>
        <?php
          /**
           * CONTROLE DE OPACIDADE DA ARTE — comprido, junto do zoom, porque é da mesma família:
           * são os três gestos de "deixar o mapa do jeito que eu leio".
           *
           * É um <form> de verdade, com botão de aplicar, e não um controle só de JavaScript:
           * sem script o jogador ainda ajusta e envia. Com script, o mapa reage ao arrastar e
           * o botão some (assets/js/pages/mapa.js) — o envio passa a ser automático.
           *
           * O padrão vem do painel; mexer aqui grava na CONTA e passa a valer em todo login.
           * O botão de voltar ao padrão só existe quando há o que desfazer.
           */
          $opAtual  = round((float)($data['map_opacity'] ?? .65) * 100);
          $opPadrao = round((float)($data['map_opacity_padrao'] ?? .65) * 100);
          $opMin    = round((float)($data['map_opacity_min'] ?? .15) * 100);
          $opPropria = (int)$opAtual !== (int)$opPadrao;
        ?>
        <form class="mopac" method="post" action="<?= e(url('mapa/opacidade')) ?>" data-map-opacity
              data-padrao="<?= (int)$opPadrao ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="regiao" value="<?= e((string)($data['active_region'] ?? '')) ?>">

          <span class="mopac__ic" aria-hidden="true"><?= icon('opacity') ?></span>

          <label class="mopac__wrap">
            <span class="sr-only">Opacidade da arte do mapa</span>
            <input class="mopac__range" type="range" name="valor_pct"
                   min="<?= (int)$opMin ?>" max="100" step="1" value="<?= (int)$opAtual ?>"
                   data-map-opacity-range
                   aria-describedby="mopac-valor">
          </label>

          <span class="mopac__v num" id="mopac-valor" data-map-opacity-out><?= (int)$opAtual ?>%</span>

          <?php /* `valor` é o que o servidor lê (fração 0..1). O <input type=range> trabalha em
                   porcentagem inteira porque é o que dá passo previsível no arrasto. */ ?>
          <input type="hidden" name="valor" value="<?= e((string)round((float)($data['map_opacity'] ?? .65), 2)) ?>" data-map-opacity-payload>

          <button class="mctrl mopac__apply" type="submit" data-map-opacity-apply
                  aria-label="Aplicar opacidade" title="Aplicar opacidade"><?= icon('check') ?></button>

          <button class="mctrl mopac__reset<?= $opPropria ? '' : ' is-hidden' ?>" type="submit"
                  name="reset" value="1" data-map-opacity-reset
                  aria-label="Voltar à opacidade padrão" title="Voltar à opacidade padrão"><?= icon('restart_alt') ?></button>
        </form>
        <?php endif; ?>
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
