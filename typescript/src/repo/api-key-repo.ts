/**
 * As chaves da API (`api_keys`): criar, autenticar, listar e revogar — App\Repo\ApiKeyRepo.
 *
 * O SEGREDO SÓ EXISTE UMA VEZ: `criar()` é a única função que devolve a chave em claro; o banco
 * guarda o SHA-256 (hex) dela. Formato: `ehk<8 hex>_<48 hex>` — o prefixo é público, indexado e
 * único (aparece na tela e no log); o resto são 192 bits aleatórios.
 *
 * FUNÇÕES EXPORTADAS (a tela /admin/api do lote do painel usa estas):
 *   - criar(dados, adminId)        -> { id, chave, prefix }   (única vez em que o segredo existe)
 *   - hash(chave)                  -> sha256 hex (async: WebCrypto)
 *   - autenticar(apresentada)      -> { ok, motivo, chave }    (motivo = código de api/resposta.ts)
 *   - marcarUso(id, ip)            -> carimba last_used_at/last_ip/uses; nunca lança
 *   - limiteDe(chave)              -> limite por minuto que vale (0 na coluna = LIMITE_PADRAO)
 *   - pagina(pagina, estado)       -> { linhas, total, pagina, paginas, falha } para a tabela da tela
 *   - porId(id)                    -> linha mapeada (sem hash) ou null
 *   - contagens()                  -> { total, ativas, revogadas, vencidas }
 *   - revogar(id, adminId)         -> false se já estava revogada (a linha nunca é apagada)
 *   - normalizarEscopos(bruto)     -> só escopos do catálogo, sem repetição, na ordem canônica
 *   - validarNova(bruto)           -> { ok, erros, dados } do formulário de criação
 *   - lerEscopos(json)             -> lista de escopos de uma coluna `scopes`
 *   - constantes POR_PAGINA, LIMITE_PADRAO, LIMITE_MAX, ESTADOS; tipo ChaveMapeada.
 *
 * Datas: `datetime('now')` (UTC) no lugar do NOW() do MySQL; `expires_at` também é lido como UTC.
 */
import * as DB from '../core/database';
import { todos as todosEscopos } from '../api/escopos';

/** Linhas por página na tela do painel. */
export const POR_PAGINA = 25;
/** Limite por minuto quando a chave não define o seu (coluna = 0). */
export const LIMITE_PADRAO = 120;
/** Teto do limite configurável por chave. */
export const LIMITE_MAX = 6000;
/** Marca do prefixo: reconhecer no olho que o texto é chave DESTA API. */
const MARCA = 'ehk';

/** Situações derivadas (calculadas de revoked_at/expires_at). */
export const ESTADOS: Record<string, string> = { ativa: 'Ativa', revogada: 'Revogada', vencida: 'Vencida' };

type Linha = Record<string, unknown>;

function hexDe(bytes: Uint8Array): string {
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

function aleatorioHex(n: number): string {
  const b = new Uint8Array(n);
  crypto.getRandomValues(b);
  return hexDe(b);
}

const corta = (s: string, n: number) => [...s].slice(0, n).join('');

/** strtotime() de uma coluna DATETIME (UTC), em segundos; 0 quando ilegível. */
function segundos(s: unknown): number {
  if (s === null || s === undefined || s === '') return 0;
  const t = Date.parse(String(s).replace(' ', 'T') + 'Z');
  return Number.isFinite(t) ? Math.floor(t / 1000) : 0;
}

// ===================================================================== criação

export interface DadosNovaChave {
  name: string;
  scopes: string[];
  rate_limit_per_min: number;
  note?: string;
  expires_at?: string | null;
}

/** Cria a chave e devolve o SEGREDO — a única vez em que ele existe fora do cliente. */
export async function criar(d: DadosNovaChave, adminId: number): Promise<{ id: number; chave: string; prefix: string }> {
  const prefixo = await prefixoInedito();
  const chave = prefixo + '_' + aleatorioHex(24); // 192 bits
  const nota = d.note ?? '';
  const id = await DB.insert('api_keys', {
    name: corta(d.name.trim(), 80),
    prefix: prefixo,
    key_hash: await hash(chave),
    scopes: JSON.stringify(normalizarEscopos(d.scopes)),
    rate_limit_per_min: Math.max(0, Math.min(LIMITE_MAX, d.rate_limit_per_min)),
    note: nota === '' ? null : corta(nota.trim(), 240),
    created_by: adminId > 0 ? adminId : null,
    expires_at: d.expires_at ?? null,
  });
  return { id, chave, prefix: prefixo };
}

/** Um prefixo que ainda não está na tabela (dez tentativas e desiste). */
async function prefixoInedito(): Promise<string> {
  for (let i = 0; i < 10; i++) {
    const tentativa = MARCA + aleatorioHex(4);
    const existe = Number(await DB.value('SELECT COUNT(*) FROM api_keys WHERE prefix = :p', { p: tentativa }));
    if (existe === 0) return tentativa;
  }
  throw new Error('Não foi possível gerar um prefixo inédito para a chave.');
}

/** SHA-256 em hex (o hash('sha256', ...) do PHP). */
export async function hash(chave: string): Promise<string> {
  const d = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(chave));
  return hexDe(new Uint8Array(d));
}

