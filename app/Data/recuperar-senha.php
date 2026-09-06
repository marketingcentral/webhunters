<?php declare(strict_types=1);

/**
 * Tela RECUPERAR SENHA — mesmo cartão do login
 * (referência `O-QUE-VAMOS-CRIAR/Telas do jogador/00-login-tela-inicial-do-jogador.png`).
 *
 * O AuthController monta `$data['auth']` com `array_replace_recursive(data_mock('auth'), data_mock('recuperar-senha'))`,
 * exatamente como faz no login. Mantemos aqui a forma de `app/Data/auth.php`
 * (chaves `brand`, `recover`, `tips`) para que a sobreposição continue trivial.
 */
return [
    'brand' => [
        'title'   => 'EXTRACT',
        'sub'     => 'HUNTERS',
        'tagline' => 'SOBREVIVA. EXTRAIA. EVOLUA.',
    ],
    'recover' => [
        'title'        => 'RECUPERAR SENHA',
        'subtitle'     => 'Informe o e-mail da conta para receber o link',
        'email_label'  => 'E-MAIL DA CONTA',
        'email_ph'     => 'Digite o e-mail cadastrado',
        'submit'       => 'ENVIAR LINK',

        // faixa "COMO FUNCIONA" (ocupa o lugar do bloco OU + Steam do login)
        'steps_title'  => 'COMO FUNCIONA',
        'steps'        => [
            ['title' => 'INFORME O E-MAIL', 'text' => 'Use o endereço cadastrado na sua conta.'],
            ['title' => 'ABRA O LINK',      'text' => 'Chega em poucos minutos e vale por 30 minutos.'],
            ['title' => 'DEFINA A SENHA',   'text' => 'Mínimo de 8 caracteres e volte à caçada.'],
        ],

        'note'         => 'Não recebeu? Confira a caixa de spam ou fale com o',
        'note_link'    => 'SUPORTE',
        'remember'     => 'Lembrou sua senha?',
        'back'         => 'ENTRAR',

        // estado enviado (POST com e-mail válido)
        'sent_title'   => 'LINK ENVIADO',
        'sent'         => 'Se estiver cadastrado, o link chega em poucos minutos.',
        'sent_to'      => 'DESTINO',
        'resend_wait'  => 'REENVIAR EM',
        'resend'       => 'REENVIAR LINK',
        'resend_secs'  => 60,

        'show_error'   => 'Informe um e-mail válido.',
    ],
    'tips' => [
        'title' => 'DICAS DE SOBREVIVÊNCIA',
        'items' => [
            'Sua senha protege o estoque: nunca a compartilhe com outro operador.',
            'Ative a verificação em duas etapas assim que recuperar o acesso.',
            'Trabalhe em equipe, gerencie seus recursos e esteja sempre preparado.',
            'Guarde o e-mail da conta: é ele que devolve o acesso em uma emergência.',
        ],
    ],
];
