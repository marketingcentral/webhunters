-- Extract Hunters — esquema completo (MySQL 8+, utf8mb4).
-- Catálogo = definições do jogo (iguais para todos). Estado = por jogador (conciliado por user_id).
-- Rodar com: php db/migrate.php   (dropa e recria tudo, depois db/seed.php popula).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS player_factions;
DROP TABLE IF EXISTS government_bases;
DROP TABLE IF EXISTS support_tickets;
DROP TABLE IF EXISTS support_categories;
DROP TABLE IF EXISTS server_services;
DROP TABLE IF EXISTS server_regions;
DROP TABLE IF EXISTS faq;
DROP TABLE IF EXISTS message_entries;
DROP TABLE IF EXISTS message_threads;
DROP TABLE IF EXISTS system_notices;
DROP TABLE IF EXISTS maintenance_windows;
DROP TABLE IF EXISTS player_bug_reports;
DROP TABLE IF EXISTS player_settings;
DROP TABLE IF EXISTS player_bonus_claims;
DROP TABLE IF EXISTS player_challenges;
DROP TABLE IF EXISTS player_season;
DROP TABLE IF EXISTS challenge_bonuses;
DROP TABLE IF EXISTS challenges;
DROP TABLE IF EXISTS season_tiers;
DROP TABLE IF EXISTS seasons;
DROP TABLE IF EXISTS squad_history;
DROP TABLE IF EXISTS friends;
DROP TABLE IF EXISTS player_matches;
DROP TABLE IF EXISTS player_achievements;
DROP TABLE IF EXISTS player_missions;
DROP TABLE IF EXISTS player_skills;
DROP TABLE IF EXISTS player_purchases;
DROP TABLE IF EXISTS player_items;
DROP TABLE IF EXISTS players;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS shop_offers;
DROP TABLE IF EXISTS achievement_rewards;
DROP TABLE IF EXISTS achievements;
DROP TABLE IF EXISTS mission_rewards;
DROP TABLE IF EXISTS missions;
DROP TABLE IF EXISTS map_markers;
DROP TABLE IF EXISTS map_point_types;
DROP TABLE IF EXISTS maps;
DROP TABLE IF EXISTS news;
DROP TABLE IF EXISTS skills;
DROP TABLE IF EXISTS equipment_slots;
DROP TABLE IF EXISTS items;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- CATÁLOGO
-- ============================================================================

