/**
 * Layouts do painel — app/Views/layouts/admin.php ('admin') e admin-auth.php ('admin-auth').
 *
 * ADMIN — casca de todas as telas do painel. Quem renderiza é AdminController.render()
 * (controllers/admin/admin-controller.ts), que já injeta `admin` e `admin_nav` em s.data.
 *
 * s.page (além do contrato normal, ver AdminController.adminPage()):
 *   slug     'admin-jogadores'  view/css/js da tela (assets/css/admin/<slug>.css, assets/js/admin/<slug>.js)
 *   nav      'jogadores'        qual item da sidebar acende
 *   title    'Jogadores'        título grande da topbar
 *   subtitle 'Contas, ...'      linha de apoio
 *   actions  SafeHtml | string  HTML já montado, colado à direita do título (string = HTML confiável)
 *   css/js   extras             caminhos relativos a assets/ (com ou sem o prefixo css/ js/)
 *
 * s.data (tudo opcional — a casca renderiza inteira sem nenhum deles):
 *   admin_nav    grupos da sidebar (formato de dataMock('admin_nav'))
 *   admin        { name, role, avatar, env, version, logout, menu: [{route, icon, label}] }
 *   admin_kpis   [{ icon, label, value, tone, sub }] — bloco compacto da topbar (máx. 3)
 *   admin_alerts [{ icon, text, meta, tone }] — sino de notificações
 *
 * A sidebar só vira link para rota que JÁ responde a GET (Router.existeGet, a tabela de rotas
 * carregada em runtime): item sem rota fica apagado com cadeado, nunca um <a> para 404.
 * Os KPIs e o sino não inventam número: sem dado, travessão.
 */
import { APP_NAME, BASE_URL } from '../../core/config';
import { ctx } from '../../core/context';
import { asset, assetExists, csrfField, dataMock, icon, img, media, url } from '../../core/helpers';
import { html, join, raw, SafeHtml } from '../../core/html';
import { Router } from '../../core/router';
import { nonce } from '../../core/security';
import type { Dict, Scope } from '../../core/view';
import { logo } from '../components/index';

/** Script do <head> que aplica o tema salvo ANTES da primeira pintura (sem flash claro/escuro). */
const TEMA_ADMIN = `(function(){
  var h = document.documentElement, t;
  try { t = localStorage.getItem('eh.admin.tema'); } catch (e) { t = null; }
  if (t !== 'claro' && t !== 'escuro') {
    /* Sem escolha explícita, o sistema é só um PALPITE — e o padrão continua escuro. */
    t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? 'claro' : 'escuro';
  }
  h.setAttribute('data-tema', t);
})();`;

const TEMA_AUTH = `(function(){
  var h = document.documentElement, t;
  try { t = localStorage.getItem('eh.admin.tema'); } catch (e) { t = null; }
  if (t !== 'claro' && t !== 'escuro') {
    t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? 'claro' : 'escuro';
  }
  h.setAttribute('data-tema', t);
})();`;

const limpaSlug = (v: unknown, def: string) => String(v ?? def).replace(/[^a-z0-9_-]/g, '') || def;
const tom = (v: unknown, def = 'lime') => String(v ?? def).toLowerCase().replace(/[^a-z]/g, '') || def;

function docTitle(s: Scope, def: string): string {
  const t = String(s.page.title ?? def).trim();
  return (t !== '' ? t + ' · ' : '') + 'Painel · ' + APP_NAME;
}

/** Lista de assets: base + extras de page.css/page.js (com prefixo normalizado, sem repetir). */
function comExtras(base: string[], extras: unknown, prefixo: 'css/' | 'js/'): string[] {
  const out = [...base];
  for (const x of Array.isArray(extras) ? extras : []) {
    let extra = String(x).replace(/^\/+/, '');
    extra = extra.startsWith(prefixo) ? extra : prefixo + extra;
    if (!out.includes(extra)) out.push(extra);
  }
  return out;
}

function preloads(fontes: string[]): SafeHtml {
  return join(
    fontes.map((f) =>
      assetExists('fonts/' + f)
        ? html`<link rel="preload" as="font" type="font/woff2" crossorigin href="${asset('fonts/' + f, false)}">
`
        : '',
    ),
  );
}

function folhas(lista: string[]): SafeHtml {
  return join(lista.map((css) => (assetExists(css) ? html`<link rel="stylesheet" href="${asset(css)}">
` : '')));
}

