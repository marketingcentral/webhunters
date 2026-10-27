/**
 * Rotas do lote "admin-operacao" (ver src/routes.ts). Formato igual ao de app/routes.php:
 * [métodos, padrão, ação]. Cada lote só mexe no próprio arquivo.
 *
 * Módulos de operação do painel: jogadores, banimentos, relatórios, transações, servidores,
 * configurações, logs e API. Todos estendem controllers/admin/admin-controller.ts.
 */
import type { RouteRow } from '../core/router';
import { ApiController } from '../controllers/admin/api-controller';
import { BansController } from '../controllers/admin/bans-controller';
import { LogsController } from '../controllers/admin/logs-controller';
import { PlayersController } from '../controllers/admin/players-controller';
import { ReportsController } from '../controllers/admin/reports-controller';
import { ServersController } from '../controllers/admin/servers-controller';
import { SettingsController } from '../controllers/admin/settings-controller';
import { TransactionsController } from '../controllers/admin/transactions-controller';

const jog = new PlayersController();
const ban = new BansController();
const rel = new ReportsController();
const tra = new TransactionsController();
const srv = new ServersController();
const cfg = new SettingsController();
const log = new LogsController();
const api = new ApiController();

export const ROTAS: RouteRow[] = [
  ['GET', '/admin/jogadores', (r, p) => jog.index(r, p)],
  ['GET', '/admin/banimentos', (r, p) => ban.index(r, p)],
  ['GET', '/admin/relatorios', (r, p) => rel.index(r, p)],
  ['GET', '/admin/transacoes', (r, p) => tra.index(r, p)],
  ['GET', '/admin/servidores', (r, p) => srv.index(r, p)],
  ['GET', '/admin/configuracoes', (r, p) => cfg.index(r, p)],
  ['GET', '/admin/logs', (r, p) => log.index(r, p)],

  ['POST', '/admin/jogadores/salvar', (r, p) => jog.salvar(r, p)],
  ['POST', '/admin/jogadores/excluir', (r, p) => jog.excluir(r, p)],
  ['POST', '/admin/servidores/salvar', (r, p) => srv.salvar(r, p)],
  ['POST', '/admin/servidores/excluir', (r, p) => srv.excluir(r, p)],
  ['POST', '/admin/banimentos/salvar', (r, p) => ban.salvar(r, p)],
  ['POST', '/admin/banimentos/excluir', (r, p) => ban.excluir(r, p)],
  ['POST', '/admin/relatorios/salvar', (r, p) => rel.salvar(r, p)],
  ['POST', '/admin/relatorios/excluir', (r, p) => rel.excluir(r, p)],
  ['POST', '/admin/transacoes/salvar', (r, p) => tra.salvar(r, p)],
  ['POST', '/admin/transacoes/excluir', (r, p) => tra.excluir(r, p)],
  ['POST', '/admin/configuracoes/salvar', (r, p) => cfg.salvar(r, p)],
  ['POST', '/admin/configuracoes/excluir', (r, p) => cfg.excluir(r, p)],
  ['POST', '/admin/logs/salvar', (r, p) => log.salvar(r, p)],
  ['POST', '/admin/logs/excluir', (r, p) => log.excluir(r, p)],

  ['GET', '/admin/api', (r, p) => api.index(r, p)],
  ['POST', '/admin/api/criar', (r, p) => api.criar(r, p)],
  ['POST', '/admin/api/revogar', (r, p) => api.revogar(r, p)],
];
