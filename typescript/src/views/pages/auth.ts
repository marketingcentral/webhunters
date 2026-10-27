/**
 * Telas de entrada — app/Views/pages/{login,criar-conta}.php e a tela de erro (_404.php).
 */
import { csrfField, icon, svg, url } from '../../core/helpers';
import { html, join, raw, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { logo } from '../components';

function dicas(tips: Dict): SafeHtml {
  const items = ((tips.items ?? []) as unknown[]).filter((v): v is string => typeof v === 'string' && v !== '');
  return html`<aside class="login-card__aside" data-stagger>
    ${logo({ variant: 'auth' })}
    <div class="login-tips" data-tip-rotator data-interval="8000">
      <p class="login-tips__head">
        <span class="login-tips__icon" aria-hidden="true">${icon('shield')}${icon('star', 'fill login-tips__star')}</span>
        <span class="login-tips__title">${String(tips.title ?? 'DICAS DE SOBREVIVÊNCIA')}</span>
      </p>
      <div class="login-tips__stack" aria-live="off">
${join(items.map((tip, i) => html`        <p class="login-tips__text${i === 0 ? ' is-current' : ''}"${i === 0 ? '' : raw(' aria-hidden="true"')}>${tip}</p>
`))}      </div>
    </div>
  </aside>`;
}

function olho(id: string, t: Dict): SafeHtml {
  return html`<button class="ms login__eye" type="button" data-pass-toggle="${id}" aria-pressed="false"
                data-label-show="${String(t.show_pass ?? 'Mostrar senha')}"
                data-label-hide="${String(t.hide_pass ?? 'Ocultar senha')}"
                aria-label="${String(t.show_pass ?? 'Mostrar senha')}">visibility_off</button>`;
}

export function login(s: Scope): SafeHtml {
  const t: Dict = s.data.auth?.login ?? {};
  const tips: Dict = s.data.auth?.tips ?? {};
  const error = String(s.data.error ?? '').trim();
  const user = String(s.data.user ?? '');
  return html`<section class="panel login-card" data-boot data-stagger>

  ${dicas(tips)}

  <div class="login-card__main" data-stagger>
    <h1 class="page-title login__title">${String(t.title ?? 'ENTRAR NA CONTA')}</h1>
    <p class="login__sub">${String(t.subtitle ?? '')}</p>

    <form class="login__form" method="post" action="${url('login')}" novalidate>
      ${csrfField()}
${error !== '' ? html`      <p class="login__error" role="alert">${icon('warning')}<span>${error}</span></p>
` : ''}
      <div class="field field--icon login__field">
        <label class="field__label" for="login-user">${String(t.user_label ?? 'E-MAIL OU NOME DE USUÁRIO')}</label>
        <input class="input" id="login-user" type="text" name="user" value="${user}"
               placeholder="${String(t.user_ph ?? '')}" autocomplete="username"
               autocapitalize="none" spellcheck="false" maxlength="60" required>
        ${icon('person', 'fill login__field-icon')}
      </div>

      <div class="field field--icon login__field">
        <label class="field__label" for="login-pass">${String(t.pass_label ?? 'SENHA')}</label>
        <input class="input" id="login-pass" type="password" name="password"
               placeholder="${String(t.pass_ph ?? '')}" autocomplete="current-password" required>
        ${olho('login-pass', t)}
      </div>

      <a class="link login__forgot" href="${url('recuperar-senha')}">${String(t.forgot ?? 'Esqueceu sua senha?')}</a>

      <button class="btn btn--primary btn--lg btn--block login__submit" type="submit">
        ${icon('logout')}<span class="btn__label">${String(t.submit ?? 'ENTRAR')}</span>
      </button>
    </form>

    <p class="divider divider--text login__or">${String(t.or ?? 'OU')}</p>

    <button class="btn btn--outline btn--lg btn--block login__steam" type="button"
            data-steam data-tip="${String(t.steam_tip ?? '')}">
      ${svg('steam', 'login__steam-glyph')}<span class="btn__label">${String(t.steam ?? 'ENTRAR COM STEAM')}</span>
    </button>

    <p class="login__signup">
      ${String(t.no_account ?? 'Não tem uma conta?')}
      <a class="link login__signup-link" href="${url('criar-conta')}">${String(t.create ?? 'CRIAR CONTA')}</a>
    </p>
  </div>

</section>
`;
}

export function criarConta(s: Scope): SafeHtml {
  const t: Dict = s.data.auth?.register ?? {};
  const tips: Dict = s.data.auth?.tips ?? {};
  const errors: Record<string, string> = s.data.errors ?? {};
  const v: Dict = s.data.values ?? {};
  const str: Dict = t.strength ?? {};
  const levels = ((str.levels ?? []) as unknown[]).filter((x): x is string => typeof x === 'string');
  const err = (k: string) => (typeof errors[k] === 'string' ? errors[k]! : '');
  const erroCampo = (k: string, id: string) =>
    err(k) !== '' ? html`<small class="field__error" id="${id}">${icon('warning')}<span>${err(k)}</span></small>` : '';
  const aria = (k: string, id: string, alt = '') =>
    raw(err(k) !== '' ? `aria-describedby="${id}" aria-invalid="true"` : alt);

  return html`<div class="auth-scope page-login" data-boot>
<section class="panel login-card register-card" data-stagger>

  ${dicas(tips)}

  <div class="login-card__main register-card__main" data-stagger>
    <h1 class="page-title login__title">${String(t.title ?? 'CRIAR CONTA')}</h1>
    <p class="login__sub">${String(t.subtitle ?? '')}</p>

    <form class="login__form register__form" method="post" action="${url('criar-conta')}" novalidate data-stagger>
      ${csrfField()}
${Object.keys(errors).length ? html`      <p class="login__error" role="alert">${icon('warning')}<span>${err('form') !== '' ? err('form') : String(t.error_title ?? 'Revise os campos destacados para continuar.')}</span></p>
` : ''}
      <div class="field field--icon login__field register__field${err('user') !== '' ? ' is-invalid' : ''}">
        <label class="field__label" for="reg-user">${String(t.user_label ?? 'NOME DE USUÁRIO')}</label>
        <input class="input" id="reg-user" type="text" name="user" value="${String(v.user ?? '')}"
               placeholder="${String(t.user_ph ?? '')}" autocomplete="username"
               autocapitalize="none" spellcheck="false" maxlength="24" required
               pattern="[A-Za-z0-9_]{3,24}"
               ${aria('user', 'reg-user-err', 'aria-describedby="reg-user-hint"')}>
        ${icon('person', 'fill login__field-icon')}
        ${err('user') !== '' ? erroCampo('user', 'reg-user-err') : html`<small class="field__hint register__hint" id="reg-user-hint">${String(t.user_hint ?? '')}</small>`}
      </div>

      <div class="field field--icon login__field register__field${err('email') !== '' ? ' is-invalid' : ''}">
        <label class="field__label" for="reg-email">${String(t.email_label ?? 'E-MAIL')}</label>
        <input class="input" id="reg-email" type="email" name="email" value="${String(v.email ?? '')}"
               placeholder="${String(t.email_ph ?? '')}" autocomplete="email"
               autocapitalize="none" spellcheck="false" maxlength="120" required
               ${aria('email', 'reg-email-err')}>
        ${icon('mail', 'login__field-icon')}
        ${erroCampo('email', 'reg-email-err')}
      </div>

      <div class="register__pair">
        <div class="field field--icon login__field register__field${err('password') !== '' ? ' is-invalid' : ''}">
          <label class="field__label" for="reg-pass">${String(t.pass_label ?? 'SENHA')}</label>
          <input class="input" id="reg-pass" type="password" name="password"
                 placeholder="${String(t.pass_ph ?? '')}" autocomplete="new-password"
                 minlength="8" required data-strength-input
                 ${aria('password', 'reg-pass-err')}>
          ${olho('reg-pass', t)}
          ${erroCampo('password', 'reg-pass-err')}
        </div>

        <div class="field field--icon login__field register__field${err('password_confirm') !== '' ? ' is-invalid' : ''}">
          <label class="field__label" for="reg-pass2">${String(t.confirm_label ?? 'CONFIRMAR SENHA')}</label>
          <input class="input" id="reg-pass2" type="password" name="password_confirm"
                 placeholder="${String(t.confirm_ph ?? '')}" autocomplete="new-password"
                 minlength="8" required data-strength-match
                 ${aria('password_confirm', 'reg-pass2-err')}>
          ${olho('reg-pass2', t)}
          ${erroCampo('password_confirm', 'reg-pass2-err')}
        </div>
      </div>

      <div class="register__meter" data-strength-meter>
        <span class="register__meter-label">${String(str.label ?? 'FORÇA')}</span>
        <span class="register__meter-bars" aria-hidden="true">
          <i class="register__meter-seg"></i><i class="register__meter-seg"></i><i class="register__meter-seg"></i><i class="register__meter-seg"></i>
        </span>
        <span class="register__meter-value num" role="status" aria-live="polite"
              data-levels="${(levels.length ? levels : ['—', 'FRACA', 'RAZOÁVEL', 'BOA', 'FORTE']).join('|')}">${levels[0] ?? '—'}</span>
      </div>

      <label class="toggle toggle--label-right register__terms${err('terms') !== '' ? ' is-invalid' : ''}">
        <input type="checkbox" name="terms" value="1" required${v.terms ? raw(' checked') : ''}
               ${aria('terms', 'reg-terms-err')}>
        <span class="toggle__track"></span>
        <span class="register__terms-text">${String(t.terms ?? '')}</span>
      </label>
${err('terms') !== '' ? html`      <small class="field__error register__terms-err" id="reg-terms-err">${icon('warning')}<span>${err('terms')}</span></small>
` : ''}
      <button class="btn btn--primary btn--lg btn--block login__submit register__submit" type="submit">
        ${icon('person_add')}<span class="btn__label">${String(t.submit ?? 'CRIAR CONTA')}</span>
      </button>
    </form>

    <p class="login__signup register__signin">
      ${String(t.has_account ?? 'Já tem uma conta?')}
      <a class="link login__signup-link" href="${url('login')}">${String(t.login ?? 'ENTRAR')}</a>
    </p>
  </div>

</section>
</div>
`;
}

export function erro(s: Scope): SafeHtml {
  const d = s.data;
  const code = Number(d.code ?? 404);
  const labels: Dict = d.labels ?? {};
  const accent = ['lime', 'cyan', 'amber', 'red'].includes(String(d.accent)) ? String(d.accent) : 'lime';
  const path = String(d.path ?? '');
  const statusL = String(d.status ?? '');
  const detail = typeof d.detail === 'string' ? d.detail : '';
  const hint = String(d.hint ?? '');
  const links = (d.links ?? []) as Dict[];
  return html`<section class="panel error-panel err" data-boot data-accent="${accent}" aria-labelledby="error-title">

  <span class="err__wm" aria-hidden="true">${svg('emblem')}</span>
  <span class="err__scan" aria-hidden="true"></span>

  <header class="panel__head err__head">
    <span class="err__badge">
      <span class="err__led" aria-hidden="true"></span>
      <span class="err__badge-lbl">ERRO</span>
      <span class="err__badge-num num">${code}</span>
    </span>
${statusL !== '' ? html`    <span class="err__status">${statusL}</span>
` : ''}    <span class="err__head-right">
      <span class="err__meter" aria-hidden="true">
        <i></i><i></i><i></i><i></i>
      </span>
      <span class="err__meter-lbl uc">${String(labels.meter ?? 'SINAL')} <span class="num">${String(d.meter ?? '0%')}</span></span>
      ${svg('emblem-hex', 'err__emblem')}
    </span>
  </header>

  <div class="panel__body err__body">

    <div class="err__glyph">
      <p class="err__code num" aria-hidden="true" data-text="${code}" data-glitch>${code}</p>
      <span class="err__flatline" aria-hidden="true"><i></i></span>
    </div>

    <div class="err__main" data-stagger>
      <p class="err__kicker">${String(d.kicker ?? 'DIAGNÓSTICO DE ROTA')}</p>
      <h1 class="page-title err__title" id="error-title">${String(d.title ?? 'SINAL PERDIDO')}</h1>
      <p class="err__msg">${String(d.message ?? '')}</p>

      <dl class="err__meta">
${path !== '' ? html`        <div class="err__meta-row">
          <dt>${String(labels.path ?? 'COORDENADA')}</dt>
          <dd><code class="err__path num">${path}</code></dd>
        </div>
` : ''}        <div class="err__meta-row">
          <dt>${String(labels.signal ?? 'TEMPO SEM SINAL')}</dt>
          <dd><span class="num err__clock" data-signal-clock>00:00</span></dd>
        </div>
        <div class="err__meta-row">
          <dt>${String(labels.node ?? 'NÓ DE OPERAÇÃO')}</dt>
          <dd><span class="err__node">${String(s.server.region ?? 'AMÉRICA DO SUL')}</span> <span class="num err__ping">${Number(s.server.ping_ms ?? 0)} ms</span></dd>
        </div>
      </dl>

${detail !== '' ? html`      <p class="err__detail-lbl uc">${String(labels.detail ?? 'REGISTRO TÉCNICO')}</p>
      <pre class="err__detail scroll-y">${detail}</pre>
` : ''}
      <nav class="err__actions" aria-label="Ações">
${join(links.map((l, i) => html`        <a class="btn ${i === 0 ? 'btn--primary btn--lg' : 'btn--outline'} err__btn"
           href="${String(l.href ?? url('inicio'))}">
          ${icon(String(l.icon ?? 'chevron_right'))}<span class="btn__label">${String(l.label ?? '')}</span>
        </a>
`))}      </nav>

${hint !== '' ? html`      <p class="err__hint">${hint}</p>
` : ''}    </div>

  </div>
</section>
`;
}
