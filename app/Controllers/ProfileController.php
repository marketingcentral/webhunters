<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Tela PERFIL — dados conciliados por user_id (Fase 2). O layout (títulos, labels, células
 * estáticas, insígnias) continua em app/Data/perfil.php; aqui trocamos a fonte dos blocos
 * dinâmicos pelo banco, preservando exatamente as chaves que a view espera:
 *   - estatísticas reais (K/D, extrações, partidas, abates)   -> player_matches (PlayerRepo::match_stats)
 *   - conquistas recentes                                     -> player_achievements concluídas × achievements
 *   - armas favoritas                                         -> itens de arma do inventário × catálogo
 *   - nível/xp/moedas/classe/facção/patente/retrato           -> PlayerRepo
 *   - atividade recente                                       -> player_matches + player_achievements + player_purchases
 */
final class ProfileController extends Controller
{
    /** Rótulo do tipo de arma a partir do subtype do catálogo. */
    private const WEAPON_TYPE = [
        'assault_rifle' => 'FUZIL DE ASSALTO',
        'carbine'       => 'CARABINA',
        'smg'           => 'SUBMETRALHADORA',
        'sniper'        => 'FUZIL DE PRECISÃO',
        'marksman'      => 'ATIRADOR DESIGNADO',
        'shotgun'       => 'ESPINGARDA',
        'lmg'           => 'METRALHADORA',
        'pistol'        => 'PISTOLA',
    ];

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $data   = data_mock('perfil');   // layout: títulos, labels, células estáticas
        $player = $this->player();        // banco (PlayerRepo), conciliado por user_id
        $uid    = $this->userId();

        $ms          = (array)($player['match_stats'] ?? []);
        $matches     = (int)($ms['matches'] ?? 0);
        $kills       = (int)($ms['kills'] ?? 0);
        $extractions = (int)($ms['extractions'] ?? 0);
        $deaths      = (int)($ms['deaths'] ?? 0);

        // --- cabeçalho / retrato / patente (PlayerRepo) ---------------------
        $data['portrait']     = (string)($player['avatar'] ?? ($data['portrait'] ?? 'portraits/op-assault.jpg'));
        $faction              = mb_strtoupper((string)($player['clan'] ?? ''));
        $rank                 = mb_strtoupper((string)($player['rank'] ?? ($data['faction_rank'] ?? '')));
        $data['faction_rank'] = $rank;

        $joined = $uid > 0 ? (string)Database::value('SELECT created_at FROM users WHERE id = :u', ['u' => $uid]) : '';
        $joinedFmt = $joined !== '' ? date('d/m/Y', strtotime($joined) ?: time()) : (string)($data['joined'] ?? '');

        // --- cartão do operador --------------------------------------------
        $data['operator']['class'] = (string)($player['class'] ?? ($data['operator']['class'] ?? 'ASSALTO'));
        $data['operator']['meta']  = [
            ['label' => 'FACÇÃO',         'value' => $faction !== '' ? $faction : '—', 'icon' => 'handshake',     'color' => 'lime'],
            ['label' => 'PATENTE',        'value' => $rank !== '' ? $rank : '—',       'icon' => 'military_tech', 'color' => 'gold'],
            ['label' => 'TEMPO DE JOGO',  'value' => (string)($data['playtime'] ?? ''), 'icon' => 'schedule',     'color' => ''],
            ['label' => 'EM CAMPO DESDE', 'value' => $joinedFmt,                        'icon' => 'flag',         'color' => ''],
        ];

