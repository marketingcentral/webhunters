/**
 * Tela /loja e POST /loja/comprar — o App\Controllers\ShopController do PHP.
 * Itens vêm do banco (shop_offers + items), conciliados com o saldo e o stash do jogador;
 * src/data/loja.json (app/Data/loja.php) dá textos, categorias e o cromo da vitrine.
 */
import * as DB from '../core/database';
import { dataMock, flash } from '../core/helpers';
import { atomUtc } from '../core/relogio';
import type { AppRequest } from '../core/request';
import { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import * as CatalogRepo from '../repo/catalog-repo';
import * as InventoryRepo from '../repo/inventory-repo';
import * as PlayerRepo from '../repo/player-repo';
import { loja } from '../views/pages/loja';
import { Controller } from './controller';

type Saldo = { credits: number; cash: number; points: number };

const int = (v: unknown): number => Math.trunc(Number(v) || 0);

/** (int) do PHP para texto de formulário: "12abc" -> 12, "abc" -> 0. */
const intDe = (s: string | null): number => Number.parseInt(String(s ?? ''), 10) || 0;

export class ShopController extends Controller {
  async index(req: AppRequest, _p: Params): Promise<AppResponse> {
    const data: Dict = dataMock('loja'); // textos, categorias e cromo da vitrine (layout)
    // app/Data/loja.php calcula o fim da promoção com time() a cada requisição; o JSON
    // exportado guardou o instante do export, então o Worker refaz a mesma conta aqui.
    if (data.featured && typeof data.featured === 'object') {
      data.featured.ends_at = atomUtc(Date.now() + (5 * 86400 + 7 * 3600 + 12 * 60) * 1000);
    }
    const cat = req.query('categoria', 'destaques');
    const slugs = ((data.categories ?? []) as Dict[]).map((c) => String(c.slug));
    data.active_category = cat !== null && slugs.includes(cat) ? cat : slugs[0] ?? 'destaques';

    const uid = this.userId();
    const [player, owned, byId, offers] = await Promise.all([
      this.player(),
      this.ownedItemIds(uid),
      CatalogRepo.itemsById(),
      CatalogRepo.shopOffers(),
    ]);
    const bal = this.balance(player);
    const items = offers.map((o) => this.offerToCard(o, bal, owned, byId));

    data.items = items;
    data.balance = bal;
    data.featured = this.promo((data.featured ?? {}) as Dict, items);

    const page = this.page('loja', 'Loja', { nav: 'loja' });
    return this.render(loja, page, data);
  }

  /** POST /loja/comprar — debita a moeda e envia o item ao stash do jogador. */
  async buy(req: AppRequest, _p: Params): Promise<AppResponse> {
    this.csrf(req);
    const offerId = intDe(req.input('offer_id', '0'));
    const uid = this.userId();
    const ajax = this.isJson(req);

    if (uid <= 0 || offerId <= 0) return this.fail(req, ajax, 'Requisição inválida.');

    const result = await InventoryRepo.buy(uid, offerId);
    if (result.ok !== true) return this.fail(req, ajax, String(result.error ?? 'Não foi possível comprar.'));

    const player = (await PlayerRepo.forUser(uid)) ?? {};
    const bal = this.balance(player);
    const offer = await this.offer(offerId);
    const message = String(offer?.name ?? 'Item') + ' adquirido e enviado ao seu stash.';

    if (ajax) {
      return AppResponse.json({ ok: true, message, balance: bal, offer_id: offerId, item_id: int(offer?.item_id ?? 0) }, 200);
    }
    flash('ok', message);
    return this.redirect('loja', this.backQuery(req));
  }

  /** Oferta do catálogo pelo id. */
  private async offer(offerId: number): Promise<Dict | null> {
    return (await CatalogRepo.shopOffers()).find((o) => int(o.id) === offerId) ?? null;
  }

  /** Erro: JSON 422 no fetch, flash + redirect no POST normal. */
  private fail(req: AppRequest, ajax: boolean, error: string): AppResponse {
    if (ajax) return AppResponse.json({ ok: false, error }, 422);
    flash('error', error);
    return this.redirect('loja', this.backQuery(req));
  }

  /** Mantém a aba aberta ao voltar de um POST sem JavaScript. */
  private backQuery(req: AppRequest): Record<string, string> {
    const cat = req.input('categoria', '') ?? '';
    return cat !== '' && cat !== 'destaques' ? { categoria: cat } : {};
  }

  private isJson(req: AppRequest): boolean {
    const accept = req.header('Accept', '') ?? '';
    return req.isAjax() || accept.includes('application/json');
  }

  private balance(player: Dict): Saldo {
    const c: Dict = player.currencies && typeof player.currencies === 'object' ? player.currencies : {};
    return { credits: int(c.credits ?? 0), cash: int(c.cash ?? 0), points: int(c.points ?? 0) };
  }

  /** Linha de shop_offers -> cartão da vitrine (mesmas chaves que a view espera). */
  private offerToCard(offer: Dict, bal: Saldo, owned: Set<number>, byId: Map<number, Dict>): Dict {
    const itemId = int(offer.item_id);
    const discount = int(offer.discount ?? 0);
    let price = int(offer.price);
    let old: number | null = null;
    if (discount > 0) {
      old = price;
      price = Math.round((price * (100 - discount)) / 100);
    }
    const cur = String(offer.currency);
    return {
      offer_id: int(offer.id),
      item_id: itemId,
      slug: String(byId.get(itemId)?.slug ?? 'item-' + itemId),
      name: String(offer.name),
      category: String(offer.category),
      currency: cur,
      price,
      old_price: old,
      discount: discount > 0 ? discount : null,
      tag: offer.tag !== null && offer.tag !== undefined && offer.tag !== '' ? String(offer.tag) : null,
      featured: Boolean(Number(offer.featured)),
      image: String(offer.image),
      desc: String(offer.description ?? ''),
      rarity: String(offer.rarity ?? 'comum'),
      owned: owned.has(itemId),
      affordable: ((bal as Record<string, number>)[cur] ?? 0) >= price,
    };
  }

  /**
   * Vitrine em destaque: o cromo vem de loja.json, o produto/preço vêm da MESMA oferta do
   * banco, então o botão compra de verdade.
   */
  private promo(chrome: Dict, items: Dict[]): Dict {
    const wanted = String(chrome.offer_slug ?? '');
    let pick: Dict | null = null;
    for (const it of items) {
      if (wanted !== '' && it.slug === wanted) {
        pick = it;
        break;
      }
      if (pick === null && it.featured === true) pick = it;
    }
    if (pick === null) return { offer: null, ...chrome };
    return {
      ...chrome,
      offer: pick,
      title: pick.name,
      desc: String(chrome.desc ?? '') !== '' ? chrome.desc : pick.desc,
      product: pick.image,
      price: pick.price,
      old_price: pick.old_price ?? 0,
      discount: pick.discount !== null ? '-' + pick.discount + '%' : '',
      currency: pick.currency,
    };
  }

  /** Ids de itens que o jogador já tem no stash/equipamento. */
  private async ownedItemIds(uid: number): Promise<Set<number>> {
    const out = new Set<number>();
    if (uid <= 0) return out;
    try {
      for (const r of await DB.all('SELECT DISTINCT item_id FROM player_items WHERE user_id = :u', { u: uid })) out.add(int(r.item_id));
    } catch {
      /* banco indisponível: ninguém tem nada */
    }
    return out;
  }
}
