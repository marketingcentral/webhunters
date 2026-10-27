<?php declare(strict_types=1);
/**
 * Tela FACÇÕES (/faccoes) — PRD v3 §27. Dois cards (AEGIS, FRONTIER) com a mesma estrutura:
 * identidade, reputação/faixa, relação, contratos disponíveis, instalações, benefícios e a
 * ação. Assinar/romper abre um <dialog> de confirmação (faccoes.js); o formulário só é
 * enviado com confirm=1 — sem isso o servidor recusa (422).
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf.
 * $data: title, subtitle, intro, rule, labels, factions[], operational, survivor_notice, back.
 * CSS: assets/css/pages/faccoes.css   JS: assets/js/pages/faccoes.js
 */

use App\Core\GameRules;

$labels   = (array)($data['labels'] ?? []);
$factions = (array)($data['factions'] ?? []);
$op       = (array)($data['operational'] ?? []);
$surv     = ($op['state'] ?? '') === GameRules::STATE_SURVIVOR;
$back     = (array)($data['back'] ?? []);
?>
<div class="fac">

  <header class="fac__head" data-boot>
    <div>
      <h1 class="page-title"><?= e((string)($data['title'] ?? 'FACÇÕES')) ?><span class="page-title__tag"><?= e((string)($data['subtitle'] ?? '')) ?></span></h1>
      <p class="page-subtitle fac__intro"><?= e((string)($data['intro'] ?? '')) ?></p>
    </div>
    <div class="fac__me">
      <?php component('badge', ['kind' => 'state', 'value' => $op, 'level' => (int)($player['level'] ?? 0), 'lg' => true]) ?>
      <p class="fac__rule"><?= icon('gavel', 'fac__rule-ic') ?><?= e((string)($data['rule'] ?? '')) ?></p>
    </div>
  </header>

<?php if ($surv): ?>
  <p class="fac__notice" role="status" data-boot>
    <?php component('badge', ['kind' => 'state', 'value' => $op]) ?>
    <span><?= e((string)($data['survivor_notice'] ?? '')) ?></span>
  </p>
<?php endif; ?>

  <div class="fac__grid">
<?php foreach ($factions as $f): ?>
<?php
  $slug  = (string)$f['slug'];
  $color = (string)$f['color'];
  $cta   = (string)($f['cta_key'] ?? 'sign');
  $conf  = (array)($f['confirm'] ?? []);
?>
    <section class="panel fac-card fac-card--<?= e($color) ?><?= !empty($f['active']) ? ' is-active' : '' ?><?= $f['relation_key'] === 'locked' ? ' is-locked' : '' ?>" data-boot aria-labelledby="fac-<?= e($slug) ?>">
      <header class="fac-card__head">
        <div class="fac-card__title">
          <h2 id="fac-<?= e($slug) ?>" class="fac-card__name"><?= e((string)$f['label']) ?></h2>
          <p class="fac-card__tag"><?= e((string)$f['tagline']) ?></p>
        </div>
        <span class="fac-card__emblem" aria-hidden="true">
          <picture>
            <source srcset="<?= e(asset('img/emblemas/' . $slug . '.webp')) ?>" type="image/webp">
            <img src="<?= e(asset('img/emblemas/' . $slug . '.png')) ?>" alt="" loading="lazy" decoding="async">
          </picture>
        </span>
      </header>

      <ul class="fac-card__rows">
        <li class="frow">
          <span class="frow__k"><?= e((string)($labels['reputation'] ?? 'REPUTAÇÃO')) ?></span>
          <span class="frow__v num frow__v--<?= e($color) ?>"><?= e(num((int)$f['reputation'])) ?></span>
        </li>
        <li class="frow">
          <span class="frow__k"><?= e((string)($labels['tier'] ?? 'FAIXA')) ?></span>
          <span class="frow__v"><?= e((string)$f['tier']) ?><small><?= e((string)$f['next_tier']) ?></small></span>
        </li>
        <li class="frow">
          <span class="frow__k"><?= e((string)($labels['relation'] ?? 'RELAÇÃO')) ?></span>
          <span class="frow__v frow__v--rel-<?= e((string)$f['relation_key']) ?>"><?= e((string)$f['relation']) ?>
<?php if (!empty($f['locked_until'])): ?>
            <small class="num" data-countdown-to="<?= e((string)$f['locked_until']) ?>"><?= e((string)($labels['locked_until'] ?? 'BLOQUEADO')) ?> <?= e(date('H:i:s', (int)strtotime((string)$f['locked_until']))) ?></small>
<?php endif; ?>
          </span>
        </li>
        <li class="frow">
          <span class="frow__k"><?= e((string)($labels['contracts'] ?? 'CONTRATOS DISPONÍVEIS')) ?></span>
          <span class="frow__v num"><?= e(num((int)$f['contracts'])) ?></span>
        </li>
        <li class="frow frow--wide">
          <span class="frow__k"><?= e((string)($labels['installations'] ?? 'INSTALAÇÕES CONHECIDAS')) ?></span>
          <span class="frow__v frow__v--list">
