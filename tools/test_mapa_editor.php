<?php declare(strict_types=1);

/**
 * Prova do caminho de gravação do EDITOR DE MAPA (/admin/mapas/editor), em CLI.
 *
 *   php tools/test_mapa_editor.php
 *
 * Roda sem rede e sem cookie: instancia o controller no próprio processo com uma sessão de
 * administrador simulada. É o que permite testar a guarda de CSRF e a gravação por diferença
 * sem depender de servidor no ar nem de senha de ninguém.
 *
 * Cobre o que a tela promete e o que o servidor tem de garantir:
 *   1. mover um marcador grava a coordenada nova e SÓ ela;
 *   2. arrastar um ponto novo cria uma linha com o kind do catálogo;
 *   3. tirar um do conjunto apaga aquele, e nenhum outro;
 *   4. os ids dos que ficaram NÃO mudam (gravação por diferença, não por recriar);
 *   5. chaves de `meta` que o editor não edita sobrevivem ao salvamento;
 *   6. linha com coordenada fora de 0..1 é recusada sem derrubar o resto;
 *   7. sem CSRF válido, nada é gravado.
 *
 * Deixa o banco EXATAMENTE como encontrou: o conjunto de marcadores é fotografado no início
 * e reinserido no fim, com os mesmos ids. Pode rodar quantas vezes quiser, em qualquer ordem
 * com as outras suítes.
 */

$RAIZ = dirname(__DIR__);
$_SERVER['SCRIPT_NAME']    = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST']      = '127.0.0.1:8080';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

require $RAIZ . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\Request;
use App\Controllers\Admin\MapEditorController;

$falhas = 0;
$ok = static function (string $nome, bool $cond, string $detalhe = '') use (&$falhas): void {
    if (!$cond) { $falhas++; }
    echo ($cond ? 'OK    ' : 'FALHA ') . $nome . ($detalhe !== '' ? ' — ' . $detalhe : '') . "\n";
};

$dono = (int)Database::value("SELECT id FROM users WHERE role IN ('owner','admin') ORDER BY id LIMIT 1");
$_SESSION['admin_id']   = $dono;
$_SESSION['admin_seen'] = time();
$_SESSION['_csrf']      = bin2hex(random_bytes(16));

const MAPA = 1;

/** Marcadores do mapa, no formato do editor. @return list<array<string,mixed>> */
function lerDoBanco(): array
{
    $out = [];
    foreach (Database::all('SELECT id, kind, label, x, y, meta FROM map_markers WHERE map_id = ' . MAPA . ' ORDER BY id') as $r) {
        $m = json_decode((string)$r['meta'], true) ?: [];
        $out[] = [
            'id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'name' => (string)($m['name'] ?? $r['label']),
            'x' => (float)$r['x'], 'y' => (float)$r['y'],
            'point_type' => (string)($m['point_type'] ?? ''), 'icon' => (string)($m['icon'] ?? ''),
            'color' => (string)($m['color'] ?? 'white'),
            'label' => is_string($m['label'] ?? null) ? $m['label'] : '',
            'radius' => (float)($m['radius'] ?? 0), 'mission_id' => (int)($m['mission_id'] ?? 0),
            '_meta' => $m,
        ];
    }
    return $out;
}

/** Opacidade da arte gravada em `maps.meta`, ou null quando o mapa nada diz. */
function opacidadeNoBanco(): ?float
{
    $v = Database::value("SELECT JSON_UNQUOTE(JSON_EXTRACT(meta, '$.map_opacity')) FROM maps WHERE id = " . MAPA);
    return ($v === null || $v === 'null') ? null : (float)$v;
}

/** Posta o conjunto pelo controller, como o formulário do editor faria. */
function salvar(array $pontos, bool $csrfValido = true, ?string $opacidade = null): int
{
    $_POST = [
        'mapa_id'     => (string)MAPA,
        'opacidade'   => $opacidade ?? '',
        'marcadores'  => json_encode(array_map(static fn(array $p): array => [
            'id' => $p['id'], 'kind' => $p['kind'], 'name' => $p['name'], 'x' => $p['x'], 'y' => $p['y'],
            'point_type' => $p['point_type'], 'icon' => $p['icon'] ?? '', 'color' => $p['color'], 'label' => $p['label'],
            'radius' => $p['radius'], 'mission_id' => $p['mission_id'],
        ], $pontos), JSON_UNESCAPED_UNICODE),
        '_csrf'       => $csrfValido ? (string)$_SESSION['_csrf'] : 'invalido',
    ];
    $ctl = new MapEditorController();
    try {
        return $ctl->salvar(Request::fromGlobals(), [])->status();
    } catch (\App\Core\HttpException $e) {
        return $e->getCode();
    }
}

echo "=== Editor de mapa — gravação ===\n\n";

/**
 * Fotografia crua do mapa, para devolvê-lo exatamente como estava.
 *
 * Este teste MOVE, CRIA e APAGA marcadores de verdade — é o que ele existe para provar. Sem
 * restaurar, rodá-lo duas vezes (ou junto das outras suítes) faria a segunda execução começar
 * sobre o estrago da primeira e reprovar por um motivo que não é defeito do código. Os ids
 * são reinseridos explicitamente: o teste afirma que ids não mudam, e uma restauração que os
 * trocasse tornaria essa afirmação impossível de verificar na execução seguinte.
 */
