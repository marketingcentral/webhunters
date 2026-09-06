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
$ehPages = array_slice(array_chunk($ehAll, 3), 0, 3);

return [
    'brand' => [
        'title'   => 'EXTRACT',
        'sub'     => 'HUNTERS',
        'tagline' => 'SOBREVIVA. EXTRAIA. EVOLUA.',
    ],
    'menu' => [
        ['slug' => 'jogar',         'icon' => 'terrain', 'icon_fill' => true, 'emblem' => 'mountain', 'title' => 'JOGAR SOLO',       'desc' => 'Entre sozinho no campo e extraia.',     'href' => 'jogar',         'active' => true],
        ['slug' => 'lobby',         'icon' => 'group',    'icon_fill' => false, 'title' => 'JOGAR COM AMIGOS', 'desc' => 'Forme um esquadrão e enfrente juntos.', 'href' => 'lobby',         'active' => false],
        ['slug' => 'configuracoes', 'icon' => 'settings', 'icon_fill' => true,  'title' => 'CONFIGURAÇÕES',    'desc' => 'Ajustes e opções do jogo.',            'href' => 'configuracoes', 'active' => false],
        ['slug' => 'sair',          'icon' => 'logout',   'icon_fill' => false, 'title' => 'SAIR',             'desc' => 'Voltar para a área de trabalho.',       'href' => null,            'active' => false],
    ],
    'season' => [
        'level'    => 45,
        'title'    => 'PASSE DE TEMPORADA',
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
        'href'     => 'missoes',
        'name'     => 'CAÇA NA FLORESTA',
        'desc'     => 'ELIMINE 8 INIMIGOS NA REGIÃO DA FLORESTA',
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
