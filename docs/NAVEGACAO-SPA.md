# Navegação sem recarga

Trocar de tela não recarrega o documento. Dentro do jogo (CEF) uma navegação normal derruba
fontes, imagem de fundo e o canvas WebGL do atmosphere e reconstrói tudo — é esse o engasgo
de alguns quadros que o jogador sente. Aqui o casco fica de pé e só o miolo troca.

O *porquê* de cada decisão está comentado em `assets/js/spa.js`. Este documento é o contrato.

## O contrato

O cliente pede a mesma URL com o cabeçalho `X-EH-Partial: 1`. `Controller::render()` roda o
mesmo controller e a mesma view, só trocando o layout para `layouts/partial.php`, que devolve:

```json
{
  "ok": true,
  "layout": "hud",
  "slug": "inventario",
  "title": "Inventário · Extract Hunters",
  "bodyClass": "page-inventario",
  "csrf": "…",
  "background": "<div class=\"bg\">…</div>",
  "topbar": "<header class=\"topbar\">…</header>",
  "main": "…",
  "footer": "<footer class=\"footer\">…</footer>",
  "css":    ["/assets/css/pages/inventario.css?v=…"],
  "js":     ["/assets/js/pages/inventario.js?v=…"],
  "jsOnce": ["/assets/js/three/atmosphere.js?v=…"]
}
```

`layout` é o campo que evita estrago: só `hud` tem versão parcial. `/login`, `/criar-conta`,
`/recuperar-senha` e o 404 usam o casco `auth`, e a HUD in-game usa `overlay` — nesses o
servidor devolve HTML normal e o cliente reconhece pelo `Content-Type` e navega do jeito
tradicional. Qualquer falha (rede, JSON inválido, casco diferente) cai para `location.href`:
o jogador vê o engasgo, mas nunca fica preso.

`Vary: X-EH-Partial` acompanha a resposta parcial para nenhum cache servir JSON a quem pediu
a página.

## Ao criar uma tela nova

Nada. `View::pageAssets()` deriva `css/pages/<slug>.css` e `js/pages/<slug>.js` do slug, e o
`Controller::page()` já monta o resto. Só valem duas regras:

- **Módulo de página não pode assumir que roda uma vez.** Ele é reavaliado a cada visita
  (`?_e=<época>`), então o topo do arquivo tem de ser seguro para repetir.
- **Segurando um recurso que o navegador não recolhe sozinho** (contexto WebGL, WebSocket,
  assinatura em `unreal.js`), registre a soltura:

  ```js
  import { aoSair } from '../spa.js';
  aoSair(() => renderer.dispose());
  ```

  Timers, `requestAnimationFrame` e ouvintes de evento em `window`/`document` **não** precisam disso —
  a varredura de época cuida deles. Ver `assets/js/three/map3d.js` e
  `assets/js/pages/jogar.js` como exemplo.

## Onde as coisas ficam

| Arquivo | Papel |
|---|---|
| `assets/js/spa.js` | roteador, ciclo de vida por época, preloader |
| `assets/css/spa.css` | preloader e o estado `body.is-navigating` |
| `app/Views/layouts/partial.php` | a resposta JSON |
| `app/Core/View.php` → `pageAssets()` | o que é casco e o que é da tela |
| `app/Core/Request.php` → `wantsPartial()` | detecta o cabeçalho |
| `assets/js/hud.js` | `initGlobal()` roda uma vez, `initPage()` a cada tela |
| `tools/test_spa.mjs` | a suíte (19 casos) |

## Armadilha conhecida

`page.waitForFunction()` do Puppeteer sonda com `requestAnimationFrame` **dentro da página**,
e a varredura de época cancela os rAF da tela que sai — inclusive o dele. Não é bug: é o
sweep trabalhando. Em teste, espere sondando pelo lado do Node.

## Performance

**Prefetch ao passar o mouse.** O servidor de produção responde em ~450 ms (rede + PHP +
MySQL) e esse é o custo dominante — trocar o DOM leva alguns milissegundos. Entre o
ponteiro tocar o link e o clique acontecer passam 200-400 ms de graça, e é essa janela que
o `spa.js` usa:

| | |
|---|---|
| `mouseover` | espera 65 ms parado e busca a resposta parcial (não dispara ao varrer o menu) |
| `pointerdown` | busca na hora — o clique só chega ~100 ms depois, e a intenção já é certa |

Medido com 400 ms de latência artificial (`tools/test_spa_prefetch.mjs`):
**451 ms sem prefetch → 157 ms com prefetch.**

Junto com a resposta vêm o CSS (`preload as=style`, que baixa sem aplicar — `stylesheet`
repintaria a tela atual) e o módulo da tela (`modulepreload` na época seguinte, que é
previsível: `epoca + 1`, então a URL bate com a do `import()`).

O que foi guardado vale 30 s e é usado UMA vez, porque a resposta parcial carrega estado do
jogador (créditos, XP, nível na topbar). Qualquer POST limpa tudo, já que ele muda esses
números. O token CSRF não atrapalha: é por sessão, não por requisição.

**Peso publicado.** `assets/img/_gen/` (81,6 MB de intermediários do pipeline) e
`assets/assets/` (36,6 MB de matéria-prima) não são referenciados por nada e saíram do
deploy: **170,8 MB → 52,6 MB**. Estavam abertos por HTTP — `rope.png` sozinha eram 2 MB
baixáveis em produção.

**Pendência no servidor.** O `expiresByType` do vhost OpenLiteSpeed lista
`application/javascript` e `application/x-javascript`, mas o servidor entrega nosso JS como
`text/javascript` — que não está na lista. Resultado: **nenhum arquivo .js recebe
`Cache-Control`** e é rebaixado a cada carregamento. CSS, fontes e imagens recebem
`max-age=43200`. Corrigir exige acrescentar `text/javascript=A43200` na configuração do
site pelo painel; não dá para fazer pelo `.htaccess`, porque o OLS ignora `Header`.
