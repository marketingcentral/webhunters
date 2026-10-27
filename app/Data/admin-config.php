<?php declare(strict_types=1);

/**
 * Vocabulário e REGRAS da tela Configurações do Jogo (/admin/configuracoes).
 *
 * Por que isto vive fora do banco: `admin_settings` guarda o VALOR (o que muda sem deploy);
 * aqui ficam a explicação e os limites — que só mudam quando o CÓDIGO que lê a chave muda.
 * Gravar "mínimo 1, máximo 8" numa coluna daria ao operador o poder de afrouxar a própria
 * trava que o protege; o limite tem de acompanhar quem consome o número, não quem o edita.
 *
 * ESTRUTURA
 *   'grupos'  => metadados de cada `grupo` da tabela (rótulo, ícone, ordem, o que ele governa)
 *   'chaves'  => por chave: dica, unidade, limites e widget
 *   'tipos'   => rótulo e ícone de cada `kind`
 *   'texto'   => microcopy da tela (títulos, vazios, avisos)
 *
 * Chave que existir no banco e NÃO estiver aqui continua aparecendo e sendo editável: cai no
 * padrão do seu tipo. Uma tela de configuração que esconde a chave que não conhece é pior do
 * que uma que a mostra sem legenda — o operador ao menos vê que ela existe.
 */

