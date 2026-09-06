/**
 * Suíte de interação da tela MENSAGENS. Clica em tudo e confere que o DOM mudou.
 *   node tools/test_mensagens.mjs
 */
import puppeteer from 'puppeteer-core';
import { execFileSync } from 'node:child_process';

const PHP = 'C:/wamp64/bin/php/php8.3.28/php.exe';
const reseed = () => execFileSync(PHP, ['db/seed_messages.php'], { cwd: 'C:/wamp64/www/extracthunters' }).toString().trim();
const sql = (q) => execFileSync(PHP, ['-r', `require "app/bootstrap.php"; echo json_encode(App\\Core\\Database::all(${JSON.stringify(q)}));`], { cwd: 'C:/wamp64/www/extracthunters' }).toString();

const BASE = 'http://localhost/extracthunters';
const results = [];
const check = (name, pass, detail = '') => { results.push({ name, pass }); console.log(`${pass ? 'OK   ' : 'FALHA'} ${name}${detail ? ' — ' + detail : ''}`); };

const browser = await puppeteer.launch({
  executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true,
  args: ['--no-sandbox', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
  defaultViewport: { width: 1672, height: 941 }, userDataDir: 'C:/Users/extra/AppData/Local/Temp/pptr-msgtest',
});
const page = await browser.newPage();
const errs = [];
page.on('pageerror', (e) => errs.push('PAGEERROR ' + e.message.slice(0, 200)));
page.on('console', (m) => { if (m.type() === 'error') errs.push('CONSOLE ' + m.text().slice(0, 200)); });
page.on('requestfailed', (r) => errs.push('REQFAIL ' + r.url().replace(BASE, '') + ' ' + (r.failure()?.errorText || '')));
page.on('response', (r) => { if (r.status() >= 400) errs.push(`HTTP ${r.status()} ${r.url().replace(BASE, '')}`); });

const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const go = async (path = '/mensagens') => { await page.goto(BASE + path, { waitUntil: 'networkidle2' }); await wait(1400); };
const state = () => page.evaluate(() => {
  const qa = (s) => [...document.querySelectorAll(s)];
  const q = (s) => document.querySelector(s);
  const open = qa('.thread').find((t) => !t.hidden);
  return {
    url: location.pathname,
    openThread: open?.dataset.thread || null,
    openFrom: open?.querySelector('.thread__from')?.textContent.trim().split('\n')[0] || null,
    openMsgs: open ? [...open.querySelectorAll('.chat__msg')].map((m) => m.querySelector('.chat__text')?.textContent.trim()) : [],
    seps: open ? [...open.querySelectorAll('.chat__sep')].map((s) => s.textContent.trim()) : [],
    subject: open?.querySelector('.thread__subject-text')?.textContent.trim() || null,
    subjectDate: open?.querySelector('.thread__subject-date')?.textContent.trim() || null,
    activeConv: qa('.conv').find((c) => c.classList.contains('is-active'))?.dataset.conv || null,
    visible: qa('.conv').filter((c) => !c.parentElement.hidden).map((c) => c.dataset.conv),
    unreadConvs: qa('.conv').filter((c) => c.classList.contains('is-unread')).map((c) => c.dataset.conv),
    tabActive: qa('.conv-tab').find((t) => t.classList.contains('is-active'))?.dataset.folder || null,
    tabCounts: Object.fromEntries(qa('.conv-tab').map((t) => [t.dataset.folder, t.querySelector('.conv-tab__count').textContent])),
    counter: q('[data-conv-count]')?.textContent,
    newCnt: q('[data-conv-new]')?.textContent,
    headUnread: q('.msgs-head__unread .num')?.textContent,
    emptyHidden: q('[data-conv-empty]')?.hidden,
    hiddenInput: q('[data-reply-conv]')?.value,
    flash: q('.flash__text')?.textContent.trim() || null,
    clock: q('[data-countdown]')?.textContent,
    notices: qa('.sys').map((s) => s.querySelector('.sys__title')?.textContent.trim() + ' / ' + s.querySelector('.sys__time')?.textContent.trim()),
    maint: q('.maint__label')?.textContent.trim(),
  };
});

// ---------------------------------------------------------------- 1) carga
console.log('seed:', reseed().replace(/\n/g, ' | '));
await go();
let s = await state();
check('1. /mensagens carrega com conversa aberta', !!s.openThread && s.openMsgs.length > 0, `${s.openThread} (${s.openMsgs.length} balões)`);
check('1b. contadores da lista batem com o visível', s.counter === String(s.visible.length), `contador=${s.counter} visíveis=${s.visible.length}`);
const start = s;

// ------------------------------------------------- 2) clicar em cada conversa
const ids = await page.evaluate(() => [...document.querySelectorAll('.conv')].map((c) => ({ id: c.dataset.conv, folder: c.dataset.folder })));
let switched = 0;
for (const { id, folder } of ids) {
  await page.click(`.conv-tab[data-folder="${folder}"]`);   // como o usuário faria: pasta certa antes
  await wait(220);
  await page.click(`.conv[data-conv="${id}"]`);
  await wait(300);
  const cur = await state();
  const ok = cur.openThread === id && cur.activeConv === id && cur.hiddenInput === id && cur.url.endsWith('/mensagens/' + id);
  if (ok) switched++; else console.log('   -> falhou em', id, JSON.stringify(cur).slice(0, 220));
}
check(`2. clicar troca a thread nas ${ids.length} conversas (painel + URL + campo oculto)`, switched === ids.length, `${switched}/${ids.length}`);

// ------------------------------------------------- 3) conteúdo realmente muda
await go();
await page.click('.conv[data-conv="m1"]'); await wait(350);
const a = await state();
await page.click('.conv[data-conv="m6"]'); await wait(350);
const b = await state();
check('3. balões/assunto mudam entre m1 e m6',
  a.subject !== b.subject && JSON.stringify(a.openMsgs) !== JSON.stringify(b.openMsgs) && a.openFrom !== b.openFrom,
  `${a.openFrom}:"${a.subject}" -> ${b.openFrom}:"${b.subject}"`);

// ------------------------------------------------- 4) marcar como lida grava
reseed();
await go();
const s4 = await state();
const dbUnread = JSON.parse(sql('SELECT slug FROM message_threads WHERE user_id=1 AND unread=1 ORDER BY slug')).map((r) => r.slug);
check('4a. "NÃO LIDAS" do cabeçalho vem do banco',
  s4.headUnread === String(dbUnread.length) && dbUnread.sort().join(',') === [...s4.unreadConvs].sort().join(','),
  `HUD=${s4.headUnread} [${s4.unreadConvs}] · banco=[${dbUnread}]`);

const target = s4.unreadConvs[0];
await page.click(`.conv[data-conv="${target}"]`);
await wait(1100);
const after4 = await state();
const dbAfter = JSON.parse(sql(`SELECT unread FROM message_threads WHERE user_id=1 AND slug='${target}'`))[0]?.unread;
await go();
const persisted = await state();
check('4b. clique marca como lida, grava no banco e persiste no reload',
  !after4.unreadConvs.includes(target) && String(dbAfter) === '0' && !persisted.unreadConvs.includes(target),
  `${target}: banco.unread=${dbAfter} · após reload=[${persisted.unreadConvs.join(',') || 'nenhuma'}]`);

// ------------------------------------------------- 5) abas de pasta filtram
await go();
const tabResults = [];
for (const f of ['sistema', 'enviadas', 'entrada']) {
  await page.click(`.conv-tab[data-folder="${f}"]`); await wait(320);
  const t = await state();
  const rule = await page.evaluate((folder) => [...document.querySelectorAll('.conv')]
    .filter((c) => (folder === 'sistema' ? c.dataset.system === '1' : c.dataset.folder === folder))
    .map((c) => c.dataset.conv), f);
  tabResults.push({ f, ok: t.tabActive === f && JSON.stringify(t.visible) === JSON.stringify(rule) && t.counter === String(rule.length) && t.tabCounts[f] === String(rule.length), vis: t.visible.join(','), count: t.tabCounts[f] });
}
tabResults.forEach((r) => check(`5. aba ${r.f.toUpperCase()} filtra e o contador bate`, r.ok, `visíveis=[${r.vis}] badge=${r.count}`));

// ------------------------------------------------- 6) troca de aba reabre thread
await go();
await page.click('.conv-tab[data-folder="enviadas"]'); await wait(400);
const sent = await state();
check('6. mudar de pasta reabre a 1ª conversa da pasta', sent.visible.includes(sent.openThread) && sent.openThread !== start.openThread, `abriu ${sent.openThread} (antes ${start.openThread})`);

// ------------------------------------------------- 7) busca
await go();
await page.type('[data-conv-search]', 'ghost'); await wait(420);
const found = await state();
await page.evaluate(() => { const i = document.querySelector('[data-conv-search]'); i.value = 'zzzznaoexiste'; i.dispatchEvent(new Event('input', { bubbles: true })); });
await wait(400);
const none = await state();
await page.evaluate(() => { const i = document.querySelector('[data-conv-search]'); i.value = ''; i.dispatchEvent(new Event('input', { bubbles: true })); });
await wait(350);
const back = await state();
check('7. busca filtra a lista', found.visible.length > 0 && found.visible.length < start.visible.length, `"ghost" -> [${found.visible.join(',')}]`);
check('7b. busca sem resultado mostra o vazio', none.visible.length === 0 && none.emptyHidden === false, `contador=${none.counter}`);
check('7c. limpar a busca restaura', back.visible.length === start.visible.length, `${back.visible.length} conversas`);

// ------------------------------------------------- 8) enviar resposta (POST real)
await go('/mensagens/m4');
const msg = 'Teste automatizado ' + Date.now();
await page.type('[data-reply-input]', msg);
await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('[data-reply-send]')]);
await wait(1200);
const sentState = await state();
check('8. enviar resposta faz POST, grava e volta para a conversa',
  sentState.openThread === 'm4' && sentState.openMsgs.includes(msg) && sentState.url.endsWith('/mensagens/m4'),
  `flash="${sentState.flash}" último="${sentState.openMsgs.at(-1)}"`);

