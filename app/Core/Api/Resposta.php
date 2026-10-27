<?php declare(strict_types=1);

namespace App\Core\Api;

use App\Core\Response;

/**
 * O ENVELOPE ÚNICO DA API. Toda resposta de /api/v1 sai daqui — inclusive as falhas, e
 * inclusive a falha inesperada que ninguém previu (o despachante embrulha a exceção).
 *
 * SUCESSO
 *   { "ok": true, "data": <objeto ou lista>, "meta": { ...paginação, quando presente... } }
 *
 * ERRO
 *   { "ok": false, "error": { "code": "escopo_ausente", "message": "...", "details": {...} } }
 *
 * POR QUE `code` ALÉM DA MENSAGEM
 * -------------------------------
 * `message` é para o humano que está lendo o curl e MUDA quando alguém melhora o texto.
 * `code` é para o programa do outro lado: é estável, é minúsculo, é o que se compara num
 * `if`. Sem o código, todo integrador acaba comparando string de mensagem — e a primeira
 * correção de português quebra a integração dele.
 *
 * POR QUE `details` É OBJETO E NÃO TEXTO
 * --------------------------------------
 * Erro de validação precisa dizer QUAL campo e POR QUÊ, campo a campo. Concatenar isso numa
 * frase obriga o cliente a fazer análise sintática de português para marcar o campo errado
 * no formulário dele.
 *
 * CÓDIGO HTTP E `code` ANDAM JUNTOS: a lista de CODIGOS abaixo é a única fonte dos dois, para
 * não existir um 403 com código de 401 em algum canto.
 */
final class Resposta
{
    /**
     * Catálogo fechado de erros: código => [HTTP, mensagem padrão].
     *
     * @var array<string, array{0:int, 1:string}>
     */
    public const CODIGOS = [
        // --- autenticação e autorização
        'chave_ausente'     => [401, 'Envie a chave da API no cabeçalho Authorization: Bearer <chave>.'],
        'chave_invalida'    => [401, 'Chave da API inválida.'],
        'chave_revogada'    => [401, 'Esta chave foi revogada.'],
        'chave_expirada'    => [401, 'Esta chave venceu.'],
        'escopo_ausente'    => [403, 'A chave não tem o escopo exigido por este endpoint.'],
        // --- rota
        'recurso_ausente'   => [404, 'Recurso não existe nesta API.'],
        'rota_ausente'      => [404, 'Endpoint não existe nesta API.'],
        'nao_encontrado'    => [404, 'Registro não encontrado.'],
        'metodo_invalido'   => [405, 'Método não permitido para este endpoint.'],
        // --- entrada
        'json_invalido'     => [400, 'O corpo da requisição não é um objeto JSON válido.'],
        'corpo_grande'      => [413, 'Corpo da requisição grande demais.'],
        'entrada_invalida'  => [422, 'Há campos inválidos na requisição.'],
        'conflito'          => [409, 'O registro entra em conflito com outro já existente.'],
        'nao_permitido'     => [403, 'Esta operação não é permitida sobre este registro.'],
        // --- vazão e infraestrutura
        'limite_excedido'   => [429, 'Limite de requisições por minuto excedido para esta chave.'],
        'indisponivel'      => [503, 'Serviço indisponível no momento.'],
        'erro_interno'      => [500, 'Erro interno ao processar a requisição.'],
    ];

    /**
     * Resposta de sucesso.
     *
     * `meta` só aparece quando existe: um objeto `meta` vazio em toda resposta ensinaria o
     * cliente a ignorá-lo, e é justamente nele que mora a paginação.
     *
     * @param array<string,mixed>|list<mixed> $dados
     * @param array<string,mixed> $meta
     */
    public static function ok(array $dados, array $meta = [], int $status = 200): Response
    {
        $corpo = ['ok' => true, 'data' => $dados];
        if ($meta !== []) {
            $corpo['meta'] = $meta;
        }
        return self::json($corpo, $status);
    }

    /**
     * Resposta de erro. O status sai do catálogo — quem chama não escolhe.
     *
     * @param array<string,mixed> $detalhes
     */
    public static function erro(string $codigo, string $mensagem = '', array $detalhes = []): Response
    {
        [$status, $padrao] = self::CODIGOS[$codigo] ?? self::CODIGOS['erro_interno'];
        if (!isset(self::CODIGOS[$codigo])) {
            // Código fora do catálogo é defeito de programação, não estado do cliente: o
            // cliente recebe 500 genérico e o log do PHP recebe o nome errado usado.
            error_log('[extracthunters] api: código de erro desconhecido "' . $codigo . '"');
            $codigo = 'erro_interno';
        }

        $erro = ['code' => $codigo, 'message' => $mensagem !== '' ? $mensagem : $padrao];
        if ($detalhes !== []) {
            $erro['details'] = $detalhes;
        }
        return self::json(['ok' => false, 'error' => $erro], $status);
    }

    /**
     * Metadados de paginação, no formato que TODA listagem desta API usa.
     *
     * @return array<string,mixed>
     */
    public static function paginacao(int $total, int $limite, int $deslocamento): array
    {
        return [
            'total'  => $total,
            'limit'  => $limite,
            'offset' => $deslocamento,
            // Calculado aqui e não pelo cliente: é a única informação que evita a chamada
            // extra que só serve para descobrir que não havia mais nada.
            'has_more' => $deslocamento + $limite < $total,
        ];
    }

    /**
     * Serialização única.
     *
     * JSON_PARTIAL_OUTPUT_ON_ERROR NÃO é usado: resposta meio serializada é pior que erro
     * declarado. Se a codificação falhar (UTF-8 inválido vindo do banco, por exemplo), a
     * exceção sobe e o despachante devolve 500 com envelope — nunca um corpo truncado.
     *
     * @param array<string,mixed> $corpo
     */
    private static function json(array $corpo, int $status): Response
    {
        $texto = json_encode(
            $corpo,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
        );

        return (new Response($texto, $status, ['Content-Type' => 'application/json; charset=UTF-8']))
            // Resposta de API nunca entra em cache intermediário: os dados são de gestão e
            // mudam a cada escrita do painel.
            ->withHeader('Cache-Control', 'no-store')
            // Sem isto, um navegador que receba um erro 401 desta API pode tentar adivinhar o
            // tipo do conteúdo. nosniff já vem de Security::sendHeaders(), mas o X-Robots é
            // exclusivo daqui: a API não é página e não deve ser indexada nem por engano.
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
