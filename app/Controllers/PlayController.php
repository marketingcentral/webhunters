<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\GameRules;
use App\Core\Request;
use App\Core\Response;
use App\Repo\CatalogRepo;

/**
 * Tela OPERAÇÃO (/jogar) e a grade de regiões (/mapas).
 *
 * PRD v3 §17: a tela deixa de ser "JOGAR SOLO / PARTIDA RÁPIDA / 6v6" e passa a responder às
 * três perguntas — em qual estado estou (hero por estado), para quem trabalho (Hunter Command
 * ou facção) e o que está acontecendo no setor (infestação, atividade humana, capacidade).
 *
 * As "regiões operacionais" são os mapas kind=match: são eles que têm o level da Unreal que o
 * botão carrega (Game.LoadMap). Mudou a leitura, não a fonte.
 */
final class PlayController extends Controller
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $data = data_mock('jogar');
        $op   = $this->operational();

        $data['maps']       = $this->matchMaps();
        $data['challenges'] = array_slice(data_mock('desafios')['daily'] ?? [], 0, 4);
        $data['reset_in']   = data_mock('desafios')['reset_in'] ?? '';

        $data['modes']    = $this->modes((array)($data['modes'] ?? []), (array)($data['survivor_mode'] ?? []), $op);
        $data['hero']     = $this->hero((array)($data['heroes'] ?? []), (array)($data['hero_labels'] ?? []), $op, $data['maps']);
        $data['capacity'] = GameRules::REGION_CAPACITY;
        $data['bases']    = CatalogRepo::governmentBases();
        $data['prefs']    = $this->prefs((array)($data['prefs'] ?? []), $data['bases'], $op);
        $data['operational'] = $op;

        // Sobrevivente não mobiliza por base nem tem inserção estimada: ele "retorna ao mundo".
        if ($op['state'] === GameRules::STATE_SURVIVOR) {
            $data['cta'] = (string)($data['heroes']['survivor']['cta'] ?? 'RETORNAR AO MUNDO');
            $data['eta'] = '—';
        } elseif ($op['state'] === GameRules::STATE_MERCENARY) {
            $data['cta'] = (string)($data['heroes']['mercenary']['cta'] ?? 'ATUAR COMO MERCENÁRIO');
        }

        $page = $this->page('jogar', 'Operação', ['three' => ['atmosphere' => true]]);
        return $this->render('jogar', $page, $data);
    }

    /** @param array<string, string> $params */
    public function maps(Request $request, array $params = []): Response
    {
        // Textos e rótulos de layout ficam no Data; a grade vem do banco.
        $data = data_mock('mapas');
        $data['items'] = $this->matchMaps();

        $page = $this->page('mapas', 'Regiões', ['back' => ['label' => 'VOLTAR', 'href' => url('jogar')]]);
        return $this->render('mapas', $page, $data);
    }

    /**
     * Lista da esquerda. O Mercenário mostra o contrato ativo no subtítulo; o Sobrevivente só
     * entra quando o estado É esse — e aí é o único ativo, porque não há escolha a fazer.
     *
     * @param list<array<string,mixed>> $modes
     * @param array<string,mixed> $survivor
     * @param array<string,mixed> $op
     * @return list<array<string,mixed>>
     */
    private function modes(array $modes, array $survivor, array $op): array
    {
        $state = (string)($op['state'] ?? GameRules::STATE_HUNTER);
        $out = [];
        foreach ($modes as $m) {
            $slug = (string)($m['slug'] ?? '');
            if ($slug === 'mercenary') {
                $f = GameRules::faction($op['faction'] ?? null);
                $m['desc'] = $f !== null
                    ? $f['label'] . ' — CONTRATO ATIVO · ' . $m['desc']
                    : 'SEM CONTRATO · ' . $m['desc'];
                $m['faction'] = $op['faction'] ?? null;
            }
            $m['active'] = ($slug === 'hunter' && $state === GameRules::STATE_HUNTER)
                        || ($slug === 'mercenary' && $state === GameRules::STATE_MERCENARY);
            // Sobrevivente não escolhe bandeira: as duas ficam visíveis mas indisponíveis.
            if ($state === GameRules::STATE_SURVIVOR && in_array($slug, ['hunter', 'mercenary'], true)) {
                $m['locked'] = true;
                $m['lock_tip'] = 'Indisponível no estado Sobrevivente.';
            }
            $out[] = $m;
        }
        if ($state === GameRules::STATE_SURVIVOR && $survivor !== []) {
            array_unshift($out, $survivor);
        }
        return $out;
    }

    /**
     * Hero central por estado (PRD §17.4–17.6). Devolve título/texto/CTA e a linha de
     * indicadores que cada estado pede.
     *
     * @param array<string,array<string,string>> $heroes
     * @param array<string,string> $lab
     * @param array<string,mixed> $op
     * @param list<array<string,mixed>> $maps
     * @return array<string,mixed>
     */
    private function hero(array $heroes, array $lab, array $op, array $maps): array
    {
        $state = (string)($op['state'] ?? GameRules::STATE_HUNTER);
        $sel   = null;
        foreach ($maps as $m) { if (!empty($m['selected'])) { $sel = $m; break; } }
        $sel ??= $maps[0] ?? [];
        $regiao = (string)($sel['name'] ?? '—');
        $ativ   = (string)($sel['human_activity'] ?? 'MODERADA');

        if ($state === GameRules::STATE_SURVIVOR) {
            $h = $heroes['survivor'] ?? [];
            $since = $op['survivor_since'] ?? null;
            $stats = [
                ['k' => $lab['known_pos'] ?? 'ÚLTIMA POSIÇÃO', 'v' => $regiao . ' · SETOR K-07', 'cls' => 'white'],
                ['k' => $lab['since'] ?? 'NO ESTADO HÁ', 'v' => $since ? $this->duracao((string)$since) : '—', 'cls' => 'white', 'num' => true],
                ['k' => $lab['field_base'] ?? 'BASE DE CAMPO', 'v' => 'NENHUMA CONHECIDA', 'cls' => ''],
                ['k' => $lab['condition'] ?? 'CONDIÇÃO', 'v' => 'ESTÁVEL', 'cls' => 'lime'],
            ];
            return $h + ['key' => 'survivor', 'stats' => $stats, 'badge' => ['kind' => 'state', 'value' => $op]];
        }

        if ($state === GameRules::STATE_MERCENARY) {
            $h  = $heroes['mercenary'] ?? [];
            $f  = GameRules::faction($op['faction'] ?? null) ?? ['label' => '—', 'color' => 'muted'];
            $fs = (string)($op['faction'] ?? '');
            $contratos = 0;
            try {
                $contratos = (int)\App\Core\Database::value('SELECT COUNT(*) FROM missions WHERE origin = :f AND locked = 0', ['f' => $fs]);
            } catch (\Throwable) { /* sem patch */ }
            $stats = [
                ['k' => $lab['faction'] ?? 'FACÇÃO', 'v' => $f['label'], 'cls' => $f['color']],
                ['k' => $lab['rep'] ?? 'REPUTAÇÃO', 'v' => num((int)($op['reputation'][$fs] ?? 0)), 'cls' => $f['color'], 'num' => true],
                ['k' => $lab['tier'] ?? 'FAIXA', 'v' => (string)($op['tiers'][$fs] ?? '—'), 'cls' => $f['color']],
                ['k' => $lab['contracts'] ?? 'CONTRATOS', 'v' => num($contratos) . ' DISPONÍVEIS', 'cls' => '', 'num' => true],
                ['k' => $lab['region'] ?? 'REGIÃO', 'v' => $regiao, 'cls' => ''],
            ];
            return $h + ['key' => 'mercenary', 'stats' => $stats, 'faction' => $fs];
        }

        $h = $heroes['hunter'] ?? [];
        $base = null;
        foreach (CatalogRepo::governmentBases() as $b) { if ($b['slug'] === ($op['base_pref'] ?? 'alpha')) { $base = $b; break; } }
        $base ??= CatalogRepo::governmentBases()[0] ?? ['short' => 'ALPHA', 'status' => 'OPERACIONAL'];
        $stats = [
            ['k' => $lab['rank'] ?? 'PATENTE', 'v' => (string)($op['rank'] ?? 'RECRUTA'), 'cls' => ''],
            ['k' => $lab['role'] ?? 'FUNÇÃO', 'v' => (string)($op['role'] ?? 'A DEFINIR'), 'cls' => 'lime'],
            ['k' => $lab['base'] ?? 'BASE', 'v' => (string)$base['short'], 'cls' => '', 'sub' => (string)$base['status']],
            ['k' => $lab['squad'] ?? 'ESQUADRÃO', 'v' => '1 / ' . GameRules::SQUAD_MAX, 'cls' => '', 'num' => true],
            ['k' => $lab['region'] ?? 'REGIÃO', 'v' => $regiao, 'cls' => ''],
            ['k' => $lab['activity'] ?? 'ATIVIDADE', 'v' => $ativ, 'cls' => GameRules::activityColor($ativ)],
        ];
        return $h + ['key' => 'hunter', 'stats' => $stats];
    }

    /**
     * Preferências: a Base Governamental vem da tabela (nomes reais), a função vem das quatro
     * oficiais e o valor inicial é o do jogador.
     *
     * @param list<array<string,mixed>> $prefs
     * @param list<array<string,mixed>> $bases
     * @param array<string,mixed> $op
     * @return list<array<string,mixed>>
     */
    private function prefs(array $prefs, array $bases, array $op): array
    {
        foreach ($prefs as &$p) {
            if (($p['key'] ?? '') === 'base') {
                $p['options'] = array_map(static fn(array $b): string => (string)$b['short'], $bases);
                $atual = strtoupper((string)($op['base_pref'] ?? ''));
                $p['text'] = in_array($atual, $p['options'], true) ? $atual : ($p['options'][0] ?? 'ALPHA');
                // Sobrevivente não tem base governamental para escolher.
                if (($op['state'] ?? '') === GameRules::STATE_SURVIVOR) { $p['readonly'] = true; $p['text'] = 'SEM ACESSO'; $p['options'] = ['SEM ACESSO']; }
            }
            if (($p['key'] ?? '') === 'role') {
                $p['options'] = array_keys(GameRules::ROLES);
                $role = (string)($op['role'] ?? '');
                $p['text'] = in_array($role, $p['options'], true) ? $role : $p['options'][0];
            }
        }
        unset($p);
        return $prefs;
    }

    private function duracao(string $desde): string
    {
        $s = max(0, time() - (int)strtotime($desde));
        return $s >= 3600 ? sprintf('%dh %02dmin', intdiv($s, 3600), intdiv($s % 3600, 60)) : sprintf('%d min', intdiv($s, 60));
    }

    /**
     * Regiões operacionais: catálogo `maps` kind=match (com o level da Unreal), conciliadas
     * com o estado de mundo em meta (infestação, atividade humana, persistente). Mantém as
     * chaves que a view `mapas` consome. Sem banco, cai para o mock estático.
     *
     * @return list<array<string,mixed>>
     */
    private function matchMaps(): array
    {
        try {
            $rows = CatalogRepo::maps('match');
        } catch (\Throwable) {
            return array_values(data_mock('mapas')['items'] ?? []);
        }
        if ($rows === []) {
            return array_values(data_mock('mapas')['items'] ?? []);
        }

        $items = [];
        foreach ($rows as $r) {
            $meta  = is_array($r['meta'] ?? null) ? $r['meta'] : [];
            $slug  = (string)preg_replace('/^mapa-/', '', (string)($r['slug'] ?? ''));
            $world = CatalogRepo::regionState($r);

            $items[] = [
                'slug'      => $slug,
                'name'      => (string)($r['name'] ?? ''),
                'mode'      => (string)($r['mode'] ?? ''),        // simulações (P2) ainda leem
                'players'   => (string)($r['players'] ?? ''),     // idem — não vai mais ao card
                'image'     => (string)($r['image'] ?? ''),
                'icon'      => (string)($meta['icon'] ?? ''),
                'selected'  => !empty($meta['selected']),
                'size'      => (string)($meta['size'] ?? ''),
                'biome'     => (string)($meta['biome'] ?? ''),
                'weather'   => (string)($meta['weather'] ?? ''),
                'best'      => (string)($meta['best'] ?? ''),
                'best_by'   => (string)($meta['best_by'] ?? ''),
                'your_best' => (string)($meta['your_best'] ?? ''),
                'desc'      => (string)($r['description'] ?? ''),
                // mundo persistente (PRD §17.7)
                'infestation'    => $world['infestation'],
                'human_activity' => $world['human_activity'],
                'persistent'     => $world['persistent'],
                'capacity'       => $world['capacity'],
                // level da UE que este mapa carrega; a view publica em data-ue-level
                'ue_level'  => (string)($r['unreal_level'] ?? ''),
            ];
        }
        return $items;
    }
}
