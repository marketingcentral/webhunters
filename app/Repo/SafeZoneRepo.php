<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;
use App\Core\GameRules;

/**
 * SAFE ZONE — perímetro protegido e papéis civis.
 *
 * A Safe Zone é o outro lado do mundo persistente: entre operações, o mesmo personagem entra
 * num perímetro onde a Veyra não chega, guarda a arma e presta serviço aos outros operadores
 * como COMERCIANTE, FERRAMENTEIRO, INSTRUTOR, MÉDICO DA ZONA, TRANSPORTADOR ou ANALISTA
 * ICARUS (catálogo em GameRules::SAFE_ROLES).
 *
 * O estado operacional continua mandando:
 *   HUNTER     entra em qualquer zona;
 *   MERCENARY  entra nas governamentais como visitante (papéis do Hunter Command ficam fora);
 *   SURVIVOR   nenhuma base do governo o aceita — só a ZONA FRANCA, tolerada pela Frontier.
 *
 * Um papel ativo por vez, com turno de GameRules::SAFE_SHIFT_MIN minutos. Tolerante à ausência
 * do patch v4: sem as tabelas, devolve o catálogo de zonas vazio e a tela mostra o estado
 * honesto em vez de quebrar.
 */
final class SafeZoneRepo
{
    /**
     * As zonas conhecidas, já com o que o jogador pode ou não fazer em cada uma.
     *
     * @param array<string,mixed> $op estado operacional (OperationalRepo)
     * @return list<array<string,mixed>>
     */
    public static function zones(array $op): array
    {
        $rows = [];
        try {
            $rows = Database::all('SELECT * FROM safe_zones ORDER BY sort_order, id');
        } catch (\Throwable) {
            return [];
        }

        $survivor = ($op['state'] ?? '') === GameRules::STATE_SURVIVOR;
        $out = [];
        foreach ($rows as $r) {
            $meta = self::meta($r['meta'] ?? null);
            $kind = (string)($r['kind'] ?? GameRules::SAFE_ZONE_GOV);
            $gov  = $kind === GameRules::SAFE_ZONE_GOV;

            // A regra que dói: sem bandeira, nenhuma base do governo abre o portão.
            $blocked = $survivor && $gov;
            $status  = mb_strtoupper((string)($r['status'] ?? 'ABERTA'));
            $closed  = $status === 'FECHADA';

            $out[] = [
                'slug'        => (string)$r['slug'],
                'name'        => (string)$r['name'],
                'kind'        => $kind,
                'gov'         => $gov,
                'base_slug'   => $r['base_slug'] !== null ? (string)$r['base_slug'] : null,
                'map_id'      => $r['map_id'] !== null ? (int)$r['map_id'] : null,
                'region'      => (string)($r['region'] ?? ''),
                'status'      => $status,
                'status_color'=> GameRules::SAFE_ZONE_STATUS[$status] ?? 'muted',
                'occupancy'   => mb_strtoupper((string)($r['occupancy'] ?? 'MODERADA')),
                'occ_color'   => GameRules::occupancyColor((string)($r['occupancy'] ?? '')),
                'capacity'    => (int)($r['capacity'] ?? 0),
                'protection'  => (string)($r['protection'] ?? ''),
                'description' => (string)($r['description'] ?? ''),
                'services'    => array_values((array)($meta['services'] ?? [])),
                'rules'       => array_values((array)($meta['rules'] ?? [])),
                'blocked'     => $blocked || $closed,
                'block_reason'=> $blocked
                    ? 'SEM BANDEIRA: NENHUMA BASE DO GOVERNO RESPONDE POR ESTE OPERADOR.'
                    : ($closed ? 'ZONA FECHADA NESTE CICLO.' : ''),
                'access'      => $blocked ? 'BLOQUEADA' : ($closed ? 'FECHADA' : ($gov ? ($survivor ? 'BLOQUEADA' : 'LIBERADA') : 'TOLERADA')),
            ];
        }
        return $out;
    }

    /**
     * O papel civil ativo do jogador, ou null. `remaining` é o que sobra do turno em segundos
     * (0 = turno vencido, a tela mostra ENCERRAR TURNO).
     *
     * @return array<string,mixed>|null
     */
    public static function current(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        try {
            $row = Database::one(
                'SELECT * FROM player_safe_roles WHERE user_id = :u AND status = :s',
                ['u' => $userId, 's' => 'ATIVO']
            );
        } catch (\Throwable) {
            return null;
        }
        if ($row === null) {
            return null;
        }
        $def = GameRules::safeRole((string)$row['role']);
        if ($def === null) {
            return null;
        }
        $fim = $row['shift_ends_at'] !== null ? strtotime((string)$row['shift_ends_at']) : null;
        return [
            'role'      => $def['key'],
            'label'     => $def['label'],
            'color'     => $def['color'],
            'ms'        => $def['ms'],
            'summary'   => $def['summary'],
            'zone'      => (string)$row['zone_slug'],
            'started_at'=> (string)$row['started_at'],
            'ends_at'   => $row['shift_ends_at'] !== null ? (string)$row['shift_ends_at'] : null,
            'remaining' => $fim !== null ? max(0, $fim - time()) : null,
            'served'    => (int)($row['served'] ?? 0),
        ];
    }

