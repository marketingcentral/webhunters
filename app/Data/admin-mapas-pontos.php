<?php declare(strict_types=1);

/**
 * Rótulos e listas fechadas da tela ADMIN · CRIAR PONTOS NO MAPA (/admin/mapas/pontos).
 *
 * Só escrita de interface e opção de <select>. Nenhum número e nenhum estado: o conteúdo vem
 * de App\Repo\MapPointsRepo. Este arquivo existe porque controller (que valida) e view (que
 * desenha o <select>) precisam da MESMA lista — duplicá-la seria contratar o dia em que o
 * painel recusa um valor que ele mesmo ofereceu.
 *
 * As categorias NÃO estão aqui: elas são a chave de agrupamento da própria tabela e vivem em
 * MapPointsRepo::CATEGORIAS, lidas pelas duas pontas. O mesmo vale para as cores e os `kind`,
 * que são vocabulário do jogo (app/Data/admin-mapas.php e assets/css/pages/mapa.css).
 */

return [
    'titulo'    => 'Criar pontos no mapa',
    'subtitulo' => 'Os ícones que o editor arrasta para cima da arte',

    'intro' => 'Cada ponto é um ícone pré-fabricado com um propósito. O editor de mapa arrasta '
             . 'estes ícones para a arte; o marcador guarda só o nome do ponto, então trocar o '
             . 'desenho aqui conserta todos os pontos daquele tipo no mundo inteiro.',

    /* Origens do ícone. O valor é a dica que aparece sob o campo. */
    'origens' => [
        'glyph'  => ['label' => 'Vetor do jogo',    'hint' => 'Os desenhos que a tela do mapa já traz (extração, base, ninho…). Escalam melhor em marcador pequeno.'],
        'symbol' => ['label' => 'Material Symbol',  'hint' => 'Nome do ícone na fonte que a HUD já carrega — ex.: local_cafe, local_mall, local_gas_station.'],
        'image'  => ['label' => 'Arquivo enviado',  'hint' => 'Arte própria: SVG, PNG ou WebP quadrado, de preferência com fundo transparente. Um SVG enviado leva a própria cor (para o ícone acompanhar a cor do marcador, use um vetor do jogo).'],
    ],

    /**
     * Vetores disponíveis para a origem `glyph`. É a mesma lista de $GLYPHS em
     * app/Views/pages/mapa.php — só o que existe lá pode ser escolhido aqui, senão o painel
     * ofereceria um desenho que o mapa não sabe fazer.
     */
    'glifos' => [
        'extraction'   => 'Extração',
        'base'         => 'Posto (escudo)',
        'camp'         => 'Acampamento',
        'loot'         => 'Recursos (caixa)',
        'event'        => 'Evento (losango)',
        'enemy'        => 'Hostil',
        'radio'        => 'Torre de rádio',
        'village'      => 'Vila / complexo',
        'gov-base'     => 'Base Hunter',
        'aegis'        => 'Instalação Aegis',
        'frontier'     => 'Acampamento Frontier',
        'field-base'   => 'Base de campo',
        'nest'         => 'Ninho Veyra',
        'contaminated' => 'Área contaminada',
        'human'        => 'Atividade humana',
        'safe-zone'    => 'Safe Zone',
        'fuel'         => 'Bomba de combustível',
        'market'       => 'Mercado (toldo)',
        'hospital'     => 'Hospital (cruz)',
        'convoy'       => 'Comboio',
    ],

    /* Atalhos de Material Symbol oferecidos como sugestão — digitar qualquer outro nome
       da fonte continua valendo. */
    'simbolos' => [
        'local_cafe', 'restaurant', 'lunch_dining', 'local_bar', 'bakery_dining',
        'local_mall', 'storefront', 'shopping_cart', 'local_pharmacy', 'local_grocery_store',
        'local_gas_station', 'ev_station', 'propane_tank', 'bolt',
        'medical_services', 'sanitizer', 'vaccines', 'local_hospital',
        'directions_car', 'two_wheeler', 'directions_boat', 'flight',
        'water_drop', 'build', 'payments', 'flag', 'place', 'warehouse', 'apartment', 'church',
    ],

    'vazios' => [
        'catalogo' => 'Nenhum ponto cadastrado ainda. Crie o primeiro e ele aparece na paleta do editor.',
        'filtro'   => 'Nenhum ponto corresponde ao que você procurou.',
    ],

    'notas' => [
        'sistema'  => 'Ponto do jogo: o painel edita o desenho e o rótulo, mas não apaga — o comportamento dele está no código.',
        'uso'      => 'Marcadores no mundo usando este ponto.',
        'semuso'   => 'Ainda não usado em nenhum mapa.',
        'excluir'  => 'Os marcadores que usavam este ponto NÃO são apagados: eles voltam a ser desenhados pelo tipo bruto (%s).',
    ],
];
