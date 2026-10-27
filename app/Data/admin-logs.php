<?php declare(strict_types=1);

/**
 * Cromo da tela ADMIN · LOGS DO SISTEMA (/admin/logs).
 *
 * Só TEXTO. Nenhum número e nenhuma consulta: tudo que a tela mostra sai de
 * App\Repo\AuditRepo, que lê `admin_audit_log`. Se você encontrar um valor de dado aqui,
 * ele está no lugar errado.
 *
 * O DICIONÁRIO DE AÇÕES (`acoes`) É O CORAÇÃO DESTE ARQUIVO. A coluna `action` do banco é
 * um identificador técnico (`login_falhou`), e a tela precisa dizer o que aquilo significa
 * para quem audita. A regra de honestidade: o rótulo em português é uma CONVENIÊNCIA de
 * leitura — o identificador cru continua visível no detalhe de todo registro, para o
 * operador poder conferir o que está no banco. Ação que ainda não tem verbete aqui não é
 * inventada: aparece com o próprio nome (só com os sublinhados virando espaço) e tom neutro.
 */

return [

    'titulos' => [
        'lista'   => 'Trilha de auditoria',
        'detalhe' => 'Detalhe do registro',
        'resumo'  => 'Resumo do recorte',
        'filtros' => 'Filtrar',
    ],

    /* --------------------------------------------------------------------- barra de filtros */
    'filtros' => [
        'busca_rotulo'   => 'Buscar',
        'busca_dica'     => 'Ação, alvo, IP, operador ou trecho do resumo',
        'acao_rotulo'    => 'Ação',
        'acao_todas'     => 'Todas as ações',
        'quem_rotulo'    => 'Quem fez',
        'quem_todos'     => 'Qualquer pessoa',
        'quem_anonimo'   => 'Sem conta identificada',
        'de_rotulo'      => 'De',
        'ate_rotulo'     => 'Até',
        'aplicar'        => 'Filtrar',
        'limpar'         => 'Limpar filtros',
        'periodo_rotulo' => 'Período',
    ],

    /* Atalhos de período: cada um só escreve o campo "De" (o "Até" fica vazio = até agora).
       Um único par de datas manda na consulta — dois controles disputando a mesma coisa
       criariam estado contraditório na URL. */
    'periodos' => [
        ['chave' => 'hoje',  'label' => 'Hoje',     'dias' => 0],
        ['chave' => '7d',    'label' => '7 dias',   'dias' => 6],
        ['chave' => '30d',   'label' => '30 dias',  'dias' => 29],
        ['chave' => 'tudo',  'label' => 'Tudo',     'dias' => null],
    ],

    /* ------------------------------------------------------------------ cabeçalho da tabela */
    'colunas' => [
        'id'     => '#',
        'quando' => 'Quando',
        'acao'   => 'Ação',
        'quem'   => 'Quem fez',
        'alvo'   => 'Alvo',
        'resumo' => 'O que aconteceu',
        'ip'     => 'IP',
    ],

    /**
     * Dicionário de ações. `tom` casa com as cores de .adm-badge (ok, warn, danger, info,
     * muted) e `icone` é um Material Symbol. `nota` explica o que a linha PROVA — é o texto
     * que aparece no detalhe, para quem audita não precisar adivinhar o significado.
     */
    'acoes' => [
        'login' => [
            'label' => 'Entrou no painel',
            'icone' => 'login',
            'tom'   => 'ok',
            'nota'  => 'Sessão administrativa aberta com sucesso.',
        ],
        'logout' => [
            'label' => 'Saiu do painel',
            'icone' => 'logout',
            'tom'   => 'muted',
            'nota'  => 'Sessão administrativa encerrada pelo próprio operador.',
        ],
        'login_falhou' => [
            'label' => 'Falha de login',
            'icone' => 'gpp_maybe',
            'tom'   => 'warn',
            'nota'  => 'Tentativa recusada. O campo "motivo" no meta separa senha incorreta de conta sem acesso ao painel.',
        ],
        'login_bloqueado' => [
            'label' => 'Login bloqueado',
            'icone' => 'block',
            'tom'   => 'danger',
            'nota'  => 'Tentativa durante a espera imposta pelo excesso de falhas do mesmo IP.',
        ],
        'sessao_expirada' => [
            'label' => 'Sessão expirada',
            'icone' => 'timer_off',
            'tom'   => 'muted',
            'nota'  => 'A sessão do painel caiu por inatividade — o painel derrubou, não a pessoa.',
        ],
        'acesso_revogado' => [
            'label' => 'Acesso revogado',
            'icone' => 'shield_lock',
            'tom'   => 'danger',
            'nota'  => 'A conta perdeu o papel administrativo (ou foi banida) com a sessão aberta e foi derrubada na hora.',
        ],
        'escrita_recusada' => [
            'label' => 'Escrita recusada',
            'icone' => 'lock',
            'tom'   => 'danger',
            'nota'  => 'Alguém tentou gravar ou apagar pela rota da tela de logs. A trilha é somente leitura; a tentativa virou registro.',
        ],
    ],

    /* Papel de quem assina a linha. O papel é do MOMENTO DA LEITURA (vem de users.role
       agora), e não do instante do fato: a tela diz isso no title para ninguém ler o
       crachá de hoje como se fosse o de ontem. */
    'papeis' => [
        'owner'  => 'Dono do jogo',
        'admin'  => 'Administrador',
        'player' => 'Jogador',
    ],

    'atores' => [
        'anonimo'  => 'Sem conta identificada',
        'removido' => 'Conta removida',
        'nota_papel' => 'Papel atual da conta (users.role de agora, não o do momento do fato).',
        'nota_anonimo' => 'A tentativa não bateu em nenhuma conta existente — não há a quem atribuir.',
        'nota_removido' => 'A conta #%s não existe mais em users. A prova do que ela fez sobrevive: a trilha não tem chave estrangeira justamente para isto.',
        'nota_jogador' => 'Conta SEM acesso ao painel. Aparece na trilha porque bateu na porta dele.',
    ],

    /* ---------------------------------------------------------------------- detalhe (meta) */
    'detalhe' => [
        'vazio'       => 'Escolha uma linha da trilha para ler o registro inteiro, com o meta em JSON.',
        'sem_meta'    => 'Este registro não guardou meta — a ação não tinha contexto técnico a anexar.',
        'json_bruto'  => 'JSON como está no banco',
        'campo_id'    => 'Registro',
        'campo_quando'=> 'Quando',
        'campo_acao'  => 'Ação',
        'campo_quem'  => 'Quem fez',
        'campo_alvo'  => 'Alvo',
        'campo_ip'    => 'IP de origem',
        'campo_resumo'=> 'Resumo',
        'campo_meta'  => 'Meta',
        'fechar'      => 'Fechar detalhe',
        'nao_achado'  => 'O registro pedido não existe (ou já não está nesta trilha).',
    ],

    /* ------------------------------------------------------------------------- resumo lateral */
    'resumo' => [
        'total'     => 'Registros no recorte',
        'operadores'=> 'Contas distintas',
        'periodo'   => 'Período coberto',
        'acoes'     => 'Ações no recorte',
        'sem_periodo' => 'sem registro',
    ],

    /* ------------------------------------------------------------------------ nada a mostrar */
    'vazios' => [
        // Os dois casos NÃO são a mesma frase: um diz que o painel nunca escreveu nada,
        // o outro diz que o recorte é que está vazio — e este tem conserto (limpar filtro).
        'trilha' => 'A trilha está vazia: nenhuma ação do painel foi registrada em admin_audit_log até agora.',
        'filtro' => 'Nenhum registro bate com este recorte. Amplie o período ou limpe os filtros.',
        'falha'  => 'Não foi possível ler admin_audit_log agora. O painel continua de pé, mas esta tela não pode afirmar nada sobre o que aconteceu — o erro está no log do PHP.',
    ],

    /* --------------------------------------------------------------------------- microcopy */
    'micro' => [
        'somente_leitura' => 'Somente leitura: a trilha de auditoria não é editável pelo painel.',
        'origem'          => 'Leitura direta de admin_audit_log, com users apenas para dar nome a quem fez.',
        'ver_detalhe'     => 'Ver este registro',
        'de_ate'          => '%s até %s',
        'contando'        => '%s de %s registro(s)',
        'ordenar'         => 'Ordenar por %s',
        'pagina'          => 'Página %s de %s',
    ],
];
