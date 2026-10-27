<?php declare(strict_types=1);

namespace App\Api;

use App\Core\Api\Requisicao;
use App\Core\Api\Resposta;
use App\Core\Response;

/**
 * Um recurso de coleção: as cinco operações de sempre sobre UMA declaração do Catalogo.
 *
 * É esta classe que atende missions, achievements, maps, items e shop. Não há nada específico
 * de nenhuma delas aqui — o que muda de uma para a outra está inteiramente na declaração, e é
 * por isso que uma coleção nova custa um bloco em App\Api\Catalogo e uma linha no despachante,
 * em vez de um arquivo novo com um `SELECT` sem `LIMIT` esperando para acontecer.
 *
 * PATCH, E NÃO PUT. A alteração é PARCIAL de propósito: um PUT obrigaria o cliente a reenviar
 * o registro inteiro a cada ajuste, e o campo que ele esquecesse seria apagado em silêncio.
 * Com PATCH, o que não vem no corpo não é tocado — o modo seguro é também o modo simples.
 *
 * EXCLUIR RECUSA QUANDO HÁ DEPENDENTE. Apagar uma missão que 400 jogadores têm em andamento
 * não é uma limpeza, é uma perda de progresso silenciosa: o registro some e as linhas de
 * `player_missions` ficam apontando para o nada. A API devolve 409 dizendo o que depende e
 * quantos são, e quem quiser mesmo assim tira a missão de circulação com `locked`.
 */
class TabelaRecurso extends Recurso
{
    /**
     * @param string $nome       recurso na URL (/api/v1/<nome>)
     * @param array<string,mixed> $decl declaração de App\Api\Catalogo
     * @param string $rotulo     plural, para o resumo da referência ("Lista missões ...")
     * @param string $singular   para a frase da auditoria ("Criou missão #12 pela API.")
     */
    public function __construct(
        private readonly string $nome,
        protected readonly array $decl,
        private readonly string $rotulo,
        private readonly string $singular,
    ) {
    }

    public function nome(): string
    {
        return $this->nome;
    }

    /** @return list<array{0:string, 1:string, 2:string, 3:string, 4?:string}> */
    public function rotas(): array
    {
        return [
            ['GET',    '',     'listar',    'Lista ' . $this->rotulo . ' com filtro e página.', '?q=&limit=50&offset=0'],
            ['GET',    '{id}', 'obter',     'Um registro pelo id.',                             ''],
            ['POST',   '',     'criar',     'Cria um registro.',                                ''],
            ['PATCH',  '{id}', 'atualizar', 'Altera os campos enviados (alteração parcial).',   ''],
            ['DELETE', '{id}', 'excluir',   'Apaga o registro, se nada depender dele.',         ''],
        ];
    }

    /** Coleção-pai desta declaração quando ela é aninhada; null para as de primeiro nível. */
    protected function paiId(array $params): ?int
    {
        return null;
    }

    /**
     * Executa uma das cinco ações a pedido de OUTRO recurso.
     *
     * Existe para que /maps/{id}/markers e /events/{grupo} reaproveitem este motor inteiro em
     * vez de reimplementar listar/criar/alterar/apagar. Sem este ponto de entrada, as ações
     * protegidas só seriam alcançáveis pela rota do próprio recurso — e o jeito fácil de
     * contornar isso seria copiar o arquivo, que é exatamente o que este motor evita.
     *
     * @param array<string,string> $params
     */
    final public function operar(string $acao, Requisicao $req, Contexto $ctx, array $params): Response
    {
        return match ($acao) {
            'listar'    => $this->listar($req, $ctx, $params),
            'obter'     => $this->obter($req, $ctx, $params),
            'criar'     => $this->criar($req, $ctx, $params),
            'atualizar' => $this->atualizar($req, $ctx, $params),
            'excluir'   => $this->excluir($req, $ctx, $params),
            default     => throw new \LogicException('Ação desconhecida na coleção: ' . $acao),
        };
    }

    /** Nome da entidade na trilha de auditoria: a tabela real. */
    protected function entidade(): string
    {
        return (string)($this->decl['tabela'] ?? $this->nome);
    }

    /**
     * Caminho da COLEÇÃO, sem o prefixo /api/v1, para montar o Location de um 201.
     *
     * É sobrescrito pelas coleções aninhadas: um marcador criado em /maps/7/markers mora em
     * /maps/7/markers/31, e não em /maps/31 — que é onde um Location montado com o nome do
     * recurso pai mandaria o cliente.
     *
     * @param array<string,string> $params
     */
    protected function colecaoUrl(array $params): string
    {
        return $this->nome;
    }

    // ===================================================================== ações

