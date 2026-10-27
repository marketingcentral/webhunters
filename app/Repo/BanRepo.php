<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * Punições de conta: a tabela `bans` e o reflexo dela em `users.status`.
 *
 * Existe como repositório próprio, e não dentro do AdminRepo, porque aqui mora uma regra que
 * NÃO é de tela: `bans` é o histórico (o que aconteceu, quando, por ordem de quem) e
 * `users.status` é o estado atual (a conta entra ou não entra). São duas verdades que
 * precisam nunca discordar, e quem as concilia tem de ser um lugar só — senão cada tela que
 * aplicar uma punição inventa a sua própria versão de "banido".
 *
 * ESTADO DE UM BAN é derivado, nunca uma coluna:
 *   revogado -> revoked_at preenchido (alguém desfez)
 *   ativo    -> sem revogação e sem prazo, ou com prazo ainda no futuro
 *   expirado -> sem revogação e com prazo já vencido
 *
 * O prazo vencendo NÃO dispara nada por si só — não há cron neste projeto. Quem devolve a
 * conta ao ar é reconciliar(), chamada quando a tela abre: o vencimento é lido, não agendado.
 *
 * Esquema: db/patch_v6_admin.php (tabela) + db/patch_v7_banimentos.php (revoke_reason, índice).
 */
final class BanRepo
{
    /**
     * Tipos de punição. A chave é o que vai para `bans.kind`; `status` é o que a punição
     * escreve em `users.status` enquanto estiver ativa.
     *
     * 'perma' é o mesmo literal que App\Repo\AdminRepo já usa na atividade recente do
     * dashboard — inventar um segundo nome para "definitivo" faria a linha do tempo mentir.
     *
     * @var array<string,array{rotulo:string,status:string,prazo:bool,tom:string}>
     */
    public const TIPOS = [
        'temp'      => ['rotulo' => 'Temporário',  'status' => 'banned',    'prazo' => true,  'tom' => 'warn'],
        'perma'     => ['rotulo' => 'Definitivo',  'status' => 'banned',    'prazo' => false, 'tom' => 'danger'],
        'suspensao' => ['rotulo' => 'Suspensão',   'status' => 'suspended', 'prazo' => true,  'tom' => 'info'],
    ];

    /** Estados derivados aceitos como filtro. 'todos' é a ausência de filtro. */
    public const ESTADOS = ['ativo', 'expirado', 'revogado'];

    /** Ordenações permitidas -> ORDER BY. A URL não escreve SQL; ela escolhe de uma lista. */
    private const ORDENS = [
        'recente'  => 'b.created_at DESC, b.id DESC',
        'antigo'   => 'b.created_at ASC, b.id ASC',
        'validade' => 'b.expires_at IS NULL, b.expires_at ASC, b.id DESC',
        'conta'    => 'u.username ASC, b.created_at DESC',
    ];

    public const POR_PAGINA = 15;

    /**
     * A expressão de estado, uma vez só. Repetida à mão no WHERE e no SELECT, ela viraria
     * duas regras que envelhecem separado — foi por isso que virou constante.
     *
     * PÚBLICA desde a API de gestão (App\Repo\ApiGestaoRepo::banimentos): a API pagina por
     * limit/offset e a tela por página fixa, então as duas consultas são diferentes — mas o
     * que é "ativo", "expirado" e "revogado" tem de ser a MESMA regra nas duas, senão o painel
     * e a API discordam sobre quem está punido. Continua sendo escrita só aqui.
     */
    public const ESTADO = "CASE
            WHEN b.revoked_at IS NOT NULL THEN 'revogado'
            WHEN b.expires_at IS NULL OR b.expires_at > NOW() THEN 'ativo'
            ELSE 'expirado'
        END";

    /** Um ban conta como ativo quando não foi revogado e o prazo não venceu. */
    private const VIGENTE = 'b.revoked_at IS NULL AND (b.expires_at IS NULL OR b.expires_at > NOW())';

