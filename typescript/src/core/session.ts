import { SESSION_NAME, SESSION_ROTATE, SESSION_TTL } from './config';

/**
 * Sessão do site guardada no D1 — o session_start() do PHP.
 *
 * O PHP guardava a sessão em arquivo no servidor; o Worker não tem disco que sobreviva entre
 * requisições, então a sessão vira uma linha da tabela `sessions` (última migration). Mesmas
 * garantias do App\Core\Security::startSession():
 *   - id aleatório de 48 caracteres, cookie HttpOnly + SameSite=Lax (+ Secure em HTTPS);
 *   - modo estrito: id desconhecido ou vencido NÃO é aceito, nasce outro;
 *   - rotação do id a cada 30 min e em todo login (regenerate()).
 *
 * Para não gastar uma escrita no D1 a cada página, a linha só é regravada quando o conteúdo
 * mudou ou quando o último toque tem mais de 5 minutos (para o vencimento andar).
 */
const TOQUE = 300;

type Dados = Record<string, unknown>;

function novoId(): string {
  const b = new Uint8Array(36);
  crypto.getRandomValues(b);
  return btoa(String.fromCharCode(...b)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function lerCookie(header: string | null, nome: string): string | null {
  if (!header) return null;
  for (const parte of header.split(';')) {
    const [k, ...v] = parte.trim().split('=');
    if (k === nome) return v.join('=');
  }
  return null;
}

export class Session {
  private dirty = false;
  private apagarIds: string[] = [];
  private enviarCookie = false;
  private destruida = false;
  private updatedAt = 0;

  private constructor(
    private readonly d1: D1Database,
    private id: string | null,
    public data: Dados,
    private readonly secure: boolean,
  ) {}

  static async start(d1: D1Database, cookieHeader: string | null, secure: boolean): Promise<Session> {
    const id = lerCookie(cookieHeader, SESSION_NAME);
    const agora = Math.floor(Date.now() / 1000);
    if (id && /^[A-Za-z0-9_-]{20,128}$/.test(id)) {
      const row = await d1
        .prepare('SELECT data, updated_at, expires_at FROM sessions WHERE id = ?1')
        .bind(id)
        .first<{ data: string; updated_at: number; expires_at: number }>();
      if (row && row.expires_at > agora) {
        let data: Dados = {};
        try {
          data = JSON.parse(row.data) as Dados;
        } catch {
          data = {};
        }
        const s = new Session(d1, id, data, secure);
        s.updatedAt = row.updated_at;
        // Rotação periódica: mitiga fixação em sessão longa (mesmo critério do PHP).
        if (agora - Number(data._created ?? 0) > SESSION_ROTATE) s.regenerate();
        return s;
      }
    }
    // Sem cookie, cookie forjado ou vencido: sessão nova (só vira linha se algo for gravado).
    return new Session(d1, null, {}, secure);
  }

  get<T = unknown>(k: string): T | undefined {
    return this.data[k] as T | undefined;
  }

  set(k: string, v: unknown): void {
    this.data[k] = v;
    this.dirty = true;
  }

  delete(k: string): void {
    if (k in this.data) {
      delete this.data[k];
      this.dirty = true;
    }
  }

  /** session_regenerate_id(true): id novo, o antigo é apagado. */
  regenerate(): void {
    if (this.id) this.apagarIds.push(this.id);
    this.id = null;
    this.data._created = Math.floor(Date.now() / 1000);
    this.dirty = true;
  }

  /** Esvazia a sessão e expira o cookie; a próxima escrita abre uma sessão nova. */
  destroy(): void {
    if (this.id) this.apagarIds.push(this.id);
    this.id = null;
    this.data = {};
    this.dirty = false;
    this.destruida = true;
  }

  /** Grava (se preciso) e devolve o Set-Cookie a anexar na resposta, ou null. */
  async save(): Promise<string | null> {
    const agora = Math.floor(Date.now() / 1000);
    const stmts: D1PreparedStatement[] = [];
    for (const velho of this.apagarIds) {
      stmts.push(this.d1.prepare('DELETE FROM sessions WHERE id = ?1').bind(velho));
    }

    const vazia = Object.keys(this.data).filter((k) => k !== '_created').length === 0;
    if (!this.id && this.dirty && !vazia) {
      this.id = novoId();
      this.data._created ??= agora;
      this.enviarCookie = true;
    }

    if (this.id && (this.dirty || agora - this.updatedAt > TOQUE)) {
      stmts.push(
        this.d1
          .prepare(
            `INSERT INTO sessions (id, user_id, admin_id, data, created_at, updated_at, expires_at)
             VALUES (?1, ?2, ?3, ?4, ?5, ?5, ?6)
             ON CONFLICT(id) DO UPDATE SET user_id = ?2, admin_id = ?3, data = ?4, updated_at = ?5, expires_at = ?6`,
          )
          .bind(
            this.id,
            Number(this.data.user_id ?? 0) || null,
            Number(this.data.admin_id ?? 0) || null,
            JSON.stringify(this.data),
            agora,
            agora + SESSION_TTL,
          ),
      );
    }

    // Coleta de lixo como a do PHP (gc_probability 1/100).
    if (Math.random() < 0.01) {
      stmts.push(this.d1.prepare('DELETE FROM sessions WHERE expires_at < ?1').bind(agora));
    }
    if (stmts.length) await this.d1.batch(stmts);

    const attrs = `Path=/; HttpOnly; SameSite=Lax${this.secure ? '; Secure' : ''}`;
    if (this.id && this.enviarCookie) return `${SESSION_NAME}=${this.id}; ${attrs}`;
    if (this.destruida && !this.id) return `${SESSION_NAME}=; Max-Age=0; ${attrs}`;
    return null;
  }
}
