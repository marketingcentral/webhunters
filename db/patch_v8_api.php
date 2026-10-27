<?php declare(strict_types=1);

/**
 * Patch ADITIVO v8 — API DE GESTÃO DO JOGO (esquema).
 *
 *   php db/patch_v8_api.php              aplica no banco local
 *   php db/patch_v8_api.php --dry-run    só mostra o que faria
 *   php db/patch_v8_api.php --sql        NÃO toca no banco: escreve db/backups/patch_v8_<data>.sql
 *                                          para importar pelo phpMyAdmin do host
 *
 * Mesmo contrato dos patches v3..v7: ADITIVO e IDEMPOTENTE. Não apaga nada, não altera
 * coluna existente e pode rodar quantas vezes quiser. `db/migrate.php` e `db/seed.php`
 * continuam PROIBIDOS em banco com conta real (db/guard.php).
 *
 * O que cria:
 *   1. `api_keys`        — as credenciais da API. A chave em claro NUNCA é gravada.
 *   2. `api_request_log` — uma linha por requisição atendida; é o que a tela /admin/api
 *                          mostra e é também o CONTADOR do limite por minuto.
 *
 * POR QUE SÓ O HASH
 * -----------------
 * A chave é mostrada UMA vez, na criação, e some. O banco guarda `key_hash`
 * (SHA-256 da chave inteira) e `prefix` (o pedaço público, que identifica a linha na tela).
 * Assim um dump do banco não entrega acesso à API — do hash não se volta para a chave.
 * O SHA-256 cru é suficiente AQUI, e não seria numa senha de usuário: a chave tem 40
 * caracteres de aleatoriedade real (160 bits), então não existe dicionário nem força bruta
 * viável; o custo artificial do bcrypt existe para proteger segredo ESCOLHIDO POR HUMANO.
 * Além disso a verificação acontece a cada requisição de API — um bcrypt por chamada seria
 * um limitador de vazão involuntário.
 *
 * POR QUE O `prefix` É ÚNICO
 * --------------------------
 * A busca da chave é feita PELO PREFIXO (indexado), e só então o hash é comparado com
 * hash_equals. Sem isso, autenticar exigiria varrer a tabela inteira comparando hashes a
 * cada chamada.
 *
 * SEM FOREIGN KEY em `created_by`/`revoked_by`/`key_id`, pelo mesmo motivo de
 * db/patch_v6_admin.php: apagar a conta (ou a chave) não pode apagar a prova do que ela
 * fez. O log de requisições sobrevive à revogação da chave que o gerou.
 */

require __DIR__ . '/cli_only.php';
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$DRY = in_array('--dry-run', $argv, true);
$SQL = in_array('--sql', $argv, true);

/** Mesmo executor dos patches anteriores: dois destinos — o banco ou um .sql idempotente. */
final class PatchApi
{
    /** @var list<string> */
    public array $sql = [];
    public int $feitos = 0;

    public function __construct(private bool $dry, private bool $toSql) {}

    private function log(string $m): void
    {
        echo ($this->dry ? '[dry] ' : ($this->toSql ? '[sql] ' : '')) . $m . "\n";
    }

    public function createTable(string $name, string $ddl): void
    {
        $stmt = "CREATE TABLE IF NOT EXISTS `$name` (\n$ddl\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if ($this->toSql) {
            $this->log("tabela $name (se não existir)");
            $this->sql[] = $stmt . ';';
            return;
        }
        $existe = Database::value(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
            ['t' => $name]
        );
        if ((int)$existe > 0) {
            $this->log("tabela $name já existe");
            return;
        }
        $this->log("+ tabela $name");
        $this->feitos++;
        if (!$this->dry) {
            Database::run($stmt);
        }
    }

    /** ALTER TABLE não tem IF NOT EXISTS no MySQL: em .sql sai guardado por information_schema. */
    public function addColumn(string $table, string $col, string $ddl): void
    {
        if ($this->toSql) {
            $this->log("$table.$col (se não existir)");
            $alter = "'" . str_replace("'", "\\'", "ALTER TABLE `$table` ADD COLUMN `$col` $ddl") . "'";
            $this->sql[] = "SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND COLUMN_NAME = '$col');";
            $this->sql[] = "SET @s = IF(@c = 0, $alter, 'SELECT 1');";
            $this->sql[] = 'PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;';
            return;
        }
        $existe = Database::value(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => $table, 'c' => $col]
        );
        if ((int)$existe > 0) {
            return;
        }
        $this->log("$table: + coluna $col");
        $this->feitos++;
        if (!$this->dry) {
            Database::run("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
        }
    }
}

