<?php declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Repo\AdminRepo;

/**
 * Tela principal do painel: /admin.
 *
 * O controller não calcula NADA. Ele escolhe a janela do gráfico, pede os oito blocos ao
 * AdminRepo (que é onde vive o SQL e onde dá para conferir cada número fora do navegador) e
 * entrega para a view desenhar. Se um número da tela estiver errado, o defeito está no repo
 * ou no banco — nunca aqui e nunca na view.
 *
 * O único trabalho de verdade daqui é decidir quais ATALHOS podem virar link: um atalho para
 * uma tela que ainda não tem rota levaria o operador a um 404, então a existência é conferida
 * contra a própria tabela de rotas. Quando outro agente registrar /admin/itens, o atalho
 * acende sozinho.
 */
final class DashboardController extends AdminController
{
    /** Janelas aceitas em ?dias=N. Fora da lista, cai para 7 — a URL não define consulta. */
    private const JANELAS = [7, 14, 30];

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }

        $dias = (int)($request->query('dias') ?? 0);
        if (!in_array($dias, self::JANELAS, true)) {
            $dias = self::JANELAS[0];
        }

        $cromo      = data_mock('admin-dashboard');
        $servidores = AdminRepo::statusServidores();
        $kpis       = AdminRepo::kpis();

        // "Ver tudo" para tela que ainda não existe não vira link: vira promessa apagada.
        $cromo['links'] = $this->marcarLinks((array)($cromo['links'] ?? []));

        $page = $this->adminPage('dashboard', 'Painel Admin', 'Visão geral do seu jogo');

        return $this->render($page['slug'], $page, [
            'cromo'       => $cromo,
            'admin_conta' => $this->admin(),
            'dias'        => $dias,

            'kpis'        => $kpis,
            'serie'       => AdminRepo::serieOnline($dias),
            'servidores'  => $servidores,
            'ranking'     => AdminRepo::topJogadores(5),
            'receita'     => AdminRepo::receita(30),
            // Três eventos e quatro entradas de atividade: é o QUE CABE INTEIRO nos dois
            // cartões, em 1600×900 e em 1366×768. Os cartões desenham uma grade de linhas
            // iguais que divide a altura disponível, sem rolagem — então pedir mais do que
            // isso não esconderia nada atrás de uma barra, apenas apertaria cada linha até
            // ela deixar de ser legível. Enquanto não existir /admin/logs e /admin/eventos
            // para levar ao resto, o painel mostra o que consegue mostrar por inteiro.
            'eventos'     => AdminRepo::eventosAtivos(3),
            'atividade'   => AdminRepo::atividadeRecente(4),
            'sistema'     => AdminRepo::statusSistema(),
            'acoes'       => $this->atalhos($cromo['acoes'] ?? []),

            // A casca do painel (layouts/admin.php) lê estas duas chaves.
            'admin_kpis'   => $this->kpisDaTopbar($kpis, $servidores),
            'admin_alerts' => $this->alertas($servidores),
        ]);
    }

    /**
     * Marca cada atalho com `disponivel`, conferindo a rota contra app/routes.php.
     *
     * @param list<array<string,mixed>> $acoes
     * @return list<array<string,mixed>>
     */
    private function atalhos(array $acoes): array
    {
        foreach ($acoes as $i => $acao) {
            $acoes[$i]['disponivel'] = Router::existeGet((string)($acao['rota'] ?? ''));
        }
        return $acoes;
    }

    /**
     * Marca cada link de rodapé com `disponivel`. Diferente da rodada anterior, o link NÃO
     * some mais quando a rota falta: o cartão continua dizendo para onde ele leva (é isso que
     * a referência entrega), só que apagado e sem href — a mesma regra da navegação.
     *
     * @param array<string,array<string,mixed>> $links
     * @return array<string,array<string,mixed>>
     */
    private function marcarLinks(array $links): array
    {
        foreach ($links as $chave => $link) {
            $links[$chave]['disponivel'] = Router::existeGet((string)($link['rota'] ?? ''));
        }
        return $links;
    }

    /**
     * Os três indicadores compactos da topbar. Não repetem cegamente os cartões: o terceiro
     * seria "uptime" na referência de layout, mas uptime não é medido em lugar nenhum, então
     * o lugar é ocupado por um número que existe de verdade — serviços no ar.
     *
     * @param list<array<string,mixed>> $kpis
     * @param array<string,mixed> $servidores
     * @return list<array<string,mixed>>
     */
    private function kpisDaTopbar(array $kpis, array $servidores): array
    {
        $por = [];
        foreach ($kpis as $k) {
            $por[(string)$k['chave']] = $k;
        }

        $online  = $por['online']  ?? null;
        $receita = $por['receita'] ?? null;

        $noAr = 0;
        foreach ($servidores['servicos'] ?? [] as $s) {
            $noAr += ($s['estado'] ?? '') === 'online' ? 1 : 0;
        }
        $total = count($servidores['servicos'] ?? []);

        return [
            [
                'icon'  => 'group',
                'label' => 'Jogadores online',
                'value' => $online !== null ? num((int)$online['valor']) : null,
                'sub'   => $online['sub'] ?? null,
                'tone'  => 'lime',
            ],
            [
                'icon'  => 'payments',
                'label' => 'Receita (mês)',
                'value' => $receita !== null ? 'R$ ' . num(((int)$receita['valor']) / 100, 2) : null,
                'tone'  => 'purple',
            ],
            [
                'icon'  => 'dns',
                'label' => 'Serviços no ar',
                'value' => $total > 0 ? $noAr . '/' . $total : null,
                'tone'  => $total > 0 && $noAr === $total ? 'cyan' : 'amber',
            ],
        ];
    }

    /**
     * O sino só toca por fato registrado no banco: serviço fora do padrão, janela de
     * manutenção que começa nas próximas 48 h e falhas de login no painel nas últimas 24 h.
     * Sem nada disso, o sino fica mudo — não existe alerta decorativo.
     *
     * @param array<string,mixed> $servidores
     * @return list<array<string,mixed>>
     */
    private function alertas(array $servidores): array
    {
        $alertas = [];

        foreach ($servidores['servicos'] ?? [] as $s) {
            if (($s['estado'] ?? 'online') === 'online') {
                continue;
            }
            $alertas[] = [
                'icon' => $s['estado'] === 'fora' ? 'error' : 'build',
                'tone' => $s['estado'] === 'fora' ? 'red' : 'amber',
                'text' => $s['label'] . ': ' . $s['rotulo'],
                'meta' => $s['escopo'] ?? null,
            ];
        }

        foreach ($servidores['regioes'] ?? [] as $r) {
            if (($r['nota'] ?? null) === null) {
                continue;
            }
            $alertas[] = [
                'icon' => 'schedule',
                'tone' => in_array($r['estado'], ['manutencao', 'agendada'], true) ? 'amber' : 'cyan',
                'text' => $r['label'] . ': ' . $r['nota'],
                'meta' => null,
            ];
        }

        $falhas = AdminRepo::falhasRecentes($this->ip(), 24 * 60);
        if ($falhas['n'] > 0) {
            $alertas[] = [
                'icon' => 'shield_lock',
                'tone' => 'red',
                'text' => $falhas['n'] . ' falha(s) de login no painel neste IP nas últimas 24 h',
                'meta' => 'Última: ' . date('d/m H:i', $falhas['ultima']),
            ];
        }

        return $alertas;
    }
}
