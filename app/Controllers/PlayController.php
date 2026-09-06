<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

final class PlayController extends Controller
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $data = data_mock('jogar');
        // mesma fonte da tela MAPAS: o catálogo do banco, que traz o level da Unreal
        // de cada mapa (maps.unreal_level) — a grade aqui lia o mock estático.
        $data['maps']       = $this->matchMaps();
        $data['challenges'] = array_slice(data_mock('desafios')['daily'] ?? [], 0, 4);
        $data['reset_in']   = data_mock('desafios')['reset_in'] ?? '';

        $page = $this->page('jogar', 'Jogar Solo', ['three' => ['atmosphere' => true]]);
        return $this->render('jogar', $page, $data);
    }

    /** @param array<string, string> $params */
    public function maps(Request $request, array $params = []): Response
    {
        // Textos e rótulos de layout ficam no Data; a grade de mapas vem do banco.
        $data = data_mock('mapas');
        $data['items'] = $this->matchMaps();

        $page = $this->page('mapas', 'Mapas', ['back' => ['label' => 'VOLTAR', 'href' => url('jogar')]]);
        return $this->render('mapas', $page, $data);
    }

    /**
     * Grade de mapas de partida vinda do catálogo (CatalogRepo::maps('match')),
     * conciliada por jogador: "seu melhor" usa player_matches quando houver registro.
     * Mapeia cada linha do banco para as MESMAS chaves que a view mapas consome.
     * Se o banco estiver indisponível, cai para o mock estático (HUD navegável).
     *
     * @return list<array<string,mixed>>
     */
    private function matchMaps(): array
    {
        try {
            $rows = \App\Repo\CatalogRepo::maps('match');
        } catch (\Throwable) {
            return array_values(data_mock('mapas')['items'] ?? []);
        }
        if ($rows === []) {
            return array_values(data_mock('mapas')['items'] ?? []);
        }

        // "Seu melhor" é o tempo-recorde do jogador no mapa. A tabela player_matches
        // (result/kills/xp/earnings) não guarda duração de partida, então o valor
        // continua vindo do meta do mapa até existir uma coluna de tempo por partida.
        $items = [];
        foreach ($rows as $r) {
            $meta = is_array($r['meta'] ?? null) ? $r['meta'] : [];
            $slug = (string)preg_replace('/^mapa-/', '', (string)($r['slug'] ?? ''));

            $items[] = [
                'slug'      => $slug,
                'name'      => (string)($r['name'] ?? ''),
                'mode'      => (string)($r['mode'] ?? ''),
                'players'   => (string)($r['players'] ?? ''),
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
                // level da UE que este mapa carrega; a view publica em data-ue-level
                'ue_level'  => (string)($r['unreal_level'] ?? ''),
            ];
        }
        return $items;
    }
}
