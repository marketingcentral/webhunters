/**
 * Rotas do lote "jogador-progresso" (ver src/routes.ts). Formato igual ao de app/routes.php:
 * [métodos, padrão, ação]. Cada lote só mexe no próprio arquivo.
 */
import type { RouteRow } from '../core/router';
import { ChallengesController } from '../controllers/challenges-controller';
import { MissionsController } from '../controllers/missions-controller';
import { NewsController } from '../controllers/news-controller';
import { ShopController } from '../controllers/shop-controller';

const missoes = new MissionsController();
const desafios = new ChallengesController();
const noticias = new NewsController();
const loja = new ShopController();

export const ROTAS: RouteRow[] = [
  ['GET', '/missoes', (r, p) => missoes.index(r, p)],
  ['POST', '/desafios/resgatar', (r, p) => desafios.claim(r, p)],
  ['GET', '/desafios[/{aba}]', (r, p) => desafios.index(r, p)],
  ['GET', '/noticias', (r, p) => noticias.index(r, p)],
  ['GET', '/noticias/{slug}', (r, p) => noticias.show(r, p)],
  ['GET', '/loja', (r, p) => loja.index(r, p)],
  ['POST', '/loja/comprar', (r, p) => loja.buy(r, p)],
];
