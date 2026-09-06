<?php declare(strict_types=1);
/**
 * Tela LOJA — sem referência bitmap; âncora de estilo "01-Menu-do-jogo-inicio.png".
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf.
 * $data: title, kicker, intro, tip, wallet_label, wallet, categories, active_category,
 *        featured (cromo + oferta real do banco), items (shop_offers), balance,
 *        cta_buy, cta_locked, cta_busy, cta_owned, empty, soon.
 * CSS: assets/css/pages/loja.css   JS: assets/js/pages/loja.js
 *
 * Comprar é um POST real para /loja/comprar (com csrf_field); loja.js intercepta o submit,
 * envia por fetch e atualiza a carteira sem recarregar. Sem JS, o POST recarrega a tela.
 */

$shopTip   = (string)($data['tip'] ?? '');
$tipLow    = (string)($data['tip_low'] ?? 'Saldo insuficiente');
$ctaBuy    = (string)($data['cta_buy'] ?? 'COMPRAR');
$ctaLocked = (string)($data['cta_locked'] ?? 'SEM SALDO');
$ctaBusy   = (string)($data['cta_busy'] ?? 'COMPRANDO');
$ctaOwned  = (string)($data['cta_owned'] ?? 'NO STASH');
$cats      = (array)($data['categories'] ?? []);
$active    = (string)($data['active_category'] ?? 'destaques');
$allItems  = (array)($data['items'] ?? []);
$featured  = (array)($data['featured'] ?? []);
$wallet    = (array)($data['wallet'] ?? []);
$purse     = (array)($data['balance'] ?? $player['currencies'] ?? []);
$buyUrl    = url('loja/comprar');

/* moeda -> glifo do sprite + classe de cor (SPEC §5) */
$curMeta = [
    'credits' => ['svg' => 'coin-credit', 'cls' => 'lime'],
    'cash'    => ['svg' => 'coin-cash',   'cls' => 'cyan'],
    'points'  => ['svg' => 'coin-points', 'cls' => 'amber'],
];

/** Chip de preço na cor da moeda. */
$priceChip = static function (int $value, string $currency, string $extra = '') use ($curMeta): string {
    $m = $curMeta[$currency] ?? $curMeta['credits'];
    return '<span class="price price--' . e($m['cls']) . ($extra !== '' ? ' ' . e($extra) : '') . '">'
        . svg($m['svg'], 'price__coin')
        . '<span class="price__val num">' . e(num($value)) . '</span></span>';
};

/**
 * Formulário de compra (POST real + csrf). $it precisa de offer_id/price/currency.
 * @param array<string,mixed> $it
 */
$buyForm = static function (array $it, string $btnClass, string $formClass) use (
    $buyUrl, $active, $ctaBuy, $ctaLocked, $shopTip, $tipLow
): string {
    $ok  = !empty($it['affordable']);
    $tip = $ok ? $shopTip : $tipLow;
    return '<form class="' . e($formClass) . '" method="post" action="' . e($buyUrl) . '" data-buy'
        . ' data-tip="' . e($tip) . '">'
        . csrf_field()
        . '<input type="hidden" name="offer_id" value="' . e((string)(int)($it['offer_id'] ?? 0)) . '">'
        . '<input type="hidden" name="categoria" value="' . e($active) . '">'
        . '<button class="' . e($btnClass) . '" type="submit" data-buy-btn'
        . ($ok ? '' : ' disabled') . '>'
        . icon($ok ? 'shopping_cart' : 'lock')
        . '<span data-buy-label>' . e($ok ? $ctaBuy : $ctaLocked) . '</span>'
        . '</button></form>';
};

/* itens da categoria ativa (DESTAQUES = vitrine curada) */
$items = array_values(array_filter($allItems, static function (array $it) use ($active): bool {
    return $active === 'destaques' ? !empty($it['featured']) : (string)($it['category'] ?? '') === $active;
}));

/* contagem por aba */
$counts = ['destaques' => 0];
foreach ($allItems as $it) {
    $c = (string)($it['category'] ?? '');
    $counts[$c] = ($counts[$c] ?? 0) + 1;
    if (!empty($it['featured'])) { $counts['destaques']++; }
}

$catLabel = [];
foreach ($cats as $c) { $catLabel[(string)($c['slug'] ?? '')] = (string)($c['label'] ?? ''); }

