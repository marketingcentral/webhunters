<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * Catálogo de PONTOS PRÉ-FABRICADOS do mapa (`map_point_types`).
 *
 * É a ponte entre o painel e o jogo. O operador cadastra "Café", "Shopping", "Posto de
 * gasolina" uma vez; o editor de mapa arrasta esse ícone para onde quiser, e cada marcador
 * guarda só o SLUG do tipo (`map_markers.meta.point_type`). Assim trocar o ícone do café
 * conserta todos os cafés do mundo de uma vez, em vez de exigir reedição marcador a marcador.
 *
 * DUAS REGRAS QUE EXPLICAM O RESTO:
 *
 * 1. O tipo não substitui `map_markers.kind`. `kind` continua sendo o vocabulário que o JOGO
 *    entende (camada do filtro, comportamento de extração bloqueada, pulso de base sob ataque).
 *    O tipo diz qual DESENHO e qual COR, e qual kind gravar quando o operador arrasta. Um
 *    marcador que perca o tipo continua funcionando: cai no comportamento do kind.
 *
 * 2. `is_system` marca os tipos que nascem com o jogo (extração, base Hunter, ninho Veyra…).
 *    O painel edita rótulo e ícone deles, mas não apaga: apagar "extração" não removeria um
 *    ícone, removeria uma regra de jogo — e deixaria marcadores órfãos no mapa.
 *
 * O catálogo é pequeno (dezenas de linhas) e lido em toda montagem do mapa, então
 * `porSlug()` guarda o resultado na requisição. Nada de cache entre requisições: o operador
 * precisa ver a mudança no primeiro F5.
 */
final class MapPointsRepo
{
    /** Categorias aceitas. A chave vai para o banco; o valor é o que o painel escreve na tela. */
    public const CATEGORIAS = [
        'operacional' => 'Operacional',
        'hostil'      => 'Hostil / Veyra',
        'logistica'   => 'Logística',
        'combustivel' => 'Combustível',
        'alimentacao' => 'Alimentação',
        'comercio'    => 'Comércio',
        'saude'       => 'Saúde',
        'outros'      => 'Outros',
    ];

    /** Origens aceitas no campo `icon`, no formato `origem:valor`. */
    public const ORIGENS_ICONE = ['glyph', 'symbol', 'image'];

    /** @var list<array<string,mixed>>|null memória da requisição */
    private static ?array $cache = null;

    /**
     * Catálogo inteiro, já ordenado por categoria e posição.
     * @return list<array<string,mixed>>
     */
    public static function todos(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        try {
            $linhas = Database::all(
                'SELECT id, slug, label, category, kind, icon, color, layer, show_label, description, is_system, sort_order'
                . ' FROM map_point_types ORDER BY sort_order, label'
            );
        } catch (\Throwable) {
            // Servidor sem o patch v9: o mapa continua desenhando pelo `kind`, sem catálogo.
            $linhas = [];
        }
        foreach ($linhas as &$l) {
            $l['show_label'] = (bool)$l['show_label'];
            $l['is_system']  = (bool)$l['is_system'];
        }
        unset($l);
        return self::$cache = $linhas;
    }

    /**
     * Catálogo indexado pelo slug — a forma que o mapa consulta marcador a marcador.
     * @return array<string, array<string,mixed>>
     */
    public static function porSlug(): array
    {
        $out = [];
        foreach (self::todos() as $t) {
            $out[(string)$t['slug']] = $t;
        }
        return $out;
    }

