/**
 * Tela /inicio — o App\Controllers\HomeController do PHP.
 */
import * as DB from '../core/database';
import * as GR from '../core/game-rules';
import { dataMock, num } from '../core/helpers';
import type { AppRequest } from '../core/request';
import type { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import * as Catalog from '../repo/catalog-repo';
import { inicio } from '../views/pages/inicio';
import { Controller } from './controller';

const RARIDADE: Record<string, string> = { comum: 'COMUM', incomum: 'INCOMUM', raro: 'RARO', epico: 'ÉPICO', lendario: 'LENDÁRIO' };

/** "Y-m-d H:i:s" (UTC no D1) -> dd/mm/YYYY */
function dataBr(s: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s);
  return m ? `${m[3]}/${m[2]}/${m[1]}` : '';
}

/** DATETIME -> ISO-8601 (o inicio.js faz Date.parse). As datas do D1 são UTC. */
function iso(s: string): string {
  if (!s) return '';
  const t = Date.parse(s.replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(s) ? '' : 'Z'));
  return Number.isNaN(t) ? '' : new Date(t).toISOString().replace(/\.\d{3}Z$/, '+00:00');
}

export class HomeController extends Controller {
  async index(_req: AppRequest, _p: Params): Promise<AppResponse> {
    const data = dataMock('inicio');
    // O carrossel lê as MESMAS notícias publicadas que /noticias.
    data.news_pages = await this.newsPages(data.news_pages ?? []);
    data.news = (data.news_pages[0] ?? []).slice(0, 3);

    const uid = this.userId();
    data.season = await this.season(data.season ?? {}, uid);
    data.contract = await this.contract(data.contract ?? {}, uid);
    data.status_op = await this.statusOperacional(data.status ?? {});

    const page = this.page('inicio', 'Início', {
      bg: 'bg/home-hero-wide.jpg',
      bg_pos: 'center',
      three: { atmosphere: true, hero: false },
    });
    return this.render(inicio, page, data);
  }

  private async newsPages(fallback: Dict[][]): Promise<Dict[][]> {
    let rows: Dict[];
    try {
      rows = await Catalog.news();
    } catch {
      return fallback;
    }
    if (!rows.length) return fallback;
    const itens = rows.map((n) => {
      const slug = String(n.slug ?? '');
      return {
        title: String(n.title ?? ''),
        summary: String(n.excerpt ?? ''),
        date: dataBr(String(n.published ?? '')),
        image: String(n.image ?? 'news/news-atualizacao.jpg'),
        route: slug !== '' ? 'noticias/' + slug : 'noticias',
      };
    });
    const paginas: Dict[][] = [];
    for (let i = 0; i < itens.length; i += 2) paginas.push(itens.slice(i, i + 2));
    return paginas.slice(0, 4);
  }

  private async season(chrome: Dict, uid: number): Promise<Dict> {
    const season = await DB.one('SELECT * FROM seasons WHERE is_current = 1 ORDER BY number DESC LIMIT 1');
    if (season === null) return chrome;
    const ps = await DB.one('SELECT * FROM player_season WHERE user_id = :u AND season_id = :s', { u: uid, s: Number(season.id) });
    const nivel = Number(ps?.pass_level ?? 0);
    const porNivel = Math.max(1, Number(season.xp_per_level ?? 10000));
    return {
      title: String(chrome.title ?? 'PASSE DE TEMPORADA'),
      subtitle: String(season.tag ?? ''),
      href: String(chrome.href ?? 'loja'),
      level: nivel,
      xp: Number(ps?.pass_xp ?? 0),
      xp_next: porNivel,
      image: String(season.pass_image ?? chrome.image ?? 'ui/season-pass.jpg'),
      ends_at: iso(String(season.ends_at ?? '')),
      rewards: await this.seasonRewards(Number(season.id), nivel, chrome.rewards ?? []),
    };
  }

  private async seasonRewards(seasonId: number, nivel: number, fallback: Dict[]): Promise<Dict[]> {
    const rows = await DB.all(
      `SELECT st.level, st.name, st.image, COALESCE(st.rarity, it.rarity) AS rarity
         FROM season_tiers st
         LEFT JOIN items it ON it.id = st.item_id
        WHERE st.season_id = :s
        ORDER BY (st.level <= :n), st.level, st.sort_order
        LIMIT 3`,
      { s: seasonId, n: nivel },
    );
    if (!rows.length) return Object.values(fallback);
    return rows.map((r) => ({
      level: Number(r.level),
      name: String(r.name),
      tier: RARIDADE[String(r.rarity ?? '')] ?? String(r.rarity ?? '').toUpperCase(),
      image: String(r.image ?? 'ui/season-pass.jpg'),
      pos: '50% 12%',
    }));
  }

