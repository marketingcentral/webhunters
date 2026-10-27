/**
 * Tela EVENTOS do painel: /admin/eventos — o App\Controllers\Admin\EventsController do PHP.
 *
 * Governa três catálogos numa tela só, em abas: `challenges` (+ `challenge_bonuses`), `news`
 * e `system_notices`.
 *
 * TRÊS ROTAS, QUATRO FORMULÁRIOS (igual ao PHP)
 * GET /admin/eventos, POST /admin/eventos/salvar e .../excluir. Criar, editar e confirmar
 * exclusão são ESTADOS DA MESMA URL (`?aba=`, `?form=`, `?id=`, `?excluir=`), e o tipo do
 * registro viaja num campo escondido (`tipo`) do POST.
 *
 * VALIDAÇÃO QUE NÃO PERDE O QUE FOI DIGITADO
 * POST que não passa NÃO redireciona: a própria tela é renderizada outra vez com os erros por
 * campo e os valores digitados (status 200, como no PHP).
 *
 * O QUE ESTA TELA SE RECUSA A FAZER
 * Excluir desafio com progresso em `player_challenges` (ON DELETE CASCADE) e notícia citada
 * por `maintenance_windows.news_slug` (junção por texto, sem FK).
 *
 * DIFERENÇAS PARA O PHP
 *   - `$request->all()` (valores do POST recusado) não existe no AppRequest: os valores são
 *     relidos campo a campo com input() (CAMPOS_FORM) — chegam APARADOS, onde o PHP devolvia
 *     o texto cru. Só muda espaço nas pontas do que reaparece no formulário.
 *   - Datas: a de publicação da notícia é DATE puro (sem fuso). "Hoje" (notícia futura) é a
 *     data de São Paulo (core/relogio.ts), e o created_at do aviso novo é gravado na hora de
 *     SP (ver repo/admin-events-repo.ts) — o mesmo relógio do PHP.
 */
import { dataMock, flash, icon, num, url } from '../../core/helpers';
import { html } from '../../core/html';
import type { AppRequest } from '../../core/request';
import { AppResponse } from '../../core/response';
import type { Params } from '../../core/router';
import type { Dict } from '../../core/view';
import * as EventsRepo from '../../repo/admin-events-repo';
import { adminEventos } from '../../views/pages/admin-eventos';
import { AdminController } from './admin-controller';

/** Abas da tela, na ordem em que aparecem. A primeira é o padrão. */
const ABAS = ['desafios', 'noticias', 'avisos'];

/** Formulário -> aba onde ele mora. É também a lista fechada de `?form=` e de `tipo`. */
const FORMULARIOS: Record<string, string> = {
  desafio: 'desafios',
  bonus: 'desafios',
  noticia: 'noticias',
  aviso: 'avisos',
};

/** Ícones do Material Symbols aceitos em desafio/aviso: minúsculas, dígitos e `_`. */
const RE_ICONE = /^[a-z0-9_]{1,48}$/;

/** Identificador estável usado pelo jogo nas URLs e nas junções (`news_slug`). */
const RE_SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

/** Campos que os quatro formulários postam — o substituto do `$request->all()` (ver topo). */
const CAMPOS_FORM = [
  'tipo', 'id', '_volta', 'slug', 'scope', 'season_id', 'label', 'description', 'icon', 'target',
  'xp', 'credits', 'highlight', 'sort_order', 'title', 'image', 'category', 'excerpt', 'body',
  'published', 'destino', 'destinatario', 'color', 'cta_label', 'cta_href', 'fresh',
];

type Erros = Record<string, string>;

/** O formulário aberto na tela: tipo, id (string) e os valores exibidos. */
interface Form {
  tipo: string;
  id: string;
  valores: Dict;
}

/** isset(FORMULARIOS[$tipo]) sem cair em chaves herdadas do Object. */
const ehForm = (tipo: string): boolean => Object.prototype.hasOwnProperty.call(FORMULARIOS, tipo);

/** (int) do PHP para uma string: prefixo numérico, senão 0. */
const intPhp = (v: unknown): number => {
  const n = Number.parseInt(String(v ?? '').trim(), 10);
  return Number.isNaN(n) ? 0 : n;
};

/** mb_strlen (pontos de código). */
const mbLen = (s: string): number => Array.from(s).length;

/** strtolower do PHP 8: só ASCII. */
const lowerAscii = (s: string): string => s.replace(/[A-Z]/g, (c) => c.toLowerCase());

/** (string) do PHP para um valor de coluna: null vira ''. */
const s = (v: unknown): string => (v === null || v === undefined ? '' : String(v));

export class AdminEventsController extends AdminController {
  // =====================================================================================
  // LEITURA
  // =====================================================================================

