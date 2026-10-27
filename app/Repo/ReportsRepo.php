<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * Leitura e escrita da tela ADMIN · RELATÓRIOS (/admin/relatorios).
 *
 * Duas tabelas, um único formato de saída: `player_bug_reports` (o que o jogador manda pela
 * tela RELATÓRIO DE BUG) e `support_tickets` (o que ele abre pela tela SUPORTE). A view não
 * sabe qual das duas está desenhando — recebe as mesmas chaves e um `tipo`.
 *
 * POR QUE OS DOIS CASOS VIVEM NA MESMA CLASSE
 * -------------------------------------------
 * Porque a diferença entre eles cabe numa tabela de metadados (self::FONTES): nome da tabela,
 * coluna do identificador visível, colunas de busca e o dicionário de estados. Duas classes
 * gêmeas iriam divergir no primeiro filtro novo. O que NÃO é genérico — os campos próprios de
 * cada uma (area/severity/steps contra category/priority/message) — sai nomeado, não numa
 * sopa de chaves opcionais.
 *
 * SEGURANÇA
 * ---------
 * Nada de valor de usuário entra em SQL como texto. O único trecho montado em PHP é a cláusula
 * ORDER BY / LIMIT, e mesmo esses saem de listas fechadas declaradas aqui (self::ORDENS) ou de
 * (int) — o placeholder nomeado não serve para LIMIT com PDO::ATTR_EMULATE_PREPARES=false.
 */
final class ReportsRepo
{
    /** Aba => tabela e colunas que mudam de nome entre as duas. */
    private const FONTES = [
        'bugs' => [
            'tabela'  => 'player_bug_reports',
            'alias'   => 'r',
            'codigo'  => 'code',            // identificador que o jogador vê (BUG-4812)
            'titulo'  => 'title',
            'grupo'   => 'area',            // campo "categoria" desta aba
            'peso'    => 'severity',        // campo "gravidade" desta aba
            'corpo'   => 'description',
            'busca'   => ['code', 'title', 'description', 'steps'],
        ],
        'suporte' => [
            'tabela'  => 'support_tickets',
            'alias'   => 'r',
            'codigo'  => 'protocol',
            'titulo'  => 'subject',
            'grupo'   => 'category',
            'peso'    => 'priority',
            'corpo'   => 'message',
            'busca'   => ['protocol', 'subject', 'message'],
        ],
    ];

    /**
     * Estados de cada aba, na ordem do ciclo. `encerra` marca o estado terminal: é ele que
     * carimba `closed_at` nos bugs (a tabela de chamados não tem essa coluna — tem updated_at,
     * que o MySQL mantém sozinho).
     *
     * Esta lista é a MESMA de app/Data/admin-relatorios.php de propósito: lá ficam os rótulos
     * que o operador lê, aqui fica a regra que o banco obedece. Um arquivo de dados não pode
     * ser o que autoriza uma escrita — ele é texto, e texto muda sem ninguém reler o SQL.
     */
    private const ESTADOS = [
        'bugs'    => ['recebido' => false, 'analise' => false, 'corrigido' => true, 'duplicado' => true, 'recusado' => true],
        'suporte' => ['aberto' => false, 'analise' => false, 'resolvido' => true, 'fechado' => true],
    ];

    /**
     * Ordenações permitidas, já em SQL. `peso` ordena pelo campo de gravidade/prioridade do
     * mais grave para o menos: FIELD() devolve 0 para o que não está na lista, e um valor
     * desconhecido no banco não pode furar a fila na frente de uma crítica — daí o `=0` antes.
     */
    private const ORDENS = [
        'bugs' => [
            'recentes' => 'r.created_at DESC, r.id DESC',
            'antigos'  => 'r.created_at ASC, r.id ASC',
            'peso'     => "FIELD(r.severity,'critica','alta','media','baixa')=0, "
                        . "FIELD(r.severity,'critica','alta','media','baixa'), r.created_at DESC",
        ],
        'suporte' => [
            'recentes' => 'r.created_at DESC, r.id DESC',
            'antigos'  => 'r.created_at ASC, r.id ASC',
            'peso'     => "FIELD(r.priority,'CRÍTICA','ALTA','NORMAL','BAIXA')=0, "
                        . "FIELD(r.priority,'CRÍTICA','ALTA','NORMAL','BAIXA'), r.created_at DESC",
        ],
    ];

    /** @return list<string> Abas válidas — a whitelist que o controller usa para o `pick`. */
    public static function abas(): array
    {
        return array_keys(self::FONTES);
    }

    /** @return list<string> Estados graváveis nesta aba, na ordem do ciclo. */
    public static function estados(string $aba): array
    {
        return array_keys(self::ESTADOS[$aba] ?? []);
    }

