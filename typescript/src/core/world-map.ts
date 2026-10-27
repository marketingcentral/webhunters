/**
 * Mundo do mapa: regiões, bases, marcadores e camadas — o App\Core\WorldMap do PHP, mais as
 * leituras de catálogo que ele usa (CatalogRepo::maps/regionState/governmentBases/mapMarkers
 * e MapPointsRepo::porSlug).
 */
import { memo } from './context';
import * as DB from './database';
import * as GR from './game-rules';
import type { Dict } from './view';
import type { Operational } from '../repo/operational-repo';

export const LAYER_OF: Record<string, string> = {
  mission: 'missoes', extraction: 'extracao', gov_base: 'hunter', base: 'posto', camp: 'posto',
  village: 'posto', radio: 'posto', aegis: 'aegis', frontier: 'frontier', safe_zone: 'safe-zone',
  field_base: 'base-campo', nest: 'veyra', colony: 'veyra', surge: 'veyra', migration: 'veyra',
  core: 'veyra', enemy: 'veyra', contaminated: 'contaminacao', human_activity: 'humana',
  convoy: 'logistica', supply: 'logistica', supply_drop: 'logistica', resupply: 'logistica',
  abandoned_cargo: 'logistica', bio_regrowth: 'logistica', event: 'evento', loot: 'recursos',
  fuel: 'gasolina', market: 'mercado', hospital: 'hospital',
};

export const GLYPH_OF: Record<string, string> = {
  gov_base: 'gov-base', field_base: 'field-base', human_activity: 'human', safe_zone: 'safe-zone',
  colony: 'nest', surge: 'nest', migration: 'nest', core: 'nest',
  supply: 'convoy', supply_drop: 'convoy', resupply: 'convoy', abandoned_cargo: 'convoy', bio_regrowth: 'event',
};

export const OPACIDADE_PADRAO = 0.65;
export const OPACIDADE_MIN = 0.15;

const rnd2 = (v: number) => Math.round(v * 100) / 100;

function meta(v: unknown): Dict {
  if (typeof v !== 'string' || v === '') return {};
  try {
    const m = JSON.parse(v);
    return m && typeof m === 'object' && !Array.isArray(m) ? m : {};
  } catch {
    return {};
  }
}

// ------------------------------------------------------------------ catálogo

export function maps(kind: string): Promise<Dict[]> {
  return memo('catalog.maps:' + kind, async () => {
    const rows = await DB.all('SELECT * FROM maps WHERE kind = :k ORDER BY sort_order, id', { k: kind });
    for (const r of rows) r.meta = meta(r.meta);
    return rows;
  });
}

export function regionState(row: Dict): Dict {
  const m: Dict = row.meta && typeof row.meta === 'object' ? row.meta : {};
  const cap: Dict = m.capacity && typeof m.capacity === 'object' ? m.capacity : {};
  return {
    persistent: Boolean(m.persistent ?? true),
    capacity: {
      total: Number(cap.total ?? GR.REGION_CAPACITY.total),
      hunters: Number(cap.hunters ?? GR.REGION_CAPACITY.hunters),
      mercenaries: Number(cap.mercenaries ?? GR.REGION_CAPACITY.mercenaries),
    },
    infestation: Number(m.infestation ?? 0) | 0,
    veyra_activity: String(m.veyra_activity ?? 'MODERADA'),
    human_activity: String(m.human_activity ?? 'MODERADA'),
    weather: String(m.weather ?? ''),
    sector_state: String(m.sector_state ?? 'ATIVO'),
  };
}

export function governmentBases(): Promise<Dict[]> {
  return memo('catalog.gov_bases', async () => {
    let rows: Dict[] = [];
    try {
      rows = await DB.all('SELECT slug, name, map_id, capacity, status FROM government_bases ORDER BY sort_order, id');
    } catch {
      rows = [];
    }
    if (!rows.length) {
      rows = ['ALPHA', 'BRAVO', 'CHARLIE'].map((n) => ({
        slug: n.toLowerCase(), name: 'BASE HUNTER ' + n, map_id: null, capacity: 4, status: 'OPERACIONAL',
      }));
    }
    for (const r of rows) {
      r.capacity = Number(r.capacity);
      r.map_id = r.map_id !== null ? Number(r.map_id) : null;
      r.short = String(r.name).replace(/^BASE HUNTER /u, '');
    }
    return rows;
  });
}

