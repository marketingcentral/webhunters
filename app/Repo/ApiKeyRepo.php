<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Api\Escopos;
use App\Core\Database;

/**
 * As chaves da API (`api_keys`): criar, autenticar, listar e revogar.
 *
 * O SEGREDO SÓ EXISTE UMA VEZ
 * ---------------------------
 * `criar()` é o ÚNICO método deste arquivo que devolve a chave em claro, e ela existe apenas
 * na memória daquela requisição: o que vai para o banco é o SHA-256 dela. Não há método para
 * "ver a chave de novo" — não por decisão de interface, mas porque o dado não está lá. Quem
 * perdeu a chave cria outra e revoga a antiga, e é assim que tem de ser: uma API capaz de
 * reexibir a credencial transforma o banco (e todo backup dele) em cofre de segredos vivos.
 *
 * COMO A CHAVE É LIDA
 * -------------------
 * Formato: `ehk<8 hex>_<48 hex>`. A primeira parte é o PREFIXO — público, indexado, único —
 * e é o que aparece na tela e no log. A segunda é o segredo, 192 bits de random_bytes.
 * Autenticar é: separar o prefixo, buscar UMA linha por ele e comparar o hash com
 * hash_equals. A comparação é em tempo constante porque comparar hash com `===` vaza, pelo
 * tempo de resposta, quantos caracteres iniciais bateram.
 *
 * O QUE ESTE REPO NÃO FAZ
 * -----------------------
 * Não decide se a chave pode fazer a operação (isso é escopo, e quem confere é o
 * despachante), não escreve no log de requisições (App\Repo\ApiLogRepo) e não audita
 * (AdminRepo::registrar, chamado por quem tem o id do administrador).
 */
final class ApiKeyRepo
{
    /** Linhas por página na tela do painel. */
    public const POR_PAGINA = 25;

    /** Limite de requisições por minuto quando a chave não define o seu (coluna = 0). */
    public const LIMITE_PADRAO = 120;

    /** Teto do limite configurável por chave. Acima disto não é integração, é enxurrada. */
    public const LIMITE_MAX = 6000;

    /** Marca do prefixo. Serve para reconhecer no olho que aquele texto é chave DESTA API. */
    private const MARCA = 'ehk';

    /** Situações derivadas (não existem como coluna: são calculadas de revoked_at/expires_at). */
    public const ESTADOS = [
        'ativa'    => 'Ativa',
        'revogada' => 'Revogada',
        'vencida'  => 'Vencida',
    ];

    // ===================================================================== criação

    /**
     * Cria a chave e devolve o SEGREDO — a única vez em que ele existe fora do cliente.
     *
     * @param array{name:string, scopes:list<string>, rate_limit_per_min:int, note:string, expires_at:?string} $d
     * @return array{id:int, chave:string, prefix:string}
     */
    public static function criar(array $d, int $adminId): array
    {
        $prefixo = self::prefixoInedito();
        $segredo = bin2hex(random_bytes(24));           // 192 bits
        $chave   = $prefixo . '_' . $segredo;

        $id = Database::insert('api_keys', [
            'name'               => mb_substr(trim($d['name']), 0, 80),
            'prefix'             => $prefixo,
            'key_hash'           => self::hash($chave),
            'scopes'             => json_encode(self::normalizarEscopos($d['scopes']), JSON_UNESCAPED_SLASHES),
            'rate_limit_per_min' => max(0, min(self::LIMITE_MAX, $d['rate_limit_per_min'])),
            'note'               => ($d['note'] ?? '') === '' ? null : mb_substr(trim((string)$d['note']), 0, 240),
            'created_by'         => $adminId > 0 ? $adminId : null,
            'expires_at'         => $d['expires_at'] ?? null,
        ]);

        return ['id' => $id, 'chave' => $chave, 'prefix' => $prefixo];
    }

    /**
     * Um prefixo que ainda não está na tabela.
     *
     * A coluna é UNIQUE, então a colisão daria erro de gravação — com 32 bits ela é
     * praticamente impossível, mas "praticamente impossível" vira "aconteceu" quando o custo
     * de conferir é uma consulta indexada. Dez tentativas e desiste: insistir para sempre
     * esconderia um banco quebrado atrás de um laço infinito.
     */
    private static function prefixoInedito(): string
    {
        for ($i = 0; $i < 10; $i++) {
            $tentativa = self::MARCA . bin2hex(random_bytes(4));
            $existe = (int)Database::value('SELECT COUNT(*) FROM api_keys WHERE prefix = :p', ['p' => $tentativa]);
            if ($existe === 0) {
                return $tentativa;
            }
        }
        throw new \RuntimeException('Não foi possível gerar um prefixo inédito para a chave.');
    }

