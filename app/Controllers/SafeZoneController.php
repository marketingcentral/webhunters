<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\GameRules;
use App\Core\Request;
use App\Core\Response;
use App\Repo\SafeZoneRepo;

/**
 * SAFE ZONE (/safe-zone) — o lado civil do mundo persistente.
 *
 * Entre uma operação e outra o mesmo personagem entra num perímetro protegido e assume um
 * papel: COMERCIANTE, FERRAMENTEIRO, INSTRUTOR, MÉDICO DA ZONA, TRANSPORTADOR ou ANALISTA
 * ICARUS. A tela responde às mesmas três perguntas do resto da HUD — em qual estado estou
 * operando, para quem trabalho e o que está acontecendo agora — só que dentro do muro.
 *
 * Zonas e papel ativo vêm do banco (`safe_zones`, `player_safe_roles`, patch v4); o catálogo
 * de papéis é regra e vive em GameRules::SAFE_ROLES. O mock `app/Data/safe-zone.php` é cromo
 * e fallback.
 */
final class SafeZoneController extends Controller
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $data  = data_mock('safe-zone');
        $op    = $this->operational();
        $level = (int)($this->player()['level'] ?? 0);

        $zones   = SafeZoneRepo::zones($op);
        $current = SafeZoneRepo::current($this->userId());

        // Zona escolhida: a da URL, a do papel ativo, a base preferencial, ou a primeira aberta.
        $slugs   = array_column($zones, 'slug');
        $pedida  = $params['zona'] ?? null;
        // A zona do papel ativo só vale se ainda estiver liberada para este estado: um
        // Sobrevivente não continua vendo o pátio da base que acabou de fechar para ele.
        $padrao  = $this->zonaSeLiberada($zones, $current['zone'] ?? null)
                   ?? $this->zonaDaBase($zones, (string)($op['base_pref'] ?? ''))
                   ?? $this->primeiraLiberada($zones) ?? ($slugs[0] ?? '');
        $slug    = $this->pick($pedida, $slugs, (string)$padrao);

        $zone = null;
        foreach ($zones as $z) {
            if ($z['slug'] === $slug) { $zone = $z; break; }
        }

        $data['zones']       = $zones;
        $data['zone']        = $zone;
        $data['active_zone'] = $slug;
        $data['roles']       = SafeZoneRepo::roles($op, $zone, $level, $current);
        $data['current']     = $current;
        $data['operational'] = $op;
        $data['survivor']    = ($op['state'] ?? '') === GameRules::STATE_SURVIVOR;
        $data['shift_min']   = GameRules::SAFE_SHIFT_MIN;
        $data['empty']       = $zones === [];

        $page = $this->page('safe-zone', 'Safe Zone', [
            'bg'   => 'bg/lobby.jpg',
            'back' => ['label' => 'VOLTAR', 'href' => url('inicio')],
        ]);
        return $this->render('safe-zone', $page, $data);
    }

    /** POST /safe-zone/assumir — zona + papel. As regras são revalidadas no repositório. */
    public function assume(Request $request, array $params = []): Response
    {
        $this->csrf($request);
        $zona  = (string)$request->input('zone', '');
        $papel = (string)$request->input('role', '');
        $op    = $this->operational();
        $level = (int)($this->player()['level'] ?? 0);

        $res = SafeZoneRepo::assume($this->userId(), $zona, $papel, $op, $level);
        if (!($res['ok'] ?? false)) {
            flash('error', (string)($res['error'] ?? 'Não foi possível assumir o papel.'));
            return $this->redirect('safe-zone');
        }
        flash('ok', 'TURNO ABERTO: ' . (string)$res['role'] . ' · ' . (string)$res['zone']
            . '. Encerra em ' . GameRules::SAFE_SHIFT_MIN . ' min.');
        return $this->redirect('safe-zone');
    }

    /** POST /safe-zone/encerrar — fecha o turno atual. */
    public function leave(Request $request, array $params = []): Response
    {
        $this->csrf($request);
        if (SafeZoneRepo::leave($this->userId())) {
            flash('ok', 'Turno encerrado. Você volta a estar disponível para operação.');
        } else {
            flash('error', 'Não foi possível encerrar o turno.');
        }
        return $this->redirect('safe-zone');
    }

    // ------------------------------------------------------------------------ apoio

    /** @param list<array<string,mixed>> $zones */
    private function zonaSeLiberada(array $zones, ?string $slug): ?string
    {
        if ($slug === null || $slug === '') { return null; }
        foreach ($zones as $z) {
            if ($z['slug'] === $slug) { return empty($z['blocked']) ? $slug : null; }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $zones */
    private function zonaDaBase(array $zones, string $base): ?string
    {
        if ($base === '') { return null; }
        foreach ($zones as $z) {
            if (($z['base_slug'] ?? null) === $base && empty($z['blocked'])) { return (string)$z['slug']; }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $zones */
    private function primeiraLiberada(array $zones): ?string
    {
        foreach ($zones as $z) {
            if (empty($z['blocked'])) { return (string)$z['slug']; }
        }
        return null;
    }
}
