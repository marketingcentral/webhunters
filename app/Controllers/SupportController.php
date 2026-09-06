<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repo\SupportRepo;

final class SupportController extends Controller
{
    /** Histórico de relatórios do jogador (Fase 2: tudo por user_id). */
    private const BUG_TABLE = 'player_bug_reports';

    /** status guardado no banco => rótulo + cor do chip na view */
    private const BUG_STATUS = [
        'recebido'  => ['EM FILA',    'cyan'],
        'analise'   => ['EM ANÁLISE', 'amber'],
        'corrigido' => ['CORRIGIDO',  'lime'],
        'duplicado' => ['DUPLICADO',  'muted'],
        'recusado'  => ['RECUSADO',   'red'],
    ];

    /** GET/POST /suporte */
    public function index(Request $request, array $params = []): Response
    {
        $uid    = $this->userId();
        $data   = $this->supportData($uid);
        $errors = [];
        $values = ['subject' => '', 'category' => '', 'message' => '', 'priority' => '', 'logs' => true];

        if ($request->isPost()) {
            $this->csrf($request);
            $values['subject']  = mb_substr(trim((string)$request->input('subject', '')), 0, 120);
            $values['category'] = (string)$request->input('category', '');
            $values['message']  = mb_substr(trim(str_replace("\r\n", "\n", (string)$request->input('message', ''))), 0, 2000);
            $values['priority'] = (string)$request->input('priority', 'NORMAL');
            $values['logs']     = $request->input('logs', '') === '1';

            $cats  = array_column($data['categories'] ?? [], 'slug');
            $prios = array_map('strval', (array)($data['form']['priorities'] ?? ['NORMAL']));
            if (!in_array($values['priority'], $prios, true)) {
                $values['priority'] = (string)($data['form']['priority'] ?? 'NORMAL');
            }
            if (mb_strlen($values['subject']) < 4) {
                $errors['subject'] = 'Descreva o assunto com ao menos 4 caracteres.';
            }
            if (!in_array($values['category'], $cats, true)) {
                $errors['category'] = 'Selecione uma categoria.';
            }
            if (mb_strlen($values['message']) < 20) {
                $errors['message'] = 'Conte mais detalhes (mínimo de 20 caracteres).';
            }
            if ($errors === [] && $uid <= 0) {
                $errors['message'] = 'Não foi possível registrar o chamado agora. Tente novamente em instantes.';
            }
            if ($errors === []) {
                // O stepper ASSUNTO é a própria categoria; o título do chamado sai da
                // primeira linha da descrição, para MEUS CHAMADOS não repetir a categoria.
                $protocol = SupportRepo::open($uid, ['subject' => $this->ticketSummary($values)] + $values);
                $sla      = SupportRepo::slaHours($values['category']);
                flash('ok', 'Chamado #' . $protocol . ' aberto. Responderemos em até ' . $sla . 'h.');
                return $this->redirect('suporte');
            }
        }

        $page = $this->page('suporte', 'Suporte');
        return $this->render('suporte', $page, $data + ['errors' => $errors, 'values' => $values], 'hud', $errors ? 422 : 200);
    }

    /**
     * Título curto do chamado: primeira frase/linha da descrição (até 42 caracteres),
     * caindo para o rótulo da categoria quando a descrição não rende um título.
     * @param array<string, mixed> $values
     */
    private function ticketSummary(array $values): string
    {
        $msg  = trim((string)($values['message'] ?? ''));
        $line = trim((string)(preg_split('/[\n\r]|(?<=[.!?])\s/u', $msg, 2)[0] ?? ''));
        if (mb_strlen($line) > 42) {
            $cut  = mb_substr($line, 0, 42);
            $sp   = mb_strrpos($cut, ' ');
            $line = rtrim(is_int($sp) && $sp > 22 ? mb_substr($cut, 0, $sp) : $cut, " ,;:-") . '…';
        }
        $line = rtrim($line, '.');
        return mb_strlen($line) >= 4 ? $line : (string)($values['subject'] ?? 'Chamado');
    }

