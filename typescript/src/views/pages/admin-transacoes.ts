/**
 * Tela ADMIN · TRANSAÇÕES (/admin/transacoes) — app/Views/pages/admin-transacoes.php. Layout 'admin'.
 *
 * A view NÃO consulta o banco e NÃO decide recorte: tudo chega pronto de
 * repo/transactions-repo.ts por controllers/admin/transactions-controller.ts. Aqui há
 * geometria (o SVG das barras, montado no servidor porque a CSP não deixa entrar biblioteca
 * de gráfico) e formatação.
 *
 * A REGRA DO LAYOUT: moeda de jogo não soma entre si — o total mora no quadro por moeda, o
 * gráfico tem uma faixa por moeda e a tabela carrega a moeda em toda linha.
 */
import { icon, num, url } from '../../core/helpers';
import { e, html, join, raw, type SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { formatarData, paraUnix } from '../../repo/admin-repo';
import { rotuloMoeda, siglaMoeda, tomMoeda } from '../../repo/transactions-repo';

/** Float impresso como o PHP (precision=14, sem zeros à direita). */
const fl = (n: number): string => String(Number(n.toPrecision(14)));
/** round($x, 2) do PHP. */
const r2 = (x: number): number => Math.round(x * 100) / 100;

/** "04/09/26 00:37" — data que cabe numa coluna. */
function dataHora(sql: string | null): string {
  const t = sql !== null ? paraUnix(sql) : 0;
  return t === 0 ? '—' : formatarData(t, 'd/m/y H:i');
}
/** "04/09/2026 às 00:37:12" — vai no title. */
function dataLonga(sql: string | null): string {
  const t = sql !== null ? paraUnix(sql) : 0;
  return t === 0 ? '' : formatarData(t, 'd/m/Y') + ' às ' + formatarData(t, 'H:i:s');
}

/** Números do eixo do gráfico: curtos. */
function compacto(v: number): string {
  if (Math.abs(v) >= 1000000) return num(v / 1000000, 1) + ' M';
  if (Math.abs(v) >= 10000) return num(v / 1000, 0) + ' k';
  return num(v);
}

/** Passo "redondo" de uma régua com ~div divisões: 1, 2, 2,5 ou 5 vezes uma potência de dez. */
function passoBonito(max: number, div: number): number {
  if (max <= 0) return 1;
  const alvo = max / Math.max(1, div);
  const mag = 10 ** Math.max(0, Math.floor(Math.log10(Math.max(1e-9, alvo))));
  for (const m of [1, 2, 2.5, 5, 10]) {
    const c = mag * m;
    if (alvo <= c) return Math.trunc(Math.max(1, Math.round(c)));
  }
  return Math.trunc(Math.max(1, mag * 10));
}

/** Uma faixa de barras — um balde por dia (ou por semana), na cor da moeda. */
function grafico(pontos: Dict[], max: number, tom: string, sigla: string, rotulo: string): string {
  if (pontos.length === 0) return '';
  const W = 720;
  const H = 176;
  const ml = 58;
  const mr = 8;
  const mt = 10;
  const mb = 24;
  const iw = W - ml - mr;
  const ih = H - mt - mb;

  // O topo fecha no máximo REAL arredondado para cima ao passo.
  const passo = passoBonito(max, 4);
  const topo = Math.max(passo, Math.ceil(Math.max(1, max) / passo) * passo);
  // 6% de respiro: a barra mais alta ocupa ~94% da altura útil.
  const dominio = topo * 1.06;

  const n = pontos.length;
  const cel = iw / n;
  const larg = Math.max(1.5, cel * 0.72);

  let svg = '<svg class="txn-chart" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + e('Movimento diário em ' + rotulo) + '">';

  for (let v = 0; v <= topo; v += passo) {
    const py = r2(mt + ih - ih * (v / dominio));
    svg +=
      '<line class="txn-chart__grid" x1="' + ml + '" y1="' + fl(py) + '" x2="' + (W - mr) + '" y2="' + fl(py) + '"/>' +
      '<text class="txn-chart__tick" x="' + (ml - 6) + '" y="' + fl(py + 4) + '" text-anchor="end">' + e(compacto(v)) + '</text>';
  }

  const salto = Math.trunc(Math.max(1, Math.ceil(n / 7)));
  pontos.forEach((b, i) => {
    const valor = Math.trunc(Number(b.valor));
    const nn = Number(b.n) | 0;
    const altura = ih * Math.min(1.0, valor / dominio);
    const bx = r2(ml + cel * i + (cel - larg) / 2);
    const by = r2(mt + ih - altura);

    // Balde zerado continua existindo como marca de 1px na linha de base.
    const classe = 'txn-chart__bar txn-chart__bar--' + tom + (valor === 0 ? ' is-zero' : '');
    const legenda =
      String(b.titulo) + ' · ' + (valor === 0 ? 'sem movimento' : num(valor) + ' ' + sigla + ' em ' + num(nn) + ' transaç' + (nn === 1 ? 'ão' : 'ões'));

    svg +=
      '<rect class="' + e(classe) + '" x="' + fl(bx) + '" y="' + fl(by) + '" width="' + fl(r2(larg)) +
      '" height="' + fl(r2(Math.max(1.0, altura))) + '" rx="1.5"><title>' + e(legenda) + '</title></rect>';

    if (i % salto === 0 || i === n - 1) {
      svg += '<text class="txn-chart__tick" x="' + fl(r2(bx + larg / 2)) + '" y="' + (H - 6) + '" text-anchor="middle">' + e(b.rotulo) + '</text>';
    }
  });

  return svg + '</svg>';
}

/** Cor do design system -> variante do selo. */
const seloDe = (tom: string) => (tom === 'lime' ? 'ok' : tom === 'cyan' ? 'info' : tom === 'amber' ? 'warn' : 'muted');

export function adminTransacoes(s: Scope): SafeHtml {
  const data = s.data;
  const cromo = (data.cromo && typeof data.cromo === 'object' ? data.cromo : {}) as Dict;
  const titulos = (cromo.titulos ?? {}) as Record<string, string>;
  const colunas = (cromo.colunas ?? {}) as Record<string, string>;
  const rotKpi = (cromo.kpis ?? {}) as Record<string, string>;
  const micro = (cromo.micro ?? {}) as Record<string, string>;
  const vazios = (cromo.vazios ?? {}) as Record<string, string>;
  const acoes = (cromo.acoes ?? {}) as Record<string, string>;

  const filtro = (data.filtro ?? {}) as Dict;
  const f = (filtro.f ?? { de: '', ate: '', moeda: '', busca: '' }) as Record<string, string>;
  const periodo = String(filtro.periodo ?? '30');
  const ord = String(filtro.ord ?? 'data');
  const dir = String(filtro.dir ?? 'desc');
  const bruto = (filtro.bruto ?? { de: '', ate: '' }) as Record<string, string>;
  const erros = (filtro.erros ?? {}) as Record<string, string>;

  const extremos = (data.extremos ?? { n: 0, primeira: null, ultima: null }) as Dict;
  const moedas = (data.moedas ?? []) as string[];
  const totais = (data.totais ?? { transacoes: 0, unidades: 0, jogadores: 0 }) as Dict;
  const porMoeda = (data.moeda_a_moeda ?? []) as Dict[];
  const serie = (data.serie ?? { granularidade: 'dia', moedas: [] }) as Dict;
  const linhas = (data.linhas ?? []) as Dict[];
  const topItens = (data.itens ?? []) as Dict[];
  const topJog = (data.jogadores ?? []) as Dict[];
  const pag = (data.paginacao ?? { pagina: 1, paginas: 1, total: 0, primeiro: 0, ultimo: 0 }) as Dict;

  // Nunca houve compra: a tela inteira vira UMA frase.
  const semLedger = (Number(extremos.n ?? 0) | 0) === 0;

  // ---------------------------------------------------------------------- ligações
  // O período custom viaja pelas datas EFETIVAS.
  const base: Record<string, string | null> = {
    periodo,
    moeda: String(f.moeda),
    q: String(f.busca),
    ord,
    dir,
  };
  if (periodo === 'custom') {
    base.de = String(f.de);
    base.ate = String(f.ate);
  }

  const link = (over: Record<string, string | null> = {}): string => {
    const q: Record<string, string | null> = { ...base, ...over };
    const out: Record<string, string> = {};
    for (const [k, v] of Object.entries(q)) if (v !== '' && v !== null) out[k] = v;
    return url('admin/transacoes', out);
  };

  /** Cabeçalho clicável: mesma coluna inverte a direção, coluna nova começa decrescente. */
  const cab = (chave: string, rotulo: string, classe = ''): SafeHtml => {
    const ativo = ord === chave;
    const prox = ativo && dir === 'desc' ? 'asc' : 'desc';
    const seta = ativo ? (dir === 'desc' ? 'arrow_downward' : 'arrow_upward') : 'unfold_more';
    return raw(
      '<th class="' + e(classe.trim()) + (ativo ? ' is-sorted' : '') + '"' +
        (ativo ? ' aria-sort="' + (dir === 'desc' ? 'descending' : 'ascending') + '"' : '') + '>' +
        '<a class="txn-sort" href="' + e(link({ ord: chave, dir: prox, p: null })) + '"' +
        ' title="' + e(micro.ordenar ?? 'Ordenar') + '">' +
        e(rotulo) + icon(seta, 'txn-sort__ic').value + '</a></th>',
    );
  };

  const granularidade = String(serie.granularidade ?? 'dia');

  if (semLedger) {
    // Livro-caixa vazio. Não é erro, não é filtro: é o jogo que ainda não vendeu nada.
    return html`
<div class="txn">

    <section class="adm-card">
      <div class="adm-empty">
        ${icon('receipt_long')}
        <h2 class="adm-empty__title">${titulos.lista ?? 'Transações'}</h2>
        <p class="adm-empty__txt">${vazios.ledger ?? ''}</p>
      </div>
    </section>

</div>`;
  }

  // ----------------------------------------------------------------------- filtros
  const campoData = (nome: 'de' | 'ate', rotulo: string): SafeHtml => {
    const err = erros[nome];
    const valor = bruto[nome] !== '' ? String(bruto[nome]) : periodo === 'custom' ? String(f[nome]) : '';
    return html`
        <div class="txn-field${err !== undefined ? ' is-invalid' : ''}">
          <label class="adm-label" for="txn-${nome}">${rotulo}</label>
          <input class="adm-input" type="date" id="txn-${nome}" name="${nome}"
                 value="${valor}"
                 ${err !== undefined ? html`aria-invalid="true" aria-describedby="txn-${nome}-erro"` : ''}>
          ${err !== undefined ? html`
            <span class="adm-error" id="txn-${nome}-erro">${icon('error')}${err}</span>` : ''}
        </div>`;
  };

  const filtros = html`
    <section class="adm-card txn-filtros">
      <form class="adm-toolbar txn-form" method="get" action="${url('admin/transacoes')}"
            data-adm-preservar="filtros">
        <input type="hidden" name="ord" value="${ord}">
        <input type="hidden" name="dir" value="${dir}">

        <div class="adm-toolbar__grow">
          <label class="adm-search">
            ${icon('search')}
            <span class="sr-only">Buscar</span>
            <input class="adm-input" type="search" name="q" value="${String(f.busca)}"
                   placeholder="Jogador, item ou nº da transação" maxlength="60">
          </label>
        </div>
${campoData('de', 'De')}
${campoData('ate', 'Até')}

        <div class="txn-field${erros.moeda !== undefined ? ' is-invalid' : ''}">
          <label class="adm-label" for="txn-moeda">Moeda</label>
          <select class="adm-select" id="txn-moeda" name="moeda">
            <option value="">Todas</option>
            ${join(
              moedas.map(
                (m) => html`
              <option value="${String(m)}"${String(f.moeda) === String(m) ? ' selected' : ''}>
                ${rotuloMoeda(String(m))}
              </option>`,
              ),
            )}
          </select>
          ${erros.moeda !== undefined ? html`
            <span class="adm-error">${icon('error')}${erros.moeda}</span>` : ''}
        </div>

        <button class="adm-btn adm-btn--primary" type="submit">
          ${icon('filter_alt')}${acoes.aplicar ?? 'Aplicar'}
        </button>
        <a class="adm-btn adm-btn--ghost" href="${url('admin/transacoes')}">
          ${acoes.limpar ?? 'Limpar'}
        </a>
      </form>

      <div class="txn-bar">
        <nav class="txn-seg" aria-label="Período">
          ${join(
            ((cromo.periodos ?? []) as Dict[]).map((per) => {
              const on = periodo === String(per.chave);
              // O preset NÃO carrega de/ate.
              const q: Record<string, string> = {};
              for (const [k, v] of Object.entries({ periodo: String(per.chave), moeda: String(f.moeda), q: String(f.busca), ord, dir })) {
                if (v !== '') q[k] = v;
              }
              return html`
            <a class="txn-seg__op${on ? ' is-active' : ''}"
               href="${url('admin/transacoes', q)}"${on ? html` aria-current="page"` : ''}>
              ${String(per.label)}
            </a>`;
            }),
          )}
          ${periodo === 'custom' ? html`
            <span class="txn-seg__op is-active" aria-current="page">Personalizado</span>` : ''}
        </nav>

        <p class="txn-bar__janela">
          ${icon('event')}
          <span>${dataHora(f.de + ' 00:00:00')} — ${dataHora(f.ate + ' 23:59:59')}</span>
        </p>

        ${erros.de !== undefined || erros.ate !== undefined ? html`
          <span class="adm-badge adm-badge--warn txn-bar__recusa">
            ${micro.periodo_recusado ?? ''}
          </span>` : ''}

        <p class="txn-bar__aviso" title="${micro.somavel ?? ''}">
          ${icon('info')}<span>${micro.moeda_de_jogo ?? ''}</span>
        </p>

        <a class="adm-btn adm-btn--sm adm-btn--ghost txn-bar__csv"
           href="${link({ export: 'csv' })}"
           title="${micro.export ?? ''}">
          ${icon('download')}${acoes.csv ?? 'Exportar CSV'}
        </a>
      </div>
    </section>`;

  // -------------------------------------------------------------------------- KPIs
  // O ticket médio só existe com UMA moeda em cena.
  const umaMoeda = porMoeda.length === 1 ? porMoeda[0]! : null;
  const ticket =
    umaMoeda !== null
      ? html`
            <span class="adm-kpi__value num" title="${micro.ticket_calc ?? ''}">
              ${num(Number(umaMoeda.ticket), 0)}<small>${String(umaMoeda.curto)}</small>
            </span>`
      : porMoeda.length === 0
        ? html`
            <span class="adm-kpi__value num">—</span>`
        : html`
            <span class="adm-kpi__value txn-kpi__nota" title="${micro.ticket_multi ?? ''}">
              ${porMoeda.length} moedas
            </span>`;

  const kpis = html`
    <div class="adm-grid adm-grid--4 txn-kpis">
      <article class="adm-kpi adm-kpi--cyan">
        <span class="adm-kpi__ico">${icon('receipt_long')}</span>
        <span class="adm-kpi__txt">
          <span class="adm-kpi__label">${rotKpi.transacoes ?? 'Transações'}</span>
          <span class="adm-kpi__value num">${num(Number(totais.transacoes) | 0)}</span>
        </span>
      </article>

      <article class="adm-kpi adm-kpi--purple">
        <span class="adm-kpi__ico">${icon('inventory_2')}</span>
        <span class="adm-kpi__txt">
          <span class="adm-kpi__label">${rotKpi.unidades ?? 'Unidades vendidas'}</span>
          <span class="adm-kpi__value num">${num(Number(totais.unidades) | 0)}</span>
        </span>
      </article>

      <article class="adm-kpi">
        <span class="adm-kpi__ico">${icon('group')}</span>
        <span class="adm-kpi__txt">
          <span class="adm-kpi__label">${rotKpi.jogadores ?? 'Jogadores compradores'}</span>
          <span class="adm-kpi__value num">${num(Number(totais.jogadores) | 0)}</span>
        </span>
      </article>

      <article class="adm-kpi adm-kpi--gold">
        <span class="adm-kpi__ico">${icon('sell')}</span>
        <span class="adm-kpi__txt">
          <span class="adm-kpi__label">${rotKpi.ticket ?? 'Ticket médio'}</span>
          ${ticket}
        </span>
      </article>
    </div>`;

  // ---------------------------------------------------------- gráfico + por moeda
  const seriesMoedas = (serie.moedas ?? []) as Dict[];
  const painelGrafico = html`
      <section class="adm-card txn-panel">
        <div class="adm-card__head">
          <h2 class="adm-card__title">
            ${titulos.grafico ?? 'Movimento por dia'}
            <small>${granularidade === 'semana' ? (micro.semana ?? '') : (micro.granul_dia ?? '')}</small>
          </h2>
        </div>
        <div class="adm-card__body txn-plot">
          ${
            seriesMoedas.length === 0
              ? html`
            <div class="adm-empty">${icon('bar_chart')}<p class="adm-empty__txt">${vazios.grafico ?? ''}</p></div>`
              : join(
                  // Uma FAIXA por moeda, cada uma com a sua régua.
                  seriesMoedas.map(
                    (sm) => html`
              <div class="txn-serie">
                <div class="txn-serie__head">
                  <span class="adm-badge adm-badge--${seloDe(String(sm.tom))}">
                    ${String(sm.label)}
                  </span>
                  <span class="txn-serie__total num">
                    ${num(Math.trunc(Number(sm.total)))}<small>${String(sm.curto)}</small>
                  </span>
                  <span class="txn-serie__pico num" title="Maior ${granularidade === 'semana' ? 'semana' : 'dia'} do período">
                    pico ${num(Math.trunc(Number(sm.max)))}
                  </span>
                </div>
                ${raw(grafico((sm.pontos ?? []) as Dict[], Math.trunc(Number(sm.max)), String(sm.tom), String(sm.curto), String(sm.label)))}
              </div>`,
                  ),
                )
          }
        </div>
      </section>`;

  const painelMoedas = html`
      <section class="adm-card txn-panel">
        <div class="adm-card__head">
          <h2 class="adm-card__title">
            ${titulos.resumo ?? 'Total por moeda'}
            <small>${micro.delta_base ?? ''}</small>
          </h2>
        </div>
        <div class="adm-card__body adm-card__body--flush">
          ${
            porMoeda.length === 0
              ? html`
            <div class="adm-empty">${icon('payments')}<p class="adm-empty__txt">${vazios.periodo ?? ''}</p></div>`
              : html`
            <ul class="txn-moedas">
              ${join(
                porMoeda.map((m) => {
                  const tr = Number(m.transacoes) | 0;
                  const jg = Number(m.jogadores) | 0;
                  let delta: SafeHtml;
                  if (m.delta === null || m.delta === undefined) {
                    delta = html`
                  <span class="txn-delta is-void" title="${micro.delta_sem ?? ''}">${icon('remove')}</span>`;
                  } else {
                    const up = Number(m.delta) >= 0;
                    delta = html`
                  <span class="txn-delta ${up ? 'is-up' : 'is-down'}"
                        title="${micro.delta_base ?? ''}">
                    ${icon(up ? 'trending_up' : 'trending_down')}
                    ${(up ? '+' : '') + num(Number(m.delta) * 100, 1) + '%'}
                  </span>`;
                  }
                  return html`
                <li class="txn-moeda txn-moeda--${String(m.tom)}">
                  <span class="txn-moeda__nome">
                    ${String(m.label)}
                    <small>${num(tr)} transaç${tr === 1 ? 'ão' : 'ões'}
                      · ${num(jg)} jogador${jg === 1 ? '' : 'es'}</small>
                  </span>
                  <span class="txn-moeda__val num">
                    ${num(Math.trunc(Number(m.total)))}<small>${String(m.curto)}</small>
                    <small class="txn-moeda__ticket" title="${micro.ticket_calc ?? ''}">
                      ticket ${num(Number(m.ticket), 0)}
                    </small>
                  </span>
                  ${delta}
                </li>`;
                }),
              )}
            </ul>`
          }
        </div>
      </section>`;

  // --------------------------------------------------------- mais vendidos / gastadores
  const maxUn = topItens.length ? Math.max(...topItens.map((i) => Number(i.unidades) | 0)) : 0;
  const painelItens = html`
      <section class="adm-card txn-panel">
        <div class="adm-card__head">
          <h2 class="adm-card__title">${titulos.itens ?? 'Itens mais vendidos'}</h2>
        </div>
        <div class="adm-card__body adm-card__body--flush">
          ${
            topItens.length === 0
              ? html`
            <div class="adm-empty">${icon('inventory_2')}<p class="adm-empty__txt">${vazios.itens ?? ''}</p></div>`
              : html`
            <ul class="txn-top">
              ${join(
                topItens.map(
                  (i) => html`
                <li class="txn-top__row">
                  <span class="txn-top__bar" aria-hidden="true"
                        style="--v:${String(Math.round(((Number(i.unidades) | 0) / Math.max(1, maxUn)) * 100))}%"></span>
                  <span class="txn-top__nome">
                    ${i.nome !== '' ? String(i.nome) : 'Item #' + (Number(i.item_id) | 0)}
                    ${i.nome === '' ? html`
                      <small title="${micro.item_sumido ?? ''}">removido do catálogo</small>` : ''}
                  </span>
                  <span class="txn-top__n num">${num(Number(i.unidades) | 0)} un</span>
                  <span class="txn-top__v num">
                    ${num(Math.trunc(Number(i.total)))}<small>${siglaMoeda(String(i.moeda))}</small>
                  </span>
                </li>`,
                ),
              )}
            </ul>`
          }
        </div>
      </section>`;

  const maxG = topJog.length ? Math.max(...topJog.map((j) => Math.trunc(Number(j.total)))) : 0;
  const painelJog = html`
      <section class="adm-card txn-panel">
        <div class="adm-card__head">
          <h2 class="adm-card__title">${titulos.jogadores ?? 'Quem mais gastou'}</h2>
        </div>
        <div class="adm-card__body adm-card__body--flush">
          ${
            topJog.length === 0
              ? html`
            <div class="adm-empty">${icon('group')}<p class="adm-empty__txt">${vazios.jogadores ?? ''}</p></div>`
              : html`
            <ul class="txn-top">
              ${join(
                topJog.map(
                  (j) => html`
                <li class="txn-top__row">
                  <span class="txn-top__bar" aria-hidden="true"
                        style="--v:${String(Math.round((Math.trunc(Number(j.total)) / Math.max(1, maxG)) * 100))}%"></span>
                  <span class="txn-top__nome">
                    ${j.nome !== '' ? String(j.nome) : 'Conta #' + (Number(j.user_id) | 0)}
                    ${j.nome === '' ? html`
                      <small title="${micro.conta_sumida ?? ''}">conta removida</small>` : ''}
                  </span>
                  <span class="txn-top__n num">${num(Number(j.transacoes) | 0)}×</span>
                  <span class="txn-top__v num">
                    ${num(Math.trunc(Number(j.total)))}<small>${siglaMoeda(String(j.moeda))}</small>
                  </span>
                </li>`,
                ),
              )}
            </ul>`
          }
        </div>
      </section>`;

  // ------------------------------------------------------------------------- tabela
  let tabela: SafeHtml;
  if (linhas.length === 0) {
    tabela = html`
        <div class="adm-empty">
          ${icon('search_off')}
          <p class="adm-empty__txt">
            ${vazios.periodo ?? ''}
            ${extremos.ultima ? html`
              A última compra registrada foi em ${dataHora(String(extremos.ultima))}.` : ''}
          </p>
          <a class="adm-btn adm-btn--ghost" href="${url('admin/transacoes', { periodo: 'tudo' })}">
            ${icon('history')}${acoes.tudo ?? 'Ver todo o período'}
          </a>
        </div>`;
  } else {
    const atual = Number(pag.pagina) | 0;
    const paginas = Number(pag.paginas) | 0;
    // Janela de páginas: primeira, última e duas vizinhas.
    const mostrar = [...new Set([1, paginas, atual, atual - 1, atual + 1].filter((n) => n >= 1 && n <= paginas))].sort((a, b) => a - b);
    let anterior = 0;
    const botoes = mostrar.map((n) => {
      const gap = anterior > 0 && n > anterior + 1 ? html`
                <span class="adm-pager__gap">…</span>` : '';
      anterior = n;
      return html`${gap}
              <a class="adm-pager__btn${n === atual ? ' is-active' : ''}"
                 href="${link({ p: n > 1 ? String(n) : null })}"
                 ${n === atual ? html`aria-current="page"` : ''}>${String(n)}</a>`;
    });

    tabela = html`
        <div class="adm-tablewrap">
          <table class="adm-table txn-table">
            <thead>
              <tr>
                ${cab('id', colunas.id ?? '#', 'tr')}
                ${cab('data', colunas.data ?? 'Data')}
                ${cab('jogador', colunas.jogador ?? 'Jogador')}
                ${cab('item', colunas.item ?? 'Item')}
                <th class="tr">${colunas.qtd ?? 'Qtd'}</th>
                <th class="tr">${colunas.unit ?? 'Unitário'}</th>
                ${cab('valor', colunas.total ?? 'Total', 'tr')}
                <th>${colunas.moeda ?? 'Moeda'}</th>
              </tr>
            </thead>
            <tbody>
              ${join(
                linhas.map((l) => {
                  const moeda = String(l.currency);
                  const selo = seloDe(tomMoeda(moeda));
                  const nomeJ = String(l.username ?? '');
                  const nomeI = String(l.item_name ?? '');
                  const st = String(l.user_status ?? 'active');
                  return html`
                <tr>
                  <td class="tr num txn-id">#${String(Number(l.id) | 0)}</td>
                  <td title="${dataLonga(String(l.bought_at))}">${dataHora(String(l.bought_at))}</td>
                  <td>
                    ${
                      nomeJ !== ''
                        ? html`<strong>${nomeJ}</strong>
                      ${st !== 'active' ? html`<span class="adm-badge adm-badge--danger txn-mini">${st}</span>` : ''}`
                        : html`<span class="txn-sumido" title="${micro.conta_sumida ?? ''}">
                        Conta #${String(Number(l.user_id) | 0)}
                      </span>`
                    }
                  </td>
                  <td>
                    ${
                      nomeI !== ''
                        ? html`<strong>${nomeI}</strong>
                      ${l.item_category ? html`<small class="txn-cat">${String(l.item_category)}</small>` : ''}`
                        : html`<span class="txn-sumido" title="${micro.item_sumido ?? ''}">
                        Item #${String(Number(l.item_id) | 0)}
                      </span>`
                    }
                  </td>
                  <td class="tr num">${num(Number(l.quantity) | 0)}</td>
                  <td class="tr num">${num(Math.trunc(Number(l.price)))}</td>
                  <td class="tr num txn-total">${num(Math.trunc(Number(l.total)))}</td>
                  <td><span class="adm-badge adm-badge--${selo}">${siglaMoeda(moeda)}</span></td>
                </tr>`;
                }),
              )}
            </tbody>
          </table>
        </div>

        <div class="adm-card__foot">
          <nav class="adm-pager" aria-label="Paginação das transações">
            <span class="adm-pager__info">
              ${num(Number(pag.primeiro) | 0)}–${num(Number(pag.ultimo) | 0)}
              de ${num(Number(pag.total) | 0)}
            </span>

            <a class="adm-pager__btn${atual <= 1 ? ' is-disabled' : ''}"
               href="${link({ p: atual - 1 > 1 ? String(atual - 1) : null })}"
               aria-label="Página anterior"${atual <= 1 ? html` aria-disabled="true"` : ''}>${icon('chevron_left')}</a>
            ${join(botoes)}

            <a class="adm-pager__btn${atual >= paginas ? ' is-disabled' : ''}"
               href="${link({ p: String(atual + 1) })}"
               aria-label="Próxima página"${atual >= paginas ? html` aria-disabled="true"` : ''}>${icon('chevron_right')}</a>
          </nav>
        </div>`;
  }

  // Região da lista (core/fragmento.ts) GRANDE de propósito: KPIs, gráfico e quadros são
  // leituras DO RECORTE FILTRADO — trocar só a tabela deixaria os números desatualizados.
  return html`
<div class="txn">

    <!--eh:lista-->

    <!-- ------------------------------------------------------------------ filtros -->
    ${filtros}

    <!-- --------------------------------------------------------------------- KPIs -->
    ${kpis}

    <!-- ------------------------------------------------------- gráfico + por moeda -->
    <div class="txn-row txn-row--grafico">
      ${painelGrafico}
      ${painelMoedas}
    </div>

    <!-- ------------------------------------------------------ mais vendidos / gastadores -->
    <div class="txn-row txn-row--tops">
      ${painelItens}
      ${painelJog}
    </div>

    <!-- -------------------------------------------------------------------- tabela -->
    <section class="adm-card txn-lista">
      <div class="adm-card__head">
        <h2 class="adm-card__title">
          ${titulos.lista ?? 'Transações'}
          <small data-adm-total>${num(Number(pag.total) | 0)} no período</small>
        </h2>
      </div>
      ${tabela}
    </section>
    <!--/eh:lista-->

</div>`;
}
