<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repo\SettingsRepo;

/**
 * Tela CONFIGURAÇÕES.
 *
 * Catálogo (quais opções existem, tipos e padrões) vem de app/Data/configuracoes.php — igual para
 * todos. O VALOR de cada opção é por jogador e vive em `player_settings`, conciliado por user_id
 * (SettingsRepo). Cada aba lê o catálogo, sobrepõe o que o jogador gravou e devolve à view.
 */
final class SettingsController extends Controller
{
    /** Chave da predefinição (ladrilhos do painel direito) por aba. */
    private const PRESET = 'set_preset_';

    /** Campos da aba CONTA que pertencem à tabela `users`, não a `player_settings`. */
    private const ACCOUNT = ['username', 'email'];

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $catalog = data_mock('configuracoes');
        $slugs   = array_column($catalog['tabs'] ?? [], 'slug');
        $aba     = (string)$this->pick($params['aba'] ?? null, $slugs, $slugs[0] ?? 'jogabilidade');
        $uid     = $this->userId();

        if ($request->isPost()) {
            return $this->handlePost($request, $catalog, $aba, $uid);
        }

        $saved  = SettingsRepo::forUser($uid);
        $groups = $this->mergeGroups($catalog['groups'][$aba] ?? [], $saved, $aba, $uid);
        $values = $this->flatten($groups);

        $detail = $this->detail($catalog, $aba, $saved, $values, $uid);

        $data = [
            'title'      => $catalog['title'] ?? 'CONFIGURAÇÕES',
            'tabs'       => $catalog['tabs'] ?? [],
            'active_tab' => $aba,
            'tab'        => $this->tab($catalog['tabs'] ?? [], $aba),
            'groups'     => $groups,
            'detail'     => $detail,
        ];

