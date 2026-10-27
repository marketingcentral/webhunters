<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * O log de requisições da API (`api_request_log`): escrita, leitura para a tela e o
 * CONTADOR do limite por minuto.
 *
 * POR QUE O LIMITE É CONTADO NO BANCO
 * -----------------------------------
 * A alternativa óbvia — um contador em memória — não funciona aqui: este projeto roda em
 * Apache/mod_php no desenvolvimento e OpenLiteSpeed na produção, onde cada requisição pode
 * cair num processo diferente e a memória de um não é a do outro. Um contador assim seria um
 * limite que não limita: bastaria a requisição seguinte cair noutro processo. APCu e Redis
 * resolveriam, mas nenhum dos dois existe nesta instalação, e inventar uma dependência de
 * infraestrutura para contar até 120 seria caro do jeito errado.
 *
 * Então a janela é medida por uma contagem indexada em (key_id, created_at) sobre os últimos
 * 60 segundos. Custo: uma consulta de índice por chamada de API, no mesmo banco que a
 * chamada já vai usar. É uma janela DESLIZANTE, e não "a cada minuto cheio" — quem esgota o
 * limite espera de verdade até as chamadas antigas saírem da janela, em vez de esperar a
 * virada do relógio e disparar tudo de novo.
 *
 * CONSEQUÊNCIA ASSUMIDA: quem for expurgar esta tabela precisa preservar pelo menos os
 * últimos 60 segundos, senão o limite zera junto. Há um `expurgar()` aqui que respeita isso.
 *
 * A ESCRITA NUNCA LANÇA. Falhar em registrar não pode transformar uma resposta 200 em 500 —
 * a falha vai para o log de erro do PHP e a resposta segue.
 */
final class ApiLogRepo
{
    /** Linhas por página na tela do painel. */
    public const POR_PAGINA = 30;

    /** Tamanho da janela do limite, em segundos. */
    public const JANELA_SEG = 60;

    /**
     * Quantas requisições esta chave já fez na janela.
     *
     * Devolve -1 quando a consulta FALHA. O chamador trata isso como "não sei" e DEIXA
     * PASSAR: um banco com problema não pode virar um 429 para todo mundo — isso
     * transformaria uma falha de leitura num apagão da API inteira.
     */
    public static function noMinuto(int $keyId): int
    {
        if ($keyId <= 0) {
            return 0;
        }
        try {
            // A própria recusa por limite NÃO conta. Sem esta exclusão, um cliente em laço
            // manteria a janela cheia com os 429 que ele mesmo provoca e nunca sairia do
            // castigo — o limite deixaria de medir trabalho feito e passaria a medir
            // teimosia. Excluindo-a, a vazão volta ao normal 60 s depois da última chamada
            // que de fato foi atendida.
            return (int)Database::value(
                'SELECT COUNT(*) FROM api_request_log
                  WHERE key_id = :k AND status <> 429
                    AND created_at > (NOW() - INTERVAL ' . self::JANELA_SEG . ' SECOND)',
                ['k' => $keyId]
            );
        } catch (\Throwable $e) {
            error_log('[extracthunters] api limite: ' . $e->getMessage());
            return -1;
        }
    }

    /**
     * Uma linha por requisição atendida — INCLUSIVE as recusadas.
     *
     * As recusadas são as mais importantes: uma sequência de 401 com prefixos diferentes é
     * alguém sondando, e uma sequência de 403 na mesma chave é integração mal configurada.
     * Nenhuma das duas aparece se o log só guardar sucesso.
     */
    public static function registrar(
        ?int $keyId,
        ?string $prefixo,
        string $metodo,
        string $caminho,
        int $status,
        string $codigoErro,
        string $ip,
        int $duracaoMs,
    ): void {
        try {
            Database::insert('api_request_log', [
                'key_id'      => $keyId !== null && $keyId > 0 ? $keyId : null,
                'key_prefix'  => $prefixo !== null && $prefixo !== '' ? mb_substr($prefixo, 0, 24) : null,
                'method'      => mb_substr($metodo, 0, 8),
                // Só o caminho. A querystring fica FORA: filtro de relatório é inofensivo,
                // mas basta um integrador mandar algo sensível na URL por engano para este
                // log virar o vazamento que ele deveria ajudar a investigar.
                'path'        => mb_substr($caminho, 0, 191),
                'status'      => $status,
                'error_code'  => $codigoErro !== '' ? mb_substr($codigoErro, 0, 40) : null,
                'ip'          => mb_substr($ip, 0, 45),
                'duration_ms' => max(0, $duracaoMs),
            ]);
        } catch (\Throwable $e) {
            error_log('[extracthunters] api log: ' . $e->getMessage());
        }
    }

