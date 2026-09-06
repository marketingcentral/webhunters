<?php declare(strict_types=1);
/**
 * Tela SAFE ZONE (/safe-zone).
 *
 * Três colunas no mesmo padrão das outras telas: zonas conhecidas à esquerda, papéis civis no
 * centro, zona selecionada + turno à direita. Responde às três perguntas da HUD dentro do muro:
 * em qual estado estou (badge), para quem trabalho (papel e zona) e o que acontece agora
 * (situação e movimento da zona).
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf.
 * $data: title, title_tag, subtitle, rule, zones[], zone{}, active_zone, roles[], current{},
 *        operational{}, survivor, shift_min, empty, labels{}, cta{}, back{}.
 * CSS: assets/css/pages/safe-zone.css   JS: assets/js/pages/safe-zone.js
 */

use App\Core\GameRules;

$zones   = (array)($data['zones'] ?? []);
$zone    = is_array($data['zone'] ?? null) ? $data['zone'] : null;
$roles   = (array)($data['roles'] ?? []);
$cur     = is_array($data['current'] ?? null) ? $data['current'] : null;
$op      = (array)($data['operational'] ?? []);
$labels  = (array)($data['labels'] ?? []);
$cta     = (array)($data['cta'] ?? []);
$surv    = !empty($data['survivor']);
$merc    = ($op['state'] ?? '') === GameRules::STATE_MERCENARY;
$back    = (array)($data['back'] ?? []);
?>
<div class="sz">

  <header class="sz__head" data-boot>
    <div class="sz__title">
      <h1 class="page-title"><?= e((string)($data['title'] ?? 'SAFE ZONE')) ?><small><?= e((string)($data['title_tag'] ?? '')) ?></small></h1>
      <p class="page-subtitle sz__intro"><?= e((string)($data['subtitle'] ?? '')) ?></p>
    </div>
    <div class="sz__me">
      <?php component('badge', ['kind' => 'state', 'value' => $op, 'level' => (int)($player['level'] ?? 0), 'lg' => true]) ?>
      <p class="sz__rule"><?= icon('schedule', 'sz__rule-ic') ?><?= e((string)($data['rule'] ?? '')) ?></p>
    </div>
  </header>

<?php if ($surv): ?>
  <p class="sz__notice sz__notice--survivor" role="status" data-boot>
    <?php component('badge', ['kind' => 'state', 'value' => $op]) ?>
    <span><?= e((string)($data['survivor_notice'] ?? '')) ?></span>
  </p>
<?php elseif ($merc): ?>
  <p class="sz__notice" role="status" data-boot>
    <?php component('badge', ['kind' => 'faction', 'value' => (string)($op['faction'] ?? '')]) ?>
    <span><?= e((string)($data['mercenary_notice'] ?? '')) ?></span>
  </p>
<?php endif; ?>

  <div class="sz__grid">

    <!-- ====================== ZONAS ====================== -->
    <aside class="sz__left">
      <section class="panel sz-zones" data-boot>
        <header class="panel__head">
          <h2 class="panel__title"><?= e((string)($data['zones_title'] ?? 'ZONAS CONHECIDAS')) ?></h2>
          <span class="panel__meta num"><?= e((string)count($zones)) ?></span>
        </header>
        <div class="panel__body panel__body--tight">
<?php if ($zones === []): ?>
          <p class="sz-zones__none"><?= e((string)($data['empty']['title'] ?? 'NENHUMA SAFE ZONE MAPEADA')) ?></p>
<?php else: ?>
          <div class="sz-zones__list" data-stagger>
<?php foreach ($zones as $z): ?>
            <a class="row sz-zone<?= (string)$z['slug'] === (string)($data['active_zone'] ?? '') ? ' is-active' : '' ?><?= !empty($z['blocked']) ? ' is-locked' : '' ?>"
               href="<?= e(url('safe-zone/' . (string)$z['slug'])) ?>"
               <?= (string)$z['slug'] === (string)($data['active_zone'] ?? '') ? ' aria-current="true"' : '' ?>>
              <?= icon(!empty($z['gov']) ? 'shield_person' : 'local_fire_department', 'row__icon sz-zone__ic') ?>
              <span class="row__text">
                <strong><?= e((string)$z['name']) ?></strong>
                <small><?= e((string)$z['region']) ?></small>
              </span>
              <?php component('badge', ['kind' => 'base', 'value' => (string)$z['status'], 'label' => (string)$z['status'], 'class' => 'sz-zone__st']) ?>
<?php if (!empty($z['blocked'])): ?>
              <span class="row__lock" title="<?= e((string)$z['block_reason']) ?>"><?= icon('lock', 'fill') ?></span>
