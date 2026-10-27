/**
 * Tela Configurações do Jogo do painel: /admin/configuracoes — o
 * App\Controllers\Admin\SettingsController do PHP.
 *
 * Governa `admin_settings`, agrupada por `grupo` e editada com o widget do seu `kind`.
 *
 * DECISÕES (iguais ao PHP):
 * 1. UM FORMULÁRIO POR GRUPO — a menor unidade que se sustenta sozinha.
 * 2. GRAVAÇÃO ATÔMICA DENTRO DO GRUPO: ou os campos e as regras cruzadas passam todos, ou
 *    nada é escrito. O Database::transaction() do PHP vira um DB.batch() aqui.
 * 3. EXCLUIR É UM FLUXO DE DOIS PASSOS NO SERVIDOR (link -> confirmação -> POST); chave lida
 *    pelo código (CHAVES_DO_CODIGO) é recusada também no POST.
 * 4. ERROS SOBREVIVEM AO REDIRECT numa bolsa de sessão própria (não em flash()), para o
 *    formulário voltar com a caixa em vermelho e o que o operador digitou.
 */
import { ctx } from '../../core/context';
import * as DB from '../../core/database';
import { dataMock, flash, num } from '../../core/helpers';
import type { AppRequest } from '../../core/request';
import type { AppResponse } from '../../core/response';
import type { Params } from '../../core/router';
import type { Dict } from '../../core/view';
import { corta } from '../../repo/admin-repo';
import * as Cfg from '../../repo/admin-settings-repo';
import { adminConfig } from '../../views/pages/admin-config';
import { AdminController } from './admin-controller';

/** Grupos por página — a unidade de paginação é o GRUPO (um formulário não se parte). */
const GRUPOS_POR_PAGINA = 6;

/** Bolsas de sessão que atravessam o redirect depois de um POST recusado. */
const BOLSA_ERROS = '_cfg_erros';
const BOLSA_VALORES = '_cfg_valores';
const BOLSA_NOVA = '_cfg_nova';

/** Ordenações aceitas em ?ordem= — fora da lista, cai na primeira. */
const ORDENS = ['sugerida', 'chave', 'recentes'];

/** Filtros de estado aceitos em ?estado=. */
const ESTADOS = ['todos', 'alterado', 'padrao', 'sistema', 'personalizada'];

/** Comparação no estilo `<=>` do PHP entre tuplas (número, string ou bool). */
function cmp(a: Array<string | number | boolean>, b: Array<string | number | boolean>): number {
  for (let i = 0; i < a.length; i++) {
    const x = typeof a[i] === 'boolean' ? Number(a[i]) : a[i]!;
    const y = typeof b[i] === 'boolean' ? Number(b[i]) : b[i]!;
    if (x < y) return -1;
    if (x > y) return 1;
  }
  return 0;
}

/** ucfirst(str_replace('_', ' ', $g)). */
export function rotuloCru(g: string): string {
  const s = g.replace(/_/g, ' ');
  return s.charAt(0).toUpperCase() + s.slice(1);
}

export class SettingsController extends AdminController {
  // =====================================================================  leitura

  async index(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;

    const cromo = dataMock('admin-config') as Dict;
    const erros = this.tirarBolsa(BOLSA_ERROS) as Record<string, string>;
    const digitado = this.tirarBolsa(BOLSA_VALORES) as Record<string, string>;
    const nova = this.tirarBolsa(BOLSA_NOVA);
    const subtitulo = String((cromo.texto as Dict | undefined)?.subtitulo ?? '');

    let linhas: Dict[];
    let historico: Record<string, Cfg.Mudanca>;
    try {
      [linhas, historico] = await Promise.all([Cfg.todas(), Cfg.ultimasMudancas()]);
    } catch (e) {
      // Banco fora do ar é um fato a informar, não uma tela branca.
      console.error('[extracthunters] admin/configuracoes:', e);
      const page = this.adminPage('config', 'Configurações do Jogo', subtitulo);
      return this.render(adminConfig, page, {
        cromo,
        falha: 'Não foi possível ler admin_settings agora. O jogo segue com os valores já em memória.',
        campos: [],
        grupos: [],
        filtros: this.filtrosVazios(),
        resumo: { total: 0, exibidos: 0, alterados: 0, grupos: 0, ultima: null },
      });
    }

    // Todo campo é montado ANTES de filtrar: os contadores do topo descrevem a tabela inteira.
    const campos = linhas.map((l) => this.montarCampo(l, cromo, historico, erros, digitado));

    const filtros = this.lerFiltros(req, campos);
    let filtrados = this.filtrar(campos, filtros);

    // Campo recusado tem de ser visto, mesmo que o filtro em vigor o escondesse.
    if (Object.keys(erros).length > 0) filtrados = this.reintegrarInvalidos(filtrados, campos);

    const grupos = this.agrupar(filtrados, cromo, String(filtros.ordem));
    const pagina = this.paginar(grupos, Number(filtros.pagina));

    const page = this.adminPage('config', 'Configurações do Jogo', subtitulo);
    return this.render(adminConfig, page, {
      cromo,
      falha: null,
      campos,
      grupos: pagina.grupos,
      paginacao: pagina.info,
      filtros,
      resumo: this.resumo(campos, filtrados),
      nova,
      excluir: this.confirmacaoDeExclusao(req, campos),
      admin_kpis: this.kpisDaTopbar(campos),
    });
  }

