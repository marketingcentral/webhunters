/**
 * EXTRACT HUNTERS — navegação sem recarga.
 *
 * Dentro do jogo (CEF) uma navegação normal destrói e recria o documento inteiro: fontes,
 * imagem de fundo, canvas WebGL do atmosphere. É esse o "gap" que trava a tela por alguns
 * quadros. Aqui o casco nunca sai do ar — só o miolo troca.
 *
 * Como funciona:
 *   1. clique num link do mesmo domínio -> fetch com o cabeçalho X-EH-Partial: 1
 *   2. o servidor roda o MESMO controller, só que por layouts/partial.php, que devolve JSON
 *      com background / topbar / main / footer + a lista de css e js daquela tela
 *   3. o css novo é carregado ANTES da troca (sem flash de tela sem estilo)
 *   4. troca-se o DOM, mexe-se na history, e os módulos da página são reavaliados
 *
 * ---------------------------------------------------------------------------------
 * O problema difícil aqui não é trocar HTML — é DESLIGAR a tela que saiu.
 *
 * Módulo ES é avaliado uma vez por URL. Para a tela funcionar de novo numa revisita o
 * módulo tem de ser reavaliado, e para isso a URL precisa mudar (`?_e=`). Só que aí cada
 * visita deixa para trás os `setInterval` de relógio, os `requestAnimationFrame` e os
 * listeners em window/document que aquele módulo registrou. Cinco telas deste site têm
 * relógio de 1s; vinte navegações e o jogo está rodando vinte relógios invisíveis.
 *
 * A solução é a "época": setTimeout/setInterval/requestAnimationFrame e o addEventListener
 * de window/document/body são embrulhados, e cada registro fica marcado com a época em que
 * nasceu. Ao sair de uma tela, varre-se tudo daquela época. A época 0 é o casco (hud.js,
 * este arquivo) e NUNCA é varrida. Timer que nasce dentro de outro timer herda a época
 * corrente, então cadeias de setTimeout também morrem junto.
 *
 * Nada disso exige mudança nos 24 módulos de página — eles continuam escritos como sempre.
 * ---------------------------------------------------------------------------------
 *
 * Fora do jogo o comportamento é o mesmo. Se qualquer coisa falhar (rede, JSON inválido,
 * casco diferente), cai para navegação normal: o jogador vê o engasgo, mas nunca trava.
 */

const CABECALHO = 'X-EH-Partial';
const ATRASO_PRELOADER = 180;    // ms de espera antes de mostrar o preloader
const LIMITE_FETCH = 15000;      // ms até desistir e navegar normalmente
const SAIDA_MS = 110;            // fade do miolo que sai

/*
 * Prefetch ao passar o mouse.
 *
 * O servidor de produção responde em ~450 ms (ida e volta + PHP + MySQL). Esse é o custo
 * dominante da navegação — muito maior que trocar o DOM. Mas entre o ponteiro tocar o link
 * e o clique acontecer passam tipicamente 200-400 ms de graça, e é essa janela que a gente
 * usa: quando o clique chega, a resposta muitas vezes já está aqui.
 *
 * VALIDADE existe porque a resposta parcial carrega estado do jogador (créditos, XP, nível
 * na topbar). Servir uma cópia velha demais mostraria números defasados; 30 s é curto o
 * bastante para isso não incomodar e longo o bastante para cobrir a hesitação de quem lê o
 * menu antes de clicar. O token CSRF não é problema: ele é por sessão, não por requisição.
 */
const PREFETCH_ESPERA = 65;      // ms de mouse parado antes de disparar (evita varrer o menu)
const PREFETCH_VALIDADE = 30000; // ms de vida de uma resposta guardada
const PREFETCH_MAX = 6;          // no máximo isto em memória

const reduzido = matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ================================================================= ciclo de vida */

/** Referências nativas: este arquivo nunca pode cair nas próprias armadilhas. */
const nativo = {
  setTimeout: window.setTimeout.bind(window),
  clearTimeout: window.clearTimeout.bind(window),
  setInterval: window.setInterval.bind(window),
  clearInterval: window.clearInterval.bind(window),
  raf: window.requestAnimationFrame.bind(window),
  caf: window.cancelAnimationFrame.bind(window),
};

