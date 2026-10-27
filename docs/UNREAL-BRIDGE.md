# Ponte Extract Hunters ⇄ Unreal Engine 5

O site roda como HUD dentro do jogo pelo plugin **WebNativeBrowser** (CEF embarcado).
O plugin injeta `window.WebNative` na página; fora do jogo esse objeto não existe e
**tudo continua funcionando como site normal**.

## Contrato de mensagens

Nome de função + corpo (string). É o que `WebNative.send()` manda e o que a Blueprint
recebe em `On Message Received (FunctionName, MessageBody)`.

| Direção | Função | Corpo | Efeito |
|---|---|---|---|
| Site → UE | `Game.LoadMap` | caminho do level (`/Game/FPS_Controller/Maps/MainMenu`) | `Open Level` |

Nomes usam o prefixo do domínio (`Game.`, `Player.`, `Inventory.`…) para o `switch` da
Blueprint continuar legível quando a lista crescer.

## Lado do site

- `assets/js/unreal.js` — módulo da ponte:
  - `disponivel()` — a ponte existe agora?
  - `pronta(timeout=3000)` — espera a injeção (o plugin leva alguns ms depois do load);
    resolve `true`/`false`, **nunca rejeita**.
  - `enviar(funcao, corpo)` — devolve `false` se não há ponte; **quem chama decide o
    fallback**, nada é engolido em silêncio.
  - `receber(funcao, cb)` — assina mensagens vindas da UE (`SendMessageToJS`).
  - Marca `<html class="is-unreal" data-unreal="1">` quando dentro do jogo, para o CSS
    poder esconder o que não faz sentido lá (ex.: "SAIR — voltar para a área de trabalho").

- **Qual level cada mapa carrega vem do BANCO**, não do JS: coluna `maps.unreal_level`.
  O controller publica em `ue_level`, a view imprime em `data-ue-level` no `.map-card` e
  `assets/js/pages/jogar.js` lê do card selecionado. Quando os levels reais existirem é
  uma linha de seed, não mudança de código.
  Hoje **todos** os mapas de partida apontam para `/Game/FPS_Controller/Maps/MainMenu`,
  que é o level de teste — nenhum level de partida existe ainda no projeto Unreal.

## Lado da Unreal

`/Game/UI/WBP_HuntersWeb` (Widget Blueprint) — criado para este projeto. O
`BP_WebNative` do plugin **não é usado**: é conteúdo de demo e seria sobrescrito num
update do plugin.

```
Event Construct
  └─ LoadURL (Web)  "http://localhost/extracthunters/"
     └─ Set Input Mode Game And UI (focus = self, DoNotLock, não esconde o cursor)
        └─ Set bShowMouseCursor = true

On Message Received (Web) ── FunctionName ──> Equal Exactly (String) == "Game.LoadMap"
                          └─ MessageBody ───> String To Name ──┐
                                                               v
                                          Branch(True) ──> Open Level (by Name)
```

Quem cria o widget: `BP_PlayerCharacter :: EventGraph`, no `Create Widget` +
`Add to Viewport` disparado pela ação de input `IA_ShowInputs` (FlipFlop mostra/esconde).

## O navegador de dentro do jogo é Chromium 90

O CEF que a Unreal 5.6 embarca é **libcef 90.6.7 / Chromium 90.0.4430** (abril de 2021).
Confira com:

```
(Get-Item 'C:\Program Files\Epic Games\UE_5.6\Engine\Binaries\ThirdParty\CEF3\Win64\libcef.dll').VersionInfo.FileVersion
```

Isso faz do Chromium 90 a **linha de base real da HUD**, não do navegador em que a tela foi
desenhada. Quando uma folha de estilo usa algo mais novo, o Chromium 90 descarta a declaração
inteira **em silêncio**: nada quebra no navegador de desenvolvimento e, dentro do jogo, a regra
simplesmente não existe.

Foi assim que `height:100dvh` (Chrome 108) tirou a altura da HUD lá dentro: a página passou a
medir o conteúdo, ficou ~320 px mais alta que a janela, e o rodapé e o pé do mapa saíram
cortados — sem erro nenhum no console. O mesmo valia para `dialog:modal` (`:modal` é Chrome
105), que teria devolvido todo modal para o canto superior esquerdo.

**A regra:** recurso novo pode entrar, desde que o equivalente antigo venha ANTES dele na mesma
declaração. Quem é velho fica com o primeiro, quem é novo sobrescreve com o segundo.

