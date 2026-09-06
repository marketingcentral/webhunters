<?php declare(strict_types=1);
/**
 * Tela BASE DE CAMPO (/base) — PRD v3 §28. P0: estado vazio honesto + módulos como prévia.
 * Quando player_bases existir (P1), $base vem preenchida e o painel principal pinta os dados.
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf.
 * $data: title, subtitle, intro, empty, labels, modules, terms, survivor_note, base|null, operational.
 * CSS: assets/css/pages/base.css
 */

$labels  = (array)($data['labels'] ?? []);
$empty   = (array)($data['empty'] ?? []);
$modules = (array)($data['modules'] ?? []);
$terms   = (array)($data['terms'] ?? []);
$base    = is_array($data['base'] ?? null) ? $data['base'] : null;
$op      = (array)($data['operational'] ?? []);
?>
<div class="fb">

  <header class="fb__head" data-boot>
    <div>
      <h1 class="page-title"><?= e((string)($data['title'] ?? 'BASE DE CAMPO')) ?><span class="page-title__tag"><?= e((string)($data['subtitle'] ?? '')) ?></span></h1>
      <p class="page-subtitle fb__intro"><?= e((string)($data['intro'] ?? '')) ?></p>
    </div>
    <?php component('badge', ['kind' => 'state', 'value' => $op, 'level' => (int)($player['level'] ?? 0), 'lg' => true]) ?>
  </header>

  <div class="fb__grid">

    <!-- painel principal -->
    <section class="panel fb-main" data-boot>
<?php if ($base === null): ?>
      <div class="fb-empty">
        <span class="fb-empty__ic" aria-hidden="true"><?= icon('home_work') ?></span>
        <h2 class="fb-empty__title"><?= e((string)($empty['title'] ?? 'NENHUMA BASE DE CAMPO ATIVA')) ?></h2>
        <p class="fb-empty__desc"><?= e((string)($empty['desc'] ?? '')) ?></p>
<?php if (!empty($data['is_survivor'])): ?>
        <p class="fb-empty__surv"><?php component('badge', ['kind' => 'state', 'value' => $op]) ?><span><?= e((string)($data['survivor_note'] ?? '')) ?></span></p>
<?php endif; ?>
        <div class="fb-empty__actions">
          <?php component('btn', ['label' => (string)($empty['cta'] ?? 'VER MAPA'), 'href' => url((string)($empty['href'] ?? 'mapa')), 'variant' => 'primary', 'chev' => true]) ?>
          <?php component('btn', ['label' => (string)($empty['cta2'] ?? 'STASH CENTRAL'), 'href' => url((string)($empty['href2'] ?? 'inventario')), 'variant' => 'outline']) ?>
        </div>
        <small class="fb-empty__note"><?= e((string)($data['p1_note'] ?? '')) ?></small>
      </div>
<?php else: ?>
      <header class="panel__head">
        <h2 class="panel__title"><?= e((string)($base['name'] ?? 'BASE DE CAMPO')) ?></h2>
        <?php component('badge', ['kind' => 'base', 'value' => (string)($base['status'] ?? 'OPERACIONAL')]) ?>
      </header>
      <ul class="fb-rows">
        <li><span><?= e((string)($labels['region'] ?? 'REGIÃO')) ?></span><b><?= e((string)($base['region_name'] ?? '—')) ?></b></li>
        <li><span><?= e((string)($labels['security'] ?? 'SEGURANÇA')) ?></span><b><?= e((string)($base['security'] ?? '—')) ?></b></li>
        <li><span><?= e((string)($labels['storage'] ?? 'ARMAZENAMENTO')) ?></span><b class="num"><?= e((string)($base['storage_used'] ?? 0)) ?> / <?= e((string)($base['storage_total'] ?? 0)) ?></b></li>
        <li><span><?= e((string)($labels['vehicles'] ?? 'VEÍCULOS')) ?></span><b class="num"><?= e((string)($base['vehicles'] ?? 0)) ?></b></li>
        <li><span><?= e((string)($labels['exposure'] ?? 'EXPOSIÇÃO')) ?></span><b><?= e((string)($base['exposure'] ?? 'MÉDIA')) ?></b></li>
        <li><span><?= e((string)($labels['human'] ?? 'ATIVIDADE HUMANA PRÓXIMA')) ?></span><b><?= e((string)($base['human_activity'] ?? 'NÃO DETECTADA')) ?></b></li>
      </ul>
<?php endif; ?>
    </section>

    <!-- módulos -->
    <section class="panel fb-mods" data-boot>
      <header class="panel__head"><h2 class="panel__title"><?= e((string)($data['modules_title'] ?? 'MÓDULOS')) ?></h2></header>
      <ul class="fb-mods__list" data-stagger>
<?php $ativos = $base !== null ? array_map('strtolower', (array)($base['modules'] ?? [])) : []; ?>
<?php foreach ($modules as $m): ?>
<?php $on = in_array(strtolower((string)$m['slug']), $ativos, true); ?>
        <li class="fb-mod<?= $on ? ' is-on' : '' ?>" data-boot>
          <span class="fb-mod__ic" aria-hidden="true"><?= icon((string)$m['icon']) ?></span>
          <span class="fb-mod__txt"><strong><?= e((string)$m['label']) ?></strong><small><?= e((string)$m['desc']) ?></small></span>
          <span class="fb-mod__st"><?= $on ? 'ATIVO' : 'NÃO CONSTRUÍDO' ?></span>
        </li>
<?php endforeach; ?>
      </ul>
    </section>

    <!-- glossário curto: o que é o quê -->
    <section class="panel fb-terms" data-boot>
      <header class="panel__head"><h2 class="panel__title"><?= e((string)($data['terms_title'] ?? 'NÃO CONFUNDIR')) ?></h2></header>
      <ul class="fb-terms__list">
<?php foreach ($terms as $t): ?>
        <li><b><?= e((string)$t['k']) ?></b><span><?= e((string)$t['v']) ?></span></li>
<?php endforeach; ?>
      </ul>
    </section>

  </div>
</div>
