/**
 * Rotas do lote "jogador-social" (ver src/routes.ts). Formato igual ao de app/routes.php:
 * [métodos, padrão, ação]. Cada lote só mexe no próprio arquivo.
 */
import type { RouteRow } from '../core/router';
import { FriendsController } from '../controllers/friends-controller';
import { LobbyController } from '../controllers/lobby-controller';
import { MessagesController } from '../controllers/messages-controller';
import { SettingsController } from '../controllers/settings-controller';

const amigos = new FriendsController();
const msgs = new MessagesController();
const lobby = new LobbyController();
const cfg = new SettingsController();

export const ROTAS: RouteRow[] = [
  ['GET', '/lobby', (r, p) => lobby.index(r, p)],
  ['POST', '/lobby/chat', (r, p) => lobby.chat(r, p)],
  [['GET', 'POST'], '/configuracoes[/{aba}]', (r, p) => cfg.index(r, p)],

  ['GET', '/amigos[/{aba}]', (r, p) => amigos.index(r, p)],
  ['POST', '/amigos', (r, p) => amigos.store(r, p)],
  ['GET', '/mensagens[/{conversa}]', (r, p) => msgs.index(r, p)],
  ['POST', '/mensagens/responder', (r, p) => msgs.reply(r, p)],
  ['POST', '/mensagens/lida', (r, p) => msgs.read(r, p)],
];
