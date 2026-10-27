<?php declare(strict_types=1);

/**
 * Microcopy da tela ADMIN · BANIMENTOS (/admin/banimentos).
 *
 * Só TEXTO. Nenhum número, nenhuma regra e nenhuma consulta moram aqui — o que a tela mostra
 * vem de App\Repo\BanRepo, e o que ela permite vem de App\Controllers\Admin\BansController.
 *
 * Este arquivo existe porque punição é o assunto mais delicado do painel: cada frase precisa
 * ser revisável sem abrir PHP de lógica, e a mesma palavra ("revogar", "encerrado", "prazo")
 * tem de aparecer igual na tabela, no formulário e na confirmação. Texto espalhado em três
 * arquivos é como uma tela começa a dizer duas coisas.
 */

return [
    'titulo'    => 'Banimentos',
    'subtitulo' => 'Punições aplicadas, prazos e revogações',

    /* Cartões do topo. 'chave' casa com BanRepo::contagens(). */
    'kpis' => [
        ['chave' => 'ativo',    'rotulo' => 'Punições em vigor', 'icone' => 'gavel',        'tom' => 'red'],
        ['chave' => 'contas',   'rotulo' => 'Contas fora do ar', 'icone' => 'person_off',   'tom' => 'amber'],
        ['chave' => 'expirado', 'rotulo' => 'Prazos vencidos',   'icone' => 'hourglass_bottom', 'tom' => 'cyan'],
        ['chave' => 'revogado', 'rotulo' => 'Revogadas',         'icone' => 'undo',         'tom' => 'lime'],
    ],

    /* Rótulo curto de cada estado derivado + a frase que explica o que ele significa. */
    'estados' => [
        'ativo'    => ['rotulo' => 'Em vigor',  'tom' => 'danger', 'icone' => 'block',
                       'ajuda'  => 'A conta está bloqueada agora.'],
        'expirado' => ['rotulo' => 'Encerrado', 'tom' => 'muted',  'icone' => 'schedule',
                       'ajuda'  => 'O prazo venceu e a conta voltou sozinha.'],
        'revogado' => ['rotulo' => 'Revogado',  'tom' => 'ok',     'icone' => 'undo',
                       'ajuda'  => 'Um operador desfez a punição antes do prazo.'],
    ],

    /* Ordenações oferecidas na barra. As chaves casam com BanRepo::ORDENS. */
    'ordens' => [
        'recente'  => 'Mais recentes',
        'antigo'   => 'Mais antigos',
        'validade' => 'Prazo mais próximo',
        'conta'    => 'Conta (A-Z)',
    ],

    'colunas' => [
        'conta'    => 'Conta punida',
        'tipo'     => 'Tipo',
        'motivo'   => 'Motivo',
        'aplicado' => 'Aplicado por',
        'prazo'    => 'Prazo',
        'estado'   => 'Estado',
    ],

    /* Estados vazios. Cada um responde "por que não tem nada aqui", nunca só "sem dados". */
    'vazios' => [
        'limpo' => [
            'icone'  => 'verified_user',
            'titulo' => 'Nenhuma punição registrada',
            'texto'  => 'Nenhuma conta foi banida ou suspensa até agora. Quando você aplicar a '
                      . 'primeira punição, ela aparece aqui com o motivo, o prazo e a assinatura de quem aplicou.',
        ],
        'filtro' => [
            'icone'  => 'filter_alt_off',
            'titulo' => 'Nada com esses filtros',
            'texto'  => 'Existem punições registradas, mas nenhuma corresponde à busca e aos filtros '
                      . 'selecionados. Limpe os filtros para ver o histórico completo.',
        ],
    ],

    'form' => [
        'titulo'   => 'Aplicar punição',
        'ajuda'    => 'A conta é bloqueada no mesmo instante em que você grava — o jogador perde o acesso na próxima requisição.',
        'alvo'     => ['rotulo' => 'Conta', 'ajuda' => 'Nome de usuário, e-mail ou ID numérico.'],
        'tipo'     => ['rotulo' => 'Tipo de punição'],
        'prazo'    => ['rotulo' => 'Termina em', 'ajuda' => 'Data e hora em que a conta volta sozinha. Punição definitiva não tem prazo.'],
        'motivo'   => ['rotulo' => 'Motivo', 'ajuda' => 'Fica no histórico para sempre. Escreva o que outro operador precisaria saber daqui a seis meses.'],
        'enviar'   => 'Aplicar punição',
        'cancelar' => 'Cancelar',
    ],

    'revogar' => [
        'titulo'   => 'Revogar punição',
        'ajuda'    => 'A conta volta ao ar imediatamente. O registro NÃO é apagado: ele passa a constar como revogado, com o seu nome e o motivo abaixo.',
        'motivo'   => ['rotulo' => 'Motivo da revogação', 'ajuda' => 'Recurso aceito, engano de alvo, acordo — diga qual.'],
        'enviar'   => 'Revogar agora',
        'cancelar' => 'Manter punição',
    ],

    'remover' => [
        'titulo'   => 'Apagar do histórico',
        'ajuda'    => 'Esta linha some da tabela para sempre. O registro do que você apagou fica na trilha de auditoria — mas o histórico da conta perde a punição.',
        'enviar'   => 'Apagar definitivamente',
        'cancelar' => 'Manter registro',
        'bloqueio' => [
            'ativa' => 'Esta punição está em vigor. Revogue antes de apagar — senão a conta continuaria bloqueada sem nenhuma linha explicando por quê.',
            'papel' => 'Apagar histórico de punição é exclusivo do dono do jogo.',
        ],
    ],

    /* Frases de erro/sucesso das ações. Ficam aqui para serem revisadas juntas. */
    'avisos' => [
        'aplicado'     => 'Punição aplicada a %s. A conta está bloqueada.',
        'revogado'     => 'Punição de %s revogada. A conta voltou ao ar.',
        'removido'     => 'Registro #%d apagado do histórico.',
        'reconciliado' => '%d conta(s) voltaram ao ar: o prazo da punição venceu.',
    ],
];
