<?php declare(strict_types=1);

/**
 * Vocabulário da tela ADMIN · MISSÕES (/admin/missoes).
 *
 * Só texto e listas de valor. Nada aqui consulta o banco e nada aqui é regra de jogo:
 * o que É regra de jogo mora em App\Core\GameRules e é lido de lá pelo App\Repo\MissionRepo
 * (origens, facções, faixas de reputação, teto do esquadrão).
 *
 * POR QUE `tipos`, `dificuldades`, `atividade_humana` e `recompensas` estão AQUI e não em
 * GameRules: hoje essas quatro listas não existem em GameRules. Elas são o vocabulário que
 * o lado do jogo já usa — App\Controllers\MissionsController (TYPE_LABEL, DIFFICULTY,
 * REWARD_ICON) e o comentário da coluna em db/schema.sql. Copiá-las para cá é assumir a
 * dívida por escrito, num lugar só; inventar uma quinta lista seria pior. Quando GameRules
 * ganhar as constantes, MissionRepo passa a lê-las e este bloco morre.
 *
 * O formulário NUNCA descarta um valor que já está no banco fora destas listas: o repositório
 * une o que está aqui com o DISTINCT da tabela (ver MissionRepo::vocabulario). Um select que
 * some com o dado gravado é uma corrupção silenciosa, não uma validação.
 */

