<?php declare(strict_types=1);

/**
 * Rótulos e catálogos da tela ADMIN · MAPAS (/admin/mapas).
 *
 * Aqui mora só o que é ESCRITA de interface e lista fechada de opção. Nenhum número, nenhum
 * estado: o que a tela mostra vem de App\Repo\MapsRepo. O motivo de existir este arquivo é
 * que os catálogos de marcador, cor e atividade precisam ser lidos em dois lugares (o
 * controller, para validar, e a view, para desenhar o <select>) — duplicar as duas listas era
 * a garantia de que um dia elas iam divergir e o painel ia recusar um valor que ele mesmo
 * oferece.
 *
 * Os catálogos de EXTRAÇÃO, ATIVIDADE e INFESTAÇÃO NÃO estão aqui: são regra do jogo e vivem
 * em App\Core\GameRules. O painel lê de lá para não criar uma segunda verdade.
 */

return [
    /* Cabeçalho da listagem. A chave é o valor de ?ordem= — só o que estiver aqui ordena. */
    'colunas' => [
        'ordem'      => ['label' => '#',           'ordena' => true,  'classe' => 'tr'],
        'mapa'       => ['label' => 'Mapa',        'ordena' => 'nome'],
        'tipo'       => ['label' => 'Tipo',        'ordena' => true],
        'config'     => ['label' => 'Configuração', 'ordena' => false],
        'nivel'      => ['label' => 'Nível',       'ordena' => true,  'classe' => 'tr'],
        'marcadores' => ['label' => 'Marcadores',  'ordena' => true,  'classe' => 'tr'],
        'estado'     => ['label' => 'Estado',      'ordena' => true],
    ],

    /* Filtro de estado da barra de ferramentas: rótulo do <option>. */
    'estados' => [
        ''           => 'Todos os estados',
        'aberto'     => 'Abertos',
        'bloqueado'  => 'Bloqueados',
        'sem-ue'     => 'Sem level da Unreal',
    ],

    /**
     * Tipos de marcador que o jogo sabe desenhar — é a mesma lista de
     * App\Controllers\MapController::LAYER_OF, com o nome que o operador reconhece.
     * O valor é a camada do filtro do mapa, mostrada como pista no <select>.
     */
    'marcadores' => [
        'mission'         => ['label' => 'Objetivo de missão',    'camada' => 'missões'],
        'extraction'      => ['label' => 'Extração',              'camada' => 'extração'],
        'gov_base'        => ['label' => 'Base Hunter',           'camada' => 'hunter'],
        'field_base'      => ['label' => 'Base de campo',         'camada' => 'base de campo'],
        'base'            => ['label' => 'Posto',                 'camada' => 'posto'],
        'camp'            => ['label' => 'Acampamento',           'camada' => 'posto'],
        'village'         => ['label' => 'Vila / complexo',       'camada' => 'posto'],
        'radio'           => ['label' => 'Torre de rádio',        'camada' => 'posto'],
        'safe_zone'       => ['label' => 'Safe Zone',             'camada' => 'safe zone'],
        'aegis'           => ['label' => 'Instalação Aegis',      'camada' => 'aegis'],
        'frontier'        => ['label' => 'Instalação Frontier',   'camada' => 'frontier'],
        'nest'            => ['label' => 'Ninho Veyra',           'camada' => 'veyra'],
        'colony'          => ['label' => 'Colônia Veyra',         'camada' => 'veyra'],
        'core'            => ['label' => 'Núcleo Veyra',          'camada' => 'veyra'],
        'surge'           => ['label' => 'Surto Veyra',           'camada' => 'veyra'],
        'migration'       => ['label' => 'Migração Veyra',        'camada' => 'veyra'],
        'enemy'           => ['label' => 'Hostil genérico',       'camada' => 'veyra'],
        'contaminated'    => ['label' => 'Área contaminada',      'camada' => 'contaminação'],
        'human_activity'  => ['label' => 'Atividade humana',      'camada' => 'humana'],
        'convoy'          => ['label' => 'Comboio',               'camada' => 'logística'],
        'supply'          => ['label' => 'Suprimento',            'camada' => 'logística'],
        'supply_drop'     => ['label' => 'Entrega aérea',         'camada' => 'logística'],
        'resupply'        => ['label' => 'Reabastecimento',       'camada' => 'logística'],
        'abandoned_cargo' => ['label' => 'Carga abandonada',      'camada' => 'logística'],
        'bio_regrowth'    => ['label' => 'Rebrota biológica',     'camada' => 'logística'],
        'event'           => ['label' => 'Evento',                'camada' => 'evento'],
        'loot'            => ['label' => 'Recursos',              'camada' => 'recursos'],
        'fuel'            => ['label' => 'Combustível',           'camada' => 'gasolina'],
        'market'          => ['label' => 'Mercado',               'camada' => 'mercado'],
        'hospital'        => ['label' => 'Hospital',              'camada' => 'hospital'],
    ],

    /* Cores de marcador aceitas pelo CSS do mapa (assets/css/pages/mapa.css). */
    'cores' => [
        'white'  => 'Branco (neutro)',
        'lime'   => 'Verde (aliado)',
        'cyan'   => 'Ciano (Aegis)',
        'amber'  => 'Âmbar (Frontier)',
        'red'    => 'Vermelho (hostil)',
        'purple' => 'Roxo (Icarus)',
        'gold'   => 'Dourado (destaque)',
        'muted'  => 'Apagado',
    ],

    /* Onde o rótulo do marcador se ancora em relação ao pino, no mapa do jogo. */
    'rotulos' => [
        ''       => 'Sem rótulo no mapa',
        'right'  => 'À direita',
        'left'   => 'À esquerda',
        'top'    => 'Acima',
        'bottom' => 'Abaixo',
    ],

    /* Microcopy: por que um campo existe. Aparece como .adm-hint sob o campo. */
    'ajudas' => [
        'slug'         => 'Identificador na URL e nas integrações. Só minúsculas, números e hífen — mudar quebra links já salvos.',
        'kind'         => 'Região é território do mundo persistente (aparece no Mapa do Mundo). Partida é arena de sessão.',
        'unreal_level' => 'Caminho do level na Unreal, ex.: /Game/Maps/L_FlorestaVerde. Vazio significa que o level ainda não existe.',
        'image'        => 'Caminho relativo dentro de /media ou /assets/img, ex.: bg/map-world.jpg.',
        'locked'       => 'Bloqueado aparece na lista do jogo com cadeado, mas não pode ser jogado.',
        'sort_order'   => 'Menor primeiro. Empate cai para ordem alfabética.',
        'capacidade'   => 'Teto humano do setor. O padrão do PRD é 20 = 12 Hunters + 8 Mercenários.',
        'infestacao'   => '0 a 100. O jogo converte em BAIXA / CONTROLADA / CRESCENTE / ALTA / CRÍTICA.',
        'extracoes'    => 'Uma linha por ponto de saída. Deixe o nome em branco para remover a linha ao salvar.',
        'evento'       => 'Evento em cartaz na região. Sem nome, a região não mostra faixa de evento.',
        'coordenada'   => '0 a 1, medido da borda esquerda / do topo da imagem do mapa. 0,5 é o centro.',
    ],

    /* Estados vazios — nunca uma tabela em branco sem explicação. */
    'vazios' => [
        'sem_mapa_filtro' => [
            'titulo' => 'Nenhum mapa com esse filtro',
            'txt'    => 'O catálogo tem mapas cadastrados, mas nenhum casa com a busca e os filtros atuais.',
        ],
        'sem_mapa' => [
            'titulo' => 'O catálogo de mapas está vazio',
            'txt'    => 'Nenhuma região do mundo e nenhum mapa de partida foi cadastrado ainda. O Mapa do Mundo do jogo cai no conteúdo de demonstração até o primeiro cadastro.',
        ],
        'sem_marcador' => [
            'titulo' => 'Este mapa não tem marcadores',
            'txt'    => 'Sem marcador, o mapa abre no jogo apenas com a imagem: nenhuma extração, base ou ninho aparece para o jogador.',
        ],
    ],
];
