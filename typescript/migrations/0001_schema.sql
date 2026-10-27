-- Extract Hunters — esquema BASE (equivalente ao db/schema.sql ANTES dos patches v3+).
-- Convertido de MySQL 8 (InnoDB, utf8mb4) para SQLite/D1. Regras de conversão:
--   INT/BIGINT AUTO_INCREMENT  -> INTEGER PRIMARY KEY AUTOINCREMENT (id nunca é reaproveitado, como no InnoDB)
--   VARCHAR(n)/CHAR/TEXT       -> TEXT (SQLite não limita tamanho: a validação de comprimento fica no código)
--   JSON                       -> TEXT (JSON em texto; use json_extract()/json_set() do SQLite)
--   DATETIME/TIMESTAMP         -> TEXT 'YYYY-MM-DD HH:MM:SS'; DEFAULT CURRENT_TIMESTAMP -> DEFAULT (datetime('now'))
--                                 ATENÇÃO: datetime('now') é UTC; o MySQL grava no fuso da sessão.
--   DATE                       -> TEXT 'YYYY-MM-DD'
--   TINYINT(1)/TINYINT/SMALLINT -> INTEGER;  UNSIGNED some (sem CHECK >= 0)
--   DECIMAL                    -> REAL
--   ON UPDATE CURRENT_TIMESTAMP -> não existe em SQLite: trigger AFTER UPDATE trg_<tabela>_updated_at
--                                 (só regrava quando o UPDATE não mexeu na coluna, como no MySQL)
--   KEY/UNIQUE KEY             -> CREATE [UNIQUE] INDEX com o MESMO nome do MySQL. Inclui os índices
--                                 que o InnoDB cria sozinho para FK (ex.: fk_ar_item), para os
--                                 ON DELETE CASCADE/SET NULL não varrerem a tabela inteira.
-- Login sem diferença de maiúsculas: users.username e users.email são COLLATE NOCASE.
--
-- Diferença estrutural escrita já na forma FINAL:
--   system_notices.user_id nasce NULL-ável (no MySQL foi o patch v7_eventos que fez o MODIFY).
--   Em SQLite mudar NOT NULL exige recriar a tabela, e recriar tabela com FK no D1 é arriscado;
--   então a coluna já nasce como o MySQL está hoje. NULL = aviso GLOBAL (não é de um jogador).

-- ============================================================================
-- CATÁLOGO
-- ============================================================================

-- Catálogo mestre de itens. Todo objeto físico do jogo é uma linha aqui.
CREATE TABLE items (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  slug           TEXT    NOT NULL,
  name           TEXT    NOT NULL,
  short_name     TEXT    NULL,
  category       TEXT    NOT NULL,                 -- weapon,pistol,knife,helmet,armor,rig,backpack,...
  subtype        TEXT    NULL,
  slot_type      TEXT    NULL,                     -- slot de equipamento em que cabe
  grid_w         INTEGER NOT NULL DEFAULT 1,
  grid_h         INTEGER NOT NULL DEFAULT 1,
  weight_kg      REAL    NOT NULL DEFAULT 0,       -- DECIMAL(6,3) no MySQL
  rarity         TEXT    NOT NULL DEFAULT 'comum',
  image          TEXT    NOT NULL,                 -- caminho relativo em /media
  description    TEXT    NULL,
  base_price     INTEGER NOT NULL DEFAULT 0,
  currency       TEXT    NOT NULL DEFAULT 'credits',
  stackable      INTEGER NOT NULL DEFAULT 0,
  max_stack      INTEGER NOT NULL DEFAULT 1,
  durability_max INTEGER NULL,
  grid_cols      INTEGER NULL,
  grid_rows      INTEGER NULL,
  ammo_caliber   TEXT    NULL,
  mag_capacity   INTEGER NULL,
  attach_slots   TEXT    NULL,                     -- JSON
  fits_calibers  TEXT    NULL,                     -- JSON
  meta           TEXT    NULL                      -- JSON
);
CREATE UNIQUE INDEX uq_items_slug ON items (slug);
CREATE INDEX idx_items_category ON items (category);
CREATE INDEX idx_items_slot ON items (slot_type);

