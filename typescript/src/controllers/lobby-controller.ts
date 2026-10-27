/**
 * Tela LOBBY — o App\Controllers\LobbyController do PHP. Cromo de src/data/lobby.json com o
 * contexto operacional do jogador (PRD v3 §18) e o chat da sessão.
 */
import { ctx } from '../core/context';
import * as DB from '../core/database';
import { date } from '../core/fuso';
import * as GR from '../core/game-rules';
import { dataMock, flash } from '../core/helpers';
import type { AppRequest } from '../core/request';
import type { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import * as WorldMap from '../core/world-map';
import { lobby } from '../views/pages/lobby';
import { Controller } from './controller';

const MAX_CHAT = 12;

export class LobbyController extends Controller {
  async index(_req: AppRequest, _p: Params): Promise<AppResponse> {
    let data: Dict = dataMock('lobby');
    data.news = dataMock('noticias').items ?? [];
    data = await this.comContextoOperacional(data);

    // Mensagens digitadas nesta sessão entram no fim da conversa do cromo.
    const extra = ctx().session.get<Dict[]>('lobby_chat');
    if (Array.isArray(extra) && extra.length > 0) {
      data.chat = [...((data.chat ?? []) as Dict[]), ...extra].slice(-MAX_CHAT);
    }

    const page = this.page('lobby', 'Lobby', {
      bg: 'bg/lobby.jpg',
      three: { atmosphere: true, hero: true },
    });
    return this.render(lobby, page, data);
  }

  /**
   * Lobby Hunter no mundo persistente (PRD v3 §18).
   *  - o card `self` é o jogador real: nome, nível, patente e função;
   *  - nunca mais de SQUAD_MAX operadores (o card de vaga completa o quarto);
   *  - COMANDO = maior patente presente (hierarquia, não coroa genérica);
   *  - o painel da operação lê a missão ativa do jogador; sem missão, fica o cromo.
   */
  private async comContextoOperacional(data: Dict): Promise<Dict> {
    const player = await this.player();
    const op = await this.operational();

    /* ---- esquadrão ---- */
    let squad = ((data.squad ?? []) as unknown[]).filter((m): m is Dict => !!m && typeof m === 'object' && !Array.isArray(m));
    for (const m of squad) {
      if (m.self) {
        m.name = String(player.name ?? m.name);
        m.level = Number(player.level ?? m.level) | 0;
        m.rank = String(op.rank ?? m.rank ?? 'RECRUTA');
        m.role = String(op.role ?? m.role ?? 'ASSAULT');
        m.portrait = String(player.avatar ?? m.portrait);
      }
      m.role = GR.role(m.role ?? null) ?? 'ASSAULT';
      m.leader = false;
    }
    squad = squad.slice(0, GR.SQUAD_MAX);

    // COMANDO: quem tem a maior patente. Empate favorece o jogador (é o lobby dele).
    let lider: number | null = null;
    let melhor = -1;
    squad.forEach((m, i) => {
      const idx = GR.rankIndex(String(m.rank ?? ''));
      if (idx > melhor || (idx === melhor && m.self)) {
        melhor = idx;
        lider = i;
      }
    });
    if (lider !== null) squad[lider]!.leader = true;
    data.squad = squad;
    data.max_players = GR.SQUAD_MAX;
    data.operational = op;

    /* ---- operação selecionada: missão ativa do jogador ---- */
    const uid = this.userId();
    if (uid > 0) {
      let m: Dict | null = null;
      try {
        m = await DB.one(
          `SELECT m.name, m.objective, m.region, m.origin, m.human_activity, m.squad_min, m.squad_max, m.difficulty, m.giver
             FROM missions m JOIN player_missions pm ON pm.mission_id = m.id AND pm.user_id = :u
            WHERE pm.status IN ('active','available')
            ORDER BY (pm.status <> 'active'), m.sort_order LIMIT 1`,
          { u: uid },
        );
      } catch (e) {
        console.error('[extracthunters] lobby missão:', e);
        m = null;
      }
      if (m !== null) {
        const origem = GR.origin((m.origin as string | null) ?? null);
        const regiao = String(m.region ?? '');
        const mundo = await this.estadoDaRegiao(regiao);
        const bases = await WorldMap.governmentBases();
        let base: Dict = bases[0] ?? { short: 'ALPHA' };
        for (const b of bases) if (b.slug === (op.base_pref ?? '')) base = b;

        const mission: Dict = data.mission ?? {};
        const hunter = op.state === GR.STATE_HUNTER;
        const merc = op.state === GR.STATE_MERCENARY;
        const objetivo = String(m.objective ?? '');
        mission.title = String(m.name);
        mission.subtitle = origem.label + (hunter ? ' · BASE ' + String(base.short) : '');
        mission.origin = String(m.origin ?? 'hunter_command');
        mission.objective_lines = objetivo.split(/(?<=[.!?])\s+/u);
        mission.stats = [
          { label: 'ORIGEM', value: origem.label, hot: true },
          { label: merc ? 'FACÇÃO' : 'BASE', value: merc ? String(op.faction ?? '').toUpperCase() : String(base.short), hot: false },
          { label: 'AMEAÇA VEYRA', value: String(mundo.veyra_activity ?? 'MODERADA'), hot: true },
          { label: 'ATIVIDADE HUMANA', value: String(m.human_activity || (mundo.human_activity ?? 'PROVÁVEL')), hot: true },
          { label: 'ESQUADRÃO', value: `${Number(m.squad_min ?? 1) | 0}–${Number(m.squad_max ?? GR.SQUAD_MAX) | 0}`, hot: false },
          { label: 'MUNDO', value: 'PERSISTENTE', hot: false },
        ];
        data.mission = mission;
        const settings = (data.settings ?? []) as Dict[];
        if (m.difficulty) {
          for (const s of settings) if (s.key === 'difficulty') s.value = String(m.difficulty);
        }
        if (mundo.weather) {
          for (const s of settings) if (s.key === 'weather') s.value = String(mundo.weather).toUpperCase();
        }
      }
    }

    // Sobrevivente não mobiliza esquadrão Hunter: o CTA diz a verdade.
    if (op.state === GR.STATE_SURVIVOR) {
      data.cta = 'SEM MOBILIZAÇÃO · SOBREVIVENTE';
      data.cta_ready = false;
    } else if (op.state === GR.STATE_MERCENARY) {
      data.cta = 'MOBILIZAR SOB CONTRATO';
    }
    return data;
  }

  /** Estado de mundo da região pelo NOME que a missão guarda (maps.name). */
  private async estadoDaRegiao(nome: string): Promise<Dict> {
    try {
      for (const r of await WorldMap.maps('region')) {
        if (String(r.name).toUpperCase() === nome.toUpperCase()) return WorldMap.regionState(r);
      }
    } catch {
      // sem banco
    }
    return {};
  }

  /** POST /lobby/chat: CSRF, validação, flash, volta ao lobby. */
  async chat(req: AppRequest, _p: Params): Promise<AppResponse> {
    this.csrf(req);
    const bruto = (req.input('message', '') ?? '').replace(/\s+/gu, ' ');
    const message = Array.from(bruto).slice(0, 200).join('').trim();

    if (message === '') {
      flash('error', 'Digite uma mensagem antes de enviar.');
      return this.redirect('lobby');
    }

    const atual = ctx().session.get<Dict[]>('lobby_chat');
    const chat: Dict[] = Array.isArray(atual) ? [...atual] : [];
    chat.push({
      author: (await this.player()).name,
      text: message,
      time: date('H:i'),
      self: true,
    });
    ctx().session.set('lobby_chat', chat.slice(-MAX_CHAT));

    flash('ok', 'Mensagem enviada.');
    return this.redirect('lobby');
  }
}
