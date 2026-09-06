<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repo\ChallengeRepo;
use App\Repo\PlayerRepo;

/**
 * Tela DESAFIOS (/desafios[/{aba}]).
 *
 * Tudo vem do banco, conciliado por current_user_id(): catálogo em `challenges` /
 * `challenge_bonuses` / `seasons`, progresso em `player_challenges` / `player_season`.
 * Os prazos são calculados a partir de DATETIME reais e entregues à view em SEGUNDOS,
 * para a contagem regressiva rodar de verdade no cliente.
 *
 * app/Data/desafios.php continua sendo a fonte dos RÓTULOS de layout (títulos, CTAs),
 * nunca dos números do jogador.
 */
final class ChallengesController extends Controller
{
    /** Aba -> [chave da lista na view, rota que o jogador usa para cumprir os objetivos]. */
    private const TABS = [
        'diarios'   => ['label' => 'DIÁRIOS',   'reset_label' => 'REINICIA EM', 'href' => 'jogar'],
        'semanais'  => ['label' => 'SEMANAIS',  'reset_label' => 'REINICIA EM', 'href' => 'jogar'],
        'temporada' => ['label' => 'TEMPORADA', 'reset_label' => 'TERMINA EM',  'href' => 'missoes'],
    ];

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $labels = data_mock('desafios');                       // só rótulos/CTAs de layout
        $active = (string)$this->pick($params['aba'] ?? null, array_keys(self::TABS), 'diarios');
        $uid    = $this->userId();

        $data = [
            'title'        => (string)($labels['title'] ?? 'DESAFIOS'),
            'intro'        => (string)($labels['intro'] ?? ''),
            'board_action' => (string)($labels['board_action'] ?? 'VER CONQUISTAS'),
            'bonus_cta'    => (string)($labels['bonus_cta'] ?? 'RESGATAR'),
            'active_tab'   => $active,
            'tabs'         => [],
            'groups'       => [],
            'bonus'        => [],
            'featured'     => [],
            'ring'         => [],
            'pass'         => [],
        ];

        // Banco é a fonte. Se ele estiver fora do ar a HUD continua navegável com os
        // rótulos estáticos (mesmo contrato de PlayerRepo em Controller::player()).
        try {
            $season  = ChallengeRepo::season();
            $board   = ChallengeRepo::board($uid);
            $bonuses = ChallengeRepo::bonuses();
            $claimed = ChallengeRepo::claimed($uid);
            $ps      = ChallengeRepo::playerSeason($uid, (int)($season['id'] ?? 0));
            $tierRows = ChallengeRepo::tiers((int)($season['id'] ?? 0));
            $eventSecs = ChallengeRepo::eventSeconds();
        } catch (\Throwable) {
            [$season, $board, $bonuses, $claimed, $ps, $tierRows, $eventSecs] = $this->offline($labels);
        }
        $data['tag'] = (string)($season['tag'] ?? ($labels['tag'] ?? ''));

        foreach (self::TABS as $key => $meta) {
            $g = $board[$key] ?? ['items' => [], 'done' => 0, 'total' => 0, 'seconds' => 0];

            $data['tabs'][] = [
                'key'         => $key,
                'label'       => $meta['label'],
                'count'       => $g['done'] . '/' . $g['total'],
                'reset_label' => $meta['reset_label'],
                'seconds'     => $g['seconds'],
            ];
            $data['groups'][$key] = ['items' => $g['items'], 'href' => $meta['href']];

            $b = $bonuses[$key] ?? null;
            if ($b !== null) {
                $ready = $g['total'] > 0 && $g['done'] >= $g['total'];
                $data['bonus'][$key] = [
                    'label'   => (string)$b['label'],
                    'title'   => (string)$b['title'],
                    'desc'    => (string)($b['description'] ?? ''),
                    'image'   => (string)($b['image'] ?? ''),
                    'cur'     => $g['done'],
                    'max'     => max(1, $g['total']),
                    'xp'      => (int)$b['xp'],
                    'credits' => (int)$b['credits'],
                    'ready'   => $ready && empty($claimed[$key]),
                    'claimed' => !empty($claimed[$key]),
                ];
            }
        }

        // Relógio do cabeçalho: segue a aba ativa.
        $data['reset_label'] = self::TABS[$active]['reset_label'];
        $data['reset_secs']  = (int)($board[$active]['seconds'] ?? 0);

