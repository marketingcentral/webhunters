/**
 * Tela /missoes — o App\Controllers\MissionsController do PHP.
 * Quadro = missions (catálogo) LEFT JOIN player_missions do jogador; textos de layout em
 * src/data/missoes.json (app/Data/missoes.php).
 */
import * as DB from '../core/database';
import * as GR from '../core/game-rules';
import { dataMock, num } from '../core/helpers';
import { paredeSp } from '../core/relogio';
import type { AppRequest } from '../core/request';
import type { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import * as CatalogRepo from '../repo/catalog-repo';
import * as OperationalRepo from '../repo/operational-repo';
import { missoes } from '../views/pages/missoes';
import { Controller } from './controller';

/** Estado do banco (PT ou EN) -> [status da view, rótulo]. */
const STATUS: Record<string, [string, string]> = {
  active: ['active', 'EM ANDAMENTO'],
  ativa: ['active', 'EM ANDAMENTO'],
  available: ['available', 'DISPONÍVEL'],
  disponivel: ['available', 'DISPONÍVEL'],
  done: ['done', 'CONCLUÍDA'],
  concluida: ['done', 'CONCLUÍDA'],
  locked: ['locked', 'BLOQUEADA'],
  bloqueada: ['locked', 'BLOQUEADA'],
};

/** Texto de dificuldade -> nível de pips (1..4). */
const DIFFICULTY: Record<string, number> = { FÁCIL: 1, MÉDIA: 2, DIFÍCIL: 3, EXTREMO: 4 };

/** Tipo de contrato -> rótulo exibido. `emergencia` = evento de mundo (PRD v3 §24). */
const TYPE_LABEL: Record<string, string> = { principal: 'PRINCIPAL', contrato: 'CONTRATO', diaria: 'DIÁRIA', emergencia: 'EMERGÊNCIA' };

/** kind da recompensa -> ícone do sprite (xp tem tratamento próprio na view). */
const REWARD_ICON: Record<string, string> = {
  xp: 'xp', cash: 'coin-cash', points: 'coin-points', credits: 'coin-credit',
  rep_aegis: 'sk-medal', rep_frontier: 'sk-medal', access: 'sk-exit-run', intel: 'sk-network',
};

/** Sufixo curto por kind (o texto do selo). */
const REWARD_SUFFIX: Record<string, string> = { rep_aegis: ' REP AEGIS', rep_frontier: ' REP FRONTIER', access: ' ACESSO', intel: ' INTEL' };

/** Categoria do item -> etiqueta curta da recompensa. */
const ITEM_LABEL: Record<string, string> = {
  weapon: 'FUZIL DE ASSALTO', med: 'MÉDICO', key: 'CHAVE', misc: 'CONTÊINER',
  armor: 'BLINDAGEM', ammo: 'MUNIÇÃO', equipment: 'EQUIPAMENTO', container: 'CONTÊINER',
};

/** Ação por estado -> [rótulo do botão, destino ou null]. */
const CTA: Record<string, [string, string | null]> = {
  active: ['CONTINUAR CONTRATO', 'lobby'],
  available: ['ACEITAR CONTRATO', 'lobby'],
  done: ['RECOMPENSA RESGATADA', null],
  locked: ['REQUISITOS NÃO CUMPRIDOS', null],
};

const int = (v: unknown): number => Math.trunc(Number(v) || 0);

/** updated_at do D1 (UTC, datetime('now')) -> d/m/Y no fuso de SP, como o date() do PHP. */
function dataBr(valor: string): string {
  const ms = Date.parse(valor.trim().replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(valor.trim()) ? '' : 'Z'));
  if (Number.isNaN(ms)) return '';
  const d = paredeSp(ms);
  return `${String(d.getUTCDate()).padStart(2, '0')}/${String(d.getUTCMonth() + 1).padStart(2, '0')}/${d.getUTCFullYear()}`;
}