    protected function listar(Requisicao $req, Contexto $ctx, array $params): Response
    {
        [$limite, $deslocamento] = $this->pagina($req);

        // Só os parâmetros que a declaração conhece chegam à consulta. O resto da querystring
        // é ignorado — nunca vira coluna, nunca vira condição.
        $consulta = ['q' => $req->consulta('q')];
        foreach (array_keys((array)($this->decl['filtros'] ?? [])) as $p) {
            $consulta[(string)$p] = $req->consulta((string)$p);
        }

        $r = Tabela::listar($this->decl, $consulta, $limite, $deslocamento, $this->paiId($params));

        return Resposta::ok($r['linhas'], Resposta::paginacao($r['total'], $limite, $deslocamento));
    }

    protected function obter(Requisicao $req, Contexto $ctx, array $params): Response
    {
        $id = $this->id($params);
        if ($id <= 0) {
            return $this->naoEncontrado();
        }
        $linha = Tabela::obter($this->decl, $id, $this->paiId($params));
        return $linha === null ? $this->naoEncontrado() : Resposta::ok($linha);
    }

    protected function criar(Requisicao $req, Contexto $ctx, array $params): Response
    {
        $r = Tabela::criar($this->decl, $req->corpo(), $this->paiId($params));
        if (!$r['ok']) {
            return Resposta::erro('entrada_invalida', '', $r['erros']);
        }

        $linha = Tabela::obter($this->decl, $r['id'], $this->paiId($params));
        $ctx->auditar(
            'criou',
            $this->entidade(),
            $r['id'],
            sprintf('Criou %s #%d pela API.', $this->rotuloSingular(), $r['id']),
            ['campos' => array_keys($req->corpo())]
        );

        // 201 com Location: quem criou precisa saber onde o registro passou a morar sem ter
        // de montar a URL na mão.
        return Resposta::ok($linha ?? ['id' => $r['id']], [], 201)
            ->withHeader('Location', url(ltrim(Requisicao::PREFIXO, '/') . '/' . $this->colecaoUrl($params) . '/' . $r['id']));
    }

    protected function atualizar(Requisicao $req, Contexto $ctx, array $params): Response
    {
        $id  = $this->id($params);
        $pai = $this->paiId($params);
        if ($id <= 0 || Tabela::obter($this->decl, $id, $pai) === null) {
            return $this->naoEncontrado();
        }

        $r = Tabela::atualizar($this->decl, $id, $req->corpo(), $pai);
        if (!$r['ok']) {
            return Resposta::erro('entrada_invalida', '', $r['erros']);
        }

        $ctx->auditar(
            'alterou',
            $this->entidade(),
            $id,
            sprintf('Alterou %s #%d pela API.', $this->rotuloSingular(), $id),
            // Só os NOMES dos campos, nunca os valores: a trilha diz o que foi mexido e fica
            // legível; o valor antigo e o novo estão no próprio registro e no backup.
            ['campos' => array_keys($req->corpo())]
        );

        return Resposta::ok(Tabela::obter($this->decl, $id, $pai) ?? []);
    }

    protected function excluir(Requisicao $req, Contexto $ctx, array $params): Response
    {
        $id  = $this->id($params);
        $pai = $this->paiId($params);
        if ($id <= 0) {
            return $this->naoEncontrado();
        }
        $linha = Tabela::obter($this->decl, $id, $pai);
        if ($linha === null) {
            return $this->naoEncontrado();
        }

        $presos = Tabela::dependencias((array)($this->decl['dependentes'] ?? []), $id);
        if ($presos !== []) {
            return Resposta::erro(
                'conflito',
                'Outros registros dependem deste. Apagá-lo deixaria dados órfãos.',
                ['dependentes' => $presos]
            );
        }

        Tabela::excluir($this->decl, $id, $pai);
        $ctx->auditar(
            'excluiu',
            $this->entidade(),
            $id,
            sprintf('Excluiu %s #%d pela API.', $this->rotuloSingular(), $id),
            // O registro apagado vai INTEIRO para a trilha. É o único caso em que os valores
            // são guardados, e o motivo é simples: depois do DELETE não há outro lugar de
            // onde reconstruir o que existia.
            ['registro' => $linha]
        );

        return Resposta::ok(['id' => $id, 'deleted' => true]);
    }

    /**
     * O nome no singular para a frase da trilha.
     *
     * Vem do construtor, e não de uma regra que tira o 's' do plural: "missões" viraria
     * "missõe". Português não tem plural regular o bastante para isso, e a auditoria é
     * justamente onde o texto precisa estar certo.
     */
    private function rotuloSingular(): string
    {
        return $this->singular;
    }
}