$fotografia = Database::all('SELECT id, kind, label, x, y, meta FROM map_markers WHERE map_id = ' . MAPA . ' ORDER BY id');
$metaDoMapa = Database::value('SELECT meta FROM maps WHERE id = ' . MAPA);

$restaurar = static function () use ($fotografia, $metaDoMapa): void {
    Database::transaction(static function () use ($fotografia, $metaDoMapa): void {
        Database::run('DELETE FROM map_markers WHERE map_id = ' . MAPA, []);
        foreach ($fotografia as $m) {
            Database::run(
                'INSERT INTO map_markers (id, map_id, kind, label, x, y, meta) VALUES (:i, ' . MAPA . ', :k, :l, :x, :y, :m)',
                ['i' => $m['id'], 'k' => $m['kind'], 'l' => $m['label'], 'x' => $m['x'], 'y' => $m['y'], 'm' => $m['meta']]
            );
        }
        Database::run('UPDATE maps SET meta = :m WHERE id = ' . MAPA, ['m' => $metaDoMapa]);
    });
};

$antes = lerDoBanco();
$ok('banco tem o conjunto do patch v9', count($antes) === 43, count($antes) . ' marcador(es)');

/* ---- caso 1..4: mover um, criar um, apagar um, tudo num envio só ---------- */
$conjunto = $antes;
$movido   = $conjunto[0];
$conjunto[0]['x'] = 0.1234;
$conjunto[0]['y'] = 0.5678;
$apagado  = array_pop($conjunto);
$conjunto[] = [
    'id' => 0, 'kind' => 'fuel', 'name' => 'POSTO DE TESTE', 'x' => 0.42, 'y' => 0.42,
    'point_type' => 'fuel-station', 'icon' => '', 'color' => 'amber', 'label' => '', 'radius' => 0, 'mission_id' => 0,
];

$st = salvar($conjunto);
$ok('POST válido responde 303 (redireciona com aviso)', $st === 303, 'HTTP ' . $st);

$depois = lerDoBanco();
$porId  = [];
foreach ($depois as $d) { $porId[$d['id']] = $d; }

$ok('total continua batendo (43 - 1 + 1)', count($depois) === 43, count($depois) . ' marcador(es)');
$ok('marcador movido gravou a coordenada nova',
    isset($porId[$movido['id']]) && abs($porId[$movido['id']]['x'] - 0.1234) < 1e-6 && abs($porId[$movido['id']]['y'] - 0.5678) < 1e-6,
    isset($porId[$movido['id']]) ? $porId[$movido['id']]['x'] . ' / ' . $porId[$movido['id']]['y'] : 'sumiu');
$ok('marcador removido do conjunto saiu do banco', !isset($porId[$apagado['id']]));
$ok('ponto novo entrou com o kind do catálogo',
    (bool)array_filter($depois, static fn(array $d): bool => $d['name'] === 'POSTO DE TESTE' && $d['kind'] === 'fuel' && $d['point_type'] === 'fuel-station'));

/* 4: os ids dos que ficaram não mudaram — é o que separa gravar por diferença de recriar */
$idsAntes  = array_column($antes, 'id');
$idsDepois = array_column($depois, 'id');
$sobreviventes = array_intersect($idsAntes, $idsDepois);
$ok('ids dos marcadores mantidos não mudaram', count($sobreviventes) === 42, count($sobreviventes) . ' de 42');

/* ---- caso 5: chave de meta que o editor não edita sobrevive --------------- */
$comCasta = null;
foreach ($depois as $d) { if (isset($d['_meta']['caste'])) { $comCasta = $d; break; } }
$ok('marcador com `caste` no meta ainda tem a chave', $comCasta !== null,
    $comCasta !== null ? $comCasta['name'] . ' -> ' . $comCasta['_meta']['caste'] : 'nenhum encontrado');

/* ---- caso 6: coordenada fora da faixa é recusada, o resto passa ------------ */
$conjunto2 = lerDoBanco();
$conjunto2[1]['x'] = 7.5;                 // impossível: a coluna guarda 0..1
$conjunto2[2]['y'] = 0.9111;              // essa é válida e tem de passar
$st2 = salvar($conjunto2);
$d2  = lerDoBanco();
$mapa2 = [];
foreach ($d2 as $d) { $mapa2[$d['id']] = $d; }
$ok('POST com uma linha inválida ainda responde 303', $st2 === 303, 'HTTP ' . $st2);
$ok('linha inválida foi ignorada, mas o marcador NÃO foi apagado',
    isset($mapa2[$conjunto2[1]['id']]) && $mapa2[$conjunto2[1]['id']]['x'] <= 1.0,
    isset($mapa2[$conjunto2[1]['id']]) ? 'x=' . $mapa2[$conjunto2[1]['id']]['x'] : 'sumiu');
