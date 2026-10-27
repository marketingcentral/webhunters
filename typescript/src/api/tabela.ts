/**
 * O MOTOR DE CRUD DECLARATIVO da API — App\Api\Tabela do PHP.
 *
 * A forma de cada coleção é DECLARADA (api/catalogo.ts) e executada aqui. NADA que venha do
 * cliente vira nome de coluna: o cliente escolhe uma CHAVE da declaração, a declaração
 * escolhe a coluna. `campos` é a lista branca de leitura e de escrita.
 *
 * Diferenças do MySQL: identificadores entre aspas duplas; o `ESCAPE '!'` do LIKE é igual.
 */
import * as DB from '../core/database';
import { validar as validarCampo, type Regra } from './campo';
import type { Obj } from './resposta';

export const LIMITE_MAX = 200;
export const LIMITE_PADRAO = 50;
const BUSCA_MAX = 60;

export interface Dependente {
  tabela: string;
  coluna: string;
  rotulo: string;
}

export interface Declaracao {
  tabela: string;
  chave: string;
  ordem: string;
  busca?: string[];
  filtros?: Record<string, string>;
  pai?: string;
  campos: Record<string, Regra>;
  saida_extra?: Record<string, string>;
  dependentes?: Dependente[];
}

// ===================================================================== leitura

/** Uma página da coleção. */
export async function listar(
  decl: Declaracao,
  consulta: Record<string, string>,
  limite: number,
  deslocamento: number,
  paiId: number | null = null,
): Promise<{ linhas: Obj[]; total: number }> {
  const [onde, par] = montarOnde(decl, consulta, paiId);
  const t = tabela(decl);
  const total = Number(await DB.value(`SELECT COUNT(*) FROM "${t}" WHERE ${onde}`, par));

  limite = Math.max(1, Math.min(LIMITE_MAX, limite));
  deslocamento = Math.max(0, deslocamento);

  const linhas = await DB.all<Obj>(
    `SELECT ${colunas(decl)} FROM "${t}" WHERE ${onde} ORDER BY ${ordem(decl)} LIMIT ${limite} OFFSET ${deslocamento}`,
    par,
  );
  return { linhas: linhas.map((l) => saida(decl, l)), total };
}

/** Um registro pelo id (e pelo pai, quando aninhada), ou null. */
export async function obter(decl: Declaracao, id: number, paiId: number | null = null): Promise<Obj | null> {
  const par: Obj = { id };
  let onde = `"${chave(decl)}" = :id`;
  // O pai entra no WHERE: /maps/3/markers/99 não devolve o marcador 99 do mapa 7.
  if (paiId !== null && decl.pai) {
    onde += ` AND "${coluna(decl.pai)}" = :pai`;
    par.pai = paiId;
  }
  const l = await DB.one<Obj>(`SELECT ${colunas(decl)} FROM "${tabela(decl)}" WHERE ${onde} LIMIT 1`, par);
  return l === null ? null : saida(decl, l);
}

// ===================================================================== escrita

/** Cria um registro. Devolve o id, ou os erros de validação. */
export async function criar(
  decl: Declaracao,
  corpo: Obj,
  paiId: number | null = null,
): Promise<{ ok: boolean; id: number; erros: Record<string, string> }> {
  const v = await validar(decl, corpo, true);
  if (!v.ok) return { ok: false, id: 0, erros: v.erros };

  const valores = v.valores;
  if (paiId !== null && decl.pai) valores[coluna(decl.pai)] = paiId;

  const erro = await conferirUnicos(decl, valores, 0);
  if (Object.keys(erro).length) return { ok: false, id: 0, erros: erro };

  return { ok: true, id: await DB.insert(tabela(decl), valores), erros: {} };
}

