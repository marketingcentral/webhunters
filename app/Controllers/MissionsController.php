<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\GameRules;
use App\Core\Request;
use App\Core\Response;
use App\Repo\CatalogRepo;
use App\Repo\OperationalRepo;

final class MissionsController extends Controller
{
    /** Estado do banco (PT ou EN) -> [status da view, rótulo]. */
    private const STATUS = [
        'active'      => ['active', 'EM ANDAMENTO'],
        'ativa'       => ['active', 'EM ANDAMENTO'],
        'available'   => ['available', 'DISPONÍVEL'],
        'disponivel'  => ['available', 'DISPONÍVEL'],
        'done'        => ['done', 'CONCLUÍDA'],
        'concluida'   => ['done', 'CONCLUÍDA'],
        'locked'      => ['locked', 'BLOQUEADA'],
        'bloqueada'   => ['locked', 'BLOQUEADA'],
    ];

    /** Texto de dificuldade -> nível de pips (1..4). */
    private const DIFFICULTY = ['FÁCIL' => 1, 'MÉDIA' => 2, 'DIFÍCIL' => 3, 'EXTREMO' => 4];

    /** Tipo de contrato -> rótulo exibido. `emergencia` = evento de mundo (PRD v3 §24). */
    private const TYPE_LABEL = ['principal' => 'PRINCIPAL', 'contrato' => 'CONTRATO', 'diaria' => 'DIÁRIA', 'emergencia' => 'EMERGÊNCIA'];

    /**
     * kind da recompensa -> ícone do sprite (xp tem tratamento próprio na view).
     * Reputação de facção, acesso e intel (PRD §24.8) usam glifos que já existem no sprite.
     */
    private const REWARD_ICON = [
        'xp' => 'xp', 'cash' => 'coin-cash', 'points' => 'coin-points', 'credits' => 'coin-credit',
        'rep_aegis' => 'sk-medal', 'rep_frontier' => 'sk-medal', 'access' => 'sk-exit-run', 'intel' => 'sk-network',
    ];

    /** Sufixo curto por kind (o texto do selo). */
    private const REWARD_SUFFIX = ['rep_aegis' => ' REP AEGIS', 'rep_frontier' => ' REP FRONTIER', 'access' => ' ACESSO', 'intel' => ' INTEL'];

    /** Categoria do item -> etiqueta curta da recompensa. */
    private const ITEM_LABEL = [
        'weapon' => 'FUZIL DE ASSALTO', 'med' => 'MÉDICO', 'key' => 'CHAVE', 'misc' => 'CONTÊINER',
        'armor' => 'BLINDAGEM', 'ammo' => 'MUNIÇÃO', 'equipment' => 'EQUIPAMENTO', 'container' => 'CONTÊINER',
    ];

    /** Ação por estado -> [rótulo do botão, destino ou null]. */
    private const CTA = [
        'active'    => ['CONTINUAR CONTRATO', 'lobby'],
        'available' => ['ACEITAR CONTRATO', 'lobby'],
        'done'      => ['RECOMPENSA RESGATADA', null],
        'locked'    => ['REQUISITOS NÃO CUMPRIDOS', null],
    ];

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $data = data_mock('missoes');                 // textos e rótulos de layout
        $data['contract'] = data_mock('inicio')['contract'] ?? null;

        $uid          = $this->userId();
        $resetSeconds = (int)($data['reset']['seconds'] ?? 0);
        $progLabels   = (array)($data['progress_labels'] ?? []);

        // Estado do jogador por missão (status, progresso, data de conclusão).
        $state = [];
        if ($uid > 0) {
            foreach (Database::all(
                'SELECT mission_id, status, progress, updated_at FROM player_missions WHERE user_id = :u',
                ['u' => $uid]
            ) as $r) {
                $state[(int)$r['mission_id']] = $r;
            }
        }

        // Quadro = missions (catálogo) LEFT JOIN player_missions do jogador.
        $items = [];
        foreach (CatalogRepo::missions() as $m) {
            $items[] = $this->buildCard($m, $state[(int)$m['id']] ?? null, $progLabels, $resetSeconds);
        }

        // Missão selecionada: a ativa em andamento; senão a primeira do quadro.
        $selected = $items[0]['slug'] ?? '';
        foreach ($items as $it) {
            if ($it['status'] === 'active') { $selected = $it['slug']; break; }
        }
        foreach ($items as &$it) { $it['selected'] = $it['slug'] === $selected; }
        unset($it);