  private async contract(chrome: Dict, uid: number): Promise<Dict> {
    const m = await DB.one(
      `SELECT m.id, m.name, m.objective, m.target, m.origin, pm.status, pm.progress
         FROM missions m
         JOIN player_missions pm ON pm.mission_id = m.id AND pm.user_id = :u
        WHERE pm.status IN ('active', 'available')
        ORDER BY (pm.status <> 'active'), m.sort_order
        LIMIT 1`,
      { u: uid },
    );
    if (m === null) return chrome;
    return {
      title: String(chrome.title ?? 'CONTRATO ATIVO'),
      action: String(chrome.action ?? 'VER TODOS'),
      href: String(chrome.href ?? 'missoes'),
      reward_label: String(chrome.reward_label ?? 'RECOMPENSA'),
      origin_label: String(chrome.origin_label ?? 'ORIGEM'),
      origin: String(m.origin ?? 'hunter_command'),
      name: String(m.name),
      desc: String(m.objective),
      progress: Number(m.progress),
      total: Math.max(1, Number(m.target)),
      rewards: await this.contractRewards(Number(m.id)),
    };
  }

  private async contractRewards(missionId: number): Promise<Dict[]> {
    const rotulos: Record<string, Dict> = {
      xp: { type: 'xp', label: 'Experiência' },
      cash: { type: 'cash', label: 'Dinheiro' },
      points: { type: 'rep', label: 'Pontos de reputação' },
    };
    const rows = await DB.all('SELECT kind, amount FROM mission_rewards WHERE mission_id = :m ORDER BY id', { m: missionId });
    return rows.filter((r) => rotulos[String(r.kind)]).map((r) => ({ ...rotulos[String(r.kind)], value: Number(r.amount) }));
  }

  private async statusOperacional(chrome: Dict): Promise<Dict> {
    const op = await this.operational();
    const lab: Dict = chrome.labels ?? {};
    const itens: Dict[] = [];
    if (op.state === GR.STATE_SURVIVOR) {
      itens.push({ label: lab.org ?? 'ORGANIZAÇÃO', value: 'NENHUMA', cls: 'white' });
      itens.push({ label: 'EXTRAÇÃO', value: 'INDISPONÍVEL', cls: 'white' });
      if (op.survivor_since) itens.push({ label: 'NO ESTADO HÁ', value: this.duracao(op.survivor_since), cls: 'white', num: true });
    } else if (op.state === GR.STATE_MERCENARY) {
      const f = GR.faction(op.faction) ?? { label: '—', color: 'muted' };
      itens.push({ label: lab.contract ?? 'CONTRATO', value: f.label, cls: f.color });
      itens.push({ label: lab.rep ?? 'REP', value: num(op.reputation[op.faction!] ?? 0), cls: f.color, num: true });
      itens.push({ label: 'FAIXA', value: String(op.tiers[op.faction!] ?? '—'), cls: f.color });
    } else {
      itens.push({ label: lab.org ?? 'ORGANIZAÇÃO', value: 'HUNTER COMMAND', cls: 'lime' });
      itens.push({ label: lab.base ?? 'BASE DE ORIGEM', value: await this.nomeDaBase(op.base_pref), cls: '' });
      itens.push({ label: lab.role ?? 'FUNÇÃO', value: String(op.role ?? 'A DEFINIR'), cls: '' });
      itens.push({ label: lab.rank ?? 'PATENTE', value: String(op.rank), cls: '' });
    }
    return { title: String(chrome.title ?? 'STATUS OPERACIONAL'), href: String(chrome.href ?? 'perfil'), items: itens, op };
  }

  private async nomeDaBase(slug: string | null): Promise<string> {
    const s = slug || 'alpha';
    try {
      const n = await DB.value<string>('SELECT name FROM government_bases WHERE slug = :s', { s });
      if (typeof n === 'string' && n !== '') return n.replace(/^BASE HUNTER /u, '');
    } catch {
      /* tabela ausente */
    }
    return s.toUpperCase();
  }

  private duracao(desde: string): string {
    const t = Date.parse(desde.replace(' ', 'T') + 'Z');
    const s = Math.max(0, Math.floor((Date.now() - (Number.isNaN(t) ? Date.now() : t)) / 1000));
    return s >= 3600
      ? `${Math.floor(s / 3600)}h ${String(Math.floor((s % 3600) / 60)).padStart(2, '0')}min`
      : `${Math.floor(s / 60)} min`;
  }
}
