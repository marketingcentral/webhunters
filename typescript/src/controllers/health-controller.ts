/**
 * Tela SAÚDE — o App\Controllers\HealthController do PHP, conciliada por user_id.
 *
 *  - Sinais vitais + estado corporal (membros) vêm de players.health (JSON) via PlayerRepo.forUser().health.
 *  - ITENS DE CURA vêm dos itens da categoria "med" no inventário do jogador (player_items JOIN items).
 *
 * Textos/labels de layout (títulos, exames, condições, aba STATUS, regeneração e ações) vêm de
 * app/Data/saude.php (-> src/data/saude.json) e só são complementados.
 */
import * as DB from '../core/database';
import { dataMock } from '../core/helpers';
import type { AppRequest } from '../core/request';
import type { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import { saude } from '../views/pages/saude';
import { Controller } from './controller';

/** chave do vital na view -> chave em players.health; recomputa value/pct sem redesenhar. */
const VITAL_OF: Record<string, string> = {
  health: 'saude',
  energy: 'energia',
  hydration: 'hidratacao',
  hunger: 'fome',
  temperature: 'temperatura',
};

/** parte do corpo na view -> chave do membro em health.limbs. */
const LIMB_OF: Record<string, string> = {
  head: 'cabeca',
  thorax: 'torax',
  stomach: 'estomago',
  'arm-left': 'braco_esq',
  'arm-right': 'braco_dir',
  'leg-left': 'perna_esq',
  'leg-right': 'perna_dir',
};

/** Cura restaurada por subtipo do item médico (usado pelo botão USAR / histórico). */
const HEAL_OF: Record<string, number> = {
  medkit: 100,
  surgical: 200,
  bandage: 50,
  painkiller: 25,
};

const temChave = (o: Dict, k: string): boolean => Object.prototype.hasOwnProperty.call(o, k);
const obj = (v: unknown): Dict => (v && typeof v === 'object' && !Array.isArray(v) ? (v as Dict) : {});

export class HealthController extends Controller {
  async index(req: AppRequest, _p: Params): Promise<AppResponse> {
    const data: Dict = dataMock('saude');
    const tab = req.query('aba', 'tratamento');
    data.active_tab = tab === 'tratamento' || tab === 'status' ? tab : 'tratamento';

    const uid = this.userId();
    if (uid > 0) {
      const player = await this.player();
      const health = obj(player.health);

      if (Object.keys(health).length) {
        data.vitals = this.vitals((data.vitals ?? []) as Dict[], health);
        data.body = this.body((data.body ?? []) as Dict[], health);

        const valor = Number(health.saude ?? 100);
        if (data.details?.health !== undefined && data.details.health !== null) {
          data.details.health.value = valor;
        }
      }

      const healing = await this.healingItems(uid);
      if (healing.length) data.healing = healing;
    }

    const page = this.page('saude', 'Saúde', { bg: 'bg/saude.jpg' });
    return this.render(saude, page, data);
  }

  /** Os 5 sinais vitais a partir de players.health: só value e pct vêm do banco. */
  private vitals(template: Dict[], health: Dict): Dict[] {
    return template.map((v) => {
      const src = VITAL_OF[String(v.key ?? '')];
      if (src !== undefined && temChave(health, src)) {
        const value = Number(health[src]) || 0;
        v.value = value;
        const max = v.max ?? null;
        if (max !== null && Number(max) > 0) {
          v.pct = Math.max(0, Math.min(100, (value / Number(max)) * 100));
        }
      }
      return v;
    });
  }

  /** Estado corporal (3 partes centrais + 4 membros) a partir de health.limbs/limbs_max. */
  private body(template: Dict[], health: Dict): Dict[] {
    const cur = obj(health.limbs);
    const max = obj(health.limbs_max);
    return template.map((p) => {
      const limb = LIMB_OF[String(p.part ?? '')];
      if (limb !== undefined && temChave(cur, limb)) {
        p.cur = Number(cur[limb]) || 0;
        p.max = Number(max[limb] ?? cur[limb]) || 0;
      }
      return p;
    });
  }

  /** ITENS DE CURA: itens "med" de tratamento (não diagnóstico) em posse, agrupados por item. */
  private async healingItems(uid: number): Promise<Dict[]> {
    const rows = await DB.all(
      `SELECT it.slug, it.name, it.image, it.subtype, it.description, SUM(pi.quantity) AS qty
         FROM player_items pi JOIN items it ON it.id = pi.item_id
        WHERE pi.user_id = :u AND it.category = :cat AND it.subtype <> :diag
        GROUP BY it.id, it.slug, it.name, it.image, it.subtype, it.description
        ORDER BY CASE it.subtype WHEN 'medkit' THEN 1 WHEN 'bandage' THEN 2 WHEN 'painkiller' THEN 3
                                 WHEN 'surgical' THEN 4 ELSE 0 END, it.name`,
      { u: uid, cat: 'med', diag: 'diagnostic' },
    );
    return rows.map((r) => {
      const count = Number(r.qty) | 0;
      return {
        slug: String(r.slug),
        name: String(r.name),
        image: String(r.image ?? ''),
        count,
        total: count,
        desc: String(r.description ?? ''),
        cta: 'USAR',
        heal: HEAL_OF[String(r.subtype)] ?? 0,
      };
    });
  }
}
