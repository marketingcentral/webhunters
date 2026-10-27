<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * Infraestrutura declarada do jogo: `server_regions`, `server_services` e `maintenance_windows`.
 *
 * Todo o SQL da tela /admin/servidores mora aqui — o controller só decide o que pedir e a view
 * só desenha. É o mesmo contrato do AdminRepo, e existe pelo mesmo motivo: quando um número da
 * tela estiver errado, dá para reproduzir a consulta fora do navegador.
 *
 * ATENÇÃO AO ALCANCE DA ESCRITA: estas três tabelas NÃO alimentam só o painel. Elas são lidas
 * por App\Repo\SupportRepo (painel "Status dos servidores" da tela /suporte do jogo) e por
 * App\Repo\MessageRepo (aviso de manutenção na caixa de mensagens do jogador). Mudar o estado
 * de um serviço aqui muda o que TODO jogador vê — por isso cada gravação é auditada.
 *
 * O vínculo entre janela e região é por RÓTULO (`maintenance_windows.region` guarda o texto de
 * `server_regions.label`), e não por id — foi assim que o esquema nasceu, e é o que
 * AdminRepo::statusServidores() usa para pintar a região de "em manutenção". Duas consequências
 * que esta classe trata explicitamente:
 *   1. renomear uma região órfã as janelas dela  -> renomearRegiaoNasJanelas() acompanha o rótulo;
 *   2. apagar uma região deixaria janelas apontando para o nada -> janelasDaRegiao() bloqueia.
 */
final class ServersRepo
{
    /** Estados gravados na coluna `state` das duas tabelas de infraestrutura. */
    public const ESTADOS = [
        'ok'   => 'Operacional',
        'warn' => 'Instável',
        'down' => 'Fora do ar',
    ];

    /** Situações DERIVADAS de uma janela — não existem no banco, saem da comparação com agora. */
    public const SITUACOES = [
        'ativa'     => 'Em andamento',
        'agendada'  => 'Agendada',
        'encerrada' => 'Encerrada',
    ];

    /** Janelas por página. A lista cresce para sempre (manutenção é recorrente). */
    public const POR_PAGINA = 8;

    /**
     * Colunas pelas quais a lista de regiões pode ser ordenada.
     *
     * É uma lista fechada porque o valor vem da URL e entra CONCATENADO no ORDER BY — não há
     * placeholder de PDO para nome de coluna. Fora desta lista, cai no padrão.
     */
    private const ORDEM_REGIOES = [
        'ordem'     => 'sort_order, id',
        'regiao'    => 'label',
        'ping'      => 'ping_ms',
        'jogadores' => 'players',
        'estado'    => "FIELD(state,'down','warn','ok'), label",
    ];

    /** Mesma ideia para os serviços. */
    private const ORDEM_SERVICOS = [
        'ordem'   => 'sort_order, id',
        'servico' => 'label',
        'estado'  => "FIELD(state,'down','warn','ok'), label",
        'janela'  => 'maint_starts_at IS NULL, maint_starts_at',
    ];

    /* =====================================================================================
       REGIÕES
       ===================================================================================== */

