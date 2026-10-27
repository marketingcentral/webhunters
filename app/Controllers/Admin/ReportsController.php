<?php declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Repo\ReportsRepo;
use App\Repo\SupportRepo;

/**
 * Tela RELATÓRIOS do painel: /admin/relatorios.
 *
 * Governa `player_bug_reports` (o que o jogador manda pela tela RELATÓRIO DE BUG) e
 * `support_tickets` (o que ele abre pela tela SUPORTE). Duas abas, a mesma lista, o mesmo
 * detalhe — o que muda entre elas é o vocabulário de cada tabela, e isso mora no repositório
 * (App\Repo\ReportsRepo) e nos rótulos (app/Data/admin-relatorios.php).
 *
 * O DETALHE É SERVIDO, NÃO ABERTO POR JAVASCRIPT
 * ----------------------------------------------
 * O relatório escolhido viaja na URL (`?ver=12`) e vem renderizado do servidor. Três motivos:
 * o operador consegue MANDAR o link de um bug para outra pessoa; a tela continua funcionando
 * com o JavaScript desligado (a CSP do projeto é fechada e o painel não carrega framework
 * nenhum); e é assim que ela é conferível por `curl`, que é como este painel é testado.
 *
 * ESCRITA
 * -------
 * `salvar()` muda o estado de UM relatório e `excluir()` apaga um relatório JÁ ENCERRADO.
 * As duas passam por CSRF, revalidam tudo contra a whitelist do repositório e auditam. A
 * resposta é sempre um redirect (POST/Redirect/GET): sem isso, um F5 depois de fechar um
 * chamado o fecharia de novo — e, na exclusão, tentaria apagar um id que já não existe.
 */
