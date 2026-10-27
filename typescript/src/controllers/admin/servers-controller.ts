/**
 * Tela SERVIDORES do painel: /admin/servidores — o App\Controllers\Admin\ServersController do PHP.
 *
 * Governa `server_regions`, `server_services` e `maintenance_windows` — as três tabelas que
 * alimentam o "Status dos Servidores" do dashboard, o painel de status da tela /suporte do jogo
 * e o aviso de manutenção da caixa de mensagens do jogador.
 *
 * DUAS DECISÕES DE DESENHO (iguais ao PHP):
 *
 * 1. Três tabelas, UMA rota de escrita: o campo `tipo` (regiao|servico|janela) discrimina o
 *    destino e é validado contra lista fechada; `acao=estado` separa a troca rápida de estado
 *    feita no botão da própria linha.
 *
 * 2. Erro de validação NÃO redireciona: o POST inválido renderiza a mesma tela com o que foi
 *    digitado e a mensagem embaixo do campo culpado. Sucesso redireciona com flash.
 *
 * Excluir é uma etapa de verdade (`?excluir=tipo:id`), não um confirm() de JavaScript; região
 * referenciada por janelas é BLOQUEADA nessa etapa e de novo no POST.
 */
import { dataMock, flash, num } from '../../core/helpers';
import type { AppRequest } from '../../core/request';
import type { AppResponse } from '../../core/response';
import type { Params } from '../../core/router';
import type { Dict } from '../../core/view';
import { corta, formatarData, paraUnix } from '../../repo/admin-repo';
import * as ServersRepo from '../../repo/servers-repo';
import { adminServidores } from '../../views/pages/admin-servidores';
import { AdminController } from './admin-controller';

/** Os três blocos da tela. É esta lista que valida o campo `tipo` de todo POST. */
const TIPOS = ['regiao', 'servico', 'janela'];

/** Rótulo de cada tipo para flash, auditoria e título do formulário. */
const NOME: Record<string, string> = { regiao: 'região', servico: 'serviço', janela: 'janela de manutenção' };

interface Validado {
  dados: Dict;
  bruto: Record<string, string>;
  erros: Record<string, string>;
}

/** sprintf com %d e %s, na ordem. */
function sprintf(fmt: string, ...args: Array<string | number>): string {
  let i = 0;
  return fmt.replace(/%[ds]/g, (m) => {
    const v = args[i++];
    return m === '%d' ? String(Math.trunc(Number(v) || 0)) : String(v ?? '');
  });
}

/** strtotime() de uma string DATETIME já validada (UTC, ver admin-repo). */
const ts = (v: unknown) => paraUnix(v);

export class ServersController extends AdminController {
  async index(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    return this.tela(req);
  }

  /** Cria ou atualiza uma das três entidades — e também a troca rápida de estado. */
  async salvar(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const tipo = String(req.input('tipo', '') ?? '');
    if (!TIPOS.includes(tipo)) {
      flash('error', 'Pedido inválido: tipo de registro desconhecido.');
      return this.redirect('admin/servidores');
    }

    if (String(req.input('acao', '') ?? '') === 'estado') {
      return this.trocarEstado(req, tipo);
    }

    const id = Math.max(0, intDe(req.input('id', '0')));
    const val =
      tipo === 'regiao'
        ? await this.validarRegiao(req, id)
        : tipo === 'servico'
          ? await this.validarServico(req, id)
          : await this.validarJanela(req, id);

    if (Object.keys(val.erros).length > 0) {
      // Volta para a MESMA tela, com o formulário aberto no que foi digitado.
      flash('error', 'Corrija os campos destacados para gravar.');
      return this.tela(req, { form_tipo: tipo, form_id: id, form_dados: val.bruto, form_erros: val.erros });
    }

    const novo = id <= 0;
    const antes = novo ? null : await this.carregar(tipo, id);
    if (!novo && antes === null) {
      flash('error', 'O registro que você tentou editar não existe mais.');
      return this.redirect('admin/servidores');
    }

    const gravado =
      tipo === 'regiao'
        ? await ServersRepo.salvarRegiao(val.dados, id)
        : tipo === 'servico'
          ? await ServersRepo.salvarServico(val.dados, id)
          : await ServersRepo.salvarJanela(val.dados, id);

    // Renomear a região é uma migração pequena: o rótulo é a chave que as janelas guardam.
    let renomeadas = 0;
    if (tipo === 'regiao' && antes !== null && String(antes.label) !== String(val.dados.label)) {
      renomeadas = await ServersRepo.renomearRegiaoNasJanelas(String(antes.label), String(val.dados.label));
    }

    await this.auditar(
      novo ? 'servidores_criar' : 'servidores_editar',
      'servidores_' + tipo,
      gravado,
      (novo ? 'Criou ' : 'Alterou ') + NOME[tipo] + ' ' + this.rotuloDe(tipo, val.dados),
      { tipo, campos: this.diff(antes, val.dados), janelas_renomeadas: renomeadas },
    );

    let msg = (novo ? 'Criada' : 'Salva') + ': ' + NOME[tipo] + ' ' + this.rotuloDe(tipo, val.dados) + '.';
    if (renomeadas > 0) msg += ' ' + renomeadas + ' janela(s) acompanharam o novo rótulo.';
    flash('ok', msg);

    return this.redirect('admin/servidores', this.filtrosDe(req));
  }

