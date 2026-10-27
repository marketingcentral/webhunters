/**
 * Tela DESAFIOS (/desafios[/{aba}]) e POST /desafios/resgatar — o
 * App\Controllers\ChallengesController do PHP.
 *
 * Tudo vem do banco (ChallengeRepo); src/data/desafios.json (app/Data/desafios.php) só
 * fornece RÓTULOS de layout — e, com o banco fora do ar, o mesmo formato offline.
 */
import { dataMock, flash, num, url } from '../core/helpers';
import type { AppRequest } from '../core/request';
import type { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import * as ChallengeRepo from '../repo/challenge-repo';
import { desafios } from '../views/pages/desafios';
import { Controller } from './controller';

/** Aba -> rótulo, rótulo do prazo e rota que o jogador usa para cumprir os objetivos. */
const TABS: Record<string, { label: string; reset_label: string; href: string }> = {
  diarios: { label: 'DIÁRIOS', reset_label: 'REINICIA EM', href: 'jogar' },
  semanais: { label: 'SEMANAIS', reset_label: 'REINICIA EM', href: 'jogar' },
  temporada: { label: 'TEMPORADA', reset_label: 'TERMINA EM', href: 'missoes' },
};

const int = (v: unknown): number => Math.trunc(Number(v) || 0);

type Offline = [Dict | null, ChallengeRepo.Quadro, Record<string, Dict>, Record<string, boolean>, Dict, Dict[], number];

export class ChallengesController extends Controller {
  async index(_req: AppRequest, params: Params): Promise<AppResponse> {
    const labels: Dict = dataMock('desafios'); // só rótulos/CTAs de layout
    const active = this.pick(params.aba, Object.keys(TABS), 'diarios')!;
    const uid = this.userId();

    const data: Dict = {
      title: String(labels.title ?? 'DESAFIOS'),
      intro: String(labels.intro ?? ''),
      board_action: String(labels.board_action ?? 'VER CONQUISTAS'),
      bonus_cta: String(labels.bonus_cta ?? 'RESGATAR'),
      active_tab: active,
      tabs: [],
      groups: {},
      bonus: {},
      featured: {},
      ring: {},
      pass: {},
    };

    // Banco é a fonte. Fora do ar, a HUD continua navegável com os rótulos estáticos.
    let season: Dict | null;
    let board: ChallengeRepo.Quadro;
    let bonuses: Record<string, Dict>;
    let claimed: Record<string, boolean>;
    let ps: Dict;
    let tierRows: Dict[];
    let eventSecs: number;
    try {
      season = await ChallengeRepo.season();
      [board, bonuses, claimed, eventSecs] = await Promise.all([
        ChallengeRepo.board(uid),
        ChallengeRepo.bonuses(),
        ChallengeRepo.claimed(uid),
        ChallengeRepo.eventSeconds(),
      ]);
      [ps, tierRows] = await Promise.all([
        ChallengeRepo.playerSeason(uid, int(season?.id ?? 0)),
        ChallengeRepo.tiers(int(season?.id ?? 0)),
      ]);
    } catch (e) {
      console.error('[extracthunters] desafios:', e);
      [season, board, bonuses, claimed, ps, tierRows, eventSecs] = this.offline(labels);
    }
    data.tag = String(season?.tag ?? labels.tag ?? '');

    for (const [key, meta] of Object.entries(TABS)) {
      const g = board[key] ?? { items: [], done: 0, total: 0, seconds: 0 };
      data.tabs.push({
        key,
        label: meta.label,
        count: g.done + '/' + g.total,
        reset_label: meta.reset_label,
        seconds: g.seconds,
      });
      data.groups[key] = { items: g.items, href: meta.href };

      const b = bonuses[key] ?? null;
      if (b !== null) {
        const ready = g.total > 0 && g.done >= g.total;
        data.bonus[key] = {
          label: String(b.label),
          title: String(b.title),
          desc: String(b.description ?? ''),
          image: String(b.image ?? ''),
          cur: g.done,
          max: Math.max(1, g.total),
          xp: int(b.xp),
          credits: int(b.credits),
          ready: ready && !claimed[key],
          claimed: Boolean(claimed[key]),
        };
      }
    }

    // Relógio do cabeçalho: segue a aba ativa.
    data.reset_label = TABS[active]!.reset_label;
    data.reset_secs = int(board[active]?.seconds ?? 0);

    // Destaque: evento da temporada, com progresso real dos desafios de temporada.
    const ev = board.temporada ?? { done: 0, total: 0 };
    const lf: Dict = labels.featured ?? {};
    data.featured = {
      tag: String(season?.event_tag ?? ''),
      title: String(season?.event_title ?? ''),
      desc: String(season?.event_desc ?? ''),
      image: String(season?.event_image ?? ''),
      ends_label: String(lf.ends_label ?? 'TERMINA EM'),
      ends_secs: eventSecs,
      meta_label: String(lf.meta_label ?? 'PROGRESSO DO EVENTO'),
      cur: int(ev.done),
      max: Math.max(1, int(ev.total)),
      href: String(lf.href ?? 'missoes'),
      cta: String(lf.cta ?? 'VER MISSÕES DO EVENTO'),
    };

    // Anel: derivado do MESMO quadro que a tela imprime.
    const [ringDone, ringTotal] = ChallengeRepo.boardTotals(board);
    const lr: Dict = labels.ring ?? {};
    data.ring = {
      title: String(lr.title ?? 'PROGRESSO SEMANAL'),
      caption: String(lr.caption ?? 'DESAFIOS CONCLUÍDOS'),
      done: ringDone,
      total: Math.max(1, ringTotal),
      reset_label: 'REINICIA EM',
      reset_secs: int(board.semanais?.seconds ?? 0),
      stats: [
        { label: 'XP DA SEMANA', value: num(int(ps.week_xp)), icon: 'bolt', color: 'lime' },
        { label: 'SEQUÊNCIA', value: int(ps.streak_days) + ' DIAS', icon: 'local_fire_department', color: 'amber' },
      ],
    };

    // Passe: o destaque "next" é o PRIMEIRO tier ainda não conquistado.
    const tiers: Dict[] = [];
    const level = int(ps.pass_level);
    let hasNext = false;
    for (const t of tierRows) {
      const owned = int(t.level) <= level;
      let state = 'locked';
      if (owned) state = 'owned';
      else if (!hasNext) {
        state = 'next';
        hasNext = true;
      }
      tiers.push({ level: int(t.level), name: String(t.name), image: String(t.image ?? ''), state });
    }
    const lp: Dict = labels.pass ?? {};
    data.pass = {
      title: String(lp.title ?? 'PASSE DE TEMPORADA'),
      name: String(season?.pass_name ?? ''),
      season: String(season?.tag ?? ''),
      image: String(season?.pass_image ?? ''),
      level,
      xp: int(ps.pass_xp),
      xp_next: Math.max(1, int(season?.xp_per_level ?? 10000)),
      ends_label: String(lp.ends_label ?? 'TERMINA EM'),
      ends_secs: int(board.temporada?.seconds ?? 0),
      next_label: String(lp.next_label ?? 'PRÓXIMAS RECOMPENSAS'),
      tiers,
      cta: String(lp.cta ?? 'VER PASSE COMPLETO'),
      href: String(lp.href ?? 'loja'),
    };

    const page = this.page('desafios', 'Desafios', { back: { label: 'VOLTAR', href: url('jogar') } });
    return this.render(desafios, page, data);
  }

  /** Banco fora do ar: o MESMO formato a partir dos rótulos estáticos. */
  private offline(labels: Dict): Offline {
    const lp: Dict = labels.pass ?? {};
    const lf: Dict = labels.featured ?? {};
    const season: Dict = {
      id: 0, tag: String(labels.tag ?? ''),
      pass_name: String(lp.name ?? ''),
      pass_image: String(lp.image ?? ''),
      xp_per_level: int(lp.xp_next ?? 10000),
      event_tag: String(lf.tag ?? ''),
      event_title: String(lf.title ?? ''),
      event_desc: String(lf.desc ?? ''),
      event_image: String(lf.image ?? ''),
    };

    const lists: Record<string, string> = { diarios: 'daily', semanais: 'weekly', temporada: 'event' };
    const secs: Record<string, number> = { diarios: 12 * 3600, semanais: 4 * 86400, temporada: 18 * 86400 };
    const board: ChallengeRepo.Quadro = {};
    for (const [scope, key] of Object.entries(lists)) {
      const items: Dict[] = [];
      let done = 0;
      for (const c of (labels[key] ?? []) as Dict[]) {
        const max = Math.max(1, int(c.max ?? 1));
        const cur = Math.min(max, Math.max(0, int(c.cur ?? 0)));
        const isDone = cur >= max;
        done += isDone ? 1 : 0;
        items.push({
          slug: String(c.slug ?? ''), label: String(c.label ?? ''),
          desc: String(c.desc ?? ''), icon: String(c.icon ?? 'flag'),
          cur, max, pct: Math.round((cur / max) * 100),
          xp: int(c.xp ?? 0), credits: int(c.credits ?? 0),
          hot: Boolean(c.hot), done: isDone,
        });
      }
      board[scope] = { items, done, total: items.length, seconds: secs[scope]! };
    }

    const bonuses: Record<string, Dict> = {};
    for (const [scope, b] of Object.entries((labels.bonus ?? {}) as Record<string, Dict>)) {
      bonuses[scope] = {
        scope, label: String(b.label ?? ''), title: String(b.title ?? ''),
        description: String(b.desc ?? ''), image: String(b.image ?? ''),
        xp: int(b.xp ?? 0), credits: int(b.credits ?? 0),
      };
    }

    const ps: Dict = {
      pass_level: int(lp.level ?? 0),
      pass_xp: int(lp.xp ?? 0),
      week_done: int(labels.ring?.done ?? 0),
      week_goal: int(labels.ring?.total ?? 12),
      week_xp: 0,
      streak_days: 0,
    };
    const tiers = ((lp.tiers ?? []) as Dict[]).map((t) => ({ level: int(t.level ?? 0), name: String(t.name ?? ''), image: String(t.image ?? '') }));
    return [season, board, bonuses, {}, ps, tiers, 3 * 86400];
  }

  /** Resgata o bônus da aba (POST /desafios/resgatar). */
  async claim(req: AppRequest, _p: Params): Promise<AppResponse> {
    this.csrf(req);
    const scope = req.input('escopo', '') ?? '';
    if (!ChallengeRepo.SCOPES.includes(scope)) {
      flash('error', 'Aba de desafios desconhecida.');
      return this.redirect('desafios');
    }
    const result = await ChallengeRepo.claimBonus(this.userId(), scope);
    if (result.ok === true) {
      flash('ok', 'Bônus resgatado: +' + num(result.xp ?? 0) + ' XP e +' + num(result.credits ?? 0) + ' créditos.');
    } else {
      flash('error', result.error ?? 'Não foi possível resgatar o bônus.');
    }
    // Volta para a aba de onde o jogador resgatou.
    return this.redirect('desafios/' + scope);
  }
}