let epoca = 0;
const registros = new Map();

function registro(e) {
  let r = registros.get(e);
  if (!r) {
    r = { timeouts: new Set(), intervals: new Set(), rafs: new Set(), listeners: [], limpezas: [] };
    registros.set(e, r);
  }
  return r;
}

/**
 * Embrulha um agendador. O callback se desregistra ao disparar, senão o Set cresce sem
 * limite num laço de requestAnimationFrame (60 ids por segundo).
 */
function agendador(criar, campo, umaVez) {
  return function (fn, ...resto) {
    // string em vez de função (forma `eval`) e época 0 passam direto, sem rastro.
    if (typeof fn !== 'function' || epoca === 0) return criar(fn, ...resto);
    const e = epoca;
    const conjunto = registro(e)[campo];
    let id;
    const embrulho = function (...args) {
      if (umaVez) conjunto.delete(id);
      return fn.apply(this, args);
    };
    id = criar(embrulho, ...resto);
    conjunto.add(id);
    return id;
  };
}

window.setTimeout = agendador(nativo.setTimeout, 'timeouts', true);
window.setInterval = agendador(nativo.setInterval, 'intervals', false);
window.requestAnimationFrame = agendador(nativo.raf, 'rafs', true);

/* Só window/document/body: o que é registrado dentro do <main> vai embora com o DOM. */
for (const alvo of [window, document, document.body]) {
  if (!alvo || typeof alvo.addEventListener !== 'function') continue;
  const original = alvo.addEventListener.bind(alvo);
  alvo.addEventListener = function (tipo, fn, opcoes) {
    original(tipo, fn, opcoes);
    if (epoca > 0 && fn) registro(epoca).listeners.push([alvo, tipo, fn, opcoes]);
  };
}

/** Desliga tudo que a época registrou. Erro aqui não pode abortar a navegação. */
function encerrar(e) {
  const r = registros.get(e);
  if (!r) return;
  registros.delete(e);
  r.timeouts.forEach(nativo.clearTimeout);
  r.intervals.forEach(nativo.clearInterval);
  r.rafs.forEach(nativo.caf);
  for (const [alvo, tipo, fn, opcoes] of r.listeners) {
    try { alvo.removeEventListener(tipo, fn, opcoes); } catch { /* alvo já foi */ }
  }
  for (const fn of r.limpezas) {
    try { fn(); } catch (err) { console.error('[spa] limpeza falhou', err); }
  }
}

/**
 * Registra uma limpeza para quando esta tela sair (liberar contexto WebGL, por exemplo).
 * Chamada durante a avaliação do módulo da página, então pega a época certa sozinha.
 */
export function aoSair(fn) {
  if (typeof fn === 'function' && epoca > 0) registro(epoca).limpezas.push(fn);
}

/** Fim do casco: daqui em diante tudo que for registrado pertence a uma tela. */
export function abrirEpoca() {
  if (epoca === 0) epoca = 1;
}

/* ============================================================== ganchos das telas */

const aoTrocarFns = [];
/** hud.js registra aqui o que precisa rodar de novo a cada tela (tabs, sliders, boot…). */
export function aoTrocar(fn) {
  if (typeof fn === 'function') aoTrocarFns.push(fn);
}

/* ====================================================================== preloader */

let preloader = null;
function obterPreloader() {
  if (preloader) return preloader;
  const el = document.createElement('div');
  el.className = 'spa-load';
  el.setAttribute('role', 'status');
  el.setAttribute('aria-live', 'polite');
  el.innerHTML =
    '<div class="spa-load__box">' +
      '<div class="spa-load__mark"><i class="spa-load__ring"></i><i class="spa-load__ring spa-load__ring--2"></i><i class="spa-load__core"></i></div>' +
      '<p class="spa-load__txt"><span data-carga-rotulo>CARREGANDO</span><b class="spa-load__pontos"></b></p>' +
      '<div class="spa-load__bar"><i></i></div>' +
    '</div>';
  document.body.appendChild(el);
  return (preloader = el);
}

