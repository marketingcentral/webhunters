<?php declare(strict_types=1);

namespace App\Controllers\Api;

use App\Api\BanimentosRecurso;
use App\Api\Catalogo;
use App\Api\ConfiguracoesRecurso;
use App\Api\Contexto;
use App\Api\EconomiaRecurso;
use App\Api\EventosRecurso;
use App\Api\JogadoresRecurso;
use App\Api\MapasRecurso;
use App\Api\Recurso;
use App\Api\ServidoresRecurso;
use App\Api\TabelaRecurso;
use App\Core\Api\Escopos;
use App\Core\Api\Requisicao;
use App\Core\Api\Resposta;
use App\Core\Config;
use App\Core\Database;
use App\Core\Maintenance;
use App\Core\Request;
use App\Core\Response;
use App\Repo\ApiKeyRepo;
use App\Repo\ApiLogRepo;

/**
 * O DESPACHANTE DE /api/v1 — porta única da API de gestão.
 *
 * Toda chamada da API entra por `dispatch()`, e ela faz sempre a mesma sequência. A ORDEM É A
 * SEGURANÇA, então ela está escrita aqui e em lugar nenhum mais:
 *
 *   1. endpoints abertos (/api/v1 e /api/v1/health) — respondem e saem;
 *   2. AUTENTICA a chave (Authorization: Bearer)          -> 401
 *   3. LIMITE por minuto daquela chave                    -> 429
 *   4. acha o RECURSO                                     -> 404
 *   5. acha a ROTA dentro do recurso                      -> 404 ou 405 (com Allow)
 *   6. confere o ESCOPO exigido pelo método               -> 403
 *   7. confere o CORPO (JSON válido, tamanho)             -> 400 ou 413
 *   8. executa e registra
 *
 * O limite vem ANTES do roteamento de propósito: um cliente em laço batendo numa rota que não
 * existe custa tanto quanto um batendo numa que existe, e é justamente ele que precisa ser
 * contido. O escopo vem DEPOIS de achar a rota porque é a rota que diz qual escopo é exigido.
 *
 * NADA AQUI DEPENDE DE SESSÃO OU DE CSRF. A API é servidor-a-servidor: quem chama não tem
 * navegador, não tem cookie e não tem como buscar um token antes. CSRF protege contra um
 * NAVEGADOR AUTENTICADO POR COOKIE ser usado por um site de terceiros — como aqui a
 * credencial é um cabeçalho que nenhum site consegue fazer o navegador de outra pessoa
 * enviar, o ataque que o CSRF impede simplesmente não existe nesta porta. Exigir o token
 * seria teatro: o cliente legítimo não teria como obtê-lo e o atacante não precisa dele.
 *
 * TODA RESPOSTA É JSON, INCLUSIVE AS FALHAS INESPERADAS. O `catch (Throwable)` no fim existe
 * para que nenhuma exceção suba até o front controller e vire a página de erro HTML do jogo —
 * um cliente de API que recebe HTML no lugar de JSON quebra no parser, sem saber por quê.
 */
final class ApiController
{
    /** @var array<string, Recurso>|null Recursos montados sob demanda (uma vez por requisição). */
    private static ?array $recursos = null;

