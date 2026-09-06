<?php declare(strict_types=1);

/**
 * Tela RECUPERAR SENHA — referência `O-QUE-VAMOS-CRIAR/Telas do jogador/00-login-tela-inicial-do-jogador.png`.
 * Layout `auth`. $data: auth (app/Data/auth.php + app/Data/recuperar-senha.php), sent, error, email.
 *
 * Mesmo cartão do login (58,25rem x 39,4rem) sobre bg/login.jpg:
 *   coluna esquerda -> logo (variante auth) + painel "DICAS DE SOBREVIVÊNCIA"
 *   coluna direita  -> e-mail + ENVIAR LINK + "COMO FUNCIONA" (3 passos) + volta para ENTRAR
 */

$t     = is_array($data['auth']['recover'] ?? null) ? $data['auth']['recover'] : [];
$tips  = is_array($data['auth']['tips'] ?? null) ? $data['auth']['tips'] : [];
$items = array_values(array_filter((array)($tips['items'] ?? []), static fn($v) => is_string($v) && $v !== ''));

$steps = [];
foreach ((array)($t['steps'] ?? []) as $s) {
    if (is_array($s) && isset($s['title'])) {
        $steps[] = ['title' => (string)$s['title'], 'text' => (string)($s['text'] ?? '')];
    }
}

$sent  = !empty($data['sent']);
$error = trim((string)($data['error'] ?? ''));
$email = (string)($data['email'] ?? '');
$secs  = max(10, min(900, (int)($t['resend_secs'] ?? 60)));

// destino mascarado: h*****r@dominio.com (nunca ecoa o endereço inteiro na tela enviada)
$masked = '';
if ($sent && $email !== '' && str_contains($email, '@')) {
    [$user, $host] = explode('@', $email, 2);
    $len    = mb_strlen($user);
    $masked = ($len > 2 ? mb_substr($user, 0, 1) . str_repeat('*', min(6, $len - 2)) . mb_substr($user, -1) : str_repeat('*', $len)) . '@' . $host;
}

// passo aceso na faixa "COMO FUNCIONA": 1 antes do envio, 2 depois
$current = $sent ? 1 : 0;
?>
<section class="panel recover-card" data-boot data-stagger>

  <aside class="recover-card__aside" data-stagger>
    <?php component('logo', ['variant' => 'auth']) ?>

    <div class="recover-tips" data-tip-rotator data-interval="8000">
      <p class="recover-tips__head">
        <span class="recover-tips__icon" aria-hidden="true"><?= icon('shield') ?><?= icon('star', 'fill recover-tips__star') ?></span>
        <span class="recover-tips__title"><?= e((string)($tips['title'] ?? 'DICAS DE SOBREVIVÊNCIA')) ?></span>
      </p>
      <div class="recover-tips__stack" aria-live="off">
<?php foreach ($items as $i => $tip): ?>
        <p class="recover-tips__text<?= $i === 0 ? ' is-current' : '' ?>"<?= $i === 0 ? '' : ' aria-hidden="true"' ?>><?= e($tip) ?></p>
<?php endforeach; ?>
      </div>
    </div>
  </aside>

  <div class="recover-card__main<?= $sent ? ' recover-card__main--sent' : '' ?><?= $error !== '' ? ' recover-card__main--error' : '' ?>" data-stagger>
    <h1 class="page-title recover__title"><?= e((string)($t['title'] ?? 'RECUPERAR SENHA')) ?></h1>
    <p class="recover__sub"><?= e((string)($t['subtitle'] ?? '')) ?></p>

<?php if ($sent): ?>
    <div class="recover__done" role="status">
      <p class="recover__done-head">
        <span class="recover__done-icon" aria-hidden="true"><?= icon('check_circle', 'fill') ?></span>
        <span class="recover__done-title"><?= e((string)($t['sent_title'] ?? 'LINK ENVIADO')) ?></span>
      </p>
      <p class="recover__done-text"><?= e((string)($t['sent'] ?? '')) ?></p>
