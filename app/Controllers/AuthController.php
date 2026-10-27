<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Security;

final class AuthController extends Controller
{
    /** @param array<string, string> $params */
    public function login(Request $request, array $params = []): Response
    {
        // app/Data/login.php (dono: agente da tela) refina os textos de app/Data/auth.php.
        $auth  = array_replace_recursive(data_mock('auth'), data_mock('login'));
        $error = null;
        $user  = '';

        if ($request->isPost()) {
            $this->csrf($request);
            $user = trim(mb_substr((string)$request->input('user', ''), 0, 60));
            $pass = (string)$request->input('password', '');

            if ($user === '' || $pass === '') {
                $error = 'Informe seu e-mail ou nome de usuário e a senha.';
            } else {
                try {
                    $account = \App\Repo\UserRepo::findByLogin($user);
                    if ($account !== null && \App\Repo\UserRepo::verify($account, $pass)) {
                        // Senha certa NÃO é o fim da checagem: `users.status` e a tabela `bans`
                        // decidem se esta conta ainda pode entrar. O estado é consultado AGORA
                        // (e não copiado de um cache), e um ban temporário vencido é desfeito
                        // dentro de AccountGuard::estado() — sem isso, "banir por 3 dias" seria
                        // banir para sempre, porque não existe cron neste projeto.
                        $estado = \App\Core\AccountGuard::estado((int)$account['id']);
                        if ($estado['bloqueado']) {
                            // Detalhar aqui não vaza quais contas existem: só chega neste ponto
                            // quem já provou a senha. Quem erra a senha continua vendo o
                            // genérico "Usuário ou senha inválidos" logo abaixo.
                            $error = \App\Core\AccountGuard::mensagem($estado);
                            \App\Repo\AdminRepo::registrar(
                                null,
                                'login_bloqueado',
                                'usuario',
                                (string)$account['id'],
                                'Login recusado: conta em ' . (string)$estado['status'] . '.',
                                ['kind' => $estado['kind'], 'expires_at' => $estado['expires_at']],
                                $request->ip()
                            );
                        } else {
                            $this->signIn((int)$account['id']);
                            \App\Core\Database::run('UPDATE users SET last_login = NOW() WHERE id = :id', ['id' => (int)$account['id']]);
                            flash('ok', 'Bem-vindo de volta, ' . (string)$account['username'] . '.');
                            return $this->redirect('inicio');
                        }
                    } else {
                        if ($account === null) {
                            // gasta o mesmo tempo de um hash real: sem isso o tempo de resposta
                            // denuncia quais contas existem (enumeração de usuários)
                            password_verify($pass, '$2y$12$usuarioInexistenteXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX');
                        }
                        $error = 'Usuário ou senha inválidos.';
                    }
                } catch (\Throwable $e) {
                    // banco fora do ar: o jogador vê um aviso legível, não uma página 500
                    error_log('[extracthunters] login: ' . $e->getMessage());
                    $error = 'Não foi possível verificar suas credenciais agora. Tente novamente em instantes.';
                }
            }
        }

        $page = $this->page('login', 'Entrar na conta', ['nav' => '', 'back' => null]);
        return $this->render('login', $page, [
            'auth'  => $auth,
            'error' => $error,
            'user'  => $user,
        ], 'auth', $error ? 422 : 200);
    }

