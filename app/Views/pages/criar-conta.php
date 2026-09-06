<?php declare(strict_types=1);

/**
 * Tela CRIAR CONTA — referência `O-QUE-VAMOS-CRIAR/Telas do jogador/00-login-tela-inicial-do-jogador.png`.
 * Layout `auth`. $data: auth (textos de app/Data/auth.php + app/Data/criar-conta.php), errors, values.
 *
 * Mesmo cartão do login (58,25rem x 39,4rem) sobre bg/login.jpg:
 *   coluna esquerda -> logo (variante auth) + painel "DICAS DE SOBREVIVÊNCIA"  (idêntica ao login)
 *   coluna direita  -> formulário CRIAR CONTA: usuário, e-mail, senha + confirmação lado a lado,
 *                      medidor de força, toggle dos termos, botão verde e "Já tem uma conta? ENTRAR"
 *
 * O invólucro `.auth-scope.page-login` existe para reaproveitar literalmente as regras de
 * `assets/css/pages/login.css` (carregado via $page['css'] pelo AuthController), que são escopadas
 * em `.page-login`. `assets/css/pages/criar-conta.css` ajusta o que muda, sempre sob
 * `.page-criar-conta .auth-scope` (especificidade maior que a do login).
 */

$t      = is_array($data['auth']['register'] ?? null) ? $data['auth']['register'] : [];
$tips   = is_array($data['auth']['tips'] ?? null) ? $data['auth']['tips'] : [];
$items  = array_values(array_filter((array)($tips['items'] ?? []), static fn($v) => is_string($v) && $v !== ''));
$errors = is_array($data['errors'] ?? null) ? $data['errors'] : [];
$v      = is_array($data['values'] ?? null) ? $data['values'] : [];

$str    = is_array($t['strength'] ?? null) ? $t['strength'] : [];
$levels = array_values(array_filter((array)($str['levels'] ?? []), static fn($x) => is_string($x)));
$err    = static fn(string $k): string => isset($errors[$k]) && is_string($errors[$k]) ? $errors[$k] : '';
?>
<div class="auth-scope page-login" data-boot>
<section class="panel login-card register-card" data-stagger>

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

  <div class="login-card__main register-card__main" data-stagger>
    <h1 class="page-title login__title"><?= e((string)($t['title'] ?? 'CRIAR CONTA')) ?></h1>
    <p class="login__sub"><?= e((string)($t['subtitle'] ?? '')) ?></p>

    <form class="login__form register__form" method="post" action="<?= e(url('criar-conta')) ?>" novalidate data-stagger>
      <?= csrf_field() ?>

<?php if ($errors !== []): ?>
      <p class="login__error" role="alert"><?= icon('warning') ?><span><?= e($err('form') !== '' ? $err('form') : (string)($t['error_title'] ?? 'Revise os campos destacados para continuar.')) ?></span></p>
<?php endif; ?>

      <div class="field field--icon login__field register__field<?= $err('user') !== '' ? ' is-invalid' : '' ?>">
        <label class="field__label" for="reg-user"><?= e((string)($t['user_label'] ?? 'NOME DE USUÁRIO')) ?></label>
        <input class="input" id="reg-user" type="text" name="user" value="<?= e((string)($v['user'] ?? '')) ?>"
               placeholder="<?= e((string)($t['user_ph'] ?? '')) ?>" autocomplete="username"
               autocapitalize="none" spellcheck="false" maxlength="24" required
               pattern="[A-Za-z0-9_]{3,24}"
               <?= $err('user') !== '' ? 'aria-describedby="reg-user-err" aria-invalid="true"' : 'aria-describedby="reg-user-hint"' ?>>
        <?= icon('person', 'fill login__field-icon') ?>
<?php if ($err('user') !== ''): ?>
        <small class="field__error" id="reg-user-err"><?= icon('warning') ?><span><?= e($err('user')) ?></span></small>
<?php else: ?>
        <small class="field__hint register__hint" id="reg-user-hint"><?= e((string)($t['user_hint'] ?? '')) ?></small>
<?php endif; ?>
      </div>

      <div class="field field--icon login__field register__field<?= $err('email') !== '' ? ' is-invalid' : '' ?>">
        <label class="field__label" for="reg-email"><?= e((string)($t['email_label'] ?? 'E-MAIL')) ?></label>
        <input class="input" id="reg-email" type="email" name="email" value="<?= e((string)($v['email'] ?? '')) ?>"
               placeholder="<?= e((string)($t['email_ph'] ?? '')) ?>" autocomplete="email"
               autocapitalize="none" spellcheck="false" maxlength="120" required
               <?= $err('email') !== '' ? 'aria-describedby="reg-email-err" aria-invalid="true"' : '' ?>>
        <?= icon('mail', 'login__field-icon') ?>
