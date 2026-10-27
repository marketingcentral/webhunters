# Extract Hunters — port para Cloudflare (Workers + D1 + R2)

Esta pasta é o Extract Hunters reescrito em TypeScript para rodar num **Cloudflare Worker**,
com **D1** (SQLite) no lugar do MySQL e **R2** para a mídia. Desde 2026-10-27 o domínio
`extracthunters.com.br` aponta para este Worker. O app PHP da raiz não foi alterado.

A arquitetura espelha a do PHP de propósito (front controller → guards → router → controller
→ repo → view/layout), com os mesmos nomes, para que quem conhece um ache o outro.

## Estado

**Portado e testado com `wrangler dev`** (sessão, CSRF, guards, navegação parcial do `spa.js`):

| Tela / rota | Situação |
|---|---|
| `/` landing | portada (mesmo mapa da HUD via `partials/map-stage`) |
| `/login`, `/criar-conta`, `POST /sair` | portadas; HTML idêntico ao PHP (diff só de espaço em branco) |
| `/inicio` | portada (temporada, contrato, status operacional, notícias do banco) |
| `/inventario` + `GET /inventario/dados`, `POST /inventario/{mover,equipar,acessorio}` | portados; validação de grade/colisão/slot igual à do PHP |
| `/mapa[/{regiao}]` + `POST /mapa/opacidade` | portados (camadas, bases, facções, extrações, preferência da conta) |
| `/bestiario[/{grupo}]` + `POST /bestiario/registrar` | portados |
| Erros 404/405/419/500/503 | portados (mesma tela `_404`) |
| `X-EH-Partial` (docs/NAVEGACAO-SPA.md) | portado: layout `partial` devolve o mesmo JSON |
| AccountGuard, LoginGuard, Maintenance | portados (ver diferenças abaixo) |

**Segunda leva (todas as rotas de `app/routes.php` estão portadas)**, um arquivo de rotas por
lote em `src/rotas/`:

| Lote | Rotas |
|---|---|
| `jogador-conta` | `/perfil`, `/saude`, `/habilidades[/{categoria}]` (+ aprender/redefinir), `/conquistas[/{categoria}]` |
| `jogador-progresso` | `/missoes`, `/desafios[/{aba}]` (+ resgatar), `/loja` (+ comprar), `/noticias`, `/noticias/{slug}` |
| `jogador-social` | `/amigos[/{aba}]` (+ POST), `/mensagens[/{conversa}]` (+ responder/lida), `/lobby` (+ chat), `/configuracoes[/{aba}]` |
| `jogador-mundo` | `/jogar`, `/mapas`, `/faccoes` (+ assinar/romper), `/base`, `/safe-zone[/{zona}]` (+ assumir/encerrar) |
| `jogador-suporte-hud` | `/suporte`, `/relatorio-de-bug[/{code}]`, `/recuperar-senha`, **HUD in-game** `/hud`, `/hud/dados`, `POST /hud/municao` (layout `overlay`) |
| `admin-base` | `/admin/login`, `POST /admin/sair`, `/admin` (dashboard); base `controllers/admin/admin-controller.ts`, layouts `admin`/`admin-auth` |
| `admin-conteudo` | `/admin/{missoes,conquistas,mapas,mapas/pontos,mapas/editor,itens,loja,eventos}` (+ salvar/excluir), `POST /admin/upload` (grava no R2 em `media/uploads/<entidade>/`) |
| `admin-operacao` | `/admin/{jogadores,banimentos,relatorios,transacoes,servidores,configuracoes,logs}` (+ salvar/excluir), `/admin/api` (+ criar/revogar) |
| `api` | `/api/v1/*` — chaves com escopo, limite por minuto, log; sem sessão nem cookie (`src/api/`) |

Validação feita: `tsc --noEmit` limpo; varredura com conta admin descartável no D1 local — os 44 GETs
de tela respondem 200, respostas parciais são JSON `hud`, e os 207 assets/mídias referenciados
carregam. Cada lote testou os próprios POSTs (CSRF, validações, mensagens, auditoria).