    /**
     * Monta a tela SUPORTE a partir do banco: FAQ, categorias de chamado, telemetria dos
     * servidores, janela de manutenção e os chamados DO JOGADOR (conciliados por user_id).
     * Os rótulos fixos do formulário seguem em app/Data/suporte.php — o mesmo arquivo que
     * alimenta o seed. Sem banco instalado, a tela cai inteira para esse arquivo.
     * @return array<string, mixed>
     */
    private function supportData(int $uid): array
    {
        $base = data_mock('suporte');
        try {
            if (!SupportRepo::installed()) {
                return $base;
            }
            $cats = SupportRepo::categories();
            $faq  = SupportRepo::faq();
            if ($cats !== []) { $base['categories'] = $cats; }
            if ($faq !== [])  { $base['faq'] = $faq; }
            $base['status']  = SupportRepo::status();
            $base['tickets'] = SupportRepo::ticketsFor($uid, 3);   // 3 cabem inteiros no painel; o resto em VER TODOS

            $maint = SupportRepo::maintenance();
            if ($maint !== null) {
                $base['maintenance'] = $maint;
            } else {
                unset($base['maintenance']);
            }

            // O painel de sucesso só aparece logo após um envio: o prazo citado ali é o SLA
            // real da categoria do chamado recém-gravado, não um número fixo.
            $sla = SupportRepo::latestSlaFor($uid);
            if ($sla > 0 && !empty($base['success']['steps'])) {
                $base['success']['steps'] = array_map(
                    static fn (string $s): string => str_starts_with($s, 'Prazo médio de resposta')
                        ? 'Prazo médio de resposta: ' . $sla . ' horas.'
                        : $s,
                    array_map('strval', (array)$base['success']['steps'])
                );
            }

            // PROTOCOLO na linha de identificação = o próximo número que será gravado.
            $base['ticket_meta'] = array_map(
                static function (array $m): array {
                    if (($m['label'] ?? '') === 'PROTOCOLO') {
                        $m['value'] = '#' . SupportRepo::nextProtocol();
                    }
                    return $m;
                },
                (array)($base['ticket_meta'] ?? [])
            );
        } catch (\Throwable) {
            return data_mock('suporte');   // banco indisponível — HUD segue navegável
        }
        return $base;
    }

    /** GET/POST /relatorio-de-bug */
    public function bug(Request $request, array $params = []): Response
    {
        $data   = data_mock('relatorio-de-bug');
        $uid    = $this->userId();
        $player = $this->player();
        $errors = [];
        $values = ['title' => '', 'area' => '', 'severity' => '', 'description' => '', 'steps' => ''];
        $attach = true;

        if ($request->isPost()) {
            $this->csrf($request);
            foreach (array_keys($values) as $k) {
                $raw = (string)$request->input($k, '');
                $raw = str_replace("\r\n", "\n", $raw);
                $values[$k] = trim(mb_substr($raw, 0, in_array($k, ['steps', 'description'], true) ? 3000 : 200));
            }
            $attach = $request->input('attach_logs', '') === '1';

            $areas = array_column($data['areas'] ?? [], 'slug');
            $sev   = array_column($data['severities'] ?? [], 'slug');
            if (mb_strlen($values['title']) < 6) {
                $errors['title'] = 'Dê um título com ao menos 6 caracteres.';
            }
            if (!in_array($values['area'], $areas, true)) {
                $errors['area'] = 'Selecione a área afetada.';
            }
            if (!in_array($values['severity'], $sev, true)) {
                $errors['severity'] = 'Selecione a gravidade.';
            }
            if (mb_strlen($values['steps']) < 20) {
                $errors['steps'] = 'Descreva os passos para reproduzir (mínimo de 20 caracteres).';
            }
            if ($errors === []) {
                $code = $this->storeBugReport($uid, $values, $attach);
                flash('ok', 'Relatório ' . $code . ' enviado. Obrigado por ajudar a melhorar o jogo.');
                return $this->redirect('relatorio-de-bug');
            }
        }

        $data = $this->bugSidebar($data, $request, $uid, $player);

        $page = $this->page('relatorio-de-bug', 'Relatório de Bug');
        return $this->render(
            'relatorio-de-bug',
            $page,
            $data + ['errors' => $errors, 'values' => $values, 'attach' => $attach],
            'hud',
            $errors ? 422 : 200
        );
    }

