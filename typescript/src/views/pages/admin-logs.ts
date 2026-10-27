/**
 * Tela ADMIN · LOGS DO SISTEMA (/admin/logs) — app/Views/pages/admin-logs.php. Layout 'admin'.
 *
 * A view NÃO consulta o banco e NÃO conta nada: tudo chega pronto de repo/audit-repo.ts pelo
 * controllers/admin/logs-controller.ts. Aqui só existe FORMATAÇÃO e a montagem dos endereços.
 *
 * TUDO FUNCIONA SEM JAVASCRIPT: filtro é <form method="get">, ordenação é link no cabeçalho,
 * paginação é link e o detalhe é `?log=<id>`.
 *
 * Datas: `created_at` é UTC e é mostrado como está gravado (convenção de admin-repo.ts).
 */
import { icon, num, url } from '../../core/helpers';
import { html, join, raw, type SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { agoraUnix, formatarData, paraUnix } from '../../repo/admin-repo';

const S = (v: unknown) => String(v ?? '');

/** sprintf só com %s, na ordem. */
function sprintf(fmt: string, ...args: string[]): string {
  let i = 0;
  return fmt.replace(/%s/g, () => args[i++] ?? '');
}

/** ucfirst() de PHP. */
const ucfirst = (s: string) => (s === '' ? s : s.charAt(0).toUpperCase() + s.slice(1));

interface Verbete {
  label: string;
  icone: string;
  tom: string;
  nota: string;
  conhecida: boolean;
}

export function adminLogs(s: Scope): SafeHtml {
  const data = s.data;
  const cromo: Dict = data.cromo && typeof data.cromo === 'object' ? data.cromo : {};
  const titulos: Dict = cromo.titulos ?? {};
  const rotulos: Dict = cromo.filtros ?? {};
  const colunas: Dict = cromo.colunas ?? {};
  const dicAcoes: Dict = cromo.acoes ?? {};
  const papeis: Dict = cromo.papeis ?? {};
  const atores: Dict = cromo.atores ?? {};
  const txtDet: Dict = cromo.detalhe ?? {};
  const txtRes: Dict = cromo.resumo ?? {};
  const vazios: Dict = cromo.vazios ?? {};
  const micro: Dict = cromo.micro ?? {};

  const filtro: Dict = data.filtro ?? {};
  const lista: Dict = data.lista ?? {};
  const opcoes: Dict = data.opcoes ?? {};
  const resumo: Dict = data.resumo ?? {};
  const detalhe: Dict | null = data.detalhe && typeof data.detalhe === 'object' ? data.detalhe : null;
  const temFiltro = Boolean(data.tem_filtro ?? false);

  const linhas: Dict[] = lista.linhas ?? [];
  const total = Number(lista.total ?? 0) | 0;
  const pagina = Number(lista.pagina ?? 1) | 0;
  const paginas = Number(lista.paginas ?? 1) | 0;
  const falhou = Boolean(lista.falha ?? false);
  const totalGeral = Number(opcoes.total ?? 0) | 0;

  const agora = agoraUnix();
  const hoje = formatarData(agora, 'Y-m-d');

  // ======================================================= ENDEREÇOS (estado na query string)

  /** URL desta tela com o filtro atual e as MUDANÇAS pedidas. O que fica vazio sai da URL. */
  const linkCom = (muda: Record<string, string | number | null> = {}): string => {
    const q: Record<string, string | number> = {
      q: S(filtro.q),
      acao: S(filtro.acao),
      quem: S(filtro.quem),
      de: S(filtro.de),
      ate: S(filtro.ate),
      ord: S(filtro.ord ?? 'quando'),
      dir: S(filtro.dir ?? 'desc'),
      pag: Number(filtro.pag ?? 1) | 0,
      log: Number(filtro.log ?? 0) | 0,
    };
    for (const [k, v] of Object.entries(muda)) q[k] = v === null ? '' : v;
    // A ordenação padrão não precisa aparecer escrita.
    if (q.ord === 'quando' && q.dir === 'desc') q.ord = q.dir = '';
    const limpo: Record<string, string> = {};
    for (const [k, v] of Object.entries(q)) {
      if (v === '' || v === 0 || (k === 'pag' && (Number(v) | 0) <= 1)) continue;
      limpo[k] = String(v);
    }
    return url('admin/logs', limpo);
  };

  /** Cabeçalho ordenável: mesmo campo inverte a direção; campo novo começa em desc. */
  const ordLink = (campo: string): string => {
    const atualCampo = S(filtro.ord ?? 'quando');
    const atualDir = S(filtro.dir ?? 'desc');
    const dir = atualCampo === campo && atualDir === 'desc' ? 'asc' : 'desc';
    return linkCom({ ord: campo, dir, pag: 1 });
  };

  // ============================================================================ FORMATADORES

  /** Carimbo curto da coluna (com segundos: três falhas no mesmo minuto são três fatos). */
  const carimbo = (ts: number) => formatarData(ts, 'd/m H:i:s');
  /** Carimbo por extenso, para o `title` e para o detalhe. */
  const carimboCheio = (ts: number) => formatarData(ts, 'd/m/Y H:i:s');

  /** "há 5 min" / "há 2 h" / "há 3 dias". */
  const desde = (ts: number): string => {
    const sg = Math.max(0, agora - ts);
    if (sg < 60) return 'agora mesmo';
    if (sg < 3600) return 'há ' + Math.floor(sg / 60) + ' min';
    if (sg < 86400) return 'há ' + Math.floor(sg / 3600) + ' h';
    const d = Math.floor(sg / 86400);
    return 'há ' + d + (d === 1 ? ' dia' : ' dias');
  };

  /** Verbete da ação. Sem entrada no dicionário, o nome do banco aparece cru e neutro. */
  const verbete = (acao: string): Verbete => {
    const v: Dict = dicAcoes[acao] ?? {};
    if (Object.keys(v).length > 0) {
      return { label: S(v.label ?? acao), icone: S(v.icone ?? 'bolt'), tom: S(v.tom ?? 'muted'), nota: S(v.nota), conhecida: true };
    }
    return {
      label: ucfirst(acao.replace(/_/g, ' ')),
      icone: 'help',
      tom: 'muted',
      nota: 'Ação sem verbete na tela: o rótulo é o próprio identificador gravado em admin_audit_log.',
      conhecida: false,
    };
  };

  /** Quem assinou a linha, nos três estados que o repo distingue. */
  const autor = (quem: Dict): { nome: string; papel: string; tom: string; title: string } => {
    const tipo = S(quem.tipo ?? 'anonimo');
    const id = Number(quem.id ?? 0) | 0;
    const papel = S(quem.papel);
    if (tipo === 'anonimo') {
      return { nome: S(atores.anonimo ?? 'anônimo'), papel: '', tom: 'anonimo', title: S(atores.nota_anonimo) };
    }
    if (tipo === 'removido') {
      return {
        nome: S(atores.removido ?? 'conta removida') + ' #' + id,
        papel: '',
        tom: 'removido',
        title: sprintf(S(atores.nota_removido ?? '%s'), String(id)),
      };
    }
    const rotulo = S(papeis[papel] ?? papel);
    const nota = papel === 'player' ? S(atores.nota_jogador) : S(atores.nota_papel);
    return {
      nome: S(quem.nome),
      papel: rotulo,
      tom: papel === 'owner' ? 'owner' : papel === 'admin' ? 'admin' : 'player',
      title: '#' + id + ' · ' + rotulo + (nota !== '' ? '\n' + nota : ''),
    };
  };

  /** Alvo da ação: `entity` é o tipo de coisa, `entity_id` é qual. */
  const alvo = (l: Dict): string => {
    const en = S(l.entidade);
    const i = S(l.alvo);
    return i === '' ? en : en + ' · ' + i;
  };

  /** Valor de uma chave do meta em uma linha só; o que não é escalar volta como JSON compacto. */
  const metaValor = (v: unknown): string => {
    if (v === null || v === undefined) return 'null';
    if (typeof v === 'boolean') return v ? 'true' : 'false';
    if (typeof v === 'number' || typeof v === 'string') return String(v);
    return JSON.stringify(v);
  };

  /* Atalhos de período: cada chip só escreve o campo "De". */
  const periodos = ((cromo.periodos ?? []) as Dict[]).map((p) => {
    const dias = p.dias ?? null;
    const de = dias === null ? '' : formatarData(agora - (Number(dias) | 0) * 86400, 'Y-m-d');
    return {
      label: S(p.label),
      href: linkCom({ de, ate: '', pag: 1, log: 0 }),
      ativo: S(filtro.de) === de && S(filtro.ate) === '',
    };
  });

  /* Faixa de páginas: a primeira, a última e as vizinhas da atual. */
  let faixa: number[] = [];
  if (paginas > 1) {
    const set = new Set<number>();
    for (const n of [1, paginas, pagina - 1, pagina, pagina + 1]) if (n >= 1 && n <= paginas) set.add(n);
    faixa = [...set].sort((a, b) => a - b);
  }

  // ================================================================================ TRILHA

  const opcoesAcao = join(
    ((opcoes.acoes ?? []) as Dict[]).map((op) => {
      const v = verbete(S(op.acao));
      return html`
            <option value="${S(op.acao)}"${S(filtro.acao) === S(op.acao) ? raw(' selected') : ''}>
              ${v.label + ' (' + num(Number(op.n) | 0) + ')'}
            </option>`;
    }),
  );

  const opcoesQuem = join(
    ((opcoes.contas ?? []) as Dict[]).map((c) => {
      const nome = S(c.nome) !== '' ? S(c.nome) : S(atores.removido ?? 'conta removida') + ' #' + (Number(c.id) | 0);
      return html`
            <option value="${S(c.id)}"${S(filtro.quem) === S(c.id) ? raw(' selected') : ''}>
              ${nome + ' (' + num(Number(c.n) | 0) + ')'}
            </option>`;
    }),
  );

  let corpoTabela: SafeHtml;
  if (falhou) {
    // Banco mudo NÃO é trilha vazia.
    corpoTabela = html`
        <div class="adm-empty lg-empty">
          ${icon('database_off')}
          <h3 class="adm-empty__title">Trilha indisponível</h3>
          <p class="adm-empty__txt">${S(vazios.falha)}</p>
        </div>`;
  } else if (linhas.length === 0) {
    corpoTabela = html`
        <div class="adm-empty lg-empty">
          ${icon(temFiltro ? 'filter_alt_off' : 'history_toggle_off')}
          <h3 class="adm-empty__title">${temFiltro ? 'Nada neste recorte' : 'Trilha vazia'}</h3>
          <p class="adm-empty__txt">${S(vazios[temFiltro ? 'filtro' : 'trilha'])}</p>
          ${
            temFiltro
              ? html`<a class="adm-btn adm-btn--ghost" href="${url('admin/logs')}">
              ${icon('restart_alt')}<span>${S(rotulos.limpar ?? 'Limpar filtros')}</span>
            </a>`
              : ''
          }
        </div>`;
  } else {
    const ordenaveis: Array<[string, { classe: string; rotulo: string }]> = [
      ['quando', { classe: 'lg-col-quando', rotulo: S(colunas.quando ?? 'Quando') }],
      ['acao', { classe: 'lg-col-acao', rotulo: S(colunas.acao ?? 'Ação') }],
      ['quem', { classe: 'lg-col-quem', rotulo: S(colunas.quem ?? 'Quem fez') }],
      ['alvo', { classe: 'lg-col-alvo', rotulo: S(colunas.alvo ?? 'Alvo') }],
    ];
    const cabecalhos = join(
      ordenaveis.map(([campo, c]) => {
        const on = S(filtro.ord ?? 'quando') === campo;
        return html`
                  <th class="${c.classe}${on ? ' is-sorted' : ''}">
                    <a class="lg-sort" href="${ordLink(campo)}"
                       title="${sprintf(S(micro.ordenar ?? 'Ordenar por %s'), c.rotulo)}">
                      <span>${c.rotulo}</span>
                      ${icon(on ? (S(filtro.dir ?? 'desc') === 'asc' ? 'arrow_upward' : 'arrow_downward') : 'unfold_more', 'lg-sort__ico')}
                    </a>
                  </th>`;
      }),
    );
    const corpoLinhas = join(
      linhas.map((l) => {
        const v = verbete(S(l.acao));
        const a = autor(l.quem ?? {});
        const sel = (Number(filtro.log ?? 0) | 0) === (Number(l.id) | 0);
        const href = linkCom({ log: Number(l.id) | 0 });
        const ts = Number(l.quando_ts) | 0;
        return html`
                <tr class="lg-row${sel ? ' is-selected' : ''}">
                  <td class="tr num lg-col-id">${S(l.id)}</td>
                  <td class="lg-col-quando">
                    <time class="num" datetime="${formatarData(ts, 'c')}"
                          title="${carimboCheio(ts) + ' · ' + desde(ts)}">${carimbo(ts)}</time>
                  </td>
                  <td class="lg-col-acao">
                    <span class="adm-badge adm-badge--${v.tom}${v.conhecida ? '' : ' lg-badge--crua'}"
                          title="${S(l.acao) + (v.nota !== '' ? '\n' + v.nota : '')}">
                      <span class="lg-acao__txt">${v.label}</span>
                    </span>
                  </td>
                  <td class="lg-col-quem">
                    <span class="lg-who lg-who--${a.tom}" title="${a.title}">
                      <strong>${a.nome}</strong>
                      ${a.papel !== '' ? html`<small>${a.papel}</small>` : ''}
                    </span>
                  </td>
                  <td class="lg-col-alvo"><span class="lg-alvo" title="${alvo(l)}">${alvo(l)}</span></td>
                  <td class="lg-col-resumo" title="${S(l.resumo)}">${S(l.resumo)}</td>
                  <td class="lg-col-ip num">${S(l.ip) !== '' ? S(l.ip) : raw('<span class="lg-void">—</span>')}</td>
                  <td class="lg-col-ver">
                    <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--ghost" href="${href}"
                       aria-label="${S(micro.ver_detalhe ?? 'Ver registro') + ' #' + S(l.id)}"
                       title="${S(micro.ver_detalhe ?? 'Ver registro')}">${icon('chevron_right')}</a>
                  </td>
                </tr>`;
      }),
    );
    corpoTabela = html`
        <div class="adm-tablewrap lg-wrap">
          <table class="adm-table adm-table--compact lg-table">
            <thead>
              <tr>
                <th class="tr lg-col-id">${S(colunas.id ?? '#')}</th>
                ${cabecalhos}
                <th class="lg-col-resumo">${S(colunas.resumo ?? 'O que aconteceu')}</th>
                <th class="lg-col-ip">${S(colunas.ip ?? 'IP')}</th>
                <th class="lg-col-ver"><span class="lg-sr">Detalhe</span></th>
              </tr>
            </thead>
            <tbody>
              ${corpoLinhas}
            </tbody>
          </table>
        </div>`;
  }

  // Paginação.
  let pager: SafeHtml | '' = '';
  if (!falhou && total > 0) {
    let ultimo = 0;
    const botoes = faixa.map((n) => {
      const gap = ultimo > 0 && n > ultimo + 1 ? raw('<span class="adm-pager__gap">…</span>') : '';
      ultimo = n;
      return html`
              ${gap}
              <a class="adm-pager__btn${n === pagina ? ' is-active' : ''}"
                 href="${linkCom({ pag: n })}"${n === pagina ? raw(' aria-current="page"') : ''}>${String(n)}</a>`;
    });
    pager = html`
      <div class="adm-card__foot lg-foot">
        <nav class="adm-pager" aria-label="Paginação da trilha">
          <span class="adm-pager__info">
            ${sprintf(S(micro.pagina ?? 'Página %s de %s'), num(pagina), num(paginas))}
            · ${sprintf(S(micro.contando ?? '%s de %s'), num(total), num(totalGeral))}
          </span>
          ${
            paginas > 1
              ? html`
            <a class="adm-pager__btn${pagina <= 1 ? ' is-disabled' : ''}"
               href="${linkCom({ pag: Math.max(1, pagina - 1) })}" aria-label="Página anterior">${icon('chevron_left')}</a>
            ${join(botoes)}
            <a class="adm-pager__btn${pagina >= paginas ? ' is-disabled' : ''}"
               href="${linkCom({ pag: Math.min(paginas, pagina + 1) })}" aria-label="Próxima página">${icon('chevron_right')}</a>`
              : ''
          }
        </nav>
      </div>`;
  }

  // =============================================================================== DETALHE
  let corpoDetalhe: SafeHtml;
  if (detalhe === null) {
    corpoDetalhe = html`
          <div class="adm-empty lg-empty lg-empty--sm">
            ${icon('data_object')}
            <p class="adm-empty__txt">${S(txtDet.vazio)}</p>
          </div>`;
  } else {
    const v = verbete(S(detalhe.acao));
    const a = autor(detalhe.quem ?? {});
    const ts = Number(detalhe.quando_ts) | 0;
    const meta: Dict = detalhe.meta && typeof detalhe.meta === 'object' ? detalhe.meta : {};
    const vazioMeta = Object.keys(meta).length === 0;
    corpoDetalhe = html`
          <p class="lg-detail__acao">
            <span class="adm-badge adm-badge--${v.tom}">${icon(v.icone)}${v.label}</span>
            <code class="lg-code">${S(detalhe.acao)}</code>
          </p>
          ${v.nota !== '' ? html`<p class="lg-detail__nota">${v.nota}</p>` : ''}

          <dl class="adm-dl lg-dl">
            <dt>${S(txtDet.campo_quando ?? 'Quando')}</dt>
            <dd><span class="num">${carimboCheio(ts)}</span> <small>${desde(ts)}</small></dd>

            <dt>${S(txtDet.campo_quem ?? 'Quem fez')}</dt>
            <dd class="lg-who lg-who--${a.tom}" title="${a.title}">
              <strong>${a.nome}</strong>
              ${a.papel !== '' ? html`<small>${a.papel + ' · conta #' + (Number(detalhe.quem?.id) | 0)}</small>` : ''}
            </dd>

            <dt>${S(txtDet.campo_alvo ?? 'Alvo')}</dt>
            <dd><code class="lg-code">${S(detalhe.entidade)}</code>
              ${S(detalhe.alvo) !== '' ? html`<span class="lg-alvo">${S(detalhe.alvo)}</span>` : ''}
            </dd>

            <dt>${S(txtDet.campo_ip ?? 'IP de origem')}</dt>
            <dd class="num">${S(detalhe.ip) !== '' ? S(detalhe.ip) : raw('<span class="lg-void">—</span>')}</dd>

            <dt>${S(txtDet.campo_resumo ?? 'Resumo')}</dt>
            <dd class="lg-detail__resumo">${S(detalhe.resumo)}</dd>
          </dl>

          <h3 class="lg-detail__sub">${S(txtDet.campo_meta ?? 'Meta')}</h3>
          ${
            vazioMeta
              ? html`<p class="lg-detail__nota lg-detail__nota--void">${S(txtDet.sem_meta)}</p>`
              : html`
            <dl class="adm-dl lg-dl lg-dl--meta">
              ${join(
                Object.entries(meta).map(
                  ([chave, valor]) => html`
                <dt>${chave}</dt>
                <dd class="lg-meta__val">${metaValor(valor)}</dd>`,
                ),
              )}
            </dl>
            <details class="lg-raw">
              <summary>${icon('code')}<span>${S(txtDet.json_bruto ?? 'JSON bruto')}</span></summary>
              <pre class="lg-raw__pre">${JSON.stringify(meta, null, 4)}</pre>
            </details>`
          }`;
  }

  // ================================================================================ RESUMO
  let corpoResumo: SafeHtml;
  if (falhou || total === 0) {
    corpoResumo = html`
          <p class="lg-detail__nota lg-detail__nota--void">
            ${falhou ? S(vazios.falha) : S(vazios[temFiltro ? 'filtro' : 'trilha'])}
          </p>`;
  } else {
    const pri = S(resumo.primeiro);
    const ult = S(resumo.ultimo);
    const acoesResumo: Dict[] = resumo.acoes ?? [];
    const maior = acoesResumo.length ? Math.max(...acoesResumo.map((x) => Number(x.n) | 0)) : 0;
    corpoResumo = html`
          <div class="lg-stats">
            <div class="lg-stat">
              <span class="lg-stat__lbl">${S(txtRes.total ?? 'Registros')}</span>
              <span class="lg-stat__val num">${num(total)}</span>
            </div>
            <div class="lg-stat">
              <span class="lg-stat__lbl">${S(txtRes.operadores ?? 'Contas')}</span>
              <span class="lg-stat__val num">${num(Number(resumo.contas ?? 0) | 0)}</span>
            </div>
          </div>

          <p class="lg-periodo">
            <span class="lg-stat__lbl">${S(txtRes.periodo ?? 'Período coberto')}</span>
            <span class="num">
              ${
                pri !== '' && ult !== ''
                  ? formatarData(paraUnix(pri), 'd/m/Y H:i') + ' — ' + formatarData(paraUnix(ult), 'd/m/Y H:i')
                  : S(txtRes.sem_periodo ?? '—')
              }
            </span>
          </p>

          ${
            acoesResumo.length > 0
              ? html`
            <h3 class="lg-detail__sub">${S(txtRes.acoes ?? 'Ações no recorte')}</h3>
            <ul class="lg-bars">
              ${join(
                acoesResumo.map((x) => {
                  const vv = verbete(S(x.acao));
                  const pct = maior > 0 ? Math.round(((Number(x.n) | 0) / maior) * 100) : 0;
                  return html`
                <li class="lg-bar lg-bar--${vv.tom}">
                  <a class="lg-bar__link" href="${linkCom({ acao: S(x.acao), pag: 1, log: 0 })}"
                     title="${S(x.acao)}">
                    <span class="lg-bar__lbl">${vv.label}</span>
                    <span class="lg-bar__n num">${num(Number(x.n) | 0)}</span>
                  </a>
                  <span class="lg-bar__track"><span class="lg-bar__fill" style="--v:${String(pct)}%"></span></span>
                </li>`;
                }),
              )}
            </ul>`
              : ''
          }`;
  }

  const janelaTxt =
    S(filtro.de) !== '' || S(filtro.ate) !== ''
      ? html`
        <span class="lg-quick__janela">
          ${sprintf(
            S(micro.de_ate ?? '%s até %s'),
            S(filtro.de) !== '' ? formatarData(paraUnix(S(filtro.de)), 'd/m/Y') : 'o início',
            S(filtro.ate) !== '' ? formatarData(paraUnix(S(filtro.ate)), 'd/m/Y') : 'agora',
          )}
        </span>`
      : '';

  return html`<div class="lg">

  <!-- ==================================================================== TRILHA -->
  ${raw('<!--eh:lista-->')}
  <section class="adm-card lg-list">

    <div class="adm-card__head">
      <h2 class="adm-card__title">
        ${S(titulos.lista ?? 'Trilha de auditoria')}
        <small data-adm-total>${sprintf(S(micro.contando ?? '%s de %s'), num(total), num(totalGeral))}</small>
      </h2>
      <div class="adm-card__tools">
        <span class="lg-src" title="${S(micro.origem)}">admin_audit_log</span>
        <span class="lg-ro" title="${S(micro.somente_leitura)}">
          ${icon('lock')}<span>somente leitura</span>
        </span>
      </div>
    </div>

    <!-- --------------------------------------------------------------- filtros -->
    <form class="lg-filters" id="lg-filtro" method="get" action="${url('admin/logs')}" role="search"
          data-adm-preservar="filtros">
      <input type="hidden" name="ord" value="${S(filtro.ord ?? 'quando')}">
      <input type="hidden" name="dir" value="${S(filtro.dir ?? 'desc')}">

      <div class="adm-field lg-filters__busca">
        <label class="adm-label" for="lg-q">${S(rotulos.busca_rotulo ?? 'Buscar')}</label>
        <span class="adm-search">
          ${icon('search')}
          <input class="adm-input" type="search" id="lg-q" name="q" maxlength="60"
                 value="${S(filtro.q)}"
                 placeholder="${S(rotulos.busca_dica)}">
        </span>
      </div>

      <div class="adm-field lg-filters__acao">
        <label class="adm-label" for="lg-acao">${S(rotulos.acao_rotulo ?? 'Ação')}</label>
        <select class="adm-select" id="lg-acao" name="acao">
          <option value="">${S(rotulos.acao_todas ?? 'Todas')}</option>
          ${opcoesAcao}
        </select>
      </div>

      <div class="adm-field lg-filters__quem">
        <label class="adm-label" for="lg-quem">${S(rotulos.quem_rotulo ?? 'Quem fez')}</label>
        <select class="adm-select" id="lg-quem" name="quem">
          <option value="">${S(rotulos.quem_todos ?? 'Qualquer pessoa')}</option>
          ${opcoesQuem}
          ${
            (Number(opcoes.anonimos ?? 0) | 0) > 0
              ? html`
            <option value="anonimo"${S(filtro.quem) === 'anonimo' ? raw(' selected') : ''}>
              ${S(rotulos.quem_anonimo ?? 'Sem conta identificada') + ' (' + num(Number(opcoes.anonimos) | 0) + ')'}
            </option>`
              : ''
          }
        </select>
      </div>

      <div class="adm-field lg-filters__data">
        <label class="adm-label" for="lg-de">${S(rotulos.de_rotulo ?? 'De')}</label>
        <input class="adm-input" type="date" id="lg-de" name="de" max="${hoje}"
               value="${S(filtro.de)}">
      </div>

      <div class="adm-field lg-filters__data">
        <label class="adm-label" for="lg-ate">${S(rotulos.ate_rotulo ?? 'Até')}</label>
        <input class="adm-input" type="date" id="lg-ate" name="ate" max="${hoje}"
               value="${S(filtro.ate)}">
      </div>

    </form>

    <div class="lg-quick">
      <span class="lg-quick__lbl">${S(rotulos.periodo_rotulo ?? 'Período')}</span>
      ${join(
        periodos.map(
          (p) => html`
      <a class="lg-chip${p.ativo ? ' is-active' : ''}" href="${p.href}"
         ${p.ativo ? raw('aria-current="true"') : ''}>${p.label}</a>`,
        ),
      )}
      ${janelaTxt}
      <div class="lg-quick__acoes">
        <button class="adm-btn adm-btn--sm adm-btn--primary" type="submit" form="lg-filtro">
          ${icon('filter_alt')}<span>${S(rotulos.aplicar ?? 'Filtrar')}</span>
        </button>
        ${
          temFiltro
            ? html`<a class="adm-btn adm-btn--sm adm-btn--ghost" href="${url('admin/logs')}">
            ${icon('filter_alt_off')}<span>${S(rotulos.limpar ?? 'Limpar filtros')}</span>
          </a>`
            : ''
        }
      </div>
    </div>

    <!-- ----------------------------------------------------------------- tabela -->
    <div class="adm-card__body adm-card__body--flush lg-list__body">
      ${corpoTabela}
    </div>

    <!-- -------------------------------------------------------------- paginação -->
    ${pager}
  </section>
  ${raw('<!--/eh:lista-->')}

  <!-- ================================================================= COLUNA DIREITA -->
  <aside class="lg-side">

    <!-- ---------------------------------------------------------------- detalhe -->
    <section class="adm-card lg-detail" id="detalhe">
      <div class="adm-card__head">
        <h2 class="adm-card__title">
          ${S(titulos.detalhe ?? 'Detalhe do registro')}
          ${detalhe !== null ? html`<small class="num">#${S(detalhe.id)}</small>` : ''}
        </h2>
        ${
          detalhe !== null
            ? html`
          <div class="adm-card__tools">
            <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--ghost" href="${linkCom({ log: 0 })}"
               title="${S(txtDet.fechar ?? 'Fechar')}"
               aria-label="${S(txtDet.fechar ?? 'Fechar')}">${icon('close')}</a>
          </div>`
            : ''
        }
      </div>

      <div class="adm-card__body lg-detail__body scroll-y">
        ${corpoDetalhe}
      </div>
    </section>

    <!-- ----------------------------------------------------------------- resumo -->
    <section class="adm-card lg-resumo">
      <div class="adm-card__head">
        <h2 class="adm-card__title">${S(titulos.resumo ?? 'Resumo do recorte')}</h2>
      </div>
      <div class="adm-card__body lg-resumo__body">
        ${corpoResumo}
      </div>
    </section>
  </aside>
</div>`;
}
