<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repo\BestiaryRepo;

/**
 * Tela BESTIÁRIO (/bestiario[/{grupo}]) — as criaturas do enredo v6 e o que o jogador já
 * registrou de cada uma.
 *
 * O catálogo é app/Data/criaturas.php (o mesmo da landing); o progresso é `player_bestiary`.
 * A view recebe tudo pronto: cada criatura já vem com o nível do jogador, o retrato (ou null
 * enquanto a arte não foi gerada) e a abordagem (como lidar) resolvida em rótulo + cor.
 */
final class BestiaryController extends Controller
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $cat     = data_mock('criaturas');
        $grupos  = (array)($cat['grupos'] ?? []);
        $grupo   = $this->pick($params['grupo'] ?? null, array_keys($grupos), 'todas');
        $liberar = (bool)($cat['liberar_tudo'] ?? false);
        $reg     = BestiaryRepo::registros($this->userId());

        $abord = (array)($cat['abordagens'] ?? []);
        $lista = [];
        $porGrupo = [];
        $registradas = 0;
        foreach ((array)($cat['lista'] ?? []) as $c) {
            $slug  = (string)$c['slug'];
            $r     = $reg[$slug] ?? null;
            $nivel = BestiaryRepo::nivel($r, $liberar);
            if ($nivel !== 'desconhecido') {
                $registradas++;
            }
            $g = (string)$c['grupo'];
            $porGrupo[$g] = ($porGrupo[$g] ?? 0) + 1;

            $lista[] = $c + [
                'nivel'    => $nivel,
                'img'      => creature_img($slug),
                'registro' => $r ?? ['sightings' => 0, 'kills' => 0, 'studied' => false, 'first_seen_at' => null],
                'lidar'    => (array)($abord[(string)($c['abordagem'] ?? '')] ?? ['nome' => '', 'cor' => 'lime', 'ms' => 'help', 'texto' => '']),
            ];
        }

        // A criatura aberta: ?c=<slug> (link compartilhável), senão a primeira do filtro.
        $visiveis = $grupo === 'todas' ? $lista : array_values(array_filter($lista, static fn ($c) => $c['grupo'] === $grupo));
        $sel = (string)$request->query('c', '');
        $selecionada = $visiveis[0]['slug'] ?? '';
        foreach ($visiveis as $c) {
            if ($c['slug'] === $sel) { $selecionada = $sel; }
        }

        $data = [
            'grupos'      => $grupos,
            'grupo'       => $grupo,
            'por_grupo'   => $porGrupo,
            'lista'       => $visiveis,
            'total'       => count($lista),
            'registradas' => $registradas,
            'selecionada' => $selecionada,
            'niveis'      => (array)($cat['niveis'] ?? []),
            'liberar'     => $liberar,
            'regioes'     => self::REGIOES,
            'estudo_abates' => BestiaryRepo::ESTUDO_ABATES,
        ];

        $page = $this->page('bestiario', 'Bestiário', ['bg' => 'bg/forest-generic.jpg']);
        return $this->render('bestiario', $page, $data);
    }

    /**
     * POST /bestiario/registrar  {criatura, evento: avistado|abate|amostra}
     *
     * Para o jogo reportar o que o operador encontrou em campo. Mesmo CSRF de toda escrita
     * da HUD (a página carregada no WBP manda o token do <meta>).
     */
    public function registrar(Request $request, array $params = []): Response
    {
        $this->csrf($request);
        $slugs = array_map(static fn ($c) => (string)$c['slug'], (array)(data_mock('criaturas')['lista'] ?? []));
        $ok = BestiaryRepo::registrar(
            $this->userId(),
            (string)$request->input('criatura', ''),
            (string)$request->input('evento', ''),
            $slugs
        );
        return Response::json(['ok' => $ok], $ok ? 200 : 422);
    }

    /** slug de região (maps.slug) -> nome curto exibido no habitat. */
    private const REGIOES = [
        'floresta-verde'     => 'FLORESTA VERDE',
        'pantano-cinzento'   => 'PÂNTANO CINZENTO',
        'montanhas-gelidas'  => 'MONTANHAS GÉLIDAS',
        'deserto-abandonado' => 'DESERTO ABANDONADO',
        'zona-industrial'    => 'ZONA INDUSTRIAL',
    ];
}