/** Nome da tela de destino, tirado do próprio link. Vazio vira só "CARREGANDO". */
function rotularPreloader(texto) {
  const el = obterPreloader().querySelector('[data-carga-rotulo]');
  if (!el) return;
  const limpo = String(texto || '').replace(/\s+/g, ' ').trim().slice(0, 28).toUpperCase();
  el.textContent = limpo ? 'CARREGANDO ' + limpo : 'CARREGANDO';
}

function mostrarPreloader() { obterPreloader().classList.add('is-on'); }

function esconderPreloader() {
  if (!preloader) return;
  preloader.classList.remove('is-on');
  // Volta ao rótulo neutro só depois do fade, senão o texto pisca ao sair.
  nativo.setTimeout(() => { if (preloader && !preloader.classList.contains('is-on')) rotularPreloader(''); }, 260);
}

/**
 * Mostra o preloader e o deixa lá até o navegador trocar de documento.
 *
 * Existe para os caminhos que NÃO são navegação sem recarga: a tela de login, o 404, e
 * todo fallback daqui. Sem isto o jogador clica e encara uma tela preta enquanto o
 * servidor pensa — que é exatamente a queixa que originou este código.
 *
 * `travado` impede que o `finally` de navegar() apague o preloader bem na hora em que ele
 * mais importa: quando estamos saindo para uma navegação de verdade.
 */
let travado = false;
export function preloaderAteSair(rotulo) {
  travado = true;
  if (rotulo !== undefined) rotularPreloader(rotulo);
  mostrarPreloader();
}

/* ================================================================ carga de assets */

const jaCarregado = new Set();   // URLs do casco que não se repetem

/** Insere um <link> e só resolve quando o CSS aplicou — é isso que evita o flash. */
function carregarCss(href) {
  return new Promise((resolve) => {
    if (document.querySelector('link[rel="stylesheet"][href="' + CSS.escape(href) + '"]')) return resolve();
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    link.dataset.spaCss = href;
    link.addEventListener('load', () => resolve(), { once: true });
    link.addEventListener('error', () => resolve(), { once: true });   // segue sem o estilo
    document.head.appendChild(link);
    nativo.setTimeout(resolve, 2500);   // rede ruim não pode travar a navegação
  });
}

/** Espera a imagem de fundo, para a troca não aparecer sem ela. Nunca rejeita. */
function precarregarImagem(url) {
  if (!url) return Promise.resolve();
  return new Promise((resolve) => {
    const img = new Image();
    img.onload = () => resolve();
    img.onerror = () => resolve();
    img.src = url;
    nativo.setTimeout(resolve, 2000);
  });
}

/**
 * Reavalia o módulo de uma tela.
 *
 * `?_e=` é o preço de reavaliar um módulo ES: o cache é por URL, então revisitar uma tela
 * custa um download. Módulo de página aqui tem poucos KB e o ganho de não recarregar o
 * documento inteiro é muito maior — mas é um custo real, não um detalhe.
 */
function importarPagina(url, e) {
  const sep = url.includes('?') ? '&' : '?';
  return import(url + sep + '_e=' + e).catch((err) => {
    console.error('[spa] falhou ao carregar', url, err);
  });
}

function importarUmaVez(url) {
  if (jaCarregado.has(url)) return Promise.resolve();
  jaCarregado.add(url);
  return import(url).catch((err) => {
    console.error('[spa] falhou ao carregar', url, err);
  });
}

/* ===================================================================== prefetch */

const guardados = new Map();   // url -> { t, promessa }
let timerHover = 0;

/** Busca a resposta parcial de uma URL. Devolve o JSON ou null — nunca rejeita. */
function buscarParcial(url, sinal) {
  return fetch(url, {
    headers: { [CABECALHO]: '1', 'Accept': 'application/json' },
    credentials: 'same-origin',
    signal: sinal,
  }).then(async (resp) => {
    const tipo = resp.headers.get('Content-Type') || '';
    if (!tipo.includes('application/json')) return null;
    const dados = await resp.json();
    if (!dados || dados.layout !== 'hud') return null;
    dados.__url = resp.url || url;   // o fetch segue redirect; a URL final é a que vale
    return dados;
  });
}

/**
 * Adianta o CSS e o módulo da tela sem aplicá-los.
 *
 * `preload as=style` baixa a folha SEM ativá-la — usar `rel=stylesheet` aqui repintaria
 * a tela atual com o CSS de outra página. `modulepreload` busca e compila o módulo sem
 * AVALIAR: quem avalia é o import() da navegação, e como a época seguinte é previsível
 * (`epoca + 1`) a URL bate exatamente e o trabalho é reaproveitado.
 */