function scripts(lista: string[], n: string): SafeHtml {
  return join(
    lista.map((js) => (assetExists(js) ? html`<script type="module" nonce="${n}" src="${asset(js)}"></script>
` : '')),
  );
}

/** <head> comum às duas cascas (tudo até as folhas de estilo). */
function cabeca(s: Scope, titulo: string, tema: string, fontes: string[], estilos: string[], n: string): SafeHtml {
  return html`<!DOCTYPE html>
<html lang="pt-BR" data-tema="escuro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${titulo}</title>
<script nonce="${n}">
${raw(tema)}
</script>
<meta name="color-scheme" content="dark light">
<meta name="theme-color" content="#070907">
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="${s.csrf ?? ''}">
<link rel="icon" type="image/png" href="${img('logo/emblem.png')}">
${preloads(fontes)}${folhas(estilos)}</head>
`;
}

/** Overlay de fundo (assets/css/admin.css §1.0): brilho, grade, grão e vinheta. */
const FUNDO = raw(`<div class="adm-bg" aria-hidden="true">
  <span class="adm-bg__glow"></span>
  <span class="adm-bg__grid"></span>
  <span class="adm-bg__grain"></span>
  <span class="adm-bg__vig"></span>
</div>
`);

/** Alternador de tema (o estado real mora no <html data-tema>; assets/js/admin.js sincroniza). */
function alternadorTema(recuo: string): SafeHtml {
  return html`${raw(recuo)}<div class="adm-tema" role="group" aria-label="Tema do painel">
${raw(recuo)}  <button class="adm-tema__opt" type="button" data-adm-tema="escuro"
${raw(recuo)}          aria-pressed="false" title="Modo escuro">${icon('dark_mode')}<span class="sr-only">Modo escuro</span></button>
${raw(recuo)}  <button class="adm-tema__opt" type="button" data-adm-tema="claro"
${raw(recuo)}          aria-pressed="false" title="Modo claro">${icon('light_mode')}<span class="sr-only">Modo claro</span></button>
${raw(recuo)}</div>
`;
}

/** Pilha de flashes do painel (classe extra na porta: adm-auth__flashes). */
function flashes(flash: Record<string, string>, extra = '', recuo = ''): SafeHtml {
  const itens = Object.entries(flash ?? {}).filter(([, m]) => typeof m === 'string' && m !== '');
  if (itens.length === 0) return raw('');
  const r = raw(recuo);
  return html`${r}<div class="adm-flashes${extra}" role="status" aria-live="polite">
${itens.map(([kind, msg]) => {
  const k = kind.replace(/[^a-z0-9_-]/g, '') || 'info';
  return html`${r}    <div class="adm-flash adm-flash--${k}" data-flash data-autohide="${k === 'error' ? 9000 : 5000}">
${r}      ${icon(k === 'error' ? 'error' : k === 'ok' ? 'check_circle' : 'info')}<span>${msg}</span>
${r}      <button class="adm-flash__x" type="button" data-adm-close aria-label="Fechar">${icon('close')}</button>
${r}    </div>
`;
})}${r}</div>
`;
}

// ------------------------------------------------------------------------ sidebar

interface ItemNav extends Dict {
  _rota: string;
  _vivo: boolean;
}

/** Resolve rota e existência de cada item (e filhos) de um grupo; devolve quantos estão vivos. */
function resolverGrupo(itens: Dict[]): { itens: ItemNav[]; vivos: number } {
  let vivos = 0;
  const um = (it: Dict): ItemNav => {
    const r = String(it.route ?? it.slug ?? '').replace(/^\/+|\/+$/g, '');
    const vivo = r !== '' && Router.existeGet(r);
    vivos += vivo ? 1 : 0;
    return { ...it, _rota: r, _vivo: vivo };
  };
  const out = itens.map((it) => {
    const pai = um(it);
    if (Array.isArray(it.children)) pai.children = (it.children as Dict[]).map(um);
    return pai;
  });
  return { itens: out, vivos };
}

