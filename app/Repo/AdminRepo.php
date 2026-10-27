<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;
use App\Core\GameRules;

/**
 * Contas com acesso ao painel administrativo e a trilha de auditoria (`admin_audit_log`).
 *
 * Vive aqui, e não dentro do controller, porque três donos diferentes precisam da mesma
 * regra: o login do painel (App\Controllers\Admin\AuthController), a guarda de rota
 * (App\Controllers\Admin\AdminController) e, mais adiante, a tela "Logs do Sistema".
 *
 * O esquema é o do patch db/patch_v6_admin.php: users.role, users.status, users.last_ip.
 */
final class AdminRepo
{
    /** Papéis que abrem o painel. Qualquer outro valor de users.role é jogador comum. */
    public const PAPEIS = ['admin', 'owner'];

    /** @return array<string,mixed>|null */
    public static function porId(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return Database::one(
            'SELECT id, username, email, role, status, last_login, last_ip FROM users WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * Conta por nome de usuário OU e-mail — a mesma busca do login do jogo, inclusive o
     * password_hash, porque quem chama precisa conferir a senha.
     *
     * @return array<string,mixed>|null
     */
    public static function porLogin(string $login): ?array
    {
        return UserRepo::findByLogin($login);
    }

    /** Papel administrativo E conta ativa: banido/suspenso não entra nem sendo owner. */
    public static function podeEntrar(?array $conta): bool
    {
        if ($conta === null) {
            return false;
        }
        return in_array((string)($conta['role'] ?? 'player'), self::PAPEIS, true)
            && (string)($conta['status'] ?? 'active') === 'active';
    }

    /** Rótulo do papel para a topbar do painel. */
    public static function rotuloPapel(string $role): string
    {
        return $role === 'owner' ? 'Dono do jogo' : 'Administrador';
    }

    /**
     * Uma linha na trilha de auditoria. NUNCA lança: auditoria que derruba a requisição
     * transformaria uma falha de log em uma falha de logout — o operador ficaria preso
     * dentro do painel. Falhou, vai para o log de erro do PHP e a vida segue.
     *
     * @param array<string,mixed> $meta
     */
    public static function registrar(
        ?int $adminId,
        string $acao,
        string $entidade = 'sistema',
        ?string $entidadeId = null,
        string $resumo = '',
        array $meta = [],
        ?string $ip = null,
    ): void {
        try {
            Database::insert('admin_audit_log', [
                'admin_id'  => $adminId !== null && $adminId > 0 ? $adminId : null,
                'action'    => mb_substr($acao, 0, 64),
                'entity'    => mb_substr($entidade, 0, 48),
                'entity_id' => $entidadeId !== null ? mb_substr($entidadeId, 0, 64) : null,
                'summary'   => mb_substr($resumo, 0, 255),
                'meta'      => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip'        => $ip !== null ? mb_substr($ip, 0, 45) : null,
            ]);
        } catch (\Throwable $e) {
            error_log('[extracthunters] auditoria (' . $acao . '): ' . $e->getMessage());
        }
    }

    /**
     * Quantas falhas de login o IP acumulou na janela e quando foi a última.
     *
     * O contador mora no próprio log de auditoria porque ele é o único que sobrevive a
     * apagar o cookie: quem limpa a sessão para recomeçar a força bruta continua contado.
     *
     * A janela entra no SQL como literal, e não como parâmetro, porque o PDO roda sem
     * emulação e o MySQL não aceita placeholder dentro de INTERVAL. O valor é um int
     * convertido aqui — não vem do usuário.
     *
     * @return array{n:int, ultima:int} ultima = timestamp unix (0 quando não houve falha)
     */
    public static function falhasRecentes(string $ip, int $janelaMinutos = 15): array
    {
        $janela = max(1, min(1440, $janelaMinutos));
        try {
            $linha = Database::one(
                'SELECT COUNT(*) AS n, MAX(created_at) AS ultima
                   FROM admin_audit_log
                  WHERE action = :acao AND ip = :ip
                    AND created_at > DATE_SUB(NOW(), INTERVAL ' . $janela . ' MINUTE)',
                ['acao' => 'login_falhou', 'ip' => $ip]
            );
        } catch (\Throwable $e) {
            error_log('[extracthunters] falhasRecentes: ' . $e->getMessage());
            return ['n' => 0, 'ultima' => 0];
        }

        $ultima = (string)($linha['ultima'] ?? '');
        return [
            'n'      => (int)($linha['n'] ?? 0),
            'ultima' => $ultima !== '' ? (int)strtotime($ultima) : 0,
        ];
    }

    /** Carimba o acesso do administrador (último login e último IP). */
    public static function marcarAcesso(int $id, string $ip): void
    {
        try {
            Database::run(
                'UPDATE users SET last_login = NOW(), last_ip = :ip WHERE id = :id',
                ['ip' => mb_substr($ip, 0, 45), 'id' => $id]
            );
        } catch (\Throwable $e) {
            error_log('[extracthunters] marcarAcesso: ' . $e->getMessage());
        }
    }

    // =====================================================================================
    //  DASHBOARD (/admin)
    //
    //  Todo método daqui para baixo devolve DADO, nunca HTML: a view formata. É o que os
    //  torna conferíveis fora do navegador — cada um roda sozinho num script de CLI e o
    //  número tem de bater com o SELECT anotado em 'origem'.
    //
    //  A REGRA DA HONESTIDADE. O painel mostra três procedências diferentes e nunca as
    //  mistura em silêncio; toda métrica carrega a sua em 'fonte':
    //
    //    FONTE_VIVO    consulta direta nas tabelas do jogo — é o estado real de agora.
    //    FONTE_SERIE   admin_metrics_daily. A tabela é real, mas hoje é SEMENTE de
    //                  demonstração escrita por db/patch_v6_admin.php (determinística pela
    //                  data). Quando existir telemetria, ela grava nesta mesma tabela e a
    //                  tela passa a mostrar número verdadeiro sem mudar uma linha aqui.
    //    FONTE_SEMENTE coluna real, conteúdo de demonstração: os contadores de server_regions
    //                  foram escritos por db/patch_v6_admin.php e NÃO são presença medida.
    //                  Sem este selo a tela afirmaria 61 mil jogadores nas regiões e 0 no KPI
    //                  ao vivo — duas verdades contraditórias no mesmo quadro.
    //    FONTE_AUSENTE não existe origem ainda. O valor sai NULL, a view mostra travessão
    //                  e o campo 'origem' diz de onde ele virá. Nada é inventado.
    // =====================================================================================

    /** Consulta direta nas tabelas do jogo: é o estado real deste instante. */
    public const FONTE_VIVO = 'vivo';

    /** admin_metrics_daily — série diária; hoje semeada por db/patch_v6_admin.php. */
    public const FONTE_SERIE = 'serie';

    /** Coluna real com número de demonstração — amostra, não medição. */
    public const FONTE_SEMENTE = 'semente';

    /** Sem origem: o valor é NULL e 'origem' registra de onde ele virá. */
    public const FONTE_AUSENTE = 'ausente';

    /**
     * Janela que conta como "online agora".
     *
     * Não existe tabela de sessão/heartbeat no esquema, então presença é aproximada pelo
     * último login. É uma aproximação POR CIMA (quem fechou o jogo continua contado até a
     * janela vencer) e a tela diz isso — melhor do que fingir um contador de sessões.
     */
    private const PRESENCA_MIN = 15;

    /** Teto de latência usado só para desenhar o anel de rede (não é SLA acordado). */
    private const PING_TETO_MS = 300;

    /**
     * Os quatro cartões da faixa de boas-vindas.
     *
     * @return list<array<string,mixed>> cada item:
     *   chave, rotulo, icone, tom, valor (int|float|null), formato (inteiro|duracao|dinheiro),
     *   sub (string|null), delta (float|null, fração: 0.182 = +18,2%), delta_rotulo,
     *   fonte, origem, nota (string|null)
     */
    public static function kpis(): array
    {
        return [
            self::kpiOnline(),
            self::kpiNovos(),
            self::kpiSessao(),
            self::kpiReceitaMes(),
        ];
    }

    /** @return array<string,mixed> */
    private static function kpiOnline(): array
    {
        $agora = (int)self::valor(
            'SELECT COUNT(*) FROM users
              WHERE status = :st AND last_login IS NOT NULL
                AND last_login >= DATE_SUB(NOW(), INTERVAL ' . self::PRESENCA_MIN . ' MINUTE)',
            ['st' => 'active']
        );

        $teto = self::capacidadeTotal();

        return [
            'chave'   => 'online',
            'rotulo'  => 'Jogadores online',
            'icone'   => 'group',
            'tom'     => 'lime',
            'valor'   => $agora,
            'formato' => 'inteiro',
            'sub'     => $teto > 0 ? '/ ' . $teto : null,
            // Sem histórico de presença minuto a minuto não há "vs ontem" honesto para este
            // número: a variação fica de fora em vez de emprestar a do pico diário.
            'delta'        => null,
            'delta_rotulo' => 'sem série de presença',
            'fonte'   => self::FONTE_VIVO,
            'origem'  => 'users.last_login nos últimos ' . self::PRESENCA_MIN . ' min',
            'nota'    => 'Presença aproximada pelo último login — o esquema não tem tabela de sessão.',
        ];
    }

    /** @return array<string,mixed> */
    private static function kpiNovos(): array
    {
        $linha = self::linha(
            'SELECT
               SUM(created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR))                              AS agora,
               SUM(created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
                   AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR))                           AS antes
             FROM users'
        );

        $agora = (int)($linha['agora'] ?? 0);
        $antes = (int)($linha['antes'] ?? 0);

        return [
            'chave'   => 'novos',
            // Rótulo curto de propósito: no cartão do hero ele precisa caber em UMA linha,
            // ao lado do selo de procedência, para os quatro números ficarem na mesma altura.
            'rotulo'  => 'Novos (24 h)',
            'icone'   => 'person_add',
            'tom'     => 'amber',
            'valor'   => $agora,
            'formato' => 'inteiro',
            'sub'     => null,
            'delta'        => self::variacao($agora, $antes),
            'delta_rotulo' => 'vs 24 h antes',
            'fonte'   => self::FONTE_VIVO,
            'origem'  => 'COUNT(users.created_at) nas últimas 24 h',
            'nota'    => null,
        ];
    }

