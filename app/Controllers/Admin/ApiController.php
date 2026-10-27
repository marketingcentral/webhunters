<?php declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Api\ApiController as ApiDespachante;
use App\Core\Api\Escopos;
use App\Core\Request;
use App\Core\Response;
use App\Repo\ApiKeyRepo;
use App\Repo\ApiLogRepo;

/**
 * Tela API do painel: /admin/api.
 *
 * Governa: `api_keys` (criar e revogar) + `api_request_log` (leitura) + a REFERÊNCIA dos
 * endpoints, que não vem de lugar nenhum além da própria API.
 *
 * A REFERÊNCIA NÃO É DOCUMENTAÇÃO ESCRITA À MÃO
 * ---------------------------------------------
 * Ela é montada por ApiDespachante::referencia(), que percorre os recursos vivos e pergunta a
 * cada um as rotas dele. Ou seja: é a MESMA lista que o despachante usa para atender. Um
 * endpoint novo aparece nesta tela no commit em que passa a existir, e um endpoint removido
 * some daqui no mesmo instante. Documentação copiada envelhece em silêncio e, quando
 * envelhece, ensina o operador a fazer uma chamada que a API não atende mais — que é pior do
 * que não documentar.
 *
 * O SEGREDO E O REDIRECT
 * ----------------------
 * Criar uma chave é POST e termina em redirect (o padrão desta casa: recarregar a página não
 * pode criar uma segunda chave). Mas o segredo só existe naquela requisição — depois do
 * redirect, ele não está mais em lugar nenhum de onde se possa lê-lo. Então ele viaja UMA vez
 * pela sessão do operador, em `$_SESSION['api_chave_nova']`, e é consumido na primeira
 * leitura. Não vai para a querystring (ficaria no log do servidor, no histórico do navegador
 * e no Referer) e não vai para o banco (o banco só tem o hash).
 */
