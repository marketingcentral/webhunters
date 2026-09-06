<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\GameRules;
use App\Core\Request;
use App\Core\Response;
use App\Repo\CatalogRepo;

/**
 * MAPA DO MUNDO — PRD v3 §23.
 *
 * O mapa é a leitura do mundo persistente: regiões (`maps` kind=region) com estado do setor
 * (`maps.meta`: capacidade 20, infestação, atividade Veyra/humana), marcadores (`map_markers`)
 * agrupados em camadas filtráveis, 3 Bases Hunter com estado (nunca vagas exatas), instalações
 * de facção cujo status depende do contrato do jogador, e extrações tipadas — todas
 * BLOQUEADAS quando o jogador é Sobrevivente (§23.7).
 */
final class MapController extends Controller
{
    /** map_markers.kind -> camada do filtro (slug de Data/mapa.php `filters`). */
    private const LAYER_OF = [
        'gov_base'       => 'hunter',
        'base'           => 'hunter',
        'camp'           => 'hunter',
        'radio'          => 'hunter',
        'aegis'          => 'faccoes',
        'frontier'       => 'faccoes',
        'field_base'     => 'bases',
        'safe_zone'      => 'bases',
        'nest'           => 'veyra',
        'colony'         => 'veyra',
        'surge'          => 'veyra',
        'migration'      => 'veyra',
        'core'           => 'veyra',
        'contaminated'   => 'veyra',
        'enemy'          => 'veyra',
        'extraction'     => 'extracoes',
        'event'          => 'eventos',
        'convoy'         => 'eventos',
        'supply'         => 'eventos',
        'supply_drop'    => 'eventos',
        'resupply'       => 'eventos',
        'abandoned_cargo'=> 'eventos',
        'bio_regrowth'   => 'eventos',
        'human_activity' => 'eventos',
        'loot'           => 'eventos',
        'village'        => 'hunter',
    ];

    /** kind -> glifo padrão quando o meta não traz um. */
    private const GLYPH_OF = [
        'gov_base' => 'gov-base', 'field_base' => 'field-base', 'human_activity' => 'human', 'safe_zone' => 'safe-zone',
        'colony' => 'nest', 'surge' => 'nest', 'migration' => 'nest', 'core' => 'nest',
        'supply' => 'convoy', 'supply_drop' => 'convoy', 'resupply' => 'convoy', 'abandoned_cargo' => 'convoy', 'bio_regrowth' => 'event',
    ];

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        // Textos/labels de layout continuam no Data; regiões e marcadores vêm do banco.
        $data = data_mock('mapa');
        $op   = $this->operational();
        $survivor = ($op['state'] ?? '') === GameRules::STATE_SURVIVOR;

        $regions = $this->regions($survivor);
        if ($regions === []) {
            // sem banco: o fallback do mock já traz `state`/extrações tipadas
            $regions = $this->fromMock((array)($data['regions'] ?? []), $survivor);
        }
        $slugs   = array_column($regions, 'slug');
        $slug    = $this->pick($params['regiao'] ?? null, $slugs, $slugs[0] ?? 'floresta-verde');

        $data['regions']       = $regions;
        $data['active_region'] = $slug;
        $data['region']        = null;

        $activeMapId = null;
        foreach ($regions as $r) {
            if ($r['slug'] === $slug) {
                $data['region'] = $r;
                $activeMapId    = $r['map_id'] !== null ? (int)$r['map_id'] : null;
                break;
            }
        }

        $bases   = $this->bases($activeMapId);
        $markers = $activeMapId !== null ? $this->markers($activeMapId, $op, $bases) : [];
        if ($markers === [] && $activeMapId === null) {
            $markers = $this->mockMarkers((array)(($data['markers'] ?? [])[$slug] ?? []), $op, $bases);
        }

        $data['markers']       = $markers;
        $data['bases']         = $bases;
        $data['faction_sites'] = $this->factionSites($markers);
        $data['operational']   = $op;
        $data['survivor']      = $survivor;
        if ($survivor) {
            $data['extractions_title'] = (string)($data['extractions_blocked_title'] ?? 'EXTRAÇÕES · BLOQUEADAS');
        }