    /** @return array<string,mixed> */
    private static function kpiSessao(): array
    {
        // Média ponderada pelas sessões do dia: dia com 1.500 sessões não pode pesar igual a
        // um dia vazio. COALESCE porque a divisão vira NULL quando não há sessão na janela.
        $sql = 'SELECT COALESCE(SUM(avg_session_sec * sessions) / NULLIF(SUM(sessions), 0), 0)
                  FROM admin_metrics_daily
                 WHERE `day` BETWEEN DATE_SUB(CURDATE(), INTERVAL %d DAY) AND DATE_SUB(CURDATE(), INTERVAL %d DAY)';

        $agora = (float)self::valor(sprintf($sql, 6, 0));
        $antes = (float)self::valor(sprintf($sql, 13, 7));

        return [
            'chave'   => 'sessao',
            'rotulo'  => 'Sessão média',
            'icone'   => 'schedule',
            'tom'     => 'cyan',
            'valor'   => $agora > 0 ? (int)round($agora) : null,
            'formato' => 'duracao',
            'sub'     => null,
            'delta'        => self::variacao($agora, $antes),
            'delta_rotulo' => 'vs 7 dias antes',
            'fonte'   => self::FONTE_SERIE,
            'origem'  => 'admin_metrics_daily.avg_session_sec (7 dias, ponderado por sessions)',
            'nota'    => null,
        ];
    }