    /**
     * Regiões já com o estado EFETIVO — o mesmo que o dashboard mostra.
     *
     * O estado efetivo não é a coluna `state`: uma região com janela de manutenção ativa está
     * em manutenção mesmo com `state = 'ok'` gravado. A regra é copiada de
     * AdminRepo::statusServidores() de propósito — se as duas telas divergissem, o operador
     * veria "Online" aqui e "Em manutenção" no painel de visão geral, sobre a mesma linha.
     *
     * @param array{q?:string, estado?:string, ord?:string, dir?:string} $filtro
     * @return list<array<string,mixed>>
     */
    public static function regioes(array $filtro = []): array
    {
        $q      = trim((string)($filtro['q'] ?? ''));
        $estado = (string)($filtro['estado'] ?? '');
        $ordem  = self::ORDEM_REGIOES[(string)($filtro['ord'] ?? '')] ?? self::ORDEM_REGIOES['ordem'];
        $dir    = strtolower((string)($filtro['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        $onde   = [];
        $params = [];
        if ($q !== '') {
            // Um placeholder POR OCORRÊNCIA: o PDO deste projeto roda sem emulação, e o driver
            // nativo do MySQL recusa o mesmo nome usado duas vezes na mesma consulta.
            $onde[] = '(label LIKE :q1 OR slug LIKE :q2)';
            $params['q1'] = $params['q2'] = '%' . self::escLike($q) . '%';
        }
        if (isset(self::ESTADOS[$estado])) {
            $onde[] = 'state = :st';
            $params['st'] = $estado;
        }

        $sql = 'SELECT id, slug, label, ping_ms, players, state, sort_order FROM server_regions'
             . ($onde === [] ? '' : ' WHERE ' . implode(' AND ', $onde))
             . ' ORDER BY ' . $ordem . ' ' . $dir;

        $linhas  = Database::all($sql, $params);
        $janelas = self::janelasPorRegiao();
        $pico    = 0;
        foreach ($linhas as $r) {
            $pico = max($pico, (int)$r['players']);
        }

        $saida = [];
        foreach ($linhas as $r) {
            $label = (string)$r['label'];
            $manut = $janelas[$label] ?? null;

            [$efetivo, $rotulo, $nota] = match (true) {
                $manut !== null && $manut['ativa'] => ['manutencao', 'Em manutenção', 'Janela ativa até ' . date('d/m H:i', $manut['quando'])],
                (string)$r['state'] === 'down'     => ['fora',       'Fora do ar',    null],
                (string)$r['state'] === 'warn'     => ['instavel',   'Instável',      null],
                $manut !== null                    => ['agendada',   'Manut. agendada', 'Janela em ' . date('d/m H:i', $manut['quando'])],
                default                            => ['online',     'Online',        null],
            };

            $saida[] = [
                'id'             => (int)$r['id'],
                'slug'           => (string)$r['slug'],
                'label'          => $label,
                'ping'           => (int)$r['ping_ms'],
                'jogadores'      => (int)$r['players'],
                'state'          => (string)$r['state'],
                'ordem'          => (int)$r['sort_order'],
                'estado'         => $efetivo,
                'estado_rotulo'  => $rotulo,
                'nota'           => $nota,
                'ocupacao'       => $pico > 0 ? (int)$r['players'] / $pico : 0.0,
                'janelas'        => $manut['n'] ?? 0,
            ];
        }
        return $saida;
    }

    /** @return array<string,mixed>|null Linha crua da região (o formulário edita o que está gravado). */
    public static function regiao(int $id): ?array
    {
        return $id > 0
            ? Database::one('SELECT * FROM server_regions WHERE id = :id LIMIT 1', ['id' => $id])
            : null;
    }

    /** @return list<string> Rótulos existentes — é a lista do seletor de região da janela. */
    public static function rotulosRegioes(): array
    {
        return array_map(
            static fn(array $r): string => (string)$r['label'],
            Database::all('SELECT label FROM server_regions ORDER BY sort_order, id')
        );
    }

    /* =====================================================================================
       SERVIÇOS
       ===================================================================================== */

    /**
     * @param array{q?:string, estado?:string, ord?:string, dir?:string} $filtro
     * @return list<array<string,mixed>>
     */
    public static function servicos(array $filtro = []): array
    {
        $q      = trim((string)($filtro['q'] ?? ''));
        $estado = (string)($filtro['estado'] ?? '');
        $ordem  = self::ORDEM_SERVICOS[(string)($filtro['ord'] ?? '')] ?? self::ORDEM_SERVICOS['ordem'];
        $dir    = strtolower((string)($filtro['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        $onde   = [];
        $params = [];
        if ($q !== '') {
            $onde[] = '(label LIKE :q1 OR slug LIKE :q2 OR maint_scope LIKE :q3)';
            $params['q1'] = $params['q2'] = $params['q3'] = '%' . self::escLike($q) . '%';
        }
        if (isset(self::ESTADOS[$estado])) {
            $onde[] = 'state = :st';
            $params['st'] = $estado;
        }

        $sql = 'SELECT id, slug, label, state, status_label, short_label, maint_scope,
                       maint_starts_at, maint_minutes, sort_order
                  FROM server_services'
             . ($onde === [] ? '' : ' WHERE ' . implode(' AND ', $onde))
             . ' ORDER BY ' . $ordem . ' ' . $dir;

        $agora = time();
        $saida = [];
        foreach (Database::all($sql, $params) as $r) {
            $inicio = $r['maint_starts_at'] !== null ? (int)strtotime((string)$r['maint_starts_at']) : 0;
            $min    = (int)($r['maint_minutes'] ?? 0);

            $saida[] = [
                'id'           => (int)$r['id'],
                'slug'         => (string)$r['slug'],
                'label'        => (string)$r['label'],
                'state'        => (string)$r['state'],
                'estado'       => (string)$r['state'] === 'ok' ? 'online' : ((string)$r['state'] === 'down' ? 'fora' : 'manutencao'),
                'rotulo'       => (string)$r['status_label'],
                'curto'        => (string)$r['short_label'],
                'escopo'       => (string)($r['maint_scope'] ?? '') ?: null,
                'inicio'       => $inicio,
                'minutos'      => $min > 0 ? $min : null,
                'ordem'        => (int)$r['sort_order'],
                // Só o serviço FORA do estado ok e com horário marcado vira contagem regressiva
                // na tela do jogador (SupportRepo::maintenance) — a coluna da tabela repete
                // exatamente essa condição para o operador saber qual linha está no ar.
                'no_contador'  => (string)$r['state'] !== 'ok' && $inicio > 0,
                'faltam'       => $inicio > 0 ? $inicio - $agora : null,
            ];
        }
        return $saida;
    }

    /** @return array<string,mixed>|null */
    public static function servico(int $id): ?array
    {
        return $id > 0
            ? Database::one('SELECT * FROM server_services WHERE id = :id LIMIT 1', ['id' => $id])
            : null;
    }

    /* =====================================================================================
       JANELAS DE MANUTENÇÃO
       ===================================================================================== */

    /**
     * Página da lista de janelas. Diferente das outras duas listas, esta é paginada: manutenção
     * é recorrente e o histórico só cresce — em um ano de operação são dezenas de linhas.
     *
     * @param array{q?:string, situacao?:string, pagina?:int} $filtro
     * @return array{linhas:list<array<string,mixed>>, total:int, pagina:int, paginas:int, de:int, ate:int}
     */
    public static function janelas(array $filtro = []): array
    {
        $q        = trim((string)($filtro['q'] ?? ''));
        $situacao = (string)($filtro['situacao'] ?? '');
        $pagina   = max(1, (int)($filtro['pagina'] ?? 1));

        $onde   = [];
        $params = [];
        if ($q !== '') {
            $onde[] = '(region LIKE :q1 OR note LIKE :q2)';
            $params['q1'] = $params['q2'] = '%' . self::escLike($q) . '%';
        }
        // A situação é derivada do relógio, então o filtro vira comparação com NOW() — não há
        // coluna para filtrar. Fazer isso no SQL (e não em PHP) é o que mantém a paginação
        // honesta: o total precisa contar as linhas do filtro, não da página.
        $onde[] = match ($situacao) {
            'ativa'     => 'starts_at <= NOW() AND ends_at >= NOW()',
            'agendada'  => 'starts_at > NOW()',
            'encerrada' => 'ends_at < NOW()',
            default     => '1 = 1',
        };

        $sqlOnde = ' WHERE ' . implode(' AND ', $onde);
        $total   = (int)Database::value('SELECT COUNT(*) FROM maintenance_windows' . $sqlOnde, $params);
        $paginas = max(1, (int)ceil($total / self::POR_PAGINA));
        $pagina  = min($pagina, $paginas);
        $offset  = ($pagina - 1) * self::POR_PAGINA;

        // LIMIT/OFFSET entram como literais porque o PDO deste projeto roda sem emulação e o
        // MySQL recusa placeholder ali. Ambos são int calculados acima — nunca texto da URL.
        $linhas = Database::all(
            'SELECT id, starts_at, ends_at, region, note, news_slug
               FROM maintenance_windows' . $sqlOnde . '
              ORDER BY starts_at DESC
              LIMIT ' . self::POR_PAGINA . ' OFFSET ' . $offset,
            $params
        );

        $agora  = time();
        $saida  = [];
        foreach ($linhas as $r) {
            $ini = (int)strtotime((string)$r['starts_at']);
            $fim = (int)strtotime((string)$r['ends_at']);
            $saida[] = [
                'id'        => (int)$r['id'],
                'inicio'    => $ini,
                'fim'       => $fim,
                'minutos'   => (int)round(max(0, $fim - $ini) / 60),
                'regiao'    => (string)$r['region'],
                'nota'      => (string)($r['note'] ?? '') ?: null,
                'noticia'   => (string)($r['news_slug'] ?? '') ?: null,
                'situacao'  => $ini <= $agora && $agora <= $fim ? 'ativa' : ($ini > $agora ? 'agendada' : 'encerrada'),
                // Região que não existe mais em server_regions: a janela continua no banco e
                // continua sendo lida pela caixa de mensagens do jogo, mas não pinta região
                // nenhuma. É um defeito silencioso — a tabela precisa gritar.
                'orfa'      => false,
            ];
        }

        $conhecidas = self::rotulosRegioes();
        foreach ($saida as $i => $j) {
            $saida[$i]['orfa'] = !in_array($j['regiao'], $conhecidas, true);
        }

        return [
            'linhas'  => $saida,
            'total'   => $total,
            'pagina'  => $pagina,
            'paginas' => $paginas,
            'de'      => $total === 0 ? 0 : $offset + 1,
            'ate'     => min($total, $offset + self::POR_PAGINA),
        ];
    }

    /** @return array<string,mixed>|null */
    public static function janela(int $id): ?array
    {
        return $id > 0
            ? Database::one('SELECT * FROM maintenance_windows WHERE id = :id LIMIT 1', ['id' => $id])
            : null;
    }

    /**
     * Janela do mesmo período e da mesma região. Duas janelas sobrepostas na mesma região não
     * significam nada: quem lê (dashboard e caixa de mensagens) só considera uma.
     *
     * @return array<string,mixed>|null A janela conflitante, quando existir.
     */
    public static function janelaSobreposta(string $regiao, string $inicio, string $fim, int $exceto = 0): ?array
    {
        return Database::one(
            'SELECT id, starts_at, ends_at FROM maintenance_windows
              WHERE region = :r AND id <> :ex AND starts_at < :fim AND ends_at > :ini
              ORDER BY starts_at LIMIT 1',
            ['r' => $regiao, 'ex' => $exceto, 'ini' => $inicio, 'fim' => $fim]
        );
    }

    /** Quantas janelas apontam para este rótulo de região — é o bloqueio da exclusão. */
    public static function janelasDaRegiao(string $label): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM maintenance_windows WHERE region = :r',
            ['r' => $label]
        );
    }

    /**
     * Estado do RODAPÉ da HUD (região, ping, situação e barras de sinal).
     *
     * Existe para o rodapé parar de mentir. Ele nasceu lendo app/Data/server.php — um
     * arquivo fixo que dizia "AMÉRICA DO SUL · 32 ms · online" mesmo com a região marcada
     * como "Fora do ar" em /admin/servidores. Agora a primeira região da ordem (a principal)
     * manda no rodapé, e o painel governa o que o jogador lê ali.
     *
     * Devolve [] quando não há região cadastrada ou o banco não responde: quem chama mantém
     * o cromo estático e a HUD segue desenhando.
     *
     * @return array{region:string,ping_ms:int,status:string,bars:int}|array{}
     */
    public static function rodape(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $r = Database::one(
                'SELECT label, ping_ms, state FROM server_regions ORDER BY sort_order, id LIMIT 1'
            );
        } catch (\Throwable) {
            return $cache = [];
        }
        if ($r === null) {
            return $cache = [];
        }

        $estado = (string)$r['state'];
        return $cache = [
            'region'  => (string)$r['label'],
            'ping_ms' => (int)$r['ping_ms'],
            // O rodapé só sabe desenhar online/offline; 'warn' conta como no ar, mas com
            // uma barra a menos de sinal — é o que o desenho tem para dizer "instável".
            'status'  => $estado === 'down' ? 'offline' : 'online',
            'bars'    => match ($estado) { 'down' => 0, 'warn' => 2, default => 4 },
        ];
    }

    /** @return list<array{slug:string,title:string}> Notícias, para o seletor de `news_slug`. */
    public static function noticias(): array
    {
        $out = [];
        foreach (Database::all('SELECT slug, title FROM news ORDER BY sort_order, id') as $r) {
            $out[] = ['slug' => (string)$r['slug'], 'title' => (string)$r['title']];
        }
        return $out;
    }

    /* =====================================================================================
       ESCRITA
       ===================================================================================== */

    /**
     * Grava uma região (id = 0 cria). Devolve o id.
     * @param array<string,mixed> $d
     */
    public static function salvarRegiao(array $d, int $id = 0): int
    {
        $campos = [
            'slug'       => $d['slug'],
            'label'      => $d['label'],
            'ping_ms'    => $d['ping_ms'],
            'players'    => $d['players'],
            'state'      => $d['state'],
            'sort_order' => $d['sort_order'],
        ];
        if ($id <= 0) {
            return Database::insert('server_regions', $campos);
        }
        Database::run(
            'UPDATE server_regions
                SET slug = :slug, label = :label, ping_ms = :ping_ms, players = :players,
                    state = :state, sort_order = :sort_order
              WHERE id = :id',
            $campos + ['id' => $id]
        );
        return $id;
    }

    /**
     * Grava um serviço (id = 0 cria).
     * @param array<string,mixed> $d
     */
    public static function salvarServico(array $d, int $id = 0): int
    {
        $campos = [
            'slug'            => $d['slug'],
            'label'           => $d['label'],
            'state'           => $d['state'],
            'status_label'    => $d['status_label'],
            'short_label'     => $d['short_label'],
            'maint_scope'     => $d['maint_scope'],
            'maint_starts_at' => $d['maint_starts_at'],
            'maint_minutes'   => $d['maint_minutes'],
            'sort_order'      => $d['sort_order'],
        ];
        if ($id <= 0) {
            return Database::insert('server_services', $campos);
        }
        Database::run(
            'UPDATE server_services
                SET slug = :slug, label = :label, state = :state, status_label = :status_label,
                    short_label = :short_label, maint_scope = :maint_scope,
                    maint_starts_at = :maint_starts_at, maint_minutes = :maint_minutes,
                    sort_order = :sort_order
              WHERE id = :id',
            $campos + ['id' => $id]
        );
        return $id;
    }

    /**
     * Grava uma janela (id = 0 cria).
     * @param array<string,mixed> $d
     */
    public static function salvarJanela(array $d, int $id = 0): int
    {
        $campos = [
            'starts_at' => $d['starts_at'],
            'ends_at'   => $d['ends_at'],
            'region'    => $d['region'],
            'note'      => $d['note'],
            'news_slug' => $d['news_slug'],
        ];
        if ($id <= 0) {
            return Database::insert('maintenance_windows', $campos);
        }
        Database::run(
            'UPDATE maintenance_windows
                SET starts_at = :starts_at, ends_at = :ends_at, region = :region,
                    note = :note, news_slug = :news_slug
              WHERE id = :id',
            $campos + ['id' => $id]
        );
        return $id;
    }

    /**
     * Só o `state` de uma região ou de um serviço — o botão de estado da própria linha.
     *
     * É a operação mais frequente do plantão ("a Europa caiu"), e obrigar a abrir o formulário
     * inteiro para mudar uma palavra transformaria trinta segundos em dois minutos, com o jogo
     * fora do ar. A tabela é escolhida por um `match`, nunca concatenada do pedido.
     */
    public static function trocarEstado(string $tipo, int $id, string $state): bool
    {
        $tabela = match ($tipo) {
            'regiao'  => 'server_regions',
            'servico' => 'server_services',
            default   => null,
        };
        if ($tabela === null || $id <= 0 || !isset(self::ESTADOS[$state])) {
            return false;
        }
        Database::run("UPDATE {$tabela} SET state = :st WHERE id = :id", ['st' => $state, 'id' => $id]);
        return true;
    }

    /**
     * O rótulo da região é a chave que as janelas guardam; renomear sem trazê-las junto
     * transformaria a agenda inteira em janela órfã.
     */
    public static function renomearRegiaoNasJanelas(string $de, string $para): int
    {
        if ($de === $para || $de === '') {
            return 0;
        }
        return Database::run(
            'UPDATE maintenance_windows SET region = :para WHERE region = :de',
            ['para' => $para, 'de' => $de]
        )->rowCount();
    }

    public static function excluir(string $tipo, int $id): bool
    {
        $tabela = match ($tipo) {
            'regiao'  => 'server_regions',
            'servico' => 'server_services',
            'janela'  => 'maintenance_windows',
            default   => null,
        };
        if ($tabela === null || $id <= 0) {
            return false;
        }
        return Database::run("DELETE FROM {$tabela} WHERE id = :id", ['id' => $id])->rowCount() > 0;
    }

    /** O slug já pertence a outra linha da mesma tabela? (UNIQUE no banco; erro legível aqui.) */
    public static function slugOcupado(string $tipo, string $slug, int $exceto = 0): bool
    {
        $tabela = $tipo === 'regiao' ? 'server_regions' : 'server_services';
        return (int)Database::value(
            "SELECT COUNT(*) FROM {$tabela} WHERE slug = :s AND id <> :ex",
            ['s' => $slug, 'ex' => $exceto]
        ) > 0;
    }

    /** O rótulo já pertence a outra região? (não é UNIQUE no banco, mas as janelas dependem dele) */
    public static function rotuloOcupado(string $label, int $exceto = 0): bool
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM server_regions WHERE label = :l AND id <> :ex',
            ['l' => $label, 'ex' => $exceto]
        ) > 0;
    }

    /* =====================================================================================
       RESUMO (a faixa de indicadores da tela)
       ===================================================================================== */

    /**
     * Os quatro números do topo. Todos contados do banco nesta requisição.
     *
     * @return array<string,mixed>
     */
    public static function resumo(): array
    {
        $regioes  = self::regioes();
        $servicos = self::servicos();

        $regOnline = 0;
        $jogadores = 0;
        $somaPing  = 0;
        foreach ($regioes as $r) {
            $regOnline += $r['estado'] === 'online' ? 1 : 0;
            $jogadores += $r['jogadores'];
            $somaPing  += $r['ping'];
        }

        $srvOk = 0;
        foreach ($servicos as $s) {
            $srvOk += $s['state'] === 'ok' ? 1 : 0;
        }

        $proxima = Database::one(
            'SELECT starts_at, ends_at, region FROM maintenance_windows
              WHERE ends_at >= NOW() ORDER BY starts_at LIMIT 1'
        );

        return [
            'regioes'       => count($regioes),
            'regioes_ok'    => $regOnline,
            'servicos'      => count($servicos),
            'servicos_ok'   => $srvOk,
            'jogadores'     => $jogadores,
            'ping_medio'    => $regioes === [] ? 0 : (int)round($somaPing / count($regioes)),
            'proxima'       => $proxima === null ? null : [
                'inicio' => (int)strtotime((string)$proxima['starts_at']),
                'fim'    => (int)strtotime((string)$proxima['ends_at']),
                'regiao' => (string)$proxima['region'],
                'ativa'  => strtotime((string)$proxima['starts_at']) <= time(),
            ],
            'janelas_total' => (int)Database::value('SELECT COUNT(*) FROM maintenance_windows'),
        ];
    }

    /* =====================================================================================
       APOIO
       ===================================================================================== */

    /**
     * Slug a partir do rótulo. Sem transliteração de acento por tabela: `iconv` com //TRANSLIT
     * depende de locale e no Windows devolve "?" — o que geraria slugs vazios em "ÁSIA".
     */
    public static function slugificar(string $texto, int $max = 24): string
    {
        $mapa = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ï'=>'i',
                 'ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n'];
        $t = strtr(mb_strtolower(trim($texto), 'UTF-8'), $mapa);
        $t = preg_replace('/[^a-z0-9]+/u', '-', $t) ?? '';
        $t = trim($t, '-');
        return mb_substr($t, 0, $max);
    }

