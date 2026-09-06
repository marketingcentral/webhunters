<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repo\CatalogRepo;

final class AchievementsController extends Controller
{
    /** category do item de recompensa -> subtítulo exibido no painel (PT-BR). */
    private const REWARD_ITEM_LABEL = [
        'weapon'   => 'FUZIL DE ASSALTO',
        'pistol'   => 'PISTOLA',
        'knife'    => 'FACA DE COMBATE',
        'backpack' => 'MOCHILA TÁTICA',
        'tool'     => 'EQUIPAMENTO',
        'misc'     => 'CONTÊINER',
        'med'      => 'MÉDICO',
        'key'      => 'CHAVE',
        'armband'  => 'INSÍGNIA DE FACÇÃO',
        'skin'     => 'SKIN DE ARMA',
    ];

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $data = data_mock('conquistas');   // textos/labels de layout
        $uid  = $this->userId();

        // cor do hexágono por conquista (atributo visual do layout, mantido no Data)
        $colorBySlug = [];
        foreach ((array)($data['items'] ?? []) as $m) {
            if (isset($m['slug'])) {
                $colorBySlug[(string)$m['slug']] = (string)($m['color'] ?? 'muted');
            }
        }

        // progresso do jogador (conciliado por user_id)
        $prog = [];
        if ($uid > 0) {
            foreach (Database::all(
                'SELECT achievement_id, progress, completed_at FROM player_achievements WHERE user_id = :u',
                ['u' => $uid]
            ) as $r) {
                $prog[(int)$r['achievement_id']] = $r;
            }
        }

        // catálogo de conquistas (com recompensas) x estado do jogador
        $all = [];
        foreach (CatalogRepo::achievements() as $a) {
            $all[] = $this->mapAchievement($a, $prog[(int)$a['id']] ?? null, $colorBySlug);
        }

        // progresso geral e contagem por categoria (do jogador)
        $done = 0;
        $doneByCat = [];
        $totByCat  = [];
        foreach ($all as $x) {
            $c = (string)$x['category'];
            $totByCat[$c] = ($totByCat[$c] ?? 0) + 1;
            if ($x['status'] === 'done') {
                $done++;
                $doneByCat[$c] = ($doneByCat[$c] ?? 0) + 1;
            }
        }
        $total = count($all);
        $data['done']  = $done;
        $data['total'] = $total;

        $data['categories'] = array_map(
            static function (array $c) use ($doneByCat, $totByCat, $done, $total): array {
                $slug = (string)($c['slug'] ?? '');
                if ($slug === 'todas') {
                    $c['done']  = $done;
                    $c['total'] = $total;
                } else {
                    $c['done']  = (int)($doneByCat[$slug] ?? 0);
                    $c['total'] = (int)($totByCat[$slug] ?? 0);
                }
                return $c;
            },
            (array)($data['categories'] ?? [])
        );

        // filtro por categoria (mesma validação do original)
        $slugs = array_column($data['categories'] ?? [], 'slug');
        $cat   = $this->pick($params['categoria'] ?? null, $slugs, 'todas');

        $items = $all;
        if ($cat !== 'todas') {
            $items = array_values(array_filter($items, static fn(array $a): bool => ($a['category'] ?? '') === $cat));
        }

        // ordenação
        $sort = $request->query('ordem', 'recentes');
        $data['sort'] = in_array($sort, ['recentes', 'nome', 'progresso'], true) ? $sort : 'recentes';
        if ($data['sort'] === 'nome') {
            usort($items, static fn(array $a, array $b): int => strcmp((string)$a['title'], (string)$b['title']));
        }

        $data['active_category'] = $cat;
        $data['items']    = $items;
        $data['selected'] = $items[0] ?? ($all[0] ?? null);

