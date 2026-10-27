<?php declare(strict_types=1);

/**
 * Vocabulário da tela API (/admin/api).
 *
 * Por que fora do controller e fora da view: o texto desta tela é o que separa um operador
 * que cria uma chave segura de um que cola `players:write` numa integração de relatório. Ele
 * é revisado como texto — junto, num arquivo só —, não caçado no meio de HTML.
 *
 * REGRA DAS FRASES DAQUI: dizem a CONSEQUÊNCIA, não a mecânica. "Marque o que a chave vai
 * fazer" é mecânica; "quem tem este escopo pode promover uma conta a administrador" é
 * consequência — e é a consequência que faz alguém parar e pensar antes de marcar.
 */
return [
    'titulo'    => 'API de Gestão',
    'subtitulo' => 'Chaves com escopo, log de chamadas e a referência dos endpoints.',

    'micro' => [
        'uma_vez'    => 'A chave aparece UMA única vez, agora. Ela é guardada como hash — nem o painel nem o banco conseguem mostrá-la de novo.',
        'perdeu'     => 'Perdeu a chave? Crie outra e revogue esta. Não há como recuperá-la.',
        'sem_chaves' => 'Nenhuma chave criada ainda.',
        'sem_log'    => 'Nenhuma chamada registrada ainda.',
        'falha_leitura' => 'Não foi possível ler esta lista: o banco não respondeu. Isto não é o mesmo que "não há nada".',
        'revogar_aviso' => 'Revogar tem efeito imediato: a próxima chamada com esta chave recebe 401. Não dá para desfazer.',
        'bearer'     => 'A chave vai sempre no cabeçalho. Nunca na URL: querystring fica gravada no log do servidor, no Referer e no histórico.',
    ],

    /* Avisos colados ao escopo cuja concessão tem consequência maior do que o nome sugere. */
    'avisos_escopo' => [
        'players:write' => 'Permite promover uma conta a administrador — ou seja, conceder acesso ao painel.',
        'bans:write'    => 'Permite banir e revogar banimento de qualquer conta que não seja a do dono.',
        'settings:write'=> 'Permite mudar regras do jogo (capacidade, XP, prazos) sem passar por esta tela.',
        'servers:write' => 'Permite ligar e desligar o modo manutenção, que fecha o jogo para todo mundo.',
    ],

    'estados' => [
        'ativa'    => ['rotulo' => 'Ativa',    'tom' => 'ok'],
        'revogada' => ['rotulo' => 'Revogada', 'tom' => 'danger'],
        'vencida'  => ['rotulo' => 'Vencida',  'tom' => 'warn'],
    ],

    /* Faixas de status do log. Cor E texto, sempre: a cor sozinha não sobrevive a monitor
       ruim nem a quem não distingue verde de vermelho. */
    'status' => [
        'ok'       => ['rotulo' => 'Atendida',  'tom' => 'ok'],
        'recusado' => ['rotulo' => 'Recusada',  'tom' => 'warn'],
        'erro'     => ['rotulo' => 'Com erro',  'tom' => 'danger'],
    ],

    'ajuda' => [
        'titulo' => 'Como chamar',
        'passos' => [
            'Crie uma chave marcando só os escopos de que a integração precisa.',
            'Copie a chave agora — ela não será mostrada outra vez.',
            'Mande em todo pedido: Authorization: Bearer <chave>.',
            'Confira em GET /api/v1/health, que responde sem chave nenhuma.',
        ],
    ],
];
