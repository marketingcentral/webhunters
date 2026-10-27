/**
 * Tela ADMIN · ITENS (/admin/itens) — app/Views/pages/admin-itens.php. Layout 'admin'.
 *
 * A view não consulta o banco e não decide regra: tudo chegou pronto de
 * controllers/admin/items-controller.ts (política) e repo/admin-items-repo.ts (SQL).
 *
 * A tela tem TRÊS estados:
 *   lista          — o padrão;
 *   lista + form   — ?form=novo | ?form=<id>, o formulário abre ACIMA da lista (#adm-form);
 *   lista + baixa  — ?excluir=<id>, o painel de confirmação toma o lugar do formulário.
 *
 * Tudo navega por GET com a query string inteira preservada (busca, filtro, ordem, página).
 * Os marcadores <!--eh:lista--> / <!--/eh:lista--> (core/fragmento.ts) estão escritos
 * literalmente, como no PHP.
 *
 * DIFERENÇA PARA O PHP: "existe arte?" era is_file() em /media e em assets/img. Aqui é o
 * manifesto do build (generated/media.json + assetExists('img/...')). Um arquivo em
 * `uploads/` (enviado em produção para o R2, fora do manifesto) conta como existente — a view
 * é síncrona e não consulta o R2; media() faz a mesma suposição.
 */
