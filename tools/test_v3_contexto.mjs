/**
 * PRD v3 (mundo persistente) — critérios de aceite do P0 (§40) nas telas de identidade central.
 *
 * Verifica que a interface responde às três perguntas: "em qual estado estou operando?"
 * (badge de estado na topbar, pré-visualização ?estado=… em dev), "para quem estou trabalhando?"
 * (Hunter Command / Aegis / Frontier em Home, Jogar, Lobby, Perfil, Missões, Facções) e "o que
 * está acontecendo neste mundo agora?" (Mapa: capacidade 20, mundo persistente, bases com estado,
 * camadas, extrações tipadas e bloqueadas para o Sobrevivente).
 *
 *   node tools/test_v3_contexto.mjs
 * Usa o Apache local (http://localhost/extracthunters) e o banco real, como test_dados_dinamicos.
 * A pré-visualização `?estado=` só existe em dev (Config::debug()) — em produção o estado vem do banco.
 * Sai com código 2 se qualquer caso falhar.
 */
import puppeteer from 'puppeteer-core';
import { existsSync } from 'node:fs';

const CHROME = ['C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
const BASE = 'http://localhost/extracthunters';
let falhas = 0;
const check = (nome, ok, detalhe = '') => {
  if (!ok) falhas++;
  console.log(`${ok ? 'OK  ' : 'FALHA'} ${nome}${detalhe ? ' — ' + detalhe : ''}`);
};

const browser = await puppeteer.launch({
  executablePath: CHROME, headless: true,
  args: ['--no-sandbox', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
  defaultViewport: { width: 1672, height: 941 },
});
const page = await browser.newPage();

/** Navega e devolve texto do <main> (sem topbar) + texto da topbar, já em maiúsculas. */
async function abrir(rota) {
  const resp = await page.goto(`${BASE}/${rota}`, { waitUntil: 'networkidle2' });
  await new Promise((r) => setTimeout(r, 400));
  const t = await page.evaluate(() => ({
    main: (document.querySelector('.hud-main') || document.body).innerText.toUpperCase(),
    topbar: (document.querySelector('.topbar') || {}).innerText?.toUpperCase() ?? '',
    badge: document.querySelector('.topbar .badge--state')?.textContent.trim().toUpperCase() ?? '',
    survivorChip: !!document.querySelector('.topbar .badge--state.chip--survivor'),
    phpErro: /(Warning|Fatal error|Deprecated|Undefined (variable|array key|index))/.test(document.body.innerText),
  }));
  return { status: resp?.status() ?? 0, ...t };
}

const P0 = ['inicio', 'jogar', 'lobby', 'perfil', 'mapa', 'missoes', 'faccoes'];

/* ---------------------------------------------------------------- topbar (§15) */
for (const rota of P0) {
  const r = await abrir(rota);
  check(`${rota}: 200 sem aviso PHP`, r.status === 200 && !r.phpErro, `status=${r.status}`);
  check(`${rota}: topbar responde "em qual estado?" (badge HUNTER COMMAND)`,
    r.badge.includes('HUNTER COMMAND'), `badge="${r.badge}"`);
}

/* ---------------------------------------------------------------- Home (§16) */
{
  const r = await abrir('inicio');
  const home = await page.evaluate(() => ({
    menu: [...document.querySelectorAll('.menu-btn strong')].map((e) => e.textContent.trim().toUpperCase()),
    opstat: !!document.querySelector('.opstat'),
    origem: document.querySelector('.contract__origin')?.textContent.trim().toUpperCase() ?? '',
  }));
  check('inicio: menu sem JOGAR SOLO e sem card SAIR', !home.menu.includes('JOGAR SOLO') && !home.menu.includes('SAIR'), home.menu.join(' | '));
  check('inicio: menu com ENTRAR EM OPERAÇÃO e FORMAR ESQUADRÃO',
    home.menu.includes('ENTRAR EM OPERAÇÃO') && home.menu.includes('FORMAR ESQUADRÃO'));
  check('inicio: faixa STATUS OPERACIONAL presente', home.opstat && r.main.includes('STATUS OPERACIONAL'));
  check('inicio: contrato ativo mostra ORIGEM', home.origem !== '', `origem="${home.origem}"`);
}

/* ---------------------------------------------------------------- Jogar (§17) */
{
  const r = await abrir('jogar');
  for (const t of ['OPERAÇÃO', 'HUNTER COMMAND', 'MERCENÁRIO', 'FACÇÕES']) check(`jogar: contém "${t}"`, r.main.includes(t));
  for (const t of ['6V6', 'PARTIDA RÁPIDA', 'BUSCAR PARTIDA', 'JOGADORES MÁX']) check(`jogar: não contém "${t}"`, !r.main.includes(t));
  const cap = await page.evaluate(() => document.querySelector('.jogar-cap')?.innerText.replace(/\s+/g, ' ') ?? '');
  check('jogar: capacidade do setor 20 (12 Hunters / 8 Mercenários)', /20/.test(cap) && /12/.test(cap) && /8/.test(cap), cap);
  check('jogar: SOBREVIVENTE não é opção selecionável para o Hunter', !r.main.includes('RETORNAR AO MUNDO'));
}

/* ---------------------------------------------------------------- Lobby (§18) */
{
  const r = await abrir('lobby');
  const lb = await page.evaluate(() => ({
    cards: document.querySelectorAll('.op-card').length,
    roles: [...document.querySelectorAll('.op-card__class')].map((e) => e.textContent.trim().toUpperCase()),
    ready: document.querySelectorAll('.op-card__ready').length,
    readonly: document.querySelectorAll('.set-row--readonly').length,
    cta: [...document.querySelectorAll('.btn')].map((b) => b.textContent.trim().toUpperCase()),
  }));
  check('lobby: no máximo 4 cards (3 membros + vaga)', lb.cards <= 4 && lb.cards >= 2, `cards=${lb.cards}`);
  const ROLES = ['ASSAULT', 'SNIPER', 'MEDICAL', 'RASTREADOR'];
  check('lobby: funções ∈ {ASSAULT, SNIPER, MEDICAL, RASTREADOR}',
    lb.roles.length > 0 && lb.roles.every((x) => ROLES.some((k) => x.includes(k))), lb.roles.join(','));
  check('lobby: prontidão por operador', lb.ready >= 2, `ready=${lb.ready}`);
  check('lobby: COMANDO identificado (não só a coroa)', r.main.includes('COMANDO'));
  check('lobby: sem "ATÉ 5" / "JOGADORES MÁX"', !r.main.includes('ATÉ 5') && !r.main.includes('JOGADORES MÁX'));
  check('lobby: clima e fogo amigo são informação (linhas readonly)', lb.readonly >= 2, `readonly=${lb.readonly}`);
  check('lobby: CTA MOBILIZAR ESQUADRÃO', lb.cta.some((t) => t.includes('MOBILIZAR ESQUADRÃO')));
}

/* ---------------------------------------------------------------- Perfil (§21) */
{
  const r = await abrir('perfil');
  check('perfil: sem RESISTÊNCIA como organização', !r.main.includes('RESISTÊNCIA'));
  for (const t of ['HUNTER COMMAND', 'AEGIS', 'FRONTIER', 'PATENTE']) check(`perfil: contém "${t}"`, r.main.includes(t));
}

/* ---------------------------------------------------------------- Mapa (§23) */
{
  const r = await abrir('mapa');
  check('mapa: MUNDO: PERSISTENTE', r.main.includes('PERSISTENTE') && !r.main.includes('TEMPO DO CICLO'));
  check('mapa: CAPACIDADE OPERACIONAL 20 (não "jogadores máx.")', r.main.includes('CAPACIDADE OPERACIONAL') && !r.main.includes('JOGADORES MÁX'));
  const mp = await page.evaluate(() => ({
    gov: document.querySelectorAll('#map3d-layer .mk[data-kind="gov_base"]').length,
    chips: [...document.querySelectorAll('[data-map-filters] .mlayer')].map((c) => c.textContent.trim().toUpperCase()),
    sites: document.querySelectorAll('.rbase--aegis, .rbase--frontier').length,
    tipos: [...document.querySelectorAll('.rext__t')].map((e) => e.textContent.trim().toUpperCase()),
    vagas: /\b\d+\s*\/\s*4\b/.test(document.querySelector('.rbases')?.innerText ?? ''),
  }));
  check('mapa: 3 Bases Hunter com estado', mp.gov === 3 && r.main.includes('OPERACIONAL'), `gov_base=${mp.gov}`);
  check('mapa: sem vagas exatas nas bases', !mp.vagas);
  check('mapa: instalações Aegis/Frontier com status', mp.sites >= 2 && /NEUTRA|ALIADA|HOSTIL|BLOQUEADA/.test(r.main), `sites=${mp.sites}`);
  check('mapa: camadas TODOS · HUNTER · FACÇÕES · VEYRA · BASES · EXTRAÇÕES · EVENTOS',
    JSON.stringify(mp.chips) === JSON.stringify(['TODOS', 'HUNTER', 'FACÇÕES', 'VEYRA', 'BASES', 'EXTRAÇÕES', 'EVENTOS']), mp.chips.join(','));
  check('mapa: extrações tipadas (EVAC HUNTER / PÚBLICA / TRANSPORTE AEGIS / ROTA FRONTIER)',
    ['EVAC HUNTER', 'PÚBLICA', 'TRANSPORTE AEGIS', 'ROTA FRONTIER'].every((t) => mp.tipos.includes(t)), mp.tipos.join(','));

  // camada VEYRA esconde o que não é Veyra
  await page.click('[data-map-filters] .mlayer[data-value="veyra"]');
  await new Promise((r) => setTimeout(r, 300));
  const f = await page.evaluate(() => {
    const vis = [...document.querySelectorAll('#map3d-layer .mk:not(.is-filtered)')];
    return { vis: vis.length, todasVeyra: vis.every((m) => m.dataset.layer === 'veyra'), ocultos: document.querySelectorAll('#map3d-layer .mk.is-filtered').length };
  });
  check('mapa: filtro VEYRA mostra só a camada Veyra', f.vis > 0 && f.todasVeyra && f.ocultos > 0, `visíveis=${f.vis} ocultos=${f.ocultos}`);
}

/* ---------------------------------------------------------------- Missões (§24) */
{
  const r = await abrir('missoes');
  const ms = await page.evaluate(() => {
    const cards = [...document.querySelectorAll('.mcard')];
    return {
      cards: cards.length,
      semOrigem: cards.filter((c) => !c.querySelector('.badge--origin')).length,
      requer: document.querySelectorAll('.mcard .badge--requirement').length,
      filtros: [...document.querySelectorAll('[data-filters] .filter-row strong')].map((e) => e.textContent.trim().toUpperCase()),
    };
  });
  check('missoes: todo card mostra ORIGEM', ms.cards > 0 && ms.semOrigem === 0, `cards=${ms.cards} semOrigem=${ms.semOrigem}`);
  check('missoes: pelo menos um REQUER CONTRATO', ms.requer >= 1, `requer=${ms.requer}`);
  check('missoes: sem "JOGADORES MÁX" (usa ESQUADRÃO 1–4)', !r.main.includes('JOGADORES MÁX') && r.main.includes('ESQUADRÃO'));
  check('missoes: filtros por ORIGEM', ['TODAS', 'HUNTER', 'AEGIS', 'FRONTIER', 'EVENTOS', 'DIÁRIAS', 'CONCLUÍDAS'].every((t) => ms.filtros.includes(t)), ms.filtros.join(','));
  // os painéis de detalhe não selecionados ficam [hidden] (fora do innerText): lê os CTAs direto
  const ctas = await page.evaluate(() => [...document.querySelectorAll('.accept-btn__label')].map((e) => e.textContent.trim().toUpperCase()));
  check('missoes: Hunter vê "ATUAR COMO MERCENÁRIO" no contrato de facção', ctas.includes('ATUAR COMO MERCENÁRIO'), ctas.filter((c, i, a) => a.indexOf(c) === i).join(' | '));

  await page.click('[data-filters] .filter-row[data-value="aegis"]');
  await new Promise((r) => setTimeout(r, 300));
  const fl = await page.evaluate(() => {
    const vis = [...document.querySelectorAll('.mcard:not(.is-hidden)')];
    return { vis: vis.length, todasAegis: vis.every((c) => c.dataset.origin === 'aegis') };
  });
  check('missoes: filtro AEGIS mostra só contratos Aegis', fl.vis > 0 && fl.todasAegis, `visíveis=${fl.vis}`);
}

/* ---------------------------------------------------------------- Facções (§27) */
{
  const r = await abrir('faccoes');
  check('faccoes: 200 com AEGIS e FRONTIER', r.status === 200 && r.main.includes('AEGIS') && r.main.includes('FRONTIER'));
  const post = await page.evaluate(async () => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const body = new URLSearchParams({ faction: 'aegis', _csrf: token });   // sem confirm=1
    const res = await fetch(location.origin + location.pathname.replace(/\/faccoes$/, '') + '/faccoes/assinar', {
      method: 'POST', body, headers: { 'X-CSRF-Token': token }, credentials: 'same-origin',
    });
    return res.status;
  });
  check('faccoes: POST /faccoes/assinar sem confirmação → 422', post === 422, `status=${post}`);
}

/* ------------------------------------------------ pré-visualização de estado (dev) */
{
  const r = await abrir('mapa?estado=sobrevivente');
  check('sobrevivente: topbar mostra SOBREVIVENTE · SEM EXTRAÇÃO com faixa branca',
    r.badge.includes('SOBREVIVENTE') && r.survivorChip, `badge="${r.badge}"`);
  const sv = await page.evaluate(() => ({
    ext: document.querySelectorAll('.rext__i').length,
    bloq: document.querySelectorAll('.rext__i--blocked').length,
    mk: document.querySelectorAll('#map3d-layer .mk--blocked').length,
    tip: document.body.innerHTML.includes('INDISPONÍVEL NO ESTADO SOBREVIVENTE.'),
  }));
  check('sobrevivente: todas as extrações BLOQUEADAS', sv.ext > 0 && sv.ext === sv.bloq, `${sv.bloq}/${sv.ext}`);
  check('sobrevivente: marcador de extração bloqueado + tooltip canônico', sv.mk >= 1 && sv.tip);

  const a = await abrir('perfil?estado=aegis');
  check('mercenário (aegis): topbar MERCENÁRIO · AEGIS e REP', a.badge.includes('MERCENÁRIO') && a.badge.includes('AEGIS') && a.topbar.includes('REP'), `badge="${a.badge}"`);
  const m = await abrir('mapa?estado=aegis');
  check('mercenário (aegis): instalação Aegis ALIADA e Frontier HOSTIL', m.main.includes('ALIADA') && m.main.includes('HOSTIL'));
  const j = await abrir('jogar?estado=sobrevivente');
  check('sobrevivente: Jogar mostra RETORNAR AO MUNDO (estado só quando ativo)', j.main.includes('RETORNAR AO MUNDO'));
}

await browser.close();
console.log(`\n${falhas === 0 ? 'TUDO OK' : falhas + ' FALHA(S)'}`);
process.exit(falhas ? 2 : 0);
