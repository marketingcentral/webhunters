/**
 * Tela CRIAR PONTOS NO MAPA do painel: /admin/mapas/pontos — o
 * App\Controllers\Admin\MapPointsController do PHP.
 *
 * Governa `map_point_types`, o catálogo de ícones pré-fabricados que o editor de mapa
 * arrasta para cima da arte. Aqui se define O QUE EXISTE; no editor se decide ONDE FICA.
 *
 * TRÊS DECISÕES QUE EXPLICAM O CÓDIGO (iguais ao PHP)
 *
 * 1. `kind` continua existindo, e o ponto não o substitui. `map_markers.kind` é o vocabulário
 *    que o JOGO entende. O ponto escolhe o DESENHO e a COR, e diz qual kind gravar. Por isso um
 *    ponto novo é sempre obrigado a apontar para um kind já conhecido.
 *
 * 2. Ponto de sistema não se apaga. O botão de excluir não existe para eles, e o servidor
 *    recusa mesmo que alguém poste direto.
 *
 * 3. Validação NÃO redireciona: redesenha a tela com 422 e com o que foi digitado ainda nos
 *    campos. Sucesso redireciona (PRG) com flash.
 */
import { dataMock, flash, url } from '../../core/helpers';
import { html } from '../../core/html';
import type { AppRequest } from '../../core/request';
import type { AppResponse } from '../../core/response';
import type { Params } from '../../core/router';
import type { Dict } from '../../core/view';
import * as MapPointsRepo from '../../repo/admin-map-points-repo';
import { adminMapasPontos } from '../../views/pages/admin-mapas-pontos';
import { AdminController } from './admin-controller';

/** Cores que o CSS do mapa sabe pintar (assets/css/pages/mapa.css: .mk--<cor>). */
export const CORES = ['white', 'lime', 'cyan', 'amber', 'gold', 'red', 'purple', 'muted'];

/** Erros por campo: nome do campo -> mensagem. */
type Erros = Record<string, string>;

/** O que o POST com erro força na tela (o $over do PHP). */
interface Over {
  form?: Dict;
  erros?: Erros;
  edicao?: boolean;
  alvo?: Dict | null;
}

/** (int) do PHP para uma string: prefixo numérico, senão 0. */
const intPhp = (v: unknown): number => {
  const n = Number.parseInt(String(v ?? '').trim(), 10);
  return Number.isNaN(n) ? 0 : n;
};

/** mb_substr($s, 0, $n) — conta caracteres, não unidades UTF-16. */
const mbCorta = (s: string, n: number): string => Array.from(s).slice(0, n).join('');

/** iconv('UTF-8','ASCII//TRANSLIT') aproximado: tira os acentos (NFD + marcas combinantes). */
const translit = (v: string): string => v.normalize('NFD').replace(/[̀-ͯ]/g, '');

/** `isset($lista[$k])` de um objeto. */
const tem = (lista: Record<string, unknown>, k: string): boolean =>
  Object.prototype.hasOwnProperty.call(lista, k) && lista[k] != null;

export class AdminMapPointsController extends AdminController {
  // ================================================================================ GET

  async index(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    return this.tela(req);
  }

  // =============================================================================== POST

