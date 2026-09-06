<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Tela SAÚDE conciliada por user_id.
 *
 * Fonte de dados (Fase 2):
 *  - Sinais vitais + estado corporal (membros) vêm de players.health (JSON) via PlayerRepo::forUser()[health].
 *  - ITENS DE CURA vêm dos itens da categoria "med" no inventário do jogador (player_items JOIN items).
 *
 * Os textos/labels de layout (títulos, exames, condições, aba STATUS, regeneração e ações)
 * continuam vindos de app/Data/saude.php e são apenas complementados — o visual aprovado é
 * preservado, só a origem dos dados dinâmicos muda.
 */
final class HealthController extends Controller
{
    /** health.saude → chave do vital na view; recomputa value/pct sem redesenhar. */
    private const VITAL_OF = [
        'health'      => 'saude',
        'energy'      => 'energia',
        'hydration'   => 'hidratacao',
        'hunger'      => 'fome',
        'temperature' => 'temperatura',
    ];

    /** parte do corpo na view → chave do membro em health.limbs. */
    private const LIMB_OF = [
        'head'      => 'cabeca',
        'thorax'    => 'torax',
        'stomach'   => 'estomago',
        'arm-left'  => 'braco_esq',
        'arm-right' => 'braco_dir',
        'leg-left'  => 'perna_esq',
        'leg-right' => 'perna_dir',
    ];

    /** Cura restaurada por subtipo do item médico (usado pelo botão USAR / histórico). */
    private const HEAL_OF = [
        'medkit'    => 100,
        'surgical'  => 200,
        'bandage'   => 50,
        'painkiller' => 25,
    ];

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $data = data_mock('saude');
        $tab  = $request->query('aba', 'tratamento');
        $data['active_tab'] = in_array($tab, ['tratamento', 'status'], true) ? $tab : 'tratamento';

        $uid = $this->userId();
        if ($uid > 0) {
            $player = $this->player();
            $health = (array)($player['health'] ?? []);

            if ($health !== []) {
                $data['vitals'] = $this->vitals((array)($data['vitals'] ?? []), $health);
                $data['body']   = $this->body((array)($data['body'] ?? []), $health);

                $saude = (float)($health['saude'] ?? 100);
                if (isset($data['details']['health'])) {
                    $data['details']['health']['value'] = $saude;
                }
            }

            $healing = $this->healingItems($uid);
            if ($healing !== []) {
                $data['healing'] = $healing;
            }
        }

        $page = $this->page('saude', 'Saúde', ['bg' => 'bg/saude.jpg']);
        return $this->render('saude', $page, $data);
    }

    /**
     * Recalcula os 5 sinais vitais a partir de players.health, mantendo label/ícone/classe/
     * status/unidade do layout aprovado — só value e pct passam a vir do banco.
     *
     * @param list<array<string,mixed>> $template
     * @param array<string,mixed>       $health
     * @return list<array<string,mixed>>
     */
    private function vitals(array $template, array $health): array
    {
        $out = [];
        foreach ($template as $v) {
            $key = (string)($v['key'] ?? '');
            $src = self::VITAL_OF[$key] ?? null;
            if ($src !== null && array_key_exists($src, $health)) {
                $value = (float)$health[$src];
                $v['value'] = $value;
                $max = $v['max'] ?? null;
                if ($max !== null && (float)$max > 0.0) {
                    $v['pct'] = max(0.0, min(100.0, $value / (float)$max * 100.0));
                }
            }
            $out[] = $v;
        }
        return $out;
    }

    /**
     * Estado corporal (3 partes centrais + 4 membros) a partir de health.limbs/limbs_max,
     * preservando rótulos, ícones e colunas do layout.
     *
     * @param list<array<string,mixed>> $template
     * @param array<string,mixed>       $health
     * @return list<array<string,mixed>>
     */
    private function body(array $template, array $health): array
    {
        $cur = (array)($health['limbs'] ?? []);
        $max = (array)($health['limbs_max'] ?? []);
        $out = [];
        foreach ($template as $p) {
            $part = (string)($p['part'] ?? '');
            $limb = self::LIMB_OF[$part] ?? null;
            if ($limb !== null && array_key_exists($limb, $cur)) {
                $p['cur'] = (float)$cur[$limb];
                $p['max'] = (float)($max[$limb] ?? $cur[$limb]);
            }
            $out[] = $p;
        }
        return $out;
    }

    /**
     * ITENS DE CURA: itens da categoria "med" (subtipos de tratamento, não diagnósticos)
     * que o jogador possui no inventário, agrupados por item. count = quantidade em posse.
     *
     * @return list<array<string,mixed>>
     */
    private function healingItems(int $uid): array
    {
        $rows = Database::all(
            'SELECT it.slug, it.name, it.image, it.subtype, it.description, SUM(pi.quantity) AS qty
             FROM player_items pi JOIN items it ON it.id = pi.item_id
             WHERE pi.user_id = :u AND it.category = :cat AND it.subtype <> :diag
             GROUP BY it.id, it.slug, it.name, it.image, it.subtype, it.description
             ORDER BY FIELD(it.subtype, "medkit", "bandage", "painkiller", "surgical"), it.name',
            ['u' => $uid, 'cat' => 'med', 'diag' => 'diagnostic']
        );

        $out = [];
        foreach ($rows as $r) {
            $count = (int)$r['qty'];
            $out[] = [
                'slug'  => (string)$r['slug'],
                'name'  => (string)$r['name'],
                'image' => (string)$r['image'],
                'count' => $count,
                'total' => $count,
                'desc'  => (string)($r['description'] ?? ''),
                'cta'   => 'USAR',
                'heal'  => self::HEAL_OF[(string)$r['subtype']] ?? 0,
            ];
        }
        return $out;
    }
}
