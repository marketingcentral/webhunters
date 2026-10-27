/**
 * Tabela de rotas — app/routes.php. Mesmo formato: [métodos, padrão, ação].
 * `{param}` casa [a-z0-9-]{1,40}; `[/{param}]` é segmento final opcional.
 * O que não está aqui é 404. As linhas comentadas são rotas do PHP ainda NÃO portadas
 * (ver a lista priorizada no README).
 */
import type { RouteRow } from './core/router';
import { ROTAS as jogadorConta } from './rotas/jogador-conta';
import { ROTAS as jogadorProgresso } from './rotas/jogador-progresso';
import { ROTAS as jogadorSocial } from './rotas/jogador-social';
import { ROTAS as jogadorMundo } from './rotas/jogador-mundo';
import { ROTAS as jogadorSuporteHud } from './rotas/jogador-suporte-hud';
import { ROTAS as adminBase } from './rotas/admin-base';
import { ROTAS as adminConteudo } from './rotas/admin-conteudo';
import { ROTAS as adminOperacao } from './rotas/admin-operacao';
import { ROTAS as api } from './rotas/api';
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

  // Demais telas, painel e API: um arquivo por lote em src/rotas/.
  ...jogadorConta,
  ...jogadorProgresso,
  ...jogadorSocial,
  ...jogadorMundo,
  ...jogadorSuporteHud,
  ...adminBase,
  ...adminConteudo,
  ...adminOperacao,
  ...api,
];
