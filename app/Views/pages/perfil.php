<?php declare(strict_types=1);
/**
 * Tela PERFIL — layout hud, três colunas (operador | estatísticas | histórico).
 * Painéis compactos em grafite, conforme a referência visual do perfil.
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf.
 * $data: app/Data/perfil.php (operator, stats, achievements, weapons, classes, recent, badges, actions).
 * CSS: assets/css/pages/perfil.css   JS: assets/js/pages/perfil.js
 */

$op        = (array)($data['operator'] ?? []);
$opMeta    = (array)($op['meta'] ?? []);
$stats     = (array)($data['stats'] ?? []);
$achieves  = (array)($data['achievements'] ?? []);
$weapons   = (array)($data['weapons'] ?? []);
$classes   = (array)($data['classes'] ?? []);
$recent    = (array)($data['recent'] ?? []);
$badges    = (array)($data['badges'] ?? []);
$actions   = (array)($data['actions'] ?? []);

$pName  = (string)($player['name'] ?? 'HUNTER_07');
$pLevel = (int)($player['level'] ?? 1);
$pXp    = (int)($player['xp'] ?? 0);
$pXpMax = max(1, (int)($player['xp_next'] ?? 1));
$pPct   = round(min(100, max(0, $pXp / $pXpMax * 100)), 2);
$portrait = (string)($data['portrait'] ?? 'portraits/op-assault.jpg');

$colorClass = static function (string $c): string {
    return in_array($c, ['lime', 'gold', 'cyan', 'amber', 'red', 'purple'], true) ? ' is-' . $c : '';
};
?>
<div class="page-head" data-boot>
  <h1 class="page-title"><?= e((string)($data['title'] ?? 'PERFIL')) ?> <small><?= e($pName) ?></small></h1>
  <?php /* PRD v3 §21: organização · estado · patente · reputação da facção contratada */ $pfOp = (array)($data['operational'] ?? []); ?>
  <p class="pf-head__sub">
    <span class="pf-head__org"><?= icon('handshake') ?><?= e((string)($data['organization'] ?? 'HUNTER COMMAND')) ?></span>
    <span class="pf-head__sep" aria-hidden="true"></span>
    <?php component('badge', ['kind' => 'state', 'value' => $pfOp, 'level' => $pLevel]) ?>
    <span class="pf-head__sep" aria-hidden="true"></span>
    <span class="pf-head__rank"><?= icon('military_tech') ?><?= e((string)($data['faction_rank'] ?? '')) ?></span>
<?php if (!empty($data['reputation_of'])): ?>
    <span class="pf-head__sep" aria-hidden="true"></span>
    <span class="pf-head__rep">REP <?= e((string)$data['reputation_of']) ?> <span class="num lime"><?= e(num((int)($data['reputation'] ?? 0))) ?></span></span>
<?php endif; ?>
  </p>
</div>

<div class="pf">

  <!-- ======================= coluna 1 — operador ======================= -->
  <div class="pf__col pf__col--l col">

    <section class="panel panel--hot panel--flush panel--grow op" data-boot>
      <div class="op__media">
        <img class="op__img" src="<?= e(media($portrait)) ?>" alt="<?= e($pName) ?>" decoding="async">
        <span class="op__tag op__tag--class"><?= svg((string)($op['class_icon'] ?? 'class-assault')) ?><?= e((string)($op['class'] ?? 'ASSAULT')) ?></span>
        <span class="op__tag op__tag--status"><i aria-hidden="true"></i><?= e((string)($op['status'] ?? '')) ?></span>
        <div class="op__id">
          <?php component('hex', ['size' => 'lg', 'color' => 'lime', 'text' => $pLevel, 'hot' => true, 'ring' => true, 'class' => 'op__hex', 'title' => 'Nível ' . $pLevel]) ?>
          <div class="op__idtxt">
            <span class="op__label"><?= e((string)($op['callsign'] ?? 'INDICATIVO')) ?></span>
            <strong class="op__name"><?= e($pName) ?></strong>
            <span class="op__squad"><?= icon('group') ?><?= e((string)($op['squad'] ?? '')) ?></span>
          </div>
        </div>
      </div>

      <div class="op__body">
        <div class="op__xp">
          <div class="op__xp-head">
            <span class="op__xp-lvl">NÍVEL <span class="num"><?= e((string)$pLevel) ?></span></span>
            <span class="op__xp-val num"><span class="lime" data-count="<?= e((string)$pXp) ?>" data-dur="1200"><?= e(num($pXp)) ?></span> / <?= e(num($pXpMax)) ?> XP</span>
          </div>
          <div class="progress progress--anim op__bar" style="--v:<?= e((string)$pPct) ?>%" role="progressbar" aria-label="Progresso de nível" aria-valuemin="0" aria-valuemax="<?= e((string)$pXpMax) ?>" aria-valuenow="<?= e((string)$pXp) ?>"><div class="progress__bar"></div></div>
        </div>

        <ul class="op__meta" data-stagger>