    /**
     * Página do log para a tela do painel.
     *
     * @param array{chave?:int, status?:string, pag?:int} $f
     * @return array{linhas:list<array<string,mixed>>, total:int, pagina:int, paginas:int, falha:bool}
     */
    public static function pagina(array $f): array
    {
        $cond = [];
        $par  = [];

        if ((int)($f['chave'] ?? 0) > 0) {
            $cond[] = 'l.key_id = :k';
            $par['k'] = (int)$f['chave'];
        }
        // Faixa de status, e não status exato: quem investiga quer "tudo que deu errado",
        // não "tudo que deu 422".
        $faixa = (string)($f['status'] ?? '');
        if ($faixa === 'ok') {
            $cond[] = 'l.status < 400';
        } elseif ($faixa === 'recusado') {
            $cond[] = 'l.status IN (401, 403, 429)';
        } elseif ($faixa === 'erro') {
            $cond[] = 'l.status >= 400';
        }

        $onde = $cond === [] ? '1' : implode(' AND ', $cond);

        try {
            $total   = (int)Database::value('SELECT COUNT(*) FROM api_request_log l WHERE ' . $onde, $par);
            $paginas = max(1, (int)ceil($total / self::POR_PAGINA));
            $pagina  = min(max(1, (int)($f['pag'] ?? 1)), $paginas);
            $offset  = ($pagina - 1) * self::POR_PAGINA;

            $linhas = Database::all(
                'SELECT l.id, l.key_id, l.key_prefix, l.method, l.path, l.status, l.error_code,
                        l.ip, l.duration_ms, l.created_at, k.name AS chave_nome
                   FROM api_request_log l
              LEFT JOIN api_keys k ON k.id = l.key_id
                  WHERE ' . $onde . '
               ORDER BY l.id DESC
                  LIMIT ' . self::POR_PAGINA . ' OFFSET ' . $offset,
                $par
            );

            return [
                'linhas' => array_map(
                    static fn(array $l): array => [
                        'id'       => (int)$l['id'],
                        'chave_id' => (int)($l['key_id'] ?? 0),
                        // Nome vazio com prefixo preenchido = a chave não existe (ou nunca
                        // existiu: alguém apresentou um prefixo inventado). A tela diz isso.
                        'chave'    => (string)($l['chave_nome'] ?? ''),
                        'prefixo'  => (string)($l['key_prefix'] ?? ''),
                        'metodo'   => (string)$l['method'],
                        'caminho'  => (string)$l['path'],
                        'status'   => (int)$l['status'],
                        'erro'     => (string)($l['error_code'] ?? ''),
                        'ip'       => (string)($l['ip'] ?? ''),
                        'ms'       => (int)$l['duration_ms'],
                        'quando'   => (string)$l['created_at'],
                    ],
                    $linhas
                ),
                'total'   => $total,
                'pagina'  => $pagina,
                'paginas' => $paginas,
                'falha'   => false,
            ];
        } catch (\Throwable $e) {
            error_log('[extracthunters] api log lista: ' . $e->getMessage());
            return ['linhas' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1, 'falha' => true];
        }
    }

    /**
     * Números do topo da tela, medidos nas últimas 24 h.
     *
     * @return array{total:int, recusadas:int, erros:int, ms_medio:int, chaves:int}
     */
    public static function resumo24h(): array
    {
        try {
            $l = Database::one(
                'SELECT COUNT(*) AS total,
                        SUM(status IN (401, 403, 429)) AS recusadas,
                        SUM(status >= 500) AS erros,
                        AVG(duration_ms) AS ms,
                        COUNT(DISTINCT key_id) AS chaves
                   FROM api_request_log
                  WHERE created_at > (NOW() - INTERVAL 1 DAY)'
            ) ?? [];
        } catch (\Throwable $e) {
            error_log('[extracthunters] api resumo: ' . $e->getMessage());
            $l = [];
        }
        return [
            'total'     => (int)($l['total'] ?? 0),
            'recusadas' => (int)($l['recusadas'] ?? 0),
            'erros'     => (int)($l['erros'] ?? 0),
            'ms_medio'  => (int)round((float)($l['ms'] ?? 0)),
            'chaves'    => (int)($l['chaves'] ?? 0),
        ];
    }

    /**
     * Expurgo por retenção. NÃO é um botão da tela — é para um script de manutenção.
     *
     * O piso de 1 dia é deliberado: a janela do limite por minuto vive nesta tabela, e
     * apagar "tudo" zeraria o limite de todas as chaves no mesmo instante.
     */
    public static function expurgar(int $dias): int
    {
        $dias = max(1, min(3650, $dias));
        $stmt = Database::run('DELETE FROM api_request_log WHERE created_at < (NOW() - INTERVAL ' . $dias . ' DAY)');
        return $stmt->rowCount();
    }
}
