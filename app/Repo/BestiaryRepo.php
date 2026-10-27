<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * Bestiário por jogador: o catálogo (app/Data/criaturas.php) cruzado com `player_bestiary`.
 *
 * Nível de cada criatura para um jogador:
 *   desconhecido  nenhum registro
 *   avistado      o jogo reportou ao menos um avistamento ou abate
 *   estudado      amostra entregue ao Icarus, ou abates suficientes (ESTUDO_ABATES)
 *
 * Com `liberar_tudo` ligado no catálogo, todas aparecem como estudadas — é o modo de
 * apresentação do enredo enquanto o jogo ainda não reporta avistamentos. Os contadores
 * reais continuam sendo lidos e mostrados.
 *
 * Sem a tabela (patch v11 não aplicado) a tela abre igual, sem registros: o Bestiário é
 * leitura de enredo antes de ser progresso.
 */
final class BestiaryRepo
{
    /** Abates que contam como estudo, para quem não entrega amostra. */
    public const ESTUDO_ABATES = 5;

    /** Eventos que o jogo pode reportar. */
    public const EVENTOS = ['avistado', 'abate', 'amostra'];

    /** @return array<string,array{sightings:int,kills:int,studied:bool,first_seen_at:?string}> */
    public static function registros(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        try {
            $out = [];
            foreach (Database::all(
                'SELECT creature_slug, sightings, kills, studied, first_seen_at
                   FROM player_bestiary WHERE user_id = :u',
                ['u' => $userId]
            ) as $r) {
                $out[(string)$r['creature_slug']] = [
                    'sightings'     => (int)$r['sightings'],
                    'kills'         => (int)$r['kills'],
                    'studied'       => (bool)$r['studied'],
                    'first_seen_at' => $r['first_seen_at'] !== null ? (string)$r['first_seen_at'] : null,
                ];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array{sightings:int,kills:int,studied:bool}|null $r */
    public static function nivel(?array $r, bool $liberarTudo): string
    {
        if ($liberarTudo) {
            return 'estudado';
        }
        if ($r === null || (($r['sightings'] + $r['kills']) === 0 && !$r['studied'])) {
            return 'desconhecido';
        }
        return ($r['studied'] || $r['kills'] >= self::ESTUDO_ABATES) ? 'estudado' : 'avistado';
    }

    /**
     * Registra um evento de campo. Devolve false para criatura ou evento desconhecido —
     * o slug vem do jogo e é conferido contra o catálogo antes de virar linha.
     *
     * @param list<string> $slugsValidos
     */
    public static function registrar(int $userId, string $slug, string $evento, array $slugsValidos): bool
    {
        if ($userId <= 0 || !in_array($slug, $slugsValidos, true) || !in_array($evento, self::EVENTOS, true)) {
            return false;
        }
        $col = ['avistado' => 'sightings', 'abate' => 'kills', 'amostra' => 'studied'][$evento];
        $inc = $col === 'studied' ? '`studied` = 1' : "`$col` = `$col` + 1";
        Database::run(
            "INSERT INTO player_bestiary (user_id, creature_slug, sightings, kills, studied, first_seen_at)
             VALUES (:u, :s, :a, :k, :st, NOW())
             ON DUPLICATE KEY UPDATE $inc, first_seen_at = COALESCE(first_seen_at, NOW())",
            [
                'u'  => $userId,
                's'  => $slug,
                'a'  => $col === 'sightings' ? 1 : 0,
                'k'  => $col === 'kills' ? 1 : 0,
                'st' => $col === 'studied' ? 1 : 0,
            ]
        );
        return true;
    }
}
