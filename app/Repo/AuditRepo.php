<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * Leitura da trilha de auditoria (`admin_audit_log`) — o que a tela /admin/logs mostra.
 *
 * POR QUE UM REPO SÓ PARA ISTO
 * ----------------------------
 * AdminRepo::registrar() ESCREVE a trilha e é chamado por todo o painel; ele não pode
 * carregar junto o SQL de busca, ordenação e paginação de uma tela. Aqui só se LÊ: não
 * existe um único INSERT, UPDATE ou DELETE neste arquivo, e é de propósito — a prova de
 * auditoria não pode ter um caminho de escrita que não seja o registrar().
 *
 * REGRA DE HONESTIDADE DESTA TELA
 * -------------------------------
 * A trilha é o único lugar do painel onde "não sei" e "não aconteceu" são fatos
 * DIFERENTES e não podem virar a mesma tabela vazia. Por isso toda consulta devolve
 * `falha` (o banco não respondeu) separado de `total` (respondeu e não há nada), e a view
 * escreve uma frase diferente para cada caso.
 *
 * O `admin_id` NÃO é chave estrangeira (ver db/patch_v6_admin.php): apagar a conta não pode
 * apagar a prova do que ela fez. O JOIN é LEFT e o resultado carrega três estados de autor —
 * conta existente, conta removida e tentativa anônima —, nunca um nome inventado.
 *
 * ATENÇÃO ao `admin_id` de uma falha de login: ele guarda o id da conta cuja SENHA bateu,
 * mesmo que ela não tenha acesso ao painel. Por isso o papel viaja junto em cada linha: sem
 * ele, um jogador comum aparece na trilha com a mesma cara de um administrador.
 */
final class AuditRepo
{
    /** Linhas por página. 25 é o que cabe inteiro em 1366×768 sem a tabela rolar por dentro. */
    public const POR_PAGINA = 25;

    /**
     * Colunas ordenáveis: chave da URL => expressão SQL.
     *
     * É uma LISTA BRANCA, e não uma coluna montada com o valor da query string: `ord` vem do
     * usuário e entra em ORDER BY, que nenhum placeholder de PDO cobre.
     */
    private const ORDENS = [
        'quando' => 'a.created_at',
        'acao'   => 'a.action',
        'quem'   => "COALESCE(u.username, '')",
        'alvo'   => 'a.entity',
    ];

    /** Teto do termo de busca: acima disso não é busca, é despejo de texto no LIKE. */
    private const BUSCA_MAX = 60;

    /** Campos varridos pela busca livre. Cada um ganha o seu placeholder (ver montarOnde). */
    private const CAMPOS_BUSCA = ['a.action', 'a.entity', 'a.entity_id', 'a.summary', 'a.ip', 'u.username'];

    /** SELECT único da tela: a lista e o detalhe têm de mostrar exatamente as mesmas colunas. */
    private const SELECT = 'SELECT a.id, a.admin_id, a.action, a.entity, a.entity_id, a.summary,
                                   a.meta, a.ip, a.created_at,
                                   u.username, u.role, u.status
                              FROM admin_audit_log a
                         LEFT JOIN users u ON u.id = a.admin_id';

    /**
     * Normaliza o que veio da query string. Nada entra na consulta sem passar por aqui: data
     * inválida vira vazio, ordenação desconhecida cai no padrão, página negativa vira 1.
     *
     * `acao` NÃO é conferida contra a lista de ações existentes: um filtro por ação que
     * sumiu do banco tem de devolver "nada bate com este recorte", e não fingir que o filtro
     * não foi pedido — a diferença importa para quem audita.
     *
     * @param array<string,string|null> $bruto
     * @return array{q:string,acao:string,quem:string,de:string,ate:string,ord:string,dir:string,pag:int,log:int}
     */
    public static function normalizar(array $bruto): array
    {
        $texto = static fn(?string $v, int $max): string => mb_substr(trim((string)$v), 0, $max);

        $de  = self::data($bruto['de']  ?? null);
        $ate = self::data($bruto['ate'] ?? null);
        // Datas trocadas seriam um recorte impossível (0 resultados sem explicação): o par é
        // endireitado em silêncio e a barra mostra o que de fato foi consultado.
        if ($de !== '' && $ate !== '' && $de > $ate) {
            [$de, $ate] = [$ate, $de];
        }

        $quem = $texto($bruto['quem'] ?? null, 20);
        if ($quem !== 'anonimo' && !ctype_digit($quem)) {
            $quem = '';
        }

        $ord = (string)($bruto['ord'] ?? '');
        $dir = strtolower((string)($bruto['dir'] ?? ''));

        return [
            'q'    => $texto($bruto['q'] ?? null, self::BUSCA_MAX),
            'acao' => $texto($bruto['acao'] ?? null, 64),
            'quem' => $quem,
            'de'   => $de,
            'ate'  => $ate,
            'ord'  => isset(self::ORDENS[$ord]) ? $ord : 'quando',
            'dir'  => $dir === 'asc' ? 'asc' : 'desc',
            'pag'  => max(1, (int)($bruto['pag'] ?? 1)),
            'log'  => max(0, (int)($bruto['log'] ?? 0)),
        ];
    }

