<?php declare(strict_types=1);

/**
 * Exporta app/Data/*.php (o cromo das telas e o catálogo do bestiário) para JSON em
 * typescript/src/data/, que o Worker importa direto no bundle.
 *
 * Enquanto o app PHP for o que está no ar, ele continua sendo a FONTE: edite o .php e rode
 *   php typescript/scripts/export-data.php
 * para o Worker acompanhar. Só lê arquivos; não abre banco nem sessão.
 *
 * Atenção: app/Data/inicio.php calcula `ends_at` com time() — no JSON o valor fica
 * congelado no momento do export (o Worker recalcula o que precisa ser relativo a "agora").
 */

if (PHP_SAPI !== 'cli') {
    exit("Só pela linha de comando.\n");
}

$raiz = dirname(__DIR__, 2);
require $raiz . '/app/Core/Config.php';
require $raiz . '/app/Core/View.php';
require $raiz . '/app/Core/Security.php';
\App\Core\Config::boot($raiz);
require $raiz . '/app/Core/helpers.php';

$destino = dirname(__DIR__) . '/src/data';
if (!is_dir($destino)) {
    mkdir($destino, 0775, true);
}

$n = 0;
foreach (glob($raiz . '/app/Data/*.php') as $arquivo) {
    $nome = basename($arquivo, '.php');
    if (!preg_match('/^[a-z0-9_-]+$/', $nome)) {
        continue;
    }
    $dados = data_mock($nome);
    $json  = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    file_put_contents($destino . '/' . $nome . '.json', $json . "\n");
    $n++;
}
echo "$n arquivos exportados para $destino\n";
