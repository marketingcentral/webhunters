/**
 * O DESPACHANTE DE /api/v1 — App\Controllers\Api\ApiController do PHP. A ORDEM É A SEGURANÇA:
 *
 *   1. portas abertas (/api/v1 e /api/v1/health) — respondem e saem;
 *   2. AUTENTICA a chave (Authorization: Bearer)   -> 401
 *   3. LIMITE por minuto daquela chave             -> 429
 *   4. acha o RECURSO                              -> 404
 *   5. acha a ROTA dentro do recurso               -> 404 ou 405 (com Allow)
 *   6. confere o ESCOPO exigido pelo método        -> 403
 *   7. confere o CORPO (JSON válido, tamanho)      -> 400 ou 413
 *   8. executa e registra
 *
 * Sem sessão e sem CSRF (credencial é o cabeçalho). TODA resposta é JSON, inclusive a falha
 * inesperada: o try/catch impede que a exceção chegue ao front controller e vire HTML.
 * A sessão: o src/index.ts não lê o cookie nas rotas /api/v1 e nada neste caminho grava nela,
 * então não nasce linha em `sessions` nem sai Set-Cookie.
 */
import { APP_NAME } from '../core/config';
import { ctx as ctxRequisicao } from '../core/context';
import * as DB from '../core/database';
import { url } from '../core/helpers';
import type { AppRequest } from '../core/request';
import type { AppResponse } from '../core/response';
import type { Params as RotaParams } from '../core/router';
import * as ApiKeyRepo from '../repo/api-key-repo';
import * as ApiLogRepo from '../repo/api-log-repo';
import * as Catalogo from './catalogo';
import { Contexto } from './contexto';
import * as Escopos from './escopos';
import { manutencao } from './infra-repo';
import type { Recurso, RotaReferencia } from './recurso';
import { BanimentosRecurso } from './recursos/banimentos';
import { ConfiguracoesRecurso } from './recursos/configuracoes';
import { EconomiaRecurso } from './recursos/economia';
import { EventosRecurso } from './recursos/eventos';
import { JogadoresRecurso } from './recursos/jogadores';
import { MapasRecurso } from './recursos/mapas';
import { ServidoresRecurso } from './recursos/servidores';
import { PREFIXO, Requisicao } from './requisicao';
import * as Resposta from './resposta';
import { TabelaRecurso } from './tabela-recurso';

let cacheRecursos: Record<string, Recurso> | null = null;

/**
 * Os recursos da API, montados uma vez por isolate. É AQUI que uma coleção nova entra em
 * operação: uma linha nesta lista (e o escopo em escopos.RECURSOS).
 */
export function recursos(): Record<string, Recurso> {
  if (cacheRecursos) return cacheRecursos;
  const c = Catalogo.colecoes();
  const lista: Recurso[] = [
    new JogadoresRecurso(),
    new BanimentosRecurso(),
    new TabelaRecurso('missions', c.missions!, 'missões', 'missão'),
    new TabelaRecurso('achievements', c.achievements!, 'conquistas', 'conquista'),
    new MapasRecurso(),
    new TabelaRecurso('items', c.items!, 'itens', 'item'),
    new TabelaRecurso('shop', c.shop!, 'ofertas da loja', 'oferta'),
    new EventosRecurso(),
    new ServidoresRecurso(),
    new EconomiaRecurso(),
    new ConfiguracoesRecurso(),
  ];
  const out: Record<string, Recurso> = {};
  for (const r of lista) {
    const nome = r.nome();
    // Recurso sem escopo no catálogo responderia 403 para sempre: falha cedo.
    if (!Escopos.RECURSOS[nome]) throw new Error('Recurso da API sem escopo declarado: ' + nome);
    out[nome] = r;
  }
  return (cacheRecursos = out);
}

export interface ReferenciaRecurso {
  recurso: string;
  rotulo: string;
  icone: string;
  descricao: string;
  rotas: RotaReferencia[];
}

