/**
 * Tela LOBBY — app/Views/pages/lobby.php (referência 03-lobby-ao-clicar-em-jogar-com-amigos.png).
 *
 * $data: title, subtitle, invite, squad[], empty_slot, leader_tag, chat[], chat_title, chat_ph,
 *        news_title, news_slides[], mission{...}, settings_title, settings[], cta, cta_href, gear_href.
 */
import * as GR from '../../core/game-rules';
import { csrfField, icon, media, num, svg, url } from '../../core/helpers';
import { html, join, raw, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { carousel, hex } from '../components/index';

export function lobby(s: Scope): SafeHtml {
  const d = s.data;
  const squad = (d.squad ?? []) as Dict[];
  const empty: Dict = d.empty_slot ?? {};
  const chat = (d.chat ?? []) as Dict[];
  const slides = (d.news_slides ?? []) as Dict[];
  const mission: Dict = d.mission ?? {};
  const settings = (d.settings ?? []) as Dict[];

  /* --- slides do carrossel de notícias (HTML já renderizado) --- */
  const slidesHtml = slides.map(
    (sl) => html`<a class="news-slide" href="${url(String(sl.href ?? 'noticias'))}">
  <img class="news-slide__img" src="${media(String(sl.image ?? 'news/news-atualizacao.jpg'))}" alt="" loading="lazy" decoding="async">
  <span class="news-slide__shade" aria-hidden="true"></span>
  <span class="news-slide__body">
    <strong class="news-slide__title">${String(sl.title ?? '')}</strong>
    <span class="news-slide__desc">${String(sl.desc ?? '')}</span>
  </span>
</a>
`,
  );

  /* PRD v3 §18.2–18.4: callsign · nível · PATENTE · FUNÇÃO (as quatro oficiais) · prontidão.
     O líder é COMANDO — maior patente presente — com tooltip; a coroa fica, mas não sozinha. */
  const readyCls: Dict = d.readiness_labels ?? { PRONTO: 'lime', PREPARANDO: 'amber', 'NÃO PRONTO': 'muted' };

  const opCard = (m: Dict): SafeHtml => {
    const ready = String(m.readiness ?? m.status ?? 'PRONTO').toUpperCase();
    const roleKey = GR.role((m.role ?? m.class ?? null) as string | null) ?? 'ASSAULT';
    const roleDef = GR.ROLES[roleKey]!;
    const cor = String(readyCls[ready] ?? 'muted').toLowerCase();
    const leader = Boolean(m.leader);
    const readyIc = ready === 'PRONTO' ? 'check_circle' : ready === 'PREPARANDO' ? 'pending' : 'radio_button_unchecked';
    return html`      <article class="op-card${leader ? ' op-card--leader' : ''} op-card--${cor}" data-boot>
${leader
  ? html`        <span class="op-card__tag" data-tip="${String(d.command_tip ?? 'Oficial de maior patente presente na unidade.')}">${svg('crown', 'op-card__crown')}${String(d.command_tag ?? 'COMANDO')}</span>
`
  : ''}        <span class="op-card__media">
          <img class="op-card__portrait" src="${media(String(m.portrait))}" alt="" width="896" height="1200" decoding="async">
          <span class="op-card__fade" aria-hidden="true"></span>
        </span>
        <span class="op-card__info">
          ${hex({ size: 'md', color: leader ? 'lime' : 'muted', text: String(m.level), hot: leader, class: 'op-card__hex', title: 'Nível ' + String(m.level) })}
          <span class="op-card__id">
            <strong class="op-card__name">${String(m.name)}</strong>
            <span class="op-card__rank">${String(m.rank ?? 'RECRUTA')}</span>
            <span class="op-card__ready op-card__ready--${cor}">${ready}${icon(readyIc, 'fill op-card__check')}</span>
          </span>
        </span>
        <span class="op-card__class" data-tip="${roleDef.desc}">
          ${icon(roleDef.ms ?? 'swords', 'op-card__class-ic')}
          <span class="op-card__class-name">${roleDef.label}</span>
        </span>
      </article>
`;
  };

  const objLines = (Array.isArray(mission.objective_lines) ? mission.objective_lines : [String(mission.objective ?? '')]) as unknown[];
  const rewardItem: Dict = mission.reward_item ?? {};

  const setRow = (row: Dict): SafeHtml => {
    /* PRD v3 §18.6: num mundo persistente clima, janela e protocolo de fogo amigo são
       INFORMAÇÃO — a linha continua no mesmo lugar e com a mesma cara, só perde as setas. */
    const ro = String(row.type ?? '') === 'readonly';
    const opts = ro ? [String(row.value)] : ((row.options ?? [row.value]) as unknown[]).map((o) => String(o));
    const label = String(row.label);
    return html`        <div class="set-row${ro ? ' set-row--readonly' : ' stepper'}${row.icon ? ' set-row--icon' : ''}" data-key="${String(row.key)}"${ro ? '' : html` data-options="${opts.join('|')}" data-value="${String(row.value)}"`}${row.tip ? html` data-tip="${String(row.tip)}"` : ''}>
          <span class="set-row__label">${label}</span>
${ro
  ? raw('          <span class="stepper__btn ms set-row__prev" aria-hidden="true"></span>\n')
  : html`          <button class="stepper__btn ms set-row__prev" type="button" aria-label="Anterior: ${label}">chevron_left</button>
`}${row.icon ? html`          ${icon(String(row.icon), 'set-row__ic')}
` : ''}          <span class="stepper__val set-row__val">${String(row.value)}</span>
${ro
  ? raw('          <span class="stepper__btn ms set-row__next" aria-hidden="true"></span>\n')
  : html`          <button class="stepper__btn ms set-row__next" type="button" aria-label="Próximo: ${label}">chevron_right</button>
`}          <input type="hidden" name="${String(row.key)}" value="${String(row.value)}">
        </div>
`;
  };

  return html`<div class="lobby">

  <!-- ====================== COLUNA ESQUERDA ====================== -->
  <div class="lobby__left">

    <header class="lobby-head">
      <div class="lobby-head__id">
        <h1 class="page-title">${String(d.title ?? 'LOBBY')}</h1>
        <p class="page-subtitle">${String(d.subtitle ?? '')}</p>
      </div>
      <a class="invite-btn" href="${url('amigos')}">
        ${icon('person_add', 'invite-btn__ic')}<span>${String(d.invite ?? 'CONVIDAR AMIGO')}</span>
      </a>
    </header>

    <div class="squad" data-stagger>
${join(squad.map(opCard))}
      <a class="op-card op-card--empty" href="${url('amigos')}" data-boot>
        <span class="op-card__plus">${icon('add')}</span>
        <strong class="op-card__empty-title">${String(empty.title ?? 'CONVIDAR AMIGO')}</strong>
        <small class="op-card__empty-sub">${String(empty.desc ?? 'Espaço livre')}</small>
      </a>
    </div>

    <div class="lobby-bottom">

      <section class="panel lobby-chat">
        <header class="panel__head">
          <h2 class="panel__title">${String(d.chat_title ?? 'CHAT DO LOBBY')}</h2>
        </header>
        <div class="panel__body">
          <ul class="chat scroll-y scroll-y--nomask" id="lobby-chat">
${join(
  chat.map(
    (msg) => html`            <li class="chat__msg${msg.self ? ' is-self' : ''}">
              <span class="chat__line"><span class="chat__author">${String(msg.author)}:</span> <span class="chat__text">${String(msg.text)}</span></span>
              <span class="chat__time num">${String(msg.time)}</span>
            </li>
`,
  ),
)}          </ul>
          <form class="chat__form" method="post" action="${url('lobby/chat')}" autocomplete="off">
            ${csrfField()}
            <input class="chat__input" type="text" name="message" maxlength="200" placeholder="${String(d.chat_ph ?? 'Digite sua mensagem...')}" aria-label="${String(d.chat_ph ?? 'Mensagem')}" required>
            <button class="chat__send" type="submit" aria-label="${String(d.chat_send ?? 'Enviar mensagem')}">${icon('send', 'fill')}</button>
          </form>
        </div>
      </section>

      <section class="panel lobby-news">
        <header class="panel__head">
          <h2 class="panel__title">${String(d.news_title ?? 'NOTÍCIAS E ATUALIZAÇÕES')}</h2>
        </header>
        <div class="panel__body">
          ${carousel({ slides: slidesHtml, interval: 8000, dots: true, over: true, fixed: true, class: 'news-carousel', label: 'Notícias e atualizações' })}
        </div>
      </section>

    </div>
  </div>

  <!-- ====================== COLUNA DIREITA ====================== -->
  <aside class="lobby__right">

    <section class="panel mission">
      <header class="panel__head">
        <h2 class="panel__title">${String(mission.panel_title ?? 'MISSÃO SELECIONADA')}</h2>
      </header>
      <div class="panel__body">

        <a class="mission__media" href="${url(String(mission.href ?? 'missoes'))}">
          <img class="mission__img" src="${media(String(mission.image ?? 'ui/mission-sinal-perdido.jpg'))}" alt="${String(mission.title ?? '')}" decoding="async">
          <span class="mission__shade" aria-hidden="true"></span>
          <span class="mission__head">
            <strong class="mission__title">${String(mission.title ?? '')}</strong>
            <span class="mission__sub">${String(mission.subtitle ?? '')}</span>
          </span>
          <span class="mission__stats">
${join(
  ((mission.stats ?? []) as Dict[]).map(
    (st) => html`            <span class="mstat">
              <span class="mstat__label">${String(st.label)}</span>
              <span class="mstat__val">${String(st.value)}</span>
            </span>
`,
  ),
)}          </span>
        </a>

        <h3 class="mission__section">${String(mission.objective_title ?? 'OBJETIVO')}</h3>
        <p class="mission__objective">
${join(objLines.map((line, k) => html`          ${k ? raw('<br>') : ''}${String(line)}
`))}        </p>

        <hr class="divider mission__rule">

        <h3 class="mission__section">${String(mission.rewards_title ?? 'RECOMPENSAS')}</h3>
        <div class="rewards">
${join(
  ((mission.rewards ?? []) as Dict[]).map(
    (rw) => html`          <span class="reward reward--${String(rw.type ?? 'xp')}">
            <span class="reward__ring">${(rw.icon ?? 'xp') === 'xp' ? raw('<span class="reward__xp">XP</span>') : svg(String(rw.icon), 'reward__ic')}</span>
            <span class="reward__val num">${String(rw.prefix ?? '')}${num(Number(rw.value ?? 0) | 0)}${String(rw.suffix ?? '')}</span>
          </span>
`,
  ),
)}${Object.keys(rewardItem).length > 0
  ? html`          <span class="reward-item">
            <img class="reward-item__img" src="${media(String(rewardItem.image))}" alt="" decoding="async">
          </span>
          <span class="reward-item__text">
            <strong class="reward-item__name">${String(rewardItem.name)}</strong>
            <small class="reward-item__desc">${String(rewardItem.desc)}</small>
          </span>
`
  : ''}        </div>

      </div>
    </section>

    <section class="panel mission-set">
      <header class="panel__head">
        <h2 class="panel__title">${String(d.settings_title ?? 'CONFIGURAÇÕES DA MISSÃO')}</h2>
      </header>
      <div class="panel__body panel__body--tight">
${join(settings.map(setRow))}      </div>
    </section>

    <section class="panel mission-cta" data-boot>
      <a class="btn btn--primary start-btn" href="${url(String(d.cta_href ?? 'mapa'))}">
        ${icon('play_arrow', 'fill start-btn__play')}<span class="start-btn__label">${String(d.cta ?? 'INICIAR MISSÃO')}</span>
      </a>
      <a class="btn start-gear" href="${url(String(d.gear_href ?? 'configuracoes'))}" aria-label="${String(d.gear_label ?? 'Configurações')}" data-tip="${String(d.gear_label ?? 'Configurações')}">
        ${icon('settings')}
      </a>
    </section>

  </aside>
</div>
`;
}
