<?php declare(strict_types=1);

use App\Core\Database;

/**
 * Popula a caixa de mensagens de um jogador a partir de app/Data/mensagens.php.
 * Os horários do arquivo ("HOJE", "ONTEM", "27/05" + hora) viram DATETIME reais,
 * relativos ao dia da carga — a tela reconstrói os rótulos na leitura, então o
 * "HOJE" continua sendo hoje mesmo semanas depois do seed.
 *
 * Uso direto (sem recriar o banco inteiro):
 *   "C:/wamp64/bin/php/php8.3.28/php.exe" db/seed_messages.php
 */
function seed_messages(int $userId, array $mock, ?array $window = null): void
{
    Database::run('DELETE FROM message_threads WHERE user_id = :u', ['u' => $userId]);
    Database::run('DELETE FROM system_notices  WHERE user_id = :u', ['u' => $userId]);

    // A janela de manutenção é calculada na carga; os textos que a citam precisam
    // concordar com ela, senão a tela diz "04/06" e o contador aponta outro dia.
    $window ??= maintenance_window();
    $sync = static function (string $txt) use ($window): string {
        if ($window === null) { return $txt; }
        return str_replace(
            ['04/06', '03h às 05h', '03H — 05H'],
            [date('d/m', $window['start']), date('H', $window['start']) . 'h às ' . date('H', $window['end']) . 'h',
             date('H', $window['start']) . 'H — ' . date('H', $window['end']) . 'H'],
            $txt
        );
    };

    /** Converte um separador de dia ("HOJE" | "ONTEM" | "27/05") em Y-m-d. */
    $day = static function (string $sep, string $fallback): string {
        $sep = mb_strtoupper(trim($sep));
        if ($sep === 'HOJE')  { return date('Y-m-d'); }
        if ($sep === 'ONTEM') { return date('Y-m-d', strtotime('-1 day')); }
        if (preg_match('#^(\d{1,2})/(\d{1,2})$#', $sep, $m)) {
            $d = sprintf('%04d-%02d-%02d', (int)date('Y'), (int)$m[2], (int)$m[1]);
            // datas do catálogo são sempre passado: se cair no futuro, é do ano anterior
            return $d > date('Y-m-d') ? sprintf('%04d-%02d-%02d', (int)date('Y') - 1, (int)$m[2], (int)$m[1]) : $d;
        }
        return $fallback;
    };

    $threads = 0;
    $entries = 0;
    foreach ((array)($mock['items'] ?? []) as $item) {
        $tid = Database::insert('message_threads', [
            'user_id'          => $userId,
            'slug'             => (string)$item['id'],
            'folder'           => (string)($item['folder'] ?? 'entrada'),
            'peer_name'        => (string)$item['from'],
            'is_system'        => !empty($item['system']) ? 1 : 0,
            'subject'          => $sync((string)$item['subject']),
            'peer_avatar'      => $item['avatar'] ?? null,
            'peer_icon'        => $item['icon'] ?? null,
            'peer_level'       => $item['level'] ?? null,
            'peer_class'       => $item['class'] ?? null,
            'peer_class_icon'  => $item['class_ms'] ?? null,
            'peer_status'      => $item['status'] ?? null,
            'peer_status_kind' => (string)($item['status_kind'] ?? 'offline'),
            'color'            => (string)($item['color'] ?? 'lime'),
            'tag'              => $item['tag'] ?? null,
            'unread'           => !empty($item['unread']) ? 1 : 0,
        ]);
        $threads++;

        $curDay = date('Y-m-d');
        foreach ((array)($item['thread'] ?? []) as $m) {
            if (!empty($m['sep'])) {
                $curDay = $day((string)$m['sep'], $curDay);
            }
            $self = ($m['author'] ?? '') === 'self' || !empty($m['self']);
            Database::insert('message_entries', [
                'thread_id' => $tid,
                'author'    => $self ? 'self' : (string)$m['author'],
                'is_self'   => $self ? 1 : 0,
                'body'      => $sync((string)$m['text']),
                'color'     => (string)($m['color'] ?? 'lime'),
                'sent_at'   => $curDay . ' ' . (string)$m['time'] . ':00',
            ]);
            $entries++;
        }
    }

    /** "há 2 h" | "hoje, 09:00" | "27/05" -> DATETIME. */
    $noticeTime = static function (string $t): string {
        $t = mb_strtolower(trim($t));
        if (preg_match('#h[áa]\s+(\d+)\s*h#u', $t, $m))          { return date('Y-m-d H:i:s', strtotime('-' . (int)$m[1] . ' hours')); }
        if (preg_match('#h[áa]\s+(\d+)\s*m#u', $t, $m))          { return date('Y-m-d H:i:s', strtotime('-' . (int)$m[1] . ' minutes')); }
        if (preg_match('#hoje,\s*(\d{1,2}):(\d{2})#', $t, $m))   { return date('Y-m-d') . sprintf(' %02d:%02d:00', (int)$m[1], (int)$m[2]); }
        if (preg_match('#^(\d{1,2})/(\d{1,2})$#', $t, $m)) {
            $d = sprintf('%04d-%02d-%02d', (int)date('Y'), (int)$m[2], (int)$m[1]);
            if ($d > date('Y-m-d')) { $d = sprintf('%04d-%02d-%02d', (int)date('Y') - 1, (int)$m[2], (int)$m[1]); }
            return $d . ' 09:00:00';
        }
        return date('Y-m-d H:i:s');
    };

    $notices = 0;
    foreach ((array)($mock['system_panel']['items'] ?? []) as $i => $s) {
        Database::insert('system_notices', [
            'user_id'    => $userId,
            'icon'       => (string)($s['icon'] ?? 'info'),
            'color'      => (string)($s['color'] ?? 'lime'),
            'title'      => (string)$s['title'],
            'body'       => $sync((string)$s['text']),
            'cta_label'  => $s['cta'] ?? null,
            'cta_href'   => $s['href'] ?? null,
            'fresh'      => !empty($s['fresh']) ? 1 : 0,
            'created_at' => $noticeTime((string)($s['time'] ?? '')),
            'sort_order' => $i,
        ]);
        $notices++;
    }

    echo "Mensagens (user $userId): $threads conversas, $entries balões, $notices avisos.\n";
}