        // Destaque: evento da temporada, com progresso real dos desafios de temporada.
        $ev = $board['temporada'] ?? ['done' => 0, 'total' => 0];
        $data['featured'] = [
            'tag'        => (string)($season['event_tag'] ?? ''),
            'title'      => (string)($season['event_title'] ?? ''),
            'desc'       => (string)($season['event_desc'] ?? ''),
            'image'      => (string)($season['event_image'] ?? ''),
            'ends_label' => (string)($labels['featured']['ends_label'] ?? 'TERMINA EM'),
            'ends_secs'  => $eventSecs,
            'meta_label' => (string)($labels['featured']['meta_label'] ?? 'PROGRESSO DO EVENTO'),
            'cur'        => (int)$ev['done'],
            'max'        => max(1, (int)$ev['total']),
            'href'       => (string)($labels['featured']['href'] ?? 'missoes'),
            'cta'        => (string)($labels['featured']['cta'] ?? 'VER MISSÕES DO EVENTO'),
        ];

        // Anel: derivado do MESMO quadro que a tela imprime (concluídos / total das três
        // abas). Ler week_done/week_goal cru deixaria o anel contradizendo a lista ao lado.
        [$ringDone, $ringTotal] = ChallengeRepo::boardTotals($board);
        $data['ring'] = [
            'title'       => (string)($labels['ring']['title'] ?? 'PROGRESSO SEMANAL'),
            'caption'     => (string)($labels['ring']['caption'] ?? 'DESAFIOS CONCLUÍDOS'),
            'done'        => $ringDone,
            'total'       => max(1, $ringTotal),
            'reset_label' => 'REINICIA EM',
            'reset_secs'  => (int)($board['semanais']['seconds'] ?? 0),
            'stats'       => [
                ['label' => 'XP DA SEMANA', 'value' => num((int)$ps['week_xp']), 'icon' => 'bolt', 'color' => 'lime'],
                ['label' => 'SEQUÊNCIA',    'value' => (int)$ps['streak_days'] . ' DIAS', 'icon' => 'local_fire_department', 'color' => 'amber'],
            ],
        ];

        // Passe de temporada do jogador.
        // O destaque "next" é o PRIMEIRO tier ainda não conquistado — não o primeiro da
        // lista: preso ao índice 0, o destaque sumiria assim que o jogador passasse dele.
        $tiers   = [];
        $level   = (int)$ps['pass_level'];
        $hasNext = false;
        foreach ($tierRows as $t) {
            $owned = (int)$t['level'] <= $level;
            $state = 'locked';
            if ($owned) {
                $state = 'owned';
            } elseif (!$hasNext) {
                $state   = 'next';
                $hasNext = true;
            }
            $tiers[] = [
                'level' => (int)$t['level'],
                'name'  => (string)$t['name'],
                'image' => (string)($t['image'] ?? ''),
                'state' => $state,
            ];
        }
        $data['pass'] = [
            'title'      => (string)($labels['pass']['title'] ?? 'PASSE DE TEMPORADA'),
            'name'       => (string)($season['pass_name'] ?? ''),
            'season'     => (string)($season['tag'] ?? ''),
            'image'      => (string)($season['pass_image'] ?? ''),
            'level'      => $level,
            'xp'         => (int)$ps['pass_xp'],
            'xp_next'    => max(1, (int)($season['xp_per_level'] ?? 10000)),
            'ends_label' => (string)($labels['pass']['ends_label'] ?? 'TERMINA EM'),
            'ends_secs'  => (int)($board['temporada']['seconds'] ?? 0),
            'next_label' => (string)($labels['pass']['next_label'] ?? 'PRÓXIMAS RECOMPENSAS'),
            'tiers'      => $tiers,
            'cta'        => (string)($labels['pass']['cta'] ?? 'VER PASSE COMPLETO'),
            'href'       => (string)($labels['pass']['href'] ?? 'loja'),
        ];