-- Catálogo mestre de itens. Todo objeto físico do jogo é uma linha aqui.
CREATE TABLE items (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug           VARCHAR(64)  NOT NULL,
  name           VARCHAR(120) NOT NULL,
  short_name     VARCHAR(32)  NULL,
  category       VARCHAR(24)  NOT NULL,           -- weapon,pistol,knife,helmet,armor,rig,backpack,mask,glasses,armband,clothing,med,food,drink,ammo,mag,grenade,attachment,tool,key,skin,misc
  subtype        VARCHAR(32)  NULL,               -- ex.: assault_rifle, plate_carrier, sight, suppressor, grip, foregrip...
  slot_type      VARCHAR(24)  NULL,               -- em qual slot de equipamento cabe (helmet,mask,glasses,armor,rig,backpack,primary,secondary,pistol,knife,armband)
  grid_w         TINYINT UNSIGNED NOT NULL DEFAULT 1,   -- largura na grade (células)
  grid_h         TINYINT UNSIGNED NOT NULL DEFAULT 1,   -- altura na grade (células)
  weight_kg      DECIMAL(6,3) NOT NULL DEFAULT 0.000,
  rarity         VARCHAR(16)  NOT NULL DEFAULT 'comum', -- comum,incomum,raro,epico,lendario
  image          VARCHAR(160) NOT NULL,           -- caminho relativo em /media (ex.: items/ak103.png)
  description    VARCHAR(400) NULL,
  base_price     INT UNSIGNED NOT NULL DEFAULT 0,
  currency       VARCHAR(12)  NOT NULL DEFAULT 'credits',
  stackable      TINYINT(1)   NOT NULL DEFAULT 0,
  max_stack      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  durability_max SMALLINT UNSIGNED NULL,          -- capacete/colete: vida útil
  grid_cols      TINYINT UNSIGNED NULL,           -- containers (rig/backpack/pockets): colunas internas
  grid_rows      TINYINT UNSIGNED NULL,           -- containers: linhas internas
  ammo_caliber   VARCHAR(24)  NULL,               -- arma/carregador/munição: calibre
  mag_capacity   SMALLINT UNSIGNED NULL,          -- carregador: capacidade
  attach_slots   JSON         NULL,               -- arma: [{key:'sight',accepts:['sight']},{key:'muzzle',accepts:['suppressor','muzzle']},...]
  fits_calibers  JSON         NULL,               -- arma: calibres aceitos
  meta           JSON         NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_items_slug (slug),
  KEY idx_items_category (category),
  KEY idx_items_slot (slot_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Slots de equipamento do personagem (definição + o que aceitam).
CREATE TABLE equipment_slots (
  slot_key    VARCHAR(24)  NOT NULL,
  label       VARCHAR(40)  NOT NULL,
  accepts     JSON         NOT NULL,   -- categorias aceitas, ex.: ["helmet"]
  sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (slot_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE maps (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(64)  NOT NULL,
  name          VARCHAR(80)  NOT NULL,
  kind          VARCHAR(16)  NOT NULL DEFAULT 'match',  -- region (mapa do mundo) | match (partida)
  mode          VARCHAR(40)  NULL,
  players       VARCHAR(16)  NULL,
  level_min     SMALLINT NULL,
  level_max     SMALLINT NULL,
  locked        TINYINT(1)   NOT NULL DEFAULT 0,
  image         VARCHAR(160) NULL,
  description   VARCHAR(400) NULL,
  unreal_level  VARCHAR(200) NULL,          -- level da UE que este mapa carrega (NULL = ainda não existe)
  meta          JSON         NULL,
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_maps_slug (slug),
  KEY idx_maps_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE map_markers (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  map_id    INT UNSIGNED NOT NULL,
  kind      VARCHAR(24)  NOT NULL,     -- extraction,base,camp,enemy,loot,event,risk...
  label     VARCHAR(80)  NULL,
  x         DECIMAL(6,4) NOT NULL,     -- 0..1 na imagem
  y         DECIMAL(6,4) NOT NULL,
  meta      JSON NULL,
  PRIMARY KEY (id),
  KEY idx_marker_map (map_id),
  CONSTRAINT fk_marker_map FOREIGN KEY (map_id) REFERENCES maps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Catálogo de ícones pré-fabricados que o painel arrasta para o mapa (/admin/mapas/pontos).
-- `icon` traz a ORIGEM no prefixo: glyph:<id> (vetor da tela do mapa), symbol:<nome>
-- (Material Symbol) ou image:<caminho> (arte enviada pelo painel).
-- `is_system` = tipo que o jogo conhece de nascença; o painel edita, mas não apaga.
CREATE TABLE map_point_types (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(48)  NOT NULL,
  label       VARCHAR(60)  NOT NULL,
  category    VARCHAR(32)  NOT NULL,   -- operacional,hostil,logistica,combustivel,alimentacao,comercio,saude,outros
  kind        VARCHAR(24)  NOT NULL,   -- map_markers.kind que este ponto grava
  icon        VARCHAR(180) NOT NULL DEFAULT 'symbol:place',
  color       VARCHAR(16)  NOT NULL DEFAULT 'white',
  layer       VARCHAR(24)  NULL,       -- camada do filtro; NULL = a padrão do kind
  show_label  TINYINT(1)   NOT NULL DEFAULT 0,
  description VARCHAR(255) NULL,
  is_system   TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mpt_slug (slug),
  KEY idx_mpt_cat (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE missions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug         VARCHAR(64)  NOT NULL,
  name         VARCHAR(120) NOT NULL,
  giver        VARCHAR(80)  NULL,
  region       VARCHAR(80)  NULL,
  type         VARCHAR(24)  NOT NULL DEFAULT 'contrato', -- principal,contrato,diaria
  difficulty   VARCHAR(16)  NULL,
  duration_min SMALLINT NULL,
  players_max  TINYINT NULL,
  level_req    SMALLINT NULL,
  objective    VARCHAR(400) NULL,
  target       SMALLINT NULL,                -- meta (ex.: eliminar 8)
  image        VARCHAR(160) NULL,
  locked       TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  -- Mundo persistente (PRD v3): de quem vem, o que exige e quantos vão.
  origin           VARCHAR(24) NOT NULL DEFAULT 'hunter_command', -- hunter_command | aegis | frontier | icarus | world
  act              TINYINT UNSIGNED NULL,                        -- ato da campanha (1–4) quando principal
  requires_faction VARCHAR(16) NULL,                             -- aegis | frontier | any
  requires_tier    VARCHAR(24) NULL,                             -- faixa de reputação mínima (GameRules::REP_TIERS)
  requires_rep     INT NULL,
  squad_min        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  squad_max        TINYINT UNSIGNED NOT NULL DEFAULT 4,          -- nunca 5: esquadrão Hunter
  human_activity   VARCHAR(16) NULL,                             -- IMPROVÁVEL | PROVÁVEL | DETECTADA
  PRIMARY KEY (id),
  UNIQUE KEY uq_missions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mission_rewards (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  mission_id INT UNSIGNED NOT NULL,
  kind       VARCHAR(16)  NOT NULL,   -- xp,credits,cash,points,item
  amount     INT NOT NULL DEFAULT 0,
  item_id    INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_mr_mission (mission_id),
  CONSTRAINT fk_mr_mission FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE,
  CONSTRAINT fk_mr_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE achievements (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug         VARCHAR(64)  NOT NULL,
  name         VARCHAR(120) NOT NULL,
  category     VARCHAR(24)  NOT NULL DEFAULT 'combate',
  description  VARCHAR(400) NULL,
  icon         VARCHAR(48)  NULL,
  target       INT NOT NULL DEFAULT 1,
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ach_slug (slug),
  KEY idx_ach_cat (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE achievement_rewards (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  achievement_id INT UNSIGNED NOT NULL,
  kind           VARCHAR(16)  NOT NULL,
  amount         INT NOT NULL DEFAULT 0,
  item_id        INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_ar_ach (achievement_id),
  CONSTRAINT fk_ar_ach FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE CASCADE,
  CONSTRAINT fk_ar_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE shop_offers (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id    INT UNSIGNED NOT NULL,
  category   VARCHAR(24)  NOT NULL,   -- destaques,armas,equipamento,suprimentos,cosmeticos,passe
  price      INT UNSIGNED NOT NULL,
  currency   VARCHAR(12)  NOT NULL DEFAULT 'credits',
  discount   TINYINT UNSIGNED NULL,   -- % de desconto
  tag        VARCHAR(16)  NULL,       -- NOVO,POPULAR,RARO,EPICO...
  featured   TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_offer_cat (category),
  CONSTRAINT fk_offer_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE skills (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(64)  NOT NULL,
  category    VARCHAR(24)  NOT NULL,
  tier        TINYINT NOT NULL DEFAULT 1,
  col         TINYINT NOT NULL DEFAULT 1,
  name        VARCHAR(80)  NOT NULL,
  icon        VARCHAR(48)  NULL,
  max_level   TINYINT NOT NULL DEFAULT 1,
  cost        TINYINT NOT NULL DEFAULT 0,
  description  VARCHAR(400) NULL,
  image       VARCHAR(160) NULL,             -- arte do painel de detalhe (relativa a /media)
  step        DECIMAL(7,2) NULL,             -- magnitude do efeito POR NIVEL
  unit        VARCHAR(16)  NULL,             -- sufixo da magnitude ('%', ' segundos', '')
  effect      VARCHAR(220) NULL,             -- frase do efeito com {v} no lugar do valor
  PRIMARY KEY (id),
  UNIQUE KEY uq_skill_slug (slug),
  KEY idx_skill_cat (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE news (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug       VARCHAR(80)  NOT NULL,
  title      VARCHAR(160) NOT NULL,
  category   VARCHAR(24)  NULL,
  excerpt    VARCHAR(400) NULL,
  body       TEXT NULL,
  image      VARCHAR(160) NULL,
  published  DATE NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_news_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- TEMPORADAS E DESAFIOS (tela /desafios)
-- ----------------------------------------------------------------------------

-- Temporada corrente: define o passe, o evento em destaque e os prazos reais.
-- Os prazos são DATETIME de verdade: a contagem regressiva da tela é calculada
-- a partir deles (agora -> ends_at), nunca de um texto fixo.
CREATE TABLE seasons (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug           VARCHAR(64)  NOT NULL,
  number         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  tag            VARCHAR(40)  NOT NULL,           -- TEMPORADA 04
  pass_name      VARCHAR(80)  NOT NULL,           -- MATA FECHADA
  pass_image     VARCHAR(160) NULL,               -- ui/season-pass.jpg
  xp_per_level   INT UNSIGNED NOT NULL DEFAULT 10000,
  event_tag      VARCHAR(40)  NULL,               -- EVENTO DE TEMPORADA
  event_title    VARCHAR(80)  NULL,               -- CAÇA NA FLORESTA
  event_desc     VARCHAR(400) NULL,
  event_image    VARCHAR(160) NULL,
  event_ends_at  DATETIME     NOT NULL,
  starts_at      DATETIME     NOT NULL,
  ends_at        DATETIME     NOT NULL,
  is_current     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_season_slug (slug),
  KEY idx_season_current (is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Recompensas do passe por nível (prévia "PRÓXIMAS RECOMPENSAS").
CREATE TABLE season_tiers (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  season_id  INT UNSIGNED NOT NULL,
  level      SMALLINT UNSIGNED NOT NULL,
  name       VARCHAR(80)  NOT NULL,
  image      VARCHAR(160) NULL,
  rarity     VARCHAR(16)  NULL,           -- prestígio do patamar (≠ raridade do item no loot)
  item_id    INT UNSIGNED NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_tier_season (season_id),
  CONSTRAINT fk_tier_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
  CONSTRAINT fk_tier_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Catálogo de desafios. scope = aba da tela (diarios | semanais | temporada).
CREATE TABLE challenges (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(64)  NOT NULL,
  scope       VARCHAR(16)  NOT NULL DEFAULT 'diarios',
  season_id   INT UNSIGNED NULL,                  -- só para scope=temporada
  label       VARCHAR(160) NOT NULL,
  description VARCHAR(400) NULL,
  icon        VARCHAR(48)  NULL,
  target      INT UNSIGNED NOT NULL DEFAULT 1,
  xp          INT UNSIGNED NOT NULL DEFAULT 0,
  credits     INT UNSIGNED NOT NULL DEFAULT 0,
  highlight   TINYINT(1)   NOT NULL DEFAULT 0,    -- linha "quente" (dourada)
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_challenge_slug (slug),
  KEY idx_challenge_scope (scope, sort_order),
  CONSTRAINT fk_challenge_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bônus por aba: resgatável quando o jogador conclui todos os desafios do escopo.
CREATE TABLE challenge_bonuses (
  scope       VARCHAR(16)  NOT NULL,
  label       VARCHAR(40)  NOT NULL,
  title       VARCHAR(80)  NOT NULL,
  description VARCHAR(400) NULL,
  image       VARCHAR(160) NULL,
  xp          INT UNSIGNED NOT NULL DEFAULT 0,
  credits     INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- USUÁRIOS E ESTADO POR JOGADOR
-- ============================================================================

CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(32)  NOT NULL,
  email         VARCHAR(160) NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login    DATETIME NULL,
  role          VARCHAR(16) NOT NULL DEFAULT 'player',  -- player | admin | owner  (patch v6)
  status        VARCHAR(16) NOT NULL DEFAULT 'active',  -- active | banned | suspended
  last_ip       VARCHAR(45) NULL,                       -- 45 = IPv6 com mapeamento IPv4
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE players (
  user_id       INT UNSIGNED NOT NULL,
  display_name  VARCHAR(32)  NOT NULL,
  level         SMALLINT NOT NULL DEFAULT 0,
  xp            INT NOT NULL DEFAULT 0,
  xp_next       INT NOT NULL DEFAULT 1000,
  credits       INT NOT NULL DEFAULT 0,
  cash          INT NOT NULL DEFAULT 0,
  points        INT NOT NULL DEFAULT 0,
  clan          VARCHAR(40)  NULL,
  class         VARCHAR(24)  NULL,
  class_icon    VARCHAR(32)  NULL,
  avatar        VARCHAR(160) NULL,
  player_rank   VARCHAR(40)  NULL,
  season_level  SMALLINT NOT NULL DEFAULT 0,
  skill_points  SMALLINT NOT NULL DEFAULT 0,
  health        JSON NULL,     -- {saude:100,energia:78,hidratacao:56,fome:62,temperatura:36.6, limbs:{...}}
  survival      JSON NULL,
  -- Estado operacional (PRD v3): o MESMO personagem como Hunter, Mercenário ou Sobrevivente.
  operational_state VARCHAR(16) NOT NULL DEFAULT 'HUNTER',   -- HUNTER | MERCENARY | SURVIVOR
  hunter_rank   VARCHAR(24)  NOT NULL DEFAULT 'RECRUTA',     -- GameRules::RANKS
  hunter_role   VARCHAR(16)  NULL,                           -- ASSAULT | SNIPER | MEDICAL | RASTREADOR
  base_pref     VARCHAR(16)  NULL,                           -- government_bases.slug preferencial
  survivor_since DATETIME    NULL,                           -- desde quando está sem bandeira
  PRIMARY KEY (user_id),
  CONSTRAINT fk_player_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Contrato e reputação do personagem com cada facção (PRD v3 §7). Uma linha por facção
-- conhecida; só UMA pode ter contract_active = 1 (regra na aplicação, OperationalRepo::sign).
CREATE TABLE player_factions (
  user_id         INT UNSIGNED NOT NULL,
  faction         VARCHAR(16)  NOT NULL,                 -- aegis | frontier
  reputation      INT NOT NULL DEFAULT 0,
  tier            VARCHAR(24)  NULL,                     -- RECRUTADO / OPERADOR / CONFIÁVEL / VETERANO
  contract_active TINYINT(1)   NOT NULL DEFAULT 0,
  locked_until    DATETIME     NULL,                     -- bloqueio após traição; valor vem do backend
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, faction),
  CONSTRAINT fk_pf_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- As Bases Governamentais de uma região (Bíblia §3): 3 × 4 Hunters = 12. A capacidade por
-- base é DADO, não constante — muda aqui, sem deploy.
CREATE TABLE government_bases (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug       VARCHAR(16)  NOT NULL,                      -- alpha | bravo | charlie
  name       VARCHAR(60)  NOT NULL,
  map_id     INT UNSIGNED NULL,
  capacity   TINYINT UNSIGNED NOT NULL DEFAULT 4,
  status     VARCHAR(16)  NOT NULL DEFAULT 'OPERACIONAL',-- OPERACIONAL | LOTADA | EM RISCO | ISOLADA | SOB ATAQUE
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gb_slug (slug),
  CONSTRAINT fk_gb_map FOREIGN KEY (map_id) REFERENCES maps(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Instâncias de item que o jogador possui (adquiridas em partidas ou na loja).
CREATE TABLE player_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  item_id       INT UNSIGNED NOT NULL,
  container      VARCHAR(24) NOT NULL DEFAULT 'stash',  -- stash | pockets | equip | grid | attach
  slot_key       VARCHAR(24) NULL,                       -- equip: qual slot; attachment: qual bocal
  parent_id      INT UNSIGNED NULL,                      -- item pai (rig/backpack container, ou arma p/ attachment/mag)
  pos_x          TINYINT NULL,                           -- posição na grade do container (0-based)
  pos_y          TINYINT NULL,
  rotated        TINYINT(1) NOT NULL DEFAULT 0,
  quantity       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  durability_cur SMALLINT UNSIGNED NULL,
  ammo_cur       SMALLINT UNSIGNED NULL,                 -- munição carregada (arma/carregador)
  ammo_item_id   INT UNSIGNED NULL,                      -- tipo de munição carregada
  acquired_via   VARCHAR(16) NOT NULL DEFAULT 'partida', -- partida | loja | recompensa | inicial
  acquired_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pi_user (user_id),
  KEY idx_pi_parent (parent_id),
  KEY idx_pi_container (user_id, container),
  CONSTRAINT fk_pi_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pi_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
  CONSTRAINT fk_pi_parent FOREIGN KEY (parent_id) REFERENCES player_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_pi_ammo FOREIGN KEY (ammo_item_id) REFERENCES items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE player_purchases (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  item_id    INT UNSIGNED NOT NULL,
  quantity   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  currency   VARCHAR(12)  NOT NULL,
  price      INT UNSIGNED NOT NULL,
  bought_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pp_user (user_id),
  CONSTRAINT fk_pp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Preferências da tela CONFIGURAÇÕES. Uma linha por opção alterada; o padrão
-- continua vindo do catálogo em app/Data/configuracoes.php.
CREATE TABLE player_settings (
  user_id     INT UNSIGNED NOT NULL,
  setting_key VARCHAR(64)  NOT NULL,
  value       VARCHAR(160) NOT NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, setting_key),
  KEY idx_pset_user (user_id),
  CONSTRAINT fk_pset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Relatórios de bug enviados pelo jogador (tela RELATÓRIO DE BUG).
CREATE TABLE player_bug_reports (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  code        VARCHAR(16)  NOT NULL,
  title       VARCHAR(200) NOT NULL,
  area        VARCHAR(32)  NOT NULL,
  severity    VARCHAR(16)  NOT NULL,
  description TEXT NULL,
  steps       TEXT NULL,
  attach_logs TINYINT(1) NOT NULL DEFAULT 1,
  status      VARCHAR(16) NOT NULL DEFAULT 'recebido',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at   DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bug_user_code (user_id, code),
  KEY idx_bug_user (user_id, created_at),
  CONSTRAINT fk_bug_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE player_missions (
  user_id    INT UNSIGNED NOT NULL,
  mission_id INT UNSIGNED NOT NULL,
  status     VARCHAR(16) NOT NULL DEFAULT 'disponivel', -- disponivel,ativa,concluida,bloqueada
  progress   INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, mission_id),
  CONSTRAINT fk_pm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pm_mission FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE player_achievements (
  user_id        INT UNSIGNED NOT NULL,
  achievement_id INT UNSIGNED NOT NULL,
  progress       INT NOT NULL DEFAULT 0,
  completed_at   DATETIME NULL,
  PRIMARY KEY (user_id, achievement_id),
  CONSTRAINT fk_pa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pa_ach FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE player_skills (
  user_id  INT UNSIGNED NOT NULL,
  skill_id INT UNSIGNED NOT NULL,
  level    TINYINT NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, skill_id),
  CONSTRAINT fk_ps_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ps_skill FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE player_matches (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  map_id     INT UNSIGNED NULL,
  result     VARCHAR(16) NOT NULL DEFAULT 'extraido', -- extraido,abatido,fuga
  kills      SMALLINT NOT NULL DEFAULT 0,
  xp         INT NOT NULL DEFAULT 0,
  earnings   INT NOT NULL DEFAULT 0,
  played_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_match_user (user_id),
  CONSTRAINT fk_match_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_match_map FOREIGN KEY (map_id) REFERENCES maps(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- MENSAGENS (caixa de entrada por jogador)
-- ============================================================================

-- Uma conversa da caixa do jogador. `slug` é o identificador estável usado na
-- rota /mensagens/{slug} e no data-conv da lista.
CREATE TABLE message_threads (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED NOT NULL,
  slug             VARCHAR(40)  NOT NULL,
  folder           VARCHAR(16)  NOT NULL DEFAULT 'entrada',   -- entrada | enviadas
  peer_name        VARCHAR(80)  NOT NULL,
  is_system        TINYINT(1)   NOT NULL DEFAULT 0,
  subject          VARCHAR(160) NOT NULL,
  peer_avatar      VARCHAR(160) NULL,                          -- caminho relativo em /media
  peer_icon        VARCHAR(48)  NULL,                          -- material symbol quando não há retrato
  peer_level       SMALLINT     NULL,
  peer_class       VARCHAR(48)  NULL,
  peer_class_icon  VARCHAR(48)  NULL,
  peer_status      VARCHAR(24)  NULL,
  peer_status_kind VARCHAR(16)  NOT NULL DEFAULT 'offline',    -- online | busy | offline | system
  color            VARCHAR(16)  NOT NULL DEFAULT 'lime',
  tag              VARCHAR(16)  NULL,
  unread           TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mt_user_slug (user_id, slug),
  KEY idx_mt_user (user_id, folder),
  CONSTRAINT fk_mt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada balão da conversa. `is_self = 1` é o próprio jogador.
CREATE TABLE message_entries (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  thread_id  INT UNSIGNED NOT NULL,
  author     VARCHAR(80)  NOT NULL,
  is_self    TINYINT(1)   NOT NULL DEFAULT 0,
  body       VARCHAR(600) NOT NULL,
  color      VARCHAR(16)  NOT NULL DEFAULT 'lime',
  sent_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_me_thread (thread_id, sent_at, id),
  CONSTRAINT fk_me_thread FOREIGN KEY (thread_id) REFERENCES message_threads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Avisos do painel lateral "MENSAGENS DO SISTEMA" (por jogador).
CREATE TABLE system_notices (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  icon       VARCHAR(48)  NOT NULL DEFAULT 'info',
  color      VARCHAR(16)  NOT NULL DEFAULT 'lime',
  title      VARCHAR(120) NOT NULL,
  body       VARCHAR(400) NOT NULL,
  cta_label  VARCHAR(40)  NULL,
  cta_href   VARCHAR(120) NULL,
  fresh      TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_sn_user (user_id, sort_order),
  CONSTRAINT fk_sn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Janelas de manutenção (catálogo: iguais para todos). O contador da tela é
-- calculado a partir de starts_at, então nunca "congela" num número fixo.
CREATE TABLE maintenance_windows (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  starts_at  DATETIME     NOT NULL,
  ends_at    DATETIME     NOT NULL,
  region     VARCHAR(60)  NOT NULL DEFAULT 'AMÉRICA DO SUL',
  note       VARCHAR(240) NULL,
  news_slug  VARCHAR(80)  NULL,
  PRIMARY KEY (id),
  KEY idx_mw_start (starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SOCIAL (lista de amigos e histórico de esquadrão) — estado por jogador
-- ============================================================================

-- Uma linha por vínculo social do jogador dono (`user_id`). `relation` distingue amizade
-- firmada de pedido recebido/enviado, então as três abas da tela AMIGOS saem desta tabela.
CREATE TABLE friends (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,                    -- dono da lista
  friend_user_id INT UNSIGNED NULL,                        -- conta real do amigo, quando existir
  name           VARCHAR(40)  NOT NULL,
  level          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  class          VARCHAR(24)  NULL,
  class_icon     VARCHAR(32)  NULL,
  avatar         VARCHAR(160) NULL,                        -- caminho relativo p/ media(), ex.: portraits/op-scout.jpg
  relation       VARCHAR(16)  NOT NULL DEFAULT 'amigo',    -- amigo | pedido_recebido | pedido_enviado
  presence       VARCHAR(16)  NOT NULL DEFAULT 'offline',  -- ingame | lobby | online | offline
  activity       VARCHAR(120) NULL,                        -- o que o amigo esta fazendo agora
  party_slots    VARCHAR(12)  NULL,                        -- lobby: "4 / 5"
  matches        INT UNSIGNED NOT NULL DEFAULT 0,          -- partidas jogadas (aba OFFLINE)
  last_seen      DATETIME NULL,                            -- base do rotulo "HA 12 MIN"
  sort_order     SMALLINT NOT NULL DEFAULT 0,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_friends_name (user_id, name),
  KEY idx_friends_user (user_id, relation),
  CONSTRAINT fk_friends_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_friends_other FOREIGN KEY (friend_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Com quem o jogador jogou nas ultimas partidas (painel ESQUADRAO RECENTE).
CREATE TABLE squad_history (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  mate_name  VARCHAR(40)  NOT NULL,
  avatar     VARCHAR(160) NULL,
  map_name   VARCHAR(60)  NOT NULL,
  result     VARCHAR(16)  NOT NULL DEFAULT 'extraido',     -- extraido | abatido
  played_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_squad_user (user_id, played_at),
  CONSTRAINT fk_squad_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- ESTADO DO JOGADOR NOS DESAFIOS / TEMPORADA
-- ----------------------------------------------------------------------------

-- Progresso do jogador em cada desafio.
CREATE TABLE player_challenges (
  user_id      INT UNSIGNED NOT NULL,
  challenge_id INT UNSIGNED NOT NULL,
  progress     INT UNSIGNED NOT NULL DEFAULT 0,
  completed_at DATETIME NULL,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, challenge_id),
  CONSTRAINT fk_pc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_challenge FOREIGN KEY (challenge_id) REFERENCES challenges(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Passe/temporada do jogador + estatísticas da semana (anel "PROGRESSO SEMANAL").
CREATE TABLE player_season (
  user_id     INT UNSIGNED NOT NULL,
  season_id   INT UNSIGNED NOT NULL,
  pass_level  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  pass_xp     INT UNSIGNED NOT NULL DEFAULT 0,
  week_done   SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- desafios concluídos na semana
  week_goal   SMALLINT UNSIGNED NOT NULL DEFAULT 12,  -- meta da semana
  week_xp     INT UNSIGNED NOT NULL DEFAULT 0,
  streak_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, season_id),
  CONSTRAINT fk_ps2_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ps2_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Resgates do bônus, um por escopo e por período (dia/semana/temporada).
CREATE TABLE player_bonus_claims (
  user_id    INT UNSIGNED NOT NULL,
  scope      VARCHAR(16)  NOT NULL,
  period_key VARCHAR(24)  NOT NULL,   -- 2026-09-03 | 2026-W36 | temporada-04
  xp         INT UNSIGNED NOT NULL DEFAULT 0,
  credits    INT UNSIGNED NOT NULL DEFAULT 0,
  claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, scope, period_key),
  CONSTRAINT fk_pbc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SUPORTE — FAQ, categorias de chamado, telemetria de servidores e chamados
-- ============================================================================

-- Perguntas frequentes exibidas no acordeão da tela SUPORTE (catálogo).
CREATE TABLE faq (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug       VARCHAR(80)  NOT NULL,
  question   VARCHAR(240) NOT NULL,
  answer     TEXT         NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_faq_slug (slug),
  KEY idx_faq_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Categorias de chamado (catálogo). sla_hours alimenta "RESPOSTA ESTIMADA".
CREATE TABLE support_categories (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug       VARCHAR(24)  NOT NULL,
  label      VARCHAR(48)  NOT NULL,
  sla_hours  SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_supcat_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Regiões de jogo com telemetria (ping/lotação) mostrada no painel de status.
CREATE TABLE server_regions (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug       VARCHAR(24)  NOT NULL,
  label      VARCHAR(48)  NOT NULL,
  ping_ms    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  players    INT UNSIGNED NOT NULL DEFAULT 0,
  state      VARCHAR(8)   NOT NULL DEFAULT 'ok',  -- ok | warn | down
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_srvreg_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Serviços da plataforma. A linha em manutenção alimenta o painel de contagem regressiva.
CREATE TABLE server_services (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug           VARCHAR(24)  NOT NULL,
  label          VARCHAR(48)  NOT NULL,
  state          VARCHAR(8)   NOT NULL DEFAULT 'ok',  -- ok | warn | down
  status_label   VARCHAR(32)  NOT NULL DEFAULT 'OPERACIONAL',
  short_label    VARCHAR(24)  NOT NULL DEFAULT 'ESTÁVEL',
  maint_scope    VARCHAR(48)  NULL,                   -- ex.: LOJA E MERCADO
  maint_starts_at DATETIME    NULL,
  maint_minutes  SMALLINT UNSIGNED NULL,
  sort_order     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_srvsvc_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Chamados abertos pelo jogador (estado por user_id).
CREATE TABLE support_tickets (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  protocol    INT UNSIGNED NOT NULL,
  subject     VARCHAR(120) NOT NULL,
  category    VARCHAR(24)  NOT NULL,
  priority    VARCHAR(12)  NOT NULL DEFAULT 'NORMAL',
  message     TEXT         NOT NULL,
  attach_logs TINYINT(1)   NOT NULL DEFAULT 0,
  status      VARCHAR(16)  NOT NULL DEFAULT 'aberto', -- aberto | analise | resolvido | fechado
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_protocol (protocol),
  KEY idx_ticket_user (user_id, created_at),
  CONSTRAINT fk_ticket_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SAFE ZONE (patch v4): perímetros protegidos e o papel civil de cada jogador.
-- ============================================================================
DROP TABLE IF EXISTS `player_safe_roles`;
DROP TABLE IF EXISTS `safe_zones`;

CREATE TABLE `safe_zones` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(32) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `kind` VARCHAR(8) NOT NULL DEFAULT 'gov',          -- gov | free
  `base_slug` VARCHAR(16) NULL,                       -- government_bases.slug
  `map_id` INT UNSIGNED NULL,
  `region` VARCHAR(80) NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'ABERTA',
  `occupancy` VARCHAR(16) NOT NULL DEFAULT 'MODERADA',
  `capacity` SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  `protection` VARCHAR(120) NULL,
  `description` TEXT NULL,
  `meta` JSON NULL,                                   -- services[], rules[]
  `sort_order` SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_safe_zones_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `player_safe_roles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `zone_slug` VARCHAR(32) NOT NULL,
  `role` VARCHAR(24) NOT NULL,                        -- GameRules::SAFE_ROLES
  `status` VARCHAR(16) NOT NULL DEFAULT 'ATIVO',
  `started_at` DATETIME NOT NULL,
  `shift_ends_at` DATETIME NULL,
  `served` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_safe_role_user` (`user_id`),         -- um papel por vez
  KEY `ix_safe_role_zone` (`zone_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- PAINEL ADMINISTRATIVO (patch v6): punição, auditoria, configuração e métricas.
-- users.role / users.status / users.last_ip estão declarados junto de `users`.
-- ============================================================================
DROP TABLE IF EXISTS `admin_metrics_daily`;
DROP TABLE IF EXISTS `admin_settings`;
DROP TABLE IF EXISTS `admin_audit_log`;
DROP TABLE IF EXISTS `bans`;

-- Histórico de punição. Sem FOREIGN KEY de propósito: o registro precisa sobreviver à
-- remoção da conta punida ou do admin que puniu — apagar o admin não pode apagar a prova.
CREATE TABLE `bans` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `admin_id` INT UNSIGNED NULL,
  `reason` TEXT NULL,
  `kind` VARCHAR(16) NOT NULL DEFAULT 'temp',           -- temp | perma
  `expires_at` DATETIME NULL,                           -- só faz sentido em temp
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at` DATETIME NULL,
  `revoked_by` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `ix_bans_user` (`user_id`, `created_at`),
  KEY `ix_bans_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Trilha de auditoria: toda ação de admin vira uma linha aqui. É a fonte da tela LOGS DO SISTEMA.
CREATE TABLE `admin_audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED NULL,
  `action` VARCHAR(64) NOT NULL,                        -- ex.: user.ban, settings.update
  `entity` VARCHAR(48) NOT NULL,                        -- tabela/domínio afetado
  `entity_id` VARCHAR(64) NULL,                         -- texto: nem toda entidade tem id numérico
  `summary` VARCHAR(255) NOT NULL DEFAULT '',
  `meta` JSON NULL,                                     -- antes/depois, payload da ação
  `ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_audit_admin` (`admin_id`, `created_at`),
  KEY `ix_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Parâmetros do jogo editáveis pelo admin sem deploy. Quando a chave existe aqui ela manda;
-- senão vale o padrão de App\Core\GameRules. `kind` diz como interpretar o TEXT.
CREATE TABLE `admin_settings` (
  `key` VARCHAR(64) NOT NULL,
  `value` TEXT NULL,
  `kind` VARCHAR(16) NOT NULL DEFAULT 'string',         -- string | int | bool | json
  `label` VARCHAR(120) NOT NULL DEFAULT '',
  `grupo` VARCHAR(32) NOT NULL DEFAULT 'geral',         -- aba do formulário de configuração
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` INT UNSIGNED NULL,
  PRIMARY KEY (`key`),
  KEY `ix_settings_grupo` (`grupo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Série diária do dashboard (gráficos de 7 e de 30 dias). Uma linha por dia fechado.
-- Enquanto não existir telemetria real, db/patch_v6_admin.php semeia 30 dias de demonstração.
CREATE TABLE `admin_metrics_daily` (
  `day` DATE NOT NULL,
  `players_online` INT UNSIGNED NOT NULL DEFAULT 0,     -- pico de simultâneos no dia
  `new_players` INT UNSIGNED NOT NULL DEFAULT 0,
  `sessions` INT UNSIGNED NOT NULL DEFAULT 0,
  `revenue_cents` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `avg_session_sec` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
