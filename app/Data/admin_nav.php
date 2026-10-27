<?php declare(strict_types=1);

/**
 * Navegação lateral do painel admin.
 *
 * Cada item vira uma linha na sidebar e o `slug` é o que a tela passa em adminPage() para
 * marcar o item ativo. A ordem dos grupos segue a referência do painel; o conteúdo segue o
 * que ESTE jogo realmente tem — item sem tabela por trás não entra, para a barra não
 * prometer o que o painel não faz.
 *
 * SUBMENU (`children`): um item pode abrir um segundo nível. Isso existe para "Mapas", onde
 * três tarefas diferentes compartilham a mesma tabela e seriam confundidas se virassem três
 * itens de primeiro nível: cadastrar o mapa, DESENHAR os ícones que existem, e COMPOR o mapa
 * arrastando esses ícones. O pai continua sendo uma tela de verdade, não uma pasta — quem
 * clica em "Mapas" ainda cai na listagem, e os filhos ficam sempre à vista, porque submenu
 * que só aparece depois de acertar o pai é submenu que ninguém descobre.
 */
return [
    ['label' => '', 'items' => [
        ['slug' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'home', 'route' => 'admin'],
    ]],
    ['label' => 'Gerenciar', 'items' => [
        ['slug' => 'jogadores',  'label' => 'Jogadores',  'icon' => 'group',        'route' => 'admin/jogadores'],
        ['slug' => 'servidores', 'label' => 'Servidores', 'icon' => 'dns',          'route' => 'admin/servidores'],
        ['slug' => 'banimentos', 'label' => 'Banimentos', 'icon' => 'gavel',        'route' => 'admin/banimentos'],
        ['slug' => 'relatorios', 'label' => 'Relatórios', 'icon' => 'description',  'route' => 'admin/relatorios'],
    ]],
    ['label' => 'Conteúdo', 'items' => [
        ['slug' => 'missoes',    'label' => 'Missões',    'icon' => 'flag',            'route' => 'admin/missoes'],
        ['slug' => 'conquistas', 'label' => 'Conquistas', 'icon' => 'military_tech',   'route' => 'admin/conquistas'],
        ['slug' => 'mapas',      'label' => 'Mapas',      'icon' => 'map',             'route' => 'admin/mapas', 'children' => [
            ['slug' => 'mapas-pontos', 'label' => 'Criar pontos no mapa', 'icon' => 'add_location_alt',  'route' => 'admin/mapas/pontos'],
            ['slug' => 'mapas-editor', 'label' => 'Editar mapa',          'icon' => 'edit_location_alt', 'route' => 'admin/mapas/editor'],
        ]],
        ['slug' => 'itens',      'label' => 'Itens',      'icon' => 'inventory_2',     'route' => 'admin/itens'],
        ['slug' => 'eventos',    'label' => 'Eventos',    'icon' => 'event',           'route' => 'admin/eventos'],
    ]],
    ['label' => 'Economia', 'items' => [
        ['slug' => 'loja',       'label' => 'Loja',       'icon' => 'shopping_cart',   'route' => 'admin/loja'],
        ['slug' => 'transacoes', 'label' => 'Transações', 'icon' => 'payments',        'route' => 'admin/transacoes'],
    ]],
    /* A API é grupo próprio, e não um item dentro de "Configurações", porque ela não é uma
       preferência do jogo: é uma PORTA para fora, com credencial, escopo e log próprios.
       Quem procura "quem tem acesso programático ao jogo" procura por esse nome, e uma linha
       escondida entre parâmetros de balanceamento não seria encontrada. */
    ['label' => 'API', 'items' => [
        ['slug' => 'api', 'label' => 'Chaves e Endpoints', 'icon' => 'key', 'route' => 'admin/api'],
    ]],
    ['label' => 'Configurações', 'items' => [
        ['slug' => 'config', 'label' => 'Configurações do Jogo', 'icon' => 'settings', 'route' => 'admin/configuracoes'],
        ['slug' => 'logs',   'label' => 'Logs do Sistema',       'icon' => 'receipt_long', 'route' => 'admin/logs'],
    ]],
];
