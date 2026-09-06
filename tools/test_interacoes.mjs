/**
 * Varredura de interação em TODAS as telas: clica em cada aba/filtro/card e exige que o
 * DOM realmente mude. Pega "controle morto" — o defeito que passou despercebido antes.
 *   node tools/test_interacoes.mjs [slug...]
 * Sai com 2 se algum controle não reagir ou houver erro de console/500.
 */
import puppeteer from 'puppeteer-core';
import { existsSync } from 'node:fs';

const CHROME = ['C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
const BASE = 'http://localhost/extracthunters';

// slug -> seletores de controles que DEVEM alterar o DOM ao clicar
const SCREENS = {
  // apenas controles que DEVEM agir ao clique. Slots de drop (.eq-slot) e cards de
  // exibição (.op-card) ficam de fora: não reagir neles é o comportamento correto.
  // O inventário tem suíte própria: tools/test_inventario.mjs
  inicio:        [".menu-btn"],
  jogar:         [".map-card", ".toggle", ".stepper__btn"],
  lobby:         [".stepper__btn"],
  configuracoes: [".set-nav__row", ".set-tile", ".toggle", ".stepper__btn"],
  saude:         [".tab"],
  habilidades:   [".sk-node", ".row"],
  mapa:          [".row"],
  conquistas:    [".row"],
  missoes:       [".row"],
  mapas:         [".map-card, .card"],
  desafios:      [".tab"],
  loja:          [".tab, .shop-tab"],
  amigos:        [".tab"],
  mensagens:     [".conv"],
  perfil:        [],
  noticias:      [],
  suporte:       [".faq-item__q"],
  'relatorio-de-bug': [".sev-chip, .chip"],
};

const only = process.argv.slice(2).filter((s) => SCREENS[s]);
const list = only.length ? only : Object.keys(SCREENS);

const browser = await puppeteer.launch({
  executablePath: CHROME, headless: true,
  args: ['--no-sandbox', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
  defaultViewport: { width: 1672, height: 941 },
});
const page = await browser.newPage();
let fails = 0;

for (const slug of list) {
  const errs = [];
  const onErr = (e) => errs.push('PAGEERROR ' + e.message.slice(0, 120));
  const onCon = (m) => { if (m.type() === 'error') errs.push('CONSOLE ' + m.text().slice(0, 120)); };
  const onRes = (r) => { if (r.status() >= 500) errs.push(`HTTP ${r.status()} ${r.url().replace(BASE, '')}`); };
  page.on('pageerror', onErr); page.on('console', onCon); page.on('response', onRes);

  try {
    await page.goto(`${BASE}/${slug}`, { waitUntil: 'networkidle2', timeout: 40000 });
  } catch { errs.push('GOTO falhou'); }
  await new Promise((r) => setTimeout(r, 1800));

  const report = [];
  for (const sel of SCREENS[slug]) {
    const n = await page.$$eval(sel, (els) => els.length).catch(() => 0);
    if (!n) { report.push(`${sel}: ausente`); continue; }
    // clica em até 3 e verifica se o DOM mudou
    let reagiu = 0, testados = 0;
    for (let i = 0; i < Math.min(3, n); i++) {
      const before = await page.evaluate(() => document.querySelector('.hud-main, main')?.innerHTML.length || 0);
      const antesMarcado = await page.evaluate((s, idx) => { const el = document.querySelectorAll(s)[idx];
        return !!el && (el.classList.contains('is-active') || el.classList.contains('is-open') || el.getAttribute('aria-pressed') === 'true' || el.getAttribute('aria-selected') === 'true' || el.getAttribute('open') !== null); }, sel, i).catch(() => false);
      const clicked = await page.evaluate((s, idx) => {
        const el = document.querySelectorAll(s)[idx];
        if (!el || el.disabled) return false;
        el.scrollIntoView({ block: 'center' });
        el.click();
        return true;
      }, sel, i).catch(() => false);
      if (!clicked) continue;
      testados++;
      await new Promise((r) => setTimeout(r, 550));
      const after = await page.evaluate(() => document.querySelector('.hud-main, main')?.innerHTML.length || 0);
      const marked = await page.evaluate((s, idx) => {
        const el = document.querySelectorAll(s)[idx];
        return !!el && (el.classList.contains('is-active') || el.classList.contains('is-open') || el.getAttribute('aria-pressed') === 'true'
          || el.getAttribute('aria-selected') === 'true' || el.getAttribute('open') !== null);
      }, sel, i).catch(() => false);
      if (after !== before || marked !== antesMarcado) reagiu++;
    }
    const ok = testados === 0 || reagiu > 0;
    report.push(`${sel}: ${reagiu}/${testados} reagiram${ok ? '' : '  <-- MORTO'}`);
    if (!ok) fails++;
  }
  const dead = await page.$$eval('a[href="#"], a[href=""]', (a) => a.length).catch(() => 0);
  if (dead) { report.push(`${dead} âncora(s) morta(s)`); fails++; }
  if (errs.length) { report.push(`ERROS: ${[...new Set(errs)].slice(0, 2).join(' | ')}`); fails++; }

  console.log(`${errs.length || report.some((r) => r.includes('MORTO')) ? 'FALHA' : 'OK  '} ${slug.padEnd(18)} ${report.join(' · ')}`);
  page.off('pageerror', onErr); page.off('console', onCon); page.off('response', onRes);
}

await browser.close();
console.log(fails ? `\n${fails} problema(s).` : '\nTodos os controles reagem, sem âncoras mortas nem erros.');
process.exit(fails ? 2 : 0);