        // Filtros por ORIGEM (PRD v3 §24.3) + estado do jogador.
        $counts = [
            'todas'       => count($items),
            'hunter'      => $this->countBy($items, static fn(array $i): bool => $i['origin'] === 'hunter_command'),
            'aegis'       => $this->countBy($items, static fn(array $i): bool => $i['origin'] === 'aegis'),
            'frontier'    => $this->countBy($items, static fn(array $i): bool => $i['origin'] === 'frontier'),
            'eventos'     => $this->countBy($items, static fn(array $i): bool => in_array($i['origin'], ['world', 'icarus'], true)),
            'diarias'     => $this->countBy($items, static fn(array $i): bool => $i['type'] === 'diaria'),
            'concluidas'  => $this->countBy($items, static fn(array $i): bool => $i['status'] === 'done'),
            'ativas'      => $this->countBy($items, static fn(array $i): bool => $i['status'] === 'active'),
            'disponiveis' => $this->countBy($items, static fn(array $i): bool => $i['status'] === 'available'),
            'requer'      => $this->countBy($items, static fn(array $i): bool => is_array($i['requirement']) && !$i['requirement']['met']),
        ];
        foreach ((array)($data['filters'] ?? []) as $k => $f) {
            $data['filters'][$k]['count'] = $counts[(string)$f['slug']] ?? 0;
        }
        $data['summary'] = [
            ['label' => 'ATIVAS',           'value' => $counts['ativas']],
            ['label' => 'DISPONÍVEIS',      'value' => $counts['disponiveis']],
            ['label' => 'REQUEREM CONTRATO','value' => $counts['requer']],
        ];
        $data['operational'] = $this->operational();

        $data['items'] = $items;

