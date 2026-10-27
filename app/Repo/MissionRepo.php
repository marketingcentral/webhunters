<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;
use App\Core\GameRules;

/**
 * Catálogo de missões para o PAINEL: `missions` + `mission_rewards`, com o contador de
 * `player_missions` colado.
 *
 * Existe separado do App\Controllers\MissionsController (lado do jogador) porque as duas
 * telas querem coisas opostas: o jogo lê UMA missão já traduzida para o cartão da HUD; o
 * painel lê TODAS cruas, com quanta gente está em campo com cada uma, para poder editar e
 * excluir sem quebrar ninguém.
 *
 * O contador é a peça central desta tela. `player_missions` cai por ON DELETE CASCADE: apagar
 * uma missão que está em campo apaga o progresso de quem a aceitou, em silêncio e sem volta.
 * Por isso `ativas` viaja junto de cada linha da lista — e não só na hora de confirmar.
 *
 * Vocabulário: origem, facção, faixa de reputação e teto de esquadrão vêm de GameRules (é
 * regra de jogo). Tipo, dificuldade, atividade humana e espécie de recompensa vêm de
 * app/Data/admin-missoes.php, unidos ao DISTINCT da tabela — ver vocabulario().
 */
final class MissionRepo
{
    /** Linhas por página da listagem. Cabe inteiro em 1366×768 sem a página rolar. */
    public const POR_PAGINA = 12;

    /**
     * Estados de `player_missions` que significam "o jogador está com ela EM CAMPO".
     * A coluna aceita PT e EN (ver App\Controllers\MissionsController::STATUS): contar só
     * um dos dois faria a tela liberar uma exclusão que destrói progresso real.
     */
    private const EM_CAMPO = ['ativa', 'active'];

    /** Colunas de ordenação permitidas -> ORDER BY. Nada vindo da URL entra em SQL cru. */
    private const ORDENS = [
        'ordem'   => 'm.sort_order ASC, m.id ASC',
        'nome'    => 'm.name ASC',
        'nivel'   => 'm.level_req DESC, m.name ASC',
        'ativas'  => 'ativas DESC, m.sort_order ASC',
        'recente' => 'm.id DESC',
    ];

    /** Campos de `missions` que o formulário do painel escreve. */
    private const CAMPOS = [
        'slug', 'name', 'giver', 'region', 'type', 'difficulty', 'duration_min', 'players_max',
        'level_req', 'objective', 'target', 'image', 'locked', 'sort_order', 'origin', 'act',
        'requires_faction', 'requires_tier', 'requires_rep', 'squad_min', 'squad_max',
        'human_activity',
    ];

    // ====================================================================== vocabulário

    /**
     * Todas as listas de valor da tela, num lugar só.
     *
     * Cada lista é a do vocabulário canônico UNIDA aos valores que já existem na tabela.
     * A união não é zelo: `missions.type` guarda hoje um `emergencia` que nenhuma lista de
     * GameRules conhece. Sem ela, abrir e salvar essa missão trocaria o tipo dela em silêncio.
     * O que veio só do banco é marcado com `orfao` — a tela mostra isso.
     *
     * @return array<string, array<string, array{label:string, tom:string, orfao?:bool}>>
     */
    public static function vocabulario(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $txt = data_mock('admin-missoes');

        // Origens são regra de jogo: vêm de GameRules, nunca do arquivo de texto do painel.
        $origens = [];
        foreach (GameRules::ORIGINS as $slug => $o) {
            $origens[$slug] = ['label' => (string)$o['label'], 'tom' => self::tom((string)$o['color'])];
        }

        // Facção exigida: as duas facções do jogo mais o coringa "qualquer contrato".
        $faccoes = ['any' => ['label' => 'QUALQUER CONTRATO', 'tom' => 'muted']];
        foreach (GameRules::FACTIONS as $slug => $f) {
            $faccoes[$slug] = ['label' => (string)$f['label'], 'tom' => self::tom((string)$f['color'])];
        }

        // Faixas de reputação, na ordem crescente em que GameRules as declara.
        $faixas = [];
        foreach (GameRules::REP_TIERS as $t) {
            $faixas[(string)$t['label']] = [
                'label' => (string)$t['label'],
                'tom'   => 'muted',
                'nota'  => 'a partir de ' . num((int)$t['min']) . ' de reputação',
            ];
        }

        $cache = [
            'tipos'      => self::unir((array)($txt['tipos'] ?? []), 'type'),
            'origens'    => self::unir($origens, 'origin'),
            'dificuldades' => self::unir((array)($txt['dificuldades'] ?? []), 'difficulty'),
            'atividades' => self::unir((array)($txt['atividade_humana'] ?? []), 'human_activity'),
            'faccoes'    => self::unir($faccoes, 'requires_faction'),
            'faixas'     => self::unir($faixas, 'requires_tier'),
            'recompensas'=> (array)($txt['recompensas'] ?? []),
        ];
        return $cache;
    }