    /** O estado encerra o relatório (e, nos bugs, carimba closed_at)? */
    public static function encerra(string $aba, string $status): bool
    {
        return (bool)(self::ESTADOS[$aba][$status] ?? false);
    }

    /** @return list<string> Estados que ainda contam como fila de trabalho. */
    public static function estadosAbertos(string $aba): array
    {
        return array_keys(array_filter(self::ESTADOS[$aba] ?? [], static fn(bool $t): bool => !$t));
    }

    /**
     * Contagem de fila das DUAS abas, para os KPIs da topbar. Uma consulta por tabela; sem
     * filtro nenhum, porque KPI que muda com o filtro da lista não é KPI, é rodapé de tabela.
     *
     * @return array{bugs_abertos:int,bugs_total:int,tickets_abertos:int,tickets_total:int,criticos:int}
     */
    public static function resumo(): array
    {
        $bugsAbertos = self::estadosAbertos('bugs');
        $tickAbertos = self::estadosAbertos('suporte');

        return [
            'bugs_abertos'    => self::contar('player_bug_reports', 'status', $bugsAbertos),
            'bugs_total'      => (int)Database::value('SELECT COUNT(*) FROM player_bug_reports'),
            'tickets_abertos' => self::contar('support_tickets', 'status', $tickAbertos),
            'tickets_total'   => (int)Database::value('SELECT COUNT(*) FROM support_tickets'),
            // O que precisa de gente hoje: gravidade máxima AINDA na fila, somando as duas
            // tabelas. É o único número da topbar que justifica alguém largar o que está fazendo.
            'criticos'        => self::contar('player_bug_reports', 'severity', ['critica', 'alta'], 'status', $bugsAbertos)
                               + self::contar('support_tickets', 'priority', ['CRÍTICA', 'ALTA'], 'status', $tickAbertos),
        ];
    }

    /**
     * Quantos relatórios existem em CADA estado, já com os demais filtros aplicados (busca,
     * categoria, gravidade) mas ignorando o filtro de estado — os chips precisam dizer para
     * onde cada um deles levaria, e não repetir o recorte que já está em tela.
     *
     * @param array<string,string> $filtros
     * @return array<string,int>
     */
    public static function contagemPorEstado(string $aba, array $filtros): array
    {
        $f = self::FONTES[$aba];
        [$onde, $par] = self::criterios($aba, $filtros, false);

        $out = array_fill_keys(self::estados($aba), 0);
        $sql = 'SELECT r.status, COUNT(*) AS n FROM ' . $f['tabela'] . ' r '
             . self::JUNCAO . $onde . ' GROUP BY r.status';
        foreach (Database::all($sql, $par) as $linha) {
            $out[(string)$linha['status']] = (int)$linha['n'];
        }
        return $out;
    }

    /**
     * Uma página da listagem.
     *
     * @param array<string,string> $filtros q, status, grupo, peso, ord
     * @return array{linhas:list<array<string,mixed>>, total:int, pagina:int, paginas:int, de:int, ate:int}
     */
    public static function listar(string $aba, array $filtros, int $pagina = 1, int $porPagina = 20): array
    {
        $f = self::FONTES[$aba];
        [$onde, $par] = self::criterios($aba, $filtros, true);

        $total = (int)Database::value(
            'SELECT COUNT(*) FROM ' . $f['tabela'] . ' r ' . self::JUNCAO . $onde,
            $par
        );

        // Página fora do fim (o operador excluiu o último item da página 3, por exemplo) volta
        // para a última página que existe, em vez de mostrar uma tabela vazia com paginador.
        $porPagina = max(5, min(100, $porPagina));
        $paginas   = max(1, (int)ceil($total / $porPagina));
        $pagina    = max(1, min($pagina, $paginas));
        $offset    = ($pagina - 1) * $porPagina;

        $ordem = self::ORDENS[$aba][$filtros['ord'] ?? ''] ?? self::ORDENS[$aba]['recentes'];

        $linhas = $total === 0 ? [] : Database::all(
            'SELECT ' . self::colunas($aba) . ' FROM ' . $f['tabela'] . ' r ' . self::JUNCAO
            . $onde . ' ORDER BY ' . $ordem . ' LIMIT ' . $porPagina . ' OFFSET ' . $offset,
            $par
        );

        return [
            'linhas'  => array_map(static fn(array $l): array => self::normalizar($aba, $l), $linhas),
            'total'   => $total,
            'pagina'  => $pagina,
            'paginas' => $paginas,
            'de'      => $total === 0 ? 0 : $offset + 1,
            'ate'     => min($offset + $porPagina, $total),
        ];
    }