    /** @param array<string, string> $params */
    public function register(Request $request, array $params = []): Response
    {
        // app/Data/criar-conta.php (dono: agente da tela) refina os textos de app/Data/auth.php.
        $auth   = array_replace_recursive(data_mock('auth'), data_mock('criar-conta'));
        $errors = [];
        $values = ['user' => '', 'email' => '', 'terms' => false];

        // Chave `registration_open` de /admin/configuracoes. Estava no banco desde o patch v6
        // e ninguém a lia: fechar o cadastro no painel não fechava cadastro nenhum. A leitura
        // é feita aqui, na única porta que cria conta, e o padrão é ABERTO — falha de banco ou
        // chave apagada não pode fechar o jogo para quem quer entrar.
        $aberto = (bool)\App\Repo\AdminSettingsRepo::valor('registration_open', true);
        if (!$aberto) {
            $errors['form'] = 'O cadastro de novas contas está temporariamente fechado.';
        }

        if ($request->isPost() && $aberto) {
            $this->csrf($request);
            // Corte largo só para limitar o que volta para a tela: cortar em 24/120 aqui criaria
            // a conta com um nome/e-mail DIFERENTE do que a pessoa digitou, em silêncio.
            // Quem reprova o tamanho é a validação abaixo, com mensagem visível.
            $values['user']  = mb_substr((string)$request->input('user', ''), 0, 80);
            $values['email'] = mb_substr((string)$request->input('email', ''), 0, 200);
            $values['terms'] = $request->input('terms') !== null;
            $pass    = (string)$request->input('password', '');
            $confirm = (string)$request->input('password_confirm', '');

            if (!preg_match('/^[A-Za-z0-9_]{3,24}$/', $values['user'])) {
                $errors['user'] = 'Use de 3 a 24 caracteres: letras, números e sublinhado (_).';
            }
            if (filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = 'Informe um e-mail válido.';
            } elseif (mb_strlen($values['email']) > 120) {
                $errors['email'] = 'O e-mail pode ter no máximo 120 caracteres.';
            }
            if (mb_strlen($pass) < 8) {
                $errors['password'] = 'A senha precisa ter ao menos 8 caracteres.';
            } elseif (strlen($pass) > 72) {
                // bcrypt ignora o que passa de 72 bytes: sem este limite a senha seria
                // truncada em silêncio e o login aceitaria qualquer variação do excedente.
                $errors['password'] = 'A senha pode ter no máximo 72 caracteres.';
            } elseif ($pass !== $confirm) {
                $errors['password_confirm'] = 'As senhas não coincidem.';
            }
            if (!$values['terms']) {
                $errors['terms'] = 'Aceite os termos para continuar.';
            }

            try {
                if (!isset($errors['user']) && \App\Repo\UserRepo::usernameTaken($values['user'])) {
                    $errors['user'] = 'Esse nome de usuário já está em uso.';
                }
                if (!isset($errors['email']) && \App\Repo\UserRepo::emailTaken($values['email'])) {
                    $errors['email'] = 'Já existe uma conta com esse e-mail.';
                }

                if ($errors === []) {
                    $uid = \App\Repo\UserRepo::create($values['user'], $values['email'], $pass);
                    $this->signIn($uid);
                    flash('ok', 'Conta criada. Boa caçada, ' . $values['user'] . '.');
                    return $this->redirect('inicio');
                }
            } catch (\Throwable $e) {
                // 1062 = corrida no índice único (dois cadastros simultâneos com o mesmo dado):
                // vira erro de campo. Qualquer outra falha (banco fora do ar) vira aviso legível
                // no lugar de uma página 500.
                $code = $e instanceof \PDOException ? (int)($e->errorInfo[1] ?? 0) : 0;
                if ($code === 1062) {
                    $key = str_contains($e->getMessage(), 'uq_users_email') ? 'email' : 'user';
                    $errors[$key] = $key === 'email'
                        ? 'Já existe uma conta com esse e-mail.'
                        : 'Esse nome de usuário já está em uso.';
                } else {
                    error_log('[extracthunters] criar-conta: ' . $e->getMessage());
                    $errors['form'] = 'Não foi possível criar a conta agora. Tente novamente em instantes.';
                }
            }
        }

        // `pages/login.css` é reaproveitado pelo cadastro (mesmo cartão); `pages/criar-conta.css`
        // carrega antes e reescopa/ajusta o que muda na coluna direita.
        $page = $this->page('criar-conta', 'Criar conta', [
            'nav'  => '',
            'back' => ['label' => 'VOLTAR', 'href' => url('login')],
            'css'  => ['pages/login.css'],
        ]);
        return $this->render('criar-conta', $page, [
            'auth'   => $auth,
            'errors' => $errors,
            'values' => $values,
        ], 'auth', $errors ? 422 : 200);
    }

    /** @param array<string, string> $params */
    public function recover(Request $request, array $params = []): Response
    {
        // app/Data/recuperar-senha.php (dono: agente da tela) refina os textos de app/Data/auth.php.
        $auth  = array_replace_recursive(data_mock('auth'), data_mock('recuperar-senha'));
        $sent  = false;
        $error = null;
        $email = '';

        if ($request->isPost()) {
            $this->csrf($request);
            $email = mb_substr((string)$request->input('email', ''), 0, 120);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $error = 'Informe um e-mail válido.';
            } else {
                $sent = true;   // sem back-end: sempre responde de forma neutra
            }
        }

        $page = $this->page('recuperar-senha', 'Recuperar senha', ['nav' => '', 'back' => ['label' => 'VOLTAR', 'href' => url('login')]]);
        return $this->render('recuperar-senha', $page, [
            'auth'  => $auth,
            'sent'  => $sent,
            'error' => $error,
            'email' => $email,
        ], 'auth', $error ? 422 : 200);
    }

    /** @param array<string, string> $params */
    public function logout(Request $request, array $params = []): Response
    {
        $this->csrf($request);
        Security::destroySession();
        Security::startSession();
        flash('ok', 'Você saiu da conta.');
        return $this->redirect('login');
    }

    /** Grava o id do usuário autenticado na sessão (dados vêm do banco por user_id). */
    private function signIn(int $userId): void
    {
        Security::regenerate();
        $_SESSION['user_id'] = $userId;
        unset($_SESSION['player']);  // limpa qualquer cópia mock antiga
        unset($_SESSION['_csrf']);   // rotaciona o token CSRF junto com a sessão
        // A conta acabou de ser verificada: carimba para o AccountGuard não repetir a
        // consulta na primeira tela depois do login.
        \App\Core\AccountGuard::carimbar();
    }
}
