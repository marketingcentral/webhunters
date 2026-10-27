/**
 * VALIDAÇÃO DE UM CAMPO DE ENTRADA — App\Api\Campo do PHP.
 *
 * Todo campo devolve { ok, valor, erro }, com `valor` já CANÔNICO (bool -> 0/1, número ->
 * number, JSON -> texto compacto). Tipo errado é ERRO, não conversão; a única tolerância é
 * número escrito como texto ("12").
 */
import * as DB from '../core/database';

export interface Regra {
  tipo?: string;
  max?: number;
  min?: number;
  nulo?: boolean;
  obrigatorio?: boolean;
  unico?: boolean;
  padrao?: unknown;
  opcoes?: readonly string[];
  escala?: number;
  tabela?: string;
  coluna?: string;
}

export interface Resultado {
  ok: boolean;
  valor: unknown;
  erro: string;
}

const ok = (valor: unknown): Resultado => ({ ok: true, valor, erro: '' });
const nao = (erro: string): Resultado => ({ ok: false, valor: null, erro });

/** is_scalar() do PHP (null não é escalar). */
function escalar(v: unknown): v is string | number | boolean {
  return typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean';
}

/** (string) de um escalar do PHP: true -> '1', false -> ''. */
function texto(v: unknown): string {
  if (typeof v === 'boolean') return v ? '1' : '';
  return escalar(v) ? String(v) : '';
}

/** Tamanho em caracteres (mb_strlen): conta pontos de código, não unidades UTF-16. */
export function mbLen(s: string): number {
  return [...s].length;
}

/** Valida um valor contra a regra declarada no catálogo. */
export async function validar(bruto: unknown, regra: Regra): Promise<Resultado> {
  const tipo = regra.tipo ?? 'texto';
  if (bruto === null || bruto === undefined) {
    return regra.nulo ? ok(null) : nao('Este campo não aceita valor nulo.');
  }
  switch (tipo) {
    case 'int':
      return int(bruto, regra);
    case 'bool':
      return bool(bruto);
    case 'decimal':
      return decimal(bruto, regra);
    case 'enum':
      return enumerado(bruto, regra);
    case 'json':
      return json(bruto);
    case 'data':
      return data(bruto);
    case 'slug':
      return slug(bruto, regra);
    case 'ref':
      return ref(bruto, regra);
    default:
      return textoLivre(bruto, regra);
  }
}

function textoLivre(bruto: unknown, regra: Regra): Resultado {
  if (typeof bruto === 'boolean' || typeof bruto === 'object') return nao('Esperava texto.');
  const v = String(bruto).trim();
  const max = regra.max ?? 255;
  if (mbLen(v) > max) return nao(`Texto longo demais (máximo de ${max} caracteres).`);
  // Texto vazio em coluna que aceita nulo vira NULL: uma só maneira de dizer "não preenchido".
  if (v === '' && regra.nulo) return ok(null);
  return ok(v);
}

function slug(bruto: unknown, regra: Regra): Resultado {
  const v = texto(bruto).trim().toLowerCase();
  if (v === '') return nao('O identificador não pode ficar vazio.');
  if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(v)) {
    return nao('Use apenas letras minúsculas, números e hífen (ex.: caca-ao-javali).');
  }
  const max = regra.max ?? 64;
  if (v.length > max) return nao(`Identificador longo demais (máximo de ${max} caracteres).`);
  return ok(v);
}

function int(bruto: unknown, regra: Regra): Resultado {
  if (typeof bruto === 'boolean' || typeof bruto === 'object') return nao('Esperava um número inteiro.');
  const v = typeof bruto === 'string' ? bruto.trim() : bruto;
  if (v === '') return regra.nulo ? ok(null) : nao('Esperava um número inteiro.');
  const ehInt = typeof v === 'number' && Number.isInteger(v);
  if (!ehInt && !/^-?\d{1,10}$/.test(String(v))) return nao('Esperava um número inteiro.');
  const n = typeof v === 'number' ? v : parseInt(String(v), 10);
  if (regra.min !== undefined && n < regra.min) return nao(`Valor mínimo: ${regra.min}.`);
  if (regra.max !== undefined && n > regra.max) return nao(`Valor máximo: ${regra.max}.`);
  return ok(n);
}

