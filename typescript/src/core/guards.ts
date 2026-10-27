/**
 * Porteiros que rodam antes de qualquer controller (index.php do PHP):
 *   - AccountGuard: quem TEM sessão e não deveria mais ter (banido/suspenso/apagado);
 *   - LoginGuard:   quem NÃO TEM sessão e quer entrar numa tela do jogo;
 *   - Maintenance:  modo manutenção ligado em /admin/configuracoes.
 * O porquê de cada um está nos arquivos PHP de mesmo nome em app/Core/.
 */
import { ctx } from './context';
import * as DB from './database';
import { flash, isAuthenticated, url } from './helpers';
import { HttpException } from './http-exception';
import type { AppRequest } from './request';
import { AppResponse } from './response';
import * as Settings from '../repo/settings-repo';

// ------------------------------------------------------------------------- AccountGuard

const TTL_CONTA = 30;
const BLOQUEADAS = ['banned', 'suspended'];

export interface EstadoConta {
  bloqueado: boolean;
  status: string;
  kind: string | null;
  reason: string | null;
  expires_at: string | null;
}

async function consultarConta(userId: number) {
  return DB.one<{ status: string; kind: string | null; reason: string | null; expires_at: string | null }>(
    `SELECT u.status, b.kind, b.reason, b.expires_at
       FROM users u
       LEFT JOIN bans b
         ON b.user_id = u.id
        AND b.revoked_at IS NULL
        AND (b.expires_at IS NULL OR b.expires_at > datetime('now'))
      WHERE u.id = :id
      ORDER BY b.expires_at IS NULL DESC, b.expires_at DESC, b.id DESC
      LIMIT 1`,
    { id: userId },
  );
}

/**
 * Estado da conta AGORA. Diferença do PHP: lá um ban temporário vencido era desfeito por
 * BanRepo::sincronizar() (que também escreve auditoria). Aqui, sem ban vigente o status
 * 'banned'/'suspended' é devolvido a 'active' direto — o painel de banimentos não foi portado.
 */
export async function estadoConta(userId: number): Promise<EstadoConta> {
  const livre: EstadoConta = { bloqueado: false, status: 'active', kind: null, reason: null, expires_at: null };
  if (userId <= 0) return livre;
  const linha = await consultarConta(userId);
  if (linha === null) return { bloqueado: true, status: 'gone', kind: null, reason: null, expires_at: null };
  const status = String(linha.status ?? 'active');
  if (!BLOQUEADAS.includes(status)) return livre;
  if (linha.kind === null) {
    await DB.run(`UPDATE users SET status = 'active' WHERE id = :id`, { id: userId });
    return livre;
  }
  return { bloqueado: true, status, kind: linha.kind, reason: linha.reason, expires_at: linha.expires_at };
}

function dataBr(s: string): string | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s);
  return m ? `${m[3]}/${m[2]}/${m[1]} às ${m[4]}:${m[5]}` : null;
}

export function mensagemConta(est: EstadoConta): string {
  if (est.status === 'gone') return 'Esta conta não está mais disponível.';
  let texto =
    est.status === 'suspended'
      ? 'Sua conta está suspensa e não pode entrar no jogo.'
      : 'Sua conta está banida e não pode entrar no jogo.';
  const prazo = est.expires_at ? dataBr(est.expires_at) : null;
  if (prazo) texto += ` O acesso volta em ${prazo}.`;
  else if (est.kind !== null) texto += ' A punição não tem prazo para acabar.';
  const motivo = (est.reason ?? '').trim();
  if (motivo !== '') texto += ' Motivo: ' + motivo.slice(0, 180);
  return texto + ' Fale com o suporte se discordar.';
}

export function carimbarConta(): void {
  ctx().session.set('auth_seen', Math.floor(Date.now() / 1000));
}

function querJson(req: AppRequest, caminho: string): boolean {
  return (
    req.wantsPartial() ||
    req.isAjax() ||
    caminho === 'hud' ||
    caminho.startsWith('hud/') ||
    caminho.endsWith('/dados')
  );
}

