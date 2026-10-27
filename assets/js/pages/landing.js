/**
 * EXTRACT HUNTERS — comportamento da página de divulgação (/).
 *
 * TUDO AQUI É ENRIQUECIMENTO. Sem este arquivo a página continua completa e legível: as
 * cartas de estado mostram o painel do Hunter, o bestiário mostra todas as criaturas e a
 * primeira ficha, as faixas de contaminação aparecem como tabela, o mapa aparece inteiro, a
 * galeria são links. O que o script faz é dar MOVIMENTO à leitura — e
 * numa página que promete "você já está dentro do jogo", movimento é o argumento.
 *
 * O MAPA não é tratado aqui: é o palco da HUD (partials/map-stage.php) com o mesmo
 * assets/js/three/map3d.js da tela /mapa — zoom, arrasto e relevo vêm dele. Este arquivo só
 * liga e desliga camadas, exatamente como assets/js/pages/mapa.js faz na HUD.
 *
 * O FIO QUE AMARRA A PÁGINA é `--ac` no <html>: escolher um estado ou assinar com uma facção
 * troca a cor e a página inteira repinta. "Não são personagens diferentes, é o mesmo operador
 * em outro estado" vira algo que o visitante SENTE em vez de ler.
 */

const doc = document;
const raiz = doc.documentElement;
const menosMovimento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ============================================================ sequência de conexão */
(function boot() {
  const alvo = doc.querySelector('[data-boot-txt]');
  if (!alvo || menosMovimento) return;
  const texto = alvo.textContent.trim();
  alvo.textContent = '';
  let i = 0;
  const passo = () => {
    alvo.textContent = texto.slice(0, ++i);
    if (i < texto.length) setTimeout(passo, 22);
  };
  setTimeout(passo, 260);
})();

/* ================================================================ entrada por rolagem */
(function revelar() {
  const itens = [...doc.querySelectorAll('[data-rev]')];
  if (!itens.length) return;
  const mostrarTudo = () => itens.forEach((el) => el.classList.add('is-in'));
  if (menosMovimento || !('IntersectionObserver' in window)) { mostrarTudo(); return; }

  /* Só AGORA o CSS pode esconder: até esta linha a página estava inteira à vista, e é
     assim que ela fica se este arquivo nunca chegar a rodar. */
  raiz.classList.add('js-rev');

  /* Rede de segurança: o observador não dispara em aba oculta nem em contexto sem
     renderização. Oito segundos é sobra para o caminho normal e curto para ninguém
     encarar o vazio. */
  const resgate = setTimeout(mostrarTudo, 8000);
  window.addEventListener('pagehide', () => clearTimeout(resgate), { once: true });
  const obs = new IntersectionObserver((linhas) => {
    linhas.forEach((l) => {
      if (!l.isIntersecting) return;
      l.target.classList.add('is-in');
      obs.unobserve(l.target);
    });
  }, { rootMargin: '0px 0px -10% 0px', threshold: 0.06 });
  itens.forEach((el) => obs.observe(el));
})();

/* ================================================ topo grudado, seção atual, paralaxe */
(function rolagem() {
  const top = doc.querySelector('[data-top]');
  const art = doc.querySelector('[data-parallax]');
  const links = [...doc.querySelectorAll('.top__nav a[href^="#"]')];
  const secoes = links.map((a) => doc.getElementById(a.hash.slice(1))).filter(Boolean);
  let pedido = false;

  const passo = () => {
    pedido = false;
    const y = window.scrollY || 0;
    if (top) top.classList.toggle('is-stuck', y > 40);
    /* A arte anda mais devagar que a página. Fator baixo de propósito: paralaxe forte num
       herói de tela cheia dá enjoo e atrapalha a leitura do título. */
    if (art && !menosMovimento && y < window.innerHeight * 1.2) {
      art.style.transform = `translate3d(0, ${(y * 0.18).toFixed(1)}px, 0)`;
    }
    /* Marca no topo a seção que ocupa o meio da tela. */
    if (secoes.length) {
      const meio = y + window.innerHeight * 0.45;
      let atual = null;
      secoes.forEach((s) => { if (s.offsetTop <= meio) atual = s; });
      links.forEach((a) => a.classList.toggle('is-here', !!atual && a.hash === '#' + atual.id));
    }
  };
  window.addEventListener('scroll', () => {
    if (pedido) return;
    pedido = true;
    requestAnimationFrame(passo);
  }, { passive: true });
  passo();
})();

