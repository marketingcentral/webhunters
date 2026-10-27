# Como portar uma tela PHP para o Worker

Guia curto para continuar o port (app PHP em `../app` → `src/`). Leia antes o `README.md`.

## Onde cada coisa vai

| PHP | TS |
|---|---|
| `app/Controllers/XController.php` | `src/controllers/x-controller.ts` (classe que estende `Controller`) |
| `app/Controllers/Admin/XController.php` | `src/controllers/admin/x-controller.ts` (estende o `AdminController` TS) |
| `app/Repo/XRepo.php` | `src/repo/x-repo.ts` (funções exportadas) |
| `app/Views/pages/x.php` | `src/views/pages/x.ts` — `export function x(s: Scope): SafeHtml` |
| `app/Views/components/x.php` | `src/views/components/x.ts` (um arquivo por componente novo) |
| linha de `app/routes.php` | `src/rotas/<lote>.ts` (o seu lote) |
| `app/Data/x.php` | já exportado em `src/data/x.json` → `dataMock('x')` |

Modelos completos para copiar o estilo: `controllers/map-controller.ts` + `views/pages/mapa.ts`,
`controllers/bestiary-controller.ts` + `views/pages/bestiario.ts`, `controllers/inventory-controller.ts`.

## Regras de tradução

- Template: `<?= e($x) ?>` → `${x}` dentro de `` html`...` `` (escapa sempre). HTML pronto é
  `SafeHtml`: `raw()`, `icon()`, `svg()`, `mapGlyph()`, outro template. `foreach` →
  `join(lista.map(...))`; `if` → ternário com `''`. Mantenha classes, atributos e ordem do HTML
  iguais ao PHP: o CSS/JS em `assets/` é o mesmo e depende disso.
- Helpers (`src/core/helpers.ts`): `url asset img media icon svg num attrClass dataMock flash
  flashAll active currentUserId isAuthenticated mapGlyph mapPointIcon creatureImg csrfField`.
  Componentes prontos em `src/views/components/index.ts`: `hex chip badge logo carousel progress
  infestation`. Parciais: `views/partials/shell.ts`, `views/partials/map-stage.ts`.
- Banco (`src/core/database.ts`): `one all value run insert insertStmt prepare batch changes agora`,
  placeholders `:nome` como no PHP. SQL MySQL → SQLite: `NOW()` → `datetime('now')`,
  `CURDATE()` → `date('now')`, `ON DUPLICATE KEY UPDATE` → `ON CONFLICT(pk) DO UPDATE SET x = excluded.x`,
  crases → aspas duplas, `"texto"` como string → `'texto'`, `IF()` → `CASE`/`iif()`,
  `GREATEST/LEAST` → `max()/min()` de 2+ args, `DATE_SUB(NOW(), INTERVAL n DAY)` → `datetime('now','-n days')`,
  `TIMESTAMPDIFF` → `(julianday(a)-julianday(b))*86400`, `CONCAT` → `||`, `FIELD()` → `CASE`.
  `Database::transaction()` → leia/valide antes e grave tudo num `batch([...])`.
  `rowCount()` → `changes(r)`. `lastInsertId()` → `insert()` devolve o id.
- Tipos do PHP: `(int)x` → `Number(x) | 0` (ou `Math.trunc`), `(bool)` de coluna → `Boolean(Number(x))`,
  `json_decode` → `JSON.parse` com try/catch, `mb_strtoupper` → `toUpperCase()`,
  `number_format` → `num()`, `date('d/m/Y', strtotime(x))` → formate a partir da string.
- Contexto da requisição (sessão, request): `ctx()` de `src/core/context.ts`;
  `$_SESSION['k']` → `ctx().session.get('k')/set/delete`. `Request::wantsPartial()` → `req.wantsPartial()`.
- Comentários em português. Sem `any` solto: use `Dict` de `core/view.ts`.

## Regras de convivência (vários lotes ao mesmo tempo)

- Crie arquivos NOVOS. Em arquivo que já existe e não é seu, só `Edit` pequeno e aditivo
  (nunca `Write` por cima). Não renomeie nem mude assinatura do que já existe.
- Rotas só no seu `src/rotas/<lote>.ts`.
- `npx tsc --noEmit` tem de passar nos SEUS arquivos. Erro em arquivo de outro lote: ignore e reporte.
- Há um `wrangler dev` em http://127.0.0.1:8787 que recarrega sozinho. Não pare nem reinicie.
  Se a compilação estiver quebrada por arquivo de outro lote, espere e tente de novo.
- Testes: crie contas descartáveis com prefixo do seu lote em `/criar-conta` (D1 LOCAL) e apague
  no fim (`npx wrangler d1 execute DB --local --command "DELETE FROM users WHERE username='...'"`).
  Nunca use `--remote`, nunca rode `wrangler deploy`, nunca escreva no MySQL, nunca rode
  `db/migrate.php`/`db/seed.php`, nunca copie `.env`/`app/db.local.php`.
- Comparar com o PHP: http://localhost/extracthunters/ responde o app original (só páginas públicas
  sem login). Para telas logadas, confira o HTML gerado pelo Worker contra a view PHP linha a linha.