        $page = $this->page('configuracoes', 'Configurações', [
            'bg'   => 'bg/settings.jpg',
            'back' => ['label' => 'SAIR', 'href' => null],
        ]);
        return $this->render('configuracoes', $page, $data);
    }

    /* ===================================================================== POST */

    /** @param array<string,mixed> $catalog */
    private function handlePost(Request $request, array $catalog, string $aba, int $uid): Response
    {
        $this->csrf($request);
        // Índice já com os valores atuais do jogador: entrada inválida cai para o que ele tem
        // hoje, nunca para o padrão do catálogo (que sobrescreveria dados reais em silêncio).
        $items  = $this->items($this->mergeGroups(
            $catalog['groups'][$aba] ?? [],
            SettingsRepo::forUser($uid),
            $aba,
            $uid
        ));
        $action = (string)$request->input('action', 'apply');

        if ($action === 'reset') {
            // username/e-mail vivem em `users` (identidade da conta) e não voltam "ao padrão".
            $keys = array_merge(array_diff(array_keys($items), self::ACCOUNT), [self::PRESET . $aba]);
            $n    = SettingsRepo::reset($uid, $keys);
            flash('ok', $n > 0
                ? 'Padrões restaurados (' . $n . ' ' . ($n === 1 ? 'opção voltou' : 'opções voltaram') . ' ao valor de fábrica).'
                : 'Nenhuma alteração para restaurar: a aba já está nos padrões.');
            return $this->redirect('configuracoes/' . $aba);
        }

        $values = [];
        foreach ($items as $key => $item) {
            $values[$key] = $this->sanitize($item, $request->input($key));
        }
        $preset = $this->presetSlug($catalog, $aba, (string)$request->input('set_preset', ''));
        if ($preset !== null) {
            $values[self::PRESET . $aba] = $preset;
        }

        $warn = null;
        if ($aba === 'conta') {
            $warn = $this->saveAccount($uid, [
                'username' => (string)$request->input('username', ''),
                'email'    => (string)$request->input('email', ''),
            ]);
            foreach (self::ACCOUNT as $k) {
                unset($values[$k]);
            }
        }

        $n = SettingsRepo::save($uid, $values);
        if ($warn !== null) {
            flash('error', $warn);
        }
        flash($n > 0 ? 'ok' : 'error', $n > 0
            ? 'Alterações aplicadas (' . $n . ' ' . ($n === 1 ? 'opção salva' : 'opções salvas') . ').'
            : 'Não foi possível salvar: banco de dados indisponível.');
        return $this->redirect('configuracoes/' . $aba);
    }

    /* ============================================================== catálogo */

    /**
     * Indexa os itens da aba por chave.
     * @param array<int,array<string,mixed>> $groups @return array<string,array<string,mixed>>
     */
    private function items(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            foreach ((array)($group['items'] ?? []) as $item) {
                $out[(string)$item['key']] = $item;
            }
        }
        return $out;
    }

    /**
     * Catálogo da aba + valores gravados do jogador (e, na aba conta, os dados reais do usuário).
     * @param array<int,array<string,mixed>> $groups @param array<string,string> $saved
     * @return array<int,array<string,mixed>>
     */
    private function mergeGroups(array $groups, array $saved, string $aba, int $uid): array
    {
        $account = $aba === 'conta' ? $this->account($uid) : [];
        foreach ($groups as $gi => $group) {
            foreach ((array)($group['items'] ?? []) as $ii => $item) {
                $key  = (string)$item['key'];
                $type = (string)$item['type'];
                if (isset($account[$key]) && $account[$key] !== '') {
                    $item['value'] = $account[$key];
                }
                if (array_key_exists($key, $saved) && !($aba === 'conta' && in_array($key, self::ACCOUNT, true))) {
                    $item['value'] = $this->restore($item, $saved[$key]);
                    $item['saved'] = true;
                }
                if ($type === 'range') {
                    $item['max'] = $this->rangeMax($item);
                }
                if ($type === 'stepper' || $type === 'select') {
                    $item['value'] = (string)$item['value'];
                }
                $groups[$gi]['items'][$ii] = $item;
            }
        }
        return $groups;
    }

    /**
     * Valores planos da aba (chave => valor já resolvido), usados pelos stats do painel direito.
     * @param array<int,array<string,mixed>> $groups @return array<string,mixed>
     */
    private function flatten(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            foreach ((array)($group['items'] ?? []) as $item) {
                $out[(string)$item['key']] = $item['value'] ?? null;
            }
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $tabs @return array<string,mixed>|null */
    private function tab(array $tabs, string $aba): ?array
    {
        foreach ($tabs as $tab) {
            if (($tab['slug'] ?? null) === $aba) {
                return $tab;
            }
        }
        return null;
    }

    private function rangeMax(array $item): int
    {
        return (string)($item['unit'] ?? '%') === '' ? 120 : 100;
    }

    /* ============================================================ saneamento */

    /** Valor gravado -> valor tipado para a view. */
    private function restore(array $item, string $stored): mixed
    {
        return match ((string)$item['type']) {
            'toggle' => $stored === '1',
            'range'  => max(0, min($this->rangeMax($item), (int)round((float)$stored))),
            'stepper', 'select' => in_array($stored, array_map('strval', (array)($item['options'] ?? [])), true)
                ? $stored
                : (string)$item['value'],
            default  => $stored,
        };
    }

    /** Entrada do POST -> string segura para gravar. */
    private function sanitize(array $item, mixed $raw): string
    {
        $type = (string)$item['type'];
        $str  = is_scalar($raw) ? trim((string)$raw) : '';

        if ($type === 'toggle') {
            // checkbox desmarcado não é enviado pelo navegador: ausência = desligado.
            return ($str === '1' || $str === 'on' || $str === 'true') ? '1' : '0';
        }
        if ($type === 'range') {
            $max = $this->rangeMax($item);
            $n   = is_numeric($str) ? (int)round((float)$str) : (int)round((float)$item['value']);
            return (string)max(0, min($max, $n));
        }
        if ($type === 'stepper' || $type === 'select') {
            $opts = array_map('strval', (array)($item['options'] ?? []));
            return in_array($str, $opts, true) ? $str : (string)$item['value'];
        }
        if ($type === 'key') {
            $up = mb_strtoupper($str, 'UTF-8');
            return preg_match('/^[\p{L}\p{N}][\p{L}\p{N} \+\-\/\.]{0,11}$/u', $up) === 1
                ? $up
                : (string)$item['value'];
        }
        if ((string)$item['key'] === 'email') {
            return filter_var($str, FILTER_VALIDATE_EMAIL) !== false ? mb_substr($str, 0, 160) : (string)$item['value'];
        }
        $clean = preg_replace('/[^\p{L}\p{N}_\-\. @]/u', '', $str) ?? '';
        return $clean !== '' ? mb_substr($clean, 0, 40) : (string)$item['value'];
    }

    /** Slug de predefinição válido para a aba (ou null quando não veio nada usável). */
    private function presetSlug(array $catalog, string $aba, string $raw): ?string
    {
        foreach ($this->presetOptions($catalog, $aba) as $opt) {
            if ((string)($opt['slug'] ?? '') === $raw && $raw !== '') {
                return $raw;
            }
        }
        return null;
    }

    /** @return array<int,array<string,mixed>> */
    private function presetOptions(array $catalog, string $aba): array
    {
        $detail = $catalog['details'][$aba] ?? ($aba === 'jogabilidade' ? ($catalog['difficulty'] ?? []) : []);
        return (array)($detail['options'] ?? []);
    }

    /* ======================================================= painel de detalhe */

    /**
     * Painel direito: marca a predefinição do jogador e resolve os stats a partir dos valores reais.
     * @param array<string,string> $saved @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function detail(array $catalog, string $aba, array $saved, array $values, int $uid): array
    {
        $detail = $catalog['details'][$aba] ?? ($aba === 'jogabilidade' ? ($catalog['difficulty'] ?? []) : []);
        if ($detail === []) {
            return [];
        }
        $options = (array)($detail['options'] ?? []);
        $chosen  = $saved[self::PRESET . $aba] ?? null;

        // Na jogabilidade a predefinição É a dificuldade: o stepper manda quando não há escolha gravada.
        if ($aba === 'jogabilidade' && isset($values['dificuldade'])) {
            foreach ($options as $opt) {
                if (mb_strtoupper((string)$opt['label'], 'UTF-8') === mb_strtoupper((string)$values['dificuldade'], 'UTF-8')) {
                    $chosen = (string)$opt['slug'];
                    break;
                }
            }
        }
        $found = false;
        foreach ($options as $i => $opt) {
            $on = $chosen !== null ? ((string)$opt['slug'] === $chosen) : !empty($opt['active']);
            $options[$i]['active'] = $on;
            if ($on) {
                $found = true;
                $detail['current'] = (string)$opt['label'];
                $detail['note']    = (string)($opt['desc'] ?? '');
            }
        }
        if (!$found && $options !== []) {
            $options[0]['active'] = true;
            $detail['current']    = (string)$options[0]['label'];
            $detail['note']       = (string)($options[0]['desc'] ?? '');
        }
        $detail['options'] = array_values($options);

        $detail['stats'] = $this->stats($catalog, $aba, (array)($detail['stats'] ?? []), $values, $uid);
        return $detail;
    }

    /**
     * Resolve cada stat: `src` aponta para a chave da opção (ou @campo do jogador) e `fmt` diz
     * como formatar. Sem `src`, o valor estático do catálogo é mantido.
     * @param array<int,array<string,mixed>> $stats @param array<string,mixed> $values
     * @return array<int,array<string,mixed>>
     */
    private function stats(array $catalog, string $aba, array $stats, array $values, int $uid): array
    {
        $levels  = (array)($catalog['difficulty_levels'] ?? []);
        $account = $this->account($uid);
        $keys    = array_values(array_filter(
            array_keys($values),
            static fn (string $k): bool => str_starts_with($k, 'k_')
        ));
        $binds = [];
        foreach ($keys as $k) {
            $binds[] = mb_strtoupper((string)$values[$k], 'UTF-8');
        }
        $dupes = count($binds) - count(array_unique($binds));

        foreach ($stats as $i => $st) {
            $src = (string)($st['src'] ?? '');
            $fmt = (string)($st['fmt'] ?? 'raw');
            if ($src === '') {
                continue;
            }
            $val = null;
            if ($src === '@keybinds') {
                $val = (string)count($binds);
            } elseif ($src === '@conflicts') {
                $val = $dupes > 0 ? $dupes . ($dupes === 1 ? ' tecla' : ' teclas') : 'Nenhum';
            } elseif ($src === '@player') {
                $val = (string)($this->player()['name'] ?? '—');
            } elseif ($src === '@email') {
                $val = ($account['email'] ?? '') !== '' ? 'Sim' : 'Não';
            } elseif ($src === '@last_login') {
                $val = $account['last_login'] ?? 'Primeiro acesso';
            } elseif (str_starts_with($src, '@level:')) {
                $lvl = mb_strtoupper((string)($values[substr($src, 7)] ?? ''), 'UTF-8');
                $val = (string)($levels[$lvl][(string)($st['field'] ?? '')] ?? ($st['value'] ?? '—'));
            } elseif (array_key_exists($src, $values)) {
                $raw = $values[$src];
                $val = match ($fmt) {
                    'pct'   => num((float)$raw) . '%',
                    'onoff' => $raw ? (string)($st['on'] ?? 'Ativo') : (string)($st['off'] ?? 'Inativo'),
                    'title' => $this->titleCase((string)$raw),
                    default => (string)$raw,
                };
            }
            if ($val !== null && $val !== '') {
                $stats[$i]['value'] = $val;
            }
            unset($stats[$i]['src'], $stats[$i]['fmt'], $stats[$i]['field'], $stats[$i]['on'], $stats[$i]['off']);
        }
        return array_values($stats);
    }

    /** "PADRÃO DO SISTEMA" -> "Padrão do sistema" (preserva siglas conhecidas como USB/TAA/HZ). */
    private function titleCase(string $v): string
    {
        $small = ['DO', 'DA', 'DE', 'E', 'EM', 'A', 'O'];
        $acro  = ['USB', 'HUD', 'ADS', 'FPS', 'GPU', 'CPU', 'HDR', 'TAA', 'FXAA', 'DLSS', 'HZ', 'PC', 'TV', 'VR', '2K', '4K', 'ID', 'IP', 'AMD', 'RGB'];
        $out   = [];
        foreach (preg_split('/\s+/u', trim($v)) ?: [] as $i => $w) {
            if ($w === '') {
                continue;
            }
            $bare = trim($w, '()');
            if (in_array(mb_strtoupper($bare, 'UTF-8'), $acro, true) || preg_match('/^\p{Nd}/u', $bare) === 1) {
                $out[] = $w; // sigla ou número: USB, TAA, 144, 1920
                continue;
            }
            if ($i > 0 && in_array(mb_strtoupper($w, 'UTF-8'), $small, true)) {
                $out[] = mb_strtolower($w, 'UTF-8');
                continue;
            }
            $out[] = mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8') . mb_strtolower(mb_substr($w, 1, null, 'UTF-8'), 'UTF-8');
        }
        return implode(' ', $out);
    }

    /**
     * Grava nome de usuário e e-mail na tabela `users` (identidade da conta, não preferência).
     * Recebe a entrada CRUA do POST — validar aqui evita trocar o dado real por um padrão do
     * catálogo. Devolve um aviso quando algo foi recusado, ou null quando tudo passou.
     * @param array<string,string> $raw
     */
    private function saveAccount(int $uid, array $raw): ?string
    {
        $cur = $this->account($uid);
        if ($uid <= 0 || $cur === []) {
            return null;
        }
        $user  = trim((string)($raw['username'] ?? ''));
        $mail  = trim((string)($raw['email'] ?? ''));
        $set   = [];
        $bad   = [];

        if ($user !== '' && $user !== $cur['username']) {
            if (preg_match('/^[A-Za-z0-9_]{3,24}$/', $user) === 1) {
                $set['username'] = $user;
            } else {
                $bad[] = 'nome de usuário (3 a 24 caracteres: letras, números e _)';
            }
        }
        if ($mail !== '' && $mail !== $cur['email']) {
            if (filter_var($mail, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($mail) <= 120) {
                $set['email'] = $mail;
            } else {
                $bad[] = 'e-mail';
            }
        }
        if ($set !== []) {
            try {
                $sql = 'UPDATE users SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, array_keys($set)))
                    . ' WHERE id = :u';
                Database::run($sql, $set + ['u' => $uid]);
                self::$accountCache = [];
            } catch (\Throwable) {
                $bad[] = 'nome de usuário ou e-mail já em uso';
            }
        }
        return $bad === [] ? null : 'Não foi possível atualizar: ' . implode('; ', $bad) . '.';
    }

    /** @var array<int,array<string,string>> */
    private static array $accountCache = [];

    /**
     * Dados reais do usuário logado (users), usados nos campos de texto da aba CONTA.
     * @return array<string,string>
     */
    private function account(int $uid): array
    {
        $cache = &self::$accountCache;
        if (isset($cache[$uid])) {
            return $cache[$uid];
        }
        if ($uid <= 0) {
            return $cache[$uid] = [];
        }
        try {
            $row = Database::one('SELECT username, email, last_login FROM users WHERE id = :u', ['u' => $uid]);
        } catch (\Throwable) {
            $row = null;
        }
        if ($row === null) {
            return $cache[$uid] = [];
        }
        $last = (string)($row['last_login'] ?? '');
        return $cache[$uid] = [
            'username'   => (string)($row['username'] ?? ''),
            'email'      => (string)($row['email'] ?? ''),
            'last_login' => $last !== '' ? date('d/m/Y H:i', strtotime($last) ?: time()) : '',
        ];
    }
}
