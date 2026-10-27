/**
 * Tela MAPAS do painel: /admin/mapas — o App\Controllers\Admin\MapsController do PHP.
 *
 * Governa `maps` (regiões do mundo persistente e mapas de partida) e os `map_markers` de cada
 * um. É a tela que decide o que o jogador vê no Mapa do Mundo — o que existe, onde fica, o
 * que está bloqueado e qual level da Unreal cada mapa carrega.
 *
 * TRÊS DECISÕES QUE EXPLICAM O CÓDIGO INTEIRO (iguais ao PHP):
 *
 * 1. `meta` nunca é substituído, é MESCLADO. A coluna é JSON livre e o jogo evolui: o que o
 *    formulário conhece é reescrito, e o resto do JSON volta intacto — a tela até diz quantas
 *    chaves ela está preservando sem entender.
 * 2. `meta.info` é DERIVADO, não digitado: as linhas do painel INFORMAÇÕES da região são
 *    reconstruídas a cada salvamento a partir dos campos estruturados; linha extra sobrevive.
 * 3. Validação NÃO redireciona: redesenha a tela com status 422 e com o que foi digitado.
 *
 * Excluir tem confirmação SERVIDA PELO SERVIDOR (?excluir=mapa | ?excluir=marcador&mk=N), e
 * não um confirm() de JavaScript (a CSP proíbe script inline).
 *
 * DIFERENÇAS PARA O PHP:
 *   - Request::all() (corpo cru com arrays aninhados `mk[12][kind]`, `ex[0][name]`) não existe
 *     no AppRequest: as linhas são lidas campo a campo com req.input('mk[<id>][kind]'). Para a
 *     grade de marcadores os ids vêm do banco (só linhas de marcadores que existem contam,
 *     como no PHP); para as extrações, os índices 0..LIMITE_EXTRACOES-1. Consequência: o valor
 *     que volta para a tela num 422 chega aparado (o PHP devolvia o texto cru).
 *   - Cada gravação aqui é um único statement (o PHP também não usava transação nesta tela).
 */
import * as GameRules from '../../core/game-rules';
import { dataMock, flash, icon, num, url } from '../../core/helpers';
import { html, type SafeHtml } from '../../core/html';
import type { AppRequest } from '../../core/request';
import type { AppResponse } from '../../core/response';
import type { Params } from '../../core/router';
import type { Dict } from '../../core/view';
import { corta } from '../../repo/admin-repo';
import * as MapsRepo from '../../repo/admin-maps-repo';
import { adminMapas } from '../../views/pages/admin-mapas';
import { AdminController } from './admin-controller';

const POR_PAGINA = 20;

/** Coordenada de marcador: 0..1 com 4 casas, que é o que `map_markers.x/y` guarda. */
const COORD_CASAS = 4;

/** Quantos índices `ex[i]` são lidos do POST (a tela desenha as existentes + 2 em branco). */
const LIMITE_EXTRACOES = 100;

type Erros = Record<string, string>;

// ------------------------------------------------------------------ conversões do PHP

const tem = (o: unknown, k: string): boolean =>
  o !== null && typeof o === 'object' && Object.prototype.hasOwnProperty.call(o, k);

/** (string) do PHP: null -> '', true -> '1', false -> ''. Arrays/objetos -> ''. */
const str = (v: unknown): string => {
  if (v === null || v === undefined || v === false) return '';
  if (v === true) return '1';
  if (typeof v === 'object') return '';
  return String(v);
};

/** (bool) do PHP, com padrão para ausente (o `?? def`). */
const bool = (v: unknown, def = false): boolean => {
  if (v === null || v === undefined) return def;
  if (v === false || v === 0 || v === '' || v === '0') return false;
  if (Array.isArray(v)) return v.length > 0;
  return true;
};

/** (int) do PHP para uma string: prefixo numérico, senão 0. */
const intPhp = (v: unknown): number => {
  if (typeof v === 'number') return Math.trunc(v);
  const n = Number.parseInt(String(v ?? '').trim(), 10);
  return Number.isNaN(n) ? 0 : n;
};

/** is_array() + cópia rasa: objeto/array do JSON decodificado, ou {}. */
const arr = (v: unknown): Dict => (v !== null && typeof v === 'object' ? (v as Dict) : {});

/** foreach de um array PHP (lista ou associativo). */
const valores = (v: unknown): unknown[] => {
  if (Array.isArray(v)) return v;
  if (v !== null && typeof v === 'object') return Object.values(v as Dict);
  return v === null || v === undefined ? [] : [v];
};

/** is_numeric() do PHP. */
const numerico = (v: string): boolean => /^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?\s*$/.test(v);

/** number_format($v, 4, '.', '') — arredonda metade para longe do zero, como o round() do PHP. */
const coordTxt = (v: number, sep = '.'): string => {
  const f = 10 ** COORD_CASAS;
  const r = (Math.sign(v) * Math.round(Math.abs(v) * f + 1e-7)) / f;
  return r.toFixed(COORD_CASAS).replace('.', sep);
};

/** json_encode(JSON_UNESCAPED_UNICODE|SLASHES). Vazio vira "[]", como o array vazio do PHP. */
const json = (v: unknown): string => {
  if (Array.isArray(v) ? v.length === 0 : v !== null && typeof v === 'object' && Object.keys(v).length === 0) return '[]';
  return JSON.stringify(v);
};

