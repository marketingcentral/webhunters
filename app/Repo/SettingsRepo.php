<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * Preferências da tela CONFIGURAÇÕES, conciliadas por user_id.
 *
 * O catálogo (quais opções existem, tipos, rótulos e padrões) vive em app/Data/configuracoes.php —
 * é igual para todos os jogadores. O VALOR de cada opção é por jogador e mora em `player_settings`.
 * A tabela é criada por `db/schema.sql`, com FK para `users` (ON DELETE CASCADE): apagar o
 * jogador leva junto as preferências dele, em vez de deixá-las para o próximo mesmo id.
 */
final class SettingsRepo
{
    private const TABLE = 'player_settings';

    private static ?bool $ready = null;
    /** @var array<int, array<string,string>> */
    private static array $cache = [];

    /** O banco está disponível? Sem ele a tela cai nos padrões do catálogo. */
    public static function dbReady(): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }
        try {
            return self::$ready = Database::ready();
        } catch (\Throwable) {
            return self::$ready = false;
        }
    }

    /**
     * Todas as preferências gravadas do jogador.
     * @return array<string,string>
     */
    public static function forUser(int $uid): array
    {
        if ($uid <= 0 || !self::dbReady()) {
            return [];
        }
        if (isset(self::$cache[$uid])) {
            return self::$cache[$uid];
        }
        try {
            $rows = Database::all(
                'SELECT setting_key, value FROM ' . self::TABLE . ' WHERE user_id = :u',
                ['u' => $uid]
            );
        } catch (\Throwable) {
            return self::$cache[$uid] = [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(string)$r['setting_key']] = (string)$r['value'];
        }
        return self::$cache[$uid] = $out;
    }

    /**
     * Grava (upsert) um conjunto de preferências. Devolve quantas foram persistidas.
     * @param array<string,string|int|float|bool> $values
     */
    public static function save(int $uid, array $values): int
    {
        if ($uid <= 0 || $values === [] || !self::dbReady()) {
            return 0;
        }
        try {
            return (int)Database::transaction(static function () use ($uid, $values): int {
                $sql = 'INSERT INTO ' . self::TABLE . ' (user_id, setting_key, value) VALUES (:u, :k, :v)
                        ON DUPLICATE KEY UPDATE value = VALUES(value)';
                $n = 0;
                foreach ($values as $key => $value) {
                    Database::run($sql, [
                        'u' => $uid,
                        'k' => mb_substr((string)$key, 0, 64),
                        'v' => mb_substr(is_bool($value) ? ($value ? '1' : '0') : (string)$value, 0, 160),
                    ]);
                    $n++;
                }
                return $n;
            });
        } catch (\Throwable) {
            return 0;
        } finally {
            unset(self::$cache[$uid]);
        }
    }

    /**
     * Apaga as preferências informadas (volta aos padrões do catálogo). Devolve quantas linhas caíram.
     * @param string[] $keys
     */
    public static function reset(int $uid, array $keys): int
    {
        $keys = array_values(array_unique(array_filter($keys, static fn ($k): bool => (string)$k !== '')));
        if ($uid <= 0 || $keys === [] || !self::dbReady()) {
            return 0;
        }
        try {
            $in = implode(',', array_fill(0, count($keys), '?'));
            $st = Database::run(
                'DELETE FROM ' . self::TABLE . ' WHERE user_id = ? AND setting_key IN (' . $in . ')',
                array_merge([$uid], $keys)
            );
            return $st->rowCount();
        } catch (\Throwable) {
            return 0;
        } finally {
            unset(self::$cache[$uid]);
        }
    }
}
