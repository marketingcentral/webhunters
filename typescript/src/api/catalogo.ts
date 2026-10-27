/**
 * AS DECLARAÇÕES DAS COLEÇÕES — App\Api\Catalogo do PHP. Contrato público: o nome do campo é
 * o que o cliente manda, a regra é o que ele pode mandar, e o que não está aqui não existe
 * para a API. `enum` só onde a lista é ESTRUTURAL; `padrao` onde a coluna é NOT NULL sem DEFAULT.
 */
import type { Declaracao } from './tabela';

/** Constantes de App\Repo\ShopOfferRepo usadas pela API (a mesma lista da loja). */
export const MOEDAS = ['credits', 'cash', 'points'] as const;
export const DESCONTO_MAX = 90;
export const TAG_MAX = 16;
export const ORDEM_MAX = 65535;
export const PRECO_MAX = 99999999;

export function colecoes(): Record<string, Declaracao> {
  return { missions: missoes(), achievements: conquistas(), maps: mapas(), items: itens(), shop: loja() };
}

export function missoes(): Declaracao {
  return {
    tabela: 'missions',
    chave: 'id',
    ordem: 'sort_order ASC, id ASC',
    busca: ['slug', 'name', 'giver', 'region'],
    filtros: { type: 'type', origin: 'origin', region: 'region' },
    campos: {
      slug: { tipo: 'slug', max: 64, obrigatorio: true, unico: true },
      name: { tipo: 'texto', max: 120, obrigatorio: true },
      giver: { tipo: 'texto', max: 80, nulo: true },
      region: { tipo: 'texto', max: 80, nulo: true },
      type: { tipo: 'enum', opcoes: ['principal', 'contrato', 'diaria', 'emergencia'], obrigatorio: true },
      difficulty: { tipo: 'texto', max: 16, nulo: true },
      duration_min: { tipo: 'int', min: 0, max: 32767, nulo: true },
      players_max: { tipo: 'int', min: 1, max: 127, nulo: true },
      level_req: { tipo: 'int', min: 0, max: 32767, nulo: true },
      objective: { tipo: 'texto', max: 400, nulo: true },
      target: { tipo: 'int', min: 0, max: 32767, nulo: true },
      image: { tipo: 'texto', max: 160, nulo: true },
      locked: { tipo: 'bool', padrao: 0 },
      sort_order: { tipo: 'int', min: 0, max: 65535, padrao: 0 },
      origin: { tipo: 'enum', opcoes: ['world', 'aegis', 'frontier', 'hunter_command', 'icarus'], padrao: 'world' },
      act: { tipo: 'int', min: 0, max: 255, nulo: true },
      requires_faction: { tipo: 'enum', opcoes: ['any', 'aegis', 'frontier'], nulo: true },
      requires_tier: { tipo: 'texto', max: 24, nulo: true },
      requires_rep: { tipo: 'int', min: 0, max: 999999, nulo: true },
      squad_min: { tipo: 'int', min: 1, max: 255, padrao: 1 },
      squad_max: { tipo: 'int', min: 1, max: 255, padrao: 4 },
      human_activity: { tipo: 'texto', max: 16, nulo: true },
    },
    dependentes: [
      { tabela: 'player_missions', coluna: 'mission_id', rotulo: 'progresso de jogadores' },
      { tabela: 'mission_rewards', coluna: 'mission_id', rotulo: 'recompensas' },
    ],
  };
}

export function conquistas(): Declaracao {
  return {
    tabela: 'achievements',
    chave: 'id',
    ordem: 'sort_order ASC, id ASC',
    busca: ['slug', 'name', 'description'],
    filtros: { category: 'category' },
    campos: {
      slug: { tipo: 'slug', max: 64, obrigatorio: true, unico: true },
      name: { tipo: 'texto', max: 120, obrigatorio: true },
      category: { tipo: 'texto', max: 24, obrigatorio: true },
      description: { tipo: 'texto', max: 400, nulo: true },
      // Ligadura do Material Symbols, não caminho de arquivo.
      icon: { tipo: 'texto', max: 48, nulo: true },
      target: { tipo: 'int', min: 1, max: 999999, padrao: 1 },
      sort_order: { tipo: 'int', min: 0, max: 65535, padrao: 0 },
    },
    dependentes: [
      { tabela: 'player_achievements', coluna: 'achievement_id', rotulo: 'progresso de jogadores' },
      { tabela: 'achievement_rewards', coluna: 'achievement_id', rotulo: 'recompensas' },
    ],
  };
}

