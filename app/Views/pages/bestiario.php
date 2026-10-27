<?php declare(strict_types=1);
/**
 * Tela BESTIÁRIO — as criaturas do enredo v6 e o registro do jogador.
 *
 * $data: grupos{}, grupo, por_grupo{}, lista[], total, registradas, selecionada, niveis{},
 *        liberar, regioes{}, estudo_abates               (BestiaryController::index)
 * Cada item de lista[] é a ficha de app/Data/criaturas.php + nivel, img (url|null),
 * registro{sightings,kills,studied,first_seen_at} e lidar{nome,cor,ms,texto} (como lidar).
 *
 * As fichas são todas renderizadas e o JS só alterna qual está visível (como Conquistas):
 * trocar de criatura não pede nada ao servidor, e o ?c=<slug> continua abrindo uma direto.
 * O que o jogador ainda não viu aparece como silhueta e "???" — o texto nem chega ao HTML.
 */

$grupos   = (array)($data['grupos'] ?? []);
$grupo    = (string)($data['grupo'] ?? 'todas');
$porGrupo = (array)($data['por_grupo'] ?? []);
$lista    = (array)($data['lista'] ?? []);
$niveis   = (array)($data['niveis'] ?? []);
$regioes  = (array)($data['regioes'] ?? []);
$sel      = (string)($data['selecionada'] ?? '');
$total    = (int)($data['total'] ?? 0);
$feitas   = (int)($data['registradas'] ?? 0);
$pct      = $total > 0 ? (int)floor(100 * $feitas / $total) : 0;

/** Quadro do retrato: a arte, ou o estado vazio com o ícone do grupo. */
$arte = static function (array $c, string $cls) use ($grupos): string {
    $g = (array)($grupos[(string)$c['grupo']] ?? []);
    if ($c['nivel'] === 'desconhecido') {
        return '<div class="' . $cls . ' bst-art bst-art--oculta">'
             . ($c['img'] ? '<img src="' . e($c['img']) . '" alt="" loading="lazy" decoding="async">' : '')
             . icon('help', 'bst-art__ms') . '</div>';
    }
    if ($c['img']) {
        return '<div class="' . $cls . ' bst-art"><img src="' . e($c['img']) . '" alt="' . e((string)$c['nome']) . '" loading="lazy" decoding="async"></div>';
    }
    return '<div class="' . $cls . ' bst-art bst-art--vazia">' . icon((string)($g['ms'] ?? 'pest_control'), 'bst-art__ms')
         . '<span class="bst-art__txt">SEM REGISTRO VISUAL</span></div>';
};
?>
<header class="page-head bst-head">
  <h1 class="page-title">BESTIÁRIO</h1>
  <div class="bst-prog" role="group" aria-label="Criaturas registradas">
    <span class="bst-prog__k">REGISTRADAS</span>
    <span class="bst-prog__v num"><?= e((string)$feitas) ?><i>/</i><?= e((string)$total) ?></span>
    <span class="bst-prog__bar" aria-hidden="true"><i style="width:<?= $pct ?>%"></i></span>
  </div>
</header>