    /** Verdadeiro quando algum filtro está em uso (a tela usa isto para escolher o vazio certo). */
    public static function temFiltro(array $f): bool
    {
        return ($f['q'] ?? '') !== '' || ($f['acao'] ?? '') !== ''
            || ($f['quem'] ?? '') !== '' || ($f['de'] ?? '') !== '' || ($f['ate'] ?? '') !== '';
    }

    /**
     * Uma página da trilha, já com o total do recorte (para a paginação e para o resumo).
     *
     * @param array<string,mixed> $f filtro normalizado
     * @return array{linhas:list<array<string,mixed>>,total:int,pagina:int,paginas:int,
     *               por_pagina:int,falha:bool}
     */
    public static function pagina(array $f): array
    {
        [$onde, $par] = self::montarOnde($f);

        $total = self::valor('SELECT COUNT(*) FROM admin_audit_log a LEFT JOIN users u ON u.id = a.admin_id WHERE ' . $onde, $par);
        if ($total === null) {
            return ['linhas' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1, 'por_pagina' => self::POR_PAGINA, 'falha' => true];
        }

        $total   = (int)$total;
        $paginas = max(1, (int)ceil($total / self::POR_PAGINA));
        $pagina  = min(max(1, (int)$f['pag']), $paginas);
        $offset  = ($pagina - 1) * self::POR_PAGINA;

        $coluna = self::ORDENS[$f['ord']] ?? self::ORDENS['quando'];
        $dir    = $f['dir'] === 'asc' ? 'ASC' : 'DESC';

        // LIMIT/OFFSET entram como literal porque o PDO roda sem emulação e o MySQL não aceita
        // placeholder nessa posição. Os dois valores são int calculados aqui, nunca texto da URL.
        // `a.id` desempata: sem ele, dois registros do mesmo segundo trocam de lugar entre
        // páginas e a paginação passa a esconder e repetir linhas.
        $linhas = self::linhas(
            self::SELECT . ' WHERE ' . $onde
            . ' ORDER BY ' . $coluna . ' ' . $dir . ', a.id ' . $dir
            . ' LIMIT ' . self::POR_PAGINA . ' OFFSET ' . $offset,
            $par
        );

        return [
            'linhas'     => array_map(self::mapear(...), $linhas),
            'total'      => $total,
            'pagina'     => $pagina,
            'paginas'    => $paginas,
            'por_pagina' => self::POR_PAGINA,
            'falha'      => false,
        ];
    }

    /** Um registro pelo id, no mesmo formato da lista. Null quando não existe. */
    public static function porId(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $linha = self::linha(self::SELECT . ' WHERE a.id = :id LIMIT 1', ['id' => $id]);
        return $linha === null ? null : self::mapear($linha);
    }

