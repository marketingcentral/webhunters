<?php declare(strict_types=1);

/**
 * VARREDURA DA LINHA DE BASE DO CEF — o que a HUD não pode usar porque roda dentro da Unreal.
 *
 *   php tools/varrer_cef90.php            só o que o jogo carrega (sai 2 se achar algo)
 *   php tools/varrer_cef90.php --tudo     inclui o painel /admin, que roda em navegador de verdade
 *
 * POR QUE ESTE ARQUIVO EXISTE
 * O site não é só um site: ele é a HUD dentro do jogo, carregada pelo CEF embarcado na Unreal.
 * O UE 5.6 traja CEF 90.6 — Chromium 90, de abril de 2021. Quando uma folha de estilo usa algo
 * mais novo, o Chromium 90 DESCARTA a declaração inteira em silêncio: nada quebra no navegador
 * onde a tela foi feita, e dentro do jogo a regra simplesmente não existe.
 *
 * Foi assim que `height:100dvh` (Chrome 108) tirou a altura da HUD dentro da Unreal: a página
 * passou a medir o conteúdo, ficou 320px mais alta que a janela, e o rodapé e o pé do mapa
 * saíram cortados. O defeito não aparecia em teste nenhum feito fora do jogo.
 *
 * O QUE ESTA VARREDURA NÃO É
 * Não é um polyfill nem um proibidor. Recurso novo pode entrar desde que venha com o
 * equivalente antigo ANTES dele, na mesma regra — `height:100vh` e então `height:100dvh`. Por
 * isso cada achado é conferido contra o fallback correspondente e só vira falha quando ele não
 * está lá. O objetivo é que ninguém descubra a ausência abrindo o jogo.
 */

$RAIZ = dirname(__DIR__);
$TUDO = in_array('--tudo', $argv, true);

/** Versão do Chromium dentro do CEF da Unreal. Trocou de engine? Atualize aqui e reavalie. */
const CHROMIUM_CEF = 90;

/**
 * [padrão, versão do Chrome que introduziu, o que fazer].
 *
 * `fallback` é o padrão que, se estiver no MESMO arquivo, prova que o autor já cuidou do caso
 * antigo. Quando não existe fallback possível (um seletor, por exemplo), fica null e o achado
 * é reportado como AVISO em vez de falha — a tela continua utilizável, só menos bonita.
 */
$REGRAS = [
    // --- CSS que muda LAYOUT: ausência quebra a tela ------------------------------------
    ['nome' => 'unidades dvh/svh/lvh',   'chrome' => 108, 'regex' => '/\b\d[\d.]*(dvh|svh|lvh|dvw|svw|lvw)\b/',
     'fallback' => '/\b\d[\d.]*vh\b|\b\d[\d.]*vw\b/', 'grave' => true,
     'dica' => 'declare a mesma propriedade com vh/vw na linha ANTERIOR'],

    ['nome' => 'seletor :modal',          'chrome' => 105, 'regex' => '/:modal\b/',
     'fallback' => '/^dialog\{|\bdialog\s*\{/m', 'grave' => true,
     'dica' => 'repita a regra em `dialog{…}`, que é o padrão da folha do navegador'],

    ['nome' => 'seletor :has()',          'chrome' => 105, 'regex' => '/:has\(/',
     'fallback' => null, 'grave' => false,
     'dica' => 'a regra inteira é descartada no CEF; garanta que o estado sem ela ainda serve'],

    ['nome' => '@container',              'chrome' => 105, 'regex' => '/@container\b/',
     'fallback' => null, 'grave' => true,
     'dica' => 'sem consulta de contêiner no CEF; use media query ou grade fluida'],

    ['nome' => 'subgrid',                 'chrome' => 117, 'regex' => '/\bsubgrid\b/',
     'fallback' => null, 'grave' => true,
     'dica' => 'o valor é inválido no CEF e a linha some; repita as trilhas na mão'],

    ['nome' => 'aninhamento de CSS',      'chrome' => 112, 'regex' => '/^\s*&[\s.:#\[>+~]/m',
     'fallback' => null, 'grave' => true,
     'dica' => 'escreva o seletor completo; o CEF não aninha'],

    ['nome' => '@layer',                  'chrome' => 99,  'regex' => '/@layer\b/',
     'fallback' => null, 'grave' => true,
     'dica' => 'no CEF a cascata inteira da camada é ignorada'],

    ['nome' => 'propriedade translate/rotate/scale', 'chrome' => 104, 'regex' => '/^\s*(translate|rotate|scale)\s*:/m',
     'fallback' => '/transform\s*:/', 'grave' => true,
     'dica' => 'use a função dentro de `transform:`'],

    // --- CSS COSMÉTICO: a tela funciona, só fica diferente --------------------------------
    ['nome' => 'color-mix()',             'chrome' => 111, 'regex' => '/color-mix\(/',
     'fallback' => null, 'grave' => false, 'dica' => 'a cor some; declare uma cor literal antes'],
    ['nome' => 'accent-color',            'chrome' => 93,  'regex' => '/accent-color\s*:/',
     'fallback' => null, 'grave' => false, 'dica' => 'o controle fica com a cor padrão do sistema'],
    ['nome' => 'text-wrap: balance/pretty','chrome' => 114, 'regex' => '/text-wrap\s*:\s*(balance|pretty)/',
     'fallback' => null, 'grave' => false, 'dica' => 'a quebra volta ao normal'],
    ['nome' => 'scrollbar-color/width',   'chrome' => 121, 'regex' => '/scrollbar-(color|width)\s*:/',
     'fallback' => null, 'grave' => false, 'dica' => 'a barra de rolagem fica com a aparência do sistema'],
    ['nome' => '@starting-style',         'chrome' => 117, 'regex' => '/@starting-style\b/',
     'fallback' => null, 'grave' => false, 'dica' => 'a animação de entrada não ocorre'],
    ['nome' => 'light-dark()',            'chrome' => 123, 'regex' => '/light-dark\(/',
     'fallback' => null, 'grave' => false, 'dica' => 'a cor some; declare uma literal antes'],

    // --- JavaScript: aqui a ausência é ERRO EM TEMPO DE EXECUÇÃO, não regra ignorada -----
    ['nome' => 'Array/String .at()',      'chrome' => 92,  'regex' => '/\.at\(\s*-?\d/',
     'fallback' => null, 'grave' => true, 'js' => true,
     'dica' => 'use índice explícito: lista[lista.length - 1]'],
    ['nome' => 'Object.hasOwn',           'chrome' => 93,  'regex' => '/Object\.hasOwn\(/',
     'fallback' => null, 'grave' => true, 'js' => true,
     'dica' => 'use Object.prototype.hasOwnProperty.call(...)'],
    ['nome' => 'structuredClone',         'chrome' => 98,  'regex' => '/\bstructuredClone\(/',
     'fallback' => null, 'grave' => true, 'js' => true,
     'dica' => 'JSON.parse(JSON.stringify(x)) resolve para dado simples'],
    ['nome' => 'findLast/findLastIndex',  'chrome' => 97,  'regex' => '/\.findLast(Index)?\(/',
     'fallback' => null, 'grave' => true, 'js' => true,
     'dica' => 'percorra de trás para frente'],
    ['nome' => 'crypto.randomUUID',       'chrome' => 92,  'regex' => '/crypto\.randomUUID\(/',
     'fallback' => null, 'grave' => true, 'js' => true,
     'dica' => 'monte o id com Math.random ou com um contador'],
    ['nome' => 'AbortSignal.timeout',     'chrome' => 103, 'regex' => '/AbortSignal\.timeout\(/',
     'fallback' => null, 'grave' => true, 'js' => true,
     'dica' => 'use AbortController + setTimeout'],
    ['nome' => 'querySelector com :has()','chrome' => 105, 'regex' => '/querySelector(All)?\([^)]*:has\(/',
     'fallback' => null, 'grave' => true, 'js' => true,
     'dica' => 'o seletor lança SyntaxError no CEF; filtre em JavaScript'],
];

