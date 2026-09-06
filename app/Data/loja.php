<?php declare(strict_types=1);

/**
 * Tela LOJA.
 *
 * currency: credits (lime, ⊕) | cash (cyan, $) | points (âmbar, ▲)
 * category: destaques (vitrine, itens com 'featured' => true) | armas | equipamento
 *           | suprimentos | cosmeticos | passe
 *
 * A grade da tela é de 6 colunas x 2 linhas: nenhuma categoria passa de 12 itens.
 */
return [
    'title'    => 'LOJA',
    'kicker'   => 'MERCADO NEGRO',
    'season'   => 'TEMPORADA 01',
    'intro'    => 'Equipamentos, cosméticos e suprimentos para a próxima extração.',
    'tip'      => 'Compra debitada na hora e entregue no stash',
    'tip_low'  => 'Saldo insuficiente',
    'tip_icon' => 'bolt',

    /* carteira do cabeçalho (os valores vêm de $player['currencies']) */
    'wallet_label' => 'CARTEIRA',
    'wallet' => [
        ['key' => 'credits', 'label' => 'CRÉDITOS',  'svg' => 'coin-credit', 'color' => 'lime'],
        ['key' => 'cash',    'label' => 'DINHEIRO',  'svg' => 'coin-cash',   'color' => 'cyan'],
        ['key' => 'points',  'label' => 'PONTOS',    'svg' => 'coin-points', 'color' => 'amber'],
    ],

    'categories' => [
        ['slug' => 'destaques',   'label' => 'DESTAQUES',   'icon' => 'star'],
        ['slug' => 'armas',       'label' => 'ARMAS',       'icon' => 'swords'],
        ['slug' => 'equipamento', 'label' => 'EQUIPAMENTO', 'icon' => 'backpack'],
        ['slug' => 'suprimentos', 'label' => 'SUPRIMENTOS', 'icon' => 'medical_services'],
        ['slug' => 'cosmeticos',  'label' => 'SKINS',       'icon' => 'military_tech'],
        ['slug' => 'passe',       'label' => 'PACOTES',     'icon' => 'inventory_2'],
    ],

    /* Vitrine em destaque: só o CROMO mora aqui (arte de fundo, bullets, relógio).
       Produto, preço, moeda e o botão COMPRAR vêm da MESMA oferta do banco.
       'tag' só é usada quando a oferta eleita tem desconto real (discount > 0);
       sem desconto a vitrine cai para 'tag_plain' e o relógio some — a tela nunca
       anuncia uma promoção que o preço não cumpre. */
    'featured' => [
        'offer_slug' => 'weapon-case',
        'tag'        => 'OFERTA',
        'tag_plain'  => 'DESTAQUE',
        'kicker'     => 'DESTAQUE DA LOJA',
        'desc'       => '',
        'image'      => 'ui/season-pass.jpg',
        'bullets'    => ['ENTREGA IMEDIATA NO STASH', 'ESTOQUE DA TEMPORADA 01'],
        'ends_at'    => gmdate('c', time() + 5 * 86400 + 7 * 3600 + 12 * 60),
        'ends_label' => 'TERMINA EM',
    ],

    /* Cada aba elege a SUA vitrine (o painel não pode ser o mesmo nas 6 rotas).
       O slug é uma preferência editorial: se a oferta não existir naquela categoria,
       o controller cai para a de maior desconto, depois para a primeira 'featured'. */
    'featured_by' => [
        'destaques'   => 'weapon-case',
        'armas'       => 'm4a1',
        'equipamento' => 'backpack-45l',
        'suprimentos' => 'supply-crate',
        'cosmeticos'  => 'skin-camo',
        'passe'       => 'weapon-case',
    ],

    /* Cromo por produto eleito (o texto acompanha o item, não a aba). */
    'promos' => [
        'weapon-case' => [
            'kicker'  => 'PACOTE EM DESTAQUE',
            'desc'    => 'Uma maleta lacrada do mercado negro: a arma lá dentro só aparece quando você abre no stash.',
            'image'   => 'ui/season-pass.jpg',
            'bullets' => ['ENTREGA IMEDIATA NO STASH', 'ARMA ALEATÓRIA DE ALTO VALOR', 'ESTOQUE DA TEMPORADA 01'],
        ],
        'm4a1' => [
            'kicker'  => 'ARSENAL EM DESTAQUE',
            'desc'    => 'Plataforma 5.56 NATO recém-chegada ao mercado: leve, precisa e pronta para acessórios.',
            'image'   => 'bg/jogar-banner.jpg',
            'bullets' => ['RECUO BAIXO EM RAJADA', 'ACEITA MIRA, SUPRESSOR E GRIP', 'ENTREGA IMEDIATA NO STASH'],
        ],
        'backpack-45l' => [
            'kicker'  => 'EQUIPAMENTO EM DESTAQUE',
            'desc'    => 'Mochila de trilha de 45 espaços: o que decide quanto saque volta com você da extração.',
            'image'   => 'bg/forest-generic.jpg',
            'bullets' => ['45 ESPAÇOS DE CARGA', 'ALÇAS REFORÇADAS', 'ENTREGA IMEDIATA NO STASH'],
        ],
        'supply-crate' => [
            'kicker'  => 'SUPRIMENTO EM DESTAQUE',
            'desc'    => 'Caixa selada de reposição: remédios, comida e recursos sorteados a cada abertura.',
            'image'   => 'bg/inventario.jpg',
            'bullets' => ['CONTEÚDO ALEATÓRIO', 'REPOSIÇÃO RÁPIDA DE CONSUMÍVEIS', 'ENTREGA IMEDIATA NO STASH'],
        ],
        'skin-camo' => [
            
            'bullets' => ['APLICA EM QUALQUER FUZIL', 'NÃO ALTERA ATRIBUTOS', 'ESTOQUE DA TEMPORADA 01'],
        ],
    ],

    /* ATENÇÃO: esta lista NÃO alimenta mais a tela — a vitrine lê shop_offers do banco
       (ShopController::index). Ela sobrevive como FONTE DO SEED (db/seed.php linha ~241,
       que casa 'image' com o slug do item). Editar aqui só muda o banco no próximo seed. */
    'items' => [
        /* ---------------------------------------------------------------- armas */
        ['slug' => 'ak103',          'name' => 'AK-103',                  'desc' => 'Fuzil de assalto 7.62x39',      'image' => 'items/ak103.png',          'price' => 48500, 'currency' => 'credits', 'category' => 'armas',       'tag' => null,      'owned' => true,  'featured' => true],
        ['slug' => 'm4a1',           'name' => 'M4A1',                    'desc' => 'Fuzil de assalto 5.56x45 NATO', 'image' => 'items/m4a1.png',           'price' => 52000, 'currency' => 'credits', 'category' => 'armas',       'tag' => 'NOVO',    'owned' => false, 'featured' => true],
        ['slug' => 'glock17',        'name' => 'GLOCK 17',                'desc' => 'Pistola 9x19mm',                'image' => 'items/glock17.png',        'price' => 12800, 'currency' => 'credits', 'category' => 'armas',       'tag' => null,      'owned' => true,  'featured' => false],
        ['slug' => 'knife-bayonet',  'name' => 'BAIONETA 6X4',            'desc' => 'Faca de combate',               'image' => 'items/knife-bayonet.png',  'price' => 7400,  'currency' => 'credits', 'category' => 'armas',       'tag' => null,      'owned' => false, 'featured' => false],
        ['slug' => 'm67-grenade',    'name' => 'GRANADA M67',             'desc' => 'Explosiva de fragmentação',     'image' => 'items/m67-grenade.png',    'price' => 4900,  'currency' => 'credits', 'category' => 'armas',       'tag' => null,      'owned' => false, 'featured' => false],
        ['slug' => 'ak-mag',         'name' => 'CARREGADOR 7.62',         'desc' => '30 cartuchos',                  'image' => 'items/ak-mag.png',         'price' => 2100,  'currency' => 'credits', 'category' => 'armas',       'tag' => null,      'owned' => false, 'featured' => false],

        /* ---------------------------------------------------------- equipamento */
        ['slug' => 'helmet-fast',    'name' => 'CAPACETE FAST MT',        'desc' => 'Proteção classe 4',             'image' => 'items/helmet-fast.png',    'price' => 21000, 'currency' => 'credits', 'category' => 'equipamento', 'tag' => null,      'owned' => false, 'featured' => true],
        ['slug' => 'vest-83',        'name' => 'COLETE VEST-83',          'desc' => 'Colete balístico classe 3',     'image' => 'items/vest-83.png',        'price' => 34500, 'currency' => 'credits', 'category' => 'equipamento', 'tag' => null,      'owned' => false, 'featured' => true],
        ['slug' => 'tactical-rig',   'name' => 'COLDRE TÁTICO',           'desc' => '8 espaços de acesso rápido',    'image' => 'items/tactical-rig.png',   'price' => 15600, 'currency' => 'credits', 'category' => 'equipamento', 'tag' => null,      'owned' => false, 'featured' => true],
        ['slug' => 'backpack-45l',   'name' => 'MOCHILA TRILHA 45L',      'desc' => '45 espaços de carga',           'image' => 'items/backpack-45l.png',   'price' => 18900, 'currency' => 'credits', 'category' => 'equipamento', 'tag' => '-15%',    'discount' => 15,    'owned' => false, 'featured' => true],
        ['slug' => 'gasmask-gp7',    'name' => 'MÁSCARA GP-7',            'desc' => 'Filtra gás por 6 minutos',      'image' => 'items/gasmask-gp7.png',    'price' => 9600,  'currency' => 'credits', 'category' => 'equipamento', 'tag' => null,      'owned' => false, 'featured' => false],
        ['slug' => 'radio',          'name' => 'RÁDIO DE CAMPO',          'desc' => 'Marca extrações no mapa',       'image' => 'items/radio.png',          'price' => 11200, 'currency' => 'credits', 'category' => 'equipamento', 'tag' => null,      'owned' => false, 'featured' => false],

        /* ---------------------------------------------------------- suprimentos */
        ['slug' => 'medkit',         'name' => 'KIT MÉDICO',              'desc' => 'Restaura até 100 de saúde',     'image' => 'items/medkit.png',         'price' => 3200,  'currency' => 'credits', 'category' => 'suprimentos', 'tag' => null,      'owned' => false, 'featured' => true],
        ['slug' => 'surgical-kit',   'name' => 'KIT CIRÚRGICO',           'desc' => 'Restaura até 200 de saúde',     'image' => 'items/surgical-kit.png',   'price' => 9800,  'currency' => 'credits', 'category' => 'suprimentos', 'tag' => null,      'owned' => false, 'featured' => false],
        ['slug' => 'bandage',        'name' => 'BANDAGEM',                'desc' => 'Estanca sangramento leve',      'image' => 'items/bandage.png',        'price' => 850,   'currency' => 'credits', 'category' => 'suprimentos', 'tag' => null,      'owned' => false, 'featured' => false],
        ['slug' => 'water-bottle',   'name' => 'CANTIL DE ÁGUA',          'desc' => 'Repõe 60 de hidratação',        'image' => 'items/water-bottle.png',   'price' => 620,   'currency' => 'credits', 'category' => 'suprimentos', 'tag' => null,      'owned' => false, 'featured' => false],
        ['slug' => 'canned-food',    'name' => 'RAÇÃO ENLATADA',          'desc' => 'Repõe 45 de energia',           'image' => 'items/canned-food.png',    'price' => 740,   'currency' => 'credits', 'category' => 'suprimentos', 'tag' => null,      'owned' => false, 'featured' => false],
        ['slug' => 'supply-crate',   'name' => 'CAIXA DE SUPRIMENTOS',    'desc' => 'Itens e recursos aleatórios',   'image' => 'items/supply-crate.png',   'price' => 350,   'currency' => 'cash',    'category' => 'suprimentos', 'tag' => 'POPULAR', 'owned' => false, 'featured' => true],

        /* ------------------------------------------------------------- cosméticos */
        ['slug' => 'skin-camo',      'name' => 'CAMUFLAGEM MATA FECHADA', 'desc' => 'Skin de arma — Rara',           'image' => 'items/skin-camo.png',      'price' => 1200,  'currency' => 'points',  'category' => 'cosmeticos',  'tag' => 'RARO',    'owned' => false, 'featured' => true],
        ['slug' => 'clothing-tactical', 'name' => 'TRAJE CAÇADOR',        'desc' => 'Uniforme completo — Épico',     'image' => 'items/clothing-tactical.png', 'price' => 2400, 'currency' => 'points', 'category' => 'cosmeticos', 'tag' => 'ÉPICO',  'owned' => false, 'featured' => true],
        ['slug' => 'armband-usec',   'name' => 'BRAÇADEIRA USEC',         'desc' => 'Identificação de esquadrão',    'image' => 'items/armband-usec.png',   'price' => 450,   'currency' => 'points',  'category' => 'cosmeticos',  'tag' => null,      'owned' => false, 'featured' => false],
        ['slug' => 'glasses-aviator','name' => 'ÓCULOS AVIADOR',          'desc' => 'Acessório de rosto',            'image' => 'items/glasses-aviator.png','price' => 320,   'currency' => 'points',  'category' => 'cosmeticos',  'tag' => null,      'owned' => true,  'featured' => false],

        /* ----------------------------------------------------------------- passe */
        ['slug' => 'weapon-case',    'name' => 'MALETA DE ARMA',          'desc' => 'Contém uma arma aleatória',     'image' => 'items/weapon-case.png',    'price' => 900,   'currency' => 'cash',    'category' => 'passe',       'tag' => null,      'owned' => false, 'featured' => true],
        /* não existe items/xp-token.png no pacote de arte: o token de XP usa o café tático */
        ['slug' => 'xp-token',       'name' => 'ESTIMULANTE DE XP',       'desc' => '+50% de XP por 1 hora',         'image' => 'items/coffee.png',         'price' => 250,   'currency' => 'points',  'category' => 'passe',       'tag' => null,      'owned' => false, 'featured' => true],
        ['slug' => 'keycard',        'name' => 'CARTÃO DE ACESSO',        'desc' => 'Abre um cofre por extração',    'image' => 'items/keycard.png',        'price' => 1500,  'currency' => 'cash',    'category' => 'passe',       'tag' => 'LIMITADO','owned' => false, 'featured' => false],
        ['slug' => 'ammo-box',       'name' => 'CAIXA DE MUNIÇÃO',        'desc' => '240 cartuchos sortidos',        'image' => 'items/ammo-box.png',       'price' => 480,   'currency' => 'cash',    'category' => 'passe',       'tag' => null,      'owned' => false, 'featured' => false],
    ],

    'cta_buy'    => 'COMPRAR',
    'cta_locked' => 'SEM SALDO',
    'cta_busy'   => 'COMPRANDO',
    'cta_owned'  => 'NO INVENTÁRIO',
    'empty'      => 'Nenhum item nesta categoria.',
    'soon'       => 'EM BREVE',
];