        $page = $this->page('conquistas', 'Conquistas', ['bg' => 'bg/conquistas.jpg', 'bg_pos' => 'center 62%']);
        return $this->render('conquistas', $page, $data);
    }

    /**
     * Converte uma conquista do catálogo + estado do jogador nas chaves que a view consome.
     *
     * @param array<string, mixed>      $a           linha de CatalogRepo::achievements()
     * @param array<string, mixed>|null $pa          linha de player_achievements do jogador
     * @param array<string, string>     $colorBySlug slug -> cor do hexágono
     * @return array<string, mixed>
     */
    private function mapAchievement(array $a, ?array $pa, array $colorBySlug): array
    {
        $slug     = (string)($a['slug'] ?? '');
        $target   = max(1, (int)($a['target'] ?? 1));
        $progress = (int)($pa['progress'] ?? 0);
        $doneAt   = $pa['completed_at'] ?? null;
        $doneAt   = ($doneAt !== null && $doneAt !== '') ? (string)$doneAt : null;

        if ($doneAt !== null) {
            $status = 'done';
        } elseif ($progress > 0) {
            $status = 'progress';
        } else {
            $status = 'locked';
        }

        // recompensas: moedas/XP para rewards[]; item para reward_item
        $rewards    = [];
        $rewardItem = null;
        foreach ((array)($a['rewards'] ?? []) as $r) {
            $kind = (string)($r['kind'] ?? 'xp');
            if ($kind === 'item') {
                $rewardItem = $this->rewardItem(
                    (int)($r['item_id'] ?? 0),
                    (string)($r['item_name'] ?? ''),
                    (string)($r['item_image'] ?? '')
                );
                continue;
            }
            $amount = (int)($r['amount'] ?? 0);
            $rewards[] = [
                'type'  => in_array($kind, ['xp', 'cash', 'points'], true) ? $kind : 'xp',
                'value' => $amount,
                'text'  => $this->rewardText($kind, $amount),
            ];
        }

        $row = [
            'slug'        => $slug,
            'title'       => (string)($a['name'] ?? ''),
            'desc'        => (string)($a['description'] ?? ''),
            'icon'        => (string)($a['icon'] ?? 'military_tech'),
            'color'       => $colorBySlug[$slug] ?? ($status === 'done' ? 'lime' : 'muted'),
            'category'    => (string)($a['category'] ?? 'combate'),
            'status'      => $status,
            'rewards'     => $rewards,
            'reward_item' => $rewardItem,
        ];

        if ($status === 'done') {
            $row['date']        = $this->fmtDate($doneAt);
            $row['unlocked_at'] = $this->fmtDateTime($doneAt);
        } elseif ($status === 'progress') {
            $row['cur'] = $progress;
            $row['max'] = $target;
        }

        return $row;
    }

    /** Texto formatado da recompensa (ex.: "+6.500 XP", "+2.400"). */
    private function rewardText(string $kind, int $amount): string
    {
        $n = number_format($amount, 0, ',', '.');
        return $kind === 'xp' ? '+' . $n . ' XP' : '+' . $n;
    }

    /**
     * Item de recompensa a partir do catálogo (nome curto + categoria + imagem dinâmica).
     * @return array{name: string, desc: string, image: string}
     */
    private function rewardItem(int $itemId, string $name, string $image): array
    {
        $desc = '';
        $it   = $itemId > 0 ? CatalogRepo::item($itemId) : null;
        if ($it !== null) {
            $short = (string)($it['short_name'] ?? '');
            $name  = $short !== '' ? $short : (string)($it['name'] ?? $name);
            $image = (string)($it['image'] ?? $image);
            $cat   = (string)($it['category'] ?? '');
            $desc  = self::REWARD_ITEM_LABEL[$cat] ?? mb_strtoupper($cat);
        }

        return [
            'name'  => mb_strtoupper($name),
            'desc'  => $desc,
            'image' => $image,
        ];
    }

    private function fmtDate(?string $dt): string
    {
        $ts = $dt !== null ? strtotime($dt) : false;
        return $ts !== false ? date('d/m/Y', $ts) : '';
    }

    private function fmtDateTime(?string $dt): string
    {
        $ts = $dt !== null ? strtotime($dt) : false;
        return $ts !== false ? date('d/m/Y H:i', $ts) : '';
    }
}