  async salvar(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const id = intPhp(req.input('id', '0'));
    const antes = id > 0 ? await MapPointsRepo.porId(id) : null;
    if (id > 0 && antes === null) {
      return this.voltar('Ponto não encontrado — nada foi alterado.', 'error');
    }

    const form = this.formDoPost(req);
    const erros = await this.validar(form, id, antes);
    if (Object.keys(erros).length > 0) {
      return this.tela(req, { form, erros, edicao: true, alvo: antes }, 422);
    }

    const dados: Dict = {
      slug: form.slug,
      label: form.label,
      category: form.category,
      kind: form.kind,
      icon: form.icon_origem + ':' + form.icon_valor,
      color: form.color,
      layer: form.layer !== '' ? form.layer : null,
      show_label: form.show_label ? 1 : 0,
      description: form.description !== '' ? form.description : null,
      sort_order: form.sort_order,
    };

    if (antes !== null) {
      await MapPointsRepo.atualizar(id, dados);
      await this.auditar('ponto_mapa_editado', 'map_point_type', id, 'Ponto "' + form.label + '" atualizado.',
        { antes: this.resumir(antes), depois: dados });
      return this.voltar('Ponto "' + form.label + '" atualizado.');
    }

    const novo = await MapPointsRepo.criar(dados);
    await this.auditar('ponto_mapa_criado', 'map_point_type', novo, 'Ponto "' + form.label + '" criado.', dados);
    return this.voltar('Ponto "' + form.label + '" criado e já disponível na paleta do editor.');
  }

  async excluir(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const id = intPhp(req.input('id', '0'));
    const alvo = id > 0 ? await MapPointsRepo.porId(id) : null;
    if (alvo === null) {
      return this.voltar('Ponto não encontrado — nada foi excluído.', 'error');
    }
    if (alvo.is_system) {
      // O formulário nem oferece o botão; esta é a recusa para quem posta direto.
      return this.voltar('"' + String(alvo.label) + '" é um ponto do jogo e não pode ser excluído.', 'error');
    }

    const usos = (await MapPointsRepo.usos())[String(alvo.slug)] ?? 0;
    await MapPointsRepo.excluir(id);
    await this.auditar('ponto_mapa_excluido', 'map_point_type', id, 'Ponto "' + String(alvo.label) + '" excluído.',
      { ponto: this.resumir(alvo), marcadores_afetados: usos });

    const extra = usos > 0
      ? ' ' + usos + ' marcador(es) continuam no mapa, agora desenhados pelo tipo "' + String(alvo.kind) + '".'
      : '';
    return this.voltar('Ponto "' + String(alvo.label) + '" excluído.' + extra);
  }

  // =============================================================================== TELA

  private async tela(req: AppRequest, over: Over = {}, status = 200): Promise<AppResponse> {
    const cromo = dataMock('admin-mapas-pontos') as Dict;

    const filtros = {
      q: mbCorta(String(req.query('q') ?? ''), 60),
      cat: String(req.query('cat') ?? ''),
    };

    let novo = (req.query('novo') ?? req.input('novo')) === '1';
    let alvo: Dict | null = over.alvo ?? null;
    if (alvo === null && !novo) {
      const idUrl = intPhp(req.query('id') ?? 0);
      alvo = idUrl > 0 ? await MapPointsRepo.porId(idUrl) : null;
    }
    if (over.edicao && alvo === null) novo = true;
    const edicao = novo || alvo !== null;

    const [usos, todos] = await Promise.all([MapPointsRepo.usos(), MapPointsRepo.todos()]);

    const dados: Dict = {
      cromo,
      filtros,
      categorias: MapPointsRepo.CATEGORIAS,
      cores: CORES,
      kinds: this.kinds(),
      usos,
      grupos: await this.agrupar(filtros, usos),
      total: todos.length,
      modo: edicao ? 'editor' : 'lista',
      novo,
      alvo,
      erros: over.erros ?? {},
      form: over.form !== undefined ? over.form : this.formDoBanco(alvo, todos),
      excluir: await this.confirmacao(req, usos),
      filtrando: filtros.q !== '' || filtros.cat !== '',
    };

    const page = this.adminPage(
      'mapas-pontos',
      String(cromo.titulo ?? ''),
      edicao
        ? (alvo !== null ? 'Editando "' + String(alvo.label) + '"' : 'Novo ponto')
        : todos.length + ' ponto(s) no catálogo',
    );
    page.actions = edicao
      ? html`<a class="adm-btn adm-btn--ghost" href="${url('admin/mapas/pontos')}">Voltar ao catálogo</a>`
      : html`<a class="adm-btn adm-btn--ghost" href="${url('admin/mapas/editor')}">Abrir o editor de mapa</a><a class="adm-btn adm-btn--primary" href="${url('admin/mapas/pontos', { novo: '1' })}">Novo ponto</a>`;

    return this.render(adminMapasPontos, page, dados, 'admin', status);
  }

