<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repo\CatalogRepo;

/**
 * Tela HABILIDADES — árvore de talentos conciliada por user_id.
 *
 * Catálogo (definição dos nós) vem da tabela `skills`; o progresso vem de `player_skills`
 * e os pontos disponíveis de `players.skill_points`. O `data_mock('habilidades')` só é
 * usado para os textos fixos da tela (rótulos, descrição/ícone de cada categoria).
 */
final class SkillsController extends Controller
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $chrome = data_mock('habilidades');
        $uid    = $this->userId();
        $player = $this->player();

        $catalog = $this->catalog();                       // slug => linha de `skills`
        $levels  = $this->playerLevels($uid);              // slug => nível do jogador
        $cats    = $this->categories($catalog, $levels, $chrome['categories'] ?? []);
        $slugs   = array_column($cats, 'slug');

        // pick() já devolve o padrão quando não há parâmetro e lança 404 para categoria inválida.
        $active = (string)$this->pick($params['categoria'] ?? null, $slugs, (string)($slugs[0] ?? 'combate'));

        $tree  = $this->tree($catalog, $levels, $active);
        $tiers = $this->tiers(count($tree));
        $cols  = $this->cols($tree);

        $points = (int)($player['skill_points'] ?? 0);
        $sel    = $this->detail($catalog, $levels, $active, $request->query('hab'), $points, $chrome);

        $data = [
            'title'          => (string)($chrome['title'] ?? 'CATEGORIAS'),
            'points_label'   => (string)($chrome['points_label'] ?? 'PONTOS DISPONÍVEIS'),
            'points'         => $points,
            'redistribute'   => (array)($chrome['redistribute'] ?? ['label' => 'REDISTRIBUIR', 'key' => 'R']),
            'reset_category' => (array)($chrome['reset_category'] ?? ['label' => 'REDEFINIR CATEGORIA', 'key' => 'R']),
            'unlocked_label' => (string)($chrome['unlocked_label'] ?? 'HABILIDADES DESBLOQUEADAS'),
            'level_label'    => (string)($chrome['level_label'] ?? 'NÍVEL'),
            'categories'     => $cats,
            'tiers'          => $tiers,
            'cols'           => $cols,
            'active_category'=> $active,
            'category'       => $this->categoryBySlug($cats, $active),
            'tree'           => $tree,
            'selected'       => $sel,
            // refund = pontos que voltam (texto do flash). learned = existe algo para zerar
            // (é ISTO que habilita o botão: habilidade de custo 0 também precisa ser redefinível).
            'refund'         => $this->refundable($catalog, $levels, $active),
            'refund_all'     => $this->refundable($catalog, $levels, null),
            'learned'        => $this->learnedCount($catalog, $levels, $active),
            'learned_all'    => $this->learnedCount($catalog, $levels, null),
        ];

        $page = $this->page('habilidades', 'Habilidades');
        return $this->render('habilidades', $page, $data);
    }

    /** POST /habilidades/aprender — gasta pontos e sobe um nível da habilidade. */
    public function learn(Request $request, array $params = []): Response
    {
        $this->csrf($request);
        $uid  = $this->userId();
        $slug = (string)$request->input('habilidade', '');

        $catalog = $this->catalog();
        $node    = $catalog[$slug] ?? null;
        if ($uid <= 0 || $node === null) {
            flash('error', 'Habilidade desconhecida.');
            return $this->back($request, (string)($node['category'] ?? ''), $slug);
        }

        $levels = $this->playerLevels($uid);
        $cur    = (int)($levels[$slug] ?? 0);
        $max    = (int)$node['max_level'];
        $cost   = (int)$node['cost'];
        $cat    = (string)$node['category'];

        if ($cur >= $max) {
            flash('error', 'Esta habilidade já está no nível máximo.');
            return $this->back($request, $cat, $slug);
        }
        if (!$this->available($catalog, $levels, $node)) {
            flash('error', 'Aprenda antes a habilidade do tier anterior nesta coluna.');
            return $this->back($request, $cat, $slug);
        }

        $points = (int)Database::value('SELECT skill_points FROM players WHERE user_id = :u', ['u' => $uid]);
        if ($points < $cost) {
            flash('error', 'Pontos de habilidade insuficientes.');
            return $this->back($request, $cat, $slug);
        }

        Database::transaction(static function () use ($uid, $node, $cur, $cost): void {
            Database::run(
                'UPDATE players SET skill_points = skill_points - :c WHERE user_id = :u AND skill_points >= :c2',
                ['c' => $cost, 'u' => $uid, 'c2' => $cost]
            );
            Database::run(
                'INSERT INTO player_skills (user_id, skill_id, level) VALUES (:u, :s, :l)
                 ON DUPLICATE KEY UPDATE level = VALUES(level)',
                ['u' => $uid, 's' => (int)$node['id'], 'l' => $cur + 1]
            );
        });

        flash('ok', (string)$node['name'] . ' agora está no nível ' . ($cur + 1) . '/' . $max . '.');
        return $this->back($request, $cat, $slug);
    }

    /** POST /habilidades/redefinir — zera a categoria (ou tudo) e devolve os pontos gastos. */
    public function reset(Request $request, array $params = []): Response
    {
        $this->csrf($request);
        $uid   = $this->userId();
        $scope = (string)$request->input('escopo', 'categoria');
        $cat   = (string)$request->input('categoria', '');

        $catalog = $this->catalog();
        $cats    = array_values(array_unique(array_column($catalog, 'category')));
        if ($uid <= 0 || ($scope === 'categoria' && !in_array($cat, $cats, true))) {
            flash('error', 'Categoria desconhecida.');
            return $this->back($request, in_array($cat, $cats, true) ? $cat : (string)($cats[0] ?? ''), null);
        }

        $levels = $this->playerLevels($uid);
        $only   = $scope === 'tudo' ? null : $cat;
        $refund = $this->refundable($catalog, $levels, $only);
        $ids    = [];
        foreach ($catalog as $slug => $row) {
            if (($only === null || (string)$row['category'] === $only) && (int)($levels[$slug] ?? 0) > 0) {
                $ids[] = (int)$row['id'];
            }
        }

        if ($ids === []) {
            flash('error', $scope === 'tudo'
                ? 'Nenhum ponto de habilidade aplicado para redistribuir.'
                : 'Nenhuma habilidade aprendida nesta categoria.');
            return $this->back($request, $cat, null);
        }

        Database::transaction(static function () use ($uid, $ids, $refund): void {
            $in = implode(',', array_fill(0, count($ids), '?'));
            Database::run("DELETE FROM player_skills WHERE user_id = ? AND skill_id IN ($in)", array_merge([$uid], $ids));
            if ($refund > 0) {
                Database::run('UPDATE players SET skill_points = skill_points + ? WHERE user_id = ?', [$refund, $uid]);
            }
        });

        flash('ok', $scope === 'tudo'
            ? 'Árvore redistribuída: ' . $refund . ' ponto(s) devolvido(s).'
            : 'Categoria redefinida: ' . $refund . ' ponto(s) devolvido(s).');
        return $this->back($request, $cat, null);
    }

    // ---------------------------------------------------------------- helpers

    /** Catálogo indexado por slug (uma consulta, cacheada por request no CatalogRepo). */
    private function catalog(): array
    {
        $out = [];
        foreach (CatalogRepo::skills() as $row) {
            $out[(string)$row['slug']] = $row;
        }
        return $out;
    }

    /** Nível do jogador por slug de habilidade (0 quando ainda não aprendida). */
    private function playerLevels(int $uid): array
    {
        if ($uid <= 0) {
            return [];
        }
        $rows = Database::all(
            'SELECT s.slug, ps.level FROM player_skills ps JOIN skills s ON s.id = ps.skill_id WHERE ps.user_id = :u',
            ['u' => $uid]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string)$r['slug']] = (int)$r['level'];
        }
        return $out;
    }

    /** unlocked/total por categoria calculados sobre o progresso real do jogador. */
    private function categories(array $catalog, array $levels, array $chrome): array
    {
        $meta = [];
        foreach ($chrome as $c) {
            $meta[(string)$c['slug']] = $c;
        }
        $agg = [];
        foreach ($catalog as $slug => $row) {
            $cat = (string)$row['category'];
            $agg[$cat] ??= ['unlocked' => 0, 'total' => 0];
            $agg[$cat]['total']++;
            if ((int)($levels[$slug] ?? 0) > 0) {
                $agg[$cat]['unlocked']++;
            }
        }
        // ordem: a do data_mock primeiro, depois qualquer categoria extra vinda do banco
        $out = [];
        foreach ($meta as $slug => $c) {
            if (isset($agg[$slug])) {
                $out[] = [
                    'slug'     => $slug,
                    'label'    => (string)($c['label'] ?? mb_strtoupper($slug)),
                    'desc'     => (string)($c['desc'] ?? ''),
                    'icon'     => (string)($c['icon'] ?? 'star'),
                    'unlocked' => $agg[$slug]['unlocked'],
                    'total'    => $agg[$slug]['total'],
                ];
                unset($agg[$slug]);
            }
        }
        foreach ($agg as $slug => $a) {
            $out[] = [
                'slug' => $slug, 'label' => mb_strtoupper($slug), 'desc' => '', 'icon' => 'star',
                'unlocked' => $a['unlocked'], 'total' => $a['total'],
            ];
        }
        return $out;
    }

    private function categoryBySlug(array $cats, string $slug): array
    {
        foreach ($cats as $c) {
            if ($c['slug'] === $slug) {
                return $c;
            }
        }
        return [];
    }

    /** Matriz tiers x colunas no formato que a view espera. */
    private function tree(array $catalog, array $levels, string $cat): array
    {
        $rows = [];
        foreach ($catalog as $slug => $row) {
            if ((string)$row['category'] !== $cat) {
                continue;
            }
            $j = max(1, (int)$row['tier']) - 1;
            $i = max(1, (int)$row['col']) - 1;
            $cur = (int)($levels[$slug] ?? 0);
            $max = max(1, (int)$row['max_level']);
            $rows[$j][$i] = [
                'slug'      => $slug,
                'name'      => (string)$row['name'],
                'icon'      => (string)($row['icon'] ?? 'star'),
                'state'     => $cur >= $max ? 'unlocked' : ($cur > 0 ? 'progress' : 'locked'),
                'cur'       => min($cur, $max),
                'max'       => $max,
                'cost'      => (int)$row['cost'],
                'desc'      => (string)($row['description'] ?? ''),
                'available' => $this->available($catalog, $levels, $row),
                // o JS remonta o texto do PRÓXIMO NÍVEL com os mesmos dados do catálogo
                'effect'    => (string)($row['effect'] ?? ''),
                'step'      => $row['step'] === null ? '' : $this->fmtNumber((float)$row['step']),
                'unit'      => (string)($row['unit'] ?? ''),
                'image'     => (string)($row['image'] ?? ''),
            ];
        }
        ksort($rows);
        $out = [];
        foreach ($rows as $row) {
            ksort($row);
            $out[] = array_values($row);
        }
        return $out;
    }

    /** Algarismos romanos para os tiers presentes na categoria. */
    private function tiers(int $n): array
    {
        $roman = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII'];
        return array_slice($roman, 0, max(1, $n));
    }

    /** Um nó do tier 1 sempre pode ser aprendido; nos demais exige o nó acima na coluna. */
    private function available(array $catalog, array $levels, array $node): bool
    {
        if ((int)$node['tier'] <= 1) {
            return true;
        }
        foreach ($catalog as $slug => $row) {
            if ((string)$row['category'] === (string)$node['category']
                && (int)$row['tier'] === (int)$node['tier'] - 1
                && (int)$row['col'] === (int)$node['col']) {
                return (int)($levels[$slug] ?? 0) > 0;
            }
        }
        return true;
    }

    /** Pontos que voltariam ao redefinir a categoria (ou toda a árvore quando $cat é null). */
    private function refundable(array $catalog, array $levels, ?string $cat): int
    {
        $sum = 0;
        foreach ($catalog as $slug => $row) {
            if ($cat !== null && (string)$row['category'] !== $cat) {
                continue;
            }
            $sum += (int)$row['cost'] * (int)($levels[$slug] ?? 0);
        }
        return $sum;
    }

    /** Painel da direita: nó pedido em ?hab=, senão o primeiro em progresso, senão o primeiro nó. */
    private function detail(array $catalog, array $levels, string $cat, ?string $want, int $points, array $chrome): array
    {
        $inCat = [];
        foreach ($catalog as $slug => $row) {
            if ((string)$row['category'] === $cat) {
                $inCat[$slug] = $row;
            }
        }
        if ($inCat === []) {
            return [];
        }

        $node = ($want !== null && isset($inCat[$want])) ? $inCat[$want] : null;
        if ($node === null) {
            foreach ($inCat as $slug => $row) {          // primeiro parcialmente aprendido
                $lvl = (int)($levels[$slug] ?? 0);
                if ($lvl > 0 && $lvl < (int)$row['max_level']) { $node = $row; break; }
            }
        }
        if ($node === null) {
            foreach ($inCat as $slug => $row) {          // senão o primeiro ainda não aprendido
                if ((int)($levels[$slug] ?? 0) === 0) { $node = $row; break; }
            }
        }
        $node ??= reset($inCat);

        $slug = (string)$node['slug'];
        $cur  = (int)($levels[$slug] ?? 0);
        $max  = max(1, (int)$node['max_level']);
        $cost = (int)$node['cost'];
        $desc = (string)($node['description'] ?? '');
        $ok   = $this->available($catalog, $levels, $node);

        return [
            'slug'           => $slug,
            'name'           => (string)$node['name'],
            'level'          => min($cur, $max),
            'max'            => $max,
            'image'          => 'ui/skill-preview.jpg',
            'desc'           => $desc,
            'desc_highlight' => $this->firstNumber($desc),
            'next_title'     => 'PRÓXIMO NÍVEL',
            'next'           => $this->nextText($cur, $max),
            'next_highlight' => $this->firstNumber($this->nextText($cur, $max)),
            'cost_title'     => 'CUSTO',
            'cost'           => $cost,
            'cost_label'     => (string)($chrome['cost_suffix'] ?? 'PONTO(S) DE HABILIDADE'),
            'cta'            => ['label' => 'APRENDER HABILIDADE', 'key' => 'F'],
            'cta_hold'       => 'SEGURAR PARA APRENDER',
            'can_learn'      => $cur < $max && $ok && $points >= $cost,
            'available'      => $ok,
            'reason'         => $cur >= $max
                ? 'Nível máximo atingido.'
                : (!$ok ? 'Requer a habilidade do tier anterior nesta coluna.'
                    : ($points < $cost ? 'Pontos de habilidade insuficientes.' : '')),
        ];
    }

    /** Mesmo texto que o JS gera ao trocar de nó, para o servidor e o cliente não divergirem. */
    private function nextText(int $cur, int $max): string
    {
        if ($cur >= $max) {
            return 'Habilidade já está no nível máximo.';
        }
        return $cur === 0
            ? 'Desbloqueia o nível 1 de ' . $max . ' desta habilidade.'
            : 'Evolui para o nível ' . ($cur + 1) . ' de ' . $max . ' desta habilidade.';
    }

    private function firstNumber(string $text): string
    {
        return preg_match('/\d+(?:[.,]\d+)?%?/u', $text, $m) === 1 ? $m[0] : '';
    }

    /**
     * Quantas colunas a grade da árvore precisa: a fileira mais larga.
     * A view usa isto para fixar grid-template-columns, senão fileiras de tamanhos
     * diferentes desalinhavam os nós entre si.
     *
     * @param list<array<int,array<string,mixed>>> $tree
     */
    private function cols(array $tree): int
    {
        $max = 0;
        foreach ($tree as $row) {
            $max = max($max, count($row) ? max(array_keys($row)) + 1 : 0);
        }
        return max(1, $max);
    }

    /** Número por nível no formato pt-BR, sem casas decimais inúteis ("12" e não "12,0"). */
    private function fmtNumber(float $n): string
    {
        return abs($n - round($n)) < 0.0001 ? (string)(int)round($n) : number_format($n, 1, ',', '');
    }

    /**
     * Quantas habilidades o jogador tem com nível > 0 (na categoria, ou em todas).
     * Habilita REDEFINIR mesmo quando tudo aprendido custou 0 ponto — usar `refundable`
     * para isso deixava o botão morto com uma dica de ferramenta falsa.
     *
     * @param list<array<string,mixed>> $catalog @param array<string,int> $levels
     */
    private function learnedCount(array $catalog, array $levels, ?string $cat): int
    {
        $n = 0;
        foreach ($catalog as $row) {
            $slug = (string)($row['slug'] ?? '');
            if ($cat !== null && (string)($row['category'] ?? '') !== $cat) {
                continue;
            }
            if ((int)($levels[$slug] ?? 0) > 0) {
                $n++;
            }
        }
        return $n;
    }

    /** Volta para a árvore mantendo categoria e nó selecionado. */
    private function back(Request $request, string $cat, ?string $slug): Response
    {
        $path = 'habilidades' . ($cat !== '' ? '/' . $cat : '');
        return $this->redirect($path, $slug !== null && $slug !== '' ? ['hab' => $slug] : []);
    }
}
