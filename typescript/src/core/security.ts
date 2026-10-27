import { ctx } from './context';
import { e } from './html';
import { HttpException } from './http-exception';
import type { AppRequest } from './request';

/**
 * CSRF, nonce de CSP e cabeçalhos de segurança — o App\Core\Security do PHP.
 * A sessão em si está em core/session.ts.
 */

export function newNonce(): string {
  const b = new Uint8Array(16);
  crypto.getRandomValues(b);
  return btoa(String.fromCharCode(...b)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export function nonce(): string {
  return ctx().nonce;
}

function hex(bytes: Uint8Array): string {
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

/** Token por sessão (não por requisição): o prefetch do spa.js não o invalida. */
export function csrfToken(): string {
  const s = ctx().session;
  let t = s.get<string>('_csrf');
  if (typeof t !== 'string' || t === '') {
    const b = new Uint8Array(32);
    crypto.getRandomValues(b);
    t = hex(b);
    s.set('_csrf', t);
  }
  return t;
}

export function csrfField(): string {
  return `<input type="hidden" name="_csrf" value="${e(csrfToken())}">`;
}

/** Comparação em tempo constante (hash_equals). */
function iguais(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let r = 0;
  for (let i = 0; i < a.length; i++) r |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return r === 0;
}

/**
 * Valida o token de um POST (campo `_csrf` ou cabeçalho X-CSRF-Token) e confere a origem.
 * Falha vira 419, como no PHP.
 */
export function checkCsrf(req: AppRequest): void {
  if (!req.isPost()) return;
  const sent = req.input('_csrf') ?? req.header('X-CSRF-Token') ?? '';
  const real = String(ctx().session.get('_csrf') ?? '');
  if (sent === '' || real === '' || !iguais(real, sent)) {
    throw new HttpException(419, 'Sessão expirada ou token inválido.');
  }
  const origem = req.header('Origin') ?? req.header('Referer');
  if (origem) {
    let host: string | null = null;
    try {
      host = new URL(origem).hostname;
    } catch {
      host = null;
    }
    const self = req.host().replace(/:\d+$/, '');
    if (host && self && host.toLowerCase() !== self.toLowerCase()) {
      throw new HttpException(419, 'Origem da requisição inválida.');
    }
  }
}

/** CSP com o nonce da requisição + cabeçalhos estáticos (Security::sendHeaders). */
export function securityHeaders(https: boolean): Record<string, string> {
  const n = nonce();
  const csp = [
    "default-src 'self'",
    `script-src 'self' 'nonce-${n}'`,
    `style-src 'self' 'nonce-${n}'`,
    "style-src-attr 'unsafe-inline'",
    "img-src 'self' data: blob:",
    "font-src 'self'",
    "connect-src 'self'",
    "worker-src 'self' blob:",
    "frame-ancestors 'none'",
    "base-uri 'self'",
    "form-action 'self'",
    "object-src 'none'",
  ].join('; ');
  const h: Record<string, string> = {
    'Content-Security-Policy': csp,
    'X-Content-Type-Options': 'nosniff',
    'X-Frame-Options': 'DENY',
    'Referrer-Policy': 'strict-origin-when-cross-origin',
    'Permissions-Policy': 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
    'Cross-Origin-Opener-Policy': 'same-origin',
    'Cross-Origin-Resource-Policy': 'same-origin',
  };
  if (https) h['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
  return h;
}
