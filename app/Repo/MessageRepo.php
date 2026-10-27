<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * Caixa de mensagens conciliada por user_id (tabelas message_threads / message_entries /
 * system_notices) mais a próxima janela de manutenção (catálogo maintenance_windows).
 *
 * Devolve exatamente as chaves que app/Views/pages/mensagens.php consome, para que a view
 * não precise saber de onde vieram os dados.
 */
final class MessageRepo
{
    private const MAX_BODY = 200;

    // ---------------------------------------------------------------- leitura

    /**
     * Conversas do jogador com os balões já formatados.
     * @return list<array<string,mixed>>
     */
    public static function threads(int $userId): array
    {
        $rows = Database::all(
            'SELECT t.*, MAX(m.sent_at) AS last_at
               FROM message_threads t
               LEFT JOIN message_entries m ON m.thread_id = t.id
              WHERE t.user_id = :u
              GROUP BY t.id
              ORDER BY last_at DESC, t.id DESC',
            ['u' => $userId]
        );
        if ($rows === []) {
            return [];
        }

        $ids  = array_map(static fn(array $r): int => (int)$r['id'], $rows);
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $msgs = Database::all(
            "SELECT thread_id, author, is_self, body, color, sent_at
               FROM message_entries WHERE thread_id IN ($in) ORDER BY sent_at, id",
            $ids
        );

        $byThread = [];
        $lastDay  = [];
        foreach ($msgs as $m) {
            $tid  = (int)$m['thread_id'];
            $day  = substr((string)$m['sent_at'], 0, 10);
            $line = [
                'author' => ((int)$m['is_self'] === 1) ? 'self' : (string)$m['author'],
                'self'   => (int)$m['is_self'] === 1,
                'text'   => (string)$m['body'],
                'time'   => date('H:i', strtotime((string)$m['sent_at'])),
                'color'  => (string)$m['color'],
            ];
            if (($lastDay[$tid] ?? null) !== $day) {
                $line['sep']    = self::daySeparator($day);
                $lastDay[$tid]  = $day;
            }
            $byThread[$tid][] = $line;
        }

        $items = [];
        foreach ($rows as $r) {
            $tid  = (int)$r['id'];
            $last = (string)($r['last_at'] ?? '');
            $items[] = [
                'id'          => (string)$r['slug'],
                'folder'      => (string)$r['folder'],
                'from'        => (string)$r['peer_name'],
                'system'      => (int)$r['is_system'] === 1,
                'subject'     => (string)$r['subject'],
                'preview'     => self::preview($byThread[$tid] ?? []),
                'time'        => self::listStamp($last),
                'date'        => self::fullStamp($last),
                'unread'      => (int)$r['unread'] === 1,
                'avatar'      => $r['peer_avatar'] ?: null,
                'icon'        => $r['peer_icon'] ?: null,
                'level'       => $r['peer_level'] !== null ? (int)$r['peer_level'] : null,
                'class'       => (string)($r['peer_class'] ?? ''),
                'class_ms'    => (string)($r['peer_class_icon'] ?? 'shield'),
                'status'      => (string)($r['peer_status'] ?? ''),
                'status_kind' => (string)($r['peer_status_kind'] ?? 'offline'),
                'color'       => (string)$r['color'],
                'tag'         => $r['tag'] ?: null,
                'thread'      => $byThread[$tid] ?? [],
            ];
        }
        return $items;
    }

    /**
     * Contagem por pasta usando a MESMA regra do filtro da tela: "sistema" cruza as
     * pastas (toda conversa de sistema), as demais contam pela coluna folder.
     * @return array<string,int>
     */
    public static function folderCounts(int $userId): array
    {
        $rows = Database::all(
            'SELECT folder, is_system, COUNT(*) n FROM message_threads WHERE user_id = :u GROUP BY folder, is_system',
            ['u' => $userId]
        );
        $out = ['entrada' => 0, 'sistema' => 0, 'enviadas' => 0];
        foreach ($rows as $r) {
            $f = (string)$r['folder'];
            $n = (int)$r['n'];
            $out[$f] = ($out[$f] ?? 0) + $n;
            if ((int)$r['is_system'] === 1) {
                $out['sistema'] += $n;
            }
        }
        return $out;
    }

