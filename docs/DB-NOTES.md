# Extract Hunters — Camada de dados (Fase 2)

Tudo é dinâmico e conciliado por `user_id`. Catálogo (definições do jogo, iguais para todos)
mora em tabelas de catálogo; estado por jogador em tabelas `player_*`. Sem login, `current_user_id()`
cai para o usuário demo (menor id) para a HUD continuar navegável.

## Conexão e camada
- `App\Core\Database` — PDO singleton (root@127.0.0.1 sem senha, banco `extracthunters`, ou `app/db.local.php`).
  Métodos: `Database::one($sql,$p)`, `all()`, `value()`, `run()`, `insert($tabela,$assoc)`, `transaction($fn)`.
- Reinstalar: `php db/migrate.php` (dropa+cria) depois `php db/seed.php` (popula do `app/Data/*` + jogador demo).

### ⚠ Os dois são DESTRUTIVOS
`migrate.php` faz DROP+CREATE e `seed.php` faz TRUNCATE em `users`/`players`: contas criadas
pelo /criar-conta somem nos dois. `db/guard.php` aborta quando existe conta fora do seed
(a lista de contas do seed está em `CONTAS_DO_SEED`); `--force` prossegue mas grava antes
`db/backups/pre-<script>-<data>.json` com `users` + todas as tabelas `player_*`.
Restaurar: `php db/restore.php <arquivo.json>` — faz UPSERT, nunca apaga (aceita `--dry-run`).
**Para só acrescentar uma coluna, escreva um ALTER TABLE avulso** em vez de re-migrar.


## Repositórios (`app/Repo`)
- `PlayerRepo::forUser($uid)` → array no formato da view: `name, level, xp, xp_next, currencies{credits,cash,points}, class, clan, avatar, rank, season_level, health{}, match_stats{matches,kills,extractions,deaths}`.
  Mutações: `adjustCurrency($uid,$cur,$delta)` (debita se negativo, false se faltar saldo), `addXp($uid,$n)`.
- `CatalogRepo` (cacheado por request): `items()`, `itemsById()`, `item($id)`, `shopOffers()`,
  `maps('region'|'match')`, `mapMarkers($mapId)`, `missions()` (com `rewards[]`), `achievements()` (com `rewards[]`),
  `skills()`, `news()` (body = array de parágrafos), `equipmentSlots()`.
- `InventoryRepo` — inventário Tarkov: `tree($uid)` (stash+pockets+slots+containers+attachments+weight+value),
  `move($uid,$id,$dest)`, `installAttachment(...)`, `buy($uid,$offerId)`, `sendToStash(...)`.
  `player_items.container` aceita **só** `stash | pockets | equip | grid | attach` (whitelist em `move()`).
  Grades: stash `STASH_COLS x STASH_ROWS` (8x11), bolsos `POCKET_COLS x 1` (4x1, fixos no personagem,
  sem item pai), `grid` = grade interna do contêiner em `parent_id`. Os 12 slots de equipamento
  vêm de `equipment_slots` (seed em `db/seed.php`) na ordem do GEAR: fone, capacete, máscara,
  braçadeira, colete, óculos, bandoleira, coldre, costas, bainha, colete tático, mochila.
- `UserRepo` — `findByLogin`, `create($user,$email,$pass)` (cria jogador nível 0 + dinheiro + kit).
- `SettingsRepo` — valores da tela CONFIGURAÇÕES em `player_settings` (chave/valor por `user_id`).
  `dbReady()` só checa conexão: a tabela vem de `db/schema.sql`, não é mais criada em runtime.
- `SupportRepo` — tela SUPORTE. Catálogo: `faq()`, `categories()` (slug/label/sla),
  `status()` (regiões + serviços, selo derivado dos estados reais) e `maintenance()`
  (serviço fora do ar; `starts_in` recalculado a cada request). Por jogador:
  `ticketsFor($uid,$n)`, `open($uid,$values)` (grava em `support_tickets`, devolve o protocolo),
  `nextProtocol()`, `slaHours($slug)`, `latestSlaFor($uid)`. Seed em `db/seed_support.php`.
- `ChallengeRepo` — tela DESAFIOS. `board($uid)` devolve as três abas (`diarios|semanais|temporada`),
  cada uma com `items[] / done / total / seconds`, cruzando `challenges` (catálogo) com
  `player_challenges` (progresso do uid). `season()` / `tiers($id)` / `bonuses()` são catálogo;
  `playerSeason($uid,$id)` traz passe + estatísticas da semana (`player_season`).
  Prazos NUNCA são texto fixo: `deadlines()` e `eventSeconds()` devolvem SEGUNDOS calculados de
  DATETIME reais (próxima meia-noite, próxima segunda, `seasons.ends_at`) — a view imprime o texto
  inicial e `assets/js/pages/desafios.js` reconta a partir de `data-countdown="<segundos>"`.
  Mutação: `claimBonus($uid,$scope)` — resgata o bônus da aba (só com o quadro limpo), credita
  XP + créditos e grava em `player_bonus_claims` com `period_key` (dia/semana/temporada), o que
  impede resgatar duas vezes no mesmo período.
