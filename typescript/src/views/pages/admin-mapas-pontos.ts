/**
 * Tela ADMIN · CRIAR PONTOS NO MAPA (/admin/mapas/pontos) — app/Views/pages/admin-mapas-pontos.php.
 * Layout 'admin'.
 *
 * A view não consulta o banco e não valida nada — tudo chega pronto de
 * controllers/admin/map-points-controller.ts. O que se decide aqui é APRESENTAÇÃO.
 *
 * TRÊS DECISÕES DE DESENHO (iguais ao PHP)
 * 1. O catálogo é uma GRADE DE FICHAS, não uma tabela: o dado mais importante de um ponto é
 *    o desenho dele.
 * 2. O formulário tem PRÉ-VISUALIZAÇÃO ao vivo (assets/js/admin/admin-mapas-pontos.js). Sem
 *    JavaScript os três campos de ícone aparecem juntos e o preview mostra o que está gravado.
 * 3. Excluir é uma VIAGEM (?excluir=N), não um confirm().
 *
 * Os marcadores <!--eh:lista--> / <!--/eh:lista--> (core/fragmento.ts) estão escritos
 * literalmente, como no PHP.
 */
import { BASE_URL } from '../../core/config';
import { csrfField, dataMock, icon, url } from '../../core/helpers';
import { html, join, raw, type SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { adminUpload } from '../components/admin-upload';
import { mapPointIconCmp } from '../components/map-point-icon';

/** sprintf só com %s/%d, na ordem. */
function sprintf(fmt: string, ...args: Array<string | number>): string {
  let i = 0;
  return fmt.replace(/%[sd]/g, () => String(args[i++] ?? ''));
}

/**
 * json_encode(..., JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) — para
 * dados dentro de <script type="application/json">: `<` e `>` viram \u003C/\u003E.
 */
export function jsonHexTag(v: unknown): SafeHtml {
  return raw(JSON.stringify(v).replace(/</g, '\\u003C').replace(/>/g, '\\u003E'));
}

const str = (v: unknown, def = ''): string => (v === null || v === undefined ? def : String(v));
/** `$a ?: $b` do PHP para strings: '' e '0' são falsos. */
export const phpOu = (a: string, b: string): string => (a === '' || a === '0' ? b : a);
const int = (v: unknown): number => {
  const n = Number.parseInt(String(v ?? ''), 10);
  return Number.isNaN(n) ? 0 : n;
};
const obj = (v: unknown): Dict => (v !== null && typeof v === 'object' && !Array.isArray(v) ? (v as Dict) : {});
const lista = (v: unknown): Dict[] => (Array.isArray(v) ? (v as Dict[]) : []);
const tem = (o: Dict, k: string): boolean => Object.prototype.hasOwnProperty.call(o, k) && o[k] != null;

export function adminMapasPontos(s: Scope): SafeHtml {
  const data = s.data;
  const cromo = obj(data.cromo);
  const origens = obj(cromo.origens);
  const glifos = obj(cromo.glifos);
  const simbolos = Array.isArray(cromo.simbolos) ? (cromo.simbolos as unknown[]) : [];
  const vazios = obj(cromo.vazios);
  const notas = obj(cromo.notas);

  const modo = str(data.modo, 'lista');
  const grupos = lista(data.grupos);
  const cats = obj(data.categorias);
  const cores = Array.isArray(data.cores) ? (data.cores as unknown[]) : [];
  const kinds = obj(data.kinds);
  const f = obj(data.filtros);
  const form = obj(data.form);
  const erros = obj(data.erros);
  const alvo = data.alvo && typeof data.alvo === 'object' ? (data.alvo as Dict) : null;
  const excluir = data.excluir && typeof data.excluir === 'object' ? (data.excluir as Dict) : null;
  const sistema = alvo !== null && Boolean(alvo.is_system);

  /** Link da tela mantendo busca/categoria; extras na frente, vazios fora (array_filter). */
  const lnk = (extra: Record<string, string> = {}): string => {
    const q: Record<string, string> = { ...extra };
    if (!('q' in q)) q.q = str(f.q);
    if (!('cat' in q)) q.cat = str(f.cat);
    const limpo: Record<string, string> = {};
    for (const [k, v] of Object.entries(q)) if (v !== '') limpo[k] = v;
    return url('admin/mapas/pontos', limpo);
  };

  const err = (k: string): boolean => tem(erros, k);
  const inval = (k: string): string => (err(k) ? ' is-invalid' : '');
  const sel = (a: unknown, b: unknown): string => (str(a) === str(b) ? ' selected' : '');

  // ============================ CONFIRMAÇÃO DE EXCLUSÃO ============================
  if (excluir !== null) {
    const ponto = obj(excluir.ponto);
    const usos = int(excluir.usos);
    return html`
<!-- ============================ CONFIRMAÇÃO DE EXCLUSÃO ============================ -->
<section class="adm-card adm-card--danger">
  <div class="adm-card__head">
    <h2 class="adm-card__title">${icon('delete', 'adm-card__ico')} Excluir "${str(ponto.label)}"?</h2>
  </div>
  <div class="adm-card__body">
    <p class="adm-hint">
      ${sprintf(str(notas.excluir, '%s'), str(ponto.kind))}
    </p>
    <p class="adm-hint">
      ${usos > 0
        ? html`<strong class="num">${usos}</strong> marcador(es) no mundo usam este ponto hoje.`
        : 'Nenhum marcador usa este ponto — a exclusão não muda nada no mapa.'}
    </p>
  </div>
  <div class="adm-card__foot">
    <a class="adm-btn adm-btn--ghost" href="${lnk()}">Cancelar</a>
    <form method="post" action="${url('admin/mapas/pontos/excluir')}">
      ${csrfField()}
      <input type="hidden" name="id" value="${int(ponto.id)}">
      <button class="adm-btn adm-btn--danger" type="submit">${icon('delete')} Excluir ponto</button>
    </form>
  </div>
</section>
`;
  }

  // ================================ FORMULÁRIO ================================
  if (modo === 'editor') {
    const origemAtual = str(form.icon_origem);
    const hintOrigem = str(obj(origens[str(form.icon_origem, 'symbol')]).hint);
    return html`
<!-- ================================ FORMULÁRIO ================================ -->
<script type="application/json" data-mpt-glifos>${jsonHexTag(dataMock('map_glyphs'))}</script>

<form class="adm-card mpt-form" method="post" action="${url('admin/mapas/pontos/salvar')}"
      data-mpt-form data-mpt-media="${BASE_URL + 'media/'}">
  ${csrfField()}
  ${alvo !== null ? html`<input type="hidden" name="id" value="${int(alvo.id)}">` : ''}
  ${alvo === null ? html`<input type="hidden" name="novo" value="1">` : ''}

  <div class="adm-card__head">
    <h2 class="adm-card__title">
      ${icon('add_location_alt', 'adm-card__ico')}
      ${alvo !== null ? 'Editar ponto' : 'Novo ponto'}
    </h2>
    ${sistema ? html`<span class="adm-badge adm-badge--muted" title="${str(notas.sistema)}">Ponto do jogo</span>` : ''}
  </div>

  <div class="adm-card__body mpt-form__body">

    <!-- coluna: pré-visualização -->
    <aside class="mpt-preview">
      <span class="mpt-preview__box" data-mpt-preview>
        ${mapPointIconCmp({
          icon: str(form.icon_origem, 'symbol') + ':' + str(form.icon_valor, 'place'),
          color: str(form.color, 'white'),
          size: 'lg',
        })}
      </span>
      <strong class="mpt-preview__name" data-mpt-preview-name>${phpOu(str(form.label), 'SEM NOME')}</strong>
      <span class="mpt-preview__hint">É assim que o ponto aparece na paleta e no mapa.</span>
    </aside>

    <!-- coluna: campos -->
    <div class="mpt-fields">

      <div class="adm-form__row adm-form__row--2">
        <label class="adm-field">
          <span class="adm-label">Nome<span class="adm-label__req">*</span></span>
          <input class="adm-input${inval('label')}" type="text" name="label"
                 value="${str(form.label)}" maxlength="60" required data-mpt-label>
          ${err('label') ? html`<span class="adm-hint adm-hint--err">${str(erros.label)}</span>` : ''}
        </label>
        <label class="adm-field">
          <span class="adm-label">Chave</span>
          <input class="adm-input${inval('slug')}" type="text" name="slug"
                 value="${str(form.slug)}" maxlength="48"
                 placeholder="gerada a partir do nome"${sistema ? ' readonly' : ''}>
          <span class="adm-hint${err('slug') ? ' adm-hint--err' : ''}">
            ${err('slug') ? str(erros.slug) : 'É o que os marcadores guardam. Deixe em branco para gerar.'}
          </span>
        </label>
      </div>

      <div class="adm-form__row adm-form__row--2">
        <label class="adm-field">
          <span class="adm-label">Categoria<span class="adm-label__req">*</span></span>
          <select class="adm-select${inval('category')}" name="category">
            ${join(Object.entries(cats).map(([k, rot]) => html`
              <option value="${k}"${sel(form.category, k)}>${str(rot)}</option>`))}
          </select>
          <span class="adm-hint${err('category') ? ' adm-hint--err' : ''}">
            ${err('category') ? str(erros.category) : 'A gaveta em que este ponto aparece na paleta do editor.'}
          </span>
        </label>
        <label class="adm-field">
          <span class="adm-label">Comportamento no jogo<span class="adm-label__req">*</span></span>
          <select class="adm-select${inval('kind')}" name="kind">
            ${join(Object.entries(kinds).map(([k, rot]) => html`
              <option value="${k}"${sel(form.kind, k)}>${str(rot)}</option>`))}
          </select>
          <span class="adm-hint${err('kind') ? ' adm-hint--err' : ''}">
            ${err('kind') ? str(erros.kind) : 'Decide a camada do filtro e as regras (extração bloqueada, base que pulsa…).'}
          </span>
        </label>
      </div>

      <!-- ícone: origem + valor. O JS troca o campo da direita conforme a origem; sem JS os
           três aparecem e o operador preenche o que a origem escolhida usa. -->
      <div class="adm-form__row adm-form__row--2">
        <label class="adm-field">
          <span class="adm-label">Origem do ícone</span>
          <select class="adm-select" name="icon_origem" data-mpt-origem>
            ${join(Object.entries(origens).map(([k, o]) => html`
              <option value="${k}"${sel(form.icon_origem, k)}>${str(obj(o).label)}</option>`))}
          </select>
          <span class="adm-hint" data-mpt-origem-hint>${hintOrigem}</span>
        </label>

        <div class="adm-field mpt-icon">
          <span class="adm-label">Ícone<span class="adm-label__req">*</span></span>

          <select class="adm-select mpt-icon__campo" name="icon_glyph" data-mpt-campo="glyph">
            ${join(Object.entries(glifos).map(([k, rot]) => html`
              <option value="${k}"${sel(form.icon_valor, k)}>${str(rot)}</option>`))}
          </select>

          <input class="adm-input mpt-icon__campo" type="text" list="mpt-simbolos" placeholder="ex.: local_cafe"
                 name="icon_symbol" data-mpt-campo="symbol"
                 value="${origemAtual === 'symbol' ? str(form.icon_valor) : ''}">
          <datalist id="mpt-simbolos">
            ${join(simbolos.map((sm) => html`<option value="${str(sm)}"></option>`))}
          </datalist>

          <div class="mpt-icon__campo" data-mpt-campo="image">
            ${adminUpload({
              id: 'mpt-icon-image',
              name: 'icon_image',
              entidade: 'mapas',
              mono: true,
              valor: origemAtual === 'image' ? str(form.icon_valor) : '',
              placeholder: 'uploads/mapas/arquivo.webp',
            })}
          </div>

          <span class="adm-hint${err('icon_valor') ? ' adm-hint--err' : ''}">
            ${err('icon_valor') ? str(erros.icon_valor) : 'O desenho que este ponto mostra no mapa.'}
          </span>
        </div>
      </div>

      <div class="adm-form__row adm-form__row--3">
        <label class="adm-field">
          <span class="adm-label">Cor</span>
          <select class="adm-select${inval('color')}" name="color" data-mpt-cor>
            ${join(cores.map((c) => html`
              <option value="${str(c)}"${sel(form.color, c)}>${str(c).toUpperCase()}</option>`))}
          </select>
          ${err('color') ? html`<span class="adm-hint adm-hint--err">${str(erros.color)}</span>` : ''}
        </label>
        <label class="adm-field">
          <span class="adm-label">Camada do filtro</span>
          <input class="adm-input" type="text" name="layer" maxlength="24"
                 value="${str(form.layer)}" placeholder="a do comportamento">
          <span class="adm-hint">Em branco: usa a camada do comportamento escolhido acima.</span>
        </label>
        <label class="adm-field">
          <span class="adm-label">Ordem na paleta</span>
          <input class="adm-input num" type="number" name="sort_order" min="0" max="9999"
                 value="${int(form.sort_order)}">
        </label>
      </div>

      <div class="adm-form__row">
        <label class="adm-field">
          <span class="adm-label">Descrição</span>
          <textarea class="adm-textarea" name="description" rows="2" maxlength="255"
                    placeholder="Para que serve este ponto — aparece como dica na paleta.">${str(form.description)}</textarea>
        </label>
      </div>

      <div class="adm-form__row">
        <label class="adm-check">
          <input type="checkbox" name="show_label" value="1"${form.show_label ? ' checked' : ''}>
          <span>Mostrar o nome ao lado do ícone no mapa do jogador</span>
        </label>
      </div>

    </div>
  </div>

  <div class="adm-card__foot">
    <a class="adm-btn adm-btn--ghost" href="${url('admin/mapas/pontos')}">Cancelar</a>
    ${alvo !== null && !sistema
      ? html`<a class="adm-btn adm-btn--danger adm-btn--sm" href="${lnk({ excluir: str(alvo.id) })}">${icon('delete')} Excluir</a>`
      : ''}
    <span class="adm-spacer"></span>
    <button class="adm-btn adm-btn--primary" type="submit">${icon('save')} Salvar ponto</button>
  </div>
</form>
`;
  }

  // ================================ CATÁLOGO ================================
  const filtrando = Boolean(data.filtrando);
  return html`
<!-- ================================ CATÁLOGO ================================ -->
<!--eh:lista-->
<section class="adm-card">
  <div class="adm-card__head">
    <h2 class="adm-card__title">${icon('category', 'adm-card__ico')} Catálogo de pontos</h2>
    <div class="adm-card__tools">
      <form class="adm-toolbar" method="get" action="${url('admin/mapas/pontos')}" data-adm-preservar="filtros">
        <label class="adm-search adm-toolbar__grow">
          ${icon('search')}
          <input class="adm-input" type="search" name="q" value="${str(f.q)}"
                 placeholder="Buscar ponto, chave ou comportamento" maxlength="60">
        </label>
        <select class="adm-select" name="cat">
          <option value="">Todas as categorias</option>
          ${join(Object.entries(cats).map(([k, rot]) => html`
            <option value="${k}"${sel(f.cat, k)}>${str(rot)}</option>`))}
        </select>
        <noscript><button class="adm-btn adm-btn--sm" type="submit">Filtrar</button></noscript>
      </form>
    </div>
  </div>

  <div class="adm-card__body">
    <p class="adm-hint mpt-intro">${str(cromo.intro)}</p>

    ${grupos.length === 0
      ? html`<div class="adm-empty">
        <span class="adm-empty__ic">${icon('location_off')}</span>
        <p class="adm-empty__title">${str(vazios[filtrando ? 'filtro' : 'catalogo'])}</p>
        ${filtrando ? html`<a class="adm-btn adm-btn--ghost adm-btn--sm" href="${url('admin/mapas/pontos')}">Limpar filtros</a>` : ''}
      </div>`
      : ''}

    ${join(grupos.map((g) => {
      const itens = lista(g.items);
      return html`
    <section class="mpt-group">
      <h3 class="mpt-group__title">
        ${str(g.label)}
        <span class="mpt-group__n num">${itens.length}</span>
      </h3>
      <div class="mpt-grid">
        ${join(itens.map((t) => {
          const usos = int(t.usos);
          return html`
        <a class="mpt-card${t.is_system ? ' is-system' : ''}"
           href="${lnk({ id: str(t.id) })}"
           title="${str(t.description)}">
          ${mapPointIconCmp({ icon: str(t.icon), color: str(t.color), size: 'md' })}
          <span class="mpt-card__txt">
            <strong class="mpt-card__name">${str(t.label)}</strong>
            <small class="mpt-card__kind">${str(t.kind)}</small>
          </span>
          <span class="mpt-card__use num${usos === 0 ? ' is-zero' : ''}"
                title="${usos > 0 ? str(notas.uso) : str(notas.semuso)}">
            ${usos}
          </span>
          ${t.is_system ? html`<span class="mpt-card__lock" title="${str(notas.sistema)}">${icon('lock')}</span>` : ''}
        </a>`;
        }))}
      </div>
    </section>`;
    }))}
  </div>
</section>
<!--/eh:lista-->
`;
}