    public static function unreadCount(int $userId): int
    {
        return (int)Database::value('SELECT COUNT(*) FROM message_threads WHERE user_id = :u AND unread = 1', ['u' => $userId]);
    }

    /**
     * Avisos do painel lateral: os desta conta MAIS os globais.
     *
     * `system_notices.user_id NULL` é o aviso para todo mundo — é assim que o painel grava
     * quando o operador escolhe "aviso global" (App\Controllers\Admin\EventsController::
     * salvarAviso) e é assim que a tela conta os globais no resumo. Antes esta consulta
     * pedia `user_id = :u` e só isso: o aviso global era gravado, contado no painel e
     * nunca chegava a ninguém. Os globais vêm primeiro em empate de ordem porque é um
     * recado da operação — normalmente manutenção ou evento — e ele lidera a lista.
     *
     * @return list<array<string,mixed>>
     */
    public static function notices(int $userId): array
    {
        $rows = Database::all(
            'SELECT * FROM system_notices
              WHERE user_id = :u OR user_id IS NULL
              ORDER BY sort_order, user_id IS NOT NULL, id',
            ['u' => $userId]
        );
        return array_map(static fn(array $r): array => [
            'icon'  => (string)$r['icon'],
            'color' => (string)$r['color'],
            'title' => (string)$r['title'],
            'text'  => (string)$r['body'],
            'time'  => self::relative((string)$r['created_at']),
            'cta'   => (string)($r['cta_label'] ?? 'ABRIR'),
            'href'  => (string)($r['cta_href'] ?? 'inicio'),
            'fresh' => (int)$r['fresh'] === 1,
        ], $rows);
    }

    /**
     * Próxima janela de manutenção com o contador em segundos calculado agora.
     * Se todas já passaram, avança a última em semanas até cair no futuro.
     * @return array<string,mixed>
     */
    public static function maintenance(): array
    {
        $row = Database::one('SELECT * FROM maintenance_windows WHERE starts_at > NOW() ORDER BY starts_at LIMIT 1')
            ?? Database::one('SELECT * FROM maintenance_windows ORDER BY starts_at DESC LIMIT 1');
        if ($row === null) {
            return [];
        }
        $start = strtotime((string)$row['starts_at']);
        $end   = strtotime((string)$row['ends_at']);
        while ($start <= time()) {
            $start += 7 * 86400;
            $end   += 7 * 86400;
        }
        $slug = (string)($row['news_slug'] ?? '');
        return [
            'title'   => 'PRÓXIMA MANUTENÇÃO',
            'label'   => 'JANELA ' . date('d/m', $start) . ' · ' . date('H', $start) . 'H — ' . date('H', $end) . 'H',
            'note'    => 'Servidores da ' . (string)$row['region'] . '. ' . (string)($row['note'] ?? ''),
            'seconds' => max(0, $start - time()),
            'href'    => $slug !== '' ? 'noticias/' . $slug : 'noticias',
            'cta'     => 'LER AVISO COMPLETO',
        ];
    }

    // --------------------------------------------------------------- mutações

    /** Marca a conversa como lida. Devolve false se ela não for do jogador. */
    public static function markRead(int $userId, string $slug): bool
    {
        if (!self::exists($userId, $slug)) {
            return false;
        }
        Database::run(
            'UPDATE message_threads SET unread = 0 WHERE user_id = :u AND slug = :s',
            ['u' => $userId, 's' => $slug]
        );
        return true;
    }

