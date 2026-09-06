/**
 * Prova a conciliação por jogador: cria um 2º jogador, faz LOGIN de verdade e confere
 * que a caixa de mensagens é a dele (não a do HUNTER_07), e que responder numa conta
 * não vaza para a outra.
 */
import puppeteer from 'puppeteer-core';
import { execFileSync } from 'node:child_process';

const ROOT = 'C:/wamp64/www/extracthunters';
const PHP = 'C:/wamp64/bin/php/php8.3.28/php.exe';
const php = (code) => execFileSync(PHP, ['-r', `require "app/bootstrap.php"; ${code}`], { cwd: ROOT }).toString().trim();
const BASE = 'http://localhost/extracthunters';
const results = [];
const check = (n, p, d = '') => { results.push(p); console.log(`${p ? 'OK   ' : 'FALHA'} ${n}${d ? ' — ' + d : ''}`); };

const USER = 'QA_MSG_' + Date.now().toString().slice(-6);
const PASS = 'senhaQA' + Date.now().toString().slice(-4);
const uid = php(`echo App\\Repo\\UserRepo::create(${JSON.stringify(USER)}, ${JSON.stringify(USER.toLowerCase() + '@qa.local')}, ${JSON.stringify(PASS)});`);
console.log('2º jogador criado: ' + USER + ' (id ' + uid + ')');
execFileSync(PHP, ['db/seed_messages.php'], { cwd: ROOT });

// marca a caixa do jogador 2 para ficar distinguível da do jogador 1
php(`App\\Core\\Database::run("UPDATE message_threads SET subject='ASSUNTO EXCLUSIVO DO JOGADOR 2' WHERE user_id=${uid} AND slug='m4'");`);
php(`App\\Core\\Database::run("UPDATE message_threads SET unread=1 WHERE user_id=${uid}");`);

const browser = await puppeteer.launch({
  executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true,
  args: ['--no-sandbox', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
  defaultViewport: { width: 1672, height: 941 }, userDataDir: 'C:/Users/extra/AppData/Local/Temp/pptr-msguser',
});
const page = await browser.newPage();
const errs = [];
page.on('pageerror', (e) => errs.push('PAGEERROR ' + e.message.slice(0, 180)));
page.on('console', (m) => { if (m.type() === 'error') errs.push('CONSOLE ' + m.text().slice(0, 180)); });
page.on('response', (r) => { if (r.status() >= 400) errs.push(`HTTP ${r.status()} ${r.url().replace(BASE, '')}`); });
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const read = () => page.evaluate(() => ({
  name: document.querySelector('.topbar__name')?.textContent.trim(),
  head: document.querySelector('.msgs-head__unread .num')?.textContent,
  subjects: [...document.querySelectorAll('.conv__subject')].map((s) => s.textContent.trim()),
  msgs: [...document.querySelectorAll('.thread:not([hidden]) .chat__text')].map((t) => t.textContent.trim()),
  authors: [...document.querySelectorAll('.thread:not([hidden]) .chat__author')].map((t) => t.textContent.trim()),
}));

// ------ jogador 1 (demo, sem login) responde
await page.goto(BASE + '/mensagens/m6', { waitUntil: 'networkidle2' }); await wait(1200);
const p1msg = 'Mensagem do jogador 1 ' + Date.now();
await page.type('[data-reply-input]', p1msg);
await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('[data-reply-send]')]);
await wait(900);
const one = await read();
check('A. jogador demo (HUNTER_07) grava a própria resposta', one.msgs.includes(p1msg), `${one.name} · ${one.msgs.length} balões`);

// ------ login de verdade como jogador 2
await page.goto(BASE + '/login', { waitUntil: 'networkidle2' });
await page.type('input[name="user"]', USER);
await page.type('input[name="password"]', PASS);
await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('form button[type="submit"], form input[type="submit"]')]);
await wait(900);
await page.goto(BASE + '/mensagens', { waitUntil: 'networkidle2' }); await wait(1200);
const two = await read();
check('B. login troca o jogador na HUD', two.name && two.name !== one.name, `${one.name} -> ${two.name}`);
check('C. a caixa é a do jogador 2 (assunto exclusivo aparece)', two.subjects.includes('ASSUNTO EXCLUSIVO DO JOGADOR 2'), two.subjects.slice(0, 3).join(' | '));
check('D. a resposta do jogador 1 NÃO aparece para o jogador 2', !two.msgs.includes(p1msg), `${two.msgs.length} balões abertos`);
// as 10 foram marcadas como não lidas; abrir /mensagens lê a primeira -> restam 9
check('E. "não lidas" reflete a caixa do jogador 2 (10 marcadas, 1 aberta = 9)', two.head === '9', 'cabeçalho=' + two.head);

// jogador 2 responde
await page.goto(BASE + '/mensagens/m6', { waitUntil: 'networkidle2' }); await wait(900);
const p2msg = 'Mensagem do jogador 2 ' + Date.now();
await page.type('[data-reply-input]', p2msg);
await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('[data-reply-send]')]);
await wait(900);
const two2 = await read();
check('F. jogador 2 responde na própria conversa', two2.msgs.includes(p2msg) && !two2.msgs.includes(p1msg), `autor="${two2.authors.at(-1)}"`);

const rows = JSON.parse(php(`echo json_encode(App\\Core\\Database::all("SELECT t.user_id, e.body FROM message_entries e JOIN message_threads t ON t.id=e.thread_id WHERE e.body LIKE 'Mensagem do jogador%' ORDER BY t.user_id"));`));
check('G. banco: cada balão ficou no user_id certo',
  rows.length === 2 && String(rows[0].user_id) === '1' && rows[0].body === p1msg && String(rows[1].user_id) === uid && rows[1].body === p2msg,
  JSON.stringify(rows));

// sair para não deixar sessão presa
await page.goto(BASE + '/inicio', { waitUntil: 'networkidle2' });
await page.evaluate(() => document.querySelector('form[action*="sair"]')?.submit());
await wait(700);

// limpeza: remove o jogador de QA
php(`App\\Core\\Database::run("DELETE FROM users WHERE id=${uid}");`);
php(`App\\Core\\Database::run("DELETE FROM message_entries WHERE body LIKE 'Mensagem do jogador%'");`);
console.log('jogador de QA removido.');

console.log('\nERROS:', errs.length ? JSON.stringify([...new Set(errs)]) : 'nenhum');
console.log(`${results.filter(Boolean).length}/${results.length} casos OK`);
await browser.close();
process.exit(results.every(Boolean) && !errs.length ? 0 : 2);