  async index(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    return this.tela(req);
  }

  // =====================================================================================
  // ESCRITA
  // =====================================================================================

  async salvar(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const tipo = String(req.input('tipo') ?? '');
    if (!ehForm(tipo)) {
      flash('error', 'Formulário desconhecido — nada foi gravado.');
      return this.redirect('admin/eventos');
    }

    switch (tipo) {
      case 'desafio':
        return this.salvarDesafio(req);
      case 'bonus':
        return this.salvarBonus(req);
      case 'noticia':
        return this.salvarNoticia(req);
      default:
        return this.salvarAviso(req);
    }
  }

  async excluir(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const tipo = String(req.input('tipo') ?? '');
    const id = String(req.input('id') ?? '');
    const volta = this.voltar(req.input('_volta'), ehForm(tipo) ? FORMULARIOS[tipo]! : 'desafios');

    if (!ehForm(tipo) || id === '') {
      flash('error', 'Pedido de exclusão incompleto — nada foi apagado.');
      return AppResponse.redirect(volta);
    }

    // O impedimento é conferido DE NOVO aqui: entre olhar e confirmar cabe um jogador
    // começando o desafio noutra aba.
    const impedimento = await this.impedimento(tipo, id);
    if (impedimento !== null) {
      flash('error', impedimento);
      return AppResponse.redirect(volta);
    }

    switch (tipo) {
      case 'desafio': {
        const linha = await EventsRepo.desafio(intPhp(id));
        if (linha === null) {
          flash('error', 'Esse desafio já não existe.');
          return AppResponse.redirect(volta);
        }
        await EventsRepo.excluirDesafio(intPhp(id));
        await this.auditar('evento_desafio_excluir', 'challenge', id,
          'Desafio removido: ' + s(linha.label), { slug: linha.slug, escopo: linha.scope });
        flash('ok', 'Desafio “' + s(linha.label) + '” removido.');
        break;
      }

      case 'bonus': {
        const linha = await EventsRepo.bonusDoEscopo(id);
        if (linha === null) {
          flash('error', 'Esse bônus já não existe.');
          return AppResponse.redirect(volta);
        }
        await EventsRepo.excluirBonus(id);
        await this.auditar('evento_bonus_excluir', 'challenge_bonus', id,
          'Bônus removido do escopo ' + id, { titulo: linha.title });
        flash('ok', 'Bônus do escopo “' + id + '” removido.');
        break;
      }

      case 'noticia': {
        const linha = await EventsRepo.noticia(intPhp(id));
        if (linha === null) {
          flash('error', 'Essa notícia já não existe.');
          return AppResponse.redirect(volta);
        }
        await EventsRepo.excluirNoticia(intPhp(id));
        await this.auditar('evento_noticia_excluir', 'news', id,
          'Notícia removida: ' + s(linha.title), { slug: linha.slug });
        flash('ok', 'Notícia “' + s(linha.title) + '” removida.');
        break;
      }

      default: {
        const linha = await EventsRepo.aviso(intPhp(id));
        if (linha === null) {
          flash('error', 'Esse aviso já não existe.');
          return AppResponse.redirect(volta);
        }
        await EventsRepo.excluirAviso(intPhp(id));
        await this.auditar('evento_aviso_excluir', 'system_notice', id,
          'Aviso removido: ' + s(linha.title),
          { destino: linha.user_id === null ? 'global' : s(linha.username) });
        flash('ok', 'Aviso “' + s(linha.title) + '” removido.');
      }
    }

    return AppResponse.redirect(volta);
  }

  // =====================================================================================
  // GRAVAÇÃO POR TIPO
  // =====================================================================================