await go('/mensagens/m4');
const reload = await state();
check('8b. a resposta persiste após recarregar (banco)', reload.openMsgs.includes(msg), `${reload.openMsgs.length} balões`);
check('8c. a resposta entrou como último balão', reload.openMsgs.at(-1) === msg, `último="${reload.openMsgs.at(-1)}"`);

const rows = JSON.parse(sql(`SELECT t.user_id, COUNT(*) n FROM message_entries e JOIN message_threads t ON t.id=e.thread_id WHERE e.body LIKE 'Teste automatizado%' GROUP BY t.user_id`));
check('8d. o balão foi gravado SÓ para o jogador atual (user_id=1)',
  rows.length === 1 && String(rows[0].user_id) === '1',
  JSON.stringify(rows));
const others = JSON.parse(sql(`SELECT t.user_id, COUNT(*) n FROM message_entries e JOIN message_threads t ON t.id=e.thread_id WHERE t.user_id<>1 AND t.slug='m4' GROUP BY t.user_id`));
check('8e. a conversa m4 dos outros jogadores ficou intacta (3 balões cada)',
  others.length > 0 && others.every((r) => String(r.n) === '3'),
  JSON.stringify(others));

// ------------------------------------------------- 9) enviar vazio
await go('/mensagens/m4');
const before9 = (await state()).openMsgs.length;
await page.click('[data-reply-send]'); await wait(700);
const after9 = await state();
check('9. enviar vazio não cria balão', after9.openMsgs.length === before9, `${before9} -> ${after9.openMsgs.length} balões`);

