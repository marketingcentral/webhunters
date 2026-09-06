<?php declare(strict_types=1);
/**
 * Topbar (F3). Scope (given by the layout): $page, $player, $nav, $server.
 * Only $page['nav'] (active slug) and $page['back'] vary between pages:
 *   back => ['label' => 'VOLTAR', 'href' => url('inicio')]   link (reference 10)
 *   back => ['label' => 'SAIR',   'href' => null]            POST /sair (reference 04)
 *   back => null                                             no back button: gear + tall gold LOJA block (reference 01, Home)
 * CSS: assets/css/topbar.css
 */
$player = $player ?? [];
$nav    = $nav ?? [];
$page   = $page ?? [];

$tbName    = (string)($player['name'] ?? 'HUNTER');
$tbLevel   = (int)($player['level'] ?? 1);
$tbXp      = (int)($player['xp'] ?? 0);
$tbXpNext  = max(1, (int)($player['xp_next'] ?? 1));
$tbXpPct   = min(100, max(0, round($tbXp / $tbXpNext * 100, 1)));
$tbCur     = $player['currencies'] ?? [];
$tbFriends = (int)($player['friends_online'] ?? 0);
$tbUnread  = (int)($player['unread_mail'] ?? 0);
$tbActive  = (string)($page['nav'] ?? ($page['slug'] ?? ''));
$tbHasBack = !(array_key_exists('back', $page) && $page['back'] === null);
$tbBack    = $tbHasBack && is_array($page['back'] ?? null) ? $page['back'] : ['label' => 'VOLTAR', 'href' => url('inicio')];
$tbBackLbl = (string)($tbBack['label'] ?? 'VOLTAR');
$tbBackUrl = $tbBack['href'] ?? null;
$tbBackIco = $tbBackUrl === null ? 'logout' : 'undo';
$tbHexFile = __DIR__ . '/../components/hex.php';
?>
<header class="topbar<?= $tbHasBack ? '' : ' topbar--home' ?>" role="banner">
  <a class="topbar__player" href="<?= e(url('perfil')) ?>" aria-label="Perfil de <?= e($tbName) ?>">
    <?php if (is_file($tbHexFile)): ?>
      <?php component('hex', ['size' => 'md', 'color' => 'lime', 'hot' => true, 'icon' => 'skull']) ?>
    <?php else: ?>
      <span class="hex hex--md hex--hot"><span class="hex__in"><?= icon('skull') ?></span></span>
    <?php endif ?>
    <span class="topbar__id">
      <span class="topbar__name"><?= e($tbName) ?></span>
      <span class="topbar__lvl">
        <span class="topbar__lvl-txt">Nível <span class="num"><?= e((string)$tbLevel) ?></span></span>
        <span class="topbar__xp" style="--v:<?= e((string)$tbXpPct) ?>%" role="progressbar" aria-label="Experiência" aria-valuemin="0" aria-valuemax="<?= e((string)$tbXpNext) ?>" aria-valuenow="<?= e((string)$tbXp) ?>"><span class="topbar__xp-bar"></span></span>
      </span>
      <span class="topbar__xpnum num"><?= e(num($tbXp)) ?> / <?= e(num($tbXpNext)) ?> XP</span>
    </span>
  </a>

  <nav class="topbar__nav" aria-label="Menu principal">
    <?php foreach ($nav as $item): ?>
      <?php $isOn = ($item['slug'] ?? '') === $tbActive; ?>
      <a class="topbar__link<?= $isOn ? ' is-active' : '' ?>" href="<?= e(url((string)($item['route'] ?? $item['slug'] ?? ''))) ?>"<?= $isOn ? ' aria-current="page"' : '' ?>><?= e((string)($item['label'] ?? '')) ?></a>
    <?php endforeach ?>
  </nav>

  <?php if ($tbHasBack): ?>
  <a class="btn btn--gold topbar__shop" href="<?= e(url('loja')) ?>"><?= icon('shopping_cart') ?> Loja</a>
  <?php endif ?>

  <div class="topbar__right">
    <a class="topbar__cur topbar__cur--lime" href="<?= e(url('loja')) ?>" title="Créditos"><?= svg('coin-credit') ?><span class="num"><?= e(num((int)($tbCur['credits'] ?? 0))) ?></span></a>
    <a class="topbar__cur topbar__cur--cyan" href="<?= e(url('loja')) ?>" title="Dinheiro"><?= svg('coin-cash') ?><span class="num"><?= e(num((int)($tbCur['cash'] ?? 0))) ?></span></a>
    <a class="topbar__cur topbar__cur--amber" href="<?= e(url('loja')) ?>" title="Pontos"><?= svg('coin-points') ?><span class="num"><?= e(num((int)($tbCur['points'] ?? 0))) ?></span></a>
    <span class="topbar__sep" aria-hidden="true"></span>
    <a class="topbar__ico topbar__friends" href="<?= e(url('amigos')) ?>" title="Amigos online"><?= icon('group', 'fill') ?><span class="num"><?= e((string)$tbFriends) ?></span></a>
    <a class="topbar__ico topbar__mail<?= $tbUnread > 0 ? ' has-unread' : '' ?>" href="<?= e(url('mensagens')) ?>" title="<?= $tbUnread > 0 ? e((string)$tbUnread) . ' mensagens não lidas' : 'Mensagens' ?>"><?= icon('mail', 'fill') ?></a>
    <?php if (!$tbHasBack): ?>
      <a class="topbar__ico topbar__settings" href="<?= e(url('configuracoes')) ?>" title="Configurações"><?= icon('settings', 'fill') ?></a>
      <a class="btn btn--gold topbar__shop topbar__shop--tall" href="<?= e(url('loja')) ?>"><?= icon('shopping_cart') ?><span class="topbar__shop-lbl">Loja</span></a>
    <?php elseif ($tbBackUrl === null): ?>
      <form class="topbar__back-form" method="post" action="<?= e(url('sair')) ?>">
        <?= csrf_field() ?>
        <button class="topbar__back" type="submit"><?= icon($tbBackIco) ?><span><?= e($tbBackLbl) ?></span></button>
      </form>
    <?php else: ?>
      <a class="topbar__back" href="<?= e((string)$tbBackUrl) ?>"><?= icon($tbBackIco) ?><span><?= e($tbBackLbl) ?></span></a>
    <?php endif ?>
  </div>
</header>
