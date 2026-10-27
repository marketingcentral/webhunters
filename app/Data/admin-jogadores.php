<?php declare(strict_types=1);

/**
 * Cromo da tela ADMIN · JOGADORES (/admin/jogadores).
 *
 * Só TEXTO. Nenhum número e nenhuma regra: os dados vêm de App\Repo\AdminPlayersRepo e as
 * regras de quem-pode-o-quê vivem no controller. O motivo de existir é o mesmo de
 * app/Data/admin_nav.php — o vocabulário do jogo muda (hoje "Hunter", amanhã outra coisa) e
 * trocar uma palavra não pode obrigar ninguém a abrir o HTML da tela.
 *
 * As chaves de `papeis`, `situacoes`, `ban_tipos`, `containers` e `missao_status` são os
 * valores CRUS gravados no banco. É de propósito: a view traduz pelo mapa e, quando o banco
 * traz um valor que ninguém previu (uma migração antiga, um script de importação), ela mostra
 * o valor cru em vez de inventar um rótulo bonito para um estado desconhecido.
 */

return [

    'titulos' => [
        'lista'       => 'Contas do jogo',
        'progresso'   => 'Progresso',
        'inventario'  => 'Inventário',
        'missoes'     => 'Missões',
        'conquistas'  => 'Conquistas',
        'faccoes'     => 'Facções',
        'partidas'    => 'Partidas recentes',
        'conta'       => 'Conta e acesso',
        'punicao'     => 'Punições',
        'perigo'      => 'Excluir conta',
    ],

    /* users.role — o rótulo curto vai no selo da tabela; a frase explica o que o papel ABRE. */
    'papeis' => [
        'player' => ['label' => 'Jogador',       'tom' => 'muted',  'ajuda' => 'Acesso apenas ao jogo.'],
        'admin'  => ['label' => 'Administrador', 'tom' => 'info',   'ajuda' => 'Abre o painel e gerencia jogadores.'],
        'owner'  => ['label' => 'Dono',          'tom' => 'gold',   'ajuda' => 'Controle total, incluindo a equipe.'],
    ],

    /* users.status. Cor E texto — cor sozinha não é estado para quem não distingue verde de vermelho. */
    'situacoes' => [
        'active'    => ['label' => 'Ativa',     'tom' => 'ok',     'icone' => 'check_circle'],
        'banned'    => ['label' => 'Banida',    'tom' => 'danger', 'icone' => 'gavel'],
        'suspended' => ['label' => 'Suspensa',  'tom' => 'warn',   'icone' => 'pause_circle'],
    ],

    /* Abas de situação no topo da lista. `chave` vazia = sem filtro. */
    'abas' => [
        ['chave' => '',          'label' => 'Todas'],
        ['chave' => 'active',    'label' => 'Ativas'],
        ['chave' => 'banned',    'label' => 'Banidas'],
        ['chave' => 'suspended', 'label' => 'Suspensas'],
    ],

    'ordens' => [
        'recentes' => 'Mais recentes',
        'antigos'  => 'Mais antigas',
        'acesso'   => 'Último acesso',
        'nome'     => 'Nome (A-Z)',
        'nivel'    => 'Maior nível',
        'xp'       => 'Maior XP',
    ],

    'ban_tipos' => [
        'temp'  => 'Temporário',
        'perma' => 'Permanente',
    ],

    /* player_items.container — de onde a peça está pendurada na ficha do jogador. */
    'containers' => [
        'grid'    => 'Mochila',
        'stash'   => 'Baú',
        'equip'   => 'Equipado',
        'pockets' => 'Bolsos',
        'attach'  => 'Acoplado',
    ],

    /* player_missions.status. 'disponivel' existe em dado antigo; some junto quando o banco limpar. */
    'missao_status' => [
        'active'     => ['label' => 'Em curso',   'tom' => 'info'],
        'done'       => ['label' => 'Concluída',  'tom' => 'ok'],
        'available'  => ['label' => 'Disponível', 'tom' => 'muted'],
        'disponivel' => ['label' => 'Disponível', 'tom' => 'muted'],
        'locked'     => ['label' => 'Travada',    'tom' => 'muted'],
    ],

    'partida_result' => [
        'extraido' => ['label' => 'Extraiu',  'tom' => 'ok'],
        'abatido'  => ['label' => 'Abatido',  'tom' => 'danger'],
    ],

    'faccoes' => [
        'aegis'    => 'Aegis',
        'frontier' => 'Frontier',
        'neutro'   => 'Neutro',
    ],

    /* -------------------------------------------------------------------- estados vazios.
       Um vazio precisa dizer POR QUE está vazio; "nenhum registro" é um erro de interface
       fingindo ser um dado. */
    'vazios' => [
        'sem_conta'      => ['titulo' => 'Nenhuma conta cadastrada',   'txt' => 'Assim que alguém criar uma conta no jogo, ela aparece aqui. Nada foi filtrado.'],
        'sem_resultado'  => ['titulo' => 'Nada bate com este filtro',  'txt' => 'A busca e os filtros acima não encontraram nenhuma conta. Limpe-os para ver o cadastro inteiro.'],
        'sem_inventario' => 'Esta conta não tem nenhum item — nunca lootou, comprou nem recebeu nada.',
        'sem_missao'     => 'Nenhuma missão iniciada. Missões travadas não entram nesta lista.',
        'sem_conquista'  => 'Nenhuma conquista com progresso registrado.',
        'sem_faccao'     => 'Esta conta ainda não tem reputação com nenhuma facção.',
        'sem_partida'    => 'Nenhuma partida registrada para esta conta.',
        'sem_punicao'    => 'Esta conta nunca foi punida.',
    ],

    /* ------------------------------------------------------------------------- microcopy */
    'micro' => [
        'busca'          => 'Buscar por usuário, e-mail ou nome no jogo',
        'sem_email'      => 'sem e-mail',
        'nunca_entrou'   => 'nunca entrou',
        'conta_propria'  => 'Esta é a sua conta: o painel não deixa ninguém mudar o próprio papel, se suspender ou se banir.',
        'sem_autoridade' => 'Você não tem autoridade sobre esta conta — só é possível agir sobre contas de papel inferior ao seu.',
        'ban_ativo'      => 'A situação está travada em "Banida" enquanto a punição valer. Revogue o banimento para liberar a conta.',
        'ban_ajuda'      => 'O jogador é bloqueado na hora e a punição fica registrada com o seu nome.',
        'excluir_ajuda'  => 'Apagar é definitivo e não passa pela lixeira. Para tirar alguém do jogo sem perder o histórico, use o banimento.',
        'excluir_confir' => 'Digite o nome de usuário para confirmar',
        'excluir_travado'=> 'Esta conta deixou rastro no mundo do jogo e não pode ser apagada — o histórico é prova do que aconteceu.',
        'senha'          => 'A senha não é exibida nem editável por aqui. Nem o dono do jogo lê o hash de senha de um jogador.',
    ],
];
