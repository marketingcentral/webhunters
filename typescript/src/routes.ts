/**
 * Tabela de rotas — app/routes.php. Mesmo formato: [métodos, padrão, ação].
 * `{param}` casa [a-z0-9-]{1,40}; `[/{param}]` é segmento final opcional.
 * O que não está aqui é 404. As linhas comentadas são rotas do PHP ainda NÃO portadas
 * (ver a lista priorizada no README).
 */
import type { RouteRow } from './core/router';
import { AuthController } from './controllers/auth-controller';
import { BestiaryController } from './controllers/bestiary-controller';
import { HomeController } from './controllers/home-controller';
import { InventoryController } from './controllers/inventory-controller';
import { LandingController } from './controllers/landing-controller';
import { MapController } from './controllers/map-controller';

const auth = new AuthController();
const home = new HomeController();
const inv = new InventoryController();
const mapa = new MapController();
const best = new BestiaryController();
const landing = new LandingController();

export const ROUTES: RouteRow[] = [
  ['GET', '/', (r, p) => landing.index(r, p)],
  [['GET', 'POST'], '/login', (r) => auth.login(r)],
  [['GET', 'POST'], '/criar-conta', (r) => auth.register(r)],
  // [['GET', 'POST'], '/recuperar-senha', AuthController::recover]
  ['POST', '/sair', (r) => auth.logout(r)],

  ['GET', '/inicio', (r, p) => home.index(r, p)],

  ['GET', '/inventario', (r, p) => inv.index(r, p)],
  ['GET', '/inventario/dados', (r, p) => inv.tree(r, p)],
  ['POST', '/inventario/mover', (r, p) => inv.move(r, p)],
  ['POST', '/inventario/equipar', (r, p) => inv.equip(r, p)],
  ['POST', '/inventario/acessorio', (r, p) => inv.attach(r, p)],

  ['GET', '/mapa[/{regiao}]', (r, p) => mapa.index(r, p)],
  ['POST', '/mapa/opacidade', (r, p) => mapa.opacidade(r, p)],

  ['GET', '/bestiario[/{grupo}]', (r, p) => best.index(r, p)],
  ['POST', '/bestiario/registrar', (r, p) => best.registrar(r, p)],

  // Ainda não portadas (app/routes.php): /jogar, /mapas, /lobby (+chat), /configuracoes,
  // /hud (+dados, municao), /saude, /habilidades (+aprender, redefinir), /conquistas,
  // /missoes, /desafios (+resgatar), /noticias, /loja (+comprar), /amigos, /mensagens
  // (+responder, lida), /perfil, /faccoes (+assinar, romper), /base, /safe-zone (+assumir,
  // encerrar), /suporte, /relatorio-de-bug, todo o /admin/* e a API /api/v1.
];