-- Slots de equipamento do personagem (definição + o que aceitam).
CREATE TABLE equipment_slots (
  slot_key   TEXT    NOT NULL PRIMARY KEY,
  label      TEXT    NOT NULL,
  accepts    TEXT    NOT NULL,                     -- JSON: categorias aceitas
  sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE maps (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  slug         TEXT    NOT NULL,
  name         TEXT    NOT NULL,
  kind         TEXT    NOT NULL DEFAULT 'match',   -- region | match
  mode         TEXT    NULL,
  players      TEXT    NULL,
  level_min    INTEGER NULL,
  level_max    INTEGER NULL,
  locked       INTEGER NOT NULL DEFAULT 0,
  image        TEXT    NULL,
  description  TEXT    NULL,
  unreal_level TEXT    NULL,
  meta         TEXT    NULL,                       -- JSON
  sort_order   INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX uq_maps_slug ON maps (slug);
CREATE INDEX idx_maps_kind ON maps (kind);

CREATE TABLE map_markers (
  id     INTEGER PRIMARY KEY AUTOINCREMENT,
  map_id INTEGER NOT NULL,
  kind   TEXT    NOT NULL,
  label  TEXT    NULL,
  x      REAL    NOT NULL,                         -- DECIMAL(6,4): 0..1 na imagem
  y      REAL    NOT NULL,
  meta   TEXT    NULL,                             -- JSON
  CONSTRAINT fk_marker_map FOREIGN KEY (map_id) REFERENCES maps (id) ON DELETE CASCADE
);
CREATE INDEX idx_marker_map ON map_markers (map_id);

-- (as colunas de mundo persistente entram na 0002, como no patch v3)
CREATE TABLE missions (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  slug         TEXT    NOT NULL,
  name         TEXT    NOT NULL,
  giver        TEXT    NULL,
  region       TEXT    NULL,
  type         TEXT    NOT NULL DEFAULT 'contrato',
  difficulty   TEXT    NULL,
  duration_min INTEGER NULL,
  players_max  INTEGER NULL,
  level_req    INTEGER NULL,
  objective    TEXT    NULL,
  target       INTEGER NULL,
  image        TEXT    NULL,
  locked       INTEGER NOT NULL DEFAULT 0,
  sort_order   INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX uq_missions_slug ON missions (slug);

CREATE TABLE mission_rewards (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  mission_id INTEGER NOT NULL,
  kind       TEXT    NOT NULL,                     -- xp,credits,cash,points,item,...
  amount     INTEGER NOT NULL DEFAULT 0,
  item_id    INTEGER NULL,
  CONSTRAINT fk_mr_mission FOREIGN KEY (mission_id) REFERENCES missions (id) ON DELETE CASCADE,
  CONSTRAINT fk_mr_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE SET NULL
);
CREATE INDEX idx_mr_mission ON mission_rewards (mission_id);
CREATE INDEX fk_mr_item ON mission_rewards (item_id);

CREATE TABLE achievements (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  slug        TEXT    NOT NULL,
  name        TEXT    NOT NULL,
  category    TEXT    NOT NULL DEFAULT 'combate',
  description TEXT    NULL,
  icon        TEXT    NULL,
  target      INTEGER NOT NULL DEFAULT 1,
  sort_order  INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX uq_ach_slug ON achievements (slug);
CREATE INDEX idx_ach_cat ON achievements (category);

CREATE TABLE achievement_rewards (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  achievement_id INTEGER NOT NULL,
  kind           TEXT    NOT NULL,
  amount         INTEGER NOT NULL DEFAULT 0,
  item_id        INTEGER NULL,
  CONSTRAINT fk_ar_ach FOREIGN KEY (achievement_id) REFERENCES achievements (id) ON DELETE CASCADE,
  CONSTRAINT fk_ar_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE SET NULL
);
CREATE INDEX idx_ar_ach ON achievement_rewards (achievement_id);
CREATE INDEX fk_ar_item ON achievement_rewards (item_id);

CREATE TABLE shop_offers (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  item_id    INTEGER NOT NULL,
  category   TEXT    NOT NULL,
  price      INTEGER NOT NULL,
  currency   TEXT    NOT NULL DEFAULT 'credits',
  discount   INTEGER NULL,                         -- % de desconto
  tag        TEXT    NULL,
  featured   INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0,
  CONSTRAINT fk_offer_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE
);
CREATE INDEX idx_offer_cat ON shop_offers (category);
CREATE INDEX fk_offer_item ON shop_offers (item_id);

CREATE TABLE skills (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  slug        TEXT    NOT NULL,
  category    TEXT    NOT NULL,
  tier        INTEGER NOT NULL DEFAULT 1,
  col         INTEGER NOT NULL DEFAULT 1,
  name        TEXT    NOT NULL,
  icon        TEXT    NULL,
  max_level   INTEGER NOT NULL DEFAULT 1,
  cost        INTEGER NOT NULL DEFAULT 0,
  description TEXT    NULL,
  image       TEXT    NULL,
  step        REAL    NULL,                        -- DECIMAL(7,2): magnitude do efeito por nível
  unit        TEXT    NULL,
  effect      TEXT    NULL
);
CREATE UNIQUE INDEX uq_skill_slug ON skills (slug);
CREATE INDEX idx_skill_cat ON skills (category);

CREATE TABLE news (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  slug       TEXT    NOT NULL,
  title      TEXT    NOT NULL,
  category   TEXT    NULL,
  excerpt    TEXT    NULL,
  body       TEXT    NULL,
  image      TEXT    NULL,
  published  TEXT    NULL,                         -- DATE 'YYYY-MM-DD'
  sort_order INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX uq_news_slug ON news (slug);

-- ----------------------------------------------------------------------------
-- TEMPORADAS E DESAFIOS
-- ----------------------------------------------------------------------------

CREATE TABLE seasons (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  slug          TEXT    NOT NULL,
  number        INTEGER NOT NULL DEFAULT 1,
  tag           TEXT    NOT NULL,
  pass_name     TEXT    NOT NULL,
  pass_image    TEXT    NULL,
  xp_per_level  INTEGER NOT NULL DEFAULT 10000,
  event_tag     TEXT    NULL,
  event_title   TEXT    NULL,
  event_desc    TEXT    NULL,
  event_image   TEXT    NULL,
  event_ends_at TEXT    NOT NULL,
  starts_at     TEXT    NOT NULL,
  ends_at       TEXT    NOT NULL,
  is_current    INTEGER NOT NULL DEFAULT 1
);
CREATE UNIQUE INDEX uq_season_slug ON seasons (slug);
CREATE INDEX idx_season_current ON seasons (is_current);

CREATE TABLE season_tiers (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  season_id  INTEGER NOT NULL,
  level      INTEGER NOT NULL,
  name       TEXT    NOT NULL,
  image      TEXT    NULL,
  rarity     TEXT    NULL,
  item_id    INTEGER NULL,
  sort_order INTEGER NOT NULL DEFAULT 0,
  CONSTRAINT fk_tier_season FOREIGN KEY (season_id) REFERENCES seasons (id) ON DELETE CASCADE,
  CONSTRAINT fk_tier_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE SET NULL
);
CREATE INDEX idx_tier_season ON season_tiers (season_id);
CREATE INDEX fk_tier_item ON season_tiers (item_id);

CREATE TABLE challenges (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  slug        TEXT    NOT NULL,
  scope       TEXT    NOT NULL DEFAULT 'diarios',  -- diarios | semanais | temporada
  season_id   INTEGER NULL,
  label       TEXT    NOT NULL,
  description TEXT    NULL,
  icon        TEXT    NULL,
  target      INTEGER NOT NULL DEFAULT 1,
  xp          INTEGER NOT NULL DEFAULT 0,
  credits     INTEGER NOT NULL DEFAULT 0,
  highlight   INTEGER NOT NULL DEFAULT 0,
  sort_order  INTEGER NOT NULL DEFAULT 0,
  CONSTRAINT fk_challenge_season FOREIGN KEY (season_id) REFERENCES seasons (id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX uq_challenge_slug ON challenges (slug);
CREATE INDEX idx_challenge_scope ON challenges (scope, sort_order);
CREATE INDEX fk_challenge_season ON challenges (season_id);

CREATE TABLE challenge_bonuses (
  scope       TEXT    NOT NULL PRIMARY KEY,
  label       TEXT    NOT NULL,
  title       TEXT    NOT NULL,
  description TEXT    NULL,
  image       TEXT    NULL,
  xp          INTEGER NOT NULL DEFAULT 0,
  credits     INTEGER NOT NULL DEFAULT 0
);

-- ============================================================================
-- USUÁRIOS E ESTADO POR JOGADOR
-- ============================================================================

-- (role/status/last_ip entram na 0004, como no patch v6)
CREATE TABLE users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  username      TEXT    NOT NULL COLLATE NOCASE,   -- MySQL utf8mb4_0900_ai_ci: login sem diferença de maiúsculas
  email         TEXT    NULL COLLATE NOCASE,
  password_hash TEXT    NOT NULL,
  created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
  last_login    TEXT    NULL
);
-- O código distingue o erro de e-mail duplicado pelo NOME deste índice.
CREATE UNIQUE INDEX uq_users_username ON users (username);
CREATE UNIQUE INDEX uq_users_email ON users (email);

-- (colunas de estado operacional entram na 0002, como no patch v3)
CREATE TABLE players (
  user_id      INTEGER NOT NULL PRIMARY KEY,
  display_name TEXT    NOT NULL,
  level        INTEGER NOT NULL DEFAULT 0,
  xp           INTEGER NOT NULL DEFAULT 0,
  xp_next      INTEGER NOT NULL DEFAULT 1000,
  credits      INTEGER NOT NULL DEFAULT 0,
  cash         INTEGER NOT NULL DEFAULT 0,
  points       INTEGER NOT NULL DEFAULT 0,
  clan         TEXT    NULL,
  class        TEXT    NULL,
  class_icon   TEXT    NULL,
  avatar       TEXT    NULL,
  player_rank  TEXT    NULL,
  season_level INTEGER NOT NULL DEFAULT 0,
  skill_points INTEGER NOT NULL DEFAULT 0,
  health       TEXT    NULL,                       -- JSON
  survival     TEXT    NULL,                       -- JSON
  CONSTRAINT fk_player_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE player_items (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id        INTEGER NOT NULL,
  item_id        INTEGER NOT NULL,
  container      TEXT    NOT NULL DEFAULT 'stash', -- stash | pockets | equip | grid | attach
  slot_key       TEXT    NULL,
  parent_id      INTEGER NULL,
  pos_x          INTEGER NULL,
  pos_y          INTEGER NULL,
  rotated        INTEGER NOT NULL DEFAULT 0,
  quantity       INTEGER NOT NULL DEFAULT 1,
  durability_cur INTEGER NULL,
  ammo_cur       INTEGER NULL,
  ammo_item_id   INTEGER NULL,
  acquired_via   TEXT    NOT NULL DEFAULT 'partida',
  acquired_at    TEXT    NOT NULL DEFAULT (datetime('now')),
  CONSTRAINT fk_pi_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_pi_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE,
  CONSTRAINT fk_pi_parent FOREIGN KEY (parent_id) REFERENCES player_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_pi_ammo FOREIGN KEY (ammo_item_id) REFERENCES items (id) ON DELETE SET NULL
);
CREATE INDEX idx_pi_user ON player_items (user_id);
CREATE INDEX idx_pi_parent ON player_items (parent_id);
CREATE INDEX idx_pi_container ON player_items (user_id, container);
CREATE INDEX fk_pi_item ON player_items (item_id);
CREATE INDEX fk_pi_ammo ON player_items (ammo_item_id);

CREATE TABLE player_purchases (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id   INTEGER NOT NULL,
  item_id   INTEGER NOT NULL,
  quantity  INTEGER NOT NULL DEFAULT 1,
  currency  TEXT    NOT NULL,
  price     INTEGER NOT NULL,
  bought_at TEXT    NOT NULL DEFAULT (datetime('now')),
  CONSTRAINT fk_pp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE
);
CREATE INDEX idx_pp_user ON player_purchases (user_id);
CREATE INDEX fk_pp_item ON player_purchases (item_id);

CREATE TABLE player_settings (
  user_id     INTEGER NOT NULL,
  setting_key TEXT    NOT NULL,
  value       TEXT    NOT NULL,
  updated_at  TEXT    NOT NULL DEFAULT (datetime('now')),  -- MySQL: ON UPDATE CURRENT_TIMESTAMP (trigger abaixo)
  PRIMARY KEY (user_id, setting_key),
  CONSTRAINT fk_pset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
CREATE INDEX idx_pset_user ON player_settings (user_id);
CREATE TRIGGER trg_player_settings_updated_at AFTER UPDATE ON player_settings
FOR EACH ROW WHEN NEW.updated_at IS OLD.updated_at
BEGIN
  UPDATE player_settings SET updated_at = datetime('now') WHERE rowid = NEW.rowid;
END;

CREATE TABLE player_bug_reports (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id     INTEGER NOT NULL,
  code        TEXT    NOT NULL,
  title       TEXT    NOT NULL,
  area        TEXT    NOT NULL,
  severity    TEXT    NOT NULL,
  description TEXT    NULL,
  steps       TEXT    NULL,
  attach_logs INTEGER NOT NULL DEFAULT 1,
  status      TEXT    NOT NULL DEFAULT 'recebido',
  created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
  closed_at   TEXT    NULL,
  CONSTRAINT fk_bug_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX uq_bug_user_code ON player_bug_reports (user_id, code);
CREATE INDEX idx_bug_user ON player_bug_reports (user_id, created_at);

CREATE TABLE player_missions (
  user_id    INTEGER NOT NULL,
  mission_id INTEGER NOT NULL,
  status     TEXT    NOT NULL DEFAULT 'disponivel',
  progress   INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT    NOT NULL DEFAULT (datetime('now')),   -- MySQL: ON UPDATE CURRENT_TIMESTAMP
  PRIMARY KEY (user_id, mission_id),
  CONSTRAINT fk_pm_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_pm_mission FOREIGN KEY (mission_id) REFERENCES missions (id) ON DELETE CASCADE
);
CREATE INDEX fk_pm_mission ON player_missions (mission_id);
CREATE TRIGGER trg_player_missions_updated_at AFTER UPDATE ON player_missions
FOR EACH ROW WHEN NEW.updated_at IS OLD.updated_at
BEGIN
  UPDATE player_missions SET updated_at = datetime('now') WHERE rowid = NEW.rowid;
END;

CREATE TABLE player_achievements (
  user_id        INTEGER NOT NULL,
  achievement_id INTEGER NOT NULL,
  progress       INTEGER NOT NULL DEFAULT 0,
  completed_at   TEXT    NULL,
  PRIMARY KEY (user_id, achievement_id),
  CONSTRAINT fk_pa_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_pa_ach FOREIGN KEY (achievement_id) REFERENCES achievements (id) ON DELETE CASCADE
);
CREATE INDEX fk_pa_ach ON player_achievements (achievement_id);

CREATE TABLE player_skills (
  user_id  INTEGER NOT NULL,
  skill_id INTEGER NOT NULL,
  level    INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, skill_id),
  CONSTRAINT fk_ps_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_ps_skill FOREIGN KEY (skill_id) REFERENCES skills (id) ON DELETE CASCADE
);
CREATE INDEX fk_ps_skill ON player_skills (skill_id);

CREATE TABLE player_matches (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id   INTEGER NOT NULL,
  map_id    INTEGER NULL,
  result    TEXT    NOT NULL DEFAULT 'extraido',
  kills     INTEGER NOT NULL DEFAULT 0,
  xp        INTEGER NOT NULL DEFAULT 0,
  earnings  INTEGER NOT NULL DEFAULT 0,
  played_at TEXT    NOT NULL DEFAULT (datetime('now')),
  CONSTRAINT fk_match_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_match_map FOREIGN KEY (map_id) REFERENCES maps (id) ON DELETE SET NULL
);
CREATE INDEX idx_match_user ON player_matches (user_id);
CREATE INDEX fk_match_map ON player_matches (map_id);

-- ============================================================================
-- MENSAGENS
-- ============================================================================

CREATE TABLE message_threads (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id          INTEGER NOT NULL,
  slug             TEXT    NOT NULL,
  folder           TEXT    NOT NULL DEFAULT 'entrada',
  peer_name        TEXT    NOT NULL,
  is_system        INTEGER NOT NULL DEFAULT 0,
  subject          TEXT    NOT NULL,
  peer_avatar      TEXT    NULL,
  peer_icon        TEXT    NULL,
  peer_level       INTEGER NULL,
  peer_class       TEXT    NULL,
  peer_class_icon  TEXT    NULL,
  peer_status      TEXT    NULL,
  peer_status_kind TEXT    NOT NULL DEFAULT 'offline',
  color            TEXT    NOT NULL DEFAULT 'lime',
  tag              TEXT    NULL,
  unread           INTEGER NOT NULL DEFAULT 0,
  CONSTRAINT fk_mt_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX uq_mt_user_slug ON message_threads (user_id, slug);
CREATE INDEX idx_mt_user ON message_threads (user_id, folder);

CREATE TABLE message_entries (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  thread_id INTEGER NOT NULL,
  author    TEXT    NOT NULL,
  is_self   INTEGER NOT NULL DEFAULT 0,
  body      TEXT    NOT NULL,
  color     TEXT    NOT NULL DEFAULT 'lime',
  sent_at   TEXT    NOT NULL DEFAULT (datetime('now')),
  CONSTRAINT fk_me_thread FOREIGN KEY (thread_id) REFERENCES message_threads (id) ON DELETE CASCADE
);
CREATE INDEX idx_me_thread ON message_entries (thread_id, sent_at, id);

-- Avisos do painel "MENSAGENS DO SISTEMA". user_id NULL = aviso global (forma final do patch v7_eventos).
CREATE TABLE system_notices (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NULL,
  icon       TEXT    NOT NULL DEFAULT 'info',
  color      TEXT    NOT NULL DEFAULT 'lime',
  title      TEXT    NOT NULL,
  body       TEXT    NOT NULL,
  cta_label  TEXT    NULL,
  cta_href   TEXT    NULL,
  fresh      INTEGER NOT NULL DEFAULT 0,
  created_at TEXT    NOT NULL DEFAULT (datetime('now')),
  sort_order INTEGER NOT NULL DEFAULT 0,
  CONSTRAINT fk_sn_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
CREATE INDEX idx_sn_user ON system_notices (user_id, sort_order);

-- Janelas de manutenção (catálogo: iguais para todos).
CREATE TABLE maintenance_windows (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  starts_at TEXT    NOT NULL,
  ends_at   TEXT    NOT NULL,
  region    TEXT    NOT NULL DEFAULT 'AMÉRICA DO SUL',
  note      TEXT    NULL,
  news_slug TEXT    NULL
);
CREATE INDEX idx_mw_start ON maintenance_windows (starts_at);

-- ============================================================================
-- SOCIAL
-- ============================================================================

CREATE TABLE friends (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id        INTEGER NOT NULL,
  friend_user_id INTEGER NULL,
  name           TEXT    NOT NULL,
  level          INTEGER NOT NULL DEFAULT 1,
  class          TEXT    NULL,
  class_icon     TEXT    NULL,
  avatar         TEXT    NULL,
  relation       TEXT    NOT NULL DEFAULT 'amigo',     -- amigo | pedido_recebido | pedido_enviado
  presence       TEXT    NOT NULL DEFAULT 'offline',
  activity       TEXT    NULL,
  party_slots    TEXT    NULL,
  matches        INTEGER NOT NULL DEFAULT 0,
  last_seen      TEXT    NULL,
  sort_order     INTEGER NOT NULL DEFAULT 0,
  created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
  updated_at     TEXT    NOT NULL DEFAULT (datetime('now')),  -- MySQL: ON UPDATE CURRENT_TIMESTAMP
  CONSTRAINT fk_friends_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_friends_other FOREIGN KEY (friend_user_id) REFERENCES users (id) ON DELETE SET NULL
);
CREATE UNIQUE INDEX uq_friends_name ON friends (user_id, name);
CREATE INDEX idx_friends_user ON friends (user_id, relation);
CREATE INDEX fk_friends_other ON friends (friend_user_id);
CREATE TRIGGER trg_friends_updated_at AFTER UPDATE ON friends
FOR EACH ROW WHEN NEW.updated_at IS OLD.updated_at
BEGIN
  UPDATE friends SET updated_at = datetime('now') WHERE rowid = NEW.rowid;
END;

CREATE TABLE squad_history (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL,
  mate_name  TEXT    NOT NULL,
  avatar     TEXT    NULL,
  map_name   TEXT    NOT NULL,
  result     TEXT    NOT NULL DEFAULT 'extraido',
  played_at  TEXT    NOT NULL DEFAULT (datetime('now')),
  sort_order INTEGER NOT NULL DEFAULT 0,
  CONSTRAINT fk_squad_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
CREATE INDEX idx_squad_user ON squad_history (user_id, played_at);

-- ----------------------------------------------------------------------------
-- ESTADO DO JOGADOR NOS DESAFIOS / TEMPORADA
-- ----------------------------------------------------------------------------

CREATE TABLE player_challenges (
  user_id      INTEGER NOT NULL,
  challenge_id INTEGER NOT NULL,
  progress     INTEGER NOT NULL DEFAULT 0,
  completed_at TEXT    NULL,
  updated_at   TEXT    NOT NULL DEFAULT (datetime('now')),  -- MySQL: ON UPDATE CURRENT_TIMESTAMP
  PRIMARY KEY (user_id, challenge_id),
  CONSTRAINT fk_pc_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_challenge FOREIGN KEY (challenge_id) REFERENCES challenges (id) ON DELETE CASCADE
);
CREATE INDEX fk_pc_challenge ON player_challenges (challenge_id);
CREATE TRIGGER trg_player_challenges_updated_at AFTER UPDATE ON player_challenges
FOR EACH ROW WHEN NEW.updated_at IS OLD.updated_at
BEGIN
  UPDATE player_challenges SET updated_at = datetime('now') WHERE rowid = NEW.rowid;
END;

CREATE TABLE player_season (
  user_id     INTEGER NOT NULL,
  season_id   INTEGER NOT NULL,
  pass_level  INTEGER NOT NULL DEFAULT 0,
  pass_xp     INTEGER NOT NULL DEFAULT 0,
  week_done   INTEGER NOT NULL DEFAULT 0,
  week_goal   INTEGER NOT NULL DEFAULT 12,
  week_xp     INTEGER NOT NULL DEFAULT 0,
  streak_days INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, season_id),
  CONSTRAINT fk_ps2_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_ps2_season FOREIGN KEY (season_id) REFERENCES seasons (id) ON DELETE CASCADE
);
CREATE INDEX fk_ps2_season ON player_season (season_id);

CREATE TABLE player_bonus_claims (
  user_id    INTEGER NOT NULL,
  scope      TEXT    NOT NULL,
  period_key TEXT    NOT NULL,                     -- 2026-09-03 | 2026-W36 | temporada-04
  xp         INTEGER NOT NULL DEFAULT 0,
  credits    INTEGER NOT NULL DEFAULT 0,
  claimed_at TEXT    NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (user_id, scope, period_key),
  CONSTRAINT fk_pbc_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

-- ============================================================================
-- SUPORTE
-- ============================================================================

CREATE TABLE faq (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  slug       TEXT    NOT NULL,
  question   TEXT    NOT NULL,
  answer     TEXT    NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX uq_faq_slug ON faq (slug);
CREATE INDEX idx_faq_order ON faq (sort_order);

CREATE TABLE support_categories (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  slug       TEXT    NOT NULL,
  label      TEXT    NOT NULL,
  sla_hours  INTEGER NOT NULL DEFAULT 24,
  sort_order INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX uq_supcat_slug ON support_categories (slug);

CREATE TABLE server_regions (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  slug       TEXT    NOT NULL,
  label      TEXT    NOT NULL,
  ping_ms    INTEGER NOT NULL DEFAULT 0,
  players    INTEGER NOT NULL DEFAULT 0,
  state      TEXT    NOT NULL DEFAULT 'ok',        -- ok | warn | down
  sort_order INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX uq_srvreg_slug ON server_regions (slug);

CREATE TABLE server_services (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  slug            TEXT    NOT NULL,
  label           TEXT    NOT NULL,
  state           TEXT    NOT NULL DEFAULT 'ok',
  status_label    TEXT    NOT NULL DEFAULT 'OPERACIONAL',
  short_label     TEXT    NOT NULL DEFAULT 'ESTÁVEL',
  maint_scope     TEXT    NULL,
  maint_starts_at TEXT    NULL,
  maint_minutes   INTEGER NULL,
  sort_order      INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX uq_srvsvc_slug ON server_services (slug);

CREATE TABLE support_tickets (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id     INTEGER NOT NULL,
  protocol    INTEGER NOT NULL,
  subject     TEXT    NOT NULL,
  category    TEXT    NOT NULL,
  priority    TEXT    NOT NULL DEFAULT 'NORMAL',
  message     TEXT    NOT NULL,
  attach_logs INTEGER NOT NULL DEFAULT 0,
  status      TEXT    NOT NULL DEFAULT 'aberto',
  created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
  updated_at  TEXT    NOT NULL DEFAULT (datetime('now')),  -- MySQL: ON UPDATE CURRENT_TIMESTAMP
  CONSTRAINT fk_ticket_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX uq_ticket_protocol ON support_tickets (protocol);
CREATE INDEX idx_ticket_user ON support_tickets (user_id, created_at);
CREATE TRIGGER trg_support_tickets_updated_at AFTER UPDATE ON support_tickets
FOR EACH ROW WHEN NEW.updated_at IS OLD.updated_at
BEGIN
  UPDATE support_tickets SET updated_at = datetime('now') WHERE rowid = NEW.rowid;
END;