/** Altera um registro. PARCIAL: só o que veio no corpo é tocado; corpo sem campo conhecido é erro. */
export async function atualizar(
  decl: Declaracao,
  id: number,
  corpo: Obj,
  paiId: number | null = null,
): Promise<{ ok: boolean; erros: Record<string, string> }> {
  const v = await validar(decl, corpo, false);
  if (!v.ok) return { ok: false, erros: v.erros };
  if (Object.keys(v.valores).length === 0) {
    return { ok: false, erros: { _: 'Nenhum campo conhecido veio no corpo. Confira os nomes.' } };
  }
  const erro = await conferirUnicos(decl, v.valores, id);
  if (Object.keys(erro).length) return { ok: false, erros: erro };

  const sets = Object.keys(v.valores).map((col) => `"${col}" = :${col}`);
  const par: Obj = { ...v.valores, _id: id };
  let onde = `"${chave(decl)}" = :_id`;
  if (paiId !== null && decl.pai) {
    onde += ` AND "${coluna(decl.pai)}" = :_pai`;
    par._pai = paiId;
  }
  await DB.run(`UPDATE "${tabela(decl)}" SET ${sets.join(', ')} WHERE ${onde}`, par);
  return { ok: true, erros: {} };
}

export async function excluir(decl: Declaracao, id: number, paiId: number | null = null): Promise<boolean> {
  const par: Obj = { id };
  let onde = `"${chave(decl)}" = :id`;
  if (paiId !== null && decl.pai) {
    onde += ` AND "${coluna(decl.pai)}" = :pai`;
    par.pai = paiId;
  }
  return DB.changes(await DB.run(`DELETE FROM "${tabela(decl)}" WHERE ${onde}`, par)) > 0;
}

/** Quantos registros dependem deste (rótulo => quantidade, só os > 0). */
export async function dependencias(dependentes: Dependente[], id: number): Promise<Record<string, number>> {
  const out: Record<string, number> = {};
  for (const d of dependentes) {
    const n = Number(await DB.value(`SELECT COUNT(*) FROM "${coluna(d.tabela)}" WHERE "${coluna(d.coluna)}" = :id`, { id }));
    if (n > 0) out[d.rotulo] = n;
  }
  return out;
}

// ===================================================================== validação

async function validar(
  decl: Declaracao,
  corpo: Obj,
  criando: boolean,
): Promise<{ ok: boolean; erros: Record<string, string>; valores: Obj }> {
  const erros: Record<string, string> = {};
  const valores: Obj = {};

  for (const [nome, regra] of Object.entries(decl.campos)) {
    const presente = Object.prototype.hasOwnProperty.call(corpo, nome);
    if (!presente) {
      if (!criando) continue;
      if (regra.obrigatorio) {
        erros[nome] = 'Campo obrigatório.';
        continue;
      }
      if ('padrao' in regra) valores[coluna(nome)] = regra.padrao;
      continue;
    }
    const r = await validarCampo(corpo[nome], regra);
    if (!r.ok) {
      erros[nome] = r.erro;
      continue;
    }
    if (r.valor === null && regra.obrigatorio) {
      erros[nome] = 'Campo obrigatório.';
      continue;
    }
    valores[coluna(nome)] = r.valor;
  }
  return { ok: Object.keys(erros).length === 0, erros, valores };
}

/** Campos `unico` não podem repetir (nem toda coluna tem UNIQUE no banco). */
async function conferirUnicos(decl: Declaracao, valores: Obj, exceto: number): Promise<Record<string, string>> {
  const erros: Record<string, string> = {};
  for (const [nome, regra] of Object.entries(decl.campos)) {
    const col = coluna(nome);
    if (!regra.unico || !(col in valores)) continue;
    const n = Number(
      await DB.value(`SELECT COUNT(*) FROM "${tabela(decl)}" WHERE "${col}" = :v AND "${chave(decl)}" <> :id`, {
        v: valores[col],
        id: exceto,
      }),
    );
    if (n > 0) erros[nome] = 'Já existe outro registro com este valor.';
  }
  return erros;
}

// ===================================================================== apoio