/* ====================================================== herói: profundidade pelo mouse
   As colunas do herói se deslocam com o ponteiro em direções opostas (data-depth), e a
   retícula do mini-mapa segue o cursor. Nada disso existe no toque — lá o herói é estático. */
(function profundidade() {
  const hero = doc.querySelector('[data-hero]');
  if (!hero || menosMovimento || !window.matchMedia('(hover:hover) and (pointer:fine)').matches) return;
  const camadas = [...hero.querySelectorAll('[data-depth]')];
  const mapa = hero.querySelector('[data-tele-map]');
  const ret  = hero.querySelector('[data-tele-ret]');
  let alvoX = 0, alvoY = 0, x = 0, y = 0, raf = 0;

  const frame = () => {
    raf = 0;
    x += (alvoX - x) * 0.08;
    y += (alvoY - y) * 0.08;
    camadas.forEach((c) => {
      const d = Number(c.dataset.depth) || 0;
      c.style.transform = `translate3d(${(x * d).toFixed(1)}px, ${(y * d * 0.6).toFixed(1)}px, 0)`;
    });
    if (Math.abs(alvoX - x) > 0.002 || Math.abs(alvoY - y) > 0.002) raf = requestAnimationFrame(frame);
  };
  hero.addEventListener('pointermove', (ev) => {
    const r = hero.getBoundingClientRect();
    alvoX = (ev.clientX - r.left) / r.width - 0.5;
    alvoY = (ev.clientY - r.top) / r.height - 0.5;
    if (!raf) raf = requestAnimationFrame(frame);
  });
  hero.addEventListener('pointerleave', () => { alvoX = 0; alvoY = 0; if (!raf) raf = requestAnimationFrame(frame); });

  if (mapa && ret) {
    mapa.addEventListener('pointermove', (ev) => {
      const r = mapa.getBoundingClientRect();
      ret.style.left = `${((ev.clientX - r.left) / r.width * 100).toFixed(1)}%`;
      ret.style.top  = `${((ev.clientY - r.top) / r.height * 100).toFixed(1)}%`;
    });
    mapa.addEventListener('pointerleave', () => { ret.style.left = ''; ret.style.top = ''; });
  }
})();

/* ================================================================== contagem regressiva
   O evento da região vem do banco com "mm:ss" restantes. Aqui ele anda de verdade — um
   relógio parado num painel de telemetria é a primeira coisa que denuncia uma maquete. */
(function regressiva() {
  const el = doc.querySelector('[data-countdown]');
  if (!el) return;
  const partes = String(el.dataset.countdown || '').split(':').map(Number);
  if (partes.some(Number.isNaN) || !partes.length) return;
  let seg = partes.reduce((acc, p) => acc * 60 + p, 0);
  const pad = (n) => String(n).padStart(2, '0');
  const tick = () => {
    seg = Math.max(0, seg - 1);
    const h = Math.floor(seg / 3600), m = Math.floor((seg % 3600) / 60), s = seg % 60;
    el.textContent = partes.length === 3 ? `${pad(h)}:${pad(m)}:${pad(s)}` : `${pad(h * 60 + m)}:${pad(s)}`;
    if (seg > 0) setTimeout(tick, 1000);
  };
  setTimeout(tick, 1000);
})();