/** A referência completa, para a tela /admin/api desenhar (a documentação É a tabela de rotas). */
export function referencia(): ReferenciaRecurso[] {
  return Object.entries(recursos()).map(([nome, r]) => {
    const meta = Escopos.RECURSOS[nome]!;
    return { recurso: nome, rotulo: meta.rotulo, icone: meta.icone, descricao: meta.descricao, rotas: r.referencia() };
  });
}

/** O prefixo de uma chave apresentada, para o log — sem nunca guardar o segredo. */
function prefixoDe(apresentada: string): string | null {
  if (apresentada === '') return null;
  const pedaco = apresentada.split('_')[0] ?? '';
  return /^[a-z0-9]{1,24}$/.test(pedaco) ? pedaco : 'invalido';
}

/** O `error.code` da resposta, para a coluna do log (lido do corpo: nenhum caminho esquece). */
function codigoDoCorpo(r: AppResponse): string {
  if (r.status < 400) return '';
  try {
    const d = JSON.parse(r.body) as { error?: { code?: unknown } };
    return String(d?.error?.code ?? '');
  } catch {
    return '';
  }
}

/** Os três cabeçalhos de limite, em TODA resposta autenticada (janela deslizante: reset = 60 s). */
function comLimite(r: AppResponse, limite: number, restantes: number): AppResponse {
  return r
    .withHeader('X-RateLimit-Limit', String(limite))
    .withHeader('X-RateLimit-Remaining', String(restantes))
    .withHeader('X-RateLimit-Reset', String(ApiLogRepo.JANELA_SEG));
}

export class ApiController {
  /** Ponto de entrada de TODAS as rotas /api/v1/* (os params do roteador são ignorados). */
  async dispatch(request: AppRequest, _params: RotaParams = {}): Promise<AppResponse> {
    const inicio = Date.now();
    const req = await Requisicao.de(request);

    let chaveId: number | null = null;
    let prefixo: string | null = null;
    let resposta: AppResponse;

    try {
      const recursoNome = req.recurso();
      if (recursoNome === '') {
        resposta = this.indice(req);
      } else if (recursoNome === 'health' && req.segmentos().length === 1) {
        resposta = await this.saude(req);
      } else {
        // 2. autenticação. O prefixo APRESENTADO vai para o log mesmo quando a chave não vale.
        const apresentada = req.chaveApresentada();
        const auth = await ApiKeyRepo.autenticar(apresentada);
        prefixo = prefixoDe(apresentada);

        if (!auth.ok || !auth.chave) {
          resposta = Resposta.erro(auth.motivo).withHeader('WWW-Authenticate', 'Bearer realm="Extract Hunters API"');
        } else {
          chaveId = auth.chave.id;
          prefixo = auth.chave.prefix;
          resposta = await this.atender(req, new Contexto(auth.chave, req.ip()));
        }
      }
    } catch (e) {
      // A causa vai para o log, NUNCA para o corpo (salvo em modo debug, como no PHP).
      const err = e as Error;
      console.error(`[extracthunters] api ${req.metodo()} ${req.caminho()}:`, err);
      resposta = ctxRequisicao().debug
        ? Resposta.erro('erro_interno', String(err?.message ?? err), { stack: String(err?.stack ?? '').split('\n').slice(0, 4).join('\n') })
        : Resposta.erro('erro_interno');
    }

    // registro
    const ms = Date.now() - inicio;
    await ApiLogRepo.registrar(chaveId, prefixo, req.metodo(), req.caminho(), resposta.status, codigoDoCorpo(resposta), req.ip(), ms);
    if (chaveId !== null && chaveId > 0) await ApiKeyRepo.marcarUso(chaveId, req.ip());

    return resposta.withHeader('X-Response-Time', `${ms}ms`);
  }