$tabItems = [];
foreach ($cats as $c) {
    $slug = (string)($c['slug'] ?? '');
    $tabItems[] = [
        'label'  => (string)($c['label'] ?? ''),
        'icon'   => (string)($c['icon'] ?? ''),
        'href'   => url('loja', $slug === 'destaques' ? [] : ['categoria' => $slug]),
        'count'  => (string)($counts[$slug] ?? 0),
        'active' => $slug === $active,
    ];
}

$promo  = (array)($featured['offer'] ?? []);
$fPrice = (int)($featured['price'] ?? 0);
$fOld   = (int)($featured['old_price'] ?? 0);
$fCur   = (string)($featured['currency'] ?? 'cash');
?>
<div class="shop"
     data-shop
     data-cta-buy="<?= e($ctaBuy) ?>"
     data-cta-locked="<?= e($ctaLocked) ?>"
     data-cta-busy="<?= e($ctaBusy) ?>"
     data-tip-buy="<?= e($shopTip) ?>"
     data-tip-low="<?= e($tipLow) ?>"
     data-balance="<?= e((string)json_encode([
         'credits' => (int)($purse['credits'] ?? 0),
         'cash'    => (int)($purse['cash'] ?? 0),
         'points'  => (int)($purse['points'] ?? 0),
     ])) ?>">

  <!-- ============================ cabeçalho + carteira ============================ -->
  <header class="shop__head" data-boot>
    <div class="shop__id">
      <p class="shop__kicker"><?= icon('local_fire_department', 'shop__kicker-ico') ?><?= e((string)($data['kicker'] ?? '')) ?></p>
      <h1 class="page-title shop__title"><?= e((string)($data['title'] ?? 'LOJA')) ?><small><?= e((string)($data['season'] ?? '')) ?></small></h1>
      <p class="page-subtitle shop__intro"><?= e((string)($data['intro'] ?? '')) ?></p>
    </div>

    <div class="wallet" aria-label="<?= e((string)($data['wallet_label'] ?? 'Carteira')) ?>">
      <span class="wallet__label"><?= icon('account_balance_wallet', 'wallet__label-ico') ?><?= e((string)($data['wallet_label'] ?? 'CARTEIRA')) ?></span>
      <ul class="wallet__list">
<?php foreach ($wallet as $w): ?>
<?php $wKey = (string)($w['key'] ?? ''); $wVal = (int)($purse[$wKey] ?? 0); ?>
        <li class="wallet__coin wallet__coin--<?= e((string)($w['color'] ?? 'lime')) ?>">
          <?= svg((string)($w['svg'] ?? 'coin-credit'), 'wallet__ico') ?>
          <span class="wallet__txt">
            <span class="wallet__val num" data-wallet="<?= e($wKey) ?>" data-count="<?= e((string)$wVal) ?>" data-dur="1100"><?= e(num($wVal)) ?></span>
            <span class="wallet__name"><?= e((string)($w['label'] ?? '')) ?></span>
          </span>
        </li>
<?php endforeach; ?>
      </ul>
    </div>
  </header>

  <!-- ============================== abas de categoria ============================= -->
  <div class="shop__tabs" data-boot>
    <?php component('tabs', ['items' => $tabItems, 'label' => 'Categorias da loja', 'class' => 'shop__tabnav']) ?>
    <p class="shop__note"><?= icon((string)($data['tip_icon'] ?? 'bolt'), 'shop__note-ico') ?><?= e($shopTip) ?></p>
  </div>

  <!-- ============================== pacote em destaque ============================ -->
  <section class="panel promo<?= empty($promo['affordable']) ? ' is-locked' : '' ?>" data-boot
           data-offer="<?= e((string)(int)($promo['offer_id'] ?? 0)) ?>"
           data-price="<?= e((string)$fPrice) ?>"
           data-currency="<?= e($fCur) ?>">
    <div class="promo__art" aria-hidden="true">
      <img class="promo__bg" src="<?= e(media((string)($featured['image'] ?? 'ui/season-pass.jpg'))) ?>" alt="" decoding="async">
      <img class="promo__product float" src="<?= e(media((string)($featured['product'] ?? 'items/weapon-case.png'))) ?>" alt="" decoding="async">
    </div>

    <div class="promo__info">
      <p class="promo__kicker">
        <span class="chip chip--gold chip--solid promo__tag"><?= e((string)($featured['tag'] ?? 'OFERTA')) ?></span>
        <span class="promo__kicker-txt"><?= e((string)($featured['kicker'] ?? '')) ?></span>
      </p>
      <h2 class="promo__title"><?= e((string)($featured['title'] ?? '')) ?></h2>
      <p class="promo__desc"><?= e((string)($featured['desc'] ?? '')) ?></p>
      <ul class="promo__bullets">