/** Pastas que o JOGO carrega. O painel /admin abre no navegador do operador, não no CEF. */
$ALVOS = ['assets/css', 'assets/js'];
$FORA_DO_JOGO = ['/assets/css/admin', '/assets/js/admin', '/assets/css/admin.css', '/assets/js/admin.js'];

/** @return list<string> */
function arquivos(string $raiz, array $alvos, array $excluir, bool $tudo): array
{
    $out = [];
    foreach ($alvos as $alvo) {
        $dir = $raiz . '/' . $alvo;
        if (!is_dir($dir)) { continue; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            if (!$f->isFile()) { continue; }
            $ext = strtolower($f->getExtension());
            if ($ext !== 'css' && $ext !== 'js') { continue; }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($raiz)));
            if (str_contains($rel, '/vendor/')) { continue; }   // biblioteca de terceiros
            if (!$tudo) {
                foreach ($excluir as $x) { if (str_starts_with($rel, $x)) { continue 2; } }
            }
            $out[] = $rel;
        }
    }
    sort($out);
    return $out;
}

$arquivos = arquivos($RAIZ, $ALVOS, $FORA_DO_JOGO, $TUDO);

echo "=== Linha de base do CEF: Chromium " . CHROMIUM_CEF . " (Unreal 5.6) ===\n";
echo count($arquivos) . ' arquivo(s) que o jogo carrega' . ($TUDO ? ' (incluindo o painel)' : '') . "\n\n";

$graves = 0;
$avisos = 0;

foreach ($arquivos as $rel) {
    $conteudo = (string)file_get_contents($RAIZ . $rel);
    $ehJs     = str_ends_with($rel, '.js');
    $linhas   = explode("\n", $conteudo);

    foreach ($REGRAS as $r) {
        if ((bool)($r['js'] ?? false) !== $ehJs) { continue; }
        if (!preg_match($r['regex'], $conteudo)) { continue; }

        // fallback presente no mesmo arquivo = o autor já cuidou do caso antigo
        $coberto = $r['fallback'] !== null && preg_match($r['fallback'], $conteudo) === 1;
        if ($coberto) { continue; }

        foreach ($linhas as $i => $linha) {
            if (!preg_match($r['regex'], $linha)) { continue; }
            // comentário não é código
            $limpa = trim($linha);
            if (str_starts_with($limpa, '*') || str_starts_with($limpa, '//') || str_starts_with($limpa, '/*')) { continue; }

            $grave = (bool)$r['grave'];
            $grave ? $graves++ : $avisos++;
            printf(
                "%s %s:%d\n     %s (Chrome %d) — %s\n     %s\n",
                $grave ? 'QUEBRA ' : 'AVISO  ',
                $rel, $i + 1, $r['nome'], $r['chrome'], $r['dica'], $limpa
            );
        }
    }
}

echo "\n";
if ($graves === 0 && $avisos === 0) {
    echo "Nada acima do Chromium " . CHROMIUM_CEF . " nos arquivos que o jogo carrega.\n";
    exit(0);
}
echo "$graves quebra(s) de layout/execução, $avisos aviso(s) cosmético(s).\n";
exit($graves > 0 ? 2 : 0);
