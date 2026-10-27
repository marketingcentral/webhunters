<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\GameRules;
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
        // O carrossel de notícias da home passa a ler as MESMAS linhas que /noticias lê
        // (tabela `news`, publicadas). Antes ele vinha inteiro de app/Data/inicio.php: o
        // operador publicava em /admin/eventos, a notícia aparecia em /noticias e a home
        // continuava mostrando a lista de 2024 escrita no arquivo.
        $data['news_pages'] = $this->newsPages((array)($data['news_pages'] ?? []));
        $data['news']       = array_slice($data['news_pages'][0] ?? [], 0, 3);

        $uid = $this->userId();
        $data['season']   = $this->season((array)($data['season'] ?? []), $uid);
        $data['contract'] = $this->contract((array)($data['contract'] ?? []), $uid);
        $data['status_op'] = $this->statusOperacional((array)($data['status'] ?? []));

        $page = $this->page('inicio', 'Início', [
            'bg'     => 'bg/home-hero-wide.jpg',
            'bg_pos' => 'center',
            // hero 3D desligado nesta tela: ver app/Views/pages/inicio.php
            'three'  => ['atmosphere' => true, 'hero' => false],
        ]);
        return $this->render('inicio', $page, $data);
    }

    /* =================================================================== notícias */

    /**
     * Páginas do carrossel de notícias da home, montadas da tabela `news`.
     *
     * Duas por página e no máximo quatro páginas: é o que cabe no painel ao lado da faixa
     * STATUS OPERACIONAL — o mesmo recorte que app/Data/inicio.php já fazia, mantido aqui
     * para a home não transbordar quando o operador publicar a décima notícia.
     *
     * Cai para $fallback (o carrossel estático do cromo) quando o banco não responde ou
     * quando ainda não há notícia publicada: a home nunca fica com um painel vazio.
     *
     * @param list<list<array<string,mixed>>> $fallback
     * @return list<list<array<string,mixed>>>
     */
    private function newsPages(array $fallback): array
    {
        try {
            $rows = \App\Repo\CatalogRepo::news();
        } catch (\Throwable) {
            return $fallback;
        }
        if ($rows === []) {
            return $fallback;
        }

        $itens = [];
        foreach ($rows as $n) {
            $slug = (string)($n['slug'] ?? '');
            $ts   = strtotime((string)($n['published'] ?? ''));
            $itens[] = [
                'title'   => (string)($n['title'] ?? ''),
                'summary' => (string)($n['excerpt'] ?? ''),
                'date'    => $ts !== false ? date('d/m/Y', $ts) : '',
                'image'   => (string)($n['image'] ?? 'news/news-atualizacao.jpg'),
                // /noticias/{slug} devolve 404 para slug inexistente; sem slug, manda para a lista.
                'route'   => $slug !== '' ? 'noticias/' . $slug : 'noticias',
            ];
        }
        return array_slice(array_chunk($itens, 2), 0, 4);
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
        // missions.origin vem do patch v3. Se o patch ainda não rodou no banco (deploy do
        // código antes do SQL), a coluna não existe: repete sem ela e cai em hunter_command.
        $sql = "SELECT m.id, m.name, m.objective, m.target, %s pm.status, pm.progress
                  FROM missions m
                  JOIN player_missions pm ON pm.mission_id = m.id AND pm.user_id = :u
                 WHERE pm.status IN ('active', 'available')
                 ORDER BY (pm.status <> 'active'), m.sort_order
                 LIMIT 1";
        try {
            $m = Database::one(sprintf($sql, 'm.origin,'), ['u' => $uid]);
        } catch (\Throwable) {
            $m = Database::one(sprintf($sql, ''), ['u' => $uid]);
        }
        if ($m === null) {
            return $chrome;                                  // sem missão: mantém o cromo
        }

        return [
            'title'        => (string)($chrome['title'] ?? 'CONTRATO ATIVO'),
            'action'       => (string)($chrome['action'] ?? 'VER TODOS'),
            'href'         => (string)($chrome['href'] ?? 'missoes'),
            'reward_label' => (string)($chrome['reward_label'] ?? 'RECOMPENSA'),
            'origin_label' => (string)($chrome['origin_label'] ?? 'ORIGEM'),
            // de quem vem a ordem/contrato (PRD v3 §16.2): missions.origin, fallback Hunter Command
            'origin'       => (string)($m['origin'] ?? 'hunter_command'),
            'name'         => (string)$m['name'],
            'desc'         => (string)$m['objective'],
            'progress'     => (int)$m['progress'],
            'total'        => max(1, (int)$m['target']),
            'rewards'      => $this->contractRewards((int)$m['id']),
        ];
    }

    /**
     * Faixa "STATUS OPERACIONAL" da Home (PRD v3 §16.3): responde "em qual estado estou" e
     * "para quem trabalho" em uma linha. Só rótulos vêm do cromo; valores de OperationalRepo.
     *
     * @param array<string,mixed> $chrome
     * @return array<string,mixed>
     */
    private function statusOperacional(array $chrome): array
    {
        $op    = $this->operational();
        $lab   = (array)($chrome['labels'] ?? []);
        $itens = [];

        if ($op['state'] === GameRules::STATE_SURVIVOR) {
            $itens[] = ['label' => $lab['org'] ?? 'ORGANIZAÇÃO', 'value' => 'NENHUMA', 'cls' => 'white'];
            $itens[] = ['label' => 'EXTRAÇÃO', 'value' => 'INDISPONÍVEL', 'cls' => 'white'];
            $since = $op['survivor_since'] ?? null;
            if ($since) { $itens[] = ['label' => 'NO ESTADO HÁ', 'value' => $this->duracao((string)$since), 'cls' => 'white', 'num' => true]; }
        } elseif ($op['state'] === GameRules::STATE_MERCENARY) {
            $f = GameRules::faction($op['faction']) ?? ['label' => '—', 'color' => 'muted'];
            $itens[] = ['label' => $lab['contract'] ?? 'CONTRATO', 'value' => $f['label'], 'cls' => $f['color']];
            $itens[] = ['label' => $lab['rep'] ?? 'REP', 'value' => num((int)($op['reputation'][$op['faction']] ?? 0)), 'cls' => $f['color'], 'num' => true];
            $itens[] = ['label' => 'FAIXA', 'value' => (string)($op['tiers'][$op['faction']] ?? '—'), 'cls' => $f['color']];
        } else {
            $itens[] = ['label' => $lab['org'] ?? 'ORGANIZAÇÃO', 'value' => 'HUNTER COMMAND', 'cls' => 'lime'];
            $itens[] = ['label' => $lab['base'] ?? 'BASE DE ORIGEM', 'value' => $this->nomeDaBase($op['base_pref'] ?? null), 'cls' => ''];
            $itens[] = ['label' => $lab['role'] ?? 'FUNÇÃO', 'value' => (string)($op['role'] ?? 'A DEFINIR'), 'cls' => ''];
            $itens[] = ['label' => $lab['rank'] ?? 'PATENTE', 'value' => (string)$op['rank'], 'cls' => ''];
        }

        return [
            'title' => (string)($chrome['title'] ?? 'STATUS OPERACIONAL'),
            'href'  => (string)($chrome['href'] ?? 'perfil'),
            'items' => $itens,
            'op'    => $op,
        ];
    }

    /** Nome curto da Base Governamental (government_bases); sem o patch aplicado cai no slug. */
    private function nomeDaBase(?string $slug): string
    {
        $slug = $slug ?: 'alpha';
        try {
            $n = Database::value('SELECT name FROM government_bases WHERE slug = :s', ['s' => $slug]);
            if (is_string($n) && $n !== '') { return (string)preg_replace('/^BASE HUNTER /u', '', $n); }
        } catch (\Throwable) {
            // tabela ainda não existe
        }
        return strtoupper($slug);
    }

    private function duracao(string $desde): string
    {
        $s = max(0, time() - (int)strtotime($desde));
        return $s >= 3600 ? sprintf('%dh %02dmin', intdiv($s, 3600), intdiv($s % 3600, 60)) : sprintf('%d min', intdiv($s, 60));
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
