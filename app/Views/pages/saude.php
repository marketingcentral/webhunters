<?php declare(strict_types=1);
/**
 * Tela SAÚDE (referência 06-saude.png).
 *
 * $data: overview_title, body_image, vitals[], body_title, body[], body_action, tabs[], active_tab,
 *        healing_title, healing[], exams_title, exams[], conditions_title, conditions[],
 *        status_groups[], details_title, details{health,regen}, history_title, history[], history_action.
 * Escopo da view: $page, $player, $nav, $server, $data, $flash, $csrf.
 */

$vitals     = (array)($data['vitals'] ?? []);
$parts      = (array)($data['body'] ?? []);
$healing    = (array)($data['healing'] ?? []);
$exams      = (array)($data['exams'] ?? []);
$conditions = (array)($data['conditions'] ?? []);
$groups     = (array)($data['status_groups'] ?? []);
$history    = (array)($data['history'] ?? []);
$details    = (array)($data['details'] ?? []);
$hp         = (array)($details['health'] ?? []);
$regen      = (array)($details['regen'] ?? []);
$activeTab  = (string)($data['active_tab'] ?? 'tratamento');

$colA = array_values(array_filter($parts, static fn (array $p): bool => ($p['col'] ?? 'a') === 'a'));
$colB = array_values(array_filter($parts, static fn (array $p): bool => ($p['col'] ?? 'a') === 'b'));

$bodyAct = (array)($data['body_action'] ?? []);
$histAct = (array)($data['history_action'] ?? []);
$bodyHref = url((string)($bodyAct['href'] ?? 'saude'), (array)($bodyAct['query'] ?? []));
$histHref = url((string)($histAct['href'] ?? 'perfil'));

/**
 * Pictogramas do ESTADO CORPORAL — desenhados aqui porque as ligaduras do
 * Material Symbols (capacete de perfil / estômago em traço fino) não leem
 * como capacete, colete e estômago no tamanho usado pela referência.
 */
