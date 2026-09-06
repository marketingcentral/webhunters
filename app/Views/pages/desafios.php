<?php declare(strict_types=1);
/**
 * Tela DESAFIOS (sem referência própria; âncoras de estilo: 02-jogar-solo-multplayer.png).
 *
 * $data vem inteiro do banco (ChallengesController + ChallengeRepo), conciliado por user_id:
 *   title, tag, intro, reset_label, reset_secs, tabs[], active_tab, groups{}, bonus{},
 *   featured, ring, pass.
 * Prazos chegam em SEGUNDOS (`*_secs`) — quem formata é desafios.js, para a contagem
 * regressiva ser viva. Sem JS a view já imprime o texto inicial correto.
 *
 * Layout: 2 colunas (quadro de desafios | anel semanal + passe de temporada).
 * As abas trocam no cliente (hud.js: .tab[data-tab] + [data-tab-panel] dentro de
 * [data-tabs-scope]); cada aba também tem rota própria (/desafios/{aba}) para link direto.
 */
$tabs     = is_array($data['tabs'] ?? null) ? $data['tabs'] : [];
$groups   = is_array($data['groups'] ?? null) ? $data['groups'] : [];
$featured = is_array($data['featured'] ?? null) ? $data['featured'] : [];
$bonuses  = is_array($data['bonus'] ?? null) ? $data['bonus'] : [];
$ring     = is_array($data['ring'] ?? null) ? $data['ring'] : [];
$pass     = is_array($data['pass'] ?? null) ? $data['pass'] : [];
$active   = (string)($data['active_tab'] ?? 'diarios');

/** "12h 45m 09s" / "3d 12h 44m" — mesmo formato que desafios.js usa ao atualizar. */
$span = static function (int $total): string {
    $t = max(0, $total);
    $p = static fn (int $n): string => str_pad((string)$n, 2, '0', STR_PAD_LEFT);
    if ($t >= 86400) {
        return intdiv($t, 86400) . 'd ' . $p(intdiv($t % 86400, 3600)) . 'h ' . $p(intdiv($t % 3600, 60)) . 'm';
    }
    if ($t >= 3600) {
        return intdiv($t, 3600) . 'h ' . $p(intdiv($t % 3600, 60)) . 'm ' . $p($t % 60) . 's';
    }
    return intdiv($t, 60) . 'm ' . $p($t % 60) . 's';
};

/** Arco do anel semanal: um segmento por desafio da meta, os concluídos acesos. */
$ringDone  = max(0, (int)($ring['done'] ?? 0));
$ringTotal = max(1, (int)($ring['total'] ?? 1));
$ringDone  = min($ringDone, $ringTotal);
$ringPct   = min(100, (int)round($ringDone / $ringTotal * 100));
$arc = static function (int $i, int $total): string {
    $step = 360 / $total;
    $gap  = 5.2;
    $a1   = -90 + ($i * $step) + $gap;
    $a2   = -90 + (($i + 1) * $step) - $gap;
    $p    = static function (float $deg): array {
        $r = deg2rad($deg);
        return [60 + 50 * cos($r), 60 + 50 * sin($r)];
    };
    [$x1, $y1] = $p($a1);
    [$x2, $y2] = $p($a2);
    $n = static fn (float $v): string => number_format($v, 2, '.', '');
    return 'M ' . $n($x1) . ' ' . $n($y1) . ' A 50 50 0 0 1 ' . $n($x2) . ' ' . $n($y2);
};

$passLvl  = (int)($pass['level'] ?? 0);
$passXp   = (int)($pass['xp'] ?? 0);
$passNext = max(1, (int)($pass['xp_next'] ?? 1));
$passPct  = min(100, (int)round($passXp / $passNext * 100));

$fCur = (int)($featured['cur'] ?? 0);
$fMax = max(1, (int)($featured['max'] ?? 1));
$fPct = min(100, (int)round($fCur / $fMax * 100));