function adiantarAssets(dados) {
  const proxima = epoca + 1;
  for (const href of dados.css || []) {
    if (document.querySelector(`link[href="${CSS.escape(href)}"]`)) continue;
    const l = document.createElement('link');
    l.rel = 'preload'; l.as = 'style'; l.href = href;
    document.head.appendChild(l);
  }
  for (const src of dados.js || []) {
    const url = src + (src.includes('?') ? '&' : '?') + '_e=' + proxima;
    if (document.querySelector(`link[href="${CSS.escape(url)}"]`)) continue;
    const l = document.createElement('link');
    l.rel = 'modulepreload'; l.href = url;
    document.head.appendChild(l);
  }
}

function prefetchar(url) {
  if (navegando || guardados.has(url)) return;
  // Conexão medida como cara ou usuário pedindo economia: não gastar dados por palpite.
  const con = navigator.connection;
  if (con && (con.saveData || /^(slow-)?2g$/.test(con.effectiveType || ''))) return;

  if (guardados.size >= PREFETCH_MAX) {
    guardados.delete(guardados.keys().next().value);   // Map preserva ordem de inserção
  }
  const promessa = buscarParcial(url)
    .then((dados) => { if (dados) adiantarAssets(dados); return dados; })
    .catch(() => null);   // prefetch que falha é silencioso: o clique refaz o pedido
  guardados.set(url, { t: Date.now(), promessa });
}

/** Resposta guardada ainda válida para esta URL, ou null. */
function guardadoDe(url) {
  const g = guardados.get(url);
  if (!g) return null;
  guardados.delete(url);   // usa uma vez: a próxima visita merece estado fresco
  return (Date.now() - g.t) > PREFETCH_VALIDADE ? null : g.promessa;
}

/* ==================================================================== a navegação */

let navegando = null;         // AbortController da navegação em curso
const posicoes = new Map();   // url -> scrollY, para o voltar do navegador

const chave = (u) => u.split('#')[0];

function guardarScroll() {
  posicoes.set(chave(location.href), window.scrollY || 0);
}

/**
 * O atmosphere é um canvas WebGL que roda no fundo. Recriá-lo a cada tela seria
 * exatamente o engasgo que estamos tentando eliminar, então o canvas antigo é
 * transplantado para dentro do fundo novo e o contexto segue vivo.
 */
function preservarAtmosphere(bgNovo) {
  const antigo = document.getElementById('atmosphere');
  if (!antigo) return;
  const lugar = bgNovo.querySelector('#atmosphere');
  if (lugar) lugar.replaceWith(antigo);
}

function trocarRegiao(seletor, html) {
  const atual = document.querySelector(seletor);
  if (!atual || typeof html !== 'string' || html === '') return;
  const molde = document.createElement('div');
  molde.innerHTML = html.trim();
  const novo = molde.firstElementChild;
  if (!novo) return;
  if (seletor === '.bg') preservarAtmosphere(novo);
  atual.replaceWith(novo);
}

/** A URL da imagem de fundo que vem no HTML novo, para pré-carregar antes de trocar. */
function novaImagemDeFundo(html) {
  if (typeof html !== 'string') return '';
  const m = /<img[^>]+class="bg__img"[^>]+src="([^"]+)"/.exec(html);
  return m ? m[1].replace(/&amp;/g, '&') : '';
}