$partPaths = [
    'head'    => 'M12 2.2C7.4 2.2 4 5.7 4 10.4v6.1c0 1.9 1.2 3.3 2.9 3.3h1.5l.6-3h6.1l.6 3H17c1.7 0 3-1.4 3-3.3v-6.1c0-4.7-3.4-8.2-8-8.2Zm-5.7 7h11.4c.2.7.3 1.4.3 2.2v1.4H6v-1.4c0-.8.1-1.5.3-2.2Z',
    'thorax'  => 'M12 2.3c-1.4 0-2.5.5-3.3 1L5 5.5C4 6.1 3.4 7.1 3.4 8.2v3.3h2.9v8.9c0 .6.5 1.1 1.1 1.1h9.2c.6 0 1.1-.5 1.1-1.1v-8.9h2.9V8.2c0-1.1-.6-2.1-1.6-2.7L15.3 3.3c-.8-.5-1.9-1-3.3-1Zm-.6 6.4h1.2v10.6h-1.2Z',
    'stomach' => 'M8.6 2.2c-.8 0-1.5.7-1.5 1.5v4C5.2 8.8 4 10.8 4 13.1c0 3.9 3.2 7.1 7.1 7.1 2.8 0 5.3-1.6 6.4-4.1.3-.8 0-1.7-.8-2-.8-.3-1.7 0-2 .8-.6 1.4-2 2.3-3.6 2.3-2.3 0-4.1-1.8-4.1-4.1 0-1.6.9-2.9 2.2-3.6.5-.3.8-.8.8-1.3V5.2h3.6c.8 0 1.5-.7 1.5-1.5s-.7-1.5-1.5-1.5H8.6Z',
];
$partIcon = static function (string $name) use ($partPaths): string {
    $d = $partPaths[$name] ?? '';
    if ($d === '') {
        return icon($name);
    }
    return '<svg class="ic bpart__svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
        . '<path fill="currentColor" fill-rule="evenodd" d="' . $d . '"/></svg>';
};
?>
<div class="saude">

  <!-- ====================== VISÃO GERAL (esquerda) ====================== -->
  <section class="panel sd-ov" data-boot>
    <header class="panel__head">
      <h1 class="panel__title"><?= e((string)($data['overview_title'] ?? 'VISÃO GERAL')) ?></h1>
    </header>
    <div class="panel__body">

      <div class="sd-ov__top">
        <figure class="sd-figure">
          <img class="sd-figure__img" src="<?= e(media((string)($data['body_image'] ?? 'ui/body-wireframe-clean.png'))) ?>"
               alt="<?= e((string)($data['body_image_alt'] ?? '')) ?>" width="200" height="453" decoding="async">
          <span class="sd-figure__scan" aria-hidden="true"></span>
        </figure>

        <ul class="vitals" data-stagger>
          <?php foreach ($vitals as $v): ?>
          <?php $cls = (string)($v['cls'] ?? 'lime'); $dec = (int)($v['dec'] ?? 0); ?>
          <li class="vital vital--<?= e($cls) ?>" data-vital="<?= e((string)$v['key']) ?>">
            <span class="vital__ic" aria-hidden="true"><?= icon((string)$v['icon'], 'fill') ?></span>
            <span class="vital__head">
              <span class="vital__label"><?= e((string)$v['label']) ?></span>
              <span class="vital__val num" data-vital-val><?= e(num((float)$v['value'], $dec)) ?><?php
                if (isset($v['max']) && $v['max'] !== null): ?><small>/<?= e(num((float)$v['max'])) ?></small><?php
                elseif (!empty($v['unit'])): ?><small class="unit"><?= e((string)$v['unit']) ?></small><?php endif; ?></span>
            </span>
            <?php component('progress', ['pct' => (float)($v['pct'] ?? 0), 'color' => $cls === 'lime' ? '' : $cls, 'anim' => true, 'class' => 'vital__bar']) ?>
            <span class="vital__cap"><?= e((string)$v['status']) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <hr class="divider sd-ov__rule">

      <h2 class="sd-sec sd-sec--plain"><?= e((string)($data['body_title'] ?? 'ESTADO CORPORAL')) ?></h2>

      <div class="bodyparts" data-stagger>
        <?php foreach (['a' => $colA, 'b' => $colB] as $key => $col): ?>
        <div class="bodyparts__col bodyparts__col--<?= e((string)$key) ?>">
          <?php foreach ($col as $p): ?>
          <div class="bpart<?= !empty($p['icon']) ? ' bpart--icon' : '' ?>" data-boot>
            <?php if (!empty($p['icon'])): ?>
            <span class="bpart__ic" aria-hidden="true"><?= $partIcon((string)$p['icon']) ?></span>
            <?php endif; ?>
            <span class="bpart__label"><?= e((string)$p['label']) ?></span>
            <span class="bpart__val num"><?= e(num((float)$p['cur'])) ?><small>/<?= e(num((float)$p['max'])) ?></small></span>
            <?php component('progress', ['value' => (float)$p['cur'], 'max' => (float)$p['max'], 'anim' => true, 'class' => 'bpart__bar']) ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <a class="keybtn" href="<?= e($bodyHref) ?>" id="sd-body-detail">
        <span class="keybtn__key num" aria-hidden="true"><?= e((string)($bodyAct['key'] ?? 'X')) ?></span>
        <span class="keybtn__label"><?= e((string)($bodyAct['label'] ?? 'VER DETALHES DO CORPO')) ?></span>
      </a>

    </div>
  </section>

  <!-- ====================== TRATAMENTO / STATUS (centro) ====================== -->
  <section class="panel sd-mid" data-boot data-tabs-scope>
    <nav class="tabs sd-tabs" aria-label="Seções da saúde">
      <?php foreach ((array)($data['tabs'] ?? []) as $t): ?>
      <?php $slug = (string)$t['slug']; $on = $slug === $activeTab; ?>
      <a class="tab<?= $on ? ' is-active' : '' ?>" href="<?= e(url('saude', ['aba' => $slug])) ?>" data-tab="<?= e($slug) ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= e((string)$t['label']) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="panel__body">

      <!-- ---------- aba TRATAMENTO ---------- -->
      <div class="sd-pane" data-tab-panel="tratamento"<?= $activeTab === 'tratamento' ? '' : ' hidden' ?>>

        <h2 class="sd-sec"><?= e((string)($data['healing_title'] ?? 'ITENS DE CURA')) ?></h2>
        <div class="mcards" data-stagger>
          <?php foreach ($healing as $h): ?>
          <article class="mcard mcard--heal" data-boot data-heal="<?= e((string)$h['heal']) ?>" data-name="<?= e((string)$h['name']) ?>" data-img="<?= e(media((string)$h['image'])) ?>">
            <h3 class="mcard__name"><?= e((string)$h['name']) ?></h3>
            <div class="mcard__media">
              <img class="mcard__img" src="<?= e(media((string)$h['image'])) ?>" alt="" loading="lazy" decoding="async">
            </div>
            <p class="mcard__count num"><span data-count-cur><?= e((string)$h['count']) ?></span>/<?= e((string)$h['total']) ?></p>
            <p class="mcard__desc"><?= e((string)$h['desc']) ?></p>
            <button class="mcard__btn" type="button" data-use aria-label="<?= e((string)$h['cta'] . ' ' . (string)$h['name']) ?>"><?= e((string)$h['cta']) ?></button>
          </article>
          <?php endforeach; ?>
        </div>

        <h2 class="sd-sec"><?= e((string)($data['exams_title'] ?? 'EXAMES MÉDICOS')) ?></h2>
        <div class="mcards" data-stagger>
          <?php foreach ($exams as $x): ?>
          <article class="mcard mcard--exam" data-boot data-result="<?= e((string)$x['result']) ?>" data-running="<?= e((string)($data['exam_running'] ?? 'ANALISANDO')) ?>">
            <h3 class="mcard__name"><?= e((string)$x['name']) ?></h3>
            <div class="mcard__media">
              <img class="mcard__img" src="<?= e(media((string)$x['image'])) ?>" alt="" loading="lazy" decoding="async">
              <span class="mcard__scan" aria-hidden="true"></span>
            </div>
            <p class="mcard__desc"><?= e((string)$x['desc']) ?></p>
            <button class="mcard__btn" type="button" data-exam aria-label="<?= e((string)$x['cta'] . ' ' . (string)$x['name']) ?>"><span class="mcard__btn-lbl"><?= e((string)$x['cta']) ?></span></button>
          </article>
          <?php endforeach; ?>
        </div>

        <h2 class="sd-sec"><?= e((string)($data['conditions_title'] ?? 'DOENÇAS E CONDIÇÕES')) ?></h2>
        <div class="conds" data-stagger>
          <?php foreach ($conditions as $c): ?>
          <div class="cond cond--<?= e((string)($c['level'] ?? 'ok')) ?>" data-boot>
            <span class="cond__ic" aria-hidden="true"><?= icon((string)$c['icon']) ?></span>
            <span class="cond__text">
              <strong class="cond__title"><?= e((string)$c['title']) ?></strong>
              <small class="cond__desc"><?= e((string)$c['desc']) ?></small>
            </span>
          </div>
          <?php endforeach; ?>
        </div>

      </div>

      <!-- ---------- aba STATUS ---------- -->
      <div class="sd-pane" data-tab-panel="status"<?= $activeTab === 'status' ? '' : ' hidden' ?>>
        <?php foreach ($groups as $g): ?>
        <h2 class="sd-sec"><?= e((string)$g['title']) ?></h2>

        <?php if (($g['kind'] ?? '') === 'tiles'): ?>
        <div class="stiles" data-stagger>
          <?php foreach ((array)$g['items'] as $it): ?>
          <div class="stile stile--<?= e((string)($it['cls'] ?? 'lime')) ?>" data-boot>
            <span class="stile__ic" aria-hidden="true"><?= icon((string)$it['icon'], 'fill') ?></span>
            <span class="stile__label"><?= e((string)$it['label']) ?></span>
            <span class="stile__val num"><?= is_numeric($it['value']) ? e(num((float)$it['value'], (int)($it['dec'] ?? 0))) : e((string)$it['value']) ?><?php
              if (!empty($it['unit'])): $u = (string)$it['unit']; ?><small<?= str_starts_with(ltrim($u), '°') ? ' class="unit"' : '' ?>><?= e($u) ?></small><?php endif; ?></span>
            <span class="stile__cap"><?= e((string)$it['caption']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>

        <?php elseif (($g['kind'] ?? '') === 'bars'): ?>
        <div class="sbars" data-stagger>
          <?php foreach ((array)$g['items'] as $it): ?>
          <div class="sbar sbar--<?= e((string)($it['cls'] ?? 'lime')) ?>" data-boot>
            <span class="sbar__label"><?= e((string)$it['label']) ?></span>
            <span class="sbar__val num"><?= e(num((float)$it['value'])) ?><small>%</small></span>
            <?php component('progress', ['value' => (float)$it['value'], 'max' => (float)$it['max'], 'color' => ($it['cls'] ?? 'lime') === 'lime' ? '' : (string)$it['cls'], 'anim' => true, 'class' => 'sbar__bar']) ?>
            <span class="sbar__cap"><?= e((string)$it['caption']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>

        <?php else: ?>
        <div class="conds" data-stagger>
          <?php foreach ((array)$g['items'] as $it): ?>
          <div class="cond cond--eff cond--<?= e((string)($it['cls'] ?? 'lime')) ?>" data-boot>
            <span class="cond__ic" aria-hidden="true"><?= icon((string)$it['icon'], 'fill') ?></span>
            <span class="cond__text">
              <strong class="cond__title"><?= e((string)$it['title']) ?></strong>
              <small class="cond__desc"><?= e((string)$it['desc']) ?></small>
            </span>
            <span class="cond__meta num" data-countdown="<?= e((string)$it['left']) ?>"><?= e((string)$it['meta']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>

    </div>
  </section>

  <!-- ====================== DETALHES (direita) ====================== -->
  <aside class="panel sd-det" data-boot>
    <header class="panel__head">
      <h2 class="panel__title"><?= e((string)($data['details_title'] ?? 'DETALHES')) ?></h2>
    </header>
    <div class="panel__body">

      <div class="sd-hero">
        <span class="sd-hero__badge">
          <?php component('hex', ['size' => 'xl', 'color' => 'lime', 'class' => 'sd-hero__hex']) ?>
          <svg class="sd-hero__cross" viewBox="0 0 48 48" aria-hidden="true" focusable="false">
            <path class="sd-hero__cross-fill" d="M18.6 5h10.8c.9 0 1.6.7 1.6 1.6v10.8h10.8c.9 0 1.6.7 1.6 1.6v10.8c0 .9-.7 1.6-1.6 1.6H31v10.8c0 .9-.7 1.6-1.6 1.6H18.6c-.9 0-1.6-.7-1.6-1.6V31.4H6.2c-.9 0-1.6-.7-1.6-1.6V19c0-.9.7-1.6 1.6-1.6H17V6.6c0-.9.7-1.6 1.6-1.6Z"/>
            <path class="sd-hero__cross-cut" d="M23.2 12.6h1.6v25h-1.6z"/>
            <path class="sd-hero__cross-cut" d="M24 14.4c-3.1 1.4-3.1 4.6 0 6s3.1 4.6 0 6-3.1 4.6 0 6" fill="none" stroke-width="1.5" stroke-linecap="round"/>
            <path class="sd-hero__cross-cut" d="M24 13.7c-2.2-1.8-4.4-2.3-6.5-1.4 1.8 1.7 4 2.4 6.5 2.2Zm0 0c2.2-1.8 4.4-2.3 6.5-1.4-1.8 1.7-4 2.4-6.5 2.2Z"/>
          </svg>
        </span>
        <div class="sd-hero__id">
          <span class="sd-hero__label"><?= e((string)($hp['label'] ?? 'SAÚDE')) ?></span>
          <span class="sd-hero__val num"><span class="lime" id="sd-hp"><?= e(num((float)($hp['value'] ?? 100))) ?></span><small>/<?= e(num((float)($hp['max'] ?? 100))) ?></small></span>
        </div>
      </div>

      <p class="sd-hero__desc">
        <?php foreach ((array)($hp['desc_lines'] ?? []) as $k => $line): ?><?= $k ? '<br>' : '' ?><?= e((string)$line) ?><?php endforeach; ?>
      </p>

      <hr class="divider">

      <div class="sd-regen">
        <span class="sd-regen__ring" aria-hidden="true"><?= icon((string)($regen['icon'] ?? 'monitor_heart'), 'fill') ?></span>
        <span class="sd-regen__label"><?= e((string)($regen['label'] ?? 'REGENERAÇÃO')) ?></span>
        <span class="sd-regen__val num"><?= e(num((float)($regen['value'] ?? 0), 1)) ?><?= e((string)($regen['unit'] ?? '/s')) ?></span>
        <span class="sd-regen__desc"><?= e((string)($regen['desc'] ?? '')) ?></span>
      </div>

      <hr class="divider">

      <h2 class="sd-sec sd-sec--plain"><?= e((string)($data['history_title'] ?? 'ÚLTIMOS TRATAMENTOS')) ?></h2>

      <ul class="hist" id="sd-hist" data-stagger>
        <?php foreach ($history as $h): ?>
        <li class="hist__row" data-boot>
          <img class="hist__img" src="<?= e(media((string)$h['image'])) ?>" alt="" loading="lazy" decoding="async">
          <span class="hist__text">
            <strong class="hist__name"><?= e((string)$h['name']) ?></strong>
            <small class="hist__time num"><?= e((string)$h['time']) ?></small>
          </span>
          <span class="hist__eff num"><?= e((string)$h['effect']) ?></span>
        </li>
        <?php endforeach; ?>
      </ul>

      <a class="keybtn" href="<?= e($histHref) ?>" id="sd-history">
        <span class="keybtn__key num" aria-hidden="true"><?= e((string)($histAct['key'] ?? 'H')) ?></span>
        <span class="keybtn__label"><?= e((string)($histAct['label'] ?? 'HISTÓRICO COMPLETO')) ?></span>
      </a>

    </div>
  </aside>

</div>