export async function mapMarkers(mapId: number): Promise<Dict[]> {
  const rows = await DB.all('SELECT * FROM map_markers WHERE map_id = :m ORDER BY id', { m: mapId });
  for (const r of rows) r.meta = meta(r.meta);
  return rows;
}

/** Catálogo de pontos (map_point_types) por slug. Sem a tabela, o mapa desenha pelo `kind`. */
export function pointTypes(): Promise<Record<string, Dict>> {
  return memo('catalog.point_types', async () => {
    let linhas: Dict[] = [];
    try {
      linhas = await DB.all(
        'SELECT id, slug, label, category, kind, icon, color, layer, show_label, description, is_system, sort_order FROM map_point_types ORDER BY sort_order, label',
      );
    } catch {
      linhas = [];
    }
    const out: Record<string, Dict> = {};
    for (const l of linhas) {
      l.show_label = Boolean(Number(l.show_label));
      l.is_system = Boolean(Number(l.is_system));
      out[String(l.slug)] = l;
    }
    return out;
  });
}

// ------------------------------------------------------------------ mundo

export async function regions(survivor: boolean): Promise<Dict[]> {
  return (await maps('region')).map((row) => {
    const m: Dict = row.meta;
    const key = String(m.key ?? String(row.slug ?? '').replace(/^regiao-/, ''));
    return {
      map_id: Number(row.id),
      slug: key,
      name: String(row.name ?? ''),
      level: String(m.level ?? ''),
      icon: String(m.icon ?? 'pine'),
      locked: Boolean(Number(row.locked ?? 0)),
      image: String(row.image ?? ''),
      world_image: String(m.world_image ?? ''),
      map_opacity: m.map_opacity !== undefined && m.map_opacity !== null ? Number(m.map_opacity) : null,
      desc: String(row.description ?? ''),
      info: info(Array.isArray(m.info) ? m.info : []),
      extractions: extractions(Array.isArray(m.extractions) ? m.extractions : [], survivor),
      event: m.event && typeof m.event === 'object' ? m.event : null,
      state: regionState(row),
    };
  });
}

export function fromMock(mock: Dict[], survivor: boolean): Dict[] {
  return mock.map((r) => ({
    ...r,
    map_id: null,
    info: info(r.info ?? []),
    extractions: extractions(r.extractions ?? [], survivor),
    state: regionState({ meta: r.state ?? {} }),
  }));
}

export function info(lista: Dict[]): Dict[] {
  return lista.map((it0) => {
    const it = { ...it0 };
    const label = String(it.label ?? '').toUpperCase();
    if (label === 'JOGADORES MÁX.' || label === 'JOGADORES MÁX') {
      it.label = 'CAPACIDADE OPERACIONAL';
      it.value = String(GR.REGION_CAPACITY.total);
      it.tip = `Até ${GR.REGION_CAPACITY.hunters} Hunters e ${GR.REGION_CAPACITY.mercenaries} Mercenários.`;
    } else if (label === 'TEMPO DO CICLO') {
      it.label = 'MUNDO';
      it.value = 'PERSISTENTE';
      it.cls = 'lime';
    } else if (label === 'CLIMA') {
      it.label = 'CLIMA ATUAL';
    }
    return it;
  });
}

export function extractions(lista: Dict[], survivor: boolean): Dict[] {
  return lista.map((x0) => {
    const x = { ...x0 };
    const type = String(x.type ?? 'publica').toLowerCase();
    const t = GR.EXTRACTION_TYPES[type] ?? GR.EXTRACTION_TYPES.publica!;
    x.type = type;
    x.type_label = t.label;
    x.type_color = t.color;
    x.tip = t.who;
    const state = String(x.state ?? 'active').toLowerCase();
    x.status = String(x.status ?? GR.EXTRACTION_STATES[state]?.label ?? 'ATIVA');
    if (survivor) {
      x.state = 'blocked';
      x.status = GR.EXTRACTION_STATES.blocked!.label;
      x.tip = GR.SURVIVOR_EXTRACTION_TIP;
    } else {
      x.state = state;
    }
    return x;
  });
}

