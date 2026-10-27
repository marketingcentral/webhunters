/**
 * /recuperar-senha — o AuthController::recover do PHP, numa classe à parte para não
 * mexer no auth-controller.ts. Como no PHP, não há back-end de e-mail: um endereço válido
 * sempre recebe a mesma resposta neutra (não revela se a conta existe).
 */
import { dataMock, url } from '../core/helpers';
import type { AppRequest } from '../core/request';
import type { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import { recuperarSenha } from '../views/pages/recuperar-senha';
import { Controller } from './controller';

/** array_replace_recursive() para objetos simples (listas são substituídas inteiras). */
function mesclar(a: Dict, b: Dict): Dict {
  const out: Dict = { ...a };
  for (const [k, v] of Object.entries(b)) {
    out[k] = v && typeof v === 'object' && !Array.isArray(v) && out[k] && typeof out[k] === 'object' ? mesclar(out[k], v) : v;
  }
  return out;
}

/** filter_var(FILTER_VALIDATE_EMAIL), aproximado (mesma regra do auth-controller.ts). */
function emailValido(e: string): boolean {
  return /^[^\s@"(),:;<>[\\\]]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$/.test(e);
}

export class RecoverController extends Controller {
  async recover(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    // app/Data/recuperar-senha.php refina os textos de app/Data/auth.php.
    const auth = mesclar(dataMock('auth'), dataMock('recuperar-senha'));
    let sent = false;
    let error: string | null = null;
    let email = '';

    if (req.isPost()) {
      this.csrf(req);
      email = Array.from(req.input('email', '') ?? '').slice(0, 120).join(''); // mb_substr(..., 0, 120)
      if (!emailValido(email)) {
        error = 'Informe um e-mail válido.';
      } else {
        sent = true; // sem back-end: sempre responde de forma neutra
      }
    }

    const page = this.page('recuperar-senha', 'Recuperar senha', { nav: '', back: { label: 'VOLTAR', href: url('login') } });
    return this.render(recuperarSenha, page, { auth, sent, error, email }, 'auth', error ? 422 : 200);
  }
}
