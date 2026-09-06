<?php declare(strict_types=1);

/**
 * Tela LOGIN — referência `O-QUE-VAMOS-CRIAR/Telas do jogador/00-login-tela-inicial-do-jogador.png`.
 * Layout `auth`. $data: auth (textos de app/Data/auth.php + app/Data/login.php), error, user.
 *
 * Cartão de 58,25rem x 39,6rem centrado sobre bg/login.jpg:
 *   coluna esquerda  -> logo (variante auth) + painel "DICAS DE SOBREVIVÊNCIA"
 *   coluna direita   -> formulário ENTRAR NA CONTA + Steam + criar conta
 */

$t     = is_array($data['auth']['login'] ?? null) ? $data['auth']['login'] : [];
$tips  = is_array($data['auth']['tips'] ?? null) ? $data['auth']['tips'] : [];
$items = array_values(array_filter((array)($tips['items'] ?? []), static fn($v) => is_string($v) && $v !== ''));
$error = trim((string)($data['error'] ?? ''));
$user  = (string)($data['user'] ?? '');
?>
<section class="panel login-card" data-boot data-stagger>

  <aside class="login-card__aside" data-stagger>
    <?php component('logo', ['variant' => 'auth']) ?>

    <div class="login-tips" data-tip-rotator data-interval="8000">
      <p class="login-tips__head">
        <span class="login-tips__icon" aria-hidden="true"><?= icon('shield') ?><?= icon('star', 'fill login-tips__star') ?></span>
        <span class="login-tips__title"><?= e((string)($tips['title'] ?? 'DICAS DE SOBREVIVÊNCIA')) ?></span>
      </p>
      <div class="login-tips__stack" aria-live="off">
<?php foreach ($items as $i => $tip): ?>
        <p class="login-tips__text<?= $i === 0 ? ' is-current' : '' ?>"<?= $i === 0 ? '' : ' aria-hidden="true"' ?>><?= e($tip) ?></p>
<?php endforeach; ?>
      </div>
    </div>
  </aside>

  <div class="login-card__main" data-stagger>
    <h1 class="page-title login__title"><?= e((string)($t['title'] ?? 'ENTRAR NA CONTA')) ?></h1>
    <p class="login__sub"><?= e((string)($t['subtitle'] ?? '')) ?></p>

    <form class="login__form" method="post" action="<?= e(url('login')) ?>" novalidate>
      <?= csrf_field() ?>

<?php if ($error !== ''): ?>
      <p class="login__error" role="alert"><?= icon('warning') ?><span><?= e($error) ?></span></p>
<?php endif; ?>

      <div class="field field--icon login__field">
        <label class="field__label" for="login-user"><?= e((string)($t['user_label'] ?? 'E-MAIL OU NOME DE USUÁRIO')) ?></label>
        <input class="input" id="login-user" type="text" name="user" value="<?= e($user) ?>"
               placeholder="<?= e((string)($t['user_ph'] ?? '')) ?>" autocomplete="username"
               autocapitalize="none" spellcheck="false" maxlength="60" required>
        <?= icon('person', 'fill login__field-icon') ?>
      </div>

      <div class="field field--icon login__field">
        <label class="field__label" for="login-pass"><?= e((string)($t['pass_label'] ?? 'SENHA')) ?></label>
        <input class="input" id="login-pass" type="password" name="password"
               placeholder="<?= e((string)($t['pass_ph'] ?? '')) ?>" autocomplete="current-password" required>
        <button class="ms login__eye" type="button" data-pass-toggle="login-pass" aria-pressed="false"
                data-label-show="<?= e((string)($t['show_pass'] ?? 'Mostrar senha')) ?>"
                data-label-hide="<?= e((string)($t['hide_pass'] ?? 'Ocultar senha')) ?>"
                aria-label="<?= e((string)($t['show_pass'] ?? 'Mostrar senha')) ?>">visibility_off</button>
      </div>

      <a class="link login__forgot" href="<?= e(url('recuperar-senha')) ?>"><?= e((string)($t['forgot'] ?? 'Esqueceu sua senha?')) ?></a>

      <button class="btn btn--primary btn--lg btn--block login__submit" type="submit">
        <?= icon('logout') ?><span class="btn__label"><?= e((string)($t['submit'] ?? 'ENTRAR')) ?></span>
      </button>
    </form>

    <p class="divider divider--text login__or"><?= e((string)($t['or'] ?? 'OU')) ?></p>

    <button class="btn btn--outline btn--lg btn--block login__steam" type="button"
            data-steam data-tip="<?= e((string)($t['steam_tip'] ?? '')) ?>">
      <?= svg('steam', 'login__steam-glyph') ?><span class="btn__label"><?= e((string)($t['steam'] ?? 'ENTRAR COM STEAM')) ?></span>
    </button>

    <p class="login__signup">
      <?= e((string)($t['no_account'] ?? 'Não tem uma conta?')) ?>
      <a class="link login__signup-link" href="<?= e(url('criar-conta')) ?>"><?= e((string)($t['create'] ?? 'CRIAR CONTA')) ?></a>
    </p>
  </div>

</section>