```css
height:100vh;        /* CEF 90 entende */
height:100dvh;       /* navegador atual sobrescreve */
```

**Como conferir:** `php tools/varrer_cef90.php` varre o CSS e o JS que o jogo carrega e
distingue o que QUEBRA layout ou execução do que é só cosmético (a barra de rolagem sem
`scrollbar-width`, o controle sem `accent-color`). Sai com código 2 quando encontra uma quebra.
A varredura ignora `/admin`, que abre no navegador do operador e não no CEF.

Alguns itens que a linha de base proíbe sem fallback: `dvh/svh/lvh`, `:has()`, `:modal`,
`@container`, `@layer`, `subgrid`, aninhamento de CSS, `color-mix()`, as propriedades soltas
`translate`/`rotate`/`scale`, e em JavaScript `.at()`, `Object.hasOwn`, `structuredClone`,
`findLast`, `crypto.randomUUID` e `AbortSignal.timeout`.

### Arrastar o mapa

O `SWebBrowser` da UE 5.6 captura o mouse no botão esquerdo e repassa os movimentos ao CEF
(`FWebBrowserViewport::OnMouseButtonDown` devolve `Reply.CaptureMouse`), então arrastar chega
até a página. Mesmo assim `assets/js/three/map3d.js` escuta **pointer events e mouse events**,
com trava contra o gesto dobrado: depender de um transporte só é apostar que o embutido
implementa exatamente aquele, e quando a aposta erra o mapa não arrasta e nada aparece no
console.

Lembre que no zoom 1 **não há o que arrastar**: a ilha inteira cabe no palco e o enquadramento
fica preso no centro de propósito. O arrasto passa a valer depois do primeiro `+`.

---
## Diagnóstico

A ponte não aparece → o console do CEF mostra se `window.WebNative` foi injetado.
`assets/js/unreal.js` loga `[unreal] ponte WebNative pronta` quando conecta.
Abra o DevTools do CEF pela Blueprint (`ShowDevTools` no widget do browser).

**Atenção à CSP:** o site manda `script-src 'self' 'nonce-…'` (ver `app/Core/Security.php`).
Se o plugin injetar a ponte como `<script>` inline, a CSP bloqueia e `WebNative` nunca
aparece. Injeção por V8 nativo (o normal em CEF) não é afetada. Se a ponte não conectar
dentro do jogo mas a página carregar, esta é a primeira suspeita.

---

## Configurações gráficas no menu

A tela `configuracoes/video` é aberta no menu da Unreal, antes de existir um
personagem em operação. Ao pressionar **APLICAR ALTERAÇÕES**, o site faz duas
coisas na mesma confirmação:

1. grava a preferência na conta em `player_settings`;
2. envia a escolha para o `GameInstance` pelo HuntersWeb.

Assim a configuração já está aplicada quando `Game.LoadMap` abre o level da
operação. Esta fase não depende do HUD durante a partida nem de uma tecla de
atalho.

### Contrato Web → Unreal

| Direção | Função | Corpo | Efeito |
|---|---|---|---|
| Site → UE | `Video.ApplySettings` | JSON canônico `version: 1` | aplica e persiste as opções gráficas |

Exemplo de corpo enviado pela página (sempre JSON, não texto montado na
Blueprint):

```json
{
  "version": 1,
  "display": {
    "mode": "fullscreen",
    "resolution": { "width": 1920, "height": 1080 },
    "refreshHz": 144,
    "vsync": false,
    "fpsLimit": 144
  },
  "quality": {
    "preset": 2,
    "textures": 2,
    "shadows": 1,
    "foliage": 2,
    "antiAliasing": "taa",
    "renderScale": 100
  },
  "effects": {
    "motionBlur": false,
    "bloom": true,
    "filmGrain": 20,
    "fov": 90,
    "brightness": 50
  }
}
```

`fpsLimit: null` significa ilimitado. Os níveis de qualidade já chegam como
números de 0 a 3 (`BAIXA` a `ULTRA`), e `display.mode` chega como `windowed`,
`borderless` ou `fullscreen`. O payload inclui campos `*Raw` apenas como apoio
para diagnóstico; a Unreal deve aplicar os campos canônicos.

### Implementação no projeto Unreal

Os ativos persistentes são:

