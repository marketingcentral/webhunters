/**
 * Catálogo de PONTOS PRÉ-FABRICADOS do mapa (`map_point_types`) — o App\Repo\MapPointsRepo do PHP.
 *
 * É a ponte entre o painel e o jogo. O operador cadastra "Café", "Shopping", "Posto de
 * gasolina" uma vez; o editor de mapa arrasta esse ícone para onde quiser, e cada marcador
 * guarda só o SLUG do tipo (`map_markers.meta.point_type`). Assim trocar o ícone do café
 * conserta todos os cafés do mundo de uma vez, em vez de exigir reedição marcador a marcador.
 *
 * DUAS REGRAS QUE EXPLICAM O RESTO:
 *
 * 1. O tipo não substitui `map_markers.kind`. `kind` continua sendo o vocabulário que o JOGO
 *    entende (camada do filtro, comportamento de extração bloqueada, pulso de base sob ataque).
 *    O tipo diz qual DESENHO e qual COR, e qual kind gravar quando o operador arrasta. Um
 *    marcador que perca o tipo continua funcionando: cai no comportamento do kind.
 *
 * 2. `is_system` marca os tipos que nascem com o jogo (extração, base Hunter, ninho Veyra…).
 *    O painel edita rótulo e ícone deles, mas não apaga.
 *
 * O catálogo é pequeno e lido em toda montagem do mapa, então `todos()` guarda o resultado no
 * cache DA REQUISIÇÃO (ctx().cache — o `static $cache` do PHP). Nada de cache entre requisições.
 *
 * Relação com core/world-map.ts: lá `pointTypes()` faz a MESMA leitura para o mapa do jogo
 * (memo 'catalog.point_types'). Este arquivo tem a sua própria cópia porque precisa invalidar
 * o cache depois de gravar (o PHP zerava `self::$cache`); ao gravar, as duas chaves caem.
 */
import { ctx } from '../core/context';
import * as DB from '../core/database';
import type { Dict } from '../core/view';

/** Categorias aceitas. A chave vai para o banco; o valor é o que o painel escreve na tela. */
export const CATEGORIAS: Record<string, string> = {
  operacional: 'Operacional',
  hostil: 'Hostil / Veyra',
  logistica: 'Logística',
  combustivel: 'Combustível',
  alimentacao: 'Alimentação',
  comercio: 'Comércio',
  saude: 'Saúde',
  outros: 'Outros',
};

/** Origens aceitas no campo `icon`, no formato `origem:valor`. */
export const ORIGENS_ICONE = ['glyph', 'symbol', 'image'];

/** Chave do cache da requisição (e a do world-map.ts, que lê a mesma tabela). */
const CHAVE = 'admin.map_point_types';
const CHAVE_MUNDO = 'catalog.point_types';

/** Esquece o catálogo desta requisição (o `self::$cache = null` do PHP). */
function esquecer(): void {
  const c = ctx().cache;
  c.delete(CHAVE);
  c.delete(CHAVE_MUNDO);
}

/** Grupo da paleta: categoria + itens. */
export interface Grupo {
  key: string;
  label: string;
  items: Dict[];
}

/** Catálogo inteiro, já ordenado por categoria e posição. */
export async function todos(): Promise<Dict[]> {
  const c = ctx().cache;
  if (c.has(CHAVE)) return c.get(CHAVE) as Dict[];
  let linhas: Dict[];
  try {
    linhas = await DB.all<Dict>(
      'SELECT id, slug, label, category, kind, icon, color, layer, show_label, description, is_system, sort_order' +
        ' FROM map_point_types ORDER BY sort_order, label',
    );
  } catch {
    // Banco sem a tabela (migration 0007): o mapa continua desenhando pelo `kind`, sem catálogo.
    linhas = [];
  }
  for (const l of linhas) {
    l.show_label = Boolean(Number(l.show_label));
    l.is_system = Boolean(Number(l.is_system));
  }
  c.set(CHAVE, linhas);
  return linhas;
}

/**
 * Catálogo indexado pelo slug — a forma que o mapa consulta marcador a marcador.
 * Map (e não objeto) para não reordenar slugs numéricos: a ordem é a do catálogo.
 */
export async function porSlug(): Promise<Map<string, Dict>> {
  const out = new Map<string, Dict>();
  for (const t of await todos()) out.set(String(t.slug), t);
  return out;
}

/**
 * Catálogo agrupado por categoria, no formato que a paleta do editor desenha.
 * Categoria sem nenhum tipo não aparece — paleta com gaveta vazia é ruído.
 */
