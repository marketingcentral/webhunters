-- Patch v11 (db/patch_v11_bestiario.php) — o que cada jogador já viu das criaturas.
-- O catálogo das criaturas mora em app/Data/criaturas.php; aqui só o estado por jogador.
-- Sem FK em creature_slug nem em user_id (igual ao MySQL): criatura que sai do catálogo
-- não apaga o histórico de ninguém.

CREATE TABLE player_bestiary (
  user_id       INTEGER NOT NULL,
  creature_slug TEXT    NOT NULL,
  sightings     INTEGER NOT NULL DEFAULT 0,
  kills         INTEGER NOT NULL DEFAULT 0,
  studied       INTEGER NOT NULL DEFAULT 0,
  first_seen_at TEXT    NULL,
  updated_at    TEXT    NOT NULL DEFAULT (datetime('now')),  -- MySQL: ON UPDATE CURRENT_TIMESTAMP
  PRIMARY KEY (user_id, creature_slug)
);
CREATE INDEX ix_bestiary_slug ON player_bestiary (creature_slug);
CREATE TRIGGER trg_player_bestiary_updated_at AFTER UPDATE ON player_bestiary
FOR EACH ROW WHEN NEW.updated_at IS OLD.updated_at
BEGIN
  UPDATE player_bestiary SET updated_at = datetime('now') WHERE rowid = NEW.rowid;
END;
