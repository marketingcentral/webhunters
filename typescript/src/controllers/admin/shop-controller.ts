/**
 * Tela Loja do painel: /admin/loja — o App\Controllers\Admin\ShopController do PHP.
 *
 * Governa `shop_offers` — o preço e a vitrine —, sempre conciliada com `items`, que é o
 * catálogo. Aqui NÃO se cria item, se põe item à venda.
 *
 * O QUE ESTA TELA MEXE NO JOGO, EM UMA FRASE POR CAMPO
 *   category   -> a ABA da loja em que o produto aparece
 *   featured   -> a aba DESTAQUES não tem conteúdo próprio: ela É o filtro deste campo
 *   sort_order -> a posição dentro da aba
 *   price + discount + currency -> o botão COMPRAR e quem consegue apertá-lo
 *
 * TRÊS ESCRITAS, UMA ROTA. As rotas são /salvar e /excluir. `salvar` atende o formulário
 * completo e mais duas ações de UMA tecla: alternar o destaque e mover a oferta na aba.
 *
 * Os filtros viajam na QUERY STRING do `action` de cada formulário (não em campos ocultos):
 * req.query() lê a URL em qualquer método, então o POST enxerga os mesmos filtros do GET.
 *
 * Esta tela não lida com janela de data (shop_offers não tem início/fim), então não usa
 * core/fuso.ts nem core/relogio.ts.
 */
import { dataMock, flash, num } from '../../core/helpers';
import type { AppRequest } from '../../core/request';
import type { AppResponse } from '../../core/response';
import type { Params } from '../../core/router';
import type { Dict } from '../../core/view';
import { corta } from '../../repo/admin-repo';
import * as ShopOfferRepo from '../../repo/admin-shop-offer-repo';
import { adminLoja } from '../../views/pages/admin-loja';
import { AdminController } from './admin-controller';

/** Ações que POST /admin/loja/salvar aceita em `acao`. */
const ACOES = ['formulario', 'destaque', 'mover'];

/** Erros por campo: nome do campo -> mensagem. */
type Erros = Record<string, string>;

/** O que o POST com erro força na tela (o $over do PHP). */
interface Over {
  form?: Dict;
  form_erros?: Erros;
  form_aberto?: boolean;
  form_id?: number;
}

/** (int) do PHP para uma string: prefixo numérico, senão 0. */
const intPhp = (v: unknown): number => {
  const n = Number.parseInt(String(v ?? '').trim(), 10);
  return Number.isNaN(n) ? 0 : n;
};

/** ctype_digit(): só dígitos ASCII, e não vazio. */
const soDigitos = (s: string): boolean => /^\d+$/.test(s);

/** sprintf só com %s, na ordem (e %% vira %). */
function sprintf(fmt: string, ...args: Array<string | number>): string {
  let i = 0;
  return fmt.replace(/%%|%s/g, (m) => (m === '%%' ? '%' : String(args[i++] ?? '')));
}

/** O texto da tela (app/Data/admin-loja.php). */
const textos = (): Dict => (dataMock('admin-loja') ?? {}) as Dict;
const sub = (txt: Dict, k: string): Dict => {
  const v = txt[k];
  return v !== null && typeof v === 'object' && !Array.isArray(v) ? (v as Dict) : {};
};
const msgDe = (txt: Dict, grupo: string, chave: string, def: string): string => {
  const v = sub(txt, grupo)[chave];
  return v === null || v === undefined ? def : String(v);
};

export class AdminShopController extends AdminController {
  async index(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    return this.tela(req);
  }

  /**
   * POST /admin/loja/salvar — cria/edita a oferta, ou aplica uma das ações rápidas.
   * O exigirAdmin() aqui é a ÚNICA proteção: a ação grava antes de desenhar.
   */
  async salvar(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    let acao = String(req.input('acao', 'formulario') ?? 'formulario');
    if (!ACOES.includes(acao)) acao = 'formulario';

    switch (acao) {
      case 'destaque': return this.alternarDestaque(req);
      case 'mover': return this.mover(req);
      default: return this.gravarFormulario(req);
    }
  }

