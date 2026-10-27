<?php declare(strict_types=1);

/**
 * Rótulos da tela ADMIN · RELATÓRIOS (/admin/relatorios).
 *
 * Aqui só mora TEXTO e a cor de cada estado. Nenhum número, nenhuma consulta: o que a tela
 * mostra vem de App\Repo\ReportsRepo, e as listas de CATEGORIA (áreas de bug e categorias de
 * chamado) não estão duplicadas neste arquivo de propósito — elas vêm das mesmas fontes que o
 * jogador vê (app/Data/relatorio-de-bug.php e a tabela support_categories). Duas listas de
 * rótulos para o mesmo campo divergem no primeiro dia em que alguém acrescenta uma área.
 *
 * VOCABULÁRIO DE ESTADO — por que as duas abas não usam as mesmas quatro palavras
 * -------------------------------------------------------------------------------
 * O pedido da tela falava em aberto/em análise/resolvido/fechado. Isso é exatamente o que
 * `support_tickets` guarda. `player_bug_reports`, porém, foi escrito pelo jogo com OUTRO
 * vocabulário (recebido, analise, corrigido, duplicado, recusado) e a tela do jogador
 * (App\Controllers\SupportController::BUG_STATUS) só sabe traduzir esses cinco: gravar
 * "resolvido" num bug faria o relatório aparecer como "EM FILA" para quem o abriu, porque é
 * esse o rótulo de fallback lá. O painel então fala a língua de cada tabela — o mesmo ciclo,
 * com os nomes que o jogo já usa.
 */