  /** Apaga uma das três entidades, depois da etapa de confirmação (`?excluir=tipo:id`). */
  async excluir(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const tipo = String(req.input('tipo', '') ?? '');
    const id = Math.max(0, intDe(req.input('id', '0')));
    if (!TIPOS.includes(tipo) || id <= 0) {
      flash('error', 'Pedido inválido: nada foi apagado.');
      return this.redirect('admin/servidores');
    }

    const linha = await this.carregar(tipo, id);
    if (linha === null) {
      flash('error', 'Este registro já havia sido apagado.');
      return this.redirect('admin/servidores');
    }

    // O bloqueio é conferido de novo AQUI: entre abrir a confirmação e clicar, outro
    // operador pode ter criado a janela que amarra a região.
    const motivo = await this.motivoDeBloqueio(tipo, linha);
    if (motivo !== null) {
      flash('error', motivo);
      return this.redirect('admin/servidores');
    }

    if (!(await ServersRepo.excluir(tipo, id))) {
      flash('error', 'Não foi possível apagar o registro.');
      return this.redirect('admin/servidores');
    }

    await this.auditar('servidores_excluir', 'servidores_' + tipo, id, 'Excluiu ' + NOME[tipo] + ' ' + this.rotuloCru(tipo, linha), {
      tipo,
      apagado: this.resumirLinha(tipo, linha),
    });

    flash('ok', 'Excluída: ' + NOME[tipo] + ' ' + this.rotuloCru(tipo, linha) + '.');
    return this.redirect('admin/servidores', this.filtrosDe(req));
  }

  // =====================================================================================
  //  MONTAGEM DA TELA
  // =====================================================================================

  /** A tela inteira. Serve o GET e o POST que voltou com erro (sobrescritos por parâmetro). */
  private async tela(req: AppRequest, over: Dict = {}): Promise<AppResponse> {
    const filtro = this.filtrosDe(req);

    const [regioes, servicos, janelas, resumo, rotulosReg, noticias] = await Promise.all([
      ServersRepo.regioes({ q: filtro.q ?? '', estado: filtro.estado ?? '', ord: filtro.ord ?? '', dir: filtro.dir ?? 'asc' }),
      ServersRepo.servicos({ q: filtro.q ?? '', estado: filtro.estado ?? '', ord: filtro.sord ?? '', dir: filtro.sdir ?? 'asc' }),
      ServersRepo.janelas({ q: filtro.q ?? '', situacao: filtro.situacao ?? '', pagina: intDe(filtro.jp ?? '1') }),
      ServersRepo.resumo(),
      ServersRepo.rotulosRegioes(),
      ServersRepo.noticias(),
    ]);

    // Formulário: o POST com erro manda o seu; o GET abre pela URL (?novo= / ?editar=).
    const form =
      Object.keys(over).length > 0
        ? {
            tipo: String(over.form_tipo),
            id: Number(over.form_id) | 0,
            dados: (over.form_dados ?? {}) as Dict,
            erros: (over.form_erros ?? {}) as Dict,
          }
        : await this.formularioDaUrl(req);

    const exclusao = await this.exclusaoDaUrl(req);

    // Sem botões colados ao título: criar mora no cabeçalho do cartão de cada bloco.
    const page = this.adminPage('servidores', 'Servidores', 'Regiões, serviços e janelas de manutenção');

    return this.render(adminServidores, page, {
      cromo: dataMock('admin-servidores'),
      admin_conta: this.admin(),

      resumo,
      regioes,
      servicos,
      janelas,

      filtro,
      estados: ServersRepo.ESTADOS,
      situacoes: ServersRepo.SITUACOES,
      rotulos_reg: rotulosReg,
      noticias,

      form,
      exclusao,

      admin_kpis: this.kpisDaTopbar(resumo),
      admin_alerts: this.alertas(regioes, servicos, resumo),
    });
  }

