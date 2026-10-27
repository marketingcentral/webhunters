<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * Catálogo de mapas visto pelo PAINEL (`maps` + `map_markers`).
 *
 * Existe separado de App\Repo\CatalogRepo de propósito: o CatalogRepo é a leitura do JOGO —
 * cacheia por requisição, devolve a linha já traduzida para o que a HUD espera e nunca
 * escreve. O painel precisa do oposto: linha CRUA (para o formulário mostrar exatamente o que
 * está gravado), contagem de marcadores, busca/ordenação/paginação e escrita. Misturar as
 * duas responsabilidades num repositório só faria o cache do jogo devolver dado velho logo
 * depois de um salvamento.
 *
 * Nada aqui valida: validação é decisão de tela e mora no MapsController, que precisa dizer
 * QUAL campo está errado. Aqui só existe SQL parametrizado.
 */
final class MapsRepo
{
    /** `maps.kind` — os dois únicos aceitos pelo jogo (região do mundo x mapa de partida). */
    public const TIPOS = ['region' => 'Região', 'match' => 'Partida'];

    /** Colunas que a listagem aceita ordenar. A chave vem da URL; o valor é SQL literal. */
    private const ORDENACOES = [
        'ordem'      => 'm.sort_order %s, m.name ASC',
        'nome'       => 'm.name %s',
        'tipo'       => 'm.kind %s, m.sort_order ASC',
        'nivel'      => 'm.level_min IS NULL, m.level_min %s, m.level_max %s',
        'marcadores' => 'marcadores %s, m.name ASC',
        'estado'     => 'm.locked %s, m.name ASC',
    ];

    /** Colunas de `maps` que o formulário do painel escreve. */
    private const CAMPOS = [
        'slug', 'name', 'kind', 'mode', 'players', 'level_min', 'level_max',
        'locked', 'image', 'description', 'unreal_level', 'meta', 'sort_order',
    ];

