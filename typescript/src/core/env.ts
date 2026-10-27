/** Bindings declarados em wrangler.toml. */
export interface Env {
  DB: D1Database;
  MEDIA: R2Bucket;
  ASSETS: Fetcher;
  /** "local" | "production" — só muda o detalhe das mensagens de erro. */
  APP_ENV?: string;
  /** "bcrypt" (padrão, compatível com o PHP) | "pbkdf2" (ver README, plano de re-hash). */
  PASSWORD_ALGO?: string;
}
