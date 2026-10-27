/**
 * Tela ADMIN · BANIMENTOS (/admin/banimentos) — app/Views/pages/admin-banimentos.php. Layout 'admin'.
 *
 * A view NÃO consulta o banco e NÃO decide o que é permitido: as linhas vêm prontas de
 * repo/ban-repo.ts e as permissões chegam resolvidas do controller (data.pode_apagar,
 * painel.bloqueio). Aqui só há FORMATAÇÃO e a montagem dos endereços de filtro.
 *
 * O formulário é ENDEREÇO (?painel=revogar&id=12), não modal: nenhuma linha depende de script.
 *
 * Datas: as colunas guardam UTC (datetime('now') do SQLite); a tela mostra no horário de
 * Brasília, o mesmo relógio em que o operador digita o prazo.
 */
import * as Fuso from '../../core/fuso';
import { csrfField, icon, num, url } from '../../core/helpers';
import { e, html, join, raw, type SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { paraUnix } from '../../repo/admin-repo';

const S = (v: unknown) => String(v ?? '');

/** 2026-09-20 14:30:00 (UTC) -> 20/09/2026 11:30 (Brasília). Traço quando não há data. */
function dataBr(v: unknown): string {
  const ts = v === null || v === undefined || v === '' ? 0 : paraUnix(v);
  return ts === 0 ? '—' : Fuso.date('d/m/Y H:i', ts);
}

/** Quanto falta (ou passou) em relação a agora, em uma unidade só. */
function distancia(v: unknown): string {
  const ts = v === null || v === undefined || v === '' ? 0 : paraUnix(v);
  if (ts === 0) return '';
  const agora = Math.floor(Date.now() / 1000);
  const s = Math.abs(ts - agora);
  const passou = ts < agora;
  // round() do PHP arredonda .5 para longe do zero; com s >= 0 é o Math.round.
  const u: [number, string] =
    s < 3600
      ? [Math.max(1, Math.round(s / 60)), 'min']
      : s < 86400
        ? [Math.round(s / 3600), 'h']
        : s < 2592000
          ? [Math.round(s / 86400), 'd']
          : [Math.round(s / 2592000), 'meses'];
  return (passou ? 'há ' : 'faltam ') + u[0] + ' ' + u[1];
}

export function adminBanimentos(s: Scope): SafeHtml {
  const data = s.data;
  const cromo: Dict = data.cromo && typeof data.cromo === 'object' ? data.cromo : {};
  const colunas: Dict = cromo.colunas ?? {};
  const eCromo: Dict = cromo.estados ?? {};
  const vazios: Dict = cromo.vazios ?? {};

  const lista: Dict = data.lista ?? {};
  const linhas: Dict[] = lista.linhas ?? [];
  const filtros: Dict = data.filtros ?? {};
  const contagens: Dict = data.contagens ?? {};
  const tipos: Record<string, Dict> = data.tipos ?? {};
  const painel: Dict | null = data.painel && typeof data.painel === 'object' ? data.painel : null;
  const form: Dict = data.form ?? {};
  const erros: Record<string, string> = form.erros ?? {};
  const valores: Record<string, string> = form.valores ?? {};
  const sugestoes: Dict[] = data.sugestoes ?? [];
  const podeApagar = Boolean(data.pode_apagar);

  const temFiltro = S(filtros.busca) !== '' || S(filtros.estado) !== '' || S(filtros.tipo) !== '';

  /** Endereço desta tela com os filtros atuais, trocando só o que vier em `muda`. */
  const rota = (muda: Record<string, string | null> = {}): string => {
    const base: Record<string, string> = {
      q: S(filtros.busca),
      estado: S(filtros.estado),
      tipo: S(filtros.tipo),
      ordem: (filtros.ordem ?? 'recente') !== 'recente' ? S(filtros.ordem) : '',
      p: (Number(filtros.pagina ?? 1) | 0) > 1 ? S(filtros.pagina) : '',
    };
    const q: Record<string, string> = {};
    for (const [k, v] of Object.entries(base)) if (v !== '') q[k] = v;
    for (const [k, v] of Object.entries(muda)) {
      if (v === null || v === '') delete q[k];
      else q[k] = String(v);
    }
    return url('admin/banimentos', q);
  };

  /** Estado derivado -> rótulo + tom, tudo vindo de app/Data/admin-banimentos.php. */
  const selo = (estado: string): SafeHtml => {
    const x: Dict = eCromo[estado] ?? {};
    return html`<span class="adm-badge adm-badge--${S(x.tom ?? 'muted')}" title="${S(x.ajuda)}">${S(x.rotulo ?? estado)}</span>`;
  };

  /** Campo em erro ganha a classe do sistema E a frase. */
  const erroDe = (campo: string): SafeHtml | '' =>
    erros[campo] !== undefined ? html`<span class="adm-error">${icon('error')}<span>${S(erros[campo])}</span></span>` : '';
  const erroOuDica = (campo: string, dica: unknown): SafeHtml => erroDe(campo) || html`<span class="adm-hint">${S(dica)}</span>`;

  /** Valor que o operador digitou e o servidor recusou. */
  const antigo = (campo: string, padrao = ''): string => S(valores[campo] ?? padrao);

  /* Campos ocultos que levam os filtros atuais pelo POST. */
  const ocultosFiltro = raw(
    '' +
      `<input type="hidden" name="f_q" value="${e(S(filtros.busca))}">` +
      `<input type="hidden" name="f_estado" value="${e(S(filtros.estado))}">` +
      `<input type="hidden" name="f_tipo" value="${e(S(filtros.tipo))}">` +
      `<input type="hidden" name="f_ordem" value="${e(S(filtros.ordem))}">` +
      `<input type="hidden" name="f_p" value="${e(S(filtros.pagina ?? 1))}">`,
  );

  // ---------------------------------------------------------------- 1. NÚMEROS
  const kpis = join(
    ((cromo.kpis ?? []) as Dict[]).map((kpi) => {
      const chave = S(kpi.chave);
      const tom = S(kpi.tom ?? 'lime').replace(/[^a-z]/g, '') || 'lime';
      const valor = Number(contagens[chave] ?? 0) | 0;
      // Só os três estados viram filtro — "contas fora do ar" conta contas, não linhas.
      const filtravel = ['ativo', 'expirado', 'revogado'].includes(chave);
      const ligado = filtravel && S(filtros.estado) === chave;
      const miolo = html`
          <span class="adm-kpi__ico">${icon(S(kpi.icone ?? 'gavel'))}</span>
          <span class="adm-kpi__txt">
            <span class="adm-kpi__label">${S(kpi.rotulo)}</span>
            <span class="adm-kpi__value num">${num(valor)}</span>
          </span>
          ${filtravel ? html`<span class="ban-kpi__acao">${ligado ? 'filtrando' : 'filtrar'}</span>` : ''}`;
      return filtravel
        ? html`<a class="adm-kpi adm-kpi--${tom} ban-kpi${ligado ? ' is-active' : ''}"
           href="${rota({ estado: ligado ? null : chave, p: null })}"
           ${ligado ? raw('aria-current="true"') : ''}>${miolo}</a>`
        : html`<div class="adm-kpi adm-kpi--${tom} ban-kpi ban-kpi--fixo">${miolo}</div>`;
    }),
  );

  // ------------------------------------------------- 2. PAINEL DE AÇÃO (pela URL)
  let blocoPainel: SafeHtml | '' = '';
  if (painel !== null) {
    const modo = S(painel.modo);
    const alvo: Dict | null = painel.ban && typeof painel.ban === 'object' ? painel.ban : null;

    if (modo === 'novo') {
      const f: Dict = cromo.form ?? {};
      const tipoAtual = antigo('tipo', 'temp');
      blocoPainel = html`
      <section class="adm-card ban-painel ban-painel--novo" id="painel">
        <div class="adm-card__head">
          <h2 class="adm-card__title">${icon('gavel', 'ban-painel__ico')}${S(f.titulo ?? 'Aplicar punição')}
            <small>${S(f.ajuda)}</small>
          </h2>
          <div class="adm-card__tools">
            <a class="adm-btn adm-btn--sm adm-btn--ghost" href="${rota()}">${icon('close')}<span>Fechar</span></a>
          </div>
        </div>
        <div class="adm-card__body">
          <form class="adm-form ban-form" method="post" action="${url('admin/banimentos/salvar')}">
            ${csrfField()}
            <input type="hidden" name="acao" value="aplicar">
            ${ocultosFiltro}

            <div class="adm-form__row">
              <div class="adm-field${erros.alvo !== undefined ? ' is-invalid' : ''}">
                <label class="adm-label" for="ban-alvo">${S(f.alvo?.rotulo ?? 'Conta')}<span class="adm-label__req">*</span></label>
                <input class="adm-input" id="ban-alvo" name="alvo" list="ban-contas" autocomplete="off"
                       maxlength="160" required
                       placeholder="usuário, e-mail ou ID"
                       value="${antigo('alvo')}">
                <datalist id="ban-contas">
                  ${join(
                    sugestoes.map(
                      (x) =>
                        html`<option value="${S(x.username)}">#${S(x.id)} · ${S(x.email)}${S(x.role) !== 'player' ? ' · ' + S(x.role) : ''}</option>`,
                    ),
                  )}
                </datalist>
                ${erroOuDica('alvo', f.alvo?.ajuda)}
              </div>

              <div class="adm-field ban-field--prazo${erros.prazo !== undefined ? ' is-invalid' : ''}">
                <label class="adm-label" for="ban-prazo">${S(f.prazo?.rotulo ?? 'Termina em')}</label>
                <input class="adm-input" id="ban-prazo" name="prazo" type="datetime-local"
                       min="${S(data.agora_local)}"
                       value="${antigo('prazo')}">
                ${erroOuDica('prazo', f.prazo?.ajuda)}
              </div>
            </div>

            <div class="adm-field${erros.tipo !== undefined ? ' is-invalid' : ''}">
              <span class="adm-label">${S(f.tipo?.rotulo ?? 'Tipo de punição')}<span class="adm-label__req">*</span></span>
              <div class="ban-tipos" role="radiogroup" aria-label="${S(f.tipo?.rotulo ?? 'Tipo')}">
                ${join(
                  Object.entries(tipos).map(
                    ([chave, t]) => html`
                  <label class="ban-tipo ban-tipo--${S(t.tom)}" for="ban-tipo-${chave}">
                    <input type="radio" id="ban-tipo-${chave}" name="tipo"
                           value="${chave}"${tipoAtual === chave ? raw(' checked') : ''}>
                    <span class="ban-tipo__box">
                      <strong>${S(t.rotulo)}</strong>
                      <small>${!t.prazo ? 'Sem prazo — vale até alguém revogar.' : 'Acaba sozinho na data escolhida.'}</small>
                      <em>${S(t.status) === 'suspended' ? 'users.status = suspended' : 'users.status = banned'}</em>
                    </span>
                  </label>`,
                  ),
                )}
              </div>
              ${erroDe('tipo')}
            </div>

            <div class="adm-field${erros.motivo !== undefined ? ' is-invalid' : ''}">
              <label class="adm-label" for="ban-motivo">${S(f.motivo?.rotulo ?? 'Motivo')}<span class="adm-label__req">*</span></label>
              <textarea class="adm-textarea" id="ban-motivo" name="motivo" maxlength="500" required
                        placeholder="Ex.: uso de terceiros na Floresta Verde, confirmado por três denúncias e replay.">${antigo('motivo')}</textarea>
              ${erroOuDica('motivo', f.motivo?.ajuda)}
            </div>

            <div class="adm-form__actions">
              <button class="adm-btn adm-btn--primary" type="submit">${icon('gavel')}<span>${S(f.enviar ?? 'Aplicar punição')}</span></button>
              <a class="adm-btn adm-btn--ghost" href="${rota()}">${S(f.cancelar ?? 'Cancelar')}</a>
            </div>
          </form>
        </div>
      </section>`;
    } else if (modo === 'revogar' && alvo !== null) {
      const r: Dict = cromo.revogar ?? {};
      const motivo = S(alvo.reason);
      blocoPainel = html`
      <section class="adm-card ban-painel ban-painel--revogar" id="painel">
        <div class="adm-card__head">
          <h2 class="adm-card__title">${icon('undo', 'ban-painel__ico')}${S(r.titulo ?? 'Revogar punição')}
            <small>${S(r.ajuda)}</small>
          </h2>
          <div class="adm-card__tools">
            <a class="adm-btn adm-btn--sm adm-btn--ghost" href="${rota()}">${icon('close')}<span>Fechar</span></a>
          </div>
        </div>
        <div class="adm-card__body ban-painel__corpo">
          <dl class="adm-dl ban-resumo">
            <dt>Registro</dt><dd class="num">#${S(alvo.id)}</dd>
            <dt>Conta</dt><dd><strong>${S(alvo.alvo_nome)}</strong> <span class="adm-meta">${S(alvo.alvo_email)}</span></dd>
            <dt>Tipo</dt><dd>${S(tipos[S(alvo.kind)]?.rotulo ?? alvo.kind)}</dd>
            <dt>Aplicada</dt><dd>${dataBr(alvo.created_at)} por ${S(alvo.admin_nome ?? 'conta removida')}</dd>
            <dt>Prazo</dt><dd>${alvo.expires_at === null || alvo.expires_at === undefined ? 'sem prazo' : dataBr(alvo.expires_at) + ' · ' + distancia(alvo.expires_at)}</dd>
            <dt>Motivo</dt><dd class="ban-resumo__motivo">${motivo !== '' ? motivo : raw('<span class="adm-meta">sem motivo registrado</span>')}</dd>
          </dl>

          <form class="adm-form ban-form" method="post" action="${url('admin/banimentos/salvar')}">
            ${csrfField()}
            <input type="hidden" name="acao" value="revogar">
            <input type="hidden" name="id" value="${S(alvo.id)}">
            ${ocultosFiltro}

            <div class="adm-field${erros.motivo !== undefined ? ' is-invalid' : ''}">
              <label class="adm-label" for="ban-rev-motivo">${S(r.motivo?.rotulo ?? 'Motivo da revogação')}<span class="adm-label__req">*</span></label>
              <textarea class="adm-textarea" id="ban-rev-motivo" name="motivo" maxlength="500" required
                        placeholder="Ex.: recurso aceito — o replay mostra falha de rede, não trapaça.">${antigo('motivo')}</textarea>
              ${erroOuDica('motivo', r.motivo?.ajuda)}
            </div>

            <div class="adm-form__actions">
              <button class="adm-btn adm-btn--primary" type="submit">${icon('lock_open')}<span>${S(r.enviar ?? 'Revogar agora')}</span></button>
              <a class="adm-btn adm-btn--ghost" href="${rota()}">${S(r.cancelar ?? 'Manter punição')}</a>
            </div>
          </form>
        </div>
      </section>`;
    } else if (modo === 'remover' && alvo !== null) {
      const x: Dict = cromo.remover ?? {};
      const trava = S(painel.bloqueio);
      blocoPainel = html`
      <section class="adm-card ban-painel ban-painel--remover" id="painel">
        <div class="adm-card__head">
          <h2 class="adm-card__title">${icon('delete_forever', 'ban-painel__ico')}${S(x.titulo ?? 'Apagar do histórico')}
            <small>${S(x.ajuda)}</small>
          </h2>
          <div class="adm-card__tools">
            <a class="adm-btn adm-btn--sm adm-btn--ghost" href="${rota()}">${icon('close')}<span>Fechar</span></a>
          </div>
        </div>
        <div class="adm-card__body ban-painel__corpo">
          <dl class="adm-dl ban-resumo">
            <dt>Registro</dt><dd class="num">#${S(alvo.id)}</dd>
            <dt>Conta</dt><dd><strong>${S(alvo.alvo_nome)}</strong></dd>
            <dt>Estado</dt><dd>${selo(S(alvo.estado))}</dd>
            <dt>Motivo</dt><dd class="ban-resumo__motivo">${S(alvo.reason)}</dd>
          </dl>

          ${
            trava !== ''
              ? html`
            <p class="ban-trava">${icon('block')}<span>${trava}</span></p>
            <div class="adm-form__actions">
              ${
                S(alvo.estado) === 'ativo' && podeApagar
                  ? html`<a class="adm-btn adm-btn--primary" href="${rota({ painel: 'revogar', id: S(alvo.id) })}">${icon('undo')}<span>Revogar primeiro</span></a>`
                  : ''
              }
              <a class="adm-btn adm-btn--ghost" href="${rota()}">${S(x.cancelar ?? 'Manter registro')}</a>
            </div>`
              : html`
            <form class="adm-form" method="post" action="${url('admin/banimentos/excluir')}">
              ${csrfField()}
              <input type="hidden" name="id" value="${S(alvo.id)}">
              ${ocultosFiltro}
              <div class="adm-form__actions">
                <button class="adm-btn adm-btn--danger" type="submit">${icon('delete_forever')}<span>${S(x.enviar ?? 'Apagar definitivamente')}</span></button>
                <a class="adm-btn adm-btn--ghost" href="${rota()}">${S(x.cancelar ?? 'Manter registro')}</a>
              </div>
            </form>`
          }
        </div>
      </section>`;
    }
  }

  // ---------------------------------------------------------------- 3. HISTÓRICO
  const total = Number(lista.total ?? 0) | 0;

  let corpo: SafeHtml;
  if (linhas.length === 0) {
    const v: Dict = vazios[temFiltro ? 'filtro' : 'limpo'] ?? {};
    corpo = html`
        <div class="adm-empty">
          ${icon(S(v.icone ?? 'inbox'))}
          <p class="adm-empty__title">${S(v.titulo)}</p>
          <p class="adm-empty__txt">${S(v.texto)}</p>
          ${
            temFiltro
              ? html`<a class="adm-btn adm-btn--ghost" href="${url('admin/banimentos')}">${icon('filter_alt_off')}<span>Limpar filtros</span></a>`
              : html`<a class="adm-btn adm-btn--ghost" href="${rota({ painel: 'novo' })}#painel">${icon('gavel')}<span>Aplicar a primeira punição</span></a>`
          }
        </div>`;
  } else {
    const corpoLinhas = join(
      linhas.map((b) => {
        const estado = S(b.estado);
        const tipo: Dict = tipos[S(b.kind)] ?? {};
        const motivo = S(b.reason).trim();
        const ehAdmin = ['admin', 'owner'].includes(S(b.alvo_papel));
        const revMotivo = S(b.revoke_reason).trim();
        const email = S(b.alvo_email);
        const adminNome = S(b.admin_nome);
        let acoes: SafeHtml;
        if (estado === 'ativo') {
          acoes = html`<a class="adm-btn adm-btn--sm adm-btn--ghost" href="${rota({ painel: 'revogar', id: S(b.id) })}#painel"
                         title="Revogar a punição de ${S(b.alvo_nome)}">${icon('undo')}<span>Revogar</span></a>`;
        } else if (podeApagar) {
          acoes = html`<a class="adm-btn adm-btn--sm adm-btn--danger" href="${rota({ painel: 'remover', id: S(b.id) })}#painel"
                         title="Apagar o registro #${S(b.id)} do histórico">${icon('delete')}<span>Apagar</span></a>`;
        } else {
          acoes = html`<span class="ban-t__encerrado" title="Punição encerrada — não há ação pendente">encerrado</span>`;
        }
        return html`
                <tr class="ban-t__linha ban-t__linha--${estado}">
                  <td class="ban-t__conta">
                    <span class="adm-table__who">
                      <span class="adm-table__ava">${icon(ehAdmin ? 'shield_person' : 'person')}</span>
                      <span>
                        <strong>${S(b.alvo_nome)}</strong>
                        <small>${email !== '' ? email : 'sem e-mail'}</small>
                      </span>
                    </span>
                  </td>
                  <td class="ban-t__tipo">
                    <span class="adm-badge adm-badge--${S(tipo.tom ?? 'muted')} adm-badge--nodot">${S(tipo.rotulo ?? b.kind)}</span>
                  </td>
                  <td class="ban-t__motivo">
                    <span class="ban-t__clamp" title="${motivo}">${motivo !== '' ? motivo : '—'}</span>
                    ${
                      estado === 'revogado' && revMotivo !== ''
                        ? html`<span class="ban-t__revmotivo" title="${S(b.revoke_reason)}">${icon('undo')}${S(b.revoke_reason)}</span>`
                        : ''
                    }
                  </td>
                  <td class="ban-t__admin">
                    <strong>${adminNome !== '' ? adminNome : 'sistema'}</strong>
                    <small>${dataBr(b.created_at)}</small>
                  </td>
                  <td class="ban-t__prazo num">
                    ${
                      b.expires_at === null || b.expires_at === undefined
                        ? html`<span class="ban-t__semprazo">definitivo</span>`
                        : html`<strong>${dataBr(b.expires_at)}</strong>
                      <small>${distancia(b.expires_at)}</small>`
                    }
                  </td>
                  <td class="ban-t__estado">
                    ${selo(estado)}
                    ${estado === 'revogado' ? html`<small class="adm-meta">por ${S(b.revogador_nome ?? '—')}</small>` : ''}
                  </td>
                  <td class="adm-table__acts">
                    ${acoes}
                  </td>
                </tr>`;
      }),
    );
    corpo = html`
        <div class="adm-tablewrap">
          <table class="adm-table ban-t">
            <thead>
              <tr>
                <th scope="col">${S(colunas.conta ?? 'Conta')}</th>
                <th scope="col">${S(colunas.tipo ?? 'Tipo')}</th>
                <th scope="col">${S(colunas.motivo ?? 'Motivo')}</th>
                <th scope="col">${S(colunas.aplicado ?? 'Aplicado por')}</th>
                <th scope="col">${S(colunas.prazo ?? 'Prazo')}</th>
                <th scope="col">${S(colunas.estado ?? 'Estado')}</th>
                <th scope="col" class="adm-table__acts"><span class="sr-only">Ações</span></th>
              </tr>
            </thead>
            <tbody>
              ${corpoLinhas}
            </tbody>
          </table>
        </div>`;
  }

  // Paginação: janela de 5 páginas em volta da atual.
  let pager: SafeHtml | '' = '';
  if ((Number(lista.paginas ?? 1) | 0) > 1) {
    const pag = Number(lista.pagina) | 0;
    const max = Number(lista.paginas) | 0;
    const de = Math.max(1, Math.min(pag - 2, max - 4));
    const ate = Math.min(max, Math.max(pag + 2, 5));
    const nums: SafeHtml[] = [];
    for (let i = de; i <= ate; i++) {
      nums.push(html`<a class="adm-pager__btn${i === pag ? ' is-active' : ''}" href="${rota({ p: i > 1 ? String(i) : null })}"
               ${i === pag ? raw('aria-current="page"') : ''}>${String(i)}</a>`);
    }
    pager = html`
      <div class="adm-card__foot">
        <nav class="adm-pager" aria-label="Paginação do histórico">
          <span class="adm-pager__info">
            ${num(lista.primeiro)}–${num(lista.ultimo)} de ${num(lista.total)}
          </span>
          <a class="adm-pager__btn${pag <= 1 ? ' is-disabled' : ''}" href="${rota({ p: String(Math.max(1, pag - 1)) })}"
             aria-label="Página anterior">${icon('chevron_left')}</a>
          ${de > 1 ? html`<a class="adm-pager__btn" href="${rota({ p: null })}">1</a>
            ${de > 2 ? raw('<span class="adm-pager__gap">…</span>') : ''}` : ''}
          ${join(nums)}
          ${ate < max ? html`${ate < max - 1 ? raw('<span class="adm-pager__gap">…</span>') : ''}
            <a class="adm-pager__btn" href="${rota({ p: String(max) })}">${String(max)}</a>` : ''}
          <a class="adm-pager__btn${pag >= max ? ' is-disabled' : ''}" href="${rota({ p: String(Math.min(max, pag + 1)) })}"
             aria-label="Próxima página">${icon('chevron_right')}</a>
        </nav>
      </div>`;
  }

  const ordemAtual = S(filtros.ordem ?? 'recente');

  return html`<div class="ban">

  <!-- ================================================================ 1. NÚMEROS -->
  <section class="adm-grid adm-grid--4 ban-kpis" aria-label="Resumo das punições">
    ${kpis}
  </section>

  <!-- ================================================ 2. PAINEL DE AÇÃO (pela URL) -->
  ${blocoPainel}

  <!-- ================================================================ 3. HISTÓRICO -->
  ${raw('<!--eh:lista-->')}
  <section class="adm-card ban-lista">
    <div class="adm-card__head">
      <h2 class="adm-card__title">Histórico de punições
        <small data-adm-total>${num(total)} registro${total === 1 ? '' : 's'}${temFiltro ? ' com os filtros atuais' : ' no total'}</small>
      </h2>
      <div class="adm-card__tools">
        <a class="adm-btn adm-btn--primary adm-btn--sm" href="${rota({ painel: 'novo' })}#painel">${icon('gavel')}<span>Aplicar punição</span></a>
      </div>
    </div>

    <form class="adm-toolbar" method="get" action="${url('admin/banimentos')}"
          data-adm-preservar="filtros">
      <input type="hidden" name="estado" value="${S(filtros.estado)}">
      <label class="adm-search adm-toolbar__grow">
        <span class="sr-only">Buscar punição</span>
        ${icon('search')}
        <input class="adm-input" type="search" name="q" maxlength="80"
               value="${S(filtros.busca)}"
               placeholder="Conta, e-mail, motivo — ou #12 para um registro">
      </label>

      <label class="ban-campo">
        <span class="sr-only">Tipo</span>
        <select class="adm-select" name="tipo">
          <option value="">Todos os tipos</option>
          ${join(
            Object.entries(tipos).map(
              ([chave, t]) => html`<option value="${chave}"${S(filtros.tipo) === chave ? raw(' selected') : ''}>${S(t.rotulo)}</option>`,
            ),
          )}
        </select>
      </label>

      <label class="ban-campo">
        <span class="sr-only">Ordenar</span>
        <select class="adm-select" name="ordem">
          ${join(
            Object.entries((cromo.ordens ?? {}) as Record<string, string>).map(
              ([chave, rotulo]) => html`<option value="${chave}"${ordemAtual === chave ? raw(' selected') : ''}>${S(rotulo)}</option>`,
            ),
          )}
        </select>
      </label>

      <button class="adm-btn adm-btn--ghost" type="submit">${icon('filter_alt')}<span>Filtrar</span></button>
      ${
        temFiltro || ordemAtual !== 'recente'
          ? html`<a class="adm-btn adm-btn--ghost" href="${url('admin/banimentos')}">${icon('filter_alt_off')}<span>Limpar</span></a>`
          : ''
      }
    </form>

    <div class="adm-card__body adm-card__body--flush">
      ${corpo}
    </div>
    ${pager}
  </section>
  ${raw('<!--/eh:lista-->')}
</div>`;
}