1. **`BP_HuntersVideoSaveGame`** (`SaveGame`): guarda tanto a struct
   `S_HuntersVideoSettings` quanto o JSON canônico no slot
   `HuntersVideoSettings`.
2. **`Hunters_Instanc`** (`GameInstance` configurado no projeto): em `Init`,
   carrega o slot e mantém os valores em memória. A função
   `SaveVideoSettingsJson` atualiza e salva a cópia recebida do navegador.
3. **`WBP_HuntersWeb`**: no evento `On Message Received`, o caso
   `Video.ApplySettings` encaminha o corpo para
   `Hunters_Instanc::SaveVideoSettingsJson`.
4. **`UHuntersWebBrowser`**: interpreta e valida o JSON antes de aplicar a
   parte nativa com `UGameUserSettings`.

O navegador não decide valores fora da faixa: resolução mínima de 640×480,
escala entre 50 e 100, FOV entre 70 e 120 e brilho/granulação entre 0 e 100.
O handler aplica resolução, modo de tela, V-Sync, limite de FPS, escalabilidade,
texturas, sombras, vegetação, anti-aliasing, escala de renderização, motion
blur, bloom, FOV e brilho. Em seguida chama `ApplySettings(false)` e
`SaveSettings`.

`GameUserSettings` mantém as configurações nativas entre inicializações. O
`SaveGame` mantém o contrato JSON completo para as opções de câmera e
pós-processo seguirem disponíveis entre o menu e a partida.

## HUD in-game (`/hud`)

Overlay transparente carregado por cima do jogo. Mostra as armas equipadas
(**bandoleira** e **coldre**) com a munição do carregador instalado.

### Mensagens

| Direção | Função | Corpo | Efeito |
|---|---|---|---|
| UE → site | `Hud.Ammo` | `{"slot":"primary","current":29}` | pinta o número e grava no inventário |

`slot` é a chave do inventário (`primary` \| `pistol`), não o slot da Unreal.
Mapeamento atual: UE `PrimaryWeapon` → `primary`, UE `SecondaryWeapon` → `pistol`
(o template FPS só tem dois slots de arma).

### Lado do site

- Rota `/hud` (layout `overlay`, sem topbar/rodapé, `pointer-events:none`, fundo transparente).
- `POST /hud/municao {slot, current}` grava em `player_items.ammo_cur` do carregador
  anexado à arma daquele slot. **O servidor trunca em [0, capacidade]** — o jogo diz
  quantos tiros saíram, não qual o tamanho do carregador.
- `assets/js/pages/hud.js` pinta na hora e persiste com debounce de 400 ms: numa rajada
  chegam ~10 mensagens por segundo e só o último valor precisa ir para o banco.
- A munição de uma arma vive no CARREGADOR anexado (`container='attach'`), nunca na arma.
  Sem carregador o HUD mostra "SEM CARREGADOR" e não há o que subtrair.

### Lado da Unreal — `WBP_HuntersWeb`

| Função | O que faz |
|---|---|
| `ShowMenuHUD()` | carrega o site, input Game+UI, cursor visível, para o timer de munição |
| `ShowGameHUD()` | carrega `/hud`, input Game Only, cursor escondido, liga o timer |
| `PollAmmo()` | lê `PrimaryWeapon`/`SecondaryWeapon` e chama `PushAmmo` **só quando o valor muda** |
| `PushAmmo(Slot, Current)` | monta o JSON e chama `SendMessageToJS("Hud.Ammo", …)` |

`Event Construct` chama `ShowMenuHUD()`. Para entrar no jogo, chame `ShowGameHUD()`.

**Por que timer e não dispatcher:** o certo seria assinar `BP_BaseWeapon::AmmoChanged`,
que já existe e dispara a cada tiro. O bridge do HuntersMCP não cria nós de *bind* de
dispatcher (`add_node` não tem `bind_event`), então `PollAmmo` roda a 20 Hz e compara com
`LastPrimaryAmmo`/`LastPistolAmmo` — só envia quando muda, então não há tráfego parado.
Como efeito colateral bom, isso sobrevive a troca de arma sem precisar re-assinar nada.
Se quiser trocar por evento, é ligar `AmmoChanged` → `PushAmmo` na mão no editor.

### Configuração

`Config/DefaultGame.ini` ganhou `[WebNative] cef_args=show-background-color
background-color=0x00000000` — sem isso o CEF desenha fundo opaco e o overlay tapa o jogo.
