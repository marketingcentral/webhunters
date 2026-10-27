<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\GameRules;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repo\OperationalRepo;

/**
 * FACÇÕES (/faccoes) — PRD v3 §27 e Bíblia §5.
 *
 * Dois cards, AEGIS e FRONTIER, com reputação, faixa, relação, contratos disponíveis e
 * instalações conhecidas. Assinar/romper passa SEMPRE por confirmação explícita: o POST sem
 * `confirm=1` é recusado com 422 — a confirmação não é decoração de interface, é a regra que
 * dá peso ao passo. Não há migração direta entre facções: encerrar o contrato devolve o
 * personagem ao Hunter Command, e só a partir daí é possível assinar com a outra.
 */
final class FactionsController extends Controller
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $data = data_mock('faccoes');
        $op   = $this->operational();
        $data['operational'] = $op;
        $data['factions']    = [];

        foreach (GameRules::FACTIONS as $slug => $f) {
            $rep    = (int)($op['reputation'][$slug] ?? 0);
            $tier   = (string)($op['tiers'][$slug] ?? 'SEM CONTRATO');
            $locked = $op['locked'][$slug] ?? null;
            $lockedAtiva = $locked !== null && strtotime((string)$locked) > time();
            $ativa  = ($op['faction'] ?? null) === $slug;
            $outra  = ($op['faction'] ?? null) !== null && !$ativa;
            $surv   = $op['state'] === GameRules::STATE_SURVIVOR;

            $relKey = $surv ? 'survivor' : ($lockedAtiva ? 'locked' : ($ativa ? 'active' : ($outra ? 'rival' : 'none')));
            // Sem migração direta: para trocar de facção é preciso ENCERRAR o contrato atual
            // (volta ao Hunter Command) e só então assinar o novo.
            $ctaKey = $surv ? 'survivor' : ($lockedAtiva ? 'locked' : ($ativa ? 'active' : ($outra ? 'blocked' : 'sign')));

            $data['factions'][] = [
                'slug'          => $slug,
                'label'         => $f['label'],
                'color'         => $f['color'],
                'tagline'       => $f['tagline'],
                'identity'      => $f['identity'],
                'economy'       => $f['economy'],
                'missions'      => $f['missions'],
                'npc'           => $f['npc'],
                'reputation'    => $rep,
                'tier'          => $ativa || $rep > 0 ? GameRules::repTier($rep) : $tier,
                'next_tier'     => $this->proximaFaixa($rep),
                'relation_key'  => $relKey,
                'relation'      => (string)($data['relation'][$relKey] ?? ''),
                'active'        => $ativa,
                'locked_until'  => $lockedAtiva ? (string)$locked : null,
                'cta_key'       => $ctaKey,
                'cta'           => (string)($data['cta'][$ctaKey] ?? ''),
                'can_sign'      => $ctaKey === 'sign',
                'needs_confirm' => true,
                'contracts'     => $this->contratos($slug),
                'installations' => $this->instalacoes($slug),
                'confirm'       => $this->textoConfirmacao((array)($data['confirm'] ?? []), $op, $slug, $f['label']),
            ];
        }

        $page = $this->page('faccoes', 'Facções', ['back' => ['label' => 'VOLTAR', 'href' => url('jogar')]]);
        return $this->render('faccoes', $page, $data);
    }

    /** POST /faccoes/assinar — exige `faction` e `confirm=1`. */
    public function sign(Request $request, array $params = []): Response
    {
        $this->csrf($request);
        $faction = strtolower((string)$request->input('faction', ''));
        $confirm = $request->input('confirm', '') === '1';
        $uid     = $this->userId();

        if (!isset(GameRules::FACTIONS[$faction])) {
            throw new HttpException(404, 'Facção desconhecida');
        }
        if ($uid <= 0) {
            flash('error', 'Entre na sua conta para assinar um contrato.');
            return $this->redirect('faccoes');
        }
        if (!$confirm) {
            // A interface tem de pedir confirmação explícita (PRD §7.2). Sem ela: 422 e nada
            // muda — a própria tela volta, com o aviso, para quem chegou aqui sem o modal.
            flash('error', 'Confirme a troca de contrato antes de continuar.');
            $tela = $this->index($request);
            return new Response($tela->body(), 422, [
                'Content-Type' => Request::wantsPartial() ? 'application/json; charset=UTF-8' : 'text/html; charset=UTF-8',
            ]);
        }

        // Sem migração direta (regra de tela E de servidor): quem já tem contrato precisa
        // encerrá-lo antes — encerrar devolve ao Hunter Command sem zerar a reputação.
        $antes = $this->operational();
        $atual = $antes['faction'] ?? null;
        if ($atual !== null && $atual !== $faction) {
            flash('error', 'Encerre o contrato com ' . (GameRules::FACTIONS[$atual]['label'] ?? strtoupper($atual)) . ' antes de assinar com ' . GameRules::FACTIONS[$faction]['label'] . '.');
            return $this->redirect('faccoes');
        }
        $res   = OperationalRepo::sign($uid, $faction, true);
        if (!($res['ok'] ?? false)) {
            flash('error', (string)($res['error'] ?? 'Não foi possível assinar o contrato.'));
            return $this->redirect('faccoes');
        }
        $label = GameRules::FACTIONS[$faction]['label'];
        $broke = (string)($res['broke'] ?? '');
        flash('ok', $broke !== ''
            ? 'CONTRATO ASSINADO: ' . $label . '. Reputação com ' . GameRules::FACTIONS[$broke]['label'] . ' redefinida para 0.'
            : 'CONTRATO ASSINADO: ' . $label . '. IFF do Hunter Command suspenso sob a Licença Cinza.');
        return $this->redirect('faccoes');
    }

    /** POST /faccoes/romper — encerra o contrato atual e devolve ao Hunter Command (não zera). */
    public function release(Request $request, array $params = []): Response
    {
        $this->csrf($request);
        $uid = $this->userId();
        if ($uid <= 0 || $request->input('confirm', '') !== '1') {
            flash('error', 'Confirme o encerramento do contrato antes de continuar.');
        } elseif (OperationalRepo::release($uid)) {
            flash('ok', 'Contrato encerrado. Você volta a operar sob o Hunter Command.');
        } else {
            flash('error', 'Sistema de facções indisponível neste servidor. Tente novamente mais tarde.');
        }
        return $this->redirect('faccoes');
    }

    // ------------------------------------------------------------------------ apoio

    private function contratos(string $faction): int
    {
        try {
            return (int)Database::value('SELECT COUNT(*) FROM missions WHERE origin = :f', ['f' => $faction]);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return list<string> */
    private function instalacoes(string $faction): array
    {
        try {
            $rows = Database::all('SELECT label FROM map_markers WHERE kind = :k ORDER BY id', ['k' => $faction]);
            return array_values(array_filter(array_map(static fn(array $r): string => (string)($r['label'] ?? ''), $rows)));
        } catch (\Throwable) {
            return [];
        }
    }

    private function proximaFaixa(int $rep): string
    {
        foreach (GameRules::REP_TIERS as $t) {
            if ($rep < $t['min']) { return $t['label'] . ' EM ' . num($t['min'] - $rep) . ' REP'; }
        }
        return 'FAIXA MÁXIMA';
    }

    /**
     * Texto do modal de confirmação, já com os nomes certos.
     * @param array<string,string> $c
     * @return array{title:string,body:string,ok:string,cancel:string}
     */
    private function textoConfirmacao(array $c, array $op, string $slug, string $label): array
    {
        $atual = GameRules::faction($op['faction'] ?? null);
        if ($atual !== null && ($op['faction'] ?? null) !== $slug) {
            return [
                'title'  => (string)($c['title'] ?? 'ROMPER CONTRATO?'),
                'body'   => sprintf((string)($c['body'] ?? ''), $atual['label'], $label) . ' ' . sprintf((string)($c['body_new'] ?? ''), $atual['label'], $label),
                'ok'     => sprintf((string)($c['ok'] ?? 'ROMPER E ASSINAR %2$s'), $atual['label'], $label),
                'cancel' => (string)($c['cancel'] ?? 'CANCELAR'),
            ];
        }
        return [
            'title'  => (string)($c['title_first'] ?? 'ASSINAR CONTRATO?'),
            'body'   => sprintf((string)($c['body_first'] ?? ''), '', $label),
            'ok'     => sprintf((string)($c['ok_first'] ?? 'ASSINAR %2$s'), '', $label),
            'cancel' => (string)($c['cancel'] ?? 'CANCELAR'),
        ];
    }
}