<?php foreach ($opMeta as $m): ?>
          <li class="op__meta-row">
            <span class="op__meta-ic<?= e($colorClass((string)($m['color'] ?? ''))) ?>"><?= icon((string)($m['icon'] ?? 'chevron_right')) ?></span>
            <span class="op__meta-label"><?= e((string)($m['label'] ?? '')) ?></span>
            <span class="op__meta-val<?= e($colorClass((string)($m['color'] ?? ''))) ?>"><?= e((string)($m['value'] ?? '')) ?></span>
          </li>
<?php endforeach; ?>
          <li class="op__meta-row op__meta-row--live">
            <span class="op__meta-ic is-lime"><?= icon('radar') ?></span>
            <span class="op__meta-label">SESSÃO ATUAL</span>
            <span class="op__meta-val is-lime num" data-session="<?= e((string)($op['session_seconds'] ?? 0)) ?>">00:00:00</span>
          </li>
        </ul>

        <?php component('btn', [
            'label' => (string)($actions['edit'] ?? 'EDITAR PERFIL'),
            'href' => url('configuracoes/conta'),
            'variant' => 'primary',
            'icon' => 'account_circle',
            'chev' => true,
            'block' => true,
            'class' => 'op__edit',
        ]) ?>
      </div>
    </section>

    <section class="panel bg-badges" data-boot>
      <header class="panel__head">
        <h2 class="panel__title"><?= e((string)($data['badges_title'] ?? 'INSÍGNIAS')) ?> <small class="num"><?= e((string)count($badges)) ?></small></h2>
        <a class="panel__action" href="<?= e(url('conquistas')) ?>">VER TODAS<?= icon('add') ?></a>
      </header>
      <div class="panel__body panel__body--tight bg-badges__body" data-stagger>
<?php foreach ($badges as $b): ?>
        <a class="bdg" href="<?= e(url('conquistas')) ?>" data-tip="<?= e((string)($b['label'] ?? '')) ?>">
          <?php component('hex', ['size' => 'md', 'icon' => (string)($b['icon'] ?? 'star'), 'color' => (string)($b['rarity'] ?? 'lime'), 'ring' => true, 'class' => 'bdg__hex']) ?>
          <span class="bdg__label"><?= e((string)($b['label'] ?? '')) ?></span>
        </a>
<?php endforeach; ?>
      </div>
    </section>

  </div>

  <!-- ================== coluna 2 — estatísticas / armas ================== -->
  <div class="pf__col pf__col--c col">

    <section class="panel st" data-boot>
      <header class="panel__head">
        <h2 class="panel__title"><?= icon('bar_chart') ?><?= e((string)($data['stats_title'] ?? 'ESTATÍSTICAS')) ?></h2>
        <span class="panel__meta"><?= e((string)($data['stats_meta'] ?? '')) ?></span>
      </header>
      <?php
        /* PRD v3 §21.6: estatísticas por estado. GERAL é a grade que já existia; as outras
           abas vêm do controller com o que o backend já mede (o resto aparece como "—"). */
        $stTabs  = (array)($data['stats_tabs'] ?? []);
        $tabItems = [['label' => 'GERAL', 'panel' => 'geral', 'active' => true]];
        foreach ($stTabs as $t) { $tabItems[] = ['label' => (string)$t['label'], 'panel' => (string)$t['slug']]; }
      ?>
      <div class="st__tabs"><?php component('tabs', ['items' => $tabItems, 'label' => 'Estatísticas por estado', 'class' => 'st__tabnav']) ?></div>