const vazio = (o: Dict): boolean => (Array.isArray(o) ? o.length === 0 : Object.keys(o).length === 0);

export class AdminMapsController extends AdminController {
  async index(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    return this.tela(req);
  }

  /**
   * Único destino de escrita da tela (POST /admin/mapas/salvar). O campo `acao` diz qual
   * dos três formulários chegou — o cadastro do mapa, a grade de marcadores ou a linha de
   * marcador novo.
   */
  async salvar(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    switch (str(req.input('acao', ''))) {
      case 'mapa': return this.salvarMapa(req);
      case 'marcadores': return this.salvarMarcadores(req);
      case 'marcador_novo': return this.criarMarcador(req);
      default: return this.voltar('Ação desconhecida — nada foi alterado.', 'error');
    }
  }

  async excluir(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    switch (str(req.input('alvo', ''))) {
      case 'mapa': return this.excluirMapa(req);
      case 'marcador': return this.excluirMarcador(req);
      default: return this.voltar('Ação desconhecida — nada foi excluído.', 'error');
    }
  }

  // =====================================================================================
  // ESCRITA
  // =====================================================================================

  private async salvarMapa(req: AppRequest): Promise<AppResponse> {
    const id = intPhp(req.input('id', '0'));
    const atual = id > 0 ? await MapsRepo.porId(id) : null;
    if (id > 0 && atual === null) {
      return this.voltar('Esse mapa não existe mais — alguém pode tê-lo excluído.', 'error');
    }

    const form = this.formDoPost(req);
    const erros = await this.validarMapa(form, id);

    if (Object.keys(erros).length > 0) {
      // 422 e não 200: a resposta é a MESMA tela, mas o pedido não foi aceito.
      return this.tela(req, { form, erros, mapa: atual, edicao: true }, 422);
    }

    const metaAntes = atual !== null ? arr(atual.meta_arr) : {};
    const meta = this.montarMeta(form, metaAntes);

    const dados: Dict = {
      slug: form.slug,
      name: form.name,
      kind: form.kind,
      mode: form.mode !== '' ? form.mode : null,
      players: form.players !== '' ? form.players : null,
      level_min: form.level_min !== '' ? intPhp(form.level_min) : null,
      level_max: form.level_max !== '' ? intPhp(form.level_max) : null,
      locked: form.locked ? 1 : 0,
      image: form.image !== '' ? form.image : null,
      description: form.description !== '' ? form.description : null,
      unreal_level: form.unreal_level !== '' ? form.unreal_level : null,
      sort_order: intPhp(form.sort_order),
      meta: vazio(meta) ? null : json(meta),
    };

    let novoId = id;
    if (id > 0) {
      await MapsRepo.atualizar(id, dados);
      await this.auditar(
        'mapa_editado',
        'map',
        id,
        'Mapa "' + str(form.name) + '" atualizado.',
        { slug: form.slug, campos: this.diferencas(atual ?? {}, dados) },
      );
      flash('ok', 'Mapa "' + str(form.name) + '" salvo.');
    } else {
      novoId = await MapsRepo.criar(dados);
      await this.auditar('mapa_criado', 'map', novoId, 'Mapa "' + str(form.name) + '" criado.', { slug: form.slug, kind: form.kind });
      flash('ok', 'Mapa "' + str(form.name) + '" criado. Agora ele já pode receber marcadores.');
    }

    return this.redirect('admin/mapas', { id: novoId });
  }

  /**
   * Grade de marcadores: N linhas de uma vez (acertar coordenada é trabalho comparativo —
   * mexe-se em vários olhando a posição relativa entre eles e salva-se o conjunto).
   */
  private async salvarMarcadores(req: AppRequest): Promise<AppResponse> {
    const mapId = intPhp(req.input('id', '0'));
    const mapa = await MapsRepo.porId(mapId);
    if (mapa === null) {
      return this.voltar('Esse mapa não existe mais.', 'error');
    }

    const atuais = new Map<number, Dict>();
    for (const m of await MapsRepo.marcadores(mapId)) atuais.set(Number(m.id) | 0, m);

    // O PHP percorria o `mk` cru do POST; aqui as linhas são procuradas pelos ids que existem
    // (linha de marcador que sumiu entre a leitura e o envio é ignorada do mesmo jeito).
    const enviados: Record<string, Dict> = {};
    for (const mid of atuais.keys()) {
      const linha = this.linhaDoPost(req, `mk[${mid}]`, ['kind', 'label', 'x', 'y', 'color', 'anchor']);
      if (linha !== null) enviados[String(mid)] = linha;
    }

    const erros: Erros = {};
    const prontos = new Map<number, Record<string, string>>();
    for (const [chave, linha] of Object.entries(enviados)) {
      const mid = intPhp(chave);
      const campo = this.marcadorDoPost(linha);
      for (const [c, msg] of Object.entries(this.validarMarcador(campo))) {
        erros['mk.' + mid + '.' + c] = msg;
      }
      prontos.set(mid, campo);
    }

    if (Object.keys(erros).length > 0) {
      return this.tela(req, { erros, mk_post: enviados, mapa, edicao: true }, 422);
    }

    let mudados = 0;
    for (const [mid, campo] of prontos) {
      const antes = atuais.get(mid)!;
      const novo = this.dadosMarcador(campo, arr(antes.meta_arr));
      if (this.marcadorIgual(antes, novo)) {
        continue; // não gasta UPDATE nem linha de auditoria por linha intocada
      }
      await MapsRepo.atualizarMarcador(mid, novo);
      mudados++;
    }

    if (mudados === 0) {
      flash('info', 'Nenhuma alteração nos marcadores — nada foi gravado.');
    } else {
      await this.auditar(
        'marcadores_editados',
        'map',
        mapId,
        mudados + ' marcador(es) de "' + str(mapa.name) + '" atualizado(s).',
        { mapa: str(mapa.slug), quantidade: mudados },
      );
      flash('ok', mudados === 1 ? '1 marcador atualizado.' : mudados + ' marcadores atualizados.');
    }

    return this.redirect('admin/mapas', { id: mapId });
  }

