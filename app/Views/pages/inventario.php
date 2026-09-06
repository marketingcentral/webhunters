<?php declare(strict_types=1);
/**
 * INVENTÁRIO — layout GEAR (Tarkov), dirigido pelo banco (InventoryRepo::tree).
 *
 * Quatro colunas:
 *   1. PERSONAGEM — só a silhueta por enquanto (lugar reservado para o boneco 3D)
 *   2. EQUIPAMENTO — paperdoll + barra de vitais (peso/saúde/hidratação/energia)
 *   3. CONTÊINERES — colete tático, bolsos e mochila: célula do ícone + grade lado a lado
 *   4. STASH — trilha de filtros à esquerda + grade
 *
 * A view monta só as ZONAS vazias; assets/js/pages/inventario.js busca /inventario/dados
 * e popula tudo, com drag-drop, rotação (R) e validação espelhada do servidor.
 *
 * $data: tree (do controller), weight_max, player.
 */
$tree   = $data['tree'] ?? ['stash' => ['cols' => 10, 'rows' => 10, 'items' => []], 'slots' => [], 'weight' => 0, 'value' => 0];
$player = $data['player'] ?? ($player ?? []);
$cur    = $player['currencies'] ?? ['credits' => 0, 'cash' => 0, 'points' => 0];
$wmax   = (string)($data['weight_max'] ?? 60);

/** tipos aceitos por slot — o JS revalida e o servidor decide de fato */
$ACCEPTS = [
    'earpiece' => 'earpiece', 'helmet' => 'helmet', 'mask' => 'mask', 'glasses' => 'glasses',
    'armor' => 'armor,clothing', 'armband' => 'armband', 'rig' => 'rig', 'backpack' => 'backpack',
    'primary' => 'weapon', 'secondary' => 'weapon', 'pistol' => 'pistol', 'knife' => 'knife',
];

/** shell de um slot do paperdoll (o item entra pelo JS) */
$eqSlot = static function (string $key, string $label, string $icon, string $mod = '') use ($ACCEPTS): void { ?>
  <div class="gslot <?= e($mod) ?>" data-slot="<?= e($key) ?>" data-zone="equip" data-accepts="<?= e($ACCEPTS[$key] ?? '') ?>">
    <span class="gslot__label"><?= e($label) ?></span>
    <span class="gslot__ph" aria-hidden="true"><?= svg($icon) ?></span>
  </div>
<?php };