    /**
     * Ponto de entrada de TODAS as rotas /api/v1/* (ver app/routes.php).
     *
     * @param array<string,string> $params ignorados: o caminho é lido inteiro de Requisicao
     */
    public function dispatch(Request $request, array $params = []): Response
    {
        $inicio = microtime(true);
        $req    = Requisicao::de($request);

        $chaveId = null;
        $prefixo = null;

        try {
            // ---------------------------------------------------------- 1. portas abertas
            $recursoNome = $req->recurso();
            if ($recursoNome === '') {
                $resposta = $this->indice($req);
            } elseif ($recursoNome === 'health' && count($req->segmentos()) === 1) {
                $resposta = $this->saude($req);
            } else {
                // ------------------------------------------------------ 2. autenticação
                $apresentada = $req->chaveApresentada();
                $auth = ApiKeyRepo::autenticar($apresentada);

                // O prefixo do que foi APRESENTADO é guardado mesmo quando a chave não vale:
                // uma sequência de 401 com prefixos diferentes é alguém sondando, e sem esta
                // linha o log mostraria só "401" sem dizer quem bateu na porta.
                $prefixo = self::prefixoDe($apresentada);

                if (!$auth['ok']) {
                    $resposta = Resposta::erro($auth['motivo']);
                    // WWW-Authenticate é o que manda o padrão num 401 e é o que faz um cliente
                    // HTTP genérico entender que falta credencial, em vez de "deu erro".
                    $resposta = $resposta->withHeader('WWW-Authenticate', 'Bearer realm="Extract Hunters API"');
                } else {
                    $chave   = $auth['chave'];
                    $chaveId = (int)$chave['id'];
                    $prefixo = (string)$chave['prefix'];
                    $ctx     = new Contexto($chave, $req->ip());

                    $resposta = $this->atender($req, $ctx, $chave);
                }
            }
        } catch (\Throwable $e) {
            // A causa vai para o log do servidor, NUNCA para o corpo da resposta: mensagem de
            // exceção carrega caminho de arquivo, nome de tabela e, às vezes, o valor que
            // quebrou a consulta. O cliente recebe um 500 com envelope e nada mais.
            error_log(sprintf('[extracthunters] api %s %s: %s @ %s:%d', $req->metodo(), $req->caminho(), $e->getMessage(), $e->getFile(), $e->getLine()));
            $resposta = Config::debug()
                ? Resposta::erro('erro_interno', $e->getMessage(), ['file' => basename($e->getFile()), 'line' => $e->getLine()])
                : Resposta::erro('erro_interno');
        }

        // ------------------------------------------------------------------ registro
        $ms = (int)round((microtime(true) - $inicio) * 1000);
        ApiLogRepo::registrar(
            $chaveId,
            $prefixo,
            $req->metodo(),
            $req->caminho(),
            $resposta->status(),
            self::codigoDoCorpo($resposta),
            $req->ip(),
            $ms
        );
        if ($chaveId !== null && $chaveId > 0) {
            ApiKeyRepo::marcarUso($chaveId, $req->ip());
        }

        // Cabeçalho de diagnóstico: quem está medindo latência não precisa cronometrar do
        // lado de fora para saber quanto ESTE lado gastou.
        return $resposta->withHeader('X-Response-Time', $ms . 'ms');
    }

    /**
     * Passos 3 a 8, já com a chave autenticada.
     *
     * @param array<string,mixed> $chave
     */
    private function atender(Requisicao $req, Contexto $ctx, array $chave): Response
    {
        // ---------------------------------------------------------------- 3. limite
        $limite = ApiKeyRepo::limiteDe($chave);
        $usadas = ApiLogRepo::noMinuto($ctx->chaveId());

        // -1 = a contagem falhou. Deixa passar: um banco com problema não pode virar 429 para
        // todo mundo — seria transformar uma falha de leitura num apagão da API inteira.
        if ($usadas >= 0 && $usadas >= $limite) {
            return $this->comLimite(
                Resposta::erro('limite_excedido')->withHeader('Retry-After', (string)ApiLogRepo::JANELA_SEG),
                $limite,
                0
            );
        }
        $restantes = $usadas < 0 ? $limite : max(0, $limite - $usadas - 1);

        // ---------------------------------------------------------------- 4. recurso
        $nome = $req->recurso();
        $recursos = self::recursos();
        if (!isset($recursos[$nome])) {
            return $this->comLimite(
                Resposta::erro('recurso_ausente', 'Recurso "' . $nome . '" não existe. Veja GET /api/v1 para a lista.'),
                $limite,
                $restantes
            );
        }
        $recurso = $recursos[$nome];

        // ---------------------------------------------------------------- 5. rota
        $casou = $recurso->casar(array_slice($req->segmentos(), 1), $req->metodo());

        if (isset($casou['metodos'])) {
            // O caminho existe, o verbo não. `Allow` diz quais servem — sem ele o integrador
            // fica adivinhando se errou a URL ou o método.
            return $this->comLimite(
                Resposta::erro('metodo_invalido', 'Métodos aceitos neste caminho: ' . implode(', ', $casou['metodos']) . '.')
                    ->withHeader('Allow', implode(', ', $casou['metodos'])),
                $limite,
                $restantes
            );
        }
        if (!isset($casou['rota'])) {
            return $this->comLimite(Resposta::erro('rota_ausente'), $limite, $restantes);
        }

        // ---------------------------------------------------------------- 6. escopo
        $escopo = Escopos::paraMetodo($nome, $req->metodo());
        if (!$ctx->temEscopo($escopo)) {
            return $this->comLimite(
                Resposta::erro(
                    'escopo_ausente',
                    'Este endpoint exige o escopo "' . $escopo . '", que esta chave não tem.',
                    ['required' => $escopo, 'granted' => $ctx->escopos()]
                ),
                $limite,
                $restantes
            );
        }

        // ---------------------------------------------------------------- 7. corpo
        if ($req->escreve()) {
            $erroCorpo = $req->erroCorpo();
            if ($erroCorpo !== '') {
                return $this->comLimite(Resposta::erro($erroCorpo), $limite, $restantes);
            }
        }

        // ---------------------------------------------------------------- 8. execução
        return $this->comLimite(
            $recurso->executar($casou['rota'], $casou['params'] ?? [], $req, $ctx),
            $limite,
            $restantes
        );
    }