  private async criarMarcador(req: AppRequest): Promise<AppResponse> {
    const mapId = intPhp(req.input('id', '0'));
    const mapa = await MapsRepo.porId(mapId);
    if (mapa === null) {
      return this.voltar('Esse mapa não existe mais.', 'error');
    }

    const linha = this.linhaDoPost(req, 'novo', ['kind', 'label', 'x', 'y', 'color', 'anchor']) ?? {};
    const campo = this.marcadorDoPost(linha);
    const erros: Erros = {};
    for (const [c, msg] of Object.entries(this.validarMarcador(campo))) {
      erros['novo.' + c] = msg;
    }

    if (Object.keys(erros).length > 0) {
      return this.tela(req, { erros, novo_post: campo, mapa, edicao: true }, 422);
    }

    const novoId = await MapsRepo.criarMarcador(mapId, this.dadosMarcador(campo, {}));
    await this.auditar(
      'marcador_criado',
      'map_marker',
      novoId,
      'Marcador "' + (campo.label !== '' ? campo.label : campo.kind) + '" criado em "' + str(mapa.name) + '".',
      { mapa: str(mapa.slug), kind: campo.kind, x: campo.x, y: campo.y },
    );
    flash('ok', 'Marcador adicionado a "' + str(mapa.name) + '".');

    return this.redirect('admin/mapas', { id: mapId });
  }

  private async excluirMapa(req: AppRequest): Promise<AppResponse> {
    const id = intPhp(req.input('id', '0'));
    const mapa = await MapsRepo.porId(id);
    if (mapa === null) {
      return this.voltar('Esse mapa já não existe.', 'error');
    }

    // Segunda checagem de vínculo, mesmo com a tela de confirmação já tendo feito a
    // primeira: entre ver a confirmação e clicar, uma partida pode ter sido registrada.
    const vinculos = await MapsRepo.vinculos(id);
    if (Object.keys(vinculos).length > 0) {
      flash('error', 'Este mapa não pode ser excluído: ' + this.frasePorVinculo(vinculos));
      return this.redirect('admin/mapas', { id });
    }

    const marcadores = intPhp(mapa.marcadores);
    await MapsRepo.excluir(id);
    await this.auditar(
      'mapa_excluido',
      'map',
      id,
      'Mapa "' + str(mapa.name) + '" excluído com ' + marcadores + ' marcador(es).',
      { slug: str(mapa.slug), kind: str(mapa.kind), marcadores },
    );
    flash('ok', 'Mapa "' + str(mapa.name) + '" excluído' + (marcadores > 0 ? ' junto com ' + marcadores + ' marcador(es).' : '.'));

    return this.redirect('admin/mapas');
  }

  private async excluirMarcador(req: AppRequest): Promise<AppResponse> {
    const mid = intPhp(req.input('marcador_id', '0'));
    const mk = await MapsRepo.marcador(mid);
    if (mk === null) {
      return this.voltar('Esse marcador já não existe.', 'error');
    }
    const mapId = intPhp(mk.map_id);

    await MapsRepo.excluirMarcador(mid);
    await this.auditar(
      'marcador_excluido',
      'map_marker',
      mid,
      'Marcador "' + str(mk.label ?? mk.kind) + '" excluído.',
      { map_id: mapId, kind: str(mk.kind), x: Number(mk.x), y: Number(mk.y) },
    );
    flash('ok', 'Marcador excluído.');

    return this.redirect('admin/mapas', { id: mapId });
  }

  // =====================================================================================
  // LEITURA / MONTAGEM DA TELA
  // =====================================================================================