  // =====================================================================  escrita

  /** Salva um grupo inteiro (acao=grupo) ou cria uma chave nova (acao=criar). */
  async salvar(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    switch (req.input('acao', 'grupo') ?? '') {
      case 'criar':
        return this.criarChave(req);
      case 'grupo':
        return this.salvarGrupo(req);
      default:
        return this.voltar('Ação desconhecida.', 'error');
    }
  }

  /** Remove uma chave criada pelo painel. Chave lida pelo código é recusada aqui também. */
  async excluir(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const chave = req.input('chave', '') ?? '';
    const cromo = dataMock('admin-config') as Dict;

    if (chave === '') return this.voltar('Nenhuma chave informada.', 'error');
    if (Cfg.protegida(chave)) {
      return this.voltar(String((cromo.texto as Dict | undefined)?.protegida ?? 'Esta chave não pode ser removida.'), 'error');
    }

    try {
      const linha = await Cfg.porChave(chave);
      if (linha === null) return this.voltar('A chave "' + chave + '" não existe mais.', 'error');
      // Sem a confirmação explícita, um POST solto apagaria a chave.
      if ((req.input('confirmar', '') ?? '') !== '1') {
        return this.redirect('admin/configuracoes', { excluir: chave });
      }

      await Cfg.remover(chave);

      // A linha inteira vai para o meta: é de lá que a chave pode ser reconstruída.
      await this.auditar(Cfg.ACAO_EXCLUIR, 'config', chave, 'Removeu a configuração ' + chave + '.', {
        grupo: String(linha.grupo),
        kind: String(linha.kind),
        label: String(linha.label),
        de: linha.value !== null && linha.value !== undefined ? String(linha.value) : null,
        para: null,
      });

      return this.voltar('Chave "' + chave + '" removida.', 'ok');
    } catch (e) {
      console.error('[extracthunters] admin/configuracoes/excluir:', e);
      return this.voltar('Não foi possível remover a chave agora.', 'error');
    }
  }

  // =====================================================  escrita — implementação

