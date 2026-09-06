<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;
use App\Core\GameRules;

/**
 * Estado operacional do personagem: em qual dos três estados ele está, para quem trabalha e
 * o que isso muda na interface.
 *
 * Um único personagem. HUNTER, MERCENARY e SURVIVOR não são personagens diferentes — são o
 * mesmo jogador sob outra bandeira (PRD v3 §1–2). Por isso tudo vive na própria linha de
 * `players` (colunas do patch v3) mais `player_factions` (uma linha por facção conhecida).
 *
 * Tolerante à ausência do patch: se as colunas ainda não existirem no banco (deploy do código
 * antes do SQL), tudo cai para HUNTER · RECRUTA sem contrato — a HUD continua de pé.
 *
 * Forma devolvida por forUser() / defaults():
 *   state        HUNTER | MERCENARY | SURVIVOR
 *   rank         patente Hunter (GameRules::RANKS)
 *   role         função oficial ou null
 *   faction      'aegis' | 'frontier' | null   (contrato ativo)
 *   reputation   ['aegis' => int, 'frontier' => int]
 *   tiers        ['aegis' => 'CONFIÁVEL', 'frontier' => 'SEM CONTRATO']
 *   locked       ['aegis' => ?datetime, 'frontier' => ?datetime]  bloqueio após traição
 *   base_pref    slug da Base Governamental preferencial ou null
 *   survivor_since  datetime ou null
 */