    /**
     * Catálogo agrupado por categoria, no formato que a paleta do editor desenha.
     * Categoria sem nenhum tipo não aparece — paleta com gaveta vazia é ruído.
     * @return list<array{key:string,label:string,items:list<array<string,mixed>>}>
     */
    public static function porCategoria(): array
    {
        $balde = [];
        foreach (self::todos() as $t) {
            $balde[(string)$t['category']][] = $t;
        }
        $out = [];
        foreach (self::CATEGORIAS as $chave => $rotulo) {
            if (empty($balde[$chave])) { continue; }
            $out[] = ['key' => $chave, 'label' => $rotulo, 'items' => $balde[$chave]];
            unset($balde[$chave]);
        }
        // Categoria gravada por fora da lista fechada ainda assim aparece, com o nome cru:
        // esconder seria perder o ponto no primeiro salvamento do editor.
        foreach ($balde as $chave => $itens) {
            $out[] = ['key' => (string)$chave, 'label' => (string)$chave, 'items' => $itens];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function porId(int $id): ?array
    {
        foreach (self::todos() as $t) {
            if ((int)$t['id'] === $id) { return $t; }
        }
        return null;
    }

    /** Slug já usado por OUTRO tipo (o próprio não conta, senão ninguém salva sem renomear). */
    public static function slugEmUso(string $slug, int $exceto = 0): bool
    {
        foreach (self::todos() as $t) {
            if ((string)$t['slug'] === $slug && (int)$t['id'] !== $exceto) { return true; }
        }
        return false;
    }

    /**
     * Quantos marcadores do mundo usam cada tipo. É o que impede o painel de apagar um tipo
     * em uso sem avisar quantos pontos do mapa ficariam sem desenho próprio.
     * @return array<string,int> slug => total
     */
    public static function usos(): array
    {
        try {
            $linhas = Database::all(
                "SELECT JSON_UNQUOTE(JSON_EXTRACT(meta, '$.point_type')) AS slug, COUNT(*) AS total"
                . ' FROM map_markers WHERE meta IS NOT NULL GROUP BY slug'
            );
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($linhas as $l) {
            $s = (string)($l['slug'] ?? '');
            if ($s !== '' && $s !== 'null') { $out[$s] = (int)$l['total']; }
        }
        return $out;
    }

    /** @param array<string,mixed> $d @return int id novo */
    public static function criar(array $d): int
    {
        self::$cache = null;
        // `is_system` é fixado em 0 aqui, e não vem de $d: ponto criado pelo painel nunca
        // nasce sendo do jogo — quem é do jogo entra por patch.
        return Database::insert('map_point_types', [
            'slug'        => $d['slug'],
            'label'       => $d['label'],
            'category'    => $d['category'],
            'kind'        => $d['kind'],
            'icon'        => $d['icon'],
            'color'       => $d['color'],
            'layer'       => $d['layer'],
            'show_label'  => $d['show_label'],
            'description' => $d['description'],
            'is_system'   => 0,
            'sort_order'  => $d['sort_order'],
        ]);
    }

    /** @param array<string,mixed> $d */
    public static function atualizar(int $id, array $d): void
    {
        self::$cache = null;
        $d['id'] = $id;
        Database::run(
            'UPDATE map_point_types SET slug=:slug, label=:label, category=:category, kind=:kind, icon=:icon,'
            . ' color=:color, layer=:layer, show_label=:show_label, description=:description, sort_order=:sort_order'
            . ' WHERE id = :id',
            $d
        );
    }

    /**
     * Apaga o tipo. Os marcadores que o usavam NÃO são apagados junto — eles voltam a ser
     * desenhados pelo `kind`, que é exatamente o que um mapa sem catálogo sempre fez.
     */
    public static function excluir(int $id): void
    {
        self::$cache = null;
        Database::run('DELETE FROM map_point_types WHERE id = :id AND is_system = 0', ['id' => $id]);
    }

    /**
     * Quebra `icon` em origem e valor. Entrada inválida vira o símbolo genérico, nunca erro
     * de tela: um ícone quebrado não pode derrubar o mapa inteiro.
     * @return array{0:string,1:string} [origem, valor]
     */
    public static function icone(string $icon): array
    {
        $p = explode(':', $icon, 2);
        if (count($p) !== 2 || !in_array($p[0], self::ORIGENS_ICONE, true) || $p[1] === '') {
            return ['symbol', 'place'];
        }
        return [$p[0], $p[1]];
    }
}
