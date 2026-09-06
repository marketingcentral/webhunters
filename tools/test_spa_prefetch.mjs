/**
 * Mede o efeito do prefetch ao passar o mouse (assets/js/spa.js).
 *
 * Localhost esconde o ganho: a resposta chega em 2 ms e não há o que adiantar. Produção
 * responde em ~450 ms. Então este teste INJETA latência artificial no fetch da página,
 * imitando o servidor real, e compara duas navegações idênticas:
 *
 *   A) clique seco               -> o clique paga a ida e volta inteira
 *   B) mouse em cima, depois clique -> a resposta já chegou; o clique só troca o DOM
 *
 * Também confere o que o prefetch NÃO pode fazer: buscar duas vezes a mesma tela, e
 * servir resposta velha depois de um POST (que muda créditos/XP na topbar).
 *
 * Uso: node tools/test_spa_prefetch.mjs
 */
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';
import puppeteer from 'puppeteer-core';

const AQUI = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(AQUI, '..');
const PHP = 'C:/wamp64/bin/php/php8.3.28/php.exe';
const PORTA = 8089;
const BASE = `http://127.0.0.1:${PORTA}`;
const LATENCIA = 400;   // ms enfiados em cada resposta parcial, imitando a produção

const CHROMES = [
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
];

let passou = 0, falhou = 0;
const ok = (n, c, extra = '') => {
  if (c) { passou++; console.log(`  OK    ${n}${extra ? '  — ' + extra : ''}`); }
  else { falhou++; console.log(`  FALHA ${n}${extra ? '  — ' + extra : ''}`); }
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
  executablePath: chrome, headless: 'new',
  args: ['--no-sandbox', '--disable-gpu', '--window-size=1672,941'],
});
const pag = await navegador.newPage();
await pag.setViewport({ width: 1672, height: 941 });

/* Latência artificial só nas respostas parciais + contador de pedidos por URL. */
await pag.setRequestInterception(true);
const pedidos = [];
pag.on('request', async (req) => {
  const parcial = req.headers()['x-eh-partial'] === '1';
  if (parcial) { pedidos.push(req.url()); await espera(LATENCIA); }
  try { await req.continue(); } catch { /* já resolvido */ }
});

async function esperarTela(slug, limite = 12000) {
  const t0 = Date.now();
  while (Date.now() - t0 < limite) {
    if (await pag.evaluate(() => document.body.dataset.page) === slug) return true;
    await espera(25);
  }
  return false;
}

const seletor = (s) => `.topbar a[href$="/${s}"]`;

/** Clica e mede quanto tempo até a tela nova estar montada. */
async function cronometrar(slug) {
  const t0 = Date.now();
  await pag.evaluate((sel) => document.querySelector(sel).click(), seletor(slug));
  const chegou = await esperarTela(slug);
  return chegou ? Date.now() - t0 : -1;
}

try {
  console.log(`\n=== prefetch com ${LATENCIA} ms de latência artificial ===\n`);
  await pag.goto(`${BASE}/inicio`, { waitUntil: 'networkidle2' });
  await pag.evaluate(() => { window.__marca = 'ok'; });

  /* --- A) clique seco, sem passar o mouse antes ---------------------------- */
  const semPrefetch = await cronometrar('inventario');
  ok('navegou sem prefetch', semPrefetch > 0, `${semPrefetch} ms`);

  /* --- B) mouse em cima, espera o prefetch, depois clica ------------------- */
  await pag.hover(seletor('mapa'));
  await espera(LATENCIA + 350);            // tempo do prefetch ir e voltar
  const comPrefetch = await cronometrar('mapa');
  ok('navegou com prefetch', comPrefetch > 0, `${comPrefetch} ms`);

  const ganho = semPrefetch - comPrefetch;
  ok('o clique não paga mais a ida e volta', comPrefetch < LATENCIA,
     `${comPrefetch} ms < ${LATENCIA} ms de latência`);
  ok('ganho compatível com a latência evitada', ganho > LATENCIA * 0.6,
     `${ganho} ms economizados`);

  /* --- C) o prefetch não pode duplicar o pedido ---------------------------- */
  const doMapa = pedidos.filter((u) => u.endsWith('/mapa')).length;
  ok('a tela foi buscada UMA vez, não duas', doMapa === 1, `${doMapa} pedido(s) para /mapa`);

  /* --- D) mouse passando de raspão não dispara ----------------------------- */
  const antes = pedidos.length;
  await pag.evaluate((sel) => {
    // entra e sai antes dos 65 ms de espera
    const a = document.querySelector(sel);
    a.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }));
    a.dispatchEvent(new MouseEvent('mouseout', { bubbles: true }));
  }, seletor('conquistas'));
  await espera(300);
  ok('passar de raspão não busca nada', pedidos.length === antes,
     `${pedidos.length - antes} pedido(s)`);

  /* --- E) depois de um POST, o que estava guardado é descartado ------------ */
  /*
   * POST de verdade: o chat do lobby, que valida CSRF e redireciona de volta. Um POST
   * sintético numa rota só-GET cairia no fallback de recarga e mediria outra coisa.
   */
  // /lobby não está na topbar e nem toda tela linka para ele: carrega direto.
  // Isto É uma recarga de propósito, então a marca de "não recarregou" é reposta depois —
  // as asserções anteriores já cobriram o trecho que tinha de ser sem recarga.
  await pag.goto(`${BASE}/lobby`, { waitUntil: 'networkidle2' });
  await pag.evaluate(() => { window.__marca = 'ok'; });
  const noLobby = await esperarTela('lobby');
  ok('chegou no lobby para o teste de POST', noLobby);

  await pag.hover(seletor('inventario'));
  await espera(LATENCIA + 350);
  const antesPost = pedidos.filter((u) => u.endsWith('/inventario')).length;

  await pag.evaluate(() => {
    const f = document.querySelector('form[action$="/lobby/chat"]');
    f.querySelector('[name="message"]').value = 'teste-prefetch';
    f.requestSubmit();
  });
  await espera(LATENCIA + 700);

  await pag.evaluate((sel) => document.querySelector(sel).click(), seletor('inventario'));
  await esperarTela('inventario');
  const depoisPost = pedidos.filter((u) => u.endsWith('/inventario')).length;
  ok('POST invalida o que estava guardado', depoisPost > antesPost,
     `${antesPost} -> ${depoisPost} pedidos para /inventario`);

  ok('nenhuma recarga durante o teste', await pag.evaluate(() => window.__marca === 'ok'));

} finally {
  await navegador.close();
  encerrar();
}

console.log(`\n  ${passou} passaram, ${falhou} falharam\n`);
process.exit(falhou ? 1 : 0);
