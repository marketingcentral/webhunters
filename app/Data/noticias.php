<?php declare(strict_types=1);

/**
 * Notícias (painel da Home, carrossel do Lobby, página /noticias e /noticias/{slug}).
 *
 * Fase 2: a tela `noticias` lê o CONTEÚDO do banco (CatalogRepo::news() ->
 * title, category, excerpt, body[], image, published). Este arquivo continua a
 * alimentar o seed (db/seed.php lê `items[].excerpt/category/body/image/...`) e
 * serve de OVERLAY de apresentação por slug para a tela: os rótulos de layout que
 * não têm coluna no banco (tag_cls, lead, author, read, highlights, pos) vêm daqui.
 *
 * Chaves de cada item consumidas por outras telas (NÃO remover):
 *   slug, title, summary, excerpt, date, tag, tag_cls, image, body
 * Rótulos de layout usados só pela tela `noticias`:
 *   lead, author, read, highlights, pos (object-position da arte)
 *
 * `agenda` traz alvos de contagem regressiva ao vivo (sempre no futuro para que
 * o relógio da coluna direita nunca zere numa build de demonstração).
 */

$nextMaintenance = strtotime('next tuesday 03:00');
$eventEnds       = strtotime('+2 days 21:00');

return [
    'title'    => 'NOTÍCIAS',
    'subtitle' => 'Comunicados oficiais, notas de atualização e eventos da temporada.',
    'featured' => 'atualizacao-8-8-1',
    'items' => [
        [
            'slug'  => 'atualizacao-8-8-1',
            'title' => 'ATUALIZAÇÃO 8.8.1',
            'summary' => 'Novas missões, armas e ajustes de desempenho.',
            'excerpt' => 'Novas missões, armas e ajustes de desempenho.',
            'date'  => '30/05/2024',
            'tag'   => 'ATUALIZAÇÃO',
            'tag_cls' => 'lime',
            'image' => 'news/news-atualizacao.jpg',
            'pos'   => '50% 42%',
            'author' => 'EQUIPE DE DESENVOLVIMENTO',
            'read'  => 4,
            'lead'  => 'Duas missões principais na Floresta Verde, o fuzil M4A1 com acessórios modulares e uma passada geral no desempenho do cliente.',
            'body'  => [
                'A atualização 8.8.1 chega com duas novas missões principais na Floresta Verde, o fuzil M4A1 com acessórios modulares e uma passada geral no desempenho do cliente.',
                'Reduzimos o consumo de memória de texturas em 18% e corrigimos o travamento ao abrir o inventário durante o carregamento do mapa.',
                'Também ajustamos a economia: o preço de venda de itens comuns subiu 10% e a Caixa de Suprimentos diária agora garante pelo menos um item raro.',
            ],
            'highlights' => [
                'Duas missões principais inéditas na Floresta Verde',
                'M4A1 com trilho modular: mira, cano e coronha intercambiáveis',
                'Memória de texturas 18% menor e travamento do inventário corrigido',
                'Venda de itens comuns +10% e item raro garantido na caixa diária',
            ],
        ],
        [
            'slug'  => 'evento-caca-na-floresta',
            'title' => 'EVENTO: CAÇA NA FLORESTA',
            'summary' => 'Participe do evento especial e ganhe recompensas exclusivas.',
            'excerpt' => 'Participe do evento especial e ganhe recompensas exclusivas.',
            'date'  => '28/05/2024',
            'tag'   => 'EVENTO',
            'tag_cls' => 'gold',
            'image' => 'news/news-evento-caca.jpg',
            'pos'   => '50% 38%',
            'author' => 'COMANDO DE OPERAÇÕES',
            'read'  => 3,
            'lead'  => 'Matilhas de lobos, acampamentos reforçados e entregas aéreas a cada ciclo até 05/06.',
            'body'  => [
                'Até 05/06 a Floresta Verde recebe matilhas de lobos, acampamentos inimigos reforçados e entregas aéreas a cada ciclo.',
                'Complete os desafios do evento para desbloquear a camuflagem exclusiva "Mata Fechada" e 8.000 XP de bônus.',
            ],
            'highlights' => [
                'Entregas aéreas a cada ciclo na região da Floresta Verde',
                'Camuflagem exclusiva "Mata Fechada" ao concluir os 8 desafios',
                '8.000 XP de bônus e reputação dobrada com a Resistência',
            ],
        ],
        [
            'slug'  => 'manutencao-programada',
            'title' => 'MANUTENÇÃO PROGRAMADA',
            'summary' => 'Servidores ficarão offline para manutenção.',
            'excerpt' => 'Servidores ficarão offline para manutenção.',
            'date'  => '27/05/2024',
            'tag'   => 'MANUTENÇÃO',
            'tag_cls' => 'cyan',
            'image' => 'news/news-manutencao.jpg',
            'pos'   => '50% 45%',
            'author' => 'INFRAESTRUTURA',
            'read'  => 2,
            'lead'  => 'Servidores da América do Sul offline das 03h às 05h para migração de infraestrutura.',
            'body'  => [
                'Na terça-feira, 04/06, os servidores da América do Sul ficarão offline das 03h às 05h (horário de Brasília) para migração de infraestrutura.',
                'Partidas em andamento serão encerradas 10 minutos antes com aviso no HUD. Nenhum progresso será perdido.',
            ],
            'highlights' => [
                'Janela de 2 horas: 03h às 05h (horário de Brasília)',
                'Aviso no HUD 10 minutos antes do encerramento das partidas',
                'Nenhum progresso, item ou crédito será perdido',
            ],
        ],
        [
            'slug'  => 'novo-mapa-complexo-militar',
            'title' => 'NOVO MAPA: COMPLEXO MILITAR',
            'summary' => 'Uma base fortificada com três pontos de extração e zonas de risco dinâmicas.',
            'excerpt' => 'Uma base fortificada com três pontos de extração e zonas de risco dinâmicas.',
            'date'  => '20/05/2024',
            'tag'   => 'CONTEÚDO',
            'tag_cls' => 'lime',
            'image' => 'maps/complexo-militar.jpg',
            'pos'   => '50% 50%',
            'author' => 'EQUIPE DE LEVEL DESIGN',
            'read'  => 3,
            'lead'  => 'O maior mapa de Dominação até agora: 6v6, três pontos de captura e uma torre que muda de dono a cada ciclo.',
            'body'  => [
                'O Complexo Militar é o maior mapa de Dominação até agora: 6v6, três pontos de captura e uma torre central que muda de dono a cada ciclo.',
                'As zonas de risco se deslocam a cada 4 minutos e forçam o esquadrão a decidir entre segurar o pátio central ou correr para a extração norte.',
            ],
            'highlights' => [
                'Três pontos de extração e um pátio central disputado',
                'Zonas de risco dinâmicas a cada 4 minutos',
                'Disponível em Dominação 6v6 e em incursão solo',
            ],
        ],
        [
            'slug'  => 'balanceamento-de-armas',
            'title' => 'BALANCEAMENTO DE ARMAS',
            'summary' => 'M4A1 com menos recuo horizontal; Glock 17 ganha 2 balas por carregador.',
            'excerpt' => 'M4A1 com menos recuo horizontal; Glock 17 ganha 2 balas por carregador.',
            'date'  => '15/05/2024',
            'tag'   => 'BALANCEAMENTO',
            'tag_cls' => 'amber',
            'image' => 'maps/porto-industrial.jpg',
            'pos'   => '50% 46%',
            'author' => 'EQUIPE DE COMBATE',
            'read'  => 2,
            'lead'  => 'Dois milhões de confrontos analisados: recuo, carregadores e preços revisados.',
            'body'  => [
                'Depois de analisar 2 milhões de confrontos, ajustamos o recuo horizontal da M4A1 (-12%) e aumentamos o carregador da Glock 17 para 17+2.',
                'A AK-103 segue inalterada, mas seu preço na loja de facção caiu 8%.',
            ],
            'highlights' => [
                'M4A1: recuo horizontal -12%',
                'Glock 17: carregador 17+2',
                'AK-103: preço na loja de facção -8%',
            ],
        ],
    ],

    /* contagens regressivas ao vivo da coluna direita */
    'agenda' => [
        [
            'label' => 'FIM DO EVENTO: CAÇA NA FLORESTA',
            'sub'   => 'RECOMPENSAS EXCLUSIVAS',
            'at'    => date(DATE_ATOM, $eventEnds),
            'color' => 'gold',
            'icon'  => 'local_fire_department',
            'href'  => 'noticias/evento-caca-na-floresta',
        ],
        [
            'label' => 'MANUTENÇÃO PROGRAMADA',
            'sub'   => 'AMÉRICA DO SUL · 03H ÀS 05H',
            'at'    => date(DATE_ATOM, $nextMaintenance),
            'color' => 'cyan',
            'icon'  => 'schedule',
            'href'  => 'noticias/manutencao-programada',
        ],
    ],

    /* atalhos da coluna direita — todas as rotas existem (SPEC §2) */
    'links' => [
        ['label' => 'DESAFIOS DO EVENTO', 'sub' => '4 DIÁRIOS ATIVOS',   'icon' => 'flag',            'href' => 'desafios'],
        ['label' => 'MISSÕES',            'sub' => 'CONTRATOS EM CURSO',  'icon' => 'swords',          'href' => 'missoes'],
        ['label' => 'LOJA',               'sub' => 'PASSE DE TEMPORADA',  'icon' => 'shopping_cart',   'href' => 'loja'],
        ['label' => 'RELATÓRIO DE BUG',   'sub' => 'ENVIE UMA OCORRÊNCIA','icon' => 'bug_report',      'href' => 'relatorio-de-bug'],
        ['label' => 'SUPORTE',            'sub' => 'FALE COM O COMANDO',  'icon' => 'headset_mic',     'href' => 'suporte'],
    ],
];
