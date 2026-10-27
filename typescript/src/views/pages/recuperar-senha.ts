/**
 * Tela RECUPERAR SENHA (app/Views/pages/recuperar-senha.php). Layout `auth`.
 * data: auth (auth.json + recuperar-senha.json), sent, error, email.
 *
 * Mesmo cartão do login sobre bg/login.jpg:
 *   coluna esquerda -> logo (variante auth) + painel "DICAS DE SOBREVIVÊNCIA"
 *   coluna direita  -> e-mail + ENVIAR LINK + "COMO FUNCIONA" (3 passos) + volta para ENTRAR
 */
import { csrfField, icon, url } from '../../core/helpers';
import { html, join, raw, type SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { hex, logo } from '../components';

/** Destino mascarado: h*****r@dominio.com (nunca ecoa o endereço inteiro na tela enviada). */
function mascarar(email: string): string {
  const i = email.indexOf('@');
  const user = Array.from(email.slice(0, i));
  const host = email.slice(i + 1);
  const len = user.length;
  return (len > 2 ? user[0] + '*'.repeat(Math.min(6, len - 2)) + user[len - 1] : '*'.repeat(len)) + '@' + host;
}

export function recuperarSenha(s: Scope): SafeHtml {
  const rec = s.data.auth?.recover;
  const t: Dict = rec && typeof rec === 'object' && !Array.isArray(rec) ? rec : {};
  const tp = s.data.auth?.tips;
  const tips: Dict = tp && typeof tp === 'object' && !Array.isArray(tp) ? tp : {};
  const items = (Array.isArray(tips.items) ? tips.items : []).filter((v: unknown): v is string => typeof v === 'string' && v !== '');

  const steps: Array<{ title: string; text: string }> = [];
  for (const st of Array.isArray(t.steps) ? (t.steps as unknown[]) : []) {
    if (st && typeof st === 'object' && 'title' in st && (st as Dict).title !== null) {
      steps.push({ title: String((st as Dict).title), text: String((st as Dict).text ?? '') });
    }
  }

  const sent = Boolean(s.data.sent);
  const error = String(s.data.error ?? '').trim();
  const email = String(s.data.email ?? '');
  const secs = Math.max(10, Math.min(900, Number(t.resend_secs ?? 60) | 0));
  const masked = sent && email !== '' && email.includes('@') ? mascarar(email) : '';
  // passo aceso na faixa "COMO FUNCIONA": 1 antes do envio, 2 depois
  const current = sent ? 1 : 0;

  const enviado = html`    <div class="recover__done" role="status">
      <p class="recover__done-head">
        <span class="recover__done-icon" aria-hidden="true">${icon('check_circle', 'fill')}</span>
        <span class="recover__done-title">${String(t.sent_title ?? 'LINK ENVIADO')}</span>
      </p>
      <p class="recover__done-text">${String(t.sent ?? '')}</p>
${masked !== '' ? html`      <p class="recover__done-to">
        <span class="recover__done-label">${String(t.sent_to ?? 'DESTINO')}</span>
        <span class="recover__done-mail num">${masked}</span>
      </p>
` : ''}    </div>

    <form class="recover__form recover__form--resend" method="post" action="${url('recuperar-senha')}" novalidate>
      ${csrfField()}
      <input type="hidden" name="email" value="${email}">
      <button class="btn btn--primary btn--lg btn--block recover__submit" type="submit"
              data-resend="${String(secs)}"
              data-label-wait="${String(t.resend_wait ?? 'REENVIAR EM')}"
              data-label-ready="${String(t.resend ?? 'REENVIAR LINK')}" disabled>
        ${icon('schedule')}<span class="btn__label">${String(t.resend_wait ?? 'REENVIAR EM')} <span class="num recover__clock">00:${String(secs).padStart(2, '0')}</span></span>
      </button>
    </form>
`;

  const formulario = html`    <form class="recover__form" method="post" action="${url('recuperar-senha')}" novalidate>
      ${csrfField()}

      <div class="field field--icon recover__field${error !== '' ? ' is-invalid' : ''}">
        <label class="field__label" for="recover-email">${String(t.email_label ?? 'E-MAIL DA CONTA')}</label>
        <input class="input" id="recover-email" type="email" name="email" value="${email}"
               placeholder="${String(t.email_ph ?? '')}" autocomplete="email" inputmode="email"
               autocapitalize="none" spellcheck="false" maxlength="120" required
               ${error !== '' ? raw('aria-invalid="true" aria-describedby="recover-erro"') : ''}>
        ${icon('mail', 'recover__field-icon')}
${error !== '' ? html`        <span class="field__error recover__error" id="recover-erro" role="alert">${icon('warning')}<span>${error}</span></span>
` : ''}      </div>

      <button class="btn btn--primary btn--lg btn--block recover__submit" type="submit">
        ${icon('send')}<span class="btn__label">${String(t.submit ?? 'ENVIAR LINK')}</span>
      </button>
    </form>
`;

  const passos = steps.length
    ? html`    <ol class="recover-steps">
${join(steps.map((step, i) => html`      <li class="recover-step${i === current ? ' is-current' : ''}${i < current ? ' is-done' : ''}">
        ${hex({ size: 'sm', color: 'lime', text: i + 1, flat: true, class: 'recover-step__hex' })}
        <span class="recover-step__text">
          <strong>${step.title}</strong>
          <small>${step.text}</small>
        </span>
      </li>
`))}    </ol>
`
    : '';

  return html`<section class="panel recover-card" data-boot data-stagger>

  <aside class="recover-card__aside" data-stagger>
    ${logo({ variant: 'auth' })}
    <div class="recover-tips" data-tip-rotator data-interval="8000">
      <p class="recover-tips__head">
        <span class="recover-tips__icon" aria-hidden="true">${icon('shield')}${icon('star', 'fill recover-tips__star')}</span>
        <span class="recover-tips__title">${String(tips.title ?? 'DICAS DE SOBREVIVÊNCIA')}</span>
      </p>
      <div class="recover-tips__stack" aria-live="off">
${join(items.map((tip: string, i: number) => html`        <p class="recover-tips__text${i === 0 ? ' is-current' : ''}"${i === 0 ? '' : raw(' aria-hidden="true"')}>${tip}</p>
`))}      </div>
    </div>
  </aside>

  <div class="recover-card__main${sent ? ' recover-card__main--sent' : ''}${error !== '' ? ' recover-card__main--error' : ''}" data-stagger>
    <h1 class="page-title recover__title">${String(t.title ?? 'RECUPERAR SENHA')}</h1>
    <p class="recover__sub">${String(t.subtitle ?? '')}</p>

${sent ? enviado : formulario}
    <p class="divider divider--text recover__steps-title">${String(t.steps_title ?? 'COMO FUNCIONA')}</p>

${passos}
    <p class="recover__note">
      ${icon('lock', 'recover__note-icon')}
      <span>${String(t.note ?? '')}        <a class="link recover__note-link" href="${url('suporte')}">${String(t.note_link ?? 'SUPORTE')}</a>.</span>
    </p>

    <p class="recover__back">
      ${String(t.remember ?? 'Lembrou sua senha?')}      <a class="link recover__back-link" href="${url('login')}">${String(t.back ?? 'ENTRAR')}</a>
    </p>
  </div>

</section>
`;
}