**Pendências conhecidas**
1. Datas: o que o Worker grava sai em **UTC** (`datetime('now')`); algumas telas convertem para
   Brasília (`core/fuso.ts`, `core/relogio.ts`), outras mostram o texto gravado. Unificar num
   módulo só e decidir uma convenção (sugestão: gravar UTC, mostrar sempre em -03:00).
2. `core/fuso.ts` e `core/relogio.ts` fazem a mesma coisa (dois lotes criaram ao mesmo tempo).
3. Conta nova não recebe amigos/mensagens/avisos de exemplo (igual ao PHP: só os seeds criavam).
4. `/base` sempre mostra "nenhuma base ativa": a tabela `player_bases` não existe (nem no MySQL).
5. Mídia que **já falta no PHP**: ex. `media/items/glock-mag.png` (404 nos dois).
6. Upload do painel recusa SVG (o PHP aceitava, mas nunca servia).

## Mapa PHP → TS

| PHP | TypeScript |
|---|---|
| `index.php` | `src/index.ts` (front controller: R2 → guards → router → erros → sessão) |
| `app/bootstrap.php`, `app/Core/Config.php` | `src/core/config.ts`, `src/core/env.ts`, `src/core/context.ts` |
| `app/routes.php` | `src/routes.ts` |
| `app/Core/Router.php` | `src/core/router.ts` (mesma sintaxe `{param}` / `[/{param}]`, 404/405) |
| `app/Core/Request.php`, `Response.php`, `HttpException.php` | `src/core/request.ts`, `response.ts`, `http-exception.ts` |
| `app/Core/Security.php` (sessão) | `src/core/session.ts` (sessão em D1, tabela `sessions`) |
| `app/Core/Security.php` (CSRF, nonce, cabeçalhos) | `src/core/security.ts` |
| `app/Core/LoginGuard.php`, `AccountGuard.php`, `Maintenance.php` | `src/core/guards.ts` |
| `app/Core/View.php` | `src/core/view.ts` + `src/views/layouts/registry.ts` |
| `app/Core/helpers.php` | `src/core/helpers.ts` (`e`→`html.ts`, `url`, `asset`, `img`, `media`, `icon`, `svg`, `flash`, `mapGlyph`...) |
| `app/Core/Database.php` | `src/core/database.ts` (placeholders `:nome` → `?N`; `transaction()` → `batch()`) |
| `password_hash/verify` | `src/core/password.ts` |
| `app/Core/GameRules.php` | `src/core/game-rules.ts` |
| `app/Core/WorldMap.php` (+ partes de `CatalogRepo`, `MapPointsRepo`) | `src/core/world-map.ts` |
| `app/Controllers/Controller.php` | `src/controllers/controller.ts` |
| `app/Controllers/{Auth,Errors,Home,Inventory,Map,Bestiary,Landing}Controller.php` | `src/controllers/*-controller.ts` |
| `app/Repo/{User,Player,Operational,Inventory,Bestiary}Repo.php` | `src/repo/*-repo.ts` |
| `app/Repo/SettingsRepo.php` | `src/repo/player-settings-repo.ts` |
| `AdminSettingsRepo::valor`, `ServersRepo::rodape` | `src/repo/settings-repo.ts` |
| `CatalogRepo::news`, `equipmentSlots` | `src/repo/catalog-repo.ts`, `inventory-repo.ts` |
| `app/Views/layouts/{hud,auth,partial,landing}.php` | `src/views/layouts/index.ts` |
| `app/Views/partials/{background,topbar,footer}.php` | `src/views/partials/shell.ts` |
| `app/Views/partials/map-stage.php` | `src/views/partials/map-stage.ts` |
| `app/Views/components/{hex,chip,badge,logo,carousel,progress,infestation}.php` | `src/views/components/index.ts` |
| `app/Views/pages/{login,criar-conta,_404}.php` | `src/views/pages/auth.ts` |
| `app/Views/pages/{inicio,inventario,mapa,bestiario,landing}.php` | `src/views/pages/*.ts` |
| `app/Data/*.php` (cromo, `criaturas.php`) | `src/data/*.json` — **gerado** por `scripts/export-data.php` |
| `db/schema.sql` + `db/patch_v3…v11`, `patch_municao` | `migrations/0001…0009_*.sql` |
| conteúdo do MySQL (catálogo) | `seed/conteudo.sql` — **gerado** por `scripts/export-seed.php` |
| `assets/` | `public/assets/` — **gerado** por `scripts/build.mjs` (Static Assets) |
| `media/` | R2 `media/<caminho>` — `scripts/upload-r2.mjs` |

