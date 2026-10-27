<?php declare(strict_types=1);

/**
 * Prova do envio de ÍCONE VETORIAL (POST /admin/upload), em CLI.
 *
 *   php tools/test_upload_svg.php
 *
 * SVG é XML e pode carregar script, evento e referência externa. O painel passou a aceitá-lo
 * porque ícone de mapa é vetor por natureza — e a contrapartida é este arquivo: cada porta que
 * o formato abre tem um caso aqui provando que ela está fechada.
 *
 *   1. um vetor limpo é reconhecido e aceito;
 *   2. <script> reprova;
 *   3. atributo de evento (onload=) reprova;
 *   4. href="javascript:" reprova;
 *   5. <!ENTITY> (XXE / billion laughs) reprova;
 *   6. <foreignObject> reprova;
 *   7. referência a arquivo de fora do servidor reprova;
 *   8. `<script` escondido em entidade HTML reprova;
 *   9. XML quebrado reprova;
 *  10. raiz que não é <svg> reprova;
 *  11. PNG NÃO é desviado para o caminho de vetor (o caminho de bitmap segue inteiro);
 *  12. sem CSRF válido nada é gravado.
 *
 * Nada é gravado em media/: os casos param na validação, e os temporários somem no fim.
 */

$RAIZ = dirname(__DIR__);
$_SERVER['SCRIPT_NAME']    = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST']      = '127.0.0.1';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

require $RAIZ . '/app/bootstrap.php';

use App\Controllers\Admin\UploadController;
use App\Core\Database;
use App\Core\Request;

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

$temp = [];

/**
 * Grava o conteúdo num arquivo temporário e pergunta ao controller se ele entraria.
 *
 * POR QUE NÃO PELA AÇÃO INTEIRA: enviar() começa conferindo is_uploaded_file(), que recusa
 * qualquer caminho não vindo de um POST de verdade — e essa checagem existe justamente para
 * não poder ser simulada. Como o que este arquivo prova é a VALIDAÇÃO DE CONTEÚDO (a porta
 * nova que o formato SVG abriu), o teste chama o método que decide, por reflexão. O resto do
 * caminho (sessão, CSRF, entidade, tamanho, pasta) já é o mesmo de JPG/PNG/WEBP e continua
 * coberto pelo último caso deste arquivo.
 *
 * @return string|null motivo da recusa, ou null quando o vetor entraria
 */
function recusa(string $conteudo): ?string
{
    global $temp;
    $tmp = tempnam(sys_get_temp_dir(), 'ehsvg');
    file_put_contents($tmp, $conteudo);
    $temp[] = $tmp;

    $r = new \ReflectionMethod(UploadController::class, 'recusarSvg');
    $r->setAccessible(true);
    return $r->invoke(new UploadController(), $tmp);
}

/** O arquivo é reconhecido como vetor, e portanto desviado do caminho de bitmap? */
function ehVetor(string $conteudo): bool
{
    global $temp;
    $tmp = tempnam(sys_get_temp_dir(), 'ehsvg');
    file_put_contents($tmp, $conteudo);
    $temp[] = $tmp;

    $r = new \ReflectionMethod(UploadController::class, 'pareceSvg');
    $r->setAccessible(true);
    return (bool)$r->invoke(new UploadController(), $tmp);
}

/** Envia de verdade pela ação, para cobrir a guarda de sessão/CSRF. */
function enviar(string $conteudo, string $nome, bool $csrfValido = true): array
{
    global $temp;
    $tmp = tempnam(sys_get_temp_dir(), 'ehsvg');
    file_put_contents($tmp, $conteudo);
    $temp[] = $tmp;

    $_POST  = ['entidade' => 'mapas', '_csrf' => $csrfValido ? (string)$_SESSION['_csrf'] : 'invalido'];
    $_FILES = ['arquivo' => [
        'name' => $nome, 'type' => '', 'tmp_name' => $tmp,
        'error' => UPLOAD_ERR_OK, 'size' => max(1, strlen($conteudo)),
    ]];

    $ctl = new UploadController();
    try {
        $res = $ctl->enviar(Request::fromGlobals(), []);
        return ['status' => $res->status(), 'corpo' => (array)json_decode($res->body(), true)];
    } catch (\App\Core\HttpException $e) {
        return ['status' => $e->getCode(), 'corpo' => []];
    }
}

$LIMPO = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
       . '<path d="M12 2 2 22h20z" fill="currentColor"/></svg>';

echo "=== Envio de ícone vetorial ===\n\n";

$ok('vetor limpo é reconhecido como SVG', ehVetor($LIMPO));
$ok('vetor limpo é aceito', recusa($LIMPO) === null, (string)recusa($LIMPO));

/** @var array<string,string> $PERIGOS  rótulo => conteúdo que tem de ser recusado */
$PERIGOS = [
    '<script> é recusado' =>
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><path d="M0 0h9v9z"/></svg>',
    'atributo de evento é recusado' =>
        '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><path d="M0 0h9v9z"/></svg>',
    'href javascript: é recusado' =>
        '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><path d="M0 0h9v9z"/></a></svg>',
    'ENTITY (XXE) é recusado' =>
        '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
        . '<svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>',
    '<foreignObject> é recusado' =>
        '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><body xmlns="http://www.w3.org/1999/xhtml">x</body></foreignObject></svg>',
    'referência externa é recusada' =>
        '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://exemplo.invalido/a.png"/></svg>',
    'script escondido em entidade HTML é recusado' =>
        '<svg xmlns="http://www.w3.org/2000/svg"><desc>&lt;script&gt;alert(1)&lt;/script&gt;</desc></svg>',
    'XML quebrado é recusado' =>
        '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h9v9z"></svg>',
    'raiz que não é <svg> é recusada' =>
        '<html><body><svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h9v9z"/></svg></body></html>',
];

foreach ($PERIGOS as $nome => $conteudo) {
    $motivo = recusa($conteudo);
    $ok($nome, $motivo !== null, $motivo ?? 'PASSOU — a porta está aberta');
}

/* o caminho de bitmap continua inteiro: um PNG não pode cair na leitura de XML */
$png = imagecreatetruecolor(32, 32);
ob_start(); imagepng($png); $bytesPng = (string)ob_get_clean(); imagedestroy($png);
$ok('PNG NÃO é desviado para o caminho de vetor', !ehVetor($bytesPng));

/* a guarda comum a todo upload continua valendo */
$r = enviar($LIMPO, 'icone.svg', false);
$ok('envio sem CSRF válido é recusado', $r['status'] === 419, 'HTTP ' . $r['status']);

/* ---- limpeza ------------------------------------------------------------- */
foreach ($temp as $t) { @unlink($t); }

echo "\n" . ($falhas === 0 ? "TODOS OS CASOS PASSARAM\n" : "$falhas caso(s) falharam\n");
exit($falhas === 0 ? 0 : 2);