    /** @return array<string,mixed> */
    private static function kpiReceitaMes(): array
    {
        // Mês corrente contra o MESMO NÚMERO DE DIAS do mês anterior: comparar 10 dias de
        // setembro com 31 de agosto acusaria uma queda que não existe.
        //
        // O primeiro dia sai de DAYOFMONTH e não de DATE_FORMAT(...,'%Y-%m-01') de propósito:
        // a máscara traria aspas para dentro da string SQL, e aspas duplas deixam de ser
        // literal se o servidor rodar com ANSI_QUOTES.
        $linha = self::linha(
            'SELECT
               COALESCE(SUM(CASE WHEN `day` >= DATE_SUB(CURDATE(), INTERVAL DAYOFMONTH(CURDATE()) - 1 DAY)
                                  AND `day` <= CURDATE() THEN revenue_cents END), 0) AS agora,
               COALESCE(SUM(CASE WHEN `day` >= DATE_SUB(DATE_SUB(CURDATE(), INTERVAL 1 MONTH),
                                                        INTERVAL DAYOFMONTH(CURDATE()) - 1 DAY)
                                  AND `day` <= DATE_SUB(CURDATE(), INTERVAL 1 MONTH) THEN revenue_cents END), 0) AS antes
             FROM admin_metrics_daily'
        );

        $agora = (int)($linha['agora'] ?? 0);
        $antes = (int)($linha['antes'] ?? 0);

        return [
            'chave'   => 'receita',
            'rotulo'  => 'Receita (mês)',
            'icone'   => 'payments',
            'tom'     => 'purple',
            'valor'   => $agora,
            'formato' => 'dinheiro',
            'sub'     => null,
            'delta'        => self::variacao($agora, $antes),
            'delta_rotulo' => 'vs mês anterior',
            'fonte'   => self::FONTE_SERIE,
            'origem'  => 'admin_metrics_daily.revenue_cents do mês corrente',
            'nota'    => null,
        ];
    }

    /**
     * Série diária de pico simultâneo para o gráfico de linha.
     *
     * @return array{pontos:list<array{dia:string,rotulo:string,valor:int}>, min:int, max:int,
     *               media:int, dias:int, fonte:string, origem:string}
     */
    public static function serieOnline(int $dias = 7): array
    {
        $dias = self::janela($dias);

        $linhas = self::linhas(
            'SELECT `day`, players_online
               FROM admin_metrics_daily
              WHERE `day` > DATE_SUB(CURDATE(), INTERVAL ' . $dias . ' DAY)
                AND `day` <= CURDATE()
              ORDER BY `day` ASC'
        );

        $pontos = [];
        foreach ($linhas as $l) {
            $dia = (string)$l['day'];
            $pontos[] = [
                'dia'    => $dia,
                'rotulo' => date('d/m', (int)strtotime($dia)),
                'valor'  => (int)$l['players_online'],
            ];
        }

        $valores = array_column($pontos, 'valor');

        return [
            'pontos' => $pontos,
            'min'    => $valores === [] ? 0 : (int)min($valores),
            'max'    => $valores === [] ? 0 : (int)max($valores),
            'media'  => $valores === [] ? 0 : (int)round(array_sum($valores) / count($valores)),
            'dias'   => $dias,
            'fonte'  => self::FONTE_SERIE,
            'origem' => 'admin_metrics_daily.players_online',
        ];
    }

