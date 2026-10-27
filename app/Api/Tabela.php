<?php declare(strict_types=1);

namespace App\Api;

use App\Core\Database;

/**
 * O MOTOR DE CRUD DECLARATIVO da API.
 *
 * Nove coleções desta API (missões, conquistas, mapas, marcadores, itens, ofertas da loja,
 * desafios, notícias e avisos) são a mesma operação sobre tabelas diferentes: listar com
 * filtro e página, obter por id, criar, alterar e apagar. Escrever nove arquivos quase iguais
 * garante que o décimo nasça com um `SELECT` sem `LIMIT`, ou com um campo que ninguém validou.
 *
 * Então a forma de cada coleção é DECLARADA (App\Api\Catalogo) e executada aqui. O que a
 * declaração diz:
 *
 *   'tabela'  nome real no banco (nunca vem do cliente)
 *   'chave'   coluna da chave primária
 *   'ordem'   ORDER BY padrão, escrito à mão
 *   'busca'   colunas varridas pelo ?q=
 *   'filtros' parâmetro de URL => coluna (lista branca)
 *   'pai'     coluna que amarra a coleção ao pai (marcadores de um mapa)
 *   'campos'  campo => regra de App\Api\Campo (é também a lista branca de escrita)
 *
 * NADA que venha do cliente vira nome de coluna. O cliente escolhe uma CHAVE da declaração;
 * a declaração escolhe a coluna. É a mesma disciplina de ORDER BY do AuditRepo, estendida a
 * filtro, ordenação e gravação — com `campos` como lista branca, um corpo JSON com
 * `"role": "owner"` numa coleção de itens não encontra onde encostar.
 */
final class Tabela
{
    /** Teto absoluto de uma página. O cliente pede ?limit=, mas não decide despejar a tabela. */
    public const LIMITE_MAX = 200;
    public const LIMITE_PADRAO = 50;

    /** Tamanho máximo do termo de busca: acima disso não é busca, é despejo de texto no LIKE. */
    private const BUSCA_MAX = 60;

    // ===================================================================== leitura

    /**
     * Uma página da coleção.
     *
     * @param array<string,mixed> $decl
     * @param array<string,string> $consulta parâmetros já lidos da URL (q, filtros)
     * @return array{linhas:list<array<string,mixed>>, total:int}
     */
    public static function listar(array $decl, array $consulta, int $limite, int $deslocamento, ?int $paiId = null): array
    {
        [$onde, $par] = self::montarOnde($decl, $consulta, $paiId);

        $tabela = self::tabela($decl);
        $total  = (int)Database::value("SELECT COUNT(*) FROM `$tabela` WHERE " . $onde, $par);

        // LIMIT/OFFSET entram como literal porque o PDO roda sem emulação e o MySQL não
        // aceita placeholder nessa posição. Os dois são int já presos a uma faixa.
        $limite = max(1, min(self::LIMITE_MAX, $limite));
        $deslocamento = max(0, $deslocamento);

        $linhas = Database::all(
            "SELECT " . self::colunas($decl) . " FROM `$tabela` WHERE " . $onde
            . ' ORDER BY ' . self::ordem($decl)
            . ' LIMIT ' . $limite . ' OFFSET ' . $deslocamento,
            $par
        );

        return ['linhas' => array_map(static fn(array $l): array => self::saida($decl, $l), $linhas), 'total' => $total];
    }

    /**
     * Um registro pelo id, ou null.
     *
     * @param array<string,mixed> $decl
     * @return array<string,mixed>|null
     */
    public static function obter(array $decl, int $id, ?int $paiId = null): ?array
    {
        $tabela = self::tabela($decl);
        $chave  = self::chave($decl);
        $par    = ['id' => $id];
        $onde   = "`$chave` = :id";

        // O pai entra no WHERE, e não numa conferência posterior: sem isso,
        // /maps/3/markers/99 devolveria o marcador 99 mesmo que ele fosse do mapa 7.
        if ($paiId !== null && isset($decl['pai'])) {
            $onde .= ' AND `' . self::coluna((string)$decl['pai']) . '` = :pai';
            $par['pai'] = $paiId;
        }

        $l = Database::one("SELECT " . self::colunas($decl) . " FROM `$tabela` WHERE $onde LIMIT 1", $par);
        return $l === null ? null : self::saida($decl, $l);
    }