/* ===================================================================== contadores */
(function contar() {
  const nums = [...doc.querySelectorAll('[data-count]')];
  if (!nums.length || menosMovimento || !('IntersectionObserver' in window)) return;
  const obs = new IntersectionObserver((linhas) => {
    linhas.forEach((l) => {
      if (!l.isIntersecting) return;
      const el = l.target;
      obs.unobserve(el);
      const alvo = Number(el.dataset.count) || 0;
      const t0 = performance.now(), dur = 900;
      let pronto = false;
      const fixar = () => { pronto = true; el.textContent = String(alvo); };
      const anda = (t) => {
        if (pronto) return;
        const p = Math.min(1, (t - t0) / dur);
        el.textContent = String(Math.round(alvo * (1 - Math.pow(1 - p, 3))));
        if (p < 1) requestAnimationFrame(anda); else pronto = true;
      };
      requestAnimationFrame(anda);
      /* rAF não roda em aba oculta; o temporizador crava o valor final de qualquer jeito. */
      setTimeout(fixar, dur + 120);
    });
  }, { threshold: 0.5 });
  nums.forEach((el) => obs.observe(el));
})();

/* ============================================================== estado operacional
   Uma função só (`escolherEstado`) porque DOIS lugares a chamam: as cartas de estado e a
   assinatura de contrato lá embaixo, que transforma o operador em Mercenário. */
