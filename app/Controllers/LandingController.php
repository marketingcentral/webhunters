<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\WorldMap;
use App\Repo\OperationalRepo;

/**
 * PÁGINA DE DIVULGAÇÃO — a raiz do site (/).
 *
 * É a única tela do jogo aberta a quem nunca entrou: LoginGuard deixa '' passar. Quem JÁ tem
 * sessão não vê propaganda — vai direto para /inicio, como a raiz sempre fez. Assim trazer
 * a landing para a raiz não muda nada para o jogador logado nem para a Unreal, que abre o
 * app pelas rotas internas.
 *
 * O MAPA DA PÁGINA É O MAPA DO JOGO. Nada aqui é ilustração: as regiões, os 43 pontos, as
 * camadas e a opacidade da arte vêm de App\Core\WorldMap — o mesmo código do MapController —
 * lidos como um recruta os veria no primeiro dia (estado operacional padrão: Hunter, sem
 * contrato). Se o operador mover um ponto no editor do painel, a landing muda junto.
 *
 * As CRIATURAS seguem a mesma regra: saem de app/Data/criaturas.php, a fonte única do enredo v6.
 */
final class LandingController extends Controller
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        if (is_authenticated()) {
            return $this->redirect('inicio');
        }

        $data = data_mock('landing');
        $mapa = data_mock('mapa');                  // cromo do mapa: legenda, controles, rótulos, bússola
        $op   = OperationalRepo::defaults();

        $regions = WorldMap::regions(false);
        if ($regions === []) {
            $regions = WorldMap::fromMock((array)($mapa['regions'] ?? []), false);
        }
        $ativa = $regions[0] ?? [];
        $slug  = (string)($ativa['slug'] ?? 'floresta-verde');
        $mapId = isset($ativa['map_id']) && $ativa['map_id'] !== null ? (int)$ativa['map_id'] : null;

        $bases   = WorldMap::bases($mapId);
        $markers = $mapId !== null
            ? WorldMap::markers($mapId, $op, $bases)
            : WorldMap::mockMarkers((array)(($mapa['markers'] ?? [])[$slug] ?? []), $op, $bases);

        $extracoes = 0;
        foreach ($markers as $m) { if (($m['layer'] ?? '') === 'extracao') { $extracoes++; } }

        $data['regions']       = $regions;
        $data['region']        = $ativa;
        $data['active_region'] = $slug;
        $data['markers']       = $markers;
        $data['bases']         = $bases;
        $data['map_image']     = (string)(($ativa['world_image'] ?? '') ?: ($mapa['map_image'] ?? 'bg/map-world.webp'));
        $data['map_alt']       = (string)($mapa['map_alt'] ?? 'Mapa do mundo');
        $data['map_opacity']   = WorldMap::opacidade($ativa);
        foreach (['world_labels', 'controls', 'compass', 'legend', 'legend_all'] as $k) {
            $data[$k] = $mapa[$k] ?? [];
        }

        /* As criaturas vêm do catálogo canônico (o mesmo do Bestiário logado e do mapa), não de
           uma cópia nesta página: mudou o canon, a landing acompanha sem ninguém lembrar dela. */
        $data['criaturas'] = data_mock('criaturas');

        /* Números do herói e da seção do mundo: contados do que está no banco, não escritos. */
        $data['contagens'] = [
            'regioes'   => count($regions),
            'pontos'    => count($markers),
            'bases'     => count($bases),
            'extracoes' => $extracoes,
        ];

        $page = $this->page('landing', (string)$data['title'], [
            'bg'          => 'bg/home-hero-wide.jpg',
            'description' => (string)$data['description'],
            'three'       => ['atmosphere' => false, 'map' => true],
        ]);
        return $this->render('landing', $page, $data, 'landing');
    }
}
