-- Patch v8 (db/patch_v8_api.php) — API de gestão: chaves e log de requisições.
-- Nenhuma linha destas tabelas vai para o seed (segredo/registro de uso).

CREATE TABLE api_keys (
  id                 INTEGER PRIMARY KEY AUTOINCREMENT,
  name               TEXT    NOT NULL,
  prefix             TEXT    NOT NULL,
  key_hash           TEXT    NOT NULL,             -- CHAR(64): sha256 em hex
  scopes             TEXT    NULL,                 -- JSON
  rate_limit_per_min INTEGER NOT NULL DEFAULT 0,   -- 0 = padrão do código
  note               TEXT    NULL,
  created_by         INTEGER NULL,
  created_at         TEXT    NOT NULL DEFAULT (datetime('now')),
  last_used_at       TEXT    NULL,
  last_ip            TEXT    NULL,
  uses               INTEGER NOT NULL DEFAULT 0,
  expires_at         TEXT    NULL,
  revoked_at         TEXT    NULL,
  revoked_by         INTEGER NULL
);
CREATE UNIQUE INDEX uq_api_keys_prefix ON api_keys (prefix);
CREATE INDEX ix_api_keys_estado ON api_keys (revoked_at, expires_at);

-- Uma linha por requisição (inclusive 401/403/429). Também é o contador do limite por minuto
-- (janela pelos últimos 60 s no índice key_id, created_at).
CREATE TABLE api_request_log (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,   -- BIGINT UNSIGNED no MySQL
  key_id      INTEGER NULL,
  key_prefix  TEXT    NULL,
  method      TEXT    NOT NULL,
  path        TEXT    NOT NULL,                    -- só o caminho, nunca a querystring
  status      INTEGER NOT NULL,
  error_code  TEXT    NULL,
  ip          TEXT    NULL,
  duration_ms INTEGER NOT NULL DEFAULT 0,
  created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX ix_apilog_key_janela ON api_request_log (key_id, created_at);
CREATE INDEX ix_apilog_created ON api_request_log (created_at);
CREATE INDEX ix_apilog_status ON api_request_log (status, created_at);
