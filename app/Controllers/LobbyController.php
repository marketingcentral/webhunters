<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\GameRules;
use App\Core\Request;
use App\Core\Response;
use App\Repo\CatalogRepo;

final class LobbyController extends Controller
{
    private const MAX_CHAT = 12;

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $data = data_mock('lobby');
        $data['news'] = data_mock('noticias')['items'] ?? [];
        $data = $this->comContextoOperacional($data);

        // Messages typed in this session are appended to the mock transcript.
        $extra = $_SESSION['lobby_chat'] ?? [];
        if (is_array($extra) && $extra !== []) {
            $data['chat'] = array_slice(array_merge($data['chat'] ?? [], $extra), -self::MAX_CHAT);
        }

        $page = $this->page('lobby', 'Lobby', [
            'bg'    => 'bg/lobby.jpg',
            'three' => ['atmosphere' => true, 'hero' => true],
        ]);
        return $this->render('lobby', $page, $data);
    }

    /**
     * Lobby Hunter no mundo persistente (PRD v3 §18).
     *
     *  - o card `self` é o jogador real: nome, nível, patente e função de OperationalRepo;
     *  - nunca mais de SQUAD_MAX operadores (o card de vaga completa o quarto);
     *  - COMANDO = maior patente presente (hierarquia, não coroa genérica);
     *  - o painel da operação lê a missão ativa do jogador (origem, região, ameaça Veyra e
     *    atividade humana vêm do banco); sem banco, fica o cromo.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function comContextoOperacional(array $data): array
    {
        $player = $this->player();
        $op     = $this->operational();

        /* ---- esquadrão ---- */
        $squad = array_values(array_filter((array)($data['squad'] ?? []), 'is_array'));
        foreach ($squad as &$m) {
            if (!empty($m['self'])) {
                $m['name']  = (string)($player['name'] ?? $m['name']);
                $m['level'] = (int)($player['level'] ?? $m['level']);
                $m['rank']  = (string)($op['rank'] ?? $m['rank'] ?? 'RECRUTA');
                $m['role']  = (string)($op['role'] ?? $m['role'] ?? 'ASSAULT');
                $m['portrait'] = (string)($player['avatar'] ?? $m['portrait']);
            }
            $m['role'] = GameRules::role($m['role'] ?? null) ?? 'ASSAULT';
            $m['leader'] = false;
        }
        unset($m);
        $squad = array_slice($squad, 0, GameRules::SQUAD_MAX);

        // COMANDO: quem tem a maior patente. Empate favorece o jogador (é o lobby dele).
        $lider = null; $melhor = -1;
        foreach ($squad as $i => $m) {
            $idx = GameRules::rankIndex((string)($m['rank'] ?? ''));
            if ($idx > $melhor || ($idx === $melhor && !empty($m['self']))) { $melhor = $idx; $lider = $i; }
        }
        if ($lider !== null) { $squad[$lider]['leader'] = true; }
        $data['squad']       = $squad;
        $data['max_players'] = GameRules::SQUAD_MAX;
        $data['operational'] = $op;

        /* ---- operação selecionada: missão ativa do jogador ---- */
        $uid = $this->userId();
        if ($uid > 0) {
            try {
                $m = Database::one(
                    "SELECT m.name, m.objective, m.region, m.origin, m.human_activity, m.squad_min, m.squad_max, m.difficulty, m.giver
                       FROM missions m JOIN player_missions pm ON pm.mission_id = m.id AND pm.user_id = :u
                      WHERE pm.status IN ('active','available')
                      ORDER BY (pm.status <> 'active'), m.sort_order LIMIT 1",
                    ['u' => $uid]
                );
            } catch (\Throwable) {
                $m = null;
            }
            if ($m !== null) {
                $origem = GameRules::origin($m['origin'] ?? null);
                $regiao = (string)($m['region'] ?? '');
                $mundo  = $this->estadoDaRegiao($regiao);
                $base   = CatalogRepo::governmentBases()[0] ?? ['short' => 'ALPHA'];
                foreach (CatalogRepo::governmentBases() as $b) { if ($b['slug'] === ($op['base_pref'] ?? '')) { $base = $b; } }

                $data['mission']['title']    = (string)$m['name'];
                $data['mission']['subtitle'] = $origem['label'] . ($op['state'] === GameRules::STATE_HUNTER ? ' · BASE ' . $base['short'] : '');
                $data['mission']['origin']   = (string)($m['origin'] ?? 'hunter_command');
                $data['mission']['objective_lines'] = preg_split('/(?<=[.!?])\s+/u', (string)$m['objective']) ?: [(string)$m['objective']];
                $data['mission']['stats'] = [
                    ['label' => 'ORIGEM',           'value' => $origem['label'], 'hot' => true],
                    ['label' => $op['state'] === GameRules::STATE_MERCENARY ? 'FACÇÃO' : 'BASE', 'value' => $op['state'] === GameRules::STATE_MERCENARY ? strtoupper((string)$op['faction']) : (string)$base['short'], 'hot' => false],
                    ['label' => 'AMEAÇA VEYRA',     'value' => (string)($mundo['veyra_activity'] ?? 'MODERADA'), 'hot' => true],
                    ['label' => 'ATIVIDADE HUMANA', 'value' => (string)($m['human_activity'] ?: ($mundo['human_activity'] ?? 'PROVÁVEL')), 'hot' => true],
                    ['label' => 'ESQUADRÃO',        'value' => (int)($m['squad_min'] ?? 1) . '–' . (int)($m['squad_max'] ?? GameRules::SQUAD_MAX), 'hot' => false],
                    ['label' => 'MUNDO',            'value' => 'PERSISTENTE', 'hot' => false],
                ];
                if (!empty($m['difficulty'])) {
                    foreach ($data['settings'] as &$s) { if (($s['key'] ?? '') === 'difficulty') { $s['value'] = (string)$m['difficulty']; } }
                    unset($s);
                }
                if (!empty($mundo['weather'])) {
                    foreach ($data['settings'] as &$s) { if (($s['key'] ?? '') === 'weather') { $s['value'] = strtoupper((string)$mundo['weather']); } }
                    unset($s);
                }
            }
        }

        // Sobrevivente não mobiliza esquadrão Hunter: o CTA diz a verdade.
        if ($op['state'] === GameRules::STATE_SURVIVOR) {
            $data['cta'] = 'SEM MOBILIZAÇÃO · SOBREVIVENTE';
            $data['cta_ready'] = false;
        } elseif ($op['state'] === GameRules::STATE_MERCENARY) {
            $data['cta'] = 'MOBILIZAR SOB CONTRATO';
        }
        return $data;
    }

    /** Estado de mundo da região pelo NOME que a missão guarda (maps.name). */
    private function estadoDaRegiao(string $nome): array
    {
        try {
            foreach (CatalogRepo::maps('region') as $r) {
                if (mb_strtoupper((string)$r['name']) === mb_strtoupper($nome)) { return CatalogRepo::regionState($r); }
            }
        } catch (\Throwable) {
            // sem banco
        }
        return [];
    }

    /** POST /lobby/chat: CSRF, validation, flash, redirect back to the lobby. */
    public function chat(Request $request, array $params = []): Response
    {
        $this->csrf($request);
        $message = preg_replace('/\s+/u', ' ', (string)$request->input('message', '')) ?? '';
        $message = trim(mb_substr($message, 0, 200));

        if ($message === '') {
            flash('error', 'Digite uma mensagem antes de enviar.');
            return $this->redirect('lobby');
        }

        $chat   = is_array($_SESSION['lobby_chat'] ?? null) ? $_SESSION['lobby_chat'] : [];
        $chat[] = [
            'author' => $this->player()['name'],
            'text'   => $message,
            'time'   => date('H:i'),
            'self'   => true,
        ];
        $_SESSION['lobby_chat'] = array_slice($chat, -self::MAX_CHAT);

        flash('ok', 'Mensagem enviada.');
        return $this->redirect('lobby');
    }
}