  /**
   * POST /admin/loja/excluir — só executa com a confirmação vinda da tela de confirmação,
   * e só quando a loja do jogo sobrevive à remoção.
   */
  async excluir(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const txt = textos();
    const id = intPhp(req.input('id', '0'));
    const oferta = await ShopOfferRepo.porId(id);

    if (oferta === null) {
      flash('error', msgDe(txt, 'erros', 'nao_encontrada', 'Oferta não encontrada.'));
      return this.voltar(req);
    }

    // Confirmação é do SERVIDOR, não do navegador: um POST sem este campo não apaga nada.
    if (String(req.input('confirmar', '') ?? '') !== 'sim') {
      return this.voltar(req, { excluir: String(oferta.id) });
    }

    const motivo = await this.motivoParaNaoExcluir(oferta);
    if (motivo) {
      // Recusa também vira linha de auditoria.
      await this.auditar('loja_excluir_bloqueado', 'shop_offer', oferta.id,
        'Exclusão recusada: ' + String(oferta.item_nome) + ' em ' + String(oferta.category) + '.',
        { motivo });
      flash('error', motivo);
      return this.voltar(req);
    }

    await ShopOfferRepo.apagar(Number(oferta.id) | 0);

    await this.auditar('loja_excluir', 'shop_offer', oferta.id,
      'Oferta removida: ' + String(oferta.item_nome) + ' (' + String(oferta.category) + ').',
      {
        item_id: oferta.item_id,
        preco: oferta.price,
        moeda: oferta.currency,
        desconto: oferta.discount,
        destaque: oferta.featured,
      });

    flash('ok', sprintf(msgDe(txt, 'ok', 'excluida', '%s removida.'), String(oferta.item_nome)));
    return this.voltar(req);
  }

  // ================================================================== escrita: formulário

  /** Cria ou edita a oferta a partir do formulário completo. */
  private async gravarFormulario(req: AppRequest): Promise<AppResponse> {
    const txt = textos();
    const id = intPhp(req.input('id', '0'));

    const atual = id > 0 ? await ShopOfferRepo.porId(id) : null;
    if (id > 0 && atual === null) {
      flash('error', msgDe(txt, 'erros', 'nao_encontrada', 'Oferta não encontrada.'));
      return this.voltar(req);
    }

    const [dados, erros, bruto] = await this.validar(req, txt, atual);

    if (Object.keys(erros).length > 0) {
      // 422 e a MESMA tela, com o formulário aberto e o que foi digitado no lugar.
      return this.tela(req, {
        form: bruto,
        form_erros: erros,
        form_aberto: true,
        form_id: id,
      }, 422);
    }

    const item = (await ShopOfferRepo.item(Number(dados.item_id) | 0)) ?? {};
    const nome = String(item.name ?? 'Item');
    const cat = this.rotuloCategoria(txt, String(dados.category));

    if (atual === null) {
      const novoId = await ShopOfferRepo.criar(dados);
      await this.auditar('loja_criar', 'shop_offer', novoId,
        'Oferta publicada: ' + nome + ' (' + String(dados.category) + ').', dados);
      flash('ok', sprintf(msgDe(txt, 'ok', 'criada', '%s publicada em %s.'), nome, cat));
      return this.voltar(req, { destaque_id: String(novoId) });
    }

    await ShopOfferRepo.atualizar(id, dados);
    await this.auditar('loja_editar', 'shop_offer', id,
      'Oferta atualizada: ' + nome + ' (' + String(dados.category) + ').',
      // O diff, e não só o estado final: é o que responde "quem baixou este preço?".
      { antes: this.instantanea(atual), depois: dados });
    flash('ok', sprintf(msgDe(txt, 'ok', 'atualizada', '%s atualizada.'), nome));
    return this.voltar(req, { destaque_id: String(id) });
  }