    /* ===================================================================== leitura */

    /**
     * Página da listagem, já com os dois lados do JOIN (quem foi punido, quem puniu).
     *
     * @param array{busca?:string,estado?:string,tipo?:string,ordem?:string,pagina?:int} $filtros
     * @return array{linhas:list<array<string,mixed>>,total:int,pagina:int,paginas:int,
     *               primeiro:int,ultimo:int}
     */
    public static function listar(array $filtros = []): array
    {
        [$onde, $params] = self::filtro($filtros);
        $ordem  = self::ORDENS[(string)($filtros['ordem'] ?? '')] ?? self::ORDENS['recente'];

        $total = (int)Database::value(
            'SELECT COUNT(*) FROM bans b JOIN users u ON u.id = b.user_id WHERE ' . $onde,
            $params
        );

        $paginas = max(1, (int)ceil($total / self::POR_PAGINA));
        $pagina  = max(1, min($paginas, (int)($filtros['pagina'] ?? 1)));
        // LIMIT/OFFSET entram como literal porque o PDO roda sem emulação e o MySQL trataria
        // o placeholder como string ('15'), quebrando a consulta. Ambos são int calculado aqui.
        $offset  = ($pagina - 1) * self::POR_PAGINA;

        $linhas = Database::all(
            'SELECT b.id, b.user_id, b.admin_id, b.reason, b.kind, b.expires_at, b.created_at,
                    b.revoked_at, b.revoked_by, b.revoke_reason,
                    ' . self::ESTADO . ' AS estado,
                    u.username AS alvo_nome, u.email AS alvo_email,
                    u.status AS alvo_status, u.role AS alvo_papel,
                    a.username AS admin_nome,
                    r.username AS revogador_nome
               FROM bans b
               JOIN users u ON u.id = b.user_id
               LEFT JOIN users a ON a.id = b.admin_id
               LEFT JOIN users r ON r.id = b.revoked_by
              WHERE ' . $onde . '
              ORDER BY ' . $ordem . '
              LIMIT ' . self::POR_PAGINA . ' OFFSET ' . $offset,
            $params
        );

        return [
            'linhas'   => $linhas,
            'total'    => $total,
            'pagina'   => $pagina,
            'paginas'  => $paginas,
            'primeiro' => $total === 0 ? 0 : $offset + 1,
            'ultimo'   => min($total, $offset + count($linhas)),
        ];
    }

    /**
     * Quantos bans em cada estado, mais quantas CONTAS distintas estão punidas agora.
     *
     * Contas e bans não são o mesmo número (a mesma conta reincide) — e é a contagem de
     * contas que responde "quanta gente está fora do jogo neste momento".
     *
     * @return array{ativo:int,expirado:int,revogado:int,total:int,contas:int}
     */
    public static function contagens(): array
    {
        $linha = Database::one(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN b.revoked_at IS NOT NULL THEN 1 ELSE 0 END) AS revogado,
                    SUM(CASE WHEN ' . self::VIGENTE . ' THEN 1 ELSE 0 END) AS ativo,
                    SUM(CASE WHEN b.revoked_at IS NULL AND b.expires_at IS NOT NULL
                                  AND b.expires_at <= NOW() THEN 1 ELSE 0 END) AS expirado,
                    COUNT(DISTINCT CASE WHEN ' . self::VIGENTE . ' THEN b.user_id END) AS contas
               FROM bans b'
        ) ?? [];