return [

    /* ------------------------------------------------------------------ cabeçalho da tela */
    'titulo'    => 'Missões',
    'subtitulo' => 'Contratos, requisitos e recompensas',

    /* ------------------------------------------------------------------- listas de valores */

    /**
     * Tipo do contrato. Espelha App\Controllers\MissionsController::TYPE_LABEL — os quatro
     * valores que a tela do jogador sabe desenhar. `emergencia` é evento de mundo (PRD §24).
     */
    'tipos' => [
        'principal'  => ['label' => 'PRINCIPAL',  'tom' => 'ok'],
        'contrato'   => ['label' => 'CONTRATO',   'tom' => 'info'],
        'diaria'     => ['label' => 'DIÁRIA',     'tom' => 'warn'],
        'emergencia' => ['label' => 'EMERGÊNCIA', 'tom' => 'danger'],
    ],

    /**
     * Dificuldade. Espelha ::DIFFICULTY do lado do jogo, com o `nivel` que vira os pips na
     * HUD — o painel mostra o MESMO número de pips, senão o operador edita às cegas.
     */
    'dificuldades' => [
        'FÁCIL'   => ['label' => 'FÁCIL',   'nivel' => 1, 'tom' => 'ok'],
        'MÉDIA'   => ['label' => 'MÉDIA',   'nivel' => 2, 'tom' => 'info'],
        'DIFÍCIL' => ['label' => 'DIFÍCIL', 'nivel' => 3, 'tom' => 'warn'],
        'EXTREMO' => ['label' => 'EXTREMO', 'nivel' => 4, 'tom' => 'danger'],
    ],

    /**
     * Atividade humana esperada na região da missão. A escala é a do comentário da coluna
     * `missions.human_activity` em db/schema.sql — e NÃO a GameRules::ACTIVITY, que mede
     * outra coisa (pressão da Veyra, em BAIXA/MODERADA/ELEVADA/CRÍTICA).
     */
    'atividade_humana' => [
        'IMPROVÁVEL' => ['label' => 'IMPROVÁVEL', 'tom' => 'muted'],
        'PROVÁVEL'   => ['label' => 'PROVÁVEL',   'tom' => 'warn'],
        'DETECTADA'  => ['label' => 'DETECTADA',  'tom' => 'danger'],
    ],

    /**
     * `mission_rewards.kind`. Espelha ::REWARD_ICON do lado do jogo.
     *   `item`   -> exige item_id e a quantia é sempre 1 (é UM item, não 300 itens);
     *   `unico`  -> recompensa de existência (acesso, intel): quantia sempre 1;
     *   os demais são quantidades e precisam de um número maior que zero.
     */
    'recompensas' => [
        'xp'           => ['label' => 'XP',                'forma' => 'quantia', 'tom' => 'ok'],
        'credits'      => ['label' => 'Créditos',          'forma' => 'quantia', 'tom' => 'info'],
        'cash'         => ['label' => 'Dinheiro',          'forma' => 'quantia', 'tom' => 'gold'],
        'points'       => ['label' => 'Pontos',            'forma' => 'quantia', 'tom' => 'purple'],
        'rep_aegis'    => ['label' => 'Reputação Aegis',   'forma' => 'quantia', 'tom' => 'info'],
        'rep_frontier' => ['label' => 'Reputação Frontier','forma' => 'quantia', 'tom' => 'warn'],
        'item'         => ['label' => 'Item do catálogo',  'forma' => 'item',    'tom' => 'gold'],
        'access'       => ['label' => 'Acesso',            'forma' => 'unico',   'tom' => 'muted'],
        'intel'        => ['label' => 'Intel',             'forma' => 'unico',   'tom' => 'muted'],
    ],

    /* ------------------------------------------------------------ rótulos de coluna e filtro */

    'colunas' => [
        'ordem'      => '#',
        'missao'     => 'Missão',
        'tipo'       => 'Tipo',
        'origem'     => 'Origem',
        'regiao'     => 'Região',
        'dificuldade'=> 'Dificuldade',
        'nivel'      => 'Nível',
        'esquadrao'  => 'Esquadrão',
        'recompensas'=> 'Recompensas',
        'ativas'     => 'Em jogo',
        'estado'     => 'Estado',
        'acoes'      => 'Ações',
    ],

    'estados' => [
        ''               => 'Todos os estados',
        'publicada'      => 'Publicadas',
        'bloqueada'      => 'Bloqueadas',
        'em_uso'         => 'Com jogadores em campo',
        'sem_recompensa' => 'Sem recompensa',
    ],

    'ordenacoes' => [
        'ordem'  => 'Ordem de exibição',
        'nome'   => 'Nome (A–Z)',
        'nivel'  => 'Nível exigido',
        'ativas' => 'Jogadores em campo',
        'recente'=> 'Cadastradas por último',
    ],

    /* ----------------------------------------------------------------------- microcópia */

    'micro' => [
        'busca'          => 'Buscar por nome, slug, contratante, região ou objetivo',
        'resultado'      => '%s de %s missões',
        'sem_filtro'     => '%s missões cadastradas',
        'limpar'         => 'Limpar filtros',
        'nova'           => 'Nova missão',
        'em_campo'       => '%s jogador(es) com esta missão ativa agora',
        'sem_jogadores'  => 'Nenhum jogador com esta missão em campo',
        'sem_recompensa' => 'Sem recompensa cadastrada',
        'esquadrao'      => 'Unidade recomendada: %s a %s operadores',
        'pips'           => 'Dificuldade %s de 4',
        'obrigatorio'    => 'Campo obrigatório.',
    ],

    'vazios' => [
        'sem_missao'  => 'Nenhuma missão cadastrada ainda. O quadro de contratos do jogo lê esta mesma tabela: enquanto ela estiver vazia, a tela Missões do jogador aparece sem nenhum contrato.',
        'sem_filtro'  => 'Nenhuma missão bate com esta combinação de busca e filtros. As %s missões cadastradas continuam lá.',
        'sem_item'    => 'O catálogo de itens está vazio — sem ele não há como oferecer um item como recompensa.',
    ],

    /* ------------------------------------------------------------------------ formulário */

    'form' => [
        'nova'        => 'Nova missão',
        'editar'      => 'Editar missão',
        'identidade'  => 'Identidade',
        'operacao'    => 'Operação',
        'requisitos'  => 'Requisitos de acesso',
        'recompensas' => 'Recompensas',
        'salvar'      => 'Salvar missão',
        'cancelar'    => 'Cancelar',
    ],

    'campos' => [
        'slug'         => ['label' => 'Slug',            'dica' => 'Identificador fixo usado pela HUD e pelo progresso do jogador. Minúsculas, números e hífen. Mudar depois de publicada desliga o progresso de quem já a aceitou.'],
        'name'         => ['label' => 'Nome',            'dica' => 'Como aparece no quadro de contratos. Em caixa alta, como o resto do jogo escreve.'],
        'giver'        => ['label' => 'Contratante',     'dica' => 'Quem entrega o contrato. Ex.: BASE VIGIA · HUNTER COMMAND.'],
        'region'       => ['label' => 'Região',          'dica' => 'Onde a operação acontece.'],
        'type'         => ['label' => 'Tipo',            'dica' => ''],
        'origin'       => ['label' => 'Origem',          'dica' => 'De quem vem a missão. Define a cor e o selo no quadro do jogador.'],
        'difficulty'   => ['label' => 'Dificuldade',     'dica' => ''],
        'duration_min' => ['label' => 'Duração (min)',   'dica' => 'Estimativa exibida no cartão.'],
        'level_req'    => ['label' => 'Nível mínimo',    'dica' => 'Abaixo disto o cartão sai bloqueado com o nível escrito.'],
        'objective'    => ['label' => 'Objetivo',        'dica' => 'O texto que o jogador lê. Quebrado em linhas por frase na HUD — escreva em frases curtas.'],
        'target'       => ['label' => 'Meta',            'dica' => 'Quantidade a atingir (ex.: eliminar 8). Zero quando a missão não conta nada.'],
        'image'        => ['label' => 'Imagem',          'dica' => 'Caminho dentro de assets/img. Ex.: missions/mission-sinal-perdido.jpg'],
        'locked'       => ['label' => 'Bloqueada',       'dica' => 'Fora do quadro do jogador, independentemente de nível e reputação.'],
        'sort_order'   => ['label' => 'Ordem',           'dica' => 'Menor primeiro no quadro de contratos.'],
        'act'          => ['label' => 'Ato',             'dica' => 'Ato da campanha (1 a 4). Só faz sentido em missão principal.'],
        'requires_faction' => ['label' => 'Exige facção',    'dica' => 'Contrato de facção necessário para aceitar. "Qualquer" aceita Aegis ou Frontier.'],
        'requires_tier'    => ['label' => 'Faixa mínima',    'dica' => 'Faixa de reputação com a facção exigida.'],
        'requires_rep'     => ['label' => 'Reputação mínima','dica' => 'Valor bruto de reputação, quando a faixa não basta.'],
        'squad_min'    => ['label' => 'Esquadrão mín.', 'dica' => ''],
        'squad_max'    => ['label' => 'Esquadrão máx.', 'dica' => 'Teto absoluto do jogo: esquadrão Hunter tem no máximo %s.'],
        'human_activity'=> ['label' => 'Atividade humana', 'dica' => 'Chance de encontrar outros operadores na região.'],
    ],

    /* -------------------------------------------------------------------------- exclusão */

    'excluir' => [
        'titulo'    => 'Excluir missão',
        'pergunta'  => 'Excluir a missão %s? Esta ação não tem volta.',
        'consequencia' => 'As recompensas cadastradas (%s) são apagadas junto. A missão sai do quadro de contratos do jogo na hora.',
        'bloqueio'  => 'Esta missão não pode ser excluída: %s jogador(es) a têm em campo agora. Apagá-la apagaria o progresso deles junto (player_missions cai por CASCADE).',
        'saida'     => 'Para tirá-la de circulação sem perder nada, marque-a como BLOQUEADA — ela some do quadro e o progresso de quem já a aceitou fica preservado.',
        'confirmar' => 'Sim, excluir',
        'voltar'    => 'Voltar à lista',
    ],
];