    public static function hash(string $chave): string
    {
        return hash('sha256', $chave);
    }

    // ===================================================================== autenticação

    /**
     * Confere a chave apresentada.
     *
     * Devolve SEMPRE a mesma forma: ['ok' => bool, 'motivo' => string, 'chave' => ?linha].
     * O `motivo` é um código de App\Core\Api\Resposta — quem chama não inventa texto.
     *
     * Chave desconhecida, prefixo malformado e hash que não bate devolvem TODOS
     * `chave_invalida`: dizer "este prefixo existe, mas o segredo está errado" entregaria de
     * graça a metade pública da credencial a quem está sondando.
     *
     * Já revogada e vencida são distinguidas de propósito — quem chega até aqui provou ter a
     * chave inteira, então não há o que esconder, e a diferença é exatamente o que o
     * integrador precisa para saber se liga para o suporte ou renova o contrato.
     *
     * @return array{ok:bool, motivo:string, chave:array<string,mixed>|null}
     */
    public static function autenticar(string $apresentada): array
    {
        $negar = static fn(string $motivo): array => ['ok' => false, 'motivo' => $motivo, 'chave' => null];

        if ($apresentada === '') {
            return $negar('chave_ausente');
        }
        // Formato conferido ANTES de tocar no banco: uma chave malformada não merece consulta.
        if (!preg_match('/^(' . self::MARCA . '[0-9a-f]{8})_[0-9a-f]{48}$/', $apresentada, $m)) {
            return $negar('chave_invalida');
        }

        $linha = Database::one(
            'SELECT id, name, prefix, key_hash, scopes, rate_limit_per_min, created_by,
                    created_at, last_used_at, uses, expires_at, revoked_at
               FROM api_keys WHERE prefix = :p LIMIT 1',
            ['p' => $m[1]]
        );
        if ($linha === null) {
            return $negar('chave_invalida');
        }
        if (!hash_equals((string)$linha['key_hash'], self::hash($apresentada))) {
            return $negar('chave_invalida');
        }

        // A partir daqui a posse da chave está provada. A ordem importa: revogada vence
        // vencida, porque revogar é um ato deliberado e é o que o operador precisa ver.
        if ($linha['revoked_at'] !== null) {
            return $negar('chave_revogada');
        }
        if ($linha['expires_at'] !== null && strtotime((string)$linha['expires_at']) < time()) {
            return $negar('chave_expirada');
        }

        // O hash não sobe para a camada de cima: o que não circula não vaza num dump de erro.
        unset($linha['key_hash']);
        $linha['scopes'] = self::lerEscopos($linha['scopes'] ?? null);

        return ['ok' => true, 'motivo' => '', 'chave' => $linha];
    }

    /**
     * Carimba o uso. Chamado DEPOIS de a requisição ser atendida, e nunca lança: falhar em
     * anotar "último uso" não pode transformar uma resposta 200 em 500.
     */
    public static function marcarUso(int $id, string $ip): void
    {
        if ($id <= 0) {
            return;
        }
        try {
            Database::run(
                'UPDATE api_keys SET last_used_at = NOW(), last_ip = :ip, uses = uses + 1 WHERE id = :id',
                ['ip' => mb_substr($ip, 0, 45), 'id' => $id]
            );
        } catch (\Throwable $e) {
            error_log('[extracthunters] api_keys uso: ' . $e->getMessage());
        }
    }

    /** O limite por minuto que vale para esta chave (0 na coluna = padrão do código). */
    public static function limiteDe(array $chave): int
    {
        $proprio = (int)($chave['rate_limit_per_min'] ?? 0);
        return $proprio > 0 ? $proprio : self::LIMITE_PADRAO;
    }

    // ===================================================================== leitura