    /**
     * Página da listagem + total que casa com o filtro (para o rodapé e a paginação).
     *
     * A contagem de marcadores vem por subconsulta, e não por JOIN + GROUP BY, porque a tela
     * mostra mapa SEM marcador nenhum — que é justamente o caso que o operador precisa achar.
     *
     * @param array{busca?:string,tipo?:string,estado?:string,ordem?:string,dir?:string,pagina?:int,por_pagina?:int} $f
     * @return array{linhas:list<array<string,mixed>>,total:int,pagina:int,paginas:int,por_pagina:int}
     */
    public static function listar(array $f = []): array
    {
        $onde   = [];
        $params = [];

        $busca = trim((string)($f['busca'] ?? ''));
        if ($busca !== '') {
            // Busca no que o operador tem na mão: nome, slug, modo e o level da Unreal.
            //
            // Um placeholder POR COLUNA, e não `:q` repetido quatro vezes: o PDO roda sem
            // emulação (ATTR_EMULATE_PREPARES=false) e, no prepare nativo, cada ocorrência de
            // um nome vira um parâmetro posicional distinto — reusar `:q` devolve
            // "SQLSTATE[HY093] Invalid parameter number" e derruba a busca inteira.
            $termo = '%' . str_replace(['%', '_'], ['\%', '\_'], $busca) . '%';
            $colunas = ['m.name', 'm.slug', 'm.unreal_level', 'm.mode'];
            $ors = [];
            foreach ($colunas as $i => $coluna) {
                $ors[] = $coluna . ' LIKE :q' . $i;
                $params['q' . $i] = $termo;
            }
            $onde[] = '(' . implode(' OR ', $ors) . ')';
        }

        $tipo = (string)($f['tipo'] ?? '');
        if (isset(self::TIPOS[$tipo])) {
            $onde[] = 'm.kind = :kind';
            $params['kind'] = $tipo;
        }

        $estado = (string)($f['estado'] ?? '');
        if ($estado === 'aberto' || $estado === 'bloqueado') {
            $onde[] = 'm.locked = :locked';
            $params['locked'] = $estado === 'bloqueado' ? 1 : 0;
        }
        if ($estado === 'sem-ue') {
            $onde[] = "(m.unreal_level IS NULL OR m.unreal_level = '')";
        }

        $sqlOnde = $onde === [] ? '' : ' WHERE ' . implode(' AND ', $onde);

        $total = (int)Database::value('SELECT COUNT(*) FROM maps m' . $sqlOnde, $params);

        $porPagina = max(5, min(100, (int)($f['por_pagina'] ?? 20)));
        $paginas   = max(1, (int)ceil($total / $porPagina));
        $pagina    = max(1, min($paginas, (int)($f['pagina'] ?? 1)));
        $offset    = ($pagina - 1) * $porPagina;

        $chave = (string)($f['ordem'] ?? 'ordem');
        $molde = self::ORDENACOES[$chave] ?? self::ORDENACOES['ordem'];
        $dir   = strtolower((string)($f['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        // LIMIT/OFFSET e ORDER BY entram como literal porque o PDO roda sem emulação de
        // prepared statement: parâmetro ligado vira string e `LIMIT '20'` é erro de sintaxe.
        // O risco não existe porque nada aqui vem do usuário — só chave de whitelist e int.
        $ordem = sprintf($molde, $dir, $dir);

        $linhas = Database::all(
            'SELECT m.*, (SELECT COUNT(*) FROM map_markers k WHERE k.map_id = m.id) AS marcadores
             FROM maps m' . $sqlOnde . '
             ORDER BY ' . $ordem . '
             LIMIT ' . $porPagina . ' OFFSET ' . $offset,
            $params
        );

        foreach ($linhas as &$l) {
            $l['meta_arr'] = self::decodificar($l['meta'] ?? null);
        }
        unset($l);

        return [
            'linhas'     => $linhas,
            'total'      => $total,
            'pagina'     => $pagina,
            'paginas'    => $paginas,
            'por_pagina' => $porPagina,
        ];
    }

    /** Totais do catálogo inteiro — não do filtro. É o que a topbar mostra. */
    public static function resumo(): array
    {
        $r = Database::one(
            "SELECT COUNT(*) AS total,
                    SUM(kind = 'region') AS regioes,
                    SUM(kind = 'match')  AS partidas,
                    SUM(locked = 1)      AS bloqueados,
                    SUM(unreal_level IS NULL OR unreal_level = '') AS sem_ue
             FROM maps"
        ) ?? [];

        return [
            'total'      => (int)($r['total'] ?? 0),
            'regioes'    => (int)($r['regioes'] ?? 0),
            'partidas'   => (int)($r['partidas'] ?? 0),
            'bloqueados' => (int)($r['bloqueados'] ?? 0),
            'sem_ue'     => (int)($r['sem_ue'] ?? 0),
            'marcadores' => (int)Database::value('SELECT COUNT(*) FROM map_markers'),
        ];
    }

    /** @return array<string,mixed>|null linha crua + `meta_arr` decodificado */
    public static function porId(int $id): ?array
    {
        $row = Database::one('SELECT * FROM maps WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            return null;
        }
        $row['meta_arr']   = self::decodificar($row['meta'] ?? null);
        $row['marcadores'] = (int)Database::value('SELECT COUNT(*) FROM map_markers WHERE map_id = :m', ['m' => $id]);
        return $row;
    }

    /** Slug já em uso por OUTRO mapa (o próprio não conta, senão ninguém salva sem renomear). */
    public static function slugEmUso(string $slug, int $exceto = 0): bool
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM maps WHERE slug = :s AND id <> :id',
            ['s' => $slug, 'id' => $exceto]
        ) > 0;
    }

    /** @param array<string,mixed> $d @return int id novo */
    public static function criar(array $d): int
    {
        return Database::insert('maps', self::somenteCampos($d));
    }

    /** @param array<string,mixed> $d */
    public static function atualizar(int $id, array $d): void
    {
        $d = self::somenteCampos($d);
        if ($d === []) {
            return;
        }
        $sets = [];
        foreach (array_keys($d) as $c) {
            $sets[] = '`' . $c . '` = :' . $c;
        }
        $d['id'] = $id;
        Database::run('UPDATE maps SET ' . implode(', ', $sets) . ' WHERE id = :id', $d);
    }

    /** Apaga o mapa. Os marcadores caem junto pela FK (ON DELETE CASCADE). */
    public static function excluir(int $id): void
    {
        Database::run('DELETE FROM maps WHERE id = :id', ['id' => $id]);
    }

    /**
     * O que impede este mapa de ser apagado, em número.
     *
     * Marcador NÃO entra aqui: a FK apaga em cascata e isso é o comportamento certo — um
     * marcador não existe fora do seu mapa. O que trava são as referências de FORA, que
     * perderiam sentido: bases governamentais fincadas nele, partidas já jogadas (histórico
     * do jogador) e safe zones do patch v4.
     *
     * @return array<string,int> rótulo interno => quantidade (só o que for > 0)
     */
    public static function vinculos(int $id): array
    {
        $checagens = [
            'bases'    => 'SELECT COUNT(*) FROM government_bases WHERE map_id = :id',
            'partidas' => 'SELECT COUNT(*) FROM player_matches WHERE map_id = :id',
            'safe'     => 'SELECT COUNT(*) FROM safe_zones WHERE map_id = :id',
        ];

        $out = [];
        foreach ($checagens as $chave => $sql) {
            try {
                $n = (int)Database::value($sql, ['id' => $id]);
            } catch (\Throwable) {
                // Tabela de patch ainda não aplicado neste banco: ausência não é vínculo.
                continue;
            }
            if ($n > 0) {
                $out[$chave] = $n;
            }
        }
        return $out;
    }

    /**
     * Lista enxuta para o SELETOR do editor de mapa: só o necessário para escolher em qual
     * mapa se vai trabalhar, e quanta coisa já existe nele.
     *
     * Separado de `listar()` porque aquele é o motor da LISTAGEM — paginação, ordenação,
     * filtros, contagem total. Reaproveitá-lo aqui obrigaria o editor a pedir a página 1 de
     * um recorte que ele não quer, e a torcer para que os 20 primeiros incluíssem o mapa
     * procurado.
     *
     * @return list<array{id:int,slug:string,name:string,kind:string,marcadores:int,world_image:string}>
     */
    public static function paraEditor(): array
    {
        $rows = Database::all(
            'SELECT m.id, m.slug, m.name, m.kind, m.meta,'
            . ' (SELECT COUNT(*) FROM map_markers k WHERE k.map_id = m.id) AS marcadores'
            . ' FROM maps m ORDER BY m.kind DESC, m.sort_order, m.name'
        );
        $out = [];
        foreach ($rows as $r) {
            $meta = self::decodificar($r['meta'] ?? null);
            $out[] = [
                'id'          => (int)$r['id'],
                'slug'        => (string)$r['slug'],
                'name'        => (string)$r['name'],
                'kind'        => (string)$r['kind'],
                'marcadores'  => (int)$r['marcadores'],
                'world_image' => (string)($meta['world_image'] ?? ''),
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------------ marcadores

    /** @return list<array<string,mixed>> marcadores do mapa, com `meta_arr` decodificado */
    public static function marcadores(int $mapId): array
    {
        $rows = Database::all(
            'SELECT * FROM map_markers WHERE map_id = :m ORDER BY kind, label, id',
            ['m' => $mapId]
        );
        foreach ($rows as &$r) {
            $r['meta_arr'] = self::decodificar($r['meta'] ?? null);
            $r['x'] = (float)$r['x'];
            $r['y'] = (float)$r['y'];
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function marcador(int $id): ?array
    {
        $row = Database::one('SELECT * FROM map_markers WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            return null;
        }
        $row['meta_arr'] = self::decodificar($row['meta'] ?? null);
        return $row;
    }

    /** @param array<string,mixed> $d */
    public static function criarMarcador(int $mapId, array $d): int
    {
        return Database::insert('map_markers', [
            'map_id' => $mapId,
            'kind'   => $d['kind'],
            'label'  => $d['label'],
            'x'      => $d['x'],
            'y'      => $d['y'],
            'meta'   => $d['meta'],
        ]);
    }

    /** @param array<string,mixed> $d */
    public static function atualizarMarcador(int $id, array $d): void
    {
        Database::run(
            'UPDATE map_markers SET kind = :kind, label = :label, x = :x, y = :y, meta = :meta WHERE id = :id',
            ['kind' => $d['kind'], 'label' => $d['label'], 'x' => $d['x'], 'y' => $d['y'], 'meta' => $d['meta'], 'id' => $id]
        );
    }

    public static function excluirMarcador(int $id): void
    {
        Database::run('DELETE FROM map_markers WHERE id = :id', ['id' => $id]);
    }

    /**
     * Tipos de marcador que EXISTEM no banco. O formulário mostra o catálogo canônico
     * (app/Data/admin-mapas.php) unido a isto: um tipo criado por patch ou por outra
     * ferramenta não pode sumir do <select> só porque o painel não o conhecia.
     * @return list<string>
     */
    public static function tiposDeMarcadorEmUso(): array
    {
        return array_map(
            static fn(array $r): string => (string)$r['kind'],
            Database::all('SELECT DISTINCT kind FROM map_markers ORDER BY kind')
        );
    }

    // ------------------------------------------------------------------------ internos

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private static function somenteCampos(array $d): array
    {
        return array_intersect_key($d, array_flip(self::CAMPOS));
    }

    /** @return array<string,mixed> JSON inválido ou nulo vira array vazio, nunca erro de tela. */
    private static function decodificar(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        $v = json_decode((string)$json, true);
        return is_array($v) ? $v : [];
    }
}
