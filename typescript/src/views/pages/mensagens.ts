/**
 * Tela MENSAGENS — app/Views/pages/mensagens.php (âncora de estilo 03-lobby).
 * Dados do banco por jogador (MessageRepo, conciliado por user_id).
 *
 * $data: title, subtitle, unread, folders[], active_folder, search_ph, list_title, compose,
 *        compose_href, empty_text, items[] (com thread[]), open, thread_actions[], reply_ph,
 *        reply_send, system_panel{}, maintenance{}.
 */
import { csrfField, icon, media, num, url } from '../../core/helpers';
import { html, join, raw, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { chip } from '../components/index';

export function mensagens(s: Scope): SafeHtml {
  const d = s.data;
  const items = (d.items ?? []) as Dict[];
  const folders = (d.folders ?? []) as Dict[];
  const sys: Dict = d.system_panel ?? {};
  const sysItems = (sys.items ?? []) as Dict[];
  const maint: Dict = d.maintenance ?? {};
  const sysEmpty: Dict = sys.empty ?? {};
  const noThread: Dict = d.empty_thread ?? {};
  const me = String(s.player.name ?? 'HUNTER_07');
  let activeId = String(d.open ?? '');
  if (activeId === '') {
    const ativo = items.find((it) => it.active);
    if (ativo) activeId = String(ativo.id);
  }
  if (activeId === '' && items.length > 0) activeId = String(items[0]!.id);
  const unread = Number(d.unread ?? 0) | 0;

  // contagem já correta no HTML (o JS só recalcula depois de filtrar/buscar)
  const actFolder = String(d.active_folder ?? 'entrada');
  const inFolder = items.filter((i) => (actFolder === 'sistema' ? Boolean(i.system) : String(i.folder ?? 'entrada') === actFolder));
  const folderNew = inFolder.filter((i) => i.unread).length;

  const avatar = (it: Dict, imgCls: string, icCls: string): SafeHtml =>
    it.avatar
      ? html`<img class="${imgCls}" src="${media(String(it.avatar))}" alt="" width="896" height="1200" loading="lazy" decoding="async">`
      : icon(String(it.icon ?? 'mail'), icCls);

  const convItem = (it: Dict): SafeHtml => {
    const id = String(it.id);
    const on = id === activeId;
    return html`          <li class="conv-item" data-boot>
            <button class="conv${on ? ' is-active' : ''}${it.unread ? ' is-unread' : ''}${it.system ? ' conv--system' : ''}"
                    type="button"
                    data-conv="${id}"
                    data-folder="${String(it.folder ?? 'entrada')}"
                    data-system="${it.system ? '1' : '0'}"
                    data-search="${(String(it.from) + ' ' + String(it.subject) + ' ' + String(it.preview ?? '')).toLowerCase()}"
                    aria-pressed="${on ? 'true' : 'false'}">
              <span class="conv__avatar conv__avatar--${String(it.color ?? 'lime')}">
                ${avatar(it, 'conv__img', 'conv__ic')}
${it.level ? html`                <span class="conv__lvl num">${String(it.level)}</span>
` : ''}              </span>
              <span class="conv__body">
                <span class="conv__top">
                  <strong class="conv__from">${String(it.from)}</strong>
                  <span class="conv__time num">${String(it.time)}</span>
                </span>
                <span class="conv__subject">${String(it.subject)}</span>
                <span class="conv__preview">${String(it.preview ?? '')}</span>
              </span>
              <span class="conv__dot" aria-hidden="true"></span>
            </button>
          </li>
`;
  };

  const thread = (it: Dict): SafeHtml => {
    const id = String(it.id);
    const on = id === activeId;
    const linhas = (it.thread ?? []) as Dict[];
    return html`        <article class="thread" data-thread="${id}"${on ? '' : raw(' hidden')}>

          <header class="thread__head">
            <span class="thread__avatar thread__avatar--${String(it.color ?? 'lime')}">
              ${avatar(it, 'thread__img', 'thread__ic')}
            </span>
            <span class="thread__id">
              <strong class="thread__from">${String(it.from)}
                ${it.tag ? chip({ label: String(it.tag), color: 'gold', class: 'thread__tag' }) : ''}
              </strong>
              <span class="thread__meta">
                ${it.level ? html`<span class="thread__lvl num">NÍVEL ${String(it.level)}</span><span class="thread__sep" aria-hidden="true"></span>` : ''}
                ${icon(String(it.class_ms ?? 'shield'), 'thread__class-ic')}<span class="thread__class">${String(it.class ?? '')}</span>
                <span class="thread__sep" aria-hidden="true"></span>
                <span class="thread__status thread__status--${String(it.status_kind ?? 'offline')}">${String(it.status ?? '')}</span>
              </span>
            </span>
            <span class="thread__actions">
${join(
  ((d.thread_actions ?? []) as Dict[]).map(
    (a) => html`              <a class="thread__act" href="${url(String(a.href))}" aria-label="${String(a.label)}" data-tip="${String(a.label)}">${icon(String(a.icon))}</a>
`,
  ),
)}            </span>
          </header>

          <div class="thread__scroll scroll-y" data-thread-scroll>
            <p class="thread__subject"><span class="thread__subject-label">ASSUNTO</span><span class="thread__subject-text">${String(it.subject)}</span><span class="thread__subject-date num">${String(it.date ?? it.time)}</span></p>
            <ul class="chat">
${join(
  linhas.map(
    (m, k) => html`${m.sep ? html`              <li class="chat__sep"><span>${String(m.sep)}</span></li>
` : ''}              <li class="chat__msg${m.self || m.author === 'self' ? ' is-self' : ''}" style="--m:${String(k)}">
                <span class="chat__line">
                  <span class="chat__author chat__author--${String(m.color ?? 'lime')}">${m.author === 'self' ? me : String(m.author ?? '')}:</span>
                  <span class="chat__text">${String(m.text)}</span>
                </span>
                <span class="chat__time num">${String(m.time)}</span>
              </li>
`,
  ),
)}            </ul>
          </div>

        </article>
`;
  };

  const sa: Dict = sys.action ?? {};

  return html`<div class="msgs">

  <header class="msgs-head" data-boot>
    <div class="msgs-head__id">
      <h1 class="page-title">${String(d.title ?? 'MENSAGENS')}<small class="msgs-head__unread"><span class="num" data-count="${String(unread)}" data-dur="700">${num(unread)}</span> <span data-unread-label>${unread === 1 ? 'NÃO LIDA' : 'NÃO LIDAS'}</span></small></h1>
      <p class="page-subtitle">${String(d.subtitle ?? '')}</p>
    </div>
    <a class="compose-btn" href="${url(String(d.compose_href ?? 'amigos'))}">
      ${icon('edit_square', 'compose-btn__ic')}<span>${String(d.compose ?? 'NOVA MENSAGEM')}</span>
    </a>
  </header>

  <div class="msgs-grid">

    <!-- ====================== LISTA DE CONVERSAS ====================== -->
    <section class="panel conv-panel">
      <header class="panel__head">
        <h2 class="panel__title">${String(d.list_title ?? 'CONVERSAS')}</h2>
        <span class="panel__meta"><span class="num" data-conv-count>${num(inFolder.length)}</span> NA PASTA · <span class="num lime" data-conv-new>${num(folderNew)}</span> <span data-conv-new-label>${folderNew === 1 ? 'NOVA' : 'NOVAS'}</span></span>
      </header>
      <div class="panel__body conv-panel__body">

        <div class="conv-search">
          ${icon('search', 'conv-search__ic')}
          <input class="conv-search__input" type="search" placeholder="${String(d.search_ph ?? 'Buscar...')}" aria-label="${String(d.search_ph ?? 'Buscar')}" autocomplete="off" data-conv-search>
        </div>

        <nav class="tabs conv-tabs" data-conv-tabs aria-label="Pastas">
${join(
  folders.map((f) => {
    const fOn = String(f.slug ?? '') === String(d.active_folder ?? 'entrada');
    return html`          <button class="tab conv-tab${fOn ? ' is-active' : ''}" type="button" data-folder="${String(f.slug)}" aria-selected="${fOn ? 'true' : 'false'}">
            <span class="conv-tab__label">${String(f.label)}</span>
            <span class="conv-tab__count num">${num(Number(f.count) | 0)}</span>
          </button>
`;
  }),
)}        </nav>

        <ul class="conv-list scroll-y" data-conv-list data-stagger${inFolder.length === 0 ? raw(' hidden') : ''}>
${join(items.map(convItem))}        </ul>

        <p class="conv-empty" data-conv-empty
           data-empty-folder="${String(d.empty_text ?? 'Nenhuma conversa.')}"
           data-empty-search="${String(d.empty_search ?? 'Nenhuma conversa corresponde à busca.')}"${inFolder.length === 0 ? '' : raw(' hidden')}>
          ${icon('mark_email_unread', 'conv-empty__ic')}
          <span data-empty-text>${String(d.empty_text ?? 'Nenhuma conversa.')}</span>
        </p>

      </div>
    </section>

    <!-- ====================== CONVERSA ABERTA ====================== -->
    <section class="panel thread-panel panel--flush">
${items.length === 0
  ? html`      <div class="thread-empty" data-boot>
        <span class="thread-empty__ic">${icon(String(noThread.icon ?? 'forum'))}</span>
        <strong class="thread-empty__title">${String(noThread.title ?? 'NENHUMA CONVERSA AINDA')}</strong>
        <p class="thread-empty__text">${String(noThread.text ?? '')}</p>
        <a class="btn btn--outline btn--sm thread-empty__cta" href="${url(String(noThread.href ?? 'amigos'))}">${String(noThread.cta ?? 'PROCURAR OPERADORES')}${icon('chevron_right', 'btn__chev')}</a>
      </div>
`
  : html`      <div class="thread-stack">
${join(items.map(thread))}      </div>

      <form class="thread__form" method="post" action="${url('mensagens/responder')}" data-reply-form>
        ${csrfField()}
        <input type="hidden" name="conversa" value="${activeId}" data-reply-conv>
        <input class="thread__input" type="text" name="mensagem" maxlength="200" placeholder="${String(d.reply_ph ?? 'Escreva uma resposta...')}" aria-label="${String(d.reply_ph ?? 'Resposta')}" autocomplete="off" required data-reply-input>
        <button class="thread__send" type="submit" aria-label="${String(d.reply_send ?? 'Enviar resposta')}" data-reply-send>${icon('send', 'fill')}</button>
        <p class="thread__hint" data-reply-hint role="status" aria-live="polite" hidden>Digite uma mensagem antes de enviar.</p>
      </form>
`}    </section>

    <!-- ====================== SISTEMA ====================== -->
    <aside class="msgs-side">

      <section class="panel sys-panel">
        <header class="panel__head">
          <h2 class="panel__title">${String(sys.title ?? 'MENSAGENS DO SISTEMA')}</h2>
${Object.keys(sa).length > 0
  ? html`          <a class="panel__action" href="${url(String(sa.href))}">${String(sa.label)}${icon(String(sa.icon ?? 'chevron_right'))}</a>
`
  : ''}        </header>
        <div class="panel__body sys-panel__body">
${sysItems.length === 0
  ? html`          <div class="sys-empty" data-boot>
            <span class="sys-empty__ic">${icon(String(sysEmpty.icon ?? 'inbox'))}</span>
            <strong class="sys-empty__title">${String(sysEmpty.title ?? 'SEM AVISOS')}</strong>
            <p class="sys-empty__text">${String(sysEmpty.text ?? '')}</p>
            <a class="btn btn--outline btn--sm sys-empty__cta" href="${url(String(sysEmpty.href ?? 'noticias'))}">${String(sysEmpty.cta ?? 'VER NOTÍCIAS')}${icon('chevron_right', 'btn__chev')}</a>
          </div>
`
  : html`          <ul class="sys-list scroll-y scroll-y--nomask" data-stagger>
${join(
  sysItems.map(
    (n) => html`            <li class="sys${n.fresh ? ' is-fresh' : ''} sys--${String(n.color ?? 'lime')}" data-boot>
              <span class="sys__icon">${icon(String(n.icon))}</span>
              <span class="sys__body">
                <strong class="sys__title">${String(n.title)}</strong>
                <span class="sys__text">${String(n.text)}</span>
                <span class="sys__foot">
                  <span class="sys__time num">${String(n.time)}</span>
                  <a class="sys__cta" href="${url(String(n.href))}">${String(n.cta)}${icon('chevron_right', 'sys__chev')}</a>
                </span>
              </span>
            </li>
`,
  ),
)}          </ul>
`}        </div>
      </section>

      <section class="panel maint" data-boot>
        <header class="panel__head">
          <h2 class="panel__title">${icon('schedule')}${String(maint.title ?? 'PRÓXIMA MANUTENÇÃO')}</h2>
        </header>
        <div class="panel__body maint__body">
          <p class="maint__clock num" data-countdown="${String(Number(maint.seconds ?? 0) | 0)}" aria-live="off">--:--:--</p>
          <p class="maint__label">${String(maint.label ?? '')}</p>
          <p class="maint__note">${String(maint.note ?? '')}</p>
          <a class="btn btn--outline btn--sm maint__cta" href="${url(String(maint.href ?? 'noticias'))}">${String(maint.cta ?? 'LER AVISO')}${icon('chevron_right', 'btn__chev')}</a>
        </div>
      </section>

    </aside>
  </div>
</div>
`;
}
