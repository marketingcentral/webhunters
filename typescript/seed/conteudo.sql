-- Extract Hunters — CONTEÚDO do jogo para o D1 (SQLite). GERADO: não edite à mão.
-- Fonte: MySQL local, só leitura, por typescript/scripts/export-seed.php
--   C:/wamp64/bin/php/php8.3.28/php.exe typescript/scripts/export-seed.php
--   cd typescript && npx wrangler d1 execute DB --local --file=seed/conteudo.sql
-- Sem contas nem dado de jogador. Colunas que apontam para conta saem NULL.
-- UPSERT por chave primária: reaplicar atualiza o catálogo sem apagar nada (sem cascata).
-- Linhas apagadas do catálogo no MySQL NÃO são apagadas no D1 por este arquivo.
-- 503 linhas:
--   items                  49
--   equipment_slots        12
--   maps                   13
--   map_point_types        42
--   map_markers            65
--   missions               13
--   mission_rewards        39
--   achievements           16
--   achievement_rewards    38
--   shop_offers            26
--   skills                120
--   news                    8
--   seasons                 1
--   season_tiers            3
--   challenges             15
--   challenge_bonuses       3
--   government_bases        3
--   safe_zones              5
--   faq                     4
--   support_categories      6
--   server_regions          4
--   server_services         3
--   maintenance_windows     3
--   admin_settings         12
--   system_notices          0