<?php if ($err('email') !== ''): ?>
        <small class="field__error" id="reg-email-err"><?= icon('warning') ?><span><?= e($err('email')) ?></span></small>
<?php endif; ?>
      </div>

      <div class="register__pair">
        <div class="field field--icon login__field register__field<?= $err('password') !== '' ? ' is-invalid' : '' ?>">
          <label class="field__label" for="reg-pass"><?= e((string)($t['pass_label'] ?? 'SENHA')) ?></label>
          <input class="input" id="reg-pass" type="password" name="password"
                 placeholder="<?= e((string)($t['pass_ph'] ?? '')) ?>" autocomplete="new-password"
                 minlength="8" required data-strength-input
                 <?= $err('password') !== '' ? 'aria-describedby="reg-pass-err" aria-invalid="true"' : '' ?>>
          <button class="ms login__eye" type="button" data-pass-toggle="reg-pass" aria-pressed="false"
                  data-label-show="<?= e((string)($t['show_pass'] ?? 'Mostrar senha')) ?>"
                  data-label-hide="<?= e((string)($t['hide_pass'] ?? 'Ocultar senha')) ?>"
                  aria-label="<?= e((string)($t['show_pass'] ?? 'Mostrar senha')) ?>">visibility_off</button>
<?php if ($err('password') !== ''): ?>
          <small class="field__error" id="reg-pass-err"><?= icon('warning') ?><span><?= e($err('password')) ?></span></small>
<?php endif; ?>
        </div>

        <div class="field field--icon login__field register__field<?= $err('password_confirm') !== '' ? ' is-invalid' : '' ?>">
          <label class="field__label" for="reg-pass2"><?= e((string)($t['confirm_label'] ?? 'CONFIRMAR SENHA')) ?></label>
          <input class="input" id="reg-pass2" type="password" name="password_confirm"
                 placeholder="<?= e((string)($t['confirm_ph'] ?? '')) ?>" autocomplete="new-password"
                 minlength="8" required data-strength-match
                 <?= $err('password_confirm') !== '' ? 'aria-describedby="reg-pass2-err" aria-invalid="true"' : '' ?>>
          <button class="ms login__eye" type="button" data-pass-toggle="reg-pass2" aria-pressed="false"
                  data-label-show="<?= e((string)($t['show_pass'] ?? 'Mostrar senha')) ?>"
                  data-label-hide="<?= e((string)($t['hide_pass'] ?? 'Ocultar senha')) ?>"
                  aria-label="<?= e((string)($t['show_pass'] ?? 'Mostrar senha')) ?>">visibility_off</button>
<?php if ($err('password_confirm') !== ''): ?>
          <small class="field__error" id="reg-pass2-err"><?= icon('warning') ?><span><?= e($err('password_confirm')) ?></span></small>
<?php endif; ?>
        </div>
      </div>

      <div class="register__meter" data-strength-meter>
        <span class="register__meter-label"><?= e((string)($str['label'] ?? 'FORÇA')) ?></span>
        <span class="register__meter-bars" aria-hidden="true">
          <i class="register__meter-seg"></i><i class="register__meter-seg"></i><i class="register__meter-seg"></i><i class="register__meter-seg"></i>
        </span>
        <span class="register__meter-value num" role="status" aria-live="polite"
              data-levels="<?= e(implode('|', $levels !== [] ? $levels : ['—', 'FRACA', 'RAZOÁVEL', 'BOA', 'FORTE'])) ?>"><?= e((string)($levels[0] ?? '—')) ?></span>
      </div>

      <label class="toggle toggle--label-right register__terms<?= $err('terms') !== '' ? ' is-invalid' : '' ?>">
        <input type="checkbox" name="terms" value="1" required<?= !empty($v['terms']) ? ' checked' : '' ?>
               <?= $err('terms') !== '' ? 'aria-describedby="reg-terms-err" aria-invalid="true"' : '' ?>>
        <span class="toggle__track"></span>
        <span class="register__terms-text"><?= e((string)($t['terms'] ?? '')) ?></span>
      </label>
<?php if ($err('terms') !== ''): ?>
      <small class="field__error register__terms-err" id="reg-terms-err"><?= icon('warning') ?><span><?= e($err('terms')) ?></span></small>
<?php endif; ?>

      <button class="btn btn--primary btn--lg btn--block login__submit register__submit" type="submit">
        <?= icon('person_add') ?><span class="btn__label"><?= e((string)($t['submit'] ?? 'CRIAR CONTA')) ?></span>
      </button>
    </form>

    <p class="login__signup register__signin">
      <?= e((string)($t['has_account'] ?? 'Já tem uma conta?')) ?>
      <a class="link login__signup-link" href="<?= e(url('login')) ?>"><?= e((string)($t['login'] ?? 'ENTRAR')) ?></a>
    </p>
  </div>

</section>
</div>