    /**
     * GET /relatorio-de-bug/{code} — detalhe de UM relatório do jogador logado.
     * O código chega em minúsculas na URL (bug-4812) e é conciliado por user_id + code.
     */
    public function bugDetail(Request $request, array $params = []): Response
    {
        $code   = mb_strtoupper(trim((string)($params['code'] ?? '')));
        $uid    = $this->userId();
        $data   = data_mock('relatorio-de-bug');
        $detail = $this->bugReport($uid, $code, $data);
        if ($detail === null) {
            throw new HttpException(404, 'Relatório não encontrado');
        }

        $data['detail'] = $detail;
        $data = $this->bugSidebar($data, $request, $uid, $this->player());

        $page = $this->page('relatorio-de-bug', 'Relatório ' . $detail['code']);
        return $this->render(
            'relatorio-de-bug',
            $page,
            $data + ['errors' => [], 'values' => [], 'attach' => (bool)$detail['attach_raw']],
            'hud'
        );
    }

    /**
     * Coluna direita da tela (idêntica no formulário e no detalhe): histórico do jogador,
     * dados reais da sessão e o SLA calculado dos relatórios já fechados.
     * @param array<string,mixed> $data @param array<string,mixed> $player
     * @return array<string,mixed>
     */
    private function bugSidebar(array $data, Request $request, int $uid, array $player): array
    {
        $data['recent']     = $this->bugReports($uid, (array)($data['recent_seed'] ?? []));
        $data['system']     = $this->sessionRows($request, $uid, $player);
        $data['recent_sla'] = $this->bugSla($uid) ?? (string)($data['recent_sla'] ?? '—');
        return $data;
    }

    // ---------------------------------------------------------------- relatórios (banco)