  /**
   * Filtros lidos da URL (ou dos campos f_* do POST), já saneados. A MESMA função remonta a
   * URL depois de gravar — o operador volta para a lista filtrada em que estava.
   */
  private filtrosDe(req: AppRequest): Record<string, string> {
    const f: Record<string, string> = {};

    const q = String(req.query('q') ?? req.input('f_q', '') ?? '').trim();
    if (q !== '') f.q = corta(q, 60);

    const estado = String(req.query('estado') ?? req.input('f_estado', '') ?? '');
    if (estado in ServersRepo.ESTADOS) f.estado = estado;

    const situacao = String(req.query('situacao') ?? req.input('f_situacao', '') ?? '');
    if (situacao in ServersRepo.SITUACOES) f.situacao = situacao;

    for (const [col, dir] of [['ord', 'dir'], ['sord', 'sdir']] as const) {
      const v = String(req.query(col) ?? '').toLowerCase().replace(/[^a-z]/g, '');
      if (v !== '') {
        f[col] = v;
        f[dir] = String(req.query(dir) ?? '').toLowerCase() === 'desc' ? 'desc' : 'asc';
      }
    }

    const jp = intDe(req.query('jp') ?? req.input('f_jp', '0') ?? '0');
    if (jp > 1) f.jp = String(jp);

    return f;
  }

  /** `?novo=regiao` e `?editar=regiao:3` — o formulário mora na URL, não na sessão. */
  private async formularioDaUrl(req: AppRequest): Promise<Dict | null> {
    const novo = String(req.query('novo') ?? '');
    if (TIPOS.includes(novo)) {
      return { tipo: novo, id: 0, dados: await this.padroes(novo), erros: {} };
    }

    const [tipo, id] = this.tipoId(String(req.query('editar') ?? ''));
    if (tipo === null) return null;

    const linha = await this.carregar(tipo, id);
    if (linha === null) {
      flash('error', 'O registro que você quis editar não existe mais.');
      return null;
    }
    return { tipo, id, dados: this.paraFormulario(tipo, linha), erros: {} };
  }

  /** `?excluir=janela:7` — a etapa de confirmação, já com o motivo do bloqueio quando existe. */
  private async exclusaoDaUrl(req: AppRequest): Promise<Dict | null> {
    const [tipo, id] = this.tipoId(String(req.query('excluir') ?? ''));
    if (tipo === null) return null;
    const linha = await this.carregar(tipo, id);
    if (linha === null) return null;
    return {
      tipo,
      id,
      linha,
      rotulo: this.rotuloCru(tipo, linha),
      detalhes: this.resumirLinha(tipo, linha),
      bloqueio: await this.motivoDeBloqueio(tipo, linha),
    };
  }

  /** "regiao:3" -> ['regiao', 3]; qualquer outra coisa -> [null, 0]. */
  private tipoId(ref: string): [string | null, number] {
    const i = ref.indexOf(':');
    const tipo = i < 0 ? ref : ref.slice(0, i);
    const id = i < 0 ? 0 : intDe(ref.slice(i + 1));
    return TIPOS.includes(tipo) && id > 0 ? [tipo, id] : [null, 0];
  }

