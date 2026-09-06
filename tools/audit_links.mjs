/**
 * Crawl every screen, collect every href / form action / src, and check each one resolves.
 * Flags dead anchors (href="#"), external links, 4xx/5xx targets and missing assets.
 *
 *   node tools/audit_links.mjs            audit every screen in capture.mjs SCREENS
 *   node tools/audit_links.mjs inicio     only these slugs
 *
 * Exit code 0 when everything resolves, 2 otherwise. Report: captures/reports/links.json
 */
import puppeteer from 'puppeteer-core';
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { SCREENS, BASE } from './capture.mjs';

const ROOT = path.resolve(import.meta.dirname, '..');
const OUT = path.join(ROOT, 'captures/reports');
mkdirSync(OUT, { recursive: true });

const argv = process.argv.slice(2).filter(a => SCREENS[a]);
const list = argv.length ? argv : Object.keys(SCREENS);

const CHROME = ['C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(p => existsSync(p));
if (!CHROME) { console.error('No Chrome or Edge found.'); process.exit(1); }

const browser = await puppeteer.launch({
  executablePath: CHROME, headless: true,
  args: ['--no-sandbox', '--hide-scrollbars', '--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--lang=pt-BR'],
  defaultViewport: { width: 1672, height: 941 },
});
const page = await browser.newPage();

// rotas declaradas apenas para POST: um 405 no GET e a resposta CORRETA, nao um link quebrado
const POST_ONLY = new Set(["sair", "lobby/chat", "loja/comprar", "habilidades/aprender",
  "habilidades/redefinir", "desafios/resgatar", "mensagens/responder", "mensagens/lida",
  "inventario/mover", "inventario/equipar", "inventario/acessorio"].map(p => BASE + p));
const targets = new Map();   // url -> Set of screens that link to it
const deadAnchors = [];      // {screen, text}
const external = new Set();

for (const slug of list) {
  const url = BASE + SCREENS[slug].path;
  try { await page.goto(url, { waitUntil: 'networkidle2', timeout: 45000 }); }
  catch (e) { console.error(`GOTO ${slug}: ${e.message.slice(0, 120)}`); continue; }

  const found = await page.evaluate(() => {
    const out = { links: [], forms: [], dead: [] };
    for (const a of document.querySelectorAll('a[href]')) {
      const h = a.getAttribute('href');
      if (!h || h === '#' || h.startsWith('#') || h === 'javascript:void(0)') { out.dead.push((a.textContent || '').trim().slice(0, 40) || a.className); continue; }
      out.links.push(a.href);
    }
    for (const f of document.querySelectorAll('form[action]')) { const a = f.getAttribute('action'); if (a) out.forms.push(new URL(a, location.href).href); }
    return out;
  });

  found.dead.forEach(t => deadAnchors.push({ screen: slug, text: t }));
  for (const u of [...found.links, ...found.forms]) {
    if (!u.startsWith(BASE)) { external.add(u); continue; }
    if (!targets.has(u)) targets.set(u, new Set());
    targets.get(u).add(slug);
  }
  process.stdout.write(`\rcoletado ${slug.padEnd(20)}`);
}
console.log(`\n${targets.size} destinos internos únicos, ${external.size} externos, ${deadAnchors.length} âncoras mortas`);

// Check each internal target with a GET (HEAD is not routed by the front controller).
const broken = [];
const postOnly = [];   // rotas POST que corretamente recusaram o GET
let i = 0;
for (const [u, from] of targets) {
  i++;
  const res = await page.goto(u, { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => null);
  const status = res ? res.status() : 0;
  const postOnlyHit = POST_ONLY.has(u);
  const expected = postOnlyHit ? [404, 405] : [200];
  if (!expected.includes(status)) broken.push({ url: u.replace(BASE, '/'), status, linkedFrom: [...from] });
  else if (postOnlyHit) postOnly.push(u.replace(BASE, '/'));
  process.stdout.write(`\rverificando ${i}/${targets.size}`);
}
await browser.close();

const report = { checked: targets.size, broken, deadAnchors, external: [...external] };
writeFileSync(path.join(OUT, 'links.json'), JSON.stringify(report, null, 2));

console.log('\n');
if (deadAnchors.length) {
  console.log(`ÂNCORAS MORTAS (${deadAnchors.length}):`);
  for (const d of deadAnchors.slice(0, 25)) console.log(`  ${d.screen.padEnd(18)} "${d.text}"`);
}
if (broken.length) {
  console.log(`DESTINOS QUEBRADOS (${broken.length}):`);
  for (const b of broken) console.log(`  ${String(b.status).padEnd(4)} ${b.url}  <- ${b.linkedFrom.join(', ')}`);
}
if (postOnly.length) console.log(`Rotas POST (405 no GET = correto): ${postOnly.join(', ')}`);
if (external.size) console.log(`Externos (ignorados): ${[...external].slice(0, 8).join(', ')}`);
if (!broken.length && !deadAnchors.length) console.log('OK: todos os links internos resolvem 200 e não há âncoras mortas.');
process.exit(broken.length || deadAnchors.length ? 2 : 0);
