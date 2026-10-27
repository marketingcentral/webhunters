<?php declare(strict_types=1);

/**
 * Prova do CATÁLOGO DE PONTOS DO MAPA (/admin/mapas/pontos), em CLI.
 *
 *   php tools/test_mapa_pontos.php
 *
 * Cobre o que a tela promete e o que o servidor tem de garantir:
 *   1. criar um ponto grava o ícone no formato `origem:valor` e ele aparece na paleta;
 *   2. a chave é gerada a partir do nome quando o operador não a digita;
 *   3. chave repetida é recusada com 422 e o formulário volta com o que foi digitado;
 *   4. `kind` fora do vocabulário do jogo é recusado — ícone novo sim, comportamento não;
 *   5. ícone de origem `glyph` só aceita vetor que a tela do mapa realmente tem;
 *   6. ponto de SISTEMA não pode ser excluído nem ter a chave renomeada;
 *   7. excluir um ponto em uso NÃO apaga os marcadores que o usavam;
 *   8. sem CSRF válido, nada é gravado.
 *
 * Roda sem rede e sem cookie: instancia o controller no próprio processo com uma sessão de
 * administrador simulada. Deixa o catálogo EXATAMENTE como encontrou: ele é fotografado no
 * início e reinserido no fim, com os mesmos ids — inclusive o tipo que o caso 7 exclui de
 * propósito. Pode rodar quantas vezes quiser, em qualquer ordem com as outras suítes.
 */

$RAIZ = dirname(__DIR__);
$_SERVER['SCRIPT_NAME']    = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST']      = '127.0.0.1';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

require $RAIZ . '/app/bootstrap.php';

use App\Controllers\Admin\MapPointsController;
use App\Core\Database;
use App\Core\Request;
use App\Repo\MapPointsRepo;

$falhas = 0;
$ok = static function (string $nome, bool $cond, string $detalhe = '') use (&$falhas): void {
    if (!$cond) { $falhas++; }
    echo ($cond ? 'OK    ' : 'FALHA ') . $nome . ($detalhe !== '' ? ' — ' . $detalhe : '') . "\n";
};

$dono = (int)Database::value("SELECT id FROM users WHERE role IN ('owner','admin') ORDER BY id LIMIT 1");
if ($dono <= 0) { fwrite(STDERR, "nenhuma conta de administrador no banco\n"); exit(1); }
$_SESSION['admin_id']   = $dono;
$_SESSION['admin_seen'] = time();
$_SESSION['_csrf']      = bin2hex(random_bytes(16));

/** Posta o formulário do ponto e devolve o status. */
function postar(string $acao, array $campos, bool $csrfValido = true): int
{
    $_POST = $campos + ['_csrf' => $csrfValido ? (string)$_SESSION['_csrf'] : 'invalido'];
    $_GET  = [];
    $ctl   = new MapPointsController();
    try {
        return $ctl->{$acao}(Request::fromGlobals(), [])->status();
    } catch (\App\Core\HttpException $e) {
        return $e->getCode();
    }
}

/**
 * Esquece o catálogo guardado em memória pelo repositório.
 *
 * Obrigatório depois de qualquer DELETE/INSERT cru feito por este arquivo: o repo guarda
 * `todos()` por requisição, e aqui tudo roda numa requisição só. Sem isto, o controller
 * validaria contra a lista de antes — foi assim que a criação começou a ser recusada por
 * "chave já existe" logo depois de a chave ter sido apagada.
 */
function esquecerCatalogo(): void
{
    $p = new \ReflectionProperty(MapPointsRepo::class, 'cache');
    $p->setAccessible(true);
    $p->setValue(null, null);
}

/** Relê o catálogo do banco. */
function achar(string $slug): ?array
{
    esquecerCatalogo();
    foreach (MapPointsRepo::todos() as $t) {
        if ((string)$t['slug'] === $slug) { return $t; }
    }
    return null;
}

$BASE = [
    'label' => 'Ponto de Teste QA', 'slug' => '', 'category' => 'comercio', 'kind' => 'market',
    'icon_origem' => 'symbol', 'icon_symbol' => 'local_cafe', 'icon_glyph' => 'market', 'icon_image' => '',
    'color' => 'gold', 'layer' => '', 'description' => 'criado por tools/test_mapa_pontos.php',
    'sort_order' => '9000',
];

/* O resíduo de uma execução interrompida sai ANTES da fotografia: fotografá-lo junto faria
   a restauração devolver o lixo ao catálogo, e ele nunca mais sairia. */
Database::run("DELETE FROM map_point_types WHERE slug = 'ponto-de-teste-qa'", []);
esquecerCatalogo();

/**
 * Fotografia do catálogo, para devolvê-lo exatamente como estava.
 *
 * O caso 7 EXCLUI um tipo em uso — é o que ele existe para provar. Sem restaurar, a suíte
 * seguinte (ou a próxima execução desta) encontraria um catálogo com um ponto a menos e
 * reprovaria por um motivo que não é defeito do código. Os ids voltam explícitos porque os
 * marcadores do mundo apontam para o SLUG, mas o log de auditoria aponta para o id.
 */