  private carregar(tipo: string, id: number): Promise<Dict | null> {
    if (tipo === 'regiao') return ServersRepo.regiao(id);
    if (tipo === 'servico') return ServersRepo.servico(id);
    if (tipo === 'janela') return ServersRepo.janela(id);
    return Promise.resolve(null);
  }

  /**
   * O motivo pelo qual este registro NÃO pode ser apagado, ou null quando pode. Só a região
   * tem amarra: as janelas guardam o RÓTULO dela, não o id.
   */
  private async motivoDeBloqueio(tipo: string, linha: Dict): Promise<string | null> {
    if (tipo !== 'regiao') return null;
    const label = String(linha.label ?? '');
    const n = await ServersRepo.janelasDaRegiao(label);
    if (n === 0) return null;
    const cromo = dataMock('admin-servidores') as Dict;
    return sprintf(String(cromo.excluir?.bloqueio ?? '%d janela(s) usam "%s".'), n, label);
  }

  // =====================================================================================
  //  VALIDAÇÃO — servidor, campo a campo
  // =====================================================================================

  private async validarRegiao(req: AppRequest, id: number): Promise<Validado> {
    const bruto: Record<string, string> = {
      label: String(req.input('label', '') ?? '').trim(),
      slug: String(req.input('slug', '') ?? '').trim(),
      ping_ms: String(req.input('ping_ms', '') ?? '').trim(),
      players: String(req.input('players', '') ?? '').trim(),
      state: String(req.input('state', 'ok') ?? 'ok'),
      sort_order: String(req.input('sort_order', '0') ?? '0').trim(),
    };
    const erros: Record<string, string> = {};

    const label = bruto.label!;
    if (label === '') erros.label = 'Informe o nome da região.';
    else if (Array.from(label).length > 48) erros.label = 'No máximo 48 caracteres.';
    else if (await ServersRepo.rotuloOcupado(label, id)) erros.label = 'Já existe outra região com este nome — e o nome é a chave das janelas.';

    // Slug em branco não é erro: sai do rótulo.
    const slug = bruto.slug !== '' ? bruto.slug!.toLowerCase() : ServersRepo.slugificar(label);
    if (slug === '') erros.slug = 'Não foi possível gerar um identificador a partir do nome. Escreva um.';
    else if (!/^[a-z0-9][a-z0-9-]{1,23}$/.test(slug)) erros.slug = 'Use de 2 a 24 caracteres: letras minúsculas, números e hífen.';
    else if (await ServersRepo.slugOcupado('regiao', slug, id)) erros.slug = 'Este identificador já pertence a outra região.';

    const ping = inteiro(bruto.ping_ms!, 0, 65535);
    if (ping === null) erros.ping_ms = 'Um número inteiro de 0 a 65535.';

    const players = inteiro(bruto.players!, 0, 4294967295);
    if (players === null) erros.players = 'Um número inteiro de 0 para cima.';

    if (!(bruto.state! in ServersRepo.ESTADOS)) erros.state = 'Escolha um dos estados da lista.';

    const ordem = inteiro(bruto.sort_order === '' ? '0' : bruto.sort_order!, 0, 999);
    if (ordem === null) erros.sort_order = 'Um número inteiro de 0 a 999.';

    return {
      bruto,
      erros,
      dados: { label, slug, ping_ms: ping ?? 0, players: players ?? 0, state: bruto.state, sort_order: ordem ?? 0 },
    };
  }