    // ===================================================================== escrita

    /**
     * Cria um registro. Devolve o id, ou os erros de validação.
     *
     * @param array<string,mixed> $decl @param array<string,mixed> $corpo
     * @return array{ok:bool, id:int, erros:array<string,string>}
     */
    public static function criar(array $decl, array $corpo, ?int $paiId = null): array
    {
        $v = self::validar($decl, $corpo, true);
        if (!$v['ok']) {
            return ['ok' => false, 'id' => 0, 'erros' => $v['erros']];
        }

        $valores = $v['valores'];
        if ($paiId !== null && isset($decl['pai'])) {
            $valores[self::coluna((string)$decl['pai'])] = $paiId;
        }

        $erro = self::conferirUnicos($decl, $valores, 0);
        if ($erro !== []) {
            return ['ok' => false, 'id' => 0, 'erros' => $erro];
        }

        return ['ok' => true, 'id' => Database::insert(self::tabela($decl), $valores), 'erros' => []];
    }

    /**
     * Altera um registro. PARCIAL: só o que veio no corpo é tocado.
     *
     * Corpo sem nenhum campo conhecido devolve erro em vez de "alterado com sucesso" sem ter
     * alterado nada — quase sempre isso é um nome de campo escrito errado do outro lado, e
     * responder 200 esconderia o defeito por semanas.
     *
     * @param array<string,mixed> $decl @param array<string,mixed> $corpo
     * @return array{ok:bool, erros:array<string,string>}
     */
    public static function atualizar(array $decl, int $id, array $corpo, ?int $paiId = null): array
    {
        $v = self::validar($decl, $corpo, false);
        if (!$v['ok']) {
            return ['ok' => false, 'erros' => $v['erros']];
        }
        if ($v['valores'] === []) {
            return ['ok' => false, 'erros' => ['_' => 'Nenhum campo conhecido veio no corpo. Confira os nomes.']];
        }

        $erro = self::conferirUnicos($decl, $v['valores'], $id);
        if ($erro !== []) {
            return ['ok' => false, 'erros' => $erro];
        }

        $sets = [];
        foreach (array_keys($v['valores']) as $col) {
            $sets[] = "`$col` = :$col";
        }
        $par = $v['valores'] + ['_id' => $id];

        $tabela = self::tabela($decl);
        $chave  = self::chave($decl);
        $onde   = "`$chave` = :_id";
        if ($paiId !== null && isset($decl['pai'])) {
            $onde .= ' AND `' . self::coluna((string)$decl['pai']) . '` = :_pai';
            $par['_pai'] = $paiId;
        }

        Database::run("UPDATE `$tabela` SET " . implode(', ', $sets) . " WHERE $onde", $par);
        return ['ok' => true, 'erros' => []];
    }

    /** @param array<string,mixed> $decl */
    public static function excluir(array $decl, int $id, ?int $paiId = null): bool
    {
        $tabela = self::tabela($decl);
        $chave  = self::chave($decl);
        $par    = ['id' => $id];
        $onde   = "`$chave` = :id";
        if ($paiId !== null && isset($decl['pai'])) {
            $onde .= ' AND `' . self::coluna((string)$decl['pai']) . '` = :pai';
            $par['pai'] = $paiId;
        }
        return Database::run("DELETE FROM `$tabela` WHERE $onde", $par)->rowCount() > 0;
    }

    /**
     * Quantos registros dependem deste (para recusar uma exclusão que deixaria órfãos).
     *
     * @param list<array{tabela:string, coluna:string, rotulo:string}> $dependentes
     * @return array<string,int> rótulo => quantidade, só os maiores que zero
     */
    public static function dependencias(array $dependentes, int $id): array
    {
        $out = [];
        foreach ($dependentes as $d) {
            $tabela = self::coluna($d['tabela']);
            $coluna = self::coluna($d['coluna']);
            $n = (int)Database::value("SELECT COUNT(*) FROM `$tabela` WHERE `$coluna` = :id", ['id' => $id]);
            if ($n > 0) {
                $out[$d['rotulo']] = $n;
            }
        }
        return $out;
    }

    // ===================================================================== validação