### Como uma view PHP vira TS

`<?= e($x) ?>` vira `${x}` dentro de `` html`...` `` (escapa sempre); HTML pronto é `SafeHtml`
(`raw()`, `icon()`, outro template). `component('badge', $p)` vira `badge(p)`;
`partial('map-stage', $v)` vira `mapStage(s, v)`. Os helpers que no PHP liam `$_SESSION`/
`$_SERVER` leem o contexto da requisição (`AsyncLocalStorage`, `src/core/context.ts`).

## Diferenças de comportamento (de propósito ou por limitação da plataforma)

- **Sessão**: linha na tabela `sessions` do D1 (não há disco). Mesmo cookie `EHSESSID`,
  HttpOnly/SameSite=Lax/Secure em HTTPS, modo estrito, rotação a cada 30 min e no login. A linha
  só é regravada quando muda ou a cada 5 min (para não gastar uma escrita por página);
  limpeza das vencidas com probabilidade 1/100, como o GC do PHP.
- **Transações**: D1 não tem `BEGIN/COMMIT` interativo. Onde o PHP usava `transaction()`, o
  TS lê/valida primeiro e grava tudo num `batch()` (atômico). Cadastro: se o lote do jogador
  falhar, o usuário recém-criado é apagado à mão.
- **Datas**: `datetime('now')` do SQLite é **UTC**; o MySQL gravava no fuso do servidor
  (America/Sao_Paulo). As datas do seed vêm como estavam no MySQL (horário local).
- **Comparação de texto**: só `users.username`/`users.email` são `COLLATE NOCASE` (login sem
  diferença de maiúsculas). NOCASE ignora caixa só em ASCII e não ignora acento.
- **`current_user_id()` sem login** não cai mais no "usuário demo" em localhost: o LoginGuard já
  barra anônimo, e o atalho não tinha função no Worker.
- **Ban vencido**: o AccountGuard chama `BanRepo.sincronizar()` (portado), como no PHP.
- **`asset()`**: o `?v=` é o mtime do arquivo no momento do `npm run build` (mesmo número do
  PHP, então as URLs saem iguais). Nome de arquivo agora é sensível a maiúsculas; o build cria
  apelidos para o que o PHP achava sem diferenciar (ex.: `models/soldier.glb` → `Soldier.glb`).
- **`app/Data/inicio.php`** calcula `ends_at` com `time()`: no JSON fica o valor do momento do
  export (a home usa a temporada do banco quando existe).

## Senhas

O PHP grava `password_hash(..., PASSWORD_DEFAULT)` = bcrypt `$2y$10$`. **Valida no Worker sem
re-hash**: `$2y$` é o mesmo algoritmo de `$2a$`/`$2b$`, e o `bcryptjs` confere trocando só o
prefixo. Testado nos dois sentidos: hash do PHP validado no TS, hash do TS validado pelo
`password_verify` do PHP. Por padrão o Worker também grava bcrypt `$2y$10$` — o banco continua
legível pelos dois apps durante a migração.

Custo: bcrypt em JS puro levou ~57 ms de CPU por verificação (Node local). Cabe no plano
**Workers Paid** (30 s de CPU por requisição); **não cabe no Free** (10 ms).

