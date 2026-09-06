<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * Lista social do jogador (tela AMIGOS), conciliada por user_id.
 *
 * Leitura: `friends` (uma linha por vínculo: amigo, pedido recebido ou pedido enviado) e
 * `squad_history` (com quem o jogador jogou). Os rótulos "HÁ 12 MIN", "128 PARTIDAS" e
 * "Visto há 2 horas" são DERIVADOS de last_seen/matches — nada de texto congelado.
 *
 * Mutações: invite/accept/decline/cancel devolvem [tipo_de_flash, mensagem] para o controller.
 */
final class FriendsRepo
{
    public const REL_FRIEND   = 'amigo';
    public const REL_INCOMING = 'pedido_recebido';
    public const REL_OUTGOING = 'pedido_enviado';

    /** Presenças válidas (a coluna aceita qualquer string; a view só conhece estas). */
    private const PRESENCES = ['ingame', 'lobby', 'online', 'offline'];

    /**
     * Tudo o que a view precisa, já separado por aba.
     * @return array{online:list<array<string,mixed>>,offline:list<array<string,mixed>>,pending:list<array<string,mixed>>,squad:list<array<string,mixed>>,counts:array{online:int,offline:int,pending:int,total:int}}
     */
    public static function forUser(int $userId): array
    {
        // as idades saem do próprio MySQL (TIMESTAMPDIFF) para não depender do fuso do PHP
        $rows = $userId > 0 ? Database::all(
            'SELECT f.*,
                    GREATEST(TIMESTAMPDIFF(MINUTE, f.last_seen, NOW()), 0)  AS seen_min,
                    GREATEST(TIMESTAMPDIFF(MINUTE, f.created_at, NOW()), 0) AS age_min
             FROM friends f WHERE f.user_id = :u ORDER BY f.sort_order, f.name',
            ['u' => $userId]
        ) : [];

        $online = $offline = $pending = [];
        foreach ($rows as $r) {
            $relation = (string)$r['relation'];
            if ($relation === self::REL_FRIEND) {
                $card = self::friendCard($r);
                if ($card['status'] === 'offline') {
                    $offline[] = $card;
                } else {
                    $online[] = $card;
                }
                continue;
            }
            $pending[] = self::pendingCard($r);
        }

        // pedidos recebidos primeiro, depois os enviados; dentro do grupo, mais recentes antes
        usort($pending, static function (array $a, array $b): int {
            return [$a['direction'] === 'in' ? 0 : 1, $a['age_min']] <=> [$b['direction'] === 'in' ? 0 : 1, $b['age_min']];
        });

        $squad = [];
        foreach ($userId > 0 ? Database::all(
            'SELECT s.*, GREATEST(TIMESTAMPDIFF(MINUTE, s.played_at, NOW()), 0) AS ago_min
             FROM squad_history s WHERE s.user_id = :u ORDER BY s.played_at DESC, s.sort_order LIMIT 8',
            ['u' => $userId]
        ) : [] as $s) {
            $ok = (string)$s['result'] === 'extraido';
            $squad[] = [
                'name'   => (string)$s['mate_name'],
                'avatar' => (string)($s['avatar'] ?? 'portraits/op-assault.jpg'),
                'map'    => mb_strtoupper((string)$s['map_name']),
                'result' => $ok ? 'EXTRAÍDO' : 'ABATIDO',
                'ok'     => $ok,
                'when'   => self::agoLabel((int)($s['ago_min'] ?? 0)),
            ];
        }

        return [
            'online'  => $online,
            'offline' => $offline,
            'pending' => $pending,
            'squad'   => $squad,
            'counts'  => [
                'online'  => count($online),
                'offline' => count($offline),
                'pending' => count($pending),
                'total'   => count($online) + count($offline),
            ],
        ];
    }

    // ------------------------------------------------------------------ mutações