  /**
   * A tela inteira, em dois modos: LISTA (sem ?id) e EDITOR (com ?id, ou ?novo=1).
   */
  private async tela(req: AppRequest, over: Dict = {}, status = 200): Promise<AppResponse> {
    const cromo = arr(dataMock('admin-mapas'));
    const par = (k: string): string | null => req.query(k) ?? req.input(k);

    const filtros: Dict = {
      busca: corta(str(par('busca') ?? ''), 80),
      tipo: str(par('tipo') ?? ''),
      estado: str(par('estado') ?? ''),
      ordem: str(req.query('ordem') ?? 'ordem'),
      dir: str(req.query('dir') ?? 'asc'),
      pagina: intPhp(req.query('pagina') ?? 1),
      por_pagina: POR_PAGINA,
    };

    let novo = par('novo') === '1';
    let mapa = (over.mapa ?? null) as Dict | null;
    if (mapa === null && !novo) {
      const idUrl = intPhp(par('id') ?? 0);
      mapa = idUrl > 0 ? await MapsRepo.porId(idUrl) : null;
    }
    // `edicao` é a chave que uma ação de ESCRITA recusada usa para dizer "volte para o
    // editor". Sem ela, o erro de validação de um mapa NOVO caía na lista.
    if (bool(over.edicao) && mapa === null) {
      novo = true;
    }
    const edicao = novo || mapa !== null;

    const [resumo, marcadorTipos] = await Promise.all([MapsRepo.resumo(), this.catalogoDeMarcadores(cromo)]);

    const dados: Dict = {
      cromo,
      filtros,
      resumo,
      modo: edicao ? 'editor' : 'lista',
      novo,
      mapa,
      erros: arr(over.erros),
      tipos: MapsRepo.TIPOS,

      // catálogos de regra do jogo — a tela desenha os <select> a partir daqui
      extracao_tipos: GameRules.EXTRACTION_TYPES,
      extracao_estados: GameRules.EXTRACTION_STATES,
      atividades: GameRules.ACTIVITY,
      capacidade_padrao: GameRules.REGION_CAPACITY,
      marcador_tipos: marcadorTipos,
    };

    if (!edicao) {
      Object.assign(dados, await MapsRepo.listar(filtros));
      dados.filtrando = filtros.busca !== '' || filtros.tipo !== '' || filtros.estado !== '';
    } else {
      const mapaId = mapa !== null ? intPhp(mapa.id) : 0;

      // Em erro de validação o formulário volta com o que foi DIGITADO; fora disso,
      // com o que está gravado.
      const form = over.form !== undefined && over.form !== null && typeof over.form === 'object'
        ? (over.form as Dict)
        : this.formDoBanco(mapa);
      const [marcadores, vinculos] = await Promise.all([
        mapaId > 0 ? MapsRepo.marcadores(mapaId) : Promise.resolve([] as Dict[]),
        mapaId > 0 ? MapsRepo.vinculos(mapaId) : Promise.resolve({} as Record<string, number>),
      ]);
      dados.form = form;
      dados.marcadores = marcadores;
      dados.mk_post = arr(over.mk_post);
      dados.novo_post = arr(over.novo_post);
      dados.vinculos = vinculos;
      dados.vinculo_txt = Object.keys(vinculos).length > 0 ? this.frasePorVinculo(vinculos) : '';
      dados.confirmar = this.confirmacao(req, mapa, marcadores);
      dados.meta_extra = this.chavesPreservadas(mapa, form);
    }

    const page = this.adminPage('mapas', 'Mapas', this.subtitulo(dados), { actions: this.acoesDoTopo(dados) });

    return this.render(adminMapas, page, { ...dados, admin_kpis: this.kpis(resumo) }, 'admin', status);
  }

  /**
   * Passo de confirmação de exclusão. Vive na URL (?excluir=mapa | ?excluir=marcador&mk=N)
   * porque assim ele é um ESTADO da tela: sobrevive a um F5 e dá para desistir voltando.
   */
  private confirmacao(req: AppRequest, mapa: Dict | null, marcadores: Dict[]): Dict | null {
    const alvo = str(req.query('excluir') ?? '');
    if (mapa === null || (alvo !== 'mapa' && alvo !== 'marcador')) {
      return null;
    }

    if (alvo === 'mapa') {
      return { tipo: 'mapa', id: intPhp(mapa.id), nome: str(mapa.name), marcadores: marcadores.length };
    }

    const mid = intPhp(req.query('mk') ?? 0);
    for (const m of marcadores) {
      if (intPhp(m.id) === mid) {
        return {
          tipo: 'marcador',
          id: mid,
          nome: str(m.label ?? '') !== '' ? str(m.label) : str(m.kind),
          kind: str(m.kind),
          x: Number(m.x),
          y: Number(m.y),
        };
      }
    }
    return null;
  }

  private subtitulo(dados: Dict): string {
    if (str(dados.modo) === 'editor') {
      if (bool(dados.novo)) {
        return 'Novo mapa';
      }
      const m = arr(dados.mapa);
      return str(m.slug).trim() + ' · ' + intPhp(m.marcadores ?? 0) + ' marcador(es)';
    }
    const r = arr(dados.resumo);
    return str(r.regioes ?? 0) + ' região(ões), ' + str(r.partidas ?? 0) + ' mapa(s) de partida e ' + str(r.marcadores ?? 0) + ' marcadores';
  }

  /** Botões da topbar. */
  private acoesDoTopo(dados: Dict): SafeHtml {
    if (str(dados.modo) === 'editor') {
      return html`<a class="adm-btn adm-btn--ghost" href="${url('admin/mapas')}">${icon('arrow_back')}<span>Voltar à lista</span></a>`;
    }
    return html`<a class="adm-btn adm-btn--primary" href="${url('admin/mapas', { novo: 1 })}">${icon('add_location_alt')}<span>Novo mapa</span></a>`;
  }

  /** KPIs da topbar — todos saem de COUNT, nenhum é estimativa. */
  private kpis(r: MapsRepo.Resumo): Dict[] {
    return [
      { icon: 'public', label: 'Regiões do mundo', value: num(r.regioes), tone: 'lime' },
      { icon: 'stadium', label: 'Mapas de partida', value: num(r.partidas), tone: 'cyan' },
      { icon: 'place', label: 'Marcadores', value: num(r.marcadores), tone: 'amber' },
    ];
  }