  /**
   * Passo de confirmação de exclusão, na URL (?excluir=N) e não num confirm() de JavaScript:
   * a CSP do projeto proíbe script inline.
   */
  private async confirmacao(req: AppRequest, usos: Record<string, number>): Promise<Dict | null> {
    const id = intPhp(req.query('excluir') ?? 0);
    if (id <= 0) return null;
    const alvo = await MapPointsRepo.porId(id);
    if (alvo === null || alvo.is_system) return null;
    return { ponto: alvo, usos: usos[String(alvo.slug)] ?? 0, impedido: false };
  }

  /**
   * Catálogo agrupado por categoria, já filtrado e com a contagem de uso. O filtro é
   * aplicado AQUI e não no SQL porque a tabela tem dezenas de linhas.
   */
  private async agrupar(f: { q: string; cat: string }, usos: Record<string, number>): Promise<MapPointsRepo.Grupo[]> {
    const q = f.q.trim().toLowerCase();
    const out: MapPointsRepo.Grupo[] = [];
    for (const g of await MapPointsRepo.porCategoria()) {
      if (f.cat !== '' && g.key !== f.cat) continue;
      const itens: Dict[] = [];
      for (const t of g.items) {
        const alvo = (String(t.label) + ' ' + String(t.slug) + ' ' + String(t.kind)).toLowerCase();
        if (q !== '' && !alvo.includes(q)) continue;
        // cópia: o item é o mesmo objeto do cache da requisição
        itens.push({ ...t, usos: usos[String(t.slug)] ?? 0 });
      }
      if (itens.length === 0) continue;
      out.push({ key: g.key, label: g.label, items: itens });
    }
    return out;
  }

  /**
   * `kind` oferecidos no <select>: o catálogo que o jogo sabe desenhar (o mesmo de
   * dataMock('admin-mapas').marcadores, que já é a lista de LAYER_OF).
   */
  private kinds(): Record<string, string> {
    const out: Record<string, string> = {};
    const marc = ((dataMock('admin-mapas') as Dict).marcadores ?? {}) as Record<string, Dict>;
    for (const [kind, info] of Object.entries(marc)) {
      out[kind] = String(info?.label ?? kind) + ' · camada ' + String(info?.camada ?? '');
    }
    return out;
  }

  // ========================================================================= FORMULÁRIO

  private formDoBanco(alvo: Dict | null, todos: Dict[]): Dict {
    if (alvo === null) {
      // Ponto novo entra no fim da fila: a ordem do catálogo é a ordem da paleta.
      let ultimo = 0;
      for (const t of todos) ultimo = Math.max(ultimo, Number(t.sort_order) | 0);
      return {
        slug: '', label: '', category: 'comercio', kind: 'market',
        icon_origem: 'symbol', icon_valor: 'place', color: 'gold',
        layer: '', show_label: false, description: '',
        sort_order: ultimo + 10,
      };
    }
    const [origem, valor] = MapPointsRepo.icone(String(alvo.icon ?? ''));
    return {
      slug: String(alvo.slug),
      label: String(alvo.label),
      category: String(alvo.category),
      kind: String(alvo.kind),
      icon_origem: origem,
      icon_valor: valor,
      color: String(alvo.color),
      layer: String(alvo.layer ?? ''),
      show_label: Boolean(alvo.show_label),
      description: String(alvo.description ?? ''),
      sort_order: Number(alvo.sort_order) | 0,
    };
  }