export class MissionsController extends Controller {
  async index(_req: AppRequest, _p: Params): Promise<AppResponse> {
    const data: Dict = dataMock('missoes'); // textos e rótulos de layout
    data.contract = dataMock('inicio')?.contract ?? null;

    const uid = this.userId();
    const resetSeconds = int(data.reset?.seconds ?? 0);
    const progLabels: Record<string, string> = data.progress_labels ?? {};

    // Estado do jogador por missão (status, progresso, data de conclusão).
    const state = new Map<number, Dict>();
    if (uid > 0) {
      for (const r of await DB.all('SELECT mission_id, status, progress, updated_at FROM player_missions WHERE user_id = :u', { u: uid })) {
        state.set(int(r.mission_id), r);
      }
    }

    const op = await this.operational();
    const items: Dict[] = [];
    for (const m of await CatalogRepo.missions()) {
      items.push(await this.buildCard(m, state.get(int(m.id)) ?? null, progLabels, resetSeconds, op));
    }

    // Missão selecionada: a ativa em andamento; senão a primeira do quadro.
    let selected = String(items[0]?.slug ?? '');
    const ativa = items.find((it) => it.status === 'active');
    if (ativa) selected = String(ativa.slug);
    for (const it of items) it.selected = it.slug === selected;

    // Filtros por ORIGEM (PRD v3 §24.3) + estado do jogador.
    const conta = (pred: (i: Dict) => boolean): number => items.filter(pred).length;
    const counts: Record<string, number> = {
      todas: items.length,
      hunter: conta((i) => i.origin === 'hunter_command'),
      aegis: conta((i) => i.origin === 'aegis'),
      frontier: conta((i) => i.origin === 'frontier'),
      eventos: conta((i) => ['world', 'icarus'].includes(i.origin)),
      diarias: conta((i) => i.type === 'diaria'),
      concluidas: conta((i) => i.status === 'done'),
      ativas: conta((i) => i.status === 'active'),
      disponiveis: conta((i) => i.status === 'available'),
      requer: conta((i) => i.requirement !== null && typeof i.requirement === 'object' && !i.requirement.met),
    };
    for (const f of (data.filters ?? []) as Dict[]) f.count = counts[String(f.slug)] ?? 0;
    data.summary = [
      { label: 'ATIVAS', value: counts.ativas },
      { label: 'DISPONÍVEIS', value: counts.disponiveis },
      { label: 'REQUEREM CONTRATO', value: counts.requer },
    ];
    data.operational = op;
    data.items = items;

    const page = this.page('missoes', 'Missões');
    return this.render(missoes, page, data);
  }

