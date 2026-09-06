/**
 * Teste da navegação sem recarga (assets/js/spa.js).
 *
 * Sobe o servidor embutido do PHP, abre um Chrome de verdade e navega clicando nos links,
 * como um jogador faria. Verifica o que importa:
 *
 *   - a troca de tela acontece SEM recarregar o documento
 *   - o canvas do atmosphere é o MESMO objeto depois da troca (contexto WebGL preservado)
 *   - o css da tela anterior sai e o da nova entra
 *   - voltar do navegador funciona
 *   - e o que mais preocupava: revisitar uma tela não acumula setInterval nem listener
 *
 * ATENÇÃO a quem for mexer aqui: nada de `waitForFunction`. O padrão dele é sondar com
 * requestAnimationFrame dentro da página, e a varredura de época do spa.js CANCELA os rAF
 * da tela que sai — inclusive o do Puppeteer. Isso não é bug: é o sweep fazendo o trabalho.
 * Por isso toda espera aqui é sondada do lado do Node, que o sweep não alcança.
 *
 * Uso: node tools/test_spa.mjs
 */
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';
import puppeteer from 'puppeteer-core';

const AQUI = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(AQUI, '..');
const PHP = 'C:/wamp64/bin/php/php8.3.28/php.exe';
const PORTA = 8097;
const BASE = `http://127.0.0.1:${PORTA}`;

const CHROMES = [
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
];

let passou = 0;
let falhou = 0;
const ok = (nome, cond, extra = '') => {
  if (cond) { passou++; console.log(`  OK    ${nome}${extra ? '  — ' + extra : ''}`); }
  else { falhou++; console.log(`  FALHA ${nome}${extra ? '  — ' + extra : ''}`); }
};

const espera = (ms) => new Promise((r) => setTimeout(r, ms));

const ROUTER = path.join(RAIZ, '_router_test.php');
fs.writeFileSync(ROUTER, `<?php
$f = __DIR__ . '/' . ltrim((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if ($f !== __DIR__ . '/' && is_file($f)) { return false; }
require __DIR__ . '/index.php';
`);

const servidor = spawn(PHP, ['-S', `127.0.0.1:${PORTA}`, ROUTER], { cwd: RAIZ, stdio: 'ignore' });
const encerrar = () => { try { servidor.kill(); } catch {} try { fs.unlinkSync(ROUTER); } catch {} };
process.on('exit', encerrar);

await espera(1200);

const chrome = CHROMES.find((c) => fs.existsSync(c));
if (!chrome) { console.error('Nenhum Chrome/Edge encontrado.'); encerrar(); process.exit(2); }

const navegador = await puppeteer.launch({
  executablePath: chrome,
  headless: 'new',
  args: ['--no-sandbox', '--disable-gpu', '--window-size=1672,941'],
});
const pag = await navegador.newPage();
await pag.setViewport({ width: 1672, height: 941 });

const erros = [];
pag.on('pageerror', (e) => erros.push(String(e.message)));
pag.on('console', (m) => { if (m.type() === 'error') erros.push(m.text()); });

/** Espera sondando do lado do Node (ver o aviso no topo do arquivo). */
async function esperarTela(slug, limite = 9000) {
  const t0 = Date.now();
  while (Date.now() - t0 < limite) {
    if (await pag.evaluate(() => document.body.dataset.page) === slug) { await espera(120); return true; }
    await espera(80);
  }
  return false;
}

async function irPara(slug) {
  const achou = await pag.evaluate((s) => {
    const a = document.querySelector(`.topbar a[href$="/${s}"]`)
          || [...document.querySelectorAll('a[href]')].find((x) => x.getAttribute('href').endsWith('/' + s));
    if (!a) return false;
    a.click();
    return true;
  }, slug);
  if (!achou) return false;
  return esperarTela(slug);
}

/*
 * Contador de recursos vivos, instalado ANTES de qualquer script da página.
 *
 * A ordem é o ponto: o spa.js guarda referências nativas logo na avaliação do módulo
 * (`window.clearInterval.bind(window)`). Se o contador entrar depois dele, a varredura
 * limpa pelo nativo e o contador nunca vê a baixa — foi assim que este teste acusou um
 * vazamento que não existia. Entrando antes, o spa.js captura ESTES embrulhos e cada
 * limpeza do sweep é contabilizada.
 */