    /**
     * Envia convite. Se já existe um pedido recebido do mesmo jogador, a amizade é firmada.
     * @return array{0:string,1:string} [chave de flash, mensagem]
     */
    public static function invite(int $userId, string $name, string $selfName = ''): array
    {
        $name = self::cleanName($name);
        if ($userId <= 0) {
            return ['error', 'Não foi possível enviar o convite agora.'];
        }
        if ($name === '') {
            return ['error', 'Informe o nome de usuário ou o ID do jogador.'];
        }
        if ($selfName !== '' && mb_strtolower($name) === mb_strtolower($selfName)) {
            return ['error', 'Você não pode adicionar a si mesmo.'];
        }

        $row = self::find($userId, $name);
        if ($row !== null) {
            return match ((string)$row['relation']) {
                self::REL_FRIEND   => ['error', $row['name'] . ' já está na sua lista de amigos.'],
                self::REL_OUTGOING => ['error', 'Você já enviou um convite para ' . $row['name'] . '.'],
                default            => self::accept($userId, (string)$row['name']),
            };
        }

        // last_seen/created_at pelo relógio do banco (NOW()), igual ao cálculo das idades
        Database::run(
            'INSERT INTO friends (user_id, name, level, class, class_icon, avatar, relation, presence,
                                  activity, matches, last_seen, created_at, sort_order)
             VALUES (:u, :n, 1, "RECRUTA", "class-assault", "portraits/op-assault.jpg", :r, "offline",
                     "Convite enviado por você", 0, NOW(), NOW(), 900)',
            ['u' => $userId, 'n' => $name, 'r' => self::REL_OUTGOING]
        );
        return ['ok', 'Convite enviado para ' . $name . '.'];
    }

    /** Aceita um pedido recebido: vira amizade e entra na aba ONLINE. */
    public static function accept(int $userId, string $name): array
    {
        $row = self::find($userId, $name);
        if ($row === null || (string)$row['relation'] !== self::REL_INCOMING) {
            return ['error', 'Este pedido não está mais pendente.'];
        }
        Database::run(
            'UPDATE friends SET relation = :r, presence = "online", activity = "Menu principal",
                    party_slots = NULL, last_seen = NOW() WHERE id = :id',
            ['r' => self::REL_FRIEND, 'id' => (int)$row['id']]
        );
        return ['ok', 'Pedido de ' . $row['name'] . ' aceito. Vocês agora são amigos.'];
    }

    /** Recusa um pedido recebido (remove o vínculo). */
    public static function decline(int $userId, string $name): array
    {
        $row = self::find($userId, $name);
        if ($row === null || (string)$row['relation'] !== self::REL_INCOMING) {
            return ['error', 'Este pedido não está mais pendente.'];
        }
        Database::run('DELETE FROM friends WHERE id = :id', ['id' => (int)$row['id']]);
        return ['ok', 'Pedido de ' . $row['name'] . ' recusado.'];
    }

    /** Cancela um convite que o jogador enviou. */
    public static function cancel(int $userId, string $name): array
    {
        $row = self::find($userId, $name);
        if ($row === null || (string)$row['relation'] !== self::REL_OUTGOING) {
            return ['error', 'Este convite não está mais pendente.'];
        }
        Database::run('DELETE FROM friends WHERE id = :id', ['id' => (int)$row['id']]);
        return ['ok', 'Convite para ' . $row['name'] . ' cancelado.'];
    }

    // ------------------------------------------------------------------ internos

    /** @return array<string,mixed>|null */
    private static function find(int $userId, string $name): ?array
    {
        $name = self::cleanName($name);
        if ($userId <= 0 || $name === '') {
            return null;
        }
        return Database::one('SELECT * FROM friends WHERE user_id = :u AND name = :n', ['u' => $userId, 'n' => $name]);
    }

    public static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        return mb_substr($name, 0, 40);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function friendCard(array $r): array
    {
        $status  = in_array((string)$r['presence'], self::PRESENCES, true) ? (string)$r['presence'] : 'offline';
        $minutes = (int)($r['seen_min'] ?? 0);
        $matches = (int)$r['matches'];

        if ($status === 'offline') {
            $activity = 'Visto ' . mb_strtolower(self::seenLabel($minutes));
            $meta     = $matches > 0 ? $matches . ' PARTIDAS' : 'SEM PARTIDAS';
        } elseif ($status === 'lobby') {
            $activity = (string)($r['activity'] ?? '');
            $meta     = (string)($r['party_slots'] ?? '') !== '' ? (string)$r['party_slots'] : self::agoLabel($minutes);
        } else {
            $activity = (string)($r['activity'] ?? '');
            $meta     = self::agoLabel($minutes);
        }

        return [
            'name'       => (string)$r['name'],
            'level'      => (int)$r['level'],
            'class'      => (string)($r['class'] ?? ''),
            'class_icon' => (string)($r['class_icon'] ?? 'class-assault'),
            'avatar'     => (string)($r['avatar'] ?? 'portraits/op-assault.jpg'),
            'status'     => $status,
            'activity'   => $activity,
            'meta'       => $meta,
        ];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function pendingCard(array $r): array
    {
        $in      = (string)$r['relation'] === self::REL_INCOMING;
        $minutes = (int)($r['age_min'] ?? 0);
        return [
            'name'       => (string)$r['name'],
            'level'      => (int)$r['level'],
            'class'      => (string)($r['class'] ?? ''),
            'class_icon' => (string)($r['class_icon'] ?? 'class-assault'),
            'avatar'     => (string)($r['avatar'] ?? 'portraits/op-assault.jpg'),
            'direction'  => $in ? 'in' : 'out',
            'activity'   => (string)($r['activity'] ?? ''),
            'meta'       => self::agoLabel($minutes),
            'age_min'    => $minutes,
        ];
    }

    /** "AGORA" | "HÁ 12 MIN" | "HÁ 5 H" | "ONTEM" | "HÁ 3 DIAS" */
    private static function agoLabel(int $minutes): string
    {
        if ($minutes < 1) {
            return 'AGORA';
        }
        if ($minutes < 60) {
            return 'HÁ ' . $minutes . ' MIN';
        }
        if ($minutes < 1440) {
            return 'HÁ ' . (int)floor($minutes / 60) . ' H';
        }
        $days = (int)floor($minutes / 1440);
        return $days === 1 ? 'ONTEM' : 'HÁ ' . $days . ' DIAS';
    }

    /** "Agora" | "Há 12 minutos" | "Há 5 horas" | "Ontem" | "Há 3 dias" (para o texto de atividade) */
    private static function seenLabel(int $minutes): string
    {
        if ($minutes < 1) {
            return 'Agora';
        }
        if ($minutes < 60) {
            return 'há ' . $minutes . ($minutes === 1 ? ' minuto' : ' minutos');
        }
        if ($minutes < 1440) {
            $h = (int)floor($minutes / 60);
            return 'há ' . $h . ($h === 1 ? ' hora' : ' horas');
        }
        $days = (int)floor($minutes / 1440);
        return $days === 1 ? 'ontem' : 'há ' . $days . ' dias';
    }
}