  /**
   * Validação — no SERVIDOR, campo a campo. Devolve [dados prontos para o repo, erros por
   * campo, o que foi digitado]. O terceiro repopula o formulário.
   */
  private async validar(req: AppRequest, txt: Dict, atual: Dict | null): Promise<[Dict, Erros, Dict]> {
    const msg = sub(txt, 'erros');
    const m = (k: string, def: string): string => (msg[k] === null || msg[k] === undefined ? def : String(msg[k]));
    const erros: Erros = {};

    const entrada = (k: string): string => String(req.input(k, '') ?? '');
    const itemId = intPhp(req.input('item_id', '0'));
    const categoria = entrada('category');
    const precoTxt = entrada('price');
    const moeda = entrada('currency');
    const descTxt = entrada('discount');
    const tag = entrada('tag');
    const ordemTxt = entrada('sort_order');
    const destaque = entrada('featured') !== '';

    // ---- item -----------------------------------------------------------------------
    const item = await ShopOfferRepo.item(itemId);
    if (itemId <= 0) {
      erros.item_id = m('item_vazio', 'Escolha o item.');
    } else if (item === null) {
      erros.item_id = m('item_inexistente', 'Item inexistente.');
    }

    // ---- categoria ------------------------------------------------------------------
    const abas = Object.keys(sub(txt, 'categorias'));
    if (categoria === 'destaques') {
      // DESTAQUES é uma aba visível, mas não é um valor de category — é o filtro de `featured`.
      erros.category = m('categoria_destaque', 'Use o campo de destaque.');
    } else if (!abas.includes(categoria)) {
      erros.category = m('categoria', 'Categoria inválida.');
    }

    // ---- duplicidade (só quando item e aba são ambos válidos) ------------------------
    // Fiel ao PHP: `!isset($erros['item_id'], $erros['category'])` é "não estão AMBOS
    // definidos"; as demais condições já exigem item e aba válidos.
    if (!(erros.item_id !== undefined && erros.category !== undefined)
      && item !== null && abas.includes(categoria)
      && await ShopOfferRepo.categoriaDoDuplicado(itemId, categoria, atual !== null ? Number(atual.id) | 0 : 0)
    ) {
      erros.item_id = sprintf(m('item_duplicado', 'Item já à venda em %s.'), this.rotuloCategoria(txt, categoria));
    }

    // ---- moeda ----------------------------------------------------------------------
    if (!ShopOfferRepo.MOEDAS.includes(moeda)) {
      erros.currency = m('moeda', 'Moeda inválida.');
    }

    // ---- preço ----------------------------------------------------------------------
    // Vazio herda o preço de catálogo do item; item sem preço base torna o campo obrigatório.
    let preco = 0;
    if (precoTxt === '') {
      const base = Number(item?.base_price ?? 0) | 0;
      if (base > 0) {
        preco = base;
      } else {
        erros.price = m('preco_vazio', 'Informe o preço.');
      }
    } else if (!soDigitos(precoTxt) || intPhp(precoTxt) < 1 || intPhp(precoTxt) > ShopOfferRepo.PRECO_MAX) {
      erros.price = sprintf(m('preco_invalido', 'Preço inválido (máx. %s).'), num(ShopOfferRepo.PRECO_MAX));
    } else {
      preco = intPhp(precoTxt);
    }

    // ---- desconto -------------------------------------------------------------------
    let desconto: number | null = null;
    if (descTxt !== '' && descTxt !== '0') {
      if (!soDigitos(descTxt) || intPhp(descTxt) > ShopOfferRepo.DESCONTO_MAX) {
        erros.discount = sprintf(m('desconto', 'Desconto de 0 a %s%%.'), String(ShopOfferRepo.DESCONTO_MAX));
      } else {
        desconto = intPhp(descTxt);
      }
    }

    // ---- etiqueta -------------------------------------------------------------------
    // Conta por caractere (mb_strlen), não por unidade UTF-16.
    if (Array.from(tag).length > ShopOfferRepo.TAG_MAX) {
      erros.tag = sprintf(m('tag', 'Máximo de %s caracteres.'), String(ShopOfferRepo.TAG_MAX));
    }

    // ---- ordem ----------------------------------------------------------------------
    let ordem = 0;
    if (ordemTxt === '') {
      // Criar sem dizer a ordem põe no fim da aba; editar sem dizer mantém onde está.
      ordem = atual !== null
        ? Number(atual.sort_order) | 0
        : await ShopOfferRepo.proximaOrdem(abas.includes(categoria) ? categoria : '');
    } else if (!soDigitos(ordemTxt) || intPhp(ordemTxt) > ShopOfferRepo.ORDEM_MAX) {
      erros.sort_order = sprintf(m('ordem', 'Ordem de 0 a %s.'), num(ShopOfferRepo.ORDEM_MAX));
    } else {
      ordem = intPhp(ordemTxt);
    }

    const bruto: Dict = {
      id: atual !== null ? Number(atual.id) | 0 : 0,
      item_id: itemId,
      category: categoria,
      price: precoTxt,
      currency: moeda,
      discount: descTxt,
      tag,
      sort_order: ordemTxt,
      featured: destaque,
    };

    return [{
      item_id: itemId,
      category: categoria,
      price: preco,
      currency: moeda,
      discount: desconto,
      tag,
      featured: destaque,
      sort_order: ordem,
    }, erros, bruto];
  }

