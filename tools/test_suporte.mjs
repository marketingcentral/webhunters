/**
 * Suíte de teste da tela SUPORTE no navegador real.
 * Exercita o acordeão do FAQ (clique + teclado), os dois steppers, o contador,
 * o anexo, o POST inválido, o POST válido (que grava no banco) e a proteção CSRF.
 *   node tools/test_suporte.mjs
 * Sai com código 2 se qualquer caso falhar.
 */
import puppeteer from 'puppeteer-core';
import { existsSync } from 'node:fs';

const CHROME = ['C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
const BASE = 'http://localhost/extracthunters';
const results = [];
const check = (name, pass, detail = '') => { results.push({ name, pass }); console.log(`${pass ? 'OK   ' : 'FALHA'} ${name}${detail ? ' — ' + detail : ''}`); };

const browser = await puppeteer.launch({
  executablePath: CHROME, headless: true,
  args: ['--no-sandbox', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
  defaultViewport: { width: 1672, height: 941 },
});
const page = await browser.newPage();
const errs = [];
page.on('pageerror', (e) => errs.push('PAGEERROR ' + e.message.slice(0, 180)));
// 422 (formulário reexibido com erro de validação) e 419 (teste de CSRF abaixo) são esperados
page.on('console', (m) => { if (m.type() === 'error' && !/\b(419|422)\b/.test(m.text())) errs.push('CONSOLE ' + m.text().slice(0, 180)); });
page.on('requestfailed', (r) => errs.push('REQFAIL ' + r.url().replace(BASE, '')));
page.on('response', (r) => { const s = r.status(); if (s === 404 || s >= 500) errs.push(`HTTP ${s} ${r.url().replace(BASE, '')}`); });

const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const load = async (path = '/suporte') => {
  const resp = await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle2' });
  await wait(900);
  return resp.status();
};
const faqState = () => page.$$eval('.faq-item', (n) => n.map((i) => ({
  open: i.classList.contains('is-open'),
  aria: i.querySelector('.faq-item__q')?.getAttribute('aria-expanded'),
  h: Math.round(i.querySelector('.faq-item__a')?.getBoundingClientRect().height || 0),
})));

// ---------------------------------------------------------------- 1) carga
check('GET /suporte responde 200', (await load()) === 200);
check('cabeçalho e colunas renderizam', await page.$eval('.sup', (e) => !!e.querySelector('.sup__left') && !!e.querySelector('.sup__mid') && !!e.querySelector('.sup__right')));

// ------------------------------------------------------------- 2) acordeão
const n = await page.$$eval('.faq-item', (x) => x.length);
check('FAQ carregou perguntas do banco', n > 0, n + ' itens');
let s = await faqState();
await page.evaluate(() => document.querySelectorAll('.faq-item__q')[1].click());
await wait(700);
let s2 = await faqState();
check('clicar no FAQ 2 abre o 2 e fecha o 1',
  s2[1].open && s2[1].h > 10 && s2[1].aria === 'true' && !s2[0].open && s2[0].h < 5,
  `h1 ${s[0].h}->${s2[0].h} / h2 ${s[1].h}->${s2[1].h}`);
await page.evaluate(() => document.querySelectorAll('.faq-item__q')[1].click());
await wait(700);
check('reclique fecha (toggle)', (await faqState())[1].h < 5);
for (let i = 0; i < n; i++) {
  // garante que o item está fechado antes de medir a abertura (o acordeão é toggle)
  if ((await faqState())[i].open) {
    await page.evaluate((k) => document.querySelectorAll('.faq-item__q')[k].click(), i);
    await wait(600);
  }
  await page.evaluate((k) => document.querySelectorAll('.faq-item__q')[k].click(), i);
  await wait(700);
  const st = await faqState();
  const outros = st.filter((x, k) => k !== i && x.open).length;
  check(`FAQ ${i + 1} abre a resposta e fecha os demais`,
    st[i].open && st[i].h > 10 && outros === 0, `altura=${st[i].h}px, outros abertos=${outros}`);
}
await page.evaluate(() => document.querySelectorAll('.faq-item__q')[0].focus());
await page.keyboard.press('Enter');
await wait(800);
check('acordeão responde ao teclado (Enter)', (await faqState())[0].h > 10);

// ------------------------------------------------------ 3) stepper ASSUNTO
const subj = () => page.evaluate(() => ({
  val: document.querySelector('.sup-stepper[data-slugs] .stepper__val')?.textContent.trim(),
  hidden: document.querySelector('input[name=subject]')?.value,
  cat: document.querySelector('[data-category]')?.value,
  sla: document.querySelector('[data-sla]')?.textContent.trim(),
}));
const a0 = await subj();
await page.evaluate(() => document.querySelectorAll('.sup-stepper[data-slugs] .stepper__btn')[1].click());
await wait(400);
const a1 = await subj();
check('stepper ASSUNTO troca categoria + SLA',
  a1.val !== a0.val && a1.cat !== a0.cat && a1.sla !== a0.sla && a1.hidden === a1.val,
  `${a0.val}/${a0.cat}/${a0.sla} -> ${a1.val}/${a1.cat}/${a1.sla}`);
await page.evaluate(() => document.querySelectorAll('.sup-stepper[data-slugs] .stepper__btn')[0].click());
await wait(400);
check('stepper ASSUNTO volta para a opção anterior', (await subj()).val === a0.val);

// --------------------------------------------------- 4) stepper PRIORIDADE
const prio = () => page.evaluate(() => ({
  val: document.querySelectorAll('.sup-stepper')[1].querySelector('.stepper__val').textContent.trim(),
  lvl: document.querySelector('.sup-prio').dataset.prioLevel,
  hidden: document.querySelector('input[name=priority]').value,
}));
const p0 = await prio();
await page.evaluate(() => document.querySelectorAll('.sup-stepper')[1].querySelectorAll('.stepper__btn')[1].click());
await wait(400);
const p1 = await prio();
check('stepper PRIORIDADE move o medidor', p1.val !== p0.val && p1.lvl !== p0.lvl && p1.hidden === p1.val,
  `${p0.val}(n${p0.lvl}) -> ${p1.val}(n${p1.lvl})`);

// ------------------------------------------------------------ 5) contador
await page.click('.sup-textarea');
await page.type('.sup-textarea', 'teste');
await wait(250);
check('contador da descrição acompanha a digitação', (await page.$eval('[data-count-out]', (e) => e.textContent.trim())) === '5');
const c0 = await page.$eval('input[name=logs]', (e) => e.checked);
await page.evaluate(() => document.querySelector('.sup-attach').click());
await wait(200);
check('checkbox de anexo alterna', (await page.$eval('input[name=logs]', (e) => e.checked)) !== c0);

// ------------------------------------------------------- 6) POST inválido
await load();
const before = await page.$$eval('.tkt', (x) => x.length);
await page.evaluate(() => { document.querySelector('.sup-textarea').value = 'curto'; });
await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('.sup-form__send')]);
await wait(600);
check('POST inválido reexibe o formulário com erro', (await page.$$eval('.field__error', (x) => x.length)) > 0);
check('POST inválido preserva o texto digitado', (await page.$eval('.sup-textarea', (e) => e.value)) === 'curto');
check('POST inválido NÃO cria chamado', (await page.$$eval('.tkt', (x) => x.length)) === before, `${before} chamados`);

