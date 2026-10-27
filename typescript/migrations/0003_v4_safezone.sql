-- Patch v4 (db/patch_v4_safezone.php) — SAFE ZONE: só a ESTRUTURA.
-- As zonas e os marcadores vêm em seed/conteudo.sql.
-- O patch v5 (db/patch_v5_mapa_mundo.php) não mexe em estrutura (só recria marcadores do mapa 1
-- e acrescenta uma zona): não tem migration própria, o resultado dele está no seed.

CREATE TABLE safe_zones (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  slug        TEXT    NOT NULL,
  name        TEXT    NOT NULL,
  kind        TEXT    NOT NULL DEFAULT 'gov',      -- gov | free
  base_slug   TEXT    NULL,                        -- government_bases.slug (sem FK, como no MySQL)
  map_id      INTEGER NULL,                        -- sem FK, como no MySQL
  region      TEXT    NULL,
  status      TEXT    NOT NULL DEFAULT 'ABERTA',
  occupancy   TEXT    NOT NULL DEFAULT 'MODERADA',
  capacity    INTEGER NOT NULL DEFAULT 24,
  protection  TEXT    NULL,
  description TEXT    NULL,
  meta        TEXT    NULL,                        -- JSON: services[], rules[]
  sort_order  INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX uk_safe_zones_slug ON safe_zones (slug);

-- Papel civil do jogador na zona: um por vez (UNIQUE em user_id). Sem FK, como no MySQL.
CREATE TABLE player_safe_roles (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id       INTEGER NOT NULL,
  zone_slug     TEXT    NOT NULL,
  role          TEXT    NOT NULL,                  -- GameRules::SAFE_ROLES
  status        TEXT    NOT NULL DEFAULT 'ATIVO',
  started_at    TEXT    NOT NULL,
  shift_ends_at TEXT    NULL,
  served        INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX uk_safe_role_user ON player_safe_roles (user_id);
CREATE INDEX ix_safe_role_zone ON player_safe_roles (zone_slug);
