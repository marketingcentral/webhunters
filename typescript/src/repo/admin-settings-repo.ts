/**
 * `admin_settings` — os parâmetros que o jogo lê em tempo real (tela /admin/configuracoes).
 * O App\Repo\AdminSettingsRepo do PHP, menos `valor()`, que mora em repo/settings-repo.ts
 * (o jogo lê de lá; reexportado aqui para quem pensar no nome do PHP).
 *
 * NÃO confundir com repo/player-settings-repo.ts (preferência de CADA jogador). Aqui é a
 * regra do jogo inteiro, uma linha por parâmetro, e só a administração escreve.
 *
 * A validação por tipo vive no repositório (e não no controller) porque a garantia é da
 * tabela: qualquer outro caminho que grave uma chave passa pelos mesmos validar()/gravar().
 *
 * CHAVES DO CÓDIGO: as doze chaves semeadas pelo patch v6 são lidas pelo jogo — editáveis,
 * nunca removíveis (sumir com a linha faria o jogo cair na constante embutida em silêncio).
 */
import * as DB from '../core/database';
import type { Dict } from '../core/view';

export { valor } from './settings-repo';

/** Os quatro widgets que a tela sabe desenhar e validar. */
export const TIPOS = ['string', 'int', 'bool', 'json'];

/** Grupo usado quando a chave nova não declara um. */
export const GRUPO_PADRAO = 'geral';

/** Ações gravadas em admin_audit_log — é por elas que o histórico da tela é lido. */
export const ACAO_ALTERAR = 'config_alterada';
export const ACAO_CRIAR = 'config_criada';
export const ACAO_EXCLUIR = 'config_excluida';

/** Chaves que o CÓDIGO do jogo consome (sementes do patch v6). Lista literal, de propósito. */
export const CHAVES_DO_CODIGO = [
  'maintenance_enabled',
  'maintenance_message',
  'registration_open',
  'region_capacity_total',
  'region_capacity_hunters',
  'region_capacity_mercenaries',
  'human_signal_ttl_min',
  'world_event_interval_min',
  'safe_shift_minutes',
  'squad_max',
  'xp_per_level',
  'support_sla_hours',
];

/** Teto de um texto simples quando a chave não declara o seu. */
const TEXTO_MAX = 2000;

/** Teto de um documento JSON (bytes). Acima disso não é configuração, é conteúdo. */
const JSON_MAX = 8000;

/** Profundidade máxima do json_decode do PHP (o 32 passado lá). */
const JSON_PROFUNDIDADE = 32;

/** Resultado de uma validação: `valor` é o que vai para o banco, já canônico. */
export interface Veredito {
  ok: boolean;
  valor: string | null;
  erro: string;
}

/** Mudança registrada na auditoria (a última de cada chave). */
export interface Mudanca {
  de: string | null;
  para: string | null;
  quando: string;
  quem: string | null;
}

// =====================================================================  leitura

/** Todas as linhas, cruas, na ordem canônica (grupo, chave). Filtro e ordem são da tela. */
export function todas(): Promise<Dict[]> {
  return DB.all<Dict>(
    `SELECT s."key", s."value", s.kind, s.label, s.grupo, s.updated_at, s.updated_by,
            u.username AS updated_por
       FROM admin_settings s
       LEFT JOIN users u ON u.id = s.updated_by
      ORDER BY s.grupo, s."key"`,
  );
}

export function porChave(chave: string): Promise<Dict | null> {
  return DB.one<Dict>(
    `SELECT s."key", s."value", s.kind, s.label, s.grupo, s.updated_at, s.updated_by,
            u.username AS updated_por
       FROM admin_settings s
       LEFT JOIN users u ON u.id = s.updated_by
      WHERE s."key" = :k`,
    { k: chave },
  );
}

/**
 * A ÚLTIMA alteração de cada chave, lida da trilha de auditoria — é o que faz a tela mostrar
 * "era 20" ao lado do campo. O histórico verdadeiro é o log, não uma coluna `value_old`.
 */
export async function ultimasMudancas(limite = 400): Promise<Record<string, Mudanca>> {
  let linhas: Dict[];
  try {
    linhas = await DB.all<Dict>(
      `SELECT a.entity_id, a.meta, a.created_at, u.username
         FROM admin_audit_log a
         LEFT JOIN users u ON u.id = a.admin_id
        WHERE a.entity = :ent AND a."action" = :acao
        ORDER BY a.id DESC
        LIMIT ${Math.max(1, Math.min(2000, Math.trunc(limite)))}`,
      { ent: 'config', acao: ACAO_ALTERAR },
    );
  } catch (e) {
    console.error('[extracthunters] AdminSettingsRepo.ultimasMudancas:', e);
    return {};
  }

  const por: Record<string, Mudanca> = {};
  for (const l of linhas) {
    const chave = String(l.entity_id ?? '');
    if (chave === '' || chave in por) continue; // ORDER BY id DESC: a primeira já é a mais recente
    let meta: Dict = {};
    try {
      const m = JSON.parse(String(l.meta ?? ''));
      if (m !== null && typeof m === 'object') meta = m as Dict;
    } catch {
      /* meta quebrado: sem "era" */
    }
    por[chave] = {
      de: 'de' in meta && meta.de !== null ? String(meta.de) : null,
      para: 'para' in meta && meta.para !== null ? String(meta.para) : null,
      quando: String(l.created_at ?? ''),
      quem: l.username !== null && l.username !== undefined ? String(l.username) : null,
    };
  }
  return por;
}

