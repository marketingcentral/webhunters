/**
 * Tela Banimentos do painel: /admin/banimentos — o App\Controllers\Admin\BansController do PHP.
 *
 * Governa `bans` (o histórico) e `users.status` (o estado atual). O controller não escreve
 * nem uma nem outra direto — quem grava é repo/ban-repo.ts, num lote atômico. Aqui ficam:
 *   1. QUEM PODE — ninguém se pune, o dono é intocável, conta administrativa só pelo dono;
 *   2. O QUE É VÁLIDO — validação inteira no servidor, com mensagem por campo;
 *   3. PARA ONDE VOLTA — todo POST termina em redirect (PRG) com os MESMOS filtros.
 *
 * O painel de formulário/confirmação é escolhido pela URL (?painel=novo|revogar|remover).
 *
 * PRAZO: o <input datetime-local> é lido no horário de Brasília (como o strtotime() do PHP
 * com America/Sao_Paulo) e gravado em UTC, porque `bans.expires_at` é comparado com o
 * datetime('now') do SQLite. A view converte de volta para Brasília ao mostrar.
 */
import { ctx } from '../../core/context';
import * as Fuso from '../../core/fuso';
import { dataMock, flash, num } from '../../core/helpers';
import type { AppRequest } from '../../core/request';
import type { AppResponse } from '../../core/response';
import type { Params } from '../../core/router';
import type { Dict } from '../../core/view';
import { corta, formatarData } from '../../repo/admin-repo';
import * as BanRepo from '../../repo/ban-repo';
import { adminBanimentos } from '../../views/pages/admin-banimentos';
import { AdminController } from './admin-controller';

/** Motivo: curto demais não explica nada; a coluna é TEXT, o limite é editorial. */
const MOTIVO_MIN = 8;
const MOTIVO_MAX = 500;

/** Prazo máximo de uma punição temporária. Além disso, o tipo honesto é "definitivo". */
const PRAZO_MAX_ANOS = 10;

/** Onde o formulário recusado guarda erros e valores até a próxima renderização. */
const SESSAO_FORM = 'adm_bans_form';

/** Cache por requisição do formulário consumido (o `static $cache` do PHP). */
const CHAVE_LEMBRADO = 'bans.lembrado';

interface Lembrado {
  modo: string;
  valores: Record<string, string>;
  erros: Record<string, string>;
}

/** sprintf com %s/%d, na ordem. */
function sprintf(fmt: string, ...args: Array<string | number>): string {
  let i = 0;
  return fmt.replace(/%[sd]/g, () => String(args[i++] ?? ''));
}

const tamanho = (s: string) => Array.from(s).length;

export class BansController extends AdminController {
  // ====================================================================== leitura

  async index(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;

    // Punição temporária não tem quem a encerre (não há agendador): quem percebe o prazo
    // vencido é esta abertura de tela. A volta ao ar é auditada.
    const voltaram = await BanRepo.reconciliar();
    for (const v of voltaram) {
      await this.auditar('ban_expirado', 'user', v.id, 'Prazo de punição vencido: ' + v.username + ' voltou ao ar.', { de: v.de, para: v.para });
    }
    if (voltaram.length > 0) {
      // flash() grava na sessão e render() consome na MESMA requisição.
      flash('info', sprintf(String(dataMock('admin-banimentos')?.avisos?.reconciliado ?? '%d conta(s) voltaram ao ar.'), voltaram.length));
    }

    const filtros = this.filtrosDaUrl(req);
    const [lista, contas, painel, sugestoes] = await Promise.all([
      BanRepo.listar(filtros),
      BanRepo.contagens(),
      this.painel(req),
      BanRepo.contasParaEscolha(),
    ]);
    const cromo = dataMock('admin-banimentos') as Dict;

    const page = this.adminPage('banimentos', String(cromo.titulo ?? 'Banimentos'), String(cromo.subtitulo ?? ''));

    return this.render(adminBanimentos, page, {
      cromo,
      lista,
      contagens: contas,
      filtros,
      tipos: BanRepo.TIPOS,
      painel,
      form: this.lembrado(),
      sugestoes,
      eu: this.admin(),
      pode_apagar: this.ehDono(),
      // Prazo mínimo do <input datetime-local>, no relógio de Brasília.
      agora_local: Fuso.date('Y-m-d') + 'T' + Fuso.date('H:i'),

      // A casca lê esta chave: os números do topo do painel são os desta tela.
      admin_kpis: [
        { icon: 'gavel', label: 'Punições em vigor', value: num(contas.ativo), tone: 'red' },
        { icon: 'person_off', label: 'Contas fora do ar', value: num(contas.contas), tone: 'amber' },
        { icon: 'history', label: 'Histórico', value: num(contas.total), tone: 'cyan' },
      ],
    });
  }

