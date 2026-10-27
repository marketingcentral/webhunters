/**
 * Tela SUPORTE (app/Views/pages/suporte.php). Âncora de estilo: 04-settings.
 *
 * Colunas: [FAQ + canais] | [abertura de chamado] | [status dos servidores].
 * data: title, tag, intro, categories, form, ticket_meta, success, faq, contacts,
 *       status, maintenance, tickets, errors, values.
 */
import { csrfField, icon, num, url } from '../../core/helpers';
import { html, join, raw, type SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';

const obj = (v: unknown): Dict => (v && typeof v === 'object' ? (v as Dict) : {});
const lista = (v: unknown): Dict[] => (Array.isArray(v) ? (v as Dict[]) : v && typeof v === 'object' ? (Object.values(v) as Dict[]) : []);
/** mb_strlen */
const mbLen = (s: string): number => Array.from(s).length;

function faqItem(item: Dict, i: number): SafeHtml {
  return html`          <article class="faq-item${i === 0 ? ' is-open' : ''}">
            <button class="faq-item__q" type="button" aria-expanded="${i === 0 ? 'true' : 'false'}" aria-controls="faq-${String(i)}">
              <span class="faq-item__idx num">${String(i + 1).padStart(2, '0')}</span>
              <span class="faq-item__text">${String(item.q)}</span>
              <span class="ms faq-item__chev" aria-hidden="true">expand_more</span>
            </button>
            <div class="faq-item__a" id="faq-${String(i)}" role="region">
              <p class="faq-item__p">${String(item.a)}</p>
            </div>
          </article>
`;
}

function canal(c: Dict): SafeHtml {
  const tag = c.href ? 'a' : 'div';
  return html`        <${raw(tag)} class="chan"${c.href ? html` href="${url(String(c.href))}"` : ''}>
          <span class="chan__ic">${icon(String(c.icon ?? 'headset_mic'))}</span>
          <span class="chan__text">
            <strong class="chan__label">${String(c.label)}</strong>
            <small class="chan__value">${String(c.value)}</small>
          </span>
          <span class="chan__meta chan__meta--${String(c.tone ?? 'muted')}">${String(c.meta ?? '')}</span>
        </${raw(tag)}>
`;
}

export function suporte(s: Scope): SafeHtml {
  const d = s.data;
  const f = obj(d.form);
  const errors = obj(d.errors);
  const values = obj(d.values);
  const cats = lista(d.categories);
  const faq = lista(d.faq);
  const status = obj(d.status);
  const maint = obj(d.maintenance);
  const success = obj(d.success);
  const sent = Boolean(s.flash.ok);

  const labels = cats.map((c) => String(c.label));
  const slugs = cats.map((c) => String(c.slug));
  const slas = cats.map((c) => String(c.sla ?? ''));

  let idx = slugs.indexOf(String(values.category ?? ''));
  if (idx < 0) idx = 0;
  const curLabel = labels[idx] ?? '';
  const curSlug = slugs[idx] ?? '';
  const curSla = slas[idx] ?? '';

  const msgVal = String(values.message ?? '');
  const msgMax = Number(f.message_max ?? 2000) | 0;
  const prios = (Array.isArray(f.priorities) ? f.priorities : ['NORMAL']).map((p: unknown) => String(p));

  // prioridade: mantém a escolha do jogador quando o POST volta com erro
  let curPrio = String(values.priority ?? '');
  if (!prios.includes(curPrio)) curPrio = String(f.priority ?? prios[0] ?? 'NORMAL');
  let prioIdx = prios.indexOf(curPrio);
  if (prioIdx < 0) prioIdx = 0;
  const logsOn = !('logs' in values) || Boolean(values.logs);
  const tickets = lista(d.tickets);
  const sucessoSec = obj(success.secondary);
  const sucessoPri = obj(success.primary);

  const feito = html`      <div class="panel__body sup-done">
        <span class="sup-done__ring" aria-hidden="true">${icon('check_circle', 'fill')}</span>
        <h3 class="sup-done__title">${String(success.title ?? 'CHAMADO REGISTRADO')}</h3>
        <p class="sup-done__msg">${String(s.flash.ok ?? '')}</p>
        <p class="sup-done__desc">${String(success.desc ?? '')}</p>
        <ul class="sup-done__steps">
${join(lista(success.steps).map((st) => html`          <li class="sup-done__step">${icon('check', 'sup-done__check')}<span>${String(st)}</span></li>
`))}        </ul>
        <div class="sup-done__actions">
          <a class="btn btn--outline" href="${url(String(sucessoSec.href ?? 'suporte'))}">${icon('add')}${String(sucessoSec.label ?? 'ABRIR OUTRO CHAMADO')}</a>
          <a class="btn btn--primary" href="${url(String(sucessoPri.href ?? 'mensagens'))}">${icon('mail')}${String(sucessoPri.label ?? 'IR PARA MENSAGENS')}${icon('chevron_right', 'btn__chev')}</a>
        </div>
      </div>
`;

  const erroAssunto = errors.category || errors.subject;
  const formulario = html`      <form class="panel__body sup-form" method="post" action="${url('suporte')}" novalidate>
        ${csrfField()}

        <div class="sup-meta">
          <span class="sup-meta__item">
            <span class="sup-meta__label">OPERADOR</span>
            <span class="sup-meta__val">${String(s.player.name ?? 'HUNTER')}</span>
          </span>
${join(lista(d.ticket_meta).map((m) => html`          <span class="sup-meta__item">
            <span class="sup-meta__label">${String(m.label)}</span>
            <span class="sup-meta__val num">${String(m.value)}</span>
          </span>
`))}        </div>

        <div class="sup-field">
          <span class="sup-field__label">${String(f.subject_label ?? 'ASSUNTO')}</span>
          <div class="stepper sup-stepper" data-options="${labels.join('|')}" data-value="${curLabel}"
               data-slugs="${slugs.join('|')}" data-slas="${slas.join('|')}">
            <button class="stepper__btn ms" type="button" aria-label="Assunto anterior">chevron_left</button>
            <span class="stepper__val">${curLabel}</span>
            <button class="stepper__btn ms" type="button" aria-label="Próximo assunto">chevron_right</button>
            <input type="hidden" name="subject" value="${curLabel}">
          </div>
          <span class="sup-field__aside">
            <span class="sup-field__aside-label">${String(f.sla_label ?? 'RESPOSTA ESTIMADA')}</span>
            <span class="sup-field__aside-val num" data-sla>${curSla}</span>
          </span>
          <input type="hidden" name="category" value="${curSlug}" data-category>
${erroAssunto ? html`          <small class="field__error">${icon('warning')}${String(errors.category ?? errors.subject)}</small>
` : ''}        </div>

        <div class="sup-field">
          <span class="sup-field__label">${String(f.priority_label ?? 'PRIORIDADE')}</span>
          <div class="stepper sup-stepper" data-options="${prios.join('|')}" data-value="${curPrio}">
            <button class="stepper__btn ms" type="button" aria-label="Prioridade anterior">chevron_left</button>
            <span class="stepper__val">${curPrio}</span>
            <button class="stepper__btn ms" type="button" aria-label="Próxima prioridade">chevron_right</button>
            <input type="hidden" name="priority" value="${curPrio}">
          </div>
          <span class="sup-prio" data-prio-level="${String(prioIdx + 1)}" aria-hidden="true">
            ${join(prios.map(() => raw('<span class="sup-prio__seg"></span>')))}
          </span>
        </div>

        <div class="sup-field sup-field--area${errors.message ? ' is-invalid' : ''}">
          <span class="sup-field__label">${String(f.message_label ?? 'DESCRIÇÃO')}</span>
          <span class="sup-field__count num"><span data-count-out>${num(mbLen(msgVal))}</span> / ${num(msgMax)}</span>
          <textarea class="input sup-textarea" name="message" maxlength="${String(msgMax)}" data-counter
                    placeholder="${String(f.message_ph ?? '')}" aria-label="${String(f.message_label ?? 'Descrição')}">${msgVal}</textarea>
${errors.message
    ? html`          <small class="field__error">${icon('warning')}${String(errors.message)}</small>
`
    : html`          <small class="field__hint">${String(f.message_hint ?? '')}</small>
`}        </div>

        <div class="sup-related">
          <span class="sup-related__label">${String(d.related_title ?? 'ARTIGOS RELACIONADOS')}</span>
${join(lista(d.related).map((rel) => html`          <a class="sup-related__chip" href="${url(String(rel.href))}">${icon('article', 'sup-related__ic')}${String(rel.label)}</a>
`))}        </div>

        <label class="sup-attach">
          <input type="checkbox" name="logs" value="1"${logsOn ? raw(' checked') : ''}>
          <span class="sup-attach__box" aria-hidden="true">${icon('check')}</span>
          <span class="sup-attach__text">${String(f.attach_label ?? '')}</span>
          <span class="sup-attach__file num">MATCH_LOG_4412.TXT</span>
        </label>

        <div class="sup-form__actions">
          <a class="btn btn--outline sup-form__bug" href="${url('relatorio-de-bug')}">${icon('bug_report')}${String(f.bug_label ?? 'RELATÓRIO DE BUG')}</a>
          <button class="btn btn--primary sup-form__send" type="submit">${icon('send', 'fill')}<span class="sup-form__send-label">${String(f.submit ?? 'ENVIAR')}</span></button>
        </div>
      </form>
`;

  const regioes = join(lista(status.regions).map((r) => html`          <li class="srv__row">
            <span class="dot srv__dot" aria-hidden="true"></span>
            <span class="srv__text">
              <strong class="srv__name">${String(r.label)}</strong>
              <small class="srv__sub"><span class="num">${String(r.players ?? '')}</span> operadores</small>
            </span>
            <span class="srv__ping num" data-ping="${String(Number(r.ping) | 0)}"><span data-ping-out>${String(Number(r.ping) | 0)}</span><small>ms</small></span>
          </li>
`));

  const servicos = join(lista(status.services).map((sv) => {
    const warn = String(sv.state ?? 'ok') !== 'ok';
    return html`          <li class="svc__tile${warn ? ' is-warn' : ''}">
            <span class="svc__head">
              <span class="dot svc__dot${warn ? ' dot--amber' : ''}" aria-hidden="true"></span>
              <span class="svc__name">${String(sv.label)}</span>
            </span>
            <span class="svc__state">${String(sv.short ?? sv.value)}</span>
          </li>
`;
  }));

  const manutencao = Object.keys(maint).length
    ? html`    <section class="panel sup-maint">
      <div class="panel__body sup-maint__body">
        <span class="sup-maint__ic">${icon('build')}</span>
        <div class="sup-maint__text">
          <strong class="sup-maint__title">${String(maint.title ?? 'MANUTENÇÃO PROGRAMADA')}</strong>
          <small class="sup-maint__desc">${String(maint.service ?? '')} — ${String(maint.desc ?? '')}</small>
        </div>
        <span class="sup-maint__timer">
          <span class="sup-maint__label">${String(maint.label ?? 'INÍCIO EM')}</span>
          <span class="num sup-maint__val" data-countdown="${String(Number(maint.starts_in ?? 0) | 0)}">--:--:--</span>
        </span>
      </div>
    </section>
`
    : '';

  const chamados = join(tickets.map((t) => html`        <a class="tkt" href="${url('mensagens')}">
          <span class="tkt__id num">${String(t.id)}</span>
          <span class="tkt__subject">${String(t.subject)}</span>
          <span class="tkt__date num">${String(t.date)}</span>
          <span class="tkt__state tkt__state--${String(t.tone ?? 'muted')}">${String(t.state)}</span>
        </a>
`));

  return html`<div class="sup">

  <!-- ====================== CABEÇALHOS DE COLUNA ====================== -->
  <header class="sup-head" data-boot>
    <h1 class="page-title">${String(d.title ?? 'SUPORTE')}<small>${String(d.tag ?? '')}</small></h1>
    <p class="page-subtitle">${String(d.intro ?? '')}</p>
  </header>

  <h2 class="sup-col-title" data-boot>${String(f.panel_title ?? 'ABRIR CHAMADO')}</h2>

  <p class="sup-live" data-boot>
    <span class="dot sup-live__dot" aria-hidden="true"></span>
    <span class="sup-live__label">${String(status.updated ?? 'ATUALIZADO HÁ')}</span>
    <span class="num sup-live__val" data-elapsed>00:00</span>
  </p>

  <!-- ========================= COLUNA ESQUERDA ======================== -->
  <div class="sup__left">

    <section class="panel sup-faq">
      <header class="panel__head">
        <h2 class="panel__title">${String(d.faq_title ?? 'PERGUNTAS FREQUENTES')}</h2>
        <span class="panel__meta num">${num(faq.length)}</span>
      </header>
      <div class="panel__body sup-faq__body">
        <div class="faq" data-accordion data-stagger>
${join(faq.map(faqItem))}        </div>
      </div>
      <footer class="panel__foot sup-faq__foot">
        <span class="sup-faq__note">${String(d.faq_foot ?? '')}</span>
        <a class="link sup-faq__link" href="${url('relatorio-de-bug')}">${String(f.bug_label ?? 'RELATÓRIO DE BUG')}${icon('chevron_right')}</a>
      </footer>
    </section>

    <section class="panel sup-channels">
      <header class="panel__head">
        <h2 class="panel__title">${String(d.contacts_title ?? 'CANAIS DE ATENDIMENTO')}</h2>
      </header>
      <div class="panel__body sup-channels__body" data-stagger>
${join(lista(d.contacts).map(canal))}      </div>
    </section>

  </div>

  <!-- ========================== COLUNA CENTRAL ======================== -->
  <div class="sup__mid">
    <section class="panel sup-ticket">

${sent ? feito : formulario}
    </section>
  </div>

  <!-- ========================== COLUNA DIREITA ======================== -->
  <aside class="sup__right">

    <section class="panel sup-status">
      <header class="panel__head">
        <h2 class="panel__title">${String(status.label ?? 'STATUS DOS SERVIDORES')}</h2>
        <span class="chip chip--${status.ok ? 'lime' : 'amber'} chip--dot sup-status__badge">${String(status.badge ?? 'OPERACIONAL')}</span>
      </header>
      <div class="panel__body sup-status__body">
        <ul class="srv" data-stagger>
${regioes}        </ul>

        <h3 class="sup-status__section">${String(status.services_title ?? 'SERVIÇOS')}</h3>
        <ul class="svc">
${servicos}        </ul>
      </div>
    </section>

${manutencao}
    <section class="panel sup-tickets">
      <header class="panel__head">
        <h2 class="panel__title">${String(d.tickets_title ?? 'MEUS CHAMADOS')}</h2>
        <a class="panel__action" href="${url('mensagens')}">VER TODOS${icon('chevron_right')}</a>
      </header>
      <div class="panel__body sup-tickets__body" data-stagger>
${chamados}${tickets.length === 0 ? html`        <p class="sup-tickets__empty">${icon('inbox')}<span>Você ainda não abriu nenhum chamado.</span></p>
` : ''}      </div>
    </section>

  </aside>
</div>
`;
}
