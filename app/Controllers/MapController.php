<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\GameRules;
use App\Core\Request;
use App\Core\Response;
use App\Core\WorldMap;
use App\Repo\SettingsRepo;

/**
 * MAPA DO MUNDO — PRD v3 §23.
 *
 * O mapa é a leitura do mundo persistente: regiões (`maps` kind=region) com estado do setor
 * (`maps.meta`: capacidade 20, infestação, atividade Veyra/humana), marcadores (`map_markers`)
 * agrupados em camadas filtráveis, 3 Bases Hunter com estado (nunca vagas exatas), instalações
 * de facção cujo status depende do contrato do jogador, e extrações tipadas — todas
 * BLOQUEADAS quando o jogador é Sobrevivente (§23.7).
 *
 * A MONTAGEM em si (regiões, marcadores, leitura por estado) vive em App\Core\WorldMap, porque a
 * página de divulgação desenha o MESMO mapa. Aqui fica só o que é desta tela: escolher a região
 * pela URL, aplicar a preferência de opacidade do jogador e renderizar.
 */
final class MapController extends Controller
{
    /**
     * Opacidade da arte: chave da preferência do jogador, padrão e piso.
     *
     * O piso existe porque abaixo dele não há mais mapa na tela — e quem arrastasse o controle
     * até o fim ficaria com um retângulo preto sem entender o que aconteceu. O padrão é o
     * mesmo número de MapEditorController: é ele que vale quando nem o mapa nem o jogador
     * disseram nada.
     */
    private const PREF_OPACIDADE   = 'mapa.opacidade';
    private const OPACIDADE_PADRAO = WorldMap::OPACIDADE_PADRAO;
    private const OPACIDADE_MIN    = WorldMap::OPACIDADE_MIN;

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        // Textos/labels de layout continuam no Data; regiões e marcadores vêm do banco.
        $data = data_mock('mapa');
        $op   = $this->operational();
        $survivor = ($op['state'] ?? '') === GameRules::STATE_SURVIVOR;

        $regions = WorldMap::regions($survivor);
        if ($regions === []) {
            // sem banco: o fallback do mock já traz `state`/extrações tipadas
            $regions = WorldMap::fromMock((array)($data['regions'] ?? []), $survivor);
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

        $bases   = WorldMap::bases($activeMapId);
        $markers = $activeMapId !== null ? WorldMap::markers($activeMapId, $op, $bases) : [];
        if ($markers === [] && $activeMapId === null) {
            $markers = WorldMap::mockMarkers((array)(($data['markers'] ?? [])[$slug] ?? []), $op, $bases);
        }

        /* A arte do mundo vem do próprio mapa (`maps.meta.world_image`), e não de uma constante
           no cromo: é o editor do painel que decide sobre qual imagem se está posicionando, e
           um dia haverá mais de uma ilha. O mock continua valendo quando a chave não existe. */
        $arte = (string)(($data['region']['world_image'] ?? '') ?: '');
        if ($arte !== '') { $data['map_image'] = $arte; }

        /* Quanto a arte recua para o texto da HUD ser lido por cima dela.
         *
         * DUAS FONTES, NESTA ORDEM:
         *   1. o MAPA (`maps.meta.map_opacity`), ajustado no editor do painel — é o padrão,
         *      porque cada arte tem o seu contraste: uma ilha de neve precisa recuar mais do
         *      que uma de floresta;
         *   2. o JOGADOR (`player_settings['mapa.opacidade']`), que vence quando existe. Quem
         *      joga no escuro e quem joga com o sol na tela não enxergam o mesmo mapa, e essa
         *      é uma preferência de conforto — não uma decisão de arte.
         *
         * O limite 0,15..1 é aplicado AQUI, não só nos formulários: um valor estragado no JSON
         * ou na tabela deixaria o mapa invisível e ninguém saberia por quê.
         */
        $limite = static fn(float $v): float => max(self::OPACIDADE_MIN, min(1.0, $v));

        $doMapa = $data['region']['map_opacity'] ?? null;
        $padrao = $doMapa !== null ? $limite((float)$doMapa) : self::OPACIDADE_PADRAO;

        $doJogador = SettingsRepo::forUser(current_user_id())[self::PREF_OPACIDADE] ?? null;
        $data['map_opacity']        = is_numeric($doJogador) ? $limite((float)$doJogador) : $padrao;
        $data['map_opacity_padrao'] = $padrao;
        $data['map_opacity_min']    = self::OPACIDADE_MIN;

        $data['markers']       = $markers;
        $data['bases']         = $bases;
        $data['faction_sites'] = WorldMap::factionSites($markers);
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
     * Grava (ou devolve ao padrão) a opacidade da arte ESCOLHIDA PELO JOGADOR.
     *
     * POST /mapa/opacidade — `valor` em 0..1, ou `reset=1` para voltar ao que o painel definiu.
     *
     * TRÊS DECISÕES:
     *
     * 1. Voltar ao padrão é APAGAR a linha, não gravar o número do padrão. Se o operador
     *    reajustar a arte no painel amanhã, quem nunca mexeu no controle acompanha; quem
     *    tivesse o valor congelado ficaria preso ao contraste de hoje sem saber por quê.
     *
     * 2. Responde JSON para o fetch do controle e REDIRECIONA para um POST de formulário. O
     *    controle é um <input type="range"> com botão de aplicar, então sem JavaScript ele
     *    continua funcionando — e nesse caminho a resposta certa é a própria tela de volta,
     *    não um punhado de JSON.
     *
     * 3. Sem sessão de verdade não grava nada: a preferência é da CONTA. O LoginGuard já
     *    barra anônimo nesta rota, e a checagem aqui é a segunda linha.
     *
     * @param array<string, string> $params
     */
    public function opacidade(Request $request, array $params = []): Response
    {
        $this->csrf($request);

        $uid = current_user_id();
        if ($uid <= 0 || !is_authenticated()) {
            return $this->respostaOpacidade($request, false, 'Entre na sua conta para guardar essa preferência.');
        }

        if ((string)$request->input('reset', '') === '1') {
            SettingsRepo::reset($uid, [self::PREF_OPACIDADE]);
            return $this->respostaOpacidade($request, true, 'Opacidade do mapa de volta ao padrão.');
        }

        $bruto = (string)$request->input('valor', '');
        if (!is_numeric($bruto)) {
            return $this->respostaOpacidade($request, false, 'Valor de opacidade inválido.');
        }
        $valor = round(max(self::OPACIDADE_MIN, min(1.0, (float)$bruto)), 2);

        SettingsRepo::save($uid, [self::PREF_OPACIDADE => (string)$valor]);
        return $this->respostaOpacidade($request, true, 'Opacidade do mapa guardada na sua conta.');
    }

    /** JSON para o controle; a tela de volta para quem postou sem JavaScript. */
    private function respostaOpacidade(Request $request, bool $ok, string $msg): Response
    {
        if ($request->isAjax() || Request::wantsPartial()) {
            return Response::json(['ok' => $ok, 'message' => $msg], $ok ? 200 : 422);
        }
        flash($ok ? 'ok' : 'error', $msg);
        return $this->redirect('mapa' . (($r = (string)$request->input('regiao', '')) !== '' ? '/' . $r : ''));
    }

}
