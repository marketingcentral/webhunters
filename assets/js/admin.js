/**
 * Casca do painel administrativo (layouts/admin.php).
 *
 * Só o que a casca precisa: popovers da topbar, trilho da sidebar e as mensagens
 * de flash. Nada de estado de tela — cada tela do admin carrega o seu js/admin/<slug>.js.
 */

const doc = document;

/* ---- popovers (sino, menu do administrador) ------------------------------
   Um de cada vez: dois painéis abertos ao mesmo tempo escondem conteúdo. */
const popTriggers = [...doc.querySelectorAll('[data-adm-pop]')];

function fecharPops(exceto) {
  for (const btn of popTriggers) {
    if (btn === exceto) continue;
    btn.setAttribute('aria-expanded', 'false');
    const alvo = doc.getElementById(btn.dataset.admPop);
    if (alvo) alvo.hidden = true;
  }
}

for (const btn of popTriggers) {
  const alvo = doc.getElementById(btn.dataset.admPop);
  if (!alvo) continue;
  btn.addEventListener('click', (ev) => {
    ev.stopPropagation();
    const abrindo = alvo.hidden;
    fecharPops(btn);
    alvo.hidden = !abrindo;
    btn.setAttribute('aria-expanded', abrindo ? 'true' : 'false');
  });
  alvo.addEventListener('click', (ev) => ev.stopPropagation());
}

if (popTriggers.length) {
  doc.addEventListener('click', () => fecharPops(null));
  doc.addEventListener('keydown', (ev) => {
    if (ev.key !== 'Escape') return;
    const aberto = popTriggers.find((b) => b.getAttribute('aria-expanded') === 'true');
    fecharPops(null);
    if (aberto) aberto.focus();
  });
}

/* ---- trilho da sidebar ---------------------------------------------------
   A escolha fica no navegador do operador: quem passa o dia numa tabela larga
   não quer reabrir o menu a cada navegação. */
const CHAVE_TRILHO = 'eh.admin.rail';
const btnTrilho = doc.querySelector('[data-adm-rail]');

try {
  if (localStorage.getItem(CHAVE_TRILHO) === '1') doc.body.classList.add('is-rail');
} catch { /* modo privado: segue sem lembrar */ }

btnTrilho?.addEventListener('click', () => {
  const ligado = doc.body.classList.toggle('is-rail');
  btnTrilho.setAttribute('aria-label', ligado ? 'Expandir menu' : 'Recolher menu');
  try { localStorage.setItem(CHAVE_TRILHO, ligado ? '1' : '0'); } catch { /* idem */ }
});

/* ---- tema (escuro / claro) -----------------------------------------------
   O estado real é o atributo data-tema no <html>; quem o escreve PRIMEIRO é o
   script inline do <head> (layouts/admin.php), antes da primeira pintura. Este
   módulo é adiado (type=module), então chega depois: aqui só sincronizamos os
   botões, gravamos a escolha e mantemos a cor da barra do navegador junto.

   Regra do dono: o palpite do sistema (prefers-color-scheme) só vale enquanto
   NÃO houver escolha explícita. Depois que o operador clica, a escolha dele
   vence — inclusive se o sistema mudar de tema no meio do expediente. */
const CHAVE_TEMA = 'eh.admin.tema';
const raiz = doc.documentElement;
const btnsTema = [...doc.querySelectorAll('[data-adm-tema]')];
/* A cor da barra do navegador acompanha o fundo real do painel — sem isto o
   celular/PWA desenha uma faixa preta em cima de uma tela clara. */
const CORES_BARRA = { escuro: '#070907', claro: '#e8ece2' };

function marcarTema(tema) {
  for (const btn of btnsTema) {
    btn.setAttribute('aria-pressed', btn.dataset.admTema === tema ? 'true' : 'false');
  }
  const meta = doc.querySelector('meta[name="theme-color"]');
  if (meta) meta.setAttribute('content', CORES_BARRA[tema] || CORES_BARRA.escuro);
}

function aplicarTema(tema, salvar) {
  const alvo = tema === 'claro' ? 'claro' : 'escuro';
  raiz.setAttribute('data-tema', alvo);
  marcarTema(alvo);
  if (salvar) {
    try { localStorage.setItem(CHAVE_TEMA, alvo); } catch { /* modo privado: vale só nesta aba */ }
  }
}

if (btnsTema.length) {
  marcarTema(raiz.getAttribute('data-tema') === 'claro' ? 'claro' : 'escuro');
  for (const btn of btnsTema) {
    btn.addEventListener('click', () => aplicarTema(btn.dataset.admTema, true));
  }
}

/* O sistema mudou de tema e o operador nunca escolheu: seguimos o palpite. */
const consultaTema = window.matchMedia?.('(prefers-color-scheme: light)');
consultaTema?.addEventListener?.('change', (ev) => {
  let escolhido = null;
  try { escolhido = localStorage.getItem(CHAVE_TEMA); } catch { /* idem */ }
  if (escolhido === 'claro' || escolhido === 'escuro') return;
  aplicarTema(ev.matches ? 'claro' : 'escuro', false);
});

/* ---- flash ---------------------------------------------------------------- */
for (const flash of doc.querySelectorAll('.adm-flash[data-flash]')) {
  const fechar = () => {
    flash.style.transition = 'opacity 200ms linear';
    flash.style.opacity = '0';
    setTimeout(() => flash.remove(), 220);
  };
  flash.querySelector('[data-adm-close]')?.addEventListener('click', fechar);
  const espera = Number.parseInt(flash.dataset.autohide || '0', 10);
  if (espera > 0) setTimeout(fechar, espera);
}
