/**
 * Login do painel administrativo — o App\Controllers\Admin\AuthController do PHP.
 *
 * Não existe senha de administrador: o dono/admin entra com o MESMO usuário e senha do jogo;
 * o acesso vem de users.role ('admin' | 'owner'). Para dar acesso:
 *   UPDATE users SET role='admin' WHERE username='...'
 *
 * DUAS SESSÕES NO MESMO COOKIE: `user_id` (jogo) e `admin_id` (painel) são independentes —
 * entrar no painel não loga no jogo, e sair do painel não derruba o jogo.
 *
 * A falha é sempre a MESMA frase e o mesmo status (senha errada, conta inexistente e conta sem
 * papel administrativo são indistinguíveis de fora); só o admin_audit_log sabe a diferença.
 * A partir da 4ª falha a porta fecha por um tempo crescente (sessão + log por IP).
 */
import { ctx } from '../../core/context';
import { flash } from '../../core/helpers';
import * as Password from '../../core/password';
import type { AppRequest } from '../../core/request';
import type { AppResponse } from '../../core/response';
import type { Params } from '../../core/router';
import type { Dict } from '../../core/view';
import * as AdminRepo from '../../repo/admin-repo';
import { adminLogin } from '../../views/pages/admin-login';
import { Controller } from '../controller';

/** Única mensagem de falha — não diferencia conta inexistente de conta sem acesso. */
const ERRO_GENERICO = 'Usuário ou senha inválidos.';
/** Tentativas sem espera; a partir da 4ª a porta fecha. */
const TENTATIVAS_LIVRES = 3;
/** Espera (s) da 4ª falha em diante; a última se repete. */
const ESPERAS = [15, 45, 120, 300];
/** Janela em que as falhas do mesmo IP são contadas juntas. */
const JANELA_MIN = 15;

export class AdminAuthController extends Controller {
  async login(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const s = ctx().session;
    if (!req.isPost() && (Number(s.get('admin_id') ?? 0) | 0) > 0) return this.redirect('admin');

    const ip = req.ip();
    let erro: string | null = null;
    let usuario = '';
    let status = 200;

    if (req.isPost()) {
      this.csrf(req);
      usuario = AdminRepo.corta(req.input('user', '') ?? '', 60).trim();
      const senha = req.input('password', '') ?? '';
      const espera = await this.esperaRestante(ip);

      if (espera > 0) {
        await AdminRepo.registrar(null, 'login_bloqueado', 'admin', usuario !== '' ? usuario : null,
          'Tentativa durante o bloqueio por excesso de falhas.', { espera_s: espera }, ip);
        erro = 'Muitas tentativas seguidas. Tente novamente em ' + porExtenso(espera) + '.';
        status = 429;
      } else if (usuario === '' || senha === '') {
        erro = 'Informe o usuário e a senha.';
        status = 422;
      } else {
        try {
          const conta = await AdminRepo.porLogin(usuario);
          if (conta === null || !(await Password.verify(senha, String(conta.password_hash ?? '')))) {
            // sem isto o tempo de resposta denuncia quais contas existem
            if (conta === null) await Password.verifyDummy(senha, ctx().env.PASSWORD_ALGO);
            await this.registrarFalha(ip, usuario, conta !== null ? Number(conta.id) | 0 : null,
              'senha_incorreta', 'Senha incorreta no painel administrativo.');
            erro = ERRO_GENERICO;
            status = 422;
          } else if (!AdminRepo.podeEntrar(conta)) {
            // Senha certa em conta sem acesso: a tela não conta isso — o log conta.
            await this.registrarFalha(ip, usuario, Number(conta.id) | 0, 'sem_acesso',
              'Senha correta em conta sem acesso ao painel.',
              { role: String(conta.role), status: String(conta.status) });
            erro = ERRO_GENERICO;
            status = 422;
          } else {
            return await this.entrar(conta, req);
          }
        } catch (e) {
          console.error('[extracthunters] admin/login:', e);
          erro = 'Não foi possível verificar suas credenciais agora. Tente novamente em instantes.';
          status = 503;
        }
      }
    }

    const page = this.page('admin-login', 'Acesso restrito', {
      slug: 'admin-login',
      nav: '',
      back: null,
      three: { atmosphere: false },
    });
    return this.render(adminLogin, page, { erro, usuario }, 'admin-auth', status);
  }

  /** Encerra SÓ a sessão administrativa (user_id continua de pé). */
  async logout(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    this.csrf(req);
    const s = ctx().session;
    const id = Number(s.get('admin_id') ?? 0) | 0;
    if (id > 0) {
      await AdminRepo.registrar(id, 'logout', 'admin', String(id), 'Saiu do painel administrativo.', {}, req.ip());
    }
    s.delete('admin_id');
    s.delete('admin_seen');
    s.regenerate(); // id novo: o antigo não vale mais para nada
    flash('ok', 'Você saiu do painel.');
    return this.redirect('admin/login');
  }

  private async entrar(conta: Dict, req: AppRequest): Promise<AppResponse> {
    const id = Number(conta.id) | 0;
    const s = ctx().session;
    s.regenerate();
    s.set('admin_id', id);
    s.set('admin_seen', AdminRepo.agoraUnix());
    s.delete('_csrf'); // token novo com a sessão nova
    s.delete('admin_falhas');
    s.delete('admin_espera_ate');

    await AdminRepo.marcarAcesso(id, req.ip());
    await AdminRepo.registrar(id, 'login', 'admin', String(id), 'Entrou no painel administrativo.',
      { role: String(conta.role), ua: AdminRepo.corta(req.userAgent(), 180) }, req.ip());

    flash('ok', 'Painel liberado, ' + String(conta.username) + '.');
    return this.redirect('admin');
  }

  /** Conta a falha na sessão (rápido) e no log (honesto: sobrevive a apagar o cookie). */
  private async registrarFalha(ip: string, login: string, userId: number | null, motivo: string, resumo: string, meta: Dict = {}): Promise<void> {
    await AdminRepo.registrar(userId, 'login_falhou', 'admin', login !== '' ? login : null, resumo,
      { ...meta, motivo, login }, ip);
    const s = ctx().session;
    let n = (Number(s.get('admin_falhas') ?? 0) | 0) + 1;
    s.set('admin_falhas', n);
    n = Math.max(n, (await AdminRepo.falhasRecentes(ip, JANELA_MIN)).n);
    const e = espera(n);
    s.set('admin_espera_ate', e > 0 ? AdminRepo.agoraUnix() + e : 0);
  }

  /** Segundos que ainda faltam para a porta reabrir (0 = pode tentar). */
  private async esperaRestante(ip: string): Promise<number> {
    const agora = AdminRepo.agoraUnix();
    const restaSessao = (Number(ctx().session.get('admin_espera_ate') ?? 0) | 0) - agora;
    const f = await AdminRepo.falhasRecentes(ip, JANELA_MIN);
    const restaIp = f.ultima > 0 ? f.ultima + espera(f.n) - agora : 0;
    return Math.max(0, restaSessao, restaIp);
  }
}

function espera(falhas: number): number {
  if (falhas <= TENTATIVAS_LIVRES) return 0;
  const passo = Math.min(falhas - TENTATIVAS_LIVRES, ESPERAS.length);
  return ESPERAS[passo - 1]!;
}

function porExtenso(segundos: number): string {
  if (segundos < 60) return segundos + ' segundo' + (segundos === 1 ? '' : 's');
  const min = Math.ceil(segundos / 60);
  return min + ' minuto' + (min === 1 ? '' : 's');
}