export async function bases(mapId: number | null): Promise<Dict[]> {
  const out: Dict[] = [];
  for (const b of await governmentBases()) {
    if (mapId !== null && b.map_id !== null && Number(b.map_id) !== mapId) continue;
    const status = String(b.status ?? 'OPERACIONAL').toUpperCase();
    out.push({
      slug: String(b.slug),
      name: String(b.name),
      short: String(b.short ?? b.name),
      status,
      color: GR.BASE_STATUS[status] ?? 'muted',
    });
  }
  return out;
}

export async function markers(mapId: number, op: Operational, bs: Dict[]): Promise<Dict[]> {
  const tipos = await pointTypes();
  const out: Dict[] = [];
  for (const row of await mapMarkers(mapId)) {
    const m: Dict = row.meta;
    const kind = String(row.kind ?? 'loot');
    // O tipo do catálogo é o PADRÃO; o que o marcador gravou no próprio meta vence.
    const tipo = tipos[String(m.point_type ?? '')] ?? null;
    const mk: Dict = {
      id: String(m.id ?? 'mk-' + String(row.id ?? '0')),
      kind,
      glyph: String(m.glyph ?? GLYPH_OF[kind] ?? kind),
      color: String(m.color ?? tipo?.color ?? 'white'),
      name: String(m.name ?? row.label ?? ''),
      x: rnd2(Number(row.x ?? 0) * 100),
      y: rnd2(Number(row.y ?? 0) * 100),
      label: m.label ?? (tipo !== null && tipo.show_label ? 'right' : false),
    };
    if (typeof m.icon === 'string' && m.icon !== '') mk.icon = m.icon;
    if (tipo !== null) {
      mk.point_type = String(tipo.slug);
      mk.icon = String(m.icon ?? tipo.icon);
      if (tipo.layer !== null && tipo.layer !== undefined && tipo.layer !== '') mk.layer_forced = String(tipo.layer);
    }
    if (m.narrow) mk.narrow = true;
    if (m.pulse) mk.pulse = true;
    if (m.risk && typeof m.risk === 'object') mk.risk = m.risk;
    for (const k of ['base', 'faction', 'caste', 'signal', 'event', 'owner', 'status', 'ttl_min', 'zone', 'tag', 'radius']) {
      if (m[k] !== undefined && m[k] !== null) mk[k] = m[k];
    }
    out.push(decorate(mk, op, bs));
  }
  return out;
}

export function mockMarkers(mock: Dict[], op: Operational, bs: Dict[]): Dict[] {
  return mock.map((mk0) => {
    const mk = { ...mk0 };
    mk.kind = String(mk.kind ?? mk.glyph ?? 'loot');
    mk.glyph = String(mk.glyph ?? GLYPH_OF[mk.kind] ?? mk.kind);
    return decorate(mk, op, bs);
  });
}

