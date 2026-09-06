<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * Tela SUPORTE — leitura/gravação no banco. Catálogo (faq, support_categories,
 * server_regions, server_services) é igual para todos; os chamados (support_tickets)
 * são conciliados por user_id. Cada método devolve exatamente as chaves que a view
 * de suporte já consumia do app/Data/suporte.php.
 */
final class SupportRepo
{
    /** @var array<string,mixed> */
    private static array $cache = [];

    /** Rótulo e tom de cada status de chamado (usados pelo painel MEUS CHAMADOS). */
    private const STATUS = [
        'aberto'    => ['ABERTO',     'lime'],
        'analise'   => ['EM ANÁLISE', 'amber'],
        'resolvido' => ['RESOLVIDO',  'lime'],
        'fechado'   => ['FECHADO',    'muted'],
    ];

    public static function installed(): bool
    {
        return self::cached('installed', static function (): bool {
            try {
                Database::pdo()->query('SELECT 1 FROM support_categories LIMIT 1');
                return true;
            } catch (\Throwable) {
                return false;
            }
        });
    }

    /** @return list<array{q:string,a:string}> */
    public static function faq(): array
    {
        return self::cached('faq', static function (): array {
            $out = [];
            foreach (Database::all('SELECT question, answer FROM faq ORDER BY sort_order, id') as $r) {
                $out[] = ['q' => (string)$r['question'], 'a' => (string)$r['answer']];
            }
            return $out;
        });
    }

    /** @return list<array{slug:string,label:string,sla:string}> */
    public static function categories(): array
    {
        return self::cached('categories', static function (): array {
            $out = [];
            foreach (Database::all('SELECT slug, label, sla_hours FROM support_categories ORDER BY sort_order, id') as $r) {
                $out[] = [
                    'slug'  => (string)$r['slug'],
                    'label' => (string)$r['label'],
                    'sla'   => ((int)$r['sla_hours']) . 'H',
                ];
            }
            return $out;
        });
    }

    /**
     * Painel de status: regiões + serviços, com o selo geral derivado dos estados reais.
     * @return array<string,mixed>
     */
    public static function status(): array
    {
        return self::cached('status', static function (): array {
            $regions = [];
            foreach (Database::all('SELECT label, ping_ms, players, state FROM server_regions ORDER BY sort_order, id') as $r) {
                $regions[] = [
                    'label'   => (string)$r['label'],
                    'ping'    => (int)$r['ping_ms'],
                    'players' => number_format((int)$r['players'], 0, ',', '.'),
                    'state'   => (string)$r['state'],
                ];
            }
            $services = [];
            $degraded = 0;
            foreach (Database::all('SELECT label, state, status_label, short_label FROM server_services ORDER BY sort_order, id') as $r) {
                $ok = (string)$r['state'] === 'ok';
                if (!$ok) { $degraded++; }
                $services[] = [
                    'label' => (string)$r['label'],
                    'value' => (string)$r['status_label'],
                    'short' => (string)$r['short_label'],
                    'state' => (string)$r['state'],
                ];
            }
            return [
                'label'          => 'STATUS DOS SERVIDORES',
                'value'          => $degraded === 0 ? 'Todos os sistemas operacionais' : $degraded . ' serviço(s) em manutenção',
                'badge'          => $degraded === 0 ? 'OPERACIONAL' : 'PARCIAL',
                'ok'             => $degraded === 0,
                'updated'        => 'ATUALIZADO HÁ',
                'regions'        => $regions,
                'services_title' => 'SERVIÇOS',
                'services'       => $services,
            ];
        });
    }

    /**
     * Próxima janela de manutenção (serviço fora do estado ok com horário marcado).
     * `starts_in` em segundos, recalculado a cada requisição — é o que a HUD conta.
     * @return array<string,mixed>|null
     */
    public static function maintenance(): ?array
    {
        return self::cached('maintenance', static function (): ?array {
            $r = Database::one(
                'SELECT label, maint_scope, maint_starts_at, maint_minutes
                   FROM server_services
                  WHERE state <> :ok AND maint_starts_at IS NOT NULL
                  ORDER BY maint_starts_at LIMIT 1',
                ['ok' => 'ok']
            );
            if ($r === null) {
                return null;
            }
            $in = strtotime((string)$r['maint_starts_at']) - time();
            return [
                'title'     => 'MANUTENÇÃO PROGRAMADA',
                'service'   => (string)($r['maint_scope'] ?: $r['label']),
                'desc'      => 'Janela de ' . (int)($r['maint_minutes'] ?? 0) . ' minutos.',
                'label'     => $in > 0 ? 'INÍCIO EM' : 'EM ANDAMENTO HÁ',
                'starts_in' => max(0, $in),
            ];
        });
    }

    /**
     * Chamados do jogador, mais recentes primeiro.
     * @return list<array<string,string>>
     */
    public static function ticketsFor(int $uid, int $limit = 4): array
    {
        if ($uid <= 0) {
            return [];
        }
        $rows = Database::all(
            'SELECT protocol, subject, status, created_at
               FROM support_tickets WHERE user_id = :u
              ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(20, $limit)),
            ['u' => $uid]
        );
        $out = [];
        foreach ($rows as $r) {
            [$label, $tone] = self::STATUS[(string)$r['status']] ?? ['ABERTO', 'lime'];
            $out[] = [
                'id'      => '#' . (int)$r['protocol'],
                'subject' => (string)$r['subject'],
                'state'   => $label,
                'tone'    => $tone,
                'date'    => date('d/m', strtotime((string)$r['created_at']) ?: time()),
            ];
        }
        return $out;
    }

    /** Próximo protocolo livre — mostrado na linha de identificação antes do envio. */
    public static function nextProtocol(): int
    {
        $max = (int)Database::value('SELECT MAX(protocol) FROM support_tickets');
        return max(48000, $max + 1);
    }

    /**
     * Abre um chamado para o jogador. Devolve o protocolo gravado.
     * @param array<string,mixed> $v subject, category, priority, message, logs
     */
    public static function open(int $uid, array $v): int
    {
        $protocol = self::nextProtocol();
        Database::insert('support_tickets', [
            'user_id'     => $uid,
            'protocol'    => $protocol,
            'subject'     => (string)$v['subject'],
            'category'    => (string)$v['category'],
            'priority'    => (string)($v['priority'] ?? 'NORMAL'),
            'message'     => (string)$v['message'],
            'attach_logs' => !empty($v['logs']) ? 1 : 0,
            'status'      => 'aberto',
        ]);
        unset(self::$cache['nextProtocol']);
        return $protocol;
    }

    /** SLA (em horas) do chamado mais recente do jogador; 0 quando não há nenhum. */
    public static function latestSlaFor(int $uid): int
    {
        if ($uid <= 0) {
            return 0;
        }
        $cat = (string)Database::value(
            'SELECT category FROM support_tickets WHERE user_id = :u ORDER BY created_at DESC, id DESC LIMIT 1',
            ['u' => $uid]
        );
        return $cat === '' ? 0 : self::slaHours($cat);
    }

    /** SLA (em horas) da categoria, para compor a mensagem de sucesso. */
    public static function slaHours(string $slug): int
    {
        foreach (self::categories() as $c) {
            if ($c['slug'] === $slug) {
                return (int)$c['sla'];
            }
        }
        return 24;
    }

    /** @template T @param callable():T $fn @return T */
    private static function cached(string $key, callable $fn): mixed
    {
        if (!array_key_exists($key, self::$cache)) {
            self::$cache[$key] = $fn();
        }
        /** @var T */
        return self::$cache[$key];
    }
}