<?php foreach ((array)($featured['bullets'] ?? []) as $b): ?>
        <li class="promo__bullet"><?= icon('check_circle', 'fill') ?><?= e((string)$b) ?></li>
<?php endforeach; ?>
      </ul>
    </div>

    <div class="promo__buy">
      <p class="promo__timer">
        <?= icon('schedule', 'promo__timer-ico') ?>
        <span class="promo__timer-lbl"><?= e((string)($featured['ends_label'] ?? 'TERMINA EM')) ?></span>
        <span class="promo__clock num" data-countdown="<?= e((string)($featured['ends_at'] ?? '')) ?>">--</span>
      </p>
<?php if ($fOld > 0): ?>
      <p class="promo__prices">
        <span class="promo__old num"><?= e(num($fOld)) ?></span>
        <span class="chip chip--red promo__disc"><?= e((string)($featured['discount'] ?? '')) ?></span>
      </p>
<?php endif; ?>
      <?= $priceChip($fPrice, $fCur, 'price--lg promo__price') ?>
<?php if (!empty($promo['offer_id'])): ?>
      <?= $buyForm($promo, 'btn btn--gold btn--lg promo__cta', 'promo__form') ?>
<?php endif; ?>
    </div>
  </section>

  <!-- ================================ grade de itens ============================== -->
<?php if ($items === []): ?>
  <p class="shop__empty muted" data-boot><?= e((string)($data['empty'] ?? '')) ?></p>
<?php else: ?>
  <div class="shop__grid" data-stagger>
<?php foreach ($items as $it): ?>
<?php
    $iCur   = (string)($it['currency'] ?? 'credits');
    $iOwned = !empty($it['owned']);
    $iPoor  = empty($it['affordable']);
    $iTag   = (string)($it['tag'] ?? '');
    $tagCol = match ($iTag) {
        'NOVO'     => 'lime',
        'POPULAR'  => 'cyan',
        'RARO'     => 'purple',
        'ÉPICO'    => 'gold',
        'LIMITADO' => 'amber',
        default    => 'red',
    };
?>
    <article class="item<?= $iOwned ? ' is-owned' : '' ?><?= $iPoor ? ' is-locked' : '' ?>"
             data-slug="<?= e((string)($it['slug'] ?? '')) ?>"
             data-offer="<?= e((string)(int)($it['offer_id'] ?? 0)) ?>"
             data-price="<?= e((string)(int)($it['price'] ?? 0)) ?>"
             data-currency="<?= e($iCur) ?>">
      <div class="item__media">
        <span class="item__glow" aria-hidden="true"></span>
        <img class="item__img" src="<?= e(media((string)($it['image'] ?? ''))) ?>" alt="<?= e((string)($it['name'] ?? '')) ?>" loading="lazy" decoding="async">
<?php if ($iTag !== ''): ?>
        <span class="item__tag"><?php component('chip', ['label' => $iTag, 'color' => $tagCol]) ?></span>
<?php endif; ?>
        <span class="item__owned"<?= $iOwned ? '' : ' hidden' ?> data-owned-badge><?= icon('check_circle', 'fill') ?><?= e($ctaOwned) ?></span>
        <?= $buyForm($it, 'btn btn--primary btn--sm item__buy', 'item__hover') ?>
      </div>
      <div class="item__body">
        <h3 class="item__name"><?= e((string)($it['name'] ?? '')) ?></h3>
        <p class="item__desc"><?= e((string)($it['desc'] ?? '')) ?></p>
        <div class="item__foot">
          <?= $priceChip((int)($it['price'] ?? 0), $iCur) ?>
          <span class="item__cat"><?= e($catLabel[(string)($it['category'] ?? '')] ?? '') ?></span>
        </div>
      </div>
    </article>
<?php endforeach; ?>
<?php /* a grade é sempre 6x2: as vagas que sobram viram nichos vazios do mostruário */ ?>
<?php for ($i = count($items); $i < 12; $i++): ?>
    <div class="item item--soon" aria-hidden="true">
      <span class="item__soon"><?= icon('lock', 'item__soon-ico') ?><span class="item__soon-txt"><?= e((string)($data['soon'] ?? 'EM BREVE')) ?></span></span>
    </div>
<?php endfor; ?>
  </div>
<?php endif; ?>
</div>