    /** Um relatório inteiro (com descrição e passos), ou null quando o id não existe. */
    public static function detalhe(string $aba, int $id): ?array
    {
        if ($id <= 0 || !isset(self::FONTES[$aba])) {
            return null;
        }
        $f = self::FONTES[$aba];
        $linha = Database::one(
            'SELECT ' . self::colunas($aba, true) . ' FROM ' . $f['tabela'] . ' r ' . self::JUNCAO
            . ' WHERE r.id = :id LIMIT 1',
            ['id' => $id]
        );
        return $linha === null ? null : self::normalizar($aba, $linha, true);
    }

    /**
     * Grava o novo estado. Devolve false quando o id sumiu entre a leitura e o POST.
     *
     * Nos bugs o `closed_at` acompanha o estado: entrar num estado terminal carimba a data
     * (se ainda não houver uma — reabrir e fechar de novo não pode reescrever o encerramento
     * original), e voltar para a fila limpa o carimbo. Sem isso a tela do jogador mostraria
     * "ENCERRADO EM" num relatório que voltou para análise.
     */
    public static function mudarEstado(string $aba, int $id, string $status): bool
    {
        if (!isset(self::ESTADOS[$aba][$status])) {
            return false;   // dupla checagem: o controller já validou, mas quem grava confere
        }
        $tabela = self::FONTES[$aba]['tabela'];

        if ($aba === 'bugs') {
            $sql = self::encerra($aba, $status)
                ? 'UPDATE ' . $tabela . ' SET status = :s, closed_at = COALESCE(closed_at, NOW()) WHERE id = :id'
                : 'UPDATE ' . $tabela . ' SET status = :s, closed_at = NULL WHERE id = :id';
        } else {
            $sql = 'UPDATE ' . $tabela . ' SET status = :s WHERE id = :id';
        }

        return Database::run($sql, ['s' => $status, 'id' => $id])->rowCount() > 0;
    }

    /** Apaga um relatório. O chamador já conferiu que ele está encerrado. */
    public static function excluir(string $aba, int $id): bool
    {
        if (!isset(self::FONTES[$aba]) || $id <= 0) {
            return false;
        }
        return Database::run(
            'DELETE FROM ' . self::FONTES[$aba]['tabela'] . ' WHERE id = :id',
            ['id' => $id]
        )->rowCount() > 0;
    }

    /**
     * Valores de categoria realmente presentes na tabela — é isso que enche o seletor.
     * Um filtro que oferece oito áreas quando só quatro existem faz o operador procurar
     * lista vazia; e uma área gravada por uma build antiga, fora do catálogo, precisa
     * continuar filtrável.
     *
     * @return list<string>
     */
    public static function gruposEmUso(string $aba): array
    {
        $f = self::FONTES[$aba];
        $col = $f['grupo'];
        $rows = Database::all(
            'SELECT DISTINCT ' . $col . ' AS v FROM ' . $f['tabela'] . ' WHERE ' . $col . " <> '' ORDER BY v"
        );
        return array_map(static fn(array $r): string => (string)$r['v'], $rows);
    }

    /* ===================================================================== interno */

    /**
     * O jogador dono do relatório entra por LEFT JOIN: a conta pode ter sido apagada e o
     * relatório continua existindo como registro de operação (a FK é ON DELETE CASCADE hoje,
     * mas a lista não pode depender disso para não sumir uma linha inteira).
     */
    private const JUNCAO = ' LEFT JOIN users u ON u.id = r.user_id'
                         . ' LEFT JOIN players p ON p.user_id = r.user_id';

    /** Colunas do SELECT. O corpo (descrição/mensagem) só entra no detalhe: numa lista de
     *  cem linhas seriam cem TEXT trafegados para desenhar um resumo de duas linhas. */
    private static function colunas(string $aba, bool $completo = false): string
    {
        $comum = 'r.id, r.user_id, r.status, r.created_at, r.attach_logs, '
               . 'u.username, p.display_name, p.avatar';

        if ($aba === 'bugs') {
            $col = $comum . ', r.code AS codigo, r.title AS titulo, r.area AS grupo, '
                 . 'r.severity AS peso, r.closed_at';
            return $completo ? $col . ', r.description, r.steps' : $col;
        }

        $col = $comum . ', r.protocol AS codigo, r.subject AS titulo, r.category AS grupo, '
             . 'r.priority AS peso, r.updated_at';
        return $completo ? $col . ', r.message' : $col;
    }

