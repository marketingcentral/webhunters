<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repo\CatalogRepo;
use App\Repo\InventoryRepo;
use App\Repo\PlayerRepo;

final class ShopController extends Controller
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $data  = data_mock('loja');   // textos, categorias e cromo da vitrine (layout)
        $cat   = $request->query('categoria', 'destaques');
        $slugs = array_column($data['categories'] ?? [], 'slug');
        $data['active_category'] = in_array($cat, $slugs, true) ? $cat : ($slugs[0] ?? 'destaques');

        // itens vêm do banco (catálogo + oferta), conciliados com o saldo e o stash do jogador
        $uid    = $this->userId();
        $player = $this->player();
        $bal    = $this->balance($player);
        $owned  = $this->ownedItemIds($uid);
        $byId   = CatalogRepo::itemsById();

        $items = array_map(
            fn (array $o): array => $this->offerToCard($o, $bal, $owned, $byId),
            CatalogRepo::shopOffers()
        );

        $data['items']    = $items;
        $data['balance']  = $bal;
        $data['featured'] = $this->promo((array)($data['featured'] ?? []), $items);

        $page = $this->page('loja', 'Loja', ['nav' => 'loja']);
        return $this->render('loja', $page, $data);
    }

    /** POST /loja/comprar — debita a moeda e envia o item ao stash do jogador. */
    public function buy(Request $request, array $params = []): Response
    {
        $this->csrf($request);
        $offerId = (int)$request->input('offer_id', '0');
        $uid     = $this->userId();
        $ajax    = $this->isJson($request);

        if ($uid <= 0 || $offerId <= 0) {
            return $this->fail($request, $ajax, 'Requisição inválida.');
        }

        $result = InventoryRepo::buy($uid, $offerId);
        if (($result['ok'] ?? false) !== true) {
            return $this->fail($request, $ajax, (string)($result['error'] ?? 'Não foi possível comprar.'));
        }

        $player  = PlayerRepo::forUser($uid) ?? [];
        $bal     = $this->balance($player);
        $offer   = $this->offer($offerId);
        $name    = (string)($offer['name'] ?? 'Item');
        $message = $name . ' adquirido e enviado ao seu stash.';

        if ($ajax) {
            return Response::json([
                'ok'       => true,
                'message'  => $message,
                'balance'  => $bal,
                'offer_id' => $offerId,
                'item_id'  => (int)($offer['item_id'] ?? 0),
            ], 200);
        }
        flash('ok', $message);
        return $this->redirect('loja', $this->backQuery($request));
    }

    /** @return array<string,mixed>|null oferta do catálogo pelo id */
    private function offer(int $offerId): ?array
    {
        foreach (CatalogRepo::shopOffers() as $o) {
            if ((int)$o['id'] === $offerId) { return $o; }
        }
        return null;
    }

    /** Resposta de erro: JSON 422 no fetch, flash + redirect no POST normal. */
    private function fail(Request $request, bool $ajax, string $error): Response
    {
        if ($ajax) {
            return Response::json(['ok' => false, 'error' => $error], 422);
        }
        flash('error', $error);
        return $this->redirect('loja', $this->backQuery($request));
    }

    /** Mantém a aba aberta ao voltar de um POST sem JavaScript. */
    private function backQuery(Request $request): array
    {
        $cat = (string)($request->input('categoria', '') ?? '');
        return ($cat !== '' && $cat !== 'destaques') ? ['categoria' => $cat] : [];
    }

    private function isJson(Request $request): bool
    {
        $accept = (string)($request->header('Accept', '') ?? '');
        return $request->isAjax() || str_contains($accept, 'application/json');
    }

    /** @param array<string,mixed> $player @return array{credits:int,cash:int,points:int} */
    private function balance(array $player): array
    {
        $c = (array)($player['currencies'] ?? []);
        return [
            'credits' => (int)($c['credits'] ?? 0),
            'cash'    => (int)($c['cash'] ?? 0),
            'points'  => (int)($c['points'] ?? 0),
        ];
    }

    /**
     * Linha de shop_offers -> cartão da vitrine (mesmas chaves que a view espera).
     * @param array<string,mixed> $offer
     * @param array{credits:int,cash:int,points:int} $bal
     * @param array<int,true> $owned
     * @param array<int,array<string,mixed>> $byId
     * @return array<string,mixed>
     */
    private function offerToCard(array $offer, array $bal, array $owned, array $byId): array
    {
        $itemId   = (int)$offer['item_id'];
        $discount = (int)($offer['discount'] ?? 0);
        $price    = (int)$offer['price'];
        $old      = null;
        if ($discount > 0) {
            $old   = $price;
            $price = (int)round($price * (100 - $discount) / 100);
        }
        $cur = (string)$offer['currency'];

        return [
            'offer_id'   => (int)$offer['id'],
            'item_id'    => $itemId,
            'slug'       => (string)($byId[$itemId]['slug'] ?? ('item-' . $itemId)),
            'name'       => (string)$offer['name'],
            'category'   => (string)$offer['category'],
            'currency'   => $cur,
            'price'      => $price,
            'old_price'  => $old,
            'discount'   => $discount > 0 ? $discount : null,
            'tag'        => $offer['tag'] !== null && $offer['tag'] !== '' ? (string)$offer['tag'] : null,
            'featured'   => (bool)$offer['featured'],
            'image'      => (string)$offer['image'],
            'desc'       => (string)($offer['description'] ?? ''),
            'rarity'     => (string)($offer['rarity'] ?? 'comum'),
            'owned'      => isset($owned[$itemId]),
            'affordable' => ($bal[$cur] ?? 0) >= $price,
        ];
    }

    /**
     * Vitrine em destaque: o cromo (arte, bullets, relógio) vem de app/Data/loja.php,
     * o produto/preço vêm da MESMA oferta do banco, então o botão compra de verdade.
     * @param array<string,mixed> $chrome @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function promo(array $chrome, array $items): array
    {
        $wanted = (string)($chrome['offer_slug'] ?? '');
        $pick   = null;
        foreach ($items as $it) {
            if ($wanted !== '' && $it['slug'] === $wanted) { $pick = $it; break; }
            if ($pick === null && $it['featured'] === true) { $pick = $it; }
        }
        if ($pick === null) {
            return $chrome + ['offer' => null];
        }
        return array_replace($chrome, [
            'offer'     => $pick,
            'title'     => $pick['name'],
            'desc'      => (string)($chrome['desc'] ?? '') !== '' ? $chrome['desc'] : $pick['desc'],
            'product'   => $pick['image'],
            'price'     => $pick['price'],
            'old_price' => $pick['old_price'] ?? 0,
            'discount'  => $pick['discount'] !== null ? '-' . $pick['discount'] . '%' : '',
            'currency'  => $pick['currency'],
        ]);
    }

    /** @return array<int,true> ids de itens que o jogador já tem no stash/equipamento */
    private function ownedItemIds(int $uid): array
    {
        if ($uid <= 0) {
            return [];
        }
        try {
            $rows = Database::all('SELECT DISTINCT item_id FROM player_items WHERE user_id = :u', ['u' => $uid]);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) { $out[(int)$r['item_id']] = true; }
        return $out;
    }
}