async function aplicar(dados, url) {
  const main = document.getElementById('main');
  const css = Array.isArray(dados.css) ? dados.css : [];

  /* 1. estilos e imagem de fundo entram antes da troca */
  await Promise.all([
    ...css.map(carregarCss),
    precarregarImagem(novaImagemDeFundo(dados.background)),
  ]);

  /* 2. a tela que sai é desligada por inteiro */
  encerrar(epoca);
  const hero = window.__ehHero3d;
  if (hero && typeof hero.dispose === 'function') { try { hero.dispose(); } catch { /* já foi */ } }
  window.__ehHero3d = null;

  /* 3. troca */
  trocarRegiao('.bg', dados.background);
  trocarRegiao('.topbar', dados.topbar);
  trocarRegiao('.footer', dados.footer);
  if (main) main.innerHTML = dados.main || '';

  document.title = dados.title || document.title;
  document.body.className = dados.bodyClass || document.body.className;
  document.body.dataset.page = dados.slug || '';
  const meta = document.querySelector('meta[name="csrf-token"]');
  if (meta && dados.csrf) meta.content = dados.csrf;

  /* 4. o css da tela anterior sai só agora, com a nova já pintada */
  const mantidos = new Set(css);
  document.querySelectorAll('link[data-spa-css]').forEach((l) => {
    if (!mantidos.has(l.getAttribute('href'))) l.remove();
  });

  /* 5. nova época: daqui em diante os registros pertencem à tela nova */
  epoca += 1;
  document.body.classList.remove('is-booted');

  for (const fn of aoTrocarFns) {
    try { fn(main || document); } catch (err) { console.error('[spa] gancho de troca falhou', err); }
  }

  await Promise.all((dados.jsOnce || []).map(importarUmaVez));
  await Promise.all((dados.js || []).map((u) => importarPagina(u, epoca)));

  document.dispatchEvent(new CustomEvent('spa:navegou', { detail: { slug: dados.slug, url } }));
}

function foraDoAr(url) {
  // Último recurso: navegação normal. O preloader FICA — a espera do documento novo é
  // justamente a mais longa, e é a que deixava a tela seca.
  preloaderAteSair();
  location.href = url;
}

export async function navegar(url, { push = true, restaurar = null, rotulo = '' } = {}) {
  if (navegando) navegando.abort('substituida');
  const controle = new AbortController();
  navegando = controle;

  if (push) guardarScroll();

  document.body.classList.add('is-navigating');
  rotularPreloader(rotulo);
  const t = nativo.setTimeout(mostrarPreloader, ATRASO_PRELOADER);
  const expira = nativo.setTimeout(() => controle.abort('tempo'), LIMITE_FETCH);
  // O fade de saída roda em paralelo com o fetch: se a resposta for rápida, ninguém vê.
  const saida = reduzido ? Promise.resolve() : new Promise((r) => nativo.setTimeout(r, SAIDA_MS));

  try {
    // Se o ponteiro passou por cima antes do clique, a resposta já pode estar aqui.
    const guardado = guardadoDe(url);
    const dados = await (guardado || buscarParcial(url, controle.signal));
    if (!dados) return foraDoAr(url);
    if (controle.signal.aborted) return;

    const final = dados.__url || url;
    await saida;
    await aplicar(dados, final);

    if (push) history.pushState({ spa: true }, '', final);
    window.scrollTo({ top: restaurar == null ? 0 : restaurar, behavior: 'auto' });
  } catch (err) {
    // outra navegação assumiu no meio: sair calado, ela é que manda agora
    if (controle.signal.aborted && controle.signal.reason === 'substituida') return;
    console.error('[spa] navegação falhou', err);
    return foraDoAr(url);
  } finally {
    nativo.clearTimeout(t);
    nativo.clearTimeout(expira);
    if (navegando === controle && !travado) {
      navegando = null;
      esconderPreloader();
      document.body.classList.remove('is-navigating');
    }
  }
}

/* =============================================================== interceptações */

/** URL que este link representa, se ele for navegável sem recarga. Senão, null. */
function urlDoLink(a) {
  if (!a || (a.target && a.target !== '_self')) return null;
  if (a.hasAttribute('download') || a.hasAttribute('data-no-spa')) return null;
  const href = a.getAttribute('href');
  if (!href || href.startsWith('#') || /^(javascript|mailto|tel):/i.test(href)) return null;
  let u;
  try { u = new URL(a.href, location.href); } catch { return null; }
  if (u.origin !== location.origin) return null;
  if (u.pathname === location.pathname && u.search === location.search && u.hash) return null;
  return u.href;
}

/** Este clique deve ser tratado sem recarga? Devolve a URL ou null. */
function elegivel(a, ev) {
  if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return null;
  return urlDoLink(a);
}