final class ReportsController extends AdminController
{
    /** Linhas por página. Vinte é o que cabe na lista sem a página inteira rolar em 1366×768. */
    private const POR_PAGINA = 20;

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }

        $cromo   = data_mock('admin-relatorios');
        $aba     = $this->aba((string)$request->query('aba', ''));
        $filtros = $this->filtros($request, $aba, false);
        $pagina  = max(1, (int)$request->query('pag', '1'));

        $lista     = ReportsRepo::listar($aba, $filtros, $pagina, self::POR_PAGINA);
        $contagens = ReportsRepo::contagemPorEstado($aba, $filtros);
        $resumo    = ReportsRepo::resumo();

        /* O relatório aberto no painel de detalhe. Um `ver` de outra aba (link antigo, ou o
           operador trocou de aba com o detalhe aberto) simplesmente não abre nada — melhor o
           convite do painel vazio do que um 404 em cima de uma lista que está correta. */
        $detalhe = ReportsRepo::detalhe($aba, (int)$request->query('ver', '0'));

        $page = $this->adminPage(
            'relatorios',
            'Relatórios',
            (string)($cromo['abas'][$aba]['sub'] ?? 'Bugs e chamados de suporte')
        );

        return $this->render($page['slug'], $page, [
            'cromo'      => $cromo,
            'aba'        => $aba,
            'filtros'    => $filtros,
            'lista'      => $lista,
            'contagens'  => $contagens,
            'detalhe'    => $detalhe,
            'grupos'     => $this->grupos($aba),
            'pesos'      => $this->pesos($aba, $cromo),
            'resumo'     => $resumo,
            'erro'       => (string)$request->query('erro', ''),
            /* A exclusão é confirmada em DUAS requisições, e não num `confirm()` de JavaScript:
               a CSP do projeto proíbe manipulador inline, e uma confirmação que depende de JS
               deixa de existir justamente quando o JS falha — apagando sem perguntar. */
            'confirmar'  => (int)$request->query('confirmar', '0'),
            'admin_kpis' => $this->kpisTopo($resumo),
        ]);
    }

    /**
     * POST /admin/relatorios/salvar — muda o estado de um relatório.
     *
     * Só existe UM campo editável nesta tela de propósito: o texto do relatório é o que o
     * jogador escreveu, e um painel que reescreve o relato do jogador destrói a prova do bug.
     */
    public function salvar(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $aba  = $this->aba((string)$request->input('aba', ''));
        $id   = (int)$request->input('id', '0');
        /* `novo` e não `status`: o formulário carrega TAMBÉM o filtro `status` da listagem,
           escondido, para o redirect devolver o operador ao recorte de onde ele saiu. */
        $novo  = (string)$request->input('novo', '');
        $volta = $this->filtros($request, $aba, true) + ['aba' => $aba, 'ver' => (string)$id];

        $atual = ReportsRepo::detalhe($aba, $id);
        if ($atual === null) {
            return $this->voltar($volta, 'nao_encontrado');
        }
        if (!in_array($novo, ReportsRepo::estados($aba), true)) {
            return $this->voltar($volta, 'status_invalido');
        }
        if ($novo === $atual['status']) {
            // Não é erro de sistema, é um clique sem efeito — a mensagem diz isso e a tela
            // marca o campo, em vez de gravar uma linha de auditoria que não mudou nada.
            return $this->voltar($volta, 'status_igual');
        }

        if (!ReportsRepo::mudarEstado($aba, $id, $novo)) {
            return $this->voltar($volta, 'nao_encontrado');
        }

        $this->auditar(
            'relatorio_estado',
            $aba === 'bugs' ? 'bug' : 'ticket',
            $id,
            sprintf('%s: %s → %s', $atual['codigo'], $atual['status'], $novo),
            [
                'aba'     => $aba,
                'codigo'  => $atual['codigo'],
                'de'      => $atual['status'],
                'para'    => $novo,
                'user_id' => $atual['user_id'],
            ]
        );

        flash('ok', sprintf(
            '%s agora está como %s.',
            $atual['codigo'],
            (string)(data_mock('admin-relatorios')['status'][$aba][$novo]['rotulo'] ?? $novo)
        ));
        return $this->voltar($volta);
    }

    /**
     * POST /admin/relatorios/excluir — apaga um relatório encerrado.
     *
     * DUAS TRAVAS, e as duas existem por um motivo diferente:
     *   1. só encerrado. Um relatório em aberto é trabalho pendente de alguém; apagar é a
     *      forma silenciosa de fechar sem resolver. Quem quiser sumir com ele, encerra antes
     *      e a mudança de estado fica na auditoria;
     *   2. o conteúdo inteiro vai para o `meta` da auditoria ANTES do DELETE. A linha some da
     *      conta do jogador (é isso que a exclusão faz), mas o painel não perde a evidência —
     *      um relatório apagado por engano continua legível na tela de Logs.
     */
    public function excluir(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $aba   = $this->aba((string)$request->input('aba', ''));
        $id    = (int)$request->input('id', '0');
        $volta = $this->filtros($request, $aba, true) + ['aba' => $aba];

        $alvo = ReportsRepo::detalhe($aba, $id);
        if ($alvo === null) {
            return $this->voltar($volta + ['ver' => (string)$id], 'nao_encontrado');
        }
        if (!ReportsRepo::encerra($aba, (string)$alvo['status'])) {
            return $this->voltar($volta + ['ver' => (string)$id], 'excluir_aberto');
        }

        $this->auditar(
            'relatorio_excluido',
            $aba === 'bugs' ? 'bug' : 'ticket',
            $id,
            sprintf('%s excluído (%s)', $alvo['codigo'], $alvo['titulo']),
            [
                'aba'       => $aba,
                'codigo'    => $alvo['codigo'],
                'titulo'    => $alvo['titulo'],
                'estado'    => $alvo['status'],
                'grupo'     => $alvo['grupo'],
                'peso'      => $alvo['peso'],
                'user_id'   => $alvo['user_id'],
                'jogador'   => $alvo['jogador'],
                'aberto_em' => date('c', (int)$alvo['criado']),
                'texto'     => mb_substr((string)($alvo['descricao'] ?? ''), 0, 4000),
                'passos'    => mb_substr((string)($alvo['passos'] ?? ''), 0, 4000),
            ]
        );

        if (!ReportsRepo::excluir($aba, $id)) {
            return $this->voltar($volta + ['ver' => (string)$id], 'nao_encontrado');
        }

        flash('ok', $alvo['codigo'] . ' foi excluído. O conteúdo ficou guardado na auditoria.');
        return $this->voltar($volta);   // sem `ver`: o detalhe aberto acabou de deixar de existir
    }

    /* ===================================================================== apoio */

    /** Aba pedida, ou a primeira. Valor desconhecido não é 404 — é a aba padrão. */
    private function aba(string $pedida): string
    {
        $abas = ReportsRepo::abas();
        return in_array($pedida, $abas, true) ? $pedida : $abas[0];
    }

    /**
     * Filtros da requisição, já validados contra as listas fechadas. O que não passar sai
     * como string vazia — um filtro inválido não pode virar uma lista vazia inexplicável.
     *
     * @return array<string,string> q, status, grupo, peso, ord, pag
     */
    private function filtros(Request $request, string $aba, bool $doPost): array
    {
        $ler = static fn(string $k): string => $doPost
            ? (string)$request->input($k, '')
            : (string)$request->query($k, '');

        $status = $ler('status');
        $grupo  = $ler('grupo');
        $peso   = $ler('peso');
        $ord    = $ler('ord');

        $pesosOk = $aba === 'bugs'
            ? array_keys((array)(data_mock('admin-relatorios')['severidades'] ?? []))
            : array_keys((array)(data_mock('admin-relatorios')['prioridades'] ?? []));

        return [
            // 80 caracteres: acima disso é colagem acidental, não busca — e o LIKE não
            // melhora com um parágrafo inteiro dentro.
            'q'      => mb_substr(trim($ler('q')), 0, 80),
            'status' => in_array($status, ReportsRepo::estados($aba), true) ? $status : '',
            // A categoria é conferida contra o que EXISTE na tabela, e não contra o catálogo:
            // um valor gravado por uma build antiga precisa continuar filtrável.
            'grupo'  => in_array($grupo, ReportsRepo::gruposEmUso($aba), true) ? $grupo : '',
            'peso'   => in_array($peso, $pesosOk, true) ? $peso : '',
            'ord'    => in_array($ord, ['recentes', 'antigos', 'peso'], true) ? $ord : 'recentes',
            'pag'    => (string)max(1, (int)$ler('pag')),
        ];
    }

    /**
     * Opções do seletor de CATEGORIA: os valores que existem na tabela, com o rótulo que o
     * jogador vê. As áreas de bug saem de app/Data/relatorio-de-bug.php e as categorias de
     * chamado da tabela support_categories — as mesmas fontes das telas do jogo. Um valor
     * gravado por uma build antiga, fora do catálogo, aparece com o próprio nome em vez de
     * sumir do filtro.
     *
     * @return list<array{valor:string,rotulo:string}>
     */
    private function grupos(string $aba): array
    {
        $rotulos = [];
        if ($aba === 'bugs') {
            foreach ((array)(data_mock('relatorio-de-bug')['areas'] ?? []) as $a) {
                $rotulos[(string)$a['slug']] = (string)$a['label'];
            }
        } else {
            try {
                foreach (SupportRepo::categories() as $c) {
                    $rotulos[$c['slug']] = $c['label'];
                }
            } catch (\Throwable) {
                // Catálogo de suporte indisponível: os slugs crus ainda filtram.
            }
        }

        $out = [];
        foreach (ReportsRepo::gruposEmUso($aba) as $valor) {
            $out[] = ['valor' => $valor, 'rotulo' => $rotulos[$valor] ?? mb_strtoupper($valor)];
        }
        return $out;
    }

    /**
     * Opções do seletor de GRAVIDADE (bugs) ou PRIORIDADE (chamados), na ordem do mais grave
     * para o menos — a mesma da ordenação "por gravidade".
     *
     * @param array<string,mixed> $cromo
     * @return list<array{valor:string,rotulo:string,tom:string}>
     */
    private function pesos(string $aba, array $cromo): array
    {
        $mapa = (array)($aba === 'bugs' ? ($cromo['severidades'] ?? []) : ($cromo['prioridades'] ?? []));
        $out  = [];
        foreach ($mapa as $valor => $meta) {
            $out[] = [
                'valor'  => (string)$valor,
                'rotulo' => (string)($meta['rotulo'] ?? $valor),
                'tom'    => (string)($meta['tom'] ?? 'muted'),
            ];
        }
        return $out;
    }

    /**
     * Os três KPIs da topbar. São a FILA — não o total: o total de chamados de todos os
     * tempos não faz ninguém agir, e a topbar é o lugar de onde se decide o que abrir.
     *
     * @param array<string,int> $r
     * @return list<array<string,mixed>>
     */
    private function kpisTopo(array $r): array
    {
        return [
            ['icon' => 'bug_report',    'label' => 'Bugs em aberto',     'value' => num($r['bugs_abertos']),
             'tone' => $r['bugs_abertos'] > 0 ? 'amber' : 'lime',
             'sub'  => '/ ' . num($r['bugs_total'])],
            ['icon' => 'support_agent', 'label' => 'Chamados em aberto', 'value' => num($r['tickets_abertos']),
             'tone' => $r['tickets_abertos'] > 0 ? 'cyan' : 'lime',
             'sub'  => '/ ' . num($r['tickets_total'])],
            ['icon' => 'priority_high', 'label' => 'Alta e crítica',     'value' => num($r['criticos']),
             'tone' => $r['criticos'] > 0 ? 'red' : 'lime'],
        ];
    }

    /**
     * Volta para a listagem no MESMO recorte de onde o POST saiu (aba, busca, filtros, página
     * e relatório aberto). Sem isso, fechar o quinto chamado da página 3 devolvia o operador
     * para a página 1 sem filtro nenhum, e ele tinha que refazer o caminho a cada ação.
     *
     * @param array<string,string> $estado
     */
    private function voltar(array $estado, string $erro = ''): Response
    {
        if ($erro !== '') {
            flash('error', (string)(data_mock('admin-relatorios')['erros'][$erro] ?? 'Não foi possível concluir a ação.'));
            $estado['erro'] = $erro;
        }
        // Só o que muda alguma coisa entra na URL: uma barra de endereços com seis parâmetros
        // vazios é impossível de ler e de comparar quando algo dá errado.
        $limpo = array_filter($estado, static fn(string $v, string $k): bool =>
            $v !== '' && $v !== '0' && !($k === 'ord' && $v === 'recentes') && !($k === 'pag' && $v === '1'),
            ARRAY_FILTER_USE_BOTH);

        return $this->redirect('admin/relatorios', $limpo);
    }
}
