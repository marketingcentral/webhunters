/**
 * O ENVELOPE ÚNICO DA API — App\Core\Api\Resposta do PHP.
 *
 *   sucesso: { "ok": true, "data": ..., "meta": {...} }   (meta só quando existe)
 *   erro:    { "ok": false, "error": { "code", "message", "details"? } }
 *
 * O status HTTP sai do catálogo CODIGOS junto com o `code`: quem chama não escolhe.
 */
import { AppResponse } from '../core/response';

export type Obj = Record<string, unknown>;

/** Catálogo fechado de erros: código => [HTTP, mensagem padrão]. */
export const CODIGOS: Record<string, [number, string]> = {
  // autenticação e autorização
  chave_ausente: [401, 'Envie a chave da API no cabeçalho Authorization: Bearer <chave>.'],
  chave_invalida: [401, 'Chave da API inválida.'],
  chave_revogada: [401, 'Esta chave foi revogada.'],
  chave_expirada: [401, 'Esta chave venceu.'],
  escopo_ausente: [403, 'A chave não tem o escopo exigido por este endpoint.'],
  // rota
  recurso_ausente: [404, 'Recurso não existe nesta API.'],
  rota_ausente: [404, 'Endpoint não existe nesta API.'],
  nao_encontrado: [404, 'Registro não encontrado.'],
  metodo_invalido: [405, 'Método não permitido para este endpoint.'],
  // entrada
  json_invalido: [400, 'O corpo da requisição não é um objeto JSON válido.'],
  corpo_grande: [413, 'Corpo da requisição grande demais.'],
  entrada_invalida: [422, 'Há campos inválidos na requisição.'],
  conflito: [409, 'O registro entra em conflito com outro já existente.'],
  nao_permitido: [403, 'Esta operação não é permitida sobre este registro.'],
  // vazão e infraestrutura
  limite_excedido: [429, 'Limite de requisições por minuto excedido para esta chave.'],
  indisponivel: [503, 'Serviço indisponível no momento.'],
  erro_interno: [500, 'Erro interno ao processar a requisição.'],
};

/** Objeto/lista vazio? (o `$x !== []` do PHP, que vale para os dois) */
function vazio(v: unknown): boolean {
  if (Array.isArray(v)) return v.length === 0;
  return v !== null && typeof v === 'object' && Object.keys(v).length === 0;
}

/** Sucesso. `meta` só aparece quando tem conteúdo. */
export function ok(dados: unknown, meta: Obj = {}, status = 200): AppResponse {
  const corpo: Obj = { ok: true, data: dados };
  if (!vazio(meta)) corpo.meta = meta;
  return json(corpo, status);
}

/** Erro. Código fora do catálogo é defeito de programação: vira 500 genérico. */
export function erro(codigo: string, mensagem = '', detalhes: Obj = {}): AppResponse {
  let par = CODIGOS[codigo];
  if (!par) {
    console.error(`[extracthunters] api: código de erro desconhecido "${codigo}"`);
    codigo = 'erro_interno';
    par = CODIGOS.erro_interno!;
  }
  const [status, padrao] = par;
  const e: Obj = { code: codigo, message: mensagem !== '' ? mensagem : padrao };
  if (!vazio(detalhes)) e.details = detalhes;
  return json({ ok: false, error: e }, status);
}

/** Metadados de paginação, no formato que TODA listagem usa. */
export function paginacao(total: number, limite: number, deslocamento: number): Obj {
  return { total, limit: limite, offset: deslocamento, has_more: deslocamento + limite < total };
}

/** Serialização única. Sem cache intermediário e sem indexação. */
function json(corpo: Obj, status: number): AppResponse {
  return new AppResponse(JSON.stringify(corpo), status, {
    'Content-Type': 'application/json; charset=UTF-8',
    'Cache-Control': 'no-store',
    'X-Robots-Tag': 'noindex, nofollow',
  });
}
