/**
 * Ponte com a Unreal Engine.
 *
 * Funciona com dois motores, sem o resto do site saber qual está em uso:
 *
 *   1. WebNativeBrowser (plugin comercial) — injeta `window.WebNative` por extensão
 *      nativa do V8, que a CSP não alcança. Nada a fazer aqui.
 *   2. HuntersWeb (plugin nosso) — expõe o objeto ligado em `window.ue.huntersweb` e
 *      TENTA injetar o mesmo shim via ExecuteJavascript. Só que essa injeção roda no
 *      mundo principal do frame e **a CSP do site pode barrar** (`script-src 'self'
 *      'nonce-…'`). Por isso montamos o shim AQUI: este arquivo carrega com nonce, é
 *      código nosso, e a CSP não tem nada contra ele.
 *
 * Em ambos os casos o resto do site vê a mesma API: send / on / off / emit.
 *
 * Fora do jogo nada disso existe. Todo chamador trata os dois casos: `enviar()` devolve
 * false e quem chamou decide o fallback — nunca engolimos a ação em silêncio.
 */

const PONTE_TIMEOUT = 3000;   // ms de espera máxima pela injeção do plugin
const raiz = document.documentElement;

/** Nomes sob os quais o HuntersWeb pode publicar o objeto ligado. */
const NOMES_PONTE = ['huntersweb', 'HuntersWeb', 'HUNTERSWEB'];
/** Grafias possíveis do método: o UE não garante a caixa do nome da UFUNCTION. */
const NOMES_SEND = ['send', 'Send', 'SEND'];

/**
 * Marcador do transporte por console do HuntersWeb.
 *
 * `SWebBrowser::BindUObject` monta uma RPC por reflexão (mapa de GUID + serialização de
 * struct) que **entrega no PIE e falha calada no build Shipping empacotado** — medido em
 * campo: a página chamava o método sem erro e a Blueprint nunca era acionada.
 * `OnConsoleMessage` é um delegate simples, sem reflexão, que atravessa igual em toda
 * configuração de build. Daí o envio sair por `console.log` com este prefixo.
 */
const PREFIXO_CONSOLE = '[[HUNTERSWEB]]';

/** Acha o objeto que o HuntersWeb publicou em window.ue.<nome>, se houver. */
function objetoLigado() {
  const ue = window.ue;
  if (!ue) return null;
  for (const n of NOMES_PONTE) {
    const o = ue[n];
    if (o) return o;
  }
  return null;
}

function metodoSend(obj) {
  if (!obj) return null;
  for (const n of NOMES_SEND) {
    if (typeof obj[n] === 'function') return obj[n].bind(obj);
  }
  return null;
}

/**
 * Monta `window.WebNative` por cima do objeto ligado, se ele existir e a ponte ainda
 * não estiver montada. Idempotente: se o plugin já injetou a dele, não mexe.
 * @returns {boolean} a ponte existe depois desta chamada?
 */
function montarPonte() {
  if (window.WebNative && typeof window.WebNative.send === 'function') return true;

  // O objeto ligado só decide SE estamos dentro do jogo — não é por ele que se envia.
  const enviarNativo = metodoSend(objetoLigado());
  if (!enviarNativo) return false;

  const ouvintes = Object.create(null);

  window.WebNative = {
    __hunters: true,

    send(funcao, corpo) {
      const texto = typeof corpo === 'string' ? corpo
        : corpo === undefined ? '' : JSON.stringify(corpo);
      try {
        // transporte principal: console (ver PREFIXO_CONSOLE)
        console.log(PREFIXO_CONSOLE + JSON.stringify({ fn: String(funcao), body: texto }));
        return true;
      } catch (err) {
        console.error('[unreal] falha ao enviar', funcao, err);
        return false;
      }
    },

    on(funcao, cb) {
      if (typeof cb !== 'function') return;
      (ouvintes[funcao] || (ouvintes[funcao] = [])).push(cb);
    },

    off(funcao, cb) {
      const lista = ouvintes[funcao];
      if (!lista) return;
      const i = lista.indexOf(cb);
      if (i >= 0) lista.splice(i, 1);
    },

    /** Chamado pelo lado UE. Um ouvinte que estoura não derruba os outros. */
    emit(funcao, corpo) {
      const lista = ouvintes[funcao];
      if (!lista) return;
      for (const cb of lista.slice()) {
        try { cb(corpo); } catch (err) { console.error('[unreal] ouvinte de', funcao, 'falhou', err); }
      }
    },
  };

  console.info('[unreal] ponte montada sobre window.ue (HuntersWeb)');
  return true;
}

/** A ponte existe AGORA? Tenta montá-la se o objeto ligado já estiver disponível. */
export function disponivel() {
  if (window.WebNative && typeof window.WebNative.send === 'function') return true;
  return montarPonte();
}

/**
 * Espera a ponte aparecer. Resolve com true/false — nunca rejeita, para o chamador
 * poder decidir o fallback sem try/catch.
 *
 * O polling é necessário nos dois motores: o comercial injeta alguns ms depois do load,
 * e o nosso publica `window.ue` na criação do contexto V8, que também não é síncrona
 * com o `DOMContentLoaded`.
 *
 * @returns {Promise<boolean>}
 */
export function pronta(timeout = PONTE_TIMEOUT) {
  if (disponivel()) return Promise.resolve(true);
  return new Promise((resolve) => {
    const inicio = Date.now();
    const t = setInterval(() => {
      if (disponivel()) { clearInterval(t); resolve(true); }
      else if (Date.now() - inicio > timeout) { clearInterval(t); resolve(false); }
    }, 50);
  });
}

/**
 * Manda uma mensagem para a Unreal.
 * @returns {boolean} false se a ponte não existe (o chamador faz o fallback)
 */
export function enviar(funcao, corpo = '') {
  if (!disponivel()) return false;
  return window.WebNative.send(funcao, corpo) !== false;
}

/** Assina uma mensagem vinda da Unreal. Devolve a função para cancelar. */
export function receber(funcao, callback) {
  // A assinatura pode acontecer antes de a ponte existir (o módulo da página carrega
  // primeiro). Guardamos e reassinamos quando ela aparecer, senão a primeira mensagem
  // se perderia — que é exatamente o caso do Hud.Ammo no primeiro tiro.
  const assinar = () => {
    if (window.WebNative && typeof window.WebNative.on === 'function') {
      window.WebNative.on(funcao, callback);
      return true;
    }
    return false;
  };

  if (!assinar()) {
    pronta().then((ok) => { if (ok) assinar(); });
  }

  return () => {
    if (window.WebNative && typeof window.WebNative.off === 'function') {
      window.WebNative.off(funcao, callback);
    }
  };
}

/**
 * Marca <html class="is-unreal"> quando estamos dentro do jogo, para o CSS poder
 * esconder o que não faz sentido lá (ex.: "SAIR — voltar para a área de trabalho").
 * Roda uma vez no import.
 */
pronta().then((ok) => {
  raiz.classList.toggle('is-unreal', ok);
  raiz.dataset.unreal = ok ? '1' : '0';
  if (ok) console.info('[unreal] ponte pronta');
});

export default { disponivel, pronta, enviar, receber };
