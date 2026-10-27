/**
 * Tela PERFIL — o App\Controllers\ProfileController do PHP. O layout (títulos, labels, células
 * estáticas, insígnias) vem de app/Data/perfil.php (-> src/data/perfil.json); os blocos
 * dinâmicos vêm do banco, com as mesmas chaves que a view espera:
 *   - estatísticas reais (K/D, extrações, partidas, abates)   -> player_matches (PlayerRepo.match_stats)
 *   - conquistas recentes                                     -> player_achievements concluídas × achievements
 *   - armas favoritas                                         -> itens de arma do inventário × catálogo
 *   - nível/xp/moedas/classe/facção/patente/retrato           -> PlayerRepo
 *   - atividade recente                                       -> player_matches + player_achievements + player_purchases
 */
import * as DB from '../core/database';
import * as GR from '../core/game-rules';
import { dataMock, num } from '../core/helpers';
import type { AppRequest } from '../core/request';
import type { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import { governmentBases } from '../core/world-map';
import * as OperationalRepo from '../repo/operational-repo';
import { perfil } from '../views/pages/perfil';
import { Controller } from './controller';

/** Rótulo do tipo de arma a partir do subtype do catálogo. */
const WEAPON_TYPE: Record<string, string> = {
  assault_rifle: 'FUZIL DE ASSALTO',
  carbine: 'CARABINA',
  smg: 'SUBMETRALHADORA',
  sniper: 'FUZIL DE PRECISÃO',
  marksman: 'ATIRADOR DESIGNADO',
  shotgun: 'ESPINGARDA',
  lmg: 'METRALHADORA',
  pistol: 'PISTOLA',
};

/** "AAAA-MM-DD HH:MM:SS" -> partes; data inválida cai em "agora" (o `strtotime() ?: time()` do PHP). */
function partes(dt: string): { d: string; m: string; y: string; h: string; i: string } {
  const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(dt);
  if (m) return { y: m[1]!, m: m[2]!, d: m[3]!, h: m[4] ?? '00', i: m[5] ?? '00' };
  const n = new Date().toISOString();
  return { y: n.slice(0, 4), m: n.slice(5, 7), d: n.slice(8, 10), h: n.slice(11, 13), i: n.slice(14, 16) };
}

/** date('d/m/Y', strtotime($dt)). */
export function fmtData(dt: string): string {
  const p = partes(dt);
  return `${p.d}/${p.m}/${p.y}`;
}

/** date('d/m/Y H:i', strtotime($dt)). */
export function fmtDataHora(dt: string): string {
  const p = partes(dt);
  return `${p.d}/${p.m}/${p.y} ${p.h}:${p.i}`;
}

/** strtotime() de um DATETIME do D1 (UTC) em ms; NaN se inválido. */
function ts(dt: string): number {
  return Date.parse(dt.replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(dt) ? '' : 'Z'));
}