-- ---------------------------------------------------------------- items (49 linhas)
INSERT INTO "items" ("id", "slug", "name", "short_name", "category", "subtype", "slot_type", "grid_w", "grid_h", "weight_kg", "rarity", "image", "description", "base_price", "currency", "stackable", "max_stack", "durability_max", "grid_cols", "grid_rows", "ammo_caliber", "mag_capacity", "attach_slots", "fits_calibers", "meta") VALUES
(1, 'ak103', 'AK-103', 'AK-103', 'weapon', 'assault_rifle', 'primary', 5, 2, 3.600, 'comum', 'items/ak103.png', 'Fuzil de assalto russo 7.62x39. Robusto e confiável.', 48500, 'credits', 0, 1, 100, NULL, NULL, '7.62x39', NULL, '[{"key": "sight", "label": "MIRA", "accepts": ["sight"]}, {"key": "muzzle", "label": "CANO", "accepts": ["suppressor", "muzzle"]}, {"key": "grip", "label": "EMPUNHADURA", "accepts": ["grip"]}, {"key": "mag", "label": "CARREGADOR", "accepts": ["mag"]}]', '["7.62x39"]', NULL),
(2, 'm4a1', 'M4A1', 'M4A1', 'weapon', 'assault_rifle', 'primary', 5, 2, 3.100, 'comum', 'items/m4a1.png', 'Carabina modular 5.56x45 NATO. Alta precisão e customização.', 52000, 'credits', 0, 1, 100, NULL, NULL, '5.56x45', NULL, '[{"key": "sight", "label": "MIRA", "accepts": ["sight"]}, {"key": "muzzle", "label": "CANO", "accepts": ["suppressor", "muzzle"]}, {"key": "foregrip", "label": "GRIP FRONTAL", "accepts": ["grip", "foregrip"]}, {"key": "tactical", "label": "TÁTICO", "accepts": ["tactical", "flashlight"]}, {"key": "mag", "label": "CARREGADOR", "accepts": ["mag"]}]', '["5.56x45"]', NULL),
(3, 'glock17', 'Glock 17', 'G17', 'pistol', 'pistol', 'pistol', 2, 1, 0.900, 'comum', 'items/glock17.png', 'Pistola semiautomática 9x19mm. Leve e discreta.', 12800, 'credits', 0, 1, 100, NULL, NULL, '9x19', NULL, '[{"key": "sight", "label": "MIRA", "accepts": ["sight"]}, {"key": "muzzle", "label": "CANO", "accepts": ["suppressor"]}, {"key": "mag", "label": "CARREGADOR", "accepts": ["mag"]}]', '["9x19"]', NULL),
(4, 'knife-bayonet', 'Baioneta 6X4', 'FACA', 'knife', 'melee', 'knife', 1, 2, 0.300, 'comum', 'items/knife-bayonet.png', 'Baioneta de combate. Silenciosa e sempre pronta.', 7400, 'credits', 0, 1, 100, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(5, 'ak-mag', 'Carregador AK 7.62', 'MAG AK', 'mag', 'magazine', NULL, 1, 2, 0.400, 'comum', 'items/ak-mag.png', 'Carregador curvo de 30 munições 7.62x39.', 2100, 'credits', 0, 1, NULL, NULL, NULL, '7.62x39', 30, NULL, NULL, NULL),
(6, 'm4-mag', 'Carregador M4 5.56', 'MAG M4', 'mag', 'magazine', NULL, 1, 2, 0.300, 'comum', 'items/m4-mag.png', 'Carregador STANAG de 30 munições 5.56x45.', 1900, 'credits', 0, 1, NULL, NULL, NULL, '5.56x45', 30, NULL, NULL, NULL),
(7, 'ammo-box', 'Caixa de Munição', 'MUNIÇÃO', 'ammo', 'ammo_box', NULL, 2, 2, 1.200, 'comum', 'items/ammo-box.png', 'Caixa com 60 munições 7.62x39.', 480, 'cash', 1, 60, NULL, NULL, NULL, '7.62x39', NULL, NULL, NULL, NULL),
(8, 'm67-grenade', 'Granada M67', 'M67', 'grenade', 'frag', NULL, 1, 1, 0.400, 'comum', 'items/m67-grenade.png', 'Granada de fragmentação. Raio letal de 5m.', 4900, 'credits', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(9, 'flashbang', 'Granada de Luz', 'FLASH', 'grenade', 'flash', NULL, 1, 1, 0.200, 'comum', 'items/flashbang.png', 'Granada de atordoamento. Cega e ensurdece.', 2200, 'credits', 1, 5, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(10, 'helmet-fast', 'Capacete FAST MT', 'FAST MT', 'helmet', 'helmet', 'helmet', 2, 2, 1.100, 'comum', 'items/helmet-fast.png', 'Capacete balístico classe 4 com trilhos.', 21000, 'credits', 0, 1, 56, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(11, 'gasmask-gp7', 'Máscara GP-7', 'GP-7', 'mask', 'gasmask', 'mask', 2, 2, 0.800, 'comum', 'items/gasmask-gp7.png', 'Máscara contra gases com filtro. Protege contra toxinas.', 9600, 'credits', 0, 1, 100, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(12, 'glasses-aviator', 'Óculos Aviador', 'AVIATOR', 'glasses', 'glasses', 'glasses', 2, 1, 0.100, 'comum', 'items/glasses-aviator.png', 'Óculos táticos. Reduz o brilho e protege os olhos.', 320, 'points', 0, 1, 30, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(13, 'vest-83', 'Colete VEST-83', 'VEST-83', 'armor', 'plate_carrier', 'armor', 3, 3, 5.200, 'comum', 'items/vest-83.png', 'Colete balístico classe 3 com placas frontais.', 34500, 'credits', 0, 1, 80, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(14, 'clothing-tactical', 'Traje Caçador', 'TÁTICA', 'clothing', 'jacket', 'armor', 3, 3, 1.400, 'comum', 'items/clothing-tactical.png', 'Uniforme completo camuflado para floresta.', 2400, 'points', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(15, 'armband-usec', 'Braçadeira USEC', 'USEC', 'armband', 'armband', 'armband', 1, 1, 0.050, 'comum', 'items/armband-usec.png', 'Braçadeira de identificação de facção.', 450, 'points', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(16, 'headset-comtac', 'Fone Tático ComTac', 'COMTAC', 'earpiece', 'headset', 'earpiece', 2, 2, 0.300, 'comum', 'items/headset-comtac.png', 'Protetor auricular ativo: amplifica passos e abafa disparos.', 8600, 'credits', 0, 1, 100, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(17, 'tactical-rig', 'Coldre Tático RIG-03', 'RIG-03', 'rig', 'chest_rig', 'rig', 3, 3, 1.100, 'comum', 'items/tactical-rig.png', 'Colete de combate com 8 compartimentos de acesso rápido.', 15600, 'credits', 0, 1, NULL, 5, 2, NULL, NULL, NULL, NULL, NULL),
(18, 'backpack-45l', 'Mochila Trilha 45L', 'TRILHA 45L', 'backpack', 'backpack', 'backpack', 3, 4, 1.800, 'comum', 'items/backpack-45l.png', 'Mochila de campanha com 45L de capacidade.', 18900, 'credits', 0, 1, NULL, 5, 5, NULL, NULL, NULL, NULL, NULL),
(19, 'weapon-case', 'Maleta de Arma', 'MALETA', 'misc', 'case', NULL, 4, 2, 2.500, 'comum', 'items/weapon-case.png', 'Contém uma arma aleatória. Também guarda armas.', 900, 'cash', 0, 1, NULL, 6, 3, NULL, NULL, NULL, NULL, NULL),
(20, 'supply-crate', 'Caixa de Suprimentos', 'SUPRIMENTOS', 'misc', 'crate', NULL, 2, 2, 3.000, 'comum', 'items/supply-crate.png', 'Contém itens e recursos aleatórios.', 350, 'cash', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(21, 'medkit', 'Kit Médico', 'KIT MÉD', 'med', 'medkit', NULL, 1, 1, 0.600, 'comum', 'items/medkit.png', 'Restaura até 100 de saúde. Reutilizável.', 3200, 'credits', 0, 1, 300, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(22, 'surgical-kit', 'Kit Cirúrgico', 'CIRÚRGICO', 'med', 'surgical', NULL, 2, 1, 0.800, 'comum', 'items/surgical-kit.png', 'Restaura membros destruídos. Uso demorado.', 9800, 'credits', 0, 1, 2, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(23, 'bandage', 'Bandagem', 'BANDAGEM', 'med', 'bandage', NULL, 1, 1, 0.100, 'comum', 'items/bandage.png', 'Estanca sangramentos. Restaura até 50 de saúde.', 850, 'credits', 1, 3, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(24, 'analgesic', 'Analgésico', 'ANALGÉSICO', 'med', 'painkiller', NULL, 1, 1, 0.100, 'comum', 'items/analgesic.png', 'Remove dor e restaura 25 de saúde.', 1100, 'credits', 1, 3, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(25, 'xray', 'Raio-X Portátil', 'RAIO-X', 'med', 'diagnostic', NULL, 2, 2, 1.500, 'comum', 'items/xray.png', 'Diagnóstico de fraturas e corpos estranhos.', 2600, 'credits', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(26, 'blood-test', 'Hemograma', 'HEMOGRAMA', 'med', 'diagnostic', NULL, 1, 1, 0.200, 'comum', 'items/blood-test.png', 'Detecta infecções e toxinas no sangue.', 1400, 'credits', 1, 3, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(27, 'clipboard', 'Prancheta Médica', 'PRANCHETA', 'med', 'diagnostic', NULL, 1, 2, 0.300, 'comum', 'items/clipboard.png', 'Registra o estado de saúde do operador.', 900, 'credits', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(28, 'water-bottle', 'Cantil de Água', 'ÁGUA', 'drink', 'water', NULL, 1, 2, 0.600, 'comum', 'items/water-bottle.png', 'Restaura hidratação. 60 de capacidade.', 620, 'credits', 0, 1, 60, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(29, 'canned-food', 'Ração Enlatada', 'LATA', 'food', 'canned', NULL, 1, 1, 0.400, 'comum', 'items/canned-food.png', 'Restaura fome. Não estraga.', 740, 'credits', 1, 3, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(30, 'dried-meat', 'Carne Seca', 'CARNE', 'food', 'meat', NULL, 1, 1, 0.200, 'comum', 'items/dried-meat.png', 'Restaura fome rapidamente.', 520, 'credits', 1, 3, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(31, 'cereal-bar', 'Barra de Cereal', 'CEREAL', 'food', 'snack', NULL, 1, 1, 0.100, 'comum', 'items/cereal-bar.png', 'Lanche rápido. Restaura pouca fome e energia.', 280, 'credits', 1, 2, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(32, 'coffee', 'Estimulante de XP', 'CAFÉ', 'drink', 'stimulant', NULL, 1, 1, 0.200, 'comum', 'items/coffee.png', '+50% de XP por 1 hora.', 250, 'points', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(33, 'jerrycan', 'Galão de Combustível', 'GALÃO', 'misc', 'fuel', NULL, 2, 3, 8.000, 'comum', 'items/jerrycan.png', 'Combustível para veículos e geradores.', 1900, 'credits', 0, 1, 20, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(34, 'rope', 'Corda de Rapel', 'CORDA', 'tool', 'rope', NULL, 2, 2, 1.200, 'comum', 'items/rope.png', 'Permite descer de estruturas altas.', 680, 'credits', 1, 5, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(35, 'multitool', 'Multitool', 'MULTITOOL', 'tool', 'tool', NULL, 1, 1, 0.300, 'comum', 'items/multitool.png', 'Ferramenta para reparos rápidos em campo.', 1300, 'credits', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(36, 'tools-wrench', 'Chave Inglesa', 'CHAVE', 'tool', 'tool', NULL, 1, 2, 0.700, 'comum', 'items/tools-wrench.png', 'Abre válvulas e desmonta equipamentos.', 540, 'credits', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(37, 'tools-generic', 'Conjunto de Ferramentas', 'FERRAMENTAS', 'tool', 'toolkit', NULL, 2, 2, 1.500, 'comum', 'items/tools-generic.png', 'Kit completo de ferramentas de manutenção.', 2200, 'credits', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(38, 'radio', 'Rádio de Campo', 'RÁDIO', 'tool', 'radio', NULL, 1, 2, 0.900, 'comum', 'items/radio.png', 'Comunicação de longo alcance. Chama extração.', 11200, 'credits', 0, 1, 100, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(39, 'compass', 'Bússola', 'BÚSSOLA', 'tool', 'compass', NULL, 1, 1, 0.150, 'comum', 'items/compass.png', 'Orientação no terreno. Mostra o norte.', 480, 'credits', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(40, 'sleeping-bag', 'Saco de Dormir', 'SACO', 'misc', 'bedroll', NULL, 3, 2, 1.600, 'comum', 'items/sleeping-bag.png', 'Ponto de retorno seguro. Recupera energia.', 1200, 'credits', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(41, 'keycard', 'Cartão de Acesso', 'CARTÃO', 'key', 'keycard', NULL, 1, 1, 0.020, 'comum', 'items/keycard.png', 'Abre portas de segurança em bases militares.', 1500, 'cash', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(42, 'skin-camo', 'Camuflagem Mata Fechada', 'CAMO', 'skin', 'weapon_skin', NULL, 1, 1, 0.100, 'comum', 'items/skin-camo.png', 'Skin de arma camuflada. Apenas cosmético.', 1200, 'points', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(43, 'att-reddot', 'Red Dot EKP-8', 'RED DOT', 'attachment', 'sight', NULL, 1, 1, 0.200, 'incomum', 'items/att-reddot.png', 'Mira de ponto vermelho. Aquisição rápida de alvos.', 6400, 'credits', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(44, 'att-scope', 'Luneta 4x PSO', 'LUNETA', 'attachment', 'sight', NULL, 2, 1, 0.500, 'incomum', 'items/att-scope.png', 'Luneta 4x para engajamentos de média distância.', 9800, 'credits', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(45, 'att-suppressor', 'Silenciador PBS-4', 'SILENC.', 'attachment', 'suppressor', NULL, 2, 1, 0.400, 'incomum', 'items/att-suppressor.png', 'Reduz o ruído e o clarão do disparo.', 12200, 'credits', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(46, 'att-grip', 'Empunhadura RK-2', 'GRIP', 'attachment', 'grip', NULL, 1, 1, 0.100, 'incomum', 'items/att-grip.png', 'Empunhadura ergonômica. Reduz o recuo.', 3600, 'credits', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(47, 'att-foregrip', 'Grip Frontal Tático', 'FOREGRIP', 'attachment', 'foregrip', NULL, 1, 1, 0.100, 'incomum', 'items/att-foregrip.png', 'Grip frontal. Melhora a estabilidade.', 3200, 'credits', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(48, 'att-flashlight', 'Lanterna Tática X400', 'LANTERNA', 'attachment', 'tactical', NULL, 1, 1, 0.150, 'incomum', 'items/att-flashlight.png', 'Lanterna tática com laser integrado.', 4100, 'credits', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(49, 'glock-mag', 'Carregador Glock 9mm', 'MAG G17', 'mag', 'magazine', NULL, 1, 1, 0.150, 'comum', 'items/glock-mag.png', 'Carregador reto de 17 munições 9x19.', 1200, 'credits', 0, 1, NULL, NULL, NULL, '9x19', 17, NULL, NULL, NULL)
ON CONFLICT ("id") DO UPDATE SET "slug" = excluded."slug", "name" = excluded."name", "short_name" = excluded."short_name", "category" = excluded."category", "subtype" = excluded."subtype", "slot_type" = excluded."slot_type", "grid_w" = excluded."grid_w", "grid_h" = excluded."grid_h", "weight_kg" = excluded."weight_kg", "rarity" = excluded."rarity", "image" = excluded."image", "description" = excluded."description", "base_price" = excluded."base_price", "currency" = excluded."currency", "stackable" = excluded."stackable", "max_stack" = excluded."max_stack", "durability_max" = excluded."durability_max", "grid_cols" = excluded."grid_cols", "grid_rows" = excluded."grid_rows", "ammo_caliber" = excluded."ammo_caliber", "mag_capacity" = excluded."mag_capacity", "attach_slots" = excluded."attach_slots", "fits_calibers" = excluded."fits_calibers", "meta" = excluded."meta";

-- ---------------------------------------------------------------- equipment_slots (12 linhas)
INSERT INTO "equipment_slots" ("slot_key", "label", "accepts", "sort_order") VALUES
('armband', 'BRAÇADEIRA', '["armband"]', 4),
('armor', 'COLETE', '["armor", "clothing"]', 5),
('backpack', 'MOCHILA', '["backpack"]', 12),
('earpiece', 'FONE', '["earpiece"]', 1),
('glasses', 'ÓCULOS', '["glasses"]', 6),
('helmet', 'CAPACETE', '["helmet"]', 2),
('knife', 'BAINHA', '["knife"]', 10),
('mask', 'MÁSCARA', '["mask"]', 3),
('pistol', 'COLDRE', '["pistol"]', 8),
('primary', 'NA BANDOLEIRA', '["weapon"]', 7),
('rig', 'COLETE TÁTICO', '["rig"]', 11),
('secondary', 'NAS COSTAS', '["weapon"]', 9)
ON CONFLICT ("slot_key") DO UPDATE SET "label" = excluded."label", "accepts" = excluded."accepts", "sort_order" = excluded."sort_order";

-- ---------------------------------------------------------------- maps (13 linhas)
INSERT INTO "maps" ("id", "slug", "name", "kind", "mode", "players", "level_min", "level_max", "locked", "image", "description", "unreal_level", "meta", "sort_order") VALUES
(1, 'regiao-floresta-verde', 'FLORESTA VERDE', 'region', NULL, NULL, 1, 10, 0, 'regions/region-floresta-verde.jpg', 'Uma vasta floresta temperada, rica em recursos naturais mas também cheia de perigos. Ideal para iniciantes em busca de suprimentos e sobrevivência.', NULL, '{"key": "floresta-verde", "icon": "pine", "info": [{"cls": "lime", "label": "NÍVEL RECOMENDADO", "value": "1-10"}, {"tip": "Até 12 Hunters e 8 Mercenários. Sobreviventes ocupam vagas existentes.", "label": "CAPACIDADE OPERACIONAL", "value": "20"}, {"icon": "partly_cloudy_day", "label": "CLIMA ATUAL", "value": "Variável"}, {"cls": "lime", "label": "MUNDO", "value": "PERSISTENTE"}], "event": {"name": "ENTREGA AÉREA", "ends_in": "12:34", "ends_label": "TERMINA EM"}, "level": "1-10", "weather": "Variável", "capacity": {"total": 20, "hunters": 12, "mercenaries": 8}, "persistent": true, "extractions": [{"name": "PLATAFORMA NORTE", "type": "governamental", "state": "active", "status": "ATIVA"}, {"name": "ROTA SUL", "type": "publica", "state": "active", "status": "ATIVA"}, {"name": "TRANSPORTE AEGIS · PONTE LESTE", "type": "aegis", "state": "conditional", "status": "CONDICIONAL"}, {"name": "ROTA FRONTIER · RIO OESTE", "type": "frontier", "state": "inactive", "status": "INATIVA"}], "infestation": "42", "world_image": "bg/map-world.webp", "sector_state": "ATIVO", "human_activity": "ELEVADA", "veyra_activity": "MODERADA"}', 0),
(2, 'regiao-pantano-cinzento', 'PÂNTANO CINZENTO', 'region', NULL, NULL, 10, 20, 1, 'regions/region-pantano-cinzento.jpg', 'Águas paradas, névoa densa e doenças à espreita. Cada passo custa energia e cada ferimento pode infeccionar antes da extração.', NULL, '{"key": "pantano-cinzento", "icon": "swamp", "info": [{"cls": "lime", "label": "NÍVEL RECOMENDADO", "value": "10-20"}, {"tip": "Até 12 Hunters e 8 Mercenários. Sobreviventes ocupam vagas existentes.", "label": "CAPACIDADE OPERACIONAL", "value": "20"}, {"icon": "foggy", "label": "CLIMA ATUAL", "value": "Neblina"}, {"cls": "lime", "label": "MUNDO", "value": "PERSISTENTE"}], "event": null, "level": "10-20", "weather": "Neblina", "capacity": {"total": 20, "hunters": 12, "mercenaries": 8}, "persistent": true, "extractions": [{"name": "EVAC HUNTER · PLATAFORMA NORTE", "type": "governamental", "state": "active", "status": "ATIVA"}, {"name": "BALSA DO PÂNTANO", "type": "publica", "state": "conditional", "status": "CONDICIONAL"}, {"name": "ROTA FRONTIER · TÚNEIS SECOS", "type": "frontier", "state": "active", "status": "ATIVA"}], "infestation": "58", "world_image": "bg/map-world.webp", "sector_state": "ATIVO", "human_activity": "MODERADA", "veyra_activity": "ELEVADA"}', 1),
(3, 'regiao-montanhas-gelidas', 'MONTANHAS GÉLIDAS', 'region', NULL, NULL, 20, 30, 1, 'regions/region-montanhas-gelidas.jpg', 'Picos congelados e bases abandonadas sob a neve. A hipotermia mata mais rápido do que qualquer caçador nesta região.', NULL, '{"key": "montanhas-gelidas", "icon": "mountain", "info": [{"cls": "lime", "label": "NÍVEL RECOMENDADO", "value": "20-30"}, {"tip": "Até 12 Hunters e 8 Mercenários. Sobreviventes ocupam vagas existentes.", "label": "CAPACIDADE OPERACIONAL", "value": "20"}, {"icon": "weather_snowy", "label": "CLIMA ATUAL", "value": "Nevasca"}, {"cls": "lime", "label": "MUNDO", "value": "PERSISTENTE"}], "event": null, "level": "20-30", "weather": "Nevasca", "capacity": {"total": 20, "hunters": 12, "mercenaries": 8}, "persistent": true, "extractions": [{"name": "TELEFÉRICO DO PICO", "type": "publica", "state": "active", "status": "ATIVA"}, {"name": "TRANSPORTE AEGIS · HELIPONTO LESTE", "type": "aegis", "state": "conditional", "status": "CONDICIONAL"}, {"name": "ROTA FRONTIER · GALERIA DE MINERAÇÃO", "type": "frontier", "state": "conditional", "status": "CONDICIONAL"}], "infestation": "35", "world_image": "bg/map-world.webp", "sector_state": "DISPUTADO", "human_activity": "ELEVADA", "veyra_activity": "MODERADA"}', 2),
(4, 'regiao-deserto-abandonado', 'DESERTO ABANDONADO', 'region', NULL, NULL, 30, 40, 1, 'regions/region-deserto-abandonado.jpg', 'Dunas intermináveis e cidades engolidas pela areia. Aqui a água vale mais do que munição e a sombra vale mais do que ouro.', NULL, '{"key": "deserto-abandonado", "icon": "cactus", "info": [{"cls": "lime", "label": "NÍVEL RECOMENDADO", "value": "30-40"}, {"tip": "Até 12 Hunters e 8 Mercenários. Sobreviventes ocupam vagas existentes.", "label": "CAPACIDADE OPERACIONAL", "value": "20"}, {"icon": "air", "label": "CLIMA ATUAL", "value": "Tempestade"}, {"cls": "lime", "label": "MUNDO", "value": "PERSISTENTE"}], "event": null, "level": "30-40", "weather": "Tempestade", "capacity": {"total": 20, "hunters": 12, "mercenaries": 8}, "persistent": true, "extractions": [{"name": "OÁSIS DO NORTE", "type": "publica", "state": "active", "status": "ATIVA"}, {"name": "COMBOIO AEGIS", "type": "aegis", "state": "inactive", "status": "INATIVA"}, {"name": "ROTA FRONTIER · CANAL SECO", "type": "frontier", "state": "conditional", "status": "CONDICIONAL"}], "infestation": "47", "world_image": "bg/map-world.webp", "sector_state": "GUERRA ECONÔMICA", "human_activity": "CRÍTICA", "veyra_activity": "MODERADA"}', 3),
(5, 'regiao-zona-industrial', 'ZONA INDUSTRIAL', 'region', NULL, NULL, 40, NULL, 1, 'regions/region-zona-industrial.jpg', 'O coração da facção inimiga: fábricas, depósitos e o saque mais valioso do jogo, defendido por tudo o que eles têm.', NULL, '{"key": "zona-industrial", "icon": "factory", "info": [{"cls": "lime", "label": "NÍVEL RECOMENDADO", "value": "40+"}, {"tip": "Até 12 Hunters e 8 Mercenários. Sobreviventes ocupam vagas existentes.", "label": "CAPACIDADE OPERACIONAL", "value": "20"}, {"icon": "rainy", "label": "CLIMA ATUAL", "value": "Chuva ácida"}, {"cls": "lime", "label": "MUNDO", "value": "PERSISTENTE"}], "event": null, "level": "40+", "weather": "Chuva ácida", "capacity": {"total": 20, "hunters": 12, "mercenaries": 8}, "persistent": true, "extractions": [{"name": "EVAC HUNTER · TÚNEL DE CARGA", "type": "governamental", "state": "conditional", "status": "CONDICIONAL"}, {"name": "PÁTIO FERROVIÁRIO", "type": "publica", "state": "inactive", "status": "INATIVA"}, {"name": "CLANDESTINA · GALERIA 7", "type": "clandestina", "state": "conditional", "status": "CONDICIONAL"}], "infestation": "88", "world_image": "bg/map-world.webp", "sector_state": "NÚCLEO NEGRO", "human_activity": "CRÍTICA", "veyra_activity": "CRÍTICA"}', 4),
(6, 'mapa-vale-do-eco', 'VALE DO ECO', 'match', 'MATA-MATA EM EQUIPE', '6v6', NULL, NULL, 0, 'maps/vale-do-eco.jpg', 'Vale florestado entre encostas íngremes, com torres de vigia e um heliporto central. Rotas longas favorecem duelos de média distância.', '/Game/FPS_Controller/Maps/MainMenu', '{"best": "04:12", "icon": "emblem-hex", "size": "MÉDIO", "biome": "Floresta / Montanha", "best_by": "V1P3R_88", "weather": "Nublado", "capacity": {"total": 20, "hunters": 12, "mercenaries": 8}, "selected": true, "your_best": "05:47", "persistent": true, "infestation": "38", "human_activity": "MODERADA"}', 0),
(7, 'mapa-base-artica', 'BASE ÁRTICA', 'match', 'DOMINAÇÃO', '6v6', NULL, NULL, 0, 'maps/base-artica.jpg', 'Complexo de pesquisa soterrado pela neve. Corredores fechados ligam os hangares e a nevasca cega quem cruza o pátio.', '/Game/FPS_Controller/Maps/MainMenu', '{"best": "06:38", "icon": "mk-base", "size": "GRANDE", "biome": "Neve / Tundra", "best_by": "KOLD_RUN", "weather": "Nevasca", "capacity": {"total": 20, "hunters": 12, "mercenaries": 8}, "selected": false, "your_best": "08:02", "persistent": true, "infestation": "61", "human_activity": "ELEVADA"}', 1),
(8, 'mapa-porto-industrial', 'PORTO INDUSTRIAL', 'match', 'ZONA DE CONFLITO', '6v6', NULL, NULL, 0, 'maps/porto-industrial.jpg', 'Pátio de contêineres e guindastes sobre o cais. A verticalidade das pilhas cria ninhos de atirador e emboscadas curtas.', '/Game/FPS_Controller/Maps/MainMenu', '{"best": "05:03", "icon": "emblem-hex", "size": "MÉDIO", "biome": "Portuário", "best_by": "DOCA_9", "weather": "Chuva leve", "capacity": {"total": 20, "hunters": 12, "mercenaries": 8}, "selected": false, "your_best": "06:21", "persistent": true, "infestation": "55", "human_activity": "CRÍTICA"}', 2),
(9, 'mapa-cidade-em-ruinas', 'CIDADE EM RUÍNAS', 'match', 'MATA-MATA EM EQUIPE', '6v6', NULL, NULL, 0, 'maps/cidade-em-ruinas.jpg', 'Quarteirões arrasados pelo bombardeio. Prédios sem fachada abrem linhas de tiro cruzadas e o subsolo liga os dois lados.', '/Game/FPS_Controller/Maps/MainMenu', '{"best": "07:15", "icon": "emblem-hex", "size": "GRANDE", "biome": "Urbano", "best_by": "ASH_TOWER", "weather": "Limpo", "capacity": {"total": 20, "hunters": 12, "mercenaries": 8}, "selected": false, "your_best": "07:58", "persistent": true, "infestation": "83", "human_activity": "ELEVADA"}', 3),
(10, 'mapa-fabrica-abandonada', 'FÁBRICA ABANDONADA', 'match', 'DOMINAÇÃO', '6v6', NULL, NULL, 0, 'maps/fabrica-abandonada.jpg', 'Galpões de metalurgia tomados por ferrugem e neblina. Combate rápido entre esteiras, fornos e passarelas suspensas.', '/Game/FPS_Controller/Maps/MainMenu', '{"best": "03:47", "icon": "mk-base", "size": "PEQUENO", "biome": "Industrial", "best_by": "HUNTER_07", "weather": "Neblina", "capacity": {"total": 20, "hunters": 12, "mercenaries": 8}, "selected": false, "your_best": "03:47", "persistent": true, "infestation": "29", "human_activity": "BAIXA"}', 4),
(11, 'mapa-deserto-seco', 'DESERTO SECO', 'match', 'ZONA DE CONFLITO', '6v6', NULL, NULL, 0, 'maps/deserto-seco.jpg', 'Aldeia de barro cercada por dunas e rochas. Sem cobertura no descampado, a tempestade de areia é o único avanço seguro.', '/Game/FPS_Controller/Maps/MainMenu', '{"best": "06:59", "icon": "emblem-hex", "size": "GRANDE", "biome": "Deserto", "best_by": "SAND_KING", "weather": "Tempestade de areia", "capacity": {"total": 20, "hunters": 12, "mercenaries": 8}, "selected": false, "your_best": "09:12", "persistent": true, "infestation": "46", "human_activity": "MODERADA"}', 5),
(12, 'mapa-favela', 'FAVELA', 'match', 'MATA-MATA EM EQUIPE', '6v6', NULL, NULL, 0, 'maps/favela.jpg', 'Encosta densa de becos, lajes e escadarias. Quem controla os telhados controla a rota de extração.', '/Game/FPS_Controller/Maps/MainMenu', '{"best": "04:40", "icon": "emblem-hex", "size": "MÉDIO", "biome": "Urbano denso", "best_by": "LAJE_MAX", "weather": "Limpo", "capacity": {"total": 20, "hunters": 12, "mercenaries": 8}, "selected": false, "your_best": "05:05", "persistent": true, "infestation": "52", "human_activity": "ELEVADA"}', 6),
(13, 'mapa-complexo-militar', 'COMPLEXO MILITAR', 'match', 'DOMINAÇÃO', '6v6', NULL, NULL, 0, 'maps/complexo-militar.jpg', 'Instalação de comando com radar, bunkers e pista de pouso. Objetivos distantes exigem rotação constante da equipe.', '/Game/FPS_Controller/Maps/MainMenu', '{"best": "08:21", "icon": "mk-base", "size": "GRANDE", "biome": "Base militar", "best_by": "ECHO_ZERO", "weather": "Nublado", "capacity": {"total": 20, "hunters": 12, "mercenaries": 8}, "selected": false, "your_best": "10:34", "persistent": true, "infestation": "67", "human_activity": "CRÍTICA"}', 7)
ON CONFLICT ("id") DO UPDATE SET "slug" = excluded."slug", "name" = excluded."name", "kind" = excluded."kind", "mode" = excluded."mode", "players" = excluded."players", "level_min" = excluded."level_min", "level_max" = excluded."level_max", "locked" = excluded."locked", "image" = excluded."image", "description" = excluded."description", "unreal_level" = excluded."unreal_level", "meta" = excluded."meta", "sort_order" = excluded."sort_order";

-- ---------------------------------------------------------------- map_point_types (42 linhas)
INSERT INTO "map_point_types" ("id", "slug", "label", "category", "kind", "icon", "color", "layer", "show_label", "description", "is_system", "sort_order") VALUES
(1, 'hunter-base', 'Base Hunter', 'operacional', 'gov_base', 'glyph:gov-base', 'lime', NULL, 1, 'Base governamental: até 4 Hunters, estado visível sem vagas exatas.', 1, 20),
(2, 'field-base', 'Base de campo', 'operacional', 'field_base', 'glyph:field-base', 'muted', NULL, 0, 'Base construída por jogador. O dono define se aparece como minha, do esquadrão ou desconhecida.', 1, 30),
(3, 'safe-zone', 'Safe Zone', 'operacional', 'safe_zone', 'glyph:safe-zone', 'lime', NULL, 1, 'Perímetro civil: mercado, oficina e trégua entre operações.', 1, 40),
(4, 'aegis-site', 'Instalação Aegis', 'operacional', 'aegis', 'glyph:aegis', 'cyan', NULL, 1, 'Posto fortificado da Aegis. O status muda conforme o contrato do jogador.', 1, 50),
(5, 'frontier-site', 'Acampamento Frontier', 'operacional', 'frontier', 'glyph:frontier', 'amber', NULL, 1, 'Ponto de apoio da Frontier em rota oculta.', 1, 60),
(6, 'extraction', 'Ponto de extração', 'operacional', 'extraction', 'glyph:extraction', 'lime', NULL, 0, 'Saída do setor. Bloqueada para Sobreviventes.', 1, 70),
(7, 'outpost', 'Posto avançado', 'operacional', 'base', 'glyph:base', 'lime', NULL, 0, 'Posição militar menor, sem capacidade de extração.', 1, 80),
(8, 'camp', 'Acampamento', 'operacional', 'camp', 'glyph:camp', 'lime', NULL, 0, 'Ocupação temporária.', 1, 90),
(9, 'radio-tower', 'Torre de rádio', 'operacional', 'radio', 'glyph:radio', 'white', NULL, 1, 'Retransmissor: intercepta sinais e amplia o alcance do esquadrão.', 1, 100),
(10, 'village', 'Vila / complexo', 'operacional', 'village', 'glyph:village', 'white', NULL, 1, 'Assentamento civil, ocupado ou abandonado.', 1, 110),
(11, 'veyra-nest', 'Ninho Veyra', 'hostil', 'nest', 'glyph:nest', 'red', NULL, 1, 'Matriz ativa: alimenta a colônia em volta e atrai Errantes. Combate prolongado aqui eleva a infestação; destruí-la faz o fungo recuar.', 1, 120),
(12, 'veyra-colony', 'Colônia Veyra', 'hostil', 'colony', 'glyph:nest', 'red', NULL, 1, 'Colônia madura: micélio no chão, Véus e Sopradores. Os pulsos do Coro passam por aqui.', 1, 130),
(13, 'veyra-core', 'Núcleo Negro', 'hostil', 'core', 'glyph:nest', 'purple', NULL, 1, 'O corpo do meteoro Vesper, enterrado sob a Zona Industrial. Centro do Coro e objetivo de missão de Ciência.', 1, 140),
(14, 'veyra-surge', 'Surto', 'hostil', 'surge', 'glyph:nest', 'red', NULL, 1, 'Infestação no limite: infectados em massa e risco de Amálgama. Temporário e móvel.', 1, 150),
(15, 'contaminated', 'Área contaminada', 'hostil', 'contaminated', 'glyph:contaminated', 'purple', NULL, 0, 'Nuvem densa de esporo: a contaminação sobe enquanto o operador fica ali sem máscara.', 1, 160),
(16, 'human-signal', 'Atividade humana', 'hostil', 'human_activity', 'glyph:human', 'amber', NULL, 0, 'Sinal temporário e de posição aproximada. Desenha um raio, não um ponto.', 1, 170),
(17, 'convoy', 'Comboio', 'logistica', 'convoy', 'glyph:convoy', 'cyan', NULL, 0, 'Janela logística: carga em movimento.', 1, 180),
(18, 'supply-drop', 'Entrega aérea', 'logistica', 'supply_drop', 'glyph:convoy', 'cyan', NULL, 0, 'Queda programada de suprimento.', 1, 190),
(19, 'abandoned-cargo', 'Carga abandonada', 'logistica', 'abandoned_cargo', 'glyph:convoy', 'amber', NULL, 0, 'Sobrou de um comboio que não chegou.', 1, 200),
(20, 'resupply', 'Reabastecimento', 'logistica', 'resupply', 'glyph:convoy', 'cyan', NULL, 0, 'Ponto de recomposição de munição e consumíveis.', 1, 210),
(22, 'fuel-depot', 'Depósito de combustível', 'combustivel', 'fuel', 'symbol:propane_tank', 'amber', NULL, 0, 'Estoque em volume. Alvo de alto valor.', 0, 230),
(23, 'charger', 'Carregador elétrico', 'combustivel', 'fuel', 'symbol:ev_station', 'cyan', NULL, 0, 'Recarga de equipamento e veículo elétrico.', 0, 240),
(24, 'cafe', 'Café', 'alimentacao', 'market', 'symbol:local_cafe', 'gold', NULL, 0, 'Cafeteria em pé. Ponto de encontro dentro de zona segura.', 0, 250),
(25, 'restaurant', 'Restaurante', 'alimentacao', 'market', 'symbol:restaurant', 'gold', NULL, 0, 'Refeição quente: recupera resistência entre operações.', 0, 260),
(26, 'diner', 'Lanchonete', 'alimentacao', 'market', 'symbol:lunch_dining', 'gold', NULL, 0, 'Comida rápida, barata e sem perguntas.', 0, 270),
(27, 'bar', 'Bar', 'alimentacao', 'market', 'symbol:local_bar', 'gold', NULL, 0, 'Onde a informação circula antes de virar contrato.', 0, 280),
(28, 'water-point', 'Ponto de água', 'alimentacao', 'loot', 'symbol:water_drop', 'cyan', NULL, 0, 'Água potável verificada.', 0, 290),
(29, 'market', 'Mercado', 'comercio', 'market', 'glyph:market', 'gold', NULL, 0, 'Compra e venda de mantimentos.', 0, 300),
(30, 'mall', 'Shopping', 'comercio', 'market', 'symbol:local_mall', 'gold', NULL, 1, 'Complexo comercial: muitas lojas, muitos andares, muito lugar para emboscada.', 0, 310),
(31, 'convenience', 'Loja de conveniência', 'comercio', 'market', 'symbol:storefront', 'gold', NULL, 0, 'Estoque pequeno, sempre aberto.', 0, 320),
(32, 'pharmacy', 'Farmácia', 'comercio', 'market', 'symbol:local_pharmacy', 'lime', NULL, 0, 'Medicamentos e material de curativo.', 0, 330),
(33, 'workshop', 'Oficina', 'comercio', 'market', 'symbol:build', 'white', NULL, 0, 'Reparo de arma, armadura e veículo.', 0, 340),
(34, 'bank', 'Casa de câmbio', 'comercio', 'market', 'symbol:payments', 'gold', NULL, 0, 'Converte créditos operacionais.', 0, 350),
(35, 'hospital', 'Hospital de campanha', 'saude', 'hospital', 'glyph:hospital', 'white', NULL, 0, 'Tratamento completo, inclusive de contaminação Veyra.', 0, 360),
(36, 'medic-post', 'Posto médico', 'saude', 'hospital', 'symbol:medical_services', 'lime', NULL, 0, 'Primeiros socorros e estabilização.', 0, 370),
(37, 'decontamination', 'Descontaminação', 'saude', 'hospital', 'symbol:sanitizer', 'purple', NULL, 0, 'Reduz a contaminação acumulada.', 0, 380),
(38, 'world-event', 'Evento de mundo', 'outros', 'event', 'glyph:event', 'purple', NULL, 0, 'Acontecimento com janela de tempo.', 1, 390),
(39, 'resources', 'Área de recursos', 'outros', 'loot', 'glyph:loot', 'white', NULL, 0, 'Material de construção e componentes.', 1, 400),
(40, 'vehicle', 'Veículo', 'outros', 'loot', 'symbol:directions_car', 'white', NULL, 0, 'Transporte utilizável, se ainda tiver combustível.', 0, 410),
(41, 'landmark', 'Ponto de referência', 'outros', 'loot', 'symbol:flag', 'white', NULL, 1, 'Marco visual para orientação e ponto de encontro.', 0, 420),
(83, 'mission-objective', 'Objetivo de missão', 'operacional', 'mission', 'glyph:mission', 'gold', NULL, 1, 'Ponto de uma missão já cadastrada. O editor puxa o nome e os detalhes da própria missão.', 1, 10),
(655, 'fuel-station', 'Posto de gasolina', 'combustivel', 'fuel', 'glyph:fuel', 'amber', NULL, 0, 'Combustível para veículos. Ponto de parada óbvio — e por isso vigiado.', 0, 220)
ON CONFLICT ("id") DO UPDATE SET "slug" = excluded."slug", "label" = excluded."label", "category" = excluded."category", "kind" = excluded."kind", "icon" = excluded."icon", "color" = excluded."color", "layer" = excluded."layer", "show_label" = excluded."show_label", "description" = excluded."description", "is_system" = excluded."is_system", "sort_order" = excluded."sort_order";

-- ---------------------------------------------------------------- map_markers (65 linhas)
INSERT INTO "map_markers" ("id", "map_id", "kind", "label", "x", "y", "meta") VALUES
(15, 2, 'extraction', 'BALSA DO PÂNTANO', 0.3000, 0.6200, '{"id": "balsa-do-pantano", "name": "BALSA DO PÂNTANO", "risk": null, "color": "lime", "glyph": "extraction", "label": "below", "pulse": false, "danger": false, "narrow": false}'),
(16, 2, 'nest', 'NINHO DAS SANGUESSUGAS · MATRIZ', 0.6100, 0.2700, '{"id": "ninho-das-sanguessugas", "name": "NINHO DAS SANGUESSUGAS · MATRIZ", "risk": {"x": 62, "y": 28, "rx": 9}, "caste": "matriz", "color": "red", "glyph": "enemy", "label": "right", "pulse": false, "danger": true, "narrow": false}'),
(17, 2, 'loot', 'ÁREA DE SAQUE', 0.4400, 0.4500, '{"id": "saque-pantano-1", "name": "ÁREA DE SAQUE", "risk": null, "color": "white", "glyph": "loot", "label": false, "pulse": false, "danger": false, "narrow": false}'),
(18, 2, 'loot', 'ÁREA DE SAQUE', 0.7400, 0.6300, '{"id": "saque-pantano-2", "name": "ÁREA DE SAQUE", "risk": null, "color": "white", "glyph": "loot", "label": false, "pulse": false, "danger": false, "narrow": false}'),
(19, 2, 'base', 'POSTO DO PÂNTANO', 0.2000, 0.3500, '{"id": "posto-do-pantano", "name": "POSTO DO PÂNTANO", "risk": null, "color": "lime", "glyph": "base", "label": "below", "pulse": false, "danger": false, "narrow": false}'),
(20, 2, 'event', 'EVENTO DINÂMICO', 0.5500, 0.7200, '{"id": "evento-pantano", "name": "EVENTO DINÂMICO", "risk": null, "color": "purple", "glyph": "event", "label": false, "pulse": true, "danger": false, "narrow": false}'),
(21, 3, 'extraction', 'TELEFÉRICO DO PICO', 0.2700, 0.2400, '{"id": "teleferico-do-pico", "name": "TELEFÉRICO DO PICO", "risk": null, "color": "lime", "glyph": "extraction", "label": "below", "pulse": false, "danger": false, "narrow": false}'),
(22, 3, 'colony', 'BÚNKER NORTE · COLÔNIA DE VÉUS', 0.6300, 0.2000, '{"id": "bunker-norte", "name": "BÚNKER NORTE · COLÔNIA DE VÉUS", "risk": {"x": 64, "y": 21, "rx": 8.8}, "caste": "veu", "color": "red", "glyph": "enemy", "label": "right", "pulse": false, "danger": true, "narrow": false}'),
(23, 3, 'camp', 'ACAMPAMENTO DO GELO', 0.4200, 0.5800, '{"id": "acampamento-gelo", "name": "ACAMPAMENTO DO GELO", "risk": null, "color": "lime", "glyph": "camp", "label": "below", "pulse": false, "danger": false, "narrow": false}'),
(24, 3, 'loot', 'ÁREA DE SAQUE', 0.7100, 0.4800, '{"id": "saque-montanha-1", "name": "ÁREA DE SAQUE", "risk": null, "color": "white", "glyph": "loot", "label": false, "pulse": false, "danger": false, "narrow": false}'),
(25, 3, 'base', 'HELIPONTO LESTE', 0.8000, 0.7000, '{"id": "heliponto-leste", "name": "HELIPONTO LESTE", "risk": null, "color": "lime", "glyph": "base", "label": "below", "pulse": false, "danger": false, "narrow": false}'),
(26, 4, 'extraction', 'OÁSIS DO NORTE', 0.3800, 0.2200, '{"id": "oasis-do-norte", "name": "OÁSIS DO NORTE", "risk": null, "color": "lime", "glyph": "extraction", "label": "below", "pulse": false, "danger": false, "narrow": false}'),
(27, 4, 'colony', 'FORTE DAS DUNAS · COLÔNIA DE VIGIAS', 0.6600, 0.4500, '{"id": "forte-das-dunas", "name": "FORTE DAS DUNAS · COLÔNIA DE VIGIAS", "risk": {"x": 67, "y": 46, "rx": 9.6}, "caste": "vigia", "color": "red", "glyph": "enemy", "label": "right", "pulse": false, "danger": true, "narrow": false}'),
(28, 4, 'loot', 'ÁREA DE SAQUE', 0.2400, 0.6000, '{"id": "saque-deserto-1", "name": "ÁREA DE SAQUE", "risk": null, "color": "white", "glyph": "loot", "label": false, "pulse": false, "danger": false, "narrow": false}'),
(29, 4, 'base', 'COMBOIO DE CARGA', 0.5200, 0.7600, '{"id": "comboio-de-carga", "name": "COMBOIO DE CARGA", "risk": null, "color": "lime", "glyph": "base", "label": "below", "pulse": false, "danger": false, "narrow": false}'),
(30, 4, 'event', 'EVENTO DINÂMICO', 0.8000, 0.2600, '{"id": "evento-deserto", "name": "EVENTO DINÂMICO", "risk": null, "color": "purple", "glyph": "event", "label": false, "pulse": true, "danger": false, "narrow": false}'),
(31, 5, 'extraction', 'TÚNEL DE CARGA', 0.2200, 0.6800, '{"id": "tunel-de-carga", "name": "TÚNEL DE CARGA", "risk": null, "color": "lime", "glyph": "extraction", "label": "below", "pulse": false, "danger": false, "narrow": false}'),
(32, 5, 'core', 'COMPLEXO OMEGA · NÚCLEO NEGRO', 0.5800, 0.3300, '{"id": "complexo-omega", "name": "COMPLEXO OMEGA · NÚCLEO NEGRO", "risk": {"x": 59, "y": 34, "rx": 10.4}, "caste": "amalgama", "color": "red", "glyph": "enemy", "label": "right", "pulse": false, "danger": true, "narrow": false}'),
(33, 5, 'base', 'PÁTIO FERROVIÁRIO', 0.7900, 0.6200, '{"id": "patio-ferroviario", "name": "PÁTIO FERROVIÁRIO", "risk": null, "color": "lime", "glyph": "base", "label": "below", "pulse": false, "danger": false, "narrow": false}'),
(34, 5, 'loot', 'ÁREA DE SAQUE', 0.4000, 0.5500, '{"id": "saque-industrial-1", "name": "ÁREA DE SAQUE", "risk": null, "color": "white", "glyph": "loot", "label": false, "pulse": false, "danger": false, "narrow": false}'),
(35, 5, 'loot', 'ÁREA DE SAQUE', 0.6800, 0.7800, '{"id": "saque-industrial-2", "name": "ÁREA DE SAQUE", "risk": null, "color": "white", "glyph": "loot", "label": false, "pulse": false, "danger": false, "narrow": false}'),
(36, 5, 'event', 'EVENTO DINÂMICO', 0.3400, 0.2400, '{"id": "evento-industrial", "name": "EVENTO DINÂMICO", "risk": null, "color": "purple", "glyph": "event", "label": false, "pulse": true, "danger": false, "narrow": false}'),
(1067, 1, 'frontier', 'BASE FRONTIER', 0.1200, 0.3600, '{"id": "base-frontier-120360", "tag": "ÚLTIMA PARADA", "name": "BASE FRONTIER", "color": "amber", "label": "right", "faction": "frontier", "point_type": "frontier-site"}'),
(1068, 1, 'gov_base', 'BASE VIGIA · HUNTER COMMAND', 0.6200, 0.2500, '{"id": "base-vigia-hunter-command-620250", "base": "alpha", "name": "BASE VIGIA · HUNTER COMMAND", "color": "lime", "label": "right", "status": "OPERACIONAL", "point_type": "hunter-base"}'),
(1069, 1, 'gov_base', 'BASE HUNTER BRAVO', 0.8200, 0.1650, '{"id": "base-hunter-bravo-820165", "base": "bravo", "name": "BASE HUNTER BRAVO", "color": "lime", "label": "right", "status": "OPERACIONAL", "point_type": "hunter-base"}'),
(1070, 1, 'gov_base', 'BASE HUNTER CHARLIE', 0.7200, 0.6650, '{"id": "base-hunter-charlie-720665", "base": "charlie", "name": "BASE HUNTER CHARLIE", "color": "lime", "label": "right", "status": "EM RISCO", "point_type": "hunter-base"}'),
(1071, 1, 'aegis', 'FORTALEZA AEGIS', 0.2500, 0.3950, '{"id": "fortaleza-aegis-250395", "tag": "DISCIPLINA SOBRE O CAOS", "name": "FORTALEZA AEGIS", "color": "cyan", "label": "right", "faction": "aegis", "point_type": "aegis-site"}'),
(1072, 1, 'village', 'COMPLEXO DE PESQUISA', 0.8950, 0.3100, '{"id": "complexo-de-pesquisa-895310", "tag": "PROJETOS ESQUECIDOS", "name": "COMPLEXO DE PESQUISA", "color": "white", "label": "right", "point_type": "village"}'),
(1073, 1, 'village', 'VILA ABANDONADA', 0.4400, 0.4700, '{"id": "vila-abandonada-440470", "name": "VILA ABANDONADA", "color": "white", "label": "right", "point_type": "village"}'),
(1074, 1, 'radio', 'ESTAÇÃO DE RÁDIO', 0.7000, 0.4720, '{"id": "estac-ao-de-r-adio-700472", "tag": "SINAIS AINDA VIVEM", "name": "ESTAÇÃO DE RÁDIO", "color": "white", "label": "right", "point_type": "radio-tower"}'),
(1075, 1, 'nest', 'NINHO VEYRA NORTE', 0.3000, 0.2200, '{"id": "ninho-veyra-norte-300220", "tag": "CONTÁGIO EM EXPANSÃO", "name": "NINHO VEYRA NORTE", "caste": "errante", "color": "red", "label": "right", "pulse": true, "point_type": "veyra-nest"}'),
(1076, 1, 'colony', 'NINHO VEYRA SUL', 0.8000, 0.4100, '{"id": "ninho-veyra-sul-800410", "tag": "RAÍZES MAIS PROFUNDAS", "name": "NINHO VEYRA SUL", "risk": {"x": 80, "y": 41, "rx": 6.5}, "caste": "febril", "color": "red", "label": "right", "pulse": true, "point_type": "veyra-colony"}'),
(1077, 1, 'convoy', 'COMBOIO ABATIDO', 0.2000, 0.3000, '{"id": "comboio-abatido-200300", "tag": "SAQUE É PERIGO", "name": "COMBOIO ABATIDO", "color": "cyan", "event": "abandoned_cargo", "label": "right", "point_type": "abandoned-cargo"}'),
(1078, 1, 'extraction', 'PLATAFORMA DE EXTRAÇÃO ALPHA', 0.9300, 0.4000, '{"id": "plataforma-de-extrac-ao-alpha-930400", "name": "PLATAFORMA DE EXTRAÇÃO ALPHA", "color": "lime", "label": "below", "narrow": true, "point_type": "extraction"}'),
(1079, 1, 'extraction', 'PLATAFORMA DE EXTRAÇÃO BRAVO', 0.8200, 0.8000, '{"id": "plataforma-de-extrac-ao-bravo-820800", "name": "PLATAFORMA DE EXTRAÇÃO BRAVO", "color": "lime", "label": "below", "narrow": true, "point_type": "extraction"}'),
(1080, 1, 'safe_zone', 'SAFE ZONE VIGIA', 0.5650, 0.2880, '{"id": "safe-zone-vigia-565288", "name": "SAFE ZONE VIGIA", "zone": "vigia", "color": "lime", "label": "below", "point_type": "safe-zone"}'),
(1081, 1, 'safe_zone', 'ZONA FRANCA', 0.2800, 0.5450, '{"id": "zona-franca-280545", "name": "ZONA FRANCA", "zone": "franca", "color": "lime", "label": "below", "point_type": "safe-zone"}'),
(1082, 1, 'safe_zone', 'ENTREPOSTO CENTRAL', 0.6000, 0.5850, '{"id": "entreposto-central-600585", "name": "ENTREPOSTO CENTRAL", "zone": "entreposto", "color": "lime", "label": "below", "point_type": "safe-zone"}'),
(1083, 1, 'fuel', 'POSTO DE COMBUSTÍVEL', 0.3600, 0.3250, '{"id": "posto-de-combust-ivel-360325", "name": "POSTO DE COMBUSTÍVEL", "color": "amber", "label": false, "point_type": "fuel-station"}'),
(1084, 1, 'fuel', 'POSTO DE COMBUSTÍVEL', 0.6650, 0.6250, '{"id": "posto-de-combust-ivel-665625", "name": "POSTO DE COMBUSTÍVEL", "color": "amber", "label": false, "point_type": "fuel-station"}'),
(1085, 1, 'market', 'MERCADO', 0.5550, 0.6150, '{"id": "mercado-555615", "name": "MERCADO", "color": "gold", "label": false, "point_type": "market"}'),
(1086, 1, 'market', 'MERCADO', 0.4700, 0.6350, '{"id": "mercado-470635", "name": "MERCADO", "color": "gold", "label": false, "point_type": "market"}'),
(1087, 1, 'market', 'CAFÉ DO PORTO', 0.5080, 0.6680, '{"id": "caf-e-do-porto-508668", "name": "CAFÉ DO PORTO", "color": "gold", "label": false, "point_type": "cafe"}'),
(1088, 1, 'market', 'CENTRO COMERCIAL', 0.6400, 0.5600, '{"id": "centro-comercial-640560", "name": "CENTRO COMERCIAL", "color": "gold", "label": false, "point_type": "mall"}'),
(1089, 1, 'hospital', 'HOSPITAL DE CAMPANHA', 0.6250, 0.5450, '{"id": "hospital-de-campanha-625545", "name": "HOSPITAL DE CAMPANHA", "color": "white", "label": false, "point_type": "hospital"}'),
(1090, 1, 'hospital', 'HOSPITAL DE CAMPANHA', 0.2350, 0.4120, '{"id": "hospital-de-campanha-235412", "name": "HOSPITAL DE CAMPANHA", "color": "white", "label": false, "point_type": "hospital"}'),
(1091, 1, 'base', 'POSTO AVANÇADO NORTE', 0.4200, 0.1150, '{"id": "posto-avancado-norte-420115", "name": "POSTO AVANÇADO NORTE", "color": "lime", "label": false, "point_type": "outpost"}'),
(1092, 1, 'camp', 'ACAMPAMENTO LENHADOR', 0.2000, 0.1650, '{"id": "acampamento-lenhador-200165", "name": "ACAMPAMENTO LENHADOR", "color": "lime", "label": false, "point_type": "camp"}'),
(1093, 1, 'camp', 'ACAMPAMENTO DOS CAÇADORES', 0.8550, 0.3350, '{"id": "acampamento-dos-cacadores-855335", "name": "ACAMPAMENTO DOS CAÇADORES", "color": "lime", "label": false, "point_type": "camp"}'),
(1094, 1, 'field_base', 'BASE DE CAMPO DESCONHECIDA', 0.1550, 0.6800, '{"id": "base-de-campo-desconhecida-155680", "name": "BASE DE CAMPO DESCONHECIDA", "color": "muted", "label": false, "owner": "unknown", "point_type": "field-base"}')
ON CONFLICT ("id") DO UPDATE SET "map_id" = excluded."map_id", "kind" = excluded."kind", "label" = excluded."label", "x" = excluded."x", "y" = excluded."y", "meta" = excluded."meta";
INSERT INTO "map_markers" ("id", "map_id", "kind", "label", "x", "y", "meta") VALUES
(1095, 1, 'contaminated', 'ÁREA CONTAMINADA · SOPRADORES', 0.7550, 0.4400, '{"id": "area-contaminada-sporecasters-755440", "name": "ÁREA CONTAMINADA · SOPRADORES", "caste": "soprador", "color": "purple", "label": false, "point_type": "contaminated"}'),
(1096, 1, 'human_activity', 'TIROS DETECTADOS', 0.5300, 0.6050, '{"id": "tiros-detectados-530605", "name": "TIROS DETECTADOS", "color": "amber", "label": false, "narrow": true, "radius": 3, "signal": "shots", "ttl_min": 25, "point_type": "human-signal"}'),
(1097, 1, 'human_activity', 'TRANSMISSÃO INTERCEPTADA', 0.3100, 0.2900, '{"id": "transmiss-ao-interceptada-310290", "name": "TRANSMISSÃO INTERCEPTADA", "color": "amber", "label": false, "narrow": true, "radius": 3.8, "signal": "transmission", "ttl_min": 40, "point_type": "human-signal"}'),
(1098, 1, 'extraction', 'PONTO DE EXTRAÇÃO', 0.0900, 0.3300, '{"id": "ponto-de-extrac-ao-90330", "name": "PONTO DE EXTRAÇÃO", "color": "lime", "label": false, "point_type": "extraction"}'),
(1099, 1, 'extraction', 'PONTO DE EXTRAÇÃO', 0.5000, 0.0900, '{"id": "ponto-de-extrac-ao-50090", "name": "PONTO DE EXTRAÇÃO", "color": "lime", "label": false, "point_type": "extraction"}'),
(1100, 1, 'extraction', 'PONTO DE EXTRAÇÃO', 0.7800, 0.2350, '{"id": "ponto-de-extrac-ao-780235", "name": "PONTO DE EXTRAÇÃO", "color": "lime", "label": false, "point_type": "extraction"}'),
(1101, 1, 'extraction', 'PONTO DE EXTRAÇÃO', 0.4000, 0.5450, '{"id": "ponto-de-extrac-ao-400545", "name": "PONTO DE EXTRAÇÃO", "color": "lime", "label": false, "point_type": "extraction"}'),
(1102, 1, 'extraction', 'PONTO DE EXTRAÇÃO', 0.4550, 0.6950, '{"id": "ponto-de-extrac-ao-455695", "name": "PONTO DE EXTRAÇÃO", "color": "lime", "label": false, "point_type": "extraction"}'),
(1103, 1, 'extraction', 'PONTO DE EXTRAÇÃO', 0.9050, 0.6000, '{"id": "ponto-de-extrac-ao-905600", "name": "PONTO DE EXTRAÇÃO", "color": "lime", "label": false, "point_type": "extraction"}'),
(1104, 1, 'loot', 'ÁREA DE RECURSOS', 0.3450, 0.2450, '{"id": "area-de-recursos-345245", "name": "ÁREA DE RECURSOS", "color": "white", "label": false, "point_type": "resources"}'),
(1105, 1, 'loot', 'ÁREA DE RECURSOS', 0.6650, 0.3450, '{"id": "area-de-recursos-665345", "name": "ÁREA DE RECURSOS", "color": "white", "label": false, "point_type": "resources"}'),
(1106, 1, 'loot', 'ÁREA DE RECURSOS', 0.2600, 0.6300, '{"id": "area-de-recursos-260630", "name": "ÁREA DE RECURSOS", "color": "white", "label": false, "point_type": "resources"}'),
(1107, 1, 'loot', 'ÁREA DE RECURSOS', 0.7450, 0.7750, '{"id": "area-de-recursos-745775", "name": "ÁREA DE RECURSOS", "color": "white", "label": false, "point_type": "resources"}'),
(1108, 1, 'event', 'EVENTO DE MUNDO', 0.5300, 0.3850, '{"id": "evento-de-mundo-530385", "name": "EVENTO DE MUNDO", "color": "purple", "label": false, "pulse": true, "point_type": "world-event"}'),
(1109, 1, 'event', 'EVENTO DE MUNDO', 0.8800, 0.8750, '{"id": "evento-de-mundo-880875", "name": "EVENTO DE MUNDO", "color": "purple", "label": false, "pulse": true, "point_type": "world-event"}')
ON CONFLICT ("id") DO UPDATE SET "map_id" = excluded."map_id", "kind" = excluded."kind", "label" = excluded."label", "x" = excluded."x", "y" = excluded."y", "meta" = excluded."meta";

-- ---------------------------------------------------------------- missions (13 linhas)
INSERT INTO "missions" ("id", "slug", "name", "giver", "region", "type", "difficulty", "duration_min", "players_max", "level_req", "objective", "target", "image", "locked", "sort_order", "origin", "act", "requires_faction", "requires_tier", "requires_rep", "squad_min", "squad_max", "human_activity") VALUES
(1, 'sinal-perdido', 'SINAL PERDIDO', 'BASE VIGIA · HUNTER COMMAND', 'FLORESTA VERDE', 'principal', 'MÉDIA', 35, 4, 18, 'Restaure a comunicação da estação militar ao norte. Reative o terminal e proteja o técnico durante a sincronização. Há corpos com marcas de tiros humanos: alguém chegou antes.', 8, 'missions/mission-sinal-perdido.jpg', 0, 0, 'hunter_command', 1, NULL, NULL, NULL, 1, 4, 'DETECTADA'),
(2, 'suprimentos-medicos', 'SUPRIMENTOS MÉDICOS', 'BASE VIGIA · HUNTER COMMAND', 'FLORESTA VERDE', 'diaria', 'FÁCIL', 25, 4, 12, 'Recupere 3 kits médicos no hospital de campanha abandonado e entregue-os ao médico da base.', NULL, 'missions/mission-suprimentos-medicos.jpg', 0, 1, 'hunter_command', NULL, NULL, NULL, NULL, 1, 4, 'IMPROVÁVEL'),
(3, 'cacada-ao-cacador', 'CAÇADA AO CAÇADOR', 'CAÇADORES', 'FLORESTA VERDE', 'diaria', 'DIFÍCIL', 45, 4, 26, 'Localize e elimine o franco-atirador que controla a passagem da cordilheira. Recupere seu rifle.', NULL, 'missions/mission-cacada-ao-cacador.jpg', 0, 2, 'hunter_command', NULL, NULL, NULL, NULL, 1, 4, 'PROVÁVEL'),
(4, 'carga-valiosa', 'CARGA VALIOSA', 'COMERCIANTES', 'FLORESTA VERDE', 'contrato', 'MÉDIA', 30, 3, 20, 'Recupere a carga do comboio Aegis emboscado na estrada leste e leve-a até a Base Alfa. Outros operadores podem ter chegado antes.', 6, 'missions/mission-carga-valiosa.jpg', 0, 3, 'aegis', NULL, 'aegis', NULL, NULL, 1, 4, 'PROVÁVEL'),
(5, 'tecnologia-recuperada', 'TECNOLOGIA RECUPERADA', 'BASE VIGIA · HUNTER COMMAND', 'ZONA INDUSTRIAL', 'principal', 'EXTREMO', 60, 4, 40, 'Infiltre-se no complexo industrial e recupere o servidor de dados antes que seja destruído.', NULL, 'missions/mission-tecnologia-recuperada.jpg', 0, 4, 'hunter_command', 4, NULL, NULL, NULL, 1, 4, 'DETECTADA'),
(6, 'arquivos-vesper', 'ARQUIVOS VESPER', 'HUNTER COMMAND · LICENÇA CINZA', 'MONTANHAS GÉLIDAS', 'principal', 'DIFÍCIL', 55, 4, 20, 'Os arquivos da Axiom sobre os Fragmentos Vesper estão numa instalação congelada nas montanhas: o que a empresa sabia antes do primeiro ataque. O acesso oficial foi destruído; só um contrato com a Aegis (componentes) ou com a Frontier (rede de mineração) abre caminho.', 4, 'ui/mission-sinal-perdido.jpg', 1, 6, 'hunter_command', 2, 'any', NULL, NULL, 1, 4, 'PROVÁVEL'),
(7, 'escolta-blindada', 'ESCOLTA BLINDADA', 'BASE ALFA · AEGIS', 'FLORESTA VERDE', 'contrato', 'MÉDIA', 30, 4, 5, 'Escolte o comboio Aegis da Base Alfa até a ponte leste. Blindado faz barulho — todo infectado do setor e outros operadores vão ouvir.', 3, 'ui/mission-sinal-perdido.jpg', 0, 7, 'aegis', NULL, 'aegis', NULL, NULL, 1, 4, 'PROVÁVEL'),
(8, 'arsenal-submerso', 'ARSENAL SUBMERSO', 'BASE ALFA · AEGIS', 'PÂNTANO CINZENTO', 'contrato', 'DIFÍCIL', 40, 4, 12, 'Recupere as caixas de armamento Aegis afundadas no canal norte. A água contaminada esconde esporos e o que mais estiver embaixo.', 4, 'ui/mission-sinal-perdido.jpg', 1, 8, 'aegis', NULL, 'aegis', 'OPERADOR', NULL, 1, 4, 'PROVÁVEL'),
(9, 'rota-silenciosa', 'ROTA SILENCIOSA', 'ACAMPAMENTO DOS CAÇADORES · FRONTIER', 'PÂNTANO CINZENTO', 'contrato', 'MÉDIA', 35, 4, 8, 'Guie o informante pelos túneis secos até o Acampamento dos Caçadores sem ser detectado. Nenhum tiro, nenhuma transmissão.', 1, 'ui/mission-sinal-perdido.jpg', 0, 9, 'frontier', NULL, 'frontier', NULL, NULL, 1, 4, 'IMPROVÁVEL'),
(10, 'testemunha', 'TESTEMUNHA', 'ACAMPAMENTO DOS CAÇADORES · FRONTIER', 'FLORESTA VERDE', 'contrato', 'DIFÍCIL', 45, 4, 15, 'Localize e retire a testemunha escondida na Vila Abandonada. Ela recolhia fragmentos para a Axiom e viu o que a empresa escondeu antes do primeiro ataque — e não é a única que a procura.', 1, 'ui/mission-sinal-perdido.jpg', 1, 10, 'frontier', NULL, 'frontier', 'CONFIÁVEL', NULL, 1, 4, 'DETECTADA'),
(11, 'amostra-zero', 'AMOSTRA ZERO', 'INSTITUTO ICARUS', 'FLORESTA VERDE', 'contrato', 'MÉDIA', 30, 4, 3, 'Uma planta que não existe em nenhum catálogo brotou na margem do rio, onde o micélio cobriu o chão. Colete três amostras e extraia com o material intacto — o Icarus precisa do tecido vivo.', 3, 'ui/mission-sinal-perdido.jpg', 0, 11, 'icarus', NULL, NULL, NULL, NULL, 1, 4, 'IMPROVÁVEL'),
(12, 'surto-setor-norte', 'SURTO NO SETOR NORTE', 'EVENTO DE MUNDO', 'FLORESTA VERDE', 'emergencia', 'EXTREMO', 25, 4, 1, 'A infestação disparou perto do Posto Avançado Norte e os Errantes estão se juntando. Contenha o surto antes que alcance a Base Vigia. Hunters, Mercenários e Sobreviventes vão convergir.', 5, 'ui/mission-sinal-perdido.jpg', 0, 12, 'world', NULL, NULL, NULL, NULL, 1, 4, 'DETECTADA'),
(21, 'teste-mission', 'Teste de Missão', 'BASE ALFA · AEGIS', 'FLORESTA VERDE', 'contrato', 'DIFÍCIL', 20, 4, 5, 'Teste de missão', NULL, 'uploads/missoes/captura-de-tela-2026-09-10-105356-dae3decd.png', 1, 0, 'aegis', NULL, NULL, NULL, NULL, 1, 4, 'PROVÁVEL')
ON CONFLICT ("id") DO UPDATE SET "slug" = excluded."slug", "name" = excluded."name", "giver" = excluded."giver", "region" = excluded."region", "type" = excluded."type", "difficulty" = excluded."difficulty", "duration_min" = excluded."duration_min", "players_max" = excluded."players_max", "level_req" = excluded."level_req", "objective" = excluded."objective", "target" = excluded."target", "image" = excluded."image", "locked" = excluded."locked", "sort_order" = excluded."sort_order", "origin" = excluded."origin", "act" = excluded."act", "requires_faction" = excluded."requires_faction", "requires_tier" = excluded."requires_tier", "requires_rep" = excluded."requires_rep", "squad_min" = excluded."squad_min", "squad_max" = excluded."squad_max", "human_activity" = excluded."human_activity";

-- ---------------------------------------------------------------- mission_rewards (39 linhas)
INSERT INTO "mission_rewards" ("id", "mission_id", "kind", "amount", "item_id") VALUES
(1, 1, 'xp', 6500, NULL),
(2, 1, 'cash', 2400, NULL),
(3, 1, 'points', 1200, NULL),
(4, 1, 'item', 1, 1),
(5, 2, 'xp', 3200, NULL),
(6, 2, 'cash', 1100, NULL),
(7, 2, 'item', 1, 22),
(8, 3, 'xp', 8000, NULL),
(9, 3, 'points', 900, NULL),
(10, 3, 'item', 1, 2),
(11, 4, 'cash', 4500, NULL),
(12, 4, 'item', 1, 19),
(13, 5, 'xp', 15000, NULL),
(14, 5, 'points', 2500, NULL),
(15, 5, 'item', 1, 41),
(16, 6, 'xp', 9000, NULL),
(17, 6, 'credits', 6500, NULL),
(18, 6, 'intel', 1, NULL),
(19, 7, 'xp', 4200, NULL),
(20, 7, 'credits', 3800, NULL),
(21, 7, 'rep_aegis', 250, NULL),
(22, 8, 'xp', 6800, NULL),
(23, 8, 'credits', 5200, NULL),
(24, 8, 'rep_aegis', 400, NULL),
(25, 9, 'xp', 4600, NULL),
(26, 9, 'credits', 3200, NULL),
(27, 9, 'rep_frontier', 250, NULL),
(28, 10, 'xp', 7400, NULL),
(29, 10, 'credits', 4900, NULL),
(30, 10, 'rep_frontier', 450, NULL),
(31, 10, 'access', 1, NULL),
(32, 11, 'xp', 3600, NULL),
(33, 11, 'credits', 2400, NULL),
(34, 11, 'intel', 1, NULL),
(35, 12, 'xp', 5000, NULL),
(36, 12, 'credits', 3000, NULL),
(37, 12, 'points', 3, NULL),
(45, 21, 'item', 1, 23),
(46, 21, 'xp', 200, NULL)
ON CONFLICT ("id") DO UPDATE SET "mission_id" = excluded."mission_id", "kind" = excluded."kind", "amount" = excluded."amount", "item_id" = excluded."item_id";

-- ---------------------------------------------------------------- achievements (16 linhas)
INSERT INTO "achievements" ("id", "slug", "name", "category", "description", "icon", "target", "sort_order") VALUES
(1, 'sinal-perdido', 'SINAL PERDIDO', 'exploracao', 'Restaure a comunicação do posto avançado norte.', 'cell_tower', 1, 0),
(2, 'cacador-experiente', 'CAÇADOR EXPERIENTE', 'combate', 'Elimine 100 infectados.', 'skull', 1, 1),
(3, 'sobrevivente', 'SOBREVIVENTE', 'sobrevivencia', 'Sobreviva 10 dias em uma única campanha.', 'backpack', 1, 2),
(4, 'bom-negocio', 'BOM NEGÓCIO', 'faccoes', 'Conclua 50 trocas com comerciantes.', 'handshake', 1, 3),
(5, 'matilha', 'MATILHA', 'combate', 'Elimine 20 lobos.', 'pets', 1, 4),
(6, 'explorador', 'EXPLORADOR', 'exploracao', 'Descubra 50 pontos de interesse.', 'map', 50, 5),
(7, 'suprimentos', 'SUPRIMENTOS', 'sobrevivencia', 'Extraia com sucesso 100 suprimentos.', 'inventory_2', 100, 6),
(8, 'veterano', 'VETERANO', 'progressao', 'Alcance o nível 20.', 'keyboard_double_arrow_up', 1, 7),
(9, 'anjo-da-guarda', 'ANJO DA GUARDA', 'combate', 'Reviva aliados 25 vezes.', 'add', 25, 8),
(10, 'tiro-certo', 'TIRO CERTO', 'combate', 'Realize 50 tiros na cabeça.', 'my_location', 50, 9),
(11, 'chave-mestra', 'CHAVE MESTRA', 'exploracao', 'Abra 25 cofres ou portas trancadas.', 'lock', 25, 10),
(12, 'lider-da-resistencia', 'CADEIA DE COMANDO', 'hunter', 'Complete 25 operações Hunter seguindo sua função designada.', 'workspace_premium', 25, 11),
(13, 'colecionador', 'COLECIONADOR', 'equipamento', 'Colete 100 itens raros.', 'lock', 1, 12),
(14, 'lendario', 'LENDÁRIO', 'equipamento', 'Obtenha um equipamento lendário.', 'lock', 1, 13),
(15, 'mestre-das-armas', 'MESTRE DAS ARMAS', 'equipamento', 'Melhore uma arma ao nível máximo.', 'lock', 1, 14),
(16, 'sobrevivente-extremo', 'SOBREVIVENTE EXTREMO', 'sobrevivencia', 'Sobreviva 30 dias em uma única campanha.', 'lock', 1, 15)
ON CONFLICT ("id") DO UPDATE SET "slug" = excluded."slug", "name" = excluded."name", "category" = excluded."category", "description" = excluded."description", "icon" = excluded."icon", "target" = excluded."target", "sort_order" = excluded."sort_order";

-- ---------------------------------------------------------------- achievement_rewards (38 linhas)
INSERT INTO "achievement_rewards" ("id", "achievement_id", "kind", "amount", "item_id") VALUES
(1, 1, 'xp', 6500, NULL),
(2, 1, 'cash', 2400, NULL),
(3, 1, 'points', 1200, NULL),
(4, 1, 'item', 1, 1),
(5, 2, 'xp', 5000, NULL),
(6, 2, 'cash', 1500, NULL),
(7, 2, 'item', 1, 2),
(8, 3, 'xp', 4000, NULL),
(9, 3, 'points', 800, NULL),
(10, 3, 'item', 1, 18),
(11, 4, 'cash', 3000, NULL),
(12, 4, 'xp', 1500, NULL),
(13, 5, 'xp', 2500, NULL),
(14, 5, 'item', 1, 4),
(15, 6, 'xp', 3500, NULL),
(16, 6, 'cash', 900, NULL),
(17, 6, 'item', 1, 39),
(18, 7, 'cash', 2000, NULL),
(19, 7, 'item', 1, 20),
(20, 8, 'points', 500, NULL),
(21, 8, 'xp', 2000, NULL),
(22, 9, 'xp', 3000, NULL),
(23, 9, 'item', 1, 22),
(24, 10, 'xp', 3000, NULL),
(25, 10, 'cash', 1000, NULL),
(26, 11, 'cash', 1800, NULL),
(27, 11, 'item', 1, 41),
(28, 12, 'xp', 10000, NULL),
(29, 12, 'points', 2000, NULL),
(30, 12, 'item', 1, 15),
(31, 13, 'points', 1000, NULL),
(32, 13, 'item', 1, 19),
(33, 14, 'xp', 5000, NULL),
(34, 14, 'cash', 2500, NULL),
(35, 15, 'xp', 5000, NULL),
(36, 15, 'item', 1, 42),
(37, 16, 'xp', 15000, NULL),
(38, 16, 'points', 3000, NULL)
ON CONFLICT ("id") DO UPDATE SET "achievement_id" = excluded."achievement_id", "kind" = excluded."kind", "amount" = excluded."amount", "item_id" = excluded."item_id";

-- ---------------------------------------------------------------- shop_offers (26 linhas)
INSERT INTO "shop_offers" ("id", "item_id", "category", "price", "currency", "discount", "tag", "featured", "sort_order") VALUES
(1, 1, 'armas', 48500, 'credits', NULL, NULL, 1, 0),
(2, 2, 'armas', 52000, 'credits', NULL, 'NOVO', 1, 1),
(3, 3, 'armas', 12800, 'credits', NULL, NULL, 0, 2),
(4, 4, 'armas', 7400, 'credits', NULL, NULL, 0, 3),
(5, 8, 'armas', 4900, 'credits', NULL, NULL, 0, 4),
(6, 5, 'armas', 2100, 'credits', NULL, NULL, 0, 5),
(7, 10, 'equipamento', 21000, 'credits', NULL, NULL, 1, 6),
(8, 13, 'equipamento', 34500, 'credits', NULL, NULL, 1, 7),
(9, 17, 'equipamento', 15600, 'credits', NULL, NULL, 1, 8),
(10, 18, 'equipamento', 18900, 'credits', 15, '-15%', 1, 9),
(11, 11, 'equipamento', 9600, 'credits', NULL, NULL, 0, 10),
(12, 38, 'equipamento', 11200, 'credits', NULL, NULL, 0, 11),
(13, 21, 'suprimentos', 3200, 'credits', NULL, NULL, 1, 12),
(14, 22, 'suprimentos', 9800, 'credits', NULL, NULL, 0, 13),
(15, 23, 'suprimentos', 850, 'credits', NULL, NULL, 0, 14),
(16, 28, 'suprimentos', 620, 'credits', NULL, NULL, 0, 15),
(17, 29, 'suprimentos', 740, 'credits', NULL, NULL, 0, 16),
(18, 20, 'suprimentos', 350, 'cash', NULL, 'POPULAR', 1, 17),
(19, 42, 'cosmeticos', 1200, 'points', NULL, 'RARO', 1, 18),
(20, 14, 'cosmeticos', 2400, 'points', NULL, 'ÉPICO', 1, 19),
(21, 15, 'cosmeticos', 450, 'points', NULL, NULL, 0, 20),
(22, 12, 'cosmeticos', 320, 'points', NULL, NULL, 0, 21),
(23, 19, 'passe', 900, 'cash', NULL, NULL, 1, 22),
(24, 32, 'passe', 250, 'points', NULL, NULL, 1, 23),
(25, 41, 'passe', 1500, 'cash', NULL, 'LIMITADO', 0, 24),
(26, 7, 'passe', 480, 'cash', NULL, NULL, 0, 25)
ON CONFLICT ("id") DO UPDATE SET "item_id" = excluded."item_id", "category" = excluded."category", "price" = excluded."price", "currency" = excluded."currency", "discount" = excluded."discount", "tag" = excluded."tag", "featured" = excluded."featured", "sort_order" = excluded."sort_order";

-- ---------------------------------------------------------------- skills (120 linhas)
INSERT INTO "skills" ("id", "slug", "category", "tier", "col", "name", "icon", "max_level", "cost", "description", "image", "step", "unit", "effect") VALUES
(1, 'mira-precisa', 'combate', 1, 1, 'MIRA PRECISA', 'sk-crosshair', 1, 0, 'Reduz a oscilação da mira ao apontar em 8%.', 'ui/skill-preview.jpg', 8.00, '%', 'Reduz a oscilação da mira ao apontar em {v}.'),
(2, 'municao-extra', 'combate', 1, 2, 'MUNIÇÃO EXTRA', 'sk-bullets', 1, 0, 'Aumenta a munição inicial de cada saída em 15%.', 'ui/skill-preview.jpg', 15.00, '%', 'Aumenta a munição inicial de cada saída em {v}.'),
(3, 'recarga-rapida', 'combate', 1, 3, 'RECARGA RÁPIDA', 'sk-mag', 1, 0, 'Reduz o tempo de recarga das armas em 10%.', 'ui/skill-preview.jpg', 10.00, '%', 'Reduz o tempo de recarga das armas em {v}.'),
(4, 'olho-de-aguia', 'combate', 1, 4, 'OLHO DE ÁGUIA', 'sk-scope', 1, 2, 'Aumenta o alcance de identificação de alvos em 20%.', 'ui/skill-preview.jpg', 20.00, '%', 'Aumenta o alcance de identificação de alvos em {v}.'),
(5, 'granadeiro', 'combate', 1, 5, 'GRANADEIRO', 'sk-grenade', 1, 2, 'Aumenta o raio de dano dos explosivos em 12%.', 'ui/skill-preview.jpg', 12.00, '%', 'Aumenta o raio de dano dos explosivos em {v}.'),
(6, 'capacete-reforcado', 'combate', 2, 1, 'CAPACETE REFORÇADO', 'sk-helmet-skull', 1, 0, 'Reduz o dano recebido na cabeça em 10%.', 'ui/skill-preview.jpg', 10.00, '%', 'Reduz o dano recebido na cabeça em {v}.'),
(7, 'colete-tatico', 'combate', 2, 2, 'COLETE TÁTICO', 'sk-vest', 1, 0, 'Aumenta a durabilidade do colete em 15%.', 'ui/skill-preview.jpg', 15.00, '%', 'Aumenta a durabilidade do colete em {v}.'),
(8, 'disparo-estavel', 'combate', 2, 3, 'DISPARO ESTÁVEL', 'sk-rifle', 3, 3, 'Reduz o recuo vertical das armas em 12% por nível.', 'ui/skill-preview.jpg', 12.00, '%', 'Reduz o recuo vertical das armas em {v}.'),
(9, 'foco', 'combate', 2, 4, 'FOCO', 'sk-focus', 1, 3, 'Prende a respiração por mais 2 segundos ao mirar.', 'ui/skill-preview.jpg', 2.00, ' segundos', 'Prende a respiração por mais {v} ao mirar.'),
(10, 'explosivo', 'combate', 2, 5, 'EXPLOSIVO', 'sk-impact', 1, 3, 'Aumenta o dano de explosivos em 14%.', 'ui/skill-preview.jpg', 14.00, '%', 'Aumenta o dano de explosivos em {v}.'),
(11, 'resistencia', 'combate', 3, 1, 'RESISTÊNCIA', 'sk-shooter', 3, 3, 'Reduz o dano recebido de armas leves em 5% por nível.', 'ui/skill-preview.jpg', 5.00, '%', 'Reduz o dano recebido de armas leves em {v}.'),
(12, 'armeiro', 'combate', 3, 2, 'ARMEIRO', 'sk-gunsmith', 3, 3, 'Reduz o desgaste das armas em 8% por nível.', 'ui/skill-preview.jpg', 8.00, '%', 'Reduz o desgaste das armas em {v}.'),
(13, 'corpo-a-corpo', 'combate', 3, 3, 'CORPO A CORPO', 'sk-fist', 1, 0, 'Aumenta o dano corpo a corpo em 25%.', 'ui/skill-preview.jpg', 25.00, '%', 'Aumenta o dano corpo a corpo em {v}.'),
(14, 'instinto-assassino', 'combate', 3, 4, 'INSTINTO ASSASSINO', 'sk-killer', 3, 4, 'Aumenta o dano crítico em 6% por nível.', 'ui/skill-preview.jpg', 6.00, '%', 'Aumenta o dano crítico em {v}.'),
(15, 'pente-duplo', 'combate', 3, 5, 'PENTE DUPLO', 'sk-mag2', 3, 4, 'Reduz a troca de carregador em 7% por nível.', 'ui/skill-preview.jpg', 7.00, '%', 'Reduz a troca de carregador em {v}.'),
(16, 'atirador-de-elite', 'combate', 4, 1, 'ATIRADOR DE ELITE', 'sk-marksman', 1, 5, 'Aumenta o dano a longa distância em 18%.', 'ui/skill-preview.jpg', 18.00, '%', 'Aumenta o dano a longa distância em {v}.'),
(17, 'balas-perfurantes', 'combate', 4, 2, 'BALAS PERFURANTES', 'sk-pierce', 1, 5, 'Ignora 15% da blindagem inimiga.', 'ui/skill-preview.jpg', 15.00, '%', 'Ignora {v} da blindagem inimiga.'),
(18, 'blindagem-pesada', 'combate', 4, 3, 'BLINDAGEM PESADA', 'sk-plates', 1, 5, 'Permite equipar coletes de classe 5 sem penalidade.', 'ui/skill-preview.jpg', 5.00, '', 'Permite equipar coletes de classe {v}sem penalidade.'),
(19, 'supressao', 'combate', 4, 4, 'SUPRESSÃO', 'sk-silencer', 1, 5, 'Reduz o ruído dos disparos em 20%.', 'ui/skill-preview.jpg', 20.00, '%', 'Reduz o ruído dos disparos em {v}.'),
(20, 'cacador-implacavel', 'combate', 4, 5, 'CAÇADOR IMPLACÁVEL', 'sk-reaper', 1, 5, 'Recupera 10 de vida a cada abate confirmado.', 'ui/skill-preview.jpg', 10.00, '', 'Recupera {v}de vida a cada abate confirmado.'),
(21, 'estomago-de-ferro', 'sobrevivencia', 1, 1, 'ESTÔMAGO DE FERRO', 'sk-can', 1, 0, 'Reduz o risco de intoxicação por comida em 30%.', 'regions/region-floresta-verde.jpg', 30.00, '%', 'Reduz o risco de intoxicação por comida em {v}.'),
(22, 'hidratacao', 'sobrevivencia', 1, 2, 'HIDRATAÇÃO', 'sk-droplet', 1, 0, 'Reduz a perda de hidratação em 12%.', 'regions/region-floresta-verde.jpg', 12.00, '%', 'Reduz a perda de hidratação em {v}.'),
(23, 'fogueira', 'sobrevivencia', 1, 3, 'FOGUEIRA', 'sk-campfire', 1, 0, 'Acende fogueiras 25% mais rápido.', 'regions/region-floresta-verde.jpg', 25.00, '%', 'Acende fogueiras {v} mais rápido.'),
(24, 'rastreador', 'sobrevivencia', 1, 4, 'RASTREADOR', 'sk-tracks', 1, 2, 'Revela pegadas recentes por 15 segundos.', 'regions/region-floresta-verde.jpg', 15.00, ' segundos', 'Revela pegadas recentes por {v}.'),
(25, 'camuflagem', 'sobrevivencia', 1, 5, 'CAMUFLAGEM', 'sk-camo', 1, 2, 'Reduz a sua visibilidade na vegetação em 18%.', 'regions/region-floresta-verde.jpg', 18.00, '%', 'Reduz a sua visibilidade na vegetação em {v}.'),
(26, 'sangue-frio', 'sobrevivencia', 2, 1, 'SANGUE FRIO', 'sk-thermo', 1, 0, 'Reduz o tremor por frio em 20%.', 'regions/region-floresta-verde.jpg', 20.00, '%', 'Reduz o tremor por frio em {v}.'),
(27, 'primeiros-socorros', 'sobrevivencia', 2, 2, 'PRIMEIROS SOCORROS', 'sk-bandage', 1, 0, 'Usa curativos 20% mais rápido.', 'regions/region-floresta-verde.jpg', 20.00, '%', 'Usa curativos {v} mais rápido.'),
(28, 'abrigo', 'sobrevivencia', 2, 3, 'ABRIGO', 'sk-tent', 3, 3, 'Reduz a perda de temperatura em 10% por nível ao descansar.', 'regions/region-floresta-verde.jpg', 10.00, '%', 'Reduz a perda de temperatura em {v} ao descansar.'),
(29, 'forrageiro', 'sobrevivencia', 2, 4, 'FORRAGEIRO', 'sk-mushroom', 1, 3, 'Encontra 20% mais recursos naturais.', 'regions/region-floresta-verde.jpg', 20.00, '%', 'Encontra {v} mais recursos naturais.'),
(30, 'imunidade', 'sobrevivencia', 2, 5, 'IMUNIDADE', 'sk-virus', 1, 3, 'Reduz a duração de infecções em 25%.', 'regions/region-floresta-verde.jpg', 25.00, '%', 'Reduz a duração de infecções em {v}.'),
(31, 'sobrevivente-nato', 'sobrevivencia', 3, 1, 'SOBREVIVENTE NATO', 'sk-heart-plus', 3, 3, 'Aumenta a vida máxima em 4% por nível.', 'regions/region-floresta-verde.jpg', 4.00, '%', 'Aumenta a vida máxima em {v}.'),
(32, 'purificador', 'sobrevivencia', 3, 2, 'PURIFICADOR', 'sk-filter', 3, 3, 'Purifica água 10% mais rápido por nível.', 'regions/region-floresta-verde.jpg', 10.00, '%', 'Purifica água {v} mais rápido.'),
(33, 'cacador-noturno', 'sobrevivencia', 3, 3, 'CAÇADOR NOTURNO', 'sk-moon-eye', 1, 4, 'Melhora a visão noturna sem equipamento.', 'regions/region-floresta-verde.jpg', NULL, '', NULL),
(34, 'regeneracao', 'sobrevivencia', 3, 4, 'REGENERAÇÃO', 'sk-regen', 3, 4, 'Recupera 1 de vida a cada 8 segundos por nível.', 'regions/region-floresta-verde.jpg', 1.00, '', 'Recupera {v} de vida a cada 8 segundos.'),
(35, 'ferreiro', 'sobrevivencia', 3, 5, 'FERREIRO', 'sk-anvil', 3, 4, 'Repara equipamentos com 10% menos material por nível.', 'regions/region-floresta-verde.jpg', 10.00, '%', 'Repara equipamentos com {v} menos material.'),
(36, 'ultimo-folego', 'sobrevivencia', 4, 1, 'ÚLTIMO FÔLEGO', 'sk-lifeline', 1, 5, 'Sobrevive a um golpe fatal uma vez por saída.', 'regions/region-floresta-verde.jpg', NULL, '', NULL),
(37, 'inquebravel', 'sobrevivencia', 4, 2, 'INQUEBRÁVEL', 'sk-unbreak', 1, 5, 'Reduz o dano de queda em 40%.', 'regions/region-floresta-verde.jpg', 40.00, '%', 'Reduz o dano de queda em {v}.'),
(38, 'mestre-da-selva', 'sobrevivencia', 4, 3, 'MESTRE DA SELVA', 'sk-jungle', 1, 5, 'Move-se sem ruído em vegetação densa.', 'regions/region-floresta-verde.jpg', NULL, '', NULL),
(39, 'fantasma', 'sobrevivencia', 4, 4, 'FANTASMA', 'sk-ghost', 1, 5, 'Não aparece em radares inimigos por 30 segundos.', 'regions/region-floresta-verde.jpg', 30.00, ' segundos', 'Não aparece em radares inimigos por {v}.'),
(40, 'lenda-viva', 'sobrevivencia', 4, 5, 'LENDA VIVA', 'sk-laurel', 1, 5, 'Aumenta todo o XP de sobrevivência em 15%.', 'regions/region-floresta-verde.jpg', 15.00, '%', 'Aumenta todo o XP de sobrevivência em {v}.'),
(41, 'passo-leve', 'mobilidade', 1, 1, 'PASSO LEVE', 'sk-footstep', 1, 0, 'Reduz o ruído dos passos em 15%.', 'maps/vale-do-eco.jpg', 15.00, '%', 'Reduz o ruído dos passos em {v}.'),
(42, 'folego', 'mobilidade', 1, 2, 'FÔLEGO', 'sk-lungs', 1, 0, 'Aumenta a estamina máxima em 10%.', 'maps/vale-do-eco.jpg', 10.00, '%', 'Aumenta a estamina máxima em {v}.'),
(43, 'escalada', 'mobilidade', 1, 3, 'ESCALADA', 'sk-ladder', 1, 0, 'Escala obstáculos 20% mais rápido.', 'maps/vale-do-eco.jpg', 20.00, '%', 'Escala obstáculos {v} mais rápido.'),
(44, 'deslize', 'mobilidade', 1, 4, 'DESLIZE', 'sk-slide', 1, 2, 'Permite deslizar em corrida por 2 segundos.', 'maps/vale-do-eco.jpg', 2.00, ' segundos', 'Permite deslizar em corrida por {v}.'),
(45, 'salto-alto', 'mobilidade', 1, 5, 'SALTO ALTO', 'sk-jump', 1, 2, 'Aumenta a altura do salto em 12%.', 'maps/vale-do-eco.jpg', 12.00, '%', 'Aumenta a altura do salto em {v}.'),
(46, 'corrida-tatica', 'mobilidade', 2, 1, 'CORRIDA TÁTICA', 'sk-runner-gun', 1, 0, 'Corre 8% mais rápido com arma em punho.', 'maps/vale-do-eco.jpg', 8.00, '%', 'Corre {v} mais rápido com arma em punho.'),
(47, 'carga-leve', 'mobilidade', 2, 2, 'CARGA LEVE', 'sk-feather-pack', 3, 3, 'Reduz a penalidade de peso em 9% por nível.', 'maps/vale-do-eco.jpg', 9.00, '%', 'Reduz a penalidade de peso em {v}.'),
(48, 'reflexos', 'mobilidade', 2, 3, 'REFLEXOS', 'sk-reflex', 1, 3, 'Saca a arma 15% mais rápido.', 'maps/vale-do-eco.jpg', 15.00, '%', 'Saca a arma {v} mais rápido.'),
(49, 'agachado', 'mobilidade', 2, 4, 'AGACHADO', 'sk-crouch', 1, 3, 'Move-se 18% mais rápido agachado.', 'maps/vale-do-eco.jpg', 18.00, '%', 'Move-se {v} mais rápido agachado.'),
(50, 'rolamento', 'mobilidade', 2, 5, 'ROLAMENTO', 'sk-roll', 1, 3, 'Reduz o dano de queda em 25%.', 'maps/vale-do-eco.jpg', 25.00, '%', 'Reduz o dano de queda em {v}.')
ON CONFLICT ("id") DO UPDATE SET "slug" = excluded."slug", "category" = excluded."category", "tier" = excluded."tier", "col" = excluded."col", "name" = excluded."name", "icon" = excluded."icon", "max_level" = excluded."max_level", "cost" = excluded."cost", "description" = excluded."description", "image" = excluded."image", "step" = excluded."step", "unit" = excluded."unit", "effect" = excluded."effect";
INSERT INTO "skills" ("id", "slug", "category", "tier", "col", "name", "icon", "max_level", "cost", "description", "image", "step", "unit", "effect") VALUES
(51, 'resiliencia', 'mobilidade', 3, 1, 'RESILIÊNCIA', 'sk-stamina', 3, 3, 'Recupera estamina 8% mais rápido por nível.', 'maps/vale-do-eco.jpg', 8.00, '%', 'Recupera estamina {v} mais rápido.'),
(52, 'nadador', 'mobilidade', 3, 2, 'NADADOR', 'sk-swim', 3, 3, 'Nada 10% mais rápido por nível.', 'maps/vale-do-eco.jpg', 10.00, '%', 'Nada {v} mais rápido.'),
(53, 'sombra', 'mobilidade', 3, 3, 'SOMBRA', 'sk-shadow', 1, 4, 'Reduz o ruído ao correr em 20%.', 'maps/vale-do-eco.jpg', 20.00, '%', 'Reduz o ruído ao correr em {v}.'),
(54, 'impulso', 'mobilidade', 3, 4, 'IMPULSO', 'sk-boost', 3, 4, 'Ganha 5% de velocidade após um abate por nível.', 'maps/vale-do-eco.jpg', 5.00, '%', 'Ganha {v} de velocidade após um abate.'),
(55, 'surto', 'mobilidade', 3, 5, 'SURTO', 'sk-surge', 3, 4, 'Corre 6% mais rápido com pouca vida por nível.', 'maps/vale-do-eco.jpg', 6.00, '%', 'Corre {v} mais rápido com pouca vida.'),
(56, 'velocista', 'mobilidade', 4, 1, 'VELOCISTA', 'sk-speed', 1, 5, 'Aumenta a velocidade base de corrida em 12%.', 'maps/vale-do-eco.jpg', 12.00, '%', 'Aumenta a velocidade base de corrida em {v}.'),
(57, 'acrobata', 'mobilidade', 4, 2, 'ACROBATA', 'sk-acrobat', 1, 5, 'Encadeia manobras sem perder estamina.', 'maps/vale-do-eco.jpg', NULL, '', NULL),
(58, 'vento', 'mobilidade', 4, 3, 'VENTO', 'sk-wind', 1, 5, 'Recupera estamina mesmo em movimento.', 'maps/vale-do-eco.jpg', NULL, '', NULL),
(59, 'caminho-livre', 'mobilidade', 4, 4, 'CAMINHO LIVRE', 'sk-path', 1, 5, 'Ignora a lentidão de terrenos difíceis.', 'maps/vale-do-eco.jpg', NULL, '', NULL),
(60, 'fuga', 'mobilidade', 4, 5, 'FUGA', 'sk-exit-run', 1, 5, 'Extrai 20% mais rápido do ponto de saída.', 'maps/vale-do-eco.jpg', 20.00, '%', 'Extrai {v} mais rápido do ponto de saída.'),
(61, 'mao-firme', 'suporte', 1, 1, 'MÃO FIRME', 'sk-hand-heart', 1, 0, 'Aplica curativos em aliados 15% mais rápido.', 'missions/mission-suprimentos-medicos.jpg', 15.00, '%', 'Aplica curativos em aliados {v} mais rápido.'),
(62, 'doador', 'suporte', 1, 2, 'DOADOR', 'sk-give', 1, 0, 'Compartilha suprimentos sem penalidade de tempo.', 'missions/mission-suprimentos-medicos.jpg', NULL, '', NULL),
(63, 'sinalizador', 'suporte', 1, 3, 'SINALIZADOR', 'sk-flare', 1, 0, 'Marcações de equipe duram 10 segundos a mais.', 'missions/mission-suprimentos-medicos.jpg', 10.00, ' segundos', 'Marcações de equipe duram {v} a mais.'),
(64, 'reanimador', 'suporte', 1, 4, 'REANIMADOR', 'sk-defib', 1, 2, 'Reanima aliados 20% mais rápido.', 'missions/mission-suprimentos-medicos.jpg', 20.00, '%', 'Reanima aliados {v} mais rápido.'),
(65, 'escudo-aliado', 'suporte', 1, 5, 'ESCUDO ALIADO', 'sk-shield-team', 1, 2, 'Aliados próximos recebem 5% menos dano.', 'missions/mission-suprimentos-medicos.jpg', 5.00, '%', 'Aliados próximos recebem {v} menos dano.'),
(66, 'kit-avancado', 'suporte', 2, 1, 'KIT AVANÇADO', 'sk-medkit', 1, 0, 'Kits médicos curam 12% a mais.', 'missions/mission-suprimentos-medicos.jpg', 12.00, '%', 'Kits médicos curam {v} a mais.'),
(67, 'radio-tatico', 'suporte', 2, 2, 'RÁDIO TÁTICO', 'sk-radio', 1, 0, 'Aumenta o alcance do rádio da equipe em 25%.', 'missions/mission-suprimentos-medicos.jpg', 25.00, '%', 'Aumenta o alcance do rádio da equipe em {v}.'),
(68, 'moral-alta', 'suporte', 2, 3, 'MORAL ALTA', 'sk-morale', 3, 3, 'Aumenta a regeneração da equipe em 6% por nível.', 'missions/mission-suprimentos-medicos.jpg', 6.00, '%', 'Aumenta a regeneração da equipe em {v}.'),
(69, 'logistica', 'suporte', 2, 4, 'LOGÍSTICA', 'sk-crate', 1, 3, 'Carrega 2 suprimentos extras.', 'missions/mission-suprimentos-medicos.jpg', 2.00, '', 'Carrega {v}suprimentos extras.'),
(70, 'vigia', 'suporte', 2, 5, 'VIGIA', 'sk-binoculars', 1, 3, 'Marca inimigos automaticamente a 30 metros.', 'missions/mission-suprimentos-medicos.jpg', 30.00, ' metros', 'Marca inimigos automaticamente a {v}.'),
(71, 'medico-campo', 'suporte', 3, 1, 'MÉDICO DE CAMPO', 'sk-field-medic', 3, 3, 'Cura aliados 8% mais rápido por nível.', 'missions/mission-suprimentos-medicos.jpg', 8.00, '%', 'Cura aliados {v} mais rápido.'),
(72, 'suprimento', 'suporte', 3, 2, 'SUPRIMENTO', 'sk-ammo-supply', 3, 3, 'Distribui 10% mais munição por nível.', 'missions/mission-suprimentos-medicos.jpg', 10.00, '%', 'Distribui {v} mais munição.'),
(73, 'inspiracao', 'suporte', 3, 3, 'INSPIRAÇÃO', 'sk-spark', 1, 4, 'Aliados próximos recarregam 10% mais rápido.', 'missions/mission-suprimentos-medicos.jpg', 10.00, '%', 'Aliados próximos recarregam {v} mais rápido.'),
(74, 'barreira', 'suporte', 3, 4, 'BARREIRA', 'sk-barricade', 3, 4, 'Coberturas improvisadas resistem 10% mais por nível.', 'missions/mission-suprimentos-medicos.jpg', 10.00, '%', 'Coberturas improvisadas resistem {v} mais.'),
(75, 'coordenacao', 'suporte', 3, 5, 'COORDENAÇÃO', 'sk-network', 3, 4, 'Reduz o tempo de resposta da equipe em 5% por nível.', 'missions/mission-suprimentos-medicos.jpg', 5.00, '%', 'Reduz o tempo de resposta da equipe em {v}.'),
(76, 'anjo-da-guarda', 'suporte', 4, 1, 'ANJO DA GUARDA', 'sk-guardian', 1, 5, 'Reanimações concedem 20 de vida extra.', 'missions/mission-suprimentos-medicos.jpg', 20.00, '', 'Reanimações concedem {v}de vida extra.'),
(77, 'rede-de-apoio', 'suporte', 4, 2, 'REDE DE APOIO', 'sk-link-team', 1, 5, 'Compartilha bônus de habilidade com a equipe.', 'missions/mission-suprimentos-medicos.jpg', NULL, '', NULL),
(78, 'protocolo', 'suporte', 4, 3, 'PROTOCOLO', 'sk-beacon', 1, 5, 'Marca pontos de extração para toda a equipe.', 'missions/mission-suprimentos-medicos.jpg', NULL, '', NULL),
(79, 'resgate', 'suporte', 4, 4, 'RESGATE', 'sk-carry', 1, 5, 'Arrasta aliados caídos sem perder velocidade.', 'missions/mission-suprimentos-medicos.jpg', NULL, '', NULL),
(80, 'comandante', 'suporte', 4, 5, 'COMANDANTE', 'sk-command', 1, 5, 'Aumenta todo o XP da equipe em 10%.', 'missions/mission-suprimentos-medicos.jpg', 10.00, '%', 'Aumenta todo o XP da equipe em {v}.'),
(81, 'bolsos-extras', 'equipamento', 1, 1, 'BOLSOS EXTRAS', 'sk-pockets', 1, 0, 'Adiciona 2 espaços à mochila.', 'missions/mission-tecnologia-recuperada.jpg', 2.00, '', 'Adiciona {v}espaços à mochila.'),
(82, 'correias', 'equipamento', 1, 2, 'CORREIAS', 'sk-straps', 1, 0, 'Troca de equipamento 15% mais rápida.', 'missions/mission-tecnologia-recuperada.jpg', 15.00, '%', 'Troca de equipamento {v} mais rápida.'),
(83, 'manutencao', 'equipamento', 1, 3, 'MANUTENÇÃO', 'sk-wrench', 1, 0, 'Equipamentos desgastam 12% mais devagar.', 'missions/mission-tecnologia-recuperada.jpg', 12.00, '%', 'Equipamentos desgastam {v} mais devagar.'),
(84, 'optica', 'equipamento', 1, 4, 'ÓPTICA', 'sk-optic', 1, 2, 'Miras acopladas ganham 10% de estabilidade.', 'missions/mission-tecnologia-recuperada.jpg', 10.00, '%', 'Miras acopladas ganham {v} de estabilidade.'),
(85, 'coldre', 'equipamento', 1, 5, 'COLDRE RÁPIDO', 'sk-holster', 1, 2, 'Saca a arma secundária 25% mais rápido.', 'missions/mission-tecnologia-recuperada.jpg', 25.00, '%', 'Saca a arma secundária {v} mais rápido.'),
(86, 'capacete-tec', 'equipamento', 2, 1, 'CAPACETE TÉCNICO', 'sk-helmet-tech', 1, 0, 'Adiciona 5% de proteção na cabeça.', 'missions/mission-tecnologia-recuperada.jpg', 5.00, '%', 'Adiciona {v} de proteção na cabeça.'),
(87, 'mascara', 'equipamento', 2, 2, 'MÁSCARA', 'sk-gasmask', 1, 0, 'Reduz o efeito de gás em 30%.', 'missions/mission-tecnologia-recuperada.jpg', 30.00, '%', 'Reduz o efeito de gás em {v}.'),
(88, 'rig-tatico', 'equipamento', 2, 3, 'RIG TÁTICO', 'sk-rig', 3, 3, 'Adiciona 1 espaço de carregador por nível.', 'missions/mission-tecnologia-recuperada.jpg', 1.00, '', 'Adiciona {v} espaço(s) de carregador ao rig.'),
(89, 'kit-reparo', 'equipamento', 2, 4, 'KIT DE REPARO', 'sk-toolkit', 1, 3, 'Repara equipamentos em campo.', 'missions/mission-tecnologia-recuperada.jpg', NULL, '', NULL),
(90, 'lanterna', 'equipamento', 2, 5, 'LANTERNA', 'sk-flashlight', 1, 3, 'Aumenta o alcance da lanterna em 25%.', 'missions/mission-tecnologia-recuperada.jpg', 25.00, '%', 'Aumenta o alcance da lanterna em {v}.'),
(91, 'carga-pesada', 'equipamento', 3, 1, 'CARGA PESADA', 'sk-heavy-pack', 3, 3, 'Aumenta o peso máximo em 4% por nível.', 'missions/mission-tecnologia-recuperada.jpg', 4.00, '%', 'Aumenta o peso máximo em {v}.'),
(92, 'blindagem', 'equipamento', 3, 2, 'BLINDAGEM', 'sk-armor-plate', 3, 3, 'Coletes resistem 6% mais por nível.', 'missions/mission-tecnologia-recuperada.jpg', 6.00, '%', 'Coletes resistem {v} mais.'),
(93, 'improviso', 'equipamento', 3, 3, 'IMPROVISO', 'sk-improvise', 1, 4, 'Cria bandagens com materiais comuns.', 'missions/mission-tecnologia-recuperada.jpg', NULL, '', NULL),
(94, 'modular', 'equipamento', 3, 4, 'MODULAR', 'sk-modular', 3, 4, 'Acessórios pesam 8% menos por nível.', 'missions/mission-tecnologia-recuperada.jpg', 8.00, '%', 'Acessórios pesam {v} menos.'),
(95, 'reserva', 'equipamento', 3, 5, 'RESERVA', 'sk-spare-mag', 3, 4, 'Carrega 1 carregador extra por nível.', 'missions/mission-tecnologia-recuperada.jpg', 1.00, '', 'Carrega {v} carregador(es) extra(s).'),
(96, 'arsenal', 'equipamento', 4, 1, 'ARSENAL', 'sk-arsenal', 1, 5, 'Permite uma terceira arma primária.', 'missions/mission-tecnologia-recuperada.jpg', NULL, '', NULL),
(97, 'exoesqueleto', 'equipamento', 4, 2, 'EXOESQUELETO', 'sk-exo', 1, 5, 'Ignora a penalidade de sobrepeso.', 'missions/mission-tecnologia-recuperada.jpg', NULL, '', NULL),
(98, 'placa-balistica', 'equipamento', 4, 3, 'PLACA BALÍSTICA', 'sk-plate-ballistic', 1, 5, 'Adiciona 15% de resistência a rifles.', 'missions/mission-tecnologia-recuperada.jpg', 15.00, '%', 'Adiciona {v} de resistência a rifles.'),
(99, 'visao-termica', 'equipamento', 4, 4, 'VISÃO TÉRMICA', 'sk-thermal', 1, 5, 'Destaca corpos quentes a 40 metros.', 'missions/mission-tecnologia-recuperada.jpg', 40.00, ' metros', 'Destaca corpos quentes a {v}.'),
(100, 'prototipo', 'equipamento', 4, 5, 'PROTÓTIPO', 'sk-prototype', 1, 5, 'Libera equipamentos experimentais na loja.', 'missions/mission-tecnologia-recuperada.jpg', NULL, '', NULL)
ON CONFLICT ("id") DO UPDATE SET "slug" = excluded."slug", "category" = excluded."category", "tier" = excluded."tier", "col" = excluded."col", "name" = excluded."name", "icon" = excluded."icon", "max_level" = excluded."max_level", "cost" = excluded."cost", "description" = excluded."description", "image" = excluded."image", "step" = excluded."step", "unit" = excluded."unit", "effect" = excluded."effect";
INSERT INTO "skills" ("id", "slug", "category", "tier", "col", "name", "icon", "max_level", "cost", "description", "image", "step", "unit", "effect") VALUES
(101, 'pechincha', 'comercio', 1, 1, 'PECHINCHA', 'sk-price-tag', 1, 0, 'Reduz o preço de compra em 5%.', 'maps/porto-industrial.jpg', 5.00, '%', 'Reduz o preço de compra em {v}.'),
(102, 'avaliador', 'comercio', 1, 2, 'AVALIADOR', 'sk-appraise', 1, 0, 'Mostra o valor real dos itens encontrados.', 'maps/porto-industrial.jpg', NULL, '', NULL),
(103, 'contatos', 'comercio', 1, 3, 'CONTATOS', 'sk-contacts', 1, 0, 'Libera 1 comerciante adicional.', 'maps/porto-industrial.jpg', 1.00, '', 'Libera {v}comerciante adicional.'),
(104, 'taxas', 'comercio', 1, 4, 'TAXAS MENORES', 'sk-percent-down', 1, 2, 'Reduz a taxa do mercado em 20%.', 'maps/porto-industrial.jpg', 20.00, '%', 'Reduz a taxa do mercado em {v}.'),
(105, 'estoque', 'comercio', 1, 5, 'ESTOQUE', 'sk-shelf', 1, 2, 'Comerciantes repõem estoque 25% mais rápido.', 'maps/porto-industrial.jpg', 25.00, '%', 'Comerciantes repõem estoque {v} mais rápido.'),
(106, 'negociador', 'comercio', 2, 1, 'NEGOCIADOR', 'sk-deal', 3, 3, 'Aumenta o valor de venda em 7% por nível.', 'maps/porto-industrial.jpg', 7.00, '%', 'Aumenta o valor de venda em {v}.'),
(107, 'mercado-negro', 'comercio', 2, 2, 'MERCADO NEGRO', 'sk-blackmarket', 1, 3, 'Libera ofertas restritas por 24 horas.', 'maps/porto-industrial.jpg', 24.00, ' horas', 'Libera ofertas restritas por {v}.'),
(108, 'contrabando', 'comercio', 2, 3, 'CONTRABANDO', 'sk-smuggle', 1, 3, 'Extrai 1 item de contrabando por saída.', 'maps/porto-industrial.jpg', 1.00, '', 'Extrai {v}item de contrabando por saída.'),
(109, 'reputacao', 'comercio', 2, 4, 'REPUTAÇÃO', 'sk-medal', 1, 3, 'Ganha 15% mais reputação com comerciantes.', 'maps/porto-industrial.jpg', 15.00, '%', 'Ganha {v} mais reputação com comerciantes.'),
(110, 'leilao', 'comercio', 2, 5, 'LEILÃO', 'sk-gavel', 1, 3, 'Permite leiloar itens raros.', 'maps/porto-industrial.jpg', NULL, '', NULL),
(111, 'investidor', 'comercio', 3, 1, 'INVESTIDOR', 'sk-chart-up', 3, 3, 'Rende 3% de juros por nível a cada saída.', 'maps/porto-industrial.jpg', 3.00, '%', 'Rende {v} de juros a cada saída.'),
(112, 'logistico', 'comercio', 3, 2, 'LOGÍSTICO', 'sk-truck', 3, 3, 'Reduz o frete de seguro em 10% por nível.', 'maps/porto-industrial.jpg', 10.00, '%', 'Reduz o frete de seguro em {v}.'),
(113, 'informante', 'comercio', 3, 3, 'INFORMANTE', 'sk-informant', 1, 4, 'Revela a demanda do mercado do dia.', 'maps/porto-industrial.jpg', NULL, '', NULL),
(114, 'cartel', 'comercio', 3, 4, 'CARTEL', 'sk-cartel', 3, 4, 'Preços de clã caem 5% por nível.', 'maps/porto-industrial.jpg', 5.00, '%', 'Preços de clã caem {v}.'),
(115, 'monopolio', 'comercio', 3, 5, 'MONOPÓLIO', 'sk-monopoly', 3, 4, 'Reserva itens raros por 6% mais tempo por nível.', 'maps/porto-industrial.jpg', 6.00, '%', 'Reserva itens raros por {v} mais tempo.'),
(116, 'magnata', 'comercio', 4, 1, 'MAGNATA', 'sk-money-bag', 1, 5, 'Aumenta os créditos ganhos em 20%.', 'maps/porto-industrial.jpg', 20.00, '%', 'Aumenta os créditos ganhos em {v}.'),
(117, 'rede-global', 'comercio', 4, 2, 'REDE GLOBAL', 'sk-globe', 1, 5, 'Compra em qualquer comerciante à distância.', 'maps/porto-industrial.jpg', NULL, '', NULL),
(118, 'seguro-total', 'comercio', 4, 3, 'SEGURO TOTAL', 'sk-insurance', 1, 5, 'Recupera 50% do valor do equipamento perdido.', 'maps/porto-industrial.jpg', 50.00, '%', 'Recupera {v} do valor do equipamento perdido.'),
(119, 'lavagem', 'comercio', 4, 4, 'LAVAGEM', 'sk-launder', 1, 5, 'Vende itens marcados sem penalidade.', 'maps/porto-industrial.jpg', NULL, '', NULL),
(120, 'lenda-mercado', 'comercio', 4, 5, 'LENDA DO MERCADO', 'sk-diamond', 1, 5, 'Desbloqueia o catálogo lendário.', 'maps/porto-industrial.jpg', NULL, '', NULL)
ON CONFLICT ("id") DO UPDATE SET "slug" = excluded."slug", "category" = excluded."category", "tier" = excluded."tier", "col" = excluded."col", "name" = excluded."name", "icon" = excluded."icon", "max_level" = excluded."max_level", "cost" = excluded."cost", "description" = excluded."description", "image" = excluded."image", "step" = excluded."step", "unit" = excluded."unit", "effect" = excluded."effect";

-- ---------------------------------------------------------------- news (8 linhas)
INSERT INTO "news" ("id", "slug", "title", "category", "excerpt", "body", "image", "published", "sort_order") VALUES
(1, 'atualizacao-8-8-1', 'ATUALIZAÇÃO 8.8.1', 'ATUALIZAÇÃO', 'Novas missões, armas e ajustes de desempenho.', '["A atualização 8.8.1 chega com duas novas missões principais na Floresta Verde, o fuzil M4A1 com acessórios modulares e uma passada geral no desempenho do cliente.","Reduzimos o consumo de memória de texturas em 18% e corrigimos o travamento ao abrir o inventário durante o carregamento do mapa.","Também ajustamos a economia: o preço de venda de itens comuns subiu 10% e a Caixa de Suprimentos diária agora garante pelo menos um item raro."]', 'news/news-atualizacao.jpg', '2024-05-30', 0),
(2, 'evento-caca-na-floresta', 'EVENTO: CAÇA NA FLORESTA', 'EVENTO', 'Participe do evento especial e ganhe recompensas exclusivas.', '["Até 05\/06 a Floresta Verde recebe matilhas de lobos, acampamentos inimigos reforçados e entregas aéreas a cada ciclo.","Complete os desafios do evento para desbloquear a camuflagem exclusiva \"Mata Fechada\" e 8.000 XP de bônus."]', 'news/news-evento-caca.jpg', '2024-05-28', 1),
(3, 'manutencao-programada', 'MANUTENÇÃO PROGRAMADA', 'MANUTENÇÃO', 'Servidores ficarão offline para manutenção.', '["Na terça-feira, 04\/06, os servidores da América do Sul ficarão offline das 03h às 05h (horário de Brasília) para migração de infraestrutura.","Partidas em andamento serão encerradas 10 minutos antes com aviso no HUD. Nenhum progresso será perdido."]', 'news/news-manutencao.jpg', '2024-05-27', 2),
(4, 'novo-mapa-complexo-militar', 'NOVO MAPA: COMPLEXO MILITAR', 'CONTEÚDO', 'Uma base fortificada com três pontos de extração e zonas de risco dinâmicas.', '["O Complexo Militar é o maior mapa de Dominação até agora: 6v6, três pontos de captura e uma torre central que muda de dono a cada ciclo.","As zonas de risco se deslocam a cada 4 minutos e forçam o esquadrão a decidir entre segurar o pátio central ou correr para a extração norte."]', 'maps/complexo-militar.jpg', '2024-05-20', 3),
(5, 'balanceamento-de-armas', 'BALANCEAMENTO DE ARMAS', 'BALANCEAMENTO', 'M4A1 com menos recuo horizontal; Glock 17 ganha 2 balas por carregador.', '["Depois de analisar 2 milhões de confrontos, ajustamos o recuo horizontal da M4A1 (-12%) e aumentamos o carregador da Glock 17 para 17+2.","A AK-103 segue inalterada, mas seu preço na loja de facção caiu 8%."]', 'maps/porto-industrial.jpg', '2024-05-15', 4),
(6, 'boletim-coro-k07', 'O CORO SE AGITA NO NORTE', 'BOLETIM', 'Sensores do Icarus registram pulsos no micélio perto do Setor K-07, mais fortes a cada noite. O padrão bate com o que a estação registrou antes de silenciar.', 'Sensores do Icarus registram pulsos no micélio perto do Setor K-07, mais fortes a cada noite. O padrão bate com o que a estação registrou antes de silenciar.', 'news/news-atualizacao.jpg', '2026-09-06', 1),
(7, 'boletim-aegis-ponte', 'AEGIS REFORÇA A PONTE LESTE', 'BOLETIM', 'Blindados foram vistos cruzando a estrada leste da Floresta Verde. Mercenários sob contrato Aegis relatam patrulhas dobradas.', 'Blindados foram vistos cruzando a estrada leste da Floresta Verde. Mercenários sob contrato Aegis relatam patrulhas dobradas.', 'news/news-atualizacao.jpg', '2026-09-06', 2),
(8, 'boletim-janela-logistica', 'PRÓXIMA JANELA LOGÍSTICA CONFIRMADA', 'BOLETIM', 'Um comboio de reabastecimento cruza o setor nas próximas horas. Carga previsível atrai gente previsível — e todo infectado do setor ouve motor.', 'Um comboio de reabastecimento cruza o setor nas próximas horas. Carga previsível atrai gente previsível — e todo infectado do setor ouve motor.', 'news/news-atualizacao.jpg', '2026-09-06', 3)
ON CONFLICT ("id") DO UPDATE SET "slug" = excluded."slug", "title" = excluded."title", "category" = excluded."category", "excerpt" = excluded."excerpt", "body" = excluded."body", "image" = excluded."image", "published" = excluded."published", "sort_order" = excluded."sort_order";

-- ---------------------------------------------------------------- seasons (1 linhas)
INSERT INTO "seasons" ("id", "slug", "number", "tag", "pass_name", "pass_image", "xp_per_level", "event_tag", "event_title", "event_desc", "event_image", "event_ends_at", "starts_at", "ends_at", "is_current") VALUES
(1, 'temporada-04', 4, 'TEMPORADA 04', 'MATA FECHADA', 'ui/season-pass.jpg', 10000, 'EVENTO DE TEMPORADA', 'CAÇA NA FLORESTA', 'Objetivos exclusivos da Temporada 04. As recompensas somem quando o evento terminar.', 'missions/mission-cacada-ao-cacador.jpg', '2026-09-07 15:21:25', '2026-07-24 03:21:25', '2026-09-22 07:21:25', 1)
ON CONFLICT ("id") DO UPDATE SET "slug" = excluded."slug", "number" = excluded."number", "tag" = excluded."tag", "pass_name" = excluded."pass_name", "pass_image" = excluded."pass_image", "xp_per_level" = excluded."xp_per_level", "event_tag" = excluded."event_tag", "event_title" = excluded."event_title", "event_desc" = excluded."event_desc", "event_image" = excluded."event_image", "event_ends_at" = excluded."event_ends_at", "starts_at" = excluded."starts_at", "ends_at" = excluded."ends_at", "is_current" = excluded."is_current";

-- ---------------------------------------------------------------- season_tiers (3 linhas)
INSERT INTO "season_tiers" ("id", "season_id", "level", "name", "image", "rarity", "item_id", "sort_order") VALUES
(1, 1, 19, 'CAMUFLAGEM', 'items/skin-camo.png', 'raro', 42, 0),
(2, 1, 20, 'SUPRIMENTOS', 'items/supply-crate.png', 'epico', 20, 1),
(3, 1, 22, 'CHAVE DE ACESSO', 'items/keycard.png', 'lendario', 41, 2)
ON CONFLICT ("id") DO UPDATE SET "season_id" = excluded."season_id", "level" = excluded."level", "name" = excluded."name", "image" = excluded."image", "rarity" = excluded."rarity", "item_id" = excluded."item_id", "sort_order" = excluded."sort_order";

-- ---------------------------------------------------------------- challenges (15 linhas)
INSERT INTO "challenges" ("id", "slug", "scope", "season_id", "label", "description", "icon", "target", "xp", "credits", "highlight", "sort_order") VALUES
(1, 'elimine-20', 'diarios', NULL, 'Elimine 20 inimigos', 'Em qualquer modo com outros jogadores.', 'skull', 20, 2500, 1200, 0, 0),
(2, 'colete-5-raros', 'diarios', NULL, 'Colete 5 itens raros', 'Raridade épica ou superior.', 'inventory_2', 5, 2500, 900, 0, 1),
(3, 'sobreviva-30', 'diarios', NULL, 'Sobreviva por 30 minutos', 'Tempo acumulado sem ser eliminado.', 'schedule', 30, 2500, 1000, 0, 2),
(4, 'conclua-todos', 'diarios', NULL, 'Conclua todos os desafios diários', 'Bônus por limpar o quadro do dia.', 'local_fire_department', 3, 5000, 2500, 1, 3),
(5, 'extraia-10', 'semanais', NULL, 'Extraia com sucesso 10 vezes', 'Saia do mapa com o saque intacto.', 'flag', 10, 10000, 4000, 0, 0),
(6, 'headshots-25', 'semanais', NULL, 'Realize 25 tiros na cabeça', 'Vale para qualquer arma de fogo.', 'my_location', 25, 7500, 3000, 0, 1),
(7, 'missoes-5', 'semanais', NULL, 'Conclua 5 missões da Resistência', 'Contratos do quadro de missões.', 'handshake', 5, 12000, 5000, 1, 2),
(8, 'cure-aliados', 'semanais', NULL, 'Cure aliados 15 vezes', 'Kit médico ou bandagem em esquadrão.', 'medical_services', 15, 6000, 2200, 0, 3),
(9, 'venda-itens', 'semanais', NULL, 'Venda 30 itens a comerciantes', 'Qualquer comerciante da Zona.', 'shopping_cart', 30, 5000, 1800, 0, 4),
(10, 'lobos-20', 'temporada', 1, 'Elimine 20 lobos na Floresta Verde', 'Matilhas aparecem ao anoitecer.', 'pets', 20, 4000, 1500, 0, 0),
(11, 'acampamentos-3', 'temporada', 1, 'Limpe 3 acampamentos inimigos', 'Marcados no mapa como zona de risco.', 'local_fire_department', 3, 6000, 2400, 0, 1),
(12, 'entrega-aerea', 'temporada', 1, 'Recupere 2 entregas aéreas', 'Sinalizadores caem a cada 12 minutos.', 'inventory_2', 2, 8000, 3200, 0, 2),
(13, 'passe-nivel-25', 'temporada', 1, 'Alcance o nível 25 do passe', 'Qualquer XP da temporada conta.', 'military_tech', 25, 15000, 6000, 0, 3),
(14, 'extracao-noite', 'temporada', 1, 'Extraia 5 vezes durante a noite', 'Entre 22h e 5h no ciclo do mapa.', 'dark_mode', 5, 9000, 3600, 0, 4),
(15, 'cacador-alfa', 'temporada', 1, 'Derrote o Caçador Alfa', 'Chefe regional da Floresta Verde.', 'skull', 1, 20000, 8000, 1, 5)
ON CONFLICT ("id") DO UPDATE SET "slug" = excluded."slug", "scope" = excluded."scope", "season_id" = excluded."season_id", "label" = excluded."label", "description" = excluded."description", "icon" = excluded."icon", "target" = excluded."target", "xp" = excluded."xp", "credits" = excluded."credits", "highlight" = excluded."highlight", "sort_order" = excluded."sort_order";

-- ---------------------------------------------------------------- challenge_bonuses (3 linhas)
INSERT INTO "challenge_bonuses" ("scope", "label", "title", "description", "image", "xp", "credits") VALUES
('diarios', 'BÔNUS DIÁRIO', 'CAIXA DE SUPRIMENTOS', 'Conclua os 4 desafios do dia.', 'items/supply-crate.png', 5000, 2500),
('semanais', 'BÔNUS SEMANAL', 'ESTOJO DE ARMA', 'Conclua 5 desafios semanais.', 'items/weapon-case.png', 15000, 9000),
('temporada', 'BÔNUS DA CAÇADA', 'CAMUFLAGEM MATA FECHADA', 'Conclua 6 desafios de temporada.', 'items/skin-camo.png', 30000, 20000)
ON CONFLICT ("scope") DO UPDATE SET "label" = excluded."label", "title" = excluded."title", "description" = excluded."description", "image" = excluded."image", "xp" = excluded."xp", "credits" = excluded."credits";

-- ---------------------------------------------------------------- government_bases (3 linhas)
INSERT INTO "government_bases" ("id", "slug", "name", "map_id", "capacity", "status", "sort_order") VALUES
(1, 'alpha', 'BASE HUNTER ALPHA', 1, 4, 'OPERACIONAL', 1),
(2, 'bravo', 'BASE HUNTER BRAVO', 1, 4, 'OPERACIONAL', 2),
(3, 'charlie', 'BASE HUNTER CHARLIE', 1, 4, 'EM RISCO', 3)
ON CONFLICT ("id") DO UPDATE SET "slug" = excluded."slug", "name" = excluded."name", "map_id" = excluded."map_id", "capacity" = excluded."capacity", "status" = excluded."status", "sort_order" = excluded."sort_order";

-- ---------------------------------------------------------------- safe_zones (5 linhas)
INSERT INTO "safe_zones" ("id", "slug", "name", "kind", "base_slug", "map_id", "region", "status", "occupancy", "capacity", "protection", "description", "meta", "sort_order") VALUES
(1, 'vigia', 'SAFE ZONE VIGIA', 'gov', 'alpha', 1, 'FLORESTA VERDE', 'ABERTA', 'MODERADA', 24, 'PERÍMETRO ATIVO · GUARNIÇÃO ALPHA', 'O pátio interno da Base Vigia. Mercado coberto, oficina e enfermaria a poucos passos do portão de saída para a Floresta Verde.', '{"rules": ["Armamento pesado fica guardado no arsenal da base enquanto você estiver dentro do perímetro.", "Fogo contra outro operador dentro da zona é registrado como conduta e vai ao Relatório de Conduta.", "Infectado não passa da cerca: o perímetro é mantido pela guarnição da base."], "services": ["MERCADO COBERTO", "OFICINA", "ENFERMARIA", "ALOJAMENTO", "TERMINAL ICARUS", "GARAGEM"]}', 1),
(2, 'bravo', 'SAFE ZONE BRAVO', 'gov', 'bravo', 1, 'FLORESTA VERDE', 'MOVIMENTADA', 'ALTA', 24, 'PERÍMETRO ATIVO · GUARNIÇÃO BRAVO', 'A zona mais movimentada da região: quem volta de operação passa por aqui antes de voltar para o mundo.', '{"rules": ["Armamento pesado fica guardado no arsenal da base enquanto você estiver dentro do perímetro.", "Fogo contra outro operador dentro da zona é registrado como conduta e vai ao Relatório de Conduta.", "Infectado não passa da cerca: o perímetro é mantido pela guarnição da base."], "services": ["MERCADO COBERTO", "OFICINA", "ENFERMARIA", "ALOJAMENTO", "TERMINAL ICARUS", "GARAGEM"]}', 1),
(3, 'charlie', 'SAFE ZONE CHARLIE', 'gov', 'charlie', 1, 'FLORESTA VERDE', 'EM ALERTA', 'BAIXA', 24, 'PERÍMETRO SOB PRESSÃO · GUARNIÇÃO CHARLIE', 'Base isolada pelo avanço da Veyra no setor norte. Serviços reduzidos e movimento fraco enquanto a estrada não reabre.', '{"rules": ["Armamento pesado fica guardado no arsenal da base enquanto você estiver dentro do perímetro.", "Fogo contra outro operador dentro da zona é registrado como conduta e vai ao Relatório de Conduta.", "Infectado não passa da cerca: o perímetro é mantido pela guarnição da base."], "services": ["MERCADO COBERTO", "OFICINA", "ENFERMARIA", "ALOJAMENTO", "TERMINAL ICARUS", "GARAGEM"]}', 1),
(4, 'franca', 'ZONA FRANCA', 'free', NULL, 1, 'FLORESTA VERDE', 'TOLERADA', 'MODERADA', 16, 'SEM PERÍMETRO · TRÉGUA COMBINADA', 'Um acampamento tolerado pela Frontier fora do alcance do governo. Aceita quem nenhuma base aceita — inclusive quem perdeu a bandeira.', '{"rules": ["Ninguém guarda sua arma e ninguém garante sua segurança: a trégua aqui é combinada, não imposta.", "A Frontier tolera a zona enquanto ela render — e cobra por isso.", "É a única zona que aceita quem está sem bandeira."], "services": ["MERCADO ABERTO", "OFICINA IMPROVISADA", "POSTO MÉDICO", "DORMITÓRIO COLETIVO"]}', 1),
(5, 'entreposto', 'ENTREPOSTO CENTRAL', 'free', NULL, 1, 'PÂNTANO CINZENTO', 'ABERTA', 'MODERADA', 20, 'SEM PERÍMETRO · TRÉGUA COMBINADA', 'Entroncamento de estradas onde comboios param antes de cruzar o pântano. Ninguém manda, mas todo mundo precisa: combustível, comida e um médico.', '{"rules": ["Ninguém guarda sua arma: a trégua aqui é comercial.", "Quem atira perde o direito de voltar a comprar.", "Aceita operador sem bandeira."], "services": ["POSTO DE COMBUSTÍVEL", "MERCADO", "HOSPITAL DE CAMPANHA", "OFICINA"]}', 2)
ON CONFLICT ("id") DO UPDATE SET "slug" = excluded."slug", "name" = excluded."name", "kind" = excluded."kind", "base_slug" = excluded."base_slug", "map_id" = excluded."map_id", "region" = excluded."region", "status" = excluded."status", "occupancy" = excluded."occupancy", "capacity" = excluded."capacity", "protection" = excluded."protection", "description" = excluded."description", "meta" = excluded."meta", "sort_order" = excluded."sort_order";

-- ---------------------------------------------------------------- faq (4 linhas)
INSERT INTO "faq" ("id", "slug", "question", "answer", "sort_order") VALUES
(1, 'faq-1', 'Perdi itens após uma queda de conexão. Como recupero?', 'Itens perdidos por desconexão são restaurados automaticamente em até 24 horas. Se não voltarem, abra um chamado em PROBLEMA TÉCNICO informando o horário da partida.', 0),
(2, 'faq-2', 'Como vinculo minha conta Steam?', 'Em CONFIGURAÇÕES > CONTA, use a opção de vinculação. A conta vinculada libera o login com um clique e sincroniza suas conquistas.', 1),
(3, 'faq-3', 'Posso transferir moedas entre contas?', 'Não. Créditos, dinheiro e pontos são intransferíveis. Tentativas de venda externa resultam em suspensão permanente.', 2),
(4, 'faq-4', 'O que acontece com meu progresso na manutenção?', 'Nada é perdido. Partidas em andamento são encerradas dez minutos antes, com aviso no HUD, e todo o loot recolhido é preservado.', 3)
ON CONFLICT ("id") DO UPDATE SET "slug" = excluded."slug", "question" = excluded."question", "answer" = excluded."answer", "sort_order" = excluded."sort_order";

-- ---------------------------------------------------------------- support_categories (6 linhas)
INSERT INTO "support_categories" ("id", "slug", "label", "sla_hours", "sort_order") VALUES
(1, 'conta', 'CONTA E ACESSO', 12, 0),
(2, 'pagamento', 'COMPRAS E PAGAMENTOS', 6, 1),
(3, 'tecnico', 'PROBLEMA TÉCNICO', 8, 2),
(4, 'jogabilidade', 'JOGABILIDADE', 24, 3),
(5, 'denuncia', 'DENUNCIAR JOGADOR', 48, 4),
(6, 'outro', 'OUTRO ASSUNTO', 24, 5)
ON CONFLICT ("id") DO UPDATE SET "slug" = excluded."slug", "label" = excluded."label", "sla_hours" = excluded."sla_hours", "sort_order" = excluded."sort_order";

-- ---------------------------------------------------------------- server_regions (4 linhas)
INSERT INTO "server_regions" ("id", "slug", "label", "ping_ms", "players", "state", "sort_order") VALUES
(1, 'am-erica-do-sul', 'AMÉRICA DO SUL', 32, 12480, 'ok', 0),
(2, 'am-erica-do-norte', 'AMÉRICA DO NORTE', 96, 18204, 'ok', 1),
(3, 'europa', 'EUROPA', 142, 21870, 'ok', 2),
(4, 'asia', 'ÁSIA', 208, 9310, 'ok', 3)
ON CONFLICT ("id") DO UPDATE SET "slug" = excluded."slug", "label" = excluded."label", "ping_ms" = excluded."ping_ms", "players" = excluded."players", "state" = excluded."state", "sort_order" = excluded."sort_order";

-- ---------------------------------------------------------------- server_services (3 linhas)
INSERT INTO "server_services" ("id", "slug", "label", "state", "status_label", "short_label", "maint_scope", "maint_starts_at", "maint_minutes", "sort_order") VALUES
(1, 'login', 'LOGIN', 'ok', 'OPERACIONAL', 'ESTÁVEL', NULL, NULL, NULL, 0),
(2, 'partidas', 'PARTIDAS', 'ok', 'OPERACIONAL', 'ESTÁVEL', NULL, NULL, NULL, 1),
(3, 'loja', 'LOJA', 'warn', 'MANUTENÇÃO', 'MANUTENÇÃO', 'LOJA E MERCADO', '2026-09-04 05:36:41', 40, 2)
ON CONFLICT ("id") DO UPDATE SET "slug" = excluded."slug", "label" = excluded."label", "state" = excluded."state", "status_label" = excluded."status_label", "short_label" = excluded."short_label", "maint_scope" = excluded."maint_scope", "maint_starts_at" = excluded."maint_starts_at", "maint_minutes" = excluded."maint_minutes", "sort_order" = excluded."sort_order";

-- ---------------------------------------------------------------- maintenance_windows (3 linhas)
INSERT INTO "maintenance_windows" ("id", "starts_at", "ends_at", "region", "note", "news_slug") VALUES
(1, '2026-09-04 17:00:00', '2026-09-04 19:00:00', 'AMÉRICA DO SUL', 'Contratos ativos são preservados.', 'manutencao-programada'),
(2, '2026-09-12 17:00:00', '2026-09-12 19:00:00', 'AMÉRICA DO SUL', 'Contratos ativos são preservados.', 'manutencao-programada'),
(3, '2026-09-26 17:00:00', '2026-09-26 19:00:00', 'AMÉRICA DO SUL', 'Contratos ativos são preservados.', 'manutencao-programada')
ON CONFLICT ("id") DO UPDATE SET "starts_at" = excluded."starts_at", "ends_at" = excluded."ends_at", "region" = excluded."region", "note" = excluded."note", "news_slug" = excluded."news_slug";

-- ---------------------------------------------------------------- admin_settings (12 linhas)
INSERT INTO "admin_settings" ("key", "value", "kind", "label", "grupo", "updated_at", "updated_by") VALUES
('human_signal_ttl_min', '25', 'int', 'Validade do sinal de atividade humana (min)', 'mundo', '2026-09-10 10:42:23', NULL),
('maintenance_enabled', '0', 'bool', 'Servidor em manutenção', 'servidor', '2026-09-16 17:20:01', NULL),
('maintenance_message', 'MANUTENÇÃO PROGRAMADA. VOLTAMOS EM BREVE.', 'string', 'Aviso exibido durante a manutenção', 'servidor', '2026-09-16 17:20:32', NULL),
('region_capacity_hunters', '12', 'int', 'Vagas de Hunter por região (3 bases × 4)', 'mundo', '2026-09-10 10:42:23', NULL),
('region_capacity_mercenaries', '8', 'int', 'Vagas de Mercenário por região', 'mundo', '2026-09-10 10:42:23', NULL),
('region_capacity_total', '20', 'int', 'Operadores simultâneos por região', 'mundo', '2026-09-10 10:42:23', NULL),
('registration_open', '1', 'bool', 'Criação de contas liberada', 'servidor', '2026-09-16 16:25:22', NULL),
('safe_shift_minutes', '45', 'int', 'Duração do turno na Safe Zone (min)', 'safe_zone', '2026-09-10 10:42:23', NULL),
('squad_max', '4', 'int', 'Tamanho máximo do esquadrão', 'partida', '2026-09-16 17:19:47', NULL),
('support_sla_hours', '24', 'int', 'Prazo de resposta padrão do suporte (h)', 'suporte', '2026-09-10 10:42:23', NULL),
('world_event_interval_min', '90', 'int', 'Intervalo entre eventos de mundo (min)', 'mundo', '2026-09-10 10:42:23', NULL),
('xp_per_level', '10000', 'int', 'XP necessário por nível do passe', 'progressao', '2026-09-10 10:42:23', NULL)
ON CONFLICT ("key") DO UPDATE SET "value" = excluded."value", "kind" = excluded."kind", "label" = excluded."label", "grupo" = excluded."grupo", "updated_at" = excluded."updated_at", "updated_by" = excluded."updated_by";

-- ---------------------------------------------------------------- system_notices (0 linhas)
