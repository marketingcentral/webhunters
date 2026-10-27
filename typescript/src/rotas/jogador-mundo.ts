/**
 * Rotas do lote "jogador-mundo" (ver src/routes.ts). Formato igual ao de app/routes.php:
 * [métodos, padrão, ação]. Cada lote só mexe no próprio arquivo.
 *
 * Operação (/jogar), regiões (/mapas), facções, base de campo e Safe Zone (PRD v3).
 */
import { BaseController } from '../controllers/base-controller';
import { FactionsController } from '../controllers/factions-controller';
import { PlayController } from '../controllers/play-controller';
import { SafeZoneController } from '../controllers/safe-zone-controller';
import type { RouteRow } from '../core/router';

const play = new PlayController();
const fac = new FactionsController();
const base = new BaseController();
const sz = new SafeZoneController();

export const ROTAS: RouteRow[] = [
  ['GET', '/jogar', (r, p) => play.index(r, p)],
  ['GET', '/mapas', (r, p) => play.maps(r, p)],

  ['GET', '/faccoes', (r, p) => fac.index(r, p)],
  ['POST', '/faccoes/assinar', (r, p) => fac.sign(r, p)],
  ['POST', '/faccoes/romper', (r, p) => fac.release(r, p)],
  ['GET', '/base', (r, p) => base.index(r, p)],
  ['POST', '/safe-zone/assumir', (r, p) => sz.assume(r, p)],
  ['POST', '/safe-zone/encerrar', (r, p) => sz.leave(r, p)],
  ['GET', '/safe-zone[/{zona}]', (r, p) => sz.index(r, p)],
];
