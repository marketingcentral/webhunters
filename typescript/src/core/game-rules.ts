/**
 * Regras e vocabulário do jogo — o App\Core\GameRules do PHP (PRD v3 + canon v6).
 * Só constantes e funções puras; o catálogo de criaturas vem de src/data/criaturas.json.
 */
import { dataMock } from './helpers';

export const SQUAD_MAX = 4;
export const REGION_CAPACITY = { total: 20, hunters: 12, mercenaries: 8 };

export const STATE_HUNTER = 'HUNTER';
export const STATE_MERCENARY = 'MERCENARY';
export const STATE_SURVIVOR = 'SURVIVOR';
export const STATES = [STATE_HUNTER, STATE_MERCENARY, STATE_SURVIVOR];

export const RANKS = ['RECRUTA', 'SOLDADO', 'CABO', 'SARGENTO', 'TENENTE', 'CAPITÃO'];

export interface Role {
  label: string;
  icon: string;
  ms: string;
  desc: string;
  legacy: string[];
}
export const ROLES: Record<string, Role> = {
  ASSAULT: {
    label: 'ASSAULT', icon: 'class-assault', ms: 'swords',
    desc: 'Abre espaço, sustenta contato direto e leva a maior capacidade ofensiva da equipe.',
    legacy: ['ASSALTO'],
  },
  SNIPER: {
    label: 'SNIPER', icon: 'class-sniper', ms: 'my_location',
    desc: 'Reconhecimento de longo alcance, cobertura, marcação e eliminação de ameaças prioritárias.',
    legacy: ['FRANCO-ATIRADOR', 'FRANCO ATIRADOR'],
  },
  MEDICAL: {
    label: 'MEDICAL', icon: 'class-support', ms: 'medical_services',
    desc: 'Estabiliza feridos, administra recursos médicos e reduz a chance de uma baixa virar morte definitiva.',
    legacy: ['MÉDICO', 'MEDICO', 'SUPORTE'],
  },
  RASTREADOR: {
    label: 'RASTREADOR', icon: 'class-scout', ms: 'visibility',
    desc: 'Lê o setor antes do contato: rastros de infectados, Vigias no alto, pegadas, ruído e rotas recentes.',
    legacy: ['BATEDOR'],
  },
};

export interface Faction {
  label: string;
  color: string;
  tagline: string;
  identity: string;
  economy: string;
  missions: string;
  npc: string;
}
export const FACTIONS: Record<string, Faction> = {
  aegis: {
    label: 'AEGIS', color: 'cyan',
    tagline: 'Território só pertence a quem consegue mantê-lo.',
    identity: 'Força, blindagem, veículos e postos fortificados.',
    economy: 'Peças militares, munição, componentes de veículo e logística.',
    missions: 'Escoltas, defesas, ataques, recuperação de armas e controle de instalações.',
    npc: 'Patrulhas armadas, fogo de apoio e defesa de posições Aegis.',
  },
  frontier: {
    label: 'FRONTIER', color: 'amber',
    tagline: 'Conhecer o que existe entre os territórios.',
    identity: 'Rotas ocultas, túneis, esconderijos e caminhos fora da vigilância militar.',
    economy: 'Itens raros, medicina de campo, materiais científicos e mercado clandestino.',
    missions: 'Infiltração, reconhecimento, contrabando, resgate e recuperação discreta.',
    npc: 'Guias, alertas de perigo, apoio local e acessos alternativos.',
  },
};

export const REP_TIERS = [
  { min: 0, label: 'RECRUTADO' },
  { min: 500, label: 'OPERADOR' },
  { min: 1500, label: 'CONFIÁVEL' },
  { min: 3000, label: 'VETERANO' },
];

export const ORIGINS: Record<string, { label: string; color: string; badge: string }> = {
  hunter_command: { label: 'HUNTER COMMAND', color: 'lime', badge: 'OPERAÇÃO HUNTER' },
  aegis: { label: 'AEGIS', color: 'cyan', badge: 'CONTRATO MERCENÁRIO' },
  frontier: { label: 'FRONTIER', color: 'amber', badge: 'CONTRATO MERCENÁRIO' },
  icarus: { label: 'ICARUS', color: 'purple', badge: 'PEDIDO CIENTÍFICO' },
  world: { label: 'EVENTO DE MUNDO', color: 'red', badge: 'EVENTO DE MUNDO' },
};

