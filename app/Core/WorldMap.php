<?php declare(strict_types=1);

namespace App\Core;

use App\Repo\CatalogRepo;
use App\Repo\MapPointsRepo;

/**
 * MONTAGEM DO MAPA DO MUNDO — regiões, marcadores e a leitura de cada um conforme o jogador.
 *
 * POR QUE ISTO SAIU DE MapController
 * O mapa passou a ser desenhado em DOIS lugares: a tela /mapa da HUD e a página de divulgação
 * na raiz. A regra "o site precisa mostrar o MESMO mapa do jogo" só se sustenta se as duas
 * telas montarem os marcadores pela mesma função — a mesma leitura de camada, o mesmo status
 * de base, a mesma criatura no ninho. Enquanto tudo isto vivia como métodos privados do
 * controller, a landing teria de copiar quatrocentas linhas e as duas cópias divergiriam no
 * primeiro patch.
 *
 * Nada de comportamento mudou na mudança de endereço: os métodos são os mesmos, agora
 * estáticos e públicos, e MapController delega para eles. O que a HUD mostrava ontem ela
 * mostra hoje.
 *
 * O `$op` que várias funções recebem é o estado operacional do jogador (OperationalRepo) —
 * é ele que decide se uma instalação Aegis aparece ALIADA ou HOSTIL, se uma extração está
 * bloqueada para Sobrevivente. A landing passa o estado padrão (Hunter, sem contrato), que é
 * o mundo como um recruta o vê no primeiro dia.
 */
final class WorldMap
{
    /** map_markers.kind -> camada. Cada linha da legenda é uma camada clicável. */
    public const LAYER_OF = [
        'mission'        => 'missoes',
        'extraction'     => 'extracao',
        'gov_base'       => 'hunter',
        'base'           => 'posto',
        'camp'           => 'posto',
        'village'        => 'posto',
        'radio'          => 'posto',
        'aegis'          => 'aegis',
        'frontier'       => 'frontier',
        'safe_zone'      => 'safe-zone',
        'field_base'     => 'base-campo',
        'nest'           => 'veyra',
        'colony'         => 'veyra',
        'surge'          => 'veyra',
        'migration'      => 'veyra',
        'core'           => 'veyra',
        'enemy'          => 'veyra',
        'contaminated'   => 'contaminacao',
        'human_activity' => 'humana',
        'convoy'         => 'logistica',
        'supply'         => 'logistica',
        'supply_drop'    => 'logistica',
        'resupply'       => 'logistica',
        'abandoned_cargo'=> 'logistica',
        'bio_regrowth'   => 'logistica',
        'event'          => 'evento',
        'loot'           => 'recursos',
        'fuel'           => 'gasolina',
        'market'         => 'mercado',
        'hospital'       => 'hospital',
    ];

    /** kind -> glifo padrão quando o meta não traz um. */
    public const GLYPH_OF = [
        'gov_base' => 'gov-base', 'field_base' => 'field-base', 'human_activity' => 'human', 'safe_zone' => 'safe-zone',
        'colony' => 'nest', 'surge' => 'nest', 'migration' => 'nest', 'core' => 'nest',
        'supply' => 'convoy', 'supply_drop' => 'convoy', 'resupply' => 'convoy', 'abandoned_cargo' => 'convoy', 'bio_regrowth' => 'event',
    ];

    /**
     * Opacidade da arte: padrão do código e piso. O mesmo número de MapEditorController — é o
     * que vale quando nem o mapa nem o jogador disseram nada.
     */
    public const OPACIDADE_PADRAO = 0.65;
    public const OPACIDADE_MIN    = 0.15;