**Plano de re-hash** (se for ficar no Free, ou para baixar CPU): ligar `PASSWORD_ALGO = "pbkdf2"`
em `wrangler.toml`. Daí em diante:
1. contas novas nascem com `$pbkdf2-sha256$i=100000$...` (WebCrypto nativo, ~14 ms);
2. no login, `UserRepo.verify()` confere o bcrypt antigo e **regrava** o hash em PBKDF2
   (`needsRehash`) — a migração acontece sozinha à medida que as pessoas entram;
3. quem nunca voltar continua com bcrypt (que segue sendo aceito). Atenção: depois do re-hash
   o PHP **não** valida mais essa conta — só ligue quando o Worker for o único app no ar.

Contas reais **não** foram copiadas para cá. Para levar as contas do MySQL ao D1 no dia da
virada, exporte `users` + `players` + `player_*` (o `db/export_migracao.php --com-jogador` já
lista as tabelas) para SQL SQLite e aplique com `wrangler d1 execute --remote --file` — os
hashes entram como estão.

## Rodar local

Pré-requisitos: Node 20+ (testado no 24), PHP do WAMP só para regenerar dados.

```bash
cd typescript
npm install
npm run build                      # assets -> public/, manifestos em src/generated/
npm run db:migrate:local           # cria o D1 local (.wrangler/state)
npm run db:seed:local              # conteúdo do catálogo (sem contas)
npm run r2:upload:local            # media/ + arquivos grandes de assets/ para o R2 local
npm run dev                        # http://127.0.0.1:8787
```

Crie uma conta em `/criar-conta` (fica só no D1 local).

Regenerar a partir do app PHP (que é a fonte enquanto estiver no ar):

```bash
C:/wamp64/bin/php/php8.3.28/php.exe typescript/scripts/export-data.php   # app/Data/*.php -> src/data/*.json
C:/wamp64/bin/php/php8.3.28/php.exe typescript/scripts/export-seed.php   # MySQL (só leitura) -> seed/conteudo.sql
```

`export-seed.php` só faz SELECT, nunca exporta `users`/`players`/`player_*`/mensagens/bans/
logs/chaves, e aborta se encontrar e-mail, hash ou token na saída.

`npm run typecheck` roda o `tsc --noEmit`.

## Publicar

**Publicado em 2026-10-27** em https://extracthunters.perfectdesigner.workers.dev (conta
perfectdesigner@hotmail.com.br): D1 `extracthunters` (`ccbcdb65-…`, região ENAM — o D1 não tem
região na América do Sul), bucket R2 `extracthunters-media` (113 arquivos), migrations 0001–0009
e o seed de conteúdo aplicados, **nenhuma conta** no banco. Domínio próprio ainda não ligado.
Tempo de resposta medido do Brasil: 0,5–2 s nas telas logadas (várias consultas em sequência ao
D1 nos EUA); dá para baixar paralelizando consultas ou com réplicas de leitura do D1.

Para publicar de novo / do zero:

```bash
npx wrangler login
npx wrangler d1 create extracthunters           # copie o database_id para wrangler.toml
npx wrangler r2 bucket create extracthunters-media
npm run db:migrate:remote
npm run db:seed:remote
npm run r2:upload:remote                         # pede confirmação antes de gravar (--sim pula)
npm run deploy
```

Depois: apontar a rota/domínio no painel (ou `routes = [...]` no `wrangler.toml`), e, se for
ficar no plano Free, ligar `PASSWORD_ALGO = "pbkdf2"` (ver "Senhas").

## Arquivos gerados (fora do git)

`public/` (cópia de `../assets`, ~63 MB, sem `img/_gen`, `assets/assets`, `_dev` e rascunhos),
`src/generated/` (manifesto de versões, mídia e índice dos JSON) e `.wrangler/` (D1/R2 locais).
Arquivos acima de 24 MiB (hoje só `models/Soldier.glb`, 79 MB) não cabem em Static Assets:
vão para o R2 com a chave `assets/<caminho>` e o Worker os serve de lá.