  private async salvarGrupo(req: AppRequest): Promise<AppResponse> {
    const grupo = (req.input('grupo', '') ?? '').toLowerCase();
    if (grupo === '') return this.voltar('Formulário sem grupo — nada foi gravado.', 'error');

    const cromo = dataMock('admin-config') as Dict;
    const regras = (cromo.chaves ?? {}) as Dict;

    // `campos[]` diz quais chaves o formulário DESENHOU: um interruptor desligado não é
    // enviado pelo navegador, e sem essa lista "desligar" seria igual a "não mexeu".
    const declarados = req.inputArray('campos');

    let doGrupo: Dict[];
    try {
      doGrupo = (await Cfg.todas()).filter((l) => String(l.grupo) === grupo);
    } catch (e) {
      console.error('[extracthunters] admin/configuracoes/salvar:', e);
      return this.voltar('Não foi possível ler as configurações para gravar.', 'error');
    }
    if (doGrupo.length === 0) return this.voltar('Grupo "' + grupo + '" não existe mais.', 'error');

    const erros: Record<string, string> = {};
    const digitado: Record<string, string> = {};
    const novos: Record<string, string | null> = {};
    const atuais: Record<string, Dict> = {};

    for (const linha of doGrupo) {
      const chave = String(linha.key);
      if (!declarados.includes(chave)) continue; // fora do formulário enviado: fora de escopo
      atuais[chave] = linha;

      // `campo[chave]` do POST (o mapaPost do PHP): lido pelo nome literal do campo.
      const bruto = req.input('campo[' + chave + ']', '') ?? '';
      const regra = (regras[chave] ?? {}) as Dict;

      digitado[chave] = bruto;
      const veredito = Cfg.validar(String(linha.kind), bruto, regra);
      if (!veredito.ok) {
        erros[chave] = veredito.erro;
        continue;
      }
      novos[chave] = veredito.valor;
    }

    if (Object.keys(atuais).length === 0) return this.voltar('O formulário não trouxe nenhum campo deste grupo.', 'error');

    for (const [chave, msg] of Object.entries(this.regrasCruzadas(grupo, novos, atuais))) erros[chave] = msg;

    const nErros = Object.keys(erros).length;
    if (nErros > 0) {
      this.porBolsa(BOLSA_ERROS, erros);
      this.porBolsa(BOLSA_VALORES, digitado);
      flash(
        'error',
        nErros === 1 ? 'Um campo não passou na validação — nada foi gravado.' : nErros + ' campos não passaram na validação — nada foi gravado.',
      );
      return this.redirect('admin/configuracoes', { grupo });
    }

    // Só o que mudou de fato (o gravar() do PHP devolvia false para "de 4 para 4"); tudo
    // num batch: ou o grupo inteiro grava, ou nada.
    const mudadas: Record<string, Dict> = {};
    const stmts: D1PreparedStatement[] = [];
    for (const [chave, valor] of Object.entries(novos)) {
      const atual = atuais[chave]!;
      const antes = atual.value !== null && atual.value !== undefined ? String(atual.value) : null;
      if (String(antes ?? '') === String(valor ?? '')) continue;
      stmts.push(Cfg.gravarStmt(chave, valor, this.adminId()));
      mudadas[chave] = { de: antes, para: valor, kind: String(atual.kind), grupo };
    }
    try {
      await DB.batch(stmts);
    } catch (e) {
      console.error('[extracthunters] admin/configuracoes/salvar:', e);
      return this.voltar('A gravação falhou e foi desfeita inteira. Nada mudou.', 'error');
    }

    // Uma linha de auditoria POR CHAVE: quem investiga procura por uma chave, não por uma tela.
    for (const [chave, m] of Object.entries(mudadas)) {
      await this.auditar(
        Cfg.ACAO_ALTERAR,
        'config',
        chave,
        'Alterou ' + chave + ': ' + this.resumir(m.de as string | null) + ' -> ' + this.resumir(m.para as string | null) + '.',
        m,
      );
    }

    const nomes = Object.keys(mudadas);
    if (nomes.length === 0) {
      flash('info', String((cromo.texto as Dict | undefined)?.sem_alteracao ?? 'Nada mudou.'));
    } else {
      flash('ok', nomes.length === 1 ? 'Parâmetro atualizado: ' + nomes[0] + '.' : nomes.length + ' parâmetros atualizados em ' + grupo + '.');
    }
    return this.redirect('admin/configuracoes', { grupo });
  }

  private async criarChave(req: AppRequest): Promise<AppResponse> {
    const bruto: Record<string, string> = {
      key: req.input('key', '') ?? '',
      label: req.input('label', '') ?? '',
      kind: req.input('kind', 'string') ?? '',
      grupo: req.input('grupo_novo', '') ?? '',
      value: req.input('novo[value]') ?? req.input('value', '') ?? '',
    };

    let exame: Awaited<ReturnType<typeof Cfg.validarNova>>;
    try {
      exame = await Cfg.validarNova(bruto);
    } catch (e) {
      console.error('[extracthunters] admin/configuracoes/criar:', e);
      return this.voltar('Não foi possível validar a chave agora.', 'error');
    }

    if (Object.keys(exame.erros).length > 0) {
      // O que o operador digitou volta junto com os erros.
      this.porBolsa(BOLSA_NOVA, { erros: exame.erros, valores: bruto });
      flash('error', 'A chave não foi criada: confira os campos marcados.');
      return this.redirect('admin/configuracoes', { nova: '1' });
    }

    const linha = exame.linha;
    try {
      await Cfg.criar(linha, this.adminId());
    } catch (e) {
      console.error('[extracthunters] admin/configuracoes/criar:', e);
      return this.voltar('Não foi possível criar a chave agora.', 'error');
    }

    await this.auditar(Cfg.ACAO_CRIAR, 'config', linha.key, 'Criou a configuração ' + linha.key + ' em ' + linha.grupo + '.', {
      de: null,
      para: linha.value,
      kind: linha.kind,
      grupo: linha.grupo,
      label: linha.label,
    });

    flash('ok', 'Chave "' + linha.key + '" criada em ' + linha.grupo + '.');
    return this.redirect('admin/configuracoes', { grupo: linha.grupo });
  }