function montarOnde(decl: Declaracao, consulta: Record<string, string>, paiId: number | null): [string, Obj] {
  const cond: string[] = [];
  const par: Obj = {};

  if (paiId !== null && decl.pai) {
    cond.push(`"${coluna(decl.pai)}" = :pai`);
    par.pai = paiId;
  }

  const q = [...(consulta.q ?? '').trim()].slice(0, BUSCA_MAX).join('');
  const colunasBusca = decl.busca ?? [];
  if (q !== '' && colunasBusca.length) {
    // ESCAPE '!': % e _ digitados pelo cliente são texto procurado, não coringa.
    const termo = '%' + q.replace(/[!%_]/g, (c) => '!' + c) + '%';
    const partes = colunasBusca.map((col, i) => {
      par[`q${i}`] = termo;
      return `"${coluna(col)}" LIKE :q${i} ESCAPE '!'`;
    });
    cond.push('(' + partes.join(' OR ') + ')');
  }

  for (const [parametro, col] of Object.entries(decl.filtros ?? {})) {
    const v = (consulta[parametro] ?? '').trim();
    if (v === '') continue;
    const nome = 'f_' + parametro.replace(/[^a-z0-9_]/g, '');
    cond.push(`"${coluna(col)}" = :${nome}`);
    par[nome] = v;
  }
  return [cond.length === 0 ? '1' : cond.join(' AND '), par];
}

/** Linha do banco -> objeto JSON com os tipos certos (0/1 vira bool, JSON vira objeto...). */
function saida(decl: Declaracao, l: Obj): Obj {
  const out: Obj = {};
  const k = chave(decl);
  const idv = l[k];
  out.id = idv === undefined || idv === null ? null : /^-?\d+(\.\d+)?$/.test(String(idv)) ? Math.trunc(Number(idv)) : idv;

  for (const [nome, regra] of Object.entries(decl.campos)) {
    const col = coluna(nome);
    if (!(col in l)) continue;
    const v = l[col];
    if (v === null || v === undefined) {
      out[nome] = null;
      continue;
    }
    switch (regra.tipo ?? 'texto') {
      case 'int':
      case 'ref':
        out[nome] = Math.trunc(Number(v)) || 0;
        break;
      case 'bool':
        out[nome] = Boolean(Math.trunc(Number(v)));
        break;
      case 'decimal':
        out[nome] = Number(v) || 0;
        break;
      case 'json':
        try {
          out[nome] = JSON.parse(String(v));
        } catch {
          out[nome] = null;
        }
        break;
      default:
        out[nome] = String(v);
    }
  }

  // Colunas só de leitura (ex.: created_at).
  for (const [nome, col] of Object.entries(decl.saida_extra ?? {})) {
    if (col in l) out[nome] = l[col] === null ? null : String(l[col]);
  }
  if (decl.pai && coluna(decl.pai) in l) out[decl.pai] = Math.trunc(Number(l[coluna(decl.pai)])) || 0;
  return out;
}

/** SELECT explícito: a chave, as colunas dos campos e as extras. Nunca `SELECT *`. */
function colunas(decl: Declaracao): string {
  const cols = [chave(decl), ...Object.keys(decl.campos).map(coluna), ...Object.values(decl.saida_extra ?? {}).map(coluna)];
  if (decl.pai) cols.push(coluna(decl.pai));
  return [...new Set(cols)].map((c) => `"${c}"`).join(', ');
}

function tabela(decl: Declaracao): string {
  return coluna(decl.tabela);
}

function chave(decl: Declaracao): string {
  return coluna(decl.chave || 'id');
}

function ordem(decl: Declaracao): string {
  // ORDER BY escrito à mão na declaração; o formato é conferido mesmo assim.
  if (!/^[a-z_]+ (?:ASC|DESC)(?:, [a-z_]+ (?:ASC|DESC))*$/.test(decl.ordem)) {
    throw new Error('ORDER BY inválido na declaração da API: ' + decl.ordem);
  }
  return decl.ordem;
}

/** Identificador de tabela/coluna. Nenhum vem do cliente; esta função existe para o dia em que alguém achar que poderia. */
function coluna(nome: string): string {
  if (!/^[a-z][a-z0-9_]{0,63}$/.test(nome)) throw new Error('Identificador inválido na declaração da API: ' + nome);
  return nome;
}