    /** Cor do GameRules -> tom dos primitivos do painel (.adm-badge--*). */
    private static function tom(string $cor): string
    {
        return match ($cor) {
            'lime'   => 'ok',
            'cyan'   => 'info',
            'amber'  => 'warn',
            'red'    => 'danger',
            'purple' => 'purple',
            'gold'   => 'gold',
            default  => 'muted',
        };
    }

    /**
     * Junta a lista canônica com o que a coluna realmente guarda hoje.
     *
     * @param array<string, array<string,mixed>> $canon
     * @return array<string, array<string,mixed>>
     */
    private static function unir(array $canon, string $coluna): array
    {
        try {
            $vistos = Database::all(
                "SELECT DISTINCT `$coluna` AS v FROM missions WHERE `$coluna` IS NOT NULL AND `$coluna` <> ''"
            );
        } catch (\Throwable) {
            return $canon;   // banco fora do ar: a lista canônica ainda serve para desenhar
        }
        foreach ($vistos as $r) {
            $v = (string)$r['v'];
            if (!isset($canon[$v])) {
                $canon[$v] = ['label' => mb_strtoupper($v), 'tom' => 'muted', 'orfao' => true];
            }
        }
        return $canon;
    }

    // ========================================================================== listagem

    /**
     * Uma página da lista, já com contadores.
     *
     * @param array{q?:string, tipo?:string, origem?:string, dificuldade?:string, estado?:string, ordem?:string, pagina?:int} $f
     * @return array{linhas:list<array<string,mixed>>, total:int, total_geral:int, pagina:int, paginas:int}
     */
    public static function listar(array $f): array
    {
        [$where, $bind] = self::filtro($f);
        $ordem = self::ORDENS[(string)($f['ordem'] ?? '')] ?? self::ORDENS['ordem'];

        $total = (int)Database::value("SELECT COUNT(*) FROM missions m WHERE $where", $bind);

        $paginas = max(1, (int)ceil($total / self::POR_PAGINA));
        $pagina  = max(1, min($paginas, (int)($f['pagina'] ?? 1)));
        $offset  = ($pagina - 1) * self::POR_PAGINA;

        // LIMIT/OFFSET entram como inteiros já saneados: o PDO roda sem emulação de
        // prepares e o MySQL não aceita placeholder nessa posição.
        $sql = 'SELECT m.*,
                       COALESCE(pm.ativas, 0)     AS ativas,
                       COALESCE(pm.jogadores, 0)  AS jogadores,
                       COALESCE(mr.n, 0)          AS recompensas
                  FROM missions m
                  LEFT JOIN (SELECT mission_id,
                                    SUM(status IN (' . self::emCampoSql() . ')) AS ativas,
                                    COUNT(*) AS jogadores
                               FROM player_missions GROUP BY mission_id) pm ON pm.mission_id = m.id
                  LEFT JOIN (SELECT mission_id, COUNT(*) AS n
                               FROM mission_rewards GROUP BY mission_id) mr ON mr.mission_id = m.id
                 WHERE ' . $where . '
                 ORDER BY ' . $ordem . '
                 LIMIT ' . self::POR_PAGINA . ' OFFSET ' . $offset;

        return [
            'linhas'      => Database::all($sql, $bind),
            'total'       => $total,
            'total_geral' => (int)Database::value('SELECT COUNT(*) FROM missions'),
            'pagina'      => $pagina,
            'paginas'     => $paginas,
        ];
    }

