<?php declare(strict_types=1);
/**
 * Footer (F3). Scope (given by the layout): $server. Identical on every page.
 * CSS: assets/css/footer.css
 */
$server   = $server ?? [];
$ftMin    = !empty($minimal);   /* layouts/auth.php: only the server status, no glass bar (reference 00 has no footer) */
$ftRegion = (string)($server['region'] ?? 'AMÉRICA DO SUL');
$ftPing   = (int)($server['ping_ms'] ?? 0);
$ftOnline = (($server['status'] ?? 'online') === 'online');
$ftBars   = min(4, max(0, (int)($server['bars'] ?? 4)));
$ftLit    = $ftBars;   /* server.php says how many bars are lit (reference 10: all four at 32ms) */
$ftSocial = [
  ['id' => 'discord',   'label' => 'Discord',   'href' => 'https://discord.com/'],
  ['id' => 'twitter',   'label' => 'Twitter',   'href' => 'https://twitter.com/'],
  ['id' => 'youtube',   'label' => 'YouTube',   'href' => 'https://www.youtube.com/'],
  ['id' => 'instagram', 'label' => 'Instagram', 'href' => 'https://www.instagram.com/'],
];
?>
<footer class="footer<?= $ftMin ? " footer--minimal" : "" ?>" role="contentinfo">
<?php if ($ftMin): ?>
  <a class="footer__link" href="<?= e(url("suporte")) ?>"><?= icon("headset_mic") ?><span>Suporte</span></a>
<?php else: ?>
  <div class="footer__social">
    <span class="footer__label">Siga-nos</span>
    <?php foreach ($ftSocial as $s): ?>
      <a class="footer__soc" href="<?= e($s['href']) ?>" target="_blank" rel="noopener noreferrer" title="<?= e($s['label']) ?>" aria-label="<?= e($s['label']) ?>"><?= svg($s['id']) ?></a>
    <?php endforeach ?>
  </div>

  <div class="footer__actions">
    <a class="btn btn--ghost footer__btn" href="<?= e(url('suporte')) ?>"><?= icon('headset_mic') ?> Suporte</a>
    <a class="btn btn--ghost footer__btn" href="<?= e(url('relatorio-de-bug')) ?>"><?= icon('bug_report') ?> Relatório de bug</a>
  </div>
<?php endif ?>

  <div class="footer__server">
    <span class="footer__server-lbl">Servidor: <?= e($ftRegion) ?></span>
    <span class="footer__bars" aria-label="Qualidade da conexão" role="img"><?php for ($i = 1; $i <= 4; $i++): ?><i<?= $i <= $ftLit ? ' class="is-on"' : '' ?>></i><?php endfor ?></span>
    <span class="footer__ping num"><?= e((string)$ftPing) ?>ms</span>
    <span class="footer__dot<?= $ftOnline ? '' : ' is-off' ?>" title="<?= $ftOnline ? 'Online' : 'Offline' ?>" aria-label="<?= $ftOnline ? 'Servidor online' : 'Servidor offline' ?>" role="img"></span>
  </div>
</footer>