<?php foreach ($stTabs as $t): ?>
      <div class="panel__body st__body" data-tab-panel="<?= e((string)$t['slug']) ?>" hidden>
<?php foreach ((array)$t['items'] as $s): ?>
        <div class="st-cell<?= e($colorClass((string)($s['color'] ?? ''))) ?><?= !empty($s['dep']) ? ' is-dep' : '' ?>">
          <span class="st-cell__ic"><?= icon((string)($s['icon'] ?? 'star')) ?></span>
          <span class="st-cell__val num"><?= isset($s['n']) ? e(num((int)$s['n'])) : '—' ?></span>
          <span class="st-cell__label"><?= e((string)($s['label'] ?? '')) ?></span>
          <span class="st-cell__sub<?= !empty($s['dep']) ? ' is-dep' : '' ?>"><?= e((string)($s['sub'] ?? '')) ?></span>
        </div>
<?php endforeach; ?>
      </div>
<?php endforeach; ?>
      <div class="panel__body st__body" data-stagger data-tab-panel="geral">
<?php foreach ($stats as $s): ?>
<?php
        $sn   = $s['n'] ?? 0;
        $sDec = (int)($s['dec'] ?? 0);
        $sSuf = (string)($s['suffix'] ?? '');
?>
        <div class="st-cell<?= !empty($s['hot']) ? ' is-hot' : '' ?><?= e($colorClass((string)($s['color'] ?? ''))) ?>">
          <span class="st-cell__ic"><?= icon((string)($s['icon'] ?? 'star')) ?></span>
          <span class="st-cell__val num"><span data-count="<?= e((string)$sn) ?>" data-dec="<?= e((string)$sDec) ?>" data-suffix="<?= e($sSuf) ?>" data-dur="1100"><?= e(num(is_float($sn) ? (float)$sn : (int)$sn, $sDec) . $sSuf) ?></span><?php if (!empty($s['unit'])): ?><small><?= e((string)$s['unit']) ?></small><?php endif; ?></span>
          <span class="st-cell__label"><?= e((string)($s['label'] ?? '')) ?></span>
          <span class="st-cell__sub"><?= e((string)($s['sub'] ?? '')) ?></span>
        </div>
<?php endforeach; ?>
      </div>
    </section>

    <section class="panel ac" data-boot>
      <header class="panel__head">
        <h2 class="panel__title"><?= icon('military_tech') ?><?= e((string)($data['achievements_title'] ?? 'CONQUISTAS RECENTES')) ?></h2>
        <a class="panel__action" href="<?= e(url('conquistas')) ?>"><?= e((string)($data['achievements_action'] ?? 'VER TODAS')) ?><?= icon('add') ?></a>
      </header>
      <div class="panel__body ac__body" data-stagger>
<?php foreach ($achieves as $a): ?>
        <a class="ac-card<?= e($colorClass((string)($a['color'] ?? 'lime'))) ?>" href="<?= e(url('conquistas')) ?>">
          <?php component('hex', ['size' => 'xl', 'icon' => (string)($a['icon'] ?? 'star'), 'color' => (string)($a['color'] ?? 'lime'), 'ring' => true, 'hot' => true, 'class' => 'ac-card__hex']) ?>
          <strong class="ac-card__name"><?= e((string)($a['label'] ?? '')) ?></strong>
          <small class="ac-card__desc"><?= e((string)($a['desc'] ?? '')) ?></small>
          <span class="ac-card__foot">
            <span class="ac-card__state">CONCLUÍDA</span>
            <span class="ac-card__date num"><?= e((string)($a['date'] ?? '')) ?></span>
          </span>
          <span class="ac-card__xp num">+<?= e(num((int)($a['xp'] ?? 0))) ?> XP</span>
        </a>
<?php endforeach; ?>
      </div>
    </section>

    <section class="panel wp" data-boot>
      <header class="panel__head">
        <h2 class="panel__title"><?= icon('swords') ?><?= e((string)($data['weapons_title'] ?? 'ARMAS FAVORITAS')) ?></h2>
        <a class="panel__action" href="<?= e(url('inventario')) ?>"><?= e((string)($data['weapons_action'] ?? 'INVENTÁRIO')) ?><?= icon('chevron_right') ?></a>
      </header>
      <div class="panel__body wp__body" data-stagger>
