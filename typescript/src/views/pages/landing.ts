/**
 * PÁGINA DE DIVULGAÇÃO — app/Views/pages/landing.php, raiz do site (/). Layout: landing.
 *
 * data: title, description, nav[], hero, pilares[], queda, bestiario, sobreviver, estados,
 *       extracoes[], mundo, sistemas, faccoes[], faccoes_txt, galeria, final, rodape
 *       (data/landing.json — o que se lê); criaturas{grupos,abordagens,lista} (catálogo
 *       canônico); regions[], region, markers[], bases[], map_image, legend[], legend_all,
 *       contagens{regioes,pontos,bases,extracoes} (banco, via WorldMap — o que é).
 *
 * TUDO FUNCIONA SEM JAVASCRIPT: as abas mostram o primeiro painel, o bestiário mostra todas as
 * criaturas e a primeira ficha, as faixas de contaminação aparecem todas, o arquivo classificado
 * é um <details>, a galeria são links. O script (assets/js/pages/landing.js) só dá movimento.
 *
 * A arte do enredo passa por creatureImg(), que devolve null enquanto o arquivo não existe —
 * e aí quadro() desenha um quadro de arquivo vazio no mesmo tamanho.
 *
 * Quebras de linha: o PHP come o "\n" logo depois de um `?>`; onde isso juntava duas linhas
 * na saída, o template abaixo também as junta, para a saída bater linha a linha com o PHP.
 */