/** POST sem recarga. O fetch segue o redirect do PHP, então o flash da sessão chega junto. */
async function enviarPost(form, acao) {
  // Um POST muda estado (créditos na loja, XP, mensagem lida). Tudo o que foi adiantado
  // antes dele passa a mostrar número velho na topbar — joga fora e busca de novo.
  guardados.clear();
  guardarScroll();
  document.body.classList.add('is-navigating');
  const t = nativo.setTimeout(mostrarPreloader, ATRASO_PRELOADER);
  try {
    const resp = await fetch(acao, {
      method: 'POST',
      body: new FormData(form),
      headers: { [CABECALHO]: '1', 'Accept': 'application/json' },
      credentials: 'same-origin',
    });
    const tipo = resp.headers.get('Content-Type') || '';
    // Nos dois fallbacks o preloader fica de pé até o documento novo chegar.
    if (!tipo.includes('application/json')) { preloaderAteSair(); form.submit(); return; }
    const dados = await resp.json();
    if (!dados || dados.layout !== 'hud') { preloaderAteSair(); location.href = resp.url || acao; return; }
    await aplicar(dados, resp.url || acao);
    history.pushState({ spa: true }, '', resp.url || acao);
    window.scrollTo({ top: 0, behavior: 'auto' });
  } catch (err) {
    console.error('[spa] envio falhou', err);
    preloaderAteSair();
    form.submit();
  } finally {
    nativo.clearTimeout(t);
    if (!travado) {
      esconderPreloader();
      document.body.classList.remove('is-navigating');
    }
  }
}

function ligar() {
  /*
   * window e não document: o borbulhar chega ao document ANTES do window, então os
   * handlers das páginas (o botão JOGAR, o drag do inventário) rodam primeiro e, se
   * chamarem preventDefault, este aqui respeita e sai de perto.
   */
  window.addEventListener('click', (ev) => {
    const alvo = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
    const url = elegivel(alvo, ev);
    if (!url) return;
    ev.preventDefault();
    // O texto do próprio link vira o rótulo do preloader ("CARREGANDO INVENTÁRIO").
    navegar(url, { rotulo: alvo.dataset.rotulo || alvo.textContent });
  });

  /*
   * Prefetch. Duas janelas, da mais folgada para a mais apertada:
   *   mouseover  -> espera PREFETCH_ESPERA para não disparar sete pedidos ao varrer o menu
   *   pointerdown-> sem espera: o clique só chega ~100 ms depois, e aí a intenção é certa
   * O toque não tem hover, então o pointerdown é o que salva no celular e no controle.
   */
  window.addEventListener('mouseover', (ev) => {
    const a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
    const url = urlDoLink(a);
    if (!url) return;
    nativo.clearTimeout(timerHover);
    timerHover = nativo.setTimeout(() => prefetchar(url), PREFETCH_ESPERA);
  }, { passive: true });

  window.addEventListener('mouseout', () => nativo.clearTimeout(timerHover), { passive: true });

  window.addEventListener('pointerdown', (ev) => {
    if (ev.button !== 0) return;
    const a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
    const url = urlDoLink(a);
    if (url) prefetchar(url);
  }, { passive: true });

  window.addEventListener('submit', (ev) => {
    if (ev.defaultPrevented) return;
    const form = ev.target;
    if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-no-spa')) return;

    let acao;
    try { acao = new URL(form.getAttribute('action') || location.href, location.href); } catch { return; }
    if (acao.origin !== location.origin) return;

    const metodo = (form.method || 'get').toLowerCase();
    ev.preventDefault();

    if (metodo === 'get') {
      acao.search = new URLSearchParams(new FormData(form)).toString();
      navegar(acao.href);
      return;
    }
    enviarPost(form, acao.href);
  });

  window.addEventListener('popstate', () => {
    const alvo = chave(location.href);
    navegar(location.href, { push: false, restaurar: posicoes.get(alvo) || 0 });
  });

  window.addEventListener('beforeunload', guardarScroll);
}

/** Só faz sentido no casco HUD; /login e a HUD in-game seguem com navegação normal. */
export function instalar() {
  if (!document.getElementById('main') || !document.querySelector('.topbar')) return false;
  history.replaceState({ spa: true }, '', location.href);
  ligar();
  return true;
}

export default { instalar, navegar, aoTrocar, aoSair, abrirEpoca, preloaderAteSair };