// ------------------------------------------------- 10) rota com parâmetro
for (const id of ['m6', 's3', 'm1']) {
  await go('/mensagens/' + id);
  const st = await state();
  check(`10. rota /mensagens/${id} abre a conversa certa no servidor`, st.openThread === id && st.activeConv === id && st.tabActive === (id.startsWith('s') ? 'enviadas' : 'entrada'), `pasta=${st.tabActive} assunto="${st.subject}"`);
}
const bad = await page.goto(BASE + '/mensagens/naoexiste', { waitUntil: 'domcontentloaded' });
check('10b. conversa inexistente devolve 404', bad.status() === 404, 'status ' + bad.status());

// ------------------------------------------------- 11) links do painel lateral
await go();
const links = await page.evaluate(() => [...document.querySelectorAll('.msgs a')].map((a) => ({ href: a.getAttribute('href'), txt: a.textContent.trim().slice(0, 28) })));
check('11. nenhum href="#" morto', links.every((l) => l.href && l.href !== '#' && l.href !== ''), links.length + ' links');
const codes = [];
for (const h of [...new Set(links.map((l) => l.href))]) {
  const r = await page.goto('http://localhost' + h, { waitUntil: 'domcontentloaded' });
  codes.push(h + '=' + r.status());
}
check('11b. todos os links do painel respondem 200', codes.every((c) => c.endsWith('=200')), codes.join(' '));