  /**
   * Regras que um campo sozinho não verifica — a razão de a gravação do grupo ser atômica.
   * Só invariantes que o JOGO assume: no grupo 'mundo', o teto ≥ soma das vagas.
   */
  private regrasCruzadas(grupo: string, novos: Record<string, string | null>, atuais: Record<string, Dict>): Record<string, string> {
    if (grupo !== 'mundo') return {};

    // Referência: o que ESTÁ SENDO GRAVADO quando o campo veio, o banco quando não veio.
    const ler = (chave: string): number | null => {
      if (chave in novos) return novos[chave] !== null ? Number.parseInt(String(novos[chave]), 10) || 0 : null;
      const v = atuais[chave]?.value ?? null;
      return v !== null && v !== undefined ? Number.parseInt(String(v), 10) || 0 : null;
    };

    const total = ler('region_capacity_total');
    const hun = ler('region_capacity_hunters');
    const mer = ler('region_capacity_mercenaries');

    if (total === null || hun === null || mer === null || total >= hun + mer) return {};

    const msg = 'A soma das vagas (' + hun + ' + ' + mer + ' = ' + (hun + mer) + ') passa do teto da região (' + total + ').';

    // O erro vai no campo que o operador acabou de mexer.
    const culpado =
      'region_capacity_total' in novos
        ? 'region_capacity_total'
        : 'region_capacity_hunters' in novos
          ? 'region_capacity_hunters'
          : 'region_capacity_mercenaries';
    return { [culpado]: msg };
  }

  // =====================================================  montagem para a view

  /** Uma linha do banco vira o CAMPO que a view desenha. */
  private montarCampo(
    linha: Dict,
    cromo: Dict,
    historico: Record<string, Cfg.Mudanca>,
    erros: Record<string, string>,
    digitado: Record<string, string>,
  ): Dict {
    const chave = String(linha.key);
    const tipo = Cfg.TIPOS.includes(String(linha.kind)) ? String(linha.kind) : 'string';
    const regra = ((cromo.chaves ?? {}) as Dict)[chave] as Dict | undefined ?? {};
    const valor = linha.value !== null && linha.value !== undefined ? String(linha.value) : '';

    // Erro na tela = o campo volta com o que o OPERADOR escreveu, não com o do banco.
    const erro = erros[chave] ?? '';
    let naTela = erro !== '' && chave in digitado ? digitado[chave]! : valor;
    if (tipo === 'json' && erro === '') naTela = Cfg.jsonBonito(valor);

    const hist = historico[chave] ?? null;
    const int = (v: unknown) => Math.trunc(Number(v)) || 0;

    return {
      chave,
      label: String(linha.label ?? '') !== '' ? String(linha.label) : chave,
      tipo,
      grupo: String(linha.grupo),
      valor: naTela,
      gravado: valor,
      ligado: valor === '1',
      dica: String(regra.dica ?? ''),
      unidade: String(regra.unidade ?? ''),
      min: regra.min !== undefined && regra.min !== null ? int(regra.min) : null,
      max: regra.max !== undefined && regra.max !== null ? int(regra.max) : null,
      max_len: regra.max_len !== undefined && regra.max_len !== null ? int(regra.max_len) : null,
      linhas: regra.linhas !== undefined && regra.linhas !== null ? Math.max(1, int(regra.linhas)) : 1,
      rot_on: String(regra.ligado ?? 'Ligado'),
      rot_off: String(regra.desligado ?? 'Desligado'),
      perigo: Boolean(regra.perigo),
      ordem: regra.ordem !== undefined && regra.ordem !== null ? int(regra.ordem) : 500,
      protegida: Cfg.protegida(chave),
      alterada: linha.updated_by !== null && linha.updated_by !== undefined,
      quando: String(linha.updated_at ?? ''),
      quem: linha.updated_por !== null && linha.updated_por !== undefined ? String(linha.updated_por) : null,
      anterior: hist?.de ?? null,
      erro,
    };
  }