export async function porCategoria(): Promise<Grupo[]> {
  const balde = new Map<string, Dict[]>();
  for (const t of await todos()) {
    const k = String(t.category);
    if (!balde.has(k)) balde.set(k, []);
    balde.get(k)!.push(t);
  }
  const out: Grupo[] = [];
  for (const [chave, rotulo] of Object.entries(CATEGORIAS)) {
    const itens = balde.get(chave);
    if (!itens || itens.length === 0) continue;
    out.push({ key: chave, label: rotulo, items: itens });
    balde.delete(chave);
  }
  // Categoria gravada por fora da lista fechada ainda assim aparece, com o nome cru:
  // esconder seria perder o ponto no primeiro salvamento do editor.
  for (const [chave, itens] of balde) out.push({ key: chave, label: chave, items: itens });
  return out;
}

export async function porId(id: number): Promise<Dict | null> {
  for (const t of await todos()) if ((Number(t.id) | 0) === id) return t;
  return null;
}

/** Slug já usado por OUTRO tipo (o próprio não conta, senão ninguém salva sem renomear). */
export async function slugEmUso(slug: string, exceto = 0): Promise<boolean> {
  for (const t of await todos()) {
    if (String(t.slug) === slug && (Number(t.id) | 0) !== exceto) return true;
  }
  return false;
}

/**
 * Quantos marcadores do mundo usam cada tipo (slug => total). É o que impede o painel de
 * apagar um tipo em uso sem avisar quantos pontos do mapa ficariam sem desenho próprio.
 *
 * MySQL JSON_UNQUOTE(JSON_EXTRACT()) → json_extract() do SQLite (já devolve o texto cru).
 * `json_valid(meta)` a mais: no MySQL a coluna era JSON e nunca guardava texto inválido; no
 * SQLite um único meta estragado faria o json_extract derrubar a consulta inteira.
 */
export async function usos(): Promise<Record<string, number>> {
  let linhas: Dict[];
  try {
    linhas = await DB.all<Dict>(
      "SELECT json_extract(meta, '$.point_type') AS slug, COUNT(*) AS total" +
        ' FROM map_markers WHERE meta IS NOT NULL AND json_valid(meta) GROUP BY slug',
    );
  } catch {
    return {};
  }
  const out: Record<string, number> = {};
  for (const l of linhas) {
    const s = l.slug === null || l.slug === undefined ? '' : String(l.slug);
    if (s !== '' && s !== 'null') out[s] = Number(l.total) | 0;
  }
  return out;
}

/** Cria o tipo; devolve o id novo. */
export async function criar(d: Dict): Promise<number> {
  esquecer();
  // `is_system` é fixado em 0 aqui, e não vem de d: ponto criado pelo painel nunca
  // nasce sendo do jogo — quem é do jogo entra por patch.
  return DB.insert('map_point_types', {
    slug: d.slug,
    label: d.label,
    category: d.category,
    kind: d.kind,
    icon: d.icon,
    color: d.color,
    layer: d.layer,
    show_label: d.show_label,
    description: d.description,
    is_system: 0,
    sort_order: d.sort_order,
  });
}

export async function atualizar(id: number, d: Dict): Promise<void> {
  esquecer();
  await DB.run(
    'UPDATE map_point_types SET slug=:slug, label=:label, category=:category, kind=:kind, icon=:icon,' +
      ' color=:color, layer=:layer, show_label=:show_label, description=:description, sort_order=:sort_order' +
      ' WHERE id = :id',
    { ...d, id },
  );
}

/**
 * Apaga o tipo. Os marcadores que o usavam NÃO são apagados junto — eles voltam a ser
 * desenhados pelo `kind`, que é exatamente o que um mapa sem catálogo sempre fez.
 */
export async function excluir(id: number): Promise<void> {
  esquecer();
  await DB.run('DELETE FROM map_point_types WHERE id = :id AND is_system = 0', { id });
}

/**
 * Quebra `icon` em [origem, valor]. Entrada inválida vira o símbolo genérico, nunca erro
 * de tela: um ícone quebrado não pode derrubar o mapa inteiro.
 */
export function icone(icon: string): [string, string] {
  const i = icon.indexOf(':');
  if (i < 0) return ['symbol', 'place'];
  const origem = icon.slice(0, i);
  const valor = icon.slice(i + 1);
  if (!ORIGENS_ICONE.includes(origem) || valor === '') return ['symbol', 'place'];
  return [origem, valor];
}
