-- Patch v9 (db/patch_v9_mapa_editor.php) — catálogo de pontos do editor de mapa.
-- Os tipos, maps.meta.world_image e os marcadores recriados vêm no seed.
-- O patch v10 (db/patch_v10_enredo_meteoro.php) e o db/patch_municao.php só mexem em
-- conteúdo/estado: não têm migration, o resultado deles está no seed.

-- `icon` traz a ORIGEM no prefixo: glyph:<id> | symbol:<nome> | image:<caminho>.
-- `is_system` = tipo que o jogo conhece de nascença; o painel edita, mas não apaga.
CREATE TABLE map_point_types (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  slug        TEXT    NOT NULL,
  label       TEXT    NOT NULL,
  category    TEXT    NOT NULL,                    -- operacional,hostil,logistica,combustivel,...
  kind        TEXT    NOT NULL,                    -- map_markers.kind que este ponto grava
  icon        TEXT    NOT NULL DEFAULT 'symbol:place',
  color       TEXT    NOT NULL DEFAULT 'white',
  layer       TEXT    NULL,
  show_label  INTEGER NOT NULL DEFAULT 0,
  description TEXT    NULL,
  is_system   INTEGER NOT NULL DEFAULT 0,
  sort_order  INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX uq_mpt_slug ON map_point_types (slug);
CREATE INDEX idx_mpt_cat ON map_point_types (category);