  private async validarServico(req: AppRequest, id: number): Promise<Validado> {
    const bruto: Record<string, string> = {
      label: String(req.input('label', '') ?? '').trim(),
      slug: String(req.input('slug', '') ?? '').trim(),
      state: String(req.input('state', 'ok') ?? 'ok'),
      status_label: String(req.input('status_label', '') ?? '').trim(),
      short_label: String(req.input('short_label', '') ?? '').trim(),
      maint_scope: String(req.input('maint_scope', '') ?? '').trim(),
      maint_starts_at: String(req.input('maint_starts_at', '') ?? '').trim(),
      maint_minutes: String(req.input('maint_minutes', '') ?? '').trim(),
      sort_order: String(req.input('sort_order', '0') ?? '0').trim(),
    };
    const erros: Record<string, string> = {};
    const tam = (s: string) => Array.from(s).length;

    if (bruto.label === '') erros.label = 'Informe o nome do serviço.';
    else if (tam(bruto.label!) > 48) erros.label = 'No máximo 48 caracteres.';

    const slug = bruto.slug !== '' ? bruto.slug!.toLowerCase() : ServersRepo.slugificar(bruto.label!);
    if (slug === '') erros.slug = 'Não foi possível gerar um identificador a partir do nome. Escreva um.';
    else if (!/^[a-z0-9][a-z0-9-]{1,23}$/.test(slug)) erros.slug = 'Use de 2 a 24 caracteres: letras minúsculas, números e hífen.';
    else if (await ServersRepo.slugOcupado('servico', slug, id)) erros.slug = 'Este identificador já pertence a outro serviço.';

    if (!(bruto.state! in ServersRepo.ESTADOS)) erros.state = 'Escolha um dos estados da lista.';

    if (bruto.status_label === '') erros.status_label = 'Informe a frase que o jogador lê.';
    else if (tam(bruto.status_label!) > 32) erros.status_label = 'No máximo 32 caracteres.';

    if (bruto.short_label === '') erros.short_label = 'Informe a versão curta.';
    else if (tam(bruto.short_label!) > 24) erros.short_label = 'No máximo 24 caracteres.';

    if (tam(bruto.maint_scope!) > 48) erros.maint_scope = 'No máximo 48 caracteres.';

    const inicio = dataHora(bruto.maint_starts_at!);
    if (bruto.maint_starts_at !== '' && inicio === null) erros.maint_starts_at = 'Data e hora inválidas.';

    const minutos = bruto.maint_minutes === '' ? null : inteiro(bruto.maint_minutes!, 1, 1440);
    if (bruto.maint_minutes !== '' && minutos === null) erros.maint_minutes = 'Duração de 1 a 1440 minutos.';

    // O par início+duração é o que vira contagem regressiva (SupportRepo.maintenance).
    if (inicio !== null && minutos === null && !('maint_minutes' in erros)) {
      erros.maint_minutes = 'Com horário marcado, a duração é obrigatória.';
    }
    if (inicio === null && minutos !== null && !('maint_starts_at' in erros)) {
      erros.maint_starts_at = 'Com duração informada, o horário é obrigatório.';
    }
    // Serviço operacional com janela marcada é contradição visível.
    if (bruto.state === 'ok' && (inicio !== null || minutos !== null)) {
      erros.maint_starts_at = 'Serviço operacional não anuncia janela. Mude o estado ou limpe o horário.';
    }

    const ordem = inteiro(bruto.sort_order === '' ? '0' : bruto.sort_order!, 0, 999);
    if (ordem === null) erros.sort_order = 'Um número inteiro de 0 a 999.';

    return {
      bruto,
      erros,
      dados: {
        label: bruto.label,
        slug,
        state: bruto.state,
        status_label: bruto.status_label,
        short_label: bruto.short_label,
        maint_scope: bruto.maint_scope !== '' ? bruto.maint_scope : null,
        maint_starts_at: inicio,
        maint_minutes: minutos,
        sort_order: ordem ?? 0,
      },
    };
  }