    /** Os três cabeçalhos de limite, em TODA resposta autenticada (inclusive nas recusas). */
    private function comLimite(Response $r, int $limite, int $restantes): Response
    {
        return $r
            ->withHeader('X-RateLimit-Limit', (string)$limite)
            ->withHeader('X-RateLimit-Remaining', (string)$restantes)
            // Janela DESLIZANTE: o "reset" é sempre daqui a 60 s, porque é quando a chamada
            // mais antiga sai da janela. Não existe virada de minuto para esperar.
            ->withHeader('X-RateLimit-Reset', (string)ApiLogRepo::JANELA_SEG);
    }

    // ===================================================================== portas abertas

    /**
     * GET /api/v1 — o índice. Sem chave, de propósito: é o "onde estou" de quem acabou de
     * receber uma credencial e ainda não sabe o que ela abre. Não revela nada que já não
     * esteja na documentação do painel.
     */
    private function indice(Requisicao $req): Response
    {
        if ($req->metodo() !== 'GET') {
            return Resposta::erro('metodo_invalido')->withHeader('Allow', 'GET');
        }

        $recursos = [];
        foreach (self::recursos() as $nome => $r) {
            $recursos[$nome] = [
                'href'   => url(ltrim(Requisicao::PREFIXO, '/') . '/' . $nome),
                'label'  => Escopos::RECURSOS[$nome]['rotulo'] ?? $nome,
                'scopes' => [$nome . ':read', $nome . ':write'],
            ];
        }

        return Resposta::ok([
            'service'   => Config::APP_NAME . ' — API de gestão',
            'version'   => 'v1',
            'auth'      => 'Authorization: Bearer <chave>',
            'resources' => $recursos,
        ]);
    }

    /**
     * GET /api/v1/health — sem chave, de propósito.
     *
     * Um monitor externo precisa responder "o site está de pé?" ANTES de qualquer credencial:
     * se a saúde exigisse chave, uma chave vencida faria o monitor gritar "fora do ar" com o
     * jogo funcionando perfeitamente — e o alarme que mente é pior do que alarme nenhum.
     *
     * O que ele NÃO devolve: nada de conteúdo, nada de contagem, nada de configuração. Só o
     * que é preciso para um alarme decidir se acorda alguém.
     */
    private function saude(Requisicao $req): Response
    {
        if ($req->metodo() !== 'GET') {
            return Resposta::erro('metodo_invalido')->withHeader('Allow', 'GET');
        }

        $banco = false;
        try {
            $banco = Database::ready();
        } catch (\Throwable) {
            $banco = false;
        }

        [$manutencao] = $banco ? Maintenance::estado() : [false];

        // Banco fora é 503: o serviço está no ar mas não consegue trabalhar, e é exatamente
        // essa diferença que um monitor precisa ver. Manutenção ligada NÃO é 503 aqui — é um
        // estado deliberado, e o alarme não deve tocar por causa de uma janela programada.
        return Resposta::ok([
            'status'      => $banco ? ($manutencao ? 'maintenance' : 'ok') : 'degraded',
            'database'    => $banco,
            'maintenance' => (bool)$manutencao,
            'time'        => date('c'),
        ], [], $banco ? 200 : 503);
    }

