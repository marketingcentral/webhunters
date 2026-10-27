/**
 * Tela ADMIN · API (/admin/api) — app/Views/pages/admin-api.php. Layout 'admin'.
 *
 * A view não consulta banco e não decide regra: tudo chega pronto de
 * controllers/admin/api-controller.ts. Aqui só há apresentação.
 *
 * De propósito (igual ao PHP): o segredo aparece UMA vez, com o aviso antes do valor; escopo
 * perigoso tem aviso colado na caixa; a referência é gerada pela própria API; tudo funciona
 * sem JavaScript (copiar e "marcar todos" só melhoram; revogar é um POST comum).
 */
import { csrfField, icon, num, url } from '../../core/helpers';
import { html, join, type SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { formatarData, paraUnix } from '../../repo/admin-repo';

/** 'AAAA-MM-DD HH:MM:SS' -> '12/09/26 14:33'. Vazio vira travessão: não se inventa data. */
const dh = (sql: string): string => (sql === '' ? '—' : formatarData(paraUnix(sql), 'd/m/y H:i'));

/** Badge com cor E texto. */
function tom(t: string): string {
  switch (t) {
    case 'ok':
      return 'adm-badge--ok';
    case 'warn':
      return 'adm-badge--warn';
    case 'danger':
      return 'adm-badge--danger';
    case 'info':
      return 'adm-badge--info';
    default:
      return 'adm-badge--muted';
  }
}

/** Cor da faixa de status HTTP no log. */
function tomStatus(s: number): string {
  if (s < 400) return 'adm-badge--ok';
  if (s === 429) return 'adm-badge--purple';
  if (s === 401 || s === 403) return 'adm-badge--warn';
  if (s >= 500) return 'adm-badge--danger';
  return 'adm-badge--info';
}

export function adminApi(s: Scope): SafeHtml {
  const d = s.data;
  const cromo = (d.cromo ?? {}) as Dict;
  const micro = (cromo.micro ?? {}) as Dict;
  const avisos = (cromo.avisos_escopo ?? {}) as Record<string, string>;
  const cEst = (cromo.estados ?? {}) as Dict;
  const cSt = (cromo.status ?? {}) as Dict;
  const ajuda = (cromo.ajuda ?? {}) as Dict;

  const chaves = (d.chaves ?? { linhas: [], total: 0, pagina: 1, paginas: 1, falha: false }) as Dict;
  const contagens = (d.contagens ?? {}) as Dict;
  const estado = String(d.estado ?? '');
  const log = (d.log ?? { linhas: [], total: 0, pagina: 1, paginas: 1, falha: false }) as Dict;
  const filtroLog = (d.filtro_log ?? {}) as Dict;
  const resumo = (d.resumo ?? {}) as Dict;
  const escopos = (d.escopos ?? []) as Dict[];
  const referencia = (d.referencia ?? []) as Dict[];
  const segredo = d.segredo && typeof d.segredo === 'object' ? (d.segredo as Dict) : null;
  const form = d.form && typeof d.form === 'object' ? (d.form as Dict) : null;
  const origem = String(d.origem ?? '');
  const limPadrao = Number(d.limite_padrao ?? 120) | 0;
  const limMax = Number(d.limite_max ?? 6000) | 0;
  const temAviso = (esc: string) => Object.prototype.hasOwnProperty.call(avisos, esc);

  /** URL desta tela preservando os filtros correntes. */
  const aqui = (mais: Record<string, string | null> = {}): string => {
    const q: Record<string, string> = {};
    if (estado !== '') q.estado = estado;
    if ((Number(filtroLog.chave ?? 0) | 0) > 0) q.chave = String(filtroLog.chave);
    if ((filtroLog.status ?? '') !== '') q.status = String(filtroLog.status);
    for (const [k, v] of Object.entries(mais)) {
      if (v === null || v === '') delete q[k];
      else q[k] = String(v);
    }
    return url('admin/api', q);
  };

  /** Valor que o operador digitou e que voltou com o erro. */
  const v = (nome: string): string => String(((form?.valores ?? {}) as Dict)[nome] ?? '');
  const erro = (nome: string): string => String(((form?.erros ?? {}) as Dict)[nome] ?? '');
  const marcados = (((form?.valores ?? {}) as Dict).scopes ?? []) as string[];

  const erroCampo = (nome: string) =>
    erro(nome) !== '' ? html`
              <p class="adm-error">${icon('error')}<span>${erro(nome)}</span></p>` : '';

  // ======================================================= SEGREDO (uma vez só)
  const blocoSegredo =
    segredo !== null && String(segredo.chave ?? '') !== ''
      ? html`
    <section class="api-segredo" role="alert" aria-labelledby="api-segredo-t">
      <div class="api-segredo__head">
        ${icon('key', 'api-segredo__ico')}
        <div>
          <h2 class="api-segredo__t" id="api-segredo-t">Chave criada: ${segredo.nome}</h2>
          <p class="api-segredo__aviso">${String(micro.uma_vez ?? '')}</p>
        </div>
      </div>
      <div class="api-segredo__val">
        <input class="adm-input api-segredo__campo" type="text" readonly
               id="api-nova-chave" value="${segredo.chave}"
               aria-label="Chave da API recém-criada"
               data-api-copiar-alvo>
        <button class="adm-btn adm-btn--primary" type="button" data-api-copiar="#api-nova-chave">
          ${icon('content_copy')}<span>Copiar</span>
        </button>
      </div>
      <p class="api-segredo__pe">${String(micro.perdeu ?? '')}</p>
    </section>`
      : '';

  // ================================================================ INDICADORES
  const recusadas = Number(resumo.recusadas ?? 0) | 0;
  const cards = [
    { ico: 'key', tom: 'lime', lbl: 'Chaves ativas', val: num(Number(contagens.ativas ?? 0) | 0), sub: num(Number(contagens.total ?? 0) | 0) + ' no total' },
    { ico: 'block', tom: 'red', lbl: 'Revogadas', val: num(Number(contagens.revogadas ?? 0) | 0), sub: 'não voltam a funcionar' },
    { ico: 'swap_horiz', tom: 'cyan', lbl: 'Chamadas (24 h)', val: num(Number(resumo.total ?? 0) | 0), sub: num(Number(resumo.ms_medio ?? 0) | 0) + ' ms em média' },
    { ico: 'gpp_maybe', tom: recusadas > 0 ? 'amber' : 'lime', lbl: 'Recusadas (24 h)', val: num(recusadas), sub: '401, 403 e 429' },
  ];

  // ================================================================ NOVA CHAVE
  const grupos = join(
    escopos.map(
      (g) => html`
              <div class="api-escopos__grupo">
                <p class="api-escopos__rec">
                  ${icon(String(g.icone), 'api-escopos__ico')}
                  <span class="api-escopos__nome">${String(g.rotulo)}</span>
                  <span class="api-escopos__desc">${String(g.descricao)}</span>
                </p>
                <div class="api-escopos__linha">
                  ${join(
                    ((g.escopos ?? []) as Dict[]).map((esc) => {
                      const valor = String(esc.valor);
                      const aviso = String(avisos[valor] ?? '');
                      return html`
                    <label class="api-escopo${aviso !== '' ? ' api-escopo--perigo' : ''}">
                      <input type="checkbox" name="scopes[]" value="${valor}"
                             data-api-escopo
                             ${marcados.includes(valor) ? 'checked' : ''}>
                      <span class="api-escopo__cod">${valor}</span>
                      ${aviso !== '' ? html`
                        <span class="api-escopo__aviso">${icon('warning')}${aviso}</span>` : ''}
                    </label>`;
                    }),
                  )}
                </div>
              </div>`,
    ),
  );

  // ===================================================================== CHAVES
  const linhasChaves = (chaves.linhas ?? []) as Dict[];
  let corpoChaves: SafeHtml;
  if (chaves.falha) {
    corpoChaves = html`
        <div class="adm-empty">
          <p class="adm-empty__title">${icon('cloud_off')} Leitura indisponível</p>
          <p class="adm-empty__txt">${String(micro.falha_leitura ?? '')}</p>
        </div>`;
  } else if (linhasChaves.length === 0) {
    corpoChaves = html`
        <div class="adm-empty">
          <p class="adm-empty__title">${icon('key_off')} ${String(micro.sem_chaves ?? 'Nenhuma chave.')}</p>
          <p class="adm-empty__txt">Crie a primeira no formulário acima.</p>
        </div>`;
  } else {
    const linhas = join(
      linhasChaves.map((k) => {
        const est = (cEst[k.estado] ?? { rotulo: k.estado, tom: 'muted' }) as Dict;
        const esc = (k.escopos ?? []) as string[];
        return html`
                <tr class="${k.estado !== 'ativa' ? 'is-off' : ''}">
                  <td>
                    <span class="api-tbl__nome">${String(k.nome)}</span>
                    <code class="api-tbl__pref">${String(k.prefixo)}…</code>
                    ${String(k.nota) !== '' ? html`
                      <span class="api-tbl__nota">${String(k.nota)}</span>` : ''}
                    <span class="api-tbl__meta">
                      criada ${dh(String(k.criada_em))}
                      ${String(k.criador) !== '' ? 'por ' + String(k.criador) : ''}
                    </span>
                  </td>
                  <td>
                    ${esc.length === 0
                      ? html`
                      <span class="adm-badge adm-badge--muted">nenhum</span>`
                      : html`
                      <div class="api-tbl__escopos">
                        ${join(
                          esc.map(
                            (x) => html`
                          <span class="api-chip${temAviso(x) ? ' api-chip--perigo' : ''}"
                                ${temAviso(x) ? html`title="${String(avisos[x])}"` : ''}>${x}</span>`,
                          ),
                        )}
                      </div>`}
                  </td>
                  <td class="num">
                    ${num(Number(k.limite) | 0)}<small>/min</small>
                    ${!k.limite_proprio ? html`<span class="api-tbl__meta">padrão</span>` : ''}
                  </td>
                  <td>
                    ${dh(String(k.usada_em))}
                    <span class="api-tbl__meta">
                      ${num(Number(k.usos) | 0)} chamada(s)${String(k.ultimo_ip) !== '' ? ' · ' + String(k.ultimo_ip) : ''}
                    </span>
                  </td>
                  <td>
                    <span class="adm-badge ${tom(String(est.tom))}">${String(est.rotulo)}</span>
                    ${String(k.expira_em) !== '' ? html`
                      <span class="api-tbl__meta">vence ${dh(String(k.expira_em))}</span>` : ''}
                    ${String(k.revogada_em) !== '' ? html`
                      <span class="api-tbl__meta">
                        em ${dh(String(k.revogada_em))}${String(k.revogador) !== '' ? ' por ' + String(k.revogador) : ''}
                      </span>` : ''}
                  </td>
                  <td class="adm-table__acts">
                    <a class="adm-btn adm-btn--sm adm-btn--ghost"
                       href="${aqui({ chave: String(k.id), lpag: null })}#api-log">
                      ${icon('receipt_long')}<span>Chamadas</span>
                    </a>
                    ${k.estado !== 'revogada' ? html`
                      <form method="post" action="${url('admin/api/revogar')}" class="api-revogar">
                        ${csrfField()}
                        <input type="hidden" name="id" value="${String(k.id)}">
                        <button class="adm-btn adm-btn--sm adm-btn--danger" type="submit"
                                title="${String(micro.revogar_aviso ?? '')}">
                          ${icon('block')}<span>Revogar</span>
                        </button>
                      </form>` : ''}
                  </td>
                </tr>`;
      }),
    );
    const paginasK = Number(chaves.paginas) | 0;
    const pagerK =
      paginasK > 1
        ? html`
          <nav class="adm-pager" aria-label="Páginas de chaves">
            ${join(
              Array.from({ length: paginasK }, (_x, j) => j + 1).map(
                (i) => html`
              <a class="adm-pager__btn${i === (Number(chaves.pagina) | 0) ? ' is-active' : ''}"
                 href="${aqui({ pag: String(i) })}">${String(i)}</a>`,
              ),
            )}
            <span class="adm-pager__info">${num(Number(chaves.total) | 0)} chave(s)</span>
          </nav>`
        : '';
    corpoChaves = html`
        <div class="adm-table__wrap">
          <table class="adm-table api-tbl">
            <thead>
              <tr>
                <th scope="col">Chave</th>
                <th scope="col">Escopos</th>
                <th scope="col">Limite</th>
                <th scope="col">Último uso</th>
                <th scope="col">Estado</th>
                <th scope="col" class="adm-table__acts">Ações</th>
              </tr>
            </thead>
            <tbody>
              ${linhas}
            </tbody>
          </table>
        </div>
${pagerK}`;
  }

  // ================================================================= REFERÊNCIA
  const blocoRef = join(
    referencia.map(
      (grupo) => html`
        <details class="api-ref__grupo">
          <summary class="api-ref__sum">
            ${icon(String(grupo.icone), 'api-ref__ico')}
            <span class="api-ref__nome">${String(grupo.rotulo)}</span>
            <code class="api-ref__base">/api/v1/${String(grupo.recurso)}</code>
            <span class="api-ref__desc">${String(grupo.descricao)}</span>
            <span class="api-ref__n">${String(((grupo.rotas ?? []) as Dict[]).length)}</span>
          </summary>
          <div class="adm-table__wrap">
            <table class="adm-table adm-table--compact api-ref__tbl">
              <thead>
                <tr>
                  <th scope="col">Método</th>
                  <th scope="col">Caminho</th>
                  <th scope="col">Escopo</th>
                  <th scope="col">O que faz</th>
                  <th scope="col">Exemplo</th>
                </tr>
              </thead>
              <tbody>
                ${join(
                  ((grupo.rotas ?? []) as Dict[]).map(
                    (r) => html`
                  <tr>
                    <td><span class="api-verbo api-verbo--${String(r.metodo).toLowerCase()}">${String(r.metodo)}</span></td>
                    <td><code class="api-ref__path">${String(r.caminho)}</code></td>
                    <td><span class="api-chip${temAviso(String(r.escopo)) ? ' api-chip--perigo' : ''}">${String(r.escopo)}</span></td>
                    <td>${String(r.resumo)}</td>
                    <td>
                      ${String(r.exemplo) !== ''
                        ? html`
                        <code class="api-ref__ex">${String(r.exemplo)}</code>`
                        : html`
                        <span class="api-tbl__meta">—</span>`}
                    </td>
                  </tr>`,
                  ),
                )}
              </tbody>
            </table>
          </div>
        </details>`,
    ),
  );

  // ======================================================================== LOG
  const linhasLog = (log.linhas ?? []) as Dict[];
  let corpoLog: SafeHtml;
  if (log.falha) {
    corpoLog = html`
        <div class="adm-empty">
          <p class="adm-empty__title">${icon('cloud_off')} Leitura indisponível</p>
          <p class="adm-empty__txt">${String(micro.falha_leitura ?? '')}</p>
        </div>`;
  } else if (linhasLog.length === 0) {
    corpoLog = html`
        <div class="adm-empty">
          <p class="adm-empty__title">${icon('inbox')} ${String(micro.sem_log ?? 'Nada aqui.')}</p>
          <p class="adm-empty__txt">Toda chamada entra aqui, inclusive as recusadas.</p>
        </div>`;
  } else {
    const linhas = join(
      linhasLog.map(
        (l) => html`
                <tr>
                  <td class="num">${dh(String(l.quando))}</td>
                  <td>
                    ${String(l.chave) !== ''
                      ? html`
                      <span class="api-tbl__nome">${String(l.chave)}</span>
                      <code class="api-tbl__pref">${String(l.prefixo)}</code>`
                      : String(l.prefixo) !== ''
                        ? html`
                      <code class="api-tbl__pref">${String(l.prefixo)}</code>
                      <span class="api-tbl__meta">não reconhecida</span>`
                        : html`
                      <span class="api-tbl__meta">sem chave</span>`}
                  </td>
                  <td>
                    <span class="api-verbo api-verbo--${String(l.metodo).toLowerCase()}">${String(l.metodo)}</span>
                    <code class="api-logtbl__path">${String(l.caminho)}</code>
                  </td>
                  <td>
                    <span class="adm-badge ${tomStatus(Number(l.status) | 0)}">${String(l.status)}</span>
                    ${String(l.erro) !== '' ? html`
                      <span class="api-tbl__meta">${String(l.erro)}</span>` : ''}
                  </td>
                  <td class="num">${String(l.ip)}</td>
                  <td class="num">${num(Number(l.ms) | 0)}</td>
                </tr>`,
      ),
    );
    const paginasL = Number(log.paginas) | 0;
    let pagerL: SafeHtml | string = '';
    if (paginasL > 1) {
      const p = Number(log.pagina) | 0;
      const ini = Math.max(1, p - 3);
      const fim = Math.min(paginasL, p + 3);
      const nums: number[] = [];
      for (let i = ini; i <= fim; i++) nums.push(i);
      pagerL = html`
          <nav class="adm-pager" aria-label="Páginas do log">
            ${ini > 1 ? html`<span class="adm-pager__gap">…</span>` : ''}
            ${join(
              nums.map(
                (i) => html`
              <a class="adm-pager__btn${i === p ? ' is-active' : ''}"
                 href="${aqui({ lpag: String(i) })}#api-log">${String(i)}</a>`,
              ),
            )}
            ${fim < paginasL ? html`<span class="adm-pager__gap">…</span>` : ''}
            <span class="adm-pager__info">${num(Number(log.total) | 0)} registro(s)</span>
          </nav>`;
    }
    corpoLog = html`
        <div class="adm-table__wrap">
          <table class="adm-table adm-table--compact api-logtbl">
            <thead>
              <tr>
                <th scope="col">Quando</th>
                <th scope="col">Chave</th>
                <th scope="col">Chamada</th>
                <th scope="col">Resposta</th>
                <th scope="col">IP</th>
                <th scope="col" class="num">ms</th>
              </tr>
            </thead>
            <tbody>
              ${linhas}
            </tbody>
          </table>
        </div>
${pagerL}`;
  }

  const filtroStatus = String(filtroLog.status ?? '');

  return html`
<div class="api">
${blocoSegredo}

  <section class="adm-grid adm-grid--4 api-kpis" aria-label="Resumo da API">
    ${join(
      cards.map(
        (c) => html`
      <div class="adm-kpi adm-kpi--${c.tom}">
        ${icon(c.ico, 'adm-kpi__ico')}
        <div class="adm-kpi__txt">
          <span class="adm-kpi__label">${c.lbl}</span>
          <span class="adm-kpi__value num">${c.val}</span>
          <span class="adm-kpi__delta">${c.sub}</span>
        </div>
      </div>`,
      ),
    )}
  </section>

  <div class="api-cols">

    <section class="adm-card api-nova">
      <header class="adm-card__head">
        <h2 class="adm-card__title">${icon('add_moderator')} Nova chave</h2>
      </header>
      <div class="adm-card__body">
        <form class="adm-form" method="post" action="${url('admin/api/criar')}">
          ${csrfField()}

          <div class="adm-field${erro('name') !== '' ? ' is-invalid' : ''}">
            <label class="adm-label" for="f-name">Nome<span class="adm-label__req">*</span></label>
            <input class="adm-input" type="text" id="f-name" name="name" maxlength="80" required
                   placeholder="Integração Unreal — servidor de produção" value="${v('name')}">
            ${erroCampo('name')}
            <p class="adm-hint">É por este nome que você vai reconhecer a chave daqui a seis meses.</p>
          </div>

          <fieldset class="api-escopos${erro('scopes') !== '' ? ' is-invalid' : ''}">
            <legend class="adm-label">Escopos<span class="adm-label__req">*</span></legend>
            ${erroCampo('scopes')}
            <p class="adm-hint">Marque só o que a integração precisa. Ler e escrever são concedidos separadamente.</p>
${grupos}

            <button class="adm-btn adm-btn--ghost adm-btn--sm api-escopos__todos" type="button"
                    data-api-todos hidden>${icon('done_all')}<span>Marcar todos</span></button>
          </fieldset>

          <div class="adm-form__row">
            <div class="adm-field${erro('rate_limit_per_min') !== '' ? ' is-invalid' : ''}">
              <label class="adm-label" for="f-lim">Limite por minuto</label>
              <input class="adm-input" type="number" id="f-lim" name="rate_limit_per_min"
                     min="0" max="${String(limMax)}" value="${v('rate_limit_per_min')}"
                     placeholder="${String(limPadrao)}">
              ${erroCampo('rate_limit_per_min')}
              <p class="adm-hint">Vazio usa o padrão (${String(limPadrao)}/min). Acima disso, a chamada recebe 429.</p>
            </div>

            <div class="adm-field${erro('expires_at') !== '' ? ' is-invalid' : ''}">
              <label class="adm-label" for="f-exp">Vence em</label>
              <input class="adm-input" type="date" id="f-exp" name="expires_at" value="${v('expires_at')}">
              ${erroCampo('expires_at')}
              <p class="adm-hint">Vazio = não vence. Chave de fornecedor deveria ter prazo.</p>
            </div>
          </div>

          <div class="adm-field">
            <label class="adm-label" for="f-note">Observação</label>
            <input class="adm-input" type="text" id="f-note" name="note" maxlength="240"
                   placeholder="Quem pediu, para quê, e onde ela está configurada." value="${v('note')}">
          </div>

          <div class="adm-form__actions">
            <button class="adm-btn adm-btn--primary" type="submit">${icon('key')}<span>Criar chave</span></button>
          </div>
        </form>
      </div>
    </section>

    <section class="adm-card api-ajuda">
      <header class="adm-card__head">
        <h2 class="adm-card__title">${icon('terminal')} ${String(ajuda.titulo ?? 'Como chamar')}</h2>
      </header>
      <div class="adm-card__body">
        <ol class="api-passos">
          ${join(((ajuda.passos ?? []) as unknown[]).map((passo) => html`
            <li>${String(passo)}</li>`))}
        </ol>
        <pre class="api-cmd"><code>curl -s ${origem + url('api/v1/health')}

curl -s ${origem + url('api/v1/players')}?limit=5 \\
  -H "Authorization: Bearer SUA_CHAVE"</code></pre>
        <p class="adm-hint api-ajuda__pe">${icon('lock')} ${String(micro.bearer ?? '')}</p>
      </div>
    </section>
  </div>

  <section class="adm-card api-chaves">
    <header class="adm-card__head">
      <h2 class="adm-card__title">${icon('vpn_key')} Chaves</h2>
      <div class="adm-card__tools">
        <a class="adm-btn adm-btn--sm${estado === '' ? ' is-active' : ''}" href="${aqui({ estado: null, pag: null })}">Todas</a>
        ${join(
          Object.entries({ ativa: 'Ativas', revogada: 'Revogadas', vencida: 'Vencidas' }).map(
            ([k, rot]) => html`
        <a class="adm-btn adm-btn--sm${estado === k ? ' is-active' : ''}" href="${aqui({ estado: k, pag: null })}">${rot}</a>`,
          ),
        )}
      </div>
    </header>

    <div class="adm-card__body adm-card__body--flush">
${corpoChaves}
    </div>
  </section>

  <section class="adm-card api-ref">
    <header class="adm-card__head">
      <h2 class="adm-card__title">${icon('menu_book')} Referência dos endpoints</h2>
      <p class="adm-meta">Gerada pela própria API — é a mesma tabela que o despachante usa para atender.</p>
    </header>
    <div class="adm-card__body adm-card__body--flush">
${blocoRef}
    </div>
  </section>

  <section class="adm-card api-log" id="api-log">
    <header class="adm-card__head">
      <h2 class="adm-card__title">${icon('receipt_long')} Log de requisições</h2>
      <div class="adm-card__tools">
        <a class="adm-btn adm-btn--sm${filtroStatus === '' ? ' is-active' : ''}"
           href="${aqui({ status: null, lpag: null })}#api-log">Todas</a>
        ${join(
          Object.entries(cSt).map(
            ([k, meta]) => html`
        <a class="adm-btn adm-btn--sm${filtroStatus === k ? ' is-active' : ''}"
           href="${aqui({ status: k, lpag: null })}#api-log">${String((meta as Dict).rotulo)}</a>`,
          ),
        )}
        ${(Number(filtroLog.chave ?? 0) | 0) > 0
          ? html`
        <a class="adm-btn adm-btn--sm adm-btn--ghost" href="${aqui({ chave: null, lpag: null })}#api-log">
          ${icon('filter_alt_off')}<span>Todas as chaves</span>
        </a>`
          : ''}
      </div>
    </header>

    <div class="adm-card__body adm-card__body--flush">
${corpoLog}
    </div>
  </section>
</div>
`;
}