export class ProfileController extends Controller {
  async index(_req: AppRequest, _p: Params): Promise<AppResponse> {
    const data: Dict = dataMock('perfil'); // layout: títulos, labels, células estáticas
    const player = await this.player(); // banco (PlayerRepo), conciliado por user_id
    const uid = this.userId();

    const ms: Dict = player.match_stats ?? {};
    const matches = Number(ms.matches ?? 0) | 0;
    const kills = Number(ms.kills ?? 0) | 0;
    const extractions = Number(ms.extractions ?? 0) | 0;
    const deaths = Number(ms.deaths ?? 0) | 0;

    // --- cabeçalho / retrato / estado operacional (PRD v3 §21) ----------
    const op = await this.operational();
    data.portrait = String(player.avatar ?? data.portrait ?? 'portraits/op-assault.jpg');
    const faction = OperationalRepo.organization(op);
    const rank = String(op.rank ?? GR.RANKS[0]);
    const role = String(op.role ?? player.class ?? 'ASSAULT');
    data.faction_rank = rank;
    data.organization = faction;
    data.operational = op;
    data.reputation = op.faction !== null ? Number(op.reputation[op.faction] ?? 0) | 0 : 0;
    data.reputation_of = op.faction !== null ? (GR.faction(op.faction)?.label ?? '') : '';
    data.contract_block = this.contractBlock(op);
    data.stats_tabs = this.statsTabs(op, matches, kills, extractions, deaths);

    const joined = uid > 0 ? String((await DB.value('SELECT created_at FROM users WHERE id = :u', { u: uid })) ?? '') : '';
    const joinedFmt = joined !== '' ? fmtData(joined) : String(data.joined ?? '');

    // --- cartão do operador --------------------------------------------
    const roleKey = GR.role(role) ?? 'ASSAULT';
    const R = GR.ROLES[roleKey]!;
    data.operator ??= {};
    data.operator.class = R.label;
    data.operator.class_icon = R.icon;
    const estado =
      op.state === GR.STATE_MERCENARY
        ? { v: 'MERCENÁRIO', c: GR.faction(op.faction)?.color ?? 'cyan' }
        : op.state === GR.STATE_SURVIVOR
          ? { v: 'SOBREVIVENTE', c: 'white' }
          : { v: 'HUNTER', c: 'lime' };
    let basePref: Dict | null = null;
    for (const b of await governmentBases()) if (b.slug === (op.base_pref ?? '')) basePref = b;
    data.operator.meta = [
      { label: 'STATUS', value: estado.v, icon: 'shield_person', color: estado.c },
      { label: 'ORGANIZAÇÃO', value: faction, icon: 'handshake', color: op.state === GR.STATE_SURVIVOR ? 'white' : 'lime' },
      { label: 'PATENTE', value: rank, icon: 'military_tech', color: 'gold' },
      { label: 'FUNÇÃO', value: R.label, icon: R.ms, color: '' },
      { label: 'BASE PREFERENCIAL', value: basePref !== null ? 'BASE ' + String(basePref.short) : '—', icon: 'home_work', color: '' },
      { label: 'TEMPO DE JOGO', value: String(data.playtime ?? ''), icon: 'schedule', color: '' },
      { label: 'EM CAMPO DESDE', value: joinedFmt, icon: 'flag', color: '' },
    ];

    // --- estatísticas (4 métricas reais de player_matches) --------------
    const kd = deaths > 0 ? kills / deaths : kills;
    const successPct = matches > 0 ? Math.round((extractions / matches) * 100) : 0;
    const kpm = matches > 0 ? kills / matches : 0;
    const metricMap: Record<string, { n: number; sub: string }> = {
      kd: { n: Math.round(kd * 10) / 10, sub: num(kills) + ' ABATES / ' + num(deaths) + ' MORTES' },
      extractions: { n: extractions, sub: successPct + '% DE SUCESSO' },
      matches: { n: matches, sub: num(kills) + ' ABATES NO TOTAL' },
      wins: { n: extractions, sub: successPct + '% DAS PARTIDAS' },
      kills: { n: kills, sub: num(kpm, 1) + ' POR PARTIDA' },
    };
    data.stats_meta = 'TEMPORADA ' + String(Number(player.season_level ?? 0) | 0).padStart(2, '0');
    data.stats = ((data.stats ?? []) as Dict[]).map((s) => {
      const key = String(s.metric ?? '');
      const m = key !== '' ? metricMap[key] : undefined;
      if (m) {
        s.n = m.n;
        s.sub = m.sub;
      }
      delete s.metric;
      return s;
    });

    if (uid > 0) {
      const [achRows, wRows] = await Promise.all([
        // --- conquistas recentes (player_achievements concluídas) ----------
        DB.all(
          `SELECT a.slug, a.name, a.description, a.icon, pa.completed_at,
                  (SELECT r.amount FROM achievement_rewards r
                    WHERE r.achievement_id = a.id AND r.kind = 'xp' LIMIT 1) AS xp
             FROM player_achievements pa
             JOIN achievements a ON a.id = pa.achievement_id
            WHERE pa.user_id = :u AND pa.completed_at IS NOT NULL
            ORDER BY pa.completed_at DESC, a.sort_order
            LIMIT 3`,
          { u: uid },
        ),
        // --- armas favoritas (itens de arma do inventário) -----------------
        DB.all(
          `SELECT it.slug, it.name, it.subtype, it.ammo_caliber, it.image
             FROM player_items pi
             JOIN items it ON it.id = pi.item_id
            WHERE pi.user_id = :u AND it.category = 'weapon'
            ORDER BY pi.id
            LIMIT 2`,
          { u: uid },
        ),
      ]);
      if (achRows.length) {
        const palette = ['lime', 'amber', 'gold'];
        data.achievements = achRows.map((r, i) => ({
          slug: String(r.slug),
          label: String(r.name).toUpperCase(),
          desc: String(r.description ?? ''),
          icon: String(r.icon ?? 'star'),
          color: palette[i % palette.length],
          date: fmtData(String(r.completed_at)),
          xp: Number(r.xp ?? 0) | 0,
        }));
      }
      if (wRows.length) {
        const n = wRows.length;
        const weights = n === 2 ? [0.62, 0.38] : Array<number>(n).fill(1 / n);
        const accDef = [43, 38, 35];
        data.weapons = wRows.map((w, i) => {
          const wKills = Math.round(kills * (weights[i] ?? 1 / n));
          const usage = kills > 0 ? Math.round((wKills / kills) * 100) : 0;
          const sub = String(w.subtype ?? '');
          return {
            slug: String(w.slug),
            name: String(w.name),
            type: WEAPON_TYPE[sub] ?? (sub !== '' ? sub.toUpperCase() : 'ARMA'),
            img: String(w.image ?? 'items/ak103.png'),
            caliber: String(w.ammo_caliber ?? ''),
            kills: wKills,
            accuracy: accDef[i] ?? 30,
            usage,
            main: i === 0,
          };
        });
      }
    }

    // --- funções Hunter (PRD v3 §21.5): as QUATRO oficiais, sempre nesta ordem; a do jogador
    //     em destaque. Nível/maestria por função continuam vindo do cromo até existir backend.
    const mock: Record<string, Dict> = {};
    for (const c of (data.classes ?? []) as Dict[]) mock[GR.role(c.label ?? null) ?? ''] = c;
    data.classes = Object.entries(GR.ROLES).map(([key, r]) => {
      const m = mock[key] ?? {};
      return {
        slug: key.toLowerCase(),
        label: r.label,
        icon: r.icon,
        desc: r.desc,
        level: Number(m.level ?? 0) | 0,
        pct: Number(m.pct ?? 0) | 0,
        main: key === roleKey,
      };
    });

    // --- atividade recente (matches + achievements + purchases) --------
    const recent = await this.recentActivity(uid);
    if (recent.length) data.recent = recent;

    const page = this.page('perfil', 'Perfil', { nav: 'perfil' });
    return this.render(perfil, page, data);
  }