final class ApiController extends AdminController
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }

        $estado = (string)$request->query('estado', '');
        $estado = isset(ApiKeyRepo::ESTADOS[$estado]) ? $estado : '';

        $chaves = ApiKeyRepo::pagina(max(1, (int)$request->query('pag', '1')), $estado);

        $filtroLog = [
            'chave'  => max(0, (int)$request->query('chave', '0')),
            'status' => in_array((string)$request->query('status', ''), ['ok', 'recusado', 'erro'], true)
                ? (string)$request->query('status') : '',
            'pag'    => max(1, (int)$request->query('lpag', '1')),
        ];

        $cromo = data_mock('admin-api');
        $page  = $this->adminPage('api', $cromo['titulo'], $cromo['subtitulo']);

        return $this->render($page['slug'], $page, [
            'cromo'      => $cromo,
            'chaves'     => $chaves,
            'contagens'  => ApiKeyRepo::contagens(),
            'estado'     => $estado,
            'log'        => ApiLogRepo::pagina($filtroLog),
            'filtro_log' => $filtroLog,
            'resumo'     => ApiLogRepo::resumo24h(),
            'escopos'    => Escopos::agrupados(),
            'referencia' => ApiDespachante::referencia(),
            // A origem absoluta, montada aqui e não na view: o exemplo de `curl` só serve se
            // puder ser copiado e colado inteiro, e para isso precisa de esquema e host —
            // que a view não tem de onde tirar sem ler $_SERVER.
            'origem'     => $this->origem(),
            'limite_padrao' => ApiKeyRepo::LIMITE_PADRAO,
            'limite_max'    => ApiKeyRepo::LIMITE_MAX,
            // A chave recém-criada, consumida na primeira leitura (ver o cabeçalho).
            'segredo'    => $this->consumirSegredo(),
            // Erros e valores do formulário que não passou, para ele voltar preenchido.
            'form'       => $this->consumir('api_form'),

            'admin_kpis' => $this->kpisDaTopbar(),
        ]);
    }

    /**
     * Cria uma chave. É a única ação do painel inteiro que devolve um segredo à tela.
     *
     * @param array<string, string> $params
     */
    public function criar(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $bruto = [
            'name'   => (string)$request->input('name', ''),
            'scopes' => $request->inputArray('scopes'),
            'rate_limit_per_min' => (string)$request->input('rate_limit_per_min', ''),
            'note'       => (string)$request->input('note', ''),
            'expires_at' => (string)$request->input('expires_at', ''),
        ];

        $v = ApiKeyRepo::validarNova($bruto);
        if (!$v['ok']) {
            // O que o operador digitou volta com ele: perder a lista de escopos marcada por
            // causa de um nome em branco faria qualquer um marcar "tudo" na segunda tentativa.
            $_SESSION['api_form'] = ['erros' => $v['erros'], 'valores' => $bruto];
            flash('error', 'Confira os campos destacados.');
            return $this->redirect('admin/api');
        }

        $nova = ApiKeyRepo::criar($v['dados'], $this->adminId());

        $this->auditar(
            'api_chave_criada',
            'api_keys',
            $nova['id'],
            sprintf('Criou a chave de API "%s" (%s).', $v['dados']['name'], $nova['prefix']),
            // Os ESCOPOS vão para a trilha; a chave, nunca. É o registro que responde "quem
            // deu a esta integração o poder de banir?" seis meses depois.
            ['prefix' => $nova['prefix'], 'scopes' => $v['dados']['scopes'], 'expires_at' => $v['dados']['expires_at']]
        );

        $_SESSION['api_chave_nova'] = ['chave' => $nova['chave'], 'nome' => $v['dados']['name'], 'prefixo' => $nova['prefix']];
        flash('ok', 'Chave criada. Copie agora — ela não será mostrada de novo.');

        return $this->redirect('admin/api');
    }

    /**
     * Revoga uma chave. Não apaga: a linha continua, marcada como revogada.
     *
     * @param array<string, string> $params
     */
    public function revogar(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $id    = (int)$request->input('id', '0');
        $chave = ApiKeyRepo::porId($id);
        if ($chave === null) {
            flash('error', 'Chave não encontrada.');
            return $this->redirect('admin/api');
        }

        if (!ApiKeyRepo::revogar($id, $this->adminId())) {
            // A condição mora no próprio UPDATE: dois operadores clicando ao mesmo tempo não
            // sobrescrevem a assinatura de quem chegou primeiro.
            flash('error', 'Esta chave já estava revogada.');
            return $this->redirect('admin/api');
        }

        $this->auditar(
            'api_chave_revogada',
            'api_keys',
            $id,
            sprintf('Revogou a chave de API "%s" (%s).', (string)$chave['nome'], (string)$chave['prefixo']),
            ['prefix' => $chave['prefixo'], 'usos' => $chave['usos']]
        );

        flash('ok', 'Chave revogada. A próxima chamada com ela recebe 401.');
        return $this->redirect('admin/api');
    }

    // ===================================================================== apoio

    /**
     * Lê e APAGA o segredo da sessão. A leitura é destrutiva de propósito: recarregar a
     * página não pode mostrar a chave de novo, senão ela ficaria na sessão do operador até
     * ele sair — e "aparece uma vez" viraria "aparece até você esquecer".
     *
     * @return array{chave:string, nome:string, prefixo:string}|null
     */
    private function consumirSegredo(): ?array
    {
        $s = $this->consumir('api_chave_nova');
        return $s === null ? null : [
            'chave'   => (string)($s['chave'] ?? ''),
            'nome'    => (string)($s['nome'] ?? ''),
            'prefixo' => (string)($s['prefixo'] ?? ''),
        ];
    }

    /**
     * Esquema + host desta instalação, para o exemplo de chamada sair copiável.
     *
     * Sai do Host da requisição, e não de uma configuração: o painel é aberto em
     * localhost:8080, em /extracthunters/ e no domínio de produção, e o exemplo tem de
     * funcionar nos três sem ninguém editar nada.
     */
    private function origem(): string
    {
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        // O Host vem do cliente: só o que parece host entra, para não virar um exemplo com
        // texto injetado no meio (a view escapa, mas um exemplo estranho é ruim de qualquer forma).
        if (!preg_match('/^[A-Za-z0-9.\-]{1,190}(:\d{1,5})?$/', $host)) {
            $host = 'localhost';
        }
        return (\App\Core\Config::isHttps() ? 'https' : 'http') . '://' . $host;
    }

    /** @return array<string,mixed>|null */
    private function consumir(string $chave): ?array
    {
        $v = $_SESSION[$chave] ?? null;
        unset($_SESSION[$chave]);
        return is_array($v) ? $v : null;
    }

    /**
     * Os três indicadores da topbar, todos medidos nesta requisição.
     *
     * Falam DESTA tela: quantas chaves estão de pé, quanto a API foi usada nas últimas 24 h e
     * quantas dessas chamadas foram RECUSADAS — que é o número que denuncia integração mal
     * configurada e chave sendo sondada.
     *
     * @return list<array<string,mixed>>
     */
    private function kpisDaTopbar(): array
    {
        $c = ApiKeyRepo::contagens();
        $r = ApiLogRepo::resumo24h();

        return [
            ['icon' => 'key',        'label' => 'Chaves ativas',      'value' => num($c['ativas']), 'tone' => 'lime'],
            ['icon' => 'swap_horiz', 'label' => 'Chamadas (24 h)',    'value' => num($r['total']),  'tone' => 'cyan'],
            [
                'icon'  => 'block',
                'label' => 'Recusadas (24 h)',
                'value' => num($r['recusadas']),
                'tone'  => $r['recusadas'] > 0 ? 'amber' : 'lime',
            ],
        ];
    }
}
