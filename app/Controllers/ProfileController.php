<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\GameRules;
use App\Repo\CatalogRepo;
use App\Repo\OperationalRepo;
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

        // --- cabeçalho / retrato / estado operacional (PRD v3 §21) ----------
        // Resistência saiu: a organização é o Hunter Command (ou a facção contratada, ou
        // nenhuma, no caso do Sobrevivente). Patente e função vêm de OperationalRepo.
        $op   = $this->operational();
        $data['portrait']     = (string)($player['avatar'] ?? ($data['portrait'] ?? 'portraits/op-assault.jpg'));
        $faction              = OperationalRepo::organization($op);
        $rank                 = (string)($op['rank'] ?? GameRules::RANKS[0]);
        $role                 = (string)($op['role'] ?? ($player['class'] ?? 'ASSAULT'));
        $data['faction_rank'] = $rank;
        $data['organization'] = $faction;
        $data['operational']  = $op;
        $data['reputation']   = $op['faction'] !== null ? (int)($op['reputation'][$op['faction']] ?? 0) : 0;
        $data['reputation_of'] = $op['faction'] !== null ? (GameRules::faction($op['faction'])['label'] ?? '') : '';
        $data['contract_block'] = $this->contractBlock($op);
        $data['stats_tabs']     = $this->statsTabs($op, $matches, $kills, $extractions, $deaths);

        $joined = $uid > 0 ? (string)Database::value('SELECT created_at FROM users WHERE id = :u', ['u' => $uid]) : '';
        $joinedFmt = $joined !== '' ? date('d/m/Y', strtotime($joined) ?: time()) : (string)($data['joined'] ?? '');

        // --- cartão do operador --------------------------------------------
        $roleKey = GameRules::role($role) ?? 'ASSAULT';
        $data['operator']['class']      = GameRules::ROLES[$roleKey]['label'];
        $data['operator']['class_icon'] = GameRules::ROLES[$roleKey]['icon'];
        $estado = match ($op['state']) {
            GameRules::STATE_MERCENARY => ['v' => 'MERCENÁRIO', 'c' => GameRules::faction($op['faction'])['color'] ?? 'cyan'],
            GameRules::STATE_SURVIVOR  => ['v' => 'SOBREVIVENTE', 'c' => 'white'],
            default                    => ['v' => 'HUNTER', 'c' => 'lime'],
        };
        $basePref = null;
        foreach (CatalogRepo::governmentBases() as $b) { if ($b['slug'] === ($op['base_pref'] ?? '')) { $basePref = $b; } }
        $data['operator']['meta']  = [
            ['label' => 'STATUS',          'value' => $estado['v'],                      'icon' => 'shield_person', 'color' => $estado['c']],
            ['label' => 'ORGANIZAÇÃO',     'value' => $faction,                          'icon' => 'handshake',     'color' => $op['state'] === GameRules::STATE_SURVIVOR ? 'white' : 'lime'],
            ['label' => 'PATENTE',         'value' => $rank,                             'icon' => 'military_tech', 'color' => 'gold'],
            ['label' => 'FUNÇÃO',          'value' => GameRules::ROLES[$roleKey]['label'], 'icon' => GameRules::ROLES[$roleKey]['ms'], 'color' => ''],
            ['label' => 'BASE PREFERENCIAL','value' => $basePref !== null ? 'BASE ' . $basePref['short'] : '—', 'icon' => 'home_work', 'color' => ''],
            ['label' => 'TEMPO DE JOGO',   'value' => (string)($data['playtime'] ?? ''), 'icon' => 'schedule',      'color' => ''],
            ['label' => 'EM CAMPO DESDE',  'value' => $joinedFmt,                        'icon' => 'flag',          'color' => ''],
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

        // --- funções Hunter (PRD v3 §21.5): as QUATRO oficiais, sempre nesta ordem; a do jogador
        //     em destaque. Nível/maestria por função continuam vindo do cromo até existir backend.
        $mock = [];
        foreach ((array)($data['classes'] ?? []) as $c) { $mock[GameRules::role($c['label'] ?? null) ?? ''] = $c; }
        $classes = [];
        foreach (GameRules::ROLES as $key => $r) {
            $m = $mock[$key] ?? [];
            $classes[] = [
                'slug'  => strtolower($key),
                'label' => $r['label'],
                'icon'  => $r['icon'],
                'desc'  => $r['desc'],
                'level' => (int)($m['level'] ?? 0),
                'pct'   => (int)($m['pct'] ?? 0),
                'main'  => $key === $roleKey,
            ];
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
     * Bloco CONTRATO MERCENÁRIO + reputação das duas facções (PRD v3 §21.3–21.4).
     * A facção sem contrato mostra estado coerente: SEM CONTRATO ou BLOQUEADO hh:mm:ss.
     *
     * @param array<string,mixed> $op
     * @return array<string,mixed>
     */
    private function contractBlock(array $op): array
    {
        $ativa = $op['faction'] ?? null;
        $facs  = [];
        foreach (GameRules::FACTIONS as $slug => $f) {
            $rep    = (int)($op['reputation'][$slug] ?? 0);
            $locked = $op['locked'][$slug] ?? null;
            $isLock = $locked !== null && strtotime((string)$locked) > time();
            $facs[] = [
                'slug'      => $slug,
                'label'     => $f['label'],
                'color'     => $f['color'],
                'rep'       => $rep,
                'tier'      => $ativa === $slug || $rep > 0 ? GameRules::repTier($rep) : 'SEM CONTRATO',
                'active'    => $ativa === $slug,
                'locked'    => $isLock,
                'locked_until' => $isLock ? (string)$locked : null,
                'state'     => $ativa === $slug ? 'CONTRATO ATIVO' : ($isLock ? 'BLOQUEADO' : 'SEM CONTRATO'),
            ];
        }
        return [
            'title'    => 'CONTRATO MERCENÁRIO',
            'none'     => 'NENHUM CONTRATO ATIVO',
            'active'   => $ativa !== null ? GameRules::faction($ativa) + ['rep' => (int)($op['reputation'][$ativa] ?? 0), 'tier' => (string)($op['tiers'][$ativa] ?? '')] : null,
            'survivor' => $op['state'] === GameRules::STATE_SURVIVOR,
            'factions' => $facs,
            'cta'      => ['benefits' => 'VER BENEFÍCIOS', 'manage' => 'GERENCIAR CONTRATO', 'href' => 'faccoes'],
        ];
    }

    /**
     * Estatísticas por estado (PRD v3 §21.6): GERAL · HUNTER · MERCENÁRIO · SOBREVIVENTE · PVP ·
     * VEYRA. O que player_matches sabe medir vem real; o resto é marcado `dep` (depende de
     * backend) e aparece como "—", nunca como número inventado.
     *
     * @return list<array<string,mixed>>
     */
    private function statsTabs(array $op, int $matches, int $kills, int $extractions, int $deaths): array
    {
        $dep = static fn(string $label, string $icon = 'pending'): array => ['label' => $label, 'n' => null, 'sub' => 'DEPENDE DO BACKEND', 'icon' => $icon, 'dep' => true];
        $pct = $matches > 0 ? (int)round($extractions / $matches * 100) : 0;
        return [
            ['slug' => 'hunter', 'label' => 'HUNTER', 'items' => [
                ['label' => 'OPERAÇÕES CONCLUÍDAS', 'n' => $matches,     'sub' => 'MUNDO PERSISTENTE', 'icon' => 'flag', 'color' => 'lime'],
                ['label' => 'EXTRAÇÕES',            'n' => $extractions, 'sub' => $pct . '% DE SUCESSO',  'icon' => 'logout', 'color' => 'lime'],
                $dep('ORDENS CUMPRIDAS', 'assignment_turned_in'),
                $dep('ALIADOS REVIVIDOS', 'medical_services'),
                $dep('INFRAÇÕES', 'gavel'),
            ]],
            ['slug' => 'mercenario', 'label' => 'MERCENÁRIO', 'items' => [
                ['label' => 'REP AEGIS',    'n' => (int)($op['reputation']['aegis'] ?? 0),    'sub' => (string)($op['tiers']['aegis'] ?? 'SEM CONTRATO'),    'icon' => 'shield', 'color' => 'cyan'],
                ['label' => 'REP FRONTIER', 'n' => (int)($op['reputation']['frontier'] ?? 0), 'sub' => (string)($op['tiers']['frontier'] ?? 'SEM CONTRATO'), 'icon' => 'explore', 'color' => 'amber'],
                $dep('CONTRATOS AEGIS', 'handshake'),
                $dep('CONTRATOS FRONTIER', 'handshake'),
                $dep('MISSÕES DE FACÇÃO', 'assignment'),
            ]],
            ['slug' => 'sobrevivente', 'label' => 'SOBREVIVENTE', 'items' => [
                $dep('ENTRADAS NO ESTADO', 'person_off'),
                $dep('MAIOR TEMPO SOBREVIVIDO', 'timer'),
                $dep('RECURSOS ARMAZENADOS EM CAMPO', 'inventory_2'),
            ]],
            ['slug' => 'pvp', 'label' => 'PVP', 'items' => [
                ['label' => 'OPERADORES ELIMINADOS', 'n' => $kills,  'sub' => 'ENCONTROS HOSTIS', 'icon' => 'swords', 'color' => 'red'],
                ['label' => 'MORTES PARA OPERADORES', 'n' => $deaths, 'sub' => 'MUNDO PERSISTENTE', 'icon' => 'skull', 'color' => ''],
                $dep('ENCONTROS HOSTIS', 'radar'),
            ]],
            ['slug' => 'veyra', 'label' => 'VEYRA', 'items' => [
                $dep('INFECTADOS ABATIDOS', 'pest_control'),
                $dep('MATRIZES DESTRUÍDAS', 'local_fire_department'),
                $dep('AMÁLGAMAS ELIMINADOS', 'skull'),
            ]],
        ];
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