  private formDoPost(req: AppRequest): Dict {
    const rotulo = String(req.input('label', ''));
    const slug = String(req.input('slug', ''));
    const origem = String(req.input('icon_origem', 'symbol'));
    // Os três campos de ícone chegam SEMPRE; o servidor lê só o que corresponde à origem.
    const campoIcone = origem === 'glyph' ? 'icon_glyph' : origem === 'image' ? 'icon_image' : 'icon_symbol';

    return {
      // Slug em branco é preenchido a partir do rótulo.
      slug: mbCorta(slug !== '' ? this.slugificar(slug) : this.slugificar(rotulo), 48),
      label: mbCorta(rotulo, 60),
      category: String(req.input('category', '')),
      kind: String(req.input('kind', '')),
      icon_origem: origem,
      icon_valor: mbCorta(String(req.input(campoIcone, '')), 160),
      color: String(req.input('color', 'white')),
      layer: mbCorta(String(req.input('layer', '')), 24),
      show_label: String(req.input('show_label', '')) === '1',
      description: mbCorta(String(req.input('description', '')), 255),
      sort_order: Math.max(0, Math.min(9999, intPhp(req.input('sort_order', '0')))),
    };
  }

  private async validar(f: Dict, id: number, antes: Dict | null): Promise<Erros> {
    const cromo = dataMock('admin-mapas-pontos') as Dict;
    const glifos = (cromo.glifos ?? {}) as Record<string, unknown>;
    const e: Erros = {};

    if (f.label === '') e.label = 'Dê um nome ao ponto — é o que aparece na paleta do editor.';
    if (f.slug === '') e.slug = 'A chave não pode ficar vazia (use letras, números e hífen).';
    else if (await MapPointsRepo.slugEmUso(f.slug, id)) {
      e.slug = 'Já existe um ponto com a chave "' + f.slug + '".';
    }

    if (!tem(MapPointsRepo.CATEGORIAS, f.category)) e.category = 'Escolha uma categoria da lista.';
    if (!tem(this.kinds(), f.kind)) {
      // Ícone novo o painel aceita; COMPORTAMENTO novo, não.
      e.kind = 'Escolha um tipo que o jogo já sabe desenhar.';
    }
    if (!CORES.includes(f.color)) e.color = 'Cor fora da paleta do mapa.';

    if (!MapPointsRepo.ORIGENS_ICONE.includes(f.icon_origem)) {
      e.icon_valor = 'Origem de ícone desconhecida.';
    } else if (f.icon_valor === '') {
      e.icon_valor = 'Informe qual ícone este ponto desenha.';
    } else if (f.icon_origem === 'glyph' && !tem(glifos, f.icon_valor)) {
      e.icon_valor = 'Esse vetor não existe na tela do mapa.';
    } else if (f.icon_origem === 'symbol' && !/^[a-z0-9_]{2,40}$/.test(f.icon_valor)) {
      e.icon_valor = 'Nome de Material Symbol inválido (só letras minúsculas, números e _).';
    } else if (f.icon_origem === 'image' && !/^[A-Za-z0-9._/-]{3,160}$/.test(f.icon_valor)) {
      e.icon_valor = 'Caminho de imagem inválido.';
    }

    if (antes !== null && antes.is_system && f.slug !== String(antes.slug)) {
      // A chave de um ponto de sistema está escrita nos marcadores semeados por patch.
      e.slug = 'A chave de um ponto do jogo não pode mudar — os marcadores existentes apontam para ela.';
    }

    return e;
  }

  private slugificar(v: string): string {
    return translit(v).replace(/[^a-zA-Z0-9]+/g, '-').toLowerCase().replace(/^-+|-+$/g, '');
  }

  private resumir(t: Dict): Dict {
    return {
      slug: t.slug, label: t.label, category: t.category,
      kind: t.kind, icon: t.icon, color: t.color,
    };
  }

  private voltar(msg: string, tipo = 'ok'): AppResponse {
    flash(tipo, msg);
    return this.redirect('admin/mapas/pontos');
  }
}
