/**
 * Generic screenshot of any URL (or local file) at 1672x941 with Chrome, for quick visual checks.
 *
 *   node tools/shot.mjs http://localhost/extracthunters/inicio captures/x.png
 *   node tools/shot.mjs assets/_dev/topbar.html captures/topbar.png              (local file, relative to project root)
 *   node tools/shot.mjs <url> <out> --hover ".menu-btn" --click ".tab" --settle 2500 --w 1672 --h 941 --clip 0,0,1672,90
 *
 * Prints console errors, page errors and failed/4xx requests. Exit code 0 always (inspect output).
 */
import puppeteer from 'puppeteer-core';
import { existsSync, mkdirSync } from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const ROOT = path.resolve(import.meta.dirname, '..');
const argv = process.argv.slice(2);
const pos = argv.filter((a, i) => !a.startsWith('--') && !['--hover', '--click', '--settle', '--w', '--h', '--clip'].includes(argv[i - 1]));
const opt = (n, d) => { const i = argv.indexOf(n); return i >= 0 ? argv[i + 1] : d; };
let [target, out] = pos;
if (!target) { console.error('usage: node tools/shot.mjs <url|file> [out.png] [--hover sel] [--click sel] [--settle ms] [--w px] [--h px] [--clip x,y,w,h]'); process.exit(1); }
if (!/^https?:\/\//.test(target)) target = pathToFileURL(path.resolve(ROOT, target)).href;
out = path.resolve(ROOT, out || 'captures/shot.png');
mkdirSync(path.dirname(out), { recursive: true });
const W = Number(opt('--w', 1672)), H = Number(opt('--h', 941));

const CHROME = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(p => existsSync(p));
const browser = await puppeteer.launch({
  executablePath: CHROME, headless: true,
  args: [`--window-size=${W},${H}`, '--hide-scrollbars', '--force-device-scale-factor=1', '--enable-gpu', '--use-angle=swiftshader',
    '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist', '--no-sandbox', '--lang=pt-BR', '--allow-file-access-from-files'],
  defaultViewport: { width: W, height: H, deviceScaleFactor: 1 },
});
const page = await browser.newPage();
const errors = [];
page.on('console', m => { if (m.type() === 'error') errors.push('CONSOLE: ' + m.text().slice(0, 300)); });
page.on('pageerror', e => errors.push('PAGEERROR: ' + e.message.slice(0, 300)));
page.on('requestfailed', r => errors.push(`REQFAIL ${r.failure()?.errorText || ''} ${r.url()}`));
page.on('response', r => { if (r.status() >= 400) errors.push(`HTTP ${r.status()} ${r.url()}`); });
try { await page.goto(target, { waitUntil: 'networkidle2', timeout: 45000 }); } catch (e) { errors.push('GOTO: ' + e.message.slice(0, 200)); }
await page.evaluate(() => document.fonts?.ready).catch(() => {});
const hover = opt('--hover'), click = opt('--click');
if (hover) { try { await page.hover(hover); } catch (e) { errors.push('HOVER: ' + e.message.slice(0, 200)); } }
if (click) { try { await page.click(click); } catch (e) { errors.push('CLICK: ' + e.message.slice(0, 200)); } }
await new Promise(r => setTimeout(r, Number(opt('--settle', 2500))));
const clip = opt('--clip');
const shot = { path: out, captureBeyondViewport: false };
if (clip) { const [x, y, w, h] = clip.split(',').map(Number); shot.clip = { x, y, width: w, height: h }; }
await page.screenshot(shot);
await browser.close();
const uniq = [...new Set(errors)];
console.log(`${uniq.length ? 'WARN' : 'OK  '} ${path.relative(ROOT, out)}${uniq.length ? '\n  ' + uniq.slice(0, 12).join('\n  ') : ''}`);
