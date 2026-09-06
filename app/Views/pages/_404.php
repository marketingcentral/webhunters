<?php declare(strict_types=1);

/**
 * Tela de ERRO (404 / 405 / 419 / 500) — layout `auth`, sem referência própria.
 * Âncoras de estilo: 00-login-tela-inicial-do-jogador.png (cartão centrado de duas
 * colunas com divisória, borda quente e realce superior).
 *
 * $data: code, title, message, status, accent, detail|null, path, links[]{label,href,icon},
 *        kicker, labels{path,signal,node,detail,meter}, meter, hint  (app/Data/404.php)
 *
 * Coluna esquerda  -> código gigante em mono com tremulação + marca d'água do emblema + flatline
 * Coluna direita   -> kicker, título, mensagem, telemetria (coordenada / tempo sem sinal / nó) e ações
 */

$code    = (int)($data['code'] ?? 404);
$title   = (string)($data['title'] ?? 'SINAL PERDIDO');
$message = (string)($data['message'] ?? '');
$detail  = $data['detail'] ?? null;
$path    = (string)($data['path'] ?? '');
$links   = is_array($data['links'] ?? null) ? $data['links'] : [];
$labels  = is_array($data['labels'] ?? null) ? $data['labels'] : [];
$kicker  = (string)($data['kicker'] ?? 'DIAGNÓSTICO DE ROTA');
$hint    = (string)($data['hint'] ?? '');
$meter   = (string)($data['meter'] ?? '0%');
$statusL = (string)($data['status'] ?? '');
$accent  = in_array((string)($data['accent'] ?? 'lime'), ['lime', 'cyan', 'amber', 'red'], true)
    ? (string)$data['accent'] : 'lime';

$region  = (string)($server['region'] ?? 'AMÉRICA DO SUL');
$ping    = (int)($server['ping_ms'] ?? 0);
?>
<section class="panel error-panel err" data-boot data-accent="<?= e($accent) ?>" aria-labelledby="error-title">

  <span class="err__wm" aria-hidden="true"><?= svg('emblem') ?></span>
  <span class="err__scan" aria-hidden="true"></span>

  <header class="panel__head err__head">
    <span class="err__badge">
      <span class="err__led" aria-hidden="true"></span>
      <span class="err__badge-lbl">ERRO</span>
      <span class="err__badge-num num"><?= e($code) ?></span>
    </span>
<?php if ($statusL !== ''): ?>
    <span class="err__status"><?= e($statusL) ?></span>
<?php endif; ?>
    <span class="err__head-right">
      <span class="err__meter" aria-hidden="true">
        <i></i><i></i><i></i><i></i>
      </span>
      <span class="err__meter-lbl uc"><?= e((string)($labels['meter'] ?? 'SINAL')) ?> <span class="num"><?= e($meter) ?></span></span>
      <?= svg('emblem-hex', 'err__emblem') ?>
    </span>
  </header>

  <div class="panel__body err__body">

    <div class="err__glyph">
      <p class="err__code num" aria-hidden="true" data-text="<?= e($code) ?>" data-glitch><?= e($code) ?></p>
      <span class="err__flatline" aria-hidden="true"><i></i></span>
    </div>

    <div class="err__main" data-stagger>
      <p class="err__kicker"><?= e($kicker) ?></p>
      <h1 class="page-title err__title" id="error-title"><?= e($title) ?></h1>
      <p class="err__msg"><?= e($message) ?></p>

      <dl class="err__meta">
<?php if ($path !== ''): ?>
        <div class="err__meta-row">
          <dt><?= e((string)($labels['path'] ?? 'COORDENADA')) ?></dt>
          <dd><code class="err__path num"><?= e($path) ?></code></dd>
        </div>
<?php endif; ?>
        <div class="err__meta-row">
          <dt><?= e((string)($labels['signal'] ?? 'TEMPO SEM SINAL')) ?></dt>
          <dd><span class="num err__clock" data-signal-clock>00:00</span></dd>
        </div>
        <div class="err__meta-row">
          <dt><?= e((string)($labels['node'] ?? 'NÓ DE OPERAÇÃO')) ?></dt>
          <dd><span class="err__node"><?= e($region) ?></span> <span class="num err__ping"><?= e((string)$ping) ?> ms</span></dd>
        </div>
      </dl>

<?php if (is_string($detail) && $detail !== ''): ?>
      <p class="err__detail-lbl uc"><?= e((string)($labels['detail'] ?? 'REGISTRO TÉCNICO')) ?></p>
      <pre class="err__detail scroll-y"><?= e($detail) ?></pre>
<?php endif; ?>

      <nav class="err__actions" aria-label="Ações">
<?php foreach ($links as $i => $link): ?>
        <a class="btn <?= $i === 0 ? 'btn--primary btn--lg' : 'btn--outline' ?> err__btn"
           href="<?= e((string)($link['href'] ?? url('inicio'))) ?>">
          <?= icon((string)($link['icon'] ?? 'chevron_right')) ?><span class="btn__label"><?= e((string)($link['label'] ?? '')) ?></span>
        </a>
<?php endforeach; ?>
      </nav>

<?php if ($hint !== ''): ?>
      <p class="err__hint"><?= e($hint) ?></p>
<?php endif; ?>
    </div>

  </div>
</section>
