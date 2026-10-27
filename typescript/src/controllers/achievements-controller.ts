/**
 * Tela CONQUISTAS — o App\Controllers\AchievementsController do PHP. Catálogo (com recompensas)
 * de CatalogRepo.achievements() × progresso do jogador em player_achievements; textos e a cor
 * do hexágono de cada conquista vêm de app/Data/conquistas.php (-> src/data/conquistas.json).
 */
import * as DB from '../core/database';
import { dataMock, num } from '../core/helpers';
import type { AppRequest } from '../core/request';
import type { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import * as CatalogRepo from '../repo/catalog-repo';
import { conquistas } from '../views/pages/conquistas';
import { Controller } from './controller';
import { fmtData, fmtDataHora } from './profile-controller';

/** category do item de recompensa -> subtítulo exibido no painel (PT-BR). */
const REWARD_ITEM_LABEL: Record<string, string> = {
  weapon: 'FUZIL DE ASSALTO',
  pistol: 'PISTOLA',
  knife: 'FACA DE COMBATE',
  backpack: 'MOCHILA TÁTICA',
  tool: 'EQUIPAMENTO',
  misc: 'CONTÊINER',
  med: 'MÉDICO',
  key: 'CHAVE',
  armband: 'INSÍGNIA DE FACÇÃO',
  skin: 'SKIN DE ARMA',
};

export class AchievementsController extends Controller {
  async index(req: AppRequest, params: Params): Promise<AppResponse> {
    const data: Dict = dataMock('conquistas'); // textos/labels de layout
    const uid = this.userId();

    // cor do hexágono por conquista (atributo visual do layout, mantido no Data)
    const colorBySlug: Record<string, string> = {};
    for (const m of (data.items ?? []) as Dict[]) {
      if (m.slug !== undefined && m.slug !== null) colorBySlug[String(m.slug)] = String(m.color ?? 'muted');
    }

    // progresso do jogador (conciliado por user_id) + catálogo, em paralelo
    const [progRows, catalogo] = await Promise.all([
      uid > 0
        ? DB.all('SELECT achievement_id, progress, completed_at FROM player_achievements WHERE user_id = :u', { u: uid })
        : Promise.resolve([]),
      CatalogRepo.achievements(),
    ]);
    const prog = new Map<number, Dict>();
    for (const r of progRows) prog.set(Number(r.achievement_id), r);

    // catálogo de conquistas (com recompensas) x estado do jogador
    const all: Dict[] = [];
    for (const a of catalogo) all.push(await this.mapAchievement(a, prog.get(Number(a.id)) ?? null, colorBySlug));

    // progresso geral e contagem por categoria (do jogador)
    let done = 0;
    const doneByCat: Record<string, number> = {};
    const totByCat: Record<string, number> = {};
    for (const x of all) {
      const c = String(x.category);
      totByCat[c] = (totByCat[c] ?? 0) + 1;
      if (x.status === 'done') {
        done++;
        doneByCat[c] = (doneByCat[c] ?? 0) + 1;
      }
    }
    const total = all.length;
    data.done = done;
    data.total = total;

    data.categories = ((data.categories ?? []) as Dict[]).map((c) => {
      const slug = String(c.slug ?? '');
      if (slug === 'todas') {
        c.done = done;
        c.total = total;
      } else {
        c.done = doneByCat[slug] ?? 0;
        c.total = totByCat[slug] ?? 0;
      }
      return c;
    });

    // filtro por categoria (mesma validação do original)
    const slugs = (data.categories as Dict[]).map((c) => String(c.slug));
    const cat = this.pick(params.categoria, slugs, 'todas')!;

    let items = all;
    if (cat !== 'todas') items = items.filter((a) => (a.category ?? '') === cat);

    // ordenação
    const sort = req.query('ordem', 'recentes');
    data.sort = sort === 'recentes' || sort === 'nome' || sort === 'progresso' ? sort : 'recentes';
    if (data.sort === 'nome') {
      items = [...items].sort((a, b) => (String(a.title) < String(b.title) ? -1 : String(a.title) > String(b.title) ? 1 : 0));
    }

    data.active_category = cat;
    data.items = items;
    data.selected = items[0] ?? all[0] ?? null;

    const page = this.page('conquistas', 'Conquistas', { bg: 'bg/conquistas.jpg', bg_pos: 'center 62%' });
    return this.render(conquistas, page, data);
  }

  /** Conquista do catálogo + estado do jogador -> as chaves que a view consome. */
  private async mapAchievement(a: Dict, pa: Dict | null, colorBySlug: Record<string, string>): Promise<Dict> {
    const slug = String(a.slug ?? '');
    const target = Math.max(1, Number(a.target ?? 1) | 0);
    const progress = Number(pa?.progress ?? 0) | 0;
    const raw = pa?.completed_at;
    const doneAt = raw !== null && raw !== undefined && raw !== '' ? String(raw) : null;

    const status = doneAt !== null ? 'done' : progress > 0 ? 'progress' : 'locked';

    // recompensas: moedas/XP para rewards[]; item para reward_item
    const rewards: Dict[] = [];
    let rewardItem: Dict | null = null;
    for (const r of (a.rewards ?? []) as Dict[]) {
      const kind = String(r.kind ?? 'xp');
      if (kind === 'item') {
        rewardItem = await this.rewardItem(Number(r.item_id ?? 0) | 0, String(r.item_name ?? ''), String(r.item_image ?? ''));
        continue;
      }
      const amount = Number(r.amount ?? 0) | 0;
      rewards.push({
        type: ['xp', 'cash', 'points'].includes(kind) ? kind : 'xp',
        value: amount,
        text: this.rewardText(kind, amount),
      });
    }

    const row: Dict = {
      slug,
      title: String(a.name ?? ''),
      desc: String(a.description ?? ''),
      icon: String(a.icon ?? 'military_tech'),
      color: colorBySlug[slug] ?? (status === 'done' ? 'lime' : 'muted'),
      category: String(a.category ?? 'combate'),
      status,
      rewards,
      reward_item: rewardItem,
    };

    if (status === 'done') {
      row.date = fmtData(doneAt!);
      row.unlocked_at = fmtDataHora(doneAt!);
    } else if (status === 'progress') {
      row.cur = progress;
      row.max = target;
    }
    return row;
  }

  /** Texto formatado da recompensa (ex.: "+6.500 XP", "+2.400"). */
  private rewardText(kind: string, amount: number): string {
    const n = num(amount);
    return kind === 'xp' ? '+' + n + ' XP' : '+' + n;
  }

  /** Item de recompensa a partir do catálogo (nome curto + categoria + imagem). */
  private async rewardItem(itemId: number, name: string, image: string): Promise<Dict> {
    let desc = '';
    const it = itemId > 0 ? await CatalogRepo.item(itemId) : null;
    if (it !== null) {
      const short = String(it.short_name ?? '');
      name = short !== '' ? short : String(it.name ?? name);
      image = String(it.image ?? image);
      const cat = String(it.category ?? '');
      desc = REWARD_ITEM_LABEL[cat] ?? cat.toUpperCase();
    }
    return { name: name.toUpperCase(), desc, image };
  }
}