/**
 * Janelas de manutenção — catálogo, iguais para todos.
 * @return array{start:int,end:int} a próxima janela, para os textos citarem a data certa
 */
function seed_maintenance(array $mock): array
{
    Database::run('DELETE FROM maintenance_windows');
    // A view já escreve "Servidores da <REGIÃO>." antes da nota; guardamos só o resto.
    $note   = (string)($mock['maintenance']['note'] ?? '');
    $note   = trim(preg_replace('/^Servidores da .*?\.\s*/u', '', $note) ?? $note);
    $slug   = (string)($mock['maintenance']['href'] ?? 'noticias');
    $slug   = str_starts_with($slug, 'noticias/') ? substr($slug, 9) : null;
    $first  = (int)($mock['maintenance']['seconds'] ?? 51120);
    $window = null;
    foreach ([$first, $first + 8 * 86400, $first + 22 * 86400] as $offset) {
        $start = (int)floor((time() + $offset) / 3600) * 3600;
        Database::insert('maintenance_windows', [
            'starts_at' => date('Y-m-d H:i:s', $start),
            'ends_at'   => date('Y-m-d H:i:s', $start + 2 * 3600),
            'region'    => 'AMÉRICA DO SUL',
            'note'      => $note,
            'news_slug' => $slug,
        ]);
        $window ??= ['start' => $start, 'end' => $start + 2 * 3600];
    }
    echo "3 janelas de manutenção inseridas.\n";
    return $window ?? ['start' => time(), 'end' => time()];
}

/** Próxima janela já gravada (para semear mensagens sem repopular o catálogo). */
function maintenance_window(): ?array
{
    $row = Database::one('SELECT starts_at, ends_at FROM maintenance_windows WHERE starts_at > NOW() ORDER BY starts_at LIMIT 1');
    return $row === null ? null : ['start' => strtotime((string)$row['starts_at']), 'end' => strtotime((string)$row['ends_at'])];
}

// Execução direta: repopula mensagens de todos os usuários sem tocar no resto do banco.
if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    require __DIR__ . '/../app/Core/Config.php';
    require __DIR__ . '/../app/Core/Database.php';
    App\Core\Config::boot(dirname(__DIR__));
    $mock   = (array)require dirname(__DIR__) . '/app/Data/mensagens.php';
    $window = seed_maintenance($mock);
    foreach (Database::all('SELECT id FROM users ORDER BY id') as $u) {
        seed_messages((int)$u['id'], $mock, $window);
    }
}