    /**
     * Página da lista de chaves para o painel.
     *
     * @return array{linhas:list<array<string,mixed>>, total:int, pagina:int, paginas:int, falha:bool}
     */
    public static function pagina(int $pagina = 1, string $estado = ''): array
    {
        $onde = match ($estado) {
            'ativa'    => 'k.revoked_at IS NULL AND (k.expires_at IS NULL OR k.expires_at > NOW())',
            'revogada' => 'k.revoked_at IS NOT NULL',
            'vencida'  => 'k.revoked_at IS NULL AND k.expires_at IS NOT NULL AND k.expires_at <= NOW()',
            default    => '1',
        };

        try {
            $total   = (int)Database::value('SELECT COUNT(*) FROM api_keys k WHERE ' . $onde);
            $paginas = max(1, (int)ceil($total / self::POR_PAGINA));
            $pagina  = min(max(1, $pagina), $paginas);
            $offset  = ($pagina - 1) * self::POR_PAGINA;

            // LIMIT/OFFSET como literal: o PDO roda sem emulação e o MySQL não aceita
            // placeholder nessa posição. Os dois são int calculados aqui, nunca texto da URL.
            $linhas = Database::all(
                'SELECT k.id, k.name, k.prefix, k.scopes, k.rate_limit_per_min, k.note,
                        k.created_at, k.created_by, k.last_used_at, k.last_ip, k.uses,
                        k.expires_at, k.revoked_at, k.revoked_by,
                        u.username AS criador, r.username AS revogador
                   FROM api_keys k
              LEFT JOIN users u ON u.id = k.created_by
              LEFT JOIN users r ON r.id = k.revoked_by
                  WHERE ' . $onde . '
               ORDER BY k.revoked_at IS NOT NULL ASC, k.id DESC
                  LIMIT ' . self::POR_PAGINA . ' OFFSET ' . $offset
            );

            return [
                'linhas'  => array_map(self::mapear(...), $linhas),
                'total'   => $total,
                'pagina'  => $pagina,
                'paginas' => $paginas,
                'falha'   => false,
            ];
        } catch (\Throwable $e) {
            // Igual ao AuditRepo: "não consegui ler" e "não existe nada" são fatos
            // diferentes, e a tela escreve uma frase para cada um.
            error_log('[extracthunters] api_keys lista: ' . $e->getMessage());
            return ['linhas' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1, 'falha' => true];
        }
    }

    /** @return array<string,mixed>|null */
    public static function porId(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $linha = Database::one(
            'SELECT k.*, u.username AS criador, r.username AS revogador
               FROM api_keys k
          LEFT JOIN users u ON u.id = k.created_by
          LEFT JOIN users r ON r.id = k.revoked_by
              WHERE k.id = :id LIMIT 1',
            ['id' => $id]
        );
        if ($linha === null) {
            return null;
        }
        unset($linha['key_hash']);
        return self::mapear($linha);
    }

