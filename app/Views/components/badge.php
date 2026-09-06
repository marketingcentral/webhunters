<?php declare(strict_types=1);
/**
 * Badge de contexto operacional — component('badge', $props)
 *
 * UM componente para os onze badges do PRD v3 §37. Todos eles são a mesma coisa visual (o
 * `chip` que já existe, com cor do estado) mudando só a regra de rótulo/cor — então a regra
 * fica aqui e o chip continua sendo o único desenho. Mapeamento para os nomes do PRD:
 *
 *   kind=state        OperationalStatusBadge   HUNTER COMMAND · CABO / MERCENÁRIO · AEGIS / SOBREVIVENTE · SEM EXTRAÇÃO
 *   kind=faction      FactionBadge             AEGIS (ciano) / FRONTIER (âmbar)
 *   kind=rank         HunterRankBadge          RECRUTA … CAPITÃO
 *   kind=role         HunterRoleBadge          ASSAULT / SNIPER / MEDICAL / RASTREADOR (tooltip = descrição canônica)
 *   kind=activity     WorldActivityBadge / HumanActivityIndicator   BAIXA / MODERADA / ELEVADA / CRÍTICA
 *   kind=base         BaseStatusBadge          OPERACIONAL / LOTADA / EM RISCO / ISOLADA / SOB ATAQUE
 *   kind=extraction   ExtractionStatusBadge    tipo (EVAC HUNTER…) e/ou estado (ATIVA/CONDICIONAL/INATIVA/BLOQUEADA)
 *   kind=requirement  ContractRequirementBadge REQUER CONTRATO AEGIS / FRONTIER — CONFIÁVEL
 *   kind=origin       (origem de missão)       HUNTER COMMAND / AEGIS / FRONTIER / ICARUS / EVENTO DE MUNDO
 *   kind=discipline   DisciplineStatusBadge    REGULAR / EM REVISÃO / PUNIDO
 *
 * props:
 *  kind    : string  um dos acima
 *  value   : mixed   state -> array `operational` (ou o array de OperationalRepo::badge()); faction/rank/role/
 *                    activity/base/origin/discipline -> string; extraction -> ['type'=>..,'state'=>..];
 *                    requirement -> ['faction'=>'aegis','tier'=>'CONFIÁVEL'|null]
 *  level   : int     só para state: entra no complemento "NÍVEL 23"
 *  label   : string  sobrescreve o rótulo calculado
 *  sub     : bool    state: anexa o complemento (" · NÍVEL 23") ao rótulo (default false)
 *  icon    : bool    role/state: mostra o ícone da função / a faixa branca (default true)
 *  lg, solid, dot, class, tip : repassados ao chip
 *
 * Sobrevivente usa `chip--white` (off-white, §13) e um glifo de faixa em vez do ícone de função.
 */

use App\Core\GameRules;
use App\Repo\OperationalRepo;

$p     = $props ?? [];
$kind  = (string)($p['kind'] ?? 'state');
$v     = $p['value'] ?? null;
$label = null; $color = 'muted'; $tip = (string)($p['tip'] ?? ''); $icon = null; $svg = null; $extra = '';

