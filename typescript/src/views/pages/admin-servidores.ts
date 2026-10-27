/**
 * Tela ADMIN · SERVIDORES (/admin/servidores) — app/Views/pages/admin-servidores.php. Layout 'admin'.
 *
 * A view não consulta o banco e não decide regra: tudo chega pronto de repo/servers-repo.ts
 * via controllers/admin/servers-controller.ts. Aqui só há apresentação — formatação de data e
 * de duração, montagem de link de ordenação e o desenho das três tabelas.
 *
 * De propósito (igual ao PHP): cada bloco declara o seu ALCANCE; estado tem cor E texto; nada
 * de confirm() — excluir é uma etapa com o conteúdo do registro à vista.
 */
import { csrfField, icon, num, url } from '../../core/helpers';
import { html, join, raw, type SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { agoraUnix, formatarData } from '../../repo/admin-repo';

const p2 = (n: number) => String(Math.trunc(n)).padStart(2, '0');

/** Minutos -> "2h 00m" (ou "45m"). */
function dur(min: number): string {
  const h = Math.floor(min / 60);
  const m = min % 60;
  return h > 0 ? `${h}h ${p2(m)}m` : m + 'm';
}

/** "em 2d 04h" / "há 3 h" — o quanto falta (ou passou) para um instante. */
function conta(delta: number): string {
  const s = Math.abs(delta);
  const d = Math.floor(s / 86400);
  const h = Math.floor((s % 86400) / 3600);
  const m = Math.floor((s % 3600) / 60);
  const txt = d > 0 ? `${d}d ${p2(h)}h` : h > 0 ? `${p2(h)}h ${p2(m)}m` : m + 'm';
  return (delta >= 0 ? 'em ' : 'há ') + txt;
}

/** Classe de badge por estado efetivo. */
function badge(estado: string): string {
  switch (estado) {
    case 'online':
      return 'adm-badge--ok';
    case 'instavel':
      return 'adm-badge--warn';
    case 'fora':
      return 'adm-badge--danger';
    case 'manutencao':
    case 'ativa':
      return 'adm-badge--info';
    case 'agendada':
      return 'adm-badge--purple';
    default:
      return 'adm-badge--muted';
  }
}

const TROCAS: Array<[string, string]> = [
  ['ok', 'check_circle'],
  ['warn', 'warning'],
  ['down', 'cancel'],
];

export function adminServidores(s: Scope): SafeHtml {
  const data = s.data;
  const cromo = (data.cromo ?? {}) as Dict;
  const titulos = (cromo.titulos ?? {}) as Dict;
  const subtits = (cromo.subtitulos ?? {}) as Dict;
  const alcance = (cromo.alcance ?? {}) as Dict;
  const vazios = (cromo.vazios ?? {}) as Dict;
  const micro = (cromo.micro ?? {}) as Dict;
  const cForm = (cromo.form ?? {}) as Dict;
  const cDel = (cromo.excluir ?? {}) as Dict;

  const resumo = (data.resumo ?? {}) as Dict;
  const regioes = (data.regioes ?? []) as Dict[];
  const servicos = (data.servicos ?? []) as Dict[];
  const janelas = (data.janelas ?? { linhas: [], total: 0, pagina: 1, paginas: 1, de: 0, ate: 0 }) as Dict;

  const filtro = (data.filtro ?? {}) as Record<string, string>;
  const estados = (data.estados ?? {}) as Record<string, string>;
  const situacoes = (data.situacoes ?? {}) as Record<string, string>;
  const regRotulos = (data.rotulos_reg ?? []) as string[];
  const noticias = (data.noticias ?? []) as Dict[];

  const form = data.form && typeof data.form === 'object' ? (data.form as Dict) : null;
  const exclusao = data.exclusao && typeof data.exclusao === 'object' ? (data.exclusao as Dict) : null;

  const agora = agoraUnix();
  const temFiltro = (filtro.q ?? '') !== '' || (filtro.estado ?? '') !== '' || (filtro.situacao ?? '') !== '';

  // ------------------------------------------------------------ formatadores e montadores

  /** URL desta tela preservando os filtros correntes. */
  const aqui = (mais: Record<string, string | number | null> = {}, remove: string[] = []): string => {
    const q: Record<string, string> = { ...filtro };
    for (const k of remove) delete q[k];
    for (const [k, v] of Object.entries(mais)) {
      if (v === null) delete q[k];
      else q[k] = String(v);
    }
    return url('admin/servidores', q);
  };

  /** Cabeçalho de coluna que ordena; clicar de novo inverte, e a seta mostra o sentido ATUAL. */
  const th = (chave: string, rotulo: string, pCol: string, pDir: string, classe = ''): SafeHtml => {
    const ativa = (filtro[pCol] ?? '') === chave;
    const dir = ativa && (filtro[pDir] ?? 'asc') === 'asc' ? 'desc' : 'asc';
    const href = aqui({ [pCol]: chave, [pDir]: dir });
    const seta = ativa ? icon((filtro[pDir] ?? 'asc') === 'asc' ? 'arrow_upward' : 'arrow_downward', 'srv-th__ar') : '';
    return html`<th class="${classe}${ativa ? ' is-sorted' : ''}"><a class="srv-th" href="${href}">${rotulo}${seta}</a></th>`;
  };

  /** Os campos escondidos que levam os filtros junto no POST. */
  const filtrosOcultos = (): SafeHtml =>
    join(
      (
        [
          ['q', 'f_q'],
          ['estado', 'f_estado'],
          ['situacao', 'f_situacao'],
          ['jp', 'f_jp'],
        ] as const
      ).map(([k, nome]) => ((filtro[k] ?? '') !== '' ? html`<input type="hidden" name="${nome}" value="${filtro[k]}">` : '')),
    );

  /** Um campo do formulário, com rótulo, erro por campo e ajuda. */
  const campo = (nome: string, rotulo: string, controle: SafeHtml, ajuda = '', obrig = false): SafeHtml => {
    const erro = String((form?.erros as Dict | undefined)?.[nome] ?? '');
    return html`<div class="adm-field${erro !== '' ? ' is-invalid' : ''}"><label class="adm-label" for="f-${nome}">${rotulo}${
      obrig ? raw('<span class="adm-label__req">*</span>') : ''
    }</label>${controle}${erro !== '' ? html`<p class="adm-error">${icon('error')}<span>${erro}</span></p>` : ''}${
      ajuda !== '' ? html`<p class="adm-hint">${ajuda}</p>` : ''
    }</div>`;
  };

  /** Valor corrente de um campo do formulário aberto. */
  const v = (nome: string): string => String((form?.dados as Dict | undefined)?.[nome] ?? '');
  /** Valor de data/hora no formato do <input datetime-local>. */
  const vdt = (nome: string): string => Array.from(v(nome)).slice(0, 16).join('').replace(/ /g, 'T');

  const fTipo = String(form?.tipo ?? '');
  const fId = Number(form?.id ?? 0) | 0;

  // ------------------------------------------------------------------------ indicadores
  const prox = (resumo.proxima ?? null) as Dict | null;
  const nReg = Number(resumo.regioes ?? 0);
  const nSrv = Number(resumo.servicos ?? 0);
  const kpis = [
    {
      ico: 'public',
      tom: nReg > 0 && (resumo.regioes_ok ?? 0) === (resumo.regioes ?? 0) ? 'lime' : 'amber',
      lbl: 'Regiões online',
      val: nReg > 0 ? raw(num(Number(resumo.regioes_ok) | 0) + '<small>/' + num(nReg | 0) + '</small>') : '—',
      sub: 'estado efetivo, com janela ativa contando',
    },
    {
      ico: 'dns',
      tom: nSrv > 0 && (resumo.servicos_ok ?? 0) === (resumo.servicos ?? 0) ? 'cyan' : 'amber',
      lbl: 'Serviços no ar',
      val: nSrv > 0 ? raw(num(Number(resumo.servicos_ok) | 0) + '<small>/' + num(nSrv | 0) + '</small>') : '—',
      sub: 'coluna state de server_services',
    },
    {
      ico: 'network_ping',
      tom: 'purple',
      lbl: 'Ping médio declarado',
      val: nReg > 0 ? raw(num(Number(resumo.ping_medio) | 0) + '<small>ms</small>') : '—',
      sub: String(micro.semente ?? ''),
    },
    {
      ico: 'schedule',
      tom: prox !== null && prox.ativa ? 'red' : 'gold',
      lbl: prox !== null && prox.ativa ? 'Janela em andamento' : 'Próxima janela',
      val: prox === null ? '—' : formatarData(Number(prox.inicio), 'd/m H:i'),
      sub:
        prox === null
          ? num(Number(resumo.janelas_total ?? 0) | 0) + ' janela(s) no histórico'
          : prox.regiao + ' · ' + conta(Number(prox.inicio) - agora),
    },
  ];

  const blocoKpis = html`
  <section class="adm-grid adm-grid--4 srv-kpis" aria-label="Resumo da infraestrutura">
    ${join(
      kpis.map(
        (k) => html`
      <div class="adm-card adm-kpi adm-kpi--${k.tom}">
        <span class="adm-kpi__ico">${icon(k.ico)}</span>
        <span class="adm-kpi__txt">
          <span class="adm-kpi__label">${k.lbl}</span>
          <span class="adm-kpi__value num">${k.val}</span>
          <span class="srv-kpi__sub">${k.sub}</span>
        </span>
      </div>`,
      ),
    )}
  </section>`;

  // ----------------------------------------------------------------- confirmar exclusão
  const nomesExcl: Record<string, string> = { regiao: 'a região', servico: 'o serviço', janela: 'a janela' };
  const blocoExclusao =
    exclusao === null
      ? ''
      : html`
    <section class="adm-card srv-danger" id="adm-srv-excluir">
      <div class="adm-card__head">
        <h2 class="adm-card__title">${icon('delete_forever')} ${String(cDel.titulo ?? 'Confirmar exclusão')}</h2>
        <div class="adm-card__tools">
          <a class="adm-card__link" href="${aqui({}, ['excluir'])}">Cancelar${icon('close')}</a>
        </div>
      </div>
      <div class="adm-card__body srv-danger__body">
        <p class="srv-danger__what">
          Você está prestes a excluir
          <strong>${nomesExcl[String(exclusao.tipo)] ?? 'o registro'}
          ${String(exclusao.rotulo)}</strong>.
        </p>
        <dl class="adm-dl srv-danger__dl">
          ${join(Object.entries((exclusao.detalhes ?? {}) as Dict).map(([rot, val]) => html`
            <dt>${rot}</dt><dd>${String(val)}</dd>`))}
        </dl>
${
  (exclusao.bloqueio ?? null) !== null
    ? html`
          <p class="srv-block">${icon('block')}<span>${String(exclusao.bloqueio)}</span></p>
          <div class="adm-form__actions">
            <a class="adm-btn adm-btn--ghost" href="${aqui({ situacao: null }, ['excluir'])}#srv-janelas">${icon('event')}<span>Ver as janelas</span></a>
            <a class="adm-btn" href="${aqui({}, ['excluir'])}">Voltar</a>
          </div>`
    : html`
          <p class="adm-hint">${String(cDel.aviso ?? '')}</p>
          <form class="adm-form__actions" method="post" action="${url('admin/servidores/excluir')}">
            ${csrfField()}
            <input type="hidden" name="tipo" value="${String(exclusao.tipo)}">
            <input type="hidden" name="id" value="${String(exclusao.id)}">
            ${filtrosOcultos()}
            <button class="adm-btn adm-btn--danger" type="submit">${icon('delete')}<span>Excluir definitivamente</span></button>
            <a class="adm-btn adm-btn--ghost" href="${aqui({}, ['excluir'])}">Cancelar</a>
          </form>`
}
      </div>
    </section>`;

  // ------------------------------------------------------------------------- formulário
  const opcoesEstado = (): SafeHtml =>
    join(Object.entries(estados).map(([k, rot]) => html`<option value="${k}"${v('state') === k ? ' selected' : ''}>${rot}</option>`));

  let blocoForm: SafeHtml | string = '';
  if (form !== null) {
    const cf = (cForm[fTipo] ?? {}) as Dict;
    const ajuda = (cf.ajuda ?? {}) as Dict;
    const tituloF = String(fId > 0 ? (cf.editar ?? 'Editar') : (cf.novo ?? 'Novo'));
    const nErros = Object.keys((form.erros ?? {}) as Dict).length;
    const temErro = nErros > 0;
    const icones: Record<string, string> = { regiao: 'public', servico: 'dns', janela: 'schedule' };

    let corpo: SafeHtml;
    if (fTipo === 'regiao') {
      corpo = html`
            <div class="adm-form__row">
              ${campo('label', 'Nome da região', html`<input class="adm-input" id="f-label" name="label" maxlength="48" required value="${v('label')}">`, String(ajuda.label ?? ''), true)}
              ${campo('slug', 'Identificador', html`<input class="adm-input" id="f-slug" name="slug" maxlength="24" placeholder="gerado do nome" value="${v('slug')}">`, String(ajuda.slug ?? ''))}
            </div>
            <div class="adm-form__row adm-form__row--3">
              ${campo('ping_ms', 'Ping (ms)', html`<input class="adm-input num" id="f-ping_ms" name="ping_ms" inputmode="numeric" required value="${v('ping_ms')}">`, String(ajuda.ping ?? ''), true)}
              ${campo('players', 'Jogadores', html`<input class="adm-input num" id="f-players" name="players" inputmode="numeric" required value="${v('players')}">`, String(ajuda.players ?? ''), true)}
              ${campo('sort_order', 'Ordem', html`<input class="adm-input num" id="f-sort_order" name="sort_order" inputmode="numeric" value="${v('sort_order')}">`, String(ajuda.ordem ?? ''))}
            </div>
            <div class="adm-form__row">
              ${campo('state', 'Estado', html`<select class="adm-select" id="f-state" name="state">${opcoesEstado()}</select>`, 'Manutenção de região não se declara aqui: ela vem da janela agendada.', true)}
              <div class="adm-field srv-field--note">
                <span class="adm-label">O que isto altera</span>
                <p class="adm-hint">${String(alcance.regioes ?? '')}</p>
              </div>
            </div>
`;
    } else if (fTipo === 'servico') {
      corpo = html`
            <div class="adm-form__row">
              ${campo('label', 'Nome do serviço', html`<input class="adm-input" id="f-label" name="label" maxlength="48" required value="${v('label')}">`, '', true)}
              ${campo('slug', 'Identificador', html`<input class="adm-input" id="f-slug" name="slug" maxlength="24" placeholder="gerado do nome" value="${v('slug')}">`)}
            </div>
            <div class="adm-form__row adm-form__row--3">
              ${campo('state', 'Estado', html`<select class="adm-select" id="f-state" name="state">${opcoesEstado()}</select>`, '', true)}
              ${campo('status_label', 'Rótulo completo', html`<input class="adm-input" id="f-status_label" name="status_label" maxlength="32" required value="${v('status_label')}">`, String(ajuda.status ?? ''), true)}
              ${campo('short_label', 'Rótulo curto', html`<input class="adm-input" id="f-short_label" name="short_label" maxlength="24" required value="${v('short_label')}">`, String(ajuda.curto ?? ''), true)}
            </div>
            <div class="adm-form__row adm-form__row--3">
              ${campo('maint_scope', 'Escopo da parada', html`<input class="adm-input" id="f-maint_scope" name="maint_scope" maxlength="48" value="${v('maint_scope')}">`, String(ajuda.escopo ?? ''))}
              ${campo('maint_starts_at', 'Início da janela', html`<input class="adm-input" id="f-maint_starts_at" name="maint_starts_at" type="datetime-local" value="${vdt('maint_starts_at')}">`, String(ajuda.janela ?? ''))}
              ${campo('maint_minutes', 'Duração (min)', html`<input class="adm-input num" id="f-maint_minutes" name="maint_minutes" inputmode="numeric" value="${v('maint_minutes')}">`)}
            </div>
            <div class="adm-form__row">
              ${campo('sort_order', 'Ordem', html`<input class="adm-input num" id="f-sort_order" name="sort_order" inputmode="numeric" value="${v('sort_order')}">`)}
              <div class="adm-field srv-field--note">
                <span class="adm-label">O que isto altera</span>
                <p class="adm-hint">${String(alcance.servicos ?? '')}</p>
              </div>
            </div>
`;
    } else {
      const optsReg = join(regRotulos.map((rot) => html`<option value="${rot}"${v('region') === rot ? ' selected' : ''}>${rot}</option>`));
      const optsNews = html`<option value="">— nenhuma —</option>${join(
        noticias.map((n) => html`<option value="${String(n.slug)}"${v('news_slug') === String(n.slug) ? ' selected' : ''}>${String(n.title)}</option>`),
      )}`;
      corpo = html`
${
  regRotulos.length === 0
    ? html`              <p class="srv-block srv-block--warn">${icon('warning')}<span>Não há região cadastrada: uma janela precisa apontar para uma.</span></p>
`
    : ''
}            <div class="adm-form__row adm-form__row--3">
              ${campo('starts_at', 'Início', html`<input class="adm-input" id="f-starts_at" name="starts_at" type="datetime-local" required value="${vdt('starts_at')}">`, '', true)}
              ${campo('ends_at', 'Fim', html`<input class="adm-input" id="f-ends_at" name="ends_at" type="datetime-local" required value="${vdt('ends_at')}">`, '', true)}
              ${campo('region', 'Região', html`<select class="adm-select" id="f-region" name="region">${optsReg}</select>`, String(ajuda.regiao ?? ''), true)}
            </div>
            <div class="adm-form__row">
              ${campo('note', 'Nota para o jogador', html`<input class="adm-input" id="f-note" name="note" maxlength="240" value="${v('note')}">`, String(ajuda.nota ?? ''))}
              ${campo('news_slug', 'Notícia relacionada', html`<select class="adm-select" id="f-news_slug" name="news_slug">${optsNews}</select>`, String(ajuda.noticia ?? ''))}
            </div>
            <p class="adm-hint">${String(alcance.janelas ?? '')}</p>
`;
    }

    blocoForm = html`
    <section class="adm-card srv-form${temErro ? ' is-invalid' : ''}" id="adm-srv-form">
      <div class="adm-card__head">
        <h2 class="adm-card__title">
          ${icon(icones[fTipo] ?? 'edit')}
          ${tituloF}
          ${fId > 0 ? html`<small>registro #${String(fId)}</small>` : ''}
        </h2>
        <div class="adm-card__tools">
          <a class="adm-card__link" href="${aqui({}, ['novo', 'editar'])}">Fechar${icon('close')}</a>
        </div>
      </div>
      <div class="adm-card__body">
${
  temErro
    ? html`          <p class="srv-block srv-block--warn">${icon('error')}<span>
            ${nErros} campo(s) impedem a gravação. Nada foi alterado no banco.
          </span></p>
`
    : ''
}
        <form class="adm-form" method="post" action="${url('admin/servidores/salvar')}">
          ${csrfField()}
          <input type="hidden" name="tipo" value="${fTipo}">
          <input type="hidden" name="id" value="${String(fId)}">
          ${filtrosOcultos()}
${corpo}
          <div class="adm-form__actions">
            <button class="adm-btn adm-btn--primary" type="submit">${icon('save')}<span>${fId > 0 ? 'Salvar alterações' : 'Criar'}</span></button>
            <a class="adm-btn adm-btn--ghost" href="${aqui({}, ['novo', 'editar'])}">Cancelar</a>
            ${
              fId > 0
                ? html`<a class="adm-btn adm-btn--danger adm-spacer" href="${aqui({ excluir: fTipo + ':' + fId }, ['novo', 'editar'])}#adm-srv-excluir">${icon('delete')}<span>Excluir</span></a>`
                : ''
            }
          </div>
        </form>
      </div>
    </section>`;
  }

  // ---------------------------------------------------------------------------- filtros
  const ordemOculta = join(
    ['ord', 'dir', 'sord', 'sdir'].map((k) => ((filtro[k] ?? '') !== '' ? html`
          <input type="hidden" name="${k}" value="${filtro[k]}">` : '')),
  );
  const blocoFiltros = html`
  <section class="adm-card srv-filtros" aria-label="Busca e filtros">
    <form class="adm-toolbar" method="get" action="${url('admin/servidores')}"
          data-adm-preservar="filtros">
      <div class="adm-toolbar__grow">
        <label class="adm-search">
          ${icon('search')}
          <input class="adm-input" type="search" name="q" value="${filtro.q ?? ''}"
                 placeholder="Buscar região, serviço, escopo ou nota" aria-label="Buscar">
        </label>
      </div>

      <label class="srv-sel">
        <span class="adm-label">Estado</span>
        <select class="adm-select" name="estado">
          <option value="">Todos</option>
          ${join(Object.entries(estados).map(([k, rot]) => html`
            <option value="${k}"${(filtro.estado ?? '') === k ? ' selected' : ''}>${rot}</option>`))}
        </select>
      </label>

      <label class="srv-sel">
        <span class="adm-label">Janelas</span>
        <select class="adm-select" name="situacao">
          <option value="">Todas</option>
          ${join(Object.entries(situacoes).map(([k, rot]) => html`
            <option value="${k}"${(filtro.situacao ?? '') === k ? ' selected' : ''}>${rot}</option>`))}
        </select>
      </label>
${ordemOculta}
      <button class="adm-btn" type="submit">${icon('filter_alt')}<span>Filtrar</span></button>
      ${temFiltro ? html`<a class="adm-btn adm-btn--ghost" href="${url('admin/servidores')}">${icon('filter_alt_off')}<span>Limpar</span></a>` : ''}
    </form>
  </section>`;

  // -------------------------------------------------------------- formulário de troca
  const troca = (tipo: string, linha: Dict): SafeHtml => html`
                  <form class="srv-troca" method="post" action="${url('admin/servidores/salvar')}">
                    ${csrfField()}
                    <input type="hidden" name="tipo" value="${tipo}">
                    <input type="hidden" name="acao" value="estado">
                    <input type="hidden" name="id" value="${String(linha.id)}">
                    ${filtrosOcultos()}
                    ${join(
                      TROCAS.map(
                        ([st, ic]) => html`
                      <button class="srv-troca__b srv-troca__b--${st}${linha.state === st ? ' is-on' : ''}"
                              type="submit" name="state" value="${st}"
                              title="${estados[st] ?? ''}"
                              aria-label="Marcar ${String(linha.label)} como ${estados[st] ?? ''}"
                              ${linha.state === st ? raw('aria-pressed="true"') : ''}>${icon(ic)}</button>`,
                      ),
                    )}
                  </form>`;

  /** Bloco "vazio" padrão das três listas. */
  const vazio = (iconeFiltro: boolean, iconeSem: string, tituloSem: string, chave: string, novo: string, botao: string, filtrado = temFiltro): SafeHtml => html`
      <div class="adm-card__body">
        <div class="adm-empty">
          ${icon(iconeFiltro ? 'search_off' : iconeSem)}
          <h3 class="adm-empty__title">${iconeFiltro ? 'Nada encontrado' : tituloSem}</h3>
          <p class="adm-empty__txt">${String(vazios[iconeFiltro ? chave + '_filtro' : chave] ?? '')}</p>
          ${
            filtrado
              ? html`<a class="adm-btn adm-btn--ghost" href="${url('admin/servidores')}">Limpar filtros</a>`
              : html`<a class="adm-btn adm-btn--primary" href="${url('admin/servidores', { novo })}#adm-srv-form">${icon('add')}<span>${botao}</span></a>`
          }
        </div>
      </div>`;

  // ---------------------------------------------------------------------------- regiões
  const blocoRegioes = html`
  <section class="adm-card srv-bloco" id="srv-regioes">
    <div class="adm-card__head">
      <h2 class="adm-card__title">${icon('public')} ${String(titulos.regioes ?? 'Regiões')}
        <small>${String(subtits.regioes ?? '')}</small>
      </h2>
      <div class="adm-card__tools">
        <span class="srv-count num">${num(regioes.length)}</span>
        <a class="adm-card__link" href="${aqui({ novo: 'regiao' }, ['editar', 'excluir'])}#adm-srv-form">Nova região${icon('add')}</a>
      </div>
    </div>
    <p class="srv-scope">${icon('info')}<span>${String(alcance.regioes ?? '')}</span></p>
${
  regioes.length === 0
    ? vazio(temFiltro, 'public_off', 'Sem regiões', 'regioes', 'regiao', 'Cadastrar a primeira')
    : html`
      <div class="adm-tablewrap">
        <table class="adm-table srv-table srv-table--reg">
          <thead>
            <tr>
              ${th('ordem', '#', 'ord', 'dir', 'srv-c-ord tr')}
              ${th('regiao', 'Região', 'ord', 'dir', 'srv-c-nome')}
              ${th('ping', 'Ping', 'ord', 'dir', 'srv-c-num tr')}
              ${th('jogadores', 'Jogadores', 'ord', 'dir', 'srv-c-num tr')}
              <th class="srv-c-ocup">Ocupação</th>
              ${th('estado', 'Estado', 'ord', 'dir', 'srv-c-estado')}
              <th class="srv-c-troca" title="${String(micro.estado_titulo ?? '')}">${String(micro.estado_col ?? 'Trocar')}</th>
              <th class="adm-table__acts">Ações</th>
            </tr>
          </thead>
          <tbody>
            ${join(
              regioes.map((r) => {
                const pct = String(Math.round(Number(r.ocupacao) * 100));
                return html`
              <tr>
                <td class="srv-c-ord tr num">${String(r.ordem)}</td>
                <td class="srv-c-nome">
                  <span class="srv-nome">
                    <strong>${String(r.label)}</strong>
                    <small class="mono">${String(r.slug)}</small>
                  </span>
                </td>
                <td class="srv-c-num tr num">${num(Number(r.ping) | 0)}<small>${String(micro.ping_unidade ?? 'ms')}</small></td>
                <td class="srv-c-num tr num">${num(Number(r.jogadores) | 0)}</td>
                <td class="srv-c-ocup">
                  <span class="srv-bar" title="${String(micro.ocupacao ?? '')}">
                    <span class="srv-bar__fill" style="width:${pct}%"></span>
                  </span>
                  <span class="srv-bar__pct num">${pct}%</span>
                </td>
                <td class="srv-c-estado">
                  <span class="adm-badge ${badge(String(r.estado))}">${String(r.estado_rotulo)}</span>
                  ${(r.nota ?? null) !== null ? html`<small class="srv-nota">${String(r.nota)}</small>` : ''}
                  ${
                    ['manutencao', 'agendada'].includes(String(r.estado)) && r.state !== 'ok'
                      ? html`<small class="srv-nota">gravado: ${estados[String(r.state)] ?? String(r.state)}</small>`
                      : ''
                  }
                </td>
                <td class="srv-c-troca">${troca('regiao', r)}
                </td>
                <td class="adm-table__acts">
                  <a class="adm-btn adm-btn--sm adm-btn--icon" title="Editar ${String(r.label)}"
                     href="${aqui({ editar: 'regiao:' + r.id }, ['novo', 'excluir'])}#adm-srv-form">${icon('edit')}</a>
                  <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger" title="Excluir ${String(r.label)}"
                     href="${aqui({ excluir: 'regiao:' + r.id }, ['novo', 'editar'])}#adm-srv-excluir">${icon('delete')}</a>
                </td>
              </tr>`;
              }),
            )}
          </tbody>
        </table>
      </div>`
}
  </section>`;

  // --------------------------------------------------------------------------- serviços
  const blocoServicos = html`
  <section class="adm-card srv-bloco" id="srv-servicos">
    <div class="adm-card__head">
      <h2 class="adm-card__title">${icon('dns')} ${String(titulos.servicos ?? 'Serviços')}
        <small>${String(subtits.servicos ?? '')}</small>
      </h2>
      <div class="adm-card__tools">
        <span class="srv-count num">${num(servicos.length)}</span>
        <a class="adm-card__link" href="${aqui({ novo: 'servico' }, ['editar', 'excluir'])}#adm-srv-form">Novo serviço${icon('add')}</a>
      </div>
    </div>
    <p class="srv-scope">${icon('info')}<span>${String(alcance.servicos ?? '')}</span></p>
${
  servicos.length === 0
    ? vazio(temFiltro, 'dns', 'Sem serviços', 'servicos', 'servico', 'Cadastrar o primeiro')
    : html`
      <div class="adm-tablewrap">
        <table class="adm-table srv-table srv-table--srv">
          <thead>
            <tr>
              ${th('ordem', '#', 'sord', 'sdir', 'srv-c-ord tr')}
              ${th('servico', 'Serviço', 'sord', 'sdir', 'srv-c-nome')}
              ${th('estado', 'Estado', 'sord', 'sdir', 'srv-c-estado')}
              <th class="srv-c-rot">Rótulo exibido</th>
              <th class="srv-c-escopo">Escopo</th>
              ${th('janela', 'Janela anunciada', 'sord', 'sdir', 'srv-c-janela')}
              <th class="srv-c-troca" title="${String(micro.estado_titulo ?? '')}">${String(micro.estado_col ?? 'Trocar')}</th>
              <th class="adm-table__acts">Ações</th>
            </tr>
          </thead>
          <tbody>
            ${join(
              servicos.map(
                (sv) => html`
              <tr${sv.no_contador ? raw(' class="is-live"') : ''}>
                <td class="srv-c-ord tr num">${String(sv.ordem)}</td>
                <td class="srv-c-nome">
                  <span class="srv-nome">
                    <strong>${String(sv.label)}</strong>
                    <small class="mono">${String(sv.slug)}</small>
                  </span>
                </td>
                <td class="srv-c-estado">
                  <span class="adm-badge ${badge(String(sv.estado))}">${estados[String(sv.state)] ?? String(sv.state)}</span>
                </td>
                <td class="srv-c-rot">
                  <span class="srv-nome">
                    <strong>${String(sv.rotulo)}</strong>
                    <small>curto: ${String(sv.curto)}</small>
                  </span>
                </td>
                <td class="srv-c-escopo">${sv.escopo !== null ? String(sv.escopo) : raw('<span class="srv-vazio">—</span>')}</td>
                <td class="srv-c-janela">
                  ${
                    Number(sv.inicio) > 0
                      ? html`<span class="srv-nome">
                      <strong>${formatarData(Number(sv.inicio), 'd/m/Y H:i')}</strong>
                      <small>
                        ${sv.minutos !== null ? dur(Number(sv.minutos) | 0) : 'sem duração'}
                        · ${conta(Number(sv.faltam) | 0)}
                      </small>
                    </span>
                    ${sv.no_contador ? html`<span class="srv-tag" title="${String(micro.no_contador ?? '')}">${icon('bolt')}no ar</span>` : ''}`
                      : raw('<span class="srv-vazio">—</span>')
                  }
                </td>
                <td class="srv-c-troca">${troca('servico', sv)}
                </td>
                <td class="adm-table__acts">
                  <a class="adm-btn adm-btn--sm adm-btn--icon" title="Editar ${String(sv.label)}"
                     href="${aqui({ editar: 'servico:' + sv.id }, ['novo', 'excluir'])}#adm-srv-form">${icon('edit')}</a>
                  <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger" title="Excluir ${String(sv.label)}"
                     href="${aqui({ excluir: 'servico:' + sv.id }, ['novo', 'editar'])}#adm-srv-excluir">${icon('delete')}</a>
                </td>
              </tr>`,
              ),
            )}
          </tbody>
        </table>
      </div>`
}
  </section>`;

  // ---------------------------------------------------------------------------- janelas
  const linhasJ = (janelas.linhas ?? []) as Dict[];
  const filtradoJ = temFiltro || (filtro.situacao ?? '') !== '';
  const pg = Number(janelas.pagina) | 0;
  const tot = Number(janelas.paginas) | 0;
  const paginador = (): SafeHtml => {
    const itens: SafeHtml[] = [];
    for (let i = 1; i <= tot; i++) {
      if (i === 1 || i === tot || Math.abs(i - pg) <= 1) {
        itens.push(html`
                  <a class="adm-pager__btn${i === pg ? ' is-active' : ''}" href="${aqui({ jp: i })}#srv-janelas">${String(i)}</a>`);
      } else if (Math.abs(i - pg) === 2) {
        itens.push(html`
                  <span class="adm-pager__gap">…</span>`);
      }
    }
    return html`
              <a class="adm-pager__btn${pg <= 1 ? ' is-disabled' : ''}" aria-label="Página anterior"
                 href="${aqui({ jp: Math.max(1, pg - 1) })}#srv-janelas">${icon('chevron_left')}</a>${join(itens)}
              <a class="adm-pager__btn${pg >= tot ? ' is-disabled' : ''}" aria-label="Próxima página"
                 href="${aqui({ jp: Math.min(tot, pg + 1) })}#srv-janelas">${icon('chevron_right')}</a>`;
  };

  const blocoJanelas = html`
  <section class="adm-card srv-bloco" id="srv-janelas">
    <div class="adm-card__head">
      <h2 class="adm-card__title">${icon('schedule')} ${String(titulos.janelas ?? 'Janelas de manutenção')}
        <small>${String(subtits.janelas ?? '')}</small>
      </h2>
      <div class="adm-card__tools">
        <span class="srv-count num">${num(Number(janelas.total) | 0)}</span>
        <a class="adm-card__link" href="${aqui({ novo: 'janela' }, ['editar', 'excluir'])}#adm-srv-form">Nova janela${icon('add')}</a>
      </div>
    </div>
    <p class="srv-scope">${icon('info')}<span>${String(alcance.janelas ?? '')}</span></p>
${
  linhasJ.length === 0
    ? vazio(filtradoJ, 'event_busy', 'Sem janelas', 'janelas', 'janela', 'Agendar a primeira')
    : html`
      <div class="adm-tablewrap">
        <table class="adm-table srv-table srv-table--jan">
          <thead>
            <tr>
              <th class="srv-c-quando">Início</th>
              <th class="srv-c-quando">Fim</th>
              <th class="srv-c-num tr">Duração</th>
              <th class="srv-c-nome">Região</th>
              <th class="srv-c-estado">Situação</th>
              <th class="srv-c-nota">Nota / notícia</th>
              <th class="adm-table__acts">Ações</th>
            </tr>
          </thead>
          <tbody>
            ${join(
              linhasJ.map(
                (j) => html`
              <tr${j.situacao === 'ativa' ? raw(' class="is-live"') : ''}>
                <td class="srv-c-quando">
                  <span class="srv-nome">
                    <strong>${formatarData(Number(j.inicio), 'd/m/Y H:i')}</strong>
                    <small>${conta(Number(j.inicio) - agora)}</small>
                  </span>
                </td>
                <td class="srv-c-quando">${formatarData(Number(j.fim), 'd/m/Y H:i')}</td>
                <td class="srv-c-num tr num">${dur(Number(j.minutos) | 0)}</td>
                <td class="srv-c-nome">
                  <span class="srv-nome">
                    <strong>${String(j.regiao)}</strong>
                    ${j.orfa ? html`<small class="srv-orfa" title="${String(micro.orfa ?? '')}">${icon('link_off')}região inexistente</small>` : ''}
                  </span>
                </td>
                <td class="srv-c-estado">
                  <span class="adm-badge ${badge(String(j.situacao))}">${situacoes[String(j.situacao)] ?? String(j.situacao)}</span>
                </td>
                <td class="srv-c-nota">
                  ${j.nota !== null ? html`<span class="srv-nota-txt">${String(j.nota)}</span>` : ''}
                  ${j.noticia !== null ? html`<small class="mono srv-slug">${icon('article')}${String(j.noticia)}</small>` : ''}
                  ${j.nota === null && j.noticia === null ? raw('<span class="srv-vazio">—</span>') : ''}
                </td>
                <td class="adm-table__acts">
                  <a class="adm-btn adm-btn--sm adm-btn--icon" title="Editar janela"
                     href="${aqui({ editar: 'janela:' + j.id }, ['novo', 'excluir'])}#adm-srv-form">${icon('edit')}</a>
                  <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger" title="Excluir janela"
                     href="${aqui({ excluir: 'janela:' + j.id }, ['novo', 'editar'])}#adm-srv-excluir">${icon('delete')}</a>
                </td>
              </tr>`,
              ),
            )}
          </tbody>
        </table>
      </div>
${
  tot > 1 || (Number(janelas.total) | 0) > 0
    ? html`
        <div class="adm-card__foot">
          <nav class="adm-pager" aria-label="Paginação das janelas">
            <span class="adm-pager__info">
              ${num(Number(janelas.de) | 0)}–${num(Number(janelas.ate) | 0)} de ${num(Number(janelas.total) | 0)} janela(s)
            </span>
            ${tot > 1 ? paginador() : ''}
          </nav>
        </div>`
    : ''
}`
}
  </section>`;

  // A região da lista (core/fragmento.ts) cobre a barra de filtros E os três blocos: o mesmo
  // filtro recorta os três, e trocar só um deixaria os outros dois no recorte anterior.
  return html`
<div class="srv">
${blocoKpis}
${blocoExclusao}
${blocoForm}
  <!--eh:lista-->
${blocoFiltros}
${blocoRegioes}
${blocoServicos}
${blocoJanelas}
  <!--/eh:lista-->

</div>
`;
}