export const INFESTATION = [
  { max: 20, label: 'BAIXA', color: 'lime' },
  { max: 40, label: 'CONTROLADA', color: 'lime' },
  { max: 60, label: 'CRESCENTE', color: 'amber' },
  { max: 80, label: 'ALTA', color: 'red' },
  { max: 100, label: 'CRÍTICA', color: 'purple' },
];

export const ACTIVITY: Record<string, string> = { BAIXA: 'lime', MODERADA: 'amber', ELEVADA: 'red', CRÍTICA: 'purple' };

export const BASE_STATUS: Record<string, string> = {
  OPERACIONAL: 'lime', LOTADA: 'amber', 'EM RISCO': 'amber', ISOLADA: 'red', 'SOB ATAQUE': 'red', COMPROMETIDA: 'red',
};

export const FACTION_SITE_STATUS: Record<string, string> = { ALIADA: 'lime', NEUTRA: 'muted', HOSTIL: 'red', BLOQUEADA: 'muted' };

export const EXTRACTION_TYPES: Record<string, { label: string; color: string; who: string }> = {
  governamental: { label: 'EVAC HUNTER', color: 'lime', who: 'Requer autorização Hunter ativa.' },
  publica: { label: 'PÚBLICA', color: 'muted', who: 'Gratuita, previsível e exposta. Todo operador conhece a rota.' },
  aegis: { label: 'TRANSPORTE AEGIS', color: 'cyan', who: 'Blindado ou comboio da infraestrutura Aegis.' },
  frontier: { label: 'ROTA FRONTIER', color: 'amber', who: 'Saída discreta por trilhas, túneis, rios ou pontos clandestinos.' },
  clandestina: { label: 'CLANDESTINA', color: 'purple', who: 'Sem dono declarado. Ninguém garante que ela ainda existe.' },
};

export const EXTRACTION_STATES: Record<string, { label: string; color: string }> = {
  active: { label: 'ATIVA', color: 'lime' },
  conditional: { label: 'CONDICIONAL', color: 'amber' },
  inactive: { label: 'INATIVA', color: 'muted' },
  blocked: { label: 'BLOQUEADA', color: 'muted' },
};

export const SURVIVOR_EXTRACTION_TIP = 'INDISPONÍVEL NO ESTADO SOBREVIVENTE.';

/** Castas antigas (enredo v5) -> criatura canônica da v6. */
export const LEGACY_CASTES: Record<string, string> = {
  runners: 'febril', burrowers: 'rastejante', sporecasters: 'semeador', bulwarks: 'couracado',
  harvesters: 'matriz', listeners: 'vigia', mimics: 'eco', colossi: 'amalgama',
};

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Criatura = Record<string, any>;
let criaturasCache: Record<string, Criatura> | null = null;

/** Catálogo canônico por slug (app/Data/criaturas.php -> src/data/criaturas.json). */
export function creatures(): Record<string, Criatura> {
  if (criaturasCache === null) {
    const cat = dataMock('criaturas');
    criaturasCache = {};
    for (const c of (cat.lista ?? []) as Criatura[]) {
      if (c && c.slug) criaturasCache[String(c.slug)] = c;
    }
  }
  return criaturasCache;
}

export function creature(slug: string | null | undefined): Criatura | null {
  let k = String(slug ?? '').trim().toLowerCase();
  k = LEGACY_CASTES[k] ?? k;
  return creatures()[k] ?? null;
}

export function veyraCastes(): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [k, c] of Object.entries(creatures())) out[k] = String(c.linha ?? '');
  return out;
}

export const CONTAMINATION = [
  { max: 0, label: 'LIMPO', color: 'lime' },
  { max: 25, label: 'EXPOSIÇÃO BAIXA', color: 'lime' },
  { max: 50, label: 'EXPOSIÇÃO MODERADA', color: 'amber' },
  { max: 75, label: 'CONTAMINADO', color: 'red' },
  { max: 100, label: 'CRÍTICO', color: 'purple' },
];

