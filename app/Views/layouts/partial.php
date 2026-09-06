<?php declare(strict_types=1);

/**
 * Layout PARCIAL — a resposta da navegação sem recarga (assets/js/spa.js).
 *
 * Mesmo escopo do layout HUD ($page, $player, $nav, $server, $data, $flash, $csrf, $content),
 * mas em vez de uma página inteira devolve JSON com só o que muda entre telas. O casco
 * (fontes, tokens, importmap, hud.js, canvas do atmosphere) fica de pé no cliente.
 *
 * `layout: "hud"` viaja junto porque o cliente precisa saber se a tela de destino usa outro
 * casco (o /login usa layouts/auth.php): quando muda, ele faz navegação normal em vez de
 * tentar remendar um shell que não é o dele.
 */

use App\Core\Config;
use App\Core\View;

$assets = View::pageAssets(is_array($page ?? null) ? $page : []);
$slug   = $assets['slug'];
$title  = trim((string)($page['title'] ?? ''));
$title  = $title !== '' ? $title . ' · ' . Config::APP_NAME : Config::APP_NAME;

/** Captura um partial em string (partial() escreve na saída). */
$capturar = static function (string $nome): string {
    ob_start();
    partial($nome);
    return (string)ob_get_clean();
};

/** Só o que existe em disco vira URL; o cliente não precisa saber do resto. */
$urls = static function (array $lista): array {
    $out = [];
    foreach ($lista as $rel) {
        if (View::assetExists($rel)) {
            $out[] = asset($rel);
        }
    }
    return $out;
};

/* O <main> do layout HUD: o flash vem antes do conteúdo, igual à navegação normal. */
ob_start();
if (!empty($flash) && is_array($flash)):
    ?><div class="flash-stack" role="status" aria-live="polite"><?php
    foreach ($flash as $kind => $msg):
        if (is_string($msg) && $msg !== ''):
            ?><div class="flash flash--<?= e(preg_replace('/[^a-z0-9_-]/', '', (string)$kind) ?: 'info') ?>" data-flash data-autohide="<?= $kind === 'error' ? 9000 : 5000 ?>"><?= icon($kind === 'error' ? 'warning' : 'check_circle', 'flash__icon') ?><span class="flash__text"><?= e($msg) ?></span></div><?php
        endif;
    endforeach;
    ?></div><?php
endif;
echo $content;
$main = (string)ob_get_clean();

echo json_encode([
    'ok'         => true,
    'layout'     => 'hud',
    'slug'       => $slug,
    'title'      => $title,
    'bodyClass'  => 'page-' . $slug,
    'csrf'       => (string)($csrf ?? ''),
    'background' => $capturar('background'),
    'topbar'     => $capturar('topbar'),
    'main'       => $main,
    'footer'     => $capturar('footer'),
    'css'        => $urls($assets['pageCss']),
    'js'         => $urls($assets['pageJs']),
    'jsOnce'     => $urls($assets['onceJs']),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