  /** Filtros da URL, já saneados, mais o catálogo que a barra de ferramentas desenha. */
  private lerFiltros(req: AppRequest, campos: Dict[]): Dict {
    const g0: Record<string, number> = {};
    const t0: Record<string, number> = {};
    for (const c of campos) {
      g0[c.grupo] = (g0[c.grupo] ?? 0) + 1;
      t0[c.tipo] = (t0[c.tipo] ?? 0) + 1;
    }
    // ksort()
    const ordenar = (o: Record<string, number>) => Object.fromEntries(Object.keys(o).sort().map((k) => [k, o[k]!]));
    const grupos = ordenar(g0);
    const tipos = ordenar(t0);

    const grupo = req.query('grupo', '') ?? '';
    const tipo = req.query('tipo', '') ?? '';
    const estado = req.query('estado', '') ?? '';
    const ordem = req.query('ordem', '') ?? '';

    return {
      q: corta(req.query('q', '') ?? '', 60),
      grupo: grupo in grupos ? grupo : '',
      tipo: tipo in tipos ? tipo : '',
      estado: ESTADOS.includes(estado) ? estado : 'todos',
      ordem: ORDENS.includes(ordem) ? ordem : ORDENS[0]!,
      pagina: Math.max(1, Number.parseInt(req.query('p', '1') ?? '1', 10) || 0),
      abrir_nova: (req.query('nova', '') ?? '') === '1',
      cat_grupos: grupos,
      cat_tipos: tipos,
    };
  }

  private filtrosVazios(): Dict {
    return { q: '', grupo: '', tipo: '', estado: 'todos', ordem: ORDENS[0], pagina: 1, abrir_nova: false, cat_grupos: {}, cat_tipos: {} };
  }

  private filtrar(campos: Dict[], f: Dict): Dict[] {
    const q = String(f.q).trim().toLowerCase();
    return campos.filter((c) => {
      if (f.grupo !== '' && c.grupo !== f.grupo) return false;
      if (f.tipo !== '' && c.tipo !== f.tipo) return false;
      const passaEstado =
        f.estado === 'alterado' ? Boolean(c.alterada)
        : f.estado === 'padrao' ? !c.alterada
        : f.estado === 'sistema' ? Boolean(c.protegida)
        : f.estado === 'personalizada' ? !c.protegida
        : true;
      if (!passaEstado) return false;
      if (q === '') return true;
      // A busca cobre chave, rótulo, VALOR e dica.
      const palheiro = (c.chave + ' ' + c.label + ' ' + c.gravado + ' ' + c.dica).toLowerCase();
      return palheiro.includes(q);
    });
  }

  /** Devolve para a lista os campos recusados que o filtro atual esconderia. */
  private reintegrarInvalidos(filtrados: Dict[], todos: Dict[]): Dict[] {
    const jaTem = filtrados.map((c) => c.chave);
    const saida = [...filtrados];
    for (const c of todos) {
      if (String(c.erro) !== '' && !jaTem.includes(c.chave)) saida.push(c);
    }
    return saida;
  }

  /** Agrupa por `grupo`, na ordem declarada no cromo; grupo desconhecido vai para o fim. */
  private agrupar(campos: Dict[], cromo: Dict, ordem: string): Dict[] {
    const meta = (cromo.grupos ?? {}) as Dict;
    const caixas = new Map<string, Dict>();

    for (const c of campos) {
      const g = String(c.grupo);
      if (!caixas.has(g)) {
        const m = (meta[g] ?? {}) as Dict;
        caixas.set(g, {
          chave: g,
          label: String(m.label ?? rotuloCru(g)),
          desc: String(m.desc ?? ''),
          icon: String(m.icon ?? 'tune'),
          ordem: m.ordem !== undefined && m.ordem !== null ? Math.trunc(Number(m.ordem)) || 0 : 900,
          itens: [] as Dict[],
        });
      }
      (caixas.get(g)!.itens as Dict[]).push(c);
    }

    const lista = [...caixas.values()].sort((a, b) => cmp([a.ordem, a.chave], [b.ordem, b.chave]));
    for (const caixa of lista) {
      (caixa.itens as Dict[]).sort((a, b) => {
        if (ordem === 'chave') return cmp([a.chave], [b.chave]);
        // Mais recente primeiro; quem nunca mudou vai para o fim.
        if (ordem === 'recentes') return cmp([b.alterada, String(b.quando)], [a.alterada, String(a.quando)]);
        return cmp([a.ordem, a.chave], [b.ordem, b.chave]);
      });
    }
    return lista;
  }