$headSecs = (int)($data['reset_secs'] ?? 0);
?>
<div class="layout-2 layout-2--r desafios">

  <!-- ======================= COLUNA PRINCIPAL ============================ -->
  <div class="col des-main">

    <header class="des-head" data-boot>
      <div class="des-head__text">
        <h1 class="page-title"><?= e((string)($data['title'] ?? 'DESAFIOS')) ?><span class="page-title__tag"><?= e((string)($data['tag'] ?? '')) ?></span></h1>
        <p class="page-subtitle"><?= e((string)($data['intro'] ?? '')) ?></p>
      </div>
      <div class="des-clock">
        <?= icon('schedule', 'des-clock__ic') ?>
        <span class="des-clock__text">
          <span class="des-clock__label" data-clock-label><?= e((string)($data['reset_label'] ?? 'REINICIA EM')) ?></span>
          <span class="des-clock__val num" data-clock data-countdown="<?= e((string)$headSecs) ?>"><?= e($span($headSecs)) ?></span>
        </span>
      </div>
    </header>

    <!-- destaque do evento -->
    <section class="des-banner" data-boot>
      <img class="des-banner__img" src="<?= e(media((string)($featured['image'] ?? 'missions/mission-cacada-ao-cacador.jpg'))) ?>" alt="">
      <span class="des-banner__shade" aria-hidden="true"></span>
      <div class="des-banner__body">
        <span class="des-banner__tag"><?= e((string)($featured['tag'] ?? '')) ?></span>
        <h2 class="des-banner__title"><?= e((string)($featured['title'] ?? '')) ?></h2>
        <p class="des-banner__desc"><?= e((string)($featured['desc'] ?? '')) ?></p>
      </div>
      <div class="des-banner__side">
        <div class="des-banner__clock">
          <span class="des-banner__clock-label"><?= e((string)($featured['ends_label'] ?? '')) ?></span>
<?php $evSecs = (int)($featured['ends_secs'] ?? 0); ?>
          <span class="des-banner__clock-val num" data-countdown="<?= e((string)$evSecs) ?>"><?= e($span($evSecs)) ?></span>
        </div>
        <div class="des-banner__prog">
          <div class="des-banner__prog-head">
            <span><?= e((string)($featured['meta_label'] ?? '')) ?></span>
            <span class="num"><?= e(num($fCur)) ?> / <?= e(num($fMax)) ?></span>
          </div>
          <span class="progress progress--anim des-banner__bar" style="--v:<?= e((string)$fPct) ?>%" role="progressbar" aria-label="<?= e((string)($featured['meta_label'] ?? 'Progresso do evento')) ?>" aria-valuemin="0" aria-valuemax="<?= e((string)$fMax) ?>" aria-valuenow="<?= e((string)$fCur) ?>"><span class="progress__bar"></span></span>
        </div>
        <?php component('btn', ['label' => (string)($featured['cta'] ?? 'VER MISSÕES'), 'href' => url((string)($featured['href'] ?? 'missoes')), 'variant' => 'outline', 'size' => 'sm', 'chev' => true, 'class' => 'des-banner__cta']) ?>
      </div>
    </section>

    <!-- quadro de desafios -->
    <section class="panel des-board panel--grow" data-boot data-tabs-scope>
      <header class="panel__head des-board__head">
        <?php
        $tabItems = [];
        foreach ($tabs as $t) {
            $key = (string)($t['key'] ?? '');
            $tabItems[] = [
                'label'  => (string)($t['label'] ?? ''),
                'panel'  => $key,   // sem href: aba que só troca painel é <button> (SPEC)
                'count'  => (string)($t['count'] ?? ''),
                'active' => $key === $active,
                'attrs'  => [
                    // id + aria-controls fecham o par aba/painel para o leitor de tela
                    // (o painel devolve o aria-labelledby lá embaixo).
                    'id'               => 'des-tab-' . $key,
                    'aria-controls'    => 'des-panel-' . $key,
                    'data-reset'       => (string)($t['seconds'] ?? '0'),
                    'data-reset-label' => (string)($t['reset_label'] ?? ''),
                    'data-tab-url'     => url('desafios/' . $key),
                ],
            ];
        }
        component('tabs', ['items' => $tabItems, 'class' => 'des-tabs', 'label' => 'Tipos de desafio']);
        ?>
        <a class="panel__action des-board__all" href="<?= e(url('conquistas')) ?>"><?= e((string)($data['board_action'] ?? 'VER CONQUISTAS')) ?><?= icon('chevron_right') ?></a>
      </header>

      <div class="panel__body des-board__body">