    /**
     * Os seis papéis com o veredito para ESTE jogador nesta zona: pode assumir, e se não pode,
     * por quê. A HUD nunca oferece um botão que o servidor vai recusar.
     *
     * @param array<string,mixed> $op
     * @param array<string,mixed>|null $zone
     * @return list<array<string,mixed>>
     */
    public static function roles(array $op, ?array $zone, int $level, ?array $current = null): array
    {
        $out = [];
        foreach (GameRules::SAFE_ROLES as $key => $r) {
            $req    = (array)$r['req'];
            $motivo = null;

            if ($zone === null) {
                $motivo = 'ESCOLHA UMA ZONA';
            } elseif (!empty($zone['blocked'])) {
                $motivo = 'ZONA INDISPONÍVEL';
            } elseif (($req['state'] ?? null) !== null && ($op['state'] ?? '') !== $req['state']) {
                $motivo = $req['state'] === GameRules::STATE_HUNTER
                    ? 'EXCLUSIVO DO HUNTER COMMAND'
                    : 'ESTADO OPERACIONAL INCOMPATÍVEL';
            } elseif (($req['state'] ?? null) === null && ($op['state'] ?? '') === GameRules::STATE_SURVIVOR && !empty($zone['gov'])) {
                $motivo = 'INDISPONÍVEL NO ESTADO SOBREVIVENTE';
            } elseif (!empty($req['faction']) && ($op['faction'] ?? null) === null) {
                $motivo = 'EXIGE CONTRATO MERCENÁRIO ATIVO';
            } elseif (($req['rank'] ?? null) !== null && GameRules::rankIndex((string)($op['rank'] ?? '')) < GameRules::rankIndex((string)$req['rank'])) {
                $motivo = 'PATENTE MÍNIMA: ' . $req['rank'];
            } elseif (($req['role'] ?? null) !== null && ($op['role'] ?? null) !== $req['role']) {
                $motivo = 'FUNÇÃO HUNTER: ' . $req['role'];
            } elseif ((int)($req['level'] ?? 0) > $level) {
                $motivo = 'NÍVEL ' . (int)$req['level'];
            }

            $ativo = $current !== null && $current['role'] === $key;
            $out[] = [
                'key'     => $key,
                'label'   => $r['label'],
                'ms'      => $r['ms'],
                'color'   => $r['color'],
                'summary' => $r['summary'],
                'desc'    => $r['desc'],
                'gain'    => $r['gain'],
                'req'     => self::requisito($req),
                'can'     => $motivo === null && !$ativo,
                'active'  => $ativo,
                'reason'  => $motivo,
            ];
        }
        return $out;
    }

    /** Requisito em uma linha, para o card não precisar de tabela. */
    private static function requisito(array $req): string
    {
        $partes = [];
        if (($req['level'] ?? 0) > 0)          { $partes[] = 'NÍVEL ' . (int)$req['level']; }
        if (($req['rank'] ?? null) !== null)   { $partes[] = 'PATENTE ' . $req['rank']; }
        if (($req['role'] ?? null) !== null)   { $partes[] = 'FUNÇÃO ' . $req['role']; }
        if (!empty($req['faction']))           { $partes[] = 'CONTRATO MERCENÁRIO'; }
        if (($req['state'] ?? null) === GameRules::STATE_HUNTER) { $partes[] = 'HUNTER COMMAND'; }
        return $partes === [] ? 'SEM REQUISITO' : implode(' · ', $partes);
    }

    /**
     * Assume um papel civil. Trocar de papel encerra o turno anterior — não há dois ao mesmo
     * tempo, como não há dois contratos de facção.
     *
     * @param array<string,mixed> $op
     * @return array{ok:bool,error?:string,role?:string,zone?:string,ends_at?:string}
     */
    public static function assume(int $userId, string $zoneSlug, string $roleKey, array $op, int $level): array
    {
        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'Entre na sua conta para assumir um papel.'];
        }
        $key = GameRules::safeRoleKey($roleKey);
        if ($key === null) {
            return ['ok' => false, 'error' => 'Papel desconhecido.'];
        }
        $zone = null;
        foreach (self::zones($op) as $z) {
            if ($z['slug'] === $zoneSlug) { $zone = $z; break; }
        }
        if ($zone === null) {
            return ['ok' => false, 'error' => 'Zona desconhecida.'];
        }
        if (!empty($zone['blocked'])) {
            return ['ok' => false, 'error' => (string)$zone['block_reason']];
        }

        // Mesma checagem que a tela mostrou: o servidor não confia no botão.
        foreach (self::roles($op, $zone, $level, null) as $r) {
            if ($r['key'] === $key && !$r['can']) {
                return ['ok' => false, 'error' => 'REQUISITO NÃO CUMPRIDO — ' . (string)$r['reason']];
            }
        }

        $ends = date('Y-m-d H:i:s', time() + GameRules::SAFE_SHIFT_MIN * 60);
        try {
            Database::run(
                'INSERT INTO player_safe_roles (user_id, zone_slug, role, status, started_at, shift_ends_at, served)
                 VALUES (:u, :z, :r, :s, NOW(), :e, 0)
                 ON DUPLICATE KEY UPDATE zone_slug = VALUES(zone_slug), role = VALUES(role),
                                         status = VALUES(status), started_at = NOW(),
                                         shift_ends_at = VALUES(shift_ends_at), served = 0',
                ['u' => $userId, 'z' => $zone['slug'], 'r' => $key, 's' => 'ATIVO', 'e' => $ends]
            );
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'A Safe Zone ainda não está disponível neste servidor.'];
        }
        return ['ok' => true, 'role' => GameRules::SAFE_ROLES[$key]['label'], 'zone' => (string)$zone['name'], 'ends_at' => $ends];
    }

    /** Encerra o turno atual. Reversível: o jogador pode assumir outro papel em seguida. */
    public static function leave(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        try {
            Database::run('DELETE FROM player_safe_roles WHERE user_id = :u', ['u' => $userId]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private static function meta(mixed $meta): array
    {
        if (is_array($meta)) { return $meta; }
        if (is_string($meta) && $meta !== '') {
            $d = json_decode($meta, true);
            if (is_array($d)) { return $d; }
        }
        return [];
    }
}
