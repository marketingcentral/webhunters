/**
 * Tela ADMIN · DASHBOARD (/admin) — app/Views/pages/admin-dashboard.php. Layout 'admin'.
 *
 * A view NÃO consulta o banco e NÃO calcula métrica: tudo chega pronto de repo/admin-repo.ts
 * via controllers/admin/dashboard-controller.ts. Aqui só há geometria (os SVG dos gráficos,
 * montados no servidor porque a CSP não deixa entrar biblioteca de gráfico) e formatação.
 * Toda métrica mostra a sua procedência (selo .dash-src com a frase inteira no title).
 */
import { icon, img, media, num, url } from '../../core/helpers';
import { e, html, join, raw, type SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { agoraUnix, formatarData } from '../../repo/admin-repo';

/** sprintf só com %s, na ordem. */
function sprintf(fmt: string, ...args: string[]): string {
  let i = 0;
  return fmt.replace(/%s/g, () => args[i++] ?? '');
}

const p2 = (n: number) => String(Math.trunc(n)).padStart(2, '0');
/** round($x, 2) impresso como o PHP imprime float (sem zeros à direita). */
const r2 = (x: number) => String(Math.round(x * 100) / 100);
const soLetras = (v: unknown, def = 'lime') => String(v ?? '').replace(/[^a-z]/g, '') || def;

// ------------------------------------------------------------------- formatadores

/** Dinheiro a partir de centavos: R$ 1.234,56 */
const dinheiro = (centavos: number) => 'R$ ' + num(centavos / 100, 2);

/** Segundos -> "02h 45m" (ou "45m 10s"). */
function duracao(seg: number): string {
  const h = Math.floor(seg / 3600);
  const m = Math.floor((seg % 3600) / 60);
  return h > 0 ? `${p2(h)}h ${p2(m)}m` : `${p2(m)}m ${p2(seg % 60)}s`;
}

/** "11d 19h" — o contador curto da lista de eventos. */
function intervaloCurto(seg: number): string {
  seg = Math.max(0, seg);
  const d = Math.floor(seg / 86400);
  const h = Math.floor((seg % 86400) / 3600);
  const m = Math.floor((seg % 3600) / 60);
  if (d > 0) return `${d}d ${p2(h)}h`;
  return h > 0 ? `${p2(h)}h ${p2(m)}m` : `${p2(m)}m`;
}

/** "5d 12h 34m". */
function intervalo(seg: number): string {
  seg = Math.max(0, seg);
  const d = Math.floor(seg / 86400);
  const h = Math.floor((seg % 86400) / 3600);
  const m = Math.floor((seg % 3600) / 60);
  if (d > 0) return `${d}d ${p2(h)}h ${p2(m)}m`;
  return h > 0 ? `${p2(h)}h ${p2(m)}m` : `${p2(m)}m`;
}

/** "há 5 min" / "há 2 h" / "há 3 dias". */
function desde(ts: number, agora: number): string {
  const s = Math.max(0, agora - ts);
  if (s < 60) return 'agora';
  if (s < 3600) return 'há ' + Math.floor(s / 60) + ' min';
  if (s < 86400) return 'há ' + Math.floor(s / 3600) + ' h';
  const d = Math.floor(s / 86400);
  return 'há ' + d + (d === 1 ? ' dia' : ' dias');
}

function valorKpi(kpi: Dict): string {
  const v = kpi.valor ?? null;
  if (v === null) return '—';
  switch (String(kpi.formato ?? 'inteiro')) {
    case 'dinheiro':
      return dinheiro(Math.trunc(Number(v)));
    case 'duracao':
      return duracao(Math.trunc(Number(v)));
    default:
      return num(Math.trunc(Number(v)));
  }
}

// ---------------------------------------------------------------------- gráficos

/** Topo "redondo" de um eixo (313 → 400). */
function escala(max: number, divisoes = 4): { topo: number; passo: number } {
  if (max <= 0) return { topo: divisoes, passo: 1 };
  const bruto = max / divisoes;
  const ordem = 10 ** Math.floor(Math.log10(Math.max(1.0, bruto)));
  for (const m of [1, 2, 2.5, 5, 10]) {
    const passo = Math.round(m * ordem);
    if (passo > 0 && passo * divisoes >= max) return { topo: passo * divisoes, passo };
  }
  return { topo: max, passo: Math.ceil(max / divisoes) };
}

/** Gráfico de linha com área (viewBox 640×250). */
function graficoLinha(pontos: Dict[]): string {
  if (pontos.length === 0) return '';
  const W = 640, H = 250, ml = 46, mr = 10, mt = 12, mb = 28;
  const iw = W - ml - mr;
  const ih = H - mt - mb;
  const valores = pontos.map((p) => Number(p.valor) | 0);
  let { topo, passo } = escala(Math.max(...valores));
  topo = Math.max(1, topo);
  const n = pontos.length;
  const x = (i: number) => (n === 1 ? ml + iw / 2 : ml + (iw * i) / (n - 1));
  const y = (v: number) => mt + ih - ih * Math.min(1.0, v / topo);

  let svg = `<svg class="dash-chart" viewBox="0 0 ${W} ${H}" role="img" aria-label="Pico de jogadores simultâneos por dia">`;
  for (let v = 0; v <= topo; v += passo) {
    const py = Math.round(y(v) * 100) / 100;
    svg +=
      `<line class="dash-chart__grid" x1="${ml}" y1="${py}" x2="${W - mr}" y2="${py}"/>` +
      `<text class="dash-chart__tick" x="${ml - 8}" y="${py + 4}" text-anchor="end">${e(num(v))}</text>`;
  }
  const linha = pontos.map((p, i) => r2(x(i)) + ',' + r2(y(Number(p.valor) | 0)));
  const base = r2(mt + ih);
  svg += `<polygon class="dash-chart__area" points="${r2(x(0))},${base} ${linha.join(' ')} ${r2(x(n - 1))},${base}"/>`;
  svg += `<polyline class="dash-chart__line" points="${linha.join(' ')}"/>`;
  const salto = Math.max(1, Math.ceil(n / 8));
  pontos.forEach((p, i) => {
    const px = r2(x(i));
    svg +=
      `<circle class="dash-chart__dot" cx="${px}" cy="${r2(y(Number(p.valor) | 0))}" r="3.5">` +
      `<title>${e(p.rotulo + ' · ' + num(Number(p.valor) | 0) + ' jogadores')}</title></circle>`;
    if (i % salto === 0 || i === n - 1) {
      svg += `<text class="dash-chart__tick" x="${px}" y="${H - 9}" text-anchor="middle">${e(p.rotulo)}</text>`;
    }
  });
  return svg + '</svg>';
}

/** Barras da receita (viewBox 340×200, mesma proporção do cartão). */
function graficoBarras(barras: Dict[], maxCent: number): string {
  if (barras.length === 0) return '';
  const W = 340, H = 200, ml = 46, mr = 6, mt = 10, mb = 22;
  const iw = W - ml - mr;
  const ih = H - mt - mb;
  const { passo: passoReais } = escala(Math.ceil(maxCent / 100), 3);
  const passo = Math.max(1, passoReais) * 100;
  const topo = Math.max(passo, Math.ceil(Math.max(1, maxCent) / passo) * passo);
  const dominio = topo * 1.05; // 5% de respiro acima da barra mais alta
  const n = barras.length;
  const celula = iw / n;
  const larg = Math.max(2.0, celula * 0.78);

  let svg = `<svg class="dash-chart dash-chart--bars" viewBox="0 0 ${W} ${H}" role="img" aria-label="Receita diária do período">`;
  for (let v = 0; v <= topo; v += passo) {
    const py = Math.round((mt + ih - ih * (v / dominio)) * 100) / 100;
    svg +=
      `<line class="dash-chart__grid" x1="${ml}" y1="${py}" x2="${W - mr}" y2="${py}"/>` +
      `<text class="dash-chart__tick" x="${ml - 6}" y="${py + 4}" text-anchor="end">${e(num(v / 100))}</text>`;
  }
  const salto = Math.max(1, Math.ceil(n / 4));
  barras.forEach((b, i) => {
    const cent = Math.trunc(Number(b.centavos));
    const altura = ih * Math.min(1.0, cent / dominio);
    const bx = Math.round((ml + celula * i + (celula - larg) / 2) * 100) / 100;
    const by = r2(mt + ih - altura);
    svg +=
      `<rect class="dash-chart__bar" x="${bx}" y="${by}" width="${r2(larg)}" height="${r2(Math.max(1.0, altura))}" rx="1.5">` +
      `<title>${e(b.rotulo + ' · ' + dinheiro(cent))}</title></rect>`;
    if (i % salto === 0 || i === n - 1) {
      svg += `<text class="dash-chart__tick" x="${r2(bx + larg / 2)}" y="${H - 6}" text-anchor="middle">${e(b.rotulo)}</text>`;
    }
  });
  return svg + '</svg>';
}

/** Anel de progresso; com `segmentos`, cada fatia sai com a cor do seu estado. */
function anel(fracao: number, texto: string, tom: string, segmentos: Dict[] = []): string {
  const r = 32.0;
  const circ = 2 * Math.PI * r;
  let arcos = '';
  if (segmentos.length) {
    const vao = segmentos.length > 1 ? 0.014 : 0.0;
    let giro = -90.0;
    for (const seg of segmentos) {
      const f = Math.max(0.0, Math.min(1.0, Number(seg.fracao ?? 0)));
      if (f <= 0.0) continue;
      const comp = Math.max(0.0, f - vao) * circ;
      arcos +=
        `<circle class="dash-ring__arc dash-ring__arc--seg dash-ring__arc--${e(soLetras(seg.tom))}" cx="40" cy="40" r="${r}" ` +
        `stroke-dasharray="${r2(comp)} ${r2(circ)}" transform="rotate(${r2(giro)} 40 40)"/>`;
      giro += f * 360;
    }
  } else {
    const frac = Math.max(0.0, Math.min(1.0, fracao));
    // Zero MEDIDO tem um arco mínimo (3°): "está vivo e marca zero", não "sem dado".
    const zerado = frac <= 0.0;
    const comp = zerado ? circ * (3 / 360) : circ * frac;
    arcos =
      `<circle class="dash-ring__arc${zerado ? ' dash-ring__arc--zero' : ''}" cx="40" cy="40" r="${r}" ` +
      `stroke-dasharray="${r2(comp)} ${r2(circ)}" transform="rotate(-90 40 40)"/>`;
  }
  const vazio = segmentos.length === 0 && fracao <= 0.0;
  return (
    `<svg class="dash-ring dash-ring--${e(tom)}" viewBox="0 0 80 80" role="img" aria-label="${e(texto)}">` +
    `<circle class="dash-ring__track${vazio ? ' dash-ring__track--zero' : ''}" cx="40" cy="40" r="${r}"/>` +
    arcos +
    `<text class="dash-ring__txt" x="40" y="45" text-anchor="middle">${e(texto)}</text>` +
    '</svg>'
  );
}

// -------------------------------------------------------------------------- a tela

export function adminDashboard(s: Scope): SafeHtml {
  const d = s.data;
  const cromo = (d.cromo && typeof d.cromo === 'object' ? d.cromo : {}) as Dict;
  const titulos = (cromo.titulos ?? {}) as Dict;
  const links = (cromo.links ?? {}) as Dict;
  const estados = (cromo.estados ?? {}) as Dict;
  const fontes = (cromo.fontes ?? {}) as Dict;
  const vazios = (cromo.vazios ?? {}) as Dict;
  const micro = (cromo.micro ?? {}) as Dict;

  const conta = (d.admin_conta ?? {}) as Dict;
  const nome = String(conta.username ?? 'operador');

  const kpis = (d.kpis ?? []) as Dict[];
  const serie = (d.serie ?? {}) as Dict;
  const servidores = (d.servidores ?? {}) as Dict;
  const ranking = (d.ranking ?? []) as Dict[];
  const receita = (d.receita ?? {}) as Dict;
  const eventos = (d.eventos ?? []) as Dict[];
  const atividade = (d.atividade ?? []) as Dict[];
  const sistema = (d.sistema ?? {}) as Dict;
  const acoes = (d.acoes ?? []) as Dict[];
  const dias = Number(d.dias ?? 7) | 0;
  const agora = agoraUnix();

  /* Quantas posições o ranking sempre mostra quando JÁ HÁ progresso. */
  const POSICOES = 4;
  const rankingSemProgresso = ranking.length > 0 && ranking.reduce((a, j) => a + (Number(j.nivel) | 0) + (Number(j.xp) | 0), 0) === 0;

  const qualificador = (fonte: unknown): string => String(((micro.qualificador ?? {}) as Dict)[String(fonte ?? '')] ?? '');

  /** Link "ver tudo": rota registrada vira <a>; rota que não existe não desenha nada. */
  const cardLink = (lk: Dict): SafeHtml => {
    const label = String(lk?.label ?? '');
    if (label === '' || !lk.disponivel) return raw('');
    return html`<a class="adm-card__link" href="${url(String(lk.rota))}">${label}${icon('arrow_forward')}</a>`;
  };

  /** Selo de procedência (frase inteira no title). */
  const selo = (fonte: unknown, extra = ''): SafeHtml => {
    const f = String(fonte ?? '');
    if (f === '' || !fontes[f]) return raw('');
    const titulo = String(fontes[f].texto) + (extra !== '' ? '\n' + extra : '');
    return html`<span class="dash-src dash-src--${f}" title="${titulo}">${String(fontes[f].selo)}</span>`;
  };

  /* Atalhos: só o que tem rota — e em número PAR (a grade é de duas colunas). */
  const acoesVivas = acoes.filter((a) => a.disponivel);
  if (acoesVivas.length % 2 === 1) acoesVivas.pop();

  const hero = (cromo.boas_vindas ?? {}) as Dict;

  // ---------------------------------------------------------------- faixa de KPIs
  const heroKpis = join(
    kpis.map((kpi) => {
      const tom = soLetras(kpi.tom);
      const delta = kpi.delta ?? null;
      const deltaHtml =
        delta !== null
          ? html`<span class="adm-kpi__delta ${Number(delta) >= 0 ? 'is-up' : 'is-down'}">
                    ${icon(Number(delta) >= 0 ? 'trending_up' : 'trending_down')}
                    ${num(Math.abs(Number(delta)) * 100, 1)}%
                    <small>${String(kpi.delta_rotulo ?? '')}</small>
                  </span>`
          : html`<span class="adm-kpi__delta is-void" title="${String(micro.sem_base ?? '')}">
                    ${icon('remove')}
                  </span>`;
      return html`
            <article class="adm-kpi adm-kpi--${tom} dash-kpi">
              <span class="adm-kpi__ico">${icon(String(kpi.icone ?? 'monitoring'))}</span>
              <span class="adm-kpi__txt">
                <span class="dash-kpi__top">
                  <span class="adm-kpi__label" title="${String(kpi.rotulo ?? '')}">${String(kpi.rotulo ?? '')}</span>
                  ${selo(kpi.fonte, String(kpi.nota ?? kpi.origem ?? ''))}
                </span>
                <span class="adm-kpi__value num">
                  ${valorKpi(kpi)}
                  ${kpi.sub ? html`<small>${String(kpi.sub)}</small>` : ''}
                </span>
                ${deltaHtml}
              </span>
            </article>`;
    }),
  );

  // ---------------------------------------------------------- gráfico de linha
  const qSerie = qualificador(serie.fonte);
  const subSerie = (qSerie !== '' ? qSerie + ' · ' : '') + sprintf(String(micro.media ?? 'Média: %s'), num(Number(serie.media ?? 0) | 0));
  const periodos = join(
    ((cromo.periodos ?? []) as Dict[]).map((per) => {
      const on = (Number(per.dias) | 0) === dias;
      return html`
                <a class="dash-seg__op${on ? ' is-active' : ''}"
                   href="${url('admin', { dias: String(per.dias) })}"${on ? raw(' aria-current="page"') : ''}>
                  ${String(per.label)}
                </a>`;
    }),
  );
  const pontos = (serie.pontos ?? []) as Dict[];

  // -------------------------------------------------------------- servidores
  const regioes = (servidores.regioes ?? []) as Dict[];
  const qSrv = qualificador(servidores.fonte);
  const estCurto = (cromo.estados_regiao ?? {}) as Dict;
  const listaRegioes = join(
    regioes.map(
      (r) => html`
                <li class="dash-srv__row dash-srv__row--${String(r.estado)}"
                    title="${String(r.label) + ' · ' + num(Number(r.jogadores) | 0) + ' jogadores · ' + r.ping + ' ms' + (r.nota ? ' · ' + r.nota : '')}">
                  <span class="dash-srv__dot" aria-hidden="true"></span>
                  <span class="dash-srv__name">
                    ${String(r.label)}
                    ${r.nota ? html`<small>${String(r.nota)}</small>` : ''}
                  </span>
                  <span class="dash-srv__state">${String(estCurto[String(r.estado)] ?? estados[String(r.estado)] ?? r.estado_rotulo)}</span>
                  <span class="dash-srv__count num">${num(Number(r.jogadores) | 0)}</span>
                  <span class="dash-srv__ping num">${r.ping + ' ms'}</span>
                  <span class="dash-srv__bar" style="--v:${String(Math.round(Number(r.ocupacao) * 100))}%"
                        title="${String(micro.ocupacao ?? '')}"></span>
                </li>`,
    ),
  );
  const servicos = (servidores.servicos ?? []) as Dict[];
  let blocoServicos: SafeHtml = raw('');
  if (servicos.length) {
    const foraDoPadrao = servicos.filter((sv) => (sv.estado ?? '') !== 'online').length;
    const verSrv = cardLink((links.servidores ?? {}) as Dict);
    blocoServicos = html`
          <ul class="adm-card__foot dash-svc">${servicos.map(
            (sv) => html`
              <li class="dash-svc__chip dash-svc__chip--${String(sv.estado)}"
                  title="${String(sv.label) + ' · ' + String(sv.rotulo)}">
                <span class="dash-svc__dot" aria-hidden="true"></span>
                <span>${String(sv.label)}</span>
              </li>`,
          )}
          </ul>${
            foraDoPadrao > 0 || verSrv.value !== ''
              ? html`
            <p class="adm-card__foot dash-svc__legenda">
              ${foraDoPadrao > 0 ? html`<span>${sprintf(String(micro.servicos_fora ?? '%s de %s serviços fora do padrão'), num(foraDoPadrao), num(servicos.length))}</span>` : ''}
              ${verSrv}
            </p>`
              : ''
          }`;
  }

  // ------------------------------------------------------------------- ranking
  let corpoRanking: SafeHtml;
  const ava = (j: Dict, tam: number) =>
    j.avatar !== ''
      ? html`<img src="${media(String(j.avatar))}" alt="" width="${tam}" height="${tam}" loading="lazy" decoding="async">`
      : raw('<span class="adm-table__ava"></span>');
  if (ranking.length === 0) {
    corpoRanking = html`<div class="adm-empty">${icon('military_tech')}<p class="adm-empty__txt">${String(vazios.ranking ?? '')}</p></div>`;
  } else if (rankingSemProgresso) {
    const n = ranking.length;
    const contas = (micro.contas ?? ['%s conta criada', '%s contas criadas']) as string[];
    const frase = sprintf(String(micro.sem_progresso ?? '%s'), sprintf(String(contas[n === 1 ? 0 : 1] ?? '%s contas criadas'), num(n)));
    corpoRanking = html`<div class="dash-rank__none">
              <p class="dash-rank__none-txt">${icon('military_tech')}<span>${frase}</span></p>
              <ul class="dash-rank__contas">${ranking.map(
                (j) => html`
                  <li>
                    <span class="adm-table__who">
                      ${ava(j, 24)}
                      <span><strong>${String(j.nome)}</strong>${j.patente !== '' ? html`<small>${String(j.patente)}</small>` : ''}</span>
                    </span>
                  </li>`,
              )}
              </ul>
            </div>`;
  } else {
    const vagas: SafeHtml[] = [];
    for (let p = ranking.length + 1; p <= POSICOES; p++) {
      vagas.push(html`
                    <tr class="dash-rank__vaga">
                      <td class="tc"><span class="dash-pos">${String(p)}</span></td>
                      <td><span class="adm-table__who"><span class="adm-table__ava"></span><span>${String(micro.vaga ?? 'Vaga em aberto')}</span></span></td>
                      <td class="tr num">—</td>
                      <td class="tr num">—</td>
                    </tr>`);
    }
    corpoRanking = html`<div class="adm-tablewrap">
              <table class="adm-table adm-table--compact dash-rank">
                <thead><tr><th class="tc">#</th><th>Jogador</th><th class="tr">Nível</th><th class="tr">XP</th></tr></thead>
                <tbody>${ranking.map(
                  (j) => html`
                    <tr>
                      <td class="tc"><span class="dash-pos dash-pos--${String(Math.min(4, Number(j.posicao) | 0))}">${String(j.posicao)}</span></td>
                      <td>
                        <span class="adm-table__who">
                          ${ava(j, 32)}
                          <span><strong>${String(j.nome)}</strong><small>${String(j.patente)}</small></span>
                        </span>
                      </td>
                      <td class="tr num">${num(Number(j.nivel) | 0)}</td>
                      <td class="tr num">${num(Number(j.xp) | 0)}</td>
                    </tr>`,
                )}${vagas}
                </tbody>
              </table>
            </div>`;
  }

  // ------------------------------------------------------------------- receita
  const cp = (receita.compras ?? { n: 0, creditos: 0 }) as Dict;
  const dr = receita.delta ?? null;
  const qRec = qualificador(receita.fonte);
  const notaRec = (qRec !== '' ? qRec + ' · ' : '') + (Number(receita.dias ?? 30) | 0) + ' dias';
  const barras = (receita.barras ?? []) as Dict[];

  // ------------------------------------------------------------------- eventos
  const listaEventos = join(
    eventos.map((ev) => {
      const est = String(ev.estado);
      let prazo: string, prazoCheio: string;
      if (est === 'agendado') {
        prazo = intervaloCurto(Number(ev.inicio) - agora);
        prazoCheio = 'Começa em: ' + intervalo(Number(ev.inicio) - agora);
      } else if (est === 'encerrado') {
        prazo = desde(Number(ev.fim), agora);
        prazoCheio = 'Terminou ' + desde(Number(ev.fim), agora);
      } else {
        prazo = intervaloCurto(Number(ev.fim) - agora);
        prazoCheio = 'Termina em: ' + intervalo(Number(ev.fim) - agora);
      }
      return html`
                <li class="dash-evt__row dash-evt__row--${est}">
                  ${
                    ev.imagem
                      ? html`<img class="dash-evt__thumb" src="${media(String(ev.imagem))}" alt="" width="72" height="52" loading="lazy" decoding="async">`
                      : html`<span class="dash-evt__thumb dash-evt__thumb--ph">${icon('event')}</span>`
                  }
                  <span class="dash-evt__txt">
                    <strong title="${String(ev.titulo)}">${String(ev.titulo)}</strong>
                    <span class="dash-evt__meta">
                      <span class="adm-badge adm-badge--${est === 'ativo' ? 'ok' : est === 'agendado' ? 'info' : 'muted'}">
                        ${String(estados[est] ?? est)}
                      </span>
                      <em title="${prazoCheio}">${prazo}</em>
                    </span>
                  </span>
                </li>`;
    }),
  );
  const verEvt = cardLink((links.eventos ?? {}) as Dict);

  // --------------------------------------------------------------- coluna direita
  const atalhos = join(
    acoesVivas.map((a) => {
      // "Atualizar" é o próprio painel: a janela do gráfico viaja junto.
      const rota = String(a.rota ?? '');
      const href = rota === 'admin' ? url('admin', { dias: String(dias) }) : url(rota);
      return html`
            <a class="dash-act" href="${href}">
              ${icon(String(a.icone ?? 'chevron_right'), 'dash-act__ico')}
              <span class="dash-act__txt">
                <strong>${String(a.label ?? '')}</strong>
                <small>${String(a.sub ?? '')}</small>
              </span>
            </a>`;
    }),
  );
  const jogo = (micro.jogo ?? {}) as Dict;

  const feed = join(
    atividade.map((ev) => {
      const vezes = Math.max(1, Number(ev.repeticoes ?? 1) | 0);
      return html`
              <li class="dash-feed__row dash-feed__row--${soLetras(ev.tom)}">
                ${icon(String(ev.icone), 'dash-feed__ico')}
                <span class="dash-feed__txt">
                  <span class="dash-feed__msg" title="${String(ev.texto)}">${vezes > 1 ? html`<b class="dash-feed__n">${vezes + '×'}</b>` : ''}${String(ev.texto)}</span>
                  ${ev.detalhe ? html`<small>${String(ev.detalhe)}</small>` : ''}
                </span>
                <time class="dash-feed__when" datetime="${formatarData(Number(ev.quando), 'c')}">${desde(Number(ev.quando), agora)}</time>
              </li>`;
    }),
  );

  const aneis = join(
    ((sistema.aneis ?? []) as Dict[]).map((an) => {
      const segs = (an.segmentos ?? []) as Dict[];
      const tituloAnel = 'Origem: ' + String(an.origem) + (Number(an.fracao) <= 0.0 && segs.length === 0 ? '\n' + String(micro.ocupacao_zero ?? '') : '');
      return html`
            <div class="dash-rings__cell" title="${tituloAnel}">
              ${raw(anel(Number(an.fracao), String(an.texto), soLetras(an.tom), segs))}
              <span class="dash-rings__lbl">${String(an.rotulo)}</span>
            </div>`;
    }),
  );
  const rodapeSis = join(
    ((sistema.rodape ?? []) as Dict[]).map(
      (rp) => html`
          <div class="dash-sys__cell" title="${String(rp.origem)}">
            <span class="dash-sys__lbl">${String(rp.rotulo)}</span>
            <span class="dash-sys__val num${rp.valor === null ? ' is-void' : ''}">
              ${rp.valor === null ? '—' : String(rp.valor)}
            </span>
            ${selo(rp.fonte, String(rp.origem))}
          </div>`,
    ),
  );

  return html`<div class="dash">

  <!-- ============================================================ COLUNA PRINCIPAL -->
  <div class="dash__main">

    <!-- ---------------------------------------------- faixa de boas-vindas + 4 KPIs -->
    <section class="dash-hero" style="background-image:url('${img(String(hero.imagem ?? 'bg/admin.jpg'))}')">
      <div class="dash-hero__mask">
        <h2 class="dash-hero__title">
          ${sprintf(String(hero.saudacao ?? 'Bem-vindo(a), %s'), nome)}
          ${icon('verified', 'dash-hero__seal')}
        </h2>

        <div class="dash-hero__kpis">${heroKpis}
        </div>
      </div>
    </section>

    <!-- ------------------------------------------- gráfico de linha + servidores -->
    <div class="dash-row dash-row--chart">

      <section class="adm-card dash-panel">
        <div class="adm-card__head">
          <h2 class="adm-card__title">
            ${String(titulos.grafico ?? 'Curva de jogadores online')}
            <small>${subSerie}</small>
          </h2>
          <div class="adm-card__tools">
            ${selo(serie.fonte, 'Origem: ' + String(serie.origem ?? ''))}
            <nav class="dash-seg" aria-label="Período do gráfico">${periodos}
            </nav>
          </div>
        </div>
        <div class="adm-card__body dash-panel__plot">
          ${
            pontos.length === 0
              ? html`<div class="adm-empty">${icon('show_chart')}<p class="adm-empty__txt">${String(vazios.grafico ?? '')}</p></div>`
              : raw(graficoLinha(pontos))
          }
        </div>
      </section>

      <section class="adm-card dash-panel">
        <div class="adm-card__head">
          <h2 class="adm-card__title">
            ${String(titulos.servidores ?? 'Status dos servidores')}
            ${qSrv !== '' ? html`<small>${qSrv}</small>` : ''}
          </h2>
          <div class="adm-card__tools">
            ${selo(servidores.fonte, String(servidores.escala ?? ''))}
          </div>
        </div>
        <div class="adm-card__body adm-card__body--flush dash-panel__list">
          ${
            regioes.length === 0
              ? html`<div class="adm-empty">${icon('dns')}<p class="adm-empty__txt">${String(vazios.servidores ?? '')}</p></div>`
              : html`<ul class="dash-srv">${listaRegioes}
            </ul>`
          }
        </div>${blocoServicos}
      </section>
    </div>

    <!-- ------------------------------------- ranking + receita + eventos ativos -->
    <div class="dash-row dash-row--trio">

      <section class="adm-card dash-panel">
        <div class="adm-card__head">
          <h2 class="adm-card__title">${String(titulos.ranking ?? 'Top jogadores')}</h2>
          <div class="adm-card__tools">
            ${selo('vivo', 'Origem: players + users, ordenado por nível')}
            ${cardLink((links.ranking ?? {}) as Dict)}
          </div>
        </div>
        <div class="adm-card__body adm-card__body--flush dash-panel__list dash-panel__list--rank">
          ${corpoRanking}
        </div>
      </section>

      <section class="adm-card dash-panel">
        <div class="adm-card__head">
          <h2 class="adm-card__title">${String(titulos.receita ?? 'Receita')}</h2>
          <div class="adm-card__tools">
            ${selo(receita.fonte, sprintf(String(micro.compras ?? '%s / %s'), num(Number(cp.n) | 0), num(Math.trunc(Number(cp.creditos))) + ' créditos'))}
            ${cardLink((links.receita ?? {}) as Dict)}
          </div>
        </div>
        <div class="adm-card__body dash-rev">
          <p class="dash-rev__top">
            <span class="dash-money num">${dinheiro(Math.trunc(Number(receita.total_centavos ?? 0)))}</span>
            <span class="adm-kpi__delta ${dr === null ? 'is-void' : Number(dr) >= 0 ? 'is-up' : 'is-down'}"
                  ${dr === null ? html`title="${String(micro.sem_base ?? '')}"` : ''}>
              ${dr === null ? icon('remove') : html`${icon(Number(dr) >= 0 ? 'trending_up' : 'trending_down')}
                ${num(Math.abs(Number(dr)) * 100, 1)}%`}
            </span>
          </p>
          <p class="dash-rev__note">${notaRec}</p>
          <div class="dash-rev__plot">
            ${
              barras.length === 0
                ? html`<div class="adm-empty">${icon('bar_chart')}<p class="adm-empty__txt">${String(vazios.receita ?? '')}</p></div>`
                : raw(graficoBarras(barras, Math.trunc(Number(receita.max_centavos ?? 0))))
            }
          </div>
        </div>
      </section>

      <section class="adm-card dash-panel">
        <div class="adm-card__head">
          <h2 class="adm-card__title">${String(titulos.eventos ?? 'Eventos ativos')}</h2>
          <div class="adm-card__tools">
            ${selo('vivo', 'Origem: seasons + maintenance_windows')}
            ${verEvt}
          </div>
        </div>
        <div class="adm-card__body adm-card__body--flush dash-panel__feed dash-panel__feed--evt">
          ${
            eventos.length === 0
              ? html`<div class="adm-empty">${icon('event')}<p class="adm-empty__txt">${String(vazios.eventos ?? '')}</p></div>`
              : html`<ul class="dash-evt">${listaEventos}
            </ul>`
          }
        </div>${verEvt.value !== '' ? html`
        <div class="adm-card__foot dash-evt__foot">${verEvt}</div>` : ''}
      </section>
    </div>
  </div>

  <!-- ================================================================ COLUNA DIREITA -->
  <aside class="dash__side">

    <section class="adm-card">
      <div class="adm-card__head"><h2 class="adm-card__title">${String(titulos.acoes ?? 'Ações rápidas')}</h2></div>
      <div class="adm-card__body">
        <div class="dash-acts">${atalhos}
        </div>${
          jogo.label
            ? html`
        <p class="dash-acts__go">
          <a href="${url(String(jogo.rota ?? 'inicio'))}">
            ${icon('sports_esports')}<span>${String(jogo.label)}</span>
          </a>
        </p>`
            : ''
        }
      </div>
    </section>

    <section class="adm-card dash-panel">
      <div class="adm-card__head">
        <h2 class="adm-card__title">${String(titulos.atividade ?? 'Atividade recente')}</h2>
        <div class="adm-card__tools">
          ${cardLink((links.atividade ?? {}) as Dict)}
        </div>
      </div>
      <div class="adm-card__body adm-card__body--flush dash-panel__feed">
        ${
          atividade.length === 0
            ? html`<div class="adm-empty">${icon('history')}<p class="adm-empty__txt">${String(vazios.atividade ?? '')}</p></div>`
            : html`<ul class="dash-feed">${feed}
          </ul>`
        }
      </div>
    </section>

    <section class="adm-card">
      <div class="adm-card__head"><h2 class="adm-card__title">${String(titulos.sistema ?? 'Status do sistema')}</h2></div>
      <div class="adm-card__body">
        <div class="dash-rings">${aneis}
        </div>
      </div>
      <div class="adm-card__foot dash-sys">${rodapeSis}
      </div>
    </section>
  </aside>
</div>
`;
}