export function mapas(): Declaracao {
  return {
    tabela: 'maps',
    chave: 'id',
    ordem: 'sort_order ASC, id ASC',
    busca: ['slug', 'name', 'mode'],
    filtros: { kind: 'kind' },
    campos: {
      slug: { tipo: 'slug', max: 64, obrigatorio: true, unico: true },
      name: { tipo: 'texto', max: 80, obrigatorio: true },
      kind: { tipo: 'enum', opcoes: ['region', 'match'], obrigatorio: true },
      mode: { tipo: 'texto', max: 40, nulo: true },
      players: { tipo: 'texto', max: 16, nulo: true },
      level_min: { tipo: 'int', min: 0, max: 32767, nulo: true },
      level_max: { tipo: 'int', min: 0, max: 32767, nulo: true },
      locked: { tipo: 'bool', padrao: 0 },
      image: { tipo: 'texto', max: 160, nulo: true },
      description: { tipo: 'texto', max: 400, nulo: true },
      unreal_level: { tipo: 'texto', max: 200, nulo: true },
      meta: { tipo: 'json', nulo: true },
      sort_order: { tipo: 'int', min: 0, max: 65535, padrao: 0 },
    },
    dependentes: [{ tabela: 'map_markers', coluna: 'map_id', rotulo: 'marcadores' }],
  };
}

/** Marcadores de UM mapa: `pai` faz a coleção só existir dentro de /maps/{id}. */
export function marcadores(): Declaracao {
  return {
    tabela: 'map_markers',
    chave: 'id',
    pai: 'map_id',
    ordem: 'id ASC',
    busca: ['label', 'kind'],
    filtros: { kind: 'kind' },
    campos: {
      kind: { tipo: 'texto', max: 24, obrigatorio: true },
      label: { tipo: 'texto', max: 80, nulo: true },
      // Frações da imagem (0 a 1), quatro casas como o DECIMAL(6,4) do MySQL.
      x: { tipo: 'decimal', min: 0, max: 1, escala: 4, obrigatorio: true },
      y: { tipo: 'decimal', min: 0, max: 1, escala: 4, obrigatorio: true },
      meta: { tipo: 'json', nulo: true },
    },
  };
}

export function itens(): Declaracao {
  return {
    tabela: 'items',
    chave: 'id',
    ordem: 'name ASC, id ASC',
    busca: ['slug', 'name', 'short_name'],
    filtros: { category: 'category', rarity: 'rarity', slot_type: 'slot_type' },
    campos: {
      slug: { tipo: 'slug', max: 64, obrigatorio: true, unico: true },
      name: { tipo: 'texto', max: 120, obrigatorio: true },
      short_name: { tipo: 'texto', max: 32, nulo: true },
      category: { tipo: 'texto', max: 24, obrigatorio: true },
      subtype: { tipo: 'texto', max: 32, nulo: true },
      slot_type: { tipo: 'texto', max: 24, nulo: true },
      grid_w: { tipo: 'int', min: 1, max: 255, padrao: 1 },
      grid_h: { tipo: 'int', min: 1, max: 255, padrao: 1 },
      weight_kg: { tipo: 'decimal', min: 0, max: 999, escala: 3, padrao: 0 },
      rarity: { tipo: 'texto', max: 16, padrao: 'comum' },
      image: { tipo: 'texto', max: 160, obrigatorio: true },
      description: { tipo: 'texto', max: 400, nulo: true },
      base_price: { tipo: 'int', min: 0, max: 99999999, padrao: 0 },
      currency: { tipo: 'enum', opcoes: MOEDAS, padrao: 'credits' },
      stackable: { tipo: 'bool', padrao: 0 },
      max_stack: { tipo: 'int', min: 1, max: 65535, padrao: 1 },
      durability_max: { tipo: 'int', min: 0, max: 65535, nulo: true },
      grid_cols: { tipo: 'int', min: 0, max: 255, nulo: true },
      grid_rows: { tipo: 'int', min: 0, max: 255, nulo: true },
      ammo_caliber: { tipo: 'texto', max: 24, nulo: true },
      mag_capacity: { tipo: 'int', min: 0, max: 65535, nulo: true },
      attach_slots: { tipo: 'json', nulo: true },
      fits_calibers: { tipo: 'json', nulo: true },
      meta: { tipo: 'json', nulo: true },
    },
    dependentes: [
      { tabela: 'player_items', coluna: 'item_id', rotulo: 'itens no inventário de jogadores' },
      { tabela: 'shop_offers', coluna: 'item_id', rotulo: 'ofertas na loja' },
    ],
  };
}

