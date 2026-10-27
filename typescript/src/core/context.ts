import { AsyncLocalStorage } from 'node:async_hooks';
import type { Env } from './env';
import type { AppRequest } from './request';
import type { Session } from './session';

/**
 * Estado de UMA requisição.
 *
 * No PHP, url(), asset(), csrf_field(), flash() e current_user_id() são funções globais que
 * leem $_SESSION/$_SERVER. Para os templates TS poderem chamar os mesmos helpers sem passar
 * contexto de mão em mão, o estado da requisição vive num AsyncLocalStorage — cada
 * requisição tem o seu, mesmo com várias rodando no mesmo isolate.
 */
export interface Ctx {
  env: Env;
  req: AppRequest;
  session: Session;
  nonce: string;
  debug: boolean;
  /** Cache por requisição (o `static $cache` dos helpers PHP). */
  cache: Map<string, unknown>;
}

const als = new AsyncLocalStorage<Ctx>();

export function runWithCtx<T>(c: Ctx, fn: () => Promise<T>): Promise<T> {
  return als.run(c, fn);
}

export function ctx(): Ctx {
  const c = als.getStore();
  if (!c) throw new Error('Fora de uma requisição: ctx() indisponível');
  return c;
}

/** O `static $cache` dos helpers: memoiza por requisição. */
export async function memo<T>(chave: string, fn: () => Promise<T>): Promise<T> {
  const c = ctx().cache;
  if (c.has(chave)) return c.get(chave) as T;
  const v = await fn();
  c.set(chave, v);
  return v;
}