        $page = $this->page('desafios', 'Desafios', ['back' => ['label' => 'VOLTAR', 'href' => url('jogar')]]);
        return $this->render('desafios', $page, $data);
    }

    /**
     * Banco fora do ar: monta o MESMO formato a partir dos rótulos estáticos, para a HUD
     * continuar navegável. Só é usado nesse caso — o caminho normal é sempre o banco.
     *
     * @param array<string,mixed> $labels app/Data/desafios.php
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>,3:array<string,bool>,4:array<string,int>,5:list<array<string,mixed>>,6:int}
     */
    private function offline(array $labels): array
    {
        $season = [
            'id' => 0, 'tag' => (string)($labels['tag'] ?? ''),
            'pass_name' => (string)($labels['pass']['name'] ?? ''),
            'pass_image' => (string)($labels['pass']['image'] ?? ''),
            'xp_per_level' => (int)($labels['pass']['xp_next'] ?? 10000),
            'event_tag' => (string)($labels['featured']['tag'] ?? ''),
            'event_title' => (string)($labels['featured']['title'] ?? ''),
            'event_desc' => (string)($labels['featured']['desc'] ?? ''),
            'event_image' => (string)($labels['featured']['image'] ?? ''),
        ];

        $lists = ['diarios' => 'daily', 'semanais' => 'weekly', 'temporada' => 'event'];
        $secs  = ['diarios' => 12 * 3600, 'semanais' => 4 * 86400, 'temporada' => 18 * 86400];
        $board = [];
        foreach ($lists as $scope => $key) {
            $items = [];
            $done  = 0;
            foreach ((array)($labels[$key] ?? []) as $c) {
                $max = max(1, (int)($c['max'] ?? 1));
                $cur = min($max, max(0, (int)($c['cur'] ?? 0)));
                $isDone = $cur >= $max;
                $done  += $isDone ? 1 : 0;
                $items[] = [
                    'slug' => (string)($c['slug'] ?? ''), 'label' => (string)($c['label'] ?? ''),
                    'desc' => (string)($c['desc'] ?? ''), 'icon' => (string)($c['icon'] ?? 'flag'),
                    'cur' => $cur, 'max' => $max, 'pct' => (int)round($cur / $max * 100),
                    'xp' => (int)($c['xp'] ?? 0), 'credits' => (int)($c['credits'] ?? 0),
                    'hot' => !empty($c['hot']), 'done' => $isDone,
                ];
            }
            $board[$scope] = ['items' => $items, 'done' => $done, 'total' => count($items), 'seconds' => $secs[$scope]];
        }

        $bonuses = [];
        foreach ((array)($labels['bonus'] ?? []) as $scope => $b) {
            $bonuses[(string)$scope] = [
                'scope' => (string)$scope, 'label' => (string)($b['label'] ?? ''), 'title' => (string)($b['title'] ?? ''),
                'description' => (string)($b['desc'] ?? ''), 'image' => (string)($b['image'] ?? ''),
                'xp' => (int)($b['xp'] ?? 0), 'credits' => (int)($b['credits'] ?? 0),
            ];
        }

        $ps = [
            'pass_level' => (int)($labels['pass']['level'] ?? 0),
            'pass_xp'    => (int)($labels['pass']['xp'] ?? 0),
            'week_done'  => (int)($labels['ring']['done'] ?? 0),
            'week_goal'  => (int)($labels['ring']['total'] ?? 12),
            'week_xp'    => 0,
            'streak_days' => 0,
        ];

        $tiers = [];
        foreach ((array)($labels['pass']['tiers'] ?? []) as $t) {
            $tiers[] = ['level' => (int)($t['level'] ?? 0), 'name' => (string)($t['name'] ?? ''), 'image' => (string)($t['image'] ?? '')];
        }

        return [$season, $board, $bonuses, [], $ps, $tiers, 3 * 86400];
    }

    /** Resgata o bônus da aba (POST /desafios/resgatar). */
    public function claim(Request $request, array $params = []): Response
    {
        $this->csrf($request);
        $scope = (string)$request->input('escopo', '');
        if (!in_array($scope, ChallengeRepo::SCOPES, true)) {
            flash('error', 'Aba de desafios desconhecida.');
            return $this->redirect('desafios');
        }

        $result = ChallengeRepo::claimBonus($this->userId(), $scope);
        if (($result['ok'] ?? false) === true) {
            flash('ok', 'Bônus resgatado: +' . num((int)$result['xp']) . ' XP e +' . num((int)$result['credits']) . ' créditos.');
        } else {
            flash('error', (string)($result['error'] ?? 'Não foi possível resgatar o bônus.'));
        }

        // Volta para a aba de onde o jogador resgatou.
        return $this->redirect('desafios/' . $scope);
    }
}