function itemNav(item: ItemNav, navAtivo: string): SafeHtml {
  const filhos = (Array.isArray(item.children) ? item.children : []) as ItemNav[];
  const itSlug = String(item.slug ?? '');
  // O pai acende (mais fraco) quando quem está aberto é um filho.
  const ramoOn = filhos.some((f) => String(f.slug ?? '') === navAtivo);
  const itOn = itSlug !== '' && itSlug === navAtivo;
  const itBadge = item.badge ?? null;
  const label = String(item.label ?? '');
  const cls =
    'adm-nav__item' +
    (item.feature ? ' adm-nav__item--feature' : '') +
    (itOn ? ' is-active' : '') +
    (item._vivo ? '' : ' is-soon') +
    (filhos.length ? ' adm-nav__item--parent' : '') +
    (ramoOn && !itOn ? ' is-branch' : '');
  const ico = String(item.icon ?? 'chevron_right');

  const linha = item._vivo
    ? html`            <a class="${cls}" href="${url(item._rota)}"${itOn ? raw(' aria-current="page"') : ''}>
                ${icon(ico, 'adm-nav__ico')}
                <span class="adm-nav__txt">${label}</span>
${itBadge !== null && itBadge !== '' ? html`                  <span class="adm-nav__count num">${String(itBadge)}</span>
` : ''}                ${icon('chevron_right', 'adm-nav__chev')}
              </a>
`
    : html`                            <span class="${cls}" aria-disabled="true"
                    aria-label="${label + ' — em breve'}"
                    title="${label + ' — tela ainda não publicada'}">
                ${icon(ico, 'adm-nav__ico')}
                <span class="adm-nav__txt">${label}</span>
                ${icon('lock', 'adm-nav__lock')}
              </span>
`;

  const sub = filhos.length
    ? html`              <div class="adm-nav__sub">
${filhos.map((f) => {
  const fSlug = String(f.slug ?? '');
  const fOn = fSlug !== '' && fSlug === navAtivo;
  const fCls = 'adm-nav__item adm-nav__item--child' + (fOn ? ' is-active' : '') + (f._vivo ? '' : ' is-soon');
  const fIco = String(f.icon ?? 'chevron_right');
  const fLbl = String(f.label ?? '');
  return f._vivo
    ? html`                                      <a class="${fCls}" href="${url(f._rota)}"${fOn ? raw(' aria-current="page"') : ''}>
                      ${icon(fIco, 'adm-nav__ico')}
                      <span class="adm-nav__txt">${fLbl}</span>
                    </a>
`
    : html`                                      <span class="${fCls}" aria-disabled="true"
                          title="${fLbl + ' — tela ainda não publicada'}">
                      ${icon(fIco, 'adm-nav__ico')}
                      <span class="adm-nav__txt">${fLbl}</span>
                      ${icon('lock', 'adm-nav__lock')}
                    </span>
`;
})}              </div>
`
    : '';
  return html`${linha}${sub}`;
}

function sidebar(grupos: Dict[], navAtivo: string): SafeHtml {
  return join(
    grupos.map((grupo) => {
      const brutos = Array.isArray(grupo.items) ? (grupo.items as Dict[]) : [];
      if (brutos.length === 0) return '';
      const { itens, vivos } = resolverGrupo(brutos);
      const pendente = vivos === 0; // o módulo inteiro ainda está por vir: diz UMA vez
      const rotulo = grupo.label
        ? html`            <p class="adm-nav__label">
              ${String(grupo.label)}${pendente ? raw('<span class="adm-nav__label-soon"> · em breve</span>') : ''}
            </p>
`
        : '';
      return html`                <div class="adm-nav__group">
${rotulo}${itens.map((it) => itemNav(it, navAtivo))}        </div>
`;
    }),
  );
}

// ------------------------------------------------------------------------ topbar

function kpisTopo(kpis: Dict[]): SafeHtml {
  // Sem dado do controller, os três KPIs padrão aparecem em branco (travessão).
  const lista = kpis.length
    ? kpis
    : [
        { icon: 'group', label: 'Jogadores online', value: null, tone: 'lime' },
        { icon: 'payments', label: 'Receita (mês)', value: null, tone: 'cyan' },
        { icon: 'schedule', label: 'Uptime', value: null, tone: 'amber' },
      ];
  return join(
    lista.slice(0, 3).map((k) => {
      const v = k.value ?? null;
      return html`                    <div class="adm-top__kpi adm-top__kpi--${tom(k.tone)}">
            ${icon(String(k.icon ?? 'monitoring'), 'adm-top__kpi-ico')}
            <span class="adm-top__kpi-txt">
              <span class="adm-top__kpi-lbl">${String(k.label ?? '')}</span>
              <span class="adm-top__kpi-val num">${v === null || v === '' ? '—' : String(v)}${k.sub ? html`<small>${String(k.sub)}</small>` : ''}</span>
            </span>
          </div>
`;
    }),
  );
}

