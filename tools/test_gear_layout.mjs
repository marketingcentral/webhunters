/**
 * Layout GEAR do inventário: geometria das quatro colunas e trilha de filtros do stash.
 * Complementa test_inventario.mjs (que cobre drag-drop, rotação e acessórios).
 *   node tools/test_gear_layout.mjs
 * Sai com código 2 se qualquer caso falhar.
 */
import puppeteer from 'puppeteer-core';
import { existsSync } from 'node:fs';

const CHROME = ['C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
const BASE = 'http://localhost/extracthunters';
let falhas = 0;
const check = (name, pass, detail = '') => {
  if (!pass) falhas++;
  console.log(`${pass ? 'OK  ' : 'FALHA'} ${name}${detail ? ' — ' + detail : ''}`);
};

const browser = await puppeteer.launch({
  executablePath: CHROME, headless: true,
  args: ['--no-sandbox', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
  defaultViewport: { width: 1672, height: 941 },
});
const page = await browser.newPage();
const load = async () => {
  await page.goto(`${BASE}/inventario`, { waitUntil: 'networkidle2' });
  await new Promise((r) => setTimeout(r, 2200));
};

await load();

// ---- 1) as quatro zonas do GEAR existem e estão na ordem esperada ----
const cols = await page.evaluate(() => {
  // DOMRect não sobrevive à serialização do evaluate (propriedades não enumeráveis)
  const box = (s) => {
    const e = document.querySelector(s); if (!e) return null;
    const r = e.getBoundingClientRect();
    return { left: r.left, right: r.right, width: r.width };
  };
  return {
    char: box('.inv2-col--char'), gear: box('.inv2-col--gear'),
    bags: box('.inv2-col--bags'), stash: box('.inv2-col--stash'),
  };
});
const ordem = ['char', 'gear', 'bags', 'stash'];
check('quatro colunas: personagem | equipamento | contêineres | stash',
  ordem.every((k) => cols[k]) &&
  ordem.slice(0, -1).every((k, i) => cols[k].right <= cols[ordem[i + 1]].left + 1),
  ordem.map((k) => (cols[k] ? Math.round(cols[k].width) : '—')).join(' / ') + ' px');

// ---- 1b) o box do personagem mostra a silhueta e não estica até o rodapé ----
const char = await page.evaluate(() => {
  const box = document.querySelector('.char');
  const img = document.querySelector('.char__body');
  const col = document.querySelector('.inv2-col--char');
  const gear = document.querySelector('.inv2-col--gear');
  if (!box || !img || !col || !gear) return null;
  return {
    carregou: img.complete && img.naturalWidth > 0,
    alturaBox: Math.round(box.getBoundingClientRect().height),
    fimChar: Math.round(col.getBoundingClientRect().bottom),
    fimGear: Math.round(gear.getBoundingClientRect().bottom),
  };
});
check('box do personagem com a silhueta, alinhado ao equipamento',
  !!char && char.carregou && Math.abs(char.fimChar - char.fimGear) <= 40,
  char ? `img=${char.carregou} box=${char.alturaBox}px fim char=${char.fimChar} gear=${char.fimGear}` : 'box ausente');

// ---- 2) os slots largos (bandoleira/costas) ficam LADO A LADO com coldre/bainha ----
const linhas = await page.evaluate(() => {
  const y = (k) => { const e = document.querySelector(`.gslot[data-slot="${k}"]`); return e ? Math.round(e.getBoundingClientRect().top) : null; };
  return { primary: y('primary'), pistol: y('pistol'), secondary: y('secondary'), knife: y('knife') };
});
check('arma primária e coldre na mesma linha; secundária e bainha na seguinte',
  linhas.primary !== null && Math.abs(linhas.primary - linhas.pistol) <= 2 &&
  Math.abs(linhas.secondary - linhas.knife) <= 2 && linhas.secondary > linhas.primary,
  JSON.stringify(linhas));

// ---- 3) slot preenchido não empilha o rótulo do slot sobre o nome do item ----
const rotulo = await page.evaluate(() => {
  const s = document.querySelector('.gslot.is-filled:not(.gslot--panel)');
  if (!s) return null;
  const lbl = s.querySelector('.gslot__label');
  return { escondido: !lbl || getComputedStyle(lbl).display === 'none', temNome: !!s.querySelector('.item__name') };
});
check('slot preenchido mostra só o nome do item', !!rotulo && rotulo.escondido && rotulo.temNome,
  rotulo ? JSON.stringify(rotulo) : 'nenhum slot preenchido');

// ---- 4) BOLSOS: zona própria de 4 células ----
const bolsos = await page.evaluate(() => {
  const g = document.querySelector('[data-zone="pockets"]');
  return g ? { celulas: g.querySelectorAll('.grid__cell').length, itens: g.querySelectorAll('.item').length } : null;
});
check('BOLSOS com 4 células', !!bolsos && bolsos.celulas === 4, bolsos ? `${bolsos.celulas} células, ${bolsos.itens} itens` : 'zona ausente');

// ---- 5) barra de vitais com peso/saúde/hidratação/energia ----
const vitais = await page.evaluate(() => [...document.querySelectorAll('.vitals__v')].map((e) => e.textContent.trim()));
check('barra de vitais com os quatro medidores', vitais.length === 4 && /\d/.test(vitais[0]), vitais.join(' · '));

// ---- 6) nada vaza horizontalmente, em duas resoluções ----
for (const [w, h] of [[1672, 941], [1366, 768]]) {
  await page.setViewport({ width: w, height: h });
  await load();
  const geo = await page.evaluate(() => {
    const d = document.documentElement;
    const inv = document.querySelector('.inv2').getBoundingClientRect();
    const stash = document.querySelector('.grid--stash').getBoundingClientRect();
    const bag = document.querySelector('[data-parent-slot="backpack"]');
    const body = document.querySelector('[data-container-panel="backpack"] .bag__body');
    return {
      rolagem: d.scrollWidth > d.clientWidth + 1,
      stashDentro: stash.right <= inv.right + 1,
      mochilaDentro: !bag || bag.hidden || bag.getBoundingClientRect().right <= body.getBoundingClientRect().right + 1,
    };
  });
  check(`${w}x${h}: sem rolagem horizontal`, !geo.rolagem);
  check(`${w}x${h}: grades dentro dos painéis`, geo.stashDentro && geo.mochilaDentro,
    `stash=${geo.stashDentro} mochila=${geo.mochilaDentro}`);
}

// ---- 6b) o painel do stash acompanha a grade (sem fundo sobrando embaixo) ----
await page.setViewport({ width: 1672, height: 941 });
await load();
const sobra = await page.evaluate(() => {
  const p = document.querySelector('.stash').getBoundingClientRect();
  const g = document.querySelector('.grid--stash').getBoundingClientRect();
  return Math.round(p.bottom - g.bottom);
});
check('painel do stash sem fundo sobrando abaixo da grade', sobra <= 16, `${sobra}px de folga`);

// ---- 7) trilha de filtros ----
await page.setViewport({ width: 1672, height: 941 });
await load();
const antes = await page.evaluate(() => document.querySelectorAll('.grid--stash .item.is-dimmed').length);
await page.click('[data-filter="ammo"]');
await new Promise((r) => setTimeout(r, 300));
const dep = await page.evaluate(() => {
  const todos = [...document.querySelectorAll('.grid--stash .item')];
  const acesos = todos.filter((i) => !i.classList.contains('is-dimmed'));
  return { total: todos.length, acesos: acesos.length, cats: [...new Set(acesos.map((i) => i.dataset.cat))] };
});
check('filtro MUNIÇÃO acende só munição/carregadores',
  antes === 0 && dep.acesos > 0 && dep.acesos < dep.total && dep.cats.every((c) => ['ammo', 'mag'].includes(c)),
  `antes=${antes} acesos=${dep.acesos}/${dep.total} cats=${dep.cats.join(',')}`);

await page.click('[data-filter="all"]');
await new Promise((r) => setTimeout(r, 300));
const volta = await page.evaluate(() => document.querySelectorAll('.grid--stash .item.is-dimmed').length);
check('filtro TUDO reacende tudo', volta === 0, `${volta} apagados`);

await browser.close();
console.log(falhas === 0 ? '\nLayout GEAR: todos os casos passaram.' : `\n${falhas} falha(s).`);
process.exit(falhas ? 2 : 0);
