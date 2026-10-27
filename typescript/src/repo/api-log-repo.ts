/**
 * O log de requisições da API (`api_request_log`) — App\Repo\ApiLogRepo: escrita, leitura para
 * a tela e o CONTADOR do limite por minuto (janela DESLIZANTE de 60 s contada no banco, no
 * índice (key_id, created_at) — no Worker, como no PHP, não há memória compartilhada entre
 * requisições que sirva de contador).
 *
 * FUNÇÕES EXPORTADAS (a tela /admin/api do lote do painel usa estas):
 *   - noMinuto(keyId)        -> requisições da chave nos últimos 60 s (sem contar os 429); -1 se a consulta falhar
 *   - registrar(...)         -> uma linha por requisição, inclusive as recusadas; nunca lança
 *   - pagina({chave, status, pag}) -> { linhas, total, pagina, paginas, falha }; status = 'ok' | 'recusado' | 'erro'
 *   - resumo24h()            -> { total, recusadas, erros, ms_medio, chaves } das últimas 24 h
 *   - expurgar(dias)         -> apaga o que tem mais de N dias (mínimo 1; para script de manutenção)
 *   - constantes POR_PAGINA, JANELA_SEG; tipo LinhaLog.
 *
 * Datas: `created_at` é `datetime('now')` (UTC); as janelas usam datetime('now', '-N seconds').
 */
import * as DB from '../core/database';

/** Linhas por página na tela do painel. */
export const POR_PAGINA = 30;
/** Tamanho da janela do limite, em segundos. */
export const JANELA_SEG = 60;

type Linha = Record<string, unknown>;
const corta = (s: string, n: number) => [...s].slice(0, n).join('');

/**
 * Quantas requisições esta chave já fez na janela. -1 quando a consulta FALHA (o chamador
 * deixa passar: banco com problema não vira 429 para todo mundo). A recusa por limite (429)
 * não conta, senão um cliente em laço nunca sairia do castigo.
 */
export async function noMinuto(keyId: number): Promise<number> {
  if (keyId <= 0) return 0;
  try {
    return Number(
      await DB.value(
        `SELECT COUNT(*) FROM api_request_log
          WHERE key_id = :k AND status <> 429
            AND created_at > datetime('now', '-${JANELA_SEG} seconds')`,
        { k: keyId },
      ),
    ) | 0;
  } catch (e) {
    console.error('[extracthunters] api limite:', e);
    return -1;
  }
}

/** Uma linha por requisição — INCLUSIVE as recusadas. Só o caminho, nunca a querystring. Nunca lança. */
export async function registrar(
  keyId: number | null,
  prefixo: string | null,
  metodo: string,
  caminho: string,
  status: number,
  codigoErro: string,
  ip: string,
  duracaoMs: number,
): Promise<void> {
  try {
    await DB.insert('api_request_log', {
      key_id: keyId !== null && keyId > 0 ? keyId : null,
      key_prefix: prefixo !== null && prefixo !== '' ? corta(prefixo, 24) : null,
      method: corta(metodo, 8),
      path: corta(caminho, 191),
      status,
      error_code: codigoErro !== '' ? corta(codigoErro, 40) : null,
      ip: corta(ip, 45),
      duration_ms: Math.max(0, duracaoMs),
    });
  } catch (e) {
    console.error('[extracthunters] api log:', e);
  }
}

export interface LinhaLog {
  id: number;
  chave_id: number;
  /** Nome vazio com prefixo preenchido = a chave não existe (ou o prefixo foi inventado). */
  chave: string;
  prefixo: string;
  metodo: string;
  caminho: string;
  status: number;
  erro: string;
  ip: string;
  ms: number;
  quando: string;
}

/** Página do log para a tela. `status` é FAIXA: 'ok' (<400), 'recusado' (401/403/429), 'erro' (>=400). */
export async function pagina(f: {
  chave?: number;
  status?: string;
  pag?: number;
}): Promise<{ linhas: LinhaLog[]; total: number; pagina: number; paginas: number; falha: boolean }> {
  const cond: string[] = [];
  const par: Record<string, unknown> = {};
  if ((f.chave ?? 0) > 0) {
    cond.push('l.key_id = :k');
    par.k = f.chave;
  }
  const faixa = f.status ?? '';
  if (faixa === 'ok') cond.push('l.status < 400');
  else if (faixa === 'recusado') cond.push('l.status IN (401, 403, 429)');
  else if (faixa === 'erro') cond.push('l.status >= 400');
  const onde = cond.length === 0 ? '1' : cond.join(' AND ');

  try {
    const total = Number(await DB.value('SELECT COUNT(*) FROM api_request_log l WHERE ' + onde, par));
    const paginas = Math.max(1, Math.ceil(total / POR_PAGINA));
    const pag = Math.min(Math.max(1, (f.pag ?? 1) | 0), paginas);
    const offset = (pag - 1) * POR_PAGINA;
    const linhas = await DB.all<Linha>(
      `SELECT l.id, l.key_id, l.key_prefix, l.method, l.path, l.status, l.error_code,
              l.ip, l.duration_ms, l.created_at, k.name AS chave_nome
         FROM api_request_log l
         LEFT JOIN api_keys k ON k.id = l.key_id
        WHERE ${onde}
        ORDER BY l.id DESC
        LIMIT ${POR_PAGINA} OFFSET ${offset}`,
      par,
    );
    const s = (v: unknown) => (v === null || v === undefined ? '' : String(v));
    return {
      linhas: linhas.map((l) => ({
        id: Number(l.id) | 0,
        chave_id: Number(l.key_id ?? 0) | 0,
        chave: s(l.chave_nome),
        prefixo: s(l.key_prefix),
        metodo: s(l.method),
        caminho: s(l.path),
        status: Number(l.status) | 0,
        erro: s(l.error_code),
        ip: s(l.ip),
        ms: Number(l.duration_ms) | 0,
        quando: s(l.created_at),
      })),
      total,
      pagina: pag,
      paginas,
      falha: false,
    };
  } catch (e) {
    console.error('[extracthunters] api log lista:', e);
    return { linhas: [], total: 0, pagina: 1, paginas: 1, falha: true };
  }
}

/** Números do topo da tela, medidos nas últimas 24 h. */
export async function resumo24h(): Promise<{ total: number; recusadas: number; erros: number; ms_medio: number; chaves: number }> {
  let l: Linha = {};
  try {
    l =
      (await DB.one<Linha>(
        `SELECT COUNT(*) AS total,
                SUM(status IN (401, 403, 429)) AS recusadas,
                SUM(status >= 500) AS erros,
                AVG(duration_ms) AS ms,
                COUNT(DISTINCT key_id) AS chaves
           FROM api_request_log
          WHERE created_at > datetime('now', '-1 day')`,
      )) ?? {};
  } catch (e) {
    console.error('[extracthunters] api resumo:', e);
  }
  return {
    total: Number(l.total ?? 0) | 0,
    recusadas: Number(l.recusadas ?? 0) | 0,
    erros: Number(l.erros ?? 0) | 0,
    ms_medio: Math.round(Number(l.ms ?? 0)),
    chaves: Number(l.chaves ?? 0) | 0,
  };
}

/** Expurgo por retenção (script de manutenção). Piso de 1 dia: a janela do limite mora aqui. */
export async function expurgar(dias: number): Promise<number> {
  dias = Math.max(1, Math.min(3650, dias | 0));
  return DB.changes(await DB.run(`DELETE FROM api_request_log WHERE created_at < datetime('now', '-${dias} days')`));
}
