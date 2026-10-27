import { HttpException } from './http-exception';
import type { AppRequest } from './request';
import type { AppResponse } from './response';

/**
 * Roteador de whitelist — o App\Core\Router do PHP, mesma sintaxe de padrão:
 *   `{param}`   casa [a-z0-9-]{1,40}
 *   `[/{param}]` segmento final opcional
 * Caminho que casa com verbo errado dá 405; nada casou, 404.
 */
export type Params = Record<string, string>;
export type Action = (req: AppRequest, params: Params) => Promise<AppResponse>;
export type RouteRow = [string | string[], string, Action];

const PARAM = '[a-z0-9-]{1,40}';

interface Rota {
  methods: string[];
  pattern: string;
  regex: RegExp;
  action: Action;
}

function quote(s: string): string {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

export function compile(pattern: string): RegExp {
  const p = '/' + pattern.replace(/^\/+|\/+$/g, '');
  if (p === '/') return /^\/$/;
  let out = '';
  const re = /\[\/\{([a-z_][a-z0-9_]*)\}\]|\{([a-z_][a-z0-9_]*)\}/gi;
  let last = 0;
  for (const m of p.matchAll(re)) {
    out += quote(p.slice(last, m.index));
    out += m[1] ? `(?:/(?<${m[1]}>${PARAM}))?` : `(?<${m[2]}>${PARAM})`;
    last = m.index! + m[0].length;
  }
  out += quote(p.slice(last));
  return new RegExp('^' + out + '$');
}

export class Router {
  private routes: Rota[] = [];

  load(table: RouteRow[]): void {
    for (const [methods, pattern, action] of table) {
      this.routes.push({
        methods: (Array.isArray(methods) ? methods : [methods]).map((m) => m.toUpperCase()),
        pattern,
        regex: compile(pattern),
        action,
      });
    }
  }

  async dispatch(req: AppRequest): Promise<AppResponse> {
    const path = req.path();
    const method = req.method();
    const allowed: string[] = [];
    for (const r of this.routes) {
      const m = r.regex.exec(path);
      if (!m) continue;
      if (!r.methods.includes(method)) {
        allowed.push(...r.methods);
        continue;
      }
      const params: Params = {};
      for (const [k, v] of Object.entries(m.groups ?? {})) if (v) params[k] = v;
      return r.action(req, params);
    }
    if (allowed.length) {
      throw new HttpException(405, 'Método não permitido', { Allow: [...new Set(allowed)].join(', ') });
    }
    throw new HttpException(404, 'Página não encontrada');
  }

  /** Caminhos GET fixos da whitelist (Router::caminhosGet), para links que só acendem quando a rota existe. */
  getPaths(): string[] {
    return this.routes
      .filter((r) => r.methods.includes('GET'))
      .map((r) => r.pattern.replace(/\[.*$/, ''))
      .filter((p) => p !== '' && !p.includes('{'))
      .map((p) => p.replace(/\/+$/, '') || '/');
  }
}
