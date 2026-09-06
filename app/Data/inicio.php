<?php declare(strict_types=1);

/**
 * Tela INÍCIO (referência 01 — "01-Menu-do-jogo-inicio.png").
 *
 * Chaves consumidas pelo HomeController / pages/inicio.php:
 *   brand       textos do logo hero
 *   menu        4 botões da coluna esquerda (o último faz POST /sair)
 *   season      passe de temporada + 3 recompensas para o carrossel
 *   contract    contrato ativo + recompensas
 *   news_panel  cabeçalho do painel de notícias
 *   news_pages  3 páginas de 3 notícias (carrossel). Montadas a partir de
 *               app/Data/noticias.php (dono: tela `noticias`) + itens extras
 *               que apontam para a listagem /noticias, já que /noticias/{slug}
 *               devolve 404 para slugs inexistentes.
 * $data['news'] (3 primeiras de noticias.php) continua sendo entregue pelo controller.
 */

/** @return array<int, array<string, mixed>> */
$ehNewsItems = static function (): array {
    $out = [];
    foreach (data_mock('noticias')['items'] ?? [] as $item) {
        $out[] = [
            'title'   => (string)($item['title'] ?? ''),
            'summary' => (string)($item['summary'] ?? ''),
            'date'    => (string)($item['date'] ?? ''),
            'image'   => (string)($item['image'] ?? 'news/news-atualizacao.jpg'),
            'route'   => 'noticias/' . (string)($item['slug'] ?? ''),
        ];
    }
    return $out;
};

$ehNews = $ehNewsItems();
$ehExtra = [
    [
        'title'   => 'PASSE DE TEMPORADA 01',
        'summary' => 'Cem níveis de recompensas, camuflagens e projetos.',
        'date'    => '12/05/2024',
        'image'   => 'ui/season-pass.jpg',
        'route'   => 'noticias',
    ],
    [
        'title'   => 'MODO DOMINAÇÃO EM TESTE',
        'summary' => 'Servidores de teste abertos para o novo modo 6v6.',
        'date'    => '08/05/2024',
        'image'   => 'maps/complexo-militar.jpg',
        'route'   => 'noticias',
    ],
    [
        'title'   => 'REGIÃO: PÂNTANO CINZENTO',
        'summary' => 'Nova zona de risco com extração noturna.',
        'date'    => '02/05/2024',
        'image'   => 'regions/region-pantano-cinzento.jpg',
        'route'   => 'noticias',
    ],
    [
        'title'   => 'RELATÓRIO ANTI-TRAPAÇA',
        'summary' => 'Mais de 12.000 contas banidas no último ciclo.',
        'date'    => '28/04/2024',
        'image'   => 'bg/admin.jpg',
        'route'   => 'noticias',
    ],
];

$ehAll = array_merge($ehNews, $ehExtra);
/* 2 por página: com a faixa STATUS OPERACIONAL no meio da coluna, três linhas de notícia
   não cabem no painel e a lista transbordava para fora dele. */
$ehPages = array_slice(array_chunk($ehAll, 2), 0, 4);

