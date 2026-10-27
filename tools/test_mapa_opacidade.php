<?php declare(strict_types=1);

/**
 * Prova da OPACIDADE DA ARTE DO MAPA: padrão do painel × preferência do jogador.
 *
 *   php tools/test_mapa_opacidade.php
 *
 * Duas fontes decidem quanto a arte recua para o texto da HUD ser lido por cima dela, e a
 * ordem entre elas é a regra que este arquivo protege:
 *
 *   1. sem preferência, vale o que o painel gravou no mapa;
 *   2. sem nada gravado em lugar nenhum, vale o padrão do código;
 *   3. a escolha do jogador VENCE a do painel — e continua valendo no próximo login;
 *   4. valor fora de 0,15..1 é aparado, não recusado;
 *   5. valor ilegível não apaga a escolha anterior;
 *   6. voltar ao padrão APAGA a linha (não grava o número), para quem nunca mexeu continuar
 *      acompanhando o painel quando a arte for reajustada;
 *   7. a escolha de um jogador não vaza para outro;
 *   8. sem CSRF válido nada é gravado.
 *
 * Roda sem rede e sem cookie: instancia o controller no próprio processo. Deixa o banco como
 * encontrou — a preferência criada é apagada no fim.
 */

$RAIZ = dirname(__DIR__);
$_SERVER['SCRIPT_NAME']    = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST']      = '127.0.0.1';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

require $RAIZ . '/app/bootstrap.php';

use App\Controllers\MapController;
use App\Core\Database;
use App\Core\Request;
use App\Repo\SettingsRepo;

$falhas = 0;
$ok = static function (string $nome, bool $cond, string $detalhe = '') use (&$falhas): void {
    if (!$cond) { $falhas++; }
    echo ($cond ? 'OK    ' : 'FALHA ') . $nome . ($detalhe !== '' ? ' — ' . $detalhe : '') . "\n";
};

const CHAVE = 'mapa.opacidade';

$uid = (int)Database::value('SELECT id FROM users ORDER BY id LIMIT 1');
$outro = (int)Database::value('SELECT id FROM users WHERE id <> :u ORDER BY id LIMIT 1', ['u' => $uid]);
if ($uid <= 0) { fwrite(STDERR, "nenhum usuário no banco\n"); exit(1); }

/* Estado original, para devolver tudo no fim.
 *
 * O SEGUNDO jogador também entra aqui. O caso "a escolha de um não vaza para o outro" mede o
 * que a tela DELE mostra, então uma preferência que ele já tivesse — de uso real ou de uma
 * execução anterior interrompida — reprovaria o caso sem que nada estivesse errado no código.
 * Guardar e limpar os dois é o que torna a comparação honesta. */
$prefOriginal = SettingsRepo::forUser($uid)[CHAVE] ?? null;
$prefDoOutro  = $outro > 0 ? (SettingsRepo::forUser($outro)[CHAVE] ?? null) : null;
$metaOriginal = Database::value('SELECT meta FROM maps WHERE id = 1');

if ($outro > 0) { SettingsRepo::reset($outro, [CHAVE]); }

/**
 * Esquece o que os repositórios guardaram NESTA requisição.
 *
 * Em produção cada requisição nasce com os caches vazios; aqui tudo roda num processo só, e
 * sem isto a segunda renderização devolveria o mapa lido antes de o teste alterá-lo — e o
 * teste estaria medindo memória, não banco. É também o que faz "próximo login" significar
 * alguma coisa: o valor tem de voltar a ser buscado.
 */
function esquecerCaches(): void
{
    // o valor "vazio" de cada cache é o que a própria classe declara: array para os dois
    // primeiros, null para o de pontos (que usa null como "ainda não li")
    $vazios = [
        \App\Repo\CatalogRepo::class   => [],
        \App\Repo\SettingsRepo::class  => [],
        \App\Repo\MapPointsRepo::class => null,
    ];
    foreach ($vazios as $classe => $vazio) {
        $p = new \ReflectionProperty($classe, 'cache');
        $p->setAccessible(true);
        $p->setValue(null, $vazio);
    }
}

/** Renderiza /mapa e devolve a opacidade que a tela usaria. */
function opacidadeDaTela(int $uid): array
{
    esquecerCaches();
    $_SESSION['user_id'] = $uid;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = $_POST = [];
    unset($_SERVER['HTTP_X_REQUESTED_WITH']);

    /* O controller monta $data e a view escreve --map-art-opacity; ler o HTML é o que prova
       que o número chega até a tela, e não só até uma variável. */
    $res  = (new MapController())->index(Request::fromGlobals(), []);
    $html = $res->body();
    preg_match('/--map-art-opacity:([0-9.]+)/', $html, $m);
    preg_match('/data-padrao="(\d+)"/', $html, $p);
    return ['usada' => isset($m[1]) ? (float)$m[1] : -1.0, 'padrao' => isset($p[1]) ? (int)$p[1] : -1];
}

/** Posta a preferência pelo controller, como o controle da tela faria. */
function postar(int $uid, array $campos, bool $csrfValido = true): int
{
    $_SESSION['user_id'] = $uid;
    $_SESSION['_csrf'] ??= bin2hex(random_bytes(16));
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_POST = $campos + ['_csrf' => $csrfValido ? (string)$_SESSION['_csrf'] : 'invalido'];

    try {
        return (new MapController())->opacidade(Request::fromGlobals(), [])->status();
    } catch (\App\Core\HttpException $e) {
        return $e->getCode();
    }
}

