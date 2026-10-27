/**
 * Configuração estática — o App\Core\Config do PHP.
 *
 * No PHP a base da URL vinha de SCRIPT_NAME (o site roda em /, em /extracthunters/ no WAMP e
 * em /unreal/ na produção). O Worker responde sempre na raiz do domínio/rota, então a base é
 * fixa em '/'. `debug` liga quando o host é local, igual ao PHP.
 */
export const APP_NAME = 'Extract Hunters';
export const SESSION_NAME = 'EHSESSID';
export const BASE_URL = '/';
export const SESSION_TTL = 7200; // session.gc_maxlifetime
export const SESSION_ROTATE = 1800; // rotação periódica do id

export function isDebugHost(host: string): boolean {
  return ['localhost', '127.0.0.1', '[::1]', '::1'].includes(host.replace(/:\d+$/, ''));
}