        // --- estatísticas (4 métricas reais de player_matches) --------------
        $kd         = $deaths > 0 ? $kills / $deaths : (float)$kills;
        $successPct = $matches > 0 ? (int)round($extractions / $matches * 100) : 0;
        $kpm        = $matches > 0 ? $kills / $matches : 0.0;
        $metricMap  = [
            'kd'          => ['n' => round($kd, 1), 'sub' => num($kills) . ' ABATES / ' . num($deaths) . ' MORTES'],
            'extractions' => ['n' => $extractions,  'sub' => $successPct . '% DE SUCESSO'],
            'matches'     => ['n' => $matches,      'sub' => num($kills) . ' ABATES NO TOTAL'],
            'wins'        => ['n' => $extractions,  'sub' => $successPct . '% DAS PARTIDAS'],
            'kills'       => ['n' => $kills,        'sub' => num($kpm, 1) . ' POR PARTIDA'],
        ];
        $data['stats_meta'] = 'TEMPORADA ' . str_pad((string)(int)($player['season_level'] ?? 0), 2, '0', STR_PAD_LEFT);
        $data['stats'] = array_map(static function (array $s) use ($metricMap): array {
            $key = (string)($s['metric'] ?? '');
            if ($key !== '' && isset($metricMap[$key])) {
                $s['n']   = $metricMap[$key]['n'];
                $s['sub'] = $metricMap[$key]['sub'];
            }
            unset($s['metric']);
            return $s;
        }, (array)($data['stats'] ?? []));

        // --- conquistas recentes (player_achievements concluídas) ----------
        if ($uid > 0) {
            $achRows = Database::all(
                'SELECT a.slug, a.name, a.description, a.icon, pa.completed_at,
                        (SELECT r.amount FROM achievement_rewards r
                          WHERE r.achievement_id = a.id AND r.kind = "xp" LIMIT 1) AS xp
                 FROM player_achievements pa
                 JOIN achievements a ON a.id = pa.achievement_id
                 WHERE pa.user_id = :u AND pa.completed_at IS NOT NULL
                 ORDER BY pa.completed_at DESC, a.sort_order
                 LIMIT 3',
                ['u' => $uid]
            );
            if ($achRows !== []) {
                $palette = ['lime', 'amber', 'gold'];
                $data['achievements'] = [];
                foreach ($achRows as $i => $r) {
                    $data['achievements'][] = [
                        'slug'  => (string)$r['slug'],
                        'label' => mb_strtoupper((string)$r['name']),
                        'desc'  => (string)($r['description'] ?? ''),
                        'icon'  => (string)($r['icon'] ?? 'star'),
                        'color' => $palette[$i % count($palette)],
                        'date'  => date('d/m/Y', strtotime((string)$r['completed_at']) ?: time()),
                        'xp'    => (int)($r['xp'] ?? 0),
                    ];
                }
            }
        }

        // --- armas favoritas (itens de arma do inventário) -----------------
        if ($uid > 0) {
            $wRows = Database::all(
                "SELECT it.slug, it.name, it.subtype, it.ammo_caliber, it.image
                 FROM player_items pi
                 JOIN items it ON it.id = pi.item_id
                 WHERE pi.user_id = :u AND it.category = 'weapon'
                 ORDER BY pi.id
                 LIMIT 2",
                ['u' => $uid]
            );
            if ($wRows !== []) {
                $n       = count($wRows);
                $weights = $n === 2 ? [0.62, 0.38] : array_fill(0, $n, 1.0 / $n);
                $accDef  = [43, 38, 35];
                $data['weapons'] = [];
                foreach ($wRows as $i => $w) {
                    $wKills = (int)round($kills * ($weights[$i] ?? 1.0 / $n));
                    $usage  = $kills > 0 ? (int)round($wKills / $kills * 100) : 0;
                    $sub    = (string)($w['subtype'] ?? '');
                    $data['weapons'][] = [
                        'slug'     => (string)$w['slug'],
                        'name'     => (string)$w['name'],
                        'type'     => self::WEAPON_TYPE[$sub] ?? ($sub !== '' ? mb_strtoupper($sub) : 'ARMA'),
                        'img'      => (string)($w['image'] ?? 'items/ak103.png'),
                        'caliber'  => (string)($w['ammo_caliber'] ?? ''),
                        'kills'    => $wKills,
                        'accuracy' => $accDef[$i] ?? 30,
                        'usage'    => $usage,
                        'main'     => $i === 0,
                    ];
                }
            }
        }

