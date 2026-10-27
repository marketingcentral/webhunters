<?php declare(strict_types=1);

/**
 * Tela ADMIN · LOGIN — porta do painel administrativo. Layout `admin-auth`.
 * $data: erro (string|null), usuario (string, o que foi digitado).
 *
 * Cartão único e estreito, sem "criar conta" e sem "esqueci a senha": não existe cadastro
 * de administrador por formulário — o acesso vem do papel gravado em users.role.
 */

$erro    = trim((string)($data['erro'] ?? ''));
$usuario = (string)($data['usuario'] ?? '');
?>
<section class="adm-login">

  <header class="adm-login__head">
    <?php component('logo', ['variant' => 'auth', 'class' => 'adm-login__logo']) ?>
    <p class="adm-login__eyebrow"><?= icon('shield_person') ?><span>Painel administrativo</span></p>
    <h1 class="adm-login__title">Acesso restrito</h1>
    <p class="adm-login__sub">Entre com a sua conta do jogo. Só contas com permissão administrativa passam daqui.</p>
  </header>

  <form class="adm-form adm-login__form" method="post" action="<?= e(url('admin/login')) ?>" novalidate>
    <?= csrf_field() ?>

<?php if ($erro !== ''): ?>
    <p class="adm-error adm-login__erro" role="alert"><?= icon('error') ?><span><?= e($erro) ?></span></p>
<?php endif; ?>

    <div class="adm-field">
      <label class="adm-label" for="adm-user">E-mail ou nome de usuário</label>
      <input class="adm-input" id="adm-user" type="text" name="user" value="<?= e($usuario) ?>"
             autocomplete="username" autocapitalize="none" spellcheck="false"
             maxlength="60" required autofocus>
    </div>

    <div class="adm-field adm-login__senha">
      <label class="adm-label" for="adm-pass">Senha</label>
      <input class="adm-input" id="adm-pass" type="password" name="password"
             autocomplete="current-password" required>
      <button class="adm-login__eye" type="button" data-adm-eye="adm-pass" aria-pressed="false"
              aria-label="Mostrar senha"><?= icon('visibility_off') ?></button>
    </div>

    <button class="adm-btn adm-btn--primary adm-login__submit" type="submit">
      <?= icon('login') ?><span>Entrar no painel</span>
    </button>
  </form>

  <footer class="adm-login__foot">
    <a class="adm-login__back" href="<?= e(url('inicio')) ?>"><?= icon('arrow_back') ?><span>Voltar ao jogo</span></a>
    <p class="adm-login__nota">Cada tentativa, aprovada ou não, fica registrada na auditoria.</p>
  </footer>

</section>