    /**
     * Ranking por nível. O empate desempata por XP e depois por nome, para a ordem não
     * dançar entre dois carregamentos da mesma página.
     *
     * @return list<array<string,mixed>>
     */
    public static function topJogadores(int $n = 5): array
    {
        $n = max(1, min(20, $n));

        $linhas = self::linhas(
            'SELECT p.user_id, p.display_name, p.level, p.xp, p.avatar, p.player_rank,
                    u.username, u.status
               FROM players p
               JOIN users u ON u.id = p.user_id
              ORDER BY p.level DESC, p.xp DESC, p.display_name ASC
              LIMIT ' . $n
        );

        $saida = [];
        foreach ($linhas as $i => $l) {
            $saida[] = [
                'posicao' => $i + 1,
                'user_id' => (int)$l['user_id'],
                'nome'    => (string)($l['display_name'] ?: $l['username']),
                'nivel'   => (int)$l['level'],
                'xp'      => (int)$l['xp'],
                'avatar'  => (string)($l['avatar'] ?? ''),
                'patente' => (string)($l['player_rank'] ?? ''),
                'ativo'   => (string)($l['status'] ?? 'active') === 'active',
            ];
        }
        return $saida;
    }

    /**
     * Receita da janela + barra por dia + a contraprova real.
     *
     * `compras` NÃO é a mesma coisa que `total`: são as compras de verdade registradas em
     * player_purchases, e elas são em moeda do jogo (créditos), não em reais. Ficam ao lado
     * do total justamente para o operador ver a diferença entre a série e o que já existe.
     *
     * @return array{barras:list<array{dia:string,rotulo:string,centavos:int}>, total_centavos:int,
     *               max_centavos:int, delta:float|null, dias:int, compras:array{n:int,creditos:int},
     *               fonte:string, origem:string}
     */
    public static function receita(int $dias = 30): array
    {
        $dias = self::janela($dias);

        $linhas = self::linhas(
            'SELECT `day`, revenue_cents
               FROM admin_metrics_daily
              WHERE `day` > DATE_SUB(CURDATE(), INTERVAL ' . $dias . ' DAY)
                AND `day` <= CURDATE()
              ORDER BY `day` ASC'
        );

        $barras = [];
        $total  = 0;
        foreach ($linhas as $l) {
            $dia   = (string)$l['day'];
            $cents = (int)$l['revenue_cents'];
            $total += $cents;
            $barras[] = ['dia' => $dia, 'rotulo' => date('d/m', (int)strtotime($dia)), 'centavos' => $cents];
        }

        $anterior = (int)self::valor(
            'SELECT COALESCE(SUM(revenue_cents), 0)
               FROM admin_metrics_daily
              WHERE `day` > DATE_SUB(CURDATE(), INTERVAL ' . ($dias * 2) . ' DAY)
                AND `day` <= DATE_SUB(CURDATE(), INTERVAL ' . $dias . ' DAY)'
        );

        $compras = self::linha(
            'SELECT COUNT(*) AS n, COALESCE(SUM(price * quantity), 0) AS creditos
               FROM player_purchases
              WHERE bought_at >= DATE_SUB(NOW(), INTERVAL ' . $dias . ' DAY)'
        );

        $cents = array_column($barras, 'centavos');

        return [
            'barras'         => $barras,
            'total_centavos' => $total,
            'max_centavos'   => $cents === [] ? 0 : (int)max($cents),
            'delta'          => self::variacao($total, $anterior),
            'dias'           => $dias,
            'compras'        => ['n' => (int)($compras['n'] ?? 0), 'creditos' => (int)($compras['creditos'] ?? 0)],
            'fonte'          => self::FONTE_SERIE,
            'origem'         => 'admin_metrics_daily.revenue_cents; compras de player_purchases',
        ];
    }