/** contêiner com célula de ícone + grade (colete tático / mochila) */
$bag = static function (string $slot, string $label, string $icon) use ($ACCEPTS): void { ?>
  <section class="bag" data-container-panel="<?= e($slot) ?>">
    <h2 class="bag__tab"><?= e($label) ?><span class="bag__meta num" data-container-count="<?= e($slot) ?>"></span></h2>
    <div class="bag__body">
      <div class="gslot gslot--bag" data-slot="<?= e($slot) ?>" data-zone="equip" data-accepts="<?= e($ACCEPTS[$slot]) ?>">
        <span class="gslot__ph" aria-hidden="true"><?= svg($icon) ?></span>
      </div>
      <div class="bag__grid grid" data-grid data-zone="grid" data-parent-slot="<?= e($slot) ?>"></div>
      <p class="bag__empty" data-bag-empty="<?= e($slot) ?>">Equipe <?= $slot === 'rig' ? 'um colete tático' : 'uma mochila' ?> para abrir os compartimentos.</p>
    </div>
  </section>
<?php };
?>
<section class="inv2" data-inv
         data-endpoint-tree="<?= e(url('inventario/dados')) ?>"
         data-endpoint-move="<?= e(url('inventario/mover')) ?>"
         data-endpoint-equip="<?= e(url('inventario/equipar')) ?>"
         data-endpoint-attach="<?= e(url('inventario/acessorio')) ?>"
         data-media="<?= e(rtrim(url(''), '/')) ?>/media/"
         data-weight-max="<?= e($wmax) ?>">

  <!-- ======================= COLUNA 1: PERSONAGEM ======================= -->
  <!-- Por enquanto só a silhueta; é o lugar reservado para o boneco 3D do jogador. -->
  <div class="inv2-col inv2-col--char">
    <h2 class="gear__tab">PERSONAGEM</h2>
    <div class="char">
      <img class="char__body" src="<?= e(media('ui/body-silhouette.png')) ?>" alt=""
           width="444" height="1030" decoding="async">
    </div>
  </div>

  <!-- ======================= COLUNA 2: EQUIPAMENTO (paperdoll) ======================= -->
  <div class="inv2-col inv2-col--gear">
    <h1 class="gear__tab">EQUIPAMENTO</h1>
    <div class="gear">
      <div class="gear__slots">
        <?php
          $eqSlot('earpiece', 'FONE', 'eq-earpiece');
          $eqSlot('helmet', 'CAPACETE', 'eq-helmet');
          $eqSlot('mask', 'MÁSCARA', 'eq-mask');
          $eqSlot('armband', 'BRAÇADEIRA', 'eq-armband');
          $eqSlot('armor', 'COLETE', 'eq-armor');
          $eqSlot('glasses', 'ÓCULOS', 'eq-glasses');
          $eqSlot('primary', 'NA BANDOLEIRA', 'eq-rifle', 'gslot--weapon');
          $eqSlot('pistol', 'COLDRE', 'eq-pistol');
          $eqSlot('secondary', 'NAS COSTAS', 'eq-rifle', 'gslot--weapon');
          $eqSlot('knife', 'BAINHA', 'eq-knife', 'gslot--knife');
        ?>
      </div>
    </div>
    <ul class="vitals">
      <li class="vitals__i"><?= svg('eq-weight') ?><span class="vitals__v num" data-weight>0,0<i>/<?= e($wmax) ?> KG</i></span></li>
      <li class="vitals__i vitals__i--hp"><?= svg('eq-health') ?><span class="vitals__v num">435<i>/435</i></span></li>
      <li class="vitals__i vitals__i--hyd"><?= svg('eq-hydration') ?><span class="vitals__v num">100<i>/100</i></span></li>
      <li class="vitals__i vitals__i--nrg"><?= svg('eq-energy') ?><span class="vitals__v num">100<i>/100</i></span></li>
    </ul>
  </div>

  <!-- ======================= COLUNA 3: CONTÊINERES ======================= -->
  <div class="inv2-col inv2-col--bags">
    <?php $bag('rig', 'COLETE TÁTICO', 'eq-rig'); ?>
    <section class="bag bag--pockets">
      <h2 class="bag__tab">BOLSOS</h2>
      <div class="bag__body">
        <div class="bag__grid grid grid--pockets" data-grid data-zone="pockets" data-cols="4" data-rows="1"></div>
      </div>
    </section>
    <?php $bag('backpack', 'MOCHILA', 'eq-backpack'); ?>
  </div>

  <!-- ======================= COLUNA 4: STASH ======================= -->
  <div class="inv2-col inv2-col--stash">
    <h2 class="gear__tab gear__tab--stash">STASH
      <span class="inv2-wallet">
        <span class="w-cur w-cur--credits"><?= svg('coin-credit') ?><b class="num"><?= e(num((int)($cur['credits'] ?? 0))) ?></b></span>
        <span class="w-cur w-cur--cash"><?= svg('coin-cash') ?><b class="num"><?= e(num((int)($cur['cash'] ?? 0))) ?></b></span>
        <span class="w-cur w-cur--points"><?= svg('coin-points') ?><b class="num"><?= e(num((int)($cur['points'] ?? 0))) ?></b></span>
      </span>
    </h2>
    <div class="stash">
      <!-- trilha de filtros: destaca as categorias, não remove itens da grade -->
      <nav class="stash__rail" data-filters aria-label="Filtrar por categoria">
        <?php foreach ([
            ['all', 'TUDO', 'eq-all'], ['weapon', 'ARMAS', 'eq-rifle'], ['ammo', 'MUNIÇÃO', 'eq-ammo'],
            ['armor', 'PROTEÇÃO', 'eq-armor'], ['med', 'MÉDICO', 'eq-med'], ['food', 'CONSUMO', 'eq-food'],
            ['mod', 'ACESSÓRIOS', 'eq-mod'], ['loot', 'ESPÓLIO', 'eq-loot'],
        ] as [$k, $lbl, $ic]): ?>
          <button type="button" class="stash__filter<?= $k === 'all' ? ' is-on' : '' ?>" data-filter="<?= e($k) ?>" title="<?= e($lbl) ?>" aria-label="<?= e($lbl) ?>"><?= svg($ic) ?></button>
        <?php endforeach; ?>
      </nav>
      <div class="grid grid--stash" data-grid data-zone="stash"
           data-cols="<?= e((string)($tree['stash']['cols'] ?? 10)) ?>" data-rows="<?= e((string)($tree['stash']['rows'] ?? 10)) ?>"></div>
    </div>
    <footer class="inv2-foot">
      <span class="inv2-foot__lbl">VALOR TOTAL</span>
      <span class="inv2-foot__val num" data-value>0</span>
      <span class="inv2-hint"><kbd>R</kbd> girar · arraste para mover · <kbd>botão direito</kbd> ações</span>
    </footer>
  </div>

  <!-- modal de acessórios (aberto pelo menu de contexto de uma arma) -->
  <div class="pick" data-pick hidden>
    <div class="pick__box" role="dialog" aria-modal="true" aria-labelledby="pick-title">
      <header class="pick__head">
        <h3 class="pick__title" id="pick-title" data-pick-title>ESCOLHER ITEM</h3>
        <span class="pick__sub" data-pick-sub></span>
        <button type="button" class="pick__close ms" data-pick-close aria-label="Fechar">close</button>
      </header>
      <nav class="pick__slots" data-pick-slots hidden></nav>
      <div class="pick__grid" data-pick-grid></div>
      <p class="pick__empty" data-pick-empty hidden>Nenhum item compatível no seu inventário.</p>
      <footer class="pick__foot">
        <button type="button" class="btn btn--outline pick__unequip" data-pick-unequip hidden>REMOVER ATUAL</button>
        <span class="pick__hint">Clique num item para usar · Esc fecha</span>
      </footer>
    </div>
  </div>

  <div class="drag-ghost" data-drag-ghost hidden></div>
  <div class="inv2-toast" data-toast hidden></div>
</section>
