/**
 * Tela ADMIN · JOGADORES (/admin/jogadores) — app/Views/pages/admin-jogadores.php. Layout 'admin'.
 *
 * DOIS MODOS, decididos por data.modo:
 *   'lista' — o cadastro inteiro, com busca, filtros, ordenação e paginação (adminJogadoresLista);
 *   'ficha' — o dossiê de uma conta e os formulários que agem sobre ela (adminJogadoresFicha).
 * Compartilham todos os formatadores (selo de papel, selo de situação, data curta, relativo).
 *
 * A view não consulta o banco, não decide permissão e não calcula regra: tudo chega pronto de
 * controllers/admin/players-controller.ts. Regras de apresentação:
 *   1. estado é COR **e** TEXTO;
 *   2. número alinhado à direita e em fonte tabular (.tr + .num);
 *   3. botão que não pode ser usado não some — aparece desabilitado, com o motivo do lado.
 *
 * Datas: as colunas guardam UTC; dataHora() e o prazo do banimento usam o horário de Brasília
 * (core/fuso.ts), o mesmo relógio da tela de banimentos e o que o PHP mostrava.
 */
import * as Fuso from '../../core/fuso';
import { csrfField, icon, media, num, url } from '../../core/helpers';
import { html, join, raw, type SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { agoraUnix, formatarData, paraUnix } from '../../repo/admin-repo';

// =========================================================================== FORMATADORES

/** Selo com cor E texto. `tom` são os sufixos de .adm-badge-- já definidos em admin.css. */
function selo(texto: string, tom = 'muted', icone = ''): SafeHtml {
  tom = tom.toLowerCase().replace(/[^a-z]/g, '') || 'muted';
  return html`<span class="adm-badge adm-badge--${tom}${icone !== '' ? ' adm-badge--nodot' : ''}">${icone !== '' ? icon(icone) : ''}${texto}</span>`;
}

/** Selo do papel/da situação; valor fora do mapa aparece CRU, em cinza. */
function seloDe(mapa: Dict, valor: unknown): SafeHtml {
  const v = String(valor ?? '');
  const def = (mapa[v] ?? null) as Dict | null;
  return def === null ? selo(v !== '' ? v : '—', 'muted') : selo(String(def.label), String(def.tom ?? 'muted'), String(def.icone ?? ''));
}

/** 'd/m/Y H:i' ou travessão. Data vazia é ausência de fato, não zero. */
function dataHora(sql: unknown): string {
  const s = String(sql ?? '');
  if (s === '' || s.startsWith('0000')) return '—';
  const ts = paraUnix(s);
  return ts === 0 ? '—' : Fuso.date('d/m/Y H:i', ts);
}

/** "há 3 dias" / "em 2 h" — a distância no tempo. */
function relativo(sql: unknown, agora: number): string {
  const s = String(sql ?? '');
  if (s === '') return '';
  const ts = paraUnix(s);
  if (ts === 0) return '';
  const d = Math.abs(agora - ts);
  const futuro = ts > agora;
  let u: [number, string];
  if (d < 90) u = [d, 's'];
  else if (d < 5400) u = [Math.floor(d / 60), 'min'];
  else if (d < 172800) u = [Math.floor(d / 3600), 'h'];
  else if (d < 5184000) u = [Math.floor(d / 86400), 'd'];
  else u = [Math.floor(d / 2592000), 'mes'];
  const txt = u[0] + (u[1] === 'mes' ? (u[0] === 1 ? ' mês' : ' meses') : ' ' + u[1]);
  return (futuro ? 'em ' : 'há ') + txt;
}

/** Dinheiro do jogo (créditos/ganhos são inteiros, não centavos). */
const moeda = (n: number): string => num(n);

const int = (v: unknown): number => Math.trunc(Number(v ?? 0)) || 0;
const obj = (v: unknown): Dict => (v && typeof v === 'object' ? (v as Dict) : {});
const lista = (v: unknown): Dict[] => (Array.isArray(v) ? (v as Dict[]) : []);

/** Escopo comum às duas telas. */
interface Comum {
  cromo: Dict;
  titulos: Dict;
  rPapel: Dict;
  rSituac: Dict;
  rOrdens: Dict;
  rTipos: Dict;
  rConts: Dict;
  rMissao: Dict;
  rResult: Dict;
  rFaccao: Dict;
  vazios: Dict;
  micro: Dict;
  operador: Dict;
  erros: Record<string, string>;
  old: Dict;
  agora: number;
  erro: (campo: string) => SafeHtml;
  invalido: (campo: string) => string;
}

function comum(data: Dict): Comum {
  const cromo = obj(data.cromo);
  const erros = obj(data.erros) as Record<string, string>;
  return {
    cromo,
    titulos: obj(cromo.titulos),
    rPapel: obj(cromo.papeis),
    rSituac: obj(cromo.situacoes),
    rOrdens: obj(cromo.ordens),
    rTipos: obj(cromo.ban_tipos),
    rConts: obj(cromo.containers),
    rMissao: obj(cromo.missao_status),
    rResult: obj(cromo.partida_result),
    rFaccao: obj(cromo.faccoes),
    vazios: obj(cromo.vazios),
    micro: obj(cromo.micro),
    operador: obj(data.operador),
    erros,
    old: obj(data.old),
    agora: agoraUnix(),
    // Bloco de erro de um campo + a classe que pinta a borda.
    erro: (campo) => (campo in erros ? html`<span class="adm-error">${icon('error')}${String(erros[campo])}</span>` : raw('')),
    invalido: (campo) => (campo in erros ? ' is-invalid' : ''),
  };
}

/** Ponto de entrada da view: escolhe o modo. */
export function adminJogadores(s: Scope): SafeHtml {
  return String(s.data.modo ?? 'lista') === 'lista' ? adminJogadoresLista(s) : adminJogadoresFicha(s);
}

// ============================================================================= MODO LISTA

export function adminJogadoresLista(s: Scope): SafeHtml {
  const data = s.data;
  const c = comum(data);
  const { rPapel, rSituac, rOrdens, vazios, micro, titulos, cromo, operador, agora } = c;

  const lst = obj(data.lista);
  const linhas = lista(lst.linhas);
  const filtros = obj(data.filtros);
  const abas = obj(data.abas);
  const resumo = obj(data.resumo);
  const filtrado = Boolean(data.filtrado);

  const total = int(lst.total);
  const pagina = int(lst.pagina) || 1;
  const paginas = int(lst.paginas) || 1;
  const offset = int(lst.offset);

  /** URL da própria listagem preservando os filtros; o que vier null/'' some da query. */
  const urlLista = (troca: Record<string, string | number | null> = {}): string => {
    const q: Record<string, string> = {};
    const base: [string, string][] = [
      ['q', String(filtros.q ?? '')],
      ['papel', String(filtros.papel ?? '')],
      ['situacao', String(filtros.situacao ?? '')],
      ['ordem', String(filtros.ordem ?? 'recentes') === 'recentes' ? '' : String(filtros.ordem)],
      ['p', int(filtros.pagina ?? 1) > 1 ? String(int(filtros.pagina)) : ''],
    ];
    for (const [k, v] of base) if (v !== '') q[k] = v;
    for (const [k, v] of Object.entries(troca)) {
      if (v === null || v === '') delete q[k];
      else q[k] = String(v);
    }
    return url('admin/jogadores', q);
  };

  // Lista vazia por dois motivos diferentes: não há conta nenhuma, ou o filtro não achou nada.
  const vazio = linhas.length === 0 ? (filtrado ? obj(vazios.sem_resultado) : obj(vazios.sem_conta)) : null;

  const cartoes = [
    { ico: 'group', tom: 'lime', lbl: 'Contas no jogo', val: int(resumo.contas), sub: '' },
    { ico: 'shield_person', tom: 'cyan', lbl: 'Com acesso ao painel', val: int(resumo.equipe), sub: 'admins e dono' },
    { ico: 'person_add', tom: 'purple', lbl: 'Novas em 7 dias', val: int(resumo.novos7), sub: '' },
    { ico: 'gavel', tom: 'red', lbl: 'Banimentos em vigor', val: int(resumo.punidos), sub: '' },
  ];

  const ordemAtual = String(filtros.ordem ?? 'recentes');

  const linhasHtml = join(
    linhas.map((j) => {
      const jid = int(j.id);
      const eu = jid === int(operador.id);
      const avatar = String(j.avatar ?? '').trim();
      const apelido = String(j.display_name ?? '').trim();
      const email = String(j.email ?? '').trim();
      // Segunda linha: o nome no jogo (quando difere do login) e o e-mail.
      let sub = apelido !== '' && apelido !== String(j.username) ? apelido + ' · ' : '';
      sub += email !== '' ? email : String(micro.sem_email ?? 'sem e-mail');
      const ficha = url('admin/jogadores', { id: jid });
      const ultimo = String(j.last_login ?? '');
      const ate = String(j.ban_kind ?? '') === 'perma' ? 'sem prazo' : 'até ' + dataHora(j.ban_expires ?? '');
      return html`
            <tr>
              <th scope="row">
                <a class="adm-table__who pj-who" href="${ficha}">
                  ${avatar !== ''
                    ? html`<img src="${media(avatar)}" alt="" width="32" height="32" loading="lazy" decoding="async">`
                    : html`<span class="adm-table__ava pj-ava">${icon('person')}</span>`}
                  <span>
                    <strong>${String(j.username)}${eu ? raw(' <em class="pj-you">você</em>') : ''}</strong>
                    <small>${sub}</small>
                  </span>
                </a>
              </th>
              <td>${seloDe(rPapel, j.role)}</td>
              <td>
                ${seloDe(rSituac, j.status)}
                ${j.ban_id ? html`<small class="adm-meta pj-ban-meta" title="${String(j.ban_reason ?? '')}">${ate}</small>` : ''}
              </td>
              <td class="tr num">${num(int(j.level))}</td>
              <td class="tr num">${num(int(j.xp))}</td>
              <td>
                ${ultimo === ''
                  ? html`<span class="adm-meta">${String(micro.nunca_entrou ?? 'nunca entrou')}</span>`
                  : html`<span class="pj-when" title="${dataHora(ultimo)}">${relativo(ultimo, agora)}</span>
                  <small class="adm-meta">${dataHora(ultimo)}</small>`}
              </td>
              <td class="adm-table__acts">
                <a class="adm-btn adm-btn--sm" href="${ficha}">${icon('badge')}<span>Abrir ficha</span></a>
              </td>
            </tr>`;
    }),
  );

  // Uma janela de páginas em volta da atual.
  const pager = (): SafeHtml => {
    const ant = Math.max(1, pagina - 1);
    const pro = Math.min(paginas, pagina + 1);
    const ini = Math.max(1, Math.min(pagina - 2, paginas - 4));
    const fim = Math.min(paginas, Math.max(pagina + 2, 5));
    const nums: SafeHtml[] = [];
    for (let i = ini; i <= fim; i++) {
      nums.push(
        i === pagina
          ? html`<span class="adm-pager__btn is-active" aria-current="page">${String(i)}</span>`
          : html`<a class="adm-pager__btn" href="${urlLista({ p: i })}">${String(i)}</a>`,
      );
    }
    return html`
      <footer class="adm-card__foot">
        <nav class="adm-pager" aria-label="Paginação da lista de jogadores">
          <span class="adm-pager__info">
            ${num(offset + 1)}–${num(Math.min(offset + linhas.length, total))} de ${num(total)}
          </span>
          ${pagina > 1
            ? html`<a class="adm-pager__btn" href="${urlLista({ p: ant })}" aria-label="Página anterior">${icon('chevron_left')}</a>`
            : html`<span class="adm-pager__btn is-disabled">${icon('chevron_left')}</span>`}
          ${ini > 1
            ? html`<a class="adm-pager__btn" href="${urlLista({ p: 1 })}">1</a>
            ${ini > 2 ? raw('<span class="adm-pager__gap">…</span>') : ''}`
            : ''}
          ${join(nums)}
          ${fim < paginas
            ? html`${fim < paginas - 1 ? raw('<span class="adm-pager__gap">…</span>') : ''}
            <a class="adm-pager__btn" href="${urlLista({ p: paginas })}">${String(paginas)}</a>`
            : ''}
          ${pagina < paginas
            ? html`<a class="adm-pager__btn" href="${urlLista({ p: pro })}" aria-label="Próxima página">${icon('chevron_right')}</a>`
            : html`<span class="adm-pager__btn is-disabled">${icon('chevron_right')}</span>`}
        </nav>
      </footer>`;
  };

  return html`
<section class="pj-sum adm-grid adm-grid--4">
  ${join(
    cartoes.map(
      (k) => html`
    <article class="adm-kpi adm-kpi--${k.tom}">
      <span class="adm-kpi__ico">${icon(k.ico)}</span>
      <span class="adm-kpi__txt">
        <span class="adm-kpi__label">${k.lbl}</span>
        <span class="adm-kpi__value num">${num(k.val)}</span>
        ${k.sub !== '' ? html`<span class="adm-meta">${k.sub}</span>` : ''}
      </span>
    </article>`,
    ),
  )}
</section>

<!--eh:lista-->
<section class="adm-card pj-list">
  <header class="adm-card__head">
    <h2 class="adm-card__title">
      ${String(titulos.lista ?? 'Contas do jogo')}
      <small data-adm-total>${num(total)} ${total === 1 ? 'conta encontrada' : 'contas encontradas'}${filtrado ? ' com os filtros atuais' : ''}</small>
    </h2>
  </header>

  <nav class="pj-tabs" aria-label="Filtrar por situação da conta">
    ${join(
      lista(cromo.abas).map((aba) => {
        const chave = String(aba.chave ?? '');
        const n = chave === '' ? int(abas.total) : int(abas[chave]);
        const ativa = chave === String(filtros.situacao ?? '');
        return html`
      <a class="pj-tab${ativa ? ' is-active' : ''}"
         href="${urlLista({ situacao: chave, p: null })}"${ativa ? raw(' aria-current="page"') : ''}>
        <span>${String(aba.label ?? '')}</span>
        <span class="pj-tab__n num">${num(n)}</span>
      </a>`;
      }),
    )}
  </nav>

  <form class="adm-toolbar" method="get" action="${url('admin/jogadores')}" role="search"
        data-adm-preservar="filtros">
    <input type="hidden" name="situacao" value="${String(filtros.situacao ?? '')}">

    <label class="adm-search adm-toolbar__grow">
      <span class="sr-only">${String(micro.busca ?? 'Buscar')}</span>
      ${icon('search')}
      <input class="adm-input" type="search" name="q" value="${String(filtros.q ?? '')}"
             placeholder="${String(micro.busca ?? 'Buscar')}" maxlength="60">
    </label>

    <label class="pj-pick">
      <span class="sr-only">Papel</span>
      <select class="adm-select" name="papel">
        <option value="">Todos os papéis</option>
        ${join(
          Object.entries(rPapel).map(
            ([chave, def]) => html`
          <option value="${chave}"${String(filtros.papel ?? '') === chave ? raw(' selected') : ''}>
            ${String(obj(def).label ?? '')}
          </option>`,
          ),
        )}
      </select>
    </label>

    <label class="pj-pick">
      <span class="sr-only">Ordenar por</span>
      <select class="adm-select" name="ordem">
        ${join(
          Object.entries(rOrdens).map(
            ([chave, rot]) => html`
          <option value="${chave}"${ordemAtual === chave ? raw(' selected') : ''}>
            ${String(rot)}
          </option>`,
          ),
        )}
      </select>
    </label>

    <button class="adm-btn adm-btn--primary" type="submit">${icon('filter_alt')}<span>Aplicar</span></button>
    ${filtrado || ordemAtual !== 'recentes'
      ? html`<a class="adm-btn adm-btn--ghost" href="${url('admin/jogadores')}">${icon('backspace')}<span>Limpar</span></a>`
      : ''}
  </form>

  ${vazio !== null
    ? html`
    <div class="adm-empty">
      ${icon(filtrado ? 'search_off' : 'group_off')}
      <h3 class="adm-empty__title">${String(vazio.titulo ?? 'Sem contas')}</h3>
      <p class="adm-empty__txt">${String(vazio.txt ?? '')}</p>
      ${filtrado ? html`<a class="adm-btn adm-btn--ghost" href="${url('admin/jogadores')}">${icon('backspace')}<span>Limpar filtros</span></a>` : ''}
    </div>`
    : html`
    <div class="adm-tablewrap">
      <table class="adm-table pj-table">
        <caption class="sr-only">Contas do jogo, ${num(total)} no total</caption>
        <thead>
          <tr>
            <th scope="col">Jogador</th>
            <th scope="col">Papel</th>
            <th scope="col">Situação</th>
            <th scope="col" class="tr">Nível</th>
            <th scope="col" class="tr">XP</th>
            <th scope="col">Último acesso</th>
            <th scope="col" class="adm-table__acts"><span class="sr-only">Ações</span></th>
          </tr>
        </thead>
        <tbody>
          ${linhasHtml}
        </tbody>
      </table>
    </div>
    ${paginas > 1 ? pager() : ''}`}
</section>
<!--/eh:lista-->
`;
}

// ============================================================================= MODO FICHA

export function adminJogadoresFicha(s: Scope): SafeHtml {
  const data = s.data;
  const c = comum(data);
  const { titulos, rPapel, rSituac, rTipos, rConts, rMissao, rResult, rFaccao, vazios, micro, old, agora, erro, invalido } = c;

  const conta = obj(data.conta);
  const uid = int(conta.id);
  const eVoce = Boolean(data.e_voce);
  const podeAgir = Boolean(data.pode_agir);
  const papeisOk = (Array.isArray(data.papeis_ok) ? data.papeis_ok : []) as string[];
  const banAtivo = data.ban_ativo && typeof data.ban_ativo === 'object' ? (data.ban_ativo as Dict) : null;
  const bans = lista(data.bans);
  const inv = obj(data.inventario);
  const missoes = lista(data.missoes);
  const conq = obj(data.conquistas);
  const faccoes = lista(data.faccoes);
  const part = obj(data.partidas);
  const refs = obj(data.referencias) as Record<string, number>;

  const nome = String(conta.username ?? '');
  const avatar = String(conta.avatar ?? '').trim();

  // Uma frase, dita UMA vez, para o motivo de os formulários estarem travados.
  const travaMotivo = podeAgir ? '' : eVoce ? String(micro.conta_propria ?? '') : String(micro.sem_autoridade ?? '');
  const trava = travaMotivo !== '' ? html`<p class="pj-note pj-note--lock">${icon('lock')}<span>${travaMotivo}</span></p>` : '';

  const xp = int(conta.xp);
  const xpNext = int(conta.xp_next);
  const xpPct = xpNext > 0 ? Math.max(0, Math.min(100, Math.round((xp / xpNext) * 100))) : 0;

  const dis = podeAgir ? '' : raw(' disabled');
  const sel = (cond: boolean) => (cond ? raw(' selected') : '');
  const ultRel = relativo(conta.last_login ?? '', agora);

  const stats: [string, string, string][] = [
    ['Nível', num(int(conta.level)), ''],
    ['XP', num(xp), xpNext > 0 ? 'de ' + num(xpNext) : ''],
    ['Passe', num(int(conta.pass_level)), String(conta.pass_name ?? '')],
    ['Créditos', moeda(int(conta.credits)), ''],
    ['Cash', moeda(int(conta.cash)), ''],
    ['Pontos', num(int(conta.points)), ''],
    ['Habilidade', num(int(conta.skill_points)), 'pontos livres'],
    ['Sequência', num(int(conta.streak_days)), 'dias'],
  ];

  // ---------------------------------------------------------------- conta/acesso (selects)
  const papelAtual = String(conta.role ?? 'player');
  const papelSel = String(old.papel ?? papelAtual);
  const mostraAtual = !papeisOk.includes(papelAtual);
  const situAtual = String(conta.status ?? 'active');
  const situSel = String(old.situacao ?? situAtual);

  // --------------------------------------------------------------------------- punições
  const blocoBan = (): SafeHtml => {
    if (banAtivo !== null) {
      const perma = String(banAtivo.kind ?? 'temp') === 'perma';
      return html`
          <div class="pj-note pj-note--ban">
            ${icon('gavel')}
            <div>
              <strong>${perma ? 'Banimento permanente' : 'Banimento temporário'}</strong>
              <p>${String(banAtivo.reason ?? '')}</p>
              <ul class="pj-note__meta">
                <li>Aplicado por ${String(banAtivo.admin_nome ?? 'sistema')} em ${dataHora(banAtivo.created_at)}</li>
                <li>${perma
                  ? 'Sem data de término.'
                  : html`Termina em ${dataHora(banAtivo.expires_at ?? '')} (${relativo(banAtivo.expires_at ?? '', agora)})`}</li>
              </ul>
            </div>
          </div>
          <form method="post" action="${url('admin/jogadores/salvar')}"
                data-confirmar="Revogar o banimento de ${nome}? A conta volta a entrar no jogo imediatamente.">
            ${csrfField()}
            <input type="hidden" name="acao" value="revogar">
            <input type="hidden" name="id" value="${String(uid)}">
            <input type="hidden" name="ban_id" value="${String(banAtivo.id)}">
            <button class="adm-btn adm-btn--primary" type="submit"${dis}>
              ${icon('lock_open')}<span>Revogar banimento</span>
            </button>
          </form>`;
    }
    // Sugestão de 7 dias: o prazo que a moderação mais usa (datetime-local 'Y-m-d\TH:i').
    // Preenchido e lido no horário de Brasília (o controller converte para UTC ao gravar).
    const sugestao = String(old.expira ?? Fuso.date('Y-m-d', agora + 7 * 86400) + 'T' + Fuso.date('H:i', agora + 7 * 86400));
    return html`
          ${trava}
          <form class="adm-form" method="post" action="${url('admin/jogadores/salvar')}"
                data-confirmar="Banir ${nome}? A conta é bloqueada na hora e a punição fica registrada com o seu nome.">
            ${csrfField()}
            <input type="hidden" name="acao" value="banir">
            <input type="hidden" name="id" value="${String(uid)}">

            <div class="adm-field${invalido('motivo')}">
              <label class="adm-label" for="pj-motivo">Motivo<span class="adm-label__req">*</span></label>
              <textarea class="adm-textarea" id="pj-motivo" name="motivo" maxlength="500" rows="3"
                        placeholder="O que aconteceu, com data e evidência se houver."
                        ${podeAgir ? '' : raw('disabled')}>${String(old.motivo ?? '')}</textarea>
              ${erro('motivo')}
              <p class="adm-hint">Fica gravado no histórico e é o que sustenta a decisão numa revisão.</p>
            </div>

            <div class="adm-form__row">
              <div class="adm-field${invalido('tipo')}">
                <label class="adm-label" for="pj-tipo">Tipo</label>
                <select class="adm-select" id="pj-tipo" name="tipo"${dis}>
                  ${join(
                    Object.entries(rTipos).map(
                      ([chave, rot]) => html`
                  <option value="${chave}"${sel(String(old.tipo ?? 'temp') === chave)}>${String(rot)}</option>`,
                    ),
                  )}
                </select>
                ${erro('tipo')}
              </div>
              <div class="adm-field${invalido('expira')}">
                <label class="adm-label" for="pj-expira">Vale até</label>
                <input class="adm-input" type="datetime-local" id="pj-expira" name="expira"
                       value="${sugestao}"${dis}>
                ${erro('expira')}
                <p class="adm-hint">Ignorado no banimento permanente.</p>
              </div>
            </div>

            <div class="adm-form__actions">
              <button class="adm-btn adm-btn--danger" type="submit"${dis}>
                ${icon('gavel')}<span>Aplicar banimento</span>
              </button>
            </div>
          </form>`;
  };

  const historico =
    bans.length === 0
      ? html`<p class="adm-meta pj-hist-vazio">${String(vazios.sem_punicao ?? '')}</p>`
      : html`
          <h4 class="pj-sub">Histórico</h4>
          <ul class="pj-hist">
            ${join(
              bans.map((b) => {
                const revogado = (b.revoked_at ?? null) !== null;
                const vencido =
                  !revogado && String(b.kind) !== 'perma' && (b.expires_at ?? null) !== null && paraUnix(b.expires_at) <= agora;
                const [rot, tom] = revogado ? ['Revogado', 'muted'] : vencido ? ['Cumprido', 'muted'] : ['Em vigor', 'danger'];
                return html`
              <li class="pj-hist__item">
                <div class="pj-hist__top">
                  ${selo(rot, tom)}
                  <span class="adm-meta">${String(rTipos[String(b.kind)] ?? b.kind)} · ${dataHora(b.created_at)}</span>
                </div>
                <p class="pj-hist__why">${String(b.reason ?? '')}</p>
                <p class="adm-meta">
                  por ${String(b.admin_nome ?? 'sistema')}
                  ${revogado ? html` · revogado por ${String(b.revogou_nome ?? 'sistema')} em ${dataHora(b.revoked_at)}` : ''}
                </p>
              </li>`;
              }),
            )}
          </ul>`;

  // ---------------------------------------------------------------------- zona de perigo
  const rotulosRef: Record<string, string> = {
    partidas: 'partidas', compras: 'compras', punicoes: 'punições',
    itens: 'itens no inventário', missoes: 'missões', conquistas: 'conquistas',
  };
  const perigo =
    Object.keys(refs).length > 0
      ? html`
          <div class="pj-note pj-note--lock">
            ${icon('block')}
            <div>
              <strong>${String(micro.excluir_travado ?? '')}</strong>
              <ul class="pj-note__meta">
                ${join(Object.entries(refs).map(([chave, n]) => html`
                  <li><span class="num">${num(int(n))}</span> ${rotulosRef[chave] ?? chave}</li>`))}
              </ul>
            </div>
          </div>
          <button class="adm-btn adm-btn--danger" type="button" disabled>${icon('delete_forever')}<span>Excluir conta</span></button>`
      : html`
          ${trava}
          <form class="adm-form" method="post" action="${url('admin/jogadores/excluir')}"
                data-confirmar="Apagar a conta ${nome} para sempre? Não há como desfazer.">
            ${csrfField()}
            <input type="hidden" name="id" value="${String(uid)}">
            <div class="adm-field${invalido('confirmar')}">
              <label class="adm-label" for="pj-confirmar">${String(micro.excluir_confir ?? 'Confirme')}</label>
              <input class="adm-input" type="text" id="pj-confirmar" name="confirmar" autocomplete="off"
                     placeholder="${nome}"${dis}>
              ${erro('confirmar')}
            </div>
            <div class="adm-form__actions">
              <button class="adm-btn adm-btn--danger" type="submit"${dis}>
                ${icon('delete_forever')}<span>Excluir conta</span>
              </button>
            </div>
          </form>`;

  const conqLinhas = lista(conq.linhas);
  const partLinhas = lista(part.linhas);
  const displayName = String(conta.display_name ?? '');
  const rank = String(conta.player_rank ?? '');

  return html`
<section class="adm-card pj-id">
  <a class="adm-btn adm-btn--ghost adm-btn--sm pj-id__back" href="${url('admin/jogadores')}">
    ${icon('arrow_back')}<span>Todas as contas</span>
  </a>

  <div class="pj-id__who">
    ${avatar !== ''
      ? html`<img class="pj-id__ava" src="${media(avatar)}" alt="" width="64" height="64" decoding="async">`
      : html`<span class="pj-id__ava pj-id__ava--ph">${icon('person')}</span>`}
    <div class="pj-id__txt">
      <h2 class="pj-id__name">
        ${nome}
        ${eVoce ? raw('<em class="pj-you">você</em>') : ''}
      </h2>
      <p class="pj-id__mail">${String(conta.email ?? '') || String(micro.sem_email ?? '')}</p>
      <div class="pj-id__badges">
        ${seloDe(rPapel, conta.role ?? '')}
        ${seloDe(rSituac, conta.status ?? '')}
        ${displayName !== '' && displayName !== nome ? selo('No jogo: ' + displayName, 'muted') : ''}
        ${rank !== '' ? selo(rank, 'purple') : ''}
      </div>
    </div>
  </div>

  <dl class="pj-id__facts">
    <div><dt>Conta criada</dt><dd>${dataHora(conta.created_at ?? '')}</dd></div>
    <div>
      <dt>Último acesso</dt>
      <dd>${dataHora(conta.last_login ?? '')}
        ${ultRel !== '' ? html`<small class="adm-meta">${ultRel}</small>` : ''}
      </dd>
    </div>
    <div><dt>Último IP</dt><dd class="num">${String(conta.last_ip ?? '') || '—'}</dd></div>
    <div><dt>ID interno</dt><dd class="num">#${String(uid)}</dd></div>
  </dl>
</section>

<div class="pj-grid">
  <div class="pj-grid__main">

    <section class="adm-card">
      <header class="adm-card__head"><h3 class="adm-card__title">${String(titulos.progresso ?? 'Progresso')}</h3></header>
      <div class="adm-card__body">
        <ul class="pj-stats">
          ${join(
            stats.map(
              ([rot, val, sub]) => html`
            <li class="pj-stat">
              <span class="pj-stat__lbl">${rot}</span>
              <span class="pj-stat__val num">${val}</span>
              ${sub !== '' ? html`<span class="pj-stat__sub">${sub}</span>` : ''}
            </li>`,
            ),
          )}
        </ul>

        ${xpNext > 0
          ? html`
          <div class="pj-bar" role="img"
               aria-label="${xpPct + '% do XP para o próximo nível'}"
               title="${num(xp) + ' de ' + num(xpNext) + ' XP'}">
            <span style="width:${String(xpPct)}%"></span>
          </div>
          <p class="adm-meta">${xpPct}% do caminho para o nível ${num(int(conta.level) + 1)}.</p>`
          : html`
          <p class="adm-meta">Sem meta de XP registrada para o nível atual.</p>`}
      </div>
    </section>

    <section class="adm-card">
      <header class="adm-card__head">
        <h3 class="adm-card__title">
          ${String(titulos.inventario ?? 'Inventário')}
          <small>${num(int(inv.itens))} itens · ${num(Number(inv.peso ?? 0) || 0, 2)} kg · ${moeda(int(inv.valor))} em valor de base</small>
        </h3>
      </header>
      ${int(inv.itens) === 0
        ? html`<div class="adm-empty">${icon('inventory_2')}<p class="adm-empty__txt">${String(vazios.sem_inventario ?? '')}</p></div>`
        : html`
        <div class="adm-card__body pj-two">
          <div>
            <h4 class="pj-sub">Onde está guardado</h4>
            <table class="adm-table adm-table--compact">
              <thead><tr><th scope="col">Local</th><th scope="col" class="tr">Itens</th><th scope="col" class="tr">Peso</th></tr></thead>
              <tbody>
                ${join(
                  lista(inv.containers).map(
                    (ct) => html`
                  <tr>
                    <th scope="row">${String(rConts[String(ct.container)] ?? ct.container)}</th>
                    <td class="tr num">${num(int(ct.itens))}</td>
                    <td class="tr num">${num(Number(ct.peso) || 0, 2)} kg</td>
                  </tr>`,
                  ),
                )}
              </tbody>
            </table>
          </div>
          <div>
            <h4 class="pj-sub">Peças de maior valor</h4>
            <table class="adm-table adm-table--compact">
              <thead><tr><th scope="col">Item</th><th scope="col" class="tr">Qtd</th><th scope="col" class="tr">Valor</th></tr></thead>
              <tbody>
                ${join(
                  lista(inv.destaques).map(
                    (it) => html`
                  <tr>
                    <th scope="row"><strong>${String(it.name)}</strong>
                      <small class="adm-meta">${String(rConts[String(it.container)] ?? it.container)}</small></th>
                    <td class="tr num">${num(int(it.quantity))}</td>
                    <td class="tr num">${moeda(int(it.valor))}</td>
                  </tr>`,
                  ),
                )}
              </tbody>
            </table>
          </div>
        </div>`}
    </section>

    <section class="adm-card">
      <header class="adm-card__head">
        <h3 class="adm-card__title">${String(titulos.missoes ?? 'Missões')}
          <small>${num(missoes.length)} com progresso registrado</small></h3>
      </header>
      ${missoes.length === 0
        ? html`<div class="adm-empty">${icon('flag')}<p class="adm-empty__txt">${String(vazios.sem_missao ?? '')}</p></div>`
        : html`
        <div class="adm-tablewrap">
          <table class="adm-table adm-table--compact">
            <thead><tr>
              <th scope="col">Missão</th><th scope="col">Tipo</th>
              <th scope="col">Estado</th><th scope="col" class="tr">Progresso</th><th scope="col">Atualizada</th>
            </tr></thead>
            <tbody>
              ${join(
                missoes.map((m) => {
                  const alvo = int(m.alvo);
                  const prog = int(m.progress);
                  return html`
                <tr>
                  <th scope="row"><strong>${String(m.name)}</strong></th>
                  <td>${String(m.type ?? '')}</td>
                  <td>${seloDe(rMissao, m.status)}</td>
                  <td class="tr num">${num(prog)}${alvo > 0 ? html`<span class="pj-of">/${num(alvo)}</span>` : ''}</td>
                  <td><span class="adm-meta" title="${dataHora(m.updated_at ?? '')}">${relativo(m.updated_at ?? '', agora) || '—'}</span></td>
                </tr>`;
                }),
              )}
            </tbody>
          </table>
        </div>`}
    </section>

    <section class="adm-card">
      <header class="adm-card__head">
        <h3 class="adm-card__title">${String(titulos.conquistas ?? 'Conquistas')}
          <small>${num(int(conq.feitas))} de ${num(int(conq.total))} concluídas</small></h3>
      </header>
      ${conqLinhas.length === 0
        ? html`<div class="adm-empty">${icon('military_tech')}<p class="adm-empty__txt">${String(vazios.sem_conquista ?? '')}</p></div>`
        : html`
        <div class="adm-tablewrap">
          <table class="adm-table adm-table--compact">
            <thead><tr>
              <th scope="col">Conquista</th><th scope="col">Categoria</th>
              <th scope="col" class="tr">Progresso</th><th scope="col">Concluída</th>
            </tr></thead>
            <tbody>
              ${join(
                conqLinhas.map((a) => {
                  const feita = (a.completed_at ?? null) !== null;
                  return html`
                <tr>
                  <th scope="row"><strong>${String(a.name)}</strong></th>
                  <td>${String(a.category ?? '')}</td>
                  <td class="tr num">${num(int(a.progress))}<span class="pj-of">/${num(int(a.target))}</span></td>
                  <td>${feita ? selo(dataHora(a.completed_at), 'ok', 'check_circle') : raw('<span class="adm-meta">em andamento</span>')}</td>
                </tr>`;
                }),
              )}
            </tbody>
          </table>
        </div>`}
    </section>

    <section class="adm-card">
      <header class="adm-card__head">
        <h3 class="adm-card__title">${String(titulos.partidas ?? 'Partidas recentes')}
          <small>${num(int(part.total))} partidas · ${num(int(part.extracoes))} extrações · ${num(int(part.abates))} abates</small></h3>
      </header>
      ${partLinhas.length === 0
        ? html`<div class="adm-empty">${icon('sports_esports')}<p class="adm-empty__txt">${String(vazios.sem_partida ?? '')}</p></div>`
        : html`
        <div class="adm-tablewrap">
          <table class="adm-table adm-table--compact">
            <thead><tr>
              <th scope="col">Quando</th><th scope="col">Mapa</th><th scope="col">Desfecho</th>
              <th scope="col" class="tr">Abates</th><th scope="col" class="tr">XP</th><th scope="col" class="tr">Ganhos</th>
            </tr></thead>
            <tbody>
              ${join(
                partLinhas.map(
                  (pm) => html`
                <tr>
                  <th scope="row">${dataHora(pm.played_at)}</th>
                  <td>${String(pm.mapa ?? '') || '—'}</td>
                  <td>${seloDe(rResult, pm.result)}</td>
                  <td class="tr num">${num(int(pm.kills))}</td>
                  <td class="tr num">${num(int(pm.xp))}</td>
                  <td class="tr num">${moeda(int(pm.earnings))}</td>
                </tr>`,
                ),
              )}
            </tbody>
            <tfoot>
              <tr>
                <th scope="row" colspan="3">Carreira inteira</th>
                <td class="tr num">${num(int(part.abates))}</td>
                <td class="tr num">—</td>
                <td class="tr num">${moeda(int(part.ganhos))}</td>
              </tr>
            </tfoot>
          </table>
        </div>`}
    </section>
  </div>

  <div class="pj-grid__side">

    <section class="adm-card">
      <header class="adm-card__head"><h3 class="adm-card__title">${String(titulos.conta ?? 'Conta e acesso')}</h3></header>
      <div class="adm-card__body">
        ${trava}

        <form class="adm-form" method="post" action="${url('admin/jogadores/salvar')}">
          ${csrfField()}
          <input type="hidden" name="acao" value="conta">
          <input type="hidden" name="id" value="${String(uid)}">

          <div class="adm-field${invalido('papel')}">
            <label class="adm-label" for="pj-papel">Papel</label>
            <select class="adm-select" id="pj-papel" name="papel"${dis}>
              ${mostraAtual
                ? html`
                <option value="${papelAtual}" selected disabled>
                  ${String(obj(rPapel[papelAtual]).label ?? papelAtual)} (atual)
                </option>`
                : ''}
              ${join(
                papeisOk.map(
                  (p) => html`
                <option value="${p}"${sel(papelSel === p)}>
                  ${String(obj(rPapel[p]).label ?? p)} — ${String(obj(rPapel[p]).ajuda ?? '')}
                </option>`,
                ),
              )}
            </select>
            ${erro('papel')}
          </div>

          <div class="adm-field${invalido('situacao')}">
            <label class="adm-label" for="pj-situacao">Situação</label>
            <select class="adm-select" id="pj-situacao" name="situacao"${podeAgir && situAtual !== 'banned' ? '' : raw(' disabled')}>
              ${situAtual === 'banned'
                ? html`
                <option value="banned" selected disabled>${String(obj(rSituac.banned).label ?? 'Banida')} (por punição)</option>`
                : ''}
              <option value="active"${sel(situSel === 'active')}>Ativa — entra no jogo normalmente</option>
              <option value="suspended"${sel(situSel === 'suspended')}>Suspensa — bloqueada, sem punição registrada</option>
            </select>
            ${erro('situacao')}
            ${situAtual === 'banned' ? html`<p class="adm-hint">${String(micro.ban_ativo ?? '')}</p>` : ''}
          </div>

          <p class="adm-hint">${icon('key_off')} ${String(micro.senha ?? '')}</p>

          <div class="adm-form__actions">
            <button class="adm-btn adm-btn--primary" type="submit"${dis}>
              ${icon('save')}<span>Salvar conta</span>
            </button>
          </div>
        </form>
      </div>
    </section>

    <section class="adm-card pj-ban">
      <header class="adm-card__head">
        <h3 class="adm-card__title">${String(titulos.punicao ?? 'Punições')}</h3>
      </header>
      <div class="adm-card__body">
        ${blocoBan()}

        ${historico}
      </div>
    </section>

    <section class="adm-card">
      <header class="adm-card__head"><h3 class="adm-card__title">${String(titulos.faccoes ?? 'Facções')}</h3></header>
      ${faccoes.length === 0
        ? html`<div class="adm-empty">${icon('handshake')}<p class="adm-empty__txt">${String(vazios.sem_faccao ?? '')}</p></div>`
        : html`
        <div class="adm-card__body">
          <ul class="pj-fac">
            ${join(
              faccoes.map(
                (f) => html`
              <li class="pj-fac__item">
                <span class="pj-fac__name">${String(rFaccao[String(f.faction)] ?? f.faction)}</span>
                <span class="pj-fac__tier">${String(f.tier ?? '—')}</span>
                <span class="pj-fac__rep num">${num(int(f.reputation))}</span>
                ${int(f.contract_active) === 1 ? selo('Contrato ativo', 'ok') : ''}
              </li>`,
              ),
            )}
          </ul>
        </div>`}
    </section>

    <section class="adm-card pj-danger">
      <header class="adm-card__head"><h3 class="adm-card__title">${String(titulos.perigo ?? 'Excluir conta')}</h3></header>
      <div class="adm-card__body">
        <p class="adm-hint">${String(micro.excluir_ajuda ?? '')}</p>
        ${perigo}
      </div>
    </section>
  </div>
</div>
`;
}
