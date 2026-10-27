<?php declare(strict_types=1);

/**
 * Microcopy da tela ADMIN · EVENTOS (/admin/eventos).
 *
 * Só TEXTO e RÓTULO. Nenhum número, nenhuma regra: o que a tela mostra vem do banco, por
 * App\Repo\EventsRepo. Mora aqui o que precisa ser lido e revisado como frase — o nome de
 * cada aba, o que cada estado quer dizer e, principalmente, os estados vazios, que são o
 * lugar onde um painel mente com mais facilidade ("nenhum resultado" quando o catálogo tem
 * 15 linhas e o filtro é que está apertado).
 */

return [

    /* As três abas. `conta` diz qual número do EventsRepo::resumo() aparece ao lado do nome. */
    'abas' => [
        'desafios' => [
            'label' => 'Desafios',
            'icon'  => 'military_tech',
            'conta' => 'desafios',
            'sub'   => 'Catálogo da tela /desafios do jogo, com o bônus de cada aba.',
        ],
        'noticias' => [
            'label' => 'Notícias',
            'icon'  => 'feed',
            'conta' => 'noticias',
            'sub'   => 'O que aparece no mural da HUD. Sem data de publicação é rascunho.',
        ],
        'avisos' => [
            'label' => 'Avisos do sistema',
            'icon'  => 'campaign',
            'conta' => 'avisos',
            'sub'   => 'Painel “MENSAGENS DO SISTEMA”. Global ou dirigido a um jogador.',
        ],
    ],

    /* Escopo do desafio = aba da tela do jogo. Os valores são os de ChallengeRepo::SCOPES. */
    'escopos' => [
        'diarios'   => 'Diários',
        'semanais'  => 'Semanais',
        'temporada' => 'Temporada',
    ],

    /* Cor do aviso. O rótulo é o SIGNIFICADO, não o nome da tinta: quem escolhe está
       escolhendo o tom da mensagem, e "âmbar" não diz nada sobre isso sozinho. */
    'cores' => [
        'lime'  => ['label' => 'Lima · confirmação', 'hint' => 'Recompensa liberada, contrato concluído.'],
        'gold'  => ['label' => 'Ouro · conquista',   'hint' => 'Prêmio, marco, recompensa de peso.'],
        'amber' => ['label' => 'Âmbar · atenção',    'hint' => 'Promoção acabando, prazo curto.'],
        'red'   => ['label' => 'Vermelho · alerta',  'hint' => 'Manutenção, punição, indisponibilidade.'],
        'cyan'  => ['label' => 'Ciano · informação', 'hint' => 'Novidade, aviso neutro.'],
    ],

    /* Estado vazio. Sempre duas versões: catálogo realmente vazio x filtro que zerou. */
    'vazios' => [
        'desafios' => [
            'titulo' => 'Nenhum desafio no catálogo',
            'txt'    => 'A tela /desafios do jogo abre sem nada para fazer enquanto isto estiver vazio.',
        ],
        'noticias' => [
            'titulo' => 'Nenhuma notícia cadastrada',
            'txt'    => 'O mural da HUD fica em branco até a primeira notícia ser publicada.',
        ],
        'avisos' => [
            'titulo' => 'Nenhum aviso do sistema',
            'txt'    => 'O painel lateral de mensagens do jogo não mostra nada enquanto não houver avisos.',
        ],
        'filtro' => [
            'titulo' => 'Nada com esses filtros',
            'txt'    => 'O catálogo tem registros — o que está aqui em cima é que não deixa nenhum passar.',
        ],
    ],

    /* Avisos honestos sobre o alcance real de cada coisa. Aparecem na tela, não no código. */
    'notas' => [
        'aviso_global' => 'O aviso global fica guardado e visível aqui, mas ainda NÃO chega ao jogador: '
            . 'App\\Repo\\MessageRepo::notices() lê `WHERE user_id = :u` e por isso só entrega aviso '
            . 'endereçado. Ele passa a aparecer na HUD quando aquela consulta incluir `OR user_id IS NULL`.',
        'noticia_futura' => 'A data está no futuro, mas o jogo mostra a notícia assim mesmo: '
            . 'App\\Repo\\CatalogRepo::news() ordena por `sort_order` e não filtra por data. '
            . 'Para segurar a publicação, deixe a data em branco (rascunho).',
        'corpo_noticia' => 'Um parágrafo por bloco, separado por linha em branco. '
            . 'É gravado como lista JSON, que é o formato que a HUD lê.',
    ],
];
