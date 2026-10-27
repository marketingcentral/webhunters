/**
 * Extract Hunters — front controller do Worker (o index.php do PHP).
 *
 * Ordem igual à do PHP: AccountGuard -> LoginGuard -> Maintenance -> Router. Arquivos de
 * /assets saem direto do Static Assets (o Worker nem é chamado); aqui chegam só:
 *   - /media/*            -> objeto do R2 (mídia de conteúdo e uploads);
 *   - /assets/* ausente   -> arquivos grandes demais para Static Assets (ex.: models/*.glb),
 *                            que o build manda para o R2 sob a chave assets/<caminho>;
 *   - todo o resto        -> rotas da aplicação.
 */
import { isDebugHost } from './core/config';
import { runWithCtx, type Ctx } from './core/context';
import type { Env } from './core/env';
import { accountGuard, loginGuard, maintenanceGuard } from './core/guards';
import { HttpException } from './core/http-exception';
import { AppRequest } from './core/request';
import { AppResponse } from './core/response';
import { Router } from './core/router';
import { newNonce } from './core/security';
import { Session } from './core/session';
import { ErrorsController } from './controllers/errors-controller';
import { ROUTES } from './routes';

const router = new Router();
router.load(ROUTES);

/** Extensões que media/ nunca entrega (o .htaccess bloqueava as executáveis pelo navegador). */
const MEDIA_PROIBIDA = /\.(php\d?|phtml|phps|phar|pht|cgi|pl|py|rb|sh|exe|htm|html|svg|xml|htaccess)$/i;

const TIPOS: Record<string, string> = {
  png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', webp: 'image/webp', gif: 'image/gif',
  avif: 'image/avif', glb: 'model/gltf-binary', mp4: 'video/mp4', webm: 'video/webm', json: 'application/json',
};

async function deR2(env: Env, req: Request, chave: string): Promise<Response> {
  if (req.method !== 'GET' && req.method !== 'HEAD') return new Response('Método não permitido', { status: 405 });
  if (chave.includes('..') || MEDIA_PROIBIDA.test(chave)) return new Response('Proibido', { status: 403 });
  const obj = await env.MEDIA.get(chave);
  if (!obj) return new Response('Não encontrado', { status: 404 });
  const h = new Headers();
  obj.writeHttpMetadata(h);
  if (!h.has('Content-Type')) h.set('Content-Type', TIPOS[chave.split('.').pop()!.toLowerCase()] ?? 'application/octet-stream');
  h.set('ETag', obj.httpEtag);
  h.set('X-Content-Type-Options', 'nosniff');
  h.set('Cross-Origin-Resource-Policy', 'same-origin');
  // ?v=<mtime> é imutável (mesma regra do .htaccess); sem versão, um dia.
  h.set('Cache-Control', new URL(req.url).searchParams.has('v') ? 'public, max-age=31536000, immutable' : 'public, max-age=86400');
  return new Response(req.method === 'HEAD' ? null : obj.body, { headers: h });
}

export default {
  async fetch(raw: Request, env: Env): Promise<Response> {
    const u = new URL(raw.url);
    if (u.pathname.startsWith('/media/')) return deR2(env, raw, decodeURIComponent(u.pathname.slice(1)));
    if (u.pathname.startsWith('/assets/')) return deR2(env, raw, decodeURIComponent(u.pathname.slice(1)));

    const req = await AppRequest.from(raw);
    const session = await Session.start(env.DB, raw.headers.get('Cookie'), req.isHttps());
    const c: Ctx = {
      env,
      req,
      session,
      nonce: newNonce(),
      debug: env.APP_ENV === 'local' || isDebugHost(req.host()),
      cache: new Map(),
    };

    return runWithCtx(c, async () => {
      let resp: AppResponse;
      try {
        // Banido/suspenso cai antes de tudo; anônimo não passa das telas públicas.
        const bloqueio = (await accountGuard(req)) ?? loginGuard(req);
        await maintenanceGuard(req);
        resp = bloqueio ?? (await router.dispatch(req));
      } catch (e) {
        const errs = new ErrorsController();
        if (e instanceof HttpException) {
          resp = await errs.fromException(e, req);
        } else {
          console.error('[extracthunters]', e);
          resp = await errs.serverError(e, req);
        }
      }
      const cookie = await session.save();
      return resp.toResponse(req.isHttps(), cookie);
    });
  },
} satisfies ExportedHandler<Env>;
