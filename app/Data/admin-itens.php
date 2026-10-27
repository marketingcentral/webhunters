<?php declare(strict_types=1);

/**
 * Vocabulário e microcopy da tela Itens (/admin/itens).
 *
 * Mora fora do controller porque as MESMAS listas são usadas em três lugares que não podem
 * divergir: a validação do servidor (o que é aceito), os <select> do formulário (o que é
 * oferecido) e os rótulos da tabela (como o valor gravado é lido em português). Um `category`
 * novo entra aqui uma vez e os três passam a conhecê-lo.
 *
 * As chaves são exatamente os valores gravados em `items` — nunca traduza dentro do banco.
 * Valor que já existe no banco e não está aqui NÃO é apagado nem rejeitado: o controller une
 * esta lista com o que o SELECT DISTINCT devolve e mostra o valor cru. É a diferença entre um
 * catálogo que o painel governa e um catálogo que o painel esconde.
 */

return [
    /* ---------------------------------------------------------------- vocabulário do domínio */

    /** items.category — a família do objeto. Ordem = ordem dos <optgroup>/select. */
    'categorias' => [
        'weapon'     => 'Arma principal',
        'pistol'     => 'Pistola',
        'knife'      => 'Faca',
        'ammo'       => 'Munição',
        'mag'        => 'Carregador',
        'attachment' => 'Acessório',
        'grenade'    => 'Granada',
        'helmet'     => 'Capacete',
        'armor'      => 'Colete',
        'rig'        => 'Rig tático',
        'backpack'   => 'Mochila',
        'mask'       => 'Máscara',
        'glasses'    => 'Óculos',
        'earpiece'   => 'Comunicador',
        'armband'    => 'Braçadeira',
        'clothing'   => 'Vestuário',
        'med'        => 'Médico',
        'food'       => 'Comida',
        'drink'      => 'Bebida',
        'tool'       => 'Ferramenta',
        'key'        => 'Chave',
        'skin'       => 'Skin',
        'misc'       => 'Diversos',
    ],

    /**
     * items.rarity. A ordem É a escala — a listagem ordena por ela, não pelo alfabeto
     * (alfabético colocaria "épico" antes de "raro" e mentiria sobre a hierarquia).
     * O `tom` casa com os modificadores de .adm-badge que já existem em assets/css/admin.css.
     */
    'raridades' => [
        'comum'    => ['label' => 'Comum',    'tom' => 'muted'],
        'incomum'  => ['label' => 'Incomum',  'tom' => 'ok'],
        'raro'     => ['label' => 'Raro',     'tom' => 'info'],
        'epico'    => ['label' => 'Épico',    'tom' => 'purple'],
        'lendario' => ['label' => 'Lendário', 'tom' => 'gold'],
    ],

    /** items.slot_type — em qual slot do personagem a peça entra. NULL = não é equipável. */
    'slots' => [
        'primary'   => 'Principal (costas)',
        'secondary' => 'Secundária',
        'pistol'    => 'Coldre',
        'knife'     => 'Faca',
        'helmet'    => 'Cabeça',
        'mask'      => 'Rosto',
        'glasses'   => 'Olhos',
        'earpiece'  => 'Ouvido',
        'armor'     => 'Torso',
        'rig'       => 'Rig',
        'backpack'  => 'Costas',
        'armband'   => 'Braço',
    ],

    /**
     * items.currency. O valor de `base_price` é um INT sem casa decimal e a UNIDADE é a
     * própria moeda — por isso o rótulo curto aparece colado ao número na tabela, e a tela
     * nunca divide por 100 "achando" que é centavo.
     */
    'moedas' => [
        'credits' => ['label' => 'Créditos', 'curto' => 'cr'],
        'points'  => ['label' => 'Pontos',   'curto' => 'pt'],
        'cash'    => ['label' => 'Dinheiro', 'curto' => '$'],
    ],

    /** Ordenações oferecidas. A chave vai na URL (?ord=), o SQL correspondente é do repo. */
    'ordenacoes' => [
        'nome'    => 'Nome (A→Z)',
        'recente' => 'Cadastro mais recente',
        'preco'   => 'Preço (maior primeiro)',
        'peso'    => 'Peso (maior primeiro)',
        'uso'     => 'Mais possuído',
    ],

    /* ------------------------------------------------------------------------- microcopy */

    'titulos' => [
        'lista'    => 'Catálogo de itens',
        'filtros'  => 'Filtrar catálogo',
        'novo'     => 'Novo item',
        'editar'   => 'Editar item',
        'excluir'  => 'Excluir item',
        'resumo'   => 'Resumo do catálogo',
    ],

    /**
     * Estados vazios. Cada um explica a CAUSA, porque "nenhum resultado" sozinho não diz se o
     * catálogo está vazio, se o filtro é estreito demais ou se o banco caiu.
     */
    'vazios' => [
        'catalogo' => [
            'titulo' => 'O catálogo ainda está vazio',
            'txt'    => 'Nenhuma linha em `items`. Cadastre o primeiro objeto do jogo para que ele possa aparecer na loja, nas recompensas e no inventário.',
        ],
        'filtro' => [
            'titulo' => 'Nenhum item com esses filtros',
            'txt'    => 'O catálogo tem itens, mas nenhum satisfaz a busca e os filtros atuais. Limpe os filtros para ver tudo de novo.',
        ],
    ],

    /** Frases que a tela usa mais de uma vez e precisam ser idênticas nas duas aparições. */
    'frases' => [
        'uso_col'      => 'Itens que alguém possui não podem ser excluídos — a exclusão apagaria a posse em cascata.',
        'imagem_falta' => 'O arquivo de imagem não foi encontrado em /media nem em /assets/img. O item funciona, mas aparece sem arte.',
        'json_livre'   => 'JSON. Deixe em branco para gravar NULL.',
        'slug_regra'   => 'Minúsculas, números e hífen. É a chave usada pelo jogo e pelos seeds — mudar quebra referências por nome.',
        'preco_unid'   => 'Número inteiro na moeda escolhida. O catálogo não usa centavos.',
    ],
];