  private async salvarDesafio(req: AppRequest): Promise<AppResponse> {
    const id = Math.max(0, intPhp(req.input('id') ?? 0));
    const ant = id > 0 ? await EventsRepo.desafio(id) : null;
    if (id > 0 && ant === null) {
      flash('error', 'Esse desafio já não existe — nada foi gravado.');
      return AppResponse.redirect(this.voltar(req.input('_volta'), 'desafios'));
    }

    const erros: Erros = {};
    const slug = this.campoSlug(req, 'slug', 64, erros);
    if (slug !== '' && (await EventsRepo.slugDesafioEmUso(slug, id))) {
      erros.slug = 'Já existe outro desafio com este identificador.';
    }

    let escopo = String(req.input('scope') ?? '');
    if (!EventsRepo.ESCOPOS.includes(escopo)) {
      erros.scope = 'Escolha um escopo.';
      escopo = EventsRepo.ESCOPOS[0]!;
    }

    const label = this.campoTexto(req, 'label', 160, true, erros, 'O título do desafio');
    const desc = this.campoTexto(req, 'description', 400, false, erros, 'A descrição');
    const icone = this.campoIcone(req, 'icon', erros);

    const alvo = this.campoInteiro(req, 'target', 1, 1_000_000, erros, 'O alvo');
    const xp = this.campoInteiro(req, 'xp', 0, 100_000_000, erros, 'O XP');
    const creditos = this.campoInteiro(req, 'credits', 0, 100_000_000, erros, 'Os créditos');
    const ordem = this.campoInteiro(req, 'sort_order', 0, 65_535, erros, 'A ordem');

    // season_id só faz sentido em desafio de temporada. Fora desse escopo o valor é ZERADO.
    let temporada: number | null = null;
    if (escopo === 'temporada') {
      temporada = Math.max(0, intPhp(req.input('season_id') ?? 0));
      if (temporada <= 0) {
        erros.season_id = 'Desafio de temporada precisa dizer de qual temporada é.';
      } else if (!(await EventsRepo.temporadaExiste(temporada))) {
        erros.season_id = 'Essa temporada não existe.';
        temporada = null;
      }
    }

    if (Object.keys(erros).length > 0) {
      return this.tela(req, erros, 'desafio');
    }

    const dados: Dict = {
      slug,
      scope: escopo,
      season_id: temporada !== null && temporada > 0 ? temporada : null,
      label,
      description: desc !== '' ? desc : null,
      icon: icone !== '' ? icone : null,
      target: alvo,
      xp,
      credits: creditos,
      highlight: req.input('highlight') !== null ? 1 : 0,
      sort_order: ordem,
    };

    const novoId = await EventsRepo.salvarDesafio(dados, id);
    await this.auditar(
      id > 0 ? 'evento_desafio_editar' : 'evento_desafio_criar',
      'challenge',
      novoId,
      (id > 0 ? 'Desafio atualizado: ' : 'Desafio criado: ') + label,
      id > 0 ? { antes: this.diff(ant ?? {}, dados) } : dados,
    );

    flash('ok', id > 0 ? 'Desafio “' + label + '” atualizado.' : 'Desafio “' + label + '” criado.');
    return AppResponse.redirect(this.voltar(req.input('_volta'), 'desafios'));
  }

  private async salvarBonus(req: AppRequest): Promise<AppResponse> {
    // A chave primária de `challenge_bonuses` é o escopo: editar não troca o escopo.
    const escopoOriginal = String(req.input('id') ?? '');
    const editando = escopoOriginal !== '' && (await EventsRepo.bonusDoEscopo(escopoOriginal)) !== null;

    const erros: Erros = {};
    const escopo = editando ? escopoOriginal : String(req.input('scope') ?? '');
    if (!EventsRepo.ESCOPOS.includes(escopo)) {
      erros.scope = 'Escolha um escopo.';
    } else if (!editando && (await EventsRepo.bonusDoEscopo(escopo)) !== null) {
      erros.scope = 'Este escopo já tem bônus. Edite o que existe.';
    }

    const rotulo = this.campoTexto(req, 'label', 40, true, erros, 'A tarja');
    const titulo = this.campoTexto(req, 'title', 80, true, erros, 'O título');
    const desc = this.campoTexto(req, 'description', 400, false, erros, 'A descrição');
    const imagem = this.campoCaminho(req, 'image', 160, erros);
    const xp = this.campoInteiro(req, 'xp', 0, 100_000_000, erros, 'O XP');
    const creds = this.campoInteiro(req, 'credits', 0, 100_000_000, erros, 'Os créditos');

    if (Object.keys(erros).length > 0) {
      return this.tela(req, erros, 'bonus');
    }

    const dados: Dict = {
      label: rotulo,
      title: titulo,
      description: desc !== '' ? desc : null,
      image: imagem !== '' ? imagem : null,
      xp,
      credits: creds,
    };
    await EventsRepo.salvarBonus(editando ? dados : { ...dados, scope: escopo }, editando ? escopo : '');

    await this.auditar(
      editando ? 'evento_bonus_editar' : 'evento_bonus_criar',
      'challenge_bonus',
      escopo,
      (editando ? 'Bônus atualizado: ' : 'Bônus criado: ') + titulo,
      dados,
    );
    flash('ok', 'Bônus do escopo “' + escopo + '” salvo.');
    return AppResponse.redirect(this.voltar(req.input('_volta'), 'desafios'));
  }

