/**
 * Tela ADMIN · JOGADORES (/admin/jogadores) — o App\Controllers\Admin\PlayersController do PHP.
 *
 * Governa `users` (papel e situação), `bans` (aplicar e revogar) e mostra, sem editar, o que
 * as tabelas player_* dizem sobre a conta.
 *
 * Uma tela, dois modos, uma rota: sem `?id=` é a LISTA; com `?id=` é o DOSSIÊ de uma conta.
 *
 * AS TRÊS REGRAS QUE ESTE ARQUIVO GARANTE:
 *   1. Ninguém age sobre a própria conta.
 *   2. Ninguém age sobre igual ou superior (AdminPlayersRepo.podeAgirSobre).
 *   3. `bans` e `users.status` NUNCA discordam: "Banida" é consequência de punição registrada,
 *      não um valor que se escolhe num <select>.
 *
 * password_hash não é lido, não é escrito e não chega à view.
 */
import * as Fuso from '../../core/fuso';
import { dataMock, flash } from '../../core/helpers';
import { HttpException } from '../../core/http-exception';
import type { AppRequest } from '../../core/request';
import type { AppResponse } from '../../core/response';
import type { Params } from '../../core/router';
import type { Dict } from '../../core/view';
import * as Repo from '../../repo/admin-players-repo';
import { agoraUnix, corta, formatarData, paraUnix } from '../../repo/admin-repo';
import { adminJogadores } from '../../views/pages/admin-jogadores';
import { AdminController } from './admin-controller';

/** Motivo de banimento: curto demais não é motivo, é rabisco. */
const MOTIVO_MIN = 8;
const MOTIVO_MAX = 500;

/** Teto do prazo de um banimento temporário. Acima disso, o honesto é usar permanente. */
const BAN_MAX_DIAS = 3650;

/** (int)$x do PHP para texto de formulário/URL: "12abc" -> 12, lixo -> 0. */
const inteiro = (v: string | null | undefined): number => Number.parseInt(String(v ?? ''), 10) || 0;

/** mb_strlen. */
const tamanho = (s: string): number => Array.from(s).length;

export class PlayersController extends AdminController {
  // ================================================================================ LEITURA

  /** GET /admin/jogadores[?id=N] */
  async index(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;

    // Sem cron no projeto, "banir por 3 dias" só termina se alguém varrer os vencidos.
    const liberados = await Repo.liberarVencidos();
    if (liberados > 0) {
      await this.auditar('ban_expirado', 'user', null, liberados + ' conta(s) reativada(s) por vencimento do banimento.', { contas: liberados });
    }

    const id = inteiro(req.query('id'));
    return id > 0 ? this.telaDossie(id) : this.telaLista(req);
  }

  // ================================================================================ ESCRITA

  /** POST /admin/jogadores/salvar — um endpoint, três ações (`acao`): conta, banir, revogar. */
  async salvar(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const alvo = await this.alvoOuFalha(inteiro(req.input('id')));

    switch (req.input('acao') ?? '') {
      case 'conta':
        return this.salvarConta(req, alvo);
      case 'banir':
        return this.banir(req, alvo);
      case 'revogar':
        return this.revogar(req, alvo);
      default:
        return this.voltarParaFicha(Number(alvo.id) | 0, 'error', 'Ação desconhecida.');
    }
  }

