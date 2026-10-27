/**
 * Rotas do lote "admin-conteudo" (ver src/routes.ts). Formato igual ao de app/routes.php:
 * [métodos, padrão, ação]. Cada lote só mexe no próprio arquivo.
 *
 * Módulos de conteúdo do painel: missões, conquistas, mapas (+ pontos e editor), itens,
 * loja, eventos e o upload de imagem (R2). Todos estendem controllers/admin/admin-controller.ts.
 * Mesmos padrões e mesma ordem de app/routes.php.
 */
import type { RouteRow } from '../core/router';
import { AdminAchievementsController } from '../controllers/admin/achievements-controller';
import { AdminEventsController } from '../controllers/admin/events-controller';
import { AdminItemsController } from '../controllers/admin/items-controller';
import { AdminMapEditorController } from '../controllers/admin/map-editor-controller';
import { AdminMapPointsController } from '../controllers/admin/map-points-controller';
import { AdminMapsController } from '../controllers/admin/maps-controller';
import { AdminMissionsController } from '../controllers/admin/missions-controller';
import { AdminShopController } from '../controllers/admin/shop-controller';
import { AdminUploadController } from '../controllers/admin/upload-controller';

const missoes = new AdminMissionsController();
const conquistas = new AdminAchievementsController();
const mapasAdm = new AdminMapsController();
const pontosMapa = new AdminMapPointsController();
const editorMapa = new AdminMapEditorController();
const itensAdm = new AdminItemsController();
const eventosAdm = new AdminEventsController();
const lojaAdm = new AdminShopController();
const upload = new AdminUploadController();

export const ROTAS: RouteRow[] = [
  ['GET', '/admin/missoes', (r, p) => missoes.index(r, p)],
  ['GET', '/admin/conquistas', (r, p) => conquistas.index(r, p)],
  ['GET', '/admin/mapas', (r, p) => mapasAdm.index(r, p)],
  ['GET', '/admin/mapas/pontos', (r, p) => pontosMapa.index(r, p)],
  ['GET', '/admin/mapas/editor', (r, p) => editorMapa.index(r, p)],
  ['GET', '/admin/itens', (r, p) => itensAdm.index(r, p)],
  ['GET', '/admin/eventos', (r, p) => eventosAdm.index(r, p)],
  ['GET', '/admin/loja', (r, p) => lojaAdm.index(r, p)],

  ['POST', '/admin/missoes/salvar', (r, p) => missoes.salvar(r, p)],
  ['POST', '/admin/missoes/excluir', (r, p) => missoes.excluir(r, p)],
  ['POST', '/admin/conquistas/salvar', (r, p) => conquistas.salvar(r, p)],
  ['POST', '/admin/conquistas/excluir', (r, p) => conquistas.excluir(r, p)],
  ['POST', '/admin/mapas/salvar', (r, p) => mapasAdm.salvar(r, p)],
  ['POST', '/admin/mapas/excluir', (r, p) => mapasAdm.excluir(r, p)],
  ['POST', '/admin/mapas/pontos/salvar', (r, p) => pontosMapa.salvar(r, p)],
  ['POST', '/admin/mapas/pontos/excluir', (r, p) => pontosMapa.excluir(r, p)],
  ['POST', '/admin/mapas/editor/salvar', (r, p) => editorMapa.salvar(r, p)],
  ['POST', '/admin/itens/salvar', (r, p) => itensAdm.salvar(r, p)],
  ['POST', '/admin/itens/excluir', (r, p) => itensAdm.excluir(r, p)],
  ['POST', '/admin/eventos/salvar', (r, p) => eventosAdm.salvar(r, p)],
  ['POST', '/admin/eventos/excluir', (r, p) => eventosAdm.excluir(r, p)],
  ['POST', '/admin/loja/salvar', (r, p) => lojaAdm.salvar(r, p)],
  ['POST', '/admin/loja/excluir', (r, p) => lojaAdm.excluir(r, p)],

  // Imagem do painel: JSON, grava no R2 em media/uploads/<entidade>/ (assets/js/admin/upload.js).
  ['POST', '/admin/upload', (r, p) => upload.enviar(r, p)],
];
