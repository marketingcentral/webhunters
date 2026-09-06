<?php declare(strict_types=1);

/**
 * Tela MENSAGENS (sem referência; âncora de estilo = 03-lobby).
 *
 * FASE 2: a tela NÃO lê mais este arquivo. Ele é a CARGA INICIAL do banco —
 * `db/seed_messages.php` transforma estas conversas em `message_threads` /
 * `message_entries` / `system_notices` / `maintenance_windows` para cada jogador, e
 * `MessagesController` lê tudo de `App\Repo\MessageRepo` conciliado por `user_id`.
 * Editar aqui só muda a tela depois de rodar `php db/seed_messages.php`.
 *
 * Chaves consumidas pela view:
 *  title, subtitle, unread, folders[], active_folder, search_ph, compose, compose_href,
 *  items[] (id, folder, from, system, subject, preview, time, unread, avatar, level, class,
 *           status, tag, thread[]), reply_ph, reply_send, system_panel{}, maintenance{}.
 * Cada item['thread'] é a conversa aberta no painel central (autor, texto, hora, self, color, sep).
 */
return [
    'title'    => 'MENSAGENS',
    'subtitle' => 'Central de comunicação da Resistência',
    'unread'   => 3,

    'folders' => [
        ['slug' => 'entrada',  'label' => 'ENTRADA',  'count' => 7, 'icon' => 'mail'],
        ['slug' => 'sistema',  'label' => 'SISTEMA',  'count' => 4, 'icon' => 'settings'],
        ['slug' => 'enviadas', 'label' => 'ENVIADAS', 'count' => 3, 'icon' => 'send'],
    ],
    'active_folder' => 'entrada',
    'search_ph'     => 'Buscar conversa ou operador...',
    'list_title'    => 'CONVERSAS',
    'compose'       => 'NOVA MENSAGEM',
    'compose_href'  => 'amigos',
    'empty_text'    => 'Nenhuma conversa nesta pasta.',

    'items' => [
        [
            'id' => 'm2', 'folder' => 'entrada', 'from' => 'CaçadorXP', 'system' => false,
            'subject' => 'Bora hoje 20h?', 'preview' => 'Montanha e Ghost confirmaram. Falta você pra fechar o squad.',
            'time' => '19:44', 'date' => 'Hoje, 19:44', 'unread' => false, 'active' => true,
            'avatar' => 'portraits/op-scout.jpg', 'level' => 18, 'class' => 'BATEDOR', 'class_ms' => 'visibility',
            'status' => 'ONLINE', 'status_kind' => 'online', 'color' => 'cyan',
            'thread' => [
                ['author' => 'CaçadorXP',  'text' => 'Fala, sumido. Bora hoje 20h?',                              'time' => '17:12', 'color' => 'cyan', 'sep' => 'ONTEM'],
                ['author' => 'self',       'text' => 'Bora. Tô terminando o contrato da floresta, faltam 5 kills.', 'time' => '17:20', 'color' => 'lime'],
                ['author' => 'CaçadorXP',  'text' => 'Montanha e Ghost confirmaram. Falta você pra fechar o squad.', 'time' => '17:22', 'color' => 'cyan'],
                ['author' => 'self',       'text' => 'Fecho sim. Só preciso reparar o colete antes.',              'time' => '17:26', 'color' => 'lime'],
                ['author' => 'CaçadorXP',  'text' => 'Tenho placa sobrando no estoque, te passo no lobby.',        'time' => '17:31', 'color' => 'cyan'],
                ['author' => 'CaçadorXP',  'text' => 'Vamos tentar SINAL PERDIDO na média e depois farmar a floresta.', 'time' => '19:30', 'color' => 'cyan', 'sep' => 'HOJE'],
                ['author' => 'self',       'text' => 'Fechado. Levo kit médico e munição extra pro Ghost.',        'time' => '19:38', 'color' => 'lime'],
                ['author' => 'CaçadorXP',  'text' => 'O Montanha pediu pra extrair pelo guindaste leste dessa vez.', 'time' => '19:41', 'color' => 'cyan'],
                ['author' => 'self',       'text' => 'Pode ser. A rota do vale tá cheia de patrulha desde a atualização.', 'time' => '19:43', 'color' => 'lime'],
                ['author' => 'CaçadorXP',  'text' => 'Perfeito. Te chamo no lobby às 19:55.',                      'time' => '19:44', 'color' => 'cyan'],
            ],
        ],
        [
            'id' => 'm1', 'folder' => 'entrada', 'from' => 'COMANDO DA RESISTÊNCIA', 'system' => true,
            'subject' => 'Recompensa do contrato disponível', 'preview' => 'Sua recompensa por CAÇA NA FLORESTA está pronta para resgate.',
            'time' => '18:30', 'date' => 'Hoje, 18:30', 'unread' => true,
            'avatar' => null, 'icon' => 'military_tech', 'level' => null, 'class' => 'COMANDO', 'class_ms' => 'shield',
            'status' => 'OFICIAL', 'status_kind' => 'system', 'color' => 'gold', 'tag' => 'OFICIAL',
            'thread' => [
                ['author' => 'COMANDO DA RESISTÊNCIA', 'text' => 'Caçador, o contrato CAÇA NA FLORESTA registrou 3 de 8 eliminações confirmadas.', 'time' => '18:30', 'color' => 'gold', 'sep' => 'HOJE'],
                ['author' => 'COMANDO DA RESISTÊNCIA', 'text' => 'A recompensa parcial de 2.500 XP já está disponível no seu perfil.',              'time' => '18:30', 'color' => 'gold'],
                ['author' => 'COMANDO DA RESISTÊNCIA', 'text' => 'Complete as 5 eliminações restantes antes do fim do ciclo para receber 7.500 XP, 4.200 em dinheiro e +2 de reputação.', 'time' => '18:31', 'color' => 'gold'],
            ],
        ],
        [
            'id' => 'm3', 'folder' => 'entrada', 'from' => 'LOJA', 'system' => true,
            'subject' => 'Oferta: Passe de Temporada -17%', 'preview' => 'Por tempo limitado o Passe de Temporada 01 sai por 2.450.',
            'time' => '09:00', 'date' => 'Hoje, 09:00', 'unread' => true,
            'avatar' => null, 'icon' => 'shopping_cart', 'level' => null, 'class' => 'LOJA', 'class_ms' => 'shopping_cart',
            'status' => 'PROMOÇÃO', 'status_kind' => 'system', 'color' => 'amber', 'tag' => 'OFERTA',
            'thread' => [
                ['author' => 'LOJA', 'text' => 'Por tempo limitado o Passe de Temporada 01 sai por 2.450 (de 2.950).', 'time' => '09:00', 'color' => 'amber', 'sep' => 'HOJE'],
                ['author' => 'LOJA', 'text' => '100 níveis de recompensas, incluindo a AK-103 dourada e 12 skins exclusivas.', 'time' => '09:00', 'color' => 'amber'],
            ],
        ],
        [
            'id' => 'm4', 'folder' => 'entrada', 'from' => 'Ghost_BR', 'system' => false,
            'subject' => 'Valeu pelo kit', 'preview' => 'Salvou demais ontem. Te devo uma bandagem.',
            'time' => '22:41', 'date' => 'Ontem, 22:41', 'unread' => true,
            'avatar' => 'portraits/op-support.jpg', 'level' => 16, 'class' => 'SUPORTE', 'class_ms' => 'medical_services',
            'status' => 'EM PARTIDA', 'status_kind' => 'busy', 'color' => 'lime',
            'thread' => [
                ['author' => 'Ghost_BR', 'text' => 'Salvou demais ontem. Te devo uma bandagem.',                   'time' => '22:41', 'color' => 'lime', 'sep' => 'ONTEM'],
                ['author' => 'self',     'text' => 'Tranquilo. Só não morre na zona de risco de novo.',            'time' => '22:47', 'color' => 'lime'],
                ['author' => 'Ghost_BR', 'text' => 'Prometo. Amanhã levo dois kits cirúrgicos.',                    'time' => '22:49', 'color' => 'lime'],
            ],
        ],
        [
            'id' => 'm5', 'folder' => 'entrada', 'from' => 'SISTEMA', 'system' => true,
            'subject' => 'Manutenção programada — 04/06', 'preview' => 'Servidores da América do Sul offline das 03h às 05h.',
            'time' => '27/05', 'date' => '27/05, 11:05', 'unread' => false,
            'avatar' => null, 'icon' => 'settings', 'level' => null, 'class' => 'SISTEMA', 'class_ms' => 'settings',
            'status' => 'AVISO', 'status_kind' => 'system', 'color' => 'amber',
            'thread' => [
                ['author' => 'SISTEMA', 'text' => 'Os servidores da América do Sul ficarão offline das 03h às 05h (horário de Brasília) em 04/06.', 'time' => '11:05', 'color' => 'amber', 'sep' => '27/05'],
                ['author' => 'SISTEMA', 'text' => 'A janela é para migração de infraestrutura. Contratos ativos serão preservados.',                'time' => '11:05', 'color' => 'amber'],
            ],
        ],
        [
            'id' => 'm6', 'folder' => 'entrada', 'from' => 'Montanha_7', 'system' => false,
            'subject' => 'Rota nova pela cachoeira', 'preview' => 'Achei um caminho que corta 4 min até a extração.',
            'time' => '26/05', 'date' => '26/05, 20:14', 'unread' => false,
            'avatar' => 'portraits/op-sniper.jpg', 'level' => 20, 'class' => 'FRANCO-ATIRADOR', 'class_ms' => 'my_location',
            'status' => 'OFFLINE', 'status_kind' => 'offline', 'color' => 'cyan',
            'thread' => [
                ['author' => 'Montanha_7', 'text' => 'Achei um caminho que corta 4 min até a plataforma de extração.', 'time' => '20:14', 'color' => 'cyan', 'sep' => '26/05'],
                ['author' => 'Montanha_7', 'text' => 'Marquei no mapa, dá uma olhada quando puder.',                    'time' => '20:15', 'color' => 'cyan'],
                ['author' => 'self',       'text' => 'Vi aqui. Passa longe do posto inimigo, boa.',                     'time' => '21:02', 'color' => 'lime'],
            ],
        ],
        [
            'id' => 'm7', 'folder' => 'entrada', 'from' => 'COMANDO DA RESISTÊNCIA', 'system' => true,
            'subject' => 'Conquista desbloqueada: SINAL PERDIDO', 'preview' => 'Você restaurou a comunicação do posto avançado norte.',
            'time' => '12/05', 'date' => '12/05, 23:58', 'unread' => false,
            'avatar' => null, 'icon' => 'military_tech', 'level' => null, 'class' => 'COMANDO', 'class_ms' => 'shield',
            'status' => 'OFICIAL', 'status_kind' => 'system', 'color' => 'gold', 'tag' => 'OFICIAL',
            'thread' => [
                ['author' => 'COMANDO DA RESISTÊNCIA', 'text' => 'Você restaurou a comunicação do posto avançado norte.',       'time' => '23:58', 'color' => 'gold', 'sep' => '12/05'],
                ['author' => 'COMANDO DA RESISTÊNCIA', 'text' => 'Recompensas creditadas: 6.500 XP, 2.400 em dinheiro, 1.200 pontos e o fuzil AK-103.', 'time' => '23:58', 'color' => 'gold'],
            ],
        ],
        [
            'id' => 's1', 'folder' => 'enviadas', 'from' => 'Montanha_7', 'system' => false,
            'subject' => 'Extração pelo porto', 'preview' => 'Se apertar, extrai pelo guindaste leste. Testei ontem.',
            'time' => '21:02', 'date' => 'Ontem, 21:02', 'unread' => false,
            'avatar' => 'portraits/op-sniper.jpg', 'level' => 20, 'class' => 'FRANCO-ATIRADOR', 'class_ms' => 'my_location',
            'status' => 'OFFLINE', 'status_kind' => 'offline', 'color' => 'cyan',
            'thread' => [
                ['author' => 'self',       'text' => 'Se apertar, extrai pelo guindaste leste. Testei ontem, ninguém rota por lá.', 'time' => '21:02', 'color' => 'lime', 'sep' => 'ONTEM'],
                ['author' => 'Montanha_7', 'text' => 'Anotado. Levo corda então.',                                                   'time' => '21:40', 'color' => 'cyan'],
            ],
        ],
        [
            'id' => 's2', 'folder' => 'enviadas', 'from' => 'Ghost_BR', 'system' => false,
            'subject' => 'Kit médico separado', 'preview' => 'Deixei dois kits e três bandagens no meu estoque pra você.',
            'time' => '25/05', 'date' => '25/05, 18:30', 'unread' => false,
            'avatar' => 'portraits/op-support.jpg', 'level' => 16, 'class' => 'SUPORTE', 'class_ms' => 'medical_services',
            'status' => 'EM PARTIDA', 'status_kind' => 'busy', 'color' => 'lime',
            'thread' => [
                ['author' => 'self',     'text' => 'Deixei dois kits e três bandagens no meu estoque pra você.', 'time' => '18:30', 'color' => 'lime', 'sep' => '25/05'],
                ['author' => 'Ghost_BR', 'text' => 'Salvou. Pego antes da próxima incursão.',                     'time' => '19:11', 'color' => 'lime'],
            ],
        ],
        [
            'id' => 's3', 'folder' => 'enviadas', 'from' => 'SUPORTE', 'system' => true,
            'subject' => 'Chamado #4127 — queda de conexão', 'preview' => 'Perdi conexão duas vezes no Porto Industrial.',
            'time' => '22/05', 'date' => '22/05, 09:44', 'unread' => false,
            'avatar' => null, 'icon' => 'headset_mic', 'level' => null, 'class' => 'SUPORTE TÉCNICO', 'class_ms' => 'headset_mic',
            'status' => 'RESOLVIDO', 'status_kind' => 'system', 'color' => 'cyan',
            'thread' => [
                ['author' => 'self',    'text' => 'Perdi conexão duas vezes no Porto Industrial, sempre perto do guindaste.', 'time' => '09:44', 'color' => 'lime', 'sep' => '22/05'],
                ['author' => 'SUPORTE', 'text' => 'Chamado #4127 registrado. O nó de rede da região foi reiniciado.',          'time' => '10:02', 'color' => 'cyan'],
                ['author' => 'SUPORTE', 'text' => 'Compensação de 500 créditos creditada na sua conta.',                       'time' => '10:03', 'color' => 'cyan'],
            ],
        ],
    ],

    'thread_actions' => [
        ['label' => 'Ver perfil',       'icon' => 'account_circle', 'href' => 'perfil'],
        ['label' => 'Convidar para o lobby', 'icon' => 'group',     'href' => 'lobby'],
        ['label' => 'Reportar',         'icon' => 'flag',           'href' => 'suporte'],
    ],
    'reply_ph'   => 'Escreva uma resposta...',
    'reply_send' => 'Enviar resposta',

    'system_panel' => [
        'title'  => 'MENSAGENS DO SISTEMA',
        'action' => ['label' => 'NOTÍCIAS', 'href' => 'noticias', 'icon' => 'chevron_right'],
        'items'  => [
            ['icon' => 'military_tech', 'color' => 'gold',  'title' => 'RECOMPENSA SEMANAL',
             'text' => '1.200 créditos e 2 caixas de suprimento liberados pelo Comando.',
             'time' => 'há 2 h', 'cta' => 'RESGATAR', 'href' => 'perfil', 'fresh' => true],
            ['icon' => 'redeem', 'color' => 'lime', 'title' => 'CONTRATO CONCLUÍDO',
             'text' => 'CAÇA NA FLORESTA pagou 2.500 XP parciais. 5 alvos restantes.',
             'time' => 'há 6 h', 'cta' => 'VER DESAFIOS', 'href' => 'desafios', 'fresh' => true],
            ['icon' => 'shopping_cart', 'color' => 'amber', 'title' => 'PASSE DE TEMPORADA -17%',
             'text' => 'Passe 01 por 2.450 até o fim do ciclo atual.',
             'time' => 'hoje, 09:00', 'cta' => 'ABRIR LOJA', 'href' => 'loja'],
            ['icon' => 'warning', 'color' => 'red', 'title' => 'MANUTENÇÃO PROGRAMADA',
             'text' => 'América do Sul offline das 03h às 05h em 04/06.',
             'time' => '27/05', 'cta' => 'DETALHES', 'href' => 'noticias/manutencao-programada'],
        ],
    ],

    'maintenance' => [
        'title'    => 'PRÓXIMA MANUTENÇÃO',
        'label'    => 'JANELA 04/06 · 03H — 05H',
        'note'     => 'Servidores da AMÉRICA DO SUL. Contratos ativos são preservados.',
        'seconds'  => 51_120,
        'href'     => 'noticias/manutencao-programada',
        'cta'      => 'LER AVISO COMPLETO',
    ],
];