// =====================================================================  escrita

/** O UPDATE de uma chave, para entrar num batch() (o Database::transaction do PHP). */
export function gravarStmt(chave: string, valor: string | null, adminId: number): D1PreparedStatement {
  return DB.prepare(
    `UPDATE admin_settings SET "value" = :v, updated_by = :by, updated_at = datetime('now') WHERE "key" = :k`,
    { v: valor, by: adminId > 0 ? adminId : null, k: chave },
  );
}

/**
 * Grava um valor JÁ VALIDADO. Devolve false quando nada mudou — o chamador usa isso para
 * não sujar a auditoria com "alterou de 4 para 4".
 */
export async function gravar(chave: string, valor: string | null, adminId: number): Promise<boolean> {
  const atual = await DB.one<Dict>('SELECT "value" FROM admin_settings WHERE "key" = :k', { k: chave });
  if (atual === null) return false;
  if (String(atual.value ?? '') === String(valor ?? '')) return false;
  await gravarStmt(chave, valor, adminId).run();
  return true;
}

export async function criar(
  linha: { key: string; value: string | null; kind: string; label: string; grupo: string },
  adminId: number,
): Promise<void> {
  await DB.insert('admin_settings', {
    key: linha.key,
    value: linha.value,
    kind: linha.kind,
    label: linha.label,
    grupo: linha.grupo,
    updated_by: adminId > 0 ? adminId : null,
  });
}

export async function remover(chave: string): Promise<void> {
  await DB.run('DELETE FROM admin_settings WHERE "key" = :k', { k: chave });
}

export async function existe(chave: string): Promise<boolean> {
  return (Number(await DB.value('SELECT COUNT(*) FROM admin_settings WHERE "key" = :k', { k: chave })) | 0) > 0;
}

/** Chave lida pelo código do jogo — editável, nunca removível. */
export function protegida(chave: string): boolean {
  return CHAVES_DO_CODIGO.includes(chave);
}

// =====================================================================  validação

/**
 * Valida e NORMALIZA um valor cru vindo do formulário. $regra vem de dataMock('admin-config')
 * (min, max, max_len).
 */
export function validar(tipo: string, bruto: string, regra: Dict = {}): Veredito {
  switch (tipo) {
    case 'bool':
      return validarBool(bruto);
    case 'int':
      return validarInt(bruto, regra);
    case 'json':
      return validarJson(bruto);
    default:
      return validarTexto(bruto, regra);
  }
}

function validarBool(bruto: string): Veredito {
  const ligado = ['1', 'on', 'true', 'sim'].includes(bruto.trim().toLowerCase());
  return { ok: true, valor: ligado ? '1' : '0', erro: '' };
}

/** Limites de um int de 64 bits do PHP (o (int) satura neles). */
const INT_MAX = 9223372036854775807n;
const INT_MIN = -9223372036854775808n;

function validarInt(bruto: string, regra: Dict): Veredito {
  // "1 000" e "10.000" são aceitos e normalizados; "10,5" NÃO (int arredondado em silêncio).
  const limpo = bruto.trim().replace(/[ . ]/g, '');

  if (limpo === '') return { ok: false, valor: null, erro: 'Informe um número.' };
  if (!/^-?\d+$/.test(limpo)) return { ok: false, valor: null, erro: 'Só números inteiros — sem vírgula nem unidade.' };

  // Mesmo teste do PHP: (string)(int)$limpo tem de voltar igual (pega estouro, "007" e "-0").
  let n = BigInt(limpo);
  if (n > INT_MAX) n = INT_MAX;
  if (n < INT_MIN) n = INT_MIN;
  if (n.toString() !== limpo) return { ok: false, valor: null, erro: 'Número grande demais para este campo.' };

  const min = regra.min !== undefined && regra.min !== null ? Math.trunc(Number(regra.min)) : null;
  const max = regra.max !== undefined && regra.max !== null ? Math.trunc(Number(regra.max)) : null;
  if (min !== null && n < BigInt(min)) return { ok: false, valor: null, erro: 'Mínimo aceito: ' + min + '.' };
  if (max !== null && n > BigInt(max)) return { ok: false, valor: null, erro: 'Máximo aceito: ' + max + '.' };

  return { ok: true, valor: n.toString(), erro: '' };
}

