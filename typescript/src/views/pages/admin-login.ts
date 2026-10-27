/**
 * Tela ADMIN · LOGIN — app/Views/pages/admin-login.php. Layout `admin-auth`.
 * s.data: erro (string|null), usuario (o que foi digitado).
 *
 * Cartão único, sem "criar conta" e sem "esqueci a senha": o acesso vem de users.role.
 */
import { csrfField, icon, url } from '../../core/helpers';
import { html, type SafeHtml } from '../../core/html';
import type { Scope } from '../../core/view';
import { logo } from '../components/index';

export function adminLogin(s: Scope): SafeHtml {
  const erro = String(s.data.erro ?? '').trim();
  const usuario = String(s.data.usuario ?? '');
  return html`<section class="adm-login">

  <header class="adm-login__head">
    ${logo({ variant: 'auth', class: 'adm-login__logo' })}    <p class="adm-login__eyebrow">${icon('shield_person')}<span>Painel administrativo</span></p>
    <h1 class="adm-login__title">Acesso restrito</h1>
    <p class="adm-login__sub">Entre com a sua conta do jogo. Só contas com permissão administrativa passam daqui.</p>
  </header>

  <form class="adm-form adm-login__form" method="post" action="${url('admin/login')}" novalidate>
    ${csrfField()}
${erro !== '' ? html`    <p class="adm-error adm-login__erro" role="alert">${icon('error')}<span>${erro}</span></p>
` : ''}
    <div class="adm-field">
      <label class="adm-label" for="adm-user">E-mail ou nome de usuário</label>
      <input class="adm-input" id="adm-user" type="text" name="user" value="${usuario}"
             autocomplete="username" autocapitalize="none" spellcheck="false"
             maxlength="60" required autofocus>
    </div>

    <div class="adm-field adm-login__senha">
      <label class="adm-label" for="adm-pass">Senha</label>
      <input class="adm-input" id="adm-pass" type="password" name="password"
             autocomplete="current-password" required>
      <button class="adm-login__eye" type="button" data-adm-eye="adm-pass" aria-pressed="false"
              aria-label="Mostrar senha">${icon('visibility_off')}</button>
    </div>

    <button class="adm-btn adm-btn--primary adm-login__submit" type="submit">
      ${icon('login')}<span>Entrar no painel</span>
    </button>
  </form>

  <footer class="adm-login__foot">
    <a class="adm-login__back" href="${url('inicio')}">${icon('arrow_back')}<span>Voltar ao jogo</span></a>
    <p class="adm-login__nota">Cada tentativa, aprovada ou não, fica registrada na auditoria.</p>
  </footer>

</section>
`;
}