    /**
     * Temporada, evento de temporada e janelas de manutenção, na mesma lista.
     *
     * Não há tabela `events` no esquema: o que o jogo chama de evento vive em `seasons`
     * (o evento em destaque, com event_ends_at) e em `maintenance_windows`. O estado sai da
     * comparação das datas com agora — por isso um evento aparece ENCERRADO quando ele
     * realmente já acabou, em vez de ficar eternamente "ativo".
     *
     * @return list<array{titulo:string,tipo:string,imagem:string,inicio:int|null,fim:int,
     *                    estado:string,nota:string|null,fonte:string}>
     */
    public static function eventosAtivos(int $n = 6): array
    {
        $n     = max(1, min(20, $n));
        $agora = time();
        $itens = [];

        $temporada = self::linha(
            'SELECT tag, pass_name, pass_image, event_tag, event_title, event_image,
                    event_ends_at, starts_at, ends_at
               FROM seasons WHERE is_current = 1 ORDER BY id DESC LIMIT 1'
        );

        if ($temporada !== null) {
            $itens[] = [
                'titulo' => trim((string)$temporada['tag'] . ' · ' . (string)$temporada['pass_name']),
                'tipo'   => 'Temporada',
                'imagem' => (string)($temporada['pass_image'] ?? ''),
                'inicio' => (int)strtotime((string)$temporada['starts_at']),
                'fim'    => (int)strtotime((string)$temporada['ends_at']),
                'nota'   => null,
                'fonte'  => self::FONTE_VIVO,
            ];
            if (!empty($temporada['event_title'])) {
                $itens[] = [
                    'titulo' => (string)$temporada['event_title'],
                    'tipo'   => (string)($temporada['event_tag'] ?: 'Evento'),
                    'imagem' => (string)($temporada['event_image'] ?? ''),
                    'inicio' => null,
                    'fim'    => (int)strtotime((string)$temporada['event_ends_at']),
                    'nota'   => null,
                    'fonte'  => self::FONTE_VIVO,
                ];
            }
        }

        $janelas = self::linhas(
            'SELECT mw.starts_at, mw.ends_at, mw.region, mw.note, nw.image
               FROM maintenance_windows mw
          LEFT JOIN news nw ON nw.slug = mw.news_slug
              ORDER BY ABS(TIMESTAMPDIFF(SECOND, NOW(), mw.starts_at)) ASC
              LIMIT ' . $n
        );

        foreach ($janelas as $j) {
            $itens[] = [
                // Região por EXTENSO, como no resto do painel: abreviar só algumas ("Am. DO SUL")
                // misturava duas convenções na mesma lista. A data desempata janelas vizinhas e
                // a caixa é uniformizada no CSS, não aqui.
                'titulo' => 'Manutenção ' . date('d/m', (int)strtotime((string)$j['starts_at']))
                            . ' · ' . trim((string)$j['region']),
                'tipo'   => 'Janela de manutenção',
                'imagem' => (string)($j['image'] ?? ''),
                'inicio' => (int)strtotime((string)$j['starts_at']),
                'fim'    => (int)strtotime((string)$j['ends_at']),
                'nota'   => (string)($j['note'] ?? '') ?: null,
                'fonte'  => self::FONTE_VIVO,
            ];
        }

        foreach ($itens as $i => $item) {
            $itens[$i]['estado'] = match (true) {
                $item['fim'] <= $agora                                     => 'encerrado',
                $item['inicio'] !== null && $item['inicio'] > $agora       => 'agendado',
                default                                                    => 'ativo',
            };
        }

        // Quem está no ar primeiro, depois o que vem aí, e o encerrado por último — dentro de
        // cada grupo, o que termina antes (ou terminou por último) na frente.
        $ordem = ['ativo' => 0, 'agendado' => 1, 'encerrado' => 2];
        usort($itens, static function (array $a, array $b) use ($ordem): int {
            $c = $ordem[$a['estado']] <=> $ordem[$b['estado']];
            if ($c !== 0) {
                return $c;
            }
            return $a['estado'] === 'encerrado' ? $b['fim'] <=> $a['fim'] : $a['fim'] <=> $b['fim'];
        });

        return array_slice($itens, 0, $n);
    }

    /**
     * Linha do tempo de tudo que aconteceu — no painel e no jogo.
     *
     * Cinco consultas curtas fundidas em PHP, e não um UNION: as tabelas não têm colunas
     * compatíveis e cada uma precisa de um JOIN diferente. Cada fonte devolve no máximo $n
     * linhas, então a fusão nunca percorre mais do que 5·$n itens.
     *
     * @return list<array{quando:int,icone:string,tom:string,texto:string,detalhe:string|null,
     *                    origem:string,repeticoes:int}>
     */
    public static function atividadeRecente(int $n = 6): array
    {
        $n = max(1, min(30, $n));
        $itens = [];

        foreach (self::linhas('SELECT id, username, created_at FROM users ORDER BY created_at DESC LIMIT ' . $n) as $u) {
            $itens[] = [
                'quando'  => (int)strtotime((string)$u['created_at']),
                'icone'   => 'person_add',
                'tom'     => 'cyan',
                'texto'   => 'Novo jogador registrado: ' . (string)$u['username'],
                'detalhe' => null,
                'origem'  => 'users',
            ];
        }

        foreach (self::linhas(
            'SELECT b.created_at, b.kind, b.reason, u.username
               FROM bans b LEFT JOIN users u ON u.id = b.user_id
              WHERE b.revoked_at IS NULL
              ORDER BY b.created_at DESC LIMIT ' . $n
        ) as $b) {
            $itens[] = [
                'quando'  => (int)strtotime((string)$b['created_at']),
                'icone'   => 'gavel',
                'tom'     => 'red',
                'texto'   => 'Jogador ' . (string)($b['username'] ?? 'removido') . ' banido ('
                             . ((string)$b['kind'] === 'perma' ? 'definitivo' : 'temporário') . ')',
                'detalhe' => (string)($b['reason'] ?? '') ?: null,
                'origem'  => 'bans',
            ];
        }

        foreach (self::linhas(
            'SELECT pp.bought_at, pp.price, pp.quantity, pp.currency, i.name, u.username
               FROM player_purchases pp
               JOIN items i ON i.id = pp.item_id
               JOIN users u ON u.id = pp.user_id
              ORDER BY pp.bought_at DESC LIMIT ' . $n
        ) as $c) {
            $itens[] = [
                'quando'  => (int)strtotime((string)$c['bought_at']),
                'icone'   => 'shopping_bag',
                'tom'     => 'gold',
                'texto'   => 'Compra na loja: ' . (string)$c['name'],
                'detalhe' => number_format((float)$c['price'] * (int)$c['quantity'], 0, ',', '.')
                             . ' ' . (string)$c['currency'] . ' · ' . (string)$c['username'],
                'origem'  => 'player_purchases',
            ];
        }

        foreach (self::linhas(
            'SELECT pm.played_at, pm.result, pm.kills, pm.xp, u.username
               FROM player_matches pm JOIN users u ON u.id = pm.user_id
              ORDER BY pm.played_at DESC LIMIT ' . $n
        ) as $m) {
            $itens[] = [
                'quando'  => (int)strtotime((string)$m['played_at']),
                'icone'   => 'flag',
                'tom'     => 'lime',
                'texto'   => 'Operação de ' . (string)$m['username'] . ': ' . (string)$m['result'],
                'detalhe' => (int)$m['kills'] . ' abates · ' . number_format((float)$m['xp'], 0, ',', '.') . ' XP',
                'origem'  => 'player_matches',
            ];
        }

        foreach (self::linhas(
            'SELECT a.created_at, a.action, a.summary, u.username
               FROM admin_audit_log a LEFT JOIN users u ON u.id = a.admin_id
              ORDER BY a.created_at DESC, a.id DESC LIMIT ' . $n
        ) as $a) {
            $acao  = (string)$a['action'];
            [$frase, $entre] = self::partirResumo((string)($a['summary'] ?: $acao));
            $quem  = 'Painel · ' . (string)($a['username'] ?? 'anônimo');
            $itens[] = [
                'quando'  => (int)strtotime((string)$a['created_at']),
                'icone'   => str_contains($acao, 'falhou') || str_contains($acao, 'bloqueio') ? 'shield_lock' : 'admin_panel_settings',
                'tom'     => str_contains($acao, 'falhou') || str_contains($acao, 'bloqueio') ? 'amber' : 'muted',
                'texto'   => $frase,
                // O aposto técnico (role=…, status=…) desce para a linha de meta: na linha de
                // cima ele era o primeiro a ser cortado pelo clamp, deixando parêntese aberto.
                'detalhe' => $entre !== '' ? $quem . ' · ' . $entre : $quem,
                'origem'  => 'admin_audit_log',
            ];
        }

        usort($itens, static fn(array $a, array $b): int => $b['quando'] <=> $a['quando']);

        // Repetição vizinha do mesmo texto vira UMA linha contada ("3 ×"). Três tentativas
        // idênticas seguidas enchiam metade da lista sem acrescentar um fato novo.
        $fundido = [];
        foreach ($itens as $item) {
            $ultimo = array_key_last($fundido);
            if ($ultimo !== null
                && $fundido[$ultimo]['texto']   === $item['texto']
                && $fundido[$ultimo]['detalhe'] === $item['detalhe']
            ) {
                $fundido[$ultimo]['repeticoes']++;
                continue;
            }
            $item['repeticoes'] = 1;
            $fundido[] = $item;
        }

        return array_slice($fundido, 0, $n);
    }

