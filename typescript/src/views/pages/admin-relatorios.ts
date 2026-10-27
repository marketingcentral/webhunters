/**
 * Tela ADMIN · RELATÓRIOS (/admin/relatorios) — app/Views/pages/admin-relatorios.php. Layout 'admin'.
 *
 * A view não consulta o banco e não decide regra: tudo chega pronto de repo/reports-repo.ts
 * por controllers/admin/reports-controller.ts. Aqui há composição (.adm-*), formatação de
 * data e a montagem dos links que preservam o recorte.
 *
 * TUDO NAVEGA PELA URL: aba, busca, filtros, página, relatório aberto e confirmação de
 * exclusão são parâmetros de GET. A exclusão confirma no servidor (`confirmar=<id>`).
 */
import { csrfField, icon, num, url } from '../../core/helpers';
import { html, join, type SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { agoraUnix, formatarData } from '../../repo/admin-repo';

/** sprintf só com %s, na ordem. */
function sprintf(fmt: string, ...args: string[]): string {
  let i = 0;
  return fmt.replace(/%s/g, () => args[i++] ?? '');
}

const FILTROS_POST = ['q', 'status', 'grupo', 'peso', 'ord', 'pag'];

export function adminRelatorios(s: Scope): SafeHtml {
  const data = s.data;
  const cromo = (data.cromo ?? {}) as Dict;
  const abas = (cromo.abas ?? {}) as Record<string, Dict>;
  const estados = ((cromo.status ?? {})[data.aba ?? ''] ?? {}) as Record<string, Dict>;
  const sev = (cromo.severidades ?? {}) as Record<string, Dict>;
  const prio = (cromo.prioridades ?? {}) as Record<string, Dict>;
  const ordens = (cromo.ordens ?? {}) as Record<string, string>;
  const vazios = (cromo.vazios ?? {}) as Record<string, string>;
  const micro = (cromo.micro ?? {}) as Record<string, string>;
  const erros = (cromo.erros ?? {}) as Record<string, string>;

  const aba = String(data.aba ?? 'bugs');
  const ehBug = aba === 'bugs';
  const filtros = (data.filtros ?? {}) as Record<string, string>;
  const lista = (data.lista ?? {}) as Dict;
  const linhas = (lista.linhas ?? []) as Dict[];
  const contagens = (data.contagens ?? {}) as Record<string, number>;
  const grupos = (data.grupos ?? []) as Array<{ valor: string; rotulo: string }>;
  const pesos = (data.pesos ?? []) as Array<{ valor: string; rotulo: string }>;
  const resumo = (data.resumo ?? {}) as Dict;
  const detalhe = data.detalhe && typeof data.detalhe === 'object' ? (data.detalhe as Dict) : null;
  const erro = String(data.erro ?? '');
  const confirmar = Number(data.confirmar ?? 0) | 0;

  // Pesos indexados pelo valor gravado (rótulo e cor sem varrer a lista a cada linha).
  const mapaPeso = ehBug ? sev : prio;
  // Categorias: o que não estiver no catálogo aparece cru e em caixa alta.
  const mapaGrupo: Record<string, string> = {};
  for (const g of grupos) mapaGrupo[String(g.valor)] = String(g.rotulo);
  const rotuloGrupo = (v: string) => mapaGrupo[v] ?? v.toUpperCase();

  const agora = agoraUnix();

  // ------------------------------------------------------------------------- links
  // O recorte de agora, sem o que é padrão (ordem `recentes`, página 1).
  const recorte: Record<string, string> = { aba };
  for (const k of ['q', 'status', 'grupo', 'peso']) if ((filtros[k] ?? '') !== '') recorte[k] = String(filtros[k]);
  if ((filtros.ord ?? 'recentes') !== 'recentes') recorte.ord = String(filtros.ord);
  if ((Number(filtros.pag ?? 1) | 0) > 1) recorte.pag = String(filtros.pag);
  // O relatório aberto acompanha paginação e ordenação.
  if (detalhe !== null) recorte.ver = String(detalhe.id);

  /** Link para o mesmo recorte com as trocas de `over`; valor '' remove o parâmetro (ksort no fim). */
  const linkPara = (over: Record<string, string>): string => {
    const q: Record<string, string> = { ...recorte, ...over };
    const chaves = Object.keys(q)
      .filter((k) => String(q[k]) !== '')
      .sort();
    const out: Record<string, string> = {};
    for (const k of chaves) out[k] = q[k]!;
    return url('admin/relatorios', out);
  };
  /** Troca um filtro e VOLTA para a página 1. */
  const linkFiltro = (over: Record<string, string>): string => linkPara({ pag: '', ver: '', ...over });

  const temFiltro = (filtros.q ?? '') !== '' || (filtros.status ?? '') !== '' || (filtros.grupo ?? '') !== '' || (filtros.peso ?? '') !== '';

  // ------------------------------------------------------------------- formatadores
  /** "12/09 · 14h32" — ano só quando não é o ano corrente. */
  const quando = (ts: number): string =>
    formatarData(ts, 'Y') === formatarData(agora, 'Y') ? formatarData(ts, 'd/m · H') + 'h' + formatarData(ts, 'i') : formatarData(ts, 'd/m/Y');

  /** "há 5 min" / "há 3 dias". */
  const desde = (ts: number): string => {
    const sg = Math.max(0, agora - ts);
    if (sg < 60) return 'agora';
    if (sg < 3600) return 'há ' + Math.floor(sg / 60) + ' min';
    if (sg < 86400) return 'há ' + Math.floor(sg / 3600) + ' h';
    const d = Math.floor(sg / 86400);
    return 'há ' + d + (d === 1 ? ' dia' : ' dias');
  };

  /** Selo de estado: cor E palavra, sempre. */
  const selo = (status: string): SafeHtml => {
    const m = (estados[status] ?? {}) as Dict;
    return html`<span class="adm-badge adm-badge--${String(m.tom ?? 'muted')}" title="${String(m.ajuda ?? '')}">${String(m.rotulo ?? status.toUpperCase())}</span>`;
  };

  /** Selo de gravidade/prioridade (sem ponto). */
  const seloPeso = (valor: string): SafeHtml => {
    const m = (mapaPeso[valor] ?? {}) as Dict;
    return html`<span class="adm-badge adm-badge--nodot adm-badge--${String(m.tom ?? 'muted')}">${String(m.rotulo ?? valor.toUpperCase())}</span>`;
  };

  const limparBtn = html`<a class="adm-btn adm-btn--ghost adm-btn--sm" href="${url('admin/relatorios', { aba })}">
            ${icon('filter_alt_off')}<span>${micro.limpar ?? 'LIMPAR'}</span>
          </a>`;

  // ------------------------------------------------------------------------- abas
  const navAbas = join(
    Object.entries(abas).map(([chave, meta]) => {
      const on = chave === aba;
      // O número da aba é a FILA daquela tabela, não o total.
      const fila = Number(resumo[chave === 'bugs' ? 'bugs_abertos' : 'tickets_abertos'] ?? 0) | 0;
      return html`
      <a class="rel-tab${on ? ' is-active' : ''}" href="${url('admin/relatorios', { aba: chave })}"
         data-adm-recarrega ${on ? html`aria-current="page"` : ''}>
        ${icon(String(meta.icone ?? 'description'), 'rel-tab__ico')}
        <span class="rel-tab__txt">${String(meta.label ?? chave)}</span>
        <span class="rel-tab__n num${fila > 0 ? ' is-hot' : ''}">${num(fila)}</span>
      </a>`;
    }),
  );

  // ----------------------------------------------------------------- filtros (form)
  const buscaRot = micro[ehBug ? 'busca_bugs' : 'busca_suporte'];
  const gruposRot = micro[ehBug ? 'todas_areas' : 'todas_cats'];
  const pesosRot = micro[ehBug ? 'todas_sev' : 'todas_prio'];
  const form = html`
      <form class="adm-toolbar rel-bar" method="get" action="${url('admin/relatorios')}"
            data-adm-preservar="filtros">
        <input type="hidden" name="aba" value="${aba}">
        <input type="hidden" name="status" value="${filtros.status ?? ''}">

        <label class="adm-search adm-toolbar__grow">
          <span class="sr-only">${buscaRot ?? 'Buscar'}</span>
          ${icon('search')}
          <input class="adm-input" type="search" name="q" value="${filtros.q ?? ''}"
                 maxlength="80" placeholder="${buscaRot ?? ''}">
        </label>

        <label class="rel-bar__sel">
          <span class="sr-only">${gruposRot ?? ''}</span>
          <select class="adm-select" name="grupo">
            <option value="">${gruposRot ?? 'TODAS'}</option>
            ${join(
              grupos.map(
                (g) => html`
              <option value="${g.valor}"${(filtros.grupo ?? '') === g.valor ? ' selected' : ''}>
                ${g.rotulo}
              </option>`,
              ),
            )}
          </select>
        </label>

        <label class="rel-bar__sel">
          <span class="sr-only">${pesosRot ?? ''}</span>
          <select class="adm-select" name="peso">
            <option value="">${pesosRot ?? 'TODAS'}</option>
            ${join(
              pesos.map(
                (p) => html`
              <option value="${p.valor}"${(filtros.peso ?? '') === p.valor ? ' selected' : ''}>
                ${p.rotulo}
              </option>`,
              ),
            )}
          </select>
        </label>

        <label class="rel-bar__sel">
          <span class="sr-only">Ordenar</span>
          <select class="adm-select" name="ord">
            ${join(
              Object.entries(ordens).map(
                ([valor, rotulo]) => html`
              <option value="${valor}"${(filtros.ord ?? 'recentes') === valor ? ' selected' : ''}>
                ${rotulo}
              </option>`,
              ),
            )}
          </select>
        </label>

        <button class="adm-btn adm-btn--primary adm-btn--sm" type="submit">${icon('filter_alt')}<span>FILTRAR</span></button>
        ${temFiltro ? limparBtn : ''}
      </form>`;

  // ------------------------------------------------------------------ chips de estado
  const totalEstados = Object.values(contagens).reduce((a, n) => a + (Number(n) | 0), 0);
  const chips = html`
      <div class="rel-chips" role="group" aria-label="Filtrar por estado">
        <a class="rel-chip${(filtros.status ?? '') === '' ? ' is-active' : ''}"
           href="${linkFiltro({ status: '' })}">
          <span>${micro.todos_status ?? 'TODOS'}</span>
          <b class="num">${num(totalEstados)}</b>
        </a>
        ${join(
          Object.entries(estados).map(([chave, meta]) => {
            const n = Number(contagens[chave] ?? 0) | 0;
            const on = (filtros.status ?? '') === chave;
            // Estado com zero continua VISÍVEL e clicável, só apagado.
            return html`
          <a class="rel-chip rel-chip--${String(meta.tom ?? 'muted')}${on ? ' is-active' : ''}${n === 0 ? ' is-zero' : ''}"
             href="${linkFiltro({ status: chave })}" title="${String(meta.ajuda ?? '')}">
            <span>${String(meta.rotulo ?? chave)}</span>
            <b class="num">${num(n)}</b>
          </a>`;
          }),
        )}
      </div>`;

  // ------------------------------------------------------------------------ tabela
  const corpoLista =
    linhas.length === 0
      ? html`
          <div class="adm-empty">
            ${icon(temFiltro ? 'search_off' : ehBug ? 'bug_report' : 'support_agent')}
            <p class="adm-empty__txt">${vazios[temFiltro ? 'filtro' : aba] ?? ''}</p>
            ${temFiltro ? limparBtn : ''}
          </div>`
      : html`
          <div class="adm-tablewrap">
            <table class="adm-table rel-table">
              <thead>
                <tr>
                  <th>${ehBug ? 'CÓDIGO' : 'PROTOCOLO'}</th>
                  <th>${ehBug ? 'RELATÓRIO' : 'CHAMADO'}</th>
                  <th>${ehBug ? 'GRAVIDADE' : 'PRIORIDADE'}</th>
                  <th>ESTADO</th>
                  <th class="tr">ABERTO</th>
                </tr>
              </thead>
              <tbody>
                ${join(
                  linhas.map((l) => {
                    const aberto = detalhe !== null && (Number(detalhe.id) | 0) === (Number(l.id) | 0);
                    const rg = rotuloGrupo(String(l.grupo));
                    const jogador = String(l.jogador);
                    // Linha de apoio: JOGADOR · CATEGORIA (a frase inteira vai no title).
                    const apoio = (jogador !== '' ? jogador : String(micro.conta_removida ?? '')) + ' · ' + rg;
                    return html`
                  <tr class="rel-row${aberto ? ' is-open' : ''}">
                    <td class="rel-row__code">
                      <a class="rel-row__link" href="${linkPara({ ver: String(l.id), confirmar: '' })}"
                         title="${micro.abrir ?? 'Abrir'}">
                        <span class="num">${String(l.codigo)}</span>
                      </a>
                    </td>
                    <td class="rel-row__what">
                      <strong title="${String(l.titulo)}">${String(l.titulo)}</strong>
                      <small title="${apoio}">
                        ${
                          jogador !== ''
                            ? html`${icon('person', 'rel-row__ico')}${jogador}`
                            : html`${icon('person_off', 'rel-row__ico')}${micro.conta_removida ?? 'conta removida'}`
                        }
                        <span class="rel-row__grupo">· ${rg}</span>
                      </small>
                    </td>
                    <td>${seloPeso(String(l.peso))}</td>
                    <td>${selo(String(l.status))}</td>
                    <td class="tr num" title="${quando(Number(l.criado) | 0)}">${desde(Number(l.criado) | 0)}</td>
                  </tr>`;
                  }),
                )}
              </tbody>
            </table>
          </div>`;

  // ---------------------------------------------------------------------- paginação
  let rodape: SafeHtml | string = '';
  if ((Number(lista.total ?? 0) | 0) > 0) {
    const pag = Number(lista.pagina) | 0;
    const pags = Number(lista.paginas) | 0;
    let botoes: SafeHtml | string = '';
    if (pags > 1) {
      // Janela curta em volta da página atual.
      const ini = Math.max(1, Math.min(pag - 2, pags - 4));
      const fim = Math.min(pags, Math.max(pag + 2, 5));
      const meio: SafeHtml[] = [];
      for (let p = ini; p <= fim; p++) {
        meio.push(html`
              <a class="adm-pager__btn${p === pag ? ' is-active' : ''}"
                 href="${linkPara({ pag: String(p) })}"${p === pag ? html` aria-current="page"` : ''}>${String(p)}</a>`);
      }
      botoes = html`
              <a class="adm-pager__btn${pag <= 1 ? ' is-disabled' : ''}"
                 href="${linkPara({ pag: String(Math.max(1, pag - 1)) })}" aria-label="Página anterior">${icon('chevron_left')}</a>
              ${
                ini > 1
                  ? html`<a class="adm-pager__btn" href="${linkPara({ pag: '1' })}">1</a>
                ${ini > 2 ? html`<span class="adm-pager__gap">…</span>` : ''}`
                  : ''
              }
              ${join(meio)}
              ${
                fim < pags
                  ? html`${fim < pags - 1 ? html`<span class="adm-pager__gap">…</span>` : ''}
                <a class="adm-pager__btn" href="${linkPara({ pag: String(pags) })}">${String(pags)}</a>`
                  : ''
              }
              <a class="adm-pager__btn${pag >= pags ? ' is-disabled' : ''}"
                 href="${linkPara({ pag: String(Math.min(pags, pag + 1)) })}" aria-label="Próxima página">${icon('chevron_right')}</a>`;
    }
    rodape = html`
        <div class="adm-card__foot rel-foot">
          <nav class="adm-pager" aria-label="Páginas">
            <span class="adm-pager__info" data-adm-total>
              ${sprintf(String(micro.pager ?? '%s–%s de %s'), num(Number(lista.de) | 0), num(Number(lista.ate) | 0), num(Number(lista.total) | 0))}
            </span>
            ${botoes}
          </nav>
        </div>`;
  }

  return html`<div class="rel">

  <!-- ==================================================================== ABAS -->
  <nav class="rel-tabs" aria-label="Tipo de relatório">
    ${navAbas}
  </nav>

  <div class="rel__grid">

    <!-- ================================================================ LISTA -->
    <!--eh:lista-->
    <section class="adm-card rel-list">

      <!-- filtros: um GET só, para o recorte inteiro caber na URL -->
      ${form}

      <!-- chips de estado: o filtro mais usado, com a contagem do recorte atual -->
      ${chips}

      <div class="adm-card__body adm-card__body--flush rel-list__body">
        ${corpoLista}
      </div>
      ${rodape}
    </section>
    <!--/eh:lista-->

    <!-- =============================================================== DETALHE -->
    <aside class="adm-card rel-detail">
      ${detalhe === null ? html`
        <div class="adm-empty rel-detail__none">
          ${icon('article')}
          <p class="adm-empty__txt">${vazios.detalhe ?? ''}</p>
        </div>` : painelDetalhe(detalhe)}
    </aside>
  </div>
</div>`;

  // ======================================================================= detalhe
  function painelDetalhe(d: Dict): SafeHtml {
    const st = String(d.status);
    const encerrado = (estados[st] ?? {}) as Dict;
    const podeApagar = Boolean(encerrado.encerra);
    const confirmaEste = confirmar === (Number(d.id) | 0);
    // O erro só marca o campo quando é DESTE formulário.
    const erroEstado = ['status_invalido', 'status_igual'].includes(erro) ? erro : '';
    const jogador = String(d.jogador);
    const conta = String(d.conta);
    const descricao = String(d.descricao ?? '');
    const passos = String(d.passos ?? '');
    const escondidos = join(FILTROS_POST.map((k) => html`
              <input type="hidden" name="${k}" value="${filtros[k] ?? ''}">`));

    const pe = confirmaEste
      ? html`
            <form class="rel-danger" method="post" action="${url('admin/relatorios/excluir')}">
              ${csrfField()}
              <input type="hidden" name="aba" value="${aba}">
              <input type="hidden" name="id" value="${String(d.id)}">
              ${escondidos}
              <p class="rel-danger__ask">
                ${icon('warning')}
                <span>${sprintf(String(micro.excluir_confirma ?? 'Excluir %s?'), String(d.codigo))}</span>
              </p>
              <p class="rel-danger__note">${micro.excluir_ajuda ?? ''}</p>
              <div class="adm-btns">
                <button class="adm-btn adm-btn--danger adm-btn--sm" type="submit">
                  ${icon('delete_forever')}<span>${micro.excluir ?? 'EXCLUIR'}</span>
                </button>
                <a class="adm-btn adm-btn--ghost adm-btn--sm" href="${linkPara({ ver: String(d.id), confirmar: '' })}">CANCELAR</a>
              </div>
            </form>`
      : html`
            <form class="rel-move" method="post" action="${url('admin/relatorios/salvar')}">
              ${csrfField()}
              <input type="hidden" name="aba" value="${aba}">
              <input type="hidden" name="id" value="${String(d.id)}">
              ${escondidos}

              <fieldset class="rel-move__set${erroEstado !== '' ? ' is-invalid' : ''}">
                <legend class="adm-label">${micro.mudar ?? 'MUDAR ESTADO'}</legend>
                ${erroEstado !== '' ? html`
                  <p class="adm-error">${icon('error')}<span>${erros[erroEstado] ?? ''}</span></p>` : ''}
                <div class="rel-move__opts">
                  ${join(
                    Object.entries(estados).map(([chave, meta]) => {
                      const atual = chave === st;
                      return html`
                    <button class="rel-move__op rel-move__op--${String(meta.tom ?? 'muted')}${atual ? ' is-current' : ''}"
                            type="submit" name="novo" value="${chave}"
                            title="${String(meta.ajuda ?? '')}"${atual ? html` disabled aria-current="true"` : ''}>
                      ${icon(String(meta.icone ?? 'chevron_right'))}
                      <span>${String(meta.rotulo ?? chave)}</span>
                    </button>`;
                    }),
                  )}
                </div>
              </fieldset>
            </form>

            <div class="rel-detail__danger">
              ${
                podeApagar
                  ? html`
                <a class="adm-btn adm-btn--danger adm-btn--sm" href="${linkPara({ ver: String(d.id), confirmar: String(d.id) })}">
                  ${icon('delete')}<span>${micro.excluir ?? 'EXCLUIR'}</span>
                </a>`
                  : html`
                <span class="adm-btn adm-btn--danger adm-btn--sm is-disabled" aria-disabled="true">
                  ${icon('delete')}<span>${micro.excluir ?? 'EXCLUIR'}</span>
                </span>
                <span class="adm-hint rel-detail__lock">
                  ${icon('lock')}${micro.excluir_travado ?? ''}
                </span>`
              }
            </div>`;

    return html`
        <div class="adm-card__head rel-detail__head">
          <h2 class="adm-card__title">
            <span class="num">${String(d.codigo)}</span>
            <small>${rotuloGrupo(String(d.grupo))}</small>
          </h2>
          <div class="adm-card__tools">
            ${selo(st)}
            <a class="adm-btn adm-btn--ghost adm-btn--sm adm-btn--icon" href="${linkPara({ ver: '', confirmar: '' })}"
               aria-label="Fechar detalhe">${icon('close')}</a>
          </div>
        </div>

        <div class="adm-card__body rel-detail__body scroll-y">
          <h3 class="rel-detail__title">${String(d.titulo)}</h3>

          <dl class="adm-dl rel-detail__meta">
            <dt>${micro.jogador ?? 'JOGADOR'}</dt>
            <dd>
              ${
                jogador !== ''
                  ? html`${jogador}${conta !== '' && conta !== jogador ? html`
                  <small class="adm-meta">(${conta})</small>` : ''}`
                  : html`<span class="adm-meta">${micro.conta_removida ?? ''}</span>`
              }
            </dd>

            <dt>${ehBug ? 'GRAVIDADE' : 'PRIORIDADE'}</dt>
            <dd>${seloPeso(String(d.peso))}</dd>

            <dt>${micro.aberto_em ?? 'ABERTO EM'}</dt>
            <dd class="num">${quando(Number(d.criado) | 0)} <small class="adm-meta">${desde(Number(d.criado) | 0)}</small></dd>

            ${
              ehBug
                ? html`
              <dt>${micro.encerrado_em ?? 'ENCERRADO EM'}</dt>
              <dd class="num">
                ${d.encerrado ? quando(Number(d.encerrado) | 0) : html`<span class="adm-meta">— ${micro.em_aberto ?? ''}</span>`}
              </dd>`
                : html`
              <dt>${micro.atualizado_em ?? 'ATUALIZADO EM'}</dt>
              <dd class="num">${quando(Number(d.atualizado ?? d.criado) | 0)}</dd>`
            }

            <dt>LOGS</dt>
            <dd>${micro[d.logs ? 'logs_on' : 'logs_off'] ?? ''}</dd>
          </dl>

          <h4 class="rel-detail__lbl">${micro[ehBug ? 'descricao' : 'mensagem'] ?? 'DESCRIÇÃO'}</h4>
          <p class="rel-detail__txt${descricao === '' ? ' is-void' : ''}">
            ${descricao !== '' ? descricao : (vazios.sem_texto ?? '')}
          </p>

          ${
            ehBug
              ? html`
            <h4 class="rel-detail__lbl">${micro.passos ?? 'PASSOS'}</h4>
            <p class="rel-detail__txt${passos === '' ? ' is-void' : ''}">
              ${passos !== '' ? passos : (vazios.sem_texto ?? '')}
            </p>`
              : ''
          }
        </div>

        <div class="adm-card__foot rel-detail__foot">
          ${pe}
        </div>`;
  }
}