  // ====================================================================== escrita

  /** Duas ações pela mesma rota: `acao=aplicar` cria a punição, `acao=revogar` a derruba. */
  async salvar(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    switch (String(req.input('acao', '') ?? '')) {
      case 'aplicar':
        return this.aplicar(req);
      case 'revogar':
        return this.revogarPunicao(req);
      default:
        return this.voltar(req, 'error', 'Ação desconhecida — nada foi alterado.');
    }
  }

  /**
   * Apaga uma linha do histórico. Punição EM VIGOR não é apagada, e só o DONO apaga. O que a
   * linha dizia vai inteiro para o `meta` da auditoria antes do DELETE.
   */
  async excluir(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const id = Number.parseInt(String(req.input('id', '0') ?? '0'), 10) || 0;
    const ban = await BanRepo.porId(id);

    if (ban === null) return this.voltar(req, 'error', 'Esse registro de punição não existe mais.');
    if (!this.ehDono()) return this.voltar(req, 'error', 'Apagar histórico de punição é exclusivo do dono do jogo.');
    if (String(ban.estado) === 'ativo') {
      return this.voltar(req, 'error', 'A punição de ' + String(ban.alvo_nome) + ' está em vigor. Revogue antes de apagar.');
    }

    if (!(await BanRepo.remover(id))) {
      return this.voltar(req, 'error', 'Não foi possível apagar o registro. Recarregue a tela e tente de novo.');
    }

    await this.auditar('ban_removido', 'ban', id, 'Registro de punição de ' + String(ban.alvo_nome) + ' apagado do histórico.', {
      user_id: Number(ban.user_id) | 0,
      conta: String(ban.alvo_nome),
      tipo: String(ban.kind),
      motivo: String(ban.reason ?? ''),
      aplicado_em: String(ban.created_at),
      expira_em: ban.expires_at ?? null,
      revogado_em: ban.revoked_at ?? null,
      estado: String(ban.estado),
    });

    return this.voltar(req, 'ok', sprintf(String(dataMock('admin-banimentos')?.avisos?.removido ?? 'Registro #%d apagado.'), id));
  }

  // ======================================================================== ações

  private async aplicar(req: AppRequest): Promise<AppResponse> {
    const bruto: Record<string, string> = {
      alvo: String(req.input('alvo', '') ?? '').trim(),
      tipo: String(req.input('tipo', '') ?? ''),
      prazo: String(req.input('prazo', '') ?? '').trim(),
      motivo: String(req.input('motivo', '') ?? '').trim(),
    };
    const erros: Record<string, string> = {};

    // --- alvo
    let alvo: Dict | null = null;
    if (bruto.alvo === '') {
      erros.alvo = 'Informe a conta que será punida.';
    } else {
      alvo = await BanRepo.usuarioPorLogin(bruto.alvo!);
      if (alvo === null) {
        erros.alvo = 'Nenhuma conta com esse usuário, e-mail ou ID.';
      } else if ((Number(alvo.id) | 0) === this.adminId()) {
        erros.alvo = 'Você não pode punir a própria conta.';
      } else if (String(alvo.role) === 'owner') {
        erros.alvo = 'A conta do dono do jogo não pode ser punida pelo painel.';
      } else if (String(alvo.role) === 'admin' && !this.ehDono()) {
        erros.alvo = 'Punir uma conta administrativa é exclusivo do dono do jogo.';
      } else {
        const vigente = await BanRepo.vigenteDe(Number(alvo.id) | 0);
        if (vigente !== null) {
          erros.alvo = 'Essa conta já tem uma punição em vigor (registro #' + (Number(vigente.id) | 0) + '). Revogue a atual antes de aplicar outra.';
        }
      }
    }

    // --- tipo
    const tipo = BanRepo.TIPOS[bruto.tipo!];
    if (!tipo) erros.tipo = 'Escolha o tipo de punição.';

    // --- prazo (lido em Brasília, gravado em UTC)
    let expiraEm: string | null = null;
    if (tipo?.prazo) {
      if (bruto.prazo === '') {
        erros.prazo = 'Punição com prazo precisa de uma data de término.';
      } else {
        // O <input type="datetime-local"> manda 2026-09-20T14:30 (às vezes com :ss).
        const ts = Fuso.strtotime(bruto.prazo!.replace('T', ' '));
        const agora = Fuso.agoraTs();
        const teto = new Date();
        teto.setUTCFullYear(teto.getUTCFullYear() + PRAZO_MAX_ANOS);
        if (Number.isNaN(ts)) {
          erros.prazo = 'Data inválida.';
        } else if (ts <= agora) {
          erros.prazo = 'O prazo precisa estar no futuro — no passado a punição já nasceria vencida.';
        } else if (ts > Math.floor(teto.getTime() / 1000)) {
          erros.prazo = 'Acima de ' + PRAZO_MAX_ANOS + ' anos, use "Definitivo" — é o que isso significa.';
        } else {
          expiraEm = formatarData(ts, 'Y-m-d H:i:s');
        }
      }
    }

    // --- motivo
    const erroMotivo = this.validarMotivo(bruto.motivo!);
    if (erroMotivo !== null) erros.motivo = erroMotivo;

    if (Object.keys(erros).length > 0 || alvo === null) {
      return this.recusar(req, 'novo', bruto, erros);
    }

    const motivo = corta(bruto.motivo!, MOTIVO_MAX);
    const banId = await BanRepo.aplicar(Number(alvo.id) | 0, this.adminId(), bruto.tipo!, motivo, expiraEm);

    await this.auditar('ban_aplicado', 'ban', banId, 'Punição aplicada a ' + String(alvo.username) + ' (' + tipo!.rotulo + ').', {
      user_id: Number(alvo.id) | 0,
      conta: String(alvo.username),
      tipo: bruto.tipo,
      expira_em: expiraEm,
      motivo,
    });

    return this.voltar(req, 'ok', sprintf(String(dataMock('admin-banimentos')?.avisos?.aplicado ?? 'Punição aplicada a %s.'), String(alvo.username)));
  }