<?php if (($f['installations'] ?? []) === []): ?>
            <em>NENHUMA MAPEADA</em>
<?php else: ?>
<?php foreach ((array)$f['installations'] as $inst): ?>
            <span><?= e((string)$inst) ?></span>
<?php endforeach; ?>
<?php endif; ?>
          </span>
        </li>
      </ul>

      <h3 class="fac-card__sec"><?= e((string)($labels['benefits'] ?? 'BENEFÍCIOS')) ?></h3>
      <ul class="fac-card__ben">
        <li><b><?= e((string)($labels['identity'] ?? 'IDENTIDADE')) ?></b><span><?= e((string)$f['identity']) ?></span></li>
        <li><b><?= e((string)($labels['economy'] ?? 'ECONOMIA')) ?></b><span><?= e((string)$f['economy']) ?></span></li>
        <li><b><?= e((string)($labels['missions'] ?? 'MISSÕES')) ?></b><span><?= e((string)$f['missions']) ?></span></li>
        <li><b><?= e((string)($labels['npc'] ?? 'APOIO EM CAMPO')) ?></b><span><?= e((string)$f['npc']) ?></span></li>
      </ul>

      <footer class="fac-card__foot">
        <span class="fac-card__status"><?= e((string)($labels['status'] ?? 'STATUS DO CONTRATO')) ?>
          <?php component('badge', ['kind' => $f['relation_key'] === 'locked' ? 'discipline' : 'base', 'value' => !empty($f['active']) ? 'OPERACIONAL' : ($f['relation_key'] === 'locked' ? 'PUNIDO' : 'ISOLADA'), 'label' => !empty($f['active']) ? 'ATIVO' : ($f['relation_key'] === 'locked' ? 'BLOQUEADO' : 'SEM CONTRATO')]) ?>
        </span>
<?php if (!empty($f['can_sign'])): ?>
        <form class="fac-card__form" method="post" action="<?= e(url('faccoes/assinar')) ?>" data-needs-confirm
              data-confirm-title="<?= e((string)($conf['title'] ?? '')) ?>" data-confirm-body="<?= e((string)($conf['body'] ?? '')) ?>"
              data-confirm-ok="<?= e((string)($conf['ok'] ?? '')) ?>" data-confirm-cancel="<?= e((string)($conf['cancel'] ?? 'CANCELAR')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="faction" value="<?= e($slug) ?>">
          <input type="hidden" name="confirm" value="0" data-confirm-field>
          <?php component('btn', ['label' => (string)$f['cta'], 'type' => 'submit', 'variant' => $cta === 'switch' ? 'danger' : 'primary', 'block' => true, 'class' => 'fac-card__cta']) ?>
        </form>
<?php elseif (!empty($f['active'])): ?>
        <form class="fac-card__form" method="post" action="<?= e(url('faccoes/romper')) ?>" data-needs-confirm
              data-confirm-title="ENCERRAR CONTRATO?" data-confirm-body="Você volta a operar sob o Hunter Command. A reputação com a <?= e((string)$f['label']) ?> é mantida. Para assinar com outra facção é preciso encerrar este contrato antes."
              data-confirm-ok="ENCERRAR CONTRATO" data-confirm-cancel="CANCELAR">
          <?= csrf_field() ?>
          <input type="hidden" name="confirm" value="0" data-confirm-field>
          <?php component('btn', ['label' => (string)($data['cta']['release'] ?? 'ENCERRAR CONTRATO'), 'type' => 'submit', 'variant' => 'outline', 'block' => true, 'class' => 'fac-card__cta']) ?>
        </form>
<?php else: ?>
        <?php component('btn', ['label' => (string)$f['cta'], 'type' => 'button', 'variant' => 'ghost', 'block' => true, 'class' => 'fac-card__cta', 'attrs' => ['disabled' => 'disabled', 'aria-disabled' => 'true']]) ?>
<?php endif; ?>
      </footer>
    </section>
<?php endforeach; ?>
  </div>

  <p class="fac__back" data-boot>
    <?php component('btn', ['label' => (string)($back['label'] ?? 'VER CONTRATOS'), 'href' => url((string)($back['href'] ?? 'missoes')), 'variant' => 'outline', 'chev' => true]) ?>
  </p>

  <!-- confirmação obrigatória (PRD §7.2) — texto e botão vêm do formulário que a abriu -->
  <dialog class="fac-modal" data-fac-modal>
    <form method="dialog" class="fac-modal__box">
      <h2 class="fac-modal__title" data-modal-title>ROMPER CONTRATO?</h2>
      <p class="fac-modal__body" data-modal-body></p>
      <div class="fac-modal__actions">
        <button class="btn btn--outline" type="button" value="cancel" data-modal-cancel>CANCELAR</button>
        <button class="btn btn--danger" type="button" value="ok" data-modal-ok>ROMPER E ASSINAR</button>
      </div>
    </form>
  </dialog>
</div>