  // =====================================================================================
  // FORMULÁRIO DO MAPA
  // =====================================================================================

  /** Linha do banco -> campos do formulário (inclusive o `meta` aberto em campos). */
  private formDoBanco(mapa: Dict | null): Dict {
    const m = mapa ?? {};
    const meta = arr(m.meta_arr);
    const cap = arr(meta.capacity);
    const ev = arr(meta.event);

    const extracoes: Dict[] = [];
    for (const x0 of valores(meta.extractions)) {
      if (x0 === null || typeof x0 !== 'object') continue;
      const x = x0 as Dict;
      extracoes.push({
        name: str(x.name ?? ''),
        type: str(x.type ?? 'publica'),
        state: str(x.state ?? 'active'),
      });
    }

    return {
      id: intPhp(m.id ?? 0),
      slug: str(m.slug ?? ''),
      name: str(m.name ?? ''),
      kind: str(m.kind ?? 'region'),
      mode: str(m.mode ?? ''),
      players: str(m.players ?? ''),
      level_min: str(m.level_min ?? ''),
      level_max: str(m.level_max ?? ''),
      locked: bool(m.locked, false),
      image: str(m.image ?? ''),
      description: str(m.description ?? ''),
      unreal_level: str(m.unreal_level ?? ''),
      sort_order: str(m.sort_order ?? '0'),

      chave: str(meta.key ?? ''),
      icone: str(meta.icon ?? ''),
      nivel_txt: str(meta.level ?? ''),
      clima: str(meta.weather ?? ''),
      setor: str(meta.sector_state ?? ''),
      persistente: bool(meta.persistent, true),
      cap_total: str(cap.total ?? ''),
      cap_hunters: str(cap.hunters ?? ''),
      cap_merc: str(cap.mercenaries ?? ''),
      infestacao: str(meta.infestation ?? ''),
      veyra: str(meta.veyra_activity ?? ''),
      humana: str(meta.human_activity ?? ''),
      extracoes,
      evento_nome: str(ev.name ?? ''),
      evento_prazo: str(ev.ends_in ?? ''),
      evento_rotulo: str(ev.ends_label ?? ''),
      tamanho: str(meta.size ?? ''),
      bioma: str(meta.biome ?? ''),
      recorde: str(meta.best ?? ''),
      recorde_por: str(meta.best_by ?? ''),
    };
  }

  /**
   * POST -> campos do formulário. Nenhum valor é convertido para o tipo final aqui: o que
   * o operador digitou tem que poder voltar para a tela exatamente como ele digitou.
   */
  private formDoPost(req: AppRequest): Dict {
    const ex: Dict[] = [];
    for (let i = 0; i < LIMITE_EXTRACOES; i++) {
      const linha = this.linhaDoPost(req, `ex[${i}]`, ['name', 'type', 'state']);
      if (linha === null) continue;
      const nome = str(linha.name ?? '').trim();
      if (nome === '') {
        continue; // linha em branco é como se remove uma extração
      }
      ex.push({
        name: corta(nome, 80),
        type: str(linha.type ?? 'publica'),
        state: str(linha.state ?? 'active'),
      });
    }

    const t = (k: string, max: number): string => corta(str(req.input(k, '')), max);
    return {
      id: intPhp(req.input('id', '0')),
      slug: t('slug', 64).toLowerCase(),
      name: t('name', 80),
      kind: str(req.input('kind', 'region')),
      mode: t('mode', 40),
      players: t('players', 16),
      level_min: str(req.input('level_min', '')),
      level_max: str(req.input('level_max', '')),
      locked: req.input('locked') === '1',
      image: t('image', 160),
      description: t('description', 400),
      unreal_level: t('unreal_level', 200),
      sort_order: str(req.input('sort_order', '0')),

      chave: t('chave', 64),
      icone: t('icone', 32),
      nivel_txt: t('nivel_txt', 16),
      clima: t('clima', 32),
      setor: t('setor', 24),
      persistente: req.input('persistente') === '1',
      cap_total: str(req.input('cap_total', '')),
      cap_hunters: str(req.input('cap_hunters', '')),
      cap_merc: str(req.input('cap_merc', '')),
      infestacao: str(req.input('infestacao', '')),
      veyra: str(req.input('veyra', '')),
      humana: str(req.input('humana', '')),
      extracoes: ex,
      evento_nome: t('evento_nome', 80),
      evento_prazo: t('evento_prazo', 24),
      evento_rotulo: t('evento_rotulo', 32),
      tamanho: t('tamanho', 24),
      bioma: t('bioma', 60),
      recorde: t('recorde', 16),
      recorde_por: t('recorde_por', 40),
    };
  }

