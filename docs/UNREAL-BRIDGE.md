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

## Diagnóstico

A ponte não aparece → o console do CEF mostra se `window.WebNative` foi injetado.
`assets/js/unreal.js` loga `[unreal] ponte WebNative pronta` quando conecta.
Abra o DevTools do CEF pela Blueprint (`ShowDevTools` no widget do browser).

**Atenção à CSP:** o site manda `script-src 'self' 'nonce-…'` (ver `app/Core/Security.php`).
Se o plugin injetar a ponte como `<script>` inline, a CSP bloqueia e `WebNative` nunca
aparece. Injeção por V8 nativo (o normal em CEF) não é afetada. Se a ponte não conectar
dentro do jogo mas a página carregar, esta é a primeira suspeita.

---

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