<?php foreach ($groups as $key => $g): ?>
        <div class="des-pane<?= $key === $active ? ' is-shown' : '' ?>" data-tab-panel="<?= e((string)$key) ?>" id="des-panel-<?= e((string)$key) ?>" role="tabpanel" aria-labelledby="des-tab-<?= e((string)$key) ?>" tabindex="0"<?= $key === $active ? '' : ' hidden' ?>>
<?php $rows = (array)($g['items'] ?? []); ?>
          <?php // 6+ linhas não cabem no quadro na altura padrão: variante compacta (evita corte) ?>
          <ul class="chal-list<?= count($rows) >= 6 ? ' chal-list--dense' : '' ?>" data-stagger data-rows="<?= e((string)count($rows)) ?>">
<?php foreach ($rows as $it): ?>
<?php
    $cur  = (int)($it['cur'] ?? 0);
    $max  = max(1, (int)($it['max'] ?? 1));
    $done = !empty($it['done']);
    $hot  = !empty($it['hot']);
    $pct  = (int)($it['pct'] ?? min(100, (int)round($cur / $max * 100)));
?>
            <li class="chal-item">
              <a class="chal<?= $done ? ' is-done' : '' ?><?= $hot ? ' is-hot' : '' ?>" href="<?= e(url((string)($g['href'] ?? 'jogar'))) ?>" data-slug="<?= e((string)($it['slug'] ?? '')) ?>">
                <span class="chal__icon" aria-hidden="true"><?= icon((string)($it['icon'] ?? 'flag')) ?></span>
                <span class="chal__main">
                  <strong class="chal__label"><?= e((string)($it['label'] ?? '')) ?></strong>
                  <span class="chal__desc"><?= e((string)($it['desc'] ?? '')) ?></span>
                  <span class="chal__prog">
                    <span class="progress progress--anim chal__bar" style="--v:<?= e((string)$pct) ?>%" role="progressbar" aria-label="<?= e((string)($it['label'] ?? 'Progresso')) ?>" aria-valuemin="0" aria-valuemax="<?= e((string)$max) ?>" aria-valuenow="<?= e((string)$cur) ?>"><span class="progress__bar"></span></span>
                    <span class="chal__count num"><?= e(num($cur)) ?><small> / <?= e(num($max)) ?></small></span>
                  </span>
                </span>
                <span class="chal__rw">
                  <span class="rw rw--xp"><span class="rw__badge">XP</span><span class="rw__val num"><?= e(num((int)($it['xp'] ?? 0))) ?></span></span>
                  <span class="rw rw--cr"><?= svg('coin-credit', 'rw__ic') ?><span class="rw__val num"><?= e(num((int)($it['credits'] ?? 0))) ?></span></span>
                </span>
                <span class="chal__state" aria-hidden="true"><?= $done ? icon('check_circle', 'chal__check') : icon('chevron_right', 'chal__chev') ?></span>
              </a>
            </li>
<?php endforeach; ?>
          </ul>

<?php $b = is_array($bonuses[$key] ?? null) ? $bonuses[$key] : []; ?>
<?php if ($b): ?>
<?php
    $bCur     = (int)($b['cur'] ?? 0);
    $bMax     = max(1, (int)($b['max'] ?? 1));
    $bPct     = min(100, (int)round($bCur / $bMax * 100));
    $bReady   = !empty($b['ready']);
    $bClaimed = !empty($b['claimed']);
    $bTip     = $bClaimed
        ? 'Bônus já resgatado neste período.'
        : ($bReady ? 'Recompensa liberada — resgate agora.' : 'Conclua os ' . num($bMax) . ' desafios desta aba para liberar.');