  /**
   * POST /admin/jogadores/excluir — só passa conta SEM RASTRO no mundo. Com rastro, a resposta
   * é um bloqueio que explica o que segura (apagar apagaria a prova).
   */
  async excluir(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const alvo = await this.alvoOuFalha(inteiro(req.input('id')));
    const id = Number(alvo.id) | 0;

    if (!Repo.podeAgirSobre(this.admin(), alvo)) {
      return this.voltarParaFicha(id, 'error', this.motivoSemAutoridade(alvo));
    }

    // O BLOQUEIO vem antes da confirmação: se não pode apagar de jeito nenhum, não cobra o nome.
    const refs = await Repo.referencias(id);
    if (Object.keys(refs).length > 0) {
      return this.voltarParaFicha(id, 'error', 'Conta não apagada: ela tem ' + this.listarReferencias(refs) + ' no histórico do jogo.');
    }

    // Confirmação por digitação: escrever o nome obriga a olhar QUAL conta é.
    const digitado = (req.input('confirmar', '') ?? '').trim();
    if (digitado !== String(alvo.username)) {
      return this.telaDossie(id, { confirmar: 'Digite exatamente ' + String(alvo.username) + ' para confirmar.' });
    }

    await Repo.excluirConta(id);
    await this.auditar('jogador_excluido', 'user', id, 'Conta ' + String(alvo.username) + ' apagada (sem histórico no jogo).', {
      username: alvo.username,
      email: alvo.email,
      role: alvo.role,
    });

    flash('ok', 'Conta ' + String(alvo.username) + ' apagada.');
    return this.redirect('admin/jogadores');
  }

  // ================================================================================== AÇÕES

  private async salvarConta(req: AppRequest, alvo: Dict): Promise<AppResponse> {
    const id = Number(alvo.id) | 0;
    const papel = req.input('papel', '') ?? '';
    let situac = req.input('situacao', '') ?? '';
    const erros: Record<string, string> = {};

    if (!Repo.podeAgirSobre(this.admin(), alvo)) {
      return this.voltarParaFicha(id, 'error', this.motivoSemAutoridade(alvo));
    }

    if (!Repo.PAPEIS.includes(papel)) {
      erros.papel = 'Escolha um papel válido.';
    } else if (Repo.peso(papel) >= Repo.peso(String(this.admin().role))) {
      // Promover alguém ao seu nível é criar um par capaz de te rebaixar.
      erros.papel = 'Você não pode conceder um papel igual ou superior ao seu.';
    }

    // 'banned' não é opção do formulário (regra 3). Numa conta banida o <select> chega
    // desabilitado e o navegador NÃO envia o campo: situação preservada, papel editável.
    const banida = String(alvo.status) === 'banned';
    if (banida) {
      if (situac !== '' && situac !== 'banned') {
        erros.situacao = 'Esta conta está banida. Revogue o banimento antes de mudar a situação.';
      }
      situac = 'banned';
    } else if (!['active', 'suspended'].includes(situac)) {
      erros.situacao = 'Escolha entre conta ativa e suspensa.';
    }

    if (Object.keys(erros).length > 0) {
      return this.telaDossie(id, erros, { old: { papel, situacao: situac } });
    }

    if (papel === String(alvo.role) && situac === String(alvo.status)) {
      return this.voltarParaFicha(id, 'info', 'Nada mudou: papel e situação já eram esses.');
    }

    await Repo.atualizarConta(id, papel, situac);
    await this.auditar(
      'jogador_atualizado',
      'user',
      id,
      'Conta ' + String(alvo.username) + ': ' + String(alvo.role) + '/' + String(alvo.status) + ' -> ' + papel + '/' + situac,
      { de: { role: alvo.role, status: alvo.status }, para: { role: papel, status: situac } },
    );

    return this.voltarParaFicha(id, 'ok', 'Conta de ' + String(alvo.username) + ' atualizada.');
  }

