/**
 * /api/v1/servers — estado da infraestrutura e o interruptor de manutenção (App\Api\ServidoresRecurso).
 *
 * Devolve o estado EFETIVO (janela ativa = manutenção) junto com o gravado. O interruptor é a
 * chave `maintenance_enabled` de admin_settings — a mesma que o guard de manutenção lê.
 */
import type { AppResponse } from '../../core/response';
import { validar as validarCampo, mbLen } from '../campo';
import type { Contexto } from '../contexto';
import * as Infra from '../infra-repo';
import { Recurso, type Params, type Rota } from '../recurso';
import type { Requisicao } from '../requisicao';
import { erro, ok } from '../resposta';

function texto(v: unknown): string {
  if (v === null || v === undefined || v === false) return '';
  if (v === true) return '1';
  return typeof v === 'object' ? 'Array' : String(v);
}

export class ServidoresRecurso extends Recurso {
  nome(): string {
    return 'servers';
  }

  rotas(): Rota[] {
    return [
      ['GET', '', 'estado', 'Regiões, serviços, janelas e modo manutenção.', ''],
      ['PATCH', 'maintenance', 'manutencao', 'Liga/desliga o modo manutenção do jogo.', ''],
      ['PATCH', 'regions/{id}', 'regiao', 'Troca o estado de uma região (ok|warn|down).', ''],
      ['PATCH', 'services/{id}', 'servico', 'Troca o estado de um serviço (ok|warn|down).', ''],
    ];
  }

  protected async estado(): Promise<AppResponse> {
    const [ligada, mensagem] = await Infra.manutencao();
    const regioes = await Infra.regioes();
    const servicos = await Infra.servicos();
    const janelas = await Infra.janelas();

    return ok({
      maintenance: { enabled: ligada, message: mensagem },
      summary: await Infra.resumoServidores(),
      regions: regioes.map((r) => ({
        id: r.id,
        slug: r.slug,
        label: r.label,
        // `state` é o gravado; `effective_state` é o que vale agora (com as janelas).
        state: r.state,
        effective_state: r.estado,
        ping_ms: r.ping,
        players: r.jogadores,
        note: r.nota,
      })),
      services: servicos.map((s) => ({
        id: s.id,
        slug: s.slug,
        label: s.label,
        state: s.state,
        status_label: s.rotulo,
        maint_scope: s.escopo,
        maint_starts_at: (s.inicio as number) > 0 ? Infra.fmt(s.inicio as number) : null,
        maint_minutes: s.minutos,
      })),
      // Só a primeira página de janelas (8, mais recentes primeiro): é estado, não agenda.
      windows: janelas.map((j) => ({
        id: j.id,
        region: j.regiao,
        starts_at: Infra.fmt(j.inicio as number),
        ends_at: Infra.fmt(j.fim as number),
        minutes: j.minutos,
        state: j.situacao,
        note: j.nota,
      })),
    });
  }

  /** Liga/desliga. Corpo: {"enabled": true, "message": "..."}; a mensagem é gravada ANTES do interruptor. */
  protected async manutencao(req: Requisicao, ctx: Contexto): Promise<AppResponse> {
    const corpo = req.corpo();
    if (!('enabled' in corpo)) return erro('entrada_invalida', '', { enabled: 'Campo obrigatório (true ou false).' });

    const r = await validarCampo(corpo.enabled, { tipo: 'bool' });
    if (!r.ok) return erro('entrada_invalida', '', { enabled: r.erro });
    const ligar = Number(r.valor) | 0;

    if ('message' in corpo) {
      const msg = texto(corpo.message).trim();
      if (mbLen(msg) > 240) {
        return erro('entrada_invalida', '', { message: 'A mensagem não pode passar de 240 caracteres.' });
      }
      await Infra.gravarConfiguracao('maintenance_message', msg, 0);
    }

    const mudou = await Infra.gravarConfiguracao('maintenance_enabled', String(ligar), 0);
    if (mudou) {
      await ctx.auditar(
        'manutencao',
        'admin_settings',
        'maintenance_enabled',
        (ligar === 1 ? 'Modo manutenção LIGADO' : 'Modo manutenção DESLIGADO') + ' pela API.',
        { enabled: ligar === 1 },
      );
    }
    const [agora, mensagem] = await Infra.manutencao();
    return ok({ maintenance: { enabled: agora, message: mensagem }, changed: mudou });
  }

  protected regiao(req: Requisicao, ctx: Contexto, params: Params): Promise<AppResponse> {
    return this.trocar(req, ctx, params, 'regiao', 'server_regions');
  }

  protected servico(req: Requisicao, ctx: Contexto, params: Params): Promise<AppResponse> {
    return this.trocar(req, ctx, params, 'servico', 'server_services');
  }

  /** Corpo dos dois PATCH de estado: {"state": "ok|warn|down"}. */
  private async trocar(req: Requisicao, ctx: Contexto, params: Params, tipo: 'regiao' | 'servico', tabela: string): Promise<AppResponse> {
    const id = this.id(params);
    const atual = tipo === 'regiao' ? await Infra.regiao(id) : await Infra.servico(id);
    if (atual === null) return this.naoEncontrado(tipo === 'regiao' ? 'Região não encontrada.' : 'Serviço não encontrado.');

    const novo = texto(req.corpo().state ?? '').trim().toLowerCase();
    if (!Infra.ESTADOS_SERVIDOR[novo]) {
      return erro('entrada_invalida', '', { state: `Estado aceito: ${Object.keys(Infra.ESTADOS_SERVIDOR).join(', ')}.` });
    }
    if (String(atual.state) === novo) return ok({ id, state: novo, changed: false });

    await Infra.trocarEstado(tipo, id, novo);
    const Tipo = tipo.charAt(0).toUpperCase() + tipo.slice(1);
    await ctx.auditar(
      'estado_servidor',
      tabela,
      id,
      `${Tipo} "${String(atual.label)}": estado ${String(atual.state)} -> ${novo}, pela API.`,
      { de: atual.state, para: novo },
    );
    return ok({ id, state: novo, changed: true });
  }
}