  private paginar(grupos: Dict[], pagina: number): { grupos: Dict[]; info: Dict } {
    const total = grupos.length;
    const paginas = Math.max(1, Math.ceil(total / GRUPOS_POR_PAGINA));
    pagina = Math.min(Math.max(1, pagina), paginas);
    return {
      grupos: grupos.slice((pagina - 1) * GRUPOS_POR_PAGINA, pagina * GRUPOS_POR_PAGINA),
      info: { pagina, paginas, total, por: GRUPOS_POR_PAGINA },
    };
  }

  private resumo(todos: Dict[], exibidos: Dict[]): Dict {
    let alterados = 0;
    const grupos = new Set<string>();
    let ultima: Dict | null = null;
    for (const c of todos) {
      grupos.add(String(c.grupo));
      if (!c.alterada) continue;
      alterados++;
      const quando = String(c.quando);
      if (quando !== '' && (ultima === null || quando > String(ultima.quando))) {
        ultima = { quando, chave: String(c.chave), quem: c.quem };
      }
    }
    return { total: todos.length, exibidos: exibidos.length, alterados, grupos: grupos.size, ultima };
  }

  /** O painel de confirmação de exclusão, quando ?excluir= aponta para uma chave real. */
  private confirmacaoDeExclusao(req: AppRequest, campos: Dict[]): Dict | null {
    const alvo = req.query('excluir', '') ?? '';
    if (alvo === '') return null;
    const c = campos.find((x) => x.chave === alvo);
    if (!c) return null;
    return {
      campo: c,
      bloqueio: c.protegida ? String(((dataMock('admin-config') as Dict).texto as Dict | undefined)?.protegida ?? '') : '',
    };
  }

  /** Os três indicadores compactos da topbar, todos tirados da própria tabela. */
  private kpisDaTopbar(campos: Dict[]): Dict[] {
    let alterados = 0;
    let manutencao: boolean | null = null;
    for (const c of campos) {
      alterados += c.alterada ? 1 : 0;
      if (c.chave === 'maintenance_enabled') manutencao = c.gravado === '1';
    }
    return [
      { icon: 'tune', label: 'Parâmetros', value: campos.length === 0 ? null : num(campos.length), tone: 'lime' },
      { icon: 'edit_note', label: 'Ajustados', value: campos.length === 0 ? null : num(alterados), tone: 'cyan' },
      {
        icon: manutencao === true ? 'build' : 'rocket_launch',
        label: 'Servidor',
        value: manutencao === null ? null : manutencao ? 'Manutenção' : 'No ar',
        tone: manutencao === true ? 'amber' : 'lime',
      },
    ];
  }

  // =====================================================================  utilidades

  /** Valor encurtado para caber no `summary` (255) da auditoria. */
  private resumir(v: string | null): string {
    if (v === null) return '(vazio)';
    v = v.replace(/\s+/gu, ' ');
    return Array.from(v).length > 60 ? corta(v, 57) + '...' : v;
  }

  /** Flash + volta para a tela (POST/redirect/GET). */
  private voltar(msg: string, tom: string): AppResponse {
    flash(tom, msg);
    return this.redirect('admin/configuracoes');
  }

  private porBolsa(nome: string, dados: Dict): void {
    ctx().session.set(nome, dados);
  }

  /** Lê E consome — a bolsa não pode sobreviver a um F5. */
  private tirarBolsa(nome: string): Dict {
    const s = ctx().session;
    const v = s.get<Dict>(nome);
    if (v !== undefined) s.delete(nome);
    return v !== null && typeof v === 'object' && !Array.isArray(v) ? v : {};
  }
}
