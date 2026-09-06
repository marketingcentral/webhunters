<?php declare(strict_types=1);

/**
 * Tela CRIAR CONTA — textos e números exatos.
 *
 * O AuthController monta `$data['auth']` a partir de `app/Data/auth.php` (compartilhado pelas três
 * telas de autenticação) e sobrepõe este arquivo com `array_replace_recursive` — mesmo padrão de
 * `app/Data/login.php`. Mantemos aqui a forma de `auth.php` (chaves `brand`, `register`, `tips`).
 *
 * A referência visual é `00-login-tela-inicial-do-jogador.png`: mesmo cartão, mesma coluna esquerda
 * (logo + DICAS DE SOBREVIVÊNCIA); só a coluna direita muda para o cadastro.
 */
return [
    'brand' => [
        'title'   => 'EXTRACT',
        'sub'     => 'HUNTERS',
        'tagline' => 'SOBREVIVA. EXTRAIA. EVOLUA.',
    ],
    'register' => [
        'title'         => 'CRIAR CONTA',
        'subtitle'      => 'Junte-se à Resistência e comece sua caçada',

        'user_label'    => 'NOME DE USUÁRIO',
        'user_ph'       => 'Escolha seu nome de caçador',
        /* "e _" sozinho no fim da linha é lido como reticências no corpo .78rem: nomeia o caractere */
        'user_hint'     => 'De 3 a 24 caracteres: letras, números e sublinhado (_)',

        'email_label'   => 'E-MAIL',
        'email_ph'      => 'Digite seu melhor e-mail',

        'pass_label'    => 'SENHA',
        'pass_ph'       => 'Mín. 8 caracteres',

        'confirm_label' => 'CONFIRMAR SENHA',
        'confirm_ph'    => 'Repita a senha',

        'show_pass'     => 'Mostrar senha',
        'hide_pass'     => 'Ocultar senha',

        /* medidor de força da senha (assets/js/pages/criar-conta.js) */
        'strength'      => [
            'label'  => 'FORÇA DA SENHA',
            'levels' => ['—', 'FRACA', 'RAZOÁVEL', 'BOA', 'FORTE'],
        ],

        'terms'         => 'Li e aceito os Termos de Uso e a Política de Privacidade.',
        'terms_hint'    => 'Ative para continuar',

        'submit'        => 'CRIAR CONTA',
        'has_account'   => 'Já tem uma conta?',
        'login'         => 'ENTRAR',
        'error_title'   => 'Revise os campos destacados para continuar.',
    ],
    'tips' => [
        'title' => 'DICAS DE SOBREVIVÊNCIA',
        'items' => [
            'Escolha um nome de caçador curto: ele aparece no placar de extração.',
            'Trabalhe em equipe, gerencie seus recursos e esteja sempre preparado.',
            'Extraia antes do fim do ciclo: o que não sai com você fica no campo.',
            'Marque os pontos de extração no mapa antes de entrar na zona de risco.',
        ],
    ],
];