return [

    /* --------------------------------------------------------------------- abas */
    'abas' => [
        'bugs' => [
            'label' => 'BUGS',
            'icone' => 'bug_report',
            'sub'   => 'Relatórios enviados pela tela de bug do jogo',
        ],
        'suporte' => [
            'label' => 'SUPORTE',
            'icone' => 'support_agent',
            'sub'   => 'Chamados abertos pelos jogadores',
        ],
    ],

    /* ------------------------------------------------------------------ estados
       ordem = ordem do ciclo (é a ordem dos chips e a do seletor de mudança).
       'aberto' marca o que ainda conta como fila de trabalho.
       'encerra' marca o estado terminal — nos bugs é ele que carimba closed_at. */
    'status' => [
        'bugs' => [
            'recebido'  => ['rotulo' => 'EM FILA',    'tom' => 'info',   'icone' => 'inbox',        'aberto' => true,  'encerra' => false, 'ajuda' => 'Chegou e ainda não foi triado.'],
            'analise'   => ['rotulo' => 'EM ANÁLISE', 'tom' => 'warn',   'icone' => 'biotech',      'aberto' => true,  'encerra' => false, 'ajuda' => 'Alguém está reproduzindo o problema.'],
            'corrigido' => ['rotulo' => 'CORRIGIDO',  'tom' => 'ok',     'icone' => 'check_circle', 'aberto' => false, 'encerra' => true,  'ajuda' => 'A correção entrou numa build.'],
            'duplicado' => ['rotulo' => 'DUPLICADO',  'tom' => 'muted',  'icone' => 'content_copy', 'aberto' => false, 'encerra' => true,  'ajuda' => 'Mesmo problema de outro relatório já aberto.'],
            'recusado'  => ['rotulo' => 'RECUSADO',   'tom' => 'danger', 'icone' => 'block',        'aberto' => false, 'encerra' => true,  'ajuda' => 'Não é defeito, não reproduz ou falta informação.'],
        ],
        'suporte' => [
            'aberto'    => ['rotulo' => 'ABERTO',     'tom' => 'info',   'icone' => 'mark_email_unread', 'aberto' => true,  'encerra' => false, 'ajuda' => 'Aguardando o primeiro atendimento.'],
            'analise'   => ['rotulo' => 'EM ANÁLISE', 'tom' => 'warn',   'icone' => 'support_agent',     'aberto' => true,  'encerra' => false, 'ajuda' => 'Em atendimento pela equipe.'],
            'resolvido' => ['rotulo' => 'RESOLVIDO',  'tom' => 'ok',     'icone' => 'task_alt',          'aberto' => false, 'encerra' => true,  'ajuda' => 'O jogador foi atendido e o caso fechou resolvido.'],
            'fechado'   => ['rotulo' => 'FECHADO',    'tom' => 'muted',  'icone' => 'lock',              'aberto' => false, 'encerra' => true,  'ajuda' => 'Encerrado sem solução: desistência, engano ou duplicidade.'],
        ],
    ],

    /* -------------------------------------------------------- gravidade / prioridade
       Do mais grave para o menos: é esta ordem que a ordenação "por gravidade" usa. */
    'severidades' => [
        'critica' => ['rotulo' => 'CRÍTICA', 'tom' => 'danger'],
        'alta'    => ['rotulo' => 'ALTA',    'tom' => 'gold'],
        'media'   => ['rotulo' => 'MÉDIA',   'tom' => 'warn'],
        'baixa'   => ['rotulo' => 'BAIXA',   'tom' => 'muted'],
    ],
    'prioridades' => [
        'CRÍTICA' => ['rotulo' => 'CRÍTICA', 'tom' => 'danger'],
        'ALTA'    => ['rotulo' => 'ALTA',    'tom' => 'gold'],
        'NORMAL'  => ['rotulo' => 'NORMAL',  'tom' => 'muted'],
        'BAIXA'   => ['rotulo' => 'BAIXA',   'tom' => 'muted'],
    ],

    /* ------------------------------------------------------------------ ordenação */
    'ordens' => [
        'recentes' => 'MAIS RECENTES',
        'antigos'  => 'MAIS ANTIGOS',
        'peso'     => 'GRAVIDADE',      // bugs: severidade; suporte: prioridade
    ],

    /* ------------------------------------------------- mensagens de erro por campo
       O POST responde com redirect (padrão POST/Redirect/GET, senão F5 regrava). O que
       viaja na URL é o CÓDIGO do erro, nunca a frase: assim a mensagem continua sendo
       escrita aqui e a barra de endereços não vira um campo de texto editável. */
    'erros' => [
        'status_invalido'  => 'Escolha um dos estados da lista.',
        'status_igual'     => 'Este relatório já está nesse estado — nada foi alterado.',
        'nao_encontrado'   => 'Relatório não encontrado. Ele pode ter sido excluído em outra aba.',
        'aba_invalida'     => 'Aba desconhecida.',
        'excluir_aberto'   => 'Só é possível excluir um relatório já encerrado. Feche-o antes.',
    ],

    /* ------------------------------------------------------------ estados vazios */
    'vazios' => [
        'bugs'         => 'Nenhum relatório de bug foi enviado pelo jogo ainda. Esta lista se enche sozinha quando um jogador usa a tela RELATÓRIO DE BUG.',
        'suporte'      => 'Nenhum chamado aberto até agora. Os jogadores abrem chamados pela tela SUPORTE do jogo.',
        'filtro'       => 'Nenhum relatório atende a estes filtros. Os números nos chips acima são do filtro atual.',
        'detalhe'      => 'Escolha um relatório na lista para ler a descrição inteira e mudar o estado.',
        'sem_texto'    => 'Não informado neste relatório.',
    ],

    /* ------------------------------------------------------------------ microcopy */
    'micro' => [
        'busca_bugs'      => 'Buscar por código, título, descrição ou jogador',
        'busca_suporte'   => 'Buscar por protocolo, assunto, mensagem ou jogador',
        'todos_status'    => 'TODOS OS ESTADOS',
        'todas_areas'     => 'TODAS AS ÁREAS',
        'todas_cats'      => 'TODAS AS CATEGORIAS',
        'todas_sev'       => 'TODAS AS GRAVIDADES',
        'todas_prio'      => 'TODAS AS PRIORIDADES',
        'limpar'          => 'LIMPAR FILTROS',
        'abrir'           => 'Abrir relatório',
        'fila'            => 'EM ABERTO',
        'total'           => 'NO PERÍODO TODO',
        'mudar'           => 'MUDAR ESTADO',
        'mudar_btn'       => 'APLICAR',
        'excluir'         => 'EXCLUIR',
        'excluir_ajuda'   => 'A exclusão apaga o relatório da conta do jogador. O conteúdo fica guardado na trilha de auditoria.',
        'excluir_confirma'=> 'Excluir %s em definitivo? O jogador deixa de ver este relatório no histórico dele.',
        'excluir_travado' => 'Encerre o relatório antes de excluir.',
        'logs_on'         => 'LOGS ANEXADOS',
        'logs_off'        => 'SEM LOGS',
        'aberto_em'       => 'ABERTO EM',
        'encerrado_em'    => 'ENCERRADO EM',
        'atualizado_em'   => 'ATUALIZADO EM',
        'em_aberto'       => 'em aberto',
        'jogador'         => 'JOGADOR',
        'conta_removida'  => 'conta removida',
        'descricao'       => 'DESCRIÇÃO',
        'passos'          => 'PASSOS PARA REPRODUZIR',
        'mensagem'        => 'MENSAGEM DO JOGADOR',
        'sla'             => 'SLA DA CATEGORIA',
        'pager'           => '%s–%s de %s',
    ],
];