  private async salvarNoticia(req: AppRequest): Promise<AppResponse> {
    const id = Math.max(0, intPhp(req.input('id') ?? 0));
    const ant = id > 0 ? await EventsRepo.noticia(id) : null;
    if (id > 0 && ant === null) {
      flash('error', 'Essa notícia já não existe — nada foi gravado.');
      return AppResponse.redirect(this.voltar(req.input('_volta'), 'noticias'));
    }

    const erros: Erros = {};
    const slug = this.campoSlug(req, 'slug', 80, erros);
    if (slug !== '' && (await EventsRepo.slugNoticiaEmUso(slug, id))) {
      erros.slug = 'Já existe outra notícia com este identificador.';
    }
    // Renomear o slug de uma notícia citada por `maintenance_windows` quebraria a junção.
    if (ant !== null && slug !== '' && slug !== s(ant.slug)) {
      const janelas = await EventsRepo.janelasDaNoticia(s(ant.slug));
      if (janelas > 0) {
        erros.slug = janelas + ' janela(s) de manutenção apontam para “' + s(ant.slug)
          + '”. Trocar o identificador desligaria essa ligação.';
      }
    }

    const titulo = this.campoTexto(req, 'title', 160, true, erros, 'O título');
    const categoria = this.campoTexto(req, 'category', 24, false, erros, 'A categoria');
    const resumo = this.campoTexto(req, 'excerpt', 400, false, erros, 'O resumo');
    const imagem = this.campoCaminho(req, 'image', 160, erros);
    const ordem = this.campoInteiro(req, 'sort_order', 0, 65_535, erros, 'A ordem');
    const corpo = this.corpoDaNoticia(String(req.input('body') ?? ''), erros);
    const data = this.campoData(req, 'published', erros);

    if (Object.keys(erros).length > 0) {
      return this.tela(req, erros, 'noticia');
    }

    const dados: Dict = {
      slug,
      title: titulo,
      category: categoria !== '' ? categoria : null,
      excerpt: resumo !== '' ? resumo : null,
      body: corpo,
      image: imagem !== '' ? imagem : null,
      published: data,
      sort_order: ordem,
    };

    const novoId = await EventsRepo.salvarNoticia(dados, id);
    const estado = data === null ? 'rascunho' : 'publicada em ' + data;
    await this.auditar(
      id > 0 ? 'evento_noticia_editar' : 'evento_noticia_criar',
      'news',
      novoId,
      (id > 0 ? 'Notícia atualizada: ' : 'Notícia criada: ') + titulo + ' (' + estado + ')',
      { slug, published: data, categoria },
    );

    flash('ok', 'Notícia “' + titulo + '” salva como ' + estado + '.');
    return AppResponse.redirect(this.voltar(req.input('_volta'), 'noticias'));
  }

  private async salvarAviso(req: AppRequest): Promise<AppResponse> {
    const id = Math.max(0, intPhp(req.input('id') ?? 0));
    const ant = id > 0 ? await EventsRepo.aviso(id) : null;
    if (id > 0 && ant === null) {
      flash('error', 'Esse aviso já não existe — nada foi gravado.');
      return AppResponse.redirect(this.voltar(req.input('_volta'), 'avisos'));
    }

    const erros: Erros = {};

    // Destino: global (user_id NULL) ou um jogador, resolvido pelo NOME digitado.
    const destino = String(req.input('destino') ?? 'global');
    let userId: number | null = null;
    let paraQuem = 'global';
    if (destino === 'jogador') {
      const nome = String(req.input('destinatario') ?? '').trim();
      if (nome === '') {
        erros.destinatario = 'Diga para qual jogador é este aviso.';
      } else {
        const jogador = await EventsRepo.jogadorPorNome(nome);
        if (jogador === null) {
          erros.destinatario = 'Não existe jogador com o nome “' + nome + '”.';
        } else {
          userId = Number(jogador.id) | 0;
          paraQuem = s(jogador.username);
        }
      }
    } else if (destino !== 'global') {
      erros.destino = 'Escolha entre aviso global e aviso de um jogador.';
    }

    const icone = this.campoIcone(req, 'icon', erros);
    if (icone === '') {
      erros.icon = 'O ícone é obrigatório (ex.: info, warning, redeem).';
    }

    let cor = String(req.input('color') ?? '');
    if (!EventsRepo.CORES_AVISO.includes(cor)) {
      erros.color = 'Escolha uma das cores que o jogo sabe desenhar.';
      cor = 'lime';
    }

    const titulo = this.campoTexto(req, 'title', 120, true, erros, 'O título');
    const texto = this.campoTexto(req, 'body', 400, true, erros, 'O texto do aviso');
    const ordem = this.campoInteiro(req, 'sort_order', 0, 65_535, erros, 'A ordem');

    // Botão: rótulo e destino andam juntos.
    const ctaLabel = this.campoTexto(req, 'cta_label', 40, false, erros, 'O rótulo do botão');
    const ctaHref = String(req.input('cta_href') ?? '').trim();
    if (mbLen(ctaHref) > 120) {
      erros.cta_href = 'O destino do botão passa de 120 caracteres.';
    } else if (ctaHref !== '' && !/^(https?:\/\/[^\s]+|[a-z0-9][a-z0-9/_-]*)$/i.test(ctaHref)) {
      erros.cta_href = 'Use um caminho do jogo (ex.: loja) ou um endereço http(s) completo.';
    }
    if (ctaLabel !== '' && ctaHref === '') {
      erros.cta_href = 'O botão tem rótulo mas não tem para onde ir.';
    }
    if (ctaHref !== '' && ctaLabel === '') {
      erros.cta_label = 'O botão tem destino mas não tem rótulo.';
    }

    if (Object.keys(erros).length > 0) {
      return this.tela(req, erros, 'aviso');
    }

    const dados: Dict = {
      user_id: userId,
      icon: icone,
      color: cor,
      title: titulo,
      body: texto,
      cta_label: ctaLabel !== '' ? ctaLabel : null,
      cta_href: ctaHref !== '' ? ctaHref : null,
      fresh: req.input('fresh') !== null ? 1 : 0,
      sort_order: ordem,
    };

    const novoId = await EventsRepo.salvarAviso(dados, id);
    await this.auditar(
      id > 0 ? 'evento_aviso_editar' : 'evento_aviso_criar',
      'system_notice',
      novoId,
      (id > 0 ? 'Aviso atualizado: ' : 'Aviso criado: ') + titulo + ' (' + paraQuem + ')',
      { destino: paraQuem, cor, icone },
    );

    flash('ok', 'Aviso “' + titulo + '” salvo para ' + (userId === null ? 'todos os jogadores' : paraQuem) + '.');
    return AppResponse.redirect(this.voltar(req.input('_volta'), 'avisos'));
  }