    /**
     * Regiões e serviços da plataforma.
     *
     * A barra de ocupação compara a região com a MAIS CHEIA, e não com um teto: o único teto
     * no banco (admin_settings.region_capacity_total = 20) é a lotação de uma instância do
     * mundo persistente, não do datacenter — usá-lo aqui daria 12.480/20 e viraria mentira.
     *
     * @return array{regioes:list<array<string,mixed>>, servicos:list<array<string,mixed>>,
     *               total:int, pico:int, ping_medio:int, fonte:string, origem:string, escala:string}
     */
    public static function statusServidores(): array
    {
        $regioes  = self::linhas('SELECT slug, label, ping_ms, players, state FROM server_regions ORDER BY sort_order, id');
        $servicos = self::linhas('SELECT slug, label, state, status_label, maint_scope FROM server_services ORDER BY sort_order, id');
        $janelas  = self::linhas(
            'SELECT region, starts_at, ends_at FROM maintenance_windows
              WHERE ends_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY starts_at ASC'
        );

        $agora = time();

        /** @var array<string,array{ativa:bool,quando:int}> $porRegiao */
        $porRegiao = [];
        foreach ($janelas as $j) {
            $ini = (int)strtotime((string)$j['starts_at']);
            $fim = (int)strtotime((string)$j['ends_at']);
            $reg = (string)$j['region'];
            $ativa = $ini <= $agora && $agora <= $fim;
            // A janela ATIVA sempre vence a próxima agendada na hora de rotular a região.
            if (!isset($porRegiao[$reg]) || ($ativa && !$porRegiao[$reg]['ativa'])) {
                $porRegiao[$reg] = ['ativa' => $ativa, 'quando' => $ativa ? $fim : $ini];
            }
        }

        $pico = 0;
        foreach ($regioes as $r) {
            $pico = max($pico, (int)$r['players']);
        }

        $saidaReg = [];
        $totalJog = 0;
        $somaPing = 0;
        foreach ($regioes as $r) {
            $label = (string)$r['label'];
            $jog   = (int)$r['players'];
            $totalJog += $jog;
            $somaPing += (int)$r['ping_ms'];

            $manut = $porRegiao[$label] ?? null;
            // Uma região com janela marcada NÃO é "Online" verde: o estado tem de mudar junto
            // com a nota, senão a linha diz manutenção no texto e operação normal na cor.
            [$estado, $rotulo, $nota] = match (true) {
                $manut !== null && $manut['ativa'] => ['manutencao', 'Em manutenção', 'Janela ativa até ' . date('H:i', $manut['quando'])],
                (string)$r['state'] === 'down'     => ['fora', 'Fora do ar', null],
                (string)$r['state'] === 'warn'     => ['instavel', 'Instável', null],
                $manut !== null                    => ['agendada', 'Manut. agendada', 'Janela em ' . date('d/m H:i', $manut['quando'])],
                default                            => ['online', 'Online', null],
            };

            $saidaReg[] = [
                'slug'          => (string)$r['slug'],
                'label'         => $label,
                'jogadores'     => $jog,
                'ping'          => (int)$r['ping_ms'],
                'estado'        => $estado,
                'estado_rotulo' => $rotulo,
                'ocupacao'      => $pico > 0 ? $jog / $pico : 0.0,
                'nota'          => $nota,
            ];
        }

        $saidaSrv = [];
        foreach ($servicos as $s) {
            $saidaSrv[] = [
                'slug'   => (string)$s['slug'],
                'label'  => (string)$s['label'],
                'estado' => (string)$s['state'] === 'ok' ? 'online' : ((string)$s['state'] === 'down' ? 'fora' : 'manutencao'),
                'rotulo' => (string)$s['status_label'],
                'escopo' => (string)($s['maint_scope'] ?? '') ?: null,
            ];
        }

        return [
            'regioes'    => $saidaReg,
            'servicos'   => $saidaSrv,
            'total'      => $totalJog,
            'pico'       => $pico,
            'ping_medio' => $regioes === [] ? 0 : (int)round($somaPing / count($regioes)),
            // server_regions.players é semente de demonstração: sem este selo o painel diria
            // 61 mil jogadores nas regiões e 0 no cartão ao vivo, na mesma tela.
            'fonte'      => self::FONTE_SEMENTE,
            'origem'     => 'server_regions + server_services + maintenance_windows',
            'escala'     => 'Contadores e ping de server_regions são amostra de demonstração; a barra compara cada região com a mais cheia, porque o banco não guarda teto por região.',
        ];
    }