<?php endif; ?>
            </a>
<?php endforeach; ?>
          </div>
<?php endif; ?>
        </div>
      </section>

      <!-- turno atual -->
      <section class="panel sz-shift<?= $cur !== null ? ' is-on' : '' ?>" data-boot>
        <header class="panel__head">
          <h2 class="panel__title"><?= e((string)($data['shift_title'] ?? 'SEU TURNO')) ?></h2>
        </header>
        <div class="panel__body">
<?php if ($cur === null): ?>
          <p class="sz-shift__none"><?= icon('work_off', 'sz-shift__none-ic') ?><span>NENHUM PAPEL ASSUMIDO</span></p>
          <small class="sz-shift__hint">Escolha uma zona e um papel ao lado para abrir um turno.</small>
<?php else: ?>
          <div class="sz-shift__now">
            <span class="sz-shift__ic sz-shift__ic--<?= e((string)$cur['color']) ?>"><?= icon((string)$cur['ms'], 'fill') ?></span>
            <span class="sz-shift__txt">
              <strong><?= e((string)$cur['label']) ?></strong>
              <small><?= e((string)($zone['name'] ?? $cur['zone'])) ?></small>
            </span>
          </div>
          <ul class="sz-shift__rows">
            <li><span><?= e((string)($labels['ends_in'] ?? 'ENCERRA EM')) ?></span>
              <b class="num" data-shift-countdown="<?= e((string)(int)($cur['remaining'] ?? 0)) ?>"><?= e(sprintf('%02d:%02d', intdiv((int)($cur['remaining'] ?? 0), 60), (int)($cur['remaining'] ?? 0) % 60)) ?></b></li>
            <li><span><?= e((string)($labels['served'] ?? 'ATENDIMENTOS')) ?></span><b class="num"><?= e((string)(int)$cur['served']) ?></b></li>
          </ul>
          <form class="sz-shift__form" method="post" action="<?= e(url('safe-zone/encerrar')) ?>">
            <?= csrf_field() ?>
            <?php component('btn', ['label' => (string)($cta['leave'] ?? 'ENCERRAR TURNO'), 'type' => 'submit', 'variant' => 'outline', 'block' => true]) ?>
          </form>
<?php endif; ?>
        </div>
      </section>
    </aside>

    <!-- ====================== PAPÉIS ====================== -->
    <section class="panel sz-roles">
      <header class="panel__head">
        <h2 class="panel__title"><?= e((string)($data['roles_title'] ?? 'PAPÉIS DISPONÍVEIS')) ?></h2>
        <span class="panel__meta"><?= e((string)($zone['name'] ?? '—')) ?></span>
      </header>
      <div class="panel__body panel__body--list">
        <div class="sz-roles__grid scroll-y" data-stagger>
<?php foreach ($roles as $r): ?>
          <article class="sz-role sz-role--<?= e((string)$r['color']) ?><?= !empty($r['active']) ? ' is-active' : '' ?><?= empty($r['can']) && empty($r['active']) ? ' is-off' : '' ?>" data-role="<?= e((string)$r['key']) ?>" data-boot>
            <header class="sz-role__head">
              <span class="sz-role__ic"><?= icon((string)$r['ms'], 'fill') ?></span>
              <span class="sz-role__id">
                <strong class="sz-role__name"><?= e((string)$r['label']) ?></strong>
                <small class="sz-role__sum"><?= e((string)$r['summary']) ?></small>
              </span>
<?php if (!empty($r['active'])): ?>
              <span class="chip chip--lime badge sz-role__tag"><?= e((string)($cta['active'] ?? 'EM TURNO')) ?></span>
<?php endif; ?>
            </header>

            <p class="sz-role__desc"><?= e((string)$r['desc']) ?></p>

            <ul class="sz-role__rows">
              <li><span><?= e((string)($labels['gain'] ?? 'O QUE RENDE')) ?></span><b><?= e((string)$r['gain']) ?></b></li>
              <li><span><?= e((string)($labels['requires'] ?? 'REQUISITO')) ?></span><b class="sz-role__req"><?= e((string)$r['req']) ?></b></li>
            </ul>

            <footer class="sz-role__foot">
<?php if (!empty($r['active'])): ?>
              <span class="btn btn--block is-disabled sz-role__cta" aria-disabled="true"><?= icon('check_circle', 'fill') ?><span><?= e((string)($cta['active'] ?? 'EM TURNO')) ?></span></span>