    /**
     * Grava a resposta do jogador na conversa. Devolve o texto salvo ou null se a
     * conversa não existir para esse user_id.
     */
    public static function reply(int $userId, string $slug, string $text): ?string
    {
        $text = trim(mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, self::MAX_BODY));
        if ($text === '') {
            return null;
        }
        $tid = Database::value(
            'SELECT id FROM message_threads WHERE user_id = :u AND slug = :s',
            ['u' => $userId, 's' => $slug]
        );
        if ($tid === null || $tid === false) {
            return null;
        }
        // O catálogo semeia horários "de hoje" que podem estar à frente do relógio;
        // a resposta nunca pode cair no meio da conversa.
        $last = (string)(Database::value('SELECT MAX(sent_at) FROM message_entries WHERE thread_id = :t', ['t' => (int)$tid]) ?: '');
        $when = max(time(), $last !== '' ? strtotime($last) + 60 : 0);

        Database::insert('message_entries', [
            'thread_id' => (int)$tid,
            'author'    => 'self',
            'is_self'   => 1,
            'body'      => $text,
            'color'     => 'lime',
            'sent_at'   => date('Y-m-d H:i:s', $when),
        ]);
        Database::run('UPDATE message_threads SET unread = 0 WHERE id = :t', ['t' => (int)$tid]);
        return $text;
    }

    public static function exists(int $userId, string $slug): bool
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM message_threads WHERE user_id = :u AND slug = :s',
            ['u' => $userId, 's' => $slug]
        ) > 0;
    }

    // ---------------------------------------------------------------- formato

    /** "HOJE" | "ONTEM" | "27/05" para os separadores da conversa. */
    private static function daySeparator(string $ymd): string
    {
        if ($ymd === date('Y-m-d'))                          { return 'HOJE'; }
        if ($ymd === date('Y-m-d', strtotime('-1 day')))     { return 'ONTEM'; }
        return date('d/m', strtotime($ymd));
    }

    /** Coluna da direita na lista: hora quando é hoje/ontem, dia/mês antes disso. */
    private static function listStamp(string $dt): string
    {
        if ($dt === '') { return ''; }
        $ts  = strtotime($dt);
        $ymd = date('Y-m-d', $ts);
        return ($ymd === date('Y-m-d') || $ymd === date('Y-m-d', strtotime('-1 day')))
            ? date('H:i', $ts)
            : date('d/m', $ts);
    }

    /** Cabeçalho do assunto: "Hoje, 19:44" | "Ontem, 22:41" | "27/05, 11:05". */
    private static function fullStamp(string $dt): string
    {
        if ($dt === '') { return ''; }
        $ts  = strtotime($dt);
        $ymd = date('Y-m-d', $ts);
        if ($ymd === date('Y-m-d'))                      { return 'Hoje, ' . date('H:i', $ts); }
        if ($ymd === date('Y-m-d', strtotime('-1 day'))) { return 'Ontem, ' . date('H:i', $ts); }
        return date('d/m', $ts) . ', ' . date('H:i', $ts);
    }

    /** "há 12 min" | "há 2 h" | "hoje, 09:00" | "27/05" para os avisos. */
    private static function relative(string $dt): string
    {
        $ts   = strtotime($dt);
        $diff = time() - $ts;
        if ($diff < 60)    { return 'agora'; }
        if ($diff < 3600)  { return 'há ' . (int)floor($diff / 60) . ' min'; }
        if ($diff < 43200) { return 'há ' . (int)floor($diff / 3600) . ' h'; }
        if (date('Y-m-d', $ts) === date('Y-m-d')) { return 'hoje, ' . date('H:i', $ts); }
        return date('d/m', $ts);
    }

    /** @param list<array<string,mixed>> $lines */
    private static function preview(array $lines): string
    {
        $last = $lines === [] ? '' : (string)end($lines)['text'];
        return mb_strlen($last) > 90 ? mb_substr($last, 0, 89) . '…' : $last;
    }
}