    /**
     * Os quatro anéis e o rodapé do bloco "Status do sistema".
     *
     * Deliberadamente NÃO tem CPU, memória, disco nem uptime: nada disso é coletado. Os anéis
     * mostram o que o banco realmente sabe (ocupação, serviços, regiões, latência) e o rodapé
     * declara o buraco em vez de preenchê-lo — ver 'origem' do item de uptime.
     *
     * @return array{aneis:list<array<string,mixed>>, rodape:list<array<string,mixed>>}
     */
    public static function statusSistema(): array
    {
        $srv   = self::statusServidores();
        $teto  = self::capacidadeTotal();
        $online = (int)self::valor(
            'SELECT COUNT(*) FROM users
              WHERE status = :st AND last_login IS NOT NULL
                AND last_login >= DATE_SUB(NOW(), INTERVAL ' . self::PRESENCA_MIN . ' MINUTE)',
            ['st' => 'active']
        );

        $srvOk = 0;
        foreach ($srv['servicos'] as $s) {
            $srvOk += $s['estado'] === 'online' ? 1 : 0;
        }

        // Três baldes, e não dois: uma região com janela AGENDADA continua operando agora
        // (por isso não é falha), mas também não pode entrar no verde — o cartão ao lado a
        // pinta de âmbar, e um anel verde inteiro contradizia a tela vizinha pela cor.
        $regOk = $regAviso = $regFora = 0;
        foreach ($srv['regioes'] as $r) {
            match ($r['estado']) {
                'online'          => $regOk++,
                'fora'            => $regFora++,
                default           => $regAviso++,
            };
        }

        $nSrv = max(1, count($srv['servicos']));
        $nReg = max(1, count($srv['regioes']));
        $ping = $srv['ping_medio'];

        $aneis = [
            [
                'chave'  => 'ocupacao',
                'rotulo' => 'Ocupação',
                'fracao' => $teto > 0 ? min(1.0, $online / $teto) : 0.0,
                'texto'  => $teto > 0 ? $online . '/' . $teto : (string)$online,
                'tom'    => 'lime',
                'fonte'  => self::FONTE_VIVO,
                'origem' => 'users.last_login vs capacidade das regiões',
            ],
            [
                'chave'  => 'servicos',
                'rotulo' => 'Serviços no ar',
                'fracao' => $srvOk / $nSrv,
                'texto'  => $srvOk . '/' . count($srv['servicos']),
                'tom'    => $srvOk === $nSrv ? 'cyan' : 'amber',
                'fonte'  => self::FONTE_VIVO,
                'origem' => 'server_services.state',
            ],
            [
                'chave'  => 'regioes',
                // "OK" afirmava saúde; "online" afirma estado — e é o que o anel realmente conta.
                'rotulo' => 'Regiões online',
                'fracao' => ($regOk + $regAviso) / $nReg,
                'texto'  => $regOk . '/' . count($srv['regioes']),
                'tom'    => $regOk === $nReg ? 'lime' : 'amber',
                // Cada região vira uma fatia do arco, com a cor do seu estado: o anel passa a
                // dizer "3 verdes + 1 âmbar" em vez de um verde cheio que a linha vizinha nega.
                'segmentos' => array_merge(
                    array_fill(0, $regOk,    ['fracao' => 1 / $nReg, 'tom' => 'lime']),
                    array_fill(0, $regAviso, ['fracao' => 1 / $nReg, 'tom' => 'amber']),
                    array_fill(0, $regFora,  ['fracao' => 1 / $nReg, 'tom' => 'red']),
                ),
                'fonte'  => self::FONTE_VIVO,
                'origem' => 'server_regions.state + maintenance_windows',
            ],
            [
                // Anel cheio = rede folgada. A escala é de leitura (300 ms), não um SLA.
                'chave'  => 'latencia',
                'rotulo' => 'Folga de rede',
                'fracao' => $ping > 0 ? max(0.0, 1 - min(1.0, $ping / self::PING_TETO_MS)) : 0.0,
                'texto'  => $ping > 0 ? $ping . ' ms' : '—',
                'tom'    => $ping <= 120 ? 'lime' : ($ping <= 200 ? 'amber' : 'red'),
                'fonte'  => self::FONTE_VIVO,
                'origem' => 'AVG(server_regions.ping_ms), teto de leitura ' . self::PING_TETO_MS . ' ms',
            ],
        ];

        $ultima = (string)(self::valor('SELECT MAX(created_at) FROM admin_audit_log') ?? '');

        $rodape = [
            [
                'rotulo' => 'Uptime',
                'valor'  => null,
                'fonte'  => self::FONTE_AUSENTE,
                'origem' => 'virá de um coletor de health-check do host; nada é gravado hoje',
            ],
            [
                'rotulo' => 'Capacidade máx.',
                'valor'  => $teto > 0 ? number_format($teto, 0, ',', '.') . ' operadores' : null,
                'fonte'  => $teto > 0 ? self::FONTE_VIVO : self::FONTE_AUSENTE,
                'origem' => 'admin_settings.region_capacity_total × COUNT(server_regions)',
            ],
            [
                'rotulo' => 'Última ação',
                'valor'  => $ultima !== '' ? date('d/m H:i', (int)strtotime($ultima)) : null,
                'fonte'  => $ultima !== '' ? self::FONTE_VIVO : self::FONTE_AUSENTE,
                'origem' => 'MAX(admin_audit_log.created_at)',
            ],
        ];

        return ['aneis' => $aneis, 'rodape' => $rodape];
    }