export const WORLD_EVENTS: Record<string, { label: string; logistic: boolean }> = {
  convoy: { label: 'COMBOIO', logistic: true },
  supply_drop: { label: 'QUEDA DE SUPRIMENTOS', logistic: true },
  resupply: { label: 'REABASTECIMENTO', logistic: true },
  abandoned_cargo: { label: 'CARGA ABANDONADA', logistic: true },
  faction_move: { label: 'MOVIMENTAÇÃO DE FACÇÃO', logistic: false },
  veyra_bloom: { label: 'NOVA COLÔNIA VEYRA', logistic: false },
  storm: { label: 'TEMPESTADE', logistic: false },
  bio_regrowth: { label: 'REBROTA BIOLÓGICA', logistic: false },
  surge: { label: 'SURTO', logistic: false },
  migration: { label: 'MIGRAÇÃO', logistic: false },
  nest_found: { label: 'NINHO DESCOBERTO', logistic: false },
  base_attack: { label: 'BASE SOB ATAQUE', logistic: false },
  distress: { label: 'SINAL DE SOCORRO', logistic: false },
};

export const HUMAN_SIGNALS: Record<string, string> = {
  shots: 'TIROS DETECTADOS',
  transmission: 'TRANSMISSÃO INTERCEPTADA',
  movement: 'MOVIMENTO HUMANO',
  combat: 'COMBATE ATIVO',
  extraction: 'SINAL DE EXTRAÇÃO',
};

export function rankIndex(rank: string): number {
  return RANKS.indexOf(rank.trim().toUpperCase());
}

export function role(any: string | null | undefined): string | null {
  if (any === null || any === undefined || any.trim() === '') return null;
  const up = any.trim().toUpperCase();
  for (const [key, r] of Object.entries(ROLES)) {
    if (up === key || r.legacy.includes(up)) return key;
  }
  return null;
}

export function repTier(rep: number): string {
  let label = REP_TIERS[0]!.label;
  for (const t of REP_TIERS) if (rep >= t.min) label = t.label;
  return label;
}

export function tierMeets(have: string, need: string): boolean {
  const order = REP_TIERS.map((t) => t.label);
  const h = order.indexOf(have.toUpperCase());
  const n = order.indexOf(need.toUpperCase());
  return h >= 0 && n >= 0 && h >= n;
}

export function infestation(pct: number): { label: string; color: string } {
  const p = Math.max(0, Math.min(100, pct));
  for (const s of INFESTATION) if (p <= s.max) return { label: s.label, color: s.color };
  return { label: 'CRÍTICA', color: 'purple' };
}

export function contamination(v: number): { label: string; color: string } {
  const p = Math.max(0, Math.min(100, v));
  for (const s of CONTAMINATION) if (p <= s.max) return { label: s.label, color: s.color };
  return { label: 'CRÍTICO', color: 'purple' };
}

export function activityColor(level: string): string {
  return ACTIVITY[level.trim().toUpperCase()] ?? 'muted';
}

export function faction(slug: string | null | undefined): Faction | null {
  return FACTIONS[String(slug ?? '').toLowerCase()] ?? null;
}

export function origin(slug: string | null | undefined): { label: string; color: string; badge: string } {
  return ORIGINS[String(slug ?? '').toLowerCase()] ?? ORIGINS.hunter_command!;
}

// Safe Zone (a tela ainda não foi portada; as constantes ficam para quando for).
export const SAFE_SHIFT_MIN = 45;
export const SAFE_ZONE_GOV = 'gov';
export const SAFE_ZONE_FREE = 'free';
export const SAFE_ZONE_STATUS: Record<string, string> = {
  ABERTA: 'lime', MOVIMENTADA: 'amber', 'EM ALERTA': 'amber', FECHADA: 'red', TOLERADA: 'muted',
};
export const SAFE_OCCUPANCY: Record<string, string> = { BAIXA: 'lime', MODERADA: 'lime', ALTA: 'amber', LOTADA: 'red' };

export function occupancyColor(level: string): string {
  return SAFE_OCCUPANCY[level.trim().toUpperCase()] ?? 'muted';
}