        // --- classes: layout; destaca a classe atual do jogador ------------
        $pClass = mb_strtoupper((string)($player['class'] ?? ''));
        $classes = (array)($data['classes'] ?? []);
        $anyMain = false;
        foreach ($classes as $k => $c) {
            $isMain = $pClass !== '' && mb_strtoupper((string)($c['label'] ?? '')) === $pClass;
            $classes[$k]['main'] = $isMain;
            $anyMain = $anyMain || $isMain;
        }
        if (!$anyMain && $classes !== []) {
            $classes[array_key_first($classes)]['main'] = true;
        }
        $data['classes'] = $classes;

        // --- atividade recente (matches + achievements + purchases) --------
        $recent = $this->recentActivity($uid);
        if ($recent !== []) {
            $data['recent'] = $recent;
        }

        $page = $this->page('perfil', 'Perfil', ['nav' => 'perfil']);
        return $this->render('perfil', $page, $data);
    }

    /**
     * Feed unificado: partidas, conquistas concluídas e compras do jogador, ordenados por data.
     * @return list<array<string,mixed>>
     */
    private function recentActivity(int $uid): array
    {
        if ($uid <= 0) {
            return [];
        }
        $events = [];

        foreach (Database::all('SELECT result, kills, played_at FROM player_matches WHERE user_id = :u ORDER BY played_at DESC LIMIT 5', ['u' => $uid]) as $m) {
            $result = (string)$m['result'];
            if ($result === 'extraido') {
                $events[] = ['dt' => (string)$m['played_at'], 'type' => 'extraction', 'icon' => 'flag', 'color' => 'lime',
                    'text' => 'Extração bem-sucedida (' . (int)$m['kills'] . ' abates)'];
            } elseif ($result === 'abatido') {
                $events[] = ['dt' => (string)$m['played_at'], 'type' => 'death', 'icon' => 'skull', 'color' => 'red',
                    'text' => 'Abatido em combate'];
            } else {
                $events[] = ['dt' => (string)$m['played_at'], 'type' => 'match', 'icon' => 'sports_esports', 'color' => '',
                    'text' => 'Fuga registrada em partida'];
            }
        }

        foreach (Database::all(
            'SELECT a.name, pa.completed_at FROM player_achievements pa
             JOIN achievements a ON a.id = pa.achievement_id
             WHERE pa.user_id = :u AND pa.completed_at IS NOT NULL
             ORDER BY pa.completed_at DESC, a.sort_order LIMIT 3',
            ['u' => $uid]
        ) as $a) {
            $events[] = ['dt' => (string)$a['completed_at'], 'type' => 'achievement', 'icon' => 'military_tech', 'color' => 'gold',
                'text' => 'Conquista desbloqueada: ' . mb_strtoupper((string)$a['name'])];
        }

        foreach (Database::all(
            'SELECT it.name, pp.bought_at FROM player_purchases pp
             JOIN items it ON it.id = pp.item_id
             WHERE pp.user_id = :u ORDER BY pp.bought_at DESC LIMIT 3',
            ['u' => $uid]
        ) as $p) {
            $events[] = ['dt' => (string)$p['bought_at'], 'type' => 'purchase', 'icon' => 'shopping_cart', 'color' => 'cyan',
                'text' => 'Comprou ' . mb_strtoupper((string)$p['name'])];
        }

        usort($events, static fn(array $x, array $y): int => strcmp((string)$y['dt'], (string)$x['dt']));
        $events = array_slice($events, 0, 5);

        return array_map(static function (array $ev): array {
            return [
                'type'  => $ev['type'],
                'icon'  => $ev['icon'],
                'text'  => $ev['text'],
                'time'  => date('d/m/Y H:i', strtotime((string)$ev['dt']) ?: time()),
                'color' => $ev['color'],
            ];
        }, $events);
    }
}