  private async validarJanela(req: AppRequest, id: number): Promise<Validado> {
    const bruto: Record<string, string> = {
      starts_at: String(req.input('starts_at', '') ?? '').trim(),
      ends_at: String(req.input('ends_at', '') ?? '').trim(),
      region: String(req.input('region', '') ?? '').trim(),
      note: String(req.input('note', '') ?? '').trim(),
      news_slug: String(req.input('news_slug', '') ?? '').trim(),
    };
    const erros: Record<string, string> = {};

    const ini = dataHora(bruto.starts_at!);
    const fim = dataHora(bruto.ends_at!);
    if (ini === null) erros.starts_at = 'Informe início válido.';
    if (fim === null) erros.ends_at = 'Informe fim válido.';
    if (ini !== null && fim !== null) {
      const dur = ts(fim) - ts(ini);
      if (dur <= 0) erros.ends_at = 'O fim tem de ser depois do início.';
      else if (dur > 30 * 86400) erros.ends_at = 'Janela de mais de 30 dias: confira as datas.';
    }

    // O rótulo vem de um <select>, mas o POST não é obrigado a vir do formulário.
    const regioes = await ServersRepo.rotulosRegioes();
    if (bruto.region === '') erros.region = 'Escolha a região.';
    else if (!regioes.includes(bruto.region!)) {
      erros.region = regioes.length === 0 ? 'Cadastre uma região antes de agendar manutenção.' : 'Esta região não existe. Escolha uma da lista.';
    }

    // Só procura choque quando as três coisas que definem a janela já são válidas.
    if (ini !== null && fim !== null && Object.keys(erros).length === 0) {
      const choque = await ServersRepo.janelaSobreposta(bruto.region!, ini, fim, id);
      if (choque !== null) {
        erros.starts_at =
          'Já existe janela nesta região de ' +
          formatarData(ts(choque.starts_at), 'd/m H:i') +
          ' a ' +
          formatarData(ts(choque.ends_at), 'd/m H:i') +
          '.';
      }
    }

    if (Array.from(bruto.note!).length > 240) erros.note = 'No máximo 240 caracteres.';

    if (bruto.news_slug !== '') {
      const slugs = (await ServersRepo.noticias()).map((n) => n.slug);
      if (!slugs.includes(bruto.news_slug!)) erros.news_slug = 'Notícia inexistente. Escolha uma da lista (ou deixe em branco).';
    }

    return {
      bruto,
      erros,
      dados: {
        starts_at: ini,
        ends_at: fim,
        region: bruto.region,
        note: bruto.note !== '' ? bruto.note : null,
        news_slug: bruto.news_slug !== '' ? bruto.news_slug : null,
      },
    };
  }

  /** Troca só o `state` da linha, pelo botão da tabela. */
  private async trocarEstado(req: AppRequest, tipo: string): Promise<AppResponse> {
    const id = Math.max(0, intDe(req.input('id', '0')));
    const state = String(req.input('state', '') ?? '');
    const linha = await this.carregar(tipo, id);

    if (tipo === 'janela' || linha === null || !(state in ServersRepo.ESTADOS)) {
      flash('error', 'Não foi possível trocar o estado.');
      return this.redirect('admin/servidores');
    }

    const antes = String(linha.state);
    if (antes === state) {
      // Sem gravação não há o que auditar — e um flash de sucesso mentiria sobre mudança.
      flash('info', String(linha.label) + ' já estava em "' + ServersRepo.ESTADOS[state] + '".');
      return this.redirect('admin/servidores', this.filtrosDe(req));
    }

    await ServersRepo.trocarEstado(tipo, id, state);
    await this.auditar(
      'servidores_estado',
      'servidores_' + tipo,
      id,
      NOME[tipo] + ' "' + String(linha.label) + '": ' + (ServersRepo.ESTADOS[antes] ?? antes) + ' -> ' + ServersRepo.ESTADOS[state],
      { tipo, de: antes, para: state },
    );

    flash('ok', String(linha.label) + ' agora está em "' + ServersRepo.ESTADOS[state] + '".');
    return this.redirect('admin/servidores', this.filtrosDe(req));
  }

  // =====================================================================================
  //  APOIO
  // =====================================================================================