function alertas(lista: Dict[]): SafeHtml {
  const tem = lista.length > 0;
  return html`        <div class="adm-pop-wrap">
          <button class="adm-icobtn${tem ? ' has-news' : ''}" type="button"
                  data-adm-pop="adm-alerts" aria-expanded="false" aria-controls="adm-alerts"
                  aria-label="Notificações">${icon('notifications')}
${tem ? html`            <span class="adm-icobtn__count num">${String(lista.length)}</span>` : ''}          </button>
          <div class="adm-pop adm-pop--alerts" id="adm-alerts" hidden>
            <p class="adm-pop__head">Notificações</p>
${
  !tem
    ? raw('              <p class="adm-pop__empty">Nada novo por aqui.</p>\n')
    : html`              <ul class="adm-pop__list">
${lista.map(
  (al) => html`                                  <li class="adm-pop__item adm-pop__item--${tom(al.tone)}">
                    ${icon(String(al.icon ?? 'info'), 'adm-pop__ico')}
                    <span class="adm-pop__txt">${String(al.text ?? '')}
${al.meta ? html`                      <small>${String(al.meta)}</small>` : ''}                    </span>
                  </li>
`,
)}              </ul>
`
}          </div>
        </div>
`;
}

function menuUsuario(adm: Dict, sair: string): SafeHtml {
  const nome = String(adm.name ?? 'Administrador');
  const papel = String(adm.role ?? 'Operador');
  const foto = String(adm.avatar ?? '');
  const menu = Array.isArray(adm.menu) ? (adm.menu as Dict[]) : [];
  return html`        <div class="adm-pop-wrap">
          <button class="adm-user" type="button" data-adm-pop="adm-user-menu" aria-expanded="false" aria-controls="adm-user-menu">
${
  foto !== ''
    ? html`              <img class="adm-user__ava" src="${media(foto)}" alt="" width="40" height="40" decoding="async">
`
    : html`              <span class="adm-user__ava adm-user__ava--ph">${icon('shield_person')}</span>
`
}            <span class="adm-user__txt">
              <span class="adm-user__name">${nome}</span>
              <span class="adm-user__role">${papel}</span>
            </span>
            ${icon('expand_more', 'adm-user__chev')}
          </button>
          <div class="adm-pop adm-pop--menu" id="adm-user-menu" hidden>
${menu.map(
  (mi) => html`                          <a class="adm-pop__link" href="${url(String(mi.route ?? ''))}">${icon(String(mi.icon ?? 'chevron_right'))}<span>${String(mi.label ?? '')}</span></a>
`,
)}            <a class="adm-pop__link" href="${url('inicio')}">${icon('sports_esports')}<span>Voltar ao jogo</span></a>
            <form method="post" action="${sair}">
              ${csrfField()}
              <button class="adm-pop__link adm-pop__link--danger" type="submit">${icon('logout')}<span>Sair do painel</span></button>
            </form>
          </div>
        </div>
`;
}

// ------------------------------------------------------------------------ cascas

