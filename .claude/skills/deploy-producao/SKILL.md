---
name: deploy-producao
description: Publica o Extract Hunters em https://extracthunters.com.br por FTP e confere se subiu de pé. Use quando o usuário disser "subir para produção", "publicar", "deploy", "manda pro site", "põe no ar" ou pedir para verificar/corrigir o site em produção.
---

# Deploy do Extract Hunters para produção

Site em produção: **https://extracthunters.com.br** (nginx na frente, Apache atrás, PHP + MySQL).
Local: `C:\wamp64\www\extracthunters`, servido em `/extracthunters/`.

Credenciais em `.env` na raiz do projeto (`FTP_IP`, `FTP_USER`, `FTP_PASS`).
**Nunca imprima nem repita essas credenciais em resposta, log ou commit.**

## Como publicar

Sempre nesta ordem. O passo 1 não é opcional.

```bash
# 1. VEJA o que subiria antes de subir
node tools/deploy.mjs --dry-run
```

Leia a lista. Se aparecer algo que não devia ir para produção (`.env`, backups de banco,
capturas, dumps), **pare e acerte o filtro `NEGAR` em `tools/deploy.mjs`** antes de continuar.

```bash
# 2. Publique (envia só o que mudou: compara tamanho com o remoto)
node tools/deploy.mjs

# 3. Confira em produção
node tools/verify_prod.mjs
```

Opções: `--only <caminho>` restringe a um subcaminho, `--force` reenvia tudo,
`--ls <dir>` lista um diretório remoto, `--clean` remove do servidor o que não é de
produção (`.env`, `tools/`, `db/`, `captures/`, `docs/`, `SPEC.md`) — combine com
`--dry-run` primeiro e confirme com o usuário, porque apaga.

## Confirme com o usuário antes de enviar

Publicar é ação externa e difícil de desfazer. Rode o `--dry-run`, mostre o resumo
(quantos arquivos, quantos MB, o que muda) e **espere o usuário confirmar** antes do envio
de verdade — a menos que ele já tenha dito explicitamente para publicar sem perguntar
naquela mensagem.

## O que nunca sobe

`tools/deploy.mjs` mantém a lista em `NEGAR`. Hoje ficam de fora:
`.env`, `.git/`, `tools/`, `captures/`, `db/backups/`, `O-QUE-VAMOS-CRIAR/`,
`node_modules/`, `.claude/` e `app/db.local.php`.

`app/db.local.php` é **por ambiente**: a produção tem o dela, com o usuário e a senha do
MySQL de lá (`MYSQL_*` no `.env`). Subir a local apontaria a produção para `root@127.0.0.1`.

## Banco de dados

O deploy só mexe em arquivos. Mudança de esquema **não** vai junto e nunca deve ir por
`db/migrate.php` ou `db/seed.php` — os dois apagam contas reais (ver `db/guard.php`).
Para alterar o esquema em produção, escreva um `ALTER TABLE` avulso ou um patch aditivo no
padrão de `db/patch_municao.php`, e rode pelo phpMyAdmin do host.

## Armadilhas já pagas

- **`RewriteBase` fixo quebra tudo.** O `.htaccess` roda na raiz do domínio em produção e
  em `/extracthunters/` no WAMP. Ele **não tem `RewriteBase`** de propósito: o Apache
  resolve os alvos relativos à pasta do arquivo. Se alguém puser `RewriteBase
  /extracthunters/` de volta, toda rota amigável em produção devolve 404 enquanto
  `/index.php/rota` continua 200 — esse é o sintoma exato desse erro.
- **A raiz remota mudou com a troca de host (set/2026).** Hoje é aaPanel + OpenLiteSpeed:
  a conta FTP cai no docroot do site (`/www/wwwroot/extracthunters.com.br`) e a aplicação
  vive em `unreal/` — que é o `REMOTO_RAIZ` padrão do `deploy.mjs`. A raiz do FTP tem só
  `unreal` e `.user.ini`. No host anterior (cPanel) a conta caía no HOME e o docroot era
  `public_html`.
- **`--ls ""` lista o REMOTO_RAIZ, NÃO a raiz do FTP.** Já me enganei com isso: vi
  `index.php`, `app/` e `assets/` na saída, concluí que a conta caía dentro da aplicação e
  troquei `REMOTO_RAIZ` para vazio. Se tivesse publicado, ~500 arquivos iriam para a raiz
  do domínio. Para ver a raiz de verdade: `--remote-root "" --ls ""`. O que denuncia o erro
  é o cabeçalho da própria saída (`docroot: unreal`) e o resumo do dry-run: quando a raiz
  está certa ele diz *"473 já idêntico(s)"*; quando está errada, **"0 já idêntico(s)"**,
  porque não encontra nada para comparar. Zero idênticos num host já publicado é bandeira
  vermelha, não coincidência.
- **O OpenLiteSpeed lê o `.htaccess`, mas só em parte.** `autoLoadHtaccess 1` está ligado e
  `mod_rewrite` funciona; `<FilesMatch>`, `Header`, `Options` e `AddType` **são ignorados**.
  Medido: `composer.json` dá 403 (regra de rewrite), enquanto os arquivos estáticos chegam
  sem `X-Content-Type-Options` (diretiva `Header` ignorada). Por isso todo bloqueio real no
  `.htaccess` é feito por `RewriteRule` — se for acrescentar proteção, acrescente ali, não
  em `<FilesMatch>`.
- **PHP novo em host novo vem velho.** O painel criou o site em PHP 7.4 e o site inteiro
  respondia **500 com corpo vazio e nenhum cabeçalho nosso** — nem cookie de sessão. Não é
  banco: é erro de compilação (`$e::class` no `index.php` exige 8.0; `readonly` em
  `Request.php` exige 8.1). O discriminador é justamente a ausência dos nossos cabeçalhos:
  se `Set-Cookie`/`Content-Security-Policy` aparecem no 500, o PHP rodou e o problema é
  outro. Mínimo real: **PHP 8.1**; use 8.3 para bater com o local.
- **O host derruba o socket de controle em remoções longas.** `--clean` remove de baixo
  para cima, um diretório por vez, e reconecta e retoma quando cai (apagar é
  idempotente). Uma pasta com `node_modules` leva minutos e várias reconexões — é
  normal, deixe terminar. Rode em background.
- **Credenciais de MySQL já estiveram fixas em `app/Core/Database.php` no servidor.**
  Publicar aquele arquivo derrubava a conexão da produção (voltava para
  `root@127.0.0.1`). Resolvido: produção tem `app/db.local.php` (fora do deploy) com
  os dados de `MYSQL_*`, e `Database.php` só traz os padrões. **Nunca** volte a
  escrever senha em arquivo versionado.

## Diagnóstico rápido de produção

`node tools/verify_prod.mjs` checa, sem enviar nada:
rotas amigáveis (o teste que pega o erro de `RewriteBase`), rotas por `index.php`,
arquivos sensíveis bloqueados, assets servindo, cabeçalhos de segurança e sinais de que
o banco está conectado.
