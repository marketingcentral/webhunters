/**
 * Parciais do casco — app/Views/partials/{background,topbar,footer}.php.
 */
import { asset, assetExists, csrfField, icon, img, num, svg, url } from '../../core/helpers';
import { html, join, raw, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import * as OperationalRepo from '../../repo/operational-repo';
import { badge, hex } from '../components';

/** .bg > picture > img.bg__img + overlay + canvas#atmosphere + grain + vignette */
export function background(s: Scope, vars: { bg?: string; bg_pos?: string } = {}): SafeHtml {
  const bgPath = String(vars.bg ?? s.page.bg ?? '');
  let bgPos = String(vars.bg_pos ?? s.page.bg_pos ?? 'center');
  if (!/^[a-z0-9% .-]{1,40}$/i.test(bgPos)) bgPos = 'center';
  let webp = '';
  if (bgPath !== '' && /\.(jpe?g|png)$/i.test(bgPath)) {
    const cand = bgPath.replace(/\.(jpe?g|png)$/i, '.webp');
    if (assetExists('img/' + cand)) webp = cand;
  }
  const hasImg = bgPath !== '' && assetExists('img/' + bgPath);
  const atmosphere = s.page.three?.atmosphere ?? true;
  return html`<div class="bg${hasImg ? '' : ' bg--plain'}" aria-hidden="true" style="--bg-pos:${bgPos}">
${hasImg ? html`  <picture>
${webp !== '' ? html`    <source srcset="${img(webp)}" type="image/webp">
` : ''}    <img class="bg__img" src="${img(bgPath)}" alt="" fetchpriority="high" decoding="async" style="object-position:${bgPos}">
  </picture>
` : ''}  <div class="bg__overlay"></div>
${atmosphere ? html`  <canvas id="atmosphere" class="bg__fx"></canvas>
` : ''}  <div class="bg__grain"></div>
  <div class="bg__vignette"></div>
</div>
`;
}

export function topbar(s: Scope): SafeHtml {
  const player = s.player ?? {};
  const page = s.page;
  const name = String(player.name ?? 'HUNTER');
  const level = Number(player.level ?? 1);
  const xp = Number(player.xp ?? 0);
  const xpNext = Math.max(1, Number(player.xp_next ?? 1));
  const xpPct = Math.min(100, Math.max(0, Math.round((xp / xpNext) * 1000) / 10));
  const cur: Dict = player.currencies ?? {};
  const friends = Number(player.friends_online ?? 0);
  const unread = Number(player.unread_mail ?? 0);
  const ativo = String(page.nav ?? page.slug ?? '');
  const hasBack = !('back' in page && page.back === null);
  const back = hasBack && page.back && typeof page.back === 'object' ? page.back : { label: 'VOLTAR', href: url('inicio') };
  const backLbl = String(back.label ?? 'VOLTAR');
  const backUrl = back.href ?? null;
  const backIco = backUrl === null ? 'logout' : 'undo';
  const op = (player.operational as OperationalRepo.Operational) ?? OperationalRepo.defaults();
  const b = OperationalRepo.badge(op, level);
  const tip = b.survivor
    ? 'Sem bandeira: nenhuma organização responde por este operador.'
    : b.state === 'MERCENARY'
      ? 'Contrato mercenário ativo. Hunter Command não garante resgate.'
      : 'Operando sob o Hunter Command.';

  return html`<header class="topbar${hasBack ? '' : ' topbar--home'}" role="banner">
  <a class="topbar__player" href="${url('perfil')}" aria-label="Perfil de ${name}">
    ${hex({ size: 'md', color: 'lime', hot: true, icon: 'skull' })}
    <span class="topbar__id">
      <span class="topbar__idtop">
        <span class="topbar__name">${name}</span>
        <span class="topbar__state">
          ${badge({ kind: 'state', value: b, class: 'topbar__state-badge', tip })}
        </span>
      </span>
      <span class="topbar__lvl">
        <span class="topbar__lvl-txt topbar__lvl-txt--${b.state.toLowerCase()}">${b.sub}</span>
        <span class="topbar__xp" style="--v:${String(xpPct)}%" role="progressbar" aria-label="Experiência" aria-valuemin="0" aria-valuemax="${String(xpNext)}" aria-valuenow="${String(xp)}"><span class="topbar__xp-bar"></span></span>
        <span class="topbar__xpnum num">${num(xp)} / ${num(xpNext)} XP</span>
      </span>
    </span>
  </a>

  <nav class="topbar__nav" aria-label="Menu principal">
    ${join(
      (s.nav ?? []).map((item) => {
        const on = (item.slug ?? '') === ativo;
        return html`<a class="topbar__link${on ? ' is-active' : ''}" href="${url(String(item.route ?? item.slug ?? ''))}"${on ? raw(' aria-current="page"') : ''}>${String(item.label ?? '')}</a>
    `;
      }),
    )}
  </nav>

  ${hasBack ? html`<a class="btn btn--gold topbar__shop" href="${url('loja')}">${icon('shopping_cart')} Loja</a>` : ''}

  <div class="topbar__right">
    <a class="topbar__cur topbar__cur--lime" href="${url('loja')}" title="Créditos">${svg('coin-credit')}<span class="num">${num(cur.credits ?? 0)}</span></a>
    <a class="topbar__cur topbar__cur--cyan" href="${url('loja')}" title="Dinheiro">${svg('coin-cash')}<span class="num">${num(cur.cash ?? 0)}</span></a>
    <a class="topbar__cur topbar__cur--amber" href="${url('loja')}" title="Pontos">${svg('coin-points')}<span class="num">${num(cur.points ?? 0)}</span></a>
    <span class="topbar__sep" aria-hidden="true"></span>
    <a class="topbar__ico topbar__friends" href="${url('amigos')}" title="Amigos online">${icon('group', 'fill')}<span class="num">${String(friends)}</span></a>
    <a class="topbar__ico topbar__mail${unread > 0 ? ' has-unread' : ''}" href="${url('mensagens')}" title="${unread > 0 ? `${unread} mensagens não lidas` : 'Mensagens'}">${icon('mail', 'fill')}</a>
    ${
      !hasBack
        ? html`<a class="topbar__ico topbar__settings" href="${url('configuracoes')}" title="Configurações">${icon('settings', 'fill')}</a>
      <a class="btn btn--gold topbar__shop topbar__shop--tall" href="${url('loja')}">${icon('shopping_cart')}<span class="topbar__shop-lbl">Loja</span></a>`
        : backUrl === null
          ? html`<form class="topbar__back-form" method="post" action="${url('sair')}">
        ${csrfField()}
        <button class="topbar__back" type="submit">${icon(backIco)}<span>${backLbl}</span></button>
      </form>`
          : html`<a class="topbar__back" href="${String(backUrl)}">${icon(backIco)}<span>${backLbl}</span></a>`
    }
  </div>
</header>
`;
}

export function footer(s: Scope, vars: { minimal?: boolean } = {}): SafeHtml {
  const server = s.server ?? {};
  const min = Boolean(vars.minimal);
  const region = String(server.region ?? 'AMÉRICA DO SUL');
  const ping = Number(server.ping_ms ?? 0);
  const online = (server.status ?? 'online') === 'online';
  const bars = Math.min(4, Math.max(0, Number(server.bars ?? 4)));
  const social = [
    { id: 'discord', label: 'Discord', href: 'https://discord.com/' },
    { id: 'twitter', label: 'Twitter', href: 'https://twitter.com/' },
    { id: 'youtube', label: 'YouTube', href: 'https://www.youtube.com/' },
    { id: 'instagram', label: 'Instagram', href: 'https://www.instagram.com/' },
  ];
  const barras = join([1, 2, 3, 4].map((i) => raw(`<i${i <= bars ? ' class="is-on"' : ''}></i>`)));
  return html`<footer class="footer${min ? ' footer--minimal' : ''}" role="contentinfo">
${
  min
    ? html`  <a class="footer__link" href="${url('suporte')}">${icon('headset_mic')}<span>Suporte</span></a>
`
    : html`  <div class="footer__social">
    <span class="footer__label">Siga-nos</span>
    ${join(social.map((x) => html`<a class="footer__soc" href="${x.href}" target="_blank" rel="noopener noreferrer" title="${x.label}" aria-label="${x.label}">${svg(x.id)}</a>
    `))}
  </div>

  <div class="footer__actions">
    <a class="btn btn--ghost footer__btn" href="${url('suporte')}">${icon('headset_mic')} Suporte</a>
    <a class="btn btn--ghost footer__btn" href="${url('relatorio-de-bug')}">${icon('bug_report')} Relatório de bug</a>
  </div>
`
}
  <div class="footer__server">
    <span class="footer__server-lbl">Servidor: ${region}</span>
    <span class="footer__bars" aria-label="Qualidade da conexão" role="img">${barras}</span>
    <span class="footer__ping num">${String(ping)}ms</span>
    <span class="footer__dot${online ? '' : ' is-off'}" title="${online ? 'Online' : 'Offline'}" aria-label="${online ? 'Servidor online' : 'Servidor offline'}" role="img"></span>
  </div>
</footer>
`;
}

/** Pilha de avisos (flash) que abre o <main> em todos os layouts. */
export function flashStack(flash: Record<string, string>, compacto = false): SafeHtml {
  const itens = Object.entries(flash ?? {}).filter(([, m]) => typeof m === 'string' && m !== '');
  if (!itens.length) return raw('');
  const nl = compacto ? '' : '\n';
  return html`<div class="flash-stack" role="status" aria-live="polite">${raw(nl)}${join(
    itens.map(([kind, msg]) => {
      const k = kind.replace(/[^a-z0-9_-]/g, '') || 'info';
      return html`<div class="flash flash--${k}" data-flash data-autohide="${kind === 'error' ? 9000 : 5000}">${raw(nl)}${icon(kind === 'error' ? 'warning' : 'check_circle', 'flash__icon')}<span class="flash__text">${msg}</span>${raw(nl)}</div>${raw(nl)}`;
    }),
  )}</div>${raw(nl)}`;
}

export const PRELOAD_FONTS = ['barlow-condensed-5.woff2', 'barlow-2.woff2', 'share-tech-mono-0.woff2', 'material-symbols-0.woff2', 'anton-2.woff2'];

export function fontPreloads(): SafeHtml {
  return join(
    PRELOAD_FONTS.filter((f) => assetExists('fonts/' + f)).map(
      (f) => html`<link rel="preload" as="font" type="font/woff2" crossorigin href="${asset('fonts/' + f, false)}">
`,
    ),
  );
}
