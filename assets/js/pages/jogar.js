/**
 * Tela JOGAR — comportamentos locais.
 *  - [data-countdown="12h 45m"] : relógio regressivo vivo (reinício dos desafios / recompensa)
 *  - [data-claim]               : resgate da recompensa diária (mock, sem back-end)
 *  - [data-ue-play]             : dentro da Unreal, carrega o level do mapa selecionado
 * Tudo é degradável: sem JS a tela continua correta (os valores do mock ficam estáticos).
 */

import { enviar, pronta, receber } from '../unreal.js';
import { aoSair } from '../spa.js';

const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ---- contagem regressiva ------------------------------------------------ */
function parseSpan(text) {
  const h = /(\d+)\s*h/i.exec(text);
  const m = /(\d+)\s*m/i.exec(text);
  const s = /(\d+)\s*s(?!\w)/i.exec(text);
  if (!h && !m && !s) return null;
  return (h ? +h[1] * 3600 : 0) + (m ? +m[1] * 60 : 0) + (s ? +s[1] : 0);
}

function formatSpan(total) {
  if (total >= 3600) {
    let h = Math.floor(total / 3600);
    let m = Math.ceil((total % 3600) / 60);   // arredonda p/ cima: 12h 45m fica 45m o minuto inteiro
    if (m === 60) { m = 0; h += 1; }
    return `${h}h ${String(m).padStart(2, '0')}m`;
  }
  const m = Math.floor(total / 60);
  const s = total % 60;
  return `${m}m ${String(s).padStart(2, '0')}s`;
}

function countdowns() {
  const els = [...document.querySelectorAll('[data-countdown]')]
    .map((el) => ({ el, left: parseSpan(el.dataset.countdown || el.textContent || '') }))
    .filter((c) => c.left !== null && c.left > 0);
  if (!els.length) return;

  const tick = () => {
    for (const c of els) {
      if (c.left <= 0) continue;
      c.left -= 1;
      const next = formatSpan(Math.max(0, c.left));
      if (c.el.textContent !== next) c.el.textContent = next;
    }
  };
  for (const c of els) c.el.textContent = formatSpan(c.left);
  window.setInterval(tick, 1000);
}

/* ---- recompensa diária -------------------------------------------------- */
function claim() {
  const btn = document.querySelector('[data-claim]');
  if (!btn) return;
  btn.addEventListener('click', () => {
    if (btn.classList.contains('is-claimed')) return;
    btn.classList.add('is-claimed');
    btn.setAttribute('aria-disabled', 'true');
    const label = btn.querySelector('.btn__label');
    if (label) label.textContent = 'RECOMPENSA RESGATADA';
    const thumb = document.querySelector('.reward__thumb img');
    if (thumb && !reduced) {
      thumb.animate(
        [{ transform: 'scale(1)' }, { transform: 'scale(1.18) rotate(-4deg)' }, { transform: 'scale(1)' }],
        { duration: 520, easing: 'cubic-bezier(.16,1,.3,1)' },
      );
    }
  });
}

/* ---- estado operacional: o hero central acompanha a seleção (PRD v3 §17.4–17.6) ----
   Os textos dos heróis vêm em data-heroes (JSON) no próprio hero; a linha de indicadores
   é do estado REAL do jogador (vem do servidor) e não muda ao passear pela lista. */
function modeSync() {
  const list = document.querySelector('.jogar-modes__list');
  const hero = document.querySelector('.jogar-hero');
  if (!list || !hero) return;
  let heroes = {};
  try { heroes = JSON.parse(hero.dataset.heroes || '{}'); } catch { heroes = {}; }
  const title = hero.querySelector('[data-hero-title]');
  const desc  = hero.querySelector('[data-hero-desc]');
  const cta   = document.querySelector('.jogar-cta__btn .btn__label');

  list.addEventListener('select:change', (e) => {
    const item = e.detail && e.detail.item;
    if (!item) return;
    const key = item.dataset.hero || 'hunter';
    const h = heroes[key];
    if (!h) return;
    if (title) title.textContent = h.title || title.textContent;
    if (desc)  desc.textContent  = h.desc  || desc.textContent;
    if (cta && h.cta) cta.textContent = h.cta;
    hero.className = hero.className.replace(/\bjogar-hero--\w+/, 'jogar-hero--' + key);
    hero.dataset.heroCurrent = key;
  });
}


