<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repo\CatalogRepo;

final class MapController extends Controller
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        // Textos/labels de layout continuam no Data; regiões e marcadores vêm do banco.
        $data = data_mock('mapa');

        $regions = $this->regions();
        $slugs   = array_column($regions, 'slug');
        $slug    = $this->pick($params['regiao'] ?? null, $slugs, $slugs[0] ?? 'floresta-verde');

        $data['regions']       = $regions;
        $data['active_region'] = $slug;
        $data['region']        = null;

        $activeMapId = null;
        foreach ($regions as $r) {
            if ($r['slug'] === $slug) {
                $data['region'] = $r;
                $activeMapId    = (int)$r['map_id'];
                break;
            }
        }

        $data['markers'] = $activeMapId !== null ? $this->markers($activeMapId) : [];

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
    private function regions(): array
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
                'info'        => is_array($meta['info'] ?? null) ? $meta['info'] : [],
                'extractions' => is_array($meta['extractions'] ?? null) ? $meta['extractions'] : [],
                'event'       => is_array($meta['event'] ?? null) ? $meta['event'] : null,
            ];
        }
        return $out;
    }

    /**
     * Marcadores da região selecionada (catálogo `map_markers`), reidratados para a view/map3d.
     * x/y voltam de 0..1 para porcentagem da área do mapa.
     * @return list<array<string,mixed>>
     */
    private function markers(int $mapId): array
    {
        $out = [];
        foreach (CatalogRepo::mapMarkers($mapId) as $row) {
            $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
            $mk = [
                'id'    => (string)($meta['id'] ?? ('mk-' . ($row['id'] ?? '0'))),
                'glyph' => (string)($meta['glyph'] ?? $row['kind'] ?? 'loot'),
                'color' => (string)($meta['color'] ?? 'white'),
                'name'  => (string)($meta['name'] ?? $row['label'] ?? ''),
                'x'     => round((float)($row['x'] ?? 0) * 100, 2),
                'y'     => round((float)($row['y'] ?? 0) * 100, 2),
                'label' => $meta['label'] ?? false,
            ];
            if (!empty($meta['narrow'])) { $mk['narrow'] = true; }
            if (!empty($meta['pulse']))  { $mk['pulse']  = true; }
            if (is_array($meta['risk'] ?? null)) { $mk['risk'] = $meta['risk']; }
            $out[] = $mk;
        }
        return $out;
    }
}