  private async banir(req: AppRequest, alvo: Dict): Promise<AppResponse> {
    const id = Number(alvo.id) | 0;
    const motivo = (req.input('motivo', '') ?? '').trim();
    const tipo = req.input('tipo', 'temp') ?? 'temp';
    const expira = (req.input('expira', '') ?? '').trim();
    const erros: Record<string, string> = {};

    if (!Repo.podeAgirSobre(this.admin(), alvo)) {
      return this.voltarParaFicha(id, 'error', this.motivoSemAutoridade(alvo));
    }
    if ((await Repo.banAtivo(id)) !== null) {
      return this.voltarParaFicha(id, 'error', 'Esta conta já tem um banimento em vigor. Revogue o atual antes de aplicar outro.');
    }

    if (tamanho(motivo) < MOTIVO_MIN) {
      erros.motivo = 'Descreva o motivo com pelo menos ' + MOTIVO_MIN + ' caracteres.';
    } else if (tamanho(motivo) > MOTIVO_MAX) {
      erros.motivo = 'O motivo passa de ' + MOTIVO_MAX + ' caracteres.';
    }

    if (!Repo.TIPOS_BAN.includes(tipo)) {
      erros.tipo = 'Escolha entre temporário e permanente.';
    }

    // O datetime-local é lido no horário de Brasília e gravado em UTC (a coluna é comparada
    // com datetime('now')) — o mesmo relógio da tela de banimentos.
    let quando: string | null = null;
    if (tipo === 'temp') {
      const lido = expira !== '' ? Fuso.strtotime(expira.replace('T', ' ')) : 0;
      const ts = Number.isNaN(lido) ? 0 : lido;
      const agora = agoraUnix();
      if (ts === 0) {
        erros.expira = 'Informe até quando o banimento vale.';
      } else if (ts <= agora) {
        erros.expira = 'A data de término precisa estar no futuro.';
      } else if (ts > agora + BAN_MAX_DIAS * 86400) {
        erros.expira = 'Prazo acima de ' + BAN_MAX_DIAS + ' dias: use banimento permanente.';
      } else {
        quando = formatarData(ts, 'Y-m-d H:i:s');
      }
    }

    if (Object.keys(erros).length > 0) {
      return this.telaDossie(id, erros, { old: { motivo, tipo, expira } });
    }

    const banId = await Repo.aplicarBan(id, this.adminId(), motivo, tipo, quando);
    await this.auditar(
      'jogador_banido',
      'user',
      id,
      'Banimento ' + (tipo === 'perma' ? 'permanente' : 'até ' + String(quando ?? '')) + ' em ' + String(alvo.username) + '.',
      { ban_id: banId, kind: tipo, expires_at: quando, reason: motivo },
    );

    flash(
      'ok',
      String(alvo.username) + ' foi banido' + (tipo === 'perma' ? ' permanentemente.' : ' até ' + Fuso.date('d/m/Y H:i', paraUnix(quando)) + '.'),
    );
    return this.redirect('admin/jogadores', { id });
  }

  private async revogar(req: AppRequest, alvo: Dict): Promise<AppResponse> {
    const id = Number(alvo.id) | 0;
    const ban = inteiro(req.input('ban_id'));

    if (!Repo.podeAgirSobre(this.admin(), alvo)) {
      return this.voltarParaFicha(id, 'error', this.motivoSemAutoridade(alvo));
    }

    if (!(await Repo.revogarBan(ban, id, this.adminId()))) {
      // Dois operadores revogando ao mesmo tempo, ou o prazo venceu entre desenhar e clicar.
      return this.voltarParaFicha(id, 'info', 'Este banimento já não estava mais em vigor.');
    }

    await this.auditar('ban_revogado', 'user', id, 'Banimento #' + ban + ' de ' + String(alvo.username) + ' revogado.', { ban_id: ban });

    return this.voltarParaFicha(id, 'ok', String(alvo.username) + ' está liberado para jogar.');
  }

  // ============================================================ MONTAGEM DAS DUAS TELAS

  private async telaLista(req: AppRequest): Promise<AppResponse> {
    const filtros = {
      q: corta(req.query('q') ?? '', 60).trim(),
      papel: req.query('papel') ?? '',
      situacao: req.query('situacao') ?? '',
      ordem: req.query('ordem') ?? 'recentes',
      pagina: Math.max(1, inteiro(req.query('p') ?? '1')),
    };
    // A URL escolhe entre valores conhecidos; o que não está na lista simplesmente não filtra.
    if (!Repo.PAPEIS.includes(filtros.papel)) filtros.papel = '';
    if (!Repo.SITUACOES.includes(filtros.situacao)) filtros.situacao = '';
    if (!Repo.ordens().includes(filtros.ordem)) filtros.ordem = 'recentes';

    const [lista, abas, resumo] = await Promise.all([Repo.listar(filtros), Repo.contagemPorSituacao(), Repo.resumo()]);
    const page = this.adminPage('jogadores', 'Jogadores', 'Contas, progresso e sanções');

    return this.render(adminJogadores, page, {
      modo: 'lista',
      cromo: dataMock('admin-jogadores'),
      operador: this.admin(),
      filtros,
      lista,
      abas,
      resumo,
      filtrado: filtros.q !== '' || filtros.papel !== '' || filtros.situacao !== '',
    });
  }