  // ============================================================== escrita: ações rápidas

  /** Põe ou tira a oferta da aba DESTAQUES, sem abrir formulário. */
  private async alternarDestaque(req: AppRequest): Promise<AppResponse> {
    const txt = textos();
    const oferta = await ShopOfferRepo.porId(intPhp(req.input('id', '0')));

    if (oferta === null) {
      flash('error', msgDe(txt, 'erros', 'nao_encontrada', 'Oferta não encontrada.'));
      return this.voltar(req);
    }

    const novo = !oferta.featured;
    await ShopOfferRepo.definirDestaque(Number(oferta.id) | 0, novo);

    await this.auditar('loja_destaque', 'shop_offer', oferta.id,
      (novo ? 'Destaque ligado: ' : 'Destaque desligado: ') + String(oferta.item_nome) + '.',
      { antes: oferta.featured, depois: novo });

    flash('ok', sprintf(msgDe(txt, 'ok', novo ? 'destacada' : 'tirada', '%s alterada.'), String(oferta.item_nome)));
    return this.voltar(req, { destaque_id: String(oferta.id) });
  }

  /**
   * Sobe ou desce a oferta uma posição DENTRO da própria aba — troca com a vizinha imediata,
   * sem renumerar a aba inteira.
   */
  private async mover(req: AppRequest): Promise<AppResponse> {
    const txt = textos();
    const oferta = await ShopOfferRepo.porId(intPhp(req.input('id', '0')));
    const direcao = String(req.input('dir', '') ?? '') === 'cima' ? 'cima' : 'baixo';

    if (oferta === null) {
      flash('error', msgDe(txt, 'erros', 'nao_encontrada', 'Oferta não encontrada.'));
      return this.voltar(req);
    }

    const viz = await ShopOfferRepo.vizinha(oferta, direcao);
    if (viz === null) {
      // Já é a primeira (ou a última) da aba: nada a fazer e nada a dizer.
      return this.voltar(req, { destaque_id: String(oferta.id) });
    }

    let ordemA = Number(oferta.sort_order) | 0;
    let ordemB = Number(viz.sort_order) | 0;
    if (ordemA === ordemB) {
      // Empate de sort_order: um dos dois recebe um número real para a ordem virar explícita.
      ordemA = direcao === 'cima' ? Math.max(0, ordemB) : ordemB;
      ordemB = direcao === 'cima' ? ordemB + 1 : Math.max(0, ordemB - 1);
    }

    await ShopOfferRepo.trocarOrdem(Number(oferta.id) | 0, ordemA, Number(viz.id) | 0, ordemB);

    const atualizada = await ShopOfferRepo.porId(Number(oferta.id) | 0);
    const posicao = atualizada !== null ? await ShopOfferRepo.posicaoNaAba(atualizada) : 0;

    await this.auditar('loja_ordenar', 'shop_offer', oferta.id,
      'Ordem alterada: ' + String(oferta.item_nome) + ' (' + String(oferta.category) + ').',
      { direcao, trocou_com: Number(viz.id) | 0, posicao });

    flash('ok', sprintf(
      msgDe(txt, 'ok', 'movida', '%s agora é a %sª de %s.'),
      String(oferta.item_nome),
      String(posicao),
      this.rotuloCategoria(txt, String(oferta.category)),
    ));
    return this.voltar(req, { destaque_id: String(oferta.id) });
  }