/** hash_equals: comparação em tempo constante. */
function iguais(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let r = 0;
  for (let i = 0; i < a.length; i++) r |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return r === 0;
}

// ===================================================================== autenticação

export interface ChaveAutenticada {
  id: number;
  name: string;
  prefix: string;
  scopes: string[];
  rate_limit_per_min: number;
  [k: string]: unknown;
}

export interface Autenticacao {
  ok: boolean;
  motivo: string;
  chave: ChaveAutenticada | null;
}

/**
 * Confere a chave apresentada. Desconhecida, malformada e hash errado dão TODAS
 * `chave_invalida` (não entrega a metade pública a quem sonda); revogada e vencida são
 * distinguidas porque quem chega lá provou ter a chave inteira.
 */
export async function autenticar(apresentada: string): Promise<Autenticacao> {
  const negar = (motivo: string): Autenticacao => ({ ok: false, motivo, chave: null });
  if (apresentada === '') return negar('chave_ausente');

  const m = new RegExp(`^(${MARCA}[0-9a-f]{8})_[0-9a-f]{48}$`).exec(apresentada);
  if (!m) return negar('chave_invalida');

  const linha = await DB.one<Linha>(
    `SELECT id, name, prefix, key_hash, scopes, rate_limit_per_min, created_by,
            created_at, last_used_at, uses, expires_at, revoked_at
       FROM api_keys WHERE prefix = :p LIMIT 1`,
    { p: m[1] },
  );
  if (linha === null) return negar('chave_invalida');
  if (!iguais(String(linha.key_hash), await hash(apresentada))) return negar('chave_invalida');

  // Revogada vence vencida: revogar é um ato deliberado.
  if (linha.revoked_at !== null) return negar('chave_revogada');
  if (linha.expires_at !== null && segundos(linha.expires_at) < Math.floor(Date.now() / 1000)) return negar('chave_expirada');

  // O hash não sobe para a camada de cima.
  delete linha.key_hash;
  const chave: ChaveAutenticada = {
    ...linha,
    id: Number(linha.id),
    name: String(linha.name ?? ''),
    prefix: String(linha.prefix ?? ''),
    rate_limit_per_min: Number(linha.rate_limit_per_min ?? 0) | 0,
    scopes: lerEscopos(linha.scopes ?? null),
  };
  return { ok: true, motivo: '', chave };
}

/** Carimba o uso. Chamado DEPOIS de atender; nunca lança. */
export async function marcarUso(id: number, ip: string): Promise<void> {
  if (id <= 0) return;
  try {
    await DB.run(`UPDATE api_keys SET last_used_at = datetime('now'), last_ip = :ip, uses = uses + 1 WHERE id = :id`, {
      ip: corta(ip, 45),
      id,
    });
  } catch (e) {
    console.error('[extracthunters] api_keys uso:', e);
  }
}

/** O limite por minuto que vale para esta chave (0 na coluna = padrão do código). */
export function limiteDe(chave: { rate_limit_per_min?: unknown }): number {
  const proprio = Number(chave.rate_limit_per_min ?? 0) | 0;
  return proprio > 0 ? proprio : LIMITE_PADRAO;
}

// ===================================================================== leitura

