<?php declare(strict_types=1);

namespace App\Api;

use App\Core\Api\Escopos;
use App\Core\Api\Requisicao;
use App\Core\Api\Resposta;
use App\Core\Response;

/**
 * Base de todo recurso de /api/v1 — e a ÚNICA declaração das rotas dele.
 *
 * A lista devolvida por `rotas()` é lida por duas coisas que não podem divergir:
 *
 *   1. o despachante, que decide qual método chamar e QUAL ESCOPO exigir;
 *   2. a tela /admin/api, que desenha a referência (método, caminho, escopo, exemplo).
 *
 * Isto é o ponto: a documentação não é escrita à parte. Ela É a tabela de rotas. Um endpoint
 * novo aparece na referência do painel no mesmo commit em que passa a existir, e um endpoint
 * removido some dos dois lugares ao mesmo tempo — nunca acontece de o painel ensinar uma
 * chamada que a API não atende mais.
 *
 * FORMA DE UMA ROTA
 *   ['GET', '{id}/markers', 'listarMarcadores', 'Marcadores do mapa', '?limit=50']
 *    metodo  padrão         ação                resumo               exemplo de querystring
 *
 * O padrão é RELATIVO ao recurso: '' é a coleção (/api/v1/maps) e '{id}/markers' vira
 * /api/v1/maps/12/markers. Segmento entre chaves é capturado e entregue à ação.
 *
 * O ESCOPO NÃO É DECLARADO POR ROTA, e isso é de propósito: ele é derivado do método por
 * Escopos::paraMetodo(). Declarar caso a caso abriria a porta para um DELETE protegido por
 * um escopo de leitura — o tipo de erro que ninguém percebe na revisão.
 */
abstract class Recurso
{
    /** Primeiro segmento depois de /api/v1. Tem de existir em Escopos::RECURSOS. */
    abstract public function nome(): string;

    /**
     * Rotas deste recurso.
     *
     * @return list<array{0:string, 1:string, 2:string, 3:string, 4?:string}>
     */
    abstract public function rotas(): array;

    /**
     * Encontra a rota que atende esta requisição.
     *
     * Três respostas possíveis, e a diferença entre elas importa para quem está integrando:
     *   - achou            -> ['rota' => ..., 'params' => [...]]
     *   - caminho existe, método não -> ['metodos' => ['GET','PATCH']]  (vira 405 com Allow)
     *   - nada bate        -> []                                       (vira 404)
     *
     * @param list<string> $segmentos já sem o nome do recurso
     * @return array{rota?:array, params?:array<string,string>, metodos?:list<string>}
     */
    final public function casar(array $segmentos, string $metodo): array
    {
        $metodosDoCaminho = [];

        foreach ($this->rotas() as $rota) {
            $partes = $rota[1] === '' ? [] : explode('/', $rota[1]);
            if (count($partes) !== count($segmentos)) {
                continue;
            }

            $params = [];
            $bate   = true;
            foreach ($partes as $i => $parte) {
                if (preg_match('/^\{([a-z_]+)\}$/', $parte, $m)) {
                    $params[$m[1]] = $segmentos[$i];
                    continue;
                }
                if ($parte !== $segmentos[$i]) {
                    $bate = false;
                    break;
                }
            }
            if (!$bate) {
                continue;
            }

            if (strtoupper($rota[0]) !== $metodo) {
                $metodosDoCaminho[] = strtoupper($rota[0]);
                continue;
            }
            return ['rota' => $rota, 'params' => $params];
        }

        return $metodosDoCaminho === [] ? [] : ['metodos' => array_values(array_unique($metodosDoCaminho))];
    }

    /**
     * Executa a ação da rota. O despachante já conferiu chave, escopo e limite.
     *
     * @param array{0:string, 1:string, 2:string, 3:string, 4?:string} $rota
     * @param array<string,string> $params
     */
    final public function executar(array $rota, array $params, Requisicao $req, Contexto $ctx): Response
    {
        $acao = $rota[2];
        if (!method_exists($this, $acao)) {
            // Rota declarada e ação inexistente é defeito de programação, não estado do
            // cliente: 500 com o envelope, e o nome que faltou vai para o log do PHP.
            error_log('[extracthunters] api: ação ausente ' . static::class . '::' . $acao);
            return Resposta::erro('erro_interno');
        }
        return $this->{$acao}($req, $ctx, $params);
    }

    /**
     * A referência deste recurso, já no formato que a tela do painel desenha.
     *
     * @return list<array{metodo:string, caminho:string, escopo:string, resumo:string, exemplo:string}>
     */
    final public function referencia(): array
    {
        $out = [];
        foreach ($this->rotas() as $rota) {
            $caminho = Requisicao::PREFIXO . '/' . $this->nome() . ($rota[1] === '' ? '' : '/' . $rota[1]);
            $out[] = [
                'metodo'  => strtoupper($rota[0]),
                'caminho' => $caminho,
                'escopo'  => Escopos::paraMetodo($this->nome(), $rota[0]),
                'resumo'  => $rota[3],
                'exemplo' => $rota[4] ?? '',
            ];
        }
        return $out;
    }

    // ================================================================= apoio das ações

    /**
     * Os dois parâmetros de página que TODA listagem desta API aceita, já presos à faixa.
     *
     * @return array{0:int, 1:int} limite, deslocamento
     */
    final protected function pagina(Requisicao $req): array
    {
        return [
            $req->consultaInt('limit', Tabela::LIMITE_PADRAO, 1, Tabela::LIMITE_MAX),
            $req->consultaInt('offset', 0, 0, 1000000),
        ];
    }

    /**
     * Um id de rota que é número positivo, ou 0 quando o segmento não é um id.
     *
     * Não lança: quem chama devolve 404, porque /players/abc e /players/999999 são a mesma
     * coisa para quem está do outro lado — não existe.
     */
    final protected function id(array $params, string $nome = 'id'): int
    {
        $v = (string)($params[$nome] ?? '');
        return preg_match('/^\d{1,10}$/', $v) ? (int)$v : 0;
    }

    /** Atalho para o 404 de registro. */
    final protected function naoEncontrado(string $oQue = 'Registro não encontrado.'): Response
    {
        return Resposta::erro('nao_encontrado', $oQue);
    }
}
