import { BASE_URL } from './config';

/**
 * Retrato imutável da requisição — o App\Core\Request do PHP. Controllers nunca leem o
 * Request cru: tudo passa por aqui, com os mesmos nomes (query, input, inputArray...).
 */
export class AppRequest {
  private constructor(
    public readonly raw: Request,
    private readonly _method: string,
    private readonly _path: string,
    private readonly _query: URLSearchParams,
    private readonly _body: FormData | null,
    public readonly url: URL,
  ) {}

  static async from(raw: Request): Promise<AppRequest> {
    const url = new URL(raw.url);
    let method = raw.method.toUpperCase();
    if (method === 'HEAD') method = 'GET';

    let path = url.pathname;
    try {
      path = decodeURIComponent(path);
    } catch {
      /* caminho com % inválido: segue cru e cai no 404 */
    }
    const base = BASE_URL.replace(/\/$/, '');
    if (base !== '' && path.startsWith(base)) path = path.slice(base.length);
    path = path.replace(/^\/index\.php/, '');
    path = '/' + path.replace(/^\/+|\/+$/g, '');
    path = path.replace(/\/{2,}/g, '/');

    let body: FormData | null = null;
    const tipo = raw.headers.get('Content-Type') ?? '';
    if (method !== 'GET' && (tipo.includes('application/x-www-form-urlencoded') || tipo.includes('multipart/form-data'))) {
      try {
        body = await raw.formData();
      } catch {
        body = null;
      }
    }
    return new AppRequest(raw, method, path, url.searchParams, body, url);
  }

  method(): string {
    return this._method;
  }
  path(): string {
    return this._path;
  }
  isPost(): boolean {
    return this._method === 'POST';
  }
  isGet(): boolean {
    return this._method === 'GET';
  }

  /** Valor da query string, sempre string aparada (ou o padrão). */
  query(key: string, def: string | null = null): string | null {
    const v = this._query.get(key);
    return v === null ? def : v.trim();
  }

  /** Valor do corpo POST, sempre string aparada (ou o padrão). Arquivo enviado conta como ausente. */
  input(key: string, def: string | null = null): string | null {
    const v = this._body?.get(key);
    return typeof v === 'string' ? v.trim() : def;
  }

  /** Campos `nome[]` (multi-select, grupo de checkbox). */
  inputArray(key: string): string[] {
    if (!this._body) return [];
    const vals = [...this._body.getAll(key + '[]'), ...this._body.getAll(key)];
    return vals.map((v) => (typeof v === 'string' ? v.trim() : ''));
  }

  has(key: string): boolean {
    return (this._body?.has(key) ?? false) || this._query.has(key);
  }

  header(name: string, def: string | null = null): string | null {
    return this.raw.headers.get(name) ?? def;
  }

  ip(): string {
    return this.raw.headers.get('CF-Connecting-IP') ?? '0.0.0.0';
  }

  userAgent(): string {
    return this.raw.headers.get('User-Agent') ?? '';
  }

  host(): string {
    return this.raw.headers.get('Host') ?? this.url.host;
  }

  isHttps(): boolean {
    return this.url.protocol === 'https:' || this.raw.headers.get('X-Forwarded-Proto') === 'https';
  }

  /** Navegação sem recarga (assets/js/spa.js): cabeçalho próprio X-EH-Partial. */
  wantsPartial(): boolean {
    return this.raw.headers.get('X-EH-Partial') === '1';
  }

  isAjax(): boolean {
    return (this.raw.headers.get('X-Requested-With') ?? '').toLowerCase() === 'xmlhttprequest';
  }
}
