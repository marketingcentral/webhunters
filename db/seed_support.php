<?php declare(strict_types=1);

/**
 * Popula as tabelas da tela SUPORTE a partir de app/Data/suporte.php:
 * faq, support_categories, server_regions, server_services e os chamados do jogador demo.
 * Chamado por db/seed.php (e utilizável isolado: php db/seed_support.php).
 */

use App\Core\Config;
use App\Core\Database;

if (!function_exists('seed_support')) {
    /**
     * @param int $demoUid usuário demo que recebe o histórico de chamados (0 = nenhum)
     */
    function seed_support(int $demoUid = 0): void
    {
        $root = dirname(__DIR__);
        $d    = (array)require $root . '/app/Data/suporte.php';
        $pdo  = Database::pdo();

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['support_tickets', 'support_categories', 'server_services', 'server_regions', 'faq'] as $t) {
            $pdo->exec("TRUNCATE TABLE `$t`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        // ---- FAQ -----------------------------------------------------------
        $n = 0;
        foreach ((array)($d['faq'] ?? []) as $i => $f) {
            Database::insert('faq', [
                'slug'       => 'faq-' . ($i + 1),
                'question'   => (string)$f['q'],
                'answer'     => (string)$f['a'],
                'sort_order' => $i,
            ]);
            $n++;
        }

        // ---- categorias de chamado ----------------------------------------
        $c = 0;
        foreach ((array)($d['categories'] ?? []) as $i => $cat) {
            Database::insert('support_categories', [
                'slug'       => (string)$cat['slug'],
                'label'      => (string)$cat['label'],
                'sla_hours'  => (int)preg_replace('/\D+/', '', (string)($cat['sla'] ?? '24')),
                'sort_order' => $i,
            ]);
            $c++;
        }

        // ---- regiões e serviços -------------------------------------------
        $r = 0;
        foreach ((array)($d['status']['regions'] ?? []) as $i => $reg) {
            Database::insert('server_regions', [
                'slug'       => slugify_support((string)$reg['label']),
                'label'      => (string)$reg['label'],
                'ping_ms'    => (int)($reg['ping'] ?? 0),
                'players'    => (int)str_replace(['.', ',', ' '], '', (string)($reg['players'] ?? '0')),
                'state'      => (string)($reg['state'] ?? 'ok'),
                'sort_order' => $i,
            ]);
            $r++;
        }

        $maint = (array)($d['maintenance'] ?? []);
        $s = 0;
        foreach ((array)($d['status']['services'] ?? []) as $i => $svc) {
            $warn = ($svc['state'] ?? 'ok') !== 'ok';
            Database::insert('server_services', [
                'slug'            => slugify_support((string)$svc['label']),
                'label'           => (string)$svc['label'],
                'state'           => (string)($svc['state'] ?? 'ok'),
                'status_label'    => (string)($svc['value'] ?? 'OPERACIONAL'),
                'short_label'     => (string)($svc['short'] ?? 'ESTÁVEL'),
                'maint_scope'     => $warn ? (string)($maint['service'] ?? $svc['label']) : null,
                'maint_starts_at' => $warn ? date('Y-m-d H:i:s', time() + (int)($maint['starts_in'] ?? 3600)) : null,
                'maint_minutes'   => $warn ? 40 : null,
                'sort_order'      => $i,
            ]);
            $s++;
        }

        // ---- histórico de chamados do jogador demo -------------------------
        $t = 0;
        if ($demoUid > 0) {
            $seedTickets = [
                ['protocol' => 48120, 'subject' => 'Itens perdidos após queda',  'category' => 'tecnico',   'status' => 'resolvido', 'days' => 1, 'priority' => 'ALTA'],
                ['protocol' => 47984, 'subject' => 'Cobrança duplicada na loja', 'category' => 'pagamento', 'status' => 'analise',   'days' => 5, 'priority' => 'CRÍTICA'],
                ['protocol' => 47730, 'subject' => 'Denúncia de jogador',        'category' => 'denuncia',  'status' => 'fechado',   'days' => 13, 'priority' => 'NORMAL'],
            ];
            foreach ($seedTickets as $tk) {
                Database::insert('support_tickets', [
                    'user_id'     => $demoUid,
                    'protocol'    => $tk['protocol'],
                    'subject'     => $tk['subject'],
                    'category'    => $tk['category'],
                    'priority'    => $tk['priority'],
                    'message'     => 'Chamado histórico do jogador demo, importado do seed.',
                    'attach_logs' => 1,
                    'status'      => $tk['status'],
                    'created_at'  => date('Y-m-d H:i:s', time() - $tk['days'] * 86400),
                ]);
                $t++;
            }
        }

        echo "$n perguntas de FAQ, $c categorias, $r regiões, $s serviços, $t chamados inseridos.\n";
    }
}

if (!function_exists('slugify_support')) {
    function slugify_support(string $s): string
    {
        $s = (string)preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(
            (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s)
        ));
        return trim($s, '-') ?: 'item';
    }
}

// Execução direta: php db/seed_support.php
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    require __DIR__ . '/../app/Core/Config.php';
    require __DIR__ . '/../app/Core/Database.php';
    Config::boot(dirname(__DIR__));
    $uid = (int)Database::value('SELECT id FROM users ORDER BY id LIMIT 1');
    seed_support($uid);
}