    /**
     * WHERE + parâmetros a partir dos filtros da tela.
     *
     * @param array<string,string> $filtros
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function criterios(string $aba, array $filtros, bool $comStatus): array
    {
        $f    = self::FONTES[$aba];
        $cond = [];
        $par  = [];

        $status = (string)($filtros['status'] ?? '');
        if ($comStatus && $status !== '' && isset(self::ESTADOS[$aba][$status])) {
            $cond[] = 'r.status = :status';
            $par['status'] = $status;
        }

        $grupo = (string)($filtros['grupo'] ?? '');
        if ($grupo !== '') {
            $cond[] = 'r.' . $f['grupo'] . ' = :grupo';
            $par['grupo'] = $grupo;
        }

        $peso = (string)($filtros['peso'] ?? '');
        if ($peso !== '') {
            $cond[] = 'r.' . $f['peso'] . ' = :peso';
            $par['peso'] = $peso;
        }

        $q = trim((string)($filtros['q'] ?? ''));
        if ($q !== '') {
            // LIKE com os curingas do próprio usuário escapados: quem digita "100%" procura
            // "100%", e não "100" seguido de qualquer coisa.
            $termo = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

            // Um placeholder POR CAMPO, e não `:q` repetido: o PDO deste projeto roda com
            // PDO::ATTR_EMULATE_PREPARES=false, e o MySQL nativo não aceita o mesmo nome
            // duas vezes na mesma consulta — a busca morria com "Invalid parameter number".
            $alvos  = array_merge(
                array_map(static fn(string $c): string => 'r.' . $c, $f['busca']),
                ['u.username', 'p.display_name']
            );
            $campos = [];
            foreach ($alvos as $i => $coluna) {
                $campos[] = $coluna . ' LIKE :q' . $i;
                $par['q' . $i] = $termo;
            }
            $cond[] = '(' . implode(' OR ', $campos) . ')';
        }

        return [$cond === [] ? '' : ' WHERE ' . implode(' AND ', $cond), $par];
    }

    /** Uma linha do banco no formato único que a view consome. */
    private static function normalizar(string $aba, array $l, bool $completo = false): array
    {
        $criado = strtotime((string)$l['created_at']) ?: time();

        $comum = [
            'id'       => (int)$l['id'],
            'tipo'     => $aba,
            'codigo'   => $aba === 'bugs' ? (string)$l['codigo'] : '#' . (int)$l['codigo'],
            'titulo'   => (string)$l['titulo'],
            'grupo'    => (string)$l['grupo'],
            'peso'     => (string)$l['peso'],
            'status'   => (string)$l['status'],
            'criado'   => $criado,
            'logs'     => (bool)$l['attach_logs'],
            'user_id'  => (int)$l['user_id'],
            // display_name é o nome no jogo; username é a conta. Quando os dois somem, a
            // conta foi apagada e a view precisa dizer isso — não inventar "Anônimo".
            'jogador'  => (string)($l['display_name'] ?? '') ?: (string)($l['username'] ?? ''),
            'conta'    => (string)($l['username'] ?? ''),
            'avatar'   => (string)($l['avatar'] ?? ''),
        ];

        if ($aba === 'bugs') {
            $fechado = $l['closed_at'] ?? null;
            $comum['encerrado'] = $fechado ? (strtotime((string)$fechado) ?: null) : null;
            if ($completo) {
                $comum['descricao'] = (string)($l['description'] ?? '');
                $comum['passos']    = (string)($l['steps'] ?? '');
            }
            return $comum;
        }

        $comum['atualizado'] = strtotime((string)($l['updated_at'] ?? '')) ?: $criado;
        if ($completo) {
            $comum['descricao'] = (string)($l['message'] ?? '');
            $comum['passos']    = '';
        }
        return $comum;
    }

    /**
     * COUNT com uma (ou duas) cláusulas IN de tamanho variável. Os placeholders são gerados
     * por posição a partir do TAMANHO da lista — nunca do conteúdo dela.
     *
     * @param list<string> $valores @param list<string> $valores2
     */
    private static function contar(
        string $tabela,
        string $coluna,
        array $valores,
        ?string $coluna2 = null,
        array $valores2 = [],
    ): int {
        if ($valores === [] || ($coluna2 !== null && $valores2 === [])) {
            return 0;
        }
        $par  = [];
        $cond = [$coluna . ' IN (' . self::marcadores($valores, 'a', $par) . ')'];
        if ($coluna2 !== null) {
            $cond[] = $coluna2 . ' IN (' . self::marcadores($valores2, 'b', $par) . ')';
        }
        return (int)Database::value(
            'SELECT COUNT(*) FROM ' . $tabela . ' WHERE ' . implode(' AND ', $cond),
            $par
        );
    }

    /** @param list<string> $valores @param array<string,mixed> $par */
    private static function marcadores(array $valores, string $prefixo, array &$par): string
    {
        $nomes = [];
        foreach (array_values($valores) as $i => $v) {
            $nome = ':' . $prefixo . $i;
            $nomes[] = $nome;
            $par[$prefixo . $i] = $v;
        }
        return implode(',', $nomes);
    }
}