import { creatureImg, icon, img, mapGlyph, media, url } from '../../core/helpers';
import { html, join, raw, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { mapStage } from '../partials/map-stage';

/** (int) do PHP. */
const int = (v: unknown): number => Math.trunc(Number(v)) || 0;

/** empty() do PHP. */
const vazio = (v: unknown): boolean =>
  v === null || v === undefined || v === false || v === 0 || v === '' || v === '0' || (Array.isArray(v) && v.length === 0);

/** (array)($x ?? []) — objeto/array ou vazio. */
const arr = (v: unknown): Dict => (v && typeof v === 'object' ? (v as Dict) : {});
const lst = (v: unknown): any[] => (Array.isArray(v) ? v : v && typeof v === 'object' ? Object.values(v) : []); // eslint-disable-line @typescript-eslint/no-explicit-any

/** As cores dos grupos no catálogo são nomes; aqui viram o r,g,b que o CSS usa em rgba(). */
const RGB: Record<string, string> = {
  red: '229,72,77',
  amber: '245,166,35',
  purple: '168,85,247',
  lime: '155,224,74',
  cyan: '62,201,240',
};

/** <picture> webp + jpg de assets/img — toda a arte do jogo existe nos dois formatos. */
function pic(base: string, alt = '', cls = '', extra = 'loading="lazy" decoding="async"'): SafeHtml {
  return html`<picture${cls !== '' ? html` class="${cls}"` : ''}><source srcset="${img(base + '.webp')}" type="image/webp"><img src="${img(base + '.jpg')}" alt="${alt}" ${raw(extra)}></picture>`;
}

export function landing(s: Scope): SafeHtml {
  const d = s.data;
  const hero = arr(d.hero);
  const queda = arr(d.queda);
  const mist = arr(queda.misterio);
  const btxt = arr(d.bestiario);
  const surv = arr(d.sobreviver);
  const contam = arr(surv.contaminacao);
  const estados = arr(d.estados);
  const extr = lst(d.extracoes) as Dict[];
  const mundo = arr(d.mundo);
  const sist = arr(d.sistemas);
  const facs = lst(d.faccoes) as Dict[];
  const ftxt = arr(d.faccoes_txt);
  const gal = arr(d.galeria);
  const fin = arr(d.final);
  const foot = arr(d.rodape);
  const region = arr(d.region);
  const rstate: Dict = region.state && typeof region.state === 'object' ? region.state : {};
  const event: Dict | null = region.event && typeof region.event === 'object' ? region.event : null;
  const cont = arr(d.contagens);
  const markers = lst(d.markers) as Dict[];
  const cap: Dict = arr(rstate.capacity ?? { total: 20, hunters: 12, mercenaries: 8 });
  const inf = int(rstate.infestation ?? 0);

  /* ---- bestiário: tudo do catálogo canônico (data/criaturas) ---- */
  const cat = arr(d.criaturas);
  const grupos: Record<string, Dict> = arr(cat.grupos);
  const lista = lst(cat.lista).filter((c) => c && typeof c === 'object') as Dict[];
  const abord: Record<string, Dict> = arr(cat.abordagens);
  const semArte = String(btxt.sem_arte ?? 'REGISTRO VISUAL PENDENTE');

  const gcor = (g: string): string => RGB[String(grupos[g]?.cor ?? 'red')] ?? RGB.red!;

  /** Como lidar com a criatura: a abordagem dela (nome, cor, ícone, texto). */
  const comoLidar = (c: Dict): Dict =>
    arr(abord[String(c.abordagem ?? '')] ?? { nome: '', cor: 'lime', ms: 'help', texto: '' });
  const porGrupo: Record<string, number> = {};
  for (const c of lista) {
    const g = String(c.grupo ?? '');
    porGrupo[g] = (porGrupo[g] ?? 0) + 1;
  }

  /**
   * Quadro da arte do enredo. Com arquivo: a imagem. Sem arquivo: um quadro de arquivo vazio
   * no MESMO tamanho. É <span> porque também vive dentro de <button>.
   */
  const quadro = (slug: string, nome: string, icone: string, cls = '', sub = ''): SafeHtml => {
    const src = creatureImg(slug);
    if (src !== null) {
      return html`<span class="ph ph--img ${cls}"><img src="${src}" alt="${nome}" loading="lazy" decoding="async"></span>`;
    }
    return html`<span class="ph ${cls}" role="img" aria-label="${nome}"><span class="ph__cross" aria-hidden="true"></span><span class="ph__body" aria-hidden="true">${icon(icone, 'ph__ic')}<b class="ph__nome">${nome}</b><small class="ph__sub">${sub !== '' ? sub : semArte}</small></span></span>`;
  };
  const entrar = url('criar-conta');
  const login = url('login');

  // Contaminação: a faixa em que o valor inicial cai.
  const faixas = lst(contam.faixas) as unknown[][];
  const ini = Math.max(0, Math.min(100, int(contam.inicial ?? 0)));
  let fAt: unknown[] = [];
  for (const f of faixas) {
    if (ini >= int(f[0]) && ini <= int(f[1])) {
      fAt = f;
      break;
    }
  }

  // Camadas do mapa que têm pontos.
  const usadas: Record<string, boolean> = {};
  for (const m of markers) usadas[String(m.layer ?? '')] = true;

  const arte = arr(queda.arte);
  const cl = arr(mist.classificado);
  const carte = arr(mist.arte);
  let n = 0;

  return html`
<div class="hudframe" aria-hidden="true">
  <span class="hudframe__corner hudframe__corner--tl"></span>
  <span class="hudframe__corner hudframe__corner--tr"></span>
  <span class="hudframe__corner hudframe__corner--bl"></span>
  <span class="hudframe__corner hudframe__corner--br"></span>
  <span class="hudframe__scan"></span>
  <span class="hudframe__grain"></span>
</div>

<!-- ======================================================================= TOPO -->
<header class="top" data-top>
  <a class="top__brand" href="#inicio">
    <img class="top__emblem" src="${img('logo/emblem.png')}" alt="" width="34" height="34">
    <span class="top__name">EXTRACT<b>HUNTERS</b></span>
  </a>

  <nav class="top__nav" aria-label="Seções">
${join(lst(d.nav).map(([href, rot]) => html`    <a href="${href}">${rot}</a>
`))}  </nav>

  <span class="top__state" data-state-badge>
    <i class="top__dot"></i><b data-state-name>HUNTER</b><small data-state-sub>RECRUTA</small>
  </span>

  <a class="btn btn--ghost top__login" href="${login}">ENTRAR</a>
  <a class="btn btn--primary top__cta" href="${entrar}">${hero.cta ?? 'ENTRAR NA OPERAÇÃO'}</a>
</header>

<!-- ======================================================================= HERO -->
<section class="hero" id="inicio" data-hero>
  <div class="hero__art" data-parallax>
    ${pic('bg/home-hero-wide', '', '', 'fetchpriority="high" decoding="async"')}  </div>
  <span class="hero__veil" aria-hidden="true"></span>
  <span class="hero__grid" aria-hidden="true"></span>

  <div class="hero__in">

    <div class="hero__left" data-depth="18">
      <p class="hero__kicker">${hero.kicker ?? ''}</p>
      <div class="hero__op">
        ${pic(String(hero.retrato ?? 'portraits/op-assault'), 'Operador do Hunter Command', 'hero__oppic', 'fetchpriority="high" decoding="async"')}        <span class="hero__opfade" aria-hidden="true"></span>
      </div>
      <p class="hero__coords mono">
        ${join(lst(hero.coords).map((c) => html`<span>${c}</span>`))}        <em><i class="led"></i>SINAL ESTÁVEL</em>
      </p>
    </div>

    <div class="hero__mid">
      <p class="boot" data-boot>
        <span class="boot__led"></span>
        <span class="boot__txt" data-boot-txt>${hero.boot ?? ''}</span>
      </p>

      <h1 class="hero__title"><span>EXTRACT</span><b>HUNTERS</b></h1>
      <p class="hero__tag">${hero.tagline ?? ''}</p>
      <p class="hero__lead">${hero.lead ?? ''}</p>

      <div class="hero__cta">
        <a class="btn btn--ghost btn--lg" href="#mundo">${icon('travel_explore')}${hero.cta2 ?? ''}</a>
        <a class="btn btn--primary btn--lg" href="${entrar}">${hero.cta ?? ''}${icon('arrow_forward')}</a>
      </div>

      <ul class="hero__stats">
        <li><b data-count="${int(cap.total ?? 20)}">${int(cap.total ?? 20)}</b><span>OPERADORES POR SETOR</span></li>
        <li><b data-count="${int(cont.regioes ?? 0)}">${int(cont.regioes ?? 0)}</b><span>REGIÕES</span></li>
        <li><b data-count="${int(cont.pontos ?? 0)}">${int(cont.pontos ?? 0)}</b><span>PONTOS NO MAPA</span></li>
        <li><b data-count="${lista.length}">${lista.length}</b><span>CRIATURAS NO BESTIÁRIO</span></li>
      </ul>
    </div>

    <aside class="tele" data-depth="-12" aria-label="${hero.telemetria_titulo ?? 'Telemetria'}">
      <header class="tele__head">
        <span class="tele__t">${hero.telemetria_titulo ?? 'TELEMETRIA'}</span>
        <span class="tele__sector mono">${hero.setor ?? ''}</span>
      </header>

      <div class="tele__map" data-tele-map style="background-image:url('${media(String(d.map_image ?? 'bg/map-world.webp'))}')">
        <span class="tele__ret" data-tele-ret aria-hidden="true"></span>
        <span class="tele__sweep" aria-hidden="true"></span>
        <b class="tele__region">${region.name ?? ''}</b>
      </div>

      <dl class="tele__rows">
        <div><dt>MUNDO</dt><dd class="accent">PERSISTENTE</dd></div>
        <div><dt>CAPACIDADE</dt><dd><span class="mono">${int(cap.total ?? 20)}</span> <small>${int(cap.hunters ?? 12)} HUNTERS · ATÉ ${int(cap.mercenaries ?? 8)} MERC.</small></dd></div>
        <div><dt>INFESTAÇÃO</dt><dd><span class="meter meter--red" style="--v:${inf}%"><i></i></span><small class="mono">${inf}%</small></dd></div>
        <div><dt>VEYRA</dt><dd>${rstate.veyra_activity ?? '—'}</dd></div>
        <div><dt>ATIV. HUMANA</dt><dd>${rstate.human_activity ?? '—'}</dd></div>
${!vazio(rstate.weather) ? html`        <div><dt>CLIMA</dt><dd>${rstate.weather}</dd></div>
` : ''}${event ? html`        <div class="tele__event"><dt>${event.ends_label ?? 'TERMINA EM'}</dt>
          <dd><b>${event.name ?? ''}</b> <span class="mono" data-countdown="${event.ends_in ?? ''}">${event.ends_in ?? ''}</span></dd></div>
` : ''}      </dl>

      <ul class="tele__vert" aria-hidden="true">
        ${join(lst(hero.verticais).map((v) => html`<li>${v}</li>`))}      </ul>
    </aside>
  </div>

  <div class="ticker" aria-hidden="true">
    <div class="ticker__run" data-ticker>
${join(
  [0, 1].map(() =>
    join(
      lst(d.regions).map((r: Dict) => {
        const st = arr(r.state);
        return html`      <span class="ticker__i"><b>${r.name}</b>
        <em>INFESTAÇÃO ${int(st.infestation ?? 0)}%</em>
        <em>${st.sector_state ?? 'ATIVO'}</em>
        ${!vazio(st.weather) ? html`<em>${st.weather}</em>` : ''}      </span>
`;
      }),
    ),
  ),
)}    </div>
  </div>
</section>

<!-- ==================================================================== PILARES -->
<section class="pillars" aria-label="O que é o jogo">
  <ul class="wrap pillars__in">
${join(
  lst(d.pilares).map(
    ([ic, t, txt], i) => html`    <li data-rev style="--i:${i}">
      <span class="pillars__ic">${icon(String(ic))}</span>
      <b>${t}</b><span>${txt}</span>
    </li>
`,
  ),
)}  </ul>
</section>

<!-- ======================================================================= QUEDA -->
<section class="queda" id="queda">
  <div class="wrap">
    <div class="queda__head">
      <div>
        <p class="kicker" data-rev>${queda.kicker ?? ''}</p>
        <h2 class="h2 h2--xl" data-rev>${queda.titulo ?? 'A QUEDA'}</h2>
      </div>
      <blockquote class="quote quote--sm" data-rev>“${queda.citacao ?? ''}”</blockquote>
    </div>

    <div class="queda__top">
      <figure class="frame" data-rev>
        ${quadro(String(arte.slug ?? 'a-queda'), 'A QUEDA', String(arte.icone ?? 'flare'), 'ph--wide')}        <figcaption class="frame__cap mono">${arte.legenda ?? ''}</figcaption>
      </figure>
      <div class="queda__txt" data-rev>
        <p>${queda.texto ?? ''}</p>
        <h3 class="queda__ft">${queda.fungo_titulo ?? ''}</h3>
        <p class="queda__fp">${queda.fungo_texto ?? ''}</p>
      </div>
    </div>

    <ol class="tl" aria-label="Linha do tempo">
${join(
  lst(queda.linha).map(
    ([quando, tt, tx, marca], i) => html`      <li class="tl__i${marca !== '' ? ' tl__i--' + String(marca ?? '') : ''}" style="--i:${i}" data-rev>
        <span class="tl__dot" aria-hidden="true"></span>
        <b class="tl__when mono">${quando}</b>
        <h3 class="tl__t">${tt}</h3>
        <p class="tl__p">${tx}</p>
        ${marca === 'agora' ? html`<em class="tl__agora"><i class="led"></i>${queda.agora ?? ''}</em>` : ''}      </li>
`,
  ),
)}    </ol>

    <ul class="fungo">
${join(
  lst(queda.fungo).map(
    ([ic, ft, fx], i) => html`      <li style="--i:${i}" data-rev>
        <span class="fungo__ic">${icon(String(ic))}</span>
        <b>${ft}</b>
        <span class="fungo__tx">${fx}</span>
      </li>
`,
  ),
)}    </ul>
  </div>

  <div class="mist">
    <div class="wrap mist__grid">
      <div class="mist__txt">
        <p class="kicker" data-rev>${mist.kicker ?? ''}</p>
        <h2 class="h2" data-rev>${mist.titulo ?? ''}</h2>
        <blockquote class="quote quote--sm" data-rev>${mist.quote ?? ''}</blockquote>

        <div class="arqs">
${join(
  lst(mist.arquivos).map(
    ([ic, at, ar, ax]) => html`          <article class="arq" data-rev>
            <p class="arq__r mono">${icon(String(ic))}${ar}</p>
            <h3>${at}</h3>
            <p>${ax}</p>
          </article>
`,
  ),
)}
          <details class="arq arq--x" data-rev>
            <summary>
              <span class="arq__r mono">${icon('lock')}${cl.rotulo ?? ''}</span>
              <span class="arq__bar" aria-hidden="true"></span>
              <span class="arq__abrir">${cl.abrir ?? 'ABRIR'}${icon('expand_more')}</span>
            </summary>
            <h3>${cl.titulo ?? ''}</h3>
            <p>${cl.texto ?? ''}</p>
          </details>
        </div>

        <p class="mist__q" data-rev>${mist.pergunta ?? ''}</p>
      </div>

      <div class="mist__side">
        <figure class="frame" data-rev>
          ${quadro(String(carte.slug ?? 'cratera'), 'A CRATERA', String(carte.icone ?? 'crisis_alert'), 'ph--wide')}          <figcaption class="frame__cap mono">${carte.legenda ?? ''}</figcaption>
        </figure>
        <div class="sig" data-rev>
          <span class="sig__lbl mono">ICARUS · MICÉLIO · BANDA 3 · <b data-sig-freq>17.4</b> Hz</span>
          <canvas class="sig__wave" data-wave width="900" height="120" aria-hidden="true"></canvas>
          <span class="sig__lbl sig__lbl--r mono">PADRÃO REPETIDO · COLÔNIAS A <b>41 km</b> · CONVERGÊNCIA: <b>CRATERA</b></span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- =================================================================== CRIATURAS -->
<section class="cri" id="criaturas">
  <div class="wrap">
    <div class="cri__head">
      <div>
        <p class="kicker" data-rev>${btxt.kicker ?? ''}</p>
        <h2 class="h2" data-rev>${btxt.titulo ?? ''}</h2>
      </div>
      <blockquote class="quote quote--sm" data-rev>“${btxt.citacao ?? ''}”</blockquote>
    </div>
    <p class="sub" data-rev>${btxt.texto ?? ''}</p>

    <div class="bestiary" data-bestiary>
      <div class="cgroups" role="group" aria-label="Grupos de criaturas" data-rev>
        <button class="cgroup is-on" type="button" data-grupo="" aria-pressed="true">
          ${icon('select_all')}<span>${btxt.todos ?? 'TODAS'}</span><b class="mono">${lista.length}</b></button>
${join(
  Object.entries(grupos).map(
    ([gid, g]) => html`        <button class="cgroup" type="button" data-grupo="${gid}" aria-pressed="false" style="--gc:${gcor(gid)}">
          ${icon(String(g.ms ?? 'coronavirus'))}<span>${g.nome ?? ''}</span><b class="mono">${int(porGrupo[gid] ?? 0)}</b></button>
`,
  ),
)}      </div>
${join(
  Object.entries(grupos).map(
    ([gid, g]) => html`      <p class="cgroup__txt" data-grupo-txt="${gid}" style="--gc:${gcor(gid)}" hidden>
        ${icon(String(g.ms ?? 'coronavirus'))}<span>${g.texto ?? ''}</span></p>
`,
  ),
)}
      <p class="lidar" data-rev>
        <span class="lidar__t">${btxt.legenda ?? 'COMO LIDAR'}</span>
${join(
  Object.values(abord).map(
    (a) => html`        <span class="lidar__i" title="${a.texto}"><span class="verdict verdict--${a.cor}">${icon(String(a.ms))}${a.nome}</span><small>${a.curto ?? ''}</small></span>
`,
  ),
)}      </p>

      <div class="carr" data-rev>
        <button class="carr__nav carr__nav--prev" type="button" data-carr="-1" aria-label="${btxt.anterior ?? 'Anteriores'}">${icon('chevron_left')}</button>
        <div class="carr__track" role="tablist" aria-label="Criaturas" data-carr-track>
${join(
  lista.map((c, i) => {
    const slug = String(c.slug);
    const gid = String(c.grupo ?? '');
    const a = comoLidar(c);
    return html`          <button class="beast${i === 0 ? ' is-on' : ''}${(c.abordagem ?? '') === 'fuja' ? ' beast--x' : ''}" type="button" role="tab"
                  aria-selected="${i === 0 ? 'true' : 'false'}" data-beast="${slug}" data-grupo="${gid}"
                  style="--gc:${gcor(gid)}">
            <span class="beast__img">${quadro(slug, String(c.nome), String(grupos[gid]?.ms ?? 'coronavirus'), 'ph--carta')}</span>
            <span class="beast__verd verdict verdict--${a.cor}">${icon(String(a.ms))}${a.nome}</span>
            <span class="beast__txt">
              <em>${c.papel ?? ''}</em>
              <b>${c.nome}</b>
            </span>
          </button>
`;
  }),
)}        </div>
        <button class="carr__nav carr__nav--next" type="button" data-carr="1" aria-label="${btxt.proxima ?? 'Próximas'}">${icon('chevron_right')}</button>
      </div>

      <div class="bestiary__view">
${join(
  lista.map((c, i) => {
    const slug = String(c.slug);
    const gid = String(c.grupo ?? '');
    const a = comoLidar(c);
    const gms = String(grupos[gid]?.ms ?? 'coronavirus');
    return html`        <article class="beastview${i === 0 ? ' is-on' : ''}" data-beast-view="${slug}" data-grupo="${gid}"
                 style="--gc:${gcor(gid)}" ${i === 0 ? '' : raw('hidden')}>
          <div class="beastview__art">
            ${quadro(slug, String(c.nome), gms, 'ph--retrato')}            <span class="beastview__tag">${c.papel ?? ''}</span>
            <span class="beastview__porte mono">${icon('straighten')}${c.porte ?? ''}</span>
          </div>
          <div class="beastview__txt">
            <p class="beastview__grupo">${icon(gms)}${grupos[gid]?.nome ?? ''}</p>
            <h3>${c.nome}</h3>
            <p class="beastview__line">${c.linha ?? ''}</p>

            <p class="beastview__lidar beastview__lidar--${a.cor}">
              ${icon(String(a.ms))}<b>${a.nome}</b><span>${a.texto}</span></p>

            <p>${c.texto ?? ''}</p>

            <dl class="beastview__facts">
              <div><dt>${icon('hearing')}SINAL</dt><dd>${c.sinal ?? ''}</dd></div>
              <div><dt>${icon('shield')}CONTRA</dt><dd>${c.contra ?? ''}</dd></div>
              <div><dt>${icon('coronavirus')}CONTÁGIO</dt><dd>${c.contagio ?? ''}</dd></div>
              <div><dt>${icon('warning')}AMEAÇA</dt>
                <dd><span class="meter meter--red" style="--v:${int(c.ameaca ?? 0)}%"><i></i></span><b class="mono">${int(c.ameaca ?? 0)}%</b></dd></div>
            </dl>

            <p class="beastview__nota">${icon('menu_book')}${btxt.ficha_nota ?? ''}</p>
          </div>
        </article>
`;
  }),
)}      </div>
    </div>
  </div>
</section>

<!-- ================================================================== SOBREVIVER -->
<section class="surv" id="sobreviver">
  <div class="wrap">
    <div class="surv__head">
      <div>
        <p class="kicker" data-rev>${surv.kicker ?? ''}</p>
        <h2 class="h2" data-rev>${surv.titulo ?? ''}</h2>
      </div>
      <blockquote class="quote quote--sm" data-rev>“${surv.citacao ?? ''}”</blockquote>
    </div>

    <div class="duo">
${join(
  ['solo', 'equipe'].map((lado, k) => {
    const dd = arr(surv[lado]);
    return html`${k === 1 ? html`      <div class="duo__mid" aria-hidden="true"><span class="mono">1</span><i></i><b>${surv.unidade ?? ''}</b><i></i><span class="mono">4</span></div>
` : ''}      <article class="duo__c duo__c--${lado}" data-rev>
        <header class="duo__h"><span class="duo__ic">${icon(String(dd.icone ?? 'person'))}</span><h3>${dd.titulo ?? ''}</h3></header>
        <p class="duo__p">${dd.texto ?? ''}</p>
        <ul class="rules">
${join(lst(dd.itens).map(([rk, rv]) => html`          <li><b>${rk}</b><span>${rv}</span></li>
`))}        </ul>
      </article>
`;
  }),
)}    </div>

    <ul class="ritmo">
${join(
  lst(surv.regras).map(
    ([ic, rt, rx], i) => html`      <li style="--i:${i}" data-rev>${icon(String(ic))}<b>${rt}</b><span>${rx}</span></li>
`,
  ),
)}    </ul>

    <div class="contam" data-contam data-cor="${fAt[5] ?? 'lime'}" data-rev>
      <div class="contam__head">
        <div>
          <p class="kicker">${contam.kicker ?? ''}</p>
          <h3 class="contam__t">${contam.titulo ?? ''}</h3>
          <p class="contam__p">${contam.texto ?? ''}</p>
        </div>
        <div class="contam__read" aria-live="polite">
          <b class="mono" data-contam-n>${ini}</b>
          <span data-contam-estado>${fAt[2] ?? ''}</span>
        </div>
      </div>

      <label class="contam__ctl" data-contam-ctl hidden>
        <span class="sr-only">${contam.rotulo ?? 'Contaminação'}</span>
        <input type="range" min="0" max="100" step="1" value="${ini}" data-contam-range style="--v:${ini}%">
        <span class="contam__scale mono" aria-hidden="true"><i>0</i><i>25</i><i>50</i><i>75</i><i>100</i></span>
      </label>

      <ol class="cfx">
${join(
  faixas.map(([de, ate, est, acon, trat, cor]) => {
    const on = ini >= Number(de) && ini <= Number(ate);
    return html`        <li class="cf cf--${cor}${on ? ' is-on' : ''}${int(de) === 100 ? ' cf--fim' : ''}" data-de="${int(de)}" data-ate="${int(ate)}" data-cor="${cor}" data-estado="${est}">
          <span class="cf__r mono">${int(de) === int(ate) ? int(de) : int(de) + '–' + int(ate)}</span>
          <b class="cf__e">${est}</b>
${int(de) === 100 ? html`          <span class="cf__thumb">${quadro('errante', 'ERRANTE', 'coronavirus', 'ph--mini', 'ERRANTE')}</span>
` : ''}          <p class="cf__a">${acon}</p>
          <p class="cf__tr">${icon('medical_services')}<span>${trat}</span></p>
        </li>
`;
  }),
)}      </ol>
    </div>
  </div>
</section>

<!-- ===================================================================== ESTADOS -->
<section class="states" id="estados">
  <div class="wrap states__grid">
    <div class="states__intro">
      <p class="kicker" data-rev>ESCOLHA SEU CAMINHO</p>
      <h2 class="h2" data-rev>TRÊS ESTADOS<br>OPERACIONAIS</h2>
      <p class="sub" data-rev>
        Não são personagens diferentes: é o mesmo operador, com o mesmo inventário e a mesma
        contaminação no sangue. Escolha um e veja o mundo mudar de lado — quem reconhece a sua
        autoridade, quem te protege, quem te caça e por onde você sai.
      </p>
      <p class="states__now" data-rev>ESTADO ATUAL · <b data-state-name>HUNTER</b></p>
    </div>

    <div class="states__cards" role="tablist" aria-label="Estados operacionais">
${join(
  Object.entries(estados).map(
    ([id, st]: [string, Dict]) => html`      <button class="scard${id === 'hunter' ? ' is-on' : ''}" type="button" role="tab"
              id="tab-${id}" aria-controls="painel-${id}"
              aria-selected="${id === 'hunter' ? 'true' : 'false'}"
              data-state="${id}" data-cor="${st.cor}" data-selo="${st.selo}"
              style="--cor:${st.cor};--i:${n++}" data-rev>
        <span class="scard__img">${pic(String(st.img), '', '')}</span>
        <span class="scard__num mono">${st.num}</span>
        <span class="scard__txt">
          <b>${st.nome}</b>
          <small>${st.titulo}</small>
          <span>${st.linha}</span>
        </span>
        <i class="scard__bar" aria-hidden="true"></i>
      </button>
`,
  ),
)}    </div>
  </div>

  <div class="wrap">
${join(
  Object.entries(estados).map(
    ([id, st]: [string, Dict]) => html`    <div class="spanel${id === 'hunter' ? ' is-on' : ''}" id="painel-${id}"
         role="tabpanel" aria-labelledby="tab-${id}" data-state-panel="${id}"
         ${id === 'hunter' ? '' : raw('hidden')}>
      <div class="spanel__txt">
        <p class="spanel__body">${st.texto}</p>
        <ul class="rules">
${join(lst(st.regras).map(([rk, rv]) => html`          <li><b>${rk}</b><span>${rv}</span></li>
`))}        </ul>
      </div>

      <aside class="exits">
        <p class="exits__t">ROTAS DE SAÍDA NESTE ESTADO</p>
        <ul>
${join(
  extr.map((x) => {
    const ok = !vazio(arr(st.extracoes)[String(x.id)]);
    return html`          <li class="${ok ? 'is-open' : 'is-shut'}" data-exit="${x.id}">
            <i aria-hidden="true"></i>
            <span><b>${x.nome}</b><small>${x.sub}</small></span>
            <em>${ok ? 'DISPONÍVEL' : 'BLOQUEADA'}</em>
          </li>
`;
  }),
)}        </ul>
      </aside>
    </div>
`,
  ),
)}  </div>
</section>

<!-- ======================================================================= MUNDO -->
<section class="world" id="mundo">
  <div class="wrap world__grid">
    <div class="world__side">
      <p class="kicker" data-rev>${mundo.kicker ?? 'EXPLORE'}</p>
      <h2 class="h2" data-rev>${mundo.titulo ?? 'A ILHA'}</h2>
      <p class="sub" data-rev>${mundo.texto ?? ''}</p>

      <a class="btn btn--primary" href="${entrar}" data-rev>${mundo.cta ?? 'EXPLORAR O MAPA'}${icon('arrow_forward')}</a>

      <ul class="world__stats" data-rev>
        <li>${icon('landscape')}<b class="mono">${int(cont.regioes ?? 0)}</b><span>REGIÕES PERSISTENTES</span></li>
        <li>${icon('flag')}<b class="mono">${int(cont.bases ?? 0)}</b><span>BASES HUNTER COMMAND</span></li>
        <li>${icon('flight_takeoff')}<b class="mono">${int(cont.extracoes ?? 0)}</b><span>PONTOS DE EXTRAÇÃO</span></li>
        <li>${icon('location_on')}<b class="mono">${int(cont.pontos ?? 0)}</b><span>PONTOS DE INTERESSE</span></li>
        <li>${icon('groups')}<b class="mono">${int(cap.total ?? 20)}</b><span>OPERADORES POR SETOR</span></li>
      </ul>

      <p class="world__hint" data-rev>${icon('mouse')}${mundo.dica ?? ''}</p>
    </div>

    <div class="world__box" data-rev>
      <div class="world__filters" data-map-filters role="group" aria-label="Camadas do mapa">
        <button class="chip mlayer is-active" type="button" data-value="todos" aria-pressed="true">
          ${icon('done_all')}${d.legend_all ?? 'TODOS'}</button>
${join(
  (lst(d.legend) as Dict[])
    .filter((l) => usadas[String(l.layer ?? '')]) // só camadas com pontos
    .map(
      (l) => html`        <button class="chip mlayer chip--${l.color ?? 'lime'}" type="button"
                data-value="${l.layer}" aria-pressed="false">
          ${mapGlyph(String(l.glyph), 'chip__gl')}${l.label}</button>
`,
    ),
)}      </div>

      <div class="page-mapa world__stage">
        <div class="mapa__map">
${mapStage(s, { markers, opacidade_form: false })}        </div>
      </div>

      <div class="world__foot">
        <span>${icon('layers')}<b data-map-visible>${markers.length}</b> pontos visíveis</span>
        <span class="world__foot-r">REGIÃO · <b>${region.name ?? ''}</b></span>
      </div>
    </div>
  </div>
</section>

<!-- ==================================================================== SISTEMAS -->
<section class="sys" id="gameplay">
  <div class="wrap">
    <div class="sys__head">
      <div>
        <p class="kicker" data-rev>${sist.kicker ?? ''}</p>
        <h2 class="h2" data-rev>${sist.titulo ?? ''}</h2>
      </div>
      <p class="sub sub--tight" data-rev>${sist.texto ?? ''}</p>
    </div>

    <div class="sysgrid" data-sys>
${join(
  lst(sist.itens).map(
    ([ic, t, sub, txt, im], i) => html`      <article class="syscard${i === 0 ? ' is-on' : ''}" tabindex="0" data-sys-card style="--i:${i}" data-rev>
        ${pic(String(im), '', 'syscard__bg')}        <span class="syscard__shade" aria-hidden="true"></span>
        <span class="syscard__ic">${icon(String(ic))}</span>
        <h3 class="syscard__t">${t}</h3>
        <div class="syscard__body">
          <p class="syscard__sub">${sub}</p>
          <p class="syscard__txt">${txt}</p>
        </div>
        <span class="syscard__n mono">0${i + 1}</span>
      </article>
`,
  ),
)}    </div>
  </div>
</section>

<!-- ===================================================================== FACÇÕES -->
<section class="fac" id="faccoes">
  <div class="wrap">
    <div class="fac__head">
      <div>
        <p class="kicker" data-rev>${ftxt.kicker ?? ''}</p>
        <h2 class="h2" data-rev>${ftxt.titulo ?? ''} <span class="h2__sub">${ftxt.sub ?? ''}</span></h2>
      </div>
      <p class="sub sub--tight" data-rev>${ftxt.texto ?? ''}</p>
    </div>

    <div class="fac__grid" data-fac>
${join(
  facs.map(
    (f) => html`      <article class="faction" data-faction="${f.id}" style="--fc:${f.cor}" data-rev>
        ${pic(String(f.img), '', 'faction__bg')}        <span class="faction__shade" aria-hidden="true"></span>
        <header class="faction__head">
          <img src="${img('emblemas/' + String(f.id) + '.webp')}" alt="" width="64" height="64" loading="lazy">
          <div><h3>${f.nome}</h3><p>${f.lema}</p></div>
        </header>
        <p class="faction__frase">“${f.frase}”</p>
        <p class="faction__origin">${f.origem}</p>
        <ul class="faction__list">
${join(lst(f.itens).map(([k, vtxt]) => html`          <li><b>${k}</b><span>${vtxt}</span></li>
`))}        </ul>
        <div class="faction__rep">
          <span>REPUTAÇÃO</span>
          <span class="meter" style="--v:0%"><i data-rep-bar></i></span>
          <b class="mono" data-rep>0</b>
          <em data-rep-tier>SEM CONTRATO</em>
        </div>
        <button class="btn btn--primary faction__sign" type="button" data-sign="${f.id}"
                data-l-assinar="${ftxt.assinar ?? 'ASSINAR CONTRATO'}"
                data-l-assinado="${ftxt.assinado ?? 'CONTRATO ATIVO'}">${ftxt.assinar ?? 'ASSINAR CONTRATO'}</button>
        <span class="faction__blocked" aria-hidden="true">IFF REVOGADO · REPUTAÇÃO ZERADA</span>
      </article>
`,
  ),
)}    </div>

    <p class="fac__aviso" data-fac-aviso hidden>
      ${icon('warning')}${ftxt.aviso ?? ''}      <button class="btn btn--ghost btn--sm" type="button" data-unsign>${ftxt.romper ?? 'ROMPER CONTRATO'}</button>
    </p>
  </div>
</section>

<!-- ===================================================================== GALERIA -->
<section class="gallery" id="galeria">
  <div class="wrap">
    <div class="gallery__head">
      <p class="kicker" data-rev>${gal.kicker ?? 'GALERIA'}</p>
      <h2 class="h2 h2--sm" data-rev>${gal.titulo ?? ''}</h2>
    </div>
    <div class="gallery__grid" data-gallery>
${join(
  lst(gal.itens).map(
    ([im, leg], i) => html`      <a class="shot" href="${img(String(im) + '.jpg')}" data-shot="${img(String(im) + '.webp')}" data-legenda="${leg}" style="--i:${i}" data-rev>
        ${pic(String(im), String(leg ?? ''))}        <span class="shot__leg">${leg}</span>
      </a>
`,
  ),
)}    </div>
  </div>

  <dialog class="lightbox" data-lightbox aria-label="Imagem ampliada">
    <button class="lightbox__x" type="button" data-lb-close aria-label="Fechar">${icon('close')}</button>
    <button class="lightbox__nav lightbox__nav--l" type="button" data-lb-prev aria-label="Anterior">${icon('chevron_left')}</button>
    <figure>
      <img src="" alt="" data-lb-img>
      <figcaption class="mono" data-lb-cap></figcaption>
    </figure>
    <button class="lightbox__nav lightbox__nav--r" type="button" data-lb-next aria-label="Próxima">${icon('chevron_right')}</button>
  </dialog>
</section>

<!-- ========================================================================= CTA -->
<section class="final">
  <div class="final__art">${pic('bg/jogar-banner', '')}</div>
  <span class="final__veil" aria-hidden="true"></span>
  <div class="wrap final__in">
    <p class="kicker" data-rev>${fin.kicker ?? ''}</p>
    <h2 class="final__h" data-rev>${fin.titulo ?? ''}</h2>
    <p class="final__p" data-rev>${fin.texto ?? ''}</p>
    <div class="final__cta" data-rev>
      <a class="btn btn--primary btn--lg" href="${entrar}">${fin.cta ?? ''}${icon('arrow_forward')}</a>
      <a class="btn btn--ghost btn--lg" href="${login}">${fin.cta2 ?? ''}</a>
    </div>
  </div>
  <p class="final__side" aria-hidden="true">${fin.lateral ?? ''}</p>
</section>

<footer class="foot">
  <div class="wrap foot__in">
    <a class="foot__brand" href="#inicio">
      <img src="${img('logo/emblem.png')}" alt="" width="28" height="28">
      <span>EXTRACT<b>HUNTERS</b></span>
    </a>
    <p class="foot__tag">${foot.tagline ?? ''}</p>
    <nav class="foot__nav" aria-label="Conta">
      <a href="${login}">ENTRAR</a>
      <a href="${entrar}">CRIAR CONTA</a>
      <a href="${url('recuperar-senha')}">RECUPERAR SENHA</a>
    </nav>
    <p class="foot__dim">${foot.canon ?? ''}</p>
  </div>
</footer>`; // sem "\n" final: o layout já põe um depois do conteúdo (no PHP o `?>` o comia)
}