<?php foreach ($weapons as $w): ?>
        <a class="wp-card<?= !empty($w['main']) ? ' is-main' : '' ?>" href="<?= e(url('inventario')) ?>">
          <span class="wp-card__art">
            <img class="wp-card__img" src="<?= e(media((string)($w['img'] ?? 'items/ak103.png'))) ?>" alt="<?= e((string)($w['name'] ?? '')) ?>" loading="lazy" decoding="async" draggable="false">
          </span>
          <span class="wp-card__info">
            <span class="wp-card__head">
              <strong class="wp-card__name"><?= e((string)($w['name'] ?? '')) ?></strong>
<?php if (!empty($w['main'])): ?>
              <span class="chip chip--lime">PRINCIPAL</span>
<?php endif; ?>
            </span>
            <small class="wp-card__type"><?= e((string)($w['type'] ?? '')) ?> <i class="num"><?= e((string)($w['caliber'] ?? '')) ?></i></small>
            <span class="wp-card__stats">
              <span class="wp-stat"><b class="num"><?= e(num((int)($w['kills'] ?? 0))) ?></b><i>ELIMINAÇÕES</i></span>
              <span class="wp-stat"><b class="num"><?= e((string)($w['accuracy'] ?? 0)) ?>%</b><i>PRECISÃO</i></span>
              <span class="wp-stat wp-stat--use">
                <b class="num"><?= e((string)($w['usage'] ?? 0)) ?>%</b><i>USO</i>
                <span class="progress progress--anim progress--thin wp-stat__bar" style="--v:<?= e((string)(int)($w['usage'] ?? 0)) ?>%"><span class="progress__bar"></span></span>
              </span>
            </span>
          </span>
        </a>
<?php endforeach; ?>
      </div>
    </section>

  </div>

  <!-- ================= coluna 3 — classes / atividade ================= -->
  <div class="pf__col pf__col--r col">

    <?php /* PRD v3 §21.3–21.4: contrato mercenário e reputação com as duas facções */ $fc = (array)($data['contract_block'] ?? []); $fcAtiva = is_array($fc['active'] ?? null) ? $fc['active'] : null; ?>
    <section class="panel fc" data-boot>
      <header class="panel__head">
        <h2 class="panel__title"><?= e((string)($fc['title'] ?? 'CONTRATO MERCENÁRIO')) ?></h2>
        <a class="panel__action" href="<?= e(url((string)($fc['cta']['href'] ?? 'faccoes'))) ?>">FACÇÕES<?= icon('chevron_right') ?></a>
      </header>
      <div class="fc__body">
<?php if (!empty($fc['survivor'])): ?>
        <p class="fc__none"><?php component('badge', ['kind' => 'state', 'value' => (array)($data['operational'] ?? [])]) ?><span>SEM BANDEIRA · NENHUMA FACÇÃO ASSINA</span></p>
<?php elseif ($fcAtiva === null): ?>
        <p class="fc__none"><span><?= e((string)($fc['none'] ?? 'NENHUM CONTRATO ATIVO')) ?></span><?php component('badge', ['kind' => 'origin', 'value' => 'hunter_command']) ?></p>
<?php else: ?>
        <p class="fc__none"><?php component('badge', ['kind' => 'faction', 'value' => (string)($data['operational']['faction'] ?? ''), 'solid' => true]) ?><span>REP <b class="num"><?= e(num((int)($fcAtiva['rep'] ?? 0))) ?></b> · <?= e((string)($fcAtiva['tier'] ?? '')) ?></span></p>
<?php endif; ?>
<?php foreach ((array)($fc['factions'] ?? []) as $f): ?>
        <div class="fc__row fc__row--<?= e((string)$f['color']) ?><?= !empty($f['active']) ? ' is-active' : '' ?>">
          <?php component('badge', ['kind' => 'faction', 'value' => (string)$f['slug']]) ?>
          <span class="fc__rep"><b class="num"><?= e(num((int)$f['rep'])) ?></b><small><?= e((string)$f['tier']) ?></small></span>