export async function accountGuard(req: AppRequest): Promise<AppResponse | null> {
  const s = ctx().session;
  const uid = Number(s.get('user_id') ?? 0);
  if (uid <= 0) return null;
  const caminho = req.path().replace(/^\/+|\/+$/g, '');
  if (caminho === 'sair') return null;
  const agora = Math.floor(Date.now() / 1000);
  const visto = Number(s.get('auth_seen') ?? 0);
  if (!req.isPost() && visto > 0 && agora - visto < TTL_CONTA) return null;

  let est: EstadoConta;
  try {
    est = await estadoConta(uid);
  } catch (e) {
    console.error('[extracthunters] AccountGuard:', e);
    return null;
  }
  if (!est.bloqueado) {
    s.set('auth_seen', agora);
    return null;
  }
  const msg = mensagemConta(est);
  s.destroy();
  if (querJson(req, caminho)) {
    return AppResponse.json({ ok: false, error: 'conta_bloqueada', message: msg, redirect: url('login') }, 401);
  }
  flash('error', msg);
  return AppResponse.redirect(url('login'));
}

// --------------------------------------------------------------------------- LoginGuard

/** Rotas abertas a quem não entrou (primeiro segmento). Lista FECHADA: rota nova nasce protegida. */
const PUBLICOS = ['', 'login', 'criar-conta', 'recuperar-senha', 'sair'];
/** Áreas com porteiro próprio: painel (sessão de operador) e API (chave no Authorization). */
const PORTEIRO_PROPRIO = ['admin', 'api'];

export function loginGuard(req: AppRequest): AppResponse | null {
  if (isAuthenticated()) return null;
  const caminho = req.path().replace(/^\/+|\/+$/g, '');
  const primeiro = caminho.split('/')[0] ?? '';
  if (PUBLICOS.includes(primeiro) || PORTEIRO_PROPRIO.includes(primeiro)) return null;
  const msg = 'Entre na sua conta para continuar.';
  if (querJson(req, caminho)) {
    return AppResponse.json({ ok: false, error: 'sem_sessao', message: msg, redirect: url('login') }, 401);
  }
  flash('error', msg);
  return AppResponse.redirect(url('login'));
}

// -------------------------------------------------------------------------- Maintenance

const TTL_MANUT = 30;
export const MANUT_PADRAO = 'MANUTENÇÃO PROGRAMADA. VOLTAMOS EM BREVE.';
const LIVRES = ['', 'login', 'sair', 'recuperar-senha', 'admin', 'api'];

async function estadoManutencao(): Promise<[boolean, string]> {
  const s = ctx().session;
  const agora = Math.floor(Date.now() / 1000);
  const visto = Number(s.get('_maint_seen') ?? 0);
  if (visto > 0 && agora - visto < TTL_MANUT && s.get('_maint_on') !== undefined) {
    return [Boolean(s.get('_maint_on')), String(s.get('_maint_msg') ?? MANUT_PADRAO)];
  }
  let ligada = false;
  let mensagem = MANUT_PADRAO;
  try {
    ligada = Boolean(await Settings.valor('maintenance_enabled', false));
    mensagem = String(await Settings.valor('maintenance_message', '')).trim() || MANUT_PADRAO;
  } catch (e) {
    console.error('[extracthunters] Maintenance:', e);
    return [false, MANUT_PADRAO];
  }
  // Só guarda na sessão de quem já tem sessão: não abre uma linha no D1 por visitante.
  if (isAuthenticated()) {
    s.set('_maint_seen', agora);
    s.set('_maint_on', ligada);
    s.set('_maint_msg', mensagem);
  }
  return [ligada, mensagem];
}

async function ehOperacao(): Promise<boolean> {
  const s = ctx().session;
  if (Number(s.get('admin_id') ?? 0) > 0) return true;
  const uid = Number(s.get('user_id') ?? 0);
  if (uid <= 0) return false;
  try {
    const papel = String(await DB.value('SELECT role FROM users WHERE id = :id', { id: uid }));
    return ['admin', 'owner'].includes(papel);
  } catch {
    return false;
  }
}

export async function maintenanceGuard(req: AppRequest): Promise<void> {
  const primeiro = req.path().replace(/^\/+|\/+$/g, '').split('/')[0] ?? '';
  if (LIVRES.includes(primeiro)) return;
  const [ligada, mensagem] = await estadoManutencao();
  if (!ligada) return;
  if (await ehOperacao()) return;
  throw new HttpException(503, mensagem);
}