  /**
   * Dossiê de uma conta. `erros` e `extra` existem para o caminho de VALIDAÇÃO FALHA: a mesma
   * tela é redesenhada com a mensagem no campo culpado, sem perder o que foi digitado.
   */
  private async telaDossie(id: number, erros: Record<string, string> = {}, extra: Dict = {}): Promise<AppResponse> {
    const conta = await Repo.conta(id);
    if (conta === null) throw new HttpException(404, 'Jogador não encontrado.');

    const operador = this.admin();
    const page = this.adminPage('jogadores', String(conta.username ?? 'Jogador'), 'Ficha completa da conta');

    const [banAtivo, bans, inventario, missoes, conquistas, faccoes, partidas, referencias] = await Promise.all([
      Repo.banAtivo(id),
      Repo.historicoBans(id),
      Repo.inventario(id),
      Repo.missoes(id),
      Repo.conquistas(id),
      Repo.faccoes(id),
      Repo.partidas(id),
      Repo.referencias(id),
    ]);

    // `$extra + [...]` do PHP: as chaves de `extra` vencem.
    return this.render(adminJogadores, page, {
      modo: 'ficha',
      cromo: dataMock('admin-jogadores'),
      operador,
      conta,
      erros,
      // A view NÃO recalcula permissão: recebe pronto o que pode e o porquê de não poder.
      pode_agir: Repo.podeAgirSobre(operador, conta),
      e_voce: (Number(operador.id) | 0) === (Number(conta.id) | 0),
      papeis_ok: this.papeisConcedeveis(),
      ban_ativo: banAtivo,
      bans,
      inventario,
      missoes,
      conquistas,
      faccoes,
      partidas,
      referencias,
      ...extra,
    });
  }

  // ================================================================================== APOIO

  /** Conta alvo de uma escrita. 404 quando o id não existe (POST forjado não merece flash). */
  private async alvoOuFalha(id: number): Promise<Dict> {
    const alvo = await Repo.conta(id);
    if (alvo === null) throw new HttpException(404, 'Jogador não encontrado.');
    return alvo;
  }

  /** Papéis que ESTE operador pode conceder (todos abaixo do dele). */
  private papeisConcedeveis(): string[] {
    const meu = Repo.peso(String(this.admin().role));
    return Repo.PAPEIS.filter((p) => Repo.peso(p) < meu);
  }

  /** A recusa diz QUAL das duas regras barrou. */
  private motivoSemAutoridade(alvo: Dict): string {
    return this.adminId() === (Number(alvo.id) | 0)
      ? 'Você não pode alterar a sua própria conta pelo painel.'
      : 'Sem autoridade sobre ' + String(alvo.username) + ': o painel só age sobre contas de papel inferior ao seu.';
  }

  /** "3 partidas, 1 compra e 2 punições" — a frase do bloqueio de exclusão. */
  private listarReferencias(refs: Record<string, number>): string {
    const rotulos: Record<string, [string, string]> = {
      partidas: ['partida', 'partidas'],
      compras: ['compra', 'compras'],
      punicoes: ['punição', 'punições'],
      itens: ['item', 'itens'],
      missoes: ['missão', 'missões'],
      conquistas: ['conquista', 'conquistas'],
    };
    const partes = Object.entries(refs).map(([chave, n]) => {
      const par = rotulos[chave] ?? [chave, chave];
      return n + ' ' + (n === 1 ? par[0] : par[1]);
    });
    if (partes.length > 1) {
      const ultima = partes.pop()!;
      return partes.join(', ') + ' e ' + ultima;
    }
    return partes[0] ?? 'histórico';
  }

  /** POST-Redirect-GET: o F5 depois de salvar não repete a escrita. */
  private voltarParaFicha(id: number, tipo: string, msg: string): AppResponse {
    flash(tipo, msg);
    return this.redirect('admin/jogadores', { id });
  }
}
