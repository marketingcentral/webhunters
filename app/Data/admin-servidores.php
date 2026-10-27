<?php declare(strict_types=1);

/**
 * Cromo da tela ADMIN · SERVIDORES (/admin/servidores).
 *
 * Só TEXTO. Nenhum número e nenhum estado: tudo que a tela mostra sai de App\Repo\ServersRepo,
 * que lê o banco. Um valor numérico aqui estaria mentindo por definição.
 *
 * Está separado da view pelo mesmo motivo de app/Data/admin-dashboard.php: o vocabulário de
 * operação muda ("serviço" virou "sistema", "janela" virou "parada") e trocar uma palavra não
 * pode obrigar ninguém a mexer no HTML da tela.
 */

return [

    'titulos' => [
        'regioes'  => 'Regiões',
        'servicos' => 'Serviços da plataforma',
        'janelas'  => 'Janelas de manutenção',
    ],

    'subtitulos' => [
        'regioes'  => 'Estado, ping e ocupação por região',
        'servicos' => 'O que a tela de suporte do jogo mostra',
        'janelas'  => 'Paradas programadas por região',
    ],

    /**
     * O ALCANCE de cada bloco. É a frase mais importante da tela: quem edita aqui está mexendo
     * no que o jogador vê, e o painel tem de dizer isso antes do clique — não depois.
     */
    'alcance' => [
        'regioes'  => 'Alimenta o bloco "Status dos servidores" do painel e a tela /suporte do jogo.',
        'servicos' => 'O serviço fora do ar com horário marcado vira a contagem regressiva da tela /suporte.',
        'janelas'  => 'A janela mais próxima aparece como aviso na caixa de mensagens do jogador.',
    ],

    /* ------------------------------------------------------------------ estados vazios ---- */
    'vazios' => [
        'regioes'          => 'Nenhuma região cadastrada. Sem elas, o painel e a tela de suporte mostram o bloco de status em branco.',
        'regioes_filtro'   => 'Nenhuma região corresponde à busca ou ao filtro de estado.',
        'servicos'         => 'Nenhum serviço cadastrado. A tela de suporte do jogo dirá "todos os sistemas operacionais" sem ter o que conferir.',
        'servicos_filtro'  => 'Nenhum serviço corresponde à busca ou ao filtro de estado.',
        'janelas'          => 'Nenhuma janela de manutenção registrada.',
        'janelas_filtro'   => 'Nenhuma janela corresponde a este filtro.',
    ],

    /* ------------------------------------------------------------------ microcópia ------- */
    'micro' => [
        'estado_col'     => 'Trocar',
        'estado_titulo'  => 'Trocar o estado desta linha sem abrir o formulário — a operação mais frequente do plantão.',
        'orfa'           => 'Esta janela aponta para um rótulo de região que não existe mais: ela continua avisando o jogador, mas não pinta região nenhuma no painel.',
        'no_contador'    => 'É esta linha que está na contagem regressiva da tela de suporte.',
        'ping_unidade'   => 'ms',
        'ocupacao'       => 'Barra comparada com a região mais cheia — o banco não guarda teto por região.',
        'semente'        => 'Ping e contador de jogadores são valores declarados nesta tela, não medição de rede.',
    ],

    /* ------------------------------------------------------------------ formulários ------ */
    'form' => [
        'regiao' => [
            'novo'   => 'Nova região',
            'editar' => 'Editar região',
            'ajuda'  => [
                'label'   => 'É esta palavra que as janelas de manutenção guardam. Renomear aqui renomeia nas janelas junto.',
                'slug'    => 'Identificador curto e único. Em branco, sai do rótulo.',
                'ping'    => 'Latência anunciada na tela de suporte, em milissegundos.',
                'players' => 'Contador exibido. Não é presença medida — é o número que você declara.',
                'ordem'   => 'Menor primeiro, na tela do painel e na do jogo.',
            ],
        ],
        'servico' => [
            'novo'   => 'Novo serviço',
            'editar' => 'Editar serviço',
            'ajuda'  => [
                'status' => 'Frase cheia, como aparece na tela de suporte: OPERACIONAL, MANUTENÇÃO...',
                'curto'  => 'Versão curta, para o cartão estreito da HUD.',
                'escopo' => 'O que exatamente está parando. Em branco, a tela usa o nome do serviço.',
                'janela' => 'Início e duração só valem para serviço FORA do estado operacional — é o par que liga a contagem regressiva.',
            ],
        ],
        'janela' => [
            'novo'   => 'Nova janela',
            'editar' => 'Editar janela',
            'ajuda'  => [
                'regiao'  => 'Escolhida da lista de regiões cadastradas: digitar o rótulo à mão é o que cria janela órfã.',
                'nota'    => 'Uma linha, mostrada ao jogador junto do aviso.',
                'noticia' => 'Notícia de destino do aviso. Opcional.',
            ],
        ],
    ],

    /* ------------------------------------------------------------------ exclusão --------- */
    'excluir' => [
        'titulo'   => 'Confirmar exclusão',
        'aviso'    => 'A exclusão é imediata e não tem desfazer. A trilha de auditoria guarda o que foi apagado.',
        'bloqueio' => 'Não dá para excluir esta região: %d janela(s) de manutenção apontam para o rótulo "%s" e ficariam órfãs. Apague ou remaneje as janelas primeiro.',
    ],
];