export interface ChaveMapeada {
  id: number;
  nome: string;
  prefixo: string;
  escopos: string[];
  limite: number;
  limite_proprio: boolean;
  nota: string;
  criada_em: string;
  criador: string;
  usada_em: string;
  ultimo_ip: string;
  usos: number;
  expira_em: string;
  revogada_em: string;
  revogador: string;
  estado: 'ativa' | 'revogada' | 'vencida';
}

/** Página da lista de chaves para o painel. `falha` separa "não consegui ler" de "não há nada". */
export async function pagina(
  pag = 1,
  estado = '',
): Promise<{ linhas: ChaveMapeada[]; total: number; pagina: number; paginas: number; falha: boolean }> {
  const onde =
    estado === 'ativa'
      ? `k.revoked_at IS NULL AND (k.expires_at IS NULL OR k.expires_at > datetime('now'))`
      : estado === 'revogada'
        ? 'k.revoked_at IS NOT NULL'
        : estado === 'vencida'
          ? `k.revoked_at IS NULL AND k.expires_at IS NOT NULL AND k.expires_at <= datetime('now')`
          : '1';
  try {
    const total = Number(await DB.value('SELECT COUNT(*) FROM api_keys k WHERE ' + onde));
    const paginas = Math.max(1, Math.ceil(total / POR_PAGINA));
    pag = Math.min(Math.max(1, pag | 0), paginas);
    const offset = (pag - 1) * POR_PAGINA;
    const linhas = await DB.all<Linha>(
      `SELECT k.id, k.name, k.prefix, k.scopes, k.rate_limit_per_min, k.note,
              k.created_at, k.created_by, k.last_used_at, k.last_ip, k.uses,
              k.expires_at, k.revoked_at, k.revoked_by,
              u.username AS criador, r.username AS revogador
         FROM api_keys k
         LEFT JOIN users u ON u.id = k.created_by
         LEFT JOIN users r ON r.id = k.revoked_by
        WHERE ${onde}
        ORDER BY k.revoked_at IS NOT NULL ASC, k.id DESC
        LIMIT ${POR_PAGINA} OFFSET ${offset}`,
    );
    return { linhas: linhas.map(mapear), total, pagina: pag, paginas, falha: false };
  } catch (e) {
    console.error('[extracthunters] api_keys lista:', e);
    return { linhas: [], total: 0, pagina: 1, paginas: 1, falha: true };
  }
}

export async function porId(id: number): Promise<ChaveMapeada | null> {
  if (id <= 0) return null;
  const linha = await DB.one<Linha>(
    `SELECT k.*, u.username AS criador, r.username AS revogador
       FROM api_keys k
       LEFT JOIN users u ON u.id = k.created_by
       LEFT JOIN users r ON r.id = k.revoked_by
      WHERE k.id = :id LIMIT 1`,
    { id },
  );
  if (linha === null) return null;
  delete linha.key_hash;
  return mapear(linha);
}

export async function contagens(): Promise<{ total: number; ativas: number; revogadas: number; vencidas: number }> {
  let l: Linha = {};
  try {
    l =
      (await DB.one<Linha>(
        `SELECT COUNT(*) AS total,
                SUM(revoked_at IS NOT NULL) AS revogadas,
                SUM(revoked_at IS NULL AND expires_at IS NOT NULL AND expires_at <= datetime('now')) AS vencidas,
                SUM(revoked_at IS NULL AND (expires_at IS NULL OR expires_at > datetime('now'))) AS ativas
           FROM api_keys`,
      )) ?? {};
  } catch (e) {
    console.error('[extracthunters] api_keys contagens:', e);
  }
  return {
    total: Number(l.total ?? 0) | 0,
    ativas: Number(l.ativas ?? 0) | 0,
    revogadas: Number(l.revogadas ?? 0) | 0,
    vencidas: Number(l.vencidas ?? 0) | 0,
  };
}

// ===================================================================== escrita

/** Revoga (a linha NÃO é apagada: o log aponta para ela). false quando já estava revogada. */
export async function revogar(id: number, adminId: number): Promise<boolean> {
  if (id <= 0) return false;
  const r = await DB.run(`UPDATE api_keys SET revoked_at = datetime('now'), revoked_by = :by WHERE id = :id AND revoked_at IS NULL`, {
    by: adminId > 0 ? adminId : null,
    id,
  });
  return DB.changes(r) > 0;
}

// ===================================================================== validação