    /**
     * O banco está disponível? A tabela vem de db/schema.sql com FK para users
     * (ON DELETE CASCADE) — antes era criada sob demanda e sem FK, e os relatórios
     * ficavam órfãos, sendo herdados pelo próximo jogador com o mesmo id.
     */
    private function dbReady(): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            return $ready = Database::ready();
        } catch (\Throwable) {
            return $ready = false;
        }
    }

    /** Grava o relatório do jogador e devolve o código (BUG-XXXX). */
    private function storeBugReport(int $uid, array $values, bool $attach): string
    {
        if ($uid <= 0 || !$this->dbReady()) {
            return 'BUG-' . random_int(1000, 9999);
        }
        try {
            $next = (int)Database::value(
                'SELECT COALESCE(MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)), 4900) + 1 FROM ' . self::BUG_TABLE
            );
            $code = 'BUG-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
            Database::insert(self::BUG_TABLE, [
                'user_id'     => $uid,
                'code'        => $code,
                'title'       => $values['title'],
                'area'        => $values['area'],
                'severity'    => $values['severity'],
                'description' => $values['description'],
                'steps'       => $values['steps'],
                'attach_logs' => $attach ? 1 : 0,
                'status'      => 'recebido',
            ]);
            return $code;
        } catch (\Throwable) {
            return 'BUG-' . random_int(1000, 9999);
        }
    }

    /**
     * Relatórios do jogador (mais recentes primeiro), no formato que a view espera.
     * Na primeira visita de um usuário o histórico de exemplo do catálogo (`recent_seed`)
     * é gravado para aquele user_id — a partir daí tudo vem do banco.
     * @return list<array<string,string>>
     */
    private function bugReports(int $uid, array $seed): array
    {
        if ($uid <= 0 || !$this->dbReady()) {
            return [];
        }
        try {
            $has = (int)Database::value('SELECT COUNT(*) FROM ' . self::BUG_TABLE . ' WHERE user_id = :u', ['u' => $uid]);
            if ($has === 0 && $seed !== []) {
                $this->seedBugReports($uid, $seed);
            }
            $rows = Database::all(
                'SELECT code, title, status, created_at FROM ' . self::BUG_TABLE . '
                 WHERE user_id = :u ORDER BY created_at DESC, id DESC LIMIT 12',
                ['u' => $uid]
            );
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            [$label, $cls] = self::BUG_STATUS[(string)$r['status']] ?? ['EM FILA', 'cyan'];
            $out[] = [
                'id'     => (string)$r['code'],
                'title'  => (string)$r['title'],
                'status' => $label,
                'cls'    => $cls,
                'date'   => date('d/m/Y', strtotime((string)$r['created_at']) ?: time()),
            ];
        }
        return $out;
    }

    /**
     * UM relatório do jogador (user_id + code), já traduzido para a view.
     * Devolve null quando o código não existe para aquele jogador — a rota vira 404.
     * @param array<string,mixed> $catalog @return array<string,mixed>|null
     */
    private function bugReport(int $uid, string $code, array $catalog): ?array
    {
        if ($uid <= 0 || $code === '' || !$this->dbReady()) {
            return null;
        }
        try {
            $r = Database::one(
                'SELECT code, title, area, severity, description, steps, attach_logs, status, created_at, closed_at
                   FROM ' . self::BUG_TABLE . ' WHERE user_id = :u AND code = :c LIMIT 1',
                ['u' => $uid, 'c' => $code]
            );
        } catch (\Throwable) {
            return null;
        }
        if ($r === null) {
            return null;
        }

        $areas = [];
        foreach ((array)($catalog['areas'] ?? []) as $a) {
            $areas[(string)$a['slug']] = $a;
        }
        $sevs = [];
        foreach ((array)($catalog['severities'] ?? []) as $s) {
            $sevs[(string)$s['slug']] = $s;
        }
        $area = $areas[(string)$r['area']] ?? ['label' => mb_strtoupper((string)$r['area']), 'icon' => 'help'];
        $sev  = $sevs[(string)$r['severity']] ?? ['label' => mb_strtoupper((string)$r['severity']), 'cls' => 'muted', 'icon' => 'warning'];
        [$stLabel, $stCls] = self::BUG_STATUS[(string)$r['status']] ?? ['EM FILA', 'cyan'];

        $open  = strtotime((string)$r['created_at']) ?: time();
        $close = $r['closed_at'] !== null ? (strtotime((string)$r['closed_at']) ?: null) : null;

        return [
            'code'          => (string)$r['code'],
            'title'         => (string)$r['title'],
            'status'        => $stLabel,
            'status_cls'    => $stCls,
            'area_label'    => (string)$area['label'],
            'area_icon'     => (string)($area['icon'] ?? 'help'),
            'severity'      => (string)$sev['label'],
            'severity_cls'  => (string)($sev['cls'] ?? 'muted'),
            'severity_icon' => (string)($sev['icon'] ?? 'warning'),
            'opened'        => date('d/m/Y · H:i', $open),
            'closed'        => $close !== null ? date('d/m/Y · H:i', $close) : null,
            'attach_raw'    => (int)$r['attach_logs'] === 1,
            'description'   => trim((string)($r['description'] ?? '')),
            'steps'         => trim((string)($r['steps'] ?? '')),
        ];
    }

    /** Popula o histórico inicial daquele jogador a partir do catálogo estático. */
    private function seedBugReports(int $uid, array $seed): void
    {
        foreach ($seed as $s) {
            $days   = (int)($s['days_ago'] ?? 0);
            $status = (string)($s['status'] ?? 'recebido');
            $when   = date('Y-m-d H:i:s', strtotime('-' . $days . ' days') ?: time());
            try {
                Database::insert(self::BUG_TABLE, [
                    'user_id'     => $uid,
                    'code'        => (string)$s['id'],
                    'title'       => (string)$s['title'],
                    'area'        => (string)($s['area'] ?? 'outro'),
                    'severity'    => (string)($s['severity'] ?? 'media'),
                    'description' => (string)($s['description'] ?? ''),
                    'steps'       => (string)($s['steps'] ?? ''),
                    'attach_logs' => 1,
                    'status'      => $status,
                    'created_at'  => $when,
                    'closed_at'   => in_array($status, ['corrigido', 'duplicado', 'recusado'], true)
                        ? date('Y-m-d H:i:s', strtotime($when . ' +' . (12 + $days % 24) . ' hours') ?: time())
                        : null,
                ]);
            } catch (\Throwable) {
                // código duplicado / banco ocupado: segue sem quebrar a tela
            }
        }
    }

    /** Tempo médio de resposta do jogador (relatórios já fechados). */
    private function bugSla(int $uid): ?string
    {
        if ($uid <= 0 || !$this->dbReady()) {
            return null;
        }
        try {
            $h = Database::value(
                'SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, closed_at)) FROM ' . self::BUG_TABLE . '
                 WHERE user_id = :u AND closed_at IS NOT NULL',
                ['u' => $uid]
            );
        } catch (\Throwable) {
            return null;
        }
        return $h === null ? null : num((int)round((float)$h)) . ' h';
    }

    // ------------------------------------------------------------- dados da sessão

    /**
     * Painel DADOS DA SESSÃO: identidade real do jogador (banco) + ambiente real da requisição.
     * @return list<array<string,string>>
     */
    private function sessionRows(Request $request, int $uid, array $player): array
    {
        $server = data_mock('server');
        $build  = (string)(data_mock('relatorio-de-bug')['tag'] ?? 'BUILD 2417');
        $pid    = $uid > 0
            ? sprintf('EH-%06d-%s', $uid, mb_strtoupper(mb_substr((string)($player['username'] ?? $player['name'] ?? 'HNT'), 0, 14)))
            : 'EH-CONVIDADO';

        return [
            ['label' => 'VERSÃO DO JOGO', 'value' => '0.8.1 (' . mb_strtolower($build) . ')', 'icon' => 'terminal'],
            ['label' => 'SERVIDOR', 'value' => (string)($server['region'] ?? '—') . ' · ' . num((int)($server['ping_ms'] ?? 0)) . ' ms', 'icon' => 'dns'],
            ['label' => 'PLATAFORMA', 'value' => $this->platform($request->userAgent()), 'icon' => 'desktop_windows'],
            ['label' => 'JOGADOR', 'value' => (string)($player['name'] ?? '—') . ' · NÍVEL ' . num((int)($player['level'] ?? 0)), 'icon' => 'person'],
            ['label' => 'ID DO JOGADOR', 'value' => $pid, 'icon' => 'badge'],
        ];
    }

    /** Sistema operacional + navegador reais da sessão (a partir do User-Agent). */
    private function platform(string $ua): string
    {
        $os = 'Desconhecido';
        foreach ([
            'Windows NT 10.0' => 'Windows 10/11',
            'Windows NT'      => 'Windows',
            'Mac OS X'        => 'macOS',
            'Android'         => 'Android',
            'Linux'           => 'Linux',
        ] as $needle => $label) {
            if (str_contains($ua, $needle)) { $os = $label; break; }
        }
        $br = 'Navegador';
        foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'] as $needle => $label) {
            if (str_contains($ua, $needle)) { $br = $label; break; }
        }
        return $os . ' / ' . $br;
    }
}