$p = new PatchApi($DRY, $SQL);

echo "=== Patch v8 — API de gestão ===\n\n";

// ==================================================== 1) api_keys
/**
 * `scopes` é JSON (lista de strings tipo "players:read"), e não uma tabela de ligação, porque
 * escopo não é entidade: ele não tem vida própria, não é consultado isoladamente e o catálogo
 * válido mora no código (App\Core\Api\Escopos). Uma tabela aqui só criaria dois lugares para
 * a mesma verdade — e um JOIN por requisição de API.
 *
 * `rate_limit_per_min` por CHAVE (e não global) porque a integração do jogo e um script de
 * relatório não têm o mesmo apetite; 0 = usa o padrão do código.
 *
 * `expires_at` e `revoked_at` são coisas DIFERENTES e as duas existem de propósito: vencer é
 * planejado (chave de fornecedor com prazo), revogar é reação (vazou). A tela mostra os dois
 * estados com nomes diferentes porque a conduta depois de cada um é diferente.
 */
$p->createTable('api_keys', <<<'SQL'
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `prefix` VARCHAR(24) NOT NULL,
  `key_hash` CHAR(64) NOT NULL,
  `scopes` JSON NULL,
  `rate_limit_per_min` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `note` VARCHAR(240) NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` DATETIME NULL,
  `last_ip` VARCHAR(45) NULL,
  `uses` INT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME NULL,
  `revoked_at` DATETIME NULL,
  `revoked_by` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_keys_prefix` (`prefix`),
  KEY `ix_api_keys_estado` (`revoked_at`, `expires_at`)
SQL);

// ==================================================== 2) api_request_log
/**
 * UMA LINHA POR REQUISIÇÃO, inclusive as recusadas (401/403/429) — são justamente essas que
 * contam a história quando uma chave vaza. `key_id` é NULL quando a chamada nem chegou a ter
 * chave reconhecida (401), e `key_prefix` guarda o que foi APRESENTADO: um prefixo inválido
 * na coluna é o rastro de quem está tentando adivinhar.
 *
 * Esta tabela é também o CONTADOR do limite por minuto (ver App\Repo\ApiLogRepo::noMinuto):
 * a janela deslizante é medida contando as linhas dos últimos 60 s pelo índice
 * (key_id, created_at). Isso troca "um contador em memória" por "uma consulta indexada", e a
 * troca vale: o contador em memória morre a cada reinício do PHP e não existe entre dois
 * processos do FastCGI — ou seja, seria um limite que não limita. Consequência assumida: quem
 * for expurgar este log precisa preservar pelo menos os últimos 60 segundos.
 *
 * `path` guarda só o CAMINHO. A querystring fica de fora de propósito: filtro de relatório é
 * inofensivo, mas basta um integrador mandar um dado sensível por engano na URL para o log
 * virar o vazamento. Nada que o cliente escreve na URL é copiado para cá além da rota.
 */
$p->createTable('api_request_log', <<<'SQL'
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key_id` INT UNSIGNED NULL,
  `key_prefix` VARCHAR(24) NULL,
  `method` VARCHAR(8) NOT NULL,
  `path` VARCHAR(191) NOT NULL,
  `status` SMALLINT UNSIGNED NOT NULL,
  `error_code` VARCHAR(40) NULL,
  `ip` VARCHAR(45) NULL,
  `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_apilog_key_janela` (`key_id`, `created_at`),
  KEY `ix_apilog_created` (`created_at`),
  KEY `ix_apilog_status` (`status`, `created_at`)
SQL);

// ==================================================== fim
if ($SQL) {
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $arq = $dir . '/patch_v8_' . date('Y-m-d_His') . '.sql';
    file_put_contents(
        $arq,
        "-- Extract Hunters — patch v8 (API de gestão). Idempotente: pode importar mais de uma vez.\n"
        . '-- Gerado por db/patch_v8_api.php em ' . date('c') . "\n\n"
        . implode("\n", $p->sql) . "\n"
    );
    echo "\n" . count($p->sql) . " instruções em $arq\n";
    return;
}

echo "\nPatch v8 concluído — {$p->feitos} passo(s).\n";

if (!$DRY) {
    // Conferência: rodar de novo tem de imprimir exatamente estes mesmos números.
    printf(
        "api_keys: %d chave(s) | api_request_log: %d registro(s)\n",
        (int)Database::value('SELECT COUNT(*) FROM api_keys'),
        (int)Database::value('SELECT COUNT(*) FROM api_request_log')
    );
}
