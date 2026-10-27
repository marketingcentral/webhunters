/**
 * Tela OPERAÇÃO (/jogar) e a grade de regiões (/mapas) — o App\Controllers\PlayController do PHP.
 *
 * PRD v3 §17: a tela responde às três perguntas — em qual estado estou (hero por estado), para
 * quem trabalho (Hunter Command ou facção) e o que está acontecendo no setor. As "regiões
 * operacionais" são os mapas kind=match (têm o level da Unreal que o botão carrega).
 */
import * as DB from '../core/database';
import * as GR from '../core/game-rules';
import { dataMock, num, url } from '../core/helpers';
import type { AppRequest } from '../core/request';
import type { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import * as WorldMap from '../core/world-map';
import * as ChallengeRepo from '../repo/challenge-repo';
import type { Operational } from '../repo/operational-repo';
import { jogar } from '../views/pages/jogar';
import { mapas } from '../views/pages/mapas';
import { Controller } from './controller';

const lista = (v: unknown): Dict[] => (Array.isArray(v) ? (v as Dict[]) : v && typeof v === 'object' ? (Object.values(v) as Dict[]) : []);
const pad2 = (n: number): string => String(n).padStart(2, '0');

export class PlayController extends Controller {
  async index(_req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const data = dataMock('jogar');
    const op = await this.operational();

    data.maps = await this.matchMaps();
    // O painel de desafios diários lê o MESMO quadro de /desafios (ChallengeRepo.board).
    const desafios = dataMock('desafios');
    [data.challenges, data.reset_in] = await this.dailyChallenges(lista(desafios.daily), String(desafios.reset_in ?? ''));

    data.modes = this.modes(lista(data.modes), (data.survivor_mode ?? {}) as Dict, op);
    data.hero = await this.hero((data.heroes ?? {}) as Dict, (data.hero_labels ?? {}) as Dict, op, data.maps);
    data.capacity = GR.REGION_CAPACITY;
    data.bases = await WorldMap.governmentBases();
    data.prefs = this.prefs(lista(data.prefs), data.bases, op);
    data.operational = op;

    // Sobrevivente não mobiliza por base nem tem inserção estimada: ele "retorna ao mundo".
    if (op.state === GR.STATE_SURVIVOR) {
      data.cta = String(data.heroes?.survivor?.cta ?? 'RETORNAR AO MUNDO');
      data.eta = '—';
    } else if (op.state === GR.STATE_MERCENARY) {
      data.cta = String(data.heroes?.mercenary?.cta ?? 'ATUAR COMO MERCENÁRIO');
    }

    const page = this.page('jogar', 'Operação', { three: { atmosphere: true } });
    return this.render(jogar, page, data);
  }

  async maps(_req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    // Textos e rótulos de layout ficam no Data; a grade vem do banco.
    const data = dataMock('mapas');
    data.items = await this.matchMaps();
    const page = this.page('mapas', 'Regiões', { back: { label: 'VOLTAR', href: url('jogar') } });
    return this.render(mapas, page, data);
  }

  // ------------------------------------------------------------------ apoio

  /** Lista da esquerda: estados operacionais (o Sobrevivente só entra quando o estado É esse). */
  private modes(modes: Dict[], survivor: Dict, op: Operational): Dict[] {
    const state = String(op.state ?? GR.STATE_HUNTER);
    const out: Dict[] = [];
    for (const m of modes) {
      const slug = String(m.slug ?? '');
      if (slug === 'mercenary') {
        const f = GR.faction(op.faction ?? null);
        m.desc = f !== null ? f.label + ' — CONTRATO ATIVO · ' + m.desc : 'SEM CONTRATO · ' + m.desc;
        m.faction = op.faction ?? null;
      }
      m.active = (slug === 'hunter' && state === GR.STATE_HUNTER) || (slug === 'mercenary' && state === GR.STATE_MERCENARY);
      // Sobrevivente não escolhe bandeira: as duas ficam visíveis mas indisponíveis.
      if (state === GR.STATE_SURVIVOR && ['hunter', 'mercenary'].includes(slug)) {
        m.locked = true;
        m.lock_tip = 'Indisponível no estado Sobrevivente.';
      }
      out.push(m);
    }
    if (state === GR.STATE_SURVIVOR && Object.keys(survivor).length > 0) out.unshift(survivor);
    return out;
  }

  /** Hero central por estado (PRD §17.4–17.6): título/texto/CTA e a linha de indicadores. */
  private async hero(heroes: Dict, lab: Dict, op: Operational, maps: Dict[]): Promise<Dict> {
    const state = String(op.state ?? GR.STATE_HUNTER);
    const sel: Dict = maps.find((m) => m.selected) ?? maps[0] ?? {};
    const regiao = String(sel.name ?? '—');
    const ativ = String(sel.human_activity ?? 'MODERADA');

    if (state === GR.STATE_SURVIVOR) {
      const h: Dict = heroes.survivor ?? {};
      const since = op.survivor_since ?? null;
      const stats = [
        { k: lab.known_pos ?? 'ÚLTIMA POSIÇÃO', v: regiao + ' · SETOR K-07', cls: 'white' },
        { k: lab.since ?? 'NO ESTADO HÁ', v: since ? this.duracao(String(since)) : '—', cls: 'white', num: true },
        { k: lab.field_base ?? 'BASE DE CAMPO', v: 'NENHUMA CONHECIDA', cls: '' },
        { k: lab.condition ?? 'CONDIÇÃO', v: 'ESTÁVEL', cls: 'lime' },
      ];
      // `$h + [...]` do PHP: as chaves do mock vencem as novas.
      return { key: 'survivor', stats, badge: { kind: 'state', value: op }, ...h };
    }

    if (state === GR.STATE_MERCENARY) {
      const h: Dict = heroes.mercenary ?? {};
      const f = GR.faction(op.faction ?? null) ?? { label: '—', color: 'muted' };
      const fs = String(op.faction ?? '');
      let contratos = 0;
      try {
        contratos = Number(await DB.value('SELECT COUNT(*) FROM missions WHERE origin = :f AND locked = 0', { f: fs })) | 0;
      } catch {
        /* sem patch */
      }
      const stats = [
        { k: lab.faction ?? 'FACÇÃO', v: f.label, cls: f.color },
        { k: lab.rep ?? 'REPUTAÇÃO', v: num(Number(op.reputation[fs] ?? 0) | 0), cls: f.color, num: true },
        { k: lab.tier ?? 'FAIXA', v: String(op.tiers[fs] ?? '—'), cls: f.color },
        { k: lab.contracts ?? 'CONTRATOS', v: num(contratos) + ' DISPONÍVEIS', cls: '', num: true },
        { k: lab.region ?? 'REGIÃO', v: regiao, cls: '' },
      ];
      return { key: 'mercenary', stats, faction: fs, ...h };
    }

    const h: Dict = heroes.hunter ?? {};
    const bases = await WorldMap.governmentBases();
    const base: Dict = bases.find((b) => b.slug === (op.base_pref ?? 'alpha')) ?? bases[0] ?? { short: 'ALPHA', status: 'OPERACIONAL' };
    const stats = [
      { k: lab.rank ?? 'PATENTE', v: String(op.rank ?? 'RECRUTA'), cls: '' },
      { k: lab.role ?? 'FUNÇÃO', v: String(op.role ?? 'A DEFINIR'), cls: 'lime' },
      { k: lab.base ?? 'BASE', v: String(base.short), cls: '', sub: String(base.status) },
      { k: lab.squad ?? 'ESQUADRÃO', v: '1 / ' + GR.SQUAD_MAX, cls: '', num: true },
      { k: lab.region ?? 'REGIÃO', v: regiao, cls: '' },
      { k: lab.activity ?? 'ATIVIDADE', v: ativ, cls: GR.activityColor(ativ) },
    ];
    return { key: 'hunter', stats, ...h };
  }

  /** Preferências: a Base Governamental vem da tabela, a função das quatro oficiais. */
  private prefs(prefs: Dict[], bases: Dict[], op: Operational): Dict[] {
    for (const p of prefs) {
      if ((p.key ?? '') === 'base') {
        p.options = bases.map((b) => String(b.short));
        const atual = String(op.base_pref ?? '').toUpperCase();
        p.text = p.options.includes(atual) ? atual : (p.options[0] ?? 'ALPHA');
        // Sobrevivente não tem base governamental para escolher.
        if ((op.state ?? '') === GR.STATE_SURVIVOR) {
          p.readonly = true;
          p.text = 'SEM ACESSO';
          p.options = ['SEM ACESSO'];
        }
      }
      if ((p.key ?? '') === 'role') {
        p.options = Object.keys(GR.ROLES);
        const role = String(op.role ?? '');
        p.text = p.options.includes(role) ? role : p.options[0];
      }
    }
    return prefs;
  }

  /** Tempo desde um DATETIME do D1 (UTC): "2h 05min" / "47 min". */
  private duracao(desde: string): string {
    const t = Date.parse(desde.replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(desde) ? '' : 'Z'));
    const s = Number.isNaN(t) ? 0 : Math.max(0, Math.floor((Date.now() - t) / 1000));
    return s >= 3600 ? `${Math.floor(s / 3600)}h ${pad2(Math.floor((s % 3600) / 60))}min` : `${Math.floor(s / 60)} min`;
  }

  /**
   * Quatro desafios DIÁRIOS do jogador + quanto falta para o quadro virar. Sem banco (ou sem
   * desafio cadastrado) devolve o cromo estático recebido.
   */
  private async dailyChallenges(cromo: Dict[], resetCromo: string): Promise<[Dict[], string]> {
    let board: ChallengeRepo.Quadro;
    try {
      board = await ChallengeRepo.board(this.userId());
    } catch {
      return [cromo.slice(0, 4), resetCromo];
    }
    const diarios = board.diarios;
    const itens = diarios?.items ?? [];
    if (itens.length === 0) return [cromo.slice(0, 4), resetCromo];
    return [itens.slice(0, 4), this.tempoCurto(Number(diarios?.seconds ?? 0) | 0) || resetCromo];
  }

  /** Segundos -> "4d 06h" / "12h 45m" / "45m", no formato que o contador da view espera. */
  private tempoCurto(segundos: number): string {
    if (segundos <= 0) return '';
    const d = Math.floor(segundos / 86400);
    const h = Math.floor((segundos % 86400) / 3600);
    const m = Math.floor((segundos % 3600) / 60);
    if (d > 0) return d + 'd ' + pad2(h) + 'h';
    return h > 0 ? h + 'h ' + pad2(m) + 'm' : m + 'm';
  }

  /**
   * Regiões operacionais: catálogo `maps` kind=match, conciliado com o estado de mundo em meta.
   * Mantém as chaves que a view `mapas` consome. Sem banco, cai para o mock estático.
   */
  private async matchMaps(): Promise<Dict[]> {
    let rows: Dict[];
    try {
      rows = await WorldMap.maps('match');
    } catch {
      return lista(dataMock('mapas').items);
    }
    if (rows.length === 0) return lista(dataMock('mapas').items);

    return rows.map((r) => {
      const meta: Dict = r.meta && typeof r.meta === 'object' && !Array.isArray(r.meta) ? r.meta : {};
      const slug = String(r.slug ?? '').replace(/^mapa-/, '');
      const world = WorldMap.regionState(r);
      return {
        slug,
        name: String(r.name ?? ''),
        mode: String(r.mode ?? ''), // simulações (P2) ainda leem
        players: String(r.players ?? ''), // idem — não vai mais ao card
        image: String(r.image ?? ''),
        icon: String(meta.icon ?? ''),
        selected: Boolean(meta.selected),
        size: String(meta.size ?? ''),
        biome: String(meta.biome ?? ''),
        weather: String(meta.weather ?? ''),
        best: String(meta.best ?? ''),
        best_by: String(meta.best_by ?? ''),
        your_best: String(meta.your_best ?? ''),
        desc: String(r.description ?? ''),
        // mundo persistente (PRD §17.7)
        infestation: world.infestation,
        human_activity: world.human_activity,
        persistent: world.persistent,
        capacity: world.capacity,
        // level da UE que este mapa carrega; a view publica em data-ue-level
        ue_level: String(r.unreal_level ?? ''),
      };
    });
  }
}
