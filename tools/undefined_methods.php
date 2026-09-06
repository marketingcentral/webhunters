<?php declare(strict_types=1);

/**
 * Encontra métodos chamados com $this->x() que não existem na classe (nem na classe base).
 * Pega exatamente a família de erro "Call to undefined method" antes de virar um 500 em produção.
 *
 *   php tools/undefined_methods.php                 varre app/Controllers e app/Repo
 *   php tools/undefined_methods.php app/Controllers/SkillsController.php
 *
 * Sai com código 2 se achar alguma chamada órfã.
 */
$root = dirname(__DIR__);
$argvFiles = array_slice($argv, 1);

$files = $argvFiles !== []
    ? array_map(static fn(string $f): string => $root . '/' . ltrim(str_replace('\\', '/', $f), '/'), $argvFiles)
    : array_merge(
        glob($root . '/app/Controllers/*.php') ?: [],
        glob($root . '/app/Repo/*.php') ?: [],
        glob($root . '/app/Core/*.php') ?: []
    );

// métodos herdados de app/Controllers/Controller.php (classe base)
$base = [];
$baseFile = $root . '/app/Controllers/Controller.php';
if (is_file($baseFile)) {
    preg_match_all('/function\s+([a-zA-Z_]\w*)\s*\(/', (string)file_get_contents($baseFile), $m);
    $base = $m[1];
}

$problems = 0;
foreach ($files as $file) {
    $src = (string)file_get_contents($file);
    preg_match_all('/\$this->([a-zA-Z_]\w*)\s*\(/', $src, $used);
    preg_match_all('/function\s+([a-zA-Z_]\w*)\s*\(/', $src, $def);
    $missing = array_values(array_unique(array_diff($used[1], $def[1], $base)));
    if ($missing === []) {
        continue;
    }
    $lines = explode("\n", $src);
    foreach ($missing as $name) {
        foreach ($lines as $i => $line) {
            if (str_contains($line, '$this->' . $name . '(')) {
                echo basename($file) . ':' . ($i + 1) . "  \$this->{$name}() não existe\n";
                $problems++;
                break;
            }
        }
    }
}

echo $problems === 0
    ? "OK: nenhuma chamada a método inexistente.\n"
    : "\n$problems chamada(s) órfã(s) — cada uma vira 500 ao abrir a página.\n";
exit($problems === 0 ? 0 : 2);