    /**
     * WHERE + binds de um conjunto de filtros. Sempre devolve uma condição verdadeira,
     * para as consultas poderem concatenar sem `if`.
     *
     * @param array<string,mixed> $f
     * @return array{0:string, 1:array<string,mixed>}
     */
    private static function filtro(array $f): array
    {
        $cond = ['1=1'];
        $bind = [];

        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            // Um placeholder POR COLUNA, e não `:q` repetido: o PDO roda sem emulação de
            // prepares (ver App\Core\Database) e o driver nativo do MySQL recusa o mesmo
            // nome usado mais de uma vez — a busca voltava 500 em vez de resultado.
            $alvos = ['m.name', 'm.slug', 'm.giver', 'm.region', 'm.objective'];
            $ors   = [];
            $termo = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            foreach ($alvos as $i => $coluna) {
                $ors[] = "$coluna LIKE :q$i";
                $bind['q' . $i] = $termo;
            }
            $cond[] = '(' . implode(' OR ', $ors) . ')';
        }
        foreach (['tipo' => 'type', 'origem' => 'origin', 'dificuldade' => 'difficulty'] as $chave => $coluna) {
            $v = trim((string)($f[$chave] ?? ''));
            if ($v !== '') {
                $cond[] = "m.`$coluna` = :$chave";
                $bind[$chave] = $v;
            }
        }
        switch ((string)($f['estado'] ?? '')) {
            case 'publicada':
                $cond[] = 'm.locked = 0';
                break;
            case 'bloqueada':
                $cond[] = 'm.locked = 1';
                break;
            case 'em_uso':
                $cond[] = 'EXISTS (SELECT 1 FROM player_missions p WHERE p.mission_id = m.id
                                     AND p.status IN (' . self::emCampoSql() . '))';
                break;
            case 'sem_recompensa':
                $cond[] = 'NOT EXISTS (SELECT 1 FROM mission_rewards r WHERE r.mission_id = m.id)';
                break;
        }
        return [implode(' AND ', $cond), $bind];
    }

    /** Lista de estados "em campo" pronta para entrar no SQL (literais fixos deste arquivo). */
    private static function emCampoSql(): string
    {
        return "'" . implode("','", self::EM_CAMPO) . "'";
    }

    /**
     * Os quatro números do topo da tela. Uma consulta só — são agregados da mesma tabela.
     *
     * @return array{total:int, publicadas:int, bloqueadas:int, em_campo:int, sem_recompensa:int, jogadores:int}
     */
    public static function resumo(): array
    {
        $r = Database::one(
            'SELECT COUNT(*) AS total,
                    SUM(m.locked = 0) AS publicadas,
                    SUM(m.locked = 1) AS bloqueadas,
                    SUM(EXISTS (SELECT 1 FROM mission_rewards r WHERE r.mission_id = m.id) = 0) AS sem_recompensa
               FROM missions m'
        ) ?? [];

        $campo = Database::one(
            'SELECT COUNT(DISTINCT mission_id) AS missoes, COUNT(*) AS jogadores
               FROM player_missions WHERE status IN (' . self::emCampoSql() . ')'
        ) ?? [];

        return [
            'total'          => (int)($r['total'] ?? 0),
            'publicadas'     => (int)($r['publicadas'] ?? 0),
            'bloqueadas'     => (int)($r['bloqueadas'] ?? 0),
            'sem_recompensa' => (int)($r['sem_recompensa'] ?? 0),
            'em_campo'       => (int)($campo['missoes'] ?? 0),
            'jogadores'      => (int)($campo['jogadores'] ?? 0),
        ];
    }

    /**
     * Resumo das recompensas de várias missões de uma vez (a lista mostra os selos).
     * Uma consulta para a página inteira — não uma por linha.
     *
     * @param list<int> $ids
     * @return array<int, list<array{kind:string, amount:int, item_id:?int, item:?string}>>
     */
    public static function recompensasDe(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        // Placeholders numerados: o conteúdo do IN é gerado por nós, os valores continuam bindados.
        $ph = [];
        $bind = [];
        foreach ($ids as $i => $id) {
            $ph[] = ':m' . $i;
            $bind['m' . $i] = $id;
        }
        $linhas = Database::all(
            'SELECT r.mission_id, r.id, r.kind, r.amount, r.item_id, i.name AS item
               FROM mission_rewards r
               LEFT JOIN items i ON i.id = r.item_id
              WHERE r.mission_id IN (' . implode(',', $ph) . ')
              ORDER BY r.id',
            $bind
        );
        $out = [];
        foreach ($linhas as $l) {
            $out[(int)$l['mission_id']][] = [
                'id'      => (int)$l['id'],
                'kind'    => (string)$l['kind'],
                'amount'  => (int)$l['amount'],
                'item_id' => $l['item_id'] !== null ? (int)$l['item_id'] : null,
                'item'    => $l['item'] !== null ? (string)$l['item'] : null,
            ];
        }
        return $out;
    }

    // ============================================================================ leitura

    /** @return array<string,mixed>|null */
    public static function porId(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return Database::one('SELECT * FROM missions WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /** O slug já pertence a OUTRA missão? (é UNIQUE no banco; a tela avisa antes do erro 500) */
    public static function slugEmUso(string $slug, int $exceto = 0): bool
    {
        return Database::one(
            'SELECT id FROM missions WHERE slug = :s AND id <> :e LIMIT 1',
            ['s' => $slug, 'e' => $exceto]
        ) !== null;
    }

    /**
     * Quantos jogadores estão com esta missão, e em que situação.
     * @return array{em_campo:int, total:int, por_status:array<string,int>}
     */
    public static function usoPorJogadores(int $id): array
    {
        $linhas = Database::all(
            'SELECT status, COUNT(*) AS n FROM player_missions WHERE mission_id = :m GROUP BY status',
            ['m' => $id]
        );
        $por = [];
        $emCampo = 0;
        $total = 0;
        foreach ($linhas as $l) {
            $st = (string)$l['status'];
            $n  = (int)$l['n'];
            $por[$st] = $n;
            $total += $n;
            if (in_array($st, self::EM_CAMPO, true)) {
                $emCampo += $n;
            }
        }
        return ['em_campo' => $emCampo, 'total' => $total, 'por_status' => $por];
    }

    /** Catálogo de itens para o select de recompensa. @return list<array{id:int,name:string,category:string}> */
    public static function itens(): array
    {
        return Database::all('SELECT id, name, category FROM items ORDER BY category, name');
    }

    /**
     * Missões prontas para virar PONTO NO MAPA, na paleta do editor.
     *
     * O editor não cria missão nem edita missão: ele fixa no terreno uma que já existe. Por
     * isso a consulta traz só o que identifica a missão na paleta e o que o marcador vai
     * herdar — nome, contratante, região e origem. O resto (objetivo, recompensas, requisitos)
     * continua vivendo na tela de Missões, que é onde se edita missão.
     *
     * @return list<array{id:int,name:string,giver:string,region:string,origin:string,type:string}>
     */
    public static function paraMapa(): array
    {
        try {
            $rows = Database::all(
                'SELECT id, name, giver, region, origin, type FROM missions ORDER BY act, sort_order, name'
            );
        } catch (\Throwable) {
            // Servidor sem as colunas do patch v3 (`origin`/`act`): a paleta ainda funciona,
            // só sem o selo de origem. Degradar é melhor do que derrubar o editor inteiro.
            $rows = Database::all('SELECT id, name, giver, region, type FROM missions ORDER BY name');
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'     => (int)$r['id'],
                'name'   => (string)$r['name'],
                'giver'  => (string)($r['giver'] ?? ''),
                'region' => (string)($r['region'] ?? ''),
                'origin' => (string)($r['origin'] ?? ''),
                'type'   => (string)($r['type'] ?? ''),
            ];
        }
        return $out;
    }

    /** Valores já usados em `region` e `giver`, para o datalist do formulário. @return array{regioes:list<string>, contratantes:list<string>} */
    public static function sugestoes(): array
    {
        $col = static fn(string $c): array => array_map(
            static fn(array $r): string => (string)$r['v'],
            Database::all("SELECT DISTINCT `$c` AS v FROM missions WHERE `$c` IS NOT NULL AND `$c` <> '' ORDER BY v")
        );
        return ['regioes' => $col('region'), 'contratantes' => $col('giver')];
    }

    // ============================================================================ escrita

    /**
     * Grava a missão e o conjunto INTEIRO de recompensas, numa transação.
     *
     * As recompensas são substituídas, não mescladas: o formulário mostra todas de uma vez,
     * então o que voltou dele É o conjunto. Mesclar deixaria órfã qualquer linha que o
     * operador removeu na tela.
     *
     * @param array<string,mixed> $dados  já validado pelo controller
     * @param list<array{kind:string, amount:int, item_id:?int}> $recompensas
     * @return int id da missão (novo ou o mesmo)
     */
    public static function salvar(array $dados, array $recompensas, ?int $id = null): int
    {
        $campos = array_intersect_key($dados, array_flip(self::CAMPOS));

        return (int)Database::transaction(static function () use ($campos, $recompensas, $id): int {
            if ($id !== null && $id > 0) {
                $sets = [];
                foreach (array_keys($campos) as $c) {
                    $sets[] = "`$c` = :$c";
                }
                Database::run(
                    'UPDATE missions SET ' . implode(', ', $sets) . ' WHERE id = :id',
                    $campos + ['id' => $id]
                );
                $missao = $id;
                Database::run('DELETE FROM mission_rewards WHERE mission_id = :m', ['m' => $missao]);
            } else {
                $missao = Database::insert('missions', $campos);
            }

            foreach ($recompensas as $r) {
                Database::insert('mission_rewards', [
                    'mission_id' => $missao,
                    'kind'       => $r['kind'],
                    'amount'     => $r['amount'],
                    'item_id'    => $r['item_id'],
                ]);
            }
            return $missao;
        });
    }

    /**
     * Apaga a missão. `mission_rewards` cai por CASCADE — é filha, não referência.
     *
     * NÃO checa `player_missions` aqui de propósito: quem decide isso é o controller, que é
     * quem tem como explicar o bloqueio ao operador. O repositório não inventa política.
     */
    public static function excluir(int $id): void
    {
        Database::run('DELETE FROM missions WHERE id = :id', ['id' => $id]);
    }
}
