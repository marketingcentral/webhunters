/**
 * Tela AMIGOS — app/Views/pages/amigos.php (âncoras de estilo: 03-lobby).
 *
 * $data: title, subtitle, online, total, tab, search_ph, search_label, tabs[],
 *        items_online[], items_offline[], pending[], status_labels{}, actions{},
 *        add{}, squad{}, empty{}
 * As listas chegam prontas do FriendsController (FriendsRepo, por user_id).
 */
import { csrfField, icon, media, num, svg, url } from '../../core/helpers';
import { html, join, raw, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { hex } from '../components/index';
import { tabs } from '../components/tabs';

export function amigos(s: Scope): SafeHtml {
  const d = s.data;
  const player = s.player;
  const online = (d.items_online ?? []) as Dict[];
  const offline = (d.items_offline ?? []) as Dict[];
  const pending = (d.pending ?? []) as Dict[];
  const tabList = (d.tabs ?? []) as Dict[];
  const labels: Dict = d.status_labels ?? {};
  const act: Dict = d.actions ?? {};
  const addBox: Dict = d.add ?? {};
  const squad: Dict = d.squad ?? {};
  const empty: Dict = d.empty ?? {};
  const active = String(d.tab ?? 'online');
  const squadItems = (squad.items ?? []) as Dict[];

  const tabItems = tabList.map((t) => {
    const slug = String(t.slug ?? '');
    return { label: String(t.label ?? ''), panel: slug, count: String(t.count ?? '0'), active: slug === active };
  });

  /** Linha de vazio de uma lista: mostra o texto da aba, ou o da busca quando o filtro está ativo. */
  const emptyRow = (slug: string, show: boolean): SafeHtml => html`    <li class="flist__empty" data-empty-list="${String(empty[slug] ?? '')}"
        data-empty-search="${String(empty.search ?? '')}"${show ? '' : raw(' hidden')}>
      ${String(empty[slug] ?? '')}
    </li>
`;

  /** Uma linha da lista de amigos. */
  const friendRow = (f: Dict, dim = false): SafeHtml => {
    const status = String(f.status ?? 'offline');
    return html`  <li class="frow frow--${status}${dim ? ' frow--dim' : ''}" data-boot data-name="${String(f.name).toLowerCase()}">
    <span class="frow__thumb">
      <img class="frow__portrait" src="${media(String(f.avatar))}" alt="" width="896" height="1200" loading="lazy" decoding="async">
      <span class="frow__thumb-fade" aria-hidden="true"></span>
    </span>
    ${hex({ size: 'sm', text: String(f.level), color: status === 'offline' ? 'muted' : 'lime', class: 'frow__hex', title: 'Nível ' + String(f.level) })}
    <span class="frow__id">
      <strong class="frow__name ellipsis">${String(f.name)}</strong>
      <span class="frow__class">
        ${svg(String(f.class_icon ?? 'class-assault'), 'frow__class-ic')}${String(f.class ?? '')}
      </span>
    </span>
    <span class="frow__state">
      <span class="frow__status"><i class="fdot" aria-hidden="true"></i>${String(labels[status] ?? '')}</span>
      <span class="frow__activity ellipsis">${String(f.activity ?? '')}</span>
    </span>
    <span class="frow__meta num">${String(f.meta ?? '')}</span>
    <span class="frow__actions">
${status !== 'offline'
  ? html`      <a class="fbtn fbtn--go" href="${url('lobby')}">${icon('person_add', 'fbtn__ic')}<span>${String(act.invite ?? 'CONVIDAR')}</span></a>
`
  : ''}      <a class="fbtn" href="${url('mensagens')}">${icon('mail', 'fbtn__ic')}<span>${String(act.message ?? 'MENSAGEM')}</span></a>
    </span>
  </li>
`;
  };

  const pendingRow = (p: Dict): SafeHtml => {
    const inn = String(p.direction ?? 'in') === 'in';
    return html`          <li class="frow frow--pending${inn ? ' frow--in' : ' frow--out'}" data-boot data-name="${String(p.name).toLowerCase()}">
            <span class="frow__thumb">
              <img class="frow__portrait" src="${media(String(p.avatar))}" alt="" width="896" height="1200" loading="lazy" decoding="async">
              <span class="frow__thumb-fade" aria-hidden="true"></span>
            </span>
            ${hex({ size: 'sm', text: String(p.level), color: 'muted', class: 'frow__hex', title: 'Nível ' + String(p.level) })}
            <span class="frow__id">
              <strong class="frow__name ellipsis">${String(p.name)}</strong>
              <span class="frow__class">${svg(String(p.class_icon ?? 'class-assault'), 'frow__class-ic')}${String(p.class ?? '')}</span>
            </span>
            <span class="frow__state">
              <span class="frow__status"><i class="fdot" aria-hidden="true"></i>${inn ? 'PEDIDO RECEBIDO' : 'CONVITE ENVIADO'}</span>
              <span class="frow__activity ellipsis">${String(p.activity ?? '')}</span>
            </span>
            <span class="frow__meta num">${String(p.meta ?? '')}</span>
            <form class="frow__actions" method="post" action="${url('amigos')}">
              ${csrfField()}
              <input type="hidden" name="jogador" value="${String(p.name)}">
${inn
  ? html`              <button class="fbtn fbtn--go" type="submit" name="acao" value="aceitar">${icon('check_circle', 'fbtn__ic')}<span>${String(act.accept ?? 'ACEITAR')}</span></button>
              <button class="fbtn fbtn--deny" type="submit" name="acao" value="recusar">${icon('close', 'fbtn__ic')}<span>${String(act.decline ?? 'RECUSAR')}</span></button>
`
  : html`              <button class="fbtn fbtn--deny" type="submit" name="acao" value="cancelar">${icon('undo', 'fbtn__ic')}<span>${String(act.cancel ?? 'CANCELAR')}</span></button>
`}            </form>
          </li>
`;
  };

  const unreadMail = Number(player.unread_mail ?? 0) | 0;

  return html`<div class="friends" data-tabs-scope>

  <!-- ====================== COLUNA ESQUERDA ====================== -->
  <section class="friends__main">

    <header class="friends-head">
      <div class="friends-head__id">
        <h1 class="page-title">${String(d.title ?? 'AMIGOS')}<small class="num">${num(Number(d.online ?? 0) | 0)}/${num(Number(d.total ?? 0) | 0)}</small></h1>
        <p class="page-subtitle">${String(d.subtitle ?? '')}</p>
      </div>
      <div class="friends-head__tools">
        <span class="fsearch">
          ${icon('search', 'fsearch__ic')}
          <input class="fsearch__input" type="search" id="friends-search" autocomplete="off"
                 placeholder="${String(d.search_ph ?? 'Buscar...')}"
                 aria-label="${String(d.search_label ?? 'Buscar amigo')}">
        </span>
        <a class="fbtn fbtn--tool" href="${url('mensagens')}">
          ${icon('mail', 'fbtn__ic')}<span>MENSAGENS</span>
          ${unreadMail > 0 ? html`<i class="fbtn__badge num">${String(unreadMail)}</i>` : ''}
        </a>
      </div>
    </header>

    ${tabs({ items: tabItems, class: 'friends-tabs', label: 'Filtros da lista de amigos' })}

    <section class="panel friends-panel">
      <div class="panel__body panel__body--tight friends-panel__body">

        <ul class="flist scroll-y" data-tab-panel="online" data-stagger${active === 'online' ? '' : raw(' hidden')}>
${join(online.map((f) => friendRow(f)))}${emptyRow('online', online.length === 0)}        </ul>

        <ul class="flist scroll-y" data-tab-panel="offline" data-stagger${active === 'offline' ? '' : raw(' hidden')}>
${join(offline.map((f) => friendRow(f, true)))}${emptyRow('offline', offline.length === 0)}        </ul>

        <ul class="flist scroll-y" data-tab-panel="pedidos" data-stagger${active === 'pedidos' ? '' : raw(' hidden')}>
${join(pending.map(pendingRow))}${emptyRow('pedidos', pending.length === 0)}        </ul>

      </div>
    </section>
  </section>

  <!-- ====================== COLUNA DIREITA ====================== -->
  <aside class="friends__side">

    <section class="panel panel--hot fadd" data-boot>
      <header class="panel__head">
        <h2 class="panel__title">${icon('person_add', 'panel__title-ic')}${String(addBox.title ?? 'ADICIONAR AMIGO')}</h2>
      </header>
      <div class="panel__body">
        <form class="form fadd__form" method="post" action="${url('amigos')}" autocomplete="off">
          ${csrfField()}
          <input type="hidden" name="acao" value="convidar">
          <div class="field field--icon">
            <label class="field__label" for="add-friend">${String(addBox.label ?? '')}</label>
            <input class="input" type="text" id="add-friend" name="jogador" maxlength="40"
                   placeholder="${String(addBox.placeholder ?? '')}" required>
            ${icon('badge')}
          </div>
          <button class="btn btn--primary btn--block fadd__submit" type="submit">
            ${icon('send', 'fill')}<span>${String(addBox.submit ?? 'ENVIAR CONVITE')}</span>
          </button>
          <p class="field__hint fadd__hint">${String(addBox.hint ?? '')}</p>
        </form>
        <hr class="divider fadd__rule">
        <div class="fadd__id">
          <span class="fadd__id-label">${String(addBox.id_label ?? 'SEU ID')}</span>
          <span class="fadd__id-val num">${String(player.name ?? '')}${String(addBox.id_suffix ?? '')}</span>
        </div>
      </div>
    </section>

    <section class="panel fsquad panel--grow" data-boot>
      <header class="panel__head">
        <h2 class="panel__title">${icon('group', 'panel__title-ic')}${String(squad.title ?? 'ESQUADRÃO RECENTE')}</h2>
        <a class="panel__action" href="${url('lobby')}">${String(squad.action ?? 'ABRIR LOBBY')}${icon('chevron_right')}</a>
      </header>
      <div class="panel__body panel__body--tight">
        <ul class="squad-list" data-stagger>
${join(
  squadItems.map(
    (m) => html`          <li class="srow" data-boot>
            <span class="srow__thumb">
              <img class="srow__portrait" src="${media(String(m.avatar))}" alt="" width="896" height="1200" loading="lazy" decoding="async">
              <span class="srow__thumb-fade" aria-hidden="true"></span>
            </span>
            <span class="srow__id">
              <strong class="srow__name ellipsis">${String(m.name)}</strong>
              <span class="srow__map ellipsis">${String(m.map)}</span>
            </span>
            <span class="srow__right">
              <span class="srow__result${m.ok ? '' : ' is-ko'}">${String(m.result)}</span>
              <span class="srow__when num">${String(m.when)}</span>
            </span>
            <a class="srow__add" href="${url('lobby')}" data-tip="Convidar ${String(m.name)}" aria-label="Convidar ${String(m.name)}">${icon('person_add')}</a>
          </li>
`,
  ),
)}${squadItems.length === 0 ? raw('          <li class="srow srow--empty">Nenhuma partida em esquadrão ainda.</li>\n') : ''}        </ul>
      </div>
    </section>

  </aside>
</div>
`;
}
