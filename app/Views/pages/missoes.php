<?php declare(strict_types=1);
/**
 * Tela MISSÕES / CONTRATOS (sem referência; identidade das telas 01-inicio e 03-lobby).
 *
 * $data: title, title_tag, subtitle, list_title, detail_title, filters_title, types_title,
 *        filters[], types[], reset{}, board{}, summary[], items[], empty{}, labels{}, cta, cta_href.
 * Escopo da view: $page, $player, $nav, $server, $data, $flash, $csrf.
 */

$items   = (array)($data['items'] ?? []);
$filters = (array)($data['filters'] ?? []);
$types   = (array)($data['types'] ?? []);
$labels  = (array)($data['labels'] ?? []);
$reset   = (array)($data['reset'] ?? []);
$board   = (array)($data['board'] ?? []);

/** Missão selecionada (fallback: a primeira). */
$selected = $items[0]['slug'] ?? '';
foreach ($items as $it) { if (!empty($it['selected'])) { $selected = (string)$it['slug']; break; } }

/** hh:mm:ss a partir de segundos. */
$hms = static function (int $s): string {
    $s = max(0, $s);
    return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
};

/** Pips de dificuldade (1..4). */
$pips = static function (int $level): string {
    $tone = [1 => 'lime', 2 => 'lime', 3 => 'amber', 4 => 'red'][$level] ?? 'lime';
    $out = '<span class="pips pips--' . $tone . '" aria-hidden="true">';
    for ($i = 1; $i <= 4; $i++) { $out .= '<i class="pips__i' . ($i <= $level ? ' is-on' : '') . '"></i>'; }
    return $out . '</span>';
};
?>
<div class="missions">

  <!-- ====================== CABEÇALHO ====================== -->
  <header class="missions-head" data-boot>
    <div class="missions-head__id">
      <h1 class="page-title"><?= e((string)($data['title'] ?? 'MISSÕES')) ?><small><?= e((string)($data['title_tag'] ?? '')) ?></small></h1>
      <p class="page-subtitle"><?= e((string)($data['subtitle'] ?? '')) ?></p>
    </div>
    <div class="missions-head__meta">
      <?php foreach ((array)($data['summary'] ?? []) as $s): ?>
      <span class="hstat">
        <span class="hstat__val num" data-count="<?= e((string)(int)$s['value']) ?>" data-dur="1100"><?= e(num((int)$s['value'])) ?></span>
        <span class="hstat__label"><?= e((string)$s['label']) ?></span>
      </span>
      <?php endforeach; ?>
      <a class="hstat hstat--link" href="<?= e(url('desafios')) ?>">
        <span class="hstat__val hstat__val--ic"><?= icon('flag') ?></span>
        <span class="hstat__label">DESAFIOS</span>
      </a>
    </div>
  </header>

  <div class="missions-grid">

    <!-- ====================== COLUNA ESQUERDA ====================== -->
    <aside class="missions__left">

      <section class="panel filters-panel">
        <header class="panel__head">
          <h2 class="panel__title"><?= e((string)($data['filters_title'] ?? 'FILTROS')) ?></h2>
        </header>
        <div class="panel__body panel__body--tight">
          <div class="filters" data-select-group data-filters>
            <?php foreach ($filters as $k => $f): ?>
            <button class="row filter-row<?= $k === 0 ? ' is-active' : '' ?>" type="button" data-select data-value="<?= e((string)$f['slug']) ?>" aria-pressed="<?= $k === 0 ? 'true' : 'false' ?>">
              <?= icon((string)($f['icon'] ?? 'flag'), 'row__icon filter-row__ic') ?>
              <span class="row__text"><strong><?= e((string)$f['label']) ?></strong></span>
              <span class="row__meta num filter-row__count"><?= e((string)(int)($f['count'] ?? 0)) ?></span>
            </button>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <section class="panel types-panel">
        <header class="panel__head">
          <h2 class="panel__title"><?= e((string)($data['types_title'] ?? 'TIPO DE CONTRATO')) ?></h2>
        </header>
        <div class="panel__body panel__body--tight">
          <div class="types" data-select-group="multi" data-types>
            <?php foreach ($types as $t): ?>
            <button class="chip chip--<?= e((string)($t['color'] ?? 'lime')) ?> type-chip is-active" type="button" data-select data-value="<?= e((string)$t['slug']) ?>" aria-pressed="true">
              <i class="type-chip__dot" aria-hidden="true"></i><?= e((string)$t['label']) ?>
            </button>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <section class="panel reset-panel">
        <div class="panel__body">
          <span class="reset__label"><?= icon('schedule', 'reset__ic') ?><?= e((string)($reset['title'] ?? '')) ?></span>
          <span class="reset__clock num" data-countdown="<?= e((string)(int)($reset['seconds'] ?? 0)) ?>"><?= e($hms((int)($reset['seconds'] ?? 0))) ?></span>
          <small class="reset__note"><?= e((string)($reset['note'] ?? '')) ?></small>
        </div>
      </section>

      <section class="panel board-panel">
        <header class="panel__head">
          <h2 class="panel__title"><?= e((string)($board['title'] ?? '')) ?></h2>
        </header>
        <div class="panel__body">
          <div class="board__row">
            <span class="board__label"><?= e((string)($board['label'] ?? '')) ?></span>
            <span class="board__val num"><?= e((string)(int)($board['done'] ?? 0)) ?><small>/<?= e((string)(int)($board['total'] ?? 0)) ?></small></span>
          </div>
          <?php $bv = ((int)($board['total'] ?? 0)) > 0 ? round((int)$board['done'] / (int)$board['total'] * 100, 1) : 0; ?>
          <div class="progress progress--anim board__bar" style="--v:<?= e((string)$bv) ?>%"><div class="progress__bar"></div></div>
          <div class="board__row board__row--sub">
            <span class="board__label"><?= icon('local_fire_department', 'board__fire') ?><?= e((string)($board['streak']['label'] ?? '')) ?></span>
            <span class="board__streak"><?= e((string)($board['streak']['value'] ?? '')) ?></span>
          </div>
        </div>
      </section>

    </aside>

    <!-- ====================== COLUNA CENTRAL ====================== -->
    <section class="panel missions__list">
      <header class="panel__head">
        <h2 class="panel__title"><?= e((string)($data['list_title'] ?? 'QUADRO DE CONTRATOS')) ?></h2>
        <span class="panel__meta"><span class="num" data-list-count><?= e((string)count($items)) ?></span> <span data-list-word><?= count($items) === 1 ? 'CONTRATO' : 'CONTRATOS' ?></span></span>
      </header>
      <div class="panel__body panel__body--list">
        <div class="mlist scroll-y" data-select-group data-list>
          <?php foreach ($items as $k => $m):
              $st  = (string)($m['status'] ?? 'available');
              $sel = (string)$m['slug'] === $selected;
              $rw  = (array)($m['rewards'] ?? []);
              $pg  = (array)($m['progress'] ?? []);
          ?>
          <button class="mcard mcard--<?= e($st) ?><?= $sel ? ' is-active' : '' ?>" type="button" data-boot data-select
                  data-value="<?= e((string)$m['slug']) ?>" data-status="<?= e($st) ?>" data-type="<?= e((string)($m['type'] ?? '')) ?>"
                  aria-pressed="<?= $sel ? 'true' : 'false' ?>">
            <span class="mcard__media">
              <img class="mcard__img" src="<?= e(media((string)$m['image'])) ?>" alt="" loading="lazy" decoding="async">
              <span class="mcard__shade" aria-hidden="true"></span>
              <span class="mcard__type mcard__type--<?= e((string)($m['type'] ?? 'contrato')) ?>"><?= e((string)($m['type_label'] ?? '')) ?></span>
              <?php if ($st === 'locked'): ?><span class="mcard__lock"><?= icon('lock') ?></span><?php endif; ?>
              <?php if ($st === 'done'): ?><span class="mcard__done"><?= icon('check_circle', 'fill') ?></span><?php endif; ?>
            </span>

            <span class="mcard__body">
              <span class="mcard__top">
                <strong class="mcard__title"><?= e((string)$m['title']) ?></strong>
                <span class="mcard__status mcard__status--<?= e($st) ?>"><i aria-hidden="true"></i><?= e((string)($m['status_label'] ?? '')) ?></span>
              </span>
              <span class="mcard__sub"><?= e((string)$m['faction']) ?> <i aria-hidden="true">•</i> <?= e((string)$m['region']) ?></span>

              <span class="mcard__meta">
                <span class="mmeta">
                  <span class="mmeta__label"><?= e((string)($labels['difficulty'] ?? 'DIFICULDADE')) ?></span>
                  <span class="mmeta__val"><?= $pips((int)($m['difficulty_level'] ?? 1)) ?><?= e((string)$m['difficulty']) ?></span>
                </span>
                <span class="mmeta">
                  <span class="mmeta__label"><?= e((string)($labels['duration'] ?? 'DURAÇÃO')) ?></span>
                  <span class="mmeta__val"><?= icon('schedule', 'mmeta__ic') ?><?= e((string)$m['duration']) ?></span>
                </span>
                <span class="mmeta">
                  <span class="mmeta__label"><?= e((string)($labels['players'] ?? 'JOGADORES')) ?></span>
                  <span class="mmeta__val"><?= icon('group', 'mmeta__ic') ?><span class="num"><?= e((string)(int)$m['players']) ?></span></span>
                </span>
              </span>

              <span class="mcard__foot">
                <span class="mrewards">
                  <?php foreach ($rw as $r): ?>
                  <span class="mrw mrw--<?= e((string)($r['type'] ?? 'xp')) ?>">
                    <span class="mrw__ring"><?php if (($r['icon'] ?? 'xp') === 'xp'): ?><span class="mrw__xp">XP</span><?php else: ?><?= svg((string)$r['icon'], 'mrw__ic') ?><?php endif; ?></span>
                    <span class="mrw__val num"><?= e((string)$r['text']) ?></span>
                  </span>
                  <?php endforeach; ?>
                  <?php $it = (array)($m['reward_item'] ?? []); ?>
                  <?php if ($it !== []): ?>
                  <span class="mrw mrw--item" data-tip="<?= e((string)$it['name']) ?> — <?= e((string)$it['desc']) ?>">
                    <img class="mrw__img" src="<?= e(media((string)$it['image'])) ?>" alt="" loading="lazy" decoding="async">
                  </span>
                  <?php endif; ?>
                </span>

                <span class="mcard__state">
                  <?php if ($st === 'active' && $pg !== []): ?>
                  <span class="mprog">
                    <span class="mprog__num num"><?= e((string)(int)$pg['cur']) ?> / <?= e((string)(int)$pg['max']) ?></span>
                    <?php $pv = (int)$pg['max'] > 0 ? round((int)$pg['cur'] / (int)$pg['max'] * 100, 1) : 0; ?>
                    <span class="progress progress--anim mprog__bar" style="--v:<?= e((string)$pv) ?>%"><span class="progress__bar"></span></span>
                  </span>
                  <?php elseif (!empty($m['expires_in'])): ?>
                  <span class="mexp"><?= icon('schedule', 'mexp__ic') ?><span class="num" data-countdown="<?= e((string)(int)$m['expires_in']) ?>"><?= e($hms((int)$m['expires_in'])) ?></span></span>
                  <?php elseif ($st === 'done'): ?>
                  <span class="mdone"><?= icon('check_circle', 'fill') ?><?= e((string)($m['status_label'] ?? '')) ?></span>
                  <?php elseif ($st === 'locked'): ?>
                  <span class="mlock"><?= icon('lock') ?>NÍVEL <?= e((string)(int)($m['level_req'] ?? 1)) ?></span>
                  <?php endif; ?>
                </span>
              </span>
            </span>
            <span class="mcard__edge" aria-hidden="true"></span>
          </button>
          <?php endforeach; ?>

          <p class="mlist__empty" hidden data-empty>
            <strong><?= e((string)($data['empty']['title'] ?? '')) ?></strong>
            <small><?= e((string)($data['empty']['desc'] ?? '')) ?></small>
          </p>
        </div>
      </div>
    </section>

    <!-- ====================== COLUNA DIREITA ====================== -->
    <aside class="missions__right">
      <?php foreach ($items as $m):
          $st  = (string)($m['status'] ?? 'available');
          $sel = (string)$m['slug'] === $selected;
          $pg  = (array)($m['progress'] ?? []);
          $it  = (array)($m['reward_item'] ?? []);
      ?>
      <div class="mdetail<?= $sel ? ' is-shown' : '' ?>" data-detail="<?= e((string)$m['slug']) ?>"<?= $sel ? '' : ' hidden' ?>>

        <section class="panel mission">
          <header class="panel__head">
            <h2 class="panel__title"><?= e((string)($data['detail_title'] ?? 'MISSÃO SELECIONADA')) ?></h2>
            <span class="panel__meta mission__tag mission__tag--<?= e($st) ?>"><?= e((string)($m['status_label'] ?? '')) ?></span>
          </header>
          <div class="panel__body">

            <a class="mission__media" href="<?= e(url('mapa')) ?>" data-tip="<?= e((string)($labels['map'] ?? 'VER NO MAPA')) ?>">
              <img class="mission__img" src="<?= e(media((string)$m['image'])) ?>" alt="<?= e((string)$m['title']) ?>" loading="lazy" decoding="async">
              <span class="mission__shade" aria-hidden="true"></span>
              <span class="mission__head">
                <strong class="mission__title"><?= e((string)$m['title']) ?></strong>
                <span class="mission__sub"><?= e((string)$m['faction']) ?></span>
              </span>
              <span class="mission__stats">
                <span class="mstat">
                  <span class="mstat__label"><?= e((string)($labels['difficulty'] ?? '')) ?></span>
                  <span class="mstat__val"><?= e((string)$m['difficulty']) ?></span>
                </span>
                <span class="mstat">
                  <span class="mstat__label"><?= e((string)($labels['duration'] ?? '')) ?></span>
                  <span class="mstat__val"><?= e((string)$m['duration']) ?></span>
                </span>
                <span class="mstat">
                  <span class="mstat__label"><?= e((string)($labels['players'] ?? '')) ?></span>
                  <span class="mstat__val num"><?= e((string)(int)$m['players']) ?></span>
                </span>
              </span>
            </a>

            <h3 class="mission__section"><?= e((string)($labels['objective'] ?? 'OBJETIVO')) ?></h3>
            <p class="mission__objective">
              <?php foreach ((array)($m['objective_lines'] ?? [(string)($m['objective'] ?? '')]) as $i => $line): ?>
              <?= $i ? '<br>' : '' ?><?= e((string)$line) ?>
              <?php endforeach; ?>
            </p>

            <?php if ($st === 'active' && $pg !== []): ?>
            <div class="mission__track">
              <span class="mission__track-head">
                <span class="mission__track-label"><?= e((string)$pg['label']) ?></span>
                <span class="mission__track-val num"><?= e((string)(int)$pg['cur']) ?><small>/<?= e((string)(int)$pg['max']) ?></small></span>
              </span>
              <?php $pv = (int)$pg['max'] > 0 ? round((int)$pg['cur'] / (int)$pg['max'] * 100, 1) : 0; ?>
              <div class="progress progress--anim" style="--v:<?= e((string)$pv) ?>%"><div class="progress__bar"></div></div>
            </div>
            <?php elseif ($st === 'locked' && !empty($m['unlock_hint'])): ?>
            <div class="mission__note mission__note--lock">
              <?= icon('lock', 'mission__note-ic') ?>
              <span><strong><?= e((string)($labels['locked'] ?? 'DESBLOQUEIO')) ?></strong><small><?= e((string)$m['unlock_hint']) ?></small></span>
            </div>
            <?php elseif ($st === 'done'): ?>
            <div class="mission__note mission__note--done">
              <?= icon('check_circle', 'fill mission__note-ic') ?>
              <span><strong>CONTRATO CONCLUÍDO</strong><small>Finalizado em <span class="num"><?= e((string)($m['completed_at'] ?? '')) ?></span>.</small></span>
            </div>
            <?php elseif (!empty($m['expires_in'])): ?>
            <div class="mission__note mission__note--time">
              <?= icon('schedule', 'mission__note-ic') ?>
              <span><strong><?= e((string)($labels['expires'] ?? 'EXPIRA EM')) ?></strong><small class="num" data-countdown="<?= e((string)(int)$m['expires_in']) ?>"><?= e($hms((int)$m['expires_in'])) ?></small></span>
            </div>
            <?php endif; ?>

            <div class="mission__facts">
              <span class="mfact">
                <span class="mfact__label"><?= e((string)($labels['region'] ?? 'REGIÃO')) ?></span>
                <span class="mfact__val"><?= icon('map', 'mfact__ic') ?><?= e((string)$m['region']) ?></span>
              </span>
              <span class="mfact">
                <span class="mfact__label"><?= e((string)($labels['type'] ?? 'TIPO')) ?></span>
                <span class="mfact__val mfact__val--<?= e((string)($m['type'] ?? '')) ?>"><?= e((string)($m['type_label'] ?? '')) ?></span>
              </span>
              <span class="mfact">
                <span class="mfact__label"><?= e((string)($labels['level'] ?? 'NÍVEL REC.')) ?></span>
                <span class="mfact__val num"><?= e((string)(int)($m['level_req'] ?? 1)) ?></span>
              </span>
            </div>

            <hr class="divider mission__rule">

            <h3 class="mission__section"><?= e((string)($labels['rewards'] ?? 'RECOMPENSAS')) ?></h3>
            <div class="rewards rewards--<?= e((string)count((array)($m['rewards'] ?? []))) ?>">
              <?php foreach ((array)($m['rewards'] ?? []) as $r): ?>
              <span class="reward reward--<?= e((string)($r['type'] ?? 'xp')) ?>">
                <span class="reward__ring"><?php if (($r['icon'] ?? 'xp') === 'xp'): ?><span class="reward__xp">XP</span><?php else: ?><?= svg((string)$r['icon'], 'reward__ic') ?><?php endif; ?></span>
                <span class="reward__val num"><?= e((string)$r['text']) ?></span>
              </span>
              <?php endforeach; ?>
              <?php if ($it !== []): ?>
              <span class="reward-item">
                <img class="reward-item__img" src="<?= e(media((string)$it['image'])) ?>" alt="" loading="lazy" decoding="async">
              </span>
              <span class="reward-item__text">
                <strong class="reward-item__name"><?= e((string)$it['name']) ?></strong>
                <small class="reward-item__desc"><?= e((string)$it['desc']) ?></small>
              </span>
              <?php endif; ?>
            </div>

          </div>
        </section>

        <div class="mission-cta">
          <?php if (!empty($m['cta_href'])): ?>
          <a class="btn btn--primary accept-btn" href="<?= e(url((string)$m['cta_href'])) ?>">
            <?= icon('check_circle', 'fill accept-btn__ic') ?><span class="accept-btn__label"><?= e((string)($m['cta'] ?? ($data['cta'] ?? 'ACEITAR CONTRATO'))) ?></span>
          </a>
          <?php else: ?>
          <span class="btn accept-btn accept-btn--off is-disabled" aria-disabled="true">
            <?= icon($st === 'done' ? 'check_circle' : 'lock', 'accept-btn__ic') ?><span class="accept-btn__label"><?= e((string)($m['cta'] ?? '')) ?></span>
          </span>
          <?php endif; ?>
          <a class="btn accept-map" href="<?= e(url('mapa')) ?>" aria-label="<?= e((string)($labels['map'] ?? 'VER NO MAPA')) ?>" data-tip="<?= e((string)($labels['map'] ?? 'VER NO MAPA')) ?>">
            <?= icon('map') ?>
          </a>
        </div>

      </div>
      <?php endforeach; ?>
    </aside>

  </div>
</div>