    /**
     * Valida o corpo contra `campos`.
     *
     * $criando = true  -> campo obrigatório ausente é erro; campo com padrão recebe o padrão.
     * $criando = false -> só o que veio é validado (alteração parcial).
     *
     * Campo DESCONHECIDO é ignorado em silêncio de propósito: um cliente que manda
     * `updated_at` junto não deve ser recusado por causa disso, e aceitar seria deixar
     * escrever coluna fora da lista branca. O que ele não pode é ficar sem resposta quando
     * NENHUM campo é conhecido — esse caso a atualizar() trata.
     *
     * @param array<string,mixed> $decl @param array<string,mixed> $corpo
     * @return array{ok:bool, erros:array<string,string>, valores:array<string,mixed>}
     */
    private static function validar(array $decl, array $corpo, bool $criando): array
    {
        $erros   = [];
        $valores = [];

        /** @var array<string,array<string,mixed>> $campos */
        $campos = (array)($decl['campos'] ?? []);

        foreach ($campos as $nome => $regra) {
            $presente = array_key_exists($nome, $corpo);

            if (!$presente) {
                if (!$criando) {
                    continue;
                }
                if (!empty($regra['obrigatorio'])) {
                    $erros[$nome] = 'Campo obrigatório.';
                    continue;
                }
                if (array_key_exists('padrao', $regra)) {
                    $valores[self::coluna($nome)] = $regra['padrao'];
                }
                continue;
            }

            $r = Campo::validar($corpo[$nome], $regra);
            if (!$r['ok']) {
                $erros[$nome] = $r['erro'];
                continue;
            }
            if ($r['valor'] === null && !empty($regra['obrigatorio'])) {
                $erros[$nome] = 'Campo obrigatório.';
                continue;
            }
            $valores[self::coluna($nome)] = $r['valor'];
        }

        return ['ok' => $erros === [], 'erros' => $erros, 'valores' => $valores];
    }

    /**
     * Campos marcados `unico` não podem repetir. A checagem é feita aqui porque nem toda
     * coluna tem UNIQUE no banco — e onde tem, o erro do MySQL viraria 500 com texto de
     * driver no lugar de "este slug já existe".
     *
     * @param array<string,mixed> $decl @param array<string,mixed> $valores
     * @return array<string,string>
     */
    private static function conferirUnicos(array $decl, array $valores, int $exceto): array
    {
        $erros  = [];
        $tabela = self::tabela($decl);
        $chave  = self::chave($decl);

        foreach ((array)($decl['campos'] ?? []) as $nome => $regra) {
            $col = self::coluna((string)$nome);
            if (empty($regra['unico']) || !array_key_exists($col, $valores)) {
                continue;
            }
            $n = (int)Database::value(
                "SELECT COUNT(*) FROM `$tabela` WHERE `$col` = :v AND `$chave` <> :id",
                ['v' => $valores[$col], 'id' => $exceto]
            );
            if ($n > 0) {
                $erros[$nome] = 'Já existe outro registro com este valor.';
            }
        }
        return $erros;
    }

    // ===================================================================== apoio

    /**
     * WHERE + parâmetros: pai, busca livre e filtros da lista branca.
     *
     * @param array<string,mixed> $decl @param array<string,string> $consulta
     * @return array{0:string, 1:array<string,mixed>}
     */
    private static function montarOnde(array $decl, array $consulta, ?int $paiId): array
    {
        $cond = [];
        $par  = [];

        if ($paiId !== null && isset($decl['pai'])) {
            $cond[] = '`' . self::coluna((string)$decl['pai']) . '` = :pai';
            $par['pai'] = $paiId;
        }

        $q = mb_substr(trim((string)($consulta['q'] ?? '')), 0, self::BUSCA_MAX);
        $colunasBusca = (array)($decl['busca'] ?? []);
        if ($q !== '' && $colunasBusca !== []) {
            // Um placeholder por coluna: com PDO sem emulação, repetir :q em várias
            // comparações é "Invalid parameter number". ESCAPE '!' porque % e _ digitados
            // pelo cliente são texto procurado, não coringa.
            $termo  = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            $partes = [];
            foreach (array_values($colunasBusca) as $i => $col) {
                $partes[] = '`' . self::coluna((string)$col) . "` LIKE :q$i ESCAPE '!'";
                $par["q$i"] = $termo;
            }
            $cond[] = '(' . implode(' OR ', $partes) . ')';
        }

        foreach ((array)($decl['filtros'] ?? []) as $parametro => $coluna) {
            $v = trim((string)($consulta[$parametro] ?? ''));
            if ($v === '') {
                continue;
            }
            $cond[] = '`' . self::coluna((string)$coluna) . '` = :f_' . preg_replace('/[^a-z0-9_]/', '', (string)$parametro);
            $par['f_' . preg_replace('/[^a-z0-9_]/', '', (string)$parametro)] = $v;
        }

        return [$cond === [] ? '1' : implode(' AND ', $cond), $par];
    }