final class OperationalRepo
{
    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'state'          => GameRules::STATE_HUNTER,
            'rank'           => GameRules::RANKS[0],
            'role'           => null,
            'faction'        => null,
            'reputation'     => ['aegis' => 0, 'frontier' => 0],
            'tiers'          => ['aegis' => 'SEM CONTRATO', 'frontier' => 'SEM CONTRATO'],
            'locked'         => ['aegis' => null, 'frontier' => null],
            'base_pref'      => null,
            'survivor_since' => null,
        ];
    }

    /**
     * Monta o estado a partir da linha de `players` já carregada (evita segunda consulta) e
     * das facções do jogador.
     *
     * @param array<string,mixed> $row linha de players (SELECT pl.*)
     * @return array<string,mixed>
     */
    public static function fromRow(int $userId, array $row): array
    {
        $op = self::defaults();

        $state = strtoupper((string)($row['operational_state'] ?? ''));
        if (in_array($state, GameRules::STATES, true)) {
            $op['state'] = $state;
        }
        $rank = strtoupper((string)($row['hunter_rank'] ?? ''));
        if (GameRules::rankIndex($rank) >= 0) {
            $op['rank'] = $rank;
        }
        $op['role']           = GameRules::role($row['hunter_role'] ?? null);
        $op['base_pref']      = ($row['base_pref'] ?? null) !== null ? (string)$row['base_pref'] : null;
        $op['survivor_since'] = ($row['survivor_since'] ?? null) !== null ? (string)$row['survivor_since'] : null;

        foreach (self::factions($userId) as $f) {
            $slug = strtolower((string)$f['faction']);
            if (!isset(GameRules::FACTIONS[$slug])) {
                continue;
            }
            $rep = (int)$f['reputation'];
            $op['reputation'][$slug] = $rep;
            $op['locked'][$slug]     = $f['locked_until'] ?? null;
            if (!empty($f['contract_active'])) {
                $op['faction']      = $slug;
                $op['tiers'][$slug] = GameRules::repTier($rep);
            } elseif ($rep > 0) {
                $op['tiers'][$slug] = GameRules::repTier($rep);
            }
        }

        // Coerência: só existe Mercenário com contrato; sem contrato o estado é Hunter.
        if ($op['state'] === GameRules::STATE_MERCENARY && $op['faction'] === null) {
            $op['state'] = GameRules::STATE_HUNTER;
        }
        return $op;
    }

    /** @return array<string,mixed> */
    public static function forUser(int $userId): array
    {
        if ($userId <= 0) {
            return self::defaults();
        }
        $row = Database::one('SELECT * FROM players WHERE user_id = :u', ['u' => $userId]) ?? [];
        return self::fromRow($userId, $row);
    }

    /** @return list<array<string,mixed>> */
    private static function factions(int $userId): array
    {
        try {
            return Database::all(
                'SELECT faction, reputation, tier, contract_active, locked_until
                   FROM player_factions WHERE user_id = :u',
                ['u' => $userId]
            );
        } catch (\Throwable) {
            return [];   // tabela ainda não existe: sem contrato
        }
    }

    // ------------------------------------------------------------------- apresentação

    /**
     * O que o badge de estado mostra: linha principal e complemento (topbar, Perfil, Lobby…).
     * PRD §14–15.
     *
     * @param array<string,mixed> $op
     * @return array{label:string,sub:string,color:string,state:string,survivor:bool}
     */
    public static function badge(array $op, int $level = 0): array
    {
        $state = (string)($op['state'] ?? GameRules::STATE_HUNTER);

        if ($state === GameRules::STATE_SURVIVOR) {
            return ['label' => 'SOBREVIVENTE', 'sub' => 'SEM EXTRAÇÃO', 'color' => 'white', 'state' => $state, 'survivor' => true];
        }
        if ($state === GameRules::STATE_MERCENARY && ($f = GameRules::faction($op['faction'] ?? null)) !== null) {
            $rep = (int)($op['reputation'][$op['faction']] ?? 0);
            return ['label' => 'MERCENÁRIO · ' . $f['label'], 'sub' => 'REP ' . num($rep), 'color' => $f['color'], 'state' => $state, 'survivor' => false];
        }
        $rank = (string)($op['rank'] ?? GameRules::RANKS[0]);
        return ['label' => 'HUNTER COMMAND · ' . $rank, 'sub' => 'NÍVEL ' . num($level), 'color' => 'lime', 'state' => $state, 'survivor' => false];
    }

    /** Quem responde por este operador — rótulo de "organização" (Perfil, contrato da Home). */
    public static function organization(array $op): string
    {
        if (($op['state'] ?? '') === GameRules::STATE_SURVIVOR) {
            return 'NENHUMA · SEM BANDEIRA';
        }
        if (($op['state'] ?? '') === GameRules::STATE_MERCENARY && ($f = GameRules::faction($op['faction'] ?? null)) !== null) {
            return $f['label'];
        }
        return 'HUNTER COMMAND';
    }

    /** O jogador satisfaz um requisito de contrato/reputação? (missões, loja, rotas) */
    public static function meets(array $op, ?string $faction, ?string $tier = null): bool
    {
        if ($faction === null || $faction === '') {
            return true;
        }
        $faction = strtolower($faction);
        if (($op['faction'] ?? null) !== $faction) {
            return false;
        }
        if ($tier === null || $tier === '') {
            return true;
        }
        return GameRules::tierMeets((string)($op['tiers'][$faction] ?? ''), $tier);
    }

    // ------------------------------------------------------------ pré-visualização (dev)

    /**
     * Sobrescreve o estado para QA e captura de tela. SÓ é chamado quando Config::debug() é
     * verdadeiro (localhost); em produção o estado vem do banco e ponto. Não é um "modo de
     * jogo": é uma lente para ver as três versões da mesma HUD sem mexer em dado real.
     *
     * @param array<string,mixed> $op
     * @return array<string,mixed>
     */
    public static function preview(array $op, ?string $estado): array
    {
        return match (strtolower((string)$estado)) {
            'hunter'       => array_replace($op, ['state' => GameRules::STATE_HUNTER, 'rank' => 'CABO', 'role' => 'MEDICAL', 'faction' => null, 'base_pref' => 'alpha']),
            'aegis'        => array_replace($op, ['state' => GameRules::STATE_MERCENARY, 'faction' => 'aegis',
                                                 'reputation' => ['aegis' => 1850, 'frontier' => 0],
                                                 'tiers' => ['aegis' => 'CONFIÁVEL', 'frontier' => 'SEM CONTRATO']]),
            'frontier'     => array_replace($op, ['state' => GameRules::STATE_MERCENARY, 'faction' => 'frontier',
                                                 'reputation' => ['aegis' => 0, 'frontier' => 2430],
                                                 'tiers' => ['aegis' => 'SEM CONTRATO', 'frontier' => 'CONFIÁVEL'],
                                                 'locked' => ['aegis' => date('Y-m-d H:i:s', time() + 7 * 3600 + 32 * 60), 'frontier' => null]]),
            'sobrevivente' => array_replace($op, ['state' => GameRules::STATE_SURVIVOR, 'faction' => null,
                                                 'survivor_since' => date('Y-m-d H:i:s', time() - 47 * 60)]),
            default        => $op,
        };
    }

    // ------------------------------------------------------------------------ contratos

    /**
     * Assina contrato com uma facção. Regras do PRD §7:
     *   - só uma facção por vez;
     *   - trocar rompe a atual e ZERA a reputação da abandonada;
     *   - a interface tem de pedir confirmação explícita — por isso `$confirm` é obrigatório
     *     e o controller devolve 422 sem ele.
     *   - facção bloqueada (traição recente) não aceita contrato até `locked_until`.
     *
     * @return array{ok:bool,error?:string,broke?:string}
     */
    public static function sign(int $userId, string $faction, bool $confirm): array
    {
        $faction = strtolower($faction);
        if (!isset(GameRules::FACTIONS[$faction])) {
            return ['ok' => false, 'error' => 'Facção desconhecida.'];
        }
        $op = self::forUser($userId);

        if ($op['state'] === GameRules::STATE_SURVIVOR) {
            return ['ok' => false, 'error' => 'Sobreviventes não têm bandeira. Nenhuma facção assina com quem está fora das cadeias de comando.'];
        }
        if ($op['faction'] === $faction) {
            return ['ok' => false, 'error' => 'Este contrato já está ativo.'];
        }
        $lock = $op['locked'][$faction] ?? null;
        if ($lock !== null && strtotime((string)$lock) > time()) {
            return ['ok' => false, 'error' => strtoupper(GameRules::FACTIONS[$faction]['label']) . ' — ACESSO TEMPORARIAMENTE BLOQUEADO'];
        }
        if (!$confirm) {
            return ['ok' => false, 'error' => 'Confirmação obrigatória.'];
        }

        $broke = $op['faction'];
        try {
            Database::transaction(static function () use ($userId, $faction, $broke): void {
                if ($broke !== null) {
                    // Romper zera a reputação da facção abandonada. É a regra que dá peso à troca.
                    Database::run(
                        'UPDATE player_factions SET contract_active = 0, reputation = 0, tier = NULL WHERE user_id = :u AND faction = :f',
                        ['u' => $userId, 'f' => $broke]
                    );
                }
                Database::run(
                    'INSERT INTO player_factions (user_id, faction, reputation, tier, contract_active)
                     VALUES (:u, :f, 0, :t, 1)
                     ON DUPLICATE KEY UPDATE contract_active = 1, tier = VALUES(tier)',
                    ['u' => $userId, 'f' => $faction, 't' => GameRules::repTier(0)]
                );
                Database::run(
                    'UPDATE players SET operational_state = :s WHERE user_id = :u',
                    ['s' => GameRules::STATE_MERCENARY, 'u' => $userId]
                );
            });
        } catch (\Throwable) {
            // player_factions / players.operational_state vêm do patch v3. Sem eles no banco,
            // assinar não persiste: avisa em vez de estourar um 500.
            return ['ok' => false, 'error' => 'Sistema de facções indisponível neste servidor. Tente novamente mais tarde.'];
        }

        return ['ok' => true, 'broke' => (string)$broke];
    }

    /**
     * Encerra o contrato atual e devolve o personagem ao Hunter Command (sem zerar nada: só a troca zera).
     * @return bool sucesso — false se o esquema de facções ainda não existe no banco.
     */
    public static function release(int $userId): bool
    {
        try {
            Database::transaction(static function () use ($userId): void {
                Database::run('UPDATE player_factions SET contract_active = 0 WHERE user_id = :u', ['u' => $userId]);
                Database::run('UPDATE players SET operational_state = :s WHERE user_id = :u AND operational_state = :m',
                    ['s' => GameRules::STATE_HUNTER, 'u' => $userId, 'm' => GameRules::STATE_MERCENARY]);
            });
            return true;
        } catch (\Throwable) {
            return false;   // patch v3 ausente: nada a encerrar
        }
    }
}