const estados = (function estados() {
  const abas = [...doc.querySelectorAll('[data-state]')];
  const paineis = [...doc.querySelectorAll('[data-state-panel]')];
  const nomes = [...doc.querySelectorAll('[data-state-name]')];
  const selos = [...doc.querySelectorAll('[data-state-sub]')];

  function escolher(id, { cor = null, selo = null } = {}) {
    const aba = abas.find((a) => a.dataset.state === id);
    if (!aba) return;
    /* A cor vive no <html> porque TUDO na página a lê. */
    raiz.style.setProperty('--ac', cor || aba.dataset.cor);
    raiz.dataset.estado = id;
    abas.forEach((a) => {
      const on = a === aba;
      a.classList.toggle('is-on', on);
      a.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    paineis.forEach((p) => {
      const on = p.dataset.statePanel === id;
      p.classList.toggle('is-on', on);
      p.hidden = !on;
    });
    nomes.forEach((n) => { n.textContent = aba.querySelector('b').textContent; });
    selos.forEach((s) => { s.textContent = selo || aba.dataset.selo || ''; });
  }

  abas.forEach((a) => a.addEventListener('click', () => {
    escolher(a.dataset.state);
    doc.dispatchEvent(new CustomEvent('lp:estado', { detail: { id: a.dataset.state, manual: true } }));
  }));
  /* Setas navegam entre cartas, como manda o padrão de tablist. */
  abas.forEach((a, i) => {
    a.addEventListener('keydown', (ev) => {
      const d = ev.key === 'ArrowRight' ? 1 : ev.key === 'ArrowLeft' ? -1 : 0;
      if (!d) return;
      ev.preventDefault();
      const prox = abas[(i + d + abas.length) % abas.length];
      prox.focus();
      escolher(prox.dataset.state);
    });
  });
  return { escolher };
})();

/* ==================================================================== camadas do mapa
   Mesma regra da HUD (assets/js/pages/mapa.js): multi-seleção, TODOS é atalho, nenhum chip
   marcado também mostra tudo — o mapa nunca fica vazio por engano. Os nós escondidos são
   os do palco compartilhado (#map3d-layer), pelo mesmo `.is-filtered` do mapa.css. */
(function camadas() {
  const barra = doc.querySelector('[data-map-filters]');
  if (!barra) return;
  const nodes = [...doc.querySelectorAll('#map3d-layer [data-layer]')];
  const chips = [...barra.querySelectorAll('.mlayer')];
  const todos = chips.find((c) => c.dataset.value === 'todos');
  const contador = doc.querySelector('[data-map-visible]');

  const marcar = (chip, on) => { chip.classList.toggle('is-active', on); chip.setAttribute('aria-pressed', on ? 'true' : 'false'); };
  const aplicar = () => {
    const on = chips.filter((c) => c !== todos && c.classList.contains('is-active')).map((c) => c.dataset.value);
    const tudo = on.length === 0;
    if (todos) marcar(todos, tudo);
    let visiveis = 0;
    nodes.forEach((n) => {
      const fora = !tudo && !on.includes(n.dataset.layer);
      n.classList.toggle('is-filtered', fora);
      if (!fora && n.classList.contains('mk')) visiveis++;
    });
    if (contador) contador.textContent = String(visiveis);
    doc.getElementById('map3d')?.setAttribute('data-layer-filter', tudo ? '' : on.join(' '));
  };
  barra.addEventListener('click', (ev) => {
    const chip = ev.target.closest('.mlayer');
    if (!chip) return;
    if (chip === todos) chips.forEach((c) => marcar(c, c === todos));
    else marcar(chip, !chip.classList.contains('is-active'));
    aplicar();
  });
  aplicar();
})();

/* ================================================================ dica dos marcadores
   Na HUD o tooltip vem de hud.js, que a landing não carrega. Um só elemento, reposicionado. */
(function dicas() {
  const palco = doc.getElementById('map3d');
  if (!palco || !window.matchMedia('(hover:hover)').matches) return;
  const tip = doc.createElement('div');
  tip.className = 'lptip';
  tip.hidden = true;
  Object.assign(tip.style, {
    position: 'fixed', zIndex: 95, maxWidth: '18rem', padding: '.4rem .6rem', pointerEvents: 'none',
    background: 'rgba(4,8,5,.94)', border: '1px solid rgba(155,224,74,.35)', borderRadius: '3px',
    font: '500 .72rem/1.35 "Barlow Condensed", sans-serif', letterSpacing: '.08em', color: '#e8ede6',
  });
  doc.body.appendChild(tip);
  let alvo = null;
  palco.addEventListener('pointerover', (ev) => {
    const mk = ev.target.closest('[data-tip]');
    if (!mk || mk === alvo) return;
    alvo = mk;
    tip.textContent = mk.dataset.tip || '';
    tip.hidden = !tip.textContent;
  });
  palco.addEventListener('pointermove', (ev) => {
    if (tip.hidden) return;
    const x = Math.min(window.innerWidth - tip.offsetWidth - 12, ev.clientX + 14);
    const y = Math.min(window.innerHeight - tip.offsetHeight - 12, ev.clientY + 16);
    tip.style.left = `${x}px`; tip.style.top = `${y}px`;
  });
  palco.addEventListener('pointerout', (ev) => {
    if (alvo && !alvo.contains(ev.relatedTarget)) { alvo = null; tip.hidden = true; }
  });
})();

/* ==================================================================== sistemas
   Sanfona: a carta sob o ponteiro (ou com foco) abre. Clique também, para o toque. */
(function sistemas() {
  const caixa = doc.querySelector('[data-sys]');
  if (!caixa) return;
  const cartas = [...caixa.querySelectorAll('[data-sys-card]')];
  const abrir = (c) => cartas.forEach((k) => k.classList.toggle('is-on', k === c));
  cartas.forEach((c) => {
    c.addEventListener('pointerenter', () => abrir(c));
    c.addEventListener('focus', () => abrir(c));
    c.addEventListener('click', () => abrir(c));
  });
})();

/* ======================================================================== bestiário
   Duas escolhas: o GRUPO (infectados, fauna, flora — filtra o carrossel) e a CRIATURA (qual
   ficha abre embaixo). Cada criatura tem uma leitura só de como lidar, já impressa no HTML:
   nada aqui recalcula veredito. O carrossel é uma faixa com rolagem nativa (dedo, roda,
   trackpad); as setas só empurram a faixa uma "página" para o lado. */
(function bestiario() {
  const caixa = doc.querySelector('[data-bestiary]');
  if (!caixa) return;
  const fichas = [...caixa.querySelectorAll('[data-beast]')];
  const vistas = [...caixa.querySelectorAll('[data-beast-view]')];
  const grupos = [...caixa.querySelectorAll('.cgroup[data-grupo]')];
  const textos = [...caixa.querySelectorAll('[data-grupo-txt]')];
  const trilho = caixa.querySelector('[data-carr-track]');
  const setas  = [...caixa.querySelectorAll('[data-carr]')];
  const visiveis = () => fichas.filter((f) => !f.classList.contains('is-out'));

  /* Rola SÓ a faixa até a carta — scrollIntoView também rolaria a página inteira. */
  function mostrar(f) {
    if (!trilho || !f) return;
    const esq = f.offsetLeft - trilho.offsetLeft;
    const dir = esq + f.offsetWidth;
    if (esq < trilho.scrollLeft) trilho.scrollTo({ left: esq - 8, behavior: menosMovimento ? 'auto' : 'smooth' });
    else if (dir > trilho.scrollLeft + trilho.clientWidth) trilho.scrollTo({ left: dir - trilho.clientWidth + 8, behavior: menosMovimento ? 'auto' : 'smooth' });
  }
  function setasEstado() {
    if (!trilho) return;
    const fim = trilho.scrollWidth - trilho.clientWidth - 8;
    setas.forEach((s) => { s.disabled = s.dataset.carr === '-1' ? trilho.scrollLeft <= 8 : trilho.scrollLeft >= fim; });
  }
  function abrir(id) {
    fichas.forEach((f) => { const on = f.dataset.beast === id; f.classList.toggle('is-on', on); f.setAttribute('aria-selected', on ? 'true' : 'false'); });
    vistas.forEach((v) => { const on = v.dataset.beastView === id; v.classList.toggle('is-on', on); v.hidden = !on; });
    mostrar(fichas.find((f) => f.dataset.beast === id));
  }
  function filtrar(g) {
    grupos.forEach((b) => { const on = b.dataset.grupo === g; b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
    textos.forEach((t) => { t.hidden = t.dataset.grupoTxt !== g; });
    fichas.forEach((f) => f.classList.toggle('is-out', g !== '' && f.dataset.grupo !== g));
    if (trilho) trilho.scrollLeft = 0;
    /* A ficha aberta não pode ser de um grupo que acabou de sumir da faixa. */
    const aberta = fichas.find((f) => f.classList.contains('is-on'));
    if (!aberta || aberta.classList.contains('is-out')) {
      const prim = visiveis()[0];
      if (prim) abrir(prim.dataset.beast);
    }
    setasEstado();
  }

  fichas.forEach((f) => f.addEventListener('click', () => abrir(f.dataset.beast)));
  grupos.forEach((b) => b.addEventListener('click', () => filtrar(b.dataset.grupo || '')));
  setas.forEach((s) => s.addEventListener('click', () => {
    if (!trilho) return;
    trilho.scrollBy({ left: Number(s.dataset.carr) * trilho.clientWidth * 0.85, behavior: menosMovimento ? 'auto' : 'smooth' });
  }));
  if (trilho) {
    trilho.addEventListener('scroll', setasEstado, { passive: true });
    window.addEventListener('resize', setasEstado);
    /* ← → andam de criatura em criatura e já abrem a ficha. */
    trilho.addEventListener('keydown', (ev) => {
      if (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft') return;
      const vs = visiveis();
      const i = vs.findIndex((f) => f.classList.contains('is-on'));
      const n = vs[Math.max(0, Math.min(vs.length - 1, i + (ev.key === 'ArrowRight' ? 1 : -1)))];
      if (n) { ev.preventDefault(); abrir(n.dataset.beast); n.focus(); }
    });
  }
  setasEstado();
})();

/* =================================================================== contaminação
   Um medidor que o visitante arrasta de 0 a 100. A faixa em que ele cai acende, o número
   muda de cor e, em 100, a última faixa pulsa: é o ponto em que o operador morre e o corpo
   levanta como Errante no mundo persistente. Sem este bloco a tabela inteira continua lá. */
(function contaminacao() {
  const caixa = doc.querySelector('[data-contam]');
  if (!caixa) return;
  const ctl = caixa.querySelector('[data-contam-ctl]');
  const range = caixa.querySelector('[data-contam-range]');
  const num = caixa.querySelector('[data-contam-n]');
  const est = caixa.querySelector('[data-contam-estado]');
  const faixas = [...caixa.querySelectorAll('[data-de]')];
  if (!range || !faixas.length) return;

  const aplicar = () => {
    const v = Math.max(0, Math.min(100, Number(range.value) || 0));
    const f = faixas.find((x) => v >= Number(x.dataset.de) && v <= Number(x.dataset.ate)) || faixas[0];
    faixas.forEach((x) => x.classList.toggle('is-on', x === f));
    caixa.dataset.cor = f.dataset.cor || 'lime';
    range.style.setProperty('--v', `${v}%`);
    if (num) num.textContent = String(v);
    if (est) est.textContent = f.dataset.estado || '';
  };
  if (ctl) ctl.hidden = false;
  caixa.classList.add('is-live');
  range.addEventListener('input', aplicar);
  /* Tocar numa faixa leva o medidor para o meio dela — no celular é mais fácil que arrastar. */
  faixas.forEach((x) => x.addEventListener('click', () => {
    const de = Number(x.dataset.de), ate = Number(x.dataset.ate);
    range.value = String(Math.round((de + ate) / 2));
    aplicar();
  }));
  aplicar();
})();

/* ============================================================ contrato exclusivo
   Aproximar-se de um lado apaga o outro (hover). ASSINAR vai até o fim: o operador vira
   Mercenário (a página repinta na cor da facção), a rival é marcada como revogada e um
   aviso explica o que acabou de acontecer — com o botão de romper e voltar ao Hunter. */
(function faccoes() {
  const grade = doc.querySelector('[data-fac]');
  if (!grade) return;
  const cartas = [...grade.querySelectorAll('[data-faction]')];
  const aviso = doc.querySelector('[data-fac-aviso]');
  const romper = doc.querySelector('[data-unsign]');
  let assinada = null;

  grade.addEventListener('pointerenter', () => grade.classList.add('is-hover'), true);
  grade.addEventListener('pointerleave', (ev) => { if (!grade.contains(ev.relatedTarget)) grade.classList.remove('is-hover'); });

  function reputacao(carta, valor, tier) {
    const bar = carta.querySelector('[data-rep-bar]');
    const num = carta.querySelector('[data-rep]');
    const t = carta.querySelector('[data-rep-tier]');
    if (bar) bar.style.width = `${Math.min(100, valor / 30)}%`;
    if (num) num.textContent = valor.toLocaleString('pt-BR');
    if (t) t.textContent = tier;
  }
  function assinar(id) {
    assinada = id;
    cartas.forEach((c) => {
      const eu = c.dataset.faction === id;
      c.classList.toggle('is-signed', eu);
      c.classList.toggle('is-rival', !eu);
      const btn = c.querySelector('[data-sign]');
      if (btn) btn.textContent = eu ? btn.dataset.lAssinado : btn.dataset.lAssinar;
      /* 1.500 é o exemplo de "CONFIÁVEL" do PRD; a rival cai a zero, como manda a regra. */
      reputacao(c, eu ? 1500 : 0, eu ? 'CONFIÁVEL' : 'REVOGADA');
    });
    const carta = cartas.find((c) => c.dataset.faction === id);
    const cor = carta ? getComputedStyle(carta).getPropertyValue('--fc').trim() : null;
    estados.escolher('mercenario', { cor, selo: (carta?.querySelector('h3')?.textContent || id).toUpperCase() });
    if (aviso) aviso.hidden = false;
  }
  function desfazer() {
    assinada = null;
    cartas.forEach((c) => {
      c.classList.remove('is-signed', 'is-rival');
      const btn = c.querySelector('[data-sign]');
      if (btn) btn.textContent = btn.dataset.lAssinar;
      reputacao(c, 0, 'SEM CONTRATO');
    });
    estados.escolher('hunter');
    if (aviso) aviso.hidden = true;
  }

  cartas.forEach((c) => c.querySelector('[data-sign]')?.addEventListener('click', () => {
    if (assinada === c.dataset.faction) return;
    assinar(c.dataset.faction);
  }));
  romper?.addEventListener('click', desfazer);
  /* Escolher um estado à mão lá em cima desfaz o contrato: o mundo volta ao que a carta diz. */
  doc.addEventListener('lp:estado', (ev) => { if (ev.detail?.manual && assinada) desfazer(); });
})();

/* ============================================== sinal do micélio (o Coro, §02) */
(function sinal() {
  const cv = doc.querySelector('[data-wave]');
  if (!cv || menosMovimento) return;
  const ctx = cv.getContext('2d');
  if (!ctx) return;
  const freq = doc.querySelector('[data-sig-freq]');
  const L = cv.width, A = cv.height, BARRAS = 84;
  let t = 0, rodando = false, raf = 0;

  /* NÃO é um player: nada toca. É a leitura de uma transmissão interceptada — o que o
     Instituto Icarus vê quando cruza as frequências e encontra padrão onde devia haver
     ruído. Por isso o desenho mistura ruído e um padrão que se repete. */
  function frame() {
    raf = 0;
    ctx.clearRect(0, 0, L, A);
    const cor = getComputedStyle(raiz).getPropertyValue('--ac').trim() || '155,224,74';
    const larg = L / BARRAS;
    for (let i = 0; i < BARRAS; i++) {
      const padrao = Math.sin((i * 0.35) + t) * Math.sin((i * 0.07) + t * 0.4);
      const ruido  = (Math.sin(i * 12.9898 + t * 0.6) * 43758.5453) % 1;
      const h = Math.max(2, (Math.abs(padrao) * 0.72 + Math.abs(ruido) * 0.28) * (A - 8));
      ctx.fillStyle = `rgba(${cor},${0.25 + Math.abs(padrao) * 0.6})`;
      ctx.fillRect(i * larg + larg * 0.22, (A - h) / 2, larg * 0.56, h);
    }
    t += 0.045;
    if (freq && (Math.round(t * 20) % 9) === 0) freq.textContent = (17.4 + Math.sin(t * 0.3) * 0.6).toFixed(1);
    if (rodando) raf = requestAnimationFrame(frame);
  }
  /* Só desenha enquanto a seção está à vista. */
  const obs = new IntersectionObserver((linhas) => {
    linhas.forEach((l) => {
      rodando = l.isIntersecting;
      if (rodando && !raf) raf = requestAnimationFrame(frame);
      if (!rodando && raf) { cancelAnimationFrame(raf); raf = 0; }
    });
  }, { threshold: 0.15 });
  obs.observe(cv);
})();

/* ======================================================================== galeria */
(function galeria() {
  const grade = doc.querySelector('[data-gallery]');
  const dlg = doc.querySelector('[data-lightbox]');
  if (!grade || !dlg || typeof dlg.showModal !== 'function') return;
  const shots = [...grade.querySelectorAll('[data-shot]')];
  const img = dlg.querySelector('[data-lb-img]');
  const cap = dlg.querySelector('[data-lb-cap]');
  let i = 0;

  const mostrar = (n) => {
    i = (n + shots.length) % shots.length;
    const s = shots[i];
    img.src = s.dataset.shot || s.href;
    img.alt = s.dataset.legenda || '';
    cap.textContent = s.dataset.legenda || '';
  };
  shots.forEach((s, n) => s.addEventListener('click', (ev) => {
    ev.preventDefault();
    mostrar(n);
    dlg.showModal();
  }));
  dlg.querySelector('[data-lb-close]')?.addEventListener('click', () => dlg.close());
  dlg.querySelector('[data-lb-prev]')?.addEventListener('click', () => mostrar(i - 1));
  dlg.querySelector('[data-lb-next]')?.addEventListener('click', () => mostrar(i + 1));
  dlg.addEventListener('click', (ev) => { if (ev.target === dlg) dlg.close(); });
  dlg.addEventListener('keydown', (ev) => {
    if (ev.key === 'ArrowLeft') mostrar(i - 1);
    if (ev.key === 'ArrowRight') mostrar(i + 1);
  });
})();