function bool(bruto: unknown): Resultado {
  if (typeof bruto === 'boolean') return ok(bruto ? 1 : 0);
  if (bruto === 0 || bruto === 1) return ok(bruto);
  const v = texto(bruto).trim().toLowerCase();
  if (['1', 'true', 'sim', 'on'].includes(v)) return ok(1);
  if (['0', 'false', 'nao', 'não', 'off'].includes(v)) return ok(0);
  return nao('Esperava verdadeiro ou falso.');
}

function decimal(bruto: unknown, regra: Regra): Resultado {
  if (typeof bruto === 'boolean' || typeof bruto === 'object') return nao('Esperava um número.');
  const v = typeof bruto === 'string' ? bruto.trim() : bruto;
  if (typeof v !== 'number' && !/^-?\d{1,7}(?:\.\d{1,6})?$/.test(String(v))) {
    return nao('Esperava um número decimal (use ponto, não vírgula).');
  }
  const n = typeof v === 'number' ? v : parseFloat(String(v));
  if (regra.min !== undefined && n < regra.min) return nao(`Valor mínimo: ${regra.min}.`);
  if (regra.max !== undefined && n > regra.max) return nao(`Valor máximo: ${regra.max}.`);
  // Arredonda para a escala da coluna aqui, para o cliente receber o que foi gravado.
  const f = 10 ** (regra.escala ?? 2);
  return ok(Math.round(n * f) / f);
}

function enumerado(bruto: unknown, regra: Regra): Resultado {
  const opcoes = (regra.opcoes ?? []).map(String);
  const v = texto(bruto).trim();
  if (v === '' && regra.nulo) return ok(null);
  if (!opcoes.includes(v)) return nao(`Valor fora da lista. Aceitos: ${opcoes.join(', ')}.`);
  return ok(v);
}

function json(bruto: unknown): Resultado {
  // Objeto/lista já decodificado é o caso normal; texto também é aceito.
  if (typeof bruto === 'object' && bruto !== null) return ok(JSON.stringify(bruto));
  const v = texto(bruto).trim();
  if (v === '') return ok(null);
  let d: unknown;
  try {
    d = JSON.parse(v);
  } catch {
    return nao('Conteúdo JSON inválido.');
  }
  return ok(JSON.stringify(d));
}

/** AAAA-MM-DD de verdade (31/02 não passa). */
export function dataValida(v: string): boolean {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v);
  if (!m) return false;
  const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
  return d.toISOString().slice(0, 10) === v;
}

function data(bruto: unknown): Resultado {
  const v = texto(bruto).trim();
  if (v === '') return ok(null);
  if (!dataValida(v)) return nao('Use uma data no formato AAAA-MM-DD.');
  return ok(v);
}

/** Referência a outra tabela (o esquema não usa FOREIGN KEY): confere que o id existe. */
async function ref(bruto: unknown, regra: Regra): Promise<Resultado> {
  const num = int(bruto, { min: 1, nulo: Boolean(regra.nulo) });
  if (!num.ok || num.valor === null) {
    return num.ok ? num : nao('Esperava o id (número) do registro referenciado.');
  }
  const tabela = regra.tabela ?? '';
  const coluna = regra.coluna ?? 'id';
  // Nome vem do catálogo (código), nunca do cliente — mas o formato é conferido do mesmo jeito.
  if (!/^[a-z_]{1,64}$/.test(tabela) || !/^[a-z_]{1,64}$/.test(coluna)) {
    throw new Error('Declaração de referência inválida no catálogo da API.');
  }
  const existe = Number(await DB.value(`SELECT COUNT(*) FROM "${tabela}" WHERE "${coluna}" = :v`, { v: num.valor }));
  if (existe === 0) return nao(`Não existe registro com este id em ${tabela}.`);
  return ok(num.valor);
}