// --------------------------------------------------------- 7) POST válido
await load();
const antes = await page.$$eval('.tkt', (x) => x.length);
const proto = await page.$eval('.sup-meta__item:nth-child(2) .sup-meta__val', (e) => e.textContent.trim());
const texto = 'Cobranca duplicada na compra do pacote de creditos hoje as 14h. Recebi dois recibos.';
await page.evaluate((t) => { document.querySelector('.sup-textarea').value = t; }, texto);
await page.evaluate(() => document.querySelectorAll('.sup-stepper[data-slugs] .stepper__btn')[1].click());
await wait(300);
await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('.sup-form__send')]);
await wait(800);
const done = await page.evaluate(() => {
  const d = document.querySelector('.sup-done');
  return d ? { t: d.querySelector('.sup-done__title')?.textContent.trim(), m: d.querySelector('.sup-done__msg')?.textContent.trim() } : null;
});
check('POST válido mostra o painel de sucesso com flash', !!done && /Chamado #\d+ aberto/.test(done.m || ''), JSON.stringify(done));
check('protocolo do flash é o que estava na tela', !!done && done.m.includes(proto.replace('#', '')), `esperado ${proto}`);
const sla = (done?.m || '').match(/até (\d+)h/)?.[1];
const passo = await page.$$eval('.sup-done__step', (n) => n.map((e) => e.textContent.trim()).find((t) => /Prazo médio/.test(t)) || '');
check('prazo do painel bate com o SLA da categoria escolhida', !!sla && passo.includes(sla + ' horas'), `flash=${sla}h / painel="${passo}"`);
const depois = await page.$$eval('.tkt', (x) => x.length);
const topo = await page.$eval('.tkt', (e) => ({
  id: e.querySelector('.tkt__id').textContent.trim(),
  subj: e.querySelector('.tkt__subject').textContent.trim(),
  st: e.querySelector('.tkt__state').textContent.trim(),
}));
check('chamado novo encabeça MEUS CHAMADOS (persistiu no banco)', topo.id === proto && /ABERTO/.test(topo.st),
  `${topo.id} ${topo.subj} [${topo.st}] (lista ${antes}->${depois})`);
check('título do chamado sai da descrição, não da categoria', topo.subj.startsWith('Cobranca duplicada'), topo.subj);

// recarrega: o painel de sucesso é one-shot (flash consumido), o chamado continua
await load();
check('após recarregar, volta o formulário (flash consumido)', (await page.$$eval('.sup-form', (x) => x.length)) === 1);
check('após recarregar, o chamado continua na lista', (await page.$$eval('.tkt', (x) => x.length)) === depois);

// ---------------------------------------------------------------- 8) CSRF
const csrfStatus = await page.evaluate(async (base) => {
  const b = new URLSearchParams({ subject: 'CONTA E ACESSO', category: 'conta', priority: 'NORMAL', message: 'x'.repeat(40), csrf_token: 'invalido' });
  const r = await fetch(base + '/suporte', { method: 'POST', body: b, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
  return r.status;
}, BASE);
check('POST sem CSRF válido é recusado', csrfStatus === 419 || csrfStatus === 403 || csrfStatus === 400, 'status=' + csrfStatus);
await load();
check('CSRF recusado não gravou chamado', (await page.$$eval('.tkt', (x) => x.length)) === depois);

// --------------------------------------------------- 9) links e integridade
const dead = await page.$$eval('.sup a', (x) => x.filter((a) => !a.getAttribute('href') || a.getAttribute('href').trim() === '#').length);
check('nenhum href="#" morto', dead === 0);
// corte real = painel escondendo conteúdo; reticências (text-overflow) e listas roláveis são intencionais
const clip = await page.$$eval('.sup, .sup *', (x) => x.filter((el) => {
  const cs = getComputedStyle(el);
  if (el.clientHeight === 0 || cs.overflowY === 'auto' || cs.textOverflow === 'ellipsis') return false;
  return el.scrollHeight - el.clientHeight > 4 || el.scrollWidth - el.clientWidth > 4;
}).map((el) => el.className.toString().slice(0, 48) + ` (${el.scrollHeight}>${el.clientHeight})`));
check('nenhum painel cortando conteúdo', clip.length === 0, JSON.stringify(clip));
const hgap = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
check('sem scroll horizontal', hgap <= 1, 'delta=' + hgap);
check('zero erro de console / 404 / 500', errs.length === 0, JSON.stringify([...new Set(errs)].slice(0, 6)));

await browser.close();
const fail = results.filter((r) => !r.pass).length;
console.log(`\n${results.length - fail}/${results.length} passaram.`);
process.exit(fail ? 2 : 0);