    // ===================================================================== apoio

    /**
     * Os recursos da API, montados uma vez.
     *
     * É AQUI que uma coleção nova entra em operação: uma linha neste arranjo, e ela passa a
     * ter rotas, escopo, validação, auditoria, limite e documentação — porque tudo isso é
     * derivado da declaração e do próprio recurso, não escrito de novo.
     *
     * @return array<string, Recurso>
     */
    public static function recursos(): array
    {
        if (self::$recursos !== null) {
            return self::$recursos;
        }

        $c = Catalogo::colecoes();

        $lista = [
            new JogadoresRecurso(),
            new BanimentosRecurso(),
            new TabelaRecurso('missions', $c['missions'], 'missões', 'missão'),
            new TabelaRecurso('achievements', $c['achievements'], 'conquistas', 'conquista'),
            new MapasRecurso(),
            new TabelaRecurso('items', $c['items'], 'itens', 'item'),
            new TabelaRecurso('shop', $c['shop'], 'ofertas da loja', 'oferta'),
            new EventosRecurso(),
            new ServidoresRecurso(),
            new EconomiaRecurso(),
            new ConfiguracoesRecurso(),
        ];

        $out = [];
        foreach ($lista as $r) {
            $nome = $r->nome();
            // Recurso sem escopo no catálogo não poderia ser autorizado por ninguém: seria um
            // endpoint que responde 403 para sempre. Melhor descobrir na primeira chamada, em
            // desenvolvimento, do que no dia em que alguém tentar usá-lo.
            if (!isset(Escopos::RECURSOS[$nome])) {
                throw new \LogicException('Recurso da API sem escopo declarado: ' . $nome);
            }
            $out[$nome] = $r;
        }

        return self::$recursos = $out;
    }

    /**
     * A referência completa, para a tela /admin/api desenhar.
     *
     * @return list<array{recurso:string, rotulo:string, icone:string, descricao:string,
     *                    rotas:list<array{metodo:string, caminho:string, escopo:string, resumo:string, exemplo:string}>}>
     */
    public static function referencia(): array
    {
        $out = [];
        foreach (self::recursos() as $nome => $r) {
            $meta = Escopos::RECURSOS[$nome];
            $out[] = [
                'recurso'   => $nome,
                'rotulo'    => $meta['rotulo'],
                'icone'     => $meta['icone'],
                'descricao' => $meta['descricao'],
                'rotas'     => $r->referencia(),
            ];
        }
        return $out;
    }

    /** O prefixo de uma chave apresentada, para o log — sem nunca guardar o segredo. */
    private static function prefixoDe(string $apresentada): ?string
    {
        if ($apresentada === '') {
            return null;
        }
        $pedaco = explode('_', $apresentada)[0];
        return preg_match('/^[a-z0-9]{1,24}$/', $pedaco) ? $pedaco : 'invalido';
    }

    /**
     * O `error.code` da resposta, para a coluna do log.
     *
     * Lê o corpo já serializado em vez de carregar o código por parâmetro porque assim
     * NENHUM caminho de saída pode esquecer de informá-lo — inclusive o `catch` de exceção
     * inesperada, que é justamente o que mais interessa numa investigação.
     */
    private static function codigoDoCorpo(Response $r): string
    {
        if ($r->status() < 400) {
            return '';
        }
        $d = json_decode($r->body(), true);
        return is_array($d) ? (string)($d['error']['code'] ?? '') : '';
    }
}