        $page = $this->page('missoes', 'Missões');
        return $this->render('missoes', $page, $data);
    }

    /**
     * Mapeia uma linha do catálogo + estado do jogador para as chaves da view.
     * @param array<string,mixed> $m      missão do catálogo (com rewards[])
     * @param array<string,mixed>|null $st linha de player_missions do jogador
     * @param array<string,string> $labels rótulos de progresso por slug
     * @return array<string,mixed>
     */
    private function buildCard(array $m, ?array $st, array $labels, int $resetSeconds): array
    {
        $slug  = (string)$m['slug'];
        $type  = (string)($m['type'] ?? 'contrato');
        $raw   = $st !== null ? (string)$st['status'] : (((int)($m['locked'] ?? 0)) === 1 ? 'locked' : 'available');
        [$status, $statusLabel] = self::STATUS[$raw] ?? ['available', 'DISPONÍVEL'];

        $curProgress = $st !== null ? (int)$st['progress'] : 0;
        $target      = (int)($m['target'] ?? 0);

        // Recompensas: moedas/xp no card; item vira reward_item.
        $rewards    = [];
        $rewardItem = [];
        foreach ((array)($m['rewards'] ?? []) as $r) {
            $kind   = (string)$r['kind'];
            $amount = (int)$r['amount'];
            if ($kind === 'item') {
                $itemId = (int)($r['item_id'] ?? 0);
                $item   = $itemId > 0 ? CatalogRepo::item($itemId) : null;
                if ($item !== null) {
                    $rewardItem = [
                        'name'  => mb_strtoupper((string)$item['name']),
                        'desc'  => self::ITEM_LABEL[(string)($item['category'] ?? '')] ?? mb_strtoupper((string)($item['category'] ?? '')),
                        'image' => (string)$item['image'],
                    ];
                }
                continue;
            }
            $rewards[] = [
                'type'  => $kind,
                'icon'  => self::REWARD_ICON[$kind] ?? $kind,
                'value' => $amount,
                'text'  => $kind === 'xp' ? '+' . num($amount) . ' XP' : '+' . num($amount) . (self::REWARD_SUFFIX[$kind] ?? ''),
            ];
        }

        [$ctaLabel, $ctaHref] = self::CTA[$status] ?? ['ACEITAR CONTRATO', 'lobby'];

        /*
         * Origem e requisito de facção (PRD v3 §24.2–24.5). Se a missão exige contrato que o
         * jogador não tem, o card diz isso e o CTA vira o caminho para resolver — VER CONTRATO
         * DA FACÇÃO — em vez de fingir que dá para aceitar.
         */
        $op       = $this->operational();
        $origin   = (string)($m['origin'] ?? 'hunter_command');
        $reqFac   = ($m['requires_faction'] ?? null) !== null ? strtolower((string)$m['requires_faction']) : null;
        $reqTier  = ($m['requires_tier'] ?? null) !== null ? (string)$m['requires_tier'] : null;
        $reqMet   = $reqFac === null
            || ($reqFac === 'any' && ($op['faction'] ?? null) !== null)
            || ($reqFac !== 'any' && OperationalRepo::meets($op, $reqFac, $reqTier));
        $requirement = null;
        if ($reqFac !== null) {
            $requirement = [
                'faction' => $reqFac === 'any' ? null : $reqFac,
                'tier'    => $reqTier,
                'met'     => $reqMet,
                'label'   => $reqFac === 'any'
                    ? 'REQUER CONTRATO MERCENÁRIO'
                    : ($reqTier !== null
                        ? (GameRules::faction($reqFac)['label'] ?? strtoupper($reqFac)) . ' — ' . mb_strtoupper($reqTier)
                        : 'REQUER CONTRATO ' . (GameRules::faction($reqFac)['label'] ?? strtoupper($reqFac))),
                'hint'    => $reqTier !== null ? mb_strtoupper($reqTier) . ' NECESSÁRIA' : null,
            ];
            if (!$reqMet && in_array($status, ['available', 'locked'], true)) {
                $statusLabel = 'REQUER CONTRATO';
                [$ctaLabel, $ctaHref] = $op['state'] === GameRules::STATE_HUNTER
                    ? ['ATUAR COMO MERCENÁRIO', 'faccoes']
                    : ['VER CONTRATO DA FACÇÃO', 'faccoes'];
            }
        }

        $card = [
            'slug'             => $slug,
            'level_req'        => (int)($m['level_req'] ?? 1),
            'title'            => (string)$m['name'],
            'faction'          => (string)($m['giver'] ?? ''),
            'origin'           => $origin,
            'origin_label'     => GameRules::origin($origin)['label'],
            'requirement'      => $requirement,
            'type'             => $type,
            'type_label'       => self::TYPE_LABEL[$type] ?? mb_strtoupper($type),
            'image'            => (string)($m['image'] ?? ''),
            'difficulty'       => (string)($m['difficulty'] ?? ''),
            'difficulty_level' => self::DIFFICULTY[(string)($m['difficulty'] ?? '')] ?? 1,
            'duration'         => ((int)($m['duration_min'] ?? 0)) . ' MIN',
            // Mundo persistente: não há "jogadores máx." — há a unidade recomendada (PRD §24.7)
            'squad'            => (int)($m['squad_min'] ?? 1) . '–' . (int)($m['squad_max'] ?? GameRules::SQUAD_MAX),
            'players'          => min((int)($m['players_max'] ?? GameRules::SQUAD_MAX), GameRules::SQUAD_MAX),
            'human_activity'   => (string)($m['human_activity'] ?? 'PROVÁVEL'),
            'region'           => (string)($m['region'] ?? ''),
            'status'           => $status,
            'status_label'     => $statusLabel,
            'objective'        => (string)($m['objective'] ?? ''),
            'objective_lines'  => $this->splitObjective((string)($m['objective'] ?? '')),
            'rewards'          => $rewards,
            'reward_item'      => $rewardItem,
            'cta'              => $ctaLabel,
            'cta_href'         => $ctaHref,
        ];

        if ($status === 'active') {
            $card['progress'] = [
                'label' => $labels[$slug] ?? 'PROGRESSO',
                'cur'   => $curProgress,
                'max'   => $target > 0 ? $target : max($curProgress, 1),
            ];
        }
        if ($status === 'done' && $st !== null && !empty($st['updated_at'])) {
            $card['completed_at'] = date('d/m/Y', strtotime((string)$st['updated_at']));
        }
        if ($status === 'locked') {
            $card['unlock_hint'] = 'Alcance o nível ' . (int)($m['level_req'] ?? 1) . ' para desbloquear este contrato.';
        }
        if ($type === 'diaria' && in_array($status, ['active', 'available'], true)) {
            $card['expires_in'] = $resetSeconds;
        }

        return $card;
    }

    /** Quebra o objetivo em linhas por frase (mantém o ritmo de duas linhas do layout). */
    private function splitObjective(string $objective): array
    {
        $objective = trim($objective);
        if ($objective === '') {
            return [];
        }
        $parts = preg_split('/(?<=\.)\s+/', $objective) ?: [$objective];
        $lines = array_values(array_filter(array_map('trim', $parts), static fn(string $s): bool => $s !== ''));
        return $lines !== [] ? $lines : [$objective];
    }

    /** @param list<array<string,mixed>> $items */
    private function countBy(array $items, callable $pred): int
    {
        $n = 0;
        foreach ($items as $i) { if ($pred($i)) { $n++; } }
        return $n;
    }
}
