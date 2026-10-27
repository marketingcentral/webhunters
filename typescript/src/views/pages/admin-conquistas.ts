/**
 * Tela ADMIN · CONQUISTAS (/admin/conquistas) — app/Views/pages/admin-conquistas.php.
 * Layout 'admin'.
 *
 * A view não consulta o banco e não decide nada: tudo chega pronto de
 * repo/admin-achievement-repo.ts por controllers/admin/achievements-controller.ts.
 *
 * DUAS COLUNAS, UM CARTÃO DE CADA VEZ NA DIREITA:
 *   sem parâmetro  -> painel de categorias (o retrato do catálogo)
 *   ?novo / ?editar -> formulário
 *   ?excluir        -> confirmação, ou o bloqueio com o motivo escrito
 *
 * SEM JAVASCRIPT: filtro é <form method="get">, confirmação de exclusão é uma tela e o botão
 * que apaga é POST. Os marcadores <!--eh:lista--> / <!--/eh:lista--> (core/fragmento.ts)
 * estão escritos literalmente, como no PHP.
 */
import { csrfField, icon, num, url } from '../../core/helpers';
import { html, join, type SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';

/** sprintf só com %s, na ordem. */
function sprintf(fmt: string, ...args: string[]): string {
  let i = 0;
  return fmt.replace(/%s/g, () => args[i++] ?? '');
}

const str = (v: unknown, def = ''): string => (v === null || v === undefined ? def : String(v));
const int = (v: unknown, def = 0): number => {
  const n = Number.parseInt(String(v ?? ''), 10);
  return Number.isNaN(n) ? def : n;
};
const obj = (v: unknown): Dict => (v !== null && typeof v === 'object' && !Array.isArray(v) ? (v as Dict) : {});
const arr = (v: unknown): Dict[] => (Array.isArray(v) ? (v as Dict[]) : []);
const tem = (lista: Dict, k: string): boolean => Object.prototype.hasOwnProperty.call(lista, k) && lista[k] != null;

export function adminConquistas(s: Scope): SafeHtml {
  const data = s.data;

  /* ---------------------------------------------------------------------------- dados prontos */
  const cromo = obj(data.cromo);
  const catRot = obj(cromo.categorias);
  const estRot = obj(cromo.estados);
  const recRot = obj(cromo.recompensas);
  const ordens = obj(cromo.ordens);
  const titulos = obj(cromo.titulos);
  const vazios = obj(cromo.vazios);
  const micro = obj(cromo.micro);

  const resumo = obj(data.resumo);
  const catalogo = obj(data.catalogo);
  const linhas = arr(catalogo.linhas);
  const cats = arr(data.cats);
  const filtros = obj(data.filtros);
  const form = data.form !== null && typeof data.form === 'object' && !Array.isArray(data.form) ? (data.form as Dict) : null;
  const exclusao =
    data.exclusao !== null && typeof data.exclusao === 'object' && !Array.isArray(data.exclusao) ? (data.exclusao as Dict) : null;
  const itens = arr(data.itens);
  const destaque = int(data.destaque, 0);

  const fBusca = str(filtros.busca);
  const fCat = str(filtros.categoria);
  const fEstado = str(filtros.estado);
  const fOrdem = str(filtros.ordem, 'ordem');
  const fPagina = int(filtros.pagina, 1);

  const temFiltro = fBusca !== '' || fCat !== '' || fEstado !== '';

  /* Query string dos filtros ativos: viaja em TODO link da tela (ordenar, paginar, editar,
     excluir, cancelar) para o operador nunca perder o recorte que abriu. */
  const qs: Record<string, string> = {};
  if (fBusca !== '') qs.q = fBusca;
  if (fCat !== '') qs.cat = fCat;
  if (fEstado !== '') qs.estado = fEstado;
  if (fOrdem !== 'ordem') qs.ordem = fOrdem;
  if (fPagina > 1) qs.pagina = String(fPagina);

  /* $extra PRIMEIRO, como o `+` de arrays do PHP: a chave do operando da ESQUERDA vence. */
  const rota = (extra: Record<string, string> = {}): string => {
    const q = { ...extra };
    for (const [k, v] of Object.entries(qs)) if (!(k in q)) q[k] = v;
    return url('admin/conquistas', q);
  };

  /* ------------------------------------------------------------------------------ formatadores */

  /** Rótulo de categoria. Categoria que existe no banco e não tem tradução mostra o próprio slug. */
  const labelCat = (slug: string): string => str(obj(catRot[slug]).label, slug);
  const iconeCat = (slug: string): string => str(obj(catRot[slug]).icone, 'label');
  const catConhecida = (slug: string): boolean => tem(catRot, slug);

  /** Uma recompensa em UMA expressão curta: "6.500 XP", "2.400", "AK-103". */
  const textoRecompensa = (r: Dict): string => {
    const tipo = str(r.kind);
    if (tipo === 'item') {
      let nome = str(r.item_nome);
      const qtd = int(r.amount, 1);
      nome = nome !== '' ? nome : 'item removido do catálogo';
      return qtd > 1 ? qtd + '× ' + nome : nome;
    }
    const sufixo = str(obj(recRot[tipo]).sufixo);
    return num(int(r.amount, 0)) + (sufixo !== '' ? ' ' + sufixo : '');
  };

  /** A frase inteira das recompensas, para o title da célula (a tela mostra no máximo três). */
  const resumoRecompensas = (rs: Dict[]): string => {
    if (rs.length === 0) return str(micro.recompensa_som, 'Sem recompensa configurada.');
    return rs.map((r) => str(obj(recRot[str(r.kind)]).label, str(r.kind)) + ': ' + textoRecompensa(r)).join(' · ');
  };

  /** Erro de um campo do formulário, ou '' quando o campo está limpo. */
  const erroDe = (campo: string): string => {
    const v = obj(form?.erros)[campo];
    return typeof v === 'string' ? v : '';
  };
  const valorDe = (campo: string): string => str(obj(form?.valores)[campo]);

  /** Bloco <span class="adm-error"> de um campo — some sozinho quando não há erro. */
  const erroHtml = (campo: string): SafeHtml | string => {
    const e = erroDe(campo);
    return e === '' ? '' : html`<span class="adm-error">${icon('error')}${e}</span>`;
  };

  const plural = (n: number, sufixo: string): string => (n === 1 ? '' : sufixo);

  /* ======================================================================== LISTA (esquerda) */

  // O subtítulo é o retrato do catálogo INTEIRO, não do filtro.
  const partes = [
    num(int(resumo.conquistas)) + ' no catálogo',
    num(int(resumo.categorias)) + ' categorias',
    num(int(resumo.desbloqueios)) + ' desbloqueios',
  ];
  if (int(resumo.sem_recompensa) > 0) {
    partes.push(num(int(resumo.sem_recompensa)) + ' ' + str(vazios.sem_recompensa, 'sem recompensa'));
  }

  const filtrosForm = html`
    <!-- ---- filtros: form GET, sem JavaScript nenhum ---- -->
    <form class="adm-toolbar cq-filtros" method="get" action="${url('admin/conquistas')}"
          data-adm-preservar="filtros">
      <div class="adm-toolbar__grow adm-search">
        ${icon('search')}
        <input class="adm-input" type="search" name="q" value="${fBusca}"
               placeholder="Buscar por nome, slug ou descrição" aria-label="Buscar conquista" maxlength="80">
      </div>

      <label class="cq-filtros__campo">
        <span class="cq-filtros__lbl">Categoria</span>
        <select class="adm-select" name="cat">
          <option value="">Todas</option>
          ${join(cats.map((c) => html`
            <option value="${str(c.slug)}"${str(c.slug) === fCat ? ' selected' : ''}>
              ${labelCat(str(c.slug))} (${num(int(c.conquistas))})
            </option>`))}
        </select>
      </label>

      <label class="cq-filtros__campo">
        <span class="cq-filtros__lbl">Estado</span>
        <select class="adm-select" name="estado">
          <option value="">Qualquer</option>
          ${join(Object.entries(estRot).map(([slug, est]) => html`
            <option value="${slug}"${slug === fEstado ? ' selected' : ''}>
              ${str(obj(est).label)}
            </option>`))}
        </select>
      </label>

      <label class="cq-filtros__campo">
        <span class="cq-filtros__lbl">Ordenar</span>
        <select class="adm-select" name="ordem">
          ${join(Object.entries(ordens).map(([slug, rot]) => html`
            <option value="${slug}"${slug === fOrdem ? ' selected' : ''}>
              ${str(rot)}
            </option>`))}
        </select>
      </label>

      <button class="adm-btn adm-btn--ghost" type="submit">${icon('filter_alt')}<span>Filtrar</span></button>
      ${temFiltro ? html`
        <a class="adm-btn adm-btn--ghost cq-filtros__limpar" href="${url('admin/conquistas')}">
          ${icon('backspace')}<span>Limpar</span>
        </a>` : ''}
    </form>`;

  /* ---- uma linha da tabela (com o cabeçalho de grupo quando a categoria muda) ---- */
  let catAtual: string | null = null;
  const linhaHtml = (l: Dict): SafeHtml => {
    let grupo: SafeHtml | string = '';
    if (str(l.category) !== catAtual) {
      catAtual = str(l.category);
      const ca = catAtual;
      /* Contagem da categoria INTEIRA (não só do que caiu nesta página). */
      const info = cats.find((c) => str(c.slug) === ca) ?? null;
      grupo = html`
                  <tr class="cq-grp">
                    <th colspan="6" scope="colgroup"><span class="cq-grp__in">
                      <span class="cq-grp__nome">
                        ${icon(iconeCat(ca), 'cq-grp__ico')}
                        ${labelCat(ca)}
                        ${!catConhecida(ca) ? html`
                          <em class="cq-grp__alien" title="${str(micro.categoria_nova)}">
                            ${icon('help')}${str(micro.categoria_nova, 'fora do catálogo')}
                          </em>` : ''}
                      </span>
                      ${info !== null ? html`
                        <span class="cq-grp__n num">
                          ${num(int(info.conquistas))} conquista${plural(int(info.conquistas), 's')}
                          · ${num(int(info.desbloqueios))} desbloqueio${plural(int(info.desbloqueios), 's')}
                        </span>` : ''}
                      ${fCat === '' ? html`
                        <a class="cq-grp__so" href="${rota({ cat: ca, pagina: '1' })}">só esta</a>` : ''}
                    </span></th>
                  </tr>`;
    }

    const id = int(l.id);
    const estado = str(l.estado);
    const tom = str(obj(estRot[estado]).tom, 'muted');
    const recs = arr(l.recompensas);
    const desc = str(l.description);
    const metaTxt = int(l.target) <= 1
      ? str(micro.meta_um, 'Passo único.')
      : sprintf(str(micro.meta_n, 'Meta: %s'), num(int(l.target)));
    const nome = str(l.name);
    const desb = int(l.desbloqueios);

    const recsHtml = recs.length === 0
      ? html`
                      <span class="cq-sem">${icon('block')}${str(vazios.sem_recompensa, 'sem recompensa')}</span>`
      : html`
                      <span class="cq-recs">
                        ${join(recs.slice(0, 3).map((r) => {
                          const rt = obj(recRot[str(r.kind)]);
                          return html`
                          <span class="cq-rec cq-rec--${str(rt.tom, 'muted')}">
                            ${icon(str(rt.icone, 'redeem'))}
                            <span class="num">${textoRecompensa(r)}</span>
                          </span>`;
                        }))}
                        ${recs.length > 3 ? html`
                          <span class="cq-rec cq-rec--mais num">+${String(recs.length - 3)}</span>` : ''}
                      </span>`;

    const progressoHtml = int(l.progresso) > 0
      ? html`
                        <small class="num">${sprintf(str(micro.progresso_n, '%s em progresso'), num(int(l.progresso)))}</small>`
      : desb === 0
        ? html`
                        <small>${str(micro.nunca, 'ninguém ainda')}</small>`
        : '';

    /* Botão desarmado com o motivo no title: a trava de verdade está no controller. */
    const excluirHtml = l.pode_excluir
      ? html`
                      <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger"
                         href="${rota({ excluir: String(id) })}"
                         title="Excluir ${nome}" aria-label="Excluir ${nome}">
                        ${icon('delete')}
                      </a>`
      : html`
                      <span class="adm-btn adm-btn--sm adm-btn--icon adm-btn--ghost is-disabled cq-trava"
                            aria-disabled="true"
                            title="${sprintf(
                              str(micro.trava_excluir, 'Não dá para excluir: %s já está no histórico de %s.'),
                              '"' + nome + '"',
                              num(desb) + ' jogador' + plural(desb, 'es'),
                            )}">
                        ${icon('lock')}
                      </span>`;

    return html`${grupo}
                <tr class="cq-row cq-row--${estado}${id === destaque ? ' is-destaque' : ''}"
                    id="conquista-${String(id)}">

                  <td>
                    <span class="cq-nome">
                      <span class="cq-nome__hex cq-nome__hex--${tom}">${icon(str(l.icon) !== '' ? str(l.icon) : 'military_tech')}</span>
                      <span class="cq-nome__txt">
                        <strong title="${nome + (desc !== '' ? ' — ' + desc : '')}">${nome}</strong>
                        <small title="${'slug: ' + str(l.slug) + ' · ' + str(micro.ordem)}">
                          ${str(l.slug)} <span class="cq-nome__ordem num">#${str(l.sort_order)}</span>
                        </small>
                      </span>
                    </span>
                  </td>

                  <td title="${resumoRecompensas(recs)}">${recsHtml}
                  </td>

                  <td class="tr num" title="${metaTxt}">${num(int(l.target))}</td>

                  <td class="tr">
                    <span class="cq-desb">
                      <span class="num${desb === 0 ? ' is-zero' : ''}">${num(desb)}</span>${progressoHtml}
                    </span>
                  </td>

                  <td>
                    <span class="adm-badge adm-badge--${tom}" title="${str(obj(estRot[estado]).texto)}">
                      ${str(obj(estRot[estado]).label, estado)}
                    </span>
                  </td>

                  <td class="adm-table__acts">
                    <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--ghost"
                       href="${rota({ editar: String(id) })}"
                       title="Editar ${nome}" aria-label="Editar ${nome}">
                      ${icon('edit')}
                    </a>${excluirHtml}
                  </td>
                </tr>`;
  };

  /* Vazio por FILTRO e vazio por CATÁLOGO são problemas diferentes e têm saídas diferentes. */
  const tabelaHtml = linhas.length === 0
    ? html`
        <div class="adm-empty">
          ${icon(temFiltro ? 'search_off' : 'military_tech')}
          <h3 class="adm-empty__title">${temFiltro ? 'Nada com estes filtros' : 'Catálogo vazio'}</h3>
          <p class="adm-empty__txt">
            ${temFiltro
              ? sprintf(
                  str(vazios.filtro, 'Nenhuma conquista responde a estes filtros. O catálogo tem %s.'),
                  num(int(resumo.conquistas)) + ' conquista' + plural(int(resumo.conquistas), 's'),
                )
              : str(vazios.catalogo, 'A tabela achievements está vazia.')}
          </p>
          ${temFiltro
            ? html`<a class="adm-btn adm-btn--ghost" href="${url('admin/conquistas')}">${icon('backspace')}<span>Limpar filtros</span></a>`
            : html`<a class="adm-btn adm-btn--primary" href="${url('admin/conquistas', { novo: '1' })}">${icon('add')}<span>Criar a primeira</span></a>`}
        </div>`
    : html`
        <div class="adm-tablewrap">
          <table class="adm-table cq-tab">
            <colgroup>
              <col class="cq-tab__c-nome">
              <col class="cq-tab__c-rec">
              <col class="cq-tab__c-meta">
              <col class="cq-tab__c-desb">
              <col class="cq-tab__c-est">
              <col class="cq-tab__c-act">
            </colgroup>
            <thead>
              <tr>
                <th scope="col">Conquista</th>
                <th scope="col">Recompensas</th>
                <th scope="col" class="tr">Meta</th>
                <th scope="col" class="tr">Desbloqueios</th>
                <th scope="col">Estado</th>
                <th scope="col" class="tr">Ações</th>
              </tr>
            </thead>
            <tbody>${join(linhas.map(linhaHtml))}
            </tbody>
          </table>
        </div>`;

  /* ---- paginação: só existe quando há mais de uma página ---- */
  let pagerHtml: SafeHtml | string = '';
  if (int(catalogo.paginas, 1) > 1) {
    const pag = int(catalogo.pagina, 1);
    const tot = int(catalogo.paginas, 1);
    // Janela de 5 páginas ao redor da atual: com 20 botões a barra vira outra tabela.
    const ini = Math.max(1, Math.min(pag - 2, tot - 4));
    const fim = Math.min(tot, Math.max(pag + 2, 5));
    const botoes: SafeHtml[] = [];
    for (let i = ini; i <= fim; i++) {
      botoes.push(html`
            <a class="adm-pager__btn${i === pag ? ' is-active' : ''}"
               href="${rota({ pagina: String(i) })}"${i === pag ? html` aria-current="page"` : ''}>${String(i)}</a>`);
    }
    pagerHtml = html`
    <!-- ---- paginação: só existe quando há mais de uma página ---- -->
      <div class="adm-card__foot">
        <nav class="adm-pager" aria-label="Paginação do catálogo">
          <span class="adm-pager__info num" data-adm-total>
            ${num(int(catalogo.inicio))}–${num(int(catalogo.fim))}
            de ${num(int(catalogo.total))}
          </span>
          <a class="adm-pager__btn${pag <= 1 ? ' is-disabled' : ''}"
             href="${rota({ pagina: String(Math.max(1, pag - 1)) })}" aria-label="Página anterior">${icon('chevron_left')}</a>
          ${ini > 1 ? html`
            <a class="adm-pager__btn" href="${rota({ pagina: '1' })}">1</a>
            ${ini > 2 ? html`<span class="adm-pager__gap">…</span>` : ''}` : ''}${join(botoes)}
          ${fim < tot ? html`
            ${fim < tot - 1 ? html`<span class="adm-pager__gap">…</span>` : ''}
            <a class="adm-pager__btn" href="${rota({ pagina: String(tot) })}">${String(tot)}</a>` : ''}
          <a class="adm-pager__btn${pag >= tot ? ' is-disabled' : ''}"
             href="${rota({ pagina: String(Math.min(tot, pag + 1)) })}" aria-label="Próxima página">${icon('chevron_right')}</a>
        </nav>
      </div>`;
  }

  /* ======================================================================= COLUNA DA DIREITA */

  /** Campos ocultos dos filtros: viajam no POST para o redirect devolver o mesmo recorte. */
  const ocultosFiltro = html`
              <input type="hidden" name="f_q" value="${fBusca}">
              <input type="hidden" name="f_cat" value="${fCat}">
              <input type="hidden" name="f_estado" value="${fEstado}">
              <input type="hidden" name="f_ordem" value="${str(filtros.ordem)}">
              <input type="hidden" name="f_pagina" value="${String(fPagina)}">`;

  let lado: SafeHtml;
  if (form !== null) {
    lado = formularioHtml();
  } else if (exclusao !== null) {
    lado = exclusaoHtml(exclusao);
  } else {
    lado = categoriasHtml();
  }

  function formularioHtml(): SafeHtml {
    const f = form!;
    const editando = str(f.modo, 'novo') === 'editar';
    const formId = int(f.id, 0);
    const uso = f.uso !== null && typeof f.uso === 'object' ? (f.uso as Dict) : null;
    const recs = arr(f.recompensas);
    const errosRec = obj(obj(f.erros).recompensas);

    /* Linhas do bloco de recompensas: as que existem mais dois slots livres, dentro do teto. */
    const maxRec = Math.max(1, int(data.max_recompensas, 6));
    const nLinhas = Math.min(maxRec, Math.max(recs.length + 2, 3));

    // Categoria que veio do banco sem tradução continua selecionável.
    const categoriasForm: Dict = { ...catRot };
    for (const c of cats) {
      if (!tem(categoriasForm, str(c.slug))) {
        categoriasForm[str(c.slug)] = { label: str(c.slug), icone: 'label' };
      }
    }

    let usoHtml: SafeHtml | string = '';
    if (editando && uso !== null) {
      const ud = int(uso.desbloqueios);
      const up = int(uso.progresso);
      usoHtml = html`
          <p class="cq-form__uso${ud > 0 ? ' is-travada' : ''}">
            ${icon(ud > 0 ? 'lock' : 'group')}
            <span>
              ${ud > 0
                ? html`${num(ud)} jogador${plural(ud, 'es')} já concluiu esta conquista.
                  Editar é seguro; excluir, não.`
                : up > 0
                  ? html`${num(up)} jogador${plural(up, 'es')} com progresso e nenhuma conclusão.`
                  : str(micro.sem_vinculo, 'Nenhum jogador tem registro nesta conquista.')}
            </span>
          </p>`;
    }

    const linhasRec: SafeHtml[] = [];
    for (let i = 0; i < nLinhas; i++) {
      const r = recs[i] ?? { kind: '', amount: '', item_id: '' };
      const erro = str(errosRec[String(i)]);
      linhasRec.push(html`
              <div class="cq-rw__linha${erro !== '' ? ' is-invalid' : ''}">
                <select class="adm-select" name="rec_kind[]" aria-label="Tipo da recompensa ${String(i + 1)}">
                  <option value="">—</option>
                  ${join(Object.entries(recRot).map(([slug, rt]) => html`
                    <option value="${slug}"${slug === str(r.kind) ? ' selected' : ''}>
                      ${str(obj(rt).label)}
                    </option>`))}
                </select>
                <input class="adm-input num" type="number" name="rec_amount[]" min="0" step="1"
                       value="${str(r.amount)}"
                       aria-label="Valor da recompensa ${String(i + 1)}" placeholder="0">
                <select class="adm-select" name="rec_item[]" aria-label="Item da recompensa ${String(i + 1)}">
                  <option value="">—</option>
                  ${join(itens.map((it) => html`
                    <option value="${str(it.id)}"${str(it.id) === str(r.item_id) ? ' selected' : ''}>
                      ${str(it.name)}
                    </option>`))}
                </select>
                ${erro !== '' ? html`
                  <span class="adm-error cq-rw__erro">${icon('error')}${erro}</span>` : ''}
              </div>`);
    }

    // `($form['uso']['desbloqueios'] ?? 1) === 0` do PHP: sem uso conhecido, nada de botão.
    const podeExcluir = editando && uso !== null && uso.desbloqueios === 0;

    return html`
      <section class="adm-card cq-form">
        <div class="adm-card__head">
          <h2 class="adm-card__title">
            ${str(titulos[editando ? 'editar' : 'novo'], 'Conquista')}
            ${editando ? html`<small>${valorDe('slug')}</small>` : ''}
          </h2>
          <div class="adm-card__tools">
            <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--ghost" href="${rota()}"
               title="Fechar formulário" aria-label="Fechar formulário">${icon('close')}</a>
          </div>
        </div>

        <form class="adm-card__body cq-form__body scroll-y" method="post"
              action="${url('admin/conquistas/salvar')}">
          ${csrfField()}
          <input type="hidden" name="id" value="${String(formId)}">${ocultosFiltro}
${usoHtml}
          <div class="adm-field${erroDe('name') !== '' ? ' is-invalid' : ''}">
            <label class="adm-label" for="cq-name">Nome<span class="adm-label__req">*</span></label>
            <input class="adm-input" type="text" id="cq-name" name="name" maxlength="120" required
                   value="${valorDe('name')}" placeholder="CAÇADOR EXPERIENTE">
            ${erroHtml('name')}
          </div>

          <div class="adm-field${erroDe('slug') !== '' ? ' is-invalid' : ''}">
            <label class="adm-label" for="cq-slug">Slug${!editando ? html`<span class="adm-hint cq-form__auto">gerado do nome se ficar em branco</span>` : ''}</label>
            <input class="adm-input cq-form__mono" type="text" id="cq-slug" name="slug" maxlength="64"
                   value="${valorDe('slug')}" placeholder="cacador-experiente"
                   pattern="[a-z0-9]+(-[a-z0-9]+)*">
            ${erroDe('slug') !== ''
              ? erroHtml('slug')
              : editando
                ? html`<span class="adm-hint">${str(micro.slug_travado)}</span>`
                : ''}
          </div>

          <div class="adm-form__row">
            <div class="adm-field${erroDe('category') !== '' ? ' is-invalid' : ''}">
              <label class="adm-label" for="cq-cat">Categoria<span class="adm-label__req">*</span></label>
              <select class="adm-select" id="cq-cat" name="category" required>
                ${join(Object.entries(categoriasForm).map(([slug, c]) => html`
                  <option value="${slug}"${slug === valorDe('category') ? ' selected' : ''}>
                    ${str(obj(c).label)}
                  </option>`))}
              </select>
              ${erroHtml('category')}
            </div>

            <div class="adm-field${erroDe('icon') !== '' ? ' is-invalid' : ''}">
              <label class="adm-label" for="cq-icon">Ícone</label>
              <span class="cq-form__icone">
                <input class="adm-input cq-form__mono" type="text" id="cq-icon" name="icon" maxlength="48"
                       value="${valorDe('icon')}" placeholder="military_tech" pattern="[a-z0-9_]*">
                <span class="cq-form__prev" aria-hidden="true">${icon(valorDe('icon') !== '' ? valorDe('icon') : 'military_tech')}</span>
              </span>
              ${erroHtml('icon')}
            </div>
          </div>

          <div class="adm-field${erroDe('description') !== '' ? ' is-invalid' : ''}">
            <label class="adm-label" for="cq-desc">Descrição</label>
            <textarea class="adm-textarea" id="cq-desc" name="description" maxlength="400"
                      placeholder="O que o jogador precisa fazer.">${valorDe('description')}</textarea>
            ${erroHtml('description')}
          </div>

          <div class="adm-form__row">
            <div class="adm-field${erroDe('target') !== '' ? ' is-invalid' : ''}">
              <label class="adm-label" for="cq-target">Meta<span class="adm-label__req">*</span></label>
              <input class="adm-input num" type="number" id="cq-target" name="target" min="1" max="1000000" required
                     value="${valorDe('target')}">
              ${erroDe('target') !== '' ? erroHtml('target') : html`<span class="adm-hint">1 = passo único</span>`}
            </div>

            <div class="adm-field${erroDe('sort_order') !== '' ? ' is-invalid' : ''}">
              <label class="adm-label" for="cq-ordem">Ordem</label>
              <input class="adm-input num" type="number" id="cq-ordem" name="sort_order" min="0" max="65535"
                     value="${valorDe('sort_order')}">
              ${erroDe('sort_order') !== '' ? erroHtml('sort_order') : html`<span class="adm-hint">dentro da categoria</span>`}
            </div>
          </div>

          <!-- ---- recompensas ---- -->
          <fieldset class="cq-rw">
            <legend class="adm-label">Recompensas<span class="cq-rw__teto">até ${String(maxRec)}</span></legend>
            <p class="adm-hint cq-rw__ajuda">O campo <b>item</b> só é usado quando o tipo é <b>Item</b>; nos demais vale o valor.</p>

            <div class="cq-rw__cab" aria-hidden="true">
              <span>Tipo</span><span>Valor</span><span>Item</span>
            </div>
${join(linhasRec)}
            ${tem(errosRec, String(maxRec)) ? html`
              <span class="adm-error">${icon('error')}${str(errosRec[String(maxRec)])}</span>` : ''}
          </fieldset>

          <div class="adm-form__actions">
            <button class="adm-btn adm-btn--primary" type="submit">
              ${icon('save')}<span>${editando ? 'Salvar alterações' : 'Criar conquista'}</span>
            </button>
            <a class="adm-btn adm-btn--ghost" href="${rota()}">Cancelar</a>
            ${podeExcluir ? html`
              <a class="adm-btn adm-btn--danger adm-spacer" href="${rota({ excluir: String(formId) })}">
                ${icon('delete')}<span>Excluir</span>
              </a>` : ''}
          </div>
        </form>
      </section>`;
  }

  function exclusaoHtml(ex: Dict): SafeHtml {
    const alvo = obj(ex.conquista);
    const uso = obj(ex.uso);
    const bloq = Boolean(ex.bloqueada);
    const up = int(uso.progresso);

    const corpo = bloq
      ? html`
            <p class="cq-del__motivo">
              ${icon('lock')}
              <span>${str(ex.motivo)}</span>
            </p>
            <p class="cq-del__saida">
              O histórico do jogador cairia junto (<code>player_achievements</code> tem
              <code>ON DELETE CASCADE</code>). Para tirar a conquista de circulação sem apagar
              o passado de ninguém, edite-a — mudar categoria, meta ou descrição é seguro.
            </p>
            <div class="adm-form__actions">
              <a class="adm-btn adm-btn--primary" href="${rota({ editar: str(alvo.id) })}">
                ${icon('edit')}<span>Editar em vez de excluir</span>
              </a>
              <a class="adm-btn adm-btn--ghost" href="${rota()}">Voltar</a>
            </div>`
      : html`
            <p class="cq-del__aviso">
              ${icon('warning')}
              <span>
                ${up > 0
                  ? sprintf(
                      str(micro.perde_progresso, '%s tem progresso e vai perdê-lo.'),
                      num(up) + ' jogador' + plural(up, 'es'),
                    )
                  : str(micro.sem_vinculo, 'Nenhum jogador tem registro nesta conquista.')}
                ${str(micro.confirmar, 'Esta ação não tem desfazer.')}
              </span>
            </p>
            <form method="post" action="${url('admin/conquistas/excluir')}">
              ${csrfField()}
              <input type="hidden" name="id" value="${str(alvo.id)}">${ocultosFiltro}
              <div class="adm-form__actions">
                <button class="adm-btn adm-btn--danger" type="submit">
                  ${icon('delete_forever')}<span>Excluir definitivamente</span>
                </button>
                <a class="adm-btn adm-btn--ghost" href="${rota()}">Cancelar</a>
              </div>
            </form>`;

    return html`
      <section class="adm-card cq-del${bloq ? ' cq-del--bloq' : ''}">
        <div class="adm-card__head">
          <h2 class="adm-card__title">
            ${str(titulos[bloq ? 'bloqueio' : 'excluir'], 'Excluir conquista')}
          </h2>
          <div class="adm-card__tools">
            <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--ghost" href="${rota()}"
               title="Cancelar" aria-label="Cancelar">${icon('close')}</a>
          </div>
        </div>

        <div class="adm-card__body scroll-y">
          <p class="cq-del__alvo">
            <span class="cq-nome__hex cq-nome__hex--${str(obj(estRot[str(alvo.estado)]).tom, 'muted')}">
              ${icon(str(alvo.icon) !== '' ? str(alvo.icon) : 'military_tech')}
            </span>
            <span>
              <strong>${str(alvo.name)}</strong>
              <small class="cq-form__mono">${str(alvo.slug)} · ${labelCat(str(alvo.category))}</small>
            </span>
          </p>

          <dl class="adm-dl cq-del__nums">
            <dt>Desbloqueios</dt><dd class="num">${num(int(uso.desbloqueios))}</dd>
            <dt>Em progresso</dt><dd class="num">${num(up)}</dd>
            <dt>Recompensas</dt><dd class="num">${num(arr(alvo.recompensas).length)}</dd>
          </dl>
${corpo}
        </div>
      </section>`;
  }

  function categoriasHtml(): SafeHtml {
    let corpo: SafeHtml;
    if (cats.length === 0) {
      corpo = html`
            <div class="adm-empty">
              ${icon('category')}
              <p class="adm-empty__txt">${str(vazios.catalogo, 'Catálogo vazio.')}</p>
            </div>`;
    } else {
      const maxCat = Math.max(...cats.map((c) => int(c.conquistas)));
      corpo = html`
            <ul class="cq-cats__lista">${join(cats.map((c) => {
              const slug = str(c.slug);
              const on = slug === fCat;
              const fatia = maxCat > 0 ? Math.round((int(c.conquistas) / maxCat) * 100) : 0;
              const d = int(c.desbloqueios);
              return html`
                <li class="cq-cats__item${on ? ' is-on' : ''}">
                  <a href="${on ? url('admin/conquistas') : rota({ cat: slug, pagina: '1' })}"
                     title="${on ? 'Remover o filtro de categoria' : 'Filtrar por ' + labelCat(slug)}">
                    ${icon(iconeCat(slug), 'cq-cats__ico')}
                    <span class="cq-cats__txt">
                      <strong>${labelCat(slug)}</strong>
                      <small class="num">${num(d)} desbloqueio${plural(d, 's')}</small>
                    </span>
                    <span class="cq-cats__n num">${num(int(c.conquistas))}</span>
                    <span class="cq-cats__bar" style="--v:${String(fatia)}%" aria-hidden="true"></span>
                  </a>
                </li>`;
            }))}
            </ul>`;
    }

    return html`
      <section class="adm-card cq-cats">
        <div class="adm-card__head">
          <h2 class="adm-card__title">
            ${str(titulos.categorias, 'Categorias no banco')}
            <small>${num(int(resumo.categorias))} em uso · ${num(int(resumo.paradas))} conquista(s) sem uso</small>
          </h2>
        </div>
        <div class="adm-card__body adm-card__body--flush cq-cats__corpo scroll-y">${corpo}
        </div>
        <p class="adm-card__foot cq-cats__pe">
          ${icon('info')}
          <span>Categoria é texto livre em <code>achievements.category</code>: o que estiver no banco aparece aqui, com rótulo ou sem.</span>
        </p>
      </section>`;
  }

  return html`<div class="cq">

  <!-- ================================================================== LISTA (esquerda) -->
  <!--eh:lista-->
  <section class="adm-card cq__list">

    <div class="adm-card__head">
      <h2 class="adm-card__title">
        ${str(titulos.lista, 'Catálogo de conquistas')}
        <small>
          ${partes.join(' · ')}
        </small>
      </h2>
    </div>
${filtrosForm}

    <!-- ---- tabela ---- -->
    <div class="adm-card__body adm-card__body--flush cq__tabela">${tabelaHtml}
    </div>
${pagerHtml}
  </section>
  <!--/eh:lista-->

  <!-- ================================================================= COLUNA DA DIREITA -->
  <aside class="cq__side">
${lado}
  </aside>
</div>
`;
}
