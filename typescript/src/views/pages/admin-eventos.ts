/**
 * Tela ADMIN · EVENTOS (/admin/eventos) — app/Views/pages/admin-eventos.php. Layout 'admin'.
 *
 * A view não consulta o banco e não valida nada: a página inteira chega pronta de
 * controllers/admin/events-controller.ts, que só conversa com repo/admin-events-repo.ts.
 *
 * Três decisões que explicam o desenho (iguais ao PHP):
 * 1. TUDO É LINK OU FORMULÁRIO (a CSP só libera <script> com nonce): excluir é uma VIAGEM
 *    para `?excluir=<id>`, que desenha o cartão de confirmação e só então oferece o POST.
 * 2. NÚMERO À DIREITA, EM FONTE TABULAR (classe .num).
 * 3. ESTADO TEM COR E TEXTO: rascunho é "RASCUNHO" escrito, global é "GLOBAL" escrito.
 *
 * Os marcadores <!--eh:lista--> / <!--/eh:lista--> (core/fragmento.ts) estão escritos
 * literalmente, como no PHP. "Hoje" (notícia com data futura) é a data de São Paulo.
 */
import { csrfField, icon, num, url } from '../../core/helpers';
import { html, join, raw, type SafeHtml } from '../../core/html';
import { ymd } from '../../core/relogio';
import type { Dict, Scope } from '../../core/view';
import { adminUpload } from '../components/admin-upload';

const str = (v: unknown, def = ''): string => (v === null || v === undefined ? def : String(v));
const int = (v: unknown, def = 0): number => {
  const n = Number.parseInt(String(v ?? ''), 10);
  return Number.isNaN(n) ? def : n;
};
const obj = (v: unknown): Dict => (v !== null && typeof v === 'object' && !Array.isArray(v) ? (v as Dict) : {});
const lst = (v: unknown): unknown[] => (Array.isArray(v) ? v : v !== null && typeof v === 'object' ? Object.values(v as Dict) : []);
const tem = (l: Dict, k: string): boolean => Object.prototype.hasOwnProperty.call(l, k) && l[k] != null;

/** Largura de exibição de um caractere para o mb_strimwidth (CJK/largura cheia = 2). */
function largura(c: string): number {
  const p = c.codePointAt(0) ?? 0;
  return (p >= 0x1100 && p <= 0x115f) || (p >= 0x2e80 && p <= 0xa4cf) || (p >= 0xac00 && p <= 0xd7a3)
    || (p >= 0xf900 && p <= 0xfaff) || (p >= 0xfe30 && p <= 0xfe4f) || (p >= 0xff00 && p <= 0xff60)
    || (p >= 0xffe0 && p <= 0xffe6) || (p >= 0x20000 && p <= 0x3fffd) ? 2 : 1;
}

/** mb_strimwidth($s, 0, $w, '…'). */
function strimwidth(s: string, w: number, marca = '…'): string {
  const cs = Array.from(s);
  if (cs.reduce((t, c) => t + largura(c), 0) <= w) return s;
  const lim = w - largura(marca);
  let out = '';
  let t = 0;
  for (const c of cs) {
    if (t + largura(c) > lim) break;
    out += c;
    t += largura(c);
  }
  return out + marca;
}

/** "12/05/2026" a partir de "2026-05-12"; vazio vira travessão. */
function dataBr(iso0: unknown): string {
  const iso = str(iso0).trim();
  if (iso === '') return '—';
  const d = iso.slice(0, 10);
  return /^\d{4}-\d{2}-\d{2}$/.test(d) ? d.slice(8, 10) + '/' + d.slice(5, 7) + '/' + d.slice(0, 4) : d;
}

/** Cor do selo de escopo. */
const tomEscopo = (sc: string): string => (sc === 'temporada' ? 'purple' : sc === 'semanais' ? 'info' : 'ok');