?>
          <form class="des-bonus<?= $bReady ? ' is-ready' : '' ?><?= $bClaimed ? ' is-claimed' : '' ?>" method="post" action="<?= e(url('desafios/resgatar')) ?>" data-bonus="<?= e((string)$key) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="escopo" value="<?= e((string)$key) ?>">
            <span class="des-bonus__thumb"><img src="<?= e(media((string)($b['image'] ?? 'items/supply-crate.png'))) ?>" alt="" loading="lazy"></span>
            <span class="des-bonus__text">
              <span class="des-bonus__label"><?= e((string)($b['label'] ?? '')) ?></span>
              <strong class="des-bonus__title"><?= e((string)($b['title'] ?? '')) ?></strong>
              <small class="des-bonus__desc"><?= e((string)($b['desc'] ?? '')) ?></small>
            </span>
            <span class="des-bonus__prog">
              <span class="des-bonus__prog-head">
                <span>PROGRESSO</span>
                <span class="num"><?= e(num($bCur)) ?> / <?= e(num($bMax)) ?></span>
              </span>
              <span class="progress progress--anim des-bonus__bar" style="--v:<?= e((string)$bPct) ?>%" role="progressbar" aria-label="Progresso do bônus" aria-valuemin="0" aria-valuemax="<?= e((string)$bMax) ?>" aria-valuenow="<?= e((string)$bCur) ?>"><span class="progress__bar"></span></span>
            </span>
            <span class="chal__rw des-bonus__rw">
              <span class="rw rw--xp rw--gold"><span class="rw__badge">XP</span><span class="rw__val num"><?= e(num((int)($b['xp'] ?? 0))) ?></span></span>
              <span class="rw rw--cr"><?= svg('coin-credit', 'rw__ic') ?><span class="rw__val num"><?= e(num((int)($b['credits'] ?? 0))) ?></span></span>
            </span>
            <?php component('btn', [
                'label'    => $bClaimed ? 'RESGATADO' : (string)($data['bonus_cta'] ?? 'RESGATAR'),
                'type'     => 'submit',
                'variant'  => 'gold',
                'size'     => 'sm',
                'disabled' => !$bReady,
                'class'    => 'des-bonus__btn',
                'tip'      => $bTip,
                'attrs'    => $bReady ? [] : ['aria-disabled' => 'true'],
            ]) ?>
          </form>
<?php endif; ?>
        </div>
<?php endforeach; ?>
      </div>
    </section>
  </div>

  <!-- ========================= COLUNA DIREITA ============================ -->
  <aside class="col des-side" aria-label="Resumo dos desafios">

    <!-- anel de progresso semanal -->
    <section class="panel des-ring" data-boot>
      <header class="panel__head">
        <h2 class="panel__title"><?= e((string)($ring['title'] ?? 'PROGRESSO SEMANAL')) ?></h2>
<?php $ringSecs = (int)($ring['reset_secs'] ?? 0); ?>
        <span class="panel__meta"><?= e((string)($ring['reset_label'] ?? '')) ?> <span class="num" data-countdown="<?= e((string)$ringSecs) ?>"><?= e($span($ringSecs)) ?></span></span>
      </header>
      <div class="panel__body des-ring__body">
        <div class="ring" role="img" aria-label="<?= e($ringDone . ' de ' . $ringTotal . ' desafios concluídos') ?>">
          <svg class="ring__svg" viewBox="0 0 120 120" aria-hidden="true" focusable="false">
<?php for ($i = 0; $i < $ringTotal; $i++): ?>
            <path class="ring__seg<?= $i < $ringDone ? ' is-on' : '' ?>" style="--i:<?= e((string)$i) ?>" d="<?= e($arc($i, $ringTotal)) ?>"></path>
<?php endfor; ?>
          </svg>
          <div class="ring__mid">
            <span class="ring__val num" data-count="<?= e((string)$ringDone) ?>" data-dur="900"><?= e(num($ringDone)) ?></span>
            <span class="ring__total num">/ <?= e(num($ringTotal)) ?></span>
            <span class="ring__cap"><?= e((string)($ring['caption'] ?? '')) ?></span>
          </div>
        </div>
        <div class="ring__pct num"><span data-count="<?= e((string)$ringPct) ?>" data-suffix="%" data-dur="900"><?= e(num($ringPct)) ?>%</span></div>
        <ul class="ring__stats">