  // =====================================================================================
  // MONTAGEM DA TELA
  // =====================================================================================

  /**
   * Desenha a tela inteira. O GET normal e o POST que não passou na validação chegam aqui
   * pela mesma porta.
   */
  private async tela(req: AppRequest, erros: Erros = {}, formErro: string | null = null): Promise<AppResponse> {
    const filtros = this.filtros(req);
    const aba = formErro !== null ? FORMULARIOS[formErro]! : String(filtros.aba);

    // Depois de um POST inválido os valores exibidos são os DIGITADOS; num GET, os do banco.
    const form: Form | null = formErro !== null
      ? { tipo: formErro, id: String(req.input('id') ?? ''), valores: this.valoresDoPost(req) }
      : await this.formularioPedido(req, aba);

    const excluir = formErro === null ? await this.exclusaoPedida(req, aba) : null;

    const cromo = dataMock('admin-eventos') as Dict;
    const volta = this.urlDaLista(filtros, aba);
    const dados: Dict = {
      aba,
      filtros,
      resumo: await EventsRepo.resumo(),
      cromo,
      form,
      erros,
      excluir,
      volta,
      temporadas: [],
      jogadores: [],
    };

    // Cada aba paga só a consulta dela.
    if (aba === 'desafios') {
      const [lista, bonus, livres] = await Promise.all([
        EventsRepo.desafios(filtros),
        EventsRepo.bonus(),
        EventsRepo.escoposSemBonus(),
      ]);
      dados.lista = lista;
      dados.bonus = bonus;
      dados.escopos_livres = livres;
    } else if (aba === 'noticias') {
      const [lista, categorias] = await Promise.all([EventsRepo.noticias(filtros), EventsRepo.categoriasDeNoticia()]);
      dados.lista = lista;
      dados.categorias = categorias;
    } else {
      dados.lista = await EventsRepo.avisos(filtros);
    }

    if ((form?.tipo ?? '') === 'desafio') {
      dados.temporadas = await EventsRepo.temporadas();
    }
    if ((form?.tipo ?? '') === 'aviso') {
      dados.jogadores = await EventsRepo.sugestoesDeJogador();
    }

    const abas = (cromo.abas ?? {}) as Record<string, Dict>;
    const rotuloAba = String((Object.prototype.hasOwnProperty.call(abas, aba) ? abas[aba]!.label : null) ?? 'Eventos');
    const page = this.adminPage('eventos', 'Eventos', 'Desafios, notícias e avisos do sistema', {
      actions: this.botaoNovo(aba, rotuloAba),
    });

    return this.render(adminEventos, page, { ...dados, admin_conta: this.admin() });
  }