    /**
     * Linha do banco -> objeto JSON com os tipos certos.
     *
     * Existe porque o PDO devolve DECIMAL como texto e TINYINT(1) como 0/1: sem esta
     * conversão, `"locked": 1` e `"weight_kg": "3.200"` chegariam ao cliente e obrigariam
     * cada integrador a adivinhar o tipo de cada campo.
     *
     * @param array<string,mixed> $decl @param array<string,mixed> $l
     * @return array<string,mixed>
     */
    private static function saida(array $decl, array $l): array
    {
        $out = [];
        $chave = self::chave($decl);
        $out['id'] = isset($l[$chave]) ? (is_numeric($l[$chave]) ? (int)$l[$chave] : $l[$chave]) : null;

        foreach ((array)($decl['campos'] ?? []) as $nome => $regra) {
            $col = self::coluna((string)$nome);
            if (!array_key_exists($col, $l)) {
                continue;
            }
            $v = $l[$col];
            if ($v === null) {
                $out[$nome] = null;
                continue;
            }
            $out[$nome] = match ((string)($regra['tipo'] ?? 'texto')) {
                'int', 'ref' => (int)$v,
                'bool'       => (bool)(int)$v,
                'decimal'    => (float)$v,
                'json'       => json_decode((string)$v, true),
                default      => (string)$v,
            };
        }

        // Colunas extras declaradas em `saida_extra` (só leitura: não entram em `campos`
        // porque não podem ser escritas pela API, como created_at).
        foreach ((array)($decl['saida_extra'] ?? []) as $nome => $col) {
            if (array_key_exists((string)$col, $l)) {
                $out[$nome] = $l[(string)$col] === null ? null : (string)$l[(string)$col];
            }
        }
        if (isset($decl['pai']) && array_key_exists(self::coluna((string)$decl['pai']), $l)) {
            $out[(string)$decl['pai']] = (int)$l[self::coluna((string)$decl['pai'])];
        }
        return $out;
    }

    /** SELECT explícito: a chave, as colunas dos campos e as extras. Nunca `SELECT *`. */
    private static function colunas(array $decl): string
    {
        $cols = [self::chave($decl)];
        foreach (array_keys((array)($decl['campos'] ?? [])) as $nome) {
            $cols[] = self::coluna((string)$nome);
        }
        foreach ((array)($decl['saida_extra'] ?? []) as $col) {
            $cols[] = self::coluna((string)$col);
        }
        if (isset($decl['pai'])) {
            $cols[] = self::coluna((string)$decl['pai']);
        }
        return '`' . implode('`, `', array_values(array_unique($cols))) . '`';
    }

    private static function tabela(array $decl): string
    {
        return self::coluna((string)($decl['tabela'] ?? ''));
    }

    private static function chave(array $decl): string
    {
        return self::coluna((string)($decl['chave'] ?? 'id'));
    }

    private static function ordem(array $decl): string
    {
        $ordem = (string)($decl['ordem'] ?? '');
        // ORDER BY escrito à mão na declaração (código), nunca montado com dado do cliente.
        // O formato é conferido do mesmo jeito: uma declaração nova e mal escrita não pode
        // virar a porta de injeção que o resto do arquivo evita.
        if (!preg_match('/^[a-z_]+ (?:ASC|DESC)(?:, [a-z_]+ (?:ASC|DESC))*$/', $ordem)) {
            throw new \LogicException('ORDER BY inválido na declaração da API: ' . $ordem);
        }
        return $ordem;
    }

    /**
     * Identificador de tabela/coluna. Toda string que vai para dentro de crases passa por
     * aqui, e nenhuma delas vem do cliente — esta função existe para o caso de alguém, um
     * dia, achar que poderia vir.
     */
    private static function coluna(string $nome): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $nome)) {
            throw new \LogicException('Identificador inválido na declaração da API: ' . $nome);
        }
        return $nome;
    }
}
