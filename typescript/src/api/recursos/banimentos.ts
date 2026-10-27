/**
 * /api/v1/bans — histórico de punições, aplicar e revogar (App\Api\BanimentosRecurso).
 *
 * O ÚNICO caminho da API para punir. `DELETE /bans/{id}` REVOGA (grava revoked_at, autor e
 * motivo e recalcula a conta) — a linha nunca sai da tabela. O motivo é obrigatório.
 */
import { url } from '../../core/helpers';
import type { AppResponse } from '../../core/response';
import { dataValida, mbLen } from '../campo';
import type { Contexto } from '../contexto';
import * as Gestao from '../gestao-repo';
import { lerDataSp } from '../../core/relogio';
import { fmt } from '../infra-repo';
import { Recurso, type Params, type Rota } from '../recurso';
import { PREFIXO, type Requisicao } from '../requisicao';
import { erro, ok, paginacao } from '../resposta';

function texto(v: unknown): string {
  if (v === null || v === undefined || v === false) return '';
  if (v === true) return '1';
  return typeof v === 'object' ? 'Array' : String(v);
}

/** (int) do PHP para um valor do corpo. */
function inteiro(v: unknown): number {
  if (typeof v === 'number') return Math.trunc(v);
  if (typeof v === 'boolean') return v ? 1 : 0;
  const m = /^\s*[+-]?\d+/.exec(texto(v));
  return m ? parseInt(m[0], 10) : 0;
}

const corta = (s: string, n: number) => [...s].slice(0, n).join('');

/**
 * Prazo: dia (vira fim do dia) ou data e hora. null quando inválido OU já passou.
 * Lido como UTC, como todas as datas do D1 (no PHP era o fuso do servidor).
 */
function prazo(bruto: string): string | null {
  bruto = bruto.trim();
  if (bruto === '') return null;
  let quando: string;
  if (dataValida(bruto)) {
    quando = bruto + ' 23:59:59';
  } else {
    const m = /^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2}):(\d{2})$/.exec(bruto);
    if (!m || !dataValida(m[1]!) || Number(m[2]) > 23 || Number(m[3]) > 59 || Number(m[4]) > 59) return null;
    quando = bruto;
  }
  // O integrador manda o prazo no horário de Brasília (como no PHP, e como a tela de
  // banimentos lê o <input>); a coluna guarda UTC, igual ao datetime('now') que a compara.
  const t = Math.floor(lerDataSp(quando) / 1000);
  return t > Math.floor(Date.now() / 1000) ? fmt(t) : null;
}

export class BanimentosRecurso extends Recurso {
  nome(): string {
    return 'bans';
  }

  rotas(): Rota[] {
    return [
      ['GET', '', 'listar', 'Lista punições (todas, não só as vigentes).', '?user_id=&state=ativo&kind=temp&limit=50'],
      ['GET', '{id}', 'obter', 'Uma punição pelo id.', ''],
      ['POST', '', 'criar', 'Aplica uma punição e bloqueia a conta.', ''],
      ['DELETE', '{id}', 'revogar', 'Revoga a punição e devolve a conta ao jogo.', ''],
    ];
  }

  protected async listar(req: Requisicao): Promise<AppResponse> {
    const [limite, deslocamento] = this.pagina(req);
    const r = await Gestao.banimentos(
      { user_id: req.consultaInt('user_id', 0, 0, 999999999), state: req.consulta('state'), kind: req.consulta('kind') },
      limite,
      deslocamento,
    );
    return ok(r.linhas, paginacao(r.total, limite, deslocamento));
  }

  protected async obter(_req: Requisicao, _ctx: Contexto, params: Params): Promise<AppResponse> {
    const ban = await Gestao.banimento(this.id(params));
    return ban === null ? this.naoEncontrado('Punição não encontrada.') : ok(ban);
  }

  /** Campos: user_id, kind (temp|perma|suspensao), reason, expires_at (nos tipos com prazo). */
  protected async criar(req: Requisicao, ctx: Contexto): Promise<AppResponse> {
    const corpo = req.corpo();
    const erros: Record<string, string> = {};

    const userId = inteiro(corpo.user_id ?? 0);
    const conta = userId > 0 ? await Gestao.conta(userId) : null;
    if (conta === null) erros.user_id = 'Informe o id de uma conta existente.';
    else if (String(conta.role) === 'owner') erros.user_id = 'A conta do dono do jogo não pode ser punida pela API.';

    const tipo = texto(corpo.kind ?? 'temp').trim().toLowerCase();
    if (!Gestao.TIPOS_BAN[tipo]) erros.kind = `Tipo aceito: ${Object.keys(Gestao.TIPOS_BAN).join(', ')}.`;

    const motivo = texto(corpo.reason ?? '').trim();
    if (motivo === '') erros.reason = 'O motivo é obrigatório: é ele que o jogador lê ao ser barrado.';
    else if (mbLen(motivo) > 1000) erros.reason = 'O motivo não pode passar de 1000 caracteres.';

    let expira: string | null = null;
    if (Object.keys(erros).length === 0 && Gestao.TIPOS_BAN[tipo]!.prazo) {
      expira = prazo(texto(corpo.expires_at ?? ''));
      if (expira === null) {
        erros.expires_at = `Tipo "${tipo}" exige prazo futuro em AAAA-MM-DD ou AAAA-MM-DD HH:MM:SS.`;
      }
    }
    if (Object.keys(erros).length) return erro('entrada_invalida', '', erros);

    if ((await Gestao.banAtivo(userId)) !== null) {
      return erro('conflito', 'Esta conta já tem punição vigente. Revogue a atual antes de aplicar outra.');
    }

    // admin_id NULO: quem puniu foi uma chave, não uma pessoa (a chave vai para a auditoria).
    const banId = await Gestao.aplicarBan(userId, null, tipo, motivo, expira);
    await ctx.auditar('ban_aplicado', 'bans', banId, `Punição ${tipo} aplicada a ${String(conta!.username)} pela API.`, {
      user_id: userId,
      kind: tipo,
      expires_at: expira,
      reason: corta(motivo, 180),
    });

    return ok((await Gestao.banimento(banId)) ?? { id: banId }, {}, 201).withHeader(
      'Location',
      url(`${PREFIXO.replace(/^\/+/, '')}/bans/${banId}`),
    );
  }

  protected async revogar(req: Requisicao, ctx: Contexto, params: Params): Promise<AppResponse> {
    const id = this.id(params);
    const ban = await Gestao.banimento(id);
    if (ban === null) return this.naoEncontrado('Punição não encontrada.');

    const motivo = texto(req.corpo().reason ?? '').trim();
    if (motivo === '') {
      return erro('entrada_invalida', '', {
        reason: 'Informe `reason`: revogar sem motivo deixa a punição e o perdão igualmente inexplicáveis.',
      });
    }
    if (!(await Gestao.revogarBan(id, null, corta(motivo, 1000)))) {
      return erro('conflito', 'Esta punição já estava revogada.');
    }
    await ctx.auditar('ban_revogado', 'bans', id, `Punição #${id} de ${String(ban.username)} revogada pela API.`, {
      user_id: ban.user_id,
      reason: corta(motivo, 180),
    });
    return ok((await Gestao.banimento(id)) ?? []);
  }
}