<?php elseif (!empty($r['can'])): ?>
              <form method="post" action="<?= e(url('safe-zone/assumir')) ?>" class="sz-role__form">
                <?= csrf_field() ?>
                <input type="hidden" name="zone" value="<?= e((string)($zone['slug'] ?? '')) ?>">
                <input type="hidden" name="role" value="<?= e((string)$r['key']) ?>">
                <?php component('btn', ['label' => (string)($cur !== null ? ($cta['switch'] ?? 'TROCAR PARA ESTE PAPEL') : ($cta['assume'] ?? 'ASSUMIR PAPEL')), 'type' => 'submit', 'variant' => 'primary', 'block' => true, 'class' => 'sz-role__cta']) ?>
              </form>
<?php else: ?>
              <span class="btn btn--block is-disabled sz-role__cta sz-role__cta--off" aria-disabled="true" data-tip="<?= e((string)$r['reason']) ?>">
                <?= icon('lock') ?><span><?= e((string)$r['reason']) ?></span>
              </span>
<?php endif; ?>
            </footer>
          </article>
<?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- ====================== ZONA SELECIONADA ====================== -->
    <aside class="sz__right">
      <section class="panel sz-zp" data-boot>
        <header class="panel__head">
          <h2 class="panel__title"><?= e((string)($data['zone_title'] ?? 'ZONA SELECIONADA')) ?></h2>
<?php if ($zone !== null): ?>
          <?php component('badge', ['kind' => 'base', 'value' => (string)$zone['status'], 'label' => (string)$zone['status']]) ?>
<?php endif; ?>
        </header>
        <div class="panel__body sz-zp__body">
<?php if ($zone === null): ?>
          <p class="sz-zp__none">
            <strong><?= e((string)($data['empty']['title'] ?? '')) ?></strong>
            <small><?= e((string)($data['empty']['desc'] ?? '')) ?></small>
          </p>
<?php else: ?>
          <h3 class="sz-zp__name"><?= e((string)$zone['name']) ?></h3>
          <p class="sz-zp__desc"><?= e((string)$zone['description']) ?></p>

          <ul class="sz-zp__rows">
            <li><span><?= e((string)($labels['region'] ?? 'REGIÃO')) ?></span><b><?= e((string)$zone['region']) ?></b></li>
            <li><span><?= e((string)($labels['occupancy'] ?? 'MOVIMENTO')) ?></span>
              <b><?php component('badge', ['kind' => 'activity', 'value' => (string)$zone['occupancy']]) ?></b></li>
            <li><span><?= e((string)($labels['capacity'] ?? 'CAPACIDADE CIVIL')) ?></span><b class="num"><?= e((string)(int)$zone['capacity']) ?></b></li>
            <li><span><?= e((string)($labels['access'] ?? 'SEU ACESSO')) ?></span>
              <b class="sz-zp__access sz-zp__access--<?= e(strtolower((string)$zone['access'])) ?>"><?= e((string)$zone['access']) ?></b></li>
          </ul>

          <p class="sz-zp__prot"><?= icon(!empty($zone['gov']) ? 'shield' : 'local_fire_department', 'sz-zp__prot-ic') ?><?= e((string)$zone['protection']) ?></p>

<?php if (!empty($zone['blocked'])): ?>
          <p class="sz-zp__block"><?= icon('lock', 'sz-zp__block-ic') ?><?= e((string)$zone['block_reason']) ?></p>
<?php endif; ?>

<?php if (($zone['services'] ?? []) !== []): ?>
          <h4 class="sz-zp__sec"><?= e((string)($data['services_title'] ?? 'ESTRUTURAS')) ?></h4>
          <ul class="sz-zp__svc">
<?php foreach ((array)$zone['services'] as $s): ?>
            <li><?= e((string)$s) ?></li>
<?php endforeach; ?>
          </ul>
<?php endif; ?>

<?php if (($zone['rules'] ?? []) !== []): ?>
          <h4 class="sz-zp__sec"><?= e((string)($data['rules_title'] ?? 'REGRAS DA ZONA')) ?></h4>
          <ul class="sz-zp__rules">
<?php foreach ((array)$zone['rules'] as $ru): ?>
            <li><?= icon('gavel', 'sz-zp__rule-ic') ?><span><?= e((string)$ru) ?></span></li>
<?php endforeach; ?>
          </ul>
<?php endif; ?>
<?php endif; ?>
        </div>
      </section>

      <div class="sz__cta">
        <?php component('btn', ['label' => (string)($cta['operate'] ?? 'VOLTAR À OPERAÇÃO'), 'href' => url('jogar'), 'variant' => 'primary', 'block' => true, 'chev' => true]) ?>
        <?php component('btn', ['label' => (string)($back['label'] ?? 'IR PARA O MAPA'), 'href' => url((string)($back['href'] ?? 'mapa')), 'variant' => 'outline', 'block' => true]) ?>
      </div>
    </aside>

  </div>
</div>