  /** Passos 3 a 8, já com a chave autenticada. */
  private async atender(req: Requisicao, ctx: Contexto): Promise<AppResponse> {
    // 3. limite
    const limite = ApiKeyRepo.limiteDe(ctx.chave);
    const usadas = await ApiLogRepo.noMinuto(ctx.chaveId());
    // -1 = a contagem falhou: deixa passar (falha de leitura não vira apagão da API).
    if (usadas >= 0 && usadas >= limite) {
      return comLimite(Resposta.erro('limite_excedido').withHeader('Retry-After', String(ApiLogRepo.JANELA_SEG)), limite, 0);
    }
    const restantes = usadas < 0 ? limite : Math.max(0, limite - usadas - 1);

    // 4. recurso
    const nome = req.recurso();
    const recurso = recursos()[nome];
    if (!recurso) {
      return comLimite(
        Resposta.erro('recurso_ausente', `Recurso "${nome}" não existe. Veja GET /api/v1 para a lista.`),
        limite,
        restantes,
      );
    }

    // 5. rota
    const casou = recurso.casar(req.segmentos().slice(1), req.metodo());
    if (casou.metodos) {
      return comLimite(
        Resposta.erro('metodo_invalido', `Métodos aceitos neste caminho: ${casou.metodos.join(', ')}.`).withHeader(
          'Allow',
          casou.metodos.join(', '),
        ),
        limite,
        restantes,
      );
    }
    if (!casou.rota) return comLimite(Resposta.erro('rota_ausente'), limite, restantes);

    // 6. escopo
    const escopo = Escopos.paraMetodo(nome, req.metodo());
    if (!ctx.temEscopo(escopo)) {
      return comLimite(
        Resposta.erro('escopo_ausente', `Este endpoint exige o escopo "${escopo}", que esta chave não tem.`, {
          required: escopo,
          granted: ctx.escopos(),
        }),
        limite,
        restantes,
      );
    }

    // 7. corpo
    if (req.escreve()) {
      const erroCorpo = req.erroCorpo();
      if (erroCorpo !== '') return comLimite(Resposta.erro(erroCorpo), limite, restantes);
    }

    // 8. execução
    return comLimite(await recurso.executar(casou.rota, casou.params ?? {}, req, ctx), limite, restantes);
  }

  /** GET /api/v1 — o índice, sem chave de propósito. */
  private indice(req: Requisicao): AppResponse {
    if (req.metodo() !== 'GET') return Resposta.erro('metodo_invalido').withHeader('Allow', 'GET');
    const lista: Record<string, unknown> = {};
    for (const nome of Object.keys(recursos())) {
      lista[nome] = {
        href: url(`${PREFIXO.replace(/^\/+/, '')}/${nome}`),
        label: Escopos.RECURSOS[nome]?.rotulo ?? nome,
        scopes: [`${nome}:read`, `${nome}:write`],
      };
    }
    return Resposta.ok({
      service: `${APP_NAME} — API de gestão`,
      version: 'v1',
      auth: 'Authorization: Bearer <chave>',
      resources: lista,
    });
  }

  /** GET /api/v1/health — sem chave: o monitor externo precisa responder "está de pé?" antes de credencial. */
  private async saude(req: Requisicao): Promise<AppResponse> {
    if (req.metodo() !== 'GET') return Resposta.erro('metodo_invalido').withHeader('Allow', 'GET');
    let banco = false;
    try {
      banco = Number(await DB.value('SELECT 1')) === 1;
    } catch {
      banco = false;
    }
    const [ligada] = banco ? await manutencao() : [false];
    // Banco fora é 503; manutenção ligada NÃO (é estado deliberado).
    return Resposta.ok(
      {
        status: banco ? (ligada ? 'maintenance' : 'ok') : 'degraded',
        database: banco,
        maintenance: Boolean(ligada),
        time: new Date().toISOString().replace(/\.\d{3}Z$/, '+00:00'),
      },
      {},
      banco ? 200 : 503,
    );
  }
}