    /**
     * Regiões do mundo (catálogo `maps` kind=region), no formato que a view espera.
     * @return list<array<string,mixed>>
     */
    public static function regions(bool $survivor): array
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
                'world_image' => (string)($meta['world_image'] ?? ''),
                'map_opacity' => isset($meta['map_opacity']) ? (float)$meta['map_opacity'] : null,
                'desc'        => (string)($row['description'] ?? ''),
                'info'        => self::info(is_array($meta['info'] ?? null) ? $meta['info'] : []),
                'extractions' => self::extractions(is_array($meta['extractions'] ?? null) ? $meta['extractions'] : [], $survivor),
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
    public static function fromMock(array $mock, bool $survivor): array
    {
        $out = [];
        foreach ($mock as $r) {
            $r['map_id']      = null;
            $r['info']        = self::info((array)($r['info'] ?? []));
            $r['extractions'] = self::extractions((array)($r['extractions'] ?? []), $survivor);
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
    public static function info(array $info): array
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
    public static function extractions(array $list, bool $survivor): array
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
    public static function bases(?int $mapId): array
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
     * Marcadores da região (catálogo `map_markers`), reidratados para a view/map3d.
     * x/y voltam de 0..1 para porcentagem da área do mapa.
     * @param array<string,mixed> $op
     * @param list<array<string,mixed>> $bases
     * @return list<array<string,mixed>>
     */
    public static function markers(int $mapId, array $op, array $bases): array
    {
        $tipos = MapPointsRepo::porSlug();
        $out = [];
        foreach (CatalogRepo::mapMarkers($mapId) as $row) {
            $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
            $kind = (string)($row['kind'] ?? 'loot');

            /* O tipo do catálogo é o PADRÃO, nunca a última palavra: o que o marcador gravou
               no próprio meta vence. Um ponto arrastado da paleta herda ícone e cor do tipo;
               um ponto ajustado à mão no editor mantém o ajuste mesmo que o tipo mude depois. */
            $tipo = $tipos[(string)($meta['point_type'] ?? '')] ?? null;

            $mk = [
                'id'    => (string)($meta['id'] ?? ('mk-' . ($row['id'] ?? '0'))),
                'kind'  => $kind,
                'glyph' => (string)($meta['glyph'] ?? self::GLYPH_OF[$kind] ?? $kind),
                'color' => (string)($meta['color'] ?? ($tipo['color'] ?? 'white')),
                'name'  => (string)($meta['name'] ?? $row['label'] ?? ''),
                'x'     => round((float)($row['x'] ?? 0) * 100, 2),
                'y'     => round((float)($row['y'] ?? 0) * 100, 2),
                'label' => $meta['label'] ?? ($tipo !== null && $tipo['show_label'] ? 'right' : false),
            ];
            /* O ícone do próprio marcador vence o do catálogo. É o que permite um ponto
               específico do mundo ter o seu desenho — a torre de rádio da estação silenciada,
               a base que é um caso à parte — sem precisar criar um tipo inteiro no catálogo
               para um ponto só. */
            if (isset($meta['icon']) && is_string($meta['icon']) && $meta['icon'] !== '') {
                $mk['icon'] = (string)$meta['icon'];
            }
            if ($tipo !== null) {
                $mk['point_type'] = (string)$tipo['slug'];
                $mk['icon']       = (string)($meta['icon'] ?? $tipo['icon']);
                if (($tipo['layer'] ?? null) !== null && $tipo['layer'] !== '') { $mk['layer_forced'] = (string)$tipo['layer']; }
            }
            if (!empty($meta['narrow']))  { $mk['narrow'] = true; }
            if (!empty($meta['pulse']))   { $mk['pulse']  = true; }
            if (is_array($meta['risk'] ?? null)) { $mk['risk'] = $meta['risk']; }
            foreach (['base', 'faction', 'caste', 'signal', 'event', 'owner', 'status', 'ttl_min', 'zone', 'tag', 'radius'] as $k) {
                if (isset($meta[$k])) { $mk[$k] = $meta[$k]; }
            }
            $out[] = self::decorate($mk, $op, $bases);
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
    public static function mockMarkers(array $mock, array $op, array $bases): array
    {
        $out = [];
        foreach ($mock as $mk) {
            $mk['kind']  = (string)($mk['kind'] ?? $mk['glyph'] ?? 'loot');
            $mk['glyph'] = (string)($mk['glyph'] ?? self::GLYPH_OF[$mk['kind']] ?? $mk['kind']);
            $out[] = self::decorate($mk, $op, $bases);
        }
        return $out;
    }

    /**
     * Camada, status e legenda secundária de um marcador, conforme o estado do jogador:
     *  - gov_base: status da base (government_bases) — sem vagas
     *  - aegis/frontier: ALIADA (contrato ativo) / HOSTIL (contrato com a rival) / BLOQUEADA (rompimento) / NEUTRA
     *  - human_activity: rótulo do sinal + expiração aproximada (ttl_min)
     *  - nest/colony/core/contaminated…: criatura do Bestiário em meta.caste (app/Data/criaturas.php)
     *  - convoy/supply/…: nome do ciclo de mundo (nunca "respawn")
     * @param array<string,mixed> $mk
     * @param array<string,mixed> $op
     * @param list<array<string,mixed>> $bases
     * @return array<string,mixed>
     */
    public static function decorate(array $mk, array $op, array $bases): array
    {
        $kind = (string)$mk['kind'];
        // Camada: a do tipo do catálogo quando ele declara uma, senão a do vocabulário do jogo.
        $mk['layer'] = (string)($mk['layer_forced'] ?? self::LAYER_OF[$kind] ?? 'eventos');
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
                // meta.caste guarda o slug da criatura que domina o ponto (nome da chave é
                // histórico). creature() ainda aceita as castas do enredo antigo.
                $cria = GameRules::creature((string)($mk['caste'] ?? ''));
                if ($cria !== null) {
                    $mk['caste'] = (string)$cria['slug'];
                    $mk['sub']   = mb_strtoupper((string)$cria['nome']) . ' · ' . mb_strtoupper((string)($cria['papel'] ?? ''));
                    $mk['tip']   = (string)($cria['linha'] ?? '');
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
    public static function factionSites(array $markers): array
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

    /**
     * Opacidade da arte de uma região, dentro dos limites. O `map_opacity` vem do editor do
     * painel; sem ele vale o padrão do código.
     * @param array<string,mixed> $region
     */
    public static function opacidade(array $region): float
    {
        $v = $region['map_opacity'] ?? null;
        return $v !== null ? max(self::OPACIDADE_MIN, min(1.0, (float)$v)) : self::OPACIDADE_PADRAO;
    }
}