/** Layout ADMIN — casca de todas as telas do painel. */
export function admin(s: Scope, content: SafeHtml): string {
  const n = nonce();
  const page = s.page;
  const data = s.data ?? {};
  const slug = limpaSlug(page.slug, 'admin');
  const navAtivo = String(page.nav ?? slug);
  const titulo = String(page.title ?? 'Painel Admin').trim();
  const subtitulo = String(page.subtitle ?? '').trim();

  // Grupos da sidebar: controller manda, senão o arquivo de dados.
  const navBruto = data.admin_nav ?? dataMock('admin_nav');
  const admNav = (Array.isArray(navBruto) ? navBruto : []) as Dict[];

  const adm = (data.admin && typeof data.admin === 'object' ? data.admin : {}) as Dict;
  const env = String(adm.env ?? (ctx().debug ? 'Ambiente local' : 'Produção'));
  const versao = String(adm.version ?? 'v1.0');
  const sair = String(adm.logout ?? url('sair'));
  const kpis = (Array.isArray(data.admin_kpis) ? data.admin_kpis : []) as Dict[];
  const alerts = (Array.isArray(data.admin_alerts) ? data.admin_alerts : []) as Dict[];

  // Casco primeiro, CSS da tela por último. admin/lista.css é o par de admin/lista.js.
  const estilos = comExtras(
    ['fonts/fonts.css', 'css/tokens.css', 'css/base.css', 'css/components.css', 'css/logo.css', 'css/admin.css', 'css/admin/lista.css', 'css/admin/' + slug + '.css'],
    page.css,
    'css/',
  );
  const js = comExtras(['js/admin.js', 'js/admin/lista.js', 'js/admin/' + slug + '.js'], page.js, 'js/');
  const fontes = ['barlow-condensed-5.woff2', 'barlow-2.woff2', 'share-tech-mono-0.woff2', 'material-symbols-0.woff2'];

  const acoes = page.actions;
  const acoesHtml = acoes instanceof SafeHtml ? acoes : typeof acoes === 'string' && acoes !== '' ? raw(acoes) : null;

  return html`${cabeca(s, docTitle(s, 'Painel Admin'), TEMA_ADMIN, fontes, estilos, n)}<body class="adm-body page-${slug}" data-page="${slug}" data-base="${BASE_URL}" data-nonce="${n}">
${FUNDO}<div class="adm">

  <aside class="adm-side" id="adm-side">
    <a class="adm-brand" href="${url('admin')}">
      ${logo({ variant: 'mark', class: 'adm-brand__mark' })}      <span class="adm-brand__txt">
        <span class="adm-brand__name">${APP_NAME}</span>
        <span class="adm-brand__env">${env}</span>
      </span>
    </a>

    <nav class="adm-nav scroll-y" aria-label="Navegação do painel">
${sidebar(admNav, navAtivo)}    </nav>

    <div class="adm-side__foot">
      <form method="post" action="${sair}">
        ${csrfField()}
        <button class="adm-side__out" type="submit">${icon('logout')}<span>Sair do painel</span></button>
      </form>
    </div>
  </aside>

  <div class="adm-main">
    <header class="adm-top">
      <button class="adm-top__rail" type="button" data-adm-rail aria-label="Recolher menu">${icon('menu_open')}</button>

      <div class="adm-top__id">
        <h1 class="adm-top__title">${titulo}</h1>
        ${subtitulo !== '' ? html`<p class="adm-top__sub">${subtitulo}</p>` : ''}
      </div>

${acoesHtml ? html`              <div class="adm-top__actions">${acoesHtml}</div>
` : ''}
      <div class="adm-top__kpis">
${kpisTopo(kpis)}      </div>

      <div class="adm-top__tools">
${alternadorTema('        ')}
${alertas(alerts)}
${menuUsuario(adm, sair)}      </div>
    </header>

    <main class="adm-content scroll-y" id="main">
${flashes(s.flash, '', '        ')}${content}    </main>

    <footer class="adm-foot">
      <span>&copy; ${String(new Date().getUTCFullYear())} ${APP_NAME}. Todos os direitos reservados.</span>
      <span class="adm-foot__ver">Painel Administrativo ${versao}</span>
    </footer>
  </div>
</div>
${scripts(js, n)}</body>
</html>
`.value;
}

/** Layout ADMIN-AUTH — a porta do painel (/admin/login): sem sidebar, sem 3D, sem SPA. */
export function adminAuth(s: Scope, content: SafeHtml): string {
  const n = nonce();
  const slug = limpaSlug(s.page.slug, 'admin-login');
  const estilos = ['fonts/fonts.css', 'css/tokens.css', 'css/base.css', 'css/components.css', 'css/logo.css', 'css/admin.css', 'css/admin/' + slug + '.css'];
  const js = ['js/admin.js', 'js/admin/' + slug + '.js'];
  const fontes = ['barlow-condensed-5.woff2', 'barlow-2.woff2', 'material-symbols-0.woff2'];
  return html`${cabeca(s, docTitle(s, 'Acesso restrito'), TEMA_AUTH, fontes, estilos, n)}<body class="adm-body adm-auth-body page-${slug}" data-page="${slug}" data-base="${BASE_URL}" data-nonce="${n}">
${FUNDO}${alternadorTema('')}<main class="adm-auth" id="main">
${flashes(s.flash, ' adm-auth__flashes', '  ')}${content}</main>
${scripts(js, n)}</body>
</html>
`.value;
}