await pag.evaluateOnNewDocument(() => {
  window.__vivos = { intervals: 0, listeners: 0 };
  const si = window.setInterval, ci = window.clearInterval;
  window.setInterval = function (...a) { window.__vivos.intervals++; return si.apply(this, a); };
  window.clearInterval = function (id) { if (id !== undefined) window.__vivos.intervals--; return ci.call(this, id); };
  for (const alvo of [window, document]) {
    const add = alvo.addEventListener.bind(alvo), rem = alvo.removeEventListener.bind(alvo);
    alvo.addEventListener = function (...a) { window.__vivos.listeners++; return add(...a); };
    alvo.removeEventListener = function (...a) { window.__vivos.listeners--; return rem(...a); };
  }
});

try {
  console.log('\n=== navegação sem recarga ===\n');

  await pag.goto(`${BASE}/inicio`, { waitUntil: 'networkidle2' });
  /* Marca o documento. Se a marca sobreviver, não houve recarga em momento nenhum. */
  await pag.evaluate(() => { window.__marca = 'documento-original'; });

  ok('spa instalou', await pag.evaluate(() => !!history.state && history.state.spa === true));
  ok('agendadores embrulhados', await pag.evaluate(() => !/native code/.test(String(window.setTimeout))));

  /* --- 1. clicar no topbar troca a tela sem recarregar --------------------- */
  ok('foi para o inventário', await irPara('inventario'));
  ok('documento NÃO recarregou', await pag.evaluate(() => window.__marca === 'documento-original'));
  ok('URL mudou', pag.url().endsWith('/inventario'), pag.url());
  ok('título mudou', (await pag.title()).toLowerCase().includes('invent'), await pag.title());
  ok('css da tela nova entrou', await pag.evaluate(() =>
    [...document.querySelectorAll('link[data-spa-css]')].some((l) => l.href.includes('/pages/inventario.css'))));
  ok('css da tela anterior saiu', await pag.evaluate(() =>
    ![...document.querySelectorAll('link[data-spa-css]')].some((l) => l.href.includes('/pages/inicio.css'))));
  ok('miolo foi repovoado', await pag.evaluate(() => document.getElementById('main').children.length > 0));
  ok('módulo da tela rodou (boot)', await pag.evaluate(() => document.body.classList.contains('is-booted')));

  /* --- 2. canvas do atmosphere sobrevive à troca --------------------------- */
  await pag.evaluate(() => { const c = document.getElementById('atmosphere'); if (c) c.__marcado = true; });
  const tinhaCanvas = await pag.evaluate(() => !!document.getElementById('atmosphere'));
  ok('foi para o mapa', await irPara('mapa'));
  ok('canvas do atmosphere preservado', !tinhaCanvas || await pag.evaluate(() => {
    const c = document.getElementById('atmosphere');
    return !!c && c.__marcado === true;
  }));

  /* --- 3. voltar do navegador ---------------------------------------------- */
  await pag.evaluate(() => history.back());
  ok('voltar do navegador funciona', await esperarTela('inventario'), pag.url());
  ok('voltar não recarregou', await pag.evaluate(() => window.__marca === 'documento-original'));

  /* --- 4. o teste que importa: revisitar não acumula nada ------------------- */
  /*
   * Conta o que fica VIVO: cada setInterval soma, cada clearInterval subtrai; idem para
   * addEventListener/removeEventListener em window e document. Se o sweep funciona, seis
   * idas e voltas entre duas telas COM relógio de 1s fecham perto de zero.
   */
  await irPara('missoes');
  await espera(300);
  const base = await pag.evaluate(() => ({ ...window.__vivos }));

  for (let i = 0; i < 5; i++) { await irPara('conquistas'); await irPara('missoes'); }
  await espera(500);
  const depois = await pag.evaluate(() => ({ ...window.__vivos }));

  const dInt = depois.intervals - base.intervals;
  const dLis = depois.listeners - base.listeners;
  ok('5 idas e voltas não acumulam setInterval', dInt <= 1, `saldo = ${dInt}`);
  ok('5 idas e voltas não acumulam listener global', dLis <= 2, `saldo = ${dLis}`);

  /* --- 5. tudo ainda de pé -------------------------------------------------- */
  ok('tela continua montada no fim', await pag.evaluate(() =>
    document.body.dataset.page === 'missoes' && document.getElementById('main').children.length > 0));
  ok('nenhuma recarga do começo ao fim', await pag.evaluate(() => window.__marca === 'documento-original'));

  const graves = erros.filter((e) => !/favicon|net::ERR_|Failed to load resource/i.test(e));
  ok('sem erro de JavaScript', graves.length === 0, graves.slice(0, 3).join(' | '));

} finally {
  await navegador.close();
  encerrar();
}

console.log(`\n  ${passou} passaram, ${falhou} falharam\n`);
process.exit(falhou ? 1 : 0);