    /**
     * Agregados DO RECORTE (não da tabela inteira): é o que dá sentido ao filtro escolhido.
     *
     * @param array<string,mixed> $f
     * @return array{acoes:list<array{acao:string,n:int}>,contas:int,primeiro:string,ultimo:string}
     */
    public static function resumo(array $f, int $limiteAcoes = 6): array
    {
        [$onde, $par] = self::montarOnde($f);

        $acoes = self::linhas(
            'SELECT a.action, COUNT(*) AS n
               FROM admin_audit_log a LEFT JOIN users u ON u.id = a.admin_id
              WHERE ' . $onde . '
           GROUP BY a.action
           ORDER BY n DESC, a.action ASC
              LIMIT ' . max(1, min(20, $limiteAcoes)),
            $par
        );

        $extremos = self::linha(
            'SELECT COUNT(DISTINCT a.admin_id) AS contas, MIN(a.created_at) AS primeiro, MAX(a.created_at) AS ultimo
               FROM admin_audit_log a LEFT JOIN users u ON u.id = a.admin_id
              WHERE ' . $onde,
            $par
        ) ?? [];

        return [
            'acoes'    => array_map(
                static fn(array $l): array => ['acao' => (string)$l['action'], 'n' => (int)$l['n']],
                $acoes
            ),
            // COUNT(DISTINCT) ignora NULL, ou seja, as tentativas anônimas não viram uma
            // "conta" fantasma no contador — elas não são conta nenhuma.
            'contas'   => (int)($extremos['contas'] ?? 0),
            'primeiro' => (string)($extremos['primeiro'] ?? ''),
            'ultimo'   => (string)($extremos['ultimo'] ?? ''),
        ];
    }

    /**
     * O que preencher nos dois <select> da barra: as ações que EXISTEM na trilha e as contas
     * que aparecem nela. Sem lista fixa no código — filtro que oferece opção sem resultado
     * é filtro que mente.
     *
     * @return array{acoes:list<array{acao:string,n:int}>,
     *               contas:list<array{id:int,nome:string,papel:string,n:int}>,
     *               anonimos:int, total:int}
     */
    public static function opcoes(): array
    {
        $acoes = self::linhas('SELECT action, COUNT(*) AS n FROM admin_audit_log GROUP BY action ORDER BY action ASC');

        $contas = self::linhas(
            'SELECT a.admin_id, u.username, u.role, COUNT(*) AS n
               FROM admin_audit_log a
          LEFT JOIN users u ON u.id = a.admin_id
              WHERE a.admin_id IS NOT NULL
           GROUP BY a.admin_id, u.username, u.role
           ORDER BY COALESCE(u.username, CAST(a.admin_id AS CHAR)) ASC'
        );

        return [
            'acoes'  => array_map(
                static fn(array $l): array => ['acao' => (string)$l['action'], 'n' => (int)$l['n']],
                $acoes
            ),
            'contas' => array_map(
                static fn(array $l): array => [
                    'id'    => (int)$l['admin_id'],
                    // Conta apagada continua selecionável pelo id: é justamente dela que a
                    // auditoria mais precisa. O rótulo diz o que houve, não some.
                    'nome'  => (string)($l['username'] ?? ''),
                    'papel' => (string)($l['role'] ?? ''),
                    'n'     => (int)$l['n'],
                ],
                $contas
            ),
            'anonimos' => (int)(self::valor('SELECT COUNT(*) FROM admin_audit_log WHERE admin_id IS NULL') ?? 0),
            'total'    => (int)(self::valor('SELECT COUNT(*) FROM admin_audit_log') ?? 0),
        ];
    }

    // ---------------------------------------------------------------------------- internos

    /**
     * Linha do banco -> linha da tela. A view não decodifica JSON e não interpreta NULL.
     *
     * @param array<string,mixed> $l
     * @return array<string,mixed>
     */
    private static function mapear(array $l): array
    {
        $bruto = $l['meta'] ?? null;
        $meta  = null;
        if (is_string($bruto) && $bruto !== '') {
            $decodificado = json_decode($bruto, true);
            $meta = is_array($decodificado) ? $decodificado : null;
        }

        $adminId = $l['admin_id'] !== null ? (int)$l['admin_id'] : 0;
        $nome    = (string)($l['username'] ?? '');

        // Três estados, nunca dois: sem conta / conta que sumiu / conta que existe. Tratar os
        // dois primeiros como "—" apagaria a diferença entre "ninguém" e "alguém que foi
        // apagado depois", que é exatamente o que uma auditoria precisa distinguir.
        $tipo = match (true) {
            $adminId <= 0  => 'anonimo',
            $nome === ''   => 'removido',
            default        => 'conta',
        };

        return [
            'id'        => (int)$l['id'],
            'acao'      => (string)$l['action'],
            'entidade'  => (string)$l['entity'],
            'alvo'      => (string)($l['entity_id'] ?? ''),
            'resumo'    => (string)$l['summary'],
            'ip'        => (string)($l['ip'] ?? ''),
            'quando'    => (string)$l['created_at'],
            'quando_ts' => (int)strtotime((string)$l['created_at']),
            'meta'      => $meta,
            'meta_bruto'=> is_string($bruto) ? $bruto : '',
            'quem'      => [
                'tipo'   => $tipo,
                'id'     => $adminId,
                'nome'   => $nome,
                'papel'  => (string)($l['role'] ?? ''),
                'situacao' => (string)($l['status'] ?? ''),
            ],
        ];
    }