// ------------------------------------------------- 12) ações da thread + tooltips
await go();
const acts = await page.evaluate(() => [...document.querySelectorAll('.thread:not([hidden]) .thread__act')].map((a) => a.getAttribute('href') + '|' + a.dataset.tip));
check('12. ações da conversa têm destino e rótulo', acts.length === 3 && acts.every((a) => !a.startsWith('#')), acts.join(' '));

// ------------------------------------------------- 13) contador de manutenção
await go();
const c1 = (await state()).clock; await wait(2500); const c2 = (await state()).clock;
check('13. contador de manutenção anda', c1 !== c2 && /^\d\d:\d\d:\d\d$/.test(c2), `${c1} -> ${c2}`);

// ------------------------------------------------- 14) layout / vazamento
await go();
const layout = await page.evaluate(() => {
  const bad = [];
  document.querySelectorAll('.msgs *').forEach((el) => {
    const cs = getComputedStyle(el);
    if (el.clientWidth > 0 && el.scrollWidth > el.clientWidth + 2 && cs.overflowX === 'visible') bad.push('OX ' + el.className + ' ' + el.scrollWidth + '>' + el.clientWidth);
    if (el.clientHeight > 0 && el.scrollHeight > el.clientHeight + 2 && cs.overflowY === 'visible' && !el.children.length) bad.push('OY ' + el.className + ' ' + el.scrollHeight + '>' + el.clientHeight + ' :: ' + el.textContent.trim().slice(0, 30));
  });
  const r = document.querySelector('.msgs').getBoundingClientRect();
  return { bad: bad.slice(0, 12), overflowsViewport: document.documentElement.scrollWidth > window.innerWidth, right: Math.round(r.right) };
});
check('14. nada vaza/corta e a página não rola na horizontal', layout.bad.length === 0 && !layout.overflowsViewport, layout.bad.join(' | '));

// ------------------------------------------------- 15) imagens
const imgs = await page.evaluate(() => [...document.querySelectorAll('.msgs img')].map((i) => ({ src: i.getAttribute('src'), w: i.naturalWidth, h: i.naturalHeight, ok: i.complete && i.naturalWidth > 0 })));
check('15. todos os retratos carregam', imgs.length > 0 && imgs.every((i) => i.ok), `${imgs.length} imagens`);

// ------------------------------------------------- 16) dados são do banco
const dbCheck = await page.evaluate(() => document.querySelector('.thread__subject-date')?.textContent.trim());
check('16. carimbo de data renderizado', /\d/.test(dbCheck || ''), dbCheck);

// ------------------------------------------------- 17) avisos e manutenção do banco
await go();
const s17 = await state();
const dbNotices = JSON.parse(sql('SELECT title FROM system_notices WHERE user_id=1 ORDER BY sort_order, id')).map((r) => r.title);
check('17. avisos do painel lateral vêm do banco (system_notices)',
  s17.notices.length === dbNotices.length && dbNotices.every((t, i) => s17.notices[i].startsWith(t)),
  s17.notices.join(' ; '));
const dbWin = JSON.parse(sql('SELECT starts_at, ends_at FROM maintenance_windows WHERE starts_at > NOW() ORDER BY starts_at LIMIT 1'))[0];
const label = dbWin ? `JANELA ${dbWin.starts_at.slice(8, 10)}/${dbWin.starts_at.slice(5, 7)} · ${dbWin.starts_at.slice(11, 13)}H — ${dbWin.ends_at.slice(11, 13)}H` : '';
check('17b. janela de manutenção vem de maintenance_windows', s17.maint === label, `tela="${s17.maint}" banco="${label}"`);

// ------------------------------------------------- 18) sem data_mock estático
const usesMock = execFileSync(PHP, ['-r', "echo (int)str_contains(file_get_contents('app/Controllers/MessagesController.php'), 'data_mock');"], { cwd: 'C:/wamp64/www/extracthunters' }).toString();
check('18. o controller não usa data_mock', usesMock.trim() === '0', 'data_mock encontrado: ' + usesMock);

const noise = ['/mensagens/naoexiste', 'status of 404'];
const real = [...new Set(errs)].filter((e) => !noise.some((n) => e.includes(n)));
console.log('\nERROS DE CONSOLE/REDE:', real.length ? JSON.stringify(real, null, 1) : 'nenhum (o 404 de /mensagens/naoexiste é o caso 10b)');
const fail = results.filter((r) => !r.pass);
console.log(`\n${results.length - fail.length}/${results.length} casos OK`);
await browser.close();
process.exit(fail.length || real.length ? 2 : 0);
