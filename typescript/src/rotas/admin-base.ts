/**
 * Rotas do lote "admin-base" (ver src/routes.ts). Formato igual ao de app/routes.php:
 * [métodos, padrão, ação]. Cada lote só mexe no próprio arquivo.
 *
 * Porta do painel (login/sair) e o dashboard. Os módulos /admin/* ficam nos lotes
 * admin-conteudo e admin-operacao, estendendo controllers/admin/admin-controller.ts.
 */
import type { RouteRow } from '../core/router';
import { AdminAuthController } from '../controllers/admin/auth-controller';
import { DashboardController } from '../controllers/admin/dashboard-controller';

const auth = new AdminAuthController();
const dash = new DashboardController();

export const ROTAS: RouteRow[] = [
  [['GET', 'POST'], '/admin/login', (r, p) => auth.login(r, p)],
  ['POST', '/admin/sair', (r, p) => auth.logout(r, p)],
  ['GET', '/admin', (r, p) => dash.index(r, p)],
];