/* ---- entrar na partida (Unreal) ----------------------------------------- */
/** Retorno visual no próprio botão — mesmo padrão do claim(), sem dependência nova. */
function avisar(btn, texto) {
  const label = btn.querySelector('.btn__label');
  if (label) label.textContent = texto;
}

/**
 * O level de cada mapa vem do banco (maps.unreal_level -> data-ue-level no card).
 * Dentro do jogo: manda Game.LoadMap e a Blueprint WBP_HuntersWeb faz o Open Level.
 * No navegador comum: não intercepta nada, o link para /lobby funciona normalmente.
 *
 * Por que há tentativas em sequência: `UGameplayStatics::OpenLevel` monta uma `FURL` e,
 * se o nome não resolver, o único aviso é um `UE_LOG(Warning)` — que o build **Shipping
 * compila fora**. Ou seja: nome errado falha em silêncio absoluto, sem log, sem erro,
 * sem DevTools. Como o widget morre junto com o level ao trocar de mapa, "a página ainda
 * estar viva" é o único sinal disponível de que a tentativa não pegou. Cada tentativa se
 * anuncia no próprio botão, então um print da tela já diz até onde chegou.
 */
async function jogarNaUnreal() {
  const btn = document.querySelector('[data-ue-play]');
  if (!btn) return;

  // sem ponte (navegador comum) o link segue o fluxo normal: nada a fazer
  if (!(await pronta())) return;

  // Se a Blueprint responder (versão com eco), sabemos que a UE recebeu — e aí o
  // problema é o Open Level, não a ponte. Inofensivo se o eco não existir.
  //
  // A assinatura vive em unreal.js, que NÃO é reavaliado a cada navegação (só o módulo
  // desta página é). Sem cancelar na saída, cada visita a /jogar deixaria mais um ouvinte
  // preso a um botão que já saiu do DOM.
  aoSair(receber('Diag.Echo', (corpo) => avisar(btn, 'UE RECEBEU: ' + String(corpo).slice(0, 40))));

  const ESPERA = 1600;   // ms para o level carregar antes de tentar a próxima forma

  btn.addEventListener('click', (e) => {
    e.preventDefault();

    const card = document.querySelector('.map-card.is-selected') || document.querySelector('.map-card');
    const level = card && card.dataset.ueLevel;
    if (!level) { avisar(btn, 'MAPA SEM LEVEL NA UNREAL'); return; }

    // 1º e 2º: o alvo real, em caminho completo e em nome curto.
    // 3º: DIAGNÓSTICO. MultiplayerTest_level está cozido em toda build desde sempre, e
    // trocar de level destrói este widget — ou seja, se ele carregar, a mensagem chegou
    // na Blueprint e o problema é do alvo; se nem ele carregar, a mensagem não chega.
    const curto = level.split('/').filter(Boolean).pop();
    const formas = [level];
    if (curto && curto !== level) { formas.push(curto); }
    formas.push('MultiplayerTest_level');

    let i = 0;
    const tentar = () => {
      if (i >= formas.length) { avisar(btn, 'UE NAO RECEBE MENSAGEM'); return; }
      const alvo = formas[i];
      const rotulo = i === 0 ? 'CARREGANDO...'
        : i === formas.length - 1 ? 'TESTE: ' + alvo
        : 'TENTANDO: ' + alvo;
      i++;

      if (!enviar('Game.LoadMap', alvo)) { avisar(btn, 'PONTE COM A UNREAL INDISPONIVEL'); return; }
      avisar(btn, rotulo);
      // ainda aqui depois da espera? o level não trocou; tenta a próxima forma
      setTimeout(tentar, ESPERA);
    };
    tentar();
  });
}

function init() {
  countdowns();
  claim();
  modeSync();
  jogarNaUnreal();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
  init();
}