  // ============================================================================== leitura

  /** Desenha a tela: GET e POST-que-falhou mostram a mesma coisa (o segundo com o form aberto). */
  private async tela(req: AppRequest, over: Over = {}, status = 200): Promise<AppResponse> {
    const txt = textos();

    const filtros = this.filtros(req);
    const [lista, resumo, porAba, itens] = await Promise.all([
      ShopOfferRepo.listar(filtros),
      ShopOfferRepo.resumo(),
      ShopOfferRepo.contagemPorCategoria(),
      ShopOfferRepo.itensDoCatalogo(),
    ]);

    // Qual painel de escrita está aberto. São exclusivos.
    const editarId = over.form_id !== undefined ? over.form_id : intPhp(req.query('editar', '0'));
    const excluirId = intPhp(req.query('excluir', '0'));
    const novo = req.query('novo') !== null;

    const emEdicao = editarId > 0 ? await ShopOfferRepo.porId(editarId) : null;
    const aExcluir = excluirId > 0 && editarId <= 0 ? await ShopOfferRepo.porId(excluirId) : null;

    const formAberto = !!over.form_aberto || novo || emEdicao !== null;
    const form = over.form ?? this.formDe(emEdicao);

    const data: Dict = {
      txt,
      filtros,
      query: this.queryLimpa(filtros),
      lista,
      resumo,
      por_aba: porAba,
      itens,

      form,
      form_erros: over.form_erros ?? {},
      form_aberto: formAberto,
      form_edicao: emEdicao,

      excluir: aExcluir,
      excluir_bloqueio: aExcluir !== null ? await this.motivoParaNaoExcluir(aExcluir) : null,
      excluir_compras: aExcluir !== null ? await ShopOfferRepo.comprasDoItem(Number(aExcluir.item_id) | 0) : null,

      // Linha recém-escrita: a tabela a acende por um instante.
      destaque_id: intPhp(req.query('destaque_id', '0')),

      limites: {
        desconto: ShopOfferRepo.DESCONTO_MAX,
        tag: ShopOfferRepo.TAG_MAX,
        ordem: ShopOfferRepo.ORDEM_MAX,
        preco: ShopOfferRepo.PRECO_MAX,
        nichos: ShopOfferRepo.NICHOS_VITRINE,
      },

      admin_kpis: this.kpisDaTopbar(resumo),
    };

    const page = this.adminPage('loja', 'Loja', 'Ofertas, preços e destaques da vitrine do jogo');
    return this.render(adminLoja, page, data, 'admin', status);
  }

  /**
   * Filtros vindos da query string, já normalizados. O que não estiver na lista branca é
   * descartado em silêncio.
   */
  private filtros(req: AppRequest): Dict {
    const txt = textos();
    const abas = Object.keys(sub(txt, 'categorias'));

    const cat = String(req.query('cat', '') ?? '');
    const moe = String(req.query('moeda', '') ?? '');
    const est = String(req.query('estado', '') ?? '');
    const ord = String(req.query('ord', '') ?? '');
    const dir = String(req.query('dir', '') ?? '');

    return {
      // A ordem das abas viaja com o filtro porque é ela que ordena a listagem.
      abas,
      q: corta(String(req.query('q', '') ?? ''), 60),
      cat: abas.includes(cat) ? cat : '',
      moeda: ShopOfferRepo.MOEDAS.includes(moe) ? moe : '',
      estado: Object.prototype.hasOwnProperty.call(sub(txt, 'estados'), est) ? est : '',
      ord: ord !== '' ? ord : 'ordem',
      dir: dir.toLowerCase() === 'desc' ? 'desc' : 'asc',
      pag: Math.max(1, intPhp(req.query('pag', '1'))),
    };
  }