  /**
   * Bloco CONTRATO MERCENÁRIO + reputação das duas facções (PRD v3 §21.3–21.4).
   * A facção sem contrato mostra estado coerente: SEM CONTRATO ou BLOQUEADO hh:mm:ss.
   */
  private contractBlock(op: OperationalRepo.Operational): Dict {
    const ativa = op.faction ?? null;
    const agora = Date.now();
    const facs = Object.entries(GR.FACTIONS).map(([slug, f]) => {
      const rep = Number(op.reputation[slug] ?? 0) | 0;
      const locked = op.locked[slug] ?? null;
      const isLock = locked !== null && ts(String(locked)) > agora;
      return {
        slug,
        label: f.label,
        color: f.color,
        rep,
        tier: ativa === slug || rep > 0 ? GR.repTier(rep) : 'SEM CONTRATO',
        active: ativa === slug,
        locked: isLock,
        locked_until: isLock ? String(locked) : null,
        state: ativa === slug ? 'CONTRATO ATIVO' : isLock ? 'BLOQUEADO' : 'SEM CONTRATO',
      };
    });
    return {
      title: 'CONTRATO MERCENÁRIO',
      none: 'NENHUM CONTRATO ATIVO',
      // `faction() + [...]` do PHP: as chaves da facção vencem
      active: ativa !== null
        ? { rep: Number(op.reputation[ativa] ?? 0) | 0, tier: String(op.tiers[ativa] ?? ''), ...GR.faction(ativa) }
        : null,
      survivor: op.state === GR.STATE_SURVIVOR,
      factions: facs,
      cta: { benefits: 'VER BENEFÍCIOS', manage: 'GERENCIAR CONTRATO', href: 'faccoes' },
    };
  }

