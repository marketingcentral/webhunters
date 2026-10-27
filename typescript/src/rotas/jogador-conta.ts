/**
 * Rotas do lote "jogador-conta" (ver src/routes.ts). Formato igual ao de app/routes.php:
 * [métodos, padrão, ação]. Cada lote só mexe no próprio arquivo.
 * Telas: /saude, /habilidades (+ aprender/redefinir), /conquistas, /perfil.
 */
import type { RouteRow } from '../core/router';
import { AchievementsController } from '../controllers/achievements-controller';
import { HealthController } from '../controllers/health-controller';
import { ProfileController } from '../controllers/profile-controller';
import { SkillsController } from '../controllers/skills-controller';

const saude = new HealthController();
const hab = new SkillsController();
const conq = new AchievementsController();
const perfil = new ProfileController();

export const ROTAS: RouteRow[] = [
  ['GET', '/saude', (r, p) => saude.index(r, p)],
  ['POST', '/habilidades/aprender', (r, p) => hab.learn(r, p)],
  ['POST', '/habilidades/redefinir', (r, p) => hab.reset(r, p)],
  ['GET', '/habilidades[/{categoria}]', (r, p) => hab.index(r, p)],
  ['GET', '/conquistas[/{categoria}]', (r, p) => conq.index(r, p)],
  ['GET', '/perfil', (r, p) => perfil.index(r, p)],
];