echo "=== Opacidade da arte do mapa ===\n\n";

/* ---- 1 e 2: o padrão vem do painel, ou do código quando o mapa nada diz ---- */
SettingsRepo::reset($uid, [CHAVE]);
Database::run("UPDATE maps SET meta = JSON_REMOVE(meta, '$.map_opacity') WHERE id = 1");
$t = opacidadeDaTela($uid);
$ok('sem nada gravado, vale o padrão do código (0,65)', abs($t['usada'] - 0.65) < 0.001, (string)$t['usada']);

Database::run("UPDATE maps SET meta = JSON_SET(COALESCE(meta,'{}'), '$.map_opacity', 0.40) WHERE id = 1");
$t = opacidadeDaTela($uid);
$ok('sem preferência, vale o que o painel gravou', abs($t['usada'] - 0.40) < 0.001, (string)$t['usada']);
$ok('a tela leva o padrão do painel para o botão de voltar', $t['padrao'] === 40, (string)$t['padrao']);

/* ---- 3: a escolha do jogador vence e sobrevive ao próximo login ------------ */
$st = postar($uid, ['valor' => '0.9']);
$ok('gravar a preferência responde 200', $st === 200, 'HTTP ' . $st);
$ok('a preferência do jogador vence a do painel', abs(opacidadeDaTela($uid)['usada'] - 0.90) < 0.001);

/* "Próximo login" é exatamente isto: nada em memória, o valor tem de voltar do banco. O que
   importa provar é a origem do número, não que o PHP troca de cookie em linha de comando. */
esquecerCaches();
$daTabela = (float)(\App\Repo\SettingsRepo::forUser($uid)[CHAVE] ?? -1);
$ok('a preferência ficou gravada na conta (e não só na sessão)', abs($daTabela - 0.90) < 0.001, (string)$daTabela);
$ok('e continua valendo quando a tela é montada do zero',
    abs(opacidadeDaTela($uid)['usada'] - 0.90) < 0.001);

/* ---- 4: aparar, não recusar ----------------------------------------------- */
postar($uid, ['valor' => '0.01']);
$ok('abaixo do piso é aparado em 0,15', abs(opacidadeDaTela($uid)['usada'] - 0.15) < 0.001);
postar($uid, ['valor' => '7']);
$ok('acima de 1 é aparado em 1', abs(opacidadeDaTela($uid)['usada'] - 1.0) < 0.001);

/* ---- 5: lixo não apaga a escolha anterior --------------------------------- */
$st = postar($uid, ['valor' => 'meio-transparente']);
$ok('valor ilegível é recusado com 422', $st === 422, 'HTTP ' . $st);
$ok('e a escolha anterior continua valendo', abs(opacidadeDaTela($uid)['usada'] - 1.0) < 0.001);

/* ---- 6: voltar ao padrão apaga a linha ------------------------------------ */
postar($uid, ['reset' => '1']);
$ok('voltar ao padrão APAGA a preferência', !isset(SettingsRepo::forUser($uid)[CHAVE]),
    (string)(SettingsRepo::forUser($uid)[CHAVE] ?? 'apagada'));
$ok('e a tela volta ao valor do painel', abs(opacidadeDaTela($uid)['usada'] - 0.40) < 0.001);

/* quem voltou ao padrão acompanha um reajuste do painel — é o motivo de apagar em vez de gravar */
Database::run("UPDATE maps SET meta = JSON_SET(meta, '$.map_opacity', 0.55) WHERE id = 1");
$ok('e passa a acompanhar o painel quando a arte é reajustada',
    abs(opacidadeDaTela($uid)['usada'] - 0.55) < 0.001, (string)opacidadeDaTela($uid)['usada']);

/* ---- 7: a preferência é da conta, não do servidor -------------------------- */
if ($outro > 0) {
    postar($uid, ['valor' => '0.20']);
    $ok('a escolha de um jogador não vaza para outro',
        abs(opacidadeDaTela($outro)['usada'] - 0.55) < 0.001, (string)opacidadeDaTela($outro)['usada']);
} else {
    echo "(pulado: só há um usuário no banco para conferir isolamento)\n";
}

/* ---- 8: sem CSRF ----------------------------------------------------------- */
$st = postar($uid, ['valor' => '0.99'], false);
$ok('POST sem CSRF válido é recusado', $st === 419, 'HTTP ' . $st);
$ok('e nada foi gravado', abs(opacidadeDaTela($uid)['usada'] - 0.20) < 0.001);

/* ---- limpeza --------------------------------------------------------------- */
SettingsRepo::reset($uid, [CHAVE]);
if ($prefOriginal !== null) { SettingsRepo::save($uid, [CHAVE => $prefOriginal]); }
if ($outro > 0) {
    SettingsRepo::reset($outro, [CHAVE]);
    if ($prefDoOutro !== null) { SettingsRepo::save($outro, [CHAVE => $prefDoOutro]); }
}
Database::run('UPDATE maps SET meta = :m WHERE id = 1', ['m' => $metaOriginal]);

echo "\n" . ($falhas === 0 ? "TODOS OS CASOS PASSARAM\n" : "$falhas caso(s) falharam\n");
exit($falhas === 0 ? 0 : 2);
