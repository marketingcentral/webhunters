<?php declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Repo\AuditRepo;

/**
 * Tela Logs do Sistema do painel: /admin/logs.
 *
 * Governa: admin_audit_log (LEITURA) + users (só para dar nome a quem fez).
 *
 * ESTA TELA NÃO ESCREVE — E ISSO É A FUNCIONALIDADE
 * -------------------------------------------------
 * A trilha de auditoria é a prova do que o painel fez. Uma tela capaz de editar ou apagar
 * essa prova destruiria o motivo dela existir: bastaria uma linha excluída para nenhuma das
 * outras valer como evidência. Por isso `salvar()` e `excluir()` existem (as rotas POST já
 * estão registradas em app/routes.php e um método ausente viraria LogicException, ou seja,
 * 500) mas RECUSAM — e a própria recusa vira uma linha na trilha.
 *
 * O controller não monta SQL e não conta nada: ele normaliza o que veio da URL, pede as
 * quatro leituras ao App\Repo\AuditRepo e entrega para a view desenhar. Número errado na
 * tela é defeito do repo ou do banco, nunca daqui.
 */
final class LogsController extends AdminController
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }

        $filtro = AuditRepo::normalizar([
            'q'    => $request->query('q'),
            'acao' => $request->query('acao'),
            'quem' => $request->query('quem'),
            'de'   => $request->query('de'),
            'ate'  => $request->query('ate'),
            'ord'  => $request->query('ord'),
            'dir'  => $request->query('dir'),
            'pag'  => $request->query('pag'),
            'log'  => $request->query('log'),
        ]);

        $lista   = AuditRepo::pagina($filtro);
        $opcoes  = AuditRepo::opcoes();
        // O resumo lateral só é consultado quando há o que resumir: com o recorte vazio (ou
        // com o banco fora), as duas agregações seriam duas varreduras para devolver zero.
        $resumo  = $lista['total'] > 0
            ? AuditRepo::resumo($filtro)
            : ['acoes' => [], 'contas' => 0, 'primeiro' => '', 'ultimo' => ''];

        // O detalhe é um id na URL (e não um clique com JavaScript) para o registro aberto ser
        // um endereço: o operador copia a barra e manda para outra pessoa ver a MESMA linha.
        $detalhe = $filtro['log'] > 0 ? AuditRepo::porId($filtro['log']) : null;
        if ($filtro['log'] > 0 && $detalhe === null) {
            flash('error', (string)(data_mock('admin-logs')['detalhe']['nao_achado'] ?? 'Registro não encontrado.'));
        }

        $cromo = data_mock('admin-logs');
        $page  = $this->adminPage(
            'logs',
            'Logs do Sistema',
            (string)($cromo['micro']['somente_leitura'] ?? 'Somente leitura.')
        );

        return $this->render($page['slug'], $page, [
            'cromo'   => $cromo,
            'filtro'  => $filtro,
            'lista'   => $lista,
            'opcoes'  => $opcoes,
            'resumo'  => $resumo,
            'detalhe' => $detalhe,
            'tem_filtro' => AuditRepo::temFiltro($filtro),

            // A casca lê estas duas chaves. Os KPIs falam DESTA tela — repetir os do
            // dashboard aqui seria enfeite; o que importa numa auditoria é o tamanho da
            // trilha, o tamanho do recorte e quando foi a última coisa que aconteceu.
            'admin_kpis' => $this->kpisDaTopbar($opcoes, $lista, $resumo),
        ]);
    }

    /**
     * A trilha é apenas acrescida (por AdminRepo::registrar), nunca editada.
     *
     * @param array<string, string> $params
     */
    public function salvar(Request $request, array $params = []): Response
    {
        return $this->recusar($request, 'editar');
    }

    /**
     * Nem o dono do jogo apaga uma linha da trilha por aqui. Se um dia houver expurgo por
     * retenção legal, ele será um script de db/ com registro próprio — não um botão.
     *
     * @param array<string, string> $params
     */
    public function excluir(Request $request, array $params = []): Response
    {
        return $this->recusar($request, 'excluir');
    }

    /**
     * Recusa a escrita, registra a tentativa e devolve o operador para a tela.
     *
     * A ordem importa: a guarda de sessão e o CSRF vêm ANTES do registro, senão qualquer
     * site do mundo conseguiria escrever linhas na trilha do painel com um formulário oculto
     * — e poluir a auditoria já seria estrago suficiente.
     */
    private function recusar(Request $request, string $tentativa): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        // A tentativa vira registro: se um dia aparecer um POST aqui, a própria trilha
        // mostra quem tentou, quando e de onde.
        $this->auditar(
            'escrita_recusada',
            'admin_audit_log',
            $request->input('id'),
            'Tentativa de ' . $tentativa . ' na trilha de auditoria, recusada pela tela de logs.',
            ['rota' => $request->path(), 'tentativa' => $tentativa]
        );

        $cromo = data_mock('admin-logs');
        flash('error', (string)($cromo['micro']['somente_leitura'] ?? 'A trilha de auditoria é somente leitura.'));

        return $this->redirect('admin/logs');
    }

    /**
     * Os três indicadores compactos da topbar, todos medidos nesta requisição.
     *
     * @param array<string,mixed> $opcoes @param array<string,mixed> $lista @param array<string,mixed> $resumo
     * @return list<array<string,mixed>>
     */
    private function kpisDaTopbar(array $opcoes, array $lista, array $resumo): array
    {
        $ultimo = (string)($resumo['ultimo'] ?? '');

        return [
            [
                'icon'  => 'receipt_long',
                'label' => 'Registros na trilha',
                'value' => $lista['falha'] ? null : num((int)$opcoes['total']),
                'tone'  => 'lime',
            ],
            [
                'icon'  => 'filter_alt',
                'label' => 'No recorte',
                'value' => $lista['falha'] ? null : num((int)$lista['total']),
                'tone'  => 'cyan',
            ],
            [
                'icon'  => 'schedule',
                'label' => 'Último registro',
                'value' => $ultimo !== '' ? date('d/m H:i', (int)strtotime($ultimo)) : null,
                'tone'  => 'amber',
            ],
        ];
    }
}
