<?php declare(strict_types=1);

/**
 * Layout LISTA — a resposta da atualização sem recarga das listas do /admin.
 *
 * Mesmo escopo de layouts/admin.php ($page, $player, $nav, $server, $data, $flash, $csrf,
 * $content): o controller é o MESMO, a view é a MESMA, só a casca muda. É o que o jogo já
 * faz com layouts/partial.php — aqui só o recorte é menor, porque a sidebar, a topbar e os
 * KPIs não mudam quando o operador troca um filtro.
 *
 * Devolve JSON. `ok:false` significa "esta tela não é uma lista agora" (dossiê de jogador,
 * confirmação de exclusão) — e o cliente responde navegando de verdade, que é o
 * comportamento de quem está sem JavaScript.
 */

use App\Core\Config;
use App\Core\Fragmento;

$regiao = Fragmento::extrair((string)$content);

$titulo = trim((string)($page['title'] ?? ''));
$titulo = ($titulo !== '' ? $titulo . ' · ' : '') . 'Painel · ' . Config::APP_NAME;

echo json_encode([
    'ok'     => $regiao !== null,
    // O <title> acompanha: a aba do navegador faz parte do estado que a URL reproduz.
    'titulo' => $titulo,
    'regiao' => $regiao ?? '',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
