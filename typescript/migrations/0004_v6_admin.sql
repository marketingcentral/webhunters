-- Patch v6 (db/patch_v6_admin.php) — PAINEL ADMINISTRATIVO: só a ESTRUTURA.
-- admin_settings (conteúdo) vem no seed; admin_metrics_daily (semente de demonstração) não.
-- Sem FOREIGN KEY em bans/admin_audit_log de propósito (igual ao MySQL): a prova sobrevive
-- à remoção da conta punida ou do admin.

ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'player';     -- player | admin | owner
ALTER TABLE users ADD COLUMN status TEXT NOT NULL DEFAULT 'active';   -- active | banned | suspended
ALTER TABLE users ADD COLUMN last_ip TEXT NULL;                       -- até 45 chars (IPv6)

CREATE TABLE bans (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL,
  admin_id   INTEGER NULL,
  reason     TEXT    NULL,
  kind       TEXT    NOT NULL DEFAULT 'temp',      -- temp | perma
  expires_at TEXT    NULL,
  created_at TEXT    NOT NULL DEFAULT (datetime('now')),
  revoked_at TEXT    NULL,
  revoked_by INTEGER NULL
);
CREATE INDEX ix_bans_user ON bans (user_id, created_at);
CREATE INDEX ix_bans_admin ON bans (admin_id);

CREATE TABLE admin_audit_log (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,    -- BIGINT UNSIGNED no MySQL (INTEGER do SQLite é 64 bits)
  admin_id   INTEGER NULL,
  "action"   TEXT    NOT NULL,                     -- ex.: user.ban, settings.update
  entity     TEXT    NOT NULL,
  entity_id  TEXT    NULL,
  summary    TEXT    NOT NULL DEFAULT '',
  meta       TEXT    NULL,                         -- JSON
  ip         TEXT    NULL,
  created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX ix_audit_admin ON admin_audit_log (admin_id, created_at);
CREATE INDEX ix_audit_created ON admin_audit_log (created_at);

-- Parâmetros editáveis sem deploy. "key" é palavra reservada: sempre entre aspas.
CREATE TABLE admin_settings (
  "key"      TEXT    NOT NULL PRIMARY KEY,
  value      TEXT    NULL,
  kind       TEXT    NOT NULL DEFAULT 'string',    -- string | int | bool | json
  label      TEXT    NOT NULL DEFAULT '',
  grupo      TEXT    NOT NULL DEFAULT 'geral',
  updated_at TEXT    NOT NULL DEFAULT (datetime('now')),  -- MySQL: ON UPDATE CURRENT_TIMESTAMP
  updated_by INTEGER NULL
);
CREATE INDEX ix_settings_grupo ON admin_settings (grupo);
CREATE TRIGGER trg_admin_settings_updated_at AFTER UPDATE ON admin_settings
FOR EACH ROW WHEN NEW.updated_at IS OLD.updated_at
BEGIN
  UPDATE admin_settings SET updated_at = datetime('now') WHERE rowid = NEW.rowid;
END;

CREATE TABLE admin_metrics_daily (
  day             TEXT    NOT NULL PRIMARY KEY,    -- DATE 'YYYY-MM-DD'
  players_online  INTEGER NOT NULL DEFAULT 0,
  new_players     INTEGER NOT NULL DEFAULT 0,
  sessions        INTEGER NOT NULL DEFAULT 0,
  revenue_cents   INTEGER NOT NULL DEFAULT 0,
  avg_session_sec INTEGER NOT NULL DEFAULT 0
);
