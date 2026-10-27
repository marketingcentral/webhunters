<?php declare(strict_types=1);

/**
 * Cromo da tela ADMIN · DASHBOARD (/admin).
 *
 * Aqui mora só TEXTO e ROTA — título de painel, rótulo de atalho, frase de estado vazio.
 * Nenhum número: todo dado do dashboard sai de App\Repo\AdminRepo, que lê o banco. Se você
 * encontrar um valor numérico neste arquivo, ele está no lugar errado.
 *
 * Por que em Data e não na view: os rótulos do painel mudam com o vocabulário do jogo (o
 * mesmo motivo de app/Data/admin_nav.php), e mudar uma palavra não pode exigir mexer no
 * HTML da tela.
 *
 * ATALHOS (`acoes`): apontam para as telas previstas do painel, as mesmas da sidebar. As que
 * ainda não têm rota em app/routes.php aparecem apagadas e sem link — o controller descobre
 * isso lendo a tabela de rotas, então nada aqui precisa ser editado quando a tela nascer.
 */

return [

    /* ---------------------------------------------------------------- faixa de boas-vindas */
    'boas_vindas' => [
        'saudacao' => 'Bem-vindo(a), %s',   // %s = nome do administrador
        'texto'    => 'Gerencie o mundo persistente, acompanhe as métricas e mantenha tudo sob controle.',
        'imagem'   => 'bg/admin.jpg',
    ],

    /* ------------------------------------------------------------------ títulos de painel */
    'titulos' => [
        // O gráfico NÃO se chama igual ao KPI ao vivo do topo: dois títulos idênticos com
        // números diferentes (1 e 267) na mesma tela se leem como contradição. O qualificador
        // só aparece enquanto a série for de demonstração — ver 'qualificador_serie'.
        'grafico'    => 'Curva de jogadores online',
        'servidores' => 'Status dos servidores',
        'ranking'    => 'Top jogadores',
        'receita'    => 'Receita',
        'eventos'    => 'Eventos ativos',
        'acoes'      => 'Ações rápidas',
        'atividade'  => 'Atividade recente',
        'sistema'    => 'Status do sistema',
    ],

    /* --- seletor de período do gráfico de linha: vira ?dias=N, sem JavaScript nenhum ----- */
    'periodos' => [
        ['dias' => 7,  'label' => '7 dias'],
        ['dias' => 14, 'label' => '14 dias'],
        ['dias' => 30, 'label' => '30 dias'],
    ],

    /**
     * ATALHOS. A lista é a ordem de prioridade, não a promessa: o controller confere cada
     * rota em app/routes.php e só as que existem viram botão. O que ainda não nasceu não
     * aparece de forma alguma — a barra lateral já é o mapa do painel, e repetir a lista de
     * telas futuras no bloco mais nobre da coluna direita não é ação, é catálogo.
     *
     * A PRIMEIRA é administrativa e existe sempre: recarrega o painel, que é a única forma
     * de reconsultar as métricas hoje. A grade é de duas colunas e a view derruba o último
     * atalho quando a conta fica ímpar — meia célula vazia no canto é buraco, não respiro.
     *
     * "Abrir o jogo" saiu daqui de propósito: virou o link discreto do rodapé do cartão
     * (`micro.jogo`). Ele não é operação administrativa e não pode ter o mesmo peso.
     * "Sair do painel" também saiu: já é o botão grande no pé da barra lateral.
     *
     * Rótulo curto porque o botão tem meia coluna: "Config. do servidor" saía truncado.
     */
    'acoes' => [
        ['icone' => 'refresh',       'label' => 'Atualizar',    'sub' => 'Reconsultar o banco',  'rota' => 'admin'],
        ['icone' => 'monitor_heart', 'label' => 'Saúde',        'sub' => 'Serviços e regiões',   'rota' => 'saude'],
        ['icone' => 'support_agent', 'label' => 'Suporte',      'sub' => 'Fila de chamados',     'rota' => 'suporte'],
        ['icone' => 'campaign',      'label' => 'Mural',        'sub' => 'Notícias no ar',       'rota' => 'noticias'],
        ['icone' => 'group',         'label' => 'Jogadores',    'sub' => 'Contas e progresso',   'rota' => 'admin/jogadores'],
        ['icone' => 'dns',           'label' => 'Servidores',   'sub' => 'Regiões e serviços',   'rota' => 'admin/servidores'],
        ['icone' => 'gavel',         'label' => 'Banimentos',   'sub' => 'Punir jogador',        'rota' => 'admin/banimentos'],
        ['icone' => 'description',   'label' => 'Logs',         'sub' => 'Trilha de auditoria',  'rota' => 'admin/logs'],
    ],

    /* ------------------------------------------------------- links "ver tudo" dos painéis
       Enquanto a rota não existir, o cabeçalho do cartão NÃO mostra nada: o par
       "VER RANKING + EM BREVE" empurrava o título para duas linhas e desalinhava a faixa
       inteira. O link acende sozinho quando a tela nascer — ver DashboardController. */
    'links' => [
        'ranking'    => ['label' => 'Ver ranking',    'rota' => 'admin/jogadores'],
        'receita'    => ['label' => 'Ver relatório',  'rota' => 'admin/relatorios'],
        'eventos'    => ['label' => 'Ver todos',      'rota' => 'admin/eventos'],
        'servidores' => ['label' => 'Ver servidores',  'rota' => 'admin/servidores'],
        'atividade'  => ['label' => 'Ver todas',      'rota' => 'admin/logs'],
    ],

    /* -------------------------------------------------------------------- estados de dado */
    'estados' => [
        'ativo'      => 'Ativo',
        'agendado'   => 'Agendado',
        'encerrado'  => 'Encerrado',
        'online'     => 'Online',
        'manutencao' => 'Em manutenção',
        'agendada'   => 'Manut. agendada',
        'instavel'   => 'Instável',
        'fora'       => 'Fora do ar',
    ],

    /* A coluna de estado da lista de regiões é de largura FIXA (é ela que põe os quatro pills
       na mesma vertical), então lá o rótulo é o curto: a nota logo abaixo da região já diz
       qual janela é. "Manut. agendada" só cabia com reticências. */
    'estados_regiao' => [
        'manutencao' => 'Manutenção',
        'agendada'   => 'Agendada',
    ],

    /**
     * Como a tela ADMITE o que não sabe. Cada chave é um valor de AdminRepo::FONTE_*; o texto
     * aparece no rodapé do painel e no title do selo, para o operador nunca confundir número
     * medido com número semeado.
     */
    'fontes' => [
        'vivo'    => ['selo' => 'ao vivo',     'texto' => 'Consulta direta nas tabelas do jogo.'],
        'serie'   => ['selo' => 'série',       'texto' => 'Série diária de admin_metrics_daily — hoje semeada por db/patch_v6_admin.php, até existir telemetria.'],
        'semente' => ['selo' => 'amostra',     'texto' => 'Coluna real com conteúdo de demonstração: os contadores de server_regions foram semeados e não são presença medida.'],
        'ausente' => ['selo' => 'sem origem',  'texto' => 'Ainda não há de onde ler este número. Nada é estimado.'],
    ],

    /* ------------------------------------------------------------------------ nada a mostrar */
    'vazios' => [
        'grafico'   => 'Sem série no período — admin_metrics_daily não tem linhas nestes dias.',
        'ranking'   => 'Nenhum jogador cadastrado ainda.',
        'eventos'   => 'Nenhuma temporada e nenhuma janela de manutenção registradas.',
        'atividade' => 'Nada aconteceu ainda — nem no jogo, nem no painel.',
        'servidores'=> 'Nenhuma região cadastrada em server_regions.',
        'receita'   => 'Sem receita registrada no período.',
    ],

    /* ------------------------------------------------------------------------- microcopy */
    'micro' => [
        // O travessão do delta é MUDO na tela: a frase inteira mora no title. Repetir
        // "sem base" em três cartões vizinhos fazia o painel parecer desligado.
        'sem_base'     => 'sem base de comparação',
        'compras'      => 'Compras reais: %s (%s em moeda do jogo)',
        'ocupacao'     => 'Ocupação relativa à região mais cheia',
        'media'        => 'Média: %s',
        'vaga'         => 'Vaga em aberto',
        /* Link discreto no rodapé do cartão de ações: sair do painel é operação da barra
           lateral e abrir a HUD não é operação administrativa nenhuma. */
        'jogo'         => ['label' => 'Abrir a HUD do jogador', 'rota' => 'inicio'],
        /* Legenda ÚNICA da fileira de serviços: o estado vira cor + ponto no chip, e a
           palavra "manutenção" sai de dentro do rótulo — era ela que fazia um chip ter o
           dobro da largura dos vizinhos e a fileira perder o ritmo. */
        'servicos_fora' => '%s de %s serviços fora do padrão',
        /* Ranking sem nenhum progresso: uma tabela de zeros e travessões lê como painel
           desligado. A tela diz o fato uma vez e lista as contas, sem colunas numéricas. */
        'sem_progresso' => 'Ainda sem progresso registrado — %s',
        'contas'        => ['%s conta criada', '%s contas criadas'],
        'ocupacao_zero' => 'nenhum jogador on-line agora',
        /* Qualificador colado ao título do painel enquanto o número vem de série semeada:
           é o que impede "Jogadores online 267" e "Jogadores online 1" na mesma tela. */
        'qualificador' => [
            'serie'   => 'série de demonstração',
            'semente' => 'contadores de amostra',
        ],
    ],
];