switch ($kind) {
    case 'state':
        $b = is_array($v) && isset($v['label'], $v['color']) ? $v : OperationalRepo::badge(is_array($v) ? $v : OperationalRepo::defaults(), (int)($p['level'] ?? 0));
        $label = (string)$b['label'];
        if (!empty($p['sub'])) { $label .= ' · ' . $b['sub']; }
        $color = (string)$b['color'];
        if (!empty($b['survivor'])) { $extra .= ' chip--survivor'; }
        elseif (($p['icon'] ?? true) && ($b['state'] ?? '') === GameRules::STATE_HUNTER) { $svg = 'emblem'; }
        break;

    case 'faction':
        $f = GameRules::faction(is_string($v) ? $v : null);
        $label = $f['label'] ?? strtoupper((string)$v);
        $color = $f['color'] ?? 'muted';
        break;

    case 'rank':
        $label = mb_strtoupper((string)$v);
        $color = GameRules::rankIndex($label) >= 3 ? 'lime' : 'muted';   // sargento para cima acende
        break;

    case 'role':
        $key = GameRules::role(is_string($v) ? $v : null);
        $r   = $key !== null ? GameRules::ROLES[$key] : null;
        $label = $r['label'] ?? mb_strtoupper((string)$v);
        $color = 'lime';
        if ($r !== null && ($p['icon'] ?? true)) { $svg = $r['icon']; }
        if ($r !== null && $tip === '') { $tip = $r['desc']; }
        break;

    case 'activity':
        $label = mb_strtoupper((string)$v);
        $color = GameRules::activityColor($label);
        break;

    case 'base':
        $label = mb_strtoupper((string)$v);
        $color = GameRules::BASE_STATUS[$label] ?? 'muted';
        break;

    case 'extraction':
        $type  = is_array($v) ? (string)($v['type'] ?? '') : (string)$v;
        $state = is_array($v) ? (string)($v['state'] ?? '') : '';
        $t = GameRules::EXTRACTION_TYPES[strtolower($type)] ?? null;
        $s = GameRules::EXTRACTION_STATES[strtolower($state)] ?? null;
        $label = $s !== null && $t === null ? $s['label'] : ($t['label'] ?? mb_strtoupper($type));
        $color = $state === 'blocked' ? 'muted' : ($s !== null && $t === null ? $s['color'] : ($t['color'] ?? 'muted'));
        if ($state === 'blocked' && $tip === '') { $tip = GameRules::SURVIVOR_EXTRACTION_TIP; }
        elseif ($t !== null && $tip === '') { $tip = $t['who']; }
        break;

    case 'requirement':
        $fs = is_array($v) ? (string)($v['faction'] ?? '') : (string)$v;
        $tier = is_array($v) ? (string)($v['tier'] ?? '') : '';
        $f = GameRules::faction($fs);
        // faction vazia = "qualquer facção" (missions.requires_faction = any, ex.: ARQUIVOS VESPER)
        $fl = $fs === '' ? 'MERCENÁRIO' : ($f['label'] ?? mb_strtoupper($fs));
        $label = $tier !== '' ? $fl . ' — ' . mb_strtoupper($tier) : 'REQUER CONTRATO ' . $fl;
        $color = $f['color'] ?? 'muted';
        $icon  = 'lock';
        if ($tip === '') {
            $com = $fs === '' ? 'qualquer facção' : $fl;
            $tip = $tier !== '' ? 'Reputação mínima com ' . $com . ': ' . mb_strtoupper($tier) . '.' : 'Exige contrato mercenário ativo com ' . $com . '.';
        }
        break;

    case 'origin':
        $o = GameRules::origin(is_string($v) ? $v : null);
        $label = $o['label'];
        $color = $o['color'];
        break;

    case 'discipline':
        $label = mb_strtoupper((string)$v);
        $color = match ($label) { 'REGULAR' => 'lime', 'EM REVISÃO' => 'amber', 'PUNIDO' => 'red', default => 'muted' };
        break;
}

if (isset($p['label'])) { $label = (string)$p['label']; }

// `white` não é cor do chip: vira a variante survivor (off-white) definida em components.css.
$chipColor = in_array($color, ['lime', 'gold', 'cyan', 'amber', 'red', 'purple', 'muted'], true) ? $color : '';

component('chip', [
    'label' => (string)$label,
    'color' => $chipColor,
    'svg'   => $svg,
    'icon'  => $icon,
    'lg'    => !empty($p['lg']),
    'solid' => !empty($p['solid']),
    'dot'   => !empty($p['dot']),
    'tip'   => $tip,
    'class' => trim('badge badge--' . e($kind) . $extra . ' ' . (string)($p['class'] ?? '')),
]);