/** Rótulo, cor e dica de cada marcador conforme o estado operacional de quem olha. */
export function decorate(mk: Dict, op: Operational, bs: Dict[]): Dict {
  const kind = String(mk.kind);
  mk.layer = String(mk.layer_forced ?? LAYER_OF[kind] ?? 'eventos');
  mk.sub = null;
  mk.tip = null;
  switch (kind) {
    case 'gov_base': {
      let status: string | null = null;
      for (const b of bs) {
        if (mk.base !== undefined && b.slug === String(mk.base)) {
          status = b.status;
          break;
        }
      }
      status ??= String(mk.status ?? 'OPERACIONAL').toUpperCase();
      mk.status = status;
      mk.sub = status;
      mk.sub_color = GR.BASE_STATUS[status] ?? 'muted';
      if (['ISOLADA', 'SOB ATAQUE', 'COMPROMETIDA'].includes(status)) {
        mk.pulse = true;
        mk.color = 'red';
      }
      break;
    }
    case 'aegis':
    case 'frontier': {
      const fac = String(mk.faction ?? kind);
      const rival = fac === 'aegis' ? 'frontier' : 'aegis';
      const mine = String(op.faction ?? '');
      const locked = (op.locked?.[fac] ?? null) !== null;
      const status = locked ? 'BLOQUEADA' : mine === fac ? 'ALIADA' : mine === rival ? 'HOSTIL' : 'NEUTRA';
      mk.status = status;
      mk.sub = status;
      mk.sub_color = GR.FACTION_SITE_STATUS[status] ?? 'muted';
      mk.tip = ({
        ALIADA: 'Instalação da sua facção contratante.',
        HOSTIL: 'Instalação da facção rival ao seu contrato.',
        BLOQUEADA: 'Acesso revogado após rompimento de contrato.',
      } as Record<string, string>)[status] ?? 'Sem contrato ativo: acesso neutro.';
      break;
    }
    case 'human_activity': {
      const signal = String(mk.signal ?? 'movement');
      mk.name = String(mk.name || GR.HUMAN_SIGNALS[signal] || 'ATIVIDADE HUMANA');
      const ttl = Number(mk.ttl_min ?? 30) | 0;
      mk.expires = Math.max(60, ttl * 60);
      mk.sub = 'POSIÇÃO APROXIMADA';
      mk.tip = `Sinal temporário; a posição é aproximada e expira em ${ttl} min.`;
      mk.pulse = true;
      break;
    }
    case 'nest': case 'colony': case 'surge': case 'migration': case 'core': case 'contaminated': {
      const cria = GR.creature(String(mk.caste ?? ''));
      if (cria !== null) {
        mk.caste = String(cria.slug);
        mk.sub = String(cria.nome).toUpperCase() + ' · ' + String(cria.papel ?? '').toUpperCase();
        mk.tip = String(cria.linha ?? '');
      }
      mk.sub_color = kind === 'contaminated' ? 'purple' : 'red';
      break;
    }
    case 'safe_zone':
      mk.sub = 'SAFE ZONE';
      mk.sub_color = 'lime';
      mk.tip = 'Perímetro protegido: papéis civis, mercado e oficina entre operações.';
      if (op.state === GR.STATE_SURVIVOR && (mk.zone ?? '') !== 'franca') {
        mk.sub = 'SEM ACESSO';
        mk.sub_color = 'muted';
        mk.color = 'muted';
        mk.tip = 'Nenhuma base do governo responde por um Sobrevivente.';
      }
      break;
    case 'field_base': {
      const owner = String(mk.owner ?? 'unknown');
      mk.sub = { mine: 'MINHA', squad: 'ESQUADRÃO', compromised: 'COMPROMETIDA' }[owner] ?? 'DESCONHECIDA';
      mk.sub_color = { mine: 'lime', squad: 'lime', compromised: 'red' }[owner] ?? 'muted';
      break;
    }
    case 'convoy': case 'supply': case 'supply_drop': case 'resupply': case 'abandoned_cargo': case 'bio_regrowth': {
      const ev = String(mk.event ?? kind);
      mk.sub = GR.WORLD_EVENTS[ev]?.logistic ? 'JANELA LOGÍSTICA' : GR.WORLD_EVENTS[ev]?.label ?? 'CICLO DE MUNDO';
      mk.sub_color = String(mk.color);
      mk.tip = 'Ciclo de mundo: recursos voltam por logística, nunca por respawn.';
      break;
    }
    case 'extraction':
      if (op.state === GR.STATE_SURVIVOR) {
        mk.sub = GR.EXTRACTION_STATES.blocked!.label;
        mk.sub_color = 'muted';
        mk.color = 'muted';
        mk.tip = GR.SURVIVOR_EXTRACTION_TIP;
        mk.blocked = true;
      }
      break;
  }
  return mk;
}

export function factionSites(mks: Dict[]): Dict[] {
  return mks
    .filter((m) => ['aegis', 'frontier'].includes(String(m.kind ?? '')))
    .map((m) => ({
      faction: String(m.faction ?? m.kind),
      name: String(m.name),
      status: String(m.status ?? 'NEUTRA'),
      color: String(m.sub_color ?? 'muted'),
      tip: String(m.tip ?? ''),
    }));
}

export function opacidade(region: Dict): number {
  const v = region.map_opacity ?? null;
  return v !== null && v !== undefined ? Math.max(OPACIDADE_MIN, Math.min(1, Number(v))) : OPACIDADE_PADRAO;
}