$catalogo = Database::all('SELECT * FROM map_point_types ORDER BY id');
$restaurar = static function () use ($catalogo): void {
    Database::transaction(static function () use ($catalogo): void {
        Database::run('DELETE FROM map_point_types', []);
        foreach ($catalogo as $t) {
            $cols = implode(', ', array_map(static fn(string $c): string => '`' . $c . '`', array_keys($t)));
            $vals = ':' . implode(', :', array_keys($t));
            Database::run("INSERT INTO map_point_types ($cols) VALUES ($vals)", $t);
        }
    });
    esquecerCatalogo();
};

echo "=== Catálogo de pontos do mapa ===\n\n";

/* ---- 1 e 2: criar, com chave gerada a partir do nome --------------------- */
$st = postar('salvar', $BASE);
$ok('criar responde 303', $st === 303, 'HTTP ' . $st);
$novo = achar('ponto-de-teste-qa');
$ok('chave gerada a partir do nome', $novo !== null, $novo !== null ? $novo['slug'] : 'não encontrado');
$ok('ícone gravado como origem:valor', $novo !== null && $novo['icon'] === 'symbol:local_cafe',
    $novo !== null ? $novo['icon'] : '');
$ok('não nasce como ponto de sistema', $novo !== null && !$novo['is_system']);

/* ---- 3: chave repetida ---------------------------------------------------- */
$st = postar('salvar', ['label' => 'Outro nome', 'slug' => 'ponto-de-teste-qa'] + $BASE);
$ok('chave repetida é recusada com 422', $st === 422, 'HTTP ' . $st);
$ok('e o ponto original continua só uma vez',
    (int)Database::value("SELECT COUNT(*) FROM map_point_types WHERE slug = 'ponto-de-teste-qa'") === 1);

/* ---- 4: kind fora do vocabulário do jogo ---------------------------------- */
$st = postar('salvar', ['id' => (string)$novo['id'], 'kind' => 'kind_inventado'] + $BASE);
$ok('kind desconhecido é recusado com 422', $st === 422, 'HTTP ' . $st);
$ok('e o kind gravado não mudou', (achar('ponto-de-teste-qa')['kind'] ?? '') === 'market');

/* ---- 5: glifo que a tela do mapa não tem ---------------------------------- */
$st = postar('salvar', ['id' => (string)$novo['id'], 'icon_origem' => 'glyph', 'icon_glyph' => 'nao_existe'] + $BASE);
$ok('vetor inexistente é recusado com 422', $st === 422, 'HTTP ' . $st);

/* ---- 6: ponto de sistema ---------------------------------------------------- */
$sistema = achar('extraction');
$ok('o catálogo tem o ponto de sistema "extraction"', $sistema !== null && (bool)$sistema['is_system']);
if ($sistema !== null) {
    $st = postar('excluir', ['id' => (string)$sistema['id']]);
    $ok('excluir ponto de sistema é recusado', $st === 303 && achar('extraction') !== null, 'HTTP ' . $st);

    $st = postar('salvar', ['id' => (string)$sistema['id'], 'slug' => 'extraction-renomeada',
        'label' => 'Extração', 'category' => 'operacional', 'kind' => 'extraction',
        'icon_origem' => 'glyph', 'icon_glyph' => 'extraction', 'icon_symbol' => '', 'icon_image' => '',
        'color' => 'lime', 'layer' => '', 'description' => '', 'sort_order' => '20']);
    $ok('renomear a chave de um ponto de sistema é recusado', $st === 422 && achar('extraction') !== null, 'HTTP ' . $st);
}

/* ---- 7: excluir um ponto EM USO não apaga os marcadores ------------------- */
$emUso = achar('fuel-station');
$antesMk = (int)Database::value("SELECT COUNT(*) FROM map_markers WHERE JSON_UNQUOTE(JSON_EXTRACT(meta,'$.point_type')) = 'fuel-station'");
$ok('há marcadores usando "fuel-station"', $antesMk > 0, $antesMk . ' marcador(es)');
// o ponto é de catálogo (não de sistema): dá para excluir, e os marcadores têm de ficar
$st = postar('excluir', ['id' => (string)$emUso['id']]);
$depoisMk = (int)Database::value("SELECT COUNT(*) FROM map_markers WHERE JSON_UNQUOTE(JSON_EXTRACT(meta,'$.point_type')) = 'fuel-station'");
$ok('excluir o ponto responde 303', $st === 303, 'HTTP ' . $st);
$ok('o ponto saiu do catálogo', achar('fuel-station') === null);
$ok('mas os marcadores continuam no mapa', $depoisMk === $antesMk, $depoisMk . ' de ' . $antesMk);

/* ---- 8: sem CSRF ----------------------------------------------------------- */
$st = postar('salvar', ['id' => (string)$novo['id'], 'label' => 'NOME QUE NÃO PODE ENTRAR'] + $BASE, false);
$ok('POST sem CSRF é recusado com 419', $st === 419, 'HTTP ' . $st);
$ok('e o nome não mudou', (achar('ponto-de-teste-qa')['label'] ?? '') === 'Ponto de Teste QA');

/* ---- limpeza --------------------------------------------------------------- */
/* A fotografia devolve o catálogo inteiro: o ponto de teste some porque não estava nela, e o
   tipo excluído no caso 7 volta com o mesmo id. */
$restaurar();

echo "\n" . ($falhas === 0 ? "TODOS OS CASOS PASSARAM\n" : "$falhas caso(s) falharam\n");
echo "Catálogo restaurado exatamente como estava antes do teste.\n";
exit($falhas === 0 ? 0 : 2);