    /** @return array{ativas:int, revogadas:int, vencidas:int, total:int} */
    public static function contagens(): array
    {
        try {
            $l = Database::one(
                "SELECT COUNT(*) AS total,
                        SUM(revoked_at IS NOT NULL) AS revogadas,
                        SUM(revoked_at IS NULL AND expires_at IS NOT NULL AND expires_at <= NOW()) AS vencidas,
                        SUM(revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())) AS ativas
                   FROM api_keys"
            ) ?? [];
        } catch (\Throwable $e) {
            error_log('[extracthunters] api_keys contagens: ' . $e->getMessage());
            $l = [];
        }
        return [
            'total'     => (int)($l['total'] ?? 0),
            'ativas'    => (int)($l['ativas'] ?? 0),
            'revogadas' => (int)($l['revogadas'] ?? 0),
            'vencidas'  => (int)($l['vencidas'] ?? 0),
        ];
    }

    // ===================================================================== escrita

    /**
     * Revoga. Devolve false quando já estava revogada — a condição está no próprio UPDATE
     * para que dois operadores clicando ao mesmo tempo não sobrescrevam a assinatura de quem
     * chegou primeiro.
     *
     * A linha NÃO é apagada: o log de requisições aponta para ela, e uma chave que some leva
     * junto a explicação do que ela fez enquanto existia.
     */
    public static function revogar(int $id, int $adminId): bool
    {
        if ($id <= 0) {
            return false;
        }
        $stmt = Database::run(
            'UPDATE api_keys SET revoked_at = NOW(), revoked_by = :by WHERE id = :id AND revoked_at IS NULL',
            ['by' => $adminId > 0 ? $adminId : null, 'id' => $id]
        );
        return $stmt->rowCount() > 0;
    }

    // ===================================================================== validação

    /**
     * Mantém só os escopos que EXISTEM no catálogo, sem repetição e na ordem do catálogo.
     *
     * A ordem canônica não é estética: ela faz duas chaves com as mesmas permissões
     * guardarem exatamente o mesmo JSON, o que deixa comparar e auditar sem normalizar de novo.
     *
     * @param list<string>|mixed $bruto
     * @return list<string>
     */
    public static function normalizarEscopos(mixed $bruto): array
    {
        if (!is_array($bruto)) {
            return [];
        }
        $pedidos = [];
        foreach ($bruto as $v) {
            if (is_string($v)) {
                $pedidos[strtolower(trim($v))] = true;
            }
        }
        return array_values(array_filter(
            Escopos::todos(),
            static fn(string $e): bool => isset($pedidos[$e])
        ));
    }

    /**
     * Valida o formulário de criação.
     *
     * @param array<string,mixed> $bruto
     * @return array{ok:bool, erros:array<string,string>, dados:array<string,mixed>}
     */
    public static function validarNova(array $bruto): array
    {
        $erros = [];

        $nome = trim((string)($bruto['name'] ?? ''));
        if ($nome === '') {
            $erros['name'] = 'Dê um nome à chave — é por ele que você vai reconhecê-la daqui a seis meses.';
        } elseif (mb_strlen($nome) > 80) {
            $erros['name'] = 'O nome não pode passar de 80 caracteres.';
        }

        $escopos = self::normalizarEscopos($bruto['scopes'] ?? []);
        if ($escopos === []) {
            // Chave sem escopo não é "chave restrita", é chave inútil: ela seria autenticada
            // e recusada em todo endpoint. Melhor recusar a criação do que entregar ao
            // operador uma credencial que só produz 403.
            $erros['scopes'] = 'Marque pelo menos um escopo: uma chave sem escopo não consegue chamar nada.';
        }

        $limite = trim((string)($bruto['rate_limit_per_min'] ?? ''));
        if ($limite === '') {
            $limite = 0;
        } elseif (!preg_match('/^\d{1,5}$/', $limite)) {
            $erros['rate_limit_per_min'] = 'O limite por minuto tem de ser um número inteiro.';
            $limite = 0;
        } else {
            $limite = (int)$limite;
            if ($limite > self::LIMITE_MAX) {
                $erros['rate_limit_per_min'] = 'O limite não pode passar de ' . self::LIMITE_MAX . ' por minuto.';
            }
        }

        $expira = trim((string)($bruto['expires_at'] ?? ''));
        $expiraSql = null;
        if ($expira !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $expira);
            if ($d === false || $d->format('Y-m-d') !== $expira) {
                $erros['expires_at'] = 'Use uma data no formato AAAA-MM-DD.';
            } elseif ($d->format('Y-m-d') < date('Y-m-d')) {
                $erros['expires_at'] = 'A data de vencimento já passou.';
            } else {
                // Fim do dia: quem digita 31/12 espera que a chave funcione durante o dia 31.
                $expiraSql = $d->format('Y-m-d') . ' 23:59:59';
            }
        }

        return [
            'ok'    => $erros === [],
            'erros' => $erros,
            'dados' => [
                'name'               => $nome,
                'scopes'             => $escopos,
                'rate_limit_per_min' => (int)$limite,
                'note'               => mb_substr(trim((string)($bruto['note'] ?? '')), 0, 240),
                'expires_at'         => $expiraSql,
            ],
        ];
    }

    // ===================================================================== apoio

    /** @param string|null $bruto @return list<string> */
    public static function lerEscopos(mixed $bruto): array
    {
        if (!is_string($bruto) || $bruto === '') {
            return [];
        }
        $lista = json_decode($bruto, true);
        return self::normalizarEscopos(is_array($lista) ? $lista : []);
    }

    /**
     * Linha do banco -> linha da tela. A view não decodifica JSON e não calcula estado.
     *
     * @param array<string,mixed> $l
     * @return array<string,mixed>
     */
    private static function mapear(array $l): array
    {
        $revogada = $l['revoked_at'] !== null;
        $vencida  = !$revogada && $l['expires_at'] !== null && strtotime((string)$l['expires_at']) < time();

        return [
            'id'        => (int)$l['id'],
            'nome'      => (string)$l['name'],
            'prefixo'   => (string)$l['prefix'],
            'escopos'   => self::lerEscopos($l['scopes'] ?? null),
            'limite'    => self::limiteDe($l),
            'limite_proprio' => (int)($l['rate_limit_per_min'] ?? 0) > 0,
            'nota'      => (string)($l['note'] ?? ''),
            'criada_em' => (string)$l['created_at'],
            'criador'   => (string)($l['criador'] ?? ''),
            'usada_em'  => (string)($l['last_used_at'] ?? ''),
            'ultimo_ip' => (string)($l['last_ip'] ?? ''),
            'usos'      => (int)($l['uses'] ?? 0),
            'expira_em' => (string)($l['expires_at'] ?? ''),
            'revogada_em' => (string)($l['revoked_at'] ?? ''),
            'revogador' => (string)($l['revogador'] ?? ''),
            'estado'    => $revogada ? 'revogada' : ($vencida ? 'vencida' : 'ativa'),
        ];
    }
}