export function loja(): Declaracao {
  return {
    tabela: 'shop_offers',
    chave: 'id',
    ordem: 'sort_order ASC, id ASC',
    busca: ['category', 'tag'],
    filtros: { category: 'category', currency: 'currency' },
    campos: {
      // `ref` confere que o item existe antes de gravar (o esquema não tem FOREIGN KEY).
      item_id: { tipo: 'ref', tabela: 'items', obrigatorio: true },
      category: { tipo: 'texto', max: 24, obrigatorio: true },
      price: { tipo: 'int', min: 0, max: PRECO_MAX, obrigatorio: true },
      currency: { tipo: 'enum', opcoes: MOEDAS, padrao: 'credits' },
      discount: { tipo: 'int', min: 0, max: DESCONTO_MAX, nulo: true },
      tag: { tipo: 'texto', max: TAG_MAX, nulo: true },
      featured: { tipo: 'bool', padrao: 0 },
      sort_order: { tipo: 'int', min: 0, max: ORDEM_MAX, padrao: 0 },
    },
  };
}

/** As três coleções de /api/v1/events (desafios, notícias, avisos). */
export function eventos(): Record<string, Declaracao> {
  return {
    challenges: {
      tabela: 'challenges',
      chave: 'id',
      ordem: 'sort_order ASC, id ASC',
      busca: ['slug', 'label', 'description'],
      filtros: { scope: 'scope' },
      campos: {
        slug: { tipo: 'slug', max: 64, obrigatorio: true, unico: true },
        scope: { tipo: 'enum', opcoes: ['diarios', 'semanais', 'temporada'], obrigatorio: true },
        season_id: { tipo: 'ref', tabela: 'seasons', nulo: true },
        label: { tipo: 'texto', max: 160, obrigatorio: true },
        description: { tipo: 'texto', max: 400, nulo: true },
        icon: { tipo: 'texto', max: 48, nulo: true },
        target: { tipo: 'int', min: 1, max: 999999, padrao: 1 },
        xp: { tipo: 'int', min: 0, max: 9999999, padrao: 0 },
        credits: { tipo: 'int', min: 0, max: 9999999, padrao: 0 },
        highlight: { tipo: 'bool', padrao: 0 },
        sort_order: { tipo: 'int', min: 0, max: 65535, padrao: 0 },
      },
      dependentes: [{ tabela: 'player_challenges', coluna: 'challenge_id', rotulo: 'progresso de jogadores' }],
    },
    news: {
      tabela: 'news',
      chave: 'id',
      ordem: 'sort_order ASC, id DESC',
      busca: ['slug', 'title', 'excerpt'],
      filtros: { category: 'category' },
      campos: {
        slug: { tipo: 'slug', max: 80, obrigatorio: true, unico: true },
        title: { tipo: 'texto', max: 160, obrigatorio: true },
        category: { tipo: 'texto', max: 24, nulo: true },
        excerpt: { tipo: 'texto', max: 400, nulo: true },
        body: { tipo: 'texto', max: 20000, nulo: true },
        image: { tipo: 'texto', max: 160, nulo: true },
        published: { tipo: 'data', nulo: true },
        sort_order: { tipo: 'int', min: 0, max: 65535, padrao: 0 },
      },
    },
    notices: {
      tabela: 'system_notices',
      chave: 'id',
      ordem: 'sort_order ASC, id DESC',
      busca: ['title', 'body'],
      filtros: { color: 'color' },
      saida_extra: { created_at: 'created_at' },
      campos: {
        user_id: { tipo: 'ref', tabela: 'users', nulo: true },
        icon: { tipo: 'texto', max: 48, padrao: 'campaign' },
        color: { tipo: 'enum', opcoes: ['lime', 'gold', 'amber', 'red'], padrao: 'lime' },
        title: { tipo: 'texto', max: 120, obrigatorio: true },
        body: { tipo: 'texto', max: 400, obrigatorio: true },
        cta_label: { tipo: 'texto', max: 40, nulo: true },
        cta_href: { tipo: 'texto', max: 120, nulo: true },
        fresh: { tipo: 'bool', padrao: 1 },
        sort_order: { tipo: 'int', min: 0, max: 65535, padrao: 0 },
      },
    },
  };
}