  private async revogarPunicao(req: AppRequest): Promise<AppResponse> {
    const id = Number.parseInt(String(req.input('id', '0') ?? '0'), 10) || 0;
    const motivo = String(req.input('motivo', '') ?? '').trim();
    const ban = await BanRepo.porId(id);

    if (ban === null) return this.voltar(req, 'error', 'Esse registro de punição não existe mais.');
    if (String(ban.estado) === 'revogado') {
      return this.voltar(req, 'error', 'Essa punição já havia sido revogada por ' + String(ban.revogador_nome ?? 'outro operador') + '.');
    }
    if (String(ban.alvo_papel) === 'admin' && !this.ehDono()) {
      return this.voltar(req, 'error', 'Punição de conta administrativa só é revogada pelo dono do jogo.');
    }

    const erroMotivo = this.validarMotivo(motivo);
    if (erroMotivo !== null) {
      return this.recusar(req, 'revogar', { id: String(id), motivo }, { motivo: erroMotivo });
    }

    // Prazo já vencido não bloqueia ninguém: revogar seria creditar ao operador uma soltura que não foi dele.
    if (String(ban.estado) === 'expirado') {
      return this.voltar(req, 'error', 'Essa punição já venceu por prazo — não há o que revogar.');
    }

    const m = corta(motivo, MOTIVO_MAX);
    if (!(await BanRepo.revogar(id, this.adminId(), m))) {
      return this.voltar(req, 'error', 'A punição mudou de estado enquanto você escrevia. Recarregue a tela.');
    }

    await this.auditar('ban_revogado', 'ban', id, 'Punição de ' + String(ban.alvo_nome) + ' revogada.', {
      user_id: Number(ban.user_id) | 0,
      conta: String(ban.alvo_nome),
      tipo: String(ban.kind),
      motivo: m,
    });

    return this.voltar(req, 'ok', sprintf(String(dataMock('admin-banimentos')?.avisos?.revogado ?? 'Punição de %s revogada.'), String(ban.alvo_nome)));
  }

  // ======================================================================== apoio

  /** A frase de erro, ou null quando o motivo serve. */
  private validarMotivo(motivo: string): string | null {
    const n = tamanho(motivo);
    if (n === 0) return 'Escreva o motivo — é o que vai constar no histórico da conta.';
    if (n < MOTIVO_MIN) return 'Muito curto: descreva em pelo menos ' + MOTIVO_MIN + ' caracteres.';
    if (n > MOTIVO_MAX) return 'Passou de ' + MOTIVO_MAX + ' caracteres (você escreveu ' + n + ').';
    return null;
  }

  /** Papel que autoriza as decisões irreversíveis desta tela. */
  private ehDono(): boolean {
    return String(this.admin().role ?? '') === 'owner';
  }