<?php foreach ((array)($ring['stats'] ?? []) as $st): ?>
          <li class="ring__stat ring__stat--<?= e((string)($st['color'] ?? 'lime')) ?>">
            <span class="ring__stat-ic"><?= icon((string)($st['icon'] ?? 'bolt')) ?></span>
            <span class="ring__stat-label"><?= e((string)($st['label'] ?? '')) ?></span>
            <span class="ring__stat-val num"><?= e((string)($st['value'] ?? '')) ?></span>
          </li>
<?php endforeach; ?>
        </ul>
      </div>
    </section>

    <!-- prévia do passe de temporada -->
    <section class="panel panel--hot des-pass panel--grow" data-boot>
      <div class="des-pass__art">
        <img class="des-pass__img" src="<?= e(media((string)($pass['image'] ?? 'ui/season-pass.jpg'))) ?>" alt="<?= e((string)($pass['name'] ?? '')) ?>">
        <span class="des-pass__shade" aria-hidden="true"></span>
        <span class="des-pass__season"><?= e((string)($pass['season'] ?? '')) ?></span>
<?php $passSecs = (int)($pass['ends_secs'] ?? 0); ?>
        <span class="des-pass__ends"><?= e((string)($pass['ends_label'] ?? '')) ?> <span class="num" data-countdown="<?= e((string)$passSecs) ?>"><?= e($span($passSecs)) ?></span></span>
        <span class="des-pass__name"><?= e((string)($pass['name'] ?? '')) ?></span>
      </div>
      <div class="panel__body des-pass__body">
        <div class="des-pass__top">
          <?php component('hex', ['size' => 'lg', 'color' => 'lime', 'text' => (string)$passLvl, 'class' => 'des-pass__hex']) ?>
          <div class="des-pass__lines">
            <h2 class="des-pass__title"><?= e((string)($pass['title'] ?? 'PASSE DE TEMPORADA')) ?></h2>
            <span class="progress progress--anim des-pass__bar" style="--v:<?= e((string)$passPct) ?>%" role="progressbar" aria-label="Progresso do passe" aria-valuemin="0" aria-valuemax="<?= e((string)$passNext) ?>" aria-valuenow="<?= e((string)$passXp) ?>"><span class="progress__bar"></span></span>
            <p class="des-pass__xp num"><span class="lime" data-count="<?= e((string)$passXp) ?>" data-dur="1100"><?= e(num($passXp)) ?></span> / <?= e(num($passNext)) ?> XP</p>
          </div>
        </div>

        <p class="des-pass__next"><?= e((string)($pass['next_label'] ?? 'PRÓXIMAS RECOMPENSAS')) ?></p>
        <ul class="tier-list">
<?php foreach ((array)($pass['tiers'] ?? []) as $tr): ?>
<?php $state = (string)($tr['state'] ?? 'locked'); ?>
          <li class="tier<?= $state === 'next' ? ' is-next' : '' ?><?= $state === 'owned' ? ' is-owned' : '' ?>">
            <span class="tier__box">
              <img class="tier__img" src="<?= e(media((string)($tr['image'] ?? ''))) ?>" alt="" loading="lazy">
              <span class="tier__lvl num"><?= e(num((int)($tr['level'] ?? 0))) ?></span>
<?php if ($state === 'locked'): ?>
              <span class="ms tier__lock" aria-hidden="true">lock</span>
<?php elseif ($state === 'owned'): ?>
              <span class="ms tier__lock tier__lock--owned" aria-hidden="true">check</span>
<?php endif; ?>
            </span>
            <span class="tier__name"><?= e((string)($tr['name'] ?? '')) ?></span>
          </li>
<?php endforeach; ?>
        </ul>

        <?php component('btn', ['label' => (string)($pass['cta'] ?? 'VER PASSE COMPLETO'), 'href' => url((string)($pass['href'] ?? 'loja')), 'variant' => 'gold', 'block' => true, 'chev' => true, 'class' => 'des-pass__cta']) ?>
      </div>
    </section>
  </aside>
</div>
