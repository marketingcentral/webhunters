/**
 * Suíte de teste do inventário Tarkov no navegador real.
 * Exercita TODOS os caminhos de interação, não só um arraste.
 *   node tools/test_inventario.mjs
 * Sai com código 2 se qualquer caso falhar.
 *
 * ATENÇÃO: esta suíte NÃO é idempotente. Ela arrasta itens do stash para o rig e para os
 * bolsos, e isso PERSISTE no banco. Rodar duas vezes seguidas faz a segunda falhar em
 * "mover item para dentro do rig" e "rotação com R" — não porque quebrou, mas porque o
 * RÁDIO e a BÚSSOLA já não estão mais no stash. Antes de acreditar numa falha dessas,
 * confira onde os itens estão (GET /inventario/dados) e devolva-os ao stash.
 */
import puppeteer from 'puppeteer-core';
import { existsSync } from 'node:fs';

const CHROME = ['C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
const BASE = 'http://localhost/extracthunters';
const results = [];
const check = (name, pass, detail = '') => { results.push({ name, pass, detail }); console.log(`${pass ? 'OK  ' : 'FALHA'} ${name}${detail ? ' — ' + detail : ''}`); };

const browser = await puppeteer.launch({
  executablePath: CHROME, headless: true,
  args: ['--no-sandbox', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
  defaultViewport: { width: 1672, height: 941 },
});
const page = await browser.newPage();
const errs = [];
page.on('pageerror', (e) => errs.push('PAGEERROR ' + e.message.slice(0, 160)));
page.on('console', (m) => { if (m.type() === 'error') errs.push('CONSOLE ' + m.text().slice(0, 160)); });
page.on('response', (r) => { if (r.status() >= 500) errs.push(`HTTP ${r.status()} ${r.url().replace(BASE, '')}`); });

const load = async () => {
  await page.goto(`${BASE}/inventario`, { waitUntil: 'networkidle2' });
  await new Promise((r) => setTimeout(r, 2200));
};
const centre = (sel, idx = 0) => page.evaluate((s, i) => {
  const el = document.querySelectorAll(s)[i]; if (!el) return null;
  const r = el.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
}, sel, idx);
const drag = async (from, to, { rotate = false } = {}) => {
  await page.mouse.move(from.x, from.y); await page.mouse.down();
  for (let i = 1; i <= 12; i++) {
    await page.mouse.move(from.x + (to.x - from.x) * i / 12, from.y + (to.y - from.y) * i / 12);
    await new Promise((r) => setTimeout(r, 20));
  }
  if (rotate) { await page.keyboard.press('r'); await new Promise((r) => setTimeout(r, 120)); }
  await page.mouse.up(); await new Promise((r) => setTimeout(r, 900));
};
const named = (name) => page.evaluate((n) => {
  const el = [...document.querySelectorAll('.grid--stash .item')].find((i) => i.querySelector('.item__name')?.textContent.includes(n));
  if (!el) return null; const r = el.getBoundingClientRect();
  return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
}, name);

await load();

// ---- 1) proporção do item equipado (não pode achatar) ----
// O loadout inicial já traz a AK-103 na bandoleira, então o painel deve existir de saída.
// arma equipada = painel completo (.wslot): nome, calibre, badge e arte sem distorção
const eq = await page.evaluate(() => {
  const s = document.querySelector('.gslot[data-slot="primary"] .wslot'); if (!s) return null;
  const img = s.querySelector('.wslot__img'); if (!img) return null;
  const r = img.getBoundingClientRect();
  const natural = img.naturalWidth && img.naturalHeight ? img.naturalWidth / img.naturalHeight : null;
  return {
    ratio: +(r.width / r.height).toFixed(2), natural: natural ? +natural.toFixed(2) : null,
    nome: s.querySelector('.wslot__name')?.textContent,
    cal: s.querySelector('.wslot__cal')?.textContent,
    badge: s.querySelector('.wslot__key')?.textContent,
    mods: s.querySelector('.wslot__mods')?.textContent ?? null,
    semMiniGrids: s.querySelectorAll('.att').length === 0,
  };
});
// A arte não distorce porque o <img> usa object-fit:contain (a caixa pode ser mais larga
// que a arte; o contain apenas centraliza). Medir a caixa seria um falso indicador —
// o que garante ausência de achatamento é o object-fit.
const fit = await page.evaluate(() => {
  const img = document.querySelector('.gslot[data-slot="primary"] .wslot__img');
  return img ? getComputedStyle(img).objectFit : null;
});
check('arma equipada: painel completo e arte sem distorção',
  !!eq && !!eq.nome && !!eq.badge && eq.semMiniGrids && !!eq.mods && fit === 'contain',
  eq ? `${eq.nome} ${eq.cal} badge=${eq.badge} mods=${eq.mods} semMiniGrids=${eq.semMiniGrids}` : 'nada equipado');

// ---- 2) mover item para célula vazia do stash ----
await load();
// procura uma REGIÃO livre do tamanho da peça (uma célula solta não serve para um item 2x1)
const target = await page.evaluate((itemName) => {
  const grid = document.querySelector('.grid--stash');
  const cols = +grid.dataset.cols, rows = +grid.dataset.rows;
  const cells = [...grid.querySelectorAll('.grid__cell')];
  const items = [...grid.querySelectorAll('.item')];
  const drag = items.find((i) => i.querySelector('.item__name')?.textContent.includes(itemName));
  if (!drag || !cells.length) return null;
  const cw = +getComputedStyle(drag).getPropertyValue('--w') || 1;
  const ch = +getComputedStyle(drag).getPropertyValue('--h') || 1;
  const cell0 = cells[0].getBoundingClientRect();
  const step = cells[1] ? cells[1].getBoundingClientRect().x - cell0.x : cell0.width;
  // ocupação em células, ignorando a própria peça
  const occ = new Set();
  for (const i of items) {
    if (i === drag) continue;
    const r = i.getBoundingClientRect();
    const x0 = Math.round((r.x - cell0.x) / step), y0 = Math.round((r.y - cell0.y) / step);
    const w = +getComputedStyle(i).getPropertyValue('--w') || 1, h = +getComputedStyle(i).getPropertyValue('--h') || 1;
    for (let dy = 0; dy < h; dy++) for (let dx = 0; dx < w; dx++) occ.add(`${y0 + dy}:${x0 + dx}`);
  }
  for (let y = 0; y <= rows - ch; y++) {
    for (let x = 0; x <= cols - cw; x++) {
      let free = true;
      for (let dy = 0; dy < ch && free; dy++) for (let dx = 0; dx < cw; dx++) if (occ.has(`${y + dy}:${x + dx}`)) { free = false; break; }
      if (free) return { x: cell0.x + x * step + (cw * step) / 2 - step * 0.5 + cell0.width / 2,
                         y: cell0.y + y * step + (ch * step) / 2 - step * 0.5 + cell0.height / 2, cell: [x, y] };
    }
  }
  return null;
}, 'MAG AK');
const knife = await named('MAG AK');
if (target && knife) {
  await drag(knife, target);
  const moved = await page.evaluate((tx, ty) => {
    const el = [...document.querySelectorAll('.grid--stash .item')].find((i) => i.querySelector('.item__name')?.textContent.includes('MAG AK'));
    if (!el) return false; const r = el.getBoundingClientRect();
    return tx >= r.x - 4 && tx <= r.x + r.width + 4 && ty >= r.y - 4 && ty <= r.y + r.height + 4;
  }, target.x, target.y);
  const toast = await page.evaluate(() => { const t = document.querySelector('[data-toast]'); return t && !t.hidden ? t.textContent : null; });
  check('mover item para célula vazia', moved && !toast, toast ? `toast="${toast}"` : 'item ocupou o destino');
} else check('mover item para célula vazia', false, 'sem célula livre ou item');

// ---- 3) equipar container e mover item para dentro dele ----
await load();
// o colete tático já vem equipado no loadout inicial: a sub-grade tem de estar aberta de saída
const rigGrid = await page.evaluate(() => {
  const g = document.querySelector('[data-parent-slot="rig"]'); if (!g || g.closest('[hidden]')) return null;
  const c = g.querySelector('.grid__cell'); if (!c) return null; const r = c.getBoundingClientRect();
  return { x: r.x + r.width / 2, y: r.y + r.height / 2, cells: g.querySelectorAll('.grid__cell').length };
});
check('equipar rig abre sub-grade de container', !!rigGrid, rigGrid ? `${rigGrid.cells} células` : 'grade do rig não apareceu');
if (rigGrid) {
  const band = await named('RÁDIO') || await named('BÚSSOLA');
  if (band) {
    await drag(band, rigGrid);
    const inside = await page.evaluate(() => document.querySelectorAll('[data-parent-slot="rig"] .item').length);
    check('mover item para dentro do rig', inside > 0, `${inside} item(ns) no rig`);
  } else check('mover item para dentro do rig', false, 'nenhum item pequeno no stash');
}

// ---- 4) acessórios pelo modal: botão direito -> ANEXAR À ARMA -> instalar ----
await load();
await page.evaluate(() => {
  const el = [...document.querySelectorAll('.grid--stash .item')].find((i) => i.querySelector('.item__name')?.textContent.includes('AK-103'));
  el?.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
});
await new Promise((r) => setTimeout(r, 1200));
await page.evaluate(() => {
  const s = document.querySelector('.wslot'); const r = s.getBoundingClientRect();
  s.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: r.x + 60, clientY: r.y + 25 }));
});
await new Promise((r) => setTimeout(r, 450));
const temAnexar = await page.evaluate(() =>
  [...document.querySelectorAll('.ctxmenu__item')].some((b) => /Anexar/i.test(b.textContent) && !b.disabled));
