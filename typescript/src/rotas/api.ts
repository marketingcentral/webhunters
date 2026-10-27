/**
 * Rotas do lote "api" (ver src/routes.ts). Formato igual ao de app/routes.php:
 * [métodos, padrão, ação]. Cada lote só mexe no próprio arquivo.
 *
 * API DE GESTÃO — /api/v1 (patch v8). QUATRO LINHAS (três padrões) PARA A API INTEIRA: tudo
 * sob /api/v1 é do despachante (src/api/api-controller.ts), que decide recurso, rota, escopo
 * e responde 404/405 em JSON. Os padrões cobrem de 1 a 4 segmentos depois de /api/v1 (o mais
 * fundo em uso é /api/v1/maps/{id}/markers/{id}).
 *
 * SEM SESSÃO E SEM CSRF nesta porta: a credencial é o cabeçalho Authorization (o src/index.ts
 * nem lê o cookie de sessão nessas rotas).
 */
import { ApiController } from '../api/api-controller';
import type { RouteRow } from '../core/router';

const api = new ApiController();
const METODOS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

export const ROTAS: RouteRow[] = [
  [METODOS, '/api/v1', (r, p) => api.dispatch(r, p)],
  [METODOS, '/api/v1/{a}[/{b}]', (r, p) => api.dispatch(r, p)],
  [METODOS, '/api/v1/{a}/{b}/{c}[/{d}]', (r, p) => api.dispatch(r, p)],
];