function validarTexto(bruto: string, regra: Dict): Veredito {
  // O PHP recusava UTF-8 quebrado (mb_check_encoding). No Worker o FormData já chega como
  // string JS válida (byte inválido vira U+FFFD), então essa porta não tem o que barrar.
  let txt = bruto.replace(/\r\n|\r/g, '\n');
  // eslint-disable-next-line no-control-regex
  txt = txt.replace(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/g, '');
  txt = txt.trim();

  const max = regra.max_len !== undefined && regra.max_len !== null ? Math.max(1, Math.trunc(Number(regra.max_len))) : TEXTO_MAX;
  const tam = Array.from(txt).length;
  if (tam > max) return { ok: false, valor: null, erro: 'Máximo de ' + max + ' caracteres (enviou ' + tam + ').' };

  return { ok: true, valor: txt, erro: '' };
}

/** Profundidade de aninhamento no critério do json_decode (escalar = 1). */
function profundidade(v: unknown): number {
  if (v === null || typeof v !== 'object') return 1;
  let max = 0;
  for (const x of Object.values(v as Record<string, unknown>)) max = Math.max(max, profundidade(x));
  return 1 + max;
}

/** JSON é gravado COMPACTO e devolvido indentado para leitura (ver jsonBonito). */
function validarJson(bruto: string): Veredito {
  const txt = bruto.trim();
  if (txt === '') return { ok: true, valor: null, erro: '' };
  if (new TextEncoder().encode(txt).length > JSON_MAX) {
    return { ok: false, valor: null, erro: 'Documento acima de ' + JSON_MAX + ' bytes — isso é conteúdo, não configuração.' };
  }

  let dado: unknown;
  try {
    dado = JSON.parse(txt);
  } catch {
    // Mesma frase do JsonException do PHP para erro de sintaxe.
    return { ok: false, valor: null, erro: 'JSON inválido: Syntax error.' };
  }
  if (profundidade(dado) > JSON_PROFUNDIDADE) {
    return { ok: false, valor: null, erro: 'JSON inválido: Maximum stack depth exceeded.' };
  }
  if (dado === null || typeof dado !== 'object') {
    return { ok: false, valor: null, erro: 'A raiz precisa ser um objeto {…} ou uma lista […].' };
  }

  // JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES: o JSON.stringify já não escapa nenhum dos dois.
  return { ok: true, valor: JSON.stringify(dado), erro: '' };
}

/** JSON compacto do banco -> indentado (JSON_PRETTY_PRINT usa 4 espaços). */
export function jsonBonito(compacto: string | null | undefined): string {
  if (compacto === null || compacto === undefined || compacto.trim() === '') return '';
  let dado: unknown;
  try {
    dado = JSON.parse(compacto);
  } catch {
    return compacto; // gravado quebrado por outro caminho: mostra como está
  }
  if (dado === null) return compacto;
  return JSON.stringify(dado, null, 4);
}

// =====================================================  validação de chave nova

/** Confere os campos de uma chave nova. Erros por CAMPO (qual caixa fica vermelha). */
export async function validarNova(bruto: Record<string, string>): Promise<{
  erros: Record<string, string>;
  linha: { key: string; label: string; kind: string; grupo: string; value: string | null };
}> {
  const erros: Record<string, string> = {};

  const chave = (bruto.key ?? '').trim().toLowerCase();
  if (chave === '') erros.key = 'Informe a chave.';
  else if (!/^[a-z][a-z0-9_]{2,63}$/.test(chave)) erros.key = 'De 3 a 64 caracteres: letras minúsculas, números e _ , começando por letra.';
  else if (await existe(chave)) erros.key = 'Já existe uma chave com este nome.';

  const label = (bruto.label ?? '').trim();
  if (label === '') erros.label = 'Informe um rótulo — é o que o operador lê na tela.';
  else if (Array.from(label).length > 120) erros.label = 'Máximo de 120 caracteres.';

  let tipo = (bruto.kind ?? '').trim().toLowerCase();
  if (!TIPOS.includes(tipo)) {
    erros.kind = 'Tipo desconhecido.';
    tipo = 'string';
  }

  const grupo = (bruto.grupo ?? '').trim().toLowerCase() || GRUPO_PADRAO;
  if (!/^[a-z][a-z0-9_]{1,31}$/.test(grupo)) erros.grupo = 'Grupo inválido: letras minúsculas, números e _ , até 32 caracteres.';

  const valor = validar(tipo, bruto.value ?? '');
  if (!valor.ok) erros.value = valor.erro;

  return { erros, linha: { key: chave, label, kind: tipo, grupo, value: valor.valor } };
}