return [
    'brand' => [
        'title'   => 'EXTRACT',
        'sub'     => 'HUNTERS',
        'tagline' => 'SOBREVIVA. EXTRAIA. EVOLUA.',
    ],
    /* PRD v3 §16.1: JOGAR SOLO -> ENTRAR EM OPERAÇÃO, JOGAR COM AMIGOS -> FORMAR ESQUADRÃO,
       terceiro espaço -> BASE DE CAMPO. SAIR saiu daqui: a topbar da Home já tem SAIR (Controller::page). */
    'menu' => [
        ['slug' => 'jogar',         'icon' => 'terrain',  'icon_fill' => true,  'emblem' => 'mountain', 'title' => 'ENTRAR EM OPERAÇÃO', 'desc' => 'Escolha seu status operacional e entre em uma região ativa.', 'href' => 'jogar',         'active' => true],
        ['slug' => 'lobby',         'icon' => 'group',    'icon_fill' => false, 'title' => 'FORMAR ESQUADRÃO',   'desc' => 'Monte uma unidade Hunter com até 4 operadores.',             'href' => 'lobby',         'active' => false],
        ['slug' => 'safe-zone',     'icon' => 'storefront','icon_fill' => false,'title' => 'SAFE ZONE',          'desc' => 'Assuma um papel civil no perímetro protegido entre operações.', 'href' => 'safe-zone',     'active' => false],
        ['slug' => 'base',          'icon' => 'home_work','icon_fill' => false, 'title' => 'BASE DE CAMPO',      'desc' => 'Gerencie armazenamento, veículos e infraestrutura persistente.', 'href' => 'base',          'active' => false],
        ['slug' => 'configuracoes', 'icon' => 'settings', 'icon_fill' => true,  'title' => 'CONFIGURAÇÕES',     'desc' => 'Ajustes e opções do jogo.',                                    'href' => 'configuracoes', 'active' => false],
    ],
    /* Faixa "STATUS OPERACIONAL" (PRD §16.3) — só os rótulos; os valores vêm de OperationalRepo. */
    'status' => [
        'title'   => 'STATUS OPERACIONAL',
        'labels'  => ['org' => 'ORGANIZAÇÃO', 'base' => 'BASE DE ORIGEM', 'role' => 'FUNÇÃO', 'rank' => 'PATENTE',
                       'contract' => 'CONTRATO', 'rep' => 'REP', 'survivor' => 'SEM EXTRAÇÃO DISPONÍVEL'],
        'href'    => 'perfil',
    ],
    'season' => [
        'level'    => 45,
        'title'    => 'PASSE DE OPERAÇÕES',
        'subtitle' => 'TEMPORADA 01',
        'xp'       => 2450,
        'xp_next'  => 10000,
        'image'    => 'ui/season-pass.jpg',
        'href'     => 'loja',
        /** fim da temporada — alimenta o contador ao vivo do overlay (JS) */
        'ends_at'  => gmdate('c', time() + 12 * 86400 + 4 * 3600 + 33 * 60),
        'rewards'  => [
            ['level' => 45, 'name' => 'OPERADOR ESPECTRO',  'tier' => 'LENDÁRIO', 'image' => 'ui/season-pass.jpg',      'pos' => '50% 12%'],
            ['level' => 52, 'name' => 'TRAJE CAÇADOR',      'tier' => 'ÉPICO',    'image' => 'portraits/op-sniper.jpg',  'pos' => '50% 10%'],
            ['level' => 60, 'name' => 'UNIFORME VANGUARDA', 'tier' => 'LENDÁRIO', 'image' => 'portraits/op-support.jpg', 'pos' => '50% 10%'],
        ],
    ],
    'contract' => [
        'title'    => 'CONTRATO ATIVO',
        'action'   => 'VER TODOS',
        'origin_label' => 'ORIGEM',
        'origin'   => 'hunter_command',   /* fallback sem banco; o real vem de missions.origin */
        'href'     => 'missoes',
        'name'     => 'SINAL PERDIDO',
        'desc'     => 'RESTAURE A COMUNICAÇÃO DA ESTAÇÃO AO NORTE. ALGUÉM CHEGOU ANTES.',
        'icon'     => 'my_location',
        'progress' => 3,
        'total'    => 8,
        'reward_label' => 'RECOMPENSA',
        'rewards'  => [
            ['type' => 'xp',         'value' => 7500, 'label' => 'Experiência'],
            ['type' => 'cash',       'value' => 4200, 'label' => 'Dinheiro'],
            ['type' => 'reputation', 'value' => 2,    'label' => 'Reputação'],
        ],
    ],
    'news_panel' => [
        'title'  => 'NOTÍCIAS',
        'action' => 'VER TODAS',
        'href'   => 'noticias',
    ],
    'news_pages' => $ehPages,
];
