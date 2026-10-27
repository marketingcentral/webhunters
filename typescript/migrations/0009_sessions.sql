-- Sessões do Worker (substituem as sessões em arquivo do PHP). Não existe no MySQL.
-- Timestamps em SEGUNDOS Unix. `data` é o JSON da sessão (o $_SESSION do PHP).
-- Sem FK em user_id/admin_id: a limpeza é por expires_at (e por user_id ao banir/sair).

CREATE TABLE sessions (
  id         TEXT    NOT NULL PRIMARY KEY,
  user_id    INTEGER,
  admin_id   INTEGER,
  data       TEXT    NOT NULL DEFAULT '{}',
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL,
  expires_at INTEGER NOT NULL
);
CREATE INDEX ix_sessions_expires ON sessions (expires_at);
CREATE INDEX ix_sessions_user ON sessions (user_id);