  /** Estado da listagem lido da URL, já domado. */
  private filtros(req: AppRequest): Dict {
    let aba = String(req.query('aba') ?? '');
    if (!ABAS.includes(aba)) {
      aba = ABAS[0]!;
    }

    return {
      aba,
      q: Array.from(String(req.query('q') ?? '')).slice(0, 80).join('').trim(),
      escopo: String(req.query('escopo') ?? ''),
      estado: String(req.query('estado') ?? ''),
      cat: String(req.query('cat') ?? ''),
      destino: String(req.query('destino') ?? ''),
      ord: String(req.query('ord') ?? ''),
      dir: String(req.query('dir') ?? '').toUpperCase() === 'DESC' ? 'DESC' : 'ASC',
      pagina: Math.max(1, intPhp(req.query('p') ?? 1)),
    };
  }

  /** Os valores do POST recusado (o `$request->all()` do PHP; ver o topo do arquivo). */
  private valoresDoPost(req: AppRequest): Dict {
    const v: Dict = {};
    for (const k of CAMPOS_FORM) {
      const x = req.input(k);
      if (x !== null) v[k] = x;
    }
    return v;
  }

  /**
   * `?form=noticia&id=3` -> formulário de edição preenchido do banco; sem id -> vazio. Um id
   * que não existe mais vira o formulário de criação.
   */
  private async formularioPedido(req: AppRequest, aba: string): Promise<Form | null> {
    const tipo = String(req.query('form') ?? '');
    if (!ehForm(tipo) || FORMULARIOS[tipo] !== aba) {
      return null;
    }
    const id = String(req.query('id') ?? '').trim();

    let valores: Dict = {};
    if (id !== '') {
      let linha: Dict | null;
      switch (tipo) {
        case 'desafio': linha = await EventsRepo.desafio(intPhp(id)); break;
        case 'bonus': linha = await EventsRepo.bonusDoEscopo(id); break;
        case 'noticia': linha = await EventsRepo.noticia(intPhp(id)); break;
        default: linha = await EventsRepo.aviso(intPhp(id));
      }
      if (linha === null) {
        return { tipo, id: '', valores: {} };
      }
      valores = { ...linha };

      if (tipo === 'noticia') {
        // O corpo é gravado como lista JSON; no campo de texto volta a ser parágrafos.
        valores.body = this.corpoParaTexto(s(linha.body));
      }
      if (tipo === 'aviso') {
        valores.destino = linha.user_id === null ? 'global' : 'jogador';
        valores.destinatario = s(linha.username);
      }
    }

    return { tipo, id, valores };
  }

  /** `?excluir=<id>&tipo=<t>` -> o cartão de confirmação (nada é apagado aqui). */
  private async exclusaoPedida(req: AppRequest, aba: string): Promise<Dict | null> {
    const id = String(req.query('excluir') ?? '').trim();
    const tipo = String(req.query('tipo') ?? '');
    if (id === '' || !ehForm(tipo) || FORMULARIOS[tipo] !== aba) {
      return null;
    }

    let titulo = '';
    let detalhe = '';
    switch (tipo) {
      case 'desafio': {
        const l = await EventsRepo.desafio(intPhp(id));
        if (l !== null) [titulo, detalhe] = [s(l.label), 'Desafio ' + s(l.scope) + ' · ' + s(l.slug)];
        break;
      }
      case 'bonus': {
        const l = await EventsRepo.bonusDoEscopo(id);
        if (l !== null) [titulo, detalhe] = [s(l.title), 'Bônus do escopo ' + id];
        break;
      }
      case 'noticia': {
        const l = await EventsRepo.noticia(intPhp(id));
        if (l !== null) [titulo, detalhe] = [s(l.title), 'Notícia · ' + s(l.slug)];
        break;
      }
      default: {
        const l = await EventsRepo.aviso(intPhp(id));
        if (l !== null) [titulo, detalhe] = [s(l.title), 'Aviso · ' + (l.user_id === null ? 'global' : s(l.username))];
      }
    }

    if (titulo === '') {
      return null;
    }

    return {
      tipo,
      id,
      titulo,
      detalhe,
      impedimento: await this.impedimento(tipo, id),
    };
  }

  /** Por que este registro NÃO pode ser apagado — ou null quando pode. */
  private async impedimento(tipo: string, id: string): Promise<string | null> {
    if (tipo === 'desafio') {
      const n = await EventsRepo.progressoDoDesafio(intPhp(id));
      return n === 0 ? null
        : 'Este desafio já tem progresso gravado de ' + n + ' jogador(es). Apagá-lo apagaria '
        + 'junto esse progresso (player_challenges cai por ON DELETE CASCADE). Zere o alvo ou '
        + 'tire o desafio do escopo em vez de excluir.';
    }
    if (tipo === 'noticia') {
      const linha = await EventsRepo.noticia(intPhp(id));
      if (linha === null) {
        return null;
      }
      const n = await EventsRepo.janelasDaNoticia(s(linha.slug));
      return n === 0 ? null
        : n + ' janela(s) de manutenção apontam para “' + s(linha.slug) + '”. Sem a notícia, '
        + 'essas janelas ficam sem imagem e sem link na HUD. Desligue a referência em '
        + 'maintenance_windows antes de excluir.';
    }
    return null;
  }