  /** Filtros vindos da URL, já normalizados (o repo confere tudo contra as listas brancas). */
  private filtrosDaUrl(req: AppRequest): { busca: string; estado: string; tipo: string; ordem: string; pagina: number } {
    const estado = String(req.query('estado', '') ?? '');
    const tipo = String(req.query('tipo', '') ?? '');
    const ordem = String(req.query('ordem', '') ?? '');
    return {
      busca: corta(String(req.query('q', '') ?? ''), 80).trim(),
      estado: BanRepo.ESTADOS.includes(estado) ? estado : '',
      tipo: BanRepo.TIPOS[tipo] ? tipo : '',
      ordem: BanRepo.ordensValidas().includes(ordem) ? ordem : 'recente',
      pagina: Math.max(1, Number.parseInt(String(req.query('p', '1') ?? '1'), 10) || 0),
    };
  }

  /**
   * Qual painel abre acima da tabela e com que dado. null quando a URL não pede nenhum — ou
   * pede um que não faz sentido (registro apagado, punição já encerrada).
   */
  private async painel(req: AppRequest): Promise<{ modo: string; ban: Dict | null; bloqueio: string } | null> {
    const modo = String(req.query('painel', '') ?? '');
    if (!['novo', 'revogar', 'remover'].includes(modo)) {
      // Formulário recusado reabre sozinho.
      const lembrado = this.lembrado();
      if (Object.keys(lembrado.erros).length > 0) {
        return { modo: lembrado.modo, ban: await this.banDoFormulario(lembrado), bloqueio: '' };
      }
      return null;
    }

    if (modo === 'novo') return { modo: 'novo', ban: null, bloqueio: '' };

    const ban = await BanRepo.porId(Number.parseInt(String(req.query('id', '0') ?? '0'), 10) || 0);
    if (ban === null) return null;

    const cromo = dataMock('admin-banimentos') as Dict;
    if (modo === 'revogar') {
      return String(ban.estado) === 'ativo' ? { modo: 'revogar', ban, bloqueio: '' } : null;
    }

    // remover: o painel abre mesmo bloqueado — a razão da recusa é o conteúdo dele.
    let bloqueio = '';
    if (!this.ehDono()) bloqueio = String(cromo.remover?.bloqueio?.papel ?? '');
    else if (String(ban.estado) === 'ativo') bloqueio = String(cromo.remover?.bloqueio?.ativa ?? '');
    return { modo: 'remover', ban, bloqueio };
  }

  private async banDoFormulario(lembrado: Lembrado): Promise<Dict | null> {
    const id = Number.parseInt(String(lembrado.valores.id ?? '0'), 10) || 0;
    return id > 0 ? BanRepo.porId(id) : null;
  }

  /** Recusa sem perder o que foi digitado: erros e valores vão para a sessão (PRG). */
  private recusar(req: AppRequest, modo: string, valores: Record<string, string>, erros: Record<string, string>): AppResponse {
    ctx().session.set(SESSAO_FORM, { modo, valores, erros });
    const n = Object.keys(erros).length;
    flash('error', n === 1 ? 'Corrija o campo destacado abaixo.' : 'Corrija os ' + n + ' campos destacados abaixo.');
    return this.voltar(req);
  }

  /** Lê e CONSOME o formulário recusado (memoizado na requisição). */
  private lembrado(): Lembrado {
    const c = ctx().cache;
    const pronto = c.get(CHAVE_LEMBRADO) as Lembrado | undefined;
    if (pronto) return pronto;
    const s = ctx().session;
    const bruto = s.get<Dict>(SESSAO_FORM);
    if (bruto !== undefined && bruto !== null) s.delete(SESSAO_FORM);
    const obj = (v: unknown): Record<string, string> => (v && typeof v === 'object' && !Array.isArray(v) ? (v as Record<string, string>) : {});
    const l: Lembrado = {
      modo: bruto && typeof bruto === 'object' ? String(bruto.modo ?? '') : '',
      valores: bruto && typeof bruto === 'object' ? obj(bruto.valores) : {},
      erros: bruto && typeof bruto === 'object' ? obj(bruto.erros) : {},
    };
    c.set(CHAVE_LEMBRADO, l);
    return l;
  }

  /** Volta para a listagem preservando busca, filtros, ordem e página (campos ocultos f_*). */
  private voltar(req: AppRequest, tom: string | null = null, mensagem: string | null = null): AppResponse {
    if (tom !== null && mensagem !== null) flash(tom, mensagem);
    const q: Record<string, string> = {};
    const mapa: Record<string, string> = { q: 'f_q', estado: 'f_estado', tipo: 'f_tipo', ordem: 'f_ordem', p: 'f_p' };
    for (const [destino, campo] of Object.entries(mapa)) {
      const valor = String(req.input(campo, '') ?? '').trim();
      if (valor !== '') q[destino] = corta(valor, 80);
    }
    return this.redirect('admin/banimentos', q);
  }
}