  /** Linha do catálogo + estado do jogador -> chaves da view. */
  private async buildCard(
    m: Dict, st: Dict | null, labels: Record<string, string>, resetSeconds: number, op: OperationalRepo.Operational,
  ): Promise<Dict> {
    const slug = String(m.slug);
    const type = String(m.type ?? 'contrato');
    const raw = st !== null ? String(st.status) : int(m.locked ?? 0) === 1 ? 'locked' : 'available';
    let [status, statusLabel] = STATUS[raw] ?? ['available', 'DISPONÍVEL'];

    const curProgress = st !== null ? int(st.progress) : 0;
    const target = int(m.target ?? 0);

    // Recompensas: moedas/xp no card; item vira reward_item.
    const rewards: Dict[] = [];
    let rewardItem: Dict = {};
    for (const r of (m.rewards ?? []) as Dict[]) {
      const kind = String(r.kind);
      const amount = int(r.amount);
      if (kind === 'item') {
        const itemId = int(r.item_id ?? 0);
        const item = itemId > 0 ? await CatalogRepo.item(itemId) : null;
        if (item !== null) {
          const cat = String(item.category ?? '');
          rewardItem = {
            name: String(item.name).toUpperCase(),
            desc: ITEM_LABEL[cat] ?? cat.toUpperCase(),
            image: String(item.image),
          };
        }
        continue;
      }
      rewards.push({
        type: kind,
        icon: REWARD_ICON[kind] ?? kind,
        value: amount,
        text: kind === 'xp' ? '+' + num(amount) + ' XP' : '+' + num(amount) + (REWARD_SUFFIX[kind] ?? ''),
      });
    }

    let [ctaLabel, ctaHref] = CTA[status] ?? ['ACEITAR CONTRATO', 'lobby'];

    // Origem e requisito de facção (PRD v3 §24.2–24.5).
    const origin = String(m.origin ?? 'hunter_command');
    const reqFac = m.requires_faction !== undefined && m.requires_faction !== null ? String(m.requires_faction).toLowerCase() : null;
    const reqTier = m.requires_tier !== undefined && m.requires_tier !== null ? String(m.requires_tier) : null;
    const reqMet =
      reqFac === null ||
      (reqFac === 'any' && (op.faction ?? null) !== null) ||
      (reqFac !== 'any' && OperationalRepo.meets(op, reqFac, reqTier));
    let requirement: Dict | null = null;
    if (reqFac !== null) {
      const facLabel = GR.faction(reqFac)?.label ?? reqFac.toUpperCase();
      requirement = {
        faction: reqFac === 'any' ? null : reqFac,
        tier: reqTier,
        met: reqMet,
        label: reqFac === 'any'
          ? 'REQUER CONTRATO MERCENÁRIO'
          : reqTier !== null
            ? facLabel + ' — ' + reqTier.toUpperCase()
            : 'REQUER CONTRATO ' + facLabel,
        hint: reqTier !== null ? reqTier.toUpperCase() + ' NECESSÁRIA' : null,
      };
      if (!reqMet && ['available', 'locked'].includes(status)) {
        statusLabel = 'REQUER CONTRATO';
        [ctaLabel, ctaHref] = op.state === GR.STATE_HUNTER
          ? ['ATUAR COMO MERCENÁRIO', 'faccoes']
          : ['VER CONTRATO DA FACÇÃO', 'faccoes'];
      }
    }

    const difficulty = String(m.difficulty ?? '');
    const objective = String(m.objective ?? '');
    const squadMax = m.squad_max ?? GR.SQUAD_MAX;
    const card: Dict = {
      slug,
      level_req: int(m.level_req ?? 1),
      title: String(m.name),
      faction: String(m.giver ?? ''),
      origin,
      origin_label: GR.origin(origin).label,
      requirement,
      type,
      type_label: TYPE_LABEL[type] ?? type.toUpperCase(),
      image: String(m.image ?? ''),
      difficulty,
      difficulty_level: DIFFICULTY[difficulty] ?? 1,
      duration: int(m.duration_min ?? 0) + ' MIN',
      // Mundo persistente: não há "jogadores máx." — há a unidade recomendada (PRD §24.7)
      squad: int(m.squad_min ?? 1) + '–' + int(squadMax),
      players: Math.min(int(m.players_max ?? GR.SQUAD_MAX), GR.SQUAD_MAX),
      human_activity: String(m.human_activity ?? 'PROVÁVEL'),
      region: String(m.region ?? ''),
      status,
      status_label: statusLabel,
      objective,
      objective_lines: splitObjective(objective),
      rewards,
      reward_item: rewardItem,
      cta: ctaLabel,
      cta_href: ctaHref,
    };

    if (status === 'active') {
      card.progress = {
        label: labels[slug] ?? 'PROGRESSO',
        cur: curProgress,
        max: target > 0 ? target : Math.max(curProgress, 1),
      };
    }
    if (status === 'done' && st !== null && st.updated_at) card.completed_at = dataBr(String(st.updated_at));
    if (status === 'locked') card.unlock_hint = 'Alcance o nível ' + int(m.level_req ?? 1) + ' para desbloquear este contrato.';
    if (type === 'diaria' && ['active', 'available'].includes(status)) card.expires_in = resetSeconds;
    return card;
  }
}

/** Quebra o objetivo em linhas por frase (mantém o ritmo de duas linhas do layout). */
function splitObjective(objective: string): string[] {
  const o = objective.trim();
  if (o === '') return [];
  const lines = o.split(/(?<=\.)\s+/).map((s) => s.trim()).filter((s) => s !== '');
  return lines.length ? lines : [o];
}