    /**
     * Janela mais relevante por rótulo de região: a ATIVA vence a próxima agendada, porque é
     * ela que decide a cor da linha. `n` é a contagem total, usada pela coluna e pelo bloqueio.
     *
     * @return array<string,array{ativa:bool,quando:int,n:int}>
     */
    private static function janelasPorRegiao(): array
    {
        $agora = time();
        $out   = [];
        $linhas = Database::all(
            'SELECT region, starts_at, ends_at FROM maintenance_windows
              WHERE ends_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY starts_at ASC'
        );
        foreach ($linhas as $j) {
            $reg   = (string)$j['region'];
            $ini   = (int)strtotime((string)$j['starts_at']);
            $fim   = (int)strtotime((string)$j['ends_at']);
            $ativa = $ini <= $agora && $agora <= $fim;
            $n     = ($out[$reg]['n'] ?? 0) + 1;

            if (!isset($out[$reg]) || ($ativa && !$out[$reg]['ativa'])) {
                $out[$reg] = ['ativa' => $ativa, 'quando' => $ativa ? $fim : $ini, 'n' => $n];
            } else {
                $out[$reg]['n'] = $n;
            }
        }
        return $out;
    }

    /** `%` e `_` digitados na busca são literais, não curingas — senão "_" casa com tudo. */
    private static function escLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }
}
