import { url } from './helpers';
import { securityHeaders } from './security';

/**
 * Resposta HTTP — o App\Core\Response do PHP. Os cabeçalhos de segurança (CSP com nonce,
 * nosniff, frame DENY...) entram no toResponse(), igual ao send() do PHP.
 */
export class AppResponse {
  constructor(
    public body: string = '',
    public status = 200,
    public headers: Record<string, string> = {},
  ) {}

  static html(body: string, status = 200, headers: Record<string, string> = {}): AppResponse {
    return new AppResponse(body, status, { 'Content-Type': 'text/html; charset=UTF-8', ...headers });
  }

  static text(body: string, status = 200): AppResponse {
    return new AppResponse(body, status, { 'Content-Type': 'text/plain; charset=UTF-8' });
  }

  static json(payload: unknown, status = 200): AppResponse {
    return new AppResponse(JSON.stringify(payload), status, { 'Content-Type': 'application/json; charset=UTF-8' });
  }

  /** Redireciona para caminho do app (use url()). 303 para POST -> GET. Sem open redirect. */
  static redirect(location: string, status = 303): AppResponse {
    if (/^(?:[a-z][a-z0-9+.-]*:)?\/\//i.test(location)) location = url('inicio');
    return new AppResponse('', status, { Location: location });
  }

  withHeader(name: string, v: string): AppResponse {
    return new AppResponse(this.body, this.status, { ...this.headers, [name]: v });
  }

  toResponse(https: boolean, setCookie: string | null): Response {
    const h = new Headers(securityHeaders(https));
    for (const [k, v] of Object.entries(this.headers)) h.set(k, v);
    if (!h.has('Cache-Control')) h.set('Cache-Control', 'no-store, max-age=0');
    if (setCookie) h.append('Set-Cookie', setCookie);
    const semCorpo = this.status === 204 || this.status === 304 || (this.status >= 300 && this.status < 400);
    return new Response(semCorpo ? null : this.body, { status: this.status, headers: h });
  }
}
