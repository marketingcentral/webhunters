/**
 * Screenshot HUD screens at exactly 1672x941 (native size of the reference PNGs in
 * O-QUE-VAMOS-CRIAR/Telas do jogador/) so critics can compare side by side.
 *
 *   node tools/capture.mjs                       capture every screen
 *   node tools/capture.mjs inicio lobby          only these slugs
 *   node tools/capture.mjs inicio --hover ".menu-btn:nth-child(2)" --suffix hover
 *   node tools/capture.mjs inicio --click ".tab[data-tab=status]" --suffix status
 *   node tools/capture.mjs --settle 3500         wait longer before each shot (ms)
 *   node tools/capture.mjs --list                print slug -> reference mapping
 *
 * Output: captures/<slug>[-suffix].png  (+ captures/<slug>.log.json with console errors / failed requests)
 */
import puppeteer from 'puppeteer-core';
import { mkdirSync, existsSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(import.meta.dirname, '..');
const OUT = path.join(ROOT, 'captures');

export const BASE = 'http://localhost/extracthunters/';
export const REF_DIR = path.join(ROOT, 'O-QUE-VAMOS-CRIAR/Telas do jogador');
const W = 1672, H = 941;

// slug -> { url path, reference file (or null) }
export const SCREENS = {
  login:              { path: 'login',              ref: '00-login-tela-inicial-do-jogador.png' },
  inicio:             { path: 'inicio',             ref: '01-Menu-do-jogo-inicio.png' },
  jogar:              { path: 'jogar',              ref: '02-jogar-solo-multplayer.png' },
  lobby:              { path: 'lobby',              ref: '03-lobby-ao-clicar-em-jogar-com-amigos.png' },
  configuracoes:      { path: 'configuracoes',      ref: '04-settings-quando-clicar-em-configuracoes.png' },
  inventario:         { path: 'inventario',         ref: '10-Inventario-game.png' },
  saude:              { path: 'saude',              ref: '06-saude.png' },
  habilidades:        { path: 'habilidades',        ref: '07-habilidades.png' },
  mapa:               { path: 'mapa',               ref: '08-mapas.png' },
  conquistas:         { path: 'conquistas',         ref: '09-conquistas.png' },
  missoes:            { path: 'missoes',            ref: null },
  mapas:              { path: 'mapas',              ref: null },
  desafios:           { path: 'desafios',           ref: null },
  noticias:           { path: 'noticias',           ref: null },
  loja:               { path: 'loja',               ref: null },
  amigos:             { path: 'amigos',             ref: null },
  mensagens:          { path: 'mensagens',          ref: null },
  perfil:             { path: 'perfil',             ref: null },
  suporte:            { path: 'suporte',            ref: null },
  'relatorio-de-bug': { path: 'relatorio-de-bug',   ref: null },
  'criar-conta':      { path: 'criar-conta',        ref: null },
  'recuperar-senha':  { path: 'recuperar-senha',    ref: null },
  '404':              { path: 'pagina-que-nao-existe-404', ref: null, expect: 404 },   // the document itself must answer 404
};

const isMain = process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url);

if (isMain) {
  mkdirSync(OUT, { recursive: true });
  const argv = process.argv.slice(2);
  const opt = (name, def) => { const i = argv.indexOf(name); return i >= 0 ? argv[i + 1] : def; };
  const SETTLE = Number(opt('--settle', 2800));
  const HOVER = opt('--hover', null);
  const CLICK = opt('--click', null);
  const SUFFIX = opt('--suffix', null);
  const flags = new Set(['--settle', '--hover', '--click', '--suffix']);
  const wanted = argv.filter((a, i) => !a.startsWith('--') && !flags.has(argv[i - 1]) && SCREENS[a]);
  const list = wanted.length ? wanted : Object.keys(SCREENS);

  if (argv.includes('--list')) {
    for (const [k, v] of Object.entries(SCREENS)) console.log(k.padEnd(18), v.ref ? path.join(REF_DIR, v.ref) : '(no reference)');
    process.exit(0);
  }

  const CHROME = [
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ].find(p => existsSync(p));
  if (!CHROME) { console.error('No Chrome or Edge found.'); process.exit(1); }

  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: true,
    args: [`--window-size=${W},${H}`, '--hide-scrollbars', '--force-device-scale-factor=1',
           '--enable-gpu', '--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist',
           '--no-sandbox', '--lang=pt-BR'],
    defaultViewport: { width: W, height: H, deviceScaleFactor: 1 },
  });

  const page = await browser.newPage();
  await page.setExtraHTTPHeaders({ 'Accept-Language': 'pt-BR,pt;q=0.9' });
  const errors = [];
  let expectStatus = 200;   // per-screen expected HTTP status of the main document (SCREENS[slug].expect)
  page.on('console', m => {
    if (m.type() !== 'error') return;
    // Chrome logs "Failed to load resource: ... 404" for the document itself when the page is an expected 404.
    if (expectStatus !== 200 && m.location()?.url === page.url() && /Failed to load resource/.test(m.text())) return;
    errors.push('CONSOLE: ' + m.text().slice(0, 300));
  });
  page.on('pageerror', e => errors.push('PAGEERROR: ' + e.message.slice(0, 300)));
  page.on('requestfailed', r => errors.push(`REQFAIL ${r.failure()?.errorText || ''} ${r.url().replace(BASE, '')}`));
  page.on('response', r => {
    if (r.status() < 400) return;
    if (r.status() === expectStatus && r.request().resourceType() === 'document') return;
    errors.push(`HTTP ${r.status()} ${r.url().replace(BASE, '')}`);
  });

  const results = [];
  for (const slug of list) {
    errors.length = 0;
    expectStatus = SCREENS[slug].expect ?? 200;
    const url = BASE + SCREENS[slug].path;
    try {
      await page.goto(url, { waitUntil: 'networkidle2', timeout: 45000 });
    } catch (e) { errors.push('GOTO: ' + e.message.slice(0, 200)); }
    await page.evaluate(() => document.fonts?.ready).catch(() => {});
    if (HOVER) { try { await page.hover(HOVER); } catch (e) { errors.push('HOVER: ' + e.message.slice(0, 200)); } }
    if (CLICK) { try { await page.click(CLICK); } catch (e) { errors.push('CLICK: ' + e.message.slice(0, 200)); } }
    await new Promise(r => setTimeout(r, SETTLE));

    const name = SUFFIX ? `${slug}-${SUFFIX}` : slug;
    const file = path.join(OUT, `${name}.png`);
    await page.screenshot({ path: file, captureBeyondViewport: false });
    const uniq = [...new Set(errors)];
    writeFileSync(path.join(OUT, `${name}.log.json`), JSON.stringify({ slug, url, hover: HOVER, click: CLICK, errors: uniq }, null, 2));
    results.push({ slug, errors: uniq });
    console.log(`${uniq.length ? 'WARN' : 'OK  '} ${name.padEnd(24)} -> captures/${name}.png` +
                (uniq.length ? `\n      ${uniq.slice(0, 5).join('\n      ')}` : ''));
  }

  await browser.close();
  const bad = results.filter(r => r.errors.length);
  console.log(`\n${results.length} captured, ${bad.length} with errors.`);
  process.exit(0);
}