  // =====================================================================================
  // CAMPOS — validação no servidor, uma regra por método
  // =====================================================================================

  private campoTexto(r: AppRequest, campo: string, max: number, obrigatorio: boolean, erros: Erros, rotulo: string): string {
    const v = String(r.input(campo) ?? '').trim();
    if (v === '') {
      if (obrigatorio) {
        erros[campo] = rotulo + ' é obrigatório.';
      }
      return '';
    }
    if (mbLen(v) > max) {
      erros[campo] = rotulo + ' passa de ' + max + ' caracteres (tem ' + mbLen(v) + ').';
    }
    return v;
  }

  private campoSlug(r: AppRequest, campo: string, max: number, erros: Erros): string {
    const v = lowerAscii(String(r.input(campo) ?? '').trim());
    if (v === '') {
      erros[campo] = 'O identificador é obrigatório — é por ele que o jogo encontra o registro.';
      return '';
    }
    if (mbLen(v) > max) {
      erros[campo] = 'O identificador passa de ' + max + ' caracteres.';
      return v;
    }
    if (!RE_SLUG.test(v)) {
      erros[campo] = 'Use letras minúsculas, números e hífen entre palavras (ex.: evento-caca-na-floresta).';
    }
    return v;
  }

  private campoInteiro(r: AppRequest, campo: string, min: number, max: number, erros: Erros, rotulo: string): number {
    const bruto = String(r.input(campo) ?? '').trim();
    if (bruto === '') {
      return min;
    }
    if (!/^\d{1,12}$/.test(bruto)) {
      erros[campo] = rotulo + ' precisa ser um número inteiro.';
      return min;
    }
    const v = Number.parseInt(bruto, 10);
    if (v < min || v > max) {
      erros[campo] = rotulo + ' precisa ficar entre ' + num(min) + ' e ' + num(max) + '.';
      return Math.max(min, Math.min(max, v));
    }
    return v;
  }

  private campoIcone(r: AppRequest, campo: string, erros: Erros): string {
    const v = lowerAscii(String(r.input(campo) ?? '').trim());
    if (v === '') {
      return '';
    }
    if (!RE_ICONE.test(v)) {
      erros[campo] = 'Nome de ícone do Material Symbols: minúsculas, números e sublinhado (ex.: military_tech).';
    }
    return v;
  }

  /** Caminho de mídia relativo (`items/supply-crate.png`), do jeito que media() resolve. */
  private campoCaminho(r: AppRequest, campo: string, max: number, erros: Erros): string {
    const v = String(r.input(campo) ?? '').trim();
    if (v === '') {
      return '';
    }
    if (mbLen(v) > max) {
      erros[campo] = 'O caminho passa de ' + max + ' caracteres.';
      return v;
    }
    if (!/^[a-z0-9][a-z0-9._/-]*\.(png|jpg|jpeg|webp|avif)$/i.test(v) || v.includes('..')) {
      erros[campo] = 'Caminho relativo dentro de /media, terminando em .png/.jpg/.webp (ex.: items/supply-crate.png).';
    }
    return v;
  }

