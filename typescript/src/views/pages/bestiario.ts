/**
 * Tela BESTIÁRIO — app/Views/pages/bestiario.php (criaturas do enredo v6 + registro do jogador).
 * Todas as fichas são renderizadas e o JS só alterna a visível. O que o jogador não viu
 * aparece como silhueta e "???" — o texto nem chega ao HTML.
 */
import { icon, url } from '../../core/helpers';
import { e, html, join, raw, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';

export function bestiario(s: Scope): SafeHtml {
  const d = s.data;
  const grupos: Record<string, Dict> = d.grupos ?? {};
  const grupo = String(d.grupo ?? 'todas');
  const porGrupo: Record<string, number> = d.por_grupo ?? {};
  const lista = (d.lista ?? []) as Dict[];
  const niveis: Record<string, Dict> = d.niveis ?? {};
  const regioes: Record<string, string> = d.regioes ?? {};
  const sel = String(d.selecionada ?? '');
  const total = Number(d.total ?? 0);
  const feitas = Number(d.registradas ?? 0);
  const pct = total > 0 ? Math.floor((100 * feitas) / total) : 0;

  /** Quadro do retrato: a arte, ou o estado vazio com o ícone do grupo. */
  const arte = (c: Dict, cls: string): SafeHtml => {
    const g: Dict = grupos[String(c.grupo)] ?? {};
    if (c.nivel === 'desconhecido') {
      return raw(
        `<div class="${cls} bst-art bst-art--oculta">` +
          (c.img ? `<img src="${e(c.img)}" alt="" loading="lazy" decoding="async">` : '') +
          icon('help', 'bst-art__ms').value +
          '</div>',
      );
    }
    if (c.img) {
      return raw(`<div class="${cls} bst-art"><img src="${e(c.img)}" alt="${e(String(c.nome))}" loading="lazy" decoding="async"></div>`);
    }
    return raw(
      `<div class="${cls} bst-art bst-art--vazia">` +
        icon(String(g.ms ?? 'pest_control'), 'bst-art__ms').value +
        '<span class="bst-art__txt">SEM REGISTRO VISUAL</span></div>',
    );
  };

  const todasOn = grupo === 'todas';
  const somaGrupos = Object.values(porGrupo).reduce((a, b) => a + Number(b), 0);

  return html`<header class="page-head bst-head">
  <h1 class="page-title">BESTIÁRIO</h1>
  <div class="bst-prog" role="group" aria-label="Criaturas registradas">
    <span class="bst-prog__k">REGISTRADAS</span>
    <span class="bst-prog__v num">${feitas}<i>/</i>${total}</span>
    <span class="bst-prog__bar" aria-hidden="true"><i style="width:${raw(String(pct))}%"></i></span>
  </div>
</header>

<div class="bst">

  <!-- ====================== GRUPOS ====================== -->
  <section class="panel bst-side" data-boot>
    <h2 class="bst-side__title">CLASSIFICAÇÃO ICARUS</h2>
    <nav class="bst-groups" aria-label="Grupos de criaturas">
      <a class="bst-group${todasOn ? ' is-active' : ''}" href="${url('bestiario')}"${todasOn ? raw(' aria-current="page"') : ''}>
        ${icon('menu_book', 'bst-group__ms')}
        <span class="bst-group__label">TODAS</span>
        <span class="bst-group__n num">${somaGrupos}</span>
      </a>
${join(
  Object.entries(grupos).map(([slug, g]) => {
    const on = grupo === slug;
    return html`      <a class="bst-group bst-group--${String(g.cor)}${on ? ' is-active' : ''}" href="${url('bestiario/' + slug)}"${on ? raw(' aria-current="page"') : ''}>
        ${icon(String(g.ms), 'bst-group__ms')}
        <span class="bst-group__label">${String(g.nome)}</span>
        <span class="bst-group__n num">${porGrupo[slug] ?? 0}</span>
      </a>
`;
  }),
)}    </nav>

${grupo !== 'todas' && grupos[grupo]
  ? html`    <p class="bst-side__lead">${String(grupos[grupo]!.texto)}</p>
`
  : raw(`    <p class="bst-side__lead">Tudo o que o Instituto Icarus catalogou desde a Queda. Cada criatura tem um sinal que se lê antes do contato: aprenda o sinal e você escolhe a briga.</p>
`)}
    <div class="bst-levels">
      <h3 class="bst-levels__title">REGISTRO</h3>
${join(
  Object.entries(niveis).map(
    ([k, n]) => html`      <div class="bst-level bst-level--${k}">
        <span class="bst-level__dot" aria-hidden="true"></span>
        <span class="bst-level__k">${String(n.label)}</span>
        <span class="bst-level__v">${String(n.texto)}</span>
      </div>
`,
  ),
)}${d.liberar ? html`      <p class="bst-levels__note">${icon('lock_open', 'bst-levels__ms')}Arquivo aberto: todas as fichas liberadas para leitura do enredo.</p>
` : ''}    </div>
  </section>

  <!-- ====================== GRADE ====================== -->
  <section class="bst-grid${lista.length <= 4 ? ' bst-grid--poucas' : ''}" aria-label="Criaturas">
${join(
  lista.map((c) => {
    const oculta = c.nivel === 'desconhecido';
    const g: Dict = grupos[String(c.grupo)] ?? {};
    const on = c.slug === sel;
    return html`    <button type="button" class="bst-card bst-card--${String(g.cor ?? 'muted')}${on ? ' is-selected' : ''}${oculta ? ' is-oculta' : ''}"
            data-criatura="${String(c.slug)}" aria-pressed="${on ? 'true' : 'false'}">
      ${arte(c, 'bst-card__art')}
      <span class="bst-card__threat num" aria-label="Ameaça">${oculta ? '??' : String(c.ameaca)}</span>
      <span class="bst-card__foot">
        <span class="bst-card__name">${oculta ? '???' : String(c.nome)}</span>
        <span class="bst-card__role">${oculta ? String(g.nome ?? '') : String(c.papel)}</span>
      </span>
      <span class="bst-card__lvl bst-card__lvl--${String(c.nivel)}">${String(niveis[c.nivel]?.label ?? '')}</span>
    </button>
`;
  }),
)}  </section>

  <!-- ====================== FICHA ====================== -->
  <aside class="panel bst-det" aria-live="polite">
${join(lista.map((c) => ficha(c, grupos, regioes, sel, Number(d.estudo_abates ?? 5), arte)))}  </aside>
</div>
`;
}

function ficha(
  c: Dict,
  grupos: Record<string, Dict>,
  regioes: Record<string, string>,
  sel: string,
  estudoAbates: number,
  arte: (c: Dict, cls: string) => SafeHtml,
): SafeHtml {
  const oculta = c.nivel === 'desconhecido';
  const completa = c.nivel === 'estudado';
  const g: Dict = grupos[String(c.grupo)] ?? {};
  const on = c.slug === sel;
  const r: Dict = c.registro ?? {};
  const lidar: Dict = c.lidar ?? {};

  const corpo = oculta
    ? html`      <p class="bst-sheet__locked">${icon('visibility_off')}Nenhum registro desta criatura. Avistar uma em campo libera a imagem e a descrição; abater ${estudoAbates} ou entregar uma amostra ao Icarus libera a ficha completa.</p>
`
    : html`
      <div class="bst-threat" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${String(c.ameaca)}" aria-label="Ameaça">
        <span class="bst-threat__k">AMEAÇA</span>
        <span class="bst-threat__bar" aria-hidden="true"><i style="width:${raw(String(Number(c.ameaca) | 0))}%"></i></span>
        <span class="bst-threat__v num">${String(c.ameaca)}</span>
      </div>

      <p class="bst-lidar bst-lidar--${String(lidar.cor)}">
        ${icon(String(lidar.ms))}<b>${String(lidar.nome)}</b><span>${String(lidar.texto)}</span></p>

      <p class="bst-sheet__line">${String(c.linha)}</p>
      <p class="bst-sheet__text">${String(c.texto)}</p>

${completa
  ? html`      <dl class="bst-facts">
        <div class="bst-fact bst-fact--sinal"><dt>${icon('hearing')}SINAL</dt><dd>${String(c.sinal)}</dd></div>
        <div class="bst-fact bst-fact--contra"><dt>${icon('strategy')}CONTRA</dt><dd>${String(c.contra)}</dd></div>
        <div class="bst-fact"><dt>${icon('my_location')}FRAQUEZA</dt><dd>${String(c.fraqueza)}</dd></div>
        <div class="bst-fact"><dt>${icon('coronavirus')}CONTÁGIO</dt><dd>${String(c.contagio)}</dd></div>
      </dl>

      <h3 class="bst-sub">HABITAT</h3>
      <ul class="bst-habitat">
${join(((c.habitat ?? []) as string[]).map((h) => html`        <li>${regioes[h] ?? String(h).replace(/-/g, ' ').toUpperCase()}</li>
`))}      </ul>
`
  : html`      <p class="bst-sheet__locked">${icon('science')}Sinal, contra e fraqueza ainda não estudados. Abata ${estudoAbates} ou entregue uma amostra ao Instituto Icarus.</p>
`}
`;

  return html`    <article class="bst-sheet bst-sheet--${String(g.cor ?? 'muted')}${on ? ' is-on' : ''}" data-ficha="${String(c.slug)}"${on ? '' : raw(' hidden')}>
      ${arte(c, 'bst-sheet__art')}

      <header class="bst-sheet__head">
        <span class="bst-sheet__group">${icon(String(g.ms ?? 'pest_control'), 'bst-sheet__gms')}${String(g.nome ?? '')}</span>
        <h2 class="bst-sheet__name">${oculta ? '???' : String(c.nome)}</h2>
${!oculta ? html`        <div class="bst-sheet__meta">
          <span>${String(c.papel)}</span>
          <span>${String(c.porte)}</span>
        </div>
` : ''}      </header>

${corpo}
      <footer class="bst-rec">
        <span><b class="num">${String(r.sightings ?? 0)}</b>AVISTAMENTOS</span>
        <span><b class="num">${String(r.kills ?? 0)}</b>ABATES</span>
        <span><b>${r.studied ? 'SIM' : 'NÃO'}</b>AMOSTRA ICARUS</span>
      </footer>
    </article>
`;
}