- `FriendsRepo` — tela AMIGOS. `forUser($uid)` devolve `online[] / offline[] / pending[] / squad[] / counts{}`
  a partir de `friends` (relation = amigo | pedido_recebido | pedido_enviado) e `squad_history`.
  Rótulos ("HÁ 12 MIN", "Visto há 2 horas", "128 PARTIDAS") são derivados de `last_seen`/`matches`
  via `TIMESTAMPDIFF` no MySQL (não dependem do fuso do PHP). Mutações: `invite($uid,$nome,$eu)`,
  `accept`, `decline`, `cancel` — todas devolvem `[chave_de_flash, mensagem]`.
  Carga inicial: `php db/seed_friends.php` (roda sozinho ou dentro de `db/seed.php`).
- `MessageRepo` — caixa de mensagens por jogador (`message_threads`, `message_entries`, `system_notices`)
  e a próxima janela de manutenção (`maintenance_windows`, catálogo).
  Leitura: `threads($uid)` (conversas já com `thread[]` e separadores HOJE/ONTEM/dd/mm calculados na hora),
  `folderCounts($uid)`, `unreadCount($uid)`, `notices($uid)`, `maintenance()` (contador em segundos vivo).
  Mutações: `markRead($uid,$slug)`, `reply($uid,$slug,$texto)` (grava o balão do jogador; devolve null se
  a conversa não for desse `user_id`). Popular sem recriar o banco: `php db/seed_messages.php`.

## Nada de DDL em tempo de execução
`db/schema.sql` é a única fonte do esquema — `grep -rn "CREATE TABLE" app/` tem de sair vazio.
`player_settings` e `player_bug_reports` eram criadas sob demanda e **sem FK**; um `DELETE` em
`users` deixava as linhas órfãs e o próximo jogador com o mesmo id herdava as preferências e os
relatórios do anterior. Hoje as duas têm `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`.
Nomes de constraint são GLOBAIS no MySQL: `fk_ps_user` já é de `player_season`, por isso
`player_settings` usa `fk_pset_user`.

## Como conciliar uma tela por jogador
No controller: `$uid = $this->userId();` e `$player = $this->player();` (já vem do banco).
Progresso do jogador cruza catálogo × tabela `player_*`:
```php
// missões do jogador: junta missions (catálogo) com player_missions (status/progresso do uid)
$rows = Database::all(
  'SELECT m.*, pm.status, pm.progress FROM missions m
   LEFT JOIN player_missions pm ON pm.mission_id = m.id AND pm.user_id = :u
   ORDER BY m.sort_order', ['u' => $uid]);
```
Mesmo padrão para `player_achievements` (progress, completed_at), `player_skills` (level),
`player_matches` (estatísticas do perfil), `player_purchases` (histórico da loja).

## Mídia
Conteúdo dinâmico (imagens de item/mapa/missão/notícia/retrato) vive em `/media/<tipo>/`.
Na view use `media('items/ak103.png')` (cai para `assets/img/` se ainda não migrado).
As linhas do catálogo guardam o caminho relativo (ex.: `items/ak103.png`).

## Telas: cromo x progresso
`app/Data/*.php` guarda o CROMO (títulos de painel, CTAs, destinos, textos de formulário) — igual
para todos. Tudo que é PROGRESSO do jogador vem do banco. `HomeController` é o exemplo: passe de
temporada (`seasons` + `season_tiers` + `player_season`) e contrato ativo (`missions` +
`player_missions` + `mission_rewards`). Antes esses dois blocos eram fixos e mostravam nível 45 /
2450 XP para um jogador nível 0. `node tools/test_dados_dinamicos.mjs` compara o que a tela
renderiza com o que o MySQL guarda e falha se voltarem a divergir.
`season_tiers.rarity` existe porque o prestígio de um patamar do passe não é a raridade do item
no loot (a camuflagem é `comum` como item e `raro` como recompensa de passe).

## Regras
- PHP `declare(strict_types=1)`; toda saída por `e()`; POST com `csrf_field()`/`Security::checkCsrf`.
- Endpoints JSON: `Response::json($payload,$status)`. CSRF via `<meta name="csrf-token">` (já no layout) para fetch.
- Não quebrar o formato que a view espera: mapeie a linha do banco para as MESMAS chaves que o `data_mock` usava.