  /** Data de publicação. Vazio é RASCUNHO (estado legítimo, não erro). */
  private campoData(r: AppRequest, campo: string, erros: Erros): string | null {
    const v = String(r.input(campo) ?? '').trim();
    if (v === '') {
      return null;
    }
    // createFromFormat('!Y-m-d') + format() === entrada: data de calendário exata, sem fuso.
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v);
    let ok = false;
    if (m) {
      const d = new Date(Date.UTC(+m[1]!, +m[2]! - 1, +m[3]!));
      ok = d.getUTCFullYear() === +m[1]! && d.getUTCMonth() === +m[2]! - 1 && d.getUTCDate() === +m[3]!;
    }
    if (!ok) {
      erros[campo] = 'Data inválida. Use o formato AAAA-MM-DD.';
      return null;
    }
    return v;
  }

  /** O corpo digitado vira LISTA JSON de parágrafos — o formato que a HUD decodifica. */
  private corpoDaNoticia(bruto0: string, erros: Erros): string | null {
    const bruto = bruto0.replace(/\r\n|\r/g, '\n').trim();
    if (bruto === '') {
      return null;
    }
    const paragrafos = bruto.split(/\n{2,}/).map((p) => p.trim()).filter((p) => p !== '');
    // JSON_UNESCAPED_UNICODE|SLASHES: o JSON.stringify já não escapa nenhum dos dois; o PHP
    // ainda escapa U+2028/U+2029 (sem JSON_UNESCAPED_LINE_TERMINATORS) — repetido aqui.
    const json = JSON.stringify(paragrafos).split(String.fromCharCode(0x2028)).join('\\u2028').split(String.fromCharCode(0x2029)).join('\\u2029');
    if (new TextEncoder().encode(json).length > 60000) {
      erros.body = 'O corpo da notícia é grande demais.';
      return null;
    }
    return json;
  }

  /** Caminho inverso: a lista JSON do banco volta a ser texto editável. */
  private corpoParaTexto(guardado: string): string {
    if (guardado.trim() === '') {
      return '';
    }
    let lista: unknown;
    try {
      lista = JSON.parse(guardado);
    } catch {
      return guardado;
    }
    if (Array.isArray(lista)) return lista.map((x) => s(x)).join('\n\n');
    if (lista !== null && typeof lista === 'object') return Object.values(lista as Dict).map((x) => s(x)).join('\n\n');
    return guardado;
  }

  // =====================================================================================
  // NAVEGAÇÃO
  // =====================================================================================

  /** URL da listagem com os filtros atuais — o "voltar" de todo formulário. */
  private urlDaLista(f: Dict, aba: string): string {
    const q: Record<string, string> = { aba };
    for (const k of ['q', 'escopo', 'estado', 'cat', 'destino', 'ord']) {
      if (s(f[k]) !== '') {
        q[k] = s(f[k]);
      }
    }
    if (s(f.ord) !== '' && s(f.dir ?? 'ASC') === 'DESC') {
      q.dir = 'DESC';
    }
    if ((Number(f.pagina ?? 1) | 0) > 1) {
      q.p = String(Number(f.pagina) | 0);
    }
    return url('admin/eventos', q);
  }

  /**
   * O `_volta` é entrada de usuário: nunca é usado como URL. A query dele é relida pelo MESMO
   * normalizador dos filtros e a URL é remontada aqui.
   */
  private voltar(bruto0: string | null, abaPadrao: string): string {
    const bruto = s(bruto0);
    // parse_url(..., PHP_URL_QUERY): o que vem depois do primeiro '?' e antes do '#'.
    const i = bruto.indexOf('?');
    const qs = i >= 0 ? bruto.slice(i + 1).split('#')[0]! : '';
    if (qs === '') {
      return url('admin/eventos', { aba: abaPadrao });
    }
    const partes = new URLSearchParams(qs);
    const g = (k: string): string => partes.get(k) ?? '';
    const f: Dict = {
      q: Array.from(g('q')).slice(0, 80).join('').trim(),
      escopo: g('escopo'),
      estado: g('estado'),
      cat: g('cat'),
      destino: g('destino'),
      ord: g('ord'),
      dir: g('dir').toUpperCase() === 'DESC' ? 'DESC' : 'ASC',
      pagina: Math.max(1, intPhp(partes.get('p') ?? 1)),
    };
    const aba = g('aba');
    return this.urlDaLista(f, ABAS.includes(aba) ? aba : abaPadrao);
  }

  /** O botão "novo" da topbar muda de nome com a aba. */
  private botaoNovo(aba: string, rotuloAba: string) {
    const tipo = aba === 'noticias' ? 'noticia' : aba === 'avisos' ? 'aviso' : 'desafio';
    const rotulo = aba === 'noticias' ? 'Nova notícia' : aba === 'avisos' ? 'Novo aviso' : 'Novo desafio';
    const href = url('admin/eventos', { aba, form: tipo });

    return html`<a class="adm-btn adm-btn--primary" href="${href}" title="${rotuloAba + ' — criar registro'}">${icon('add')}${rotulo}</a>`;
  }

  /** O que mudou entre o registro antigo e o novo — é isto que vai para a auditoria. */
  private diff(antes: Dict, depois: Dict): Dict | unknown[] {
    const mudou: Dict = {};
    for (const [campo, novo] of Object.entries(depois)) {
      if (!Object.prototype.hasOwnProperty.call(antes, campo)) {
        continue;
      }
      const velho = antes[campo];
      if (s(velho) !== s(novo)) {
        mudou[campo] = { de: velho, para: novo };
      }
    }
    // Array PHP vazio vira `[]` no json_encode (não `{}`): mesma forma na meta da auditoria.
    return Object.keys(mudou).length > 0 ? mudou : [];
  }
}
