/**
 * Tela ADMIN · LOJA (/admin/loja) — app/Views/pages/admin-loja.php. Layout 'admin'.
 *
 * A view NÃO consulta o banco e NÃO decide regra: tudo chegou pronto de
 * repo/admin-shop-offer-repo.ts via controllers/admin/shop-controller.ts. Aqui só existe
 * apresentação — o chip de moeda, o preço riscado, a seta de ordenação, o paginador.
 *
 * DUAS COISAS QUE ESTA TELA SE RECUSA A FAZER (iguais ao PHP)
 * 1. Escrever preço em R$: tudo aqui é moeda de jogo e cada número carrega o glifo da moeda.
 * 2. Confiar em JavaScript para confirmar uma exclusão: a confirmação é uma TELA servida em
 *    ?excluir=<id>, e o POST só apaga com o campo `confirmar`.
 *
 * Os marcadores <!--eh:lista--> / <!--/eh:lista--> (core/fragmento.ts) estão escritos
 * literalmente, como no PHP. Todo link e todo `action` levam os filtros atuais na query string.
 */
import { csrfField, icon, media, num, svg, url } from '../../core/helpers';
import { html, join, raw, type SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';

/** sprintf só com %s, na ordem. */
function sprintf(fmt: string, ...args: Array<string | number>): string {
  let i = 0;
  return fmt.replace(/%%|%s/g, (m) => (m === '%%' ? '%' : String(args[i++] ?? '')));
}

const str = (v: unknown, def = ''): string => (v === null || v === undefined ? def : String(v));
const int = (v: unknown, def = 0): number => {
  const n = Number.parseInt(String(v ?? ''), 10);
  return Number.isNaN(n) ? def : n;
};
const obj = (v: unknown): Dict => (v !== null && typeof v === 'object' && !Array.isArray(v) ? (v as Dict) : {});
const lst = (v: unknown): Dict[] => (Array.isArray(v) ? (v as Dict[]) : []);
/** `(string)($arr[$k] ?? $def)` */
const txtDe = (d: Dict, k: string, def = ''): string => str(d[k], def);
/** ucfirst() */
const ucfirst = (s: string): string => (s === '' ? s : s.charAt(0).toUpperCase() + s.slice(1));

export function adminLoja(s: Scope): SafeHtml {
  const data = s.data;
  const txt = obj(data.txt);
  const titulos = obj(txt.titulos);
  const rotulos = obj(txt.kpis);
  const notas = obj(txt.kpi_notas);
  const abas = obj(txt.categorias);
  const moedas = obj(txt.moedas);
  const raridades = obj(txt.raridades);
  const filtroTx = obj(txt.filtros);
  const estadoTx = obj(txt.estados);
  const colunas = obj(txt.colunas);
  const campos = obj(txt.campos);
  const ajudas = obj(txt.ajudas);
  const vazios = obj(txt.vazios);
  const acoesTx = obj(txt.acoes);
  const confTx = obj(txt.confirmacao);
  const micro = obj(txt.micro);
  const errosTx = obj(txt.erros);

  const f = obj(data.filtros);
  const query = obj(data.query) as Record<string, string>;
  const lista = obj(data.lista);
  const linhas = lst(lista.linhas);
  const resumo = obj(data.resumo);
  const porAba = obj(data.por_aba);
  const itens = lst(data.itens);
  const limites = obj(data.limites);

  const form = obj(data.form);
  const erros = obj(data.form_erros);
  const temErros = Object.keys(erros).length > 0;
  const formAberto = !!data.form_aberto;
  const emEdicao = data.form_edicao && typeof data.form_edicao === 'object' ? (data.form_edicao as Dict) : null;

  const aExcluir = data.excluir && typeof data.excluir === 'object' ? (data.excluir as Dict) : null;
  const bloqueio = data.excluir_bloqueio ?? null;
  const compras = data.excluir_compras ? obj(data.excluir_compras) : { n: 0, ultima: 0 };
  const recente = int(data.destaque_id, 0);

  const fq = str(f.q);
  const fcat = str(f.cat);
  const fmoeda = str(f.moeda);
  const festado = str(f.estado);
  const ford = str(f.ord, 'ordem');
  const fdir = str(f.dir, 'asc');

  const temFiltro = fq !== '' || fcat !== '' || fmoeda !== '' || festado !== '';

  /* =========================================================================================
     URLS — todo link e todo `action` levam os filtros atuais junto.
     ========================================================================================= */

  /* `$extra + $query` do PHP: as chaves do extra primeiro; chave com valor vazio é REMOVIDA,
     não escrita (é assim que o chip "Todas" apaga a categoria do filtro). */
  const urlLista = (extra: Record<string, string> = {}): string => {
    const q: Record<string, string> = { ...extra };
    for (const [k, v] of Object.entries(query)) if (!(k in q)) q[k] = v;
    const limpo: Record<string, string> = {};
    for (const [k, v] of Object.entries(q)) if (String(v) !== '') limpo[k] = String(v);
    return url('admin/loja', limpo);
  };
  const acaoSalvar = url('admin/loja/salvar', query);
  const acaoApagar = url('admin/loja/excluir', query);

  /* =========================================================================================
     FORMATADORES
     ========================================================================================= */

  /** Chip de preço na cor da moeda, com o mesmo glifo que o jogador vê na loja. */
  const chipMoeda = (valor: number, moeda: string, extra = ''): SafeHtml => {
    const m = obj(moedas[moeda] ?? moedas.credits);
    return html`<span class="loja-preco loja-preco--${str(m.tom, 'lime')}${extra !== '' ? ' ' + extra : ''}" title="${str(m.label, moeda)}">${svg(str(m.svg, 'coin-credit'), 'loja-preco__coin')}<span class="num">${num(valor)}</span></span>`;
  };

  /** Nome curto da moeda (CRD/DIN/PTS). */
  const moedaCurta = (m: string): string => str(obj(moedas[m]).curto, m.toUpperCase());

  /** Selo de raridade — só quando ela NÃO é a comum. */
  const seloRaridade = (r: string): SafeHtml | string => {
    if (r === '' || r === 'comum') return '';
    const meta = raridades[r] !== undefined && raridades[r] !== null ? obj(raridades[r]) : { label: ucfirst(r), tom: 'muted' };
    return html`<span class="adm-badge adm-badge--${str(meta.tom)} loja-rar">${str(meta.label)}</span>`;
  };

  /** Rótulo da aba; categoria antiga aparece com o slug cru. */
  const rotuloAba = (slug: string): string => str(abas[slug], slug);

  /** Cabeçalho ordenável. A seta desenhada é a que está VALENDO. */
  const th = (chave: string, rotulo: string, classe = ''): SafeHtml => {
    const ativo = ford === chave;
    const dir = ativo && fdir === 'asc' ? 'desc' : 'asc';
    const href = urlLista({ ord: chave, dir, pag: '1' });
    const seta = ativo ? icon(fdir === 'asc' ? 'arrow_upward' : 'arrow_downward', 'loja-th__seta') : '';
    return html`<th class="${(classe + (ativo ? ' is-sorted' : '')).trim()}"${
      ativo ? raw(' aria-sort="' + (fdir === 'asc' ? 'ascending' : 'descending') + '"') : ''
    }><a class="loja-th" href="${href}">${rotulo}${seta}</a></th>`;
  };

  /** Mensagem de erro de um campo. */
  const erro = (campo: string): SafeHtml | string => {
    if (erros[campo] === undefined || erros[campo] === null) return '';
    return html`<span class="adm-error">${icon('error')}<span>${str(erros[campo])}</span></span>`;
  };

  /** ' is-invalid' no .adm-field do campo que falhou. */
  const invalido = (campo: string): string => (erros[campo] !== undefined && erros[campo] !== null ? ' is-invalid' : '');

  /** Botão de uma ação rápida de linha: um FORMULÁRIO de verdade, com CSRF. */
  const botaoAcao = (camposH: Record<string, unknown>, icone: string, titulo: string, classe = '', ligado = false): SafeHtml => {
    const ocultos = join(Object.entries(camposH).map(([k, v]) => html`<input type="hidden" name="${k}" value="${str(v)}">`));
    return html`<form class="loja-acao" method="post" action="${acaoSalvar}">${csrfField()}${ocultos}<button class="adm-btn adm-btn--sm adm-btn--icon ${classe}${ligado ? ' is-on' : ''}" type="submit" title="${titulo}" aria-label="${titulo}">${icon(icone)}</button></form>`;
  };

  /* =========================================================================================
     FAIXA DE INDICADORES — os mesmos números da topbar, da mesma consulta
     ========================================================================================= */

  const semNada = int(resumo.ofertas, 0) === 0;
  const kpis = [
    {
      chave: 'ofertas', icone: 'sell', tom: 'lime',
      valor: num(int(resumo.ofertas, 0)),
      sub: int(resumo.itens, 0) > 0 ? 'de ' + num(int(resumo.itens, 0)) + ' itens' : null,
    },
    { chave: 'destaque', icone: 'star', tom: 'gold', valor: num(int(resumo.destaque, 0)), sub: null },
    {
      chave: 'promo', icone: 'percent', tom: int(resumo.promo, 0) > 0 ? 'amber' : 'cyan',
      valor: num(int(resumo.promo, 0)),
      sub: int(resumo.desconto_medio, 0) > 0 ? 'méd. ' + int(resumo.desconto_medio, 0) + '%' : null,
    },
    { chave: 'catalogo', icone: 'inventory_2', tom: 'purple', valor: num(int(resumo.itens_sem_oferta, 0)), sub: null },
  ];

  const secKpis = html`
  <!-- ================================================================= INDICADORES -->
  <section class="loja-kpis" aria-label="Resumo da loja">
    ${kpis.map((k) => html`
      <article class="adm-kpi adm-kpi--${k.tom}">
        <span class="adm-kpi__ico">${icon(k.icone)}</span>
        <span class="adm-kpi__txt">
          <span class="adm-kpi__label" title="${txtDe(notas, k.chave)}">
            ${txtDe(rotulos, k.chave)}
          </span>
          <span class="adm-kpi__value num">
            ${k.valor}
            ${k.sub !== null ? html`<small>${k.sub}</small>` : ''}
          </span>
        </span>
      </article>`)}
    <p class="loja-kpis__nota">${icon('info')}<span>${txtDe(micro, 'moeda_nota')}</span></p>
  </section>`;

  /* ============================================================== CONFIRMAR EXCLUSÃO */
  let secConf: SafeHtml | string = '';
  if (aExcluir !== null) {
    const temBloqueio = typeof bloqueio === 'string' && bloqueio !== '';
    const imagem = str(aExcluir.item_imagem);
    secConf = html`
    <section class="adm-card loja-conf${temBloqueio ? ' loja-conf--bloqueada' : ''}" aria-labelledby="loja-conf-t">
      <div class="adm-card__head">
        <h2 class="adm-card__title" id="loja-conf-t">
          ${icon(temBloqueio ? 'block' : 'delete_forever')}
          ${txtDe(titulos, 'exclusao', 'Excluir oferta')}
        </h2>
      </div>
      <div class="adm-card__body loja-conf__body">

        <div class="loja-conf__alvo">
          ${imagem !== ''
            ? html`<img class="loja-thumb" src="${media(imagem)}" alt=""
                 width="56" height="56" loading="lazy" decoding="async">`
            : html`<span class="loja-thumb loja-thumb--ph">${icon('inventory_2')}</span>`}
          <span class="loja-conf__id">
            <strong>${str(aExcluir.item_nome)}</strong>
            <small>
              ${rotuloAba(str(aExcluir.category))}
              · ${moedaCurta(str(aExcluir.currency))}
              ${num(int(aExcluir.preco_final, 0))}
              · oferta #${str(aExcluir.id)}
            </small>
          </span>
        </div>

        ${temBloqueio
          ? html`<p class="loja-conf__bloqueio">${icon('report')}<span>${str(bloqueio)}</span></p>`
          : html`<p class="loja-conf__pergunta">${txtDe(confTx, 'pergunta')}</p>
          <p class="loja-conf__efeito">${txtDe(confTx, 'efeito')}</p>
          <p class="loja-conf__hist">
            ${icon('receipt_long')}
            <span>${int(compras.n, 0) > 0
                ? sprintf(txtDe(confTx, 'historico', '%s compras'), num(int(compras.n, 0)))
                : txtDe(confTx, 'sem_historico')}</span>
          </p>`}

        <div class="adm-form__actions loja-conf__acoes">
          <a class="adm-btn" href="${urlLista()}">${icon('arrow_back')}<span>${txtDe(acoesTx, 'cancelar', 'Cancelar')}</span></a>
          ${!temBloqueio ? html`
            <form method="post" action="${acaoApagar}" class="adm-spacer">
              ${csrfField()}
              <input type="hidden" name="id" value="${str(aExcluir.id)}">
              <input type="hidden" name="confirmar" value="sim">
              <button class="adm-btn adm-btn--danger" type="submit">
                ${icon('delete_forever')}<span>${txtDe(acoesTx, 'confirmar', 'Excluir')}</span>
              </button>
            </form>` : ''}
        </div>
      </div>
    </section>`;
  }

  /* ==================================================================== FORMULÁRIO */
  let secForm: SafeHtml | string = '';
  if (formAberto) {
    /* Agrupado pela categoria do CATÁLOGO (items.category), que é a taxonomia do item — e
       não pela aba da loja, que é decisão desta tela. */
    const opcoesItem: SafeHtml[] = [];
    let grupoAtual: string | null = null;
    for (const it of itens) {
      const cat = str(it.categoria);
      if (grupoAtual !== cat) {
        if (grupoAtual !== null) opcoesItem.push(raw('</optgroup>'));
        grupoAtual = cat;
        opcoesItem.push(html`
                          <optgroup label="${cat.toUpperCase()}">`);
      }
      const n = int(it.ofertas, 0);
      opcoesItem.push(html`
                      <option value="${str(it.id)}"${int(form.item_id, 0) === int(it.id, 0) ? raw(' selected') : ''}>
                        ${str(it.nome)}
                        ${int(it.preco_base, 0) > 0 ? html`
                          — ${num(int(it.preco_base, 0))} ${moedaCurta(str(it.moeda_base))}` : ''}
                        ${n > 0 ? html`
                          · já em ${String(n)} oferta${n > 1 ? 's' : ''}` : ''}
                      </option>`);
    }
    if (grupoAtual !== null) opcoesItem.push(raw('</optgroup>'));

    const featured = !!form.featured;

    secForm = html`
    <section class="adm-card loja-form" aria-labelledby="loja-form-t">
      <div class="adm-card__head">
        <h2 class="adm-card__title" id="loja-form-t">
          ${icon(emEdicao !== null ? 'edit' : 'add_shopping_cart')}
          ${txtDe(titulos, emEdicao !== null ? 'formulario_editar' : 'formulario_novo', 'Oferta')}
          ${emEdicao !== null ? html`<small>#${str(emEdicao.id)} · ${str(emEdicao.item_nome)}</small>` : ''}
        </h2>
        <div class="adm-card__tools">
          <a class="adm-card__link" href="${urlLista()}">${txtDe(acoesTx, 'cancelar', 'Cancelar')}${icon('close')}</a>
        </div>
      </div>

      <form class="adm-card__body adm-form" method="post" action="${acaoSalvar}" novalidate>
        ${csrfField()}
        <input type="hidden" name="acao" value="formulario">
        <input type="hidden" name="id" value="${str(form.id, '0')}">

        ${temErros ? html`
          <p class="loja-form__aviso">${icon('error')}<span>${txtDe(errosTx, 'geral')}</span></p>` : ''}

        <div class="adm-form__row">
          <div class="adm-field${invalido('item_id')}">
            <label class="adm-label" for="of-item">${txtDe(campos, 'item', 'Item')}<span class="adm-label__req">*</span></label>
            ${itens.length === 0
              ? html`<p class="adm-hint">${txtDe(vazios, 'sem_item')}</p>`
              : html`<select class="adm-select" id="of-item" name="item_id" required>
                <option value="">— escolha o item —</option>${opcoesItem}
              </select>`}
            ${erro('item_id')}
            <p class="adm-hint">${txtDe(ajudas, 'item')}</p>
          </div>

          <div class="adm-field${invalido('category')}">
            <label class="adm-label" for="of-cat">${txtDe(campos, 'categoria', 'Categoria')}<span class="adm-label__req">*</span></label>
            <select class="adm-select" id="of-cat" name="category" required>
              <option value="">— escolha a aba —</option>
              ${Object.entries(abas).map(([slug, rot]) => html`
                <option value="${slug}"${str(form.category) === slug ? raw(' selected') : ''}>
                  ${str(rot)} (${str(porAba[slug], '0')})
                </option>`)}
            </select>
            ${erro('category')}
            <p class="adm-hint">${txtDe(ajudas, 'categoria')}</p>
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field${invalido('price')}">
            <label class="adm-label" for="of-preco">${txtDe(campos, 'preco', 'Preço')}</label>
            <input class="adm-input num" id="of-preco" name="price" type="text" inputmode="numeric"
                   value="${str(form.price)}" placeholder="0"
                   maxlength="9" autocomplete="off">
            ${erro('price')}
            <p class="adm-hint">${txtDe(ajudas, 'preco')}</p>
          </div>

          <div class="adm-field${invalido('currency')}">
            <label class="adm-label" for="of-moeda">${txtDe(campos, 'moeda', 'Moeda')}<span class="adm-label__req">*</span></label>
            <select class="adm-select" id="of-moeda" name="currency" required>
              ${Object.entries(moedas).map(([chave, m0]) => {
                const m = obj(m0);
                return html`
                <option value="${chave}"${str(form.currency) === chave ? raw(' selected') : ''}>
                  ${str(m.label)} (${str(m.curto)})
                </option>`;
              })}
            </select>
            ${erro('currency')}
            <p class="adm-hint">${txtDe(ajudas, 'moeda')}</p>
          </div>

          <div class="adm-field${invalido('discount')}">
            <label class="adm-label" for="of-desc">${txtDe(campos, 'desconto', 'Desconto')}</label>
            <input class="adm-input num" id="of-desc" name="discount" type="text" inputmode="numeric"
                   value="${str(form.discount)}" placeholder="0"
                   maxlength="2" autocomplete="off">
            ${erro('discount')}
            <p class="adm-hint">${txtDe(ajudas, 'desconto')}</p>
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field${invalido('tag')}">
            <label class="adm-label" for="of-tag">${txtDe(campos, 'tag', 'Etiqueta')}</label>
            <input class="adm-input" id="of-tag" name="tag" type="text"
                   value="${str(form.tag)}" placeholder="NOVO"
                   maxlength="${str(limites.tag, '16')}" autocomplete="off">
            ${erro('tag')}
            <p class="adm-hint">${txtDe(ajudas, 'tag')}</p>
          </div>

          <div class="adm-field${invalido('sort_order')}">
            <label class="adm-label" for="of-ordem">${txtDe(campos, 'ordem', 'Ordem')}</label>
            <input class="adm-input num" id="of-ordem" name="sort_order" type="text" inputmode="numeric"
                   value="${str(form.sort_order)}" placeholder="fim da aba"
                   maxlength="5" autocomplete="off">
            ${erro('sort_order')}
            <p class="adm-hint">${txtDe(ajudas, 'ordem')}</p>
          </div>

          <div class="adm-field">
            <span class="adm-label">${txtDe(campos, 'destaque', 'Destaque')}</span>
            <label class="adm-switch loja-form__switch">
              <input class="adm-switch__in" type="checkbox" name="featured" value="1"
                     ${featured ? raw('checked') : ''}>
              <span class="adm-switch__ui" aria-hidden="true"></span>
              <span>${featured ? 'Aparece em DESTAQUES' : 'Fora de DESTAQUES'}</span>
            </label>
            <p class="adm-hint">${txtDe(ajudas, 'destaque')}</p>
          </div>
        </div>

        <div class="adm-form__actions">
          <button class="adm-btn adm-btn--primary" type="submit">
            ${icon('save')}<span>${txtDe(acoesTx, 'salvar', 'Salvar')}</span>
          </button>
          <a class="adm-btn adm-btn--ghost" href="${urlLista()}">${txtDe(acoesTx, 'cancelar', 'Cancelar')}</a>
        </div>
      </form>
    </section>`;
  }

  /* ========================================================================= LISTA */

  /* Chips de aba: filtram E mostram a distribuição (cadeado na aba com UMA oferta, aviso
     âmbar na aba acima dos nichos da vitrine). */
  const nichos = int(limites.nichos, 12);
  const chipsAba = Object.entries(abas).map(([slug, rot0]) => {
    const rot = str(rot0);
    const n = int(porAba[slug], 0);
    const marca: [string, string, string] =
      n === 0 ? ['vazia', 'lock_open', 'Aba sem nenhuma oferta: a vitrine do jogo mostra doze nichos vazios.']
      : n === 1 ? ['travada', 'lock', 'Única oferta da aba — ela não pode ser excluída sem esvaziar a vitrine.']
      : n > nichos ? ['cheia', 'warning', sprintf(txtDe(micro, 'aba_cheia'), rot.toUpperCase(), String(n))]
      : ['', '', ''];
    return html`
        <a class="loja-aba${fcat === slug ? ' is-active' : ''}${marca[0] !== '' ? ' loja-aba--' + marca[0] : ''}"
           href="${urlLista({ cat: slug, pag: '1' })}"
           ${marca[2] !== '' ? html`title="${marca[2]}"` : ''}>
          <span>${rot}</span>
          <span class="loja-aba__n num">${num(n)}</span>
          ${marca[1] !== '' ? icon(marca[1], 'loja-aba__ico') : ''}
        </a>`;
  });

  /* As setas só são desabilitadas nas pontas da PÁGINA quando a lista está na ordem natural
     da vitrine: em qualquer outra ordenação, "acima" na tela não é "acima" na aba. */
  const ordemNatural = ford === 'ordem' && fdir === 'asc';

  const linhasHtml = linhas.map((l, i) => {
    const primeira = i === 0;
    const ultima = i === linhas.length - 1;
    const temDesconto = int(l.discount, 0) > 0;
    const id = int(l.id, 0);
    const imagem = str(l.item_imagem);
    const tag = str(l.tag);
    const featured = !!l.featured;
    const nome = str(l.item_nome);
    return html`
                <tr class="${id === recente ? 'is-recente ' : ''}${featured ? 'is-destaque' : ''}">

                  <td class="loja-col-ordem">
                    <div class="loja-ordem">
                      <span class="loja-ordem__n num" title="${txtDe(micro, 'ordem_val')}">${str(l.sort_order)}</span>
                      <span class="loja-ordem__setas">
                        ${ordemNatural && primeira
                          ? html`<span class="adm-btn adm-btn--sm adm-btn--icon is-disabled" aria-hidden="true">${icon('keyboard_arrow_up')}</span>`
                          : botaoAcao({ acao: 'mover', id, dir: 'cima' }, 'keyboard_arrow_up', txtDe(acoesTx, 'subir', 'Subir'))}
                        ${ordemNatural && ultima
                          ? html`<span class="adm-btn adm-btn--sm adm-btn--icon is-disabled" aria-hidden="true">${icon('keyboard_arrow_down')}</span>`
                          : botaoAcao({ acao: 'mover', id, dir: 'baixo' }, 'keyboard_arrow_down', txtDe(acoesTx, 'descer', 'Descer'))}
                      </span>
                    </div>
                  </td>

                  <td>
                    <span class="loja-item">
                      ${imagem !== ''
                        ? html`<img class="loja-thumb" src="${media(imagem)}" alt=""
                             width="36" height="36" loading="lazy" decoding="async">`
                        : html`<span class="loja-thumb loja-thumb--ph">${icon('inventory_2')}</span>`}
                      <span class="loja-item__txt">
                        <strong title="${nome}">${nome}</strong>
                        <small>${str(l.item_slug)}${tag !== '' ? html`
                          <span class="loja-tag">${tag}</span>
                        ` : ''}</small>
                      </span>
                    </span>
                  </td>

                  <td>
                    <span class="loja-cat">${rotuloAba(str(l.category))}</span>
                    ${seloRaridade(str(l.item_raridade))}
                  </td>

                  <td class="tr loja-col-desc">
                    ${temDesconto
                      ? html`<span class="adm-badge adm-badge--warn adm-badge--nodot num">−${str(l.discount)}%</span>`
                      : html`<span class="is-void" title="${txtDe(micro, 'sem_desconto')}">—</span>`}
                  </td>

                  <td class="tr loja-col-preco">
                    ${temDesconto ? html`<s class="loja-cheio num" title="Preço sem o desconto">${num(int(l.price, 0))}</s>` : ''}
                    ${chipMoeda(int(l.preco_final, 0), str(l.currency))}
                  </td>

                  <td class="tc loja-col-dest">
                    ${botaoAcao(
                      { acao: 'destaque', id },
                      featured ? 'star' : 'star_outline',
                      txtDe(acoesTx, featured ? 'tirar' : 'destacar', 'Destaque'),
                      'loja-star',
                      featured,
                    )}
                  </td>

                  <td class="adm-table__acts">
                    <span class="adm-btns loja-acoes">
                      <a class="adm-btn adm-btn--sm adm-btn--icon" href="${urlLista({ editar: String(id) })}"
                         title="${txtDe(acoesTx, 'editar', 'Editar')}"
                         aria-label="${txtDe(acoesTx, 'editar', 'Editar') + ': ' + nome}">${icon('edit')}</a>
                      <a class="adm-btn adm-btn--sm adm-btn--icon loja-del" href="${urlLista({ excluir: String(id) })}"
                         title="${txtDe(acoesTx, 'excluir', 'Excluir')}"
                         aria-label="${txtDe(acoesTx, 'excluir', 'Excluir') + ': ' + nome}">${icon('delete')}</a>
                    </span>
                  </td>
                </tr>`;
  });

  const corpoLista = linhas.length === 0
    ? html`
        <div class="adm-empty">
          ${icon(semNada ? 'storefront' : 'search_off')}
          <p class="adm-empty__title">${semNada ? 'Vitrine vazia' : 'Nenhuma oferta neste filtro'}</p>
          <p class="adm-empty__txt">${txtDe(vazios, semNada ? 'sem_oferta' : 'sem_filtro')}</p>
          ${semNada
            ? html`<a class="adm-btn adm-btn--primary" href="${url('admin/loja', { novo: '1' })}">
              ${icon('add')}<span>${txtDe(acoesTx, 'nova', 'Nova oferta')}</span>
            </a>`
            : html`<a class="adm-btn" href="${url('admin/loja')}">
              ${icon('filter_alt_off')}<span>${txtDe(filtroTx, 'limpar', 'Limpar filtros')}</span>
            </a>`}
        </div>`
    : html`
        <div class="adm-tablewrap">
          <table class="adm-table loja-tabela">
            <thead>
              <tr>
                ${th('ordem', txtDe(colunas, 'ordem', 'Ordem'), 'loja-col-ordem')}
                ${th('item', txtDe(colunas, 'item', 'Item'))}
                ${th('categoria', txtDe(colunas, 'categoria', 'Categoria'))}
                ${th('desconto', txtDe(colunas, 'desconto', 'Desc.'), 'tr loja-col-desc')}
                ${th('preco', txtDe(colunas, 'final', 'Preço final'), 'tr loja-col-preco')}
                ${th('destaque', txtDe(colunas, 'destaque', 'Destaque'), 'tc loja-col-dest')}
                <th class="adm-table__acts"><span class="sr-only">Ações</span></th>
              </tr>
            </thead>
            <tbody>${linhasHtml}
            </tbody>
          </table>
        </div>`;

  /* Paginador: só existe quando há mais de uma página. Janela: primeira, última e as duas
     vizinhas da atual. */
  let pager: SafeHtml | string = '';
  if (int(lista.paginas, 1) > 1) {
    const pag = int(lista.pagina, 1);
    const total = int(lista.paginas, 1);
    const mostrar = [...new Set([1, total, pag, pag - 1, pag + 1].filter((p) => p >= 1 && p <= total))].sort((a, b) => a - b);
    let anterior = 0;
    const botoes = mostrar.map((p) => {
      const gap = anterior > 0 && p > anterior + 1 ? html`<span class="adm-pager__gap">…</span>` : '';
      anterior = p;
      return html`
          ${gap}
          <a class="adm-pager__btn${p === pag ? ' is-active' : ''}"
             href="${urlLista({ pag: String(p) })}"
             ${p === pag ? raw('aria-current="page"') : ''}>${String(p)}</a>`;
    });
    pager = html`
      <nav class="adm-card__foot adm-pager" aria-label="Paginação das ofertas">
        <span class="adm-pager__info">${sprintf(txtDe(micro, 'pagina', '%s / %s'), num(pag), num(total))}</span>

        <a class="adm-pager__btn${pag <= 1 ? ' is-disabled' : ''}"
           href="${urlLista({ pag: String(Math.max(1, pag - 1)) })}"
           aria-label="Página anterior">${icon('chevron_left')}</a>
${botoes}

        <a class="adm-pager__btn${pag >= total ? ' is-disabled' : ''}"
           href="${urlLista({ pag: String(Math.min(total, pag + 1)) })}"
           aria-label="Próxima página">${icon('chevron_right')}</a>
      </nav>`;
  }

  const secLista = html`
  <!-- ========================================================================= LISTA -->
  <!--eh:lista-->
  <section class="adm-card loja-lista">
    <div class="adm-card__head">
      <h2 class="adm-card__title">
        ${txtDe(titulos, 'lista', 'Ofertas publicadas')}
        <small data-adm-total>${sprintf(txtDe(micro, 'total', '%s de %s'), num(linhas.length), num(int(lista.total, 0)))}</small>
      </h2>
      <div class="adm-card__tools">
        ${!formAberto && aExcluir === null ? html`
          <a class="adm-btn adm-btn--primary adm-btn--sm" href="${urlLista({ novo: '1' })}">
            ${icon('add')}<span>${txtDe(acoesTx, 'nova', 'Nova oferta')}</span>
          </a>` : ''}
      </div>
    </div>

    <!-- barra de busca e filtros (GET: o estado da lista é a URL, e a URL é compartilhável) -->
    <form class="adm-toolbar loja-bar" method="get" action="${url('admin/loja')}"
          data-adm-preservar="filtros">
      <label class="adm-search adm-toolbar__grow">
        <span class="sr-only">${txtDe(filtroTx, 'busca', 'Buscar')}</span>
        ${icon('search')}
        <input class="adm-input" type="search" name="q" value="${fq}"
               placeholder="${txtDe(filtroTx, 'busca')}" maxlength="60">
      </label>

      <label class="loja-bar__sel">
        <span class="sr-only">${txtDe(filtroTx, 'moeda', 'Moeda')}</span>
        <select class="adm-select" name="moeda">
          <option value="">${txtDe(filtroTx, 'moeda', 'Todas as moedas')}</option>
          ${Object.entries(moedas).map(([chave, m0]) => html`
            <option value="${chave}"${fmoeda === chave ? raw(' selected') : ''}>
              ${str(obj(m0).label)}
            </option>`)}
        </select>
      </label>

      <label class="loja-bar__sel">
        <span class="sr-only">${txtDe(filtroTx, 'estado', 'Estado')}</span>
        <select class="adm-select" name="estado">
          <option value="">${txtDe(filtroTx, 'estado', 'Qualquer estado')}</option>
          ${Object.entries(estadoTx).map(([chave, rot]) => html`
            <option value="${chave}"${festado === chave ? raw(' selected') : ''}>
              ${str(rot)}
            </option>`)}
        </select>
      </label>

      <input type="hidden" name="cat" value="${fcat}">
      ${ford !== 'ordem' || fdir !== 'asc' ? html`
        <input type="hidden" name="ord" value="${ford}">
        <input type="hidden" name="dir" value="${fdir}">` : ''}

      <button class="adm-btn adm-btn--sm" type="submit">${icon('filter_alt')}<span>Filtrar</span></button>
      ${temFiltro ? html`
        <a class="adm-btn adm-btn--ghost adm-btn--sm" href="${url('admin/loja')}">
          ${icon('filter_alt_off')}<span>${txtDe(filtroTx, 'limpar', 'Limpar')}</span>
        </a>` : ''}
    </form>

    <nav class="loja-abas" aria-label="Filtrar por aba da loja">
      <a class="loja-aba${fcat === '' ? ' is-active' : ''}"
         href="${urlLista({ cat: '', pag: '1' })}">
        <span>Todas</span><span class="loja-aba__n num">${num(int(resumo.ofertas, 0))}</span>
      </a>${chipsAba}
    </nav>

    <div class="adm-card__body adm-card__body--flush">${corpoLista}
    </div>
${pager}
  </section>
  <!--/eh:lista-->`;

  return html`<div class="loja">
${secKpis}
${secConf}
${secForm}
${secLista}
</div>`;
}