        $page = $this->page('mapa', 'Mapa do Mundo', [
            'bg'    => 'bg/forest-generic.jpg',
            'three' => ['atmosphere' => true, 'map' => true],
        ]);
        return $this->render('mapa', $page, $data);
    }

    /**
     * Regiões do mundo (catálogo `maps` kind=region), no formato que a view espera.
     * @return list<array<string,mixed>>
     */
    private function regions(bool $survivor): array
    {
        $out = [];
        foreach (CatalogRepo::maps('region') as $row) {
            $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
            $key  = (string)($meta['key'] ?? preg_replace('/^regiao-/', '', (string)($row['slug'] ?? '')));
            $out[] = [
                'map_id'      => (int)$row['id'],
                'slug'        => $key,
                'name'        => (string)($row['name'] ?? ''),
                'level'       => (string)($meta['level'] ?? ''),
                'icon'        => (string)($meta['icon'] ?? 'pine'),
                'locked'      => (bool)($row['locked'] ?? false),
                'image'       => (string)($row['image'] ?? ''),
                'desc'        => (string)($row['description'] ?? ''),
                'info'        => $this->info(is_array($meta['info'] ?? null) ? $meta['info'] : []),
                'extractions' => $this->extractions(is_array($meta['extractions'] ?? null) ? $meta['extractions'] : [], $survivor),
                'event'       => is_array($meta['event'] ?? null) ? $meta['event'] : null,
                'state'       => CatalogRepo::regionState($row),
            ];
        }
        return $out;
    }

    /**
     * Fallback sem banco: aplica as mesmas regras de extração/estado ao mock.
     * @param list<array<string,mixed>> $mock
     * @return list<array<string,mixed>>
     */
    private function fromMock(array $mock, bool $survivor): array
    {
        $out = [];
        foreach ($mock as $r) {
            $r['map_id']      = null;
            $r['info']        = $this->info((array)($r['info'] ?? []));
            $r['extractions'] = $this->extractions((array)($r['extractions'] ?? []), $survivor);
            $r['state']       = CatalogRepo::regionState(['meta' => (array)($r['state'] ?? [])]);
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Linhas de INFORMAÇÕES. Traduz rótulos legados que ainda possam existir no meta
     * (mundo persistente não tem "tempo do ciclo" nem "jogadores máx.").
     * @param list<array<string,mixed>> $info
     * @return list<array<string,mixed>>
     */
    private function info(array $info): array
    {
        $out = [];
        foreach ($info as $it) {
            $label = mb_strtoupper((string)($it['label'] ?? ''));
            if ($label === 'JOGADORES MÁX.' || $label === 'JOGADORES MÁX') {
                $it['label'] = 'CAPACIDADE OPERACIONAL';
                $it['value'] = (string)GameRules::REGION_CAPACITY['total'];
                $it['tip']   = 'Até ' . GameRules::REGION_CAPACITY['hunters'] . ' Hunters e ' . GameRules::REGION_CAPACITY['mercenaries'] . ' Mercenários.';
            } elseif ($label === 'TEMPO DO CICLO') {
                $it['label'] = 'MUNDO';
                $it['value'] = 'PERSISTENTE';
                $it['cls']   = 'lime';
            } elseif ($label === 'CLIMA') {
                $it['label'] = 'CLIMA ATUAL';
            }
            $out[] = $it;
        }
        return $out;
    }

    /**
     * Extrações tipadas (EVAC HUNTER / PÚBLICA / TRANSPORTE AEGIS / ROTA FRONTIER / CLANDESTINA).
     * Sobrevivente: todas BLOQUEADAS com o tooltip canônico (§23.7).
     * @param list<array<string,mixed>> $list
     * @return list<array<string,mixed>>
     */
    private function extractions(array $list, bool $survivor): array
    {
        $out = [];
        foreach ($list as $x) {
            $type = strtolower((string)($x['type'] ?? 'publica'));
            $t    = GameRules::EXTRACTION_TYPES[$type] ?? GameRules::EXTRACTION_TYPES['publica'];
            $x['type']       = $type;
            $x['type_label'] = $t['label'];
            $x['type_color'] = $t['color'];
            $x['tip']        = $t['who'];
            $state = strtolower((string)($x['state'] ?? 'active'));
            $x['status'] = (string)($x['status'] ?? (GameRules::EXTRACTION_STATES[$state]['label'] ?? 'ATIVA'));
            if ($survivor) {
                $x['state']  = 'blocked';
                $x['status'] = GameRules::EXTRACTION_STATES['blocked']['label'];
                $x['tip']    = GameRules::SURVIVOR_EXTRACTION_TIP;
            } else {
                $x['state'] = $state;
            }
            $out[] = $x;
        }
        return $out;
    }

    /**
     * Bases Hunter (government_bases). Estado só — o PRD proíbe vagas exatas (§23.5).
     * @return list<array<string,mixed>>
     */
    private function bases(?int $mapId): array
    {
        $all = CatalogRepo::governmentBases();
        $out = [];
        foreach ($all as $b) {
            // sem map_id no cadastro (fallback) mostra as três em toda região
            if ($mapId !== null && $b['map_id'] !== null && (int)$b['map_id'] !== $mapId) { continue; }
            $status = mb_strtoupper((string)($b['status'] ?? 'OPERACIONAL'));
            $out[] = [
                'slug'   => (string)$b['slug'],
                'name'   => (string)$b['name'],
                'short'  => (string)($b['short'] ?? $b['name']),
                'status' => $status,
                'color'  => GameRules::BASE_STATUS[$status] ?? 'muted',
            ];
        }
        return $out;
    }

    /**
     * Marcadores da região selecionada (catálogo `map_markers`), reidratados para a view/map3d.
     * x/y voltam de 0..1 para porcentagem da área do mapa.
     * @param array<string,mixed> $op
     * @param list<array<string,mixed>> $bases
     * @return list<array<string,mixed>>
     */
    private function markers(int $mapId, array $op, array $bases): array
    {
        $out = [];
        foreach (CatalogRepo::mapMarkers($mapId) as $row) {
            $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
            $kind = (string)($row['kind'] ?? 'loot');
            $mk = [
                'id'    => (string)($meta['id'] ?? ('mk-' . ($row['id'] ?? '0'))),
                'kind'  => $kind,
                'glyph' => (string)($meta['glyph'] ?? self::GLYPH_OF[$kind] ?? $kind),
                'color' => (string)($meta['color'] ?? 'white'),
                'name'  => (string)($meta['name'] ?? $row['label'] ?? ''),
                'x'     => round((float)($row['x'] ?? 0) * 100, 2),
                'y'     => round((float)($row['y'] ?? 0) * 100, 2),
                'label' => $meta['label'] ?? false,
            ];
            if (!empty($meta['narrow']))  { $mk['narrow'] = true; }
            if (!empty($meta['pulse']))   { $mk['pulse']  = true; }
            if (is_array($meta['risk'] ?? null)) { $mk['risk'] = $meta['risk']; }
            foreach (['base', 'faction', 'caste', 'signal', 'event', 'owner', 'status', 'ttl_min', 'zone'] as $k) {
                if (isset($meta[$k])) { $mk[$k] = $meta[$k]; }
            }
            $out[] = $this->decorate($mk, $op, $bases);
        }
        return $out;
    }

    /**
     * Fallback sem banco: marcadores do mock passam pela mesma decoração.
     * @param list<array<string,mixed>> $mock
     * @param array<string,mixed> $op
     * @param list<array<string,mixed>> $bases
     * @return list<array<string,mixed>>
     */
    private function mockMarkers(array $mock, array $op, array $bases): array
    {
        $out = [];
        foreach ($mock as $mk) {
            $mk['kind']  = (string)($mk['kind'] ?? $mk['glyph'] ?? 'loot');
            $mk['glyph'] = (string)($mk['glyph'] ?? self::GLYPH_OF[$mk['kind']] ?? $mk['kind']);
            $out[] = $this->decorate($mk, $op, $bases);
        }
        return $out;
    }

    /**
     * Camada, status e legenda secundária de um marcador, conforme o estado do jogador:
     *  - gov_base: status da base (government_bases) — sem vagas
     *  - aegis/frontier: ALIADA (contrato ativo) / HOSTIL (contrato com a rival) / BLOQUEADA (rompimento) / NEUTRA
     *  - human_activity: rótulo do sinal + expiração aproximada (ttl_min)
     *  - nest/contaminated: casta Veyra
     *  - convoy/supply/…: nome do ciclo de mundo (nunca "respawn")
     * @param array<string,mixed> $mk
     * @param array<string,mixed> $op
     * @param list<array<string,mixed>> $bases
     * @return array<string,mixed>
     */
    private function decorate(array $mk, array $op, array $bases): array
    {
        $kind = (string)$mk['kind'];
        $mk['layer'] = self::LAYER_OF[$kind] ?? 'eventos';
        $mk['sub']   = null;
        $mk['tip']   = null;

        switch ($kind) {
            case 'gov_base':
                $status = null;
                foreach ($bases as $b) {
                    if (isset($mk['base']) && $b['slug'] === (string)$mk['base']) { $status = $b['status']; break; }
                }
                $status = $status ?? mb_strtoupper((string)($mk['status'] ?? 'OPERACIONAL'));
                $mk['status'] = $status;
                $mk['sub']    = $status;
                $mk['sub_color'] = GameRules::BASE_STATUS[$status] ?? 'muted';
                if (in_array($status, ['ISOLADA', 'SOB ATAQUE', 'COMPROMETIDA'], true)) { $mk['pulse'] = true; $mk['color'] = 'red'; }
                break;

            case 'aegis':
            case 'frontier':
                $fac    = (string)($mk['faction'] ?? $kind);
                $rival  = $fac === 'aegis' ? 'frontier' : 'aegis';
                $mine   = (string)($op['faction'] ?? '');
                $locked = ($op['locked'][$fac] ?? null) !== null;
                if ($locked)             { $status = 'BLOQUEADA'; }
                elseif ($mine === $fac)  { $status = 'ALIADA'; }
                elseif ($mine === $rival){ $status = 'HOSTIL'; }
                else                     { $status = 'NEUTRA'; }
                $mk['status']    = $status;
                $mk['sub']       = $status;
                $mk['sub_color'] = GameRules::FACTION_SITE_STATUS[$status] ?? 'muted';
                $mk['tip']       = match ($status) {
                    'ALIADA'    => 'Instalação da sua facção contratante.',
                    'HOSTIL'    => 'Instalação da facção rival ao seu contrato.',
                    'BLOQUEADA' => 'Acesso revogado após rompimento de contrato.',
                    default     => 'Sem contrato ativo: acesso neutro.',
                };
                break;

            case 'human_activity':
                $signal = (string)($mk['signal'] ?? 'movement');
                $mk['name'] = (string)($mk['name'] ?: (GameRules::HUMAN_SIGNALS[$signal] ?? 'ATIVIDADE HUMANA'));
                $ttl = (int)($mk['ttl_min'] ?? 30);
                $mk['expires']  = max(60, $ttl * 60);
                $mk['sub']      = 'POSIÇÃO APROXIMADA';
                $mk['tip']      = 'Sinal temporário; a posição é aproximada e expira em ' . $ttl . ' min.';
                $mk['pulse']    = true;
                break;

            case 'nest': case 'colony': case 'surge': case 'migration': case 'core': case 'contaminated':
                $caste = strtolower((string)($mk['caste'] ?? ''));
                $desc = GameRules::VEYRA_CASTES[$caste] ?? null;   // casta => descrição canônica (Bíblia)
                if ($desc !== null) {
                    $mk['sub'] = 'CASTA · ' . mb_strtoupper($caste);
                    $mk['tip'] = (string)$desc;
                }
                $mk['sub_color'] = $kind === 'contaminated' ? 'purple' : 'red';
                break;

            case 'safe_zone':
                $mk['sub']       = 'SAFE ZONE';
                $mk['sub_color'] = 'lime';
                $mk['tip']       = 'Perímetro protegido: papéis civis, mercado e oficina entre operações.';
                if (($op['state'] ?? '') === GameRules::STATE_SURVIVOR && ($mk['zone'] ?? '') !== 'franca') {
                    $mk['sub']       = 'SEM ACESSO';
                    $mk['sub_color'] = 'muted';
                    $mk['color']     = 'muted';
                    $mk['tip']       = 'Nenhuma base do governo responde por um Sobrevivente.';
                }
                break;

            case 'field_base':
                $owner = (string)($mk['owner'] ?? 'unknown');
                $mk['sub'] = match ($owner) { 'mine' => 'MINHA', 'squad' => 'ESQUADRÃO', 'compromised' => 'COMPROMETIDA', default => 'DESCONHECIDA' };
                $mk['sub_color'] = match ($owner) { 'mine' => 'lime', 'squad' => 'lime', 'compromised' => 'red', default => 'muted' };
                break;

            case 'convoy': case 'supply': case 'supply_drop': case 'resupply': case 'abandoned_cargo': case 'bio_regrowth':
                $ev = (string)($mk['event'] ?? $kind);
                $mk['sub'] = !empty(GameRules::WORLD_EVENTS[$ev]['logistic']) ? 'JANELA LOGÍSTICA' : (GameRules::WORLD_EVENTS[$ev]['label'] ?? 'CICLO DE MUNDO');
                $mk['sub_color'] = (string)$mk['color'];
                $mk['tip'] = 'Ciclo de mundo: recursos voltam por logística, nunca por respawn.';
                break;

            case 'extraction':
                if (($op['state'] ?? '') === GameRules::STATE_SURVIVOR) {
                    $mk['sub']       = GameRules::EXTRACTION_STATES['blocked']['label'];
                    $mk['sub_color'] = 'muted';
                    $mk['color']     = 'muted';
                    $mk['tip']       = GameRules::SURVIVOR_EXTRACTION_TIP;
                    $mk['blocked']   = true;
                }
                break;
        }
        return $mk;
    }

    /**
     * Instalações de facção presentes na região (para a coluna direita).
     * @param list<array<string,mixed>> $markers
     * @return list<array<string,mixed>>
     */
    private function factionSites(array $markers): array
    {
        $out = [];
        foreach ($markers as $m) {
            if (!in_array($m['kind'] ?? '', ['aegis', 'frontier'], true)) { continue; }
            $fac = (string)($m['faction'] ?? $m['kind']);
            $out[] = [
                'faction' => $fac,
                'name'    => (string)$m['name'],
                'status'  => (string)($m['status'] ?? 'NEUTRA'),
                'color'   => (string)($m['sub_color'] ?? 'muted'),
                'tip'     => (string)($m['tip'] ?? ''),
            ];
        }
        return $out;
    }
}