/** Só os escopos que EXISTEM no catálogo, sem repetição e na ordem canônica. */
export function normalizarEscopos(bruto: unknown): string[] {
  if (!Array.isArray(bruto) && (bruto === null || typeof bruto !== 'object')) return [];
  const valores = Array.isArray(bruto) ? bruto : Object.values(bruto as object);
  const pedidos = new Set<string>();
  for (const v of valores) if (typeof v === 'string') pedidos.add(v.trim().toLowerCase());
  return todosEscopos().filter((e) => pedidos.has(e));
}

/** Valida o formulário de criação. `bruto.scopes` é a lista dos `scopes[]` marcados. */
export function validarNova(bruto: Record<string, unknown>): {
  ok: boolean;
  erros: Record<string, string>;
  dados: Required<DadosNovaChave>;
} {
  const erros: Record<string, string> = {};
  const texto = (v: unknown) => (v === null || v === undefined ? '' : String(v));

  const nome = texto(bruto.name).trim();
  if (nome === '') erros.name = 'Dê um nome à chave — é por ele que você vai reconhecê-la daqui a seis meses.';
  else if ([...nome].length > 80) erros.name = 'O nome não pode passar de 80 caracteres.';

  const escopos = normalizarEscopos(bruto.scopes ?? []);
  if (escopos.length === 0) erros.scopes = 'Marque pelo menos um escopo: uma chave sem escopo não consegue chamar nada.';

  const limiteTxt = texto(bruto.rate_limit_per_min).trim();
  let limite = 0;
  if (limiteTxt !== '') {
    if (!/^\d{1,5}$/.test(limiteTxt)) {
      erros.rate_limit_per_min = 'O limite por minuto tem de ser um número inteiro.';
    } else {
      limite = parseInt(limiteTxt, 10);
      if (limite > LIMITE_MAX) erros.rate_limit_per_min = `O limite não pode passar de ${LIMITE_MAX} por minuto.`;
    }
  }

  const expira = texto(bruto.expires_at).trim();
  let expiraSql: string | null = null;
  if (expira !== '') {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(expira);
    const valida = m && new Date(Date.UTC(+m[1]!, +m[2]! - 1, +m[3]!)).toISOString().slice(0, 10) === expira;
    const hoje = new Date().toISOString().slice(0, 10);
    if (!valida) erros.expires_at = 'Use uma data no formato AAAA-MM-DD.';
    else if (expira < hoje) erros.expires_at = 'A data de vencimento já passou.';
    // Fim do dia: quem digita 31/12 espera que a chave funcione durante o dia 31.
    else expiraSql = expira + ' 23:59:59';
  }

  return {
    ok: Object.keys(erros).length === 0,
    erros,
    dados: {
      name: nome,
      scopes: escopos,
      rate_limit_per_min: limite,
      note: corta(texto(bruto.note).trim(), 240),
      expires_at: expiraSql,
    },
  };
}

// ===================================================================== apoio

/** Lista de escopos de uma coluna `scopes` (JSON). */
export function lerEscopos(bruto: unknown): string[] {
  if (typeof bruto !== 'string' || bruto === '') return [];
  try {
    return normalizarEscopos(JSON.parse(bruto));
  } catch {
    return [];
  }
}

/** Linha do banco -> linha da tela (a view não decodifica JSON nem calcula estado). */
function mapear(l: Linha): ChaveMapeada {
  const revogada = l.revoked_at !== null && l.revoked_at !== undefined;
  const vencida = !revogada && l.expires_at !== null && l.expires_at !== undefined && segundos(l.expires_at) < Math.floor(Date.now() / 1000);
  const s = (v: unknown) => (v === null || v === undefined ? '' : String(v));
  return {
    id: Number(l.id) | 0,
    nome: s(l.name),
    prefixo: s(l.prefix),
    escopos: lerEscopos(l.scopes ?? null),
    limite: limiteDe(l),
    limite_proprio: (Number(l.rate_limit_per_min ?? 0) | 0) > 0,
    nota: s(l.note),
    criada_em: s(l.created_at),
    criador: s(l.criador),
    usada_em: s(l.last_used_at),
    ultimo_ip: s(l.last_ip),
    usos: Number(l.uses ?? 0) | 0,
    expira_em: s(l.expires_at),
    revogada_em: s(l.revoked_at),
    revogador: s(l.revogador),
    estado: revogada ? 'revogada' : vencida ? 'vencida' : 'ativa',
  };
}