    // ------------------------------------------------------------------ apoio do dashboard

    /**
     * Separa "Frase. (aposto)" em ['Frase.', 'aposto'].
     *
     * Vale para linha JÁ GRAVADA no admin_audit_log: encurtar só a origem (Admin\AuthController)
     * arrumaria o futuro e deixaria o histórico com o parêntese cortado no meio pelo clamp de
     * duas linhas da tela. Sem parêntese, devolve a frase inteira e string vazia.
     *
     * @return array{0:string, 1:string}
     */
    private static function partirResumo(string $resumo): array
    {
        $resumo = trim($resumo);
        if (!preg_match('/^(.*?)\s*\(([^()]*)\)\s*\.?$/u', $resumo, $m)) {
            return [$resumo, ''];
        }
        $frase = rtrim(trim($m[1]), '.');
        return [$frase . '.', trim($m[2])];
    }

    /** Teto humano da plataforma: o parâmetro por região multiplicado pelas regiões reais. */
    private static function capacidadeTotal(): int
    {
        $porRegiao = self::parametro('region_capacity_total', GameRules::REGION_CAPACITY['total']);
        $regioes   = (int)self::valor('SELECT COUNT(*) FROM server_regions');
        return $porRegiao * $regioes;
    }

    /**
     * Parâmetro de admin_settings, com a constante do código como padrão — a chave pode
     * ainda não ter sido semeada, e o painel não pode quebrar por causa disso.
     */
    private static function parametro(string $chave, int $padrao): int
    {
        $v = self::valor('SELECT `value` FROM admin_settings WHERE `key` = :k', ['k' => $chave]);
        return $v === null || $v === '' ? $padrao : (int)$v;
    }

    /** Variação fracionária entre dois períodos; null quando não há base de comparação. */
    private static function variacao(int|float $atual, int|float $anterior): ?float
    {
        if ((float)$anterior <= 0.0) {
            return null;
        }
        return ((float)$atual - (float)$anterior) / (float)$anterior;
    }

    /** Janela de dias aceita pelos gráficos (o valor vem da query string). */
    private static function janela(int $dias): int
    {
        return max(2, min(90, $dias));
    }

    // As três embalagens abaixo existem para o dashboard NUNCA derrubar o painel: uma tabela
    // que ainda não foi criada (patch não aplicado) vira lista vazia e a tela mostra o estado
    // vazio. Só o log de erro sabe do problema — o operador continua com o painel de pé.

    /** @param array<string,mixed> $p @return list<array<string,mixed>> */
    private static function linhas(string $sql, array $p = []): array
    {
        try {
            return Database::all($sql, $p);
        } catch (\Throwable $e) {
            error_log('[extracthunters] dashboard: ' . $e->getMessage());
            return [];
        }
    }

    /** @param array<string,mixed> $p @return array<string,mixed>|null */
    private static function linha(string $sql, array $p = []): ?array
    {
        try {
            return Database::one($sql, $p);
        } catch (\Throwable $e) {
            error_log('[extracthunters] dashboard: ' . $e->getMessage());
            return null;
        }
    }

    /** @param array<string,mixed> $p */
    private static function valor(string $sql, array $p = []): mixed
    {
        try {
            return Database::value($sql, $p);
        } catch (\Throwable $e) {
            error_log('[extracthunters] dashboard: ' . $e->getMessage());
            return null;
        }
    }
}
