/**
 * /api/v1/players — contas, papel, situação e progresso (App\Api\JogadoresRecurso).
 *
 * Duas travas: `owner` não existe para a API (nem para gravar, nem para alterar), e nada
 * aqui apaga conta. Punir é POST /bans — um `status: banned` solto seria desfeito pelo guard.
 */
import type { AppResponse } from '../../core/response';
import type { Contexto } from '../contexto';
import * as Gestao from '../gestao-repo';
import { Recurso, type Params, type Rota } from '../recurso';
import type { Requisicao } from '../requisicao';
import { erro, ok, paginacao } from '../resposta';

/** Papéis que a API pode GRAVAR. */
const PAPEIS_GRAVAVEIS = ['player', 'admin'];

/** (string) do PHP para um valor do corpo JSON. */
function texto(v: unknown): string {
  if (v === null || v === undefined || v === false) return '';
  if (v === true) return '1';
  return typeof v === 'object' ? 'Array' : String(v);
}

export class JogadoresRecurso extends Recurso {
  nome(): string {
    return 'players';
  }

  rotas(): Rota[] {
    return [
      ['GET', '', 'listar', 'Lista contas (sem e-mail: use /players/{id}).', '?q=&role=player&status=active&limit=50&offset=0'],
      ['GET', '{id}', 'obter', 'Uma conta, com ficha de jogador e punição vigente.', ''],
      ['PATCH', '{id}', 'atualizar', 'Altera papel (player|admin) e/ou situação.', ''],
      ['GET', '{id}/progress', 'progresso', 'Progresso: temporada, missões e conquistas.', ''],
    ];
  }

  protected async listar(req: Requisicao): Promise<AppResponse> {
    const [limite, deslocamento] = this.pagina(req);
    const r = await Gestao.jogadores(
      { q: req.consulta('q'), role: req.consulta('role'), status: req.consulta('status') },
      limite,
      deslocamento,
    );
    return ok(r.linhas, paginacao(r.total, limite, deslocamento));
  }

  protected async obter(_req: Requisicao, _ctx: Contexto, params: Params): Promise<AppResponse> {
    const c = await Gestao.jogador(this.id(params));
    return c === null ? this.naoEncontrado('Conta não encontrada.') : ok(c);
  }

  protected async progresso(_req: Requisicao, _ctx: Contexto, params: Params): Promise<AppResponse> {
    const p = await Gestao.progresso(this.id(params));
    return p === null ? this.naoEncontrado('Conta não encontrada.') : ok(p);
  }

  /** Altera papel e/ou situação (parcial). Situação só volta para `active`; punir é POST /bans. */
  protected async atualizar(req: Requisicao, ctx: Contexto, params: Params): Promise<AppResponse> {
    const id = this.id(params);
    const conta = await Gestao.conta(id);
    if (conta === null) return this.naoEncontrado('Conta não encontrada.');
    if (String(conta.role) === 'owner') {
      return erro('nao_permitido', 'A conta do dono do jogo não pode ser alterada pela API.');
    }

    const corpo = req.corpo();
    const erros: Record<string, string> = {};
    let papel = String(conta.role);
    let estado = String(conta.status);

    if ('role' in corpo) {
      const novo = texto(corpo.role).trim().toLowerCase();
      if (!PAPEIS_GRAVAVEIS.includes(novo)) erros.role = `Papel aceito: ${PAPEIS_GRAVAVEIS.join(' ou ')}.`;
      else papel = novo;
    }
    if ('status' in corpo) {
      const novo = texto(corpo.status).trim().toLowerCase();
      if (!Gestao.SITUACOES.includes(novo)) {
        erros.status = `Situação aceita: ${Gestao.SITUACOES.join(', ')}.`;
      } else if (novo !== 'active') {
        erros.status =
          'Para punir uma conta use POST /api/v1/bans, que grava motivo e prazo. ' +
          'Um status de punição sem punição registrada seria desfeito pelo próprio jogo.';
      } else {
        estado = novo;
      }
    }

    if (Object.keys(erros).length) return erro('entrada_invalida', '', erros);
    if (papel === String(conta.role) && estado === String(conta.status)) {
      // Nada mudou: não suja a trilha.
      return ok((await Gestao.jogador(id)) ?? []);
    }

    // Voltar a `active` com punição vigente exige revogar o ban (DELETE /bans/{id}).
    if (estado === 'active' && String(conta.status) !== 'active' && (await Gestao.banAtivo(id)) !== null) {
      return erro(
        'conflito',
        'Esta conta tem punição vigente. Revogue por DELETE /api/v1/bans/{id} — assim a situação é recalculada com registro do motivo.',
      );
    }

    await Gestao.atualizarConta(id, papel, estado);
    await ctx.auditar(
      'jogador_alterado',
      'users',
      id,
      `Conta ${String(conta.username)}: papel ${String(conta.role)} -> ${papel}, situação ${String(conta.status)} -> ${estado}, pela API.`,
      { de: { role: conta.role, status: conta.status }, para: { role: papel, status: estado } },
    );
    return ok((await Gestao.jogador(id)) ?? []);
  }
}