    /**
     * WHERE + parâmetros do recorte. Devolve '1' quando não há filtro nenhum, para o SQL ser
     * sempre a mesma string e as três consultas (contagem, página e resumo) não divergirem.
     *
     * @param array<string,mixed> $f
     * @return array{0:string, 1:array<string,mixed>}
     */
    private static function montarOnde(array $f): array
    {
        $cond = [];
        $par  = [];

        if (($f['q'] ?? '') !== '') {
            // Um placeholder por campo: com PDO sem emulação, repetir :q em seis comparações
            // é "Invalid parameter number". ESCAPE '!' porque % e _ digitados pelo operador
            // são texto procurado, não coringa — sem isso "100%" casa com a trilha inteira.
            $termo  = '%' . self::escaparLike((string)$f['q']) . '%';
            $partes = [];
            foreach (self::CAMPOS_BUSCA as $i => $campo) {
                $nome = 'q' . $i;
                $partes[] = $campo . ' LIKE :' . $nome . " ESCAPE '!'";
                $par[$nome] = $termo;
            }
            $cond[] = '(' . implode(' OR ', $partes) . ')';
        }

        if (($f['acao'] ?? '') !== '') {
            $cond[] = 'a.action = :acao';
            $par['acao'] = (string)$f['acao'];
        }

        if (($f['quem'] ?? '') === 'anonimo') {
            $cond[] = 'a.admin_id IS NULL';
        } elseif (($f['quem'] ?? '') !== '') {
            $cond[] = 'a.admin_id = :quem';
            $par['quem'] = (int)$f['quem'];
        }

        // A data é um DIA INTEIRO nas duas pontas: quem digita 10/09 em "Até" espera que o
        // que aconteceu às 23h de 10/09 esteja no recorte.
        if (($f['de'] ?? '') !== '') {
            $cond[] = 'a.created_at >= :de';
            $par['de'] = $f['de'] . ' 00:00:00';
        }
        if (($f['ate'] ?? '') !== '') {
            $cond[] = 'a.created_at <= :ate';
            $par['ate'] = $f['ate'] . ' 23:59:59';
        }

        return [$cond === [] ? '1' : implode(' AND ', $cond), $par];
    }

    /** 'YYYY-MM-DD' de verdade (31/02 não passa) ou string vazia. */
    private static function data(?string $v): string
    {
        $v = trim((string)$v);
        if ($v === '') {
            return '';
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v ? $v : '';
    }

    private static function escaparLike(string $termo): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $termo);
    }

    // As três embalagens abaixo devolvem null/[] em vez de deixar a exceção subir: se a
    // tabela sumiu (patch não aplicado) a tela precisa DIZER que não conseguiu ler, e não
    // devolver 500 nem — pior — uma lista vazia que se leia como "nada aconteceu".

    /** @param array<string,mixed> $p @return list<array<string,mixed>> */
    private static function linhas(string $sql, array $p = []): array
    {
        try {
            return Database::all($sql, $p);
        } catch (\Throwable $e) {
            error_log('[extracthunters] logs: ' . $e->getMessage());
            return [];
        }
    }

    /** @param array<string,mixed> $p @return array<string,mixed>|null */
    private static function linha(string $sql, array $p = []): ?array
    {
        try {
            return Database::one($sql, $p);
        } catch (\Throwable $e) {
            error_log('[extracthunters] logs: ' . $e->getMessage());
            return null;
        }
    }

    /** @param array<string,mixed> $p null = a consulta FALHOU (e não "deu zero"). */
    private static function valor(string $sql, array $p = []): mixed
    {
        try {
            $v = Database::value($sql, $p);
            return $v === false ? null : $v;
        } catch (\Throwable $e) {
            error_log('[extracthunters] logs: ' . $e->getMessage());
            return null;
        }
    }
}
