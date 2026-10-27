/**
 * Rotas do lote "jogador-suporte-hud" (ver src/routes.ts). Formato igual ao de app/routes.php:
 * [métodos, padrão, ação]. Cada lote só mexe no próprio arquivo.
 */
import { HudController } from '../controllers/hud-controller';
import { RecoverController } from '../controllers/recover-controller';
import { SupportController } from '../controllers/support-controller';
import type { RouteRow } from '../core/router';

const recover = new RecoverController();
const hud = new HudController();
const sup = new SupportController();

export const ROTAS: RouteRow[] = [
  [['GET', 'POST'], '/recuperar-senha', (r, p) => recover.recover(r, p)],

  ['GET', '/hud', (r, p) => hud.index(r, p)],
  ['GET', '/hud/dados', (r, p) => hud.data(r, p)],
  ['POST', '/hud/municao', (r, p) => hud.ammo(r, p)],

  [['GET', 'POST'], '/suporte', (r, p) => sup.index(r, p)],
  ['GET', '/relatorio-de-bug/{code}', (r, p) => sup.bugDetail(r, p)],
  [['GET', 'POST'], '/relatorio-de-bug', (r, p) => sup.bug(r, p)],
];
