/**
 * Login, cadastro e saída — o App\Controllers\AuthController do PHP.
 * Pendente: /recuperar-senha (no PHP também não envia nada; só a tela).
 */
import { ctx } from '../core/context';
import * as DB from '../core/database';
import { carimbarConta, estadoConta, mensagemConta } from '../core/guards';
import { dataMock, flash, url } from '../core/helpers';
import * as Password from '../core/password';
import type { AppRequest } from '../core/request';
import type { AppResponse } from '../core/response';
import type { Dict } from '../core/view';
import * as Settings from '../repo/settings-repo';
import * as UserRepo from '../repo/user-repo';
import { criarConta, login } from '../views/pages/auth';
import { Controller } from './controller';

/** array_replace_recursive() para objetos simples (listas são substituídas inteiras). */
function mesclar(a: Dict, b: Dict): Dict {
  const out: Dict = { ...a };
  for (const [k, v] of Object.entries(b)) {
    out[k] = v && typeof v === 'object' && !Array.isArray(v) && out[k] && typeof out[k] === 'object' ? mesclar(out[k], v) : v;
  }
  return out;
}

/** filter_var(FILTER_VALIDATE_EMAIL), aproximado. */
function emailValido(e: string): boolean {
  return /^[^\s@"(),:;<>[\\\]]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$/.test(e);
}

const bytes = (s: string) => new TextEncoder().encode(s).length;

export class AuthController extends Controller {
  async login(req: AppRequest): Promise<AppResponse> {
    const auth = mesclar(dataMock('auth'), dataMock('login'));
    let error: string | null = null;
    let user = '';

    if (req.isPost()) {
      this.csrf(req);
      user = (req.input('user', '') ?? '').slice(0, 60).trim();
      const pass = req.input('password', '') ?? '';
      if (user === '' || pass === '') {
        error = 'Informe seu e-mail ou nome de usuário e a senha.';
      } else {
        try {
          const account = await UserRepo.findByLogin(user);
          if (account !== null && (await UserRepo.verify(account, pass))) {
            // Senha certa não encerra a checagem: status e banimentos decidem se ainda entra.
            const estado = await estadoConta(Number(account.id));
            if (estado.bloqueado) {
              error = mensagemConta(estado);
            } else {
              this.signIn(Number(account.id));
              await DB.run(`UPDATE users SET last_login = datetime('now') WHERE id = :id`, { id: Number(account.id) });
              flash('ok', 'Bem-vindo de volta, ' + String(account.username) + '.');
              return this.redirect('inicio');
            }
          } else {
            if (account === null) await Password.verifyDummy(pass, ctx().env.PASSWORD_ALGO);
            error = 'Usuário ou senha inválidos.';
          }
        } catch (e) {
          console.error('[extracthunters] login:', e);
          error = 'Não foi possível verificar suas credenciais agora. Tente novamente em instantes.';
        }
      }
    }

    const page = this.page('login', 'Entrar na conta', { nav: '', back: null });
    return this.render(login, page, { auth, error, user }, 'auth', error ? 422 : 200);
  }

  async register(req: AppRequest): Promise<AppResponse> {
    const auth = mesclar(dataMock('auth'), dataMock('criar-conta'));
    const errors: Record<string, string> = {};
    const values = { user: '', email: '', terms: false };

    // registration_open (/admin/configuracoes). Padrão ABERTO: falha de banco não fecha o jogo.
    const aberto = Boolean(await Settings.valor('registration_open', true));
    if (!aberto) errors.form = 'O cadastro de novas contas está temporariamente fechado.';

    if (req.isPost() && aberto) {
      this.csrf(req);
      values.user = (req.input('user', '') ?? '').slice(0, 80);
      values.email = (req.input('email', '') ?? '').slice(0, 200);
      values.terms = req.input('terms') !== null;
      const pass = req.input('password', '') ?? '';
      const confirm = req.input('password_confirm', '') ?? '';

      if (!/^[A-Za-z0-9_]{3,24}$/.test(values.user)) errors.user = 'Use de 3 a 24 caracteres: letras, números e sublinhado (_).';
      if (!emailValido(values.email)) errors.email = 'Informe um e-mail válido.';
      else if ([...values.email].length > 120) errors.email = 'O e-mail pode ter no máximo 120 caracteres.';
      if ([...pass].length < 8) errors.password = 'A senha precisa ter ao menos 8 caracteres.';
      else if (bytes(pass) > 72) errors.password = 'A senha pode ter no máximo 72 caracteres.'; // bcrypt corta em 72 bytes
      else if (pass !== confirm) errors.password_confirm = 'As senhas não coincidem.';
      if (!values.terms) errors.terms = 'Aceite os termos para continuar.';

      try {
        if (!errors.user && (await UserRepo.usernameTaken(values.user))) errors.user = 'Esse nome de usuário já está em uso.';
        if (!errors.email && (await UserRepo.emailTaken(values.email))) errors.email = 'Já existe uma conta com esse e-mail.';
        if (Object.keys(errors).length === 0) {
          const uid = await UserRepo.create(values.user, values.email, pass);
          this.signIn(uid);
          flash('ok', 'Conta criada. Boa caçada, ' + values.user + '.');
          return this.redirect('inicio');
        }
      } catch (e) {
        // Corrida no índice único (dois cadastros simultâneos): vira erro de campo.
        const msg = String((e as Error)?.message ?? '');
        if (msg.includes('UNIQUE constraint failed')) {
          const key = msg.includes('email') ? 'email' : 'user';
          errors[key] = key === 'email' ? 'Já existe uma conta com esse e-mail.' : 'Esse nome de usuário já está em uso.';
        } else {
          console.error('[extracthunters] criar-conta:', e);
          errors.form = 'Não foi possível criar a conta agora. Tente novamente em instantes.';
        }
      }
    }

    // pages/login.css é reaproveitado pelo cadastro (mesmo cartão).
    const page = this.page('criar-conta', 'Criar conta', {
      nav: '',
      back: { label: 'VOLTAR', href: url('login') },
      css: ['pages/login.css'],
    });
    return this.render(criarConta, page, { auth, errors, values }, 'auth', Object.keys(errors).length ? 422 : 200);
  }

  async logout(req: AppRequest): Promise<AppResponse> {
    this.csrf(req);
    ctx().session.destroy();
    flash('ok', 'Você saiu da conta.');
    return this.redirect('login');
  }

  /** Grava o id na sessão, com id de sessão e token CSRF novos. */
  private signIn(userId: number): void {
    const s = ctx().session;
    s.regenerate();
    s.set('user_id', userId);
    s.delete('player');
    s.delete('_csrf');
    carimbarConta();
  }
}