  /** Valores iniciais de um formulário novo. */
  private async padroes(tipo: string): Promise<Record<string, string>> {
    if (tipo === 'regiao') return { label: '', slug: '', ping_ms: '0', players: '0', state: 'ok', sort_order: '0' };
    if (tipo === 'servico') {
      return {
        label: '', slug: '', state: 'ok', status_label: 'OPERACIONAL', short_label: 'ESTÁVEL',
        maint_scope: '', maint_starts_at: '', maint_minutes: '', sort_order: '0',
      };
    }
    // strtotime('tomorrow 17:00') — no relógio das colunas (UTC, ver admin-repo).
    const hoje = new Date();
    const amanha = Date.UTC(hoje.getUTCFullYear(), hoje.getUTCMonth(), hoje.getUTCDate() + 1) / 1000;
    return {
      starts_at: formatarData(amanha + 17 * 3600, 'Y-m-dTH:i'),
      ends_at: formatarData(amanha + 19 * 3600, 'Y-m-dTH:i'),
      region: (await ServersRepo.rotulosRegioes())[0] ?? '',
      note: '',
      news_slug: '',
    };
  }

  /** Linha do banco -> campos do formulário (os DATETIME viram o formato do input). */
  private paraFormulario(tipo: string, l: Dict): Record<string, string> {
    const local = (v: unknown): string =>
      v === null || v === undefined || v === '' ? '' : formatarData(ts(v), 'Y-m-dTH:i');
    if (tipo === 'regiao') {
      return {
        label: String(l.label), slug: String(l.slug), ping_ms: String(l.ping_ms), players: String(l.players),
        state: String(l.state), sort_order: String(l.sort_order),
      };
    }
    if (tipo === 'servico') {
      return {
        label: String(l.label), slug: String(l.slug), state: String(l.state),
        status_label: String(l.status_label), short_label: String(l.short_label),
        maint_scope: String(l.maint_scope ?? ''),
        maint_starts_at: local(l.maint_starts_at ?? null),
        maint_minutes: l.maint_minutes === null || l.maint_minutes === undefined ? '' : String(l.maint_minutes),
        sort_order: String(l.sort_order),
      };
    }
    return {
      starts_at: local(String(l.starts_at)), ends_at: local(String(l.ends_at)),
      region: String(l.region), note: String(l.note ?? ''), news_slug: String(l.news_slug ?? ''),
    };
  }

  /** Como a entidade é chamada no flash e na auditoria, a partir dos dados JÁ validados. */
  private rotuloDe(tipo: string, d: Dict): string {
    return tipo === 'janela'
      ? '"' + String(d.region) + '" ' + formatarData(ts(d.starts_at), 'd/m/Y H:i')
      : '"' + String(d.label) + '"';
  }

  /** O mesmo, a partir de uma linha crua do banco. */
  private rotuloCru(tipo: string, l: Dict): string {
    return tipo === 'janela'
      ? '"' + String(l.region) + '" ' + formatarData(ts(l.starts_at), 'd/m/Y H:i')
      : '"' + String(l.label) + '"';
  }

  /** O que foi apagado, em texto — vai para a confirmação E para o `meta` da auditoria. */
  private resumirLinha(tipo: string, l: Dict): Record<string, string> {
    if (tipo === 'regiao') {
      return {
        Identificador: String(l.slug),
        Ping: String(l.ping_ms) + ' ms',
        Jogadores: num(Number(l.players) | 0),
        Estado: ServersRepo.ESTADOS[String(l.state)] ?? String(l.state),
      };
    }
    if (tipo === 'servico') {
      return {
        Identificador: String(l.slug),
        Estado: ServersRepo.ESTADOS[String(l.state)] ?? String(l.state),
        Rótulo: String(l.status_label),
        Escopo: String(l.maint_scope ?? '') || '—',
      };
    }
    return {
      Região: String(l.region),
      Início: formatarData(ts(l.starts_at), 'd/m/Y H:i'),
      Fim: formatarData(ts(l.ends_at), 'd/m/Y H:i'),
      Nota: String(l.note ?? '') || '—',
    };
  }

  /** Só os campos que MUDARAM, para o `meta` da auditoria. */
  private diff(antes: Dict | null, depois: Dict): Dict {
    if (antes === null) return {};
    const out: Dict = {};
    for (const [campo, novo] of Object.entries(depois)) {
      if (!(campo in antes)) continue;
      const velho = antes[campo];
      if (strPhp(velho) !== strPhp(novo)) out[campo] = { de: velho, para: novo };
    }
    return out;
  }

