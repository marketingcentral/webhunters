<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repo\ChallengeRepo;

/**
 * Tela INÍCIO.
 *
 * app/Data/inicio.php segue como fonte do CROMO (títulos dos painéis, CTAs, destinos e o
 * carrossel de notícias). O que é progresso do jogador vem do banco, conciliado por user_id:
 *   - passe de temporada -> seasons + season_tiers + player_season
 *   - contrato ativo     -> missions + player_missions + mission_rewards
 * Antes esses dois blocos eram fixos e mostravam nível 45 / 2450 XP e um contrato 3/8
 * inventado, mesmo para um jogador nível 0.
 */
final class HomeController extends Controller
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $data = data_mock('inicio');
        $data['news'] = array_slice(data_mock('noticias')['items'] ?? [], 0, 3);

        $uid = $this->userId();
        $data['season']   = $this->season((array)($data['season'] ?? []), $uid);
        $data['contract'] = $this->contract((array)($data['contract'] ?? []), $uid);

        $page = $this->page('inicio', 'Início', [
            'bg'     => 'bg/home-hero-wide.jpg',
            'bg_pos' => 'center',
            // hero 3D desligado nesta tela: ver app/Views/pages/inicio.php
            'three'  => ['atmosphere' => true, 'hero' => false],
        ]);
        return $this->render('inicio', $page, $data);
    }

    /* ================================================================== temporada */

    /**
     * Passe de temporada do jogador. $chrome traz os rótulos fixos (title, href);
     * nível, XP, tag e prazo saem do banco.
     *
     * @param array<string,mixed> $chrome
     * @return array<string,mixed>
     */
    private function season(array $chrome, int $uid): array
    {
        $season = ChallengeRepo::season();
        if ($season === null) {
            return $chrome;                                  // catálogo ainda não populado
        }
        $ps      = ChallengeRepo::playerSeason($uid, (int)$season['id']);
        $nivel   = (int)($ps['pass_level'] ?? 0);
        $porNivel = max(1, (int)($season['xp_per_level'] ?? 10000));

        return [
            'title'    => (string)($chrome['title'] ?? 'PASSE DE TEMPORADA'),
            'subtitle' => (string)($season['tag'] ?? ''),
            'href'     => (string)($chrome['href'] ?? 'loja'),
            'level'    => $nivel,
            'xp'       => (int)($ps['pass_xp'] ?? 0),
            'xp_next'  => $porNivel,
            'image'    => (string)($season['pass_image'] ?? $chrome['image'] ?? 'ui/season-pass.jpg'),
            // assets/js/pages/inicio.js faz Date.parse: precisa de ISO-8601, não do DATETIME cru
            'ends_at'  => $this->iso((string)($season['ends_at'] ?? '')),
            'rewards'  => $this->seasonRewards((int)$season['id'], $nivel, (array)($chrome['rewards'] ?? [])),
        ];
    }

    /**
     * Próximas recompensas do passe (as que ainda estão à frente do jogador). O selo usa a
     * raridade do PATAMAR; se ele não declarar uma, cai na raridade do item ligado.
     *
     * @param array<int,mixed> $fallback
     * @return list<array<string,mixed>>
     */
    private function seasonRewards(int $seasonId, int $nivel, array $fallback): array
    {
        $rows = Database::all(
            'SELECT st.level, st.name, st.image, COALESCE(st.rarity, it.rarity) AS rarity
               FROM season_tiers st
               LEFT JOIN items it ON it.id = st.item_id
              WHERE st.season_id = :s
              ORDER BY (st.level <= :n), st.level, st.sort_order
              LIMIT 3',
            ['s' => $seasonId, 'n' => $nivel]
        );
        if ($rows === []) {
            return array_values($fallback);
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'level' => (int)$r['level'],
                'name'  => (string)$r['name'],
                'tier'  => $this->rarityLabel((string)($r['rarity'] ?? '')),
                'image' => (string)($r['image'] ?? 'ui/season-pass.jpg'),
                'pos'   => '50% 12%',
            ];
        }
        return $out;
    }

    /** Slug de raridade -> rótulo exibido ('epico' guarda-se sem acento no banco). */
    private function rarityLabel(string $slug): string
    {
        return [
            'comum' => 'COMUM', 'incomum' => 'INCOMUM', 'raro' => 'RARO',
            'epico' => 'ÉPICO', 'lendario' => 'LENDÁRIO',
        ][$slug] ?? mb_strtoupper($slug);
    }

    /* =================================================================== contrato */

    /**
     * Contrato ativo = a missão em andamento do jogador (ou, na falta, a próxima
     * disponível). $chrome traz título do painel, CTA e destino.
     *
     * @param array<string,mixed> $chrome
     * @return array<string,mixed>
     */
    private function contract(array $chrome, int $uid): array
    {
        $m = Database::one(
            "SELECT m.id, m.name, m.objective, m.target, pm.status, pm.progress
               FROM missions m
               JOIN player_missions pm ON pm.mission_id = m.id AND pm.user_id = :u
              WHERE pm.status IN ('active', 'available')
              ORDER BY (pm.status <> 'active'), m.sort_order
              LIMIT 1",
            ['u' => $uid]
        );
        if ($m === null) {
            return $chrome;                                  // sem missão: mantém o cromo
        }

        return [
            'title'        => (string)($chrome['title'] ?? 'CONTRATO ATIVO'),
            'action'       => (string)($chrome['action'] ?? 'VER TODOS'),
            'href'         => (string)($chrome['href'] ?? 'missoes'),
            'reward_label' => (string)($chrome['reward_label'] ?? 'RECOMPENSA'),
            'name'         => (string)$m['name'],
            'desc'         => (string)$m['objective'],
            'progress'     => (int)$m['progress'],
            'total'        => max(1, (int)$m['target']),
            'rewards'      => $this->contractRewards((int)$m['id']),
        ];
    }

    /**
     * Recompensas em moeda da missão. O painel do início só tem três selos (XP, $ e
     * pontos); recompensa em ITEM não tem representação aqui e aparece na tela MISSÕES.
     *
     * @return list<array{type:string,value:int,label:string}>
     */
    private function contractRewards(int $missionId): array
    {
        $rotulos = [
            'xp'     => ['type' => 'xp',   'label' => 'Experiência'],
            'cash'   => ['type' => 'cash', 'label' => 'Dinheiro'],
            'points' => ['type' => 'rep',  'label' => 'Pontos de reputação'],
        ];
        $out = [];
        foreach (Database::all('SELECT kind, amount FROM mission_rewards WHERE mission_id = :m ORDER BY id', ['m' => $missionId]) as $r) {
            $k = (string)$r['kind'];
            if (!isset($rotulos[$k])) {
                continue;
            }
            $out[] = $rotulos[$k] + ['value' => (int)$r['amount']];
        }
        return $out;
    }

    /** DATETIME do MySQL -> ISO-8601 com fuso, que é o que Date.parse entende. */
    private function iso(string $datetime): string
    {
        if ($datetime === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($datetime))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return '';
        }
    }
}