import { assetExists, csrfField, icon, media, num, url } from '../../core/helpers';
import { html, join, raw, type SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import mediaManifest from '../../generated/media.json';

const mediaV = mediaManifest as Record<string, number>;

const str = (v: unknown, def = ''): string => (v === null || v === undefined ? def : String(v));
const int = (v: unknown, def = 0): number => {
  const n = Number.parseInt(String(v ?? ''), 10);
  return Number.isNaN(n) ? def : n;
};
const obj = (v: unknown): Dict => (v !== null && typeof v === 'object' && !Array.isArray(v) ? (v as Dict) : {});
const tem = (d: Dict, k: string): boolean => Object.prototype.hasOwnProperty.call(d, k) && d[k] != null;

/** Existe arte para desenhar? (o $temArte do PHP; ver topo do arquivo) */
const cacheArte = new Map<string, boolean>();
function temArte(caminho0: string): boolean {
  const caminho = caminho0.replace(/\\/g, '/').replace(/^\/+/, '');
  if (caminho === '' || caminho.includes('..')) return false;
  let r = cacheArte.get(caminho);
  if (r === undefined) {
    r = mediaV[caminho] !== undefined || caminho.startsWith('uploads/') || assetExists('img/' + caminho);
    cacheArte.set(caminho, r);
  }
  return r;
}

const FILTROS_OCULTOS = ['q', 'cat', 'rar', 'ord', 'p'];

export function adminItens(s: Scope): SafeHtml {
  const data = s.data;
  const cromo = obj(data.cromo);
  const CATS = obj(cromo.categorias);
  const RARS = obj(cromo.raridades);
  const SLOTS = obj(cromo.slots);
  const MOEDAS = obj(cromo.moedas);
  const ORDENS = obj(cromo.ordenacoes);
  const TITULOS = obj(cromo.titulos);
  const VAZIOS = obj(cromo.vazios);
  const FRASES = obj(cromo.frases);

  const resumo = obj(data.resumo);
  const linhas = (Array.isArray(data.linhas) ? data.linhas : []) as Dict[];
  const total = int(data.total, 0);
  const pagina = int(data.pagina, 1);
  const paginas = int(data.paginas, 1);
  const porPag = int(data.por_pagina, 20);

  const filtros = obj(data.filtros) as Record<string, string>;
  const temFiltro = Boolean(data.tem_filtro);
  const catsDb = obj(data.cats_banco);
  const rarsDb = obj(data.rars_banco);

  let edicao = data.edicao !== null && typeof data.edicao === 'object' ? (data.edicao as Dict) : null;
  const erros = obj(data.erros) as Record<string, string>;
  const baixa = data.excluir !== null && typeof data.excluir === 'object' ? (data.excluir as Dict) : null;

  /* Confirmar exclusão e editar ao mesmo tempo seria pedir duas decisões na mesma tela. A baixa
     é a mais destrutiva das duas, então ela vence e o formulário fica fechado. */
  if (baixa !== null) edicao = null;

  /* URL desta tela mantendo a query atual e trocando/soltando o que for pedido. */
  const aqui = (troca: Record<string, string | null> = {}): string => {
    const q: Record<string, string> = {};
    for (const [k, v] of Object.entries({ ...filtros, ...troca })) {
      if (v !== null && v !== undefined && v !== '') q[k] = String(v);
    }
    return url('admin/itens', q);
  };

  /* Rótulo de um valor de lista fechada. Valor que a tela não conhece aparece CRU. */
  const rotulo = (dic: Dict, v: string | null, vazio = '—'): string =>
    v === null || v === '' ? vazio : str(dic[v] ?? v);

  const de = total === 0 ? 0 : (pagina - 1) * porPag + 1;
  const ate = Math.min(pagina * porPag, total);

  /** Campos ocultos f_* com o filtro em que o operador estava. */
  const filtrosOcultos = (): SafeHtml =>
    join(FILTROS_OCULTOS.map((k) => (str(filtros[k]) !== ''
      ? html`
            <input type="hidden" name="f_${k}" value="${str(filtros[k])}">`
      : '')));

  /* ============================================================ resumo do catálogo */
  const secResumo = html`
<section class="adm-grid adm-grid--4 itens-kpis" aria-label="${str(TITULOS.resumo, 'Resumo')}">
  <div class="adm-kpi">
    <span class="adm-kpi__ico">${icon('inventory_2')}</span>
    <span class="adm-kpi__txt">
      <span class="adm-kpi__label">Itens no catálogo</span>
      <span class="adm-kpi__value num">${num(int(resumo.itens))}</span>
      <span class="adm-meta">${num(int(resumo.categorias))} categoria(s) em uso</span>
    </span>
  </div>
  <div class="adm-kpi adm-kpi--cyan">
    <span class="adm-kpi__ico">${icon('backpack')}</span>
    <span class="adm-kpi__txt">
      <span class="adm-kpi__label">Unidades com jogadores</span>
      <span class="adm-kpi__value num">${num(int(resumo.instancias))}</span>
      <span class="adm-meta">em ${num(int(resumo.donos))} inventário(s)</span>
    </span>
  </div>
  <div class="adm-kpi adm-kpi--gold">
    <span class="adm-kpi__ico">${icon('storefront')}</span>
    <span class="adm-kpi__txt">
      <span class="adm-kpi__label">Ofertas na loja</span>
      <span class="adm-kpi__value num">${num(int(resumo.ofertas))}</span>
      <span class="adm-meta">linhas de \`shop_offers\` apontando para itens</span>
    </span>
  </div>
  <div class="adm-kpi adm-kpi--amber">
    <span class="adm-kpi__ico">${icon('do_not_disturb_on')}</span>
    <span class="adm-kpi__txt">
      <span class="adm-kpi__label">Sem nenhum dono</span>
      <span class="adm-kpi__value num">${num(int(resumo.orfaos))}</span>
      <span class="adm-meta">itens que ninguém possui</span>
    </span>
  </div>
</section>
`;

  return html`${secResumo}
${baixa !== null ? telaBaixa(baixa) : ''}
${edicao !== null ? telaForm(edicao) : ''}
<!--eh:lista-->
${telaLista()}
<!--/eh:lista-->
`;

  /* ==================================================== confirmação de exclusão */
  function telaBaixa(b: Dict): SafeHtml {
    const item = obj(b.item);
    const usos = obj(b.usos);
    const bloqueado = int(usos.total) > 0;
    /* Cada referência com o EFEITO real da exclusão: CASCADE apaga junto, SET NULL deixa a
       missão premiando nada. */
    const refs = [
      { n: int(usos.instancias), ico: 'backpack', txt: 'unidade(s) no inventário de ' + num(int(usos.jogadores)) + ' jogador(es)', efeito: 'seriam apagadas em cascata' },
      { n: int(usos.ofertas), ico: 'storefront', txt: 'oferta(s) na loja', efeito: 'seriam apagadas em cascata' },
      { n: int(usos.missoes), ico: 'flag', txt: 'recompensa(s) de missão', efeito: 'ficariam sem item, premiando nada' },
      { n: int(usos.conquistas), ico: 'military_tech', txt: 'recompensa(s) de conquista', efeito: 'ficariam sem item, premiando nada' },
    ];
    const rar = str(item.rarity);
    const moeda = str(item.currency);

    const corpo = bloqueado
      ? html`
        <p class="itens-baixa__lead">
          ${icon('block', 'itens-baixa__ico')}
          <span>Este item <strong>não pode ser excluído</strong> enquanto for referenciado. Apagar a
          linha do catálogo levaria junto o que está listado abaixo — sem aviso e sem volta.</span>
        </p>
        <ul class="itens-baixa__refs">${refs.filter((r) => r.n > 0).map((r) => html`
            <li>
              ${icon(r.ico)}
              <b class="num">${num(r.n)}</b>
              <span>${r.txt} — <em>${r.efeito}</em></span>
            </li>`)}
        </ul>
        <p class="adm-hint">
          Para tirar o item de circulação sem destruir posse, prefira editá-lo (por exemplo,
          zerar o preço e removê-lo das ofertas) — a exclusão é para catálogo que nunca chegou
          a jogador nenhum.
        </p>
        <div class="adm-btns">
          <a class="adm-btn" href="${aqui({ excluir: null, form: str(item.id) })}#adm-form">${icon('edit')}<span>Editar este item</span></a>
        </div>`
      : html`
        <p class="itens-baixa__lead">
          ${icon('warning', 'itens-baixa__ico')}
          <span>Nada referencia <strong>${str(item.name)}</strong> — nenhum inventário,
          nenhuma oferta e nenhuma recompensa. A exclusão é definitiva e não tem desfazer.</span>
        </p>
        <dl class="adm-dl itens-baixa__ficha">
          <dt>Slug</dt><dd><code>${str(item.slug)}</code></dd>
          <dt>Categoria</dt><dd>${rotulo(CATS, str(item.category))}</dd>
          <dt>Raridade</dt><dd>${str(obj(RARS[rar]).label ?? rar, rar)}</dd>
          <dt>Preço</dt><dd class="num">${num(int(item.base_price))} ${str(obj(MOEDAS[moeda]).curto ?? moeda, moeda)}</dd>
        </dl>
        <form class="adm-btns" method="post" action="${url('admin/itens/excluir')}">
          ${csrfField()}
          <input type="hidden" name="id" value="${str(item.id)}">${filtrosOcultos()}
          <button class="adm-btn adm-btn--danger" type="submit">${icon('delete_forever')}<span>Excluir definitivamente</span></button>
          <a class="adm-btn adm-btn--ghost" href="${aqui({ excluir: null })}">Cancelar</a>
        </form>`;

    return html`
  <section class="adm-card itens-baixa${bloqueado ? ' is-bloqueada' : ''}" id="adm-form">
    <div class="adm-card__head">
      <h2 class="adm-card__title">
        ${str(TITULOS.excluir, 'Excluir item')}
        <small>${str(item.name)} · ${str(item.slug)}</small>
      </h2>
      <div class="adm-card__tools">
        <a class="adm-btn adm-btn--ghost adm-btn--sm" href="${aqui({ excluir: null })}">${icon('close')}<span>Cancelar</span></a>
      </div>
    </div>
    <div class="adm-card__body itens-baixa__body">${corpo}
    </div>
  </section>
`;
  }

  /* ============================================================== formulário */
  function telaForm(ed: Dict): SafeHtml {
    const novo = int(ed.id ?? 0) <= 0;
    const v = (campo: string, padrao = ''): string => (ed[campo] ?? null) === null ? padrao : String(ed[campo]);

    /* Um campo do formulário: rótulo, controle, erro do servidor e dica. */
    const campo = (nome: string, rot: string, ctrl: SafeHtml, dica = '', req = false): SafeHtml => {
      const erro = str(erros[nome]);
      return html`<div class="adm-field${erro !== '' ? ' is-invalid' : ''}"><label class="adm-label" for="f-${nome}">${rot}${
        req ? raw('<span class="adm-label__req" aria-hidden="true">*</span>') : ''}</label>${ctrl}${
        erro !== ''
          ? html`<p class="adm-error" id="e-${nome}">${icon('error')}<span>${erro}</span></p>`
          : dica !== '' ? html`<p class="adm-hint">${dica}</p>` : ''}</div>`;
    };

    /* Atributos comuns a todo controle: id previsível, aria-invalid e o vínculo com o erro. */
    const attrs = (nome: string): SafeHtml => {
      const err = tem(erros, nome);
      return html` id="f-${nome}" name="${nome}"${err ? html` aria-invalid="true" aria-describedby="e-${nome}"` : ''}`;
    };

    interface OpTexto { num?: boolean; type?: string; padrao?: string; max?: number; min_n?: number; max_n?: number; ph?: string }
    /* Campo numérico ganha a fonte tabular (.num) e inputmode. */
    const texto = (n: string, o: OpTexto = {}): SafeHtml =>
      html`<input class="adm-input${o.num ? ' num' : ''}"${attrs(n)} type="${o.type ?? 'text'}" value="${v(n, o.padrao ?? '')}"${
        o.max !== undefined ? raw(` maxlength="${o.max | 0}"`) : ''}${
        o.min_n !== undefined ? html` min="${String(o.min_n)}"` : ''}${
        o.max_n !== undefined ? html` max="${String(o.max_n)}"` : ''}${
        o.ph !== undefined ? html` placeholder="${o.ph}"` : ''}${
        o.num ? raw(' inputmode="numeric"') : ''}>`;

    const lista = (n: string, opcoes: Dict, vazio: boolean, rotVazio = '— nenhum —'): SafeHtml => {
      const atual = v(n);
      const ops: SafeHtml[] = [];
      if (vazio) ops.push(html`<option value="">${rotVazio}</option>`);
      for (const [chave, rot] of Object.entries(opcoes)) {
        ops.push(html`<option value="${chave}"${chave === atual ? raw(' selected') : ''}>${str(rot)}</option>`);
      }
      // Valor legado gravado no banco que não está na lista: entra como opção própria.
      if (atual !== '' && !Object.prototype.hasOwnProperty.call(opcoes, atual)) {
        ops.push(html`<option value="${atual}" selected>${atual} (valor atual)</option>`);
      }
      return html`<select class="adm-select"${attrs(n)}>${ops}</select>`;
    };

    const textarea = (n: string, extra: SafeHtml): SafeHtml =>
      html`<textarea class="adm-textarea"${attrs(n)}${extra}>${v(n)}</textarea>`;
    const jsonArea = (n: string, ph: string): SafeHtml =>
      html`<textarea class="adm-textarea itens-form__json"${attrs(n)} rows="4" spellcheck="false" placeholder=${raw("'" + ph + "'")}>${v(n)}</textarea>`;

    /* Categorias e raridades oferecidas = as conhecidas + as que já existem no banco. */
    const catsOpc: Dict = { ...CATS };
    for (const c of Object.keys(catsDb)) if (!tem(catsOpc, c)) catsOpc[c] = c;
    const rarsOpc: Dict = {};
    for (const [k, meta] of Object.entries(RARS)) rarsOpc[k] = str(obj(meta).label);
    for (const r of Object.keys(rarsDb)) if (!tem(rarsOpc, r)) rarsOpc[r] = r;
    const moedasOpc: Dict = {};
    for (const [k, meta] of Object.entries(MOEDAS)) moedasOpc[k] = str(obj(meta).label);

    const nErros = Object.keys(erros).length;

    return html`
  <section class="adm-card itens-form" id="adm-form">
    <div class="adm-card__head">
      <h2 class="adm-card__title">
        ${str(TITULOS[novo ? 'novo' : 'editar'], 'Item')}
        ${!novo ? html`<small>#${str(ed.id)} · ${v('name')}</small>` : ''}
      </h2>
      <div class="adm-card__tools">
        <a class="adm-btn adm-btn--ghost adm-btn--sm" href="${aqui({ form: null })}">${icon('close')}<span>Fechar</span></a>
      </div>
    </div>
${nErros > 0 ? html`
      <p class="itens-form__alerta" role="alert">
        ${icon('error')}
        <span>${nErros === 1 ? 'Um campo foi recusado pelo servidor.' : nErros + ' campos foram recusados pelo servidor.'}
        Nada foi gravado — corrija o que está marcado e salve de novo.</span>
      </p>
` : ''}
    <form class="adm-card__body adm-form" method="post" action="${url('admin/itens/salvar')}" novalidate>
      ${csrfField()}
      <input type="hidden" name="id" value="${str(ed.id ?? 0, '0')}">${filtrosOcultos()}

      <fieldset class="itens-form__bloco">
        <legend class="itens-form__legenda">${icon('badge')}<span>Identificação</span></legend>
        <div class="adm-form__row adm-form__row--3">
          ${campo('slug', 'Slug', texto('slug', { max: 64, ph: 'ak103' }), str(FRASES.slug_regra), true)}
          ${campo('name', 'Nome', texto('name', { max: 120, ph: 'AK-103' }), '', true)}
          ${campo('short_name', 'Nome curto', texto('short_name', { max: 32 }), 'Usado onde não cabe o nome inteiro (grade do inventário).')}
        </div>
        <div class="adm-form__row">
          ${campo('image', 'Imagem', texto('image', { max: 160, ph: 'items/ak103.png' }), 'Caminho relativo dentro de /media.', true)}
          ${campo('description', 'Descrição', textarea('description', raw(' maxlength="400" rows="3"')), 'Até 400 caracteres. Aparece na ficha do item.')}
        </div>
      </fieldset>

      <fieldset class="itens-form__bloco">
        <legend class="itens-form__legenda">${icon('category')}<span>Classificação</span></legend>
        <div class="adm-form__row adm-form__row--3">
          ${campo('category', 'Categoria', lista('category', catsOpc, false), '', true)}
          ${campo('subtype', 'Subtipo', texto('subtype', { max: 32, ph: 'assault_rifle' }), 'Minúsculas e sublinhado. Livre — é o recorte fino dentro da categoria.')}
          ${campo('slot_type', 'Slot de equipamento', lista('slot_type', SLOTS, true, '— não equipável —'), 'Em branco: o item só ocupa espaço na grade.')}
        </div>
        <div class="adm-form__row adm-form__row--3">
          ${campo('rarity', 'Raridade', lista('rarity', rarsOpc, false), '', true)}
          ${campo('grid_w', 'Largura na grade', texto('grid_w', { type: 'number', min_n: 1, max_n: 20, num: true, padrao: '1' }), 'Células ocupadas na horizontal.', true)}
          ${campo('grid_h', 'Altura na grade', texto('grid_h', { type: 'number', min_n: 1, max_n: 20, num: true, padrao: '1' }), 'Células ocupadas na vertical.', true)}
        </div>
      </fieldset>

      <fieldset class="itens-form__bloco">
        <legend class="itens-form__legenda">${icon('scale')}<span>Física e durabilidade</span></legend>
        <div class="adm-form__row adm-form__row--3">
          ${campo('weight_kg', 'Peso (kg)', texto('weight_kg', { num: true, ph: '3,600', padrao: '0.000' }), 'Até três casas. Vírgula ou ponto.', true)}
          ${campo('durability_max', 'Durabilidade máxima', texto('durability_max', { type: 'number', min_n: 1, max_n: 65535, num: true }), 'Só para peças que se desgastam (capacete, colete, arma).')}
          ${campo('ammo_caliber', 'Calibre', texto('ammo_caliber', { max: 24, ph: '7.62x39' }), 'Arma, carregador ou munição.')}
        </div>
        <div class="adm-form__row adm-form__row--3">
          ${campo('grid_cols', 'Grade interna — colunas', texto('grid_cols', { type: 'number', min_n: 1, max_n: 255, num: true }), 'Só contêineres (rig, mochila).')}
          ${campo('grid_rows', 'Grade interna — linhas', texto('grid_rows', { type: 'number', min_n: 1, max_n: 255, num: true }), 'Preencha as duas, ou nenhuma.')}
          ${campo('mag_capacity', 'Capacidade do carregador', texto('mag_capacity', { type: 'number', min_n: 1, max_n: 65535, num: true }), 'Quantos cartuchos o carregador leva.')}
        </div>
      </fieldset>

      <fieldset class="itens-form__bloco">
        <legend class="itens-form__legenda">${icon('payments')}<span>Economia</span></legend>
        <div class="adm-form__row adm-form__row--3">
          ${campo('base_price', 'Preço base', texto('base_price', { type: 'number', min_n: 0, num: true, padrao: '0' }), str(FRASES.preco_unid), true)}
          ${campo('currency', 'Moeda', lista('currency', moedasOpc, false), '', true)}
          ${campo('max_stack', 'Pilha máxima', texto('max_stack', { type: 'number', min_n: 1, max_n: 65535, num: true, padrao: '1' }), 'Empilhável exige pelo menos 2. Não empilhável é gravado como 1.', true)}
        </div>
        <div class="adm-form__row">
          <div class="adm-field">
            <span class="adm-label">Empilhamento</span>
            <label class="adm-switch">
              <input class="adm-switch__in" type="checkbox" name="stackable" value="1"${int(v('stackable', '0')) === 1 ? raw(' checked') : ''}>
              <span class="adm-switch__ui"></span>
              <span>Várias unidades ocupam a mesma célula</span>
            </label>
          </div>
        </div>
      </fieldset>

      <fieldset class="itens-form__bloco">
        <legend class="itens-form__legenda">${icon('data_object')}<span>Estrutura (JSON)</span></legend>
        <div class="adm-form__row adm-form__row--3">
          ${campo('attach_slots', 'Bocais de acessório', jsonArea('attach_slots', '[{"key":"sight","label":"MIRA","accepts":["sight"]}]'), 'Array JSON. Em branco grava NULL.')}
          ${campo('fits_calibers', 'Calibres aceitos', jsonArea('fits_calibers', '["7.62x39"]'), 'Array JSON. Em branco grava NULL.')}
          ${campo('meta', 'Meta', jsonArea('meta', '{"efeito":"cura","valor":25}'), 'Campo livre do item. JSON, ou em branco.')}
        </div>
      </fieldset>

      <div class="adm-form__actions">
        <button class="adm-btn adm-btn--primary" type="submit">${icon('save')}<span>${novo ? 'Cadastrar item' : 'Salvar alterações'}</span></button>
        <a class="adm-btn adm-btn--ghost" href="${aqui({ form: null })}">Cancelar</a>
${!novo ? html`          <a class="adm-btn adm-btn--danger adm-spacer" href="${aqui({ form: null, excluir: str(ed.id) })}#adm-form">${icon('delete')}<span>Excluir…</span></a>
` : ''}      </div>
    </form>
  </section>
`;
  }

  /* ================================================================== listagem */
  function telaLista(): SafeHtml {
    /* Filtro de categoria/raridade: conhecidas + as do banco; vazia aparece desabilitada. */
    const catsFiltro: Dict = { ...CATS };
    for (const c of Object.keys(catsDb)) if (!tem(catsFiltro, c)) catsFiltro[c] = c;
    const rarsFiltro: Dict = {};
    for (const [k, meta] of Object.entries(RARS)) rarsFiltro[k] = str(obj(meta).label);
    for (const r of Object.keys(rarsDb)) if (!tem(rarsFiltro, r)) rarsFiltro[r] = r;

    const opcoesContadas = (dic: Dict, banco: Dict, atual: string): SafeHtml =>
      join(Object.entries(dic).map(([chave, rot]) => {
        const n = int(banco[chave] ?? 0);
        return html`
        <option value="${chave}"${atual === chave ? raw(' selected') : ''}${n === 0 ? raw(' disabled') : ''}>
          ${str(rot)}${n > 0 ? ' (' + num(n) + ')' : ' — vazia'}
        </option>`;
      }));

    const barra = html`
  <form class="adm-toolbar" method="get" action="${url('admin/itens')}" role="search"
        data-adm-preservar="filtros">
    <div class="adm-toolbar__grow adm-search">
      ${icon('search')}
      <label class="sr-only" for="f-busca">Buscar por nome, slug ou nome curto</label>
      <input class="adm-input" id="f-busca" type="search" name="q" maxlength="64"
             value="${str(filtros.q)}" placeholder="Nome, slug ou nome curto…">
    </div>

    <label class="sr-only" for="f-cat">Categoria</label>
    <select class="adm-select itens-toolbar__sel" id="f-cat" name="cat">
      <option value="">Todas as categorias</option>${opcoesContadas(catsFiltro, catsDb, str(filtros.cat))}
    </select>

    <label class="sr-only" for="f-rar">Raridade</label>
    <select class="adm-select itens-toolbar__sel" id="f-rar" name="rar">
      <option value="">Todas as raridades</option>${opcoesContadas(rarsFiltro, rarsDb, str(filtros.rar))}
    </select>

    <label class="sr-only" for="f-ord">Ordenar por</label>
    <select class="adm-select itens-toolbar__sel" id="f-ord" name="ord">${Object.entries(ORDENS).map(([chave, rot]) => html`
        <option value="${chave}"${str(filtros.ord, 'nome') === chave ? raw(' selected') : ''}>${str(rot)}</option>`)}
    </select>

    <button class="adm-btn" type="submit">${icon('filter_alt')}<span>Filtrar</span></button>
${temFiltro ? html`      <a class="adm-btn adm-btn--ghost" href="${url('admin/itens')}">${icon('filter_alt_off')}<span>Limpar</span></a>
` : ''}  </form>
`;

    let corpo: SafeHtml;
    if (linhas.length === 0) {
      const vazio = obj(VAZIOS[temFiltro ? 'filtro' : 'catalogo']);
      corpo = html`
    <div class="adm-empty">
      ${icon(temFiltro ? 'search_off' : 'inventory_2')}
      <h3 class="adm-empty__title">${str(vazio.titulo, 'Nada aqui')}</h3>
      <p class="adm-empty__txt">${str(vazio.txt)}</p>
      ${temFiltro
        ? html`<a class="adm-btn" href="${url('admin/itens')}">${icon('filter_alt_off')}<span>Limpar filtros</span></a>`
        : html`<a class="adm-btn adm-btn--primary" href="${aqui({ form: 'novo' })}#adm-form">${icon('add')}<span>Cadastrar o primeiro item</span></a>`}
    </div>
`;
    } else {
      corpo = html`
    <div class="adm-tablewrap">
      <table class="adm-table itens-tabela">
        <caption class="sr-only">Catálogo de itens — ${num(total)} registro(s)</caption>
        <thead>
          <tr>
            <th scope="col">Item</th>
            <th scope="col">Categoria</th>
            <th scope="col">Raridade</th>
            <th class="tc" scope="col">Grade</th>
            <th class="tr" scope="col">Peso</th>
            <th class="tr" scope="col">Preço</th>
            <th class="tr" scope="col" title="${str(FRASES.uso_col)}">Em inventários</th>
            <th class="adm-table__acts" scope="col"><span class="sr-only">Ações</span></th>
          </tr>
        </thead>
        <tbody>${linhas.map(linha)}
        </tbody>
      </table>
    </div>
${paginas > 1 ? pager() : ''}`;
    }

    return html`<section class="adm-card itens-lista">
  <div class="adm-card__head">
    <h2 class="adm-card__title">
      ${str(TITULOS.lista, 'Catálogo de itens')}
      <small data-adm-total>
        ${total === 0 ? 'nenhum resultado' : html`${num(de)}–${num(ate)} de ${num(total)}${temFiltro ? ' (filtrado)' : ''}`}
      </small>
    </h2>
  </div>
${barra}${corpo}</section>`;
  }

  function linha(l: Dict): SafeHtml {
    const id = int(l.id);
    const rar = str(l.rarity);
    const tomRar = str(obj(RARS[rar]).tom ?? 'muted', 'muted');
    const rotRar = str(obj(RARS[rar]).label ?? rar, rar);
    const arte = temArte(str(l.image));
    const inst = int(l.inst);
    const jog = int(l.jogadores);
    const cur = str(l.currency);
    const moeda = str(obj(MOEDAS[cur]).curto ?? cur, cur);
    const emEdicao = edicao !== null && int(edicao.id ?? 0) === id;

    /* Subtipo + slot numa linha só; o valor inteiro vai para o `title`. */
    const sub: string[] = [];
    if (str(l.subtype) !== '') sub.push(str(l.subtype));
    if (str(l.slot_type) !== '') sub.push(rotulo(SLOTS, str(l.slot_type)));
    const subTxt = sub.length === 0 ? '—' : sub.join(' · ');
    const catTxt = rotulo(CATS, str(l.category));
    const nome = str(l.name);

    return html`
            <tr${emEdicao ? raw(' class="is-editando"') : ''}>
              <td>
                <div class="itens-cel">
                  ${arte
                    ? html`<img class="itens-cel__art" src="${media(str(l.image))}" alt=""
                         width="40" height="40" loading="lazy" decoding="async">`
                    : html`<span class="itens-cel__art itens-cel__art--falta"
                          title="${str(FRASES.imagem_falta)}">${icon('image_not_supported')}</span>`}
                  <span class="itens-cel__txt">
                    <strong title="${nome}">${nome}</strong>
                    <small title="${str(l.slug)}"><code>${str(l.slug)}</code>${str(l.short_name) !== '' ? ' · ' + str(l.short_name) : ''}</small>
                  </span>
                </div>
              </td>
              <td>
                <span class="itens-cat" title="${catTxt}">${catTxt}</span>
                <small class="adm-meta itens-cat__sub" title="${subTxt}">${subTxt}</small>
              </td>
              <td><span class="adm-badge adm-badge--${tomRar}">${rotRar}</span></td>
              <td class="tc num itens-grade">
                ${String(int(l.grid_w))}×${String(int(l.grid_h))}
                ${int(l.stackable) === 1 ? html`<small title="Empilhável até ${num(int(l.max_stack))} por célula">×${num(int(l.max_stack))}</small>` : ''}
              </td>
              <td class="tr num">${num(Number(l.weight_kg) || 0, 3)}<small> kg</small></td>
              <td class="tr num">${num(int(l.base_price))}<small> ${moeda}</small></td>
              <td class="tr num itens-posse${inst > 0 ? ' is-usado' : ''}">
                ${inst === 0
                  ? html`<span class="itens-posse__zero" title="Ninguém possui este item — ele pode ser excluído.">0</span>`
                  : html`${num(jog)}<small> jog.</small>
                  <span class="itens-posse__inst" title="${num(inst)} unidade(s) no total">${num(inst)} un.</span>`}
              </td>
              <td class="adm-table__acts">
                <a class="adm-btn adm-btn--sm adm-btn--icon" href="${aqui({ form: String(id), excluir: null })}#adm-form"
                   title="Editar ${nome}" aria-label="Editar ${nome}">${icon('edit')}</a>
                <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger" href="${aqui({ excluir: String(id), form: null })}#adm-form"
                   title="Excluir ${nome}" aria-label="Excluir ${nome}">${icon('delete')}</a>
              </td>
            </tr>`;
  }

  function pager(): SafeHtml {
    /* Janela de 5 páginas em volta da atual, sempre com a primeira e a última à vista. */
    const ini = Math.max(1, Math.min(pagina - 2, paginas - 4));
    const fim = Math.min(paginas, Math.max(pagina + 2, 5));
    const nums: SafeHtml[] = [];
    for (let n = ini; n <= fim; n++) {
      nums.push(html`
          <a class="adm-pager__btn${n === pagina ? ' is-active' : ''}"
             href="${aqui({ p: n > 1 ? String(n) : null })}"
             ${n === pagina ? raw('aria-current="page"') : ''}>${String(n)}</a>`);
    }
    return html`      <div class="adm-card__foot">
        <nav class="adm-pager" aria-label="Paginação do catálogo">
          <span class="adm-pager__info">Página ${num(pagina)} de ${num(paginas)}</span>

          <a class="adm-pager__btn${pagina <= 1 ? ' is-disabled' : ''}"
             href="${aqui({ p: pagina > 1 ? String(pagina - 1) : null })}"
             ${pagina <= 1 ? raw('aria-disabled="true" tabindex="-1"') : ''} aria-label="Página anterior">${icon('chevron_left')}</a>
${ini > 1 ? html`
          <a class="adm-pager__btn" href="${aqui({ p: null })}">1</a>
          ${ini > 2 ? raw('<span class="adm-pager__gap">…</span>') : ''}` : ''}${nums}
${fim < paginas ? html`
          ${fim < paginas - 1 ? raw('<span class="adm-pager__gap">…</span>') : ''}
          <a class="adm-pager__btn" href="${aqui({ p: String(paginas) })}">${String(paginas)}</a>` : ''}

          <a class="adm-pager__btn${pagina >= paginas ? ' is-disabled' : ''}"
             href="${aqui({ p: String(Math.min(paginas, pagina + 1)) })}"
             ${pagina >= paginas ? raw('aria-disabled="true" tabindex="-1"') : ''} aria-label="Próxima página">${icon('chevron_right')}</a>
        </nav>
      </div>
`;
  }
}