        return [
            'total'    => (int)($linha['total'] ?? 0),
            'ativo'    => (int)($linha['ativo'] ?? 0),
            'expirado' => (int)($linha['expirado'] ?? 0),
            'revogado' => (int)($linha['revogado'] ?? 0),
            'contas'   => (int)($linha['contas'] ?? 0),
        ];
    }

    /** @return array<string,mixed>|null Um ban com os dois usuários resolvidos. */
    public static function porId(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return Database::one(
            'SELECT b.id, b.user_id, b.admin_id, b.reason, b.kind, b.expires_at, b.created_at,
                    b.revoked_at, b.revoked_by, b.revoke_reason,
                    ' . self::ESTADO . ' AS estado,
                    u.username AS alvo_nome, u.email AS alvo_email,
                    u.status AS alvo_status, u.role AS alvo_papel,
                    a.username AS admin_nome,
                    r.username AS revogador_nome
               FROM bans b
               JOIN users u ON u.id = b.user_id
               LEFT JOIN users a ON a.id = b.admin_id
               LEFT JOIN users r ON r.id = b.revoked_by
              WHERE b.id = :id
              LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * Resolve o alvo digitado no formulário: id numérico, nome de usuário ou e-mail.
     *
     * @return array<string,mixed>|null
     */
    public static function usuarioPorLogin(string $login): ?array
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }
        if (ctype_digit($login)) {
            return Database::one(
                'SELECT id, username, email, role, status FROM users WHERE id = :id LIMIT 1',
                ['id' => (int)$login]
            );
        }
        // Dois placeholders para o mesmo valor: o PDO deste projeto roda com
        // ATTR_EMULATE_PREPARES desligado, e aí um nome repetido devolve
        // SQLSTATE[HY093] Invalid parameter number. Nomes distintos, sempre.
        return Database::one(
            'SELECT id, username, email, role, status FROM users
              WHERE username = :login1 OR email = :login2
              ORDER BY id LIMIT 1',
            ['login1' => $login, 'login2' => $login]
        );
    }

    /**
     * Chaves de ordenação aceitas. Existe para o controller validar `?ordem=` contra a MESMA
     * lista que o SQL usa — validar contra os rótulos de app/Data deixaria uma ordenação
     * válida cair fora só porque alguém renomeou um texto.
     *
     * @return list<string>
     */
    public static function ordensValidas(): array
    {
        return array_keys(self::ORDENS);
    }

    /** @return array<string,mixed>|null A punição VIGENTE da conta, se houver. */
    public static function vigenteDe(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        return Database::one(
            'SELECT b.id, b.kind, b.reason, b.expires_at, b.created_at
               FROM bans b
              WHERE b.user_id = :u AND ' . self::VIGENTE . '
              ORDER BY b.created_at DESC, b.id DESC
              LIMIT 1',
            ['u' => $userId]
        );
    }

    /** Sugestões de alvo para o datalist do formulário (contas ativas primeiro). */
    public static function contasParaEscolha(int $limite = 50): array
    {
        $limite = max(1, min(200, $limite));
        return Database::all(
            "SELECT id, username, email, role, status
               FROM users
              ORDER BY status = 'active' DESC, username ASC
              LIMIT " . $limite
        );
    }

    /* ===================================================================== escrita */

    /**
     * Aplica a punição e deixa `users.status` coerente com ela, na MESMA transação: um ban
     * gravado com a conta ainda liberada é pior do que ban nenhum — o operador vê a linha na
     * tabela e acredita que resolveu.
     *
     * @param string|null $expiraEm 'Y-m-d H:i:s' ou null (definitivo)
     * @return int id do ban criado
     */
    public static function aplicar(
        int $userId,
        ?int $adminId,
        string $tipo,
        string $motivo,
        ?string $expiraEm,
    ): int {
        $tipo = isset(self::TIPOS[$tipo]) ? $tipo : 'temp';

        return (int)Database::transaction(static function () use ($userId, $adminId, $tipo, $motivo, $expiraEm): int {
            $id = Database::insert('bans', [
                'user_id'    => $userId,
                'admin_id'   => $adminId !== null && $adminId > 0 ? $adminId : null,
                'reason'     => $motivo,
                'kind'       => $tipo,
                // Definitivo não tem prazo: gravar uma data aqui criaria um ban que se
                // desfaz sozinho e ninguém pediu isso.
                'expires_at' => self::TIPOS[$tipo]['prazo'] ? $expiraEm : null,
            ]);
            self::sincronizar($userId);
            return $id;
        });
    }

    /**
     * Derruba um ban vigente. Devolve false quando o ban já estava revogado — a condição está
     * no próprio UPDATE para que dois operadores clicando ao mesmo tempo não sobrescrevam a
     * assinatura e o motivo de quem chegou primeiro.
     */
    public static function revogar(int $banId, ?int $adminId, string $motivo): bool
    {
        if ($banId <= 0) {
            return false;
        }

        return (bool)Database::transaction(static function () use ($banId, $adminId, $motivo): bool {
            $stmt = Database::run(
                'UPDATE bans
                    SET revoked_at = NOW(), revoked_by = :admin, revoke_reason = :motivo
                  WHERE id = :id AND revoked_at IS NULL',
                ['admin' => $adminId !== null && $adminId > 0 ? $adminId : null, 'motivo' => $motivo, 'id' => $banId]
            );
            if ($stmt->rowCount() === 0) {
                return false;
            }
            $userId = (int)Database::value('SELECT user_id FROM bans WHERE id = :id', ['id' => $banId]);
            self::sincronizar($userId);
            return true;
        });
    }

    /**
     * Apaga uma linha do histórico. A condição `NÃO vigente` mora no SQL de propósito: ainda
     * que uma tela futura esqueça a checagem, uma punição em vigor não sai da tabela pela
     * porta dos fundos — some da lista e a conta continuaria bloqueada, sem nada explicando.
     */
    public static function remover(int $banId): bool
    {
        if ($banId <= 0) {
            return false;
        }

        return (bool)Database::transaction(static function () use ($banId): bool {
            $userId = (int)Database::value('SELECT user_id FROM bans WHERE id = :id', ['id' => $banId]);
            if ($userId <= 0) {
                return false;
            }
            $stmt = Database::run(
                'DELETE FROM bans
                  WHERE id = :id
                    AND (revoked_at IS NOT NULL OR (expires_at IS NOT NULL AND expires_at <= NOW()))',
                ['id' => $banId]
            );
            if ($stmt->rowCount() === 0) {
                return false;
            }
            self::sincronizar($userId);
            return true;
        });
    }

    /**
     * Recalcula `users.status` a partir dos bans da conta. É a ÚNICA função que escreve nessa
     * coluna por causa de punição — aplicar, revogar, apagar e reconciliar terminam todos aqui.
     *
     * Ordem de precedência: um ban duro (temp/perma) vale mais que uma suspensão simultânea.
     *
     * @return string o status resultante
     */
    public static function sincronizar(int $userId): string
    {
        if ($userId <= 0) {
            return 'active';
        }

        // Os tipos entram como literal (e não como parâmetro) porque são constantes do código,
        // não entrada de usuário — e assim a condição fica idêntica à de reconciliar().
        $linha = Database::one(
            "SELECT
                 MAX(CASE WHEN b.kind IN ('temp','perma') THEN 1 ELSE 0 END) AS duro,
                 MAX(CASE WHEN b.kind NOT IN ('temp','perma') THEN 1 ELSE 0 END) AS leve
               FROM bans b
              WHERE b.user_id = :u AND " . self::VIGENTE,
            ['u' => $userId]
        ) ?? [];

        $status = (int)($linha['duro'] ?? 0) === 1 ? 'banned'
            : ((int)($linha['leve'] ?? 0) === 1 ? 'suspended' : 'active');

        // `status <> :atual` no WHERE para não gastar escrita (nem tocar a linha) à toa.
        // Os dois placeholders carregam o mesmo valor por exigência do PDO sem emulação.
        Database::run(
            'UPDATE users SET status = :novo WHERE id = :u AND status <> :atual',
            ['novo' => $status, 'atual' => $status, 'u' => $userId]
        );

        return $status;
    }

    /**
     * Passa a régua entre `bans` e `users.status` e devolve o que estava divergente.
     *
     * É isto que faz um ban temporário acabar: sem agendador no projeto, quem percebe que o
     * prazo venceu é a próxima abertura da tela. Também conserta o caminho inverso (conta
     * liberada com punição em vigor), que só aparece quando alguém mexe no banco por fora.
     *
     * O escopo é o JOIN, e ele é INTERNO de propósito: só entram contas que TÊM linha em
     * `bans`. Uma conta marcada como banida/suspensa sem nenhuma punição registrada não é
     * inconsistência desta tela — é estado que outra tela (ou o banco, na mão) escreveu, e
     * "consertar" isso aqui seria destravar contas que ninguém pediu para destravar. Como
     * efeito colateral, a varredura também não cresce com o número de jogadores.
     *
     * @return list<array{id:int,username:string,de:string,para:string}>
     */
    public static function reconciliar(): array
    {
        $divergentes = Database::all(
            "SELECT u.id, u.username, u.status AS atual,
                    CASE
                      WHEN MAX(CASE WHEN " . self::VIGENTE . "
                                     AND b.kind IN ('temp','perma') THEN 1 ELSE 0 END) = 1 THEN 'banned'
                      WHEN MAX(CASE WHEN " . self::VIGENTE . "
                                     AND b.kind NOT IN ('temp','perma') THEN 1 ELSE 0 END) = 1 THEN 'suspended'
                      ELSE 'active'
                    END AS desejado
               FROM users u
               JOIN bans b ON b.user_id = u.id
              GROUP BY u.id, u.username, u.status
             HAVING desejado <> atual"
        );

        $mudou = [];
        foreach ($divergentes as $d) {
            $id = (int)$d['id'];
            Database::run(
                'UPDATE users SET status = :s WHERE id = :u',
                ['s' => (string)$d['desejado'], 'u' => $id]
            );
            $mudou[] = [
                'id'       => $id,
                'username' => (string)$d['username'],
                'de'       => (string)$d['atual'],
                'para'     => (string)$d['desejado'],
            ];
        }
        return $mudou;
    }

    /* ===================================================================== apoio */

    /**
     * WHERE + parâmetros da listagem. Devolve sempre uma condição verdadeira como base para
     * que quem chama possa concatenar sem checar se a string está vazia.
     *
     * @param array<string,mixed> $f
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function filtro(array $f): array
    {
        $onde   = ['1=1'];
        $params = [];

        $busca = trim((string)($f['busca'] ?? ''));
        if ($busca !== '') {
            // `#12` procura o ban 12; o resto procura em conta, e-mail e motivo. LIKE com
            // curinga dos dois lados é aceitável aqui: a tabela de punições é pequena por
            // natureza e a alternativa (FULLTEXT) não acha "acr" dentro de "macro".
            if (preg_match('/^#(\d+)$/', $busca, $m) === 1) {
                $onde[] = 'b.id = :bid';
                $params['bid'] = (int)$m[1];
            } else {
                // Três placeholders para o MESMO valor: o PDO roda sem emulação e um nome
                // repetido depende de reescrita do driver — nomes distintos não dependem.
                $onde[] = '(u.username LIKE :q1 OR u.email LIKE :q2 OR b.reason LIKE :q3)';
                $curinga = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $busca) . '%';
                $params['q1'] = $params['q2'] = $params['q3'] = $curinga;
            }
        }

        $estado = (string)($f['estado'] ?? '');
        if (in_array($estado, self::ESTADOS, true)) {
            $onde[] = self::ESTADO . ' = :estado';
            $params['estado'] = $estado;
        }

        $tipo = (string)($f['tipo'] ?? '');
        if (isset(self::TIPOS[$tipo])) {
            $onde[] = 'b.kind = :tipo';
            $params['tipo'] = $tipo;
        }

        return [implode(' AND ', $onde), $params];
    }
}