return [

    /* ===================================================================== grupos */

    'grupos' => [
        'servidor' => [
            'label' => 'Servidor e acesso',
            'desc'  => 'A porta de entrada: janela de manutenção e cadastro de novas contas.',
            'icon'  => 'dns',
            'ordem' => 10,
        ],
        'mundo' => [
            'label' => 'Mundo persistente',
            'desc'  => 'Lotação de uma região e o ritmo com que o mundo se mexe sozinho.',
            'icon'  => 'public',
            'ordem' => 20,
        ],
        'partida' => [
            'label' => 'Partida',
            'desc'  => 'Regras de uma incursão em andamento.',
            'icon'  => 'groups',
            'ordem' => 30,
        ],
        'safe_zone' => [
            'label' => 'Safe Zone',
            'desc'  => 'Turnos e permanência nos perímetros seguros.',
            'icon'  => 'security',
            'ordem' => 40,
        ],
        'progressao' => [
            'label' => 'Progressão',
            'desc'  => 'Economia de experiência e passe.',
            'icon'  => 'trending_up',
            'ordem' => 50,
        ],
        'suporte' => [
            'label' => 'Suporte',
            'desc'  => 'Compromissos de atendimento visíveis para o jogador.',
            'icon'  => 'support_agent',
            'ordem' => 60,
        ],
        'geral' => [
            'label' => 'Geral',
            'desc'  => 'Parâmetros que ainda não têm casa própria.',
            'icon'  => 'tune',
            'ordem' => 90,
        ],
    ],

    /* ===================================================================== tipos */

    'tipos' => [
        'string' => ['label' => 'Texto',   'icon' => 'title'],
        'int'    => ['label' => 'Número',  'icon' => 'tag'],
        'bool'   => ['label' => 'Chave',   'icon' => 'toggle_on'],
        'json'   => ['label' => 'JSON',    'icon' => 'data_object'],
    ],

    /* =====================================================================
       chaves

       'dica'    frase que explica a CONSEQUÊNCIA de mexer, não o que o campo é
       'unidade' sufixo colado ao número (min, h, XP) — some do valor gravado
       'min'/'max'  faixa aceita pelo servidor; fora dela o campo volta com erro
       'max_len' teto de caracteres de um texto
       'linhas'  > 1 desenha textarea em vez de input
       'ligado'/'desligado'  como um bool se lê em cada estado (estado tem cor E TEXTO)
       ===================================================================== */

    'chaves' => [
        'maintenance_enabled' => [
            'dica'      => 'Ligado, o jogo recusa entrada em operação e mostra o aviso abaixo. Não derruba quem já está no mundo.',
            'ligado'    => 'Em manutenção',
            'desligado' => 'No ar',
            'perigo'    => true,
            'ordem'     => 10,
        ],
        'maintenance_message' => [
            'dica'    => 'Aparece em caixa alta na tela de bloqueio. Uma frase fechada — não cabe parágrafo.',
            'max_len' => 160,
            'linhas'  => 2,
            'ordem'   => 20,
        ],
        'registration_open' => [
            'dica'      => 'Desligado, /criar-conta continua existindo mas recusa novos cadastros.',
            'ligado'    => 'Aberto ao público',
            'desligado' => 'Fechado',
            'ordem'     => 30,
        ],

        'region_capacity_total' => [
            'dica'    => 'Teto humano de uma região (Bíblia §3). Precisa ser maior ou igual à soma de Hunters e Mercenários.',
            'unidade' => 'operadores',
            'min'     => 2,
            'max'     => 200,
            'ordem'   => 10,
        ],
        'region_capacity_hunters' => [
            'dica'    => 'Vagas reservadas ao Hunter Command — 3 Bases Governamentais × 4 por esquadrão.',
            'unidade' => 'vagas',
            'min'     => 0,
            'max'     => 200,
            'ordem'   => 20,
        ],
        'region_capacity_mercenaries' => [
            'dica'    => 'Vagas sob contrato de facção (Aegis e Frontier somados).',
            'unidade' => 'vagas',
            'min'     => 0,
            'max'     => 200,
            'ordem'   => 30,
        ],
        'human_signal_ttl_min' => [
            'dica'    => 'Quanto tempo um rastro humano fica visível no mapa antes de esfriar.',
            'unidade' => 'min',
            'min'     => 1,
            'max'     => 1440,
            'ordem'   => 40,
        ],
        'world_event_interval_min' => [
            'dica'    => 'Espaçamento entre dois eventos de ciclo de mundo. Muito curto, o mundo vira feira.',
            'unidade' => 'min',
            'min'     => 5,
            'max'     => 10080,
            'ordem'   => 50,
        ],

        'squad_max' => [
            'dica'    => 'Unidade Hunter: 1 jogador + acompanhantes. A Bíblia trava em 4 — acima disso o balanceamento não foi testado.',
            'unidade' => 'operadores',
            'min'     => 1,
            'max'     => 8,
            'ordem'   => 10,
        ],

        'safe_shift_minutes' => [
            'dica'    => 'Duração de um turno na Safe Zone. Ao fim dele o operador é devolvido ao mundo.',
            'unidade' => 'min',
            'min'     => 5,
            'max'     => 480,
            'ordem'   => 10,
        ],

        'xp_per_level' => [
            'dica'    => 'XP de um nível do passe. Mexer aqui reposiciona TODO mundo que já progrediu.',
            'unidade' => 'XP',
            'min'     => 100,
            'max'     => 1000000,
            'perigo'  => true,
            'ordem'   => 10,
        ],

        'support_sla_hours' => [
            'dica'    => 'Prazo prometido na tela de suporte. É texto de vitrine: prometa o que a equipe cumpre.',
            'unidade' => 'h',
            'min'     => 1,
            'max'     => 720,
            'ordem'   => 10,
        ],
    ],

    /* ===================================================================== texto */

    'texto' => [
        'subtitulo'      => 'Parâmetros que o jogo lê em tempo real',
        'vazio_titulo'   => 'Nenhum parâmetro corresponde ao filtro',
        'vazio_txt'      => 'A tabela admin_settings tem parâmetros, mas nenhum passa pela busca e pelos filtros atuais. Limpe os filtros para ver todos.',
        'vazio_tabela'   => 'A tabela admin_settings está vazia',
        'vazio_tabela_txt' => 'Nenhuma chave foi semeada ainda. Rode db/patch_v6_admin.php (é aditivo e idempotente) ou crie a primeira chave pelo formulário abaixo.',
        'sem_alteracao'  => 'Nada mudou: os valores enviados são iguais aos que já estavam gravados.',
        'protegida'      => 'Esta chave é lida diretamente pelo código do jogo. Apagá-la faria o jogo cair no valor embutido em App\\Core\\GameRules sem ninguém ser avisado — por isso ela só pode ser editada, nunca removida.',
        'anterior'       => 'Valor anterior',
        'json_ajuda'     => 'JSON válido. É gravado em forma compacta e devolvido indentado para leitura.',
    ],
];