check('menu do botão direito tem ANEXAR À ARMA ativo', temAnexar);
await page.evaluate(() => { [...document.querySelectorAll('.ctxmenu__item')].find((b) => /Anexar/i.test(b.textContent))?.click(); });
await new Promise((r) => setTimeout(r, 700));
const modal = await page.evaluate(() => {
  const m = document.querySelector('[data-pick]');
  return m && !m.hidden ? { bocais: m.querySelectorAll('.pick__slot').length, cands: m.querySelectorAll('.pick-card').length } : null;
});
check('modal de acessórios abre com bocais e candidatos',
  !!modal && modal.bocais >= 3 && modal.cands > 0, modal ? `${modal.bocais} bocais, ${modal.cands} candidato(s)` : 'modal não abriu');
if (modal?.cands) {
  await page.evaluate(() => document.querySelector('.pick-card')?.click());
  await new Promise((r) => setTimeout(r, 1500));
  const mods = await page.evaluate(() => document.querySelector('.wslot__mods')?.textContent);
  check('instalar acessório pelo modal atualiza o contador', !!mods && !mods.startsWith('0/'), `MODS ${mods}`);
}

// ---- 4c) arrastar para os BOLSOS (célula livre da zona de 4x1) ----
await load();
const pocketFree = await page.evaluate(() => {
  const g = document.querySelector('[data-zone="pockets"]');
  if (!g) return null;
  const usadas = new Set([...g.querySelectorAll('.item')].map((i) => +getComputedStyle(i).getPropertyValue('--x')));
  const cells = [...g.querySelectorAll('.grid__cell')];
  for (let x = 0; x < cells.length; x++) {
    if (usadas.has(x)) continue;
    const r = cells[x].getBoundingClientRect();
    return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
  }
  return null;
});
const pequeno = await named('BÚSSOLA') || await named('MULTITOOL');
if (pocketFree && pequeno) {
  await drag(pequeno, pocketFree);
  const n = await page.evaluate(() => document.querySelectorAll('[data-zone="pockets"] .item').length);
  const toastP = await page.evaluate(() => { const t = document.querySelector('[data-toast]'); return t && !t.hidden ? t.textContent : null; });
  check('arrastar item do stash para os BOLSOS', n >= 3 && !toastP, toastP ? `toast="${toastP}"` : `${n} itens nos bolsos`);
} else check('arrastar item do stash para os BOLSOS', false, 'sem célula livre ou item pequeno');

