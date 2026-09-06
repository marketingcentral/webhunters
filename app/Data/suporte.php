<?php declare(strict_types=1);

/**
 * Tela SUPORTE — FAQ (acordeão) + abertura de chamado + status dos servidores.
 *
 * FASE 2: este arquivo é a FONTE DO SEED (db/seed_support.php) e o fallback de quando o banco
 * não está instalado. Em execução normal o SupportController troca `faq`, `categories`,
 * `status`, `maintenance`, `tickets` e o PROTOCOLO de `ticket_meta` pelo que vem do banco
 * (App\Repo\SupportRepo), conciliado por user_id. Os rótulos fixos (`form`, `success`,
 * `related`, textos de cabeçalho) continuam saindo daqui.
 */
return [
    'title'    => 'SUPORTE',
    'tag'      => 'CENTRAL DE AJUDA',
    'intro'    => 'Consulte as perguntas frequentes ou abra um chamado direto com a equipe de operações.',

    /* ---- categorias (viram as opções do stepper ASSUNTO) ------------------ */
    'categories' => [
        ['slug' => 'conta',        'label' => 'CONTA E ACESSO',        'sla' => '12H'],
        ['slug' => 'pagamento',    'label' => 'COMPRAS E PAGAMENTOS',  'sla' => '6H'],
        ['slug' => 'tecnico',      'label' => 'PROBLEMA TÉCNICO',      'sla' => '8H'],
        ['slug' => 'jogabilidade', 'label' => 'JOGABILIDADE',          'sla' => '24H'],
        ['slug' => 'denuncia',     'label' => 'DENUNCIAR JOGADOR',     'sla' => '48H'],
        ['slug' => 'outro',        'label' => 'OUTRO ASSUNTO',         'sla' => '24H'],
    ],

    /* ---- formulário ------------------------------------------------------- */
    'form' => [
        'panel_title'    => 'ABRIR CHAMADO',
        'subject_label'  => 'ASSUNTO',
        'category_label' => 'CATEGORIA',
        'priority_label' => 'PRIORIDADE',
        'priorities'     => ['BAIXA', 'NORMAL', 'ALTA', 'CRÍTICA'],
        'priority'       => 'NORMAL',
        'message_label'  => 'DESCRIÇÃO',
        'message_ph'     => 'Descreva o que aconteceu, quando e o que você esperava que acontecesse.',
        'message_max'    => 2000,
        'message_hint'   => 'Mínimo de 20 caracteres. Informe o horário da partida e o mapa.',
        'attach_label'   => 'Anexar registros de diagnóstico da última partida',
        'submit'         => 'ENVIAR',
        'bug_label'      => 'RELATÓRIO DE BUG',
        'sla_label'      => 'RESPOSTA ESTIMADA',
    ],

    /* ---- atalhos sugeridos sob a descrição -------------------------------- */
    'related_title' => 'ARTIGOS RELACIONADOS',
    'related' => [
        ['label' => 'RECUPERAÇÃO DE ITENS', 'href' => 'noticias'],
        ['label' => 'VINCULAR CONTA',       'href' => 'configuracoes/conta'],
        ['label' => 'REEMBOLSOS',           'href' => 'loja'],
    ],

    /* ---- identificação do chamado (linha superior do formulário) ---------- */
    'ticket_meta' => [
        ['label' => 'PROTOCOLO',  'value' => '#48213'],
        ['label' => 'PLATAFORMA', 'value' => 'PC / STEAM'],
        ['label' => 'BUILD',      'value' => '1.4.2-B'],
    ],

    /* ---- painel de sucesso (POST + flash) --------------------------------- */
    'success' => [
        'title' => 'CHAMADO REGISTRADO',
        'desc'  => 'Sua solicitação entrou na fila da equipe de operações.',
        'steps' => [
            'Protocolo enviado para o e-mail cadastrado.',
            'Acompanhe o andamento em MENSAGENS.',
            'Prazo médio de resposta: 6 horas.',
        ],
        'primary'   => ['label' => 'IR PARA MENSAGENS',    'href' => 'mensagens'],
        'secondary' => ['label' => 'ABRIR OUTRO CHAMADO',  'href' => 'suporte'],
    ],

    /* ---- FAQ (acordeão, 4 itens) ------------------------------------------ */
    'faq_title' => 'PERGUNTAS FREQUENTES',
    'faq' => [
        [
            'q' => 'Perdi itens após uma queda de conexão. Como recupero?',
            'a' => 'Itens perdidos por desconexão são restaurados automaticamente em até 24 horas. Se não voltarem, abra um chamado em PROBLEMA TÉCNICO informando o horário da partida.',
        ],
        [
            'q' => 'Como vinculo minha conta Steam?',
            'a' => 'Em CONFIGURAÇÕES > CONTA, use a opção de vinculação. A conta vinculada libera o login com um clique e sincroniza suas conquistas.',
        ],
        [
            'q' => 'Posso transferir moedas entre contas?',
            'a' => 'Não. Créditos, dinheiro e pontos são intransferíveis. Tentativas de venda externa resultam em suspensão permanente.',
        ],
        [
            'q' => 'O que acontece com meu progresso na manutenção?',
            'a' => 'Nada é perdido. Partidas em andamento são encerradas dez minutos antes, com aviso no HUD, e todo o loot recolhido é preservado.',
        ],
    ],
    'faq_foot' => 'Não encontrou a resposta?',

    /* ---- canais de atendimento -------------------------------------------- */
    'contacts_title' => 'CANAIS DE ATENDIMENTO',
    'contacts' => [
        ['icon' => 'headset_mic', 'label' => 'CHAT AO VIVO', 'value' => 'Seg a sex, 09h às 21h',      'meta' => 'ONLINE',   'tone' => 'lime'],
        ['icon' => 'mail',        'label' => 'E-MAIL',       'value' => 'suporte@extracthunters.gg', 'meta' => '6H',       'tone' => 'muted'],
        ['icon' => 'group',       'label' => 'COMUNIDADE',   'value' => 'Canal oficial de operadores', 'meta' => 'ABRIR',  'tone' => 'muted', 'href' => 'mensagens'],
    ],

    /* ---- status dos servidores -------------------------------------------- */
    'status' => [
        'label'   => 'STATUS DOS SERVIDORES',
        'value'   => 'Todos os sistemas operacionais',
        'badge'   => 'OPERACIONAL',
        'ok'      => true,
        'updated' => 'ATUALIZADO HÁ',
        'regions' => [
            ['label' => 'AMÉRICA DO SUL',   'ping' => 32,  'players' => '12.480', 'state' => 'ok'],
            ['label' => 'AMÉRICA DO NORTE', 'ping' => 96,  'players' => '18.204', 'state' => 'ok'],
            ['label' => 'EUROPA',           'ping' => 142, 'players' => '21.870', 'state' => 'ok'],
            ['label' => 'ÁSIA',             'ping' => 208, 'players' => '9.310',  'state' => 'ok'],
        ],
        'services_title' => 'SERVIÇOS',
        'services' => [
            ['label' => 'LOGIN',    'value' => 'OPERACIONAL', 'short' => 'ESTÁVEL',    'state' => 'ok'],
            ['label' => 'PARTIDAS', 'value' => 'OPERACIONAL', 'short' => 'ESTÁVEL',    'state' => 'ok'],
            ['label' => 'LOJA',     'value' => 'MANUTENÇÃO',  'short' => 'MANUTENÇÃO', 'state' => 'warn'],
        ],
    ],

    /* ---- manutenção programada (contagem regressiva ao vivo) -------------- */
    'maintenance' => [
        'title'     => 'MANUTENÇÃO PROGRAMADA',
        'service'   => 'LOJA E MERCADO',
        'desc'      => 'Janela de 40 minutos.',
        'label'     => 'INÍCIO EM',
        'starts_in' => 8115,
    ],

    /* ---- chamados do jogador ---------------------------------------------- */
    'tickets_title' => 'MEUS CHAMADOS',
    'tickets' => [
        ['id' => '#48120', 'subject' => 'Itens perdidos após queda',   'state' => 'RESOLVIDO',  'tone' => 'lime',  'date' => '02/09'],
        ['id' => '#47984', 'subject' => 'Cobrança duplicada na loja',  'state' => 'EM ANÁLISE', 'tone' => 'amber', 'date' => '29/08'],
        ['id' => '#47730', 'subject' => 'Denúncia de jogador',         'state' => 'FECHADO',    'tone' => 'muted', 'date' => '21/08'],
    ],
];