<?php if ($masked !== ''): ?>
      <p class="recover__done-to">
        <span class="recover__done-label"><?= e((string)($t['sent_to'] ?? 'DESTINO')) ?></span>
        <span class="recover__done-mail num"><?= e($masked) ?></span>
      </p>
<?php endif; ?>
    </div>

    <form class="recover__form recover__form--resend" method="post" action="<?= e(url('recuperar-senha')) ?>" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="email" value="<?= e($email) ?>">
      <button class="btn btn--primary btn--lg btn--block recover__submit" type="submit"
              data-resend="<?= e((string)$secs) ?>"
              data-label-wait="<?= e((string)($t['resend_wait'] ?? 'REENVIAR EM')) ?>"
              data-label-ready="<?= e((string)($t['resend'] ?? 'REENVIAR LINK')) ?>" disabled>
        <?= icon('schedule') ?><span class="btn__label"><?= e((string)($t['resend_wait'] ?? 'REENVIAR EM')) ?> <span class="num recover__clock">00:<?= e(str_pad((string)$secs, 2, '0', STR_PAD_LEFT)) ?></span></span>
      </button>
    </form>
<?php else: ?>
    <form class="recover__form" method="post" action="<?= e(url('recuperar-senha')) ?>" novalidate>
      <?= csrf_field() ?>

      <div class="field field--icon recover__field<?= $error !== '' ? ' is-invalid' : '' ?>">
        <label class="field__label" for="recover-email"><?= e((string)($t['email_label'] ?? 'E-MAIL DA CONTA')) ?></label>
        <input class="input" id="recover-email" type="email" name="email" value="<?= e($email) ?>"
               placeholder="<?= e((string)($t['email_ph'] ?? '')) ?>" autocomplete="email" inputmode="email"
               autocapitalize="none" spellcheck="false" maxlength="120" required
               <?= $error !== '' ? 'aria-invalid="true" aria-describedby="recover-erro"' : '' ?>>
        <?= icon('mail', 'recover__field-icon') ?>
<?php if ($error !== ''): ?>
        <span class="field__error recover__error" id="recover-erro" role="alert"><?= icon('warning') ?><span><?= e($error) ?></span></span>
<?php endif; ?>
      </div>

      <button class="btn btn--primary btn--lg btn--block recover__submit" type="submit">
        <?= icon('send') ?><span class="btn__label"><?= e((string)($t['submit'] ?? 'ENVIAR LINK')) ?></span>
      </button>
    </form>
<?php endif; ?>

    <p class="divider divider--text recover__steps-title"><?= e((string)($t['steps_title'] ?? 'COMO FUNCIONA')) ?></p>

<?php if ($steps !== []): ?>
    <ol class="recover-steps">
<?php foreach ($steps as $i => $step): ?>
      <li class="recover-step<?= $i === $current ? ' is-current' : '' ?><?= $i < $current ? ' is-done' : '' ?>">
        <?php /* 'size' e 'color' são sempre explícitos: hex.php relê a chave depois do `??`. */ ?>
        <?php component('hex', ['size' => 'sm', 'color' => 'lime', 'text' => $i + 1, 'flat' => true, 'class' => 'recover-step__hex']) ?>
        <span class="recover-step__text">
          <strong><?= e($step['title']) ?></strong>
          <small><?= e($step['text']) ?></small>
        </span>
      </li>
<?php endforeach; ?>
    </ol>
<?php endif; ?>

    <p class="recover__note">
      <?= icon('lock', 'recover__note-icon') ?>
      <span><?= e((string)($t['note'] ?? '')) ?>
        <a class="link recover__note-link" href="<?= e(url('suporte')) ?>"><?= e((string)($t['note_link'] ?? 'SUPORTE')) ?></a>.</span>
    </p>

    <p class="recover__back">
      <?= e((string)($t['remember'] ?? 'Lembrou sua senha?')) ?>
      <a class="link recover__back-link" href="<?= e(url('login')) ?>"><?= e((string)($t['back'] ?? 'ENTRAR')) ?></a>
    </p>
  </div>

</section>