  /** Validação do cadastro. Devolve `campo => mensagem`. */
  private async validarMapa(f: Dict, id: number): Promise<Erros> {
    const e: Erros = {};

    const slug = str(f.slug);
    if (slug === '') {
      e.slug = 'Informe o identificador.';
    } else if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)) {
      e.slug = 'Só minúsculas, números e hífen entre eles (ex.: regiao-floresta-verde).';
    } else if (await MapsRepo.slugEmUso(slug, id)) {
      e.slug = 'Já existe outro mapa com este identificador.';
    }

    if (str(f.name).trim() === '') {
      e.name = 'Informe o nome que o jogador vê.';
    }

    if (!tem(MapsRepo.TIPOS, str(f.kind))) {
      e.kind = 'Escolha entre região do mundo e mapa de partida.';
    }

    for (const [campo, rotulo] of [['level_min', 'Nível mínimo'], ['level_max', 'Nível máximo']] as const) {
      const v = str(f[campo]);
      if (v === '') continue;
      if (!/^\d{1,4}$/.test(v) || intPhp(v) > 999) {
        e[campo] = rotulo + ' deve ser um número de 0 a 999.';
      }
    }
    // `!isset($e['level_min'], $e['level_max'])` do PHP: verdadeiro se QUALQUER um faltar.
    if (!(tem(e, 'level_min') && tem(e, 'level_max'))
      && str(f.level_min) !== '' && str(f.level_max) !== ''
      && intPhp(f.level_max) < intPhp(f.level_min)) {
      e.level_max = 'O nível máximo não pode ser menor que o mínimo.';
    }

    const ordem = str(f.sort_order);
    if (ordem !== '' && (!/^\d{1,5}$/.test(ordem) || intPhp(ordem) > 65535)) {
      e.sort_order = 'A ordem é um número inteiro de 0 a 65535.';
    }

    // A imagem é caminho relativo dentro do projeto: absoluto ou com ".." nunca resolveria.
    const img = str(f.image);
    if (img !== '' && (img.includes('..') || /^([a-z]+:)?\/\//i.test(img) || img.startsWith('/'))) {
      e.image = 'Use um caminho relativo, sem "..", sem barra inicial e sem http://.';
    }

    const ue = str(f.unreal_level);
    if (ue !== '' && !/^[A-Za-z0-9_/\-.]+$/.test(ue)) {
      e.unreal_level = 'Caminho de level inválido — use só letras, números, "/", "_", "-" e ".".';
    }

    if (str(f.kind) === 'region') {
      for (const [campo, rotulo] of [['cap_total', 'A capacidade total'], ['cap_hunters', 'A cota de Hunters'], ['cap_merc', 'A cota de Mercenários']] as const) {
        const v = str(f[campo]);
        if (v !== '' && (!/^\d{1,3}$/.test(v) || intPhp(v) > 200)) {
          e[campo] = rotulo + ' deve ser um número de 0 a 200.';
        }
      }
      if (!(tem(e, 'cap_total') && tem(e, 'cap_hunters') && tem(e, 'cap_merc'))
        && str(f.cap_total) !== '' && str(f.cap_hunters) !== '' && str(f.cap_merc) !== ''
        && intPhp(f.cap_hunters) + intPhp(f.cap_merc) > intPhp(f.cap_total)) {
        e.cap_total = 'Hunters + Mercenários (' + (intPhp(f.cap_hunters) + intPhp(f.cap_merc)) + ') passa da capacidade total.';
      }

      const inf = str(f.infestacao);
      if (inf !== '' && (!/^\d{1,3}$/.test(inf) || intPhp(inf) > 100)) {
        e.infestacao = 'A infestação vai de 0 a 100.';
      }

      for (const [campo, rotulo] of [['veyra', 'Veyra'], ['humana', 'humana']] as const) {
        const v = str(f[campo]).trim().toUpperCase();
        if (v !== '' && !tem(GameRules.ACTIVITY, v)) {
          e[campo] = 'Degrau de atividade ' + rotulo + ' desconhecido.';
        }
      }

      (f.extracoes as Dict[]).forEach((x, i) => {
        if (!tem(GameRules.EXTRACTION_TYPES, str(x.type))) {
          e['ex.' + i + '.type'] = 'Tipo de extração desconhecido.';
        }
        if (!tem(GameRules.EXTRACTION_STATES, str(x.state))) {
          e['ex.' + i + '.state'] = 'Estado de extração desconhecido.';
        }
      });

      if (str(f.evento_nome) === '' && str(f.evento_prazo) !== '') {
        e.evento_nome = 'Um prazo sem nome de evento não aparece no jogo. Dê um nome ou limpe o prazo.';
      }
    }

    return e;
  }

  /** Campos do formulário -> `maps.meta`, PRESERVANDO o que veio do banco. */
  private montarMeta(f: Dict, antes: Dict): Dict {
    // Cópia rasa (o array do PHP é por valor).
    const meta: Dict = { ...antes };

    const porTexto = (chave: string, valor: string): void => {
      if (valor === '') delete meta[chave];
      else meta[chave] = valor;
    };

    porTexto('icon', str(f.icone));

    if (str(f.kind) === 'region') {
      porTexto('key', str(f.chave) !== '' ? str(f.chave) : str(f.slug).replace(/^regiao-/, ''));
      porTexto('level', str(f.nivel_txt));
      porTexto('weather', str(f.clima));
      porTexto('sector_state', str(f.setor).toUpperCase());
      meta.persistent = bool(f.persistente);

      if (str(f.cap_total) !== '' || str(f.cap_hunters) !== '' || str(f.cap_merc) !== '') {
        const padrao = GameRules.REGION_CAPACITY;
        meta.capacity = {
          total: str(f.cap_total) !== '' ? intPhp(f.cap_total) : padrao.total,
          hunters: str(f.cap_hunters) !== '' ? intPhp(f.cap_hunters) : padrao.hunters,
          mercenaries: str(f.cap_merc) !== '' ? intPhp(f.cap_merc) : padrao.mercenaries,
        };
      }

      // A infestação é gravada como string ("42"), como o banco já tem hoje.
      porTexto('infestation', str(f.infestacao));
      porTexto('veyra_activity', str(f.veyra).toUpperCase());
      porTexto('human_activity', str(f.humana).toUpperCase());

      meta.extractions = (f.extracoes as Dict[]).map((x) => {
        const estado = str(x.state);
        return {
          name: str(x.name),
          type: str(x.type),
          state: estado,
          // `status` é o rótulo que o jogo IMPRIME: sai da regra, não da mão do operador.
          status: str(GameRules.EXTRACTION_STATES[estado]?.label ?? 'ATIVA'),
        };
      });

      if (str(f.evento_nome) !== '') {
        const ev: Dict = {};
        const nome = str(f.evento_nome);
        const prazo = str(f.evento_prazo);
        const rotulo = str(f.evento_rotulo) !== '' ? str(f.evento_rotulo) : 'TERMINA EM';
        if (nome !== '') ev.name = nome;
        if (prazo !== '') ev.ends_in = prazo;
        if (rotulo !== '') ev.ends_label = rotulo;
        meta.event = ev;
      } else {
        meta.event = null;
      }

      meta.info = this.linhasDeInfo(f, valores(arr(antes).info));
    } else {
      porTexto('size', str(f.tamanho).toUpperCase());
      porTexto('biome', str(f.bioma));
      porTexto('weather', str(f.clima));
      porTexto('best', str(f.recorde));
      porTexto('best_by', str(f.recorde_por));
      porTexto('infestation', str(f.infestacao));
      porTexto('human_activity', str(f.humana).toUpperCase());
    }

    return meta;
  }

  /**
   * As linhas do painel INFORMAÇÕES da região, reconstruídas a partir dos campos
   * estruturados. Linhas de rótulo desconhecido que já existiam são mantidas depois delas.
   */
  private linhasDeInfo(f: Dict, antes: unknown[]): Dict[] {
    const cap = GameRules.REGION_CAPACITY;
    const total = str(f.cap_total) !== '' ? intPhp(f.cap_total) : cap.total;
    const hun = str(f.cap_hunters) !== '' ? intPhp(f.cap_hunters) : cap.hunters;
    const mer = str(f.cap_merc) !== '' ? intPhp(f.cap_merc) : cap.mercenaries;

    // O ícone do clima acompanha a palavra; sem correspondência fica o genérico.
    const climas: Array<[string, string]> = [
      ['neblina', 'foggy'], ['chuva', 'rainy'], ['nevasca', 'weather_snowy'],
      ['neve', 'weather_snowy'], ['nublado', 'cloud'], ['limpo', 'clear_day'],
      ['tempestade', 'thunderstorm'], ['variável', 'partly_cloudy_day'],
    ];
    const climaTxt = str(f.clima);
    let climaIco = 'partly_cloudy_day';
    for (const [palavra, ico] of climas) {
      if (climaTxt !== '' && climaTxt.toLowerCase().includes(palavra)) {
        climaIco = ico;
        break;
      }
    }

    const linhas: Dict[] = [];
    if (str(f.nivel_txt) !== '') {
      linhas.push({ label: 'NÍVEL RECOMENDADO', value: str(f.nivel_txt), cls: 'lime' });
    }
    linhas.push({
      label: 'CAPACIDADE OPERACIONAL',
      value: String(total),
      tip: 'Até ' + hun + ' Hunters e ' + mer + ' Mercenários. Sobreviventes ocupam vagas existentes.',
    });
    if (climaTxt !== '') {
      linhas.push({ label: 'CLIMA ATUAL', value: climaTxt, icon: climaIco });
    }
    if (bool(f.persistente)) {
      linhas.push({ label: 'MUNDO', value: 'PERSISTENTE', cls: 'lime' });
    }

    const conhecidos = ['NÍVEL RECOMENDADO', 'CAPACIDADE OPERACIONAL', 'CLIMA ATUAL', 'MUNDO'];
    for (const linha of antes) {
      if (linha !== null && typeof linha === 'object' && !conhecidos.includes(str((linha as Dict).label ?? '').toUpperCase())) {
        linhas.push(linha as Dict);
      }
    }
    return linhas;
  }

  /** Chaves de `meta` que o formulário NÃO edita e vai devolver intactas ao banco. */
  private chavesPreservadas(mapa: Dict | null, form: Dict): string[] {
    const meta = arr(mapa?.meta_arr);
    const editadas = str(form.kind ?? 'region') === 'region'
      ? ['key', 'icon', 'level', 'weather', 'sector_state', 'persistent', 'capacity',
        'infestation', 'veyra_activity', 'human_activity', 'extractions', 'event', 'info']
      : ['icon', 'size', 'biome', 'weather', 'best', 'best_by', 'infestation', 'human_activity'];

    return Object.keys(meta).filter((k) => !editadas.includes(k));
  }

  // =====================================================================================
  // MARCADORES
  // =====================================================================================

  /**
   * Uma linha da grade, ainda como texto. `x`/`y` aceitam vírgula: o operador digita num
   * teclado brasileiro e "0,4980" é o que ele escreve naturalmente.
   */
  private marcadorDoPost(linha: Dict): Record<string, string> {
    const txt = (v: unknown, max: number): string =>
      corta(['string', 'number', 'boolean'].includes(typeof v) ? str(v).trim() : '', max);

    return {
      kind: txt(linha.kind ?? '', 24).toLowerCase(),
      label: txt(linha.label ?? '', 80),
      x: txt(linha.x ?? '', 12).replace(/,/g, '.'),
      y: txt(linha.y ?? '', 12).replace(/,/g, '.'),
      color: txt(linha.color ?? '', 16).toLowerCase(),
      anchor: txt(linha.anchor ?? '', 8).toLowerCase(),
    };
  }

  private validarMarcador(c: Record<string, string>): Erros {
    const e: Erros = {};

    if (c.kind === '') {
      e.kind = 'Escolha o tipo.';
    } else if (!/^[a-z0-9_]{2,24}$/.test(c.kind!)) {
      e.kind = 'Tipo inválido.';
    }

    for (const [eixo, rotulo] of [['x', 'X'], ['y', 'Y']] as const) {
      const v = c[eixo]!;
      if (v === '') {
        e[eixo] = rotulo + ' é obrigatório.';
      } else if (!numerico(v)) {
        e[eixo] = rotulo + ' precisa ser número.';
      } else if (Number(v) < 0 || Number(v) > 1) {
        e[eixo] = rotulo + ' vai de 0 a 1.';
      }
    }

    return e;
  }

  /**
   * Linha validada -> colunas de `map_markers`, com o meta do marcador mesclado.
   * `meta.name` é sincronizado com o rótulo: o jogo lê `meta.name` ANTES de `label`.
   */
  private dadosMarcador(c: Record<string, string>, metaAntes: Dict): Dict {
    const meta: Dict = { ...metaAntes };

    if (c.color !== '') meta.color = c.color;
    else delete meta.color;

    if (c.anchor !== '') meta.label = c.anchor;
    else delete meta.label;

    if (tem(meta, 'name') || c.label !== '') {
      meta.name = c.label;
    }

    return {
      kind: c.kind,
      label: c.label !== '' ? c.label : null,
      x: coordTxt(Number(c.x)),
      y: coordTxt(Number(c.y)),
      meta: vazio(meta) ? null : json(meta),
    };
  }

  private marcadorIgual(antes: Dict, novo: Dict): boolean {
    return str(antes.kind) === str(novo.kind)
      && str(antes.label ?? '') === str(novo.label ?? '')
      && coordTxt(Number(antes.x)) === str(novo.x)
      && coordTxt(Number(antes.y)) === str(novo.y)
      && json(arr(antes.meta_arr)) === str(novo.meta ?? json([]));
  }

  /** Catálogo do <select> de tipo: o que o painel conhece, mais o que já existe no banco. */
  private async catalogoDeMarcadores(cromo: Dict): Promise<Record<string, string>> {
    const out: Record<string, string> = {};
    for (const [kind, info0] of Object.entries(arr(cromo.marcadores))) {
      const info = arr(info0);
      out[kind] = str(info.label ?? kind) + ' · ' + str(info.camada ?? '');
    }
    for (const kind of await MapsRepo.tiposDeMarcadorEmUso()) {
      if (!tem(out, kind)) {
        // Tipo que veio de patch/seed e o painel não cataloga: aparece como está, com o aviso.
        out[kind] = kind + ' · fora do catálogo';
      }
    }
    return out;
  }

  // =====================================================================================
  // APOIO
  // =====================================================================================

  /**
   * Lê `prefixo[campo]` do POST para os campos dados. null quando nenhum veio (a linha não
   * foi enviada). Substitui o `$request->all()[$chave]` do PHP — ver o topo do arquivo.
   */
  private linhaDoPost(req: AppRequest, prefixo: string, campos: string[]): Dict | null {
    const out: Dict = {};
    let achou = false;
    for (const c of campos) {
      const v = req.input(`${prefixo}[${c}]`);
      if (v !== null) {
        out[c] = v;
        achou = true;
      }
    }
    return achou ? out : null;
  }

  private frasePorVinculo(vinculos: Record<string, number>): string {
    const partes: string[] = [];
    if (vinculos.bases !== undefined) {
      partes.push(vinculos.bases + ' base(s) governamental(is) fincada(s) nele');
    }
    if (vinculos.partidas !== undefined) {
      partes.push(vinculos.partidas + ' partida(s) já registrada(s) no histórico de jogadores');
    }
    if (vinculos.safe !== undefined) {
      partes.push(vinculos.safe + ' safe zone(s) ancorada(s) nele');
    }
    return partes.join('; ') + '.';
  }

  /**
   * O que mudou entre a linha do banco e o que vai ser gravado — só os NOMES dos campos.
   */
  private diferencas(antes: Dict, depois: Dict): string[] {
    return Object.keys(depois).filter((campo) => str(antes[campo] ?? null) !== str(depois[campo]));
  }

  private voltar(msg: string, tipo = 'ok'): AppResponse {
    flash(tipo, msg);
    return this.redirect('admin/mapas');
  }
}