// ---- 5) contadores: munição / pilha / usos ----
await load();
const counts = await page.evaluate(() => {
  const items = [...document.querySelectorAll('.grid--stash .item')];
  const withCount = items.filter((i) => i.querySelector('.item__count'));
  return { total: items.length, com: withCount.length,
    exemplos: withCount.slice(0, 6).map((i) => `${i.querySelector('.item__name').textContent}=${i.querySelector('.item__count').textContent}`),
    sem: items.filter((i) => !i.querySelector('.item__count')).map((i) => i.querySelector('.item__name').textContent) };
});
check('maioria dos itens mostra contador', counts.com >= counts.total * 0.5, `${counts.com}/${counts.total} — ${counts.exemplos.join(' ')} | sem: ${counts.sem.join(',')}`);

// ---- 6) rotação durante o arraste ----
await load();
// Alvo calculado como o motor calcula: gx = (clientX - grab.dx - origem) / passo, e o
// grab é o CENTRO da peça (é onde named() clica). Mirar no centro da região livre erra
// por uma célula. Procuro uma região livre de 2x1 — a forma do carregador DEPOIS de girar.
const alvoRot = await page.evaluate((nome) => {
  const grid = document.querySelector('.grid--stash');
  const cols = +grid.dataset.cols, rows = +grid.dataset.rows;
  const cells = [...grid.querySelectorAll('.grid__cell')];
  const items = [...grid.querySelectorAll('.item')];
  const alvo = items.find((i) => i.querySelector('.item__name')?.textContent.includes(nome));
  if (!alvo || !cells.length) return null;

  const gr = grid.getBoundingClientRect();
  const pad = parseFloat(getComputedStyle(grid).paddingLeft) || 0;
  const c0 = cells[0].getBoundingClientRect();
  const step = cells[1] ? cells[1].getBoundingClientRect().x - c0.x : c0.width;

  const occ = new Set();
  for (const i of items) {
    if (i === alvo) continue;                        // a própria peça libera o lugar dela
    const r = i.getBoundingClientRect();
    const x0 = Math.round((r.x - c0.x) / step), y0 = Math.round((r.y - c0.y) / step);
    const w = +getComputedStyle(i).getPropertyValue('--w') || 1;
    const h = +getComputedStyle(i).getPropertyValue('--h') || 1;
    for (let dy = 0; dy < h; dy++) for (let dx = 0; dx < w; dx++) occ.add(`${y0 + dy}:${x0 + dx}`);
  }
  let celula = null;
  for (let y = 0; y < rows && !celula; y++) {
    for (let x = 0; x <= cols - 2; x++) {
      if (!occ.has(`${y}:${x}`) && !occ.has(`${y}:${x + 1}`)) { celula = { x, y }; break; }
    }
  }
  if (!celula) return null;

  const ar = alvo.getBoundingClientRect();
  return {
    de: { x: ar.x + ar.width / 2, y: ar.y + ar.height / 2 },
    // canto do destino + o mesmo deslocamento de pegada que o motor vai descontar
    para: {
      x: gr.left + pad + celula.x * step + ar.width / 2,
      y: gr.top + pad + celula.y * step + ar.height / 2,
    },
    celula,
  };
}, 'MAG AK');

