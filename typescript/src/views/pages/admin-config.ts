/**
 * Tela ADMIN · CONFIGURAÇÕES DO JOGO (/admin/configuracoes) — app/Views/pages/admin-config.php.
 * Layout 'admin'.
 *
 * A view NÃO consulta o banco e NÃO valida nada: cada campo chega de
 * controllers/admin/settings-controller.ts já com valor, limites, dica, procedência e o erro
 * do último envio. Aqui só há escolha de WIDGET por tipo e formatação.
 *
 * Compromissos: estado tem cor E TEXTO; número tabular com a unidade FORA do campo; nada de
 * rolagem horizontal (texto e JSON ocupam a linha inteira).
 */
import { csrfField, icon, num, url } from '../../core/helpers';
import { html, join, type SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { corta, formatarData, paraUnix } from '../../repo/admin-repo';
import { TIPOS } from '../../repo/admin-settings-repo';

/** "10/09/2026 14:32" a partir do DATETIME. String vazia continua vazia. */
function quando(sql: unknown): string {
  const s = String(sql ?? '').trim();
  if (s === '') return '';
  const ts = paraUnix(s);
  return ts === 0 ? s : formatarData(ts, 'd/m/Y H:i');
}

/** Valor gravado em UMA linha, para o resumo lateral. JSON e texto longo viram um trecho. */
function emUmaLinha(v: string | null, max = 40): string {
  if (v === null || v.trim() === '') return '—';
  v = v.replace(/\s+/gu, ' ').trim();
  return Array.from(v).length > max ? corta(v, max - 1) + '…' : v;
}

/** ucfirst(str_replace('_', ' ', $g)). */
function rotuloCru(g: string): string {
  const s = g.replace(/_/g, ' ');
  return s.charAt(0).toUpperCase() + s.slice(1);
}

const ROTULO_ESTADO: Record<string, string> = {
  todos: 'Todos os estados',
  alterado: 'Ajustados pela equipe',
  padrao: 'Ainda no valor semeado',
  sistema: 'Lidos pelo código',
  personalizada: 'Criados no painel',
};
const ROTULO_ORDEM: Record<string, string> = {
  sugerida: 'Ordem sugerida',
  chave: 'Chave (A–Z)',
  recentes: 'Alterados primeiro',
};

export function adminConfig(s: Scope): SafeHtml {
  const d = s.data;
  const cromo: Dict = d.cromo && typeof d.cromo === 'object' ? d.cromo : {};
  const texto: Dict = cromo.texto ?? {};
  const tipos: Dict = cromo.tipos ?? {};
  const grupos: Dict[] = d.grupos ?? [];
  const campos: Dict[] = d.campos ?? [];
  const f: Dict = d.filtros ?? {};
  const resumo: Dict = d.resumo ?? {};
  const pag: Dict = d.paginacao ?? { pagina: 1, paginas: 1, total: 0 };
  const nova: Dict = d.nova ?? {};
  const exc: Dict | null = d.excluir && typeof d.excluir === 'object' ? d.excluir : null;
  const falha: string | null = d.falha ?? null;

  const acaoSalvar = url('admin/configuracoes/salvar');
  const acaoExcluir = url('admin/configuracoes/excluir');

  // Filtros em vigor: decide o botão "Limpar" e o texto da contagem.
  const filtrando = (f.q ?? '') !== '' || (f.grupo ?? '') !== '' || (f.tipo ?? '') !== '' || (f.estado ?? 'todos') !== 'todos';

  /** Selo do tipo, com o ícone E a palavra. */
  const seloTipo = (tipo: string): SafeHtml => {
    const m: Dict = tipos[tipo] ?? {};
    return html`<span class="adm-badge adm-badge--nodot cfg-tipo cfg-tipo--${tipo}">${icon(String(m.icon ?? 'tune'))}${String(m.label ?? tipo)}</span>`;
  };

  /** URL da própria tela preservando os filtros (paginação, link de excluir). */
  const comFiltros = (extra: Record<string, string | number> = {}): string => {
    const q: Record<string, string | number> = {};
    const base: Record<string, string> = {
      q: String(f.q ?? ''),
      grupo: String(f.grupo ?? ''),
      tipo: String(f.tipo ?? ''),
      estado: (f.estado ?? 'todos') !== 'todos' ? String(f.estado) : '',
      ordem: (f.ordem ?? 'sugerida') !== 'sugerida' ? String(f.ordem) : '',
    };
    for (const [k, v] of Object.entries(base)) if (v !== '') q[k] = v;
    return url('admin/configuracoes', { ...q, ...extra });
  };

  const total = Math.trunc(Number(resumo.total ?? 0));
  const ult: Dict | null = resumo.ultima && typeof resumo.ultima === 'object' ? resumo.ultima : null;

  // ============================================================ resumo do topo
  const kpis = html`<section class="adm-grid adm-grid--4 cfg-kpis">
  <div class="adm-kpi">
    <span class="adm-kpi__ico">${icon('tune')}</span>
    <span class="adm-kpi__txt">
      <span class="adm-kpi__label">Parâmetros</span>
      <span class="adm-kpi__value num">${num(total)}</span>
    </span>
  </div>
  <div class="adm-kpi adm-kpi--cyan">
    <span class="adm-kpi__ico">${icon('edit_note')}</span>
    <span class="adm-kpi__txt">
      <span class="adm-kpi__label">Ajustados pela equipe</span>
      <span class="adm-kpi__value num">${num(Math.trunc(Number(resumo.alterados ?? 0)))}<small>de ${num(total)}</small></span>
    </span>
  </div>
  <div class="adm-kpi adm-kpi--purple">
    <span class="adm-kpi__ico">${icon('category')}</span>
    <span class="adm-kpi__txt">
      <span class="adm-kpi__label">Grupos</span>
      <span class="adm-kpi__value num">${num(Math.trunc(Number(resumo.grupos ?? 0)))}</span>
    </span>
  </div>
  <div class="adm-kpi adm-kpi--amber">
    <span class="adm-kpi__ico">${icon('history')}</span>
    <span class="adm-kpi__txt">
      <span class="adm-kpi__label">Última alteração</span>
      <span class="adm-kpi__value cfg-kpi-data">${ult === null ? '—' : quando(ult.quando)}</span>
      ${ult !== null ? html`<span class="adm-kpi__delta">${String(ult.chave)}${ult.quem ? html`<small>por ${String(ult.quem)}</small>` : ''}</span>` : ''}
    </span>
  </div>
</section>`;

  if (falha !== null) {
    return html`${kpis}
  <section class="adm-card">
    <div class="adm-empty">
      ${icon('cloud_off')}
      <h2 class="adm-empty__title">Configurações indisponíveis</h2>
      <p class="adm-empty__txt">${falha}</p>
    </div>
  </section>`;
  }

  // ================================================= confirmação de exclusão
  let confirma: SafeHtml | string = '';
  if (exc !== null) {
    const alvo: Dict = exc.campo ?? {};
    const bloq = String(exc.bloqueio ?? '');
    confirma = html`<section class="adm-card cfg-confirma${bloq !== '' ? ' cfg-confirma--bloqueada' : ''}" id="confirmar-exclusao">
    <div class="adm-card__head">
      ${icon(bloq !== '' ? 'lock' : 'delete_forever', 'cfg-confirma__ico')}
      <h2 class="adm-card__title">
        ${bloq !== '' ? 'Esta chave não pode ser removida' : 'Confirmar remoção'}
        <small>${String(alvo.chave)} · ${String(alvo.label)}</small>
      </h2>
    </div>
    <div class="adm-card__body">
      ${bloq !== ''
        ? html`<p class="cfg-confirma__txt">${bloq}</p>
        <div class="adm-btns">
          <a class="adm-btn adm-btn--ghost" href="${comFiltros()}">${icon('arrow_back')}Voltar</a>
        </div>`
        : html`<p class="cfg-confirma__txt">
          A chave sai da tabela e o jogo passa a não encontrar valor nenhum para ela.
          O valor gravado agora é <code class="cfg-code">${emUmaLinha(String(alvo.gravado ?? ''), 120)}</code>
          e fica registrado no log de auditoria — é de lá que ele pode ser reconstruído.
        </p>
        <form class="adm-btns" method="post" action="${acaoExcluir}">
          ${csrfField()}
          <input type="hidden" name="chave" value="${String(alvo.chave)}">
          <input type="hidden" name="confirmar" value="1">
          <button class="adm-btn adm-btn--danger" type="submit">${icon('delete_forever')}Remover definitivamente</button>
          <a class="adm-btn adm-btn--ghost" href="${comFiltros()}">Cancelar</a>
        </form>`}
    </div>
  </section>`;
  }

  // ============================================================ barra de filtros
  const catGrupos: Dict = f.cat_grupos ?? {};
  const catTipos: Dict = f.cat_tipos ?? {};
  const barra = html`<section class="adm-card">
  <form class="adm-toolbar" method="get" action="${url('admin/configuracoes')}">
    <div class="adm-toolbar__grow">
      <label class="adm-search">
        ${icon('search')}
        <input class="adm-input" type="search" name="q" value="${String(f.q ?? '')}"
               placeholder="Buscar por chave, rótulo ou valor" aria-label="Buscar parâmetro">
      </label>
    </div>

    <label class="cfg-filtro">
      <span class="cfg-filtro__lbl">Grupo</span>
      <select class="adm-select" name="grupo" aria-label="Filtrar por grupo">
        <option value="">Todos (${num(total)})</option>
        ${join(Object.entries(catGrupos).map(([g, n]) => {
          const lbl = String(cromo.grupos?.[g]?.label ?? rotuloCru(g));
          return html`
          <option value="${g}"${(f.grupo ?? '') === g ? html` selected` : ''}>${lbl} (${String(n)})</option>`;
        }))}
      </select>
    </label>

    <label class="cfg-filtro">
      <span class="cfg-filtro__lbl">Tipo</span>
      <select class="adm-select" name="tipo" aria-label="Filtrar por tipo">
        <option value="">Todos</option>
        ${join(Object.entries(catTipos).map(([t, n]) => html`
          <option value="${t}"${(f.tipo ?? '') === t ? html` selected` : ''}>${String(tipos[t]?.label ?? t)} (${String(n)})</option>`))}
      </select>
    </label>

    <label class="cfg-filtro">
      <span class="cfg-filtro__lbl">Estado</span>
      <select class="adm-select" name="estado" aria-label="Filtrar por estado">
        ${join(Object.entries(ROTULO_ESTADO).map(([v, lbl]) => html`
          <option value="${v}"${(f.estado ?? 'todos') === v ? html` selected` : ''}>${lbl}</option>`))}
      </select>
    </label>

    <label class="cfg-filtro">
      <span class="cfg-filtro__lbl">Ordem</span>
      <select class="adm-select" name="ordem" aria-label="Ordenar campos">
        ${join(Object.entries(ROTULO_ORDEM).map(([v, lbl]) => html`
          <option value="${v}"${(f.ordem ?? 'sugerida') === v ? html` selected` : ''}>${lbl}</option>`))}
      </select>
    </label>

    <button class="adm-btn" type="submit">${icon('filter_alt')}Aplicar</button>
    ${filtrando ? html`<a class="adm-btn adm-btn--ghost" href="${url('admin/configuracoes')}">${icon('filter_alt_off')}Limpar</a>` : ''}
  </form>

  <p class="cfg-contagem">
    ${filtrando
      ? html`Mostrando <strong class="num">${num(Math.trunc(Number(resumo.exibidos ?? 0)))}</strong>
      de <span class="num">${num(total)}</span> parâmetros.`
      : html`<span class="num">${num(total)}</span> parâmetros em
      <span class="num">${num(Math.trunc(Number(resumo.grupos ?? 0)))}</span> grupos.
      Cada grupo é salvo por inteiro — ou tudo passa, ou nada é gravado.`}
  </p>
</section>`;

  // ================================================================== grupos
  const vazio =
    grupos.length === 0
      ? html`<section class="adm-card">
    <div class="adm-empty">
      ${icon(campos.length === 0 ? 'database_off' : 'search_off')}
      <h2 class="adm-empty__title">
        ${String(campos.length === 0 ? (texto.vazio_tabela ?? 'Tabela vazia') : (texto.vazio_titulo ?? 'Nada encontrado'))}
      </h2>
      <p class="adm-empty__txt">
        ${String(campos.length === 0 ? (texto.vazio_tabela_txt ?? '') : (texto.vazio_txt ?? ''))}
      </p>
      ${campos.length !== 0 ? html`<a class="adm-btn adm-btn--ghost" href="${url('admin/configuracoes')}">${icon('filter_alt_off')}Limpar filtros</a>` : ''}
    </div>
  </section>`
      : '';

  const item = (c: Dict): SafeHtml => {
    const chave = String(c.chave);
    const tipo = String(c.tipo);
    const erro = String(c.erro ?? '');
    const id = 'cfg-' + chave.replace(/[^a-z0-9_-]/g, '');
    const linhas = Math.trunc(Number(c.linhas ?? 1));
    const largo = tipo === 'json' || (tipo === 'string' && linhas > 1);
    const classes = 'cfg-item' + (largo ? ' cfg-item--largo' : '') + (erro !== '' ? ' is-invalid' : '') + (c.perigo ? ' cfg-item--perigo' : '');
    const maxlen = c.max_len !== null && c.max_len !== undefined ? html`maxlength="${String(c.max_len)}"` : '';

    let campo: SafeHtml;
    if (tipo === 'bool') {
      // O checkbox só é enviado quando marcado; o `campos[]` acima diz que ele estava na tela.
      const ligadoAgora = erro !== '' ? String(c.valor) === '1' : Boolean(c.ligado);
      campo = html`<label class="adm-switch cfg-switch">
                  <input class="adm-switch__in" type="checkbox" id="${id}"
                         name="campo[${chave}]" value="1"${ligadoAgora ? html` checked` : ''}>
                  <span class="adm-switch__ui"></span>
                  <span class="cfg-switch__txt cfg-switch__txt--${ligadoAgora ? 'on' : 'off'}">
                    ${ligadoAgora ? String(c.rot_on) : String(c.rot_off)}
                  </span>
                </label>`;
    } else if (tipo === 'int') {
      campo = html`<div class="cfg-num">
                  <input class="adm-input num cfg-num__in" type="text" inputmode="numeric"
                         id="${id}" name="campo[${chave}]"
                         value="${String(c.valor)}"
                         ${c.min !== null ? html`data-min="${String(c.min)}"` : ''}
                         ${c.max !== null ? html`data-max="${String(c.max)}"` : ''}
                         aria-describedby="${id}-faixa">
                  ${String(c.unidade) !== '' ? html`<span class="cfg-num__un">${String(c.unidade)}</span>` : ''}
                </div>
                ${c.min !== null || c.max !== null
                  ? html`<p class="adm-hint cfg-faixa" id="${id}-faixa">
                    Aceito: <span class="num">${String(c.min ?? '—')}</span>
                    a <span class="num">${String(c.max ?? '—')}</span>
                  </p>`
                  : ''}`;
    } else if (tipo === 'json') {
      campo = html`<textarea class="adm-textarea cfg-json" id="${id}" name="campo[${chave}]"
                          rows="7" spellcheck="false" data-cfg-json
                          aria-describedby="${id}-ajuda">${String(c.valor)}</textarea>
                <p class="adm-hint" id="${id}-ajuda">${String(texto.json_ajuda ?? '')}</p>`;
    } else if (linhas > 1) {
      campo = html`<textarea class="adm-textarea" id="${id}" name="campo[${chave}]"
                          rows="${String(Math.max(2, linhas))}"
                          ${maxlen}>${String(c.valor)}</textarea>
                ${c.max_len !== null ? html`<p class="adm-hint">Até <span class="num">${String(c.max_len)}</span> caracteres.</p>` : ''}`;
    } else {
      campo = html`<input class="adm-input" type="text" id="${id}" name="campo[${chave}]"
                       value="${String(c.valor)}"
                       ${maxlen}>`;
    }

    const anterior = c.anterior !== null && c.anterior !== undefined && String(c.anterior) !== String(c.gravado);

    return html`
          <div class="${classes}" id="${id}-linha">
            <input type="hidden" name="campos[]" value="${chave}">

            <div class="cfg-item__id">
              <label class="adm-label cfg-item__label" for="${id}">${String(c.label)}</label>
              <code class="cfg-item__chave">${chave}</code>
              ${String(c.dica) !== '' ? html`<p class="adm-hint cfg-item__dica">${String(c.dica)}</p>` : ''}
            </div>

            <div class="cfg-item__campo">
              ${campo}

              ${erro !== '' ? html`<p class="adm-error" role="alert">${icon('error')}${erro}</p>` : ''}
            </div>

            <div class="cfg-item__meta">
              ${seloTipo(tipo)}
              ${c.alterada
                ? html`<span class="cfg-meta__linha" title="Gravado em ${quando(c.quando)}">
                  ${icon('history')}${quando(c.quando)}${c.quem ? html` · ${String(c.quem)}` : ''}
                </span>`
                : html`<span class="cfg-meta__linha cfg-meta__linha--semente" title="Valor como veio do patch db/patch_v6_admin.php">
                  ${icon('spa')}Valor semeado
                </span>`}

              ${anterior
                ? html`<span class="cfg-antes" title="${String(texto.anterior ?? 'Valor anterior')}: ${String(c.anterior)}">
                  ${icon('undo')}era <code class="cfg-code">${emUmaLinha(String(c.anterior))}</code>
                </span>`
                : ''}

              ${c.protegida
                ? html`<span class="cfg-meta__linha cfg-meta__linha--lock" title="${String(texto.protegida ?? '')}">
                  ${icon('lock')}Lida pelo código
                </span>`
                : html`<a class="cfg-remover" href="${comFiltros({ excluir: chave })}#confirmar-exclusao">
                  ${icon('delete')}Remover
                </a>`}
            </div>
          </div>`;
  };

  const blocos = join(
    grupos.map((grupo) => {
      const itens: Dict[] = grupo.itens ?? [];
      if (itens.length === 0) return '';
      return html`
  <section class="adm-card cfg-grupo" id="grupo-${String(grupo.chave)}">
    <form method="post" action="${acaoSalvar}" data-cfg-form>
      ${csrfField()}
      <input type="hidden" name="acao" value="grupo">
      <input type="hidden" name="grupo" value="${String(grupo.chave)}">

      <div class="adm-card__head">
        <span class="cfg-grupo__ico">${icon(String(grupo.icon))}</span>
        <h2 class="adm-card__title">
          ${String(grupo.label)}
          ${String(grupo.desc) !== '' ? html`<small>${String(grupo.desc)}</small>` : ''}
        </h2>
        <div class="adm-card__tools">
          <span class="cfg-grupo__conta num">${String(itens.length)} ${itens.length === 1 ? 'parâmetro' : 'parâmetros'}</span>
        </div>
      </div>

      <div class="adm-card__body cfg-lista">
        ${join(itens.map(item))}
      </div>

      <div class="adm-card__foot cfg-foot">
        <span class="cfg-foot__aviso">${icon('info')}O jogo passa a ler o novo valor na requisição seguinte.</span>
        <button class="adm-btn adm-btn--primary" type="submit" data-cfg-salvar>
          ${icon('save')}Salvar ${String(grupo.label).toLowerCase()}
        </button>
      </div>
    </form>
  </section>`;
    }),
  );

  // ============================================================== paginação
  const paginas = Math.trunc(Number(pag.paginas ?? 1));
  let pager: SafeHtml | string = '';
  if (paginas > 1) {
    const atual = Math.trunc(Number(pag.pagina));
    const por = Math.trunc(Number(pag.por));
    const tot = Math.trunc(Number(pag.total));
    const botoes: SafeHtml[] = [];
    for (let i = 1; i <= paginas; i++) {
      botoes.push(html`
      <a class="adm-pager__btn${i === atual ? ' is-active' : ''}" href="${comFiltros({ p: i })}"${i === atual ? html` aria-current="page"` : ''}>${String(i)}</a>`);
    }
    pager = html`<nav class="adm-card cfg-pager" aria-label="Paginação dos grupos">
    <div class="adm-card__body adm-pager">
      <span class="adm-pager__info">
        Grupos <span class="num">${String((atual - 1) * por + 1)}</span>–<span class="num">${String(Math.min(tot, atual * por))}</span>
        de <span class="num">${String(tot)}</span>
      </span>
      <a class="adm-pager__btn${atual <= 1 ? ' is-disabled' : ''}" href="${comFiltros({ p: Math.max(1, atual - 1) })}" aria-label="Página anterior">${icon('chevron_left')}</a>
      ${join(botoes)}
      <a class="adm-pager__btn${atual >= paginas ? ' is-disabled' : ''}" href="${comFiltros({ p: Math.min(paginas, atual + 1) })}" aria-label="Próxima página">${icon('chevron_right')}</a>
    </div>
  </nav>`;
  }

  // ============================================================ nova chave
  const errosNova: Dict = nova.erros ?? {};
  const valoresNova: Dict = nova.valores ?? {};
  const abrirNova = Object.keys(errosNova).length > 0 || Boolean(f.abrir_nova);
  const erroOu = (campo: string, alt: SafeHtml | string = ''): SafeHtml | string =>
    campo in errosNova ? html`<p class="adm-error" role="alert">${icon('error')}${String(errosNova[campo])}</p>` : alt;
  const inval = (campo: string) => (campo in errosNova ? ' is-invalid' : '');

  const formNova = html`<section class="adm-card cfg-nova">
  <details${abrirNova ? html` open` : ''}>
    <summary class="adm-card__head cfg-nova__head">
      ${icon('add_circle', 'cfg-nova__ico')}
      <span class="adm-card__title">
        Criar chave
        <small>Um parâmetro novo só é lido pelo jogo depois que o código pedir por ele.</small>
      </span>
      ${icon('expand_more', 'cfg-nova__chev')}
    </summary>

    <form class="adm-card__body adm-form" method="post" action="${acaoSalvar}">
      ${csrfField()}
      <input type="hidden" name="acao" value="criar">

      <div class="adm-form__row adm-form__row--3">
        <div class="adm-field${inval('key')}">
          <label class="adm-label" for="nova-key">Chave <span class="adm-label__req">*</span></label>
          <input class="adm-input" type="text" id="nova-key" name="key" value="${String(valoresNova.key ?? '')}"
                 placeholder="ex.: loot_multiplier" maxlength="64" spellcheck="false">
          ${erroOu('key', html`<p class="adm-hint">Minúsculas, números e _ . É o nome que o código vai procurar.</p>`)}
        </div>

        <div class="adm-field${inval('kind')}">
          <label class="adm-label" for="nova-kind">Tipo <span class="adm-label__req">*</span></label>
          <select class="adm-select" id="nova-kind" name="kind">
            ${join(TIPOS.map((t) => html`
              <option value="${t}"${(valoresNova.kind ?? 'string') === t ? html` selected` : ''}>
                ${String(tipos[t]?.label ?? t)}
              </option>`))}
          </select>
          ${erroOu('kind')}
        </div>

        <div class="adm-field${inval('grupo')}">
          <label class="adm-label" for="nova-grupo">Grupo</label>
          <input class="adm-input" type="text" id="nova-grupo" name="grupo_novo" list="cfg-grupos"
                 value="${String(valoresNova.grupo ?? '')}" placeholder="geral" maxlength="32" spellcheck="false">
          <datalist id="cfg-grupos">
            ${join(Object.keys(catGrupos).map((g) => html`
              <option value="${g}"></option>`))}
          </datalist>
          ${erroOu('grupo', html`<p class="adm-hint">Em branco vira <code class="cfg-code">geral</code>.</p>`)}
        </div>
      </div>

      <div class="adm-field${inval('label')}">
        <label class="adm-label" for="nova-label">Rótulo <span class="adm-label__req">*</span></label>
        <input class="adm-input" type="text" id="nova-label" name="label" value="${String(valoresNova.label ?? '')}"
               placeholder="O que este parâmetro controla, em uma linha" maxlength="120">
        ${erroOu('label')}
      </div>

      <div class="adm-field${inval('value')}">
        <label class="adm-label" for="nova-value">Valor inicial</label>
        <textarea class="adm-textarea cfg-nova__valor" id="nova-value" name="novo[value]" rows="3"
                  spellcheck="false" placeholder="1 para um interruptor ligado; {} para um JSON vazio">${String(valoresNova.value ?? '')}</textarea>
        ${erroOu('value', html`<p class="adm-hint">Validado pelo tipo escolhido acima, com as mesmas regras da edição.</p>`)}
      </div>

      <div class="adm-form__actions">
        <button class="adm-btn adm-btn--primary" type="submit">${icon('add')}Criar chave</button>
        <span class="adm-hint adm-spacer">Chaves criadas aqui podem ser removidas; as que o código lê, não.</span>
      </div>
    </form>
  </details>
</section>`;

  return html`${kpis}

${confirma}

${barra}

${vazio}
${blocos}

${pager}

${formNova}
`;
}