  /**
   * Estatísticas por estado (PRD v3 §21.6): GERAL · HUNTER · MERCENÁRIO · SOBREVIVENTE · PVP ·
   * VEYRA. O que player_matches sabe medir vem real; o resto é marcado `dep` (depende de
   * backend) e aparece como "—", nunca como número inventado.
   */
  private statsTabs(op: OperationalRepo.Operational, matches: number, kills: number, extractions: number, deaths: number): Dict[] {
    const dep = (label: string, icon = 'pending'): Dict => ({ label, n: null, sub: 'DEPENDE DO BACKEND', icon, dep: true });
    const pct = matches > 0 ? Math.round((extractions / matches) * 100) : 0;
    return [
      { slug: 'hunter', label: 'HUNTER', items: [
        { label: 'OPERAÇÕES CONCLUÍDAS', n: matches, sub: 'MUNDO PERSISTENTE', icon: 'flag', color: 'lime' },
        { label: 'EXTRAÇÕES', n: extractions, sub: pct + '% DE SUCESSO', icon: 'logout', color: 'lime' },
        dep('ORDENS CUMPRIDAS', 'assignment_turned_in'),
        dep('ALIADOS REVIVIDOS', 'medical_services'),
        dep('INFRAÇÕES', 'gavel'),
      ] },
      { slug: 'mercenario', label: 'MERCENÁRIO', items: [
        { label: 'REP AEGIS', n: Number(op.reputation.aegis ?? 0) | 0, sub: String(op.tiers.aegis ?? 'SEM CONTRATO'), icon: 'shield', color: 'cyan' },
        { label: 'REP FRONTIER', n: Number(op.reputation.frontier ?? 0) | 0, sub: String(op.tiers.frontier ?? 'SEM CONTRATO'), icon: 'explore', color: 'amber' },
        dep('CONTRATOS AEGIS', 'handshake'),
        dep('CONTRATOS FRONTIER', 'handshake'),
        dep('MISSÕES DE FACÇÃO', 'assignment'),
      ] },
      { slug: 'sobrevivente', label: 'SOBREVIVENTE', items: [
        dep('ENTRADAS NO ESTADO', 'person_off'),
        dep('MAIOR TEMPO SOBREVIVIDO', 'timer'),
        dep('RECURSOS ARMAZENADOS EM CAMPO', 'inventory_2'),
      ] },
      { slug: 'pvp', label: 'PVP', items: [
        { label: 'OPERADORES ELIMINADOS', n: kills, sub: 'ENCONTROS HOSTIS', icon: 'swords', color: 'red' },
        { label: 'MORTES PARA OPERADORES', n: deaths, sub: 'MUNDO PERSISTENTE', icon: 'skull', color: '' },
        dep('ENCONTROS HOSTIS', 'radar'),
      ] },
      { slug: 'veyra', label: 'VEYRA', items: [
        dep('INFECTADOS ABATIDOS', 'pest_control'),
        dep('MATRIZES DESTRUÍDAS', 'local_fire_department'),
        dep('AMÁLGAMAS ELIMINADOS', 'skull'),
      ] },
    ];
  }

  /** Feed unificado: partidas, conquistas concluídas e compras do jogador, ordenados por data. */
  private async recentActivity(uid: number): Promise<Dict[]> {
    if (uid <= 0) return [];
    const events: Dict[] = [];
    const [partidas, conquistas, compras] = await Promise.all([
      DB.all('SELECT result, kills, played_at FROM player_matches WHERE user_id = :u ORDER BY played_at DESC LIMIT 5', { u: uid }),
      DB.all(
        `SELECT a.name, pa.completed_at FROM player_achievements pa
           JOIN achievements a ON a.id = pa.achievement_id
          WHERE pa.user_id = :u AND pa.completed_at IS NOT NULL
          ORDER BY pa.completed_at DESC, a.sort_order LIMIT 3`,
        { u: uid },
      ),
      DB.all(
        `SELECT it.name, pp.bought_at FROM player_purchases pp
           JOIN items it ON it.id = pp.item_id
          WHERE pp.user_id = :u ORDER BY pp.bought_at DESC LIMIT 3`,
        { u: uid },
      ),
    ]);

    for (const m of partidas) {
      const result = String(m.result);
      const dt = String(m.played_at);
      if (result === 'extraido') {
        events.push({ dt, type: 'extraction', icon: 'flag', color: 'lime', text: 'Extração bem-sucedida (' + (Number(m.kills) | 0) + ' abates)' });
      } else if (result === 'abatido') {
        events.push({ dt, type: 'death', icon: 'skull', color: 'red', text: 'Abatido em combate' });
      } else {
        events.push({ dt, type: 'match', icon: 'sports_esports', color: '', text: 'Fuga registrada em partida' });
      }
    }
    for (const a of conquistas) {
      events.push({ dt: String(a.completed_at), type: 'achievement', icon: 'military_tech', color: 'gold',
        text: 'Conquista desbloqueada: ' + String(a.name).toUpperCase() });
    }
    for (const p of compras) {
      events.push({ dt: String(p.bought_at), type: 'purchase', icon: 'shopping_cart', color: 'cyan',
        text: 'Comprou ' + String(p.name).toUpperCase() });
    }

    // strcmp decrescente (sort do JS é estável, como o usort do PHP 8)
    events.sort((x, y) => (x.dt < y.dt ? 1 : x.dt > y.dt ? -1 : 0));
    return events.slice(0, 5).map((ev) => ({
      type: ev.type,
      icon: ev.icon,
      text: ev.text,
      time: fmtDataHora(String(ev.dt)),
      color: ev.color,
    }));
  }
}