<?php if ((string)$f['state'] !== (string)$f['tier'] || !empty($f['locked_until'])): ?>
          <span class="fc__state<?= !empty($f['locked']) ? ' is-locked' : '' ?>"><?= e((string)$f['state']) ?><?php if (!empty($f['locked_until'])): ?> <span class="num" data-countdown-to="<?= e((string)$f['locked_until']) ?>"><?= e(date('H:i:s', (int)strtotime((string)$f['locked_until']))) ?></span><?php endif; ?></span>
<?php endif; ?>
        </div>
<?php endforeach; ?>
        <div class="fc__actions">
          <?php component('btn', ['label' => (string)($fc['cta']['benefits'] ?? 'VER BENEFÍCIOS'), 'href' => url((string)($fc['cta']['href'] ?? 'faccoes')), 'variant' => 'outline', 'size' => 'sm']) ?>
          <?php component('btn', ['label' => (string)($fc['cta']['manage'] ?? 'GERENCIAR CONTRATO'), 'href' => url((string)($fc['cta']['href'] ?? 'faccoes')), 'variant' => 'primary', 'size' => 'sm', 'chev' => true]) ?>
        </div>
      </div>
    </section>

    <section class="panel cl" data-boot>
      <header class="panel__head">
        <h2 class="panel__title"><?= e((string)($data['classes_title'] ?? 'FUNÇÕES HUNTER')) ?></h2>
        <a class="panel__action" href="<?= e(url('habilidades')) ?>">HABILIDADES<?= icon('chevron_right') ?></a>
      </header>
      <div class="panel__body cl__body" data-stagger>
<?php foreach ($classes as $c): ?>
        <a class="cl-row<?= !empty($c['main']) ? ' is-main' : '' ?>" href="<?= e(url('habilidades')) ?>">
          <span class="cl-row__ic"><?= svg((string)($c['icon'] ?? 'class-assault')) ?></span>
          <span class="cl-row__txt">
            <span class="cl-row__head">
              <strong class="cl-row__label"><?= e((string)($c['label'] ?? '')) ?></strong>
              <span class="cl-row__lvl num">NV <?= e((string)($c['level'] ?? 0)) ?></span>
            </span>
            <span class="progress progress--anim progress--thin cl-row__bar" style="--v:<?= e((string)(int)($c['pct'] ?? 0)) ?>%"><span class="progress__bar"></span></span>
          </span>
          <span class="cl-row__pct num"><?= e((string)($c['pct'] ?? 0)) ?>%</span>
        </a>
<?php endforeach; ?>
      </div>
    </section>

    <section class="panel panel--grow rc" data-boot>
      <header class="panel__head">
        <h2 class="panel__title"><?= icon('schedule') ?><?= e((string)($data['recent_title'] ?? 'ATIVIDADE RECENTE')) ?></h2>
        <a class="panel__action" href="<?= e(url('missoes')) ?>">MISSÕES<?= icon('chevron_right') ?></a>
      </header>
      <div class="panel__body rc__body">
        <ol class="rc__list scroll-y" data-stagger>
<?php foreach ($recent as $r): ?>
          <li class="rc-item<?= e($colorClass((string)($r['color'] ?? ''))) ?>">
            <span class="rc-item__dot" aria-hidden="true"></span>
            <span class="rc-item__ic"><?= icon((string)($r['icon'] ?? 'star')) ?></span>
            <span class="rc-item__txt">
              <span class="rc-item__text"><?= e((string)($r['text'] ?? '')) ?></span>
              <span class="rc-item__time num"><?= e((string)($r['time'] ?? '')) ?></span>
            </span>
          </li>
<?php endforeach; ?>
        </ol>
        <div class="rc__foot">
          <a class="btn btn--outline btn--sm rc__btn" href="<?= e(url('amigos')) ?>"><?= icon('group') ?>AMIGOS</a>
          <a class="btn btn--outline btn--sm rc__btn" href="<?= e(url('mensagens')) ?>"><?= icon('mail') ?>MENSAGENS</a>
        </div>
      </div>
    </section>

  </div>
</div>