<div class="bst">

  <!-- ====================== GRUPOS ====================== -->
  <section class="panel bst-side" data-boot>
    <h2 class="bst-side__title">CLASSIFICAÇÃO ICARUS</h2>
    <nav class="bst-groups" aria-label="Grupos de criaturas">
      <?php $on = $grupo === 'todas'; ?>
      <a class="bst-group<?= $on ? ' is-active' : '' ?>" href="<?= e(url('bestiario')) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
        <?= icon('menu_book', 'bst-group__ms') ?>
        <span class="bst-group__label">TODAS</span>
        <span class="bst-group__n num"><?= e((string)array_sum($porGrupo)) ?></span>
      </a>
      <?php foreach ($grupos as $slug => $g): ?>
      <?php $on = $grupo === $slug; ?>
      <a class="bst-group bst-group--<?= e((string)$g['cor']) ?><?= $on ? ' is-active' : '' ?>" href="<?= e(url('bestiario/' . $slug)) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
        <?= icon((string)$g['ms'], 'bst-group__ms') ?>
        <span class="bst-group__label"><?= e((string)$g['nome']) ?></span>
        <span class="bst-group__n num"><?= e((string)($porGrupo[$slug] ?? 0)) ?></span>
      </a>
      <?php endforeach; ?>
    </nav>

    <?php if ($grupo !== 'todas' && isset($grupos[$grupo])): ?>
    <p class="bst-side__lead"><?= e((string)$grupos[$grupo]['texto']) ?></p>
    <?php else: ?>
    <p class="bst-side__lead">Tudo o que o Instituto Icarus catalogou desde a Queda. Cada criatura tem um sinal que se lê antes do contato: aprenda o sinal e você escolhe a briga.</p>
    <?php endif; ?>

    <div class="bst-levels">
      <h3 class="bst-levels__title">REGISTRO</h3>
      <?php foreach ($niveis as $k => $n): ?>
      <div class="bst-level bst-level--<?= e((string)$k) ?>">
        <span class="bst-level__dot" aria-hidden="true"></span>
        <span class="bst-level__k"><?= e((string)$n['label']) ?></span>
        <span class="bst-level__v"><?= e((string)$n['texto']) ?></span>
      </div>
      <?php endforeach; ?>
      <?php if (!empty($data['liberar'])): ?>
      <p class="bst-levels__note"><?= icon('lock_open', 'bst-levels__ms') ?>Arquivo aberto: todas as fichas liberadas para leitura do enredo.</p>
      <?php endif; ?>
    </div>
  </section>

  <!-- ====================== GRADE ====================== -->
  <section class="bst-grid<?= count($lista) <= 4 ? ' bst-grid--poucas' : '' ?>" aria-label="Criaturas">
    <?php foreach ($lista as $c): ?>
    <?php
      $oculta = $c['nivel'] === 'desconhecido';
      $g      = (array)($grupos[(string)$c['grupo']] ?? []);
      $on     = $c['slug'] === $sel;
    ?>
    <button type="button" class="bst-card bst-card--<?= e((string)($g['cor'] ?? 'muted')) ?><?= $on ? ' is-selected' : '' ?><?= $oculta ? ' is-oculta' : '' ?>"
            data-criatura="<?= e((string)$c['slug']) ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>">
      <?= $arte($c, 'bst-card__art') ?>
      <span class="bst-card__threat num" aria-label="Ameaça"><?= $oculta ? '??' : e((string)$c['ameaca']) ?></span>
      <span class="bst-card__foot">
        <span class="bst-card__name"><?= $oculta ? '???' : e((string)$c['nome']) ?></span>
        <span class="bst-card__role"><?= $oculta ? e((string)($g['nome'] ?? '')) : e((string)$c['papel']) ?></span>
      </span>
      <span class="bst-card__lvl bst-card__lvl--<?= e((string)$c['nivel']) ?>"><?= e((string)($niveis[$c['nivel']]['label'] ?? '')) ?></span>
    </button>
    <?php endforeach; ?>
  </section>

  <!-- ====================== FICHA ====================== -->
  <aside class="panel bst-det" aria-live="polite">
    <?php foreach ($lista as $c): ?>
    <?php
      $oculta  = $c['nivel'] === 'desconhecido';
      $completa = $c['nivel'] === 'estudado';
      $g       = (array)($grupos[(string)$c['grupo']] ?? []);
      $on      = $c['slug'] === $sel;
      $r       = (array)$c['registro'];
    ?>
    <article class="bst-sheet bst-sheet--<?= e((string)($g['cor'] ?? 'muted')) ?><?= $on ? ' is-on' : '' ?>" data-ficha="<?= e((string)$c['slug']) ?>"<?= $on ? '' : ' hidden' ?>>
      <?= $arte($c, 'bst-sheet__art') ?>

      <header class="bst-sheet__head">
        <span class="bst-sheet__group"><?= icon((string)($g['ms'] ?? 'pest_control'), 'bst-sheet__gms') ?><?= e((string)($g['nome'] ?? '')) ?></span>
        <h2 class="bst-sheet__name"><?= $oculta ? '???' : e((string)$c['nome']) ?></h2>
        <?php if (!$oculta): ?>
        <div class="bst-sheet__meta">
          <span><?= e((string)$c['papel']) ?></span>
          <span><?= e((string)$c['porte']) ?></span>
        </div>
        <?php endif; ?>
      </header>

      <?php if ($oculta): ?>
      <p class="bst-sheet__locked"><?= icon('visibility_off') ?>Nenhum registro desta criatura. Avistar uma em campo libera a imagem e a descrição; abater <?= e((string)$data['estudo_abates']) ?> ou entregar uma amostra ao Icarus libera a ficha completa.</p>
      <?php else: ?>

      <div class="bst-threat" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string)$c['ameaca']) ?>" aria-label="Ameaça">
        <span class="bst-threat__k">AMEAÇA</span>
        <span class="bst-threat__bar" aria-hidden="true"><i style="width:<?= (int)$c['ameaca'] ?>%"></i></span>
        <span class="bst-threat__v num"><?= e((string)$c['ameaca']) ?></span>
      </div>

      <?php /* Como lidar: uma leitura só, logo abaixo do nome — é a pergunta de quem abriu a ficha. */ ?>
      <p class="bst-lidar bst-lidar--<?= e((string)$c['lidar']['cor']) ?>">
        <?= icon((string)$c['lidar']['ms']) ?><b><?= e((string)$c['lidar']['nome']) ?></b><span><?= e((string)$c['lidar']['texto']) ?></span></p>

      <p class="bst-sheet__line"><?= e((string)$c['linha']) ?></p>
      <p class="bst-sheet__text"><?= e((string)$c['texto']) ?></p>

      <?php if ($completa): ?>
      <dl class="bst-facts">
        <div class="bst-fact bst-fact--sinal"><dt><?= icon('hearing') ?>SINAL</dt><dd><?= e((string)$c['sinal']) ?></dd></div>
        <div class="bst-fact bst-fact--contra"><dt><?= icon('strategy') ?>CONTRA</dt><dd><?= e((string)$c['contra']) ?></dd></div>
        <div class="bst-fact"><dt><?= icon('my_location') ?>FRAQUEZA</dt><dd><?= e((string)$c['fraqueza']) ?></dd></div>
        <div class="bst-fact"><dt><?= icon('coronavirus') ?>CONTÁGIO</dt><dd><?= e((string)$c['contagio']) ?></dd></div>
      </dl>

      <h3 class="bst-sub">HABITAT</h3>
      <ul class="bst-habitat">
        <?php foreach ((array)$c['habitat'] as $h): ?>
        <li><?= e((string)($regioes[$h] ?? mb_strtoupper(str_replace('-', ' ', (string)$h)))) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <p class="bst-sheet__locked"><?= icon('science') ?>Sinal, contra e fraqueza ainda não estudados. Abata <?= e((string)$data['estudo_abates']) ?> ou entregue uma amostra ao Instituto Icarus.</p>
      <?php endif; ?>

      <?php endif; ?>

      <footer class="bst-rec">
        <span><b class="num"><?= e((string)$r['sightings']) ?></b>AVISTAMENTOS</span>
        <span><b class="num"><?= e((string)$r['kills']) ?></b>ABATES</span>
        <span><b><?= $r['studied'] ? 'SIM' : 'NÃO' ?></b>AMOSTRA ICARUS</span>
      </footer>
    </article>
    <?php endforeach; ?>
  </aside>
</div>
