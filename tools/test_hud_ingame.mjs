/**
 * HUD in-game (/hud): overlay transparente + munição vinda da Unreal grava no inventário.
 *   node tools/test_hud_ingame.mjs
 */
import puppeteer from 'puppeteer-core';
import { existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const CHROME = ['C:/Program Files/Google/Chrome/Application/chrome.exe'].find(existsSync);
const PHP = 'C:/wamp64/bin/php/php8.3.28/php.exe';
const BASE = 'http://localhost/extracthunters';
let falhas = 0;
const check = (n, ok, d='') => { if(!ok) falhas++; console.log(`${ok?'OK  ':'FALHA'} ${n}${d?' — '+d:''}`); };
const noBanco = (slot) => JSON.parse(
  execFileSync(PHP, ['tools/_ammo_of.php', '1', slot],
    { cwd: 'C:/wamp64/www/extracthunters', encoding: 'utf8' }).trim());

const b = await puppeteer.launch({ executablePath: CHROME, headless: true,
  args:['--no-sandbox','--use-angle=swiftshader','--enable-unsafe-swiftshader'], defaultViewport:{width:1672,height:941} });
const p = await b.newPage();
const errs=[]; p.on('pageerror',e=>errs.push('PAGEERROR '+e.message.slice(0,140)));
p.on('console',m=>{ if(m.type()==='error') errs.push('CONSOLE '+m.text().slice(0,140)); });
p.on('response',r=>{ if(r.status()>=500) errs.push(`HTTP ${r.status()} ${r.url().replace(BASE,'')}`); });

// ponte simulada: guarda os handlers para eu disparar Hud.Ammo como a Unreal faria
await p.evaluateOnNewDocument(()=>{
  window.__handlers = {};
  window.WebNative = {
    send: () => {},
    on: (f, cb) => { (window.__handlers[f] ||= []).push(cb); },
    off: () => {},
  };
});
await p.goto(`${BASE}/hud`, { waitUntil:'networkidle2' });
await new Promise(r=>setTimeout(r,1200));

// ---- 1) overlay é transparente e não captura clique ----
const shell = await p.evaluate(()=>{
  const cs = getComputedStyle(document.body);
  const htmlBg = getComputedStyle(document.documentElement).backgroundColor;
  return { bodyBg: cs.backgroundColor, htmlBg, pe: cs.pointerEvents,
           topbar: !!document.querySelector('.topbar'), footer: !!document.querySelector('.hud-footer,.footer') };
});
const transparente = (c)=> c==='rgba(0, 0, 0, 0)' || c==='transparent';
check('fundo transparente (html e body)', transparente(shell.bodyBg) && transparente(shell.htmlBg), `body=${shell.bodyBg} html=${shell.htmlBg}`);
check('clique atravessa (pointer-events:none)', shell.pe==='none', shell.pe);
check('sem topbar/rodapé do site', !shell.topbar && !shell.footer);

// ---- 2) mostra as armas equipadas com a munição do banco ----
const cards = await p.evaluate(()=>[...document.querySelectorAll('[data-gun]')].map(c=>({
  slot:c.dataset.gun, cap:c.dataset.capacity,
  nome:c.querySelector('.ov-gun__name')?.textContent.trim(),
  cur:c.querySelector('[data-ammo-cur]')?.textContent.trim() ?? null,
  semMag:!!c.querySelector('.ov-gun__nomag'),
})));
console.log('   cards:', JSON.stringify(cards));
const prim = cards.find(c=>c.slot==='primary');
const bd = noBanco('primary');
check('primária mostra a munição do inventário', !!prim && !!bd && Number(prim.cur)===bd.cur, prim?`tela=${prim.cur} banco=${bd?.cur}`:'sem card');

// ---- 3) tiro vindo da Unreal: pinta na hora E grava no banco ----
const alvo = bd.cur - 7;
await p.evaluate((v)=>{ (window.__handlers['Hud.Ammo']||[]).forEach(cb=>cb(JSON.stringify({slot:'primary',current:v}))); }, alvo);
await new Promise(r=>setTimeout(r,150));
const naTela = await p.evaluate(()=>document.querySelector('[data-gun="primary"] [data-ammo-cur]').textContent.trim());
check('número atualiza imediatamente', Number(naTela)===alvo, `tela=${naTela} esperado=${alvo}`);

await new Promise(r=>setTimeout(r,1200));          // espera o debounce gravar
const depois = noBanco('primary');
check('munição gravada no inventário', depois.cur===alvo, `banco=${depois.cur} esperado=${alvo}`);

// ---- 4) servidor é a autoridade sobre o limite do carregador ----
await p.evaluate(()=>{ (window.__handlers['Hud.Ammo']||[]).forEach(cb=>cb(JSON.stringify({slot:'primary',current:9999}))); });
await new Promise(r=>setTimeout(r,1400));
const estourado = noBanco('primary');
check('servidor trunca na capacidade do carregador', estourado.cur===estourado.cap, `banco=${estourado.cur} cap=${estourado.cap}`);

// A partir daqui o teste provoca erros DE PROPÓSITO; o console a partir deste ponto
// não conta como regressão.
const errsAntes = errs.length;

// ---- 5) slot inválido é recusado ----
const st = await p.evaluate(async ()=>{
  const csrf = document.querySelector('meta[name=csrf-token]').content;
  const r = await fetch('/extracthunters/hud/municao', {method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({slot:'capacete', current:'5', _csrf:csrf})});
  return r.status;
});
check('slot fora da lista é recusado', st===422, `HTTP ${st}`);

check('sem erros de console / 500 (antes dos casos de erro)', errsAntes===0, errs.slice(0,3).join(' | '));
await b.close();
console.log(falhas===0 ? '\nHUD in-game: todos os casos passaram.' : `\n${falhas} falha(s).`);
process.exit(falhas?2:0);