  /** Os indicadores da topbar desta tela: regiões, serviços e a próxima parada programada. */
  private kpisDaTopbar(r: Dict): Dict[] {
    const prox = (r.proxima ?? null) as Dict | null;
    return [
      {
        icon: 'public',
        label: 'Regiões online',
        value: r.regioes > 0 ? r.regioes_ok + '/' + r.regioes : null,
        tone: r.regioes > 0 && r.regioes_ok === r.regioes ? 'lime' : 'amber',
      },
      {
        icon: 'dns',
        label: 'Serviços no ar',
        value: r.servicos > 0 ? r.servicos_ok + '/' + r.servicos : null,
        tone: r.servicos > 0 && r.servicos_ok === r.servicos ? 'cyan' : 'amber',
      },
      {
        icon: 'schedule',
        label: prox !== null && prox.ativa ? 'Janela ativa' : 'Próxima janela',
        value: prox === null ? null : formatarData(Number(prox.inicio), 'd/m H:i'),
        sub: prox === null ? null : prox.regiao,
        tone: prox !== null && prox.ativa ? 'red' : 'amber',
      },
    ];
  }

  /** O sino desta tela: só fato do banco. Sem ocorrência, o sino fica mudo. */
  private alertas(regioes: Dict[], servicos: Dict[], resumo: Dict): Dict[] {
    const out: Dict[] = [];
    for (const s of servicos) {
      if (s.state === 'ok') continue;
      out.push({
        icon: s.state === 'down' ? 'error' : 'build',
        tone: s.state === 'down' ? 'red' : 'amber',
        text: s.label + ': ' + s.rotulo,
        meta: s.escopo,
      });
    }
    for (const r of regioes) {
      if (r.estado === 'online') continue;
      out.push({
        icon: r.estado === 'fora' ? 'error' : 'public',
        tone: r.estado === 'fora' ? 'red' : 'amber',
        text: r.label + ': ' + r.estado_rotulo,
        meta: r.nota,
      });
    }
    const prox = (resumo.proxima ?? null) as Dict | null;
    if (prox !== null && prox.ativa) {
      out.push({
        icon: 'schedule',
        tone: 'cyan',
        text: 'Janela em andamento em ' + prox.regiao,
        meta: 'Termina ' + formatarData(Number(prox.fim), 'd/m H:i'),
      });
    }
    return out;
  }
}

// ----------------------------------------------------------------------------- utilitários

/** (int)$x do PHP para texto vindo do pedido: prefixo numérico, senão 0. */
function intDe(v: unknown): number {
  const n = Number.parseInt(String(v ?? '').trim(), 10);
  return Number.isNaN(n) ? 0 : n;
}

/** (string)$x do PHP: null vira '' (o diff do PHP comparava assim). */
function strPhp(v: unknown): string {
  return v === null || v === undefined ? '' : String(v);
}

/** Inteiro dentro da faixa, ou null quando o texto não é um inteiro válido. */
function inteiro(v: string, min: number, max: number): number | null {
  if (v === '' || !/^-?\d{1,12}$/.test(v)) return null;
  const n = Number.parseInt(v, 10);
  return n >= min && n <= max ? n : null;
}

/**
 * "2026-09-12T17:00" (o que <input type="datetime-local"> manda) -> "2026-09-12 17:00:00".
 * Também aceita o formato do banco, para o POST que volta de um formulário reexibido.
 */
function dataHora(v: string): string | null {
  if (v === '') return null;
  v = v.replace(/T/g, ' ');
  const m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(:\d{2})?$/.exec(v);
  if (!m) return null;
  const [ano, mes, dia, hora, min] = [+m[1]!, +m[2]!, +m[3]!, +m[4]!, +m[5]!];
  // checkdate()
  const d = new Date(Date.UTC(ano, mes - 1, dia));
  if (mes < 1 || mes > 12 || d.getUTCDate() !== dia || d.getUTCMonth() !== mes - 1 || hora > 23 || min > 59) return null;
  return `${m[1]}-${m[2]}-${m[3]} ${m[4]}:${m[5]}:00`;
}