if (alvoRot) {
  await drag(alvoRot.de, alvoRot.para, { rotate: true });
  const rot = await page.evaluate(() => {
    const el = [...document.querySelectorAll('.grid--stash .item')]
      .find((i) => i.querySelector('.item__name')?.textContent.includes('MAG AK')
        && getComputedStyle(i).getPropertyValue('--rot').trim() === '90deg');
    if (!el) return null;
    const r = el.getBoundingClientRect();
    const img = el.querySelector('.item__img');
    const t = getComputedStyle(img).transform;        // matriz com rotação de 90°
    return {
      deitado: r.width > r.height,
      imgGirada: t !== 'none' && /matrix/.test(t) && Math.abs(parseFloat(t.split('(')[1])) < 0.01,
    };
  });
  const toastR = await page.evaluate(() => { const t = document.querySelector('[data-toast]'); return t && !t.hidden ? t.textContent : null; });
  check('rotação com R gira a célula E a arte', !!rot && rot.deitado && rot.imgGirada,
    rot ? `deitado=${rot.deitado} arteGirada=${rot.imgGirada}`
        : `nenhum item girado${toastR ? ` (toast="${toastR}")` : ''} alvo=${alvoRot.celula.x},${alvoRot.celula.y}`);
} else check('rotação com R gira a célula E a arte', false, 'sem região livre de 2x1 no stash');

check('sem erros de console / 500', errs.length === 0, errs.slice(0, 4).join(' | '));

await page.screenshot({ path: 'captures/inventario-teste.png' });
await browser.close();
const failed = results.filter((r) => !r.pass);
console.log(`\n${results.length - failed.length}/${results.length} casos passaram.`);
process.exit(failed.length ? 2 : 0);
