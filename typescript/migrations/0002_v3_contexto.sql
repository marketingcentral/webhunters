-- Patch v3 (db/patch_v3_contexto.php) — mundo persistente PvPvE: só a ESTRUTURA.
-- O conteúdo do patch (bases Alpha/Bravo/Charlie, missões novas, meta das regiões,
-- marcadores, boletins) vem em seed/conteudo.sql, exportado do MySQL já atualizado.
-- ADD COLUMN do SQLite acrescenta no fim, igual à ordem final do MySQL.

-- 1) players: estado operacional do MESMO personagem (Hunter | Mercenário | Sobrevivente)
ALTER TABLE players ADD COLUMN operational_state TEXT NOT NULL DEFAULT 'HUNTER';  -- HUNTER | MERCENARY | SURVIVOR
ALTER TABLE players ADD COLUMN hunter_rank TEXT NOT NULL DEFAULT 'RECRUTA';       -- GameRules::RANKS
ALTER TABLE players ADD COLUMN hunter_role TEXT NULL;                             -- ASSAULT | SNIPER | MEDICAL | RASTREADOR
ALTER TABLE players ADD COLUMN base_pref TEXT NULL;                               -- government_bases.slug preferencial
ALTER TABLE players ADD COLUMN survivor_since TEXT NULL;                          -- DATETIME

-- 2) contrato/reputação por facção. Só UMA linha com contract_active = 1 por jogador (regra na aplicação).
CREATE TABLE player_factions (
  user_id         INTEGER NOT NULL,
  faction         TEXT    NOT NULL,                -- aegis | frontier
  reputation      INTEGER NOT NULL DEFAULT 0,
  tier            TEXT    NULL,
  contract_active INTEGER NOT NULL DEFAULT 0,
  locked_until    TEXT    NULL,
  updated_at      TEXT    NOT NULL DEFAULT (datetime('now')),  -- MySQL: TIMESTAMP ... ON UPDATE CURRENT_TIMESTAMP
  PRIMARY KEY (user_id, faction),
  CONSTRAINT fk_pf_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
CREATE TRIGGER trg_player_factions_updated_at AFTER UPDATE ON player_factions
FOR EACH ROW WHEN NEW.updated_at IS OLD.updated_at
BEGIN
  UPDATE player_factions SET updated_at = datetime('now') WHERE rowid = NEW.rowid;
END;

-- Bases Governamentais de uma região; capacidade por base é DADO, não constante.
CREATE TABLE government_bases (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  slug       TEXT    NOT NULL,                     -- alpha | bravo | charlie
  name       TEXT    NOT NULL,
  map_id     INTEGER NULL,
  capacity   INTEGER NOT NULL DEFAULT 4,
  status     TEXT    NOT NULL DEFAULT 'OPERACIONAL',
  sort_order INTEGER NOT NULL DEFAULT 0,
  CONSTRAINT fk_gb_map FOREIGN KEY (map_id) REFERENCES maps (id) ON DELETE SET NULL
);
CREATE UNIQUE INDEX uq_gb_slug ON government_bases (slug);
CREATE INDEX fk_gb_map ON government_bases (map_id);

-- 3) missions: origem, ato da campanha, requisitos e esquadrão 1–4
ALTER TABLE missions ADD COLUMN origin TEXT NOT NULL DEFAULT 'hunter_command';    -- hunter_command | aegis | frontier | icarus | world
ALTER TABLE missions ADD COLUMN act INTEGER NULL;
ALTER TABLE missions ADD COLUMN requires_faction TEXT NULL;                       -- aegis | frontier | any
ALTER TABLE missions ADD COLUMN requires_tier TEXT NULL;
ALTER TABLE missions ADD COLUMN requires_rep INTEGER NULL;
ALTER TABLE missions ADD COLUMN squad_min INTEGER NOT NULL DEFAULT 1;
ALTER TABLE missions ADD COLUMN squad_max INTEGER NOT NULL DEFAULT 4;             -- nunca 5: esquadrão Hunter
ALTER TABLE missions ADD COLUMN human_activity TEXT NULL;                         -- IMPROVÁVEL | PROVÁVEL | DETECTADA