export function adminEventos(sc: Scope): SafeHtml {
  const data = sc.data;
  const cromo = obj(data.cromo);
  const abas = obj(cromo.abas);
  const escopos = obj(cromo.escopos);
  const cores = obj(cromo.cores);
  const vazios = obj(cromo.vazios);
  const notas = obj(cromo.notas);

  const aba = str(data.aba, 'desafios');
  const f = obj(data.filtros);
  const resumo = obj(data.resumo);
  const lista = data.lista !== undefined ? obj(data.lista) : { linhas: [], total: 0, paginas: 1, pagina: 1 };
  const linhas = lst(lista.linhas).map(obj);
  const total = int(lista.total, 0);
  const paginas = int(lista.paginas, 1);
  const atual = int(lista.pagina, 1);

  const form = data.form !== null && typeof data.form === 'object' ? (data.form as Dict) : null;
  const erros = obj(data.erros);
  const excluir = data.excluir !== null && typeof data.excluir === 'object' ? (data.excluir as Dict) : null;
  const volta = str(data.volta, url('admin/eventos'));

  const hoje = ymd();

  /* Algum filtro está ligado? É o que separa "catálogo vazio" de "filtro apertado demais". */
  const filtrando = str(f.q) !== '' || str(f.escopo) !== '' || str(f.estado) !== '' || str(f.cat) !== '' || str(f.destino) !== '';

  /* ======================================================================================
     MONTADORES DE LINK — todo link desta tela preserva o estado da listagem
     ====================================================================================== */

  /** Base da query: o que precisa sobreviver a qualquer clique. */
  const base: Record<string, string> = {};
  for (const [k, v] of Object.entries({
    aba,
    q: str(f.q),
    escopo: str(f.escopo),
    estado: str(f.estado),
    cat: str(f.cat),
    destino: str(f.destino),
    ord: str(f.ord),
    dir: str(f.ord) !== '' ? str(f.dir, 'ASC') : '',
    p: atual > 1 ? String(atual) : '',
  })) {
    if (v !== '') base[k] = v;
  }

  /** url('admin/eventos', extra + base) — a união do PHP (chaves de `extra` primeiro); '' REMOVE a chave. */
  const lnk = (extra: Record<string, string> = {}): string => {
    const q: Record<string, string> = { ...extra };
    for (const [k, v] of Object.entries(base)) if (!(k in q)) q[k] = v;
    const fil: Record<string, string> = {};
    for (const [k, v] of Object.entries(q)) if (v !== '') fil[k] = v;
    return url('admin/eventos', fil);
  };

  /** Cabeçalho ordenável: clicar na coluna ativa INVERTE a direção; noutra começa ascendente. */
  const th = (chave: string, rotulo: string, classe = ''): SafeHtml => {
    const ativa = str(f.ord) === chave;
    const dir = ativa && str(f.dir, 'ASC') === 'ASC' ? 'DESC' : 'ASC';
    const href = lnk({ ord: chave, dir, p: '' });
    const seta = ativa ? icon(str(f.dir, 'ASC') === 'DESC' ? 'arrow_downward' : 'arrow_upward', 'ev-sort__ic') : '';
    return html`<th class="${classe}"><a class="ev-sort${ativa ? ' is-on' : ''}" href="${href}">${rotulo}${seta}</a></th>`;
  };

  /* Valor de um campo do formulário: digitado (POST recusado), do banco (edição) ou o padrão. */
  const valores = obj(form?.valores);
  const val = (campo: string, padrao = ''): string => {
    const v = valores[campo];
    return v === null || v === undefined ? padrao : String(v);
  };
  const marcado = (campo: string): boolean => {
    const v = valores[campo];
    return v !== null && v !== undefined && String(v) !== '' && String(v) !== '0';
  };
  const erro = (campo: string): SafeHtml | null =>
    tem(erros, campo) ? html`<span class="adm-error">${icon('error')}${str(erros[campo])}</span>` : null;
  const invalido = (campo: string): string => (tem(erros, campo) ? ' is-invalid' : '');
  const hint = (txt: string): SafeHtml => html`<span class="adm-hint">${txt}</span>`;

  // ------------------------------------------------------------------- abas + contagem
  /* data-adm-recarrega: trocar de aba é tela nova, e recarregar é o honesto. */
  const secAbas = html`<nav class="ev-tabs" aria-label="Catálogos de evento">
${Object.entries(abas).map(([slug, meta0]) => {
  const meta = obj(meta0);
  const on = slug === aba;
  const conta = int(resumo[str(meta.conta)], 0);
  return html`    <a class="ev-tab${on ? ' is-on' : ''}" href="${url('admin/eventos', { aba: slug })}"
       data-adm-recarrega ${on ? raw('aria-current="page"') : ''}>
      ${icon(str(meta.icon, 'circle'), 'ev-tab__ic')}
      <span class="ev-tab__txt">${str(meta.label, slug)}</span>
      <span class="ev-tab__n num">${num(conta)}</span>
    </a>
`;
})}</nav>
<p class="ev-sub">${str(obj(abas[aba]).sub)}</p>
`;

  // --------------------------------------------------------- confirmação de exclusão
  let secExcluir: SafeHtml | '' = '';
  if (excluir !== null) {
    const bloqueado = excluir.impedimento !== null && excluir.impedimento !== undefined;
    secExcluir = html`
  <section class="adm-card ev-confirm${bloqueado ? ' ev-confirm--blocked' : ''}">
    <header class="adm-card__head">
      ${icon(bloqueado ? 'block' : 'delete_forever', 'ev-confirm__ic')}
      <h2 class="adm-card__title">
        ${bloqueado ? 'Não dá para excluir' : 'Confirmar exclusão'}
        <small>${str(excluir.detalhe)}</small>
      </h2>
    </header>
    <div class="adm-card__body">
      <p class="ev-confirm__alvo">${str(excluir.titulo)}</p>
${bloqueado
  ? html`        <p class="ev-confirm__txt">${str(excluir.impedimento)}</p>
`
  : html`        <p class="ev-confirm__txt">
          A remoção é definitiva e não tem desfazer nesta tela. Fica registrada na trilha de
          auditoria com o seu nome, e o registro some do jogo no próximo carregamento.
        </p>
`}    </div>
    <footer class="adm-card__foot">
${!bloqueado
  ? html`        <form method="post" action="${url('admin/eventos/excluir')}" class="ev-confirm__form">
          ${csrfField()}
          <input type="hidden" name="tipo" value="${str(excluir.tipo)}">
          <input type="hidden" name="id" value="${str(excluir.id)}">
          <input type="hidden" name="_volta" value="${volta}">
          <button class="adm-btn adm-btn--danger" type="submit">${icon('delete')}Excluir definitivamente</button>
        </form>
`
  : ''}      <a class="adm-btn adm-btn--ghost" href="${volta}">${bloqueado ? 'Voltar à lista' : 'Cancelar'}</a>
    </footer>
  </section>
`;
  }

  // ------------------------------------------------------------------- formulários
  let secForm: SafeHtml | '' = '';
  if (form !== null) {
    const tipo = str(form.tipo);
    const idForm = str(form.id);
    const editando = idForm !== '';
    const titulos: Record<string, [string, string]> = {
      desafio: ['Novo desafio', 'Editar desafio'],
      bonus: ['Novo bônus de escopo', 'Editar bônus de escopo'],
      noticia: ['Nova notícia', 'Editar notícia'],
      aviso: ['Novo aviso do sistema', 'Editar aviso do sistema'],
    };
    const tituloForm = Object.prototype.hasOwnProperty.call(titulos, tipo) ? titulos[tipo]![editando ? 1 : 0] : 'Registro';
    const nErros = Object.keys(erros).length;

    let campos: SafeHtml | '' = '';

    // ---------------------------------------------------------------- desafio
    if (tipo === 'desafio') {
      campos = html`        <div class="adm-form__row">
          <div class="adm-field${invalido('label')}">
            <label class="adm-label" for="c-label">Título do desafio<span class="adm-label__req">*</span></label>
            <input class="adm-input" id="c-label" name="label" maxlength="160" required
                   value="${val('label')}" placeholder="Elimine 20 inimigos">
            ${erro('label')}
          </div>
          <div class="adm-field${invalido('slug')}">
            <label class="adm-label" for="c-slug">Identificador<span class="adm-label__req">*</span></label>
            <input class="adm-input" id="c-slug" name="slug" maxlength="64" required
                   value="${val('slug')}" placeholder="elimine-20">
            ${erro('slug') ?? hint('É por ele que o jogo encontra o desafio. Trocar depois zera a ligação com o progresso salvo.')}
          </div>
        </div>

        <div class="adm-field${invalido('description')}">
          <label class="adm-label" for="c-desc">Descrição</label>
          <input class="adm-input" id="c-desc" name="description" maxlength="400" value="${val('description')}">
          ${erro('description')}
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field${invalido('scope')}">
            <label class="adm-label" for="c-scope">Escopo<span class="adm-label__req">*</span></label>
            <select class="adm-select" id="c-scope" name="scope">
${Object.entries(escopos).map(([k, rot]) => html`                <option value="${k}"${val('scope', 'diarios') === k ? raw(' selected') : ''}>${str(rot)}</option>
`)}            </select>
            ${erro('scope')}
          </div>
          <div class="adm-field${invalido('season_id')}">
            <label class="adm-label" for="c-season">Temporada</label>
            <select class="adm-select" id="c-season" name="season_id">
              <option value="0">— só para escopo Temporada —</option>
${lst(data.temporadas).map(obj).map((t) => html`                <option value="${str(t.id)}"${val('season_id') === str(t.id) ? raw(' selected') : ''}>
                  ${str(t.tag) + ' · ' + str(t.pass_name)}${int(t.is_current) === 1 ? ' (atual)' : ''}
                </option>
`)}            </select>
            ${erro('season_id')}
          </div>
          <div class="adm-field${invalido('icon')}">
            <label class="adm-label" for="c-icon">Ícone</label>
            <input class="adm-input" id="c-icon" name="icon" maxlength="48" value="${val('icon')}" placeholder="military_tech">
            ${erro('icon') ?? hint('Nome do Material Symbols.')}
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field${invalido('target')}">
            <label class="adm-label" for="c-target">Alvo<span class="adm-label__req">*</span></label>
            <input class="adm-input num" id="c-target" name="target" inputmode="numeric" value="${val('target', '1')}">
            ${erro('target') ?? hint('Quantas vezes precisa acontecer.')}
          </div>
          <div class="adm-field${invalido('xp')}">
            <label class="adm-label" for="c-xp">XP</label>
            <input class="adm-input num" id="c-xp" name="xp" inputmode="numeric" value="${val('xp', '0')}">
            ${erro('xp')}
          </div>
          <div class="adm-field${invalido('credits')}">
            <label class="adm-label" for="c-cred">Créditos</label>
            <input class="adm-input num" id="c-cred" name="credits" inputmode="numeric" value="${val('credits', '0')}">
            ${erro('credits')}
          </div>
        </div>

        <div class="adm-form__row">
          <div class="adm-field${invalido('sort_order')}">
            <label class="adm-label" for="c-ord">Ordem na aba</label>
            <input class="adm-input num" id="c-ord" name="sort_order" inputmode="numeric" value="${val('sort_order', '0')}">
            ${erro('sort_order') ?? hint('Menor aparece primeiro.')}
          </div>
          <div class="adm-field">
            <span class="adm-label">Destaque</span>
            <label class="adm-switch">
              <input class="adm-switch__in" type="checkbox" name="highlight" value="1"${marcado('highlight') ? raw(' checked') : ''}>
              <span class="adm-switch__ui"></span>
              <span>Linha dourada — o desafio “quente” da aba</span>
            </label>
          </div>
        </div>
`;
    }

    // ------------------------------------------------------------------ bônus
    if (tipo === 'bonus') {
      const livres = lst(data.escopos_livres).map((x) => str(x));
      let campoEscopo: SafeHtml;
      if (editando) {
        campoEscopo = html`              <input class="adm-input" id="b-scope" value="${tem(escopos, idForm) ? str(escopos[idForm]) : idForm}" disabled>
              <span class="adm-hint">O escopo é a chave do bônus e não muda — existe um por aba do jogo.</span>
`;
      } else if (livres.length === 0) {
        /* Os três escopos já têm bônus e a chave primária é o escopo: não há o que criar. */
        campoEscopo = html`              <input class="adm-input" id="b-scope" value="nenhum escopo livre" disabled>
              <span class="adm-hint">Os três escopos já têm bônus — só dá para editar os que existem.</span>
`;
      } else {
        campoEscopo = html`              <select class="adm-select" id="b-scope" name="scope">
${livres.map((k) => html`                  <option value="${k}"${val('scope') === k ? raw(' selected') : ''}>${tem(escopos, k) ? str(escopos[k]) : k}</option>
`)}              </select>
              ${erro('scope')}
`;
      }
      campos = html`        <div class="adm-form__row">
          <div class="adm-field${invalido('scope')}">
            <label class="adm-label" for="b-scope">Escopo<span class="adm-label__req">*</span></label>
${campoEscopo}          </div>
          <div class="adm-field${invalido('label')}">
            <label class="adm-label" for="b-label">Tarja<span class="adm-label__req">*</span></label>
            <input class="adm-input" id="b-label" name="label" maxlength="40" required
                   value="${val('label')}" placeholder="BÔNUS DIÁRIO">
            ${erro('label')}
          </div>
        </div>
        <div class="adm-form__row">
          <div class="adm-field${invalido('title')}">
            <label class="adm-label" for="b-title">Título<span class="adm-label__req">*</span></label>
            <input class="adm-input" id="b-title" name="title" maxlength="80" required
                   value="${val('title')}" placeholder="CAIXA DE SUPRIMENTOS">
            ${erro('title')}
          </div>
          <div class="adm-field${invalido('image')}">
            <label class="adm-label" for="b-img">Imagem</label>
            ${adminUpload({
              id: 'b-img',
              name: 'image',
              entidade: 'eventos',
              valor: val('image'),
              maxlength: 160,
              placeholder: 'items/supply-crate.png',
              invalido: invalido('image') !== '',
            })}
            ${erro('image') ?? hint('Caminho relativo dentro de /media.')}
          </div>
        </div>
        <div class="adm-field${invalido('description')}">
          <label class="adm-label" for="b-desc">Descrição</label>
          <input class="adm-input" id="b-desc" name="description" maxlength="400" value="${val('description')}">
          ${erro('description')}
        </div>
        <div class="adm-form__row">
          <div class="adm-field${invalido('xp')}">
            <label class="adm-label" for="b-xp">XP</label>
            <input class="adm-input num" id="b-xp" name="xp" inputmode="numeric" value="${val('xp', '0')}">
            ${erro('xp')}
          </div>
          <div class="adm-field${invalido('credits')}">
            <label class="adm-label" for="b-cred">Créditos</label>
            <input class="adm-input num" id="b-cred" name="credits" inputmode="numeric" value="${val('credits', '0')}">
            ${erro('credits')}
          </div>
        </div>
`;
    }

    // ---------------------------------------------------------------- notícia
    if (tipo === 'noticia') {
      campos = html`        <div class="adm-form__row">
          <div class="adm-field${invalido('title')}">
            <label class="adm-label" for="n-title">Título<span class="adm-label__req">*</span></label>
            <input class="adm-input" id="n-title" name="title" maxlength="160" required value="${val('title')}">
            ${erro('title')}
          </div>
          <div class="adm-field${invalido('slug')}">
            <label class="adm-label" for="n-slug">Identificador<span class="adm-label__req">*</span></label>
            <input class="adm-input" id="n-slug" name="slug" maxlength="80" required value="${val('slug')}">
            ${erro('slug') ?? hint('As janelas de manutenção referenciam a notícia por este texto.')}
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field${invalido('category')}">
            <label class="adm-label" for="n-cat">Categoria</label>
            <input class="adm-input" id="n-cat" name="category" maxlength="24" list="ev-cats" value="${val('category')}">
            <datalist id="ev-cats">
${lst(data.categorias).map((c) => html`                <option value="${str(c)}"></option>
`)}            </datalist>
            ${erro('category')}
          </div>
          <div class="adm-field${invalido('published')}">
            <label class="adm-label" for="n-pub">Publicação</label>
            <input class="adm-input" id="n-pub" name="published" type="date" value="${val('published').slice(0, 10)}">
            ${erro('published') ?? hint('Em branco = rascunho (não sai da gaveta).')}
          </div>
          <div class="adm-field${invalido('sort_order')}">
            <label class="adm-label" for="n-ord">Ordem no mural</label>
            <input class="adm-input num" id="n-ord" name="sort_order" inputmode="numeric" value="${val('sort_order', '0')}">
            ${erro('sort_order')}
          </div>
        </div>

        <div class="adm-form__row">
          <div class="adm-field${invalido('excerpt')}">
            <label class="adm-label" for="n-exc">Resumo</label>
            <input class="adm-input" id="n-exc" name="excerpt" maxlength="400" value="${val('excerpt')}">
            ${erro('excerpt') ?? hint('A chamada do card no mural.')}
          </div>
          <div class="adm-field${invalido('image')}">
            <label class="adm-label" for="n-img">Imagem</label>
            ${adminUpload({
              id: 'n-img',
              name: 'image',
              entidade: 'noticias',
              valor: val('image'),
              maxlength: 160,
              placeholder: 'news/patch-8-8-1.jpg',
              invalido: invalido('image') !== '',
            })}
            ${erro('image')}
          </div>
        </div>

        <div class="adm-field${invalido('body')}">
          <label class="adm-label" for="n-body">Corpo</label>
          <textarea class="adm-textarea" id="n-body" name="body" rows="8">${val('body')}</textarea>
          ${erro('body') ?? hint(str(notas.corpo_noticia))}
        </div>
`;
    }

    // ------------------------------------------------------------------ aviso
    if (tipo === 'aviso') {
      const destino = val('destino', 'global');
      const corAtual = val('color', 'lime');
      campos = html`        <div class="adm-form__row">
          <div class="adm-field${invalido('destino')}">
            <span class="adm-label">Destino<span class="adm-label__req">*</span></span>
            <div class="ev-radios">
              <label class="adm-check">
                <input type="radio" name="destino" value="global"${destino !== 'jogador' ? raw(' checked') : ''}>
                <span>Global — todos os jogadores</span>
              </label>
              <label class="adm-check">
                <input type="radio" name="destino" value="jogador"${destino === 'jogador' ? raw(' checked') : ''}>
                <span>Um jogador</span>
              </label>
            </div>
            ${erro('destino')}
          </div>
          <div class="adm-field${invalido('destinatario')}">
            <label class="adm-label" for="a-dest">Jogador</label>
            <input class="adm-input" id="a-dest" name="destinatario" list="ev-jogadores"
                   value="${val('destinatario')}" placeholder="HUNTER_07">
            <datalist id="ev-jogadores">
              ${lst(data.jogadores).map((u) => html`<option value="${str(u)}"></option>`)}
            </datalist>
            ${erro('destinatario') ?? hint('Só é lido quando o destino é “um jogador”.')}
          </div>
        </div>

        <p class="ev-nota">${icon('info', 'ev-nota__ic')}<span>${str(notas.aviso_global)}</span></p>

        <div class="adm-form__row">
          <div class="adm-field${invalido('title')}">
            <label class="adm-label" for="a-title">Título<span class="adm-label__req">*</span></label>
            <input class="adm-input" id="a-title" name="title" maxlength="120" required value="${val('title')}">
            ${erro('title')}
          </div>
          <div class="adm-field${invalido('body')}">
            <label class="adm-label" for="a-body">Texto<span class="adm-label__req">*</span></label>
            <input class="adm-input" id="a-body" name="body" maxlength="400" required value="${val('body')}">
            ${erro('body')}
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field${invalido('color')}">
            <label class="adm-label" for="a-color">Cor<span class="adm-label__req">*</span></label>
            <select class="adm-select" id="a-color" name="color">
${Object.entries(cores).map(([k, meta]) => html`                <option value="${k}"${corAtual === k ? raw(' selected') : ''}>${str(obj(meta).label)}</option>
`)}            </select>
            ${erro('color') ?? hint(tem(cores, corAtual) ? str(obj(cores[corAtual]).hint) : '')}
          </div>
          <div class="adm-field${invalido('icon')}">
            <label class="adm-label" for="a-icon">Ícone<span class="adm-label__req">*</span></label>
            <input class="adm-input" id="a-icon" name="icon" maxlength="48" required value="${val('icon', 'info')}">
            ${erro('icon')}
          </div>
          <div class="adm-field${invalido('sort_order')}">
            <label class="adm-label" for="a-ord">Ordem</label>
            <input class="adm-input num" id="a-ord" name="sort_order" inputmode="numeric" value="${val('sort_order', '0')}">
            ${erro('sort_order')}
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field${invalido('cta_label')}">
            <label class="adm-label" for="a-cta">Rótulo do botão</label>
            <input class="adm-input" id="a-cta" name="cta_label" maxlength="40" value="${val('cta_label')}" placeholder="RESGATAR">
            ${erro('cta_label')}
          </div>
          <div class="adm-field${invalido('cta_href')}">
            <label class="adm-label" for="a-href">Destino do botão</label>
            <input class="adm-input" id="a-href" name="cta_href" maxlength="120" value="${val('cta_href')}" placeholder="loja">
            ${erro('cta_href') ?? hint('Rótulo e destino andam juntos: ou os dois, ou nenhum.')}
          </div>
          <div class="adm-field">
            <span class="adm-label">Marcador</span>
            <label class="adm-switch">
              <input class="adm-switch__in" type="checkbox" name="fresh" value="1"${marcado('fresh') ? raw(' checked') : ''}>
              <span class="adm-switch__ui"></span>
              <span>Marcar como novo</span>
            </label>
          </div>
        </div>
`;
    }

    secForm = html`
  <section class="adm-card ev-form" id="form">
    <header class="adm-card__head">
      <h2 class="adm-card__title">
        ${tituloForm}
        ${editando ? html`<small>#${idForm}</small>` : ''}
      </h2>
${nErros > 0
  ? html`        <span class="adm-badge adm-badge--danger adm-card__tools">${num(nErros)} campo(s) para corrigir</span>
`
  : ''}    </header>

    <form class="adm-form adm-card__body" method="post" action="${url('admin/eventos/salvar')}">
      ${csrfField()}
      <input type="hidden" name="tipo" value="${tipo}">
      <input type="hidden" name="id" value="${idForm}">
      <input type="hidden" name="_volta" value="${volta}">

${campos}
      <div class="adm-form__actions">
        <button class="adm-btn adm-btn--primary" type="submit">${icon('save')}${editando ? 'Salvar alterações' : 'Criar'}</button>
        <a class="adm-btn adm-btn--ghost" href="${volta}">Cancelar</a>
${editando && tipo !== 'bonus'
  ? html`          <a class="adm-btn adm-btn--danger adm-spacer"
             href="${lnk({ excluir: idForm, tipo, form: '', id: '' })}">
            ${icon('delete')}Excluir
          </a>
`
  : ''}      </div>
    </form>
  </section>
`;
  }

  // ------------------------------------------------------------- listagem da aba
  const placeholder = aba === 'desafios'
    ? 'Buscar por título, identificador ou descrição'
    : aba === 'noticias' ? 'Buscar por título, identificador ou resumo' : 'Buscar por título, texto ou jogador';

  let filtrosAba: SafeHtml;
  if (aba === 'desafios') {
    filtrosAba = html`      <select class="adm-select ev-filtro" name="escopo" aria-label="Filtrar por escopo">
        <option value="">Todos os escopos</option>
${Object.entries(escopos).map(([k, rot]) => html`          <option value="${k}"${str(f.escopo) === k ? raw(' selected') : ''}>${str(rot)}</option>
`)}        <option value="destaque"${str(f.escopo) === 'destaque' ? raw(' selected') : ''}>Só destaques</option>
      </select>
`;
  } else if (aba === 'noticias') {
    filtrosAba = html`      <select class="adm-select ev-filtro" name="estado" aria-label="Filtrar por estado">
        <option value="">Todos os estados</option>
        <option value="publicada"${str(f.estado) === 'publicada' ? raw(' selected') : ''}>Publicadas</option>
        <option value="rascunho"${str(f.estado) === 'rascunho' ? raw(' selected') : ''}>Rascunhos</option>
        <option value="futura"${str(f.estado) === 'futura' ? raw(' selected') : ''}>Com data futura</option>
      </select>
      <select class="adm-select ev-filtro" name="cat" aria-label="Filtrar por categoria">
        <option value="">Todas as categorias</option>
${lst(data.categorias).map((c0) => {
  const c = str(c0);
  return html`          <option value="${c}"${str(f.cat) === c ? raw(' selected') : ''}>${c}</option>
`;
})}      </select>
`;
  } else {
    filtrosAba = html`      <select class="adm-select ev-filtro" name="destino" aria-label="Filtrar por destino">
        <option value="">Todos os destinos</option>
        <option value="global"${str(f.destino) === 'global' ? raw(' selected') : ''}>Só globais</option>
        <option value="jogador"${str(f.destino) === 'jogador' ? raw(' selected') : ''}>Só de jogador</option>
        <option value="novo"${str(f.destino) === 'novo' ? raw(' selected') : ''}>Marcados como novos</option>
      </select>
`;
  }

  let corpoLista: SafeHtml;
  if (linhas.length === 0) {
    const v = obj(vazios[filtrando ? 'filtro' : aba]);
    corpoLista = html`    <div class="adm-empty">
      ${icon(filtrando ? 'filter_alt_off' : 'inbox')}
      <h2 class="adm-empty__title">${str(v.titulo, 'Nada aqui')}</h2>
      <p class="adm-empty__txt">${str(v.txt)}</p>
${filtrando
  ? html`        <a class="adm-btn adm-btn--ghost" href="${url('admin/eventos', { aba })}">Limpar filtros</a>
`
  : ''}    </div>
`;
  } else {
    let tabela: SafeHtml | '' = '';

    // -------------------------------------------------------- desafios
    if (aba === 'desafios') {
      tabela = html`          <thead>
            <tr>
              <th>Escopo</th>
              ${th('nome', 'Desafio')}
              ${th('alvo', 'Alvo', 'tr')}
              ${th('xp', 'XP', 'tr')}
              ${th('cred', 'Créditos', 'tr')}
              ${th('jog', 'Jogadores', 'tr')}
              ${th('ordem', 'Ordem', 'tr')}
              <th class="adm-table__acts"><span class="ev-sr">Ações</span></th>
            </tr>
          </thead>
          <tbody>
${linhas.map((r) => {
  const emUso = int(r.jogadores) > 0;
  const scope = str(r.scope);
  return html`              <tr>
                <td>
                  <span class="adm-badge adm-badge--${tomEscopo(scope)}">
                    ${tem(escopos, scope) ? str(escopos[scope]) : scope}
                  </span>
${r.temporada !== null && r.temporada !== undefined
  ? html`                    <small class="ev-dim">${str(r.temporada)}</small>
`
  : ''}                </td>
                <td class="ev-cel-nome">
                  <span class="ev-nome">
${int(r.highlight) === 1
  ? html`                      <span class="ev-hot" title="Desafio em destaque — linha dourada na tela do jogo">${icon('local_fire_department')}</span>
`
  : ''}                    <strong>${str(r.label)}</strong>
                  </span>
                  <small class="ev-dim">${str(r.slug)}${r.description !== null && r.description !== undefined ? ' · ' + strimwidth(str(r.description), 64) : ''}</small>
                </td>
                <td class="tr num">${num(int(r.target))}</td>
                <td class="tr num">${num(int(r.xp))}</td>
                <td class="tr num">${num(int(r.credits))}</td>
                <td class="tr num${emUso ? ' ev-uso' : ''}"
                    title="${emUso ? str(r.jogadores) + ' jogador(es) com progresso · ' + str(r.concluidos) + ' concluíram' : 'Ninguém começou este desafio'}">
                  ${num(int(r.jogadores))}
                </td>
                <td class="tr num">${num(int(r.sort_order))}</td>
                <td class="adm-table__acts">
                  <a class="adm-btn adm-btn--sm adm-btn--icon" title="Editar"
                     href="${lnk({ form: 'desafio', id: str(r.id) })}">${icon('edit')}</a>
${emUso
  ? html`                    <span class="adm-btn adm-btn--sm adm-btn--icon is-disabled"
                          title="Não pode ser excluído: ${str(r.jogadores)} jogador(es) já têm progresso gravado">${icon('lock')}</span>
`
  : html`                    <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger" title="Excluir"
                       href="${lnk({ excluir: str(r.id), tipo: 'desafio' })}">${icon('delete')}</a>
`}                </td>
              </tr>
`;
})}          </tbody>
`;
    }

    // -------------------------------------------------------- notícias
    if (aba === 'noticias') {
      tabela = html`          <thead>
            <tr>
              <th>Estado</th>
              ${th('data', 'Publicação', 'tr')}
              ${th('nome', 'Notícia')}
              ${th('cat', 'Categoria')}
              ${th('ordem', 'Ordem', 'tr')}
              <th class="adm-table__acts"><span class="ev-sr">Ações</span></th>
            </tr>
          </thead>
          <tbody>
${linhas.map((r) => {
  const pub = str(r.published);
  const futura = pub !== '' && pub > hoje;
  const presa = int(r.janelas) > 0;
  const cat = r.category;
  return html`              <tr>
                <td>
${pub === ''
  ? html`                    <span class="adm-badge adm-badge--warn">Rascunho</span>
`
  : html`                    <span class="adm-badge adm-badge--ok">Publicada</span>
`}                </td>
                <td class="tr num${futura ? ' ev-futura' : ''}"
                    title="${futura ? str(notas.noticia_futura) : ''}">
                  ${dataBr(pub)}${futura ? html` ${icon('schedule', 'ev-futura__ic')}` : ''}
                </td>
                <td class="ev-cel-nome">
                  <span class="ev-nome"><strong>${str(r.title)}</strong></span>
                  <small class="ev-dim">
                    ${str(r.slug)}
                    · ${num(int(r.tamanho_corpo))} car.
${presa
  ? html`                      · <span class="ev-preso" title="Referenciada por manutenção — não pode ser excluída">${num(int(r.janelas))} janela(s) de manutenção</span>
`
  : ''}                  </small>
                </td>
                <td>${cat !== null && cat !== undefined && cat !== '' ? html`<span class="adm-badge adm-badge--nodot">${str(cat)}</span>` : html`<span class="ev-dim">—</span>`}</td>
                <td class="tr num">${num(int(r.sort_order))}</td>
                <td class="adm-table__acts">
                  <a class="adm-btn adm-btn--sm adm-btn--icon" title="Editar"
                     href="${lnk({ form: 'noticia', id: str(r.id) })}">${icon('edit')}</a>
${presa
  ? html`                    <span class="adm-btn adm-btn--sm adm-btn--icon is-disabled"
                          title="Não pode ser excluída: ${str(r.janelas)} janela(s) de manutenção apontam para este identificador">${icon('lock')}</span>
`
  : html`                    <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger" title="Excluir"
                       href="${lnk({ excluir: str(r.id), tipo: 'noticia' })}">${icon('delete')}</a>
`}                </td>
              </tr>
`;
})}          </tbody>
`;
    }

    // ---------------------------------------------------------- avisos
    if (aba === 'avisos') {
      tabela = html`          <thead>
            <tr>
              ${th('dest', 'Destino')}
              ${th('nome', 'Aviso')}
              <th>Botão</th>
              ${th('criado', 'Criado', 'tr')}
              ${th('ordem', 'Ordem', 'tr')}
              <th class="adm-table__acts"><span class="ev-sr">Ações</span></th>
            </tr>
          </thead>
          <tbody>
${linhas.map((r) => {
  const global = r.user_id === null || r.user_id === undefined;
  const cor = str(r.color);
  return html`              <tr>
                <td>
${global
  ? html`                    <span class="adm-badge adm-badge--info" title="${str(notas.aviso_global)}">Global</span>
`
  : html`                    <span class="adm-badge adm-badge--nodot">${icon('person')}${r.username !== null && r.username !== undefined ? str(r.username) : '#' + str(r.user_id)}</span>
`}                </td>
                <td class="ev-cel-nome">
                  <span class="ev-nome">
                    <span class="ev-tinta ev-tinta--${cor}" title="${tem(cores, cor) ? str(obj(cores[cor]).label) : cor}">${icon(str(r.icon))}</span>
                    <strong>${str(r.title)}</strong>
                    ${int(r.fresh) === 1 ? html`<span class="adm-badge adm-badge--solid ev-novo">Novo</span>` : ''}
                  </span>
                  <small class="ev-dim">${strimwidth(str(r.body), 88)}</small>
                </td>
                <td>
${str(r.cta_label) !== ''
  ? html`                    <span class="ev-cta">${str(r.cta_label)}<small>${str(r.cta_href)}</small></span>
`
  : html`                    <span class="ev-dim">—</span>
`}                </td>
                <td class="tr num">${dataBr(str(r.created_at))}</td>
                <td class="tr num">${num(int(r.sort_order))}</td>
                <td class="adm-table__acts">
                  <a class="adm-btn adm-btn--sm adm-btn--icon" title="Editar"
                     href="${lnk({ form: 'aviso', id: str(r.id) })}">${icon('edit')}</a>
                  <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger" title="Excluir"
                     href="${lnk({ excluir: str(r.id), tipo: 'aviso' })}">${icon('delete')}</a>
                </td>
              </tr>
`;
})}          </tbody>
`;
    }

    // ----------------------------------------------------------- paginação
    let pager: SafeHtml | '' = '';
    if (paginas > 1) {
      /* Janela de páginas em volta da atual: com 40 páginas, imprimir as 40 empurraria o rodapé. */
      let de = Math.max(1, atual - 2);
      const ate = Math.min(paginas, de + 4);
      de = Math.max(1, ate - 4);
      const nums: SafeHtml[] = [];
      for (let i = de; i <= ate; i++) {
        nums.push(html`            <a class="adm-pager__btn${i === atual ? ' is-active' : ''}" href="${lnk({ p: String(i) })}">${num(i)}</a>
`);
      }
      pager = html`          <a class="adm-pager__btn${atual <= 1 ? ' is-disabled' : ''}" href="${lnk({ p: String(Math.max(1, atual - 1)) })}"
             aria-label="Página anterior">${icon('chevron_left')}</a>
${de > 1
  ? html`            <a class="adm-pager__btn" href="${lnk({ p: '1' })}">1</a>
${de > 2 ? html`            <span class="adm-pager__gap">…</span>
` : ''}`
  : ''}${join(nums)}${ate < paginas
  ? html`${ate < paginas - 1 ? html`            <span class="adm-pager__gap">…</span>
` : ''}            <a class="adm-pager__btn" href="${lnk({ p: String(paginas) })}">${num(paginas)}</a>
`
  : ''}          <a class="adm-pager__btn${atual >= paginas ? ' is-disabled' : ''}" href="${lnk({ p: String(Math.min(paginas, atual + 1)) })}"
             aria-label="Próxima página">${icon('chevron_right')}</a>
`;
    }

    corpoLista = html`    <div class="adm-tablewrap">
      <table class="adm-table">

${tabela}
      </table>
    </div>

    <footer class="adm-card__foot">
      <nav class="adm-pager" aria-label="Paginação">
        <span class="adm-pager__info" data-adm-total>
          ${num(linhas.length)} de ${num(total)} registro(s)${filtrando ? ' com os filtros atuais' : ''}
          ${paginas > 1 ? ' · página ' + num(atual) + ' de ' + num(paginas) : ''}
        </span>
${pager}      </nav>
    </footer>
`;
  }

  /* data-adm-preservar: o formulário de filtros é transplantado para dentro do HTML novo
     em vez de substituído — é onde está o cursor de quem digita. */
  const secLista = html`
<!--eh:lista-->
<section class="adm-card ev-list">
  <form class="adm-toolbar" method="get" action="${url('admin/eventos')}"
        data-adm-preservar="filtros">
    <input type="hidden" name="aba" value="${aba}">
    <div class="adm-toolbar__grow">
      <div class="adm-search">
        ${icon('search')}
        <input class="adm-input" type="search" name="q" maxlength="80" value="${str(f.q)}"
               placeholder="${placeholder}"
               aria-label="Buscar">
      </div>
    </div>

${filtrosAba}
${str(f.ord) !== ''
  ? html`      <input type="hidden" name="ord" value="${str(f.ord)}">
      <input type="hidden" name="dir" value="${str(f.dir, 'ASC')}">
`
  : ''}
    <button class="adm-btn" type="submit">${icon('filter_alt')}Filtrar</button>
${filtrando
  ? html`      <a class="adm-btn adm-btn--ghost" href="${url('admin/eventos', { aba })}">${icon('close')}Limpar</a>
`
  : ''}  </form>

${corpoLista}</section>
<!--/eh:lista-->
`;

  // --------------------------------------------------- bônus por escopo (desafios)
  let secBonus: SafeHtml | '' = '';
  if (aba === 'desafios') {
    const bonus = lst(data.bonus).map(obj);
    const livres = lst(data.escopos_livres);
    secBonus = html`
  <section class="adm-card ev-bonus">
    <header class="adm-card__head">
      <h2 class="adm-card__title">
        Bônus por escopo
        <small>Um por aba: o jogador resgata quando conclui os desafios daquele escopo.</small>
      </h2>
${livres.length > 0
  ? html`        <div class="adm-card__tools">
          <a class="adm-btn adm-btn--sm" href="${lnk({ form: 'bonus', id: '' })}">${icon('add')}Novo bônus</a>
        </div>
`
  : ''}    </header>

${bonus.length === 0
  ? html`      <div class="adm-empty">
        ${icon('redeem')}
        <h3 class="adm-empty__title">Nenhum bônus configurado</h3>
        <p class="adm-empty__txt">Sem bônus, concluir todos os desafios de uma aba não entrega nada ao jogador.</p>
        <a class="adm-btn adm-btn--primary" href="${lnk({ form: 'bonus', id: '' })}">Criar o primeiro</a>
      </div>
`
  : html`      <div class="adm-tablewrap">
        <table class="adm-table adm-table--compact">
          <thead>
            <tr>
              <th>Escopo</th><th>Tarja</th><th>Recompensa</th>
              <th class="tr">XP</th><th class="tr">Créditos</th><th class="tr">Desafios na aba</th>
              <th class="adm-table__acts"><span class="ev-sr">Ações</span></th>
            </tr>
          </thead>
          <tbody>
${bonus.map((b) => {
  const scope = str(b.scope);
  const nDes = int(b.desafios);
  return html`              <tr>
                <td><span class="adm-badge adm-badge--${tomEscopo(scope)}">${tem(escopos, scope) ? str(escopos[scope]) : scope}</span></td>
                <td>${str(b.label)}</td>
                <td class="ev-cel-nome">
                  <span class="ev-nome"><strong>${str(b.title)}</strong></span>
${str(b.description) !== ''
  ? html`                    <small class="ev-dim">${strimwidth(str(b.description), 72)}</small>
`
  : ''}                </td>
                <td class="tr num">${num(int(b.xp))}</td>
                <td class="tr num">${num(int(b.credits))}</td>
                <td class="tr num${nDes === 0 ? ' ev-zero' : ''}"
                    title="${nDes === 0 ? 'Nenhum desafio neste escopo: o bônus nunca é alcançado.' : ''}">${num(nDes)}</td>
                <td class="adm-table__acts">
                  <a class="adm-btn adm-btn--sm adm-btn--icon" title="Editar"
                     href="${lnk({ form: 'bonus', id: scope })}">${icon('edit')}</a>
                  <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger" title="Excluir"
                     href="${lnk({ excluir: scope, tipo: 'bonus' })}">${icon('delete')}</a>
                </td>
              </tr>
`;
})}          </tbody>
        </table>
      </div>
`}  </section>
`;
  }

  return html`${secAbas}${secExcluir}${secForm}${secLista}${secBonus}`;
}