  /** Os filtros que viajam em cada link e formulário — sem os vazios. */
  private queryLimpa(f: Dict): Record<string, string> {
    const q: Record<string, string> = {};
    for (const k of ['q', 'cat', 'moeda', 'estado']) {
      if (String(f[k] ?? '') !== '') q[k] = String(f[k]);
    }
    if (f.ord !== 'ordem' || f.dir !== 'asc') {
      q.ord = String(f.ord);
      q.dir = String(f.dir);
    }
    if ((Number(f.pag) | 0) > 1) q.pag = String(f.pag);
    return q;
  }

  /**
   * A ÚNICA recusa de exclusão desta tela: a última oferta de uma aba (a vitrine do jogo
   * ficaria vazia). Nenhuma tabela referencia shop_offers.id, então não há órfão a temer.
   */
  private async motivoParaNaoExcluir(oferta: Dict): Promise<string | null> {
    const txt = textos();
    const abas = sub(txt, 'categorias');
    const cat = String(oferta.category);

    if (!Object.prototype.hasOwnProperty.call(abas, cat)) {
      // Categoria que não é aba da loja (dado antigo): some sem consequência visível.
      return null;
    }
    if (((await ShopOfferRepo.contagemPorCategoria())[cat] ?? 0) > 1) {
      return null;
    }

    const rotulo = String(abas[cat]);
    return sprintf(msgDe(txt, 'bloqueios', 'ultima_da_aba', 'Única oferta de %s (%s).'), rotulo, rotulo.toUpperCase());
  }

  /** Os três indicadores compactos da topbar — da MESMA consulta da faixa de KPIs. */
  private kpisDaTopbar(r: Dict): Dict[] {
    const promo = Number(r.promo) | 0;
    const medio = Number(r.desconto_medio) | 0;
    return [
      { icon: 'sell', label: 'Ofertas publicadas', value: num(Number(r.ofertas) | 0), tone: 'lime' },
      { icon: 'star', label: 'Em destaque', value: num(Number(r.destaque) | 0), tone: 'gold' },
      {
        icon: 'percent',
        label: 'Com desconto',
        value: num(promo),
        sub: medio > 0 ? 'méd. ' + medio + '%' : null,
        tone: promo > 0 ? 'amber' : 'cyan',
      },
    ];
  }

  /** Estado inicial do formulário (padrões: moeda de crédito, sem desconto). */
  private formDe(oferta: Dict | null): Dict {
    if (oferta === null) {
      return {
        id: 0, item_id: 0, category: '', price: '',
        currency: 'credits', discount: '', tag: '',
        sort_order: '', featured: false,
      };
    }
    return {
      id: Number(oferta.id) | 0,
      item_id: Number(oferta.item_id) | 0,
      category: String(oferta.category),
      price: String(oferta.price),
      currency: String(oferta.currency),
      discount: oferta.discount === null ? '' : String(oferta.discount),
      tag: String(oferta.tag),
      sort_order: String(oferta.sort_order),
      featured: Boolean(oferta.featured),
    };
  }

  /** O que guardar como "antes" na auditoria de uma edição. */
  private instantanea(o: Dict): Dict {
    return {
      item_id: o.item_id,
      category: o.category,
      price: o.price,
      currency: o.currency,
      discount: o.discount,
      tag: o.tag,
      featured: o.featured,
      sort_order: o.sort_order,
    };
  }

  private rotuloCategoria(txt: Dict, slug: string): string {
    const v = sub(txt, 'categorias')[slug];
    return v === null || v === undefined ? slug : String(v);
  }

  /**
   * Volta para a listagem com os MESMOS filtros de onde o operador saiu.
   * `queryLimpa + extra` do PHP: as chaves dos filtros vêm primeiro e o extra só acrescenta.
   */
  private voltar(req: AppRequest, extra: Record<string, string> = {}): AppResponse {
    const q = this.queryLimpa(this.filtros(req));
    for (const [k, v] of Object.entries(extra)) {
      if (!(k in q)) q[k] = v;
    }
    return this.redirect('admin/loja', q);
  }
}