$ok('a linha boa do mesmo envio foi gravada',
    isset($mapa2[$conjunto2[2]['id']]) && abs($mapa2[$conjunto2[2]['id']]['y'] - 0.9111) < 1e-6);

/* ---- caso 7: sem CSRF, nada muda ------------------------------------------ */
$conjunto3 = lerDoBanco();
$conjunto3[0]['x'] = 0.999;
$st3 = salvar($conjunto3, false);
$d3  = lerDoBanco();
$ok('POST sem CSRF válido é recusado', $st3 === 419, 'HTTP ' . $st3);
$ok('e nada foi gravado', abs($d3[0]['x'] - $conjunto3[0]['x']) > 1e-6 || abs($d3[0]['x'] - 0.999) > 1e-6);

/* ---- ícone próprio do marcador ------------------------------------------- */
$conjunto5 = lerDoBanco();
$alvo5 = null;
foreach ($conjunto5 as $i => $p) { if ($p['point_type'] === 'fuel-station') { $alvo5 = $i; break; } }
$ok('há um marcador ligado a um ponto do catálogo', $alvo5 !== null);

if ($alvo5 !== null) {
    $conjunto5[$alvo5]['icon'] = 'symbol:ev_station';
    salvar($conjunto5);
    $depois5 = [];
    foreach (lerDoBanco() as $p) { $depois5[$p['id']] = $p; }
    $m5 = $depois5[$conjunto5[$alvo5]['id']] ?? null;
    $ok('ícone próprio do marcador é gravado', ($m5['_meta']['icon'] ?? '') === 'symbol:ev_station',
        (string)($m5['_meta']['icon'] ?? 'ausente'));

    /* Gravar o MESMO ícone do tipo criaria uma cópia que pararia de acompanhar o catálogo:
       o marcador tem de voltar a herdar em vez de congelar o desenho atual. */
    $conjunto5 = lerDoBanco();
    foreach ($conjunto5 as $i => $p) {
        if ($p['id'] === $m5['id']) { $conjunto5[$i]['icon'] = 'glyph:fuel'; break; }
    }
    salvar($conjunto5);
    $depois5 = [];
    foreach (lerDoBanco() as $p) { $depois5[$p['id']] = $p; }
    $ok('ícone igual ao do tipo volta a HERDAR (não grava cópia)',
        !isset($depois5[$m5['id']]['_meta']['icon']),
        (string)($depois5[$m5['id']]['_meta']['icon'] ?? 'herdando'));

    /* forma inválida não pode entrar num campo que a view interpreta */
    $conjunto5 = lerDoBanco();
    foreach ($conjunto5 as $i => $p) {
        if ($p['id'] === $m5['id']) { $conjunto5[$i]['icon'] = 'javascript:alert(1)'; break; }
    }
    salvar($conjunto5);
    $depois5 = [];
    foreach (lerDoBanco() as $p) { $depois5[$p['id']] = $p; }
    $ok('ícone de forma inválida é descartado', !isset($depois5[$m5['id']]['_meta']['icon']),
        (string)($depois5[$m5['id']]['_meta']['icon'] ?? 'descartado'));
}

/* ---- caso 9: opacidade da arte viaja no mesmo envio ----------------------- */
$opOriginal = opacidadeNoBanco();   // conferido adiante; a restauração final é a fotografia
$conjunto4 = lerDoBanco();
salvar($conjunto4, true, '0.42');
$ok('opacidade gravada no meta do mapa', opacidadeNoBanco() === 0.42, var_export(opacidadeNoBanco(), true));

/* fora dos limites é APARADO, não recusado: quem arrasta um controle até o fim quer o
   extremo, e o extremo aceitável é 0,15 — abaixo disso não há mais mapa na tela */
salvar(lerDoBanco(), true, '0.01');
$ok('opacidade abaixo do piso é aparada em 0,15', opacidadeNoBanco() === 0.15, var_export(opacidadeNoBanco(), true));
salvar(lerDoBanco(), true, '9');
$ok('opacidade acima de 1 é aparada em 1', opacidadeNoBanco() === 1.0, var_export(opacidadeNoBanco(), true));

/* campo ausente não pode redefinir a escolha de quem veio antes */
salvar(lerDoBanco(), true, null);
$ok('envio sem o campo mantém a opacidade gravada', opacidadeNoBanco() === 1.0, var_export(opacidadeNoBanco(), true));

/* e as outras chaves do meta continuam lá — JSON_SET não substitui o objeto */
$meta = json_decode((string)Database::value('SELECT meta FROM maps WHERE id = ' . MAPA), true) ?: [];
$ok('o resto do meta do mapa sobreviveu', isset($meta['world_image'], $meta['extractions']),
    implode(', ', array_slice(array_keys($meta), 0, 6)));

/* Marcadores e `maps.meta` voltam de uma vez à fotografia do início — inclusive a opacidade,
   que também vive no meta. É o que torna este arquivo repetível em qualquer ordem. */
$restaurar();

echo "\n" . ($falhas === 0 ? "TODOS OS CASOS PASSARAM\n" : "$falhas caso(s) falharam\n");
echo "Mapa restaurado exatamente como estava antes do teste.\n";
exit($falhas === 0 ? 0 : 2);
