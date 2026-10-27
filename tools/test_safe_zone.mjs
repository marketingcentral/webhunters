/**
 * SAFE ZONE — papéis civis entre operações.
 *
 * Cobre o que a tela promete e o que o servidor tem de garantir:
 *   - as zonas aparecem, com situação e movimento;
 *   - o estado operacional decide onde se entra: Sobrevivente perde as zonas do governo e
 *     cai na ZONA FRANCA; Mercenário perde os papéis exclusivos do Hunter Command;
 *   - nenhum papel oferece botão que o servidor recusaria — e o servidor recusa mesmo assim
 *     se alguém postar direto (requisito não cumprido, CSRF ausente);
 *   - assumir grava turno com prazo, trocar substitui, encerrar apaga.
 *
 *   node tools/test_safe_zone.mjs
 * Usa o Apache local e o banco real (patch v4 aplicado). O teste deixa o jogador SEM papel
 * ativo no fim — ele encerra o próprio turno.
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

async function abrir(rota) {
  const resp = await page.goto(`${BASE}/${rota}`, { waitUntil: 'networkidle2' });
  await new Promise((r) => setTimeout(r, 350));
  return {
    status: resp?.status() ?? 0,
    ...(await page.evaluate(() => ({
      texto: (document.querySelector('.hud-main') || document.body).innerText.toUpperCase(),
      zonas: [...document.querySelectorAll('.sz-zone .row__text strong')].map((e) => e.textContent.trim()),
      travadas: document.querySelectorAll('.sz-zone.is-locked').length,
      zonaAtual: document.querySelector('.sz-zp__name')?.textContent.trim() ?? '',
      acesso: document.querySelector('.sz-zp__access')?.textContent.trim() ?? '',
      papeis: [...document.querySelectorAll('.sz-role')].map((c) => ({
        key: c.dataset.role,
        nome: c.querySelector('.sz-role__name').textContent.trim(),
        pode: !!c.querySelector('.sz-role__form'),
        emTurno: c.classList.contains('is-active'),
        motivo: c.querySelector('.sz-role__cta--off')?.dataset.tip ?? null,
      })),
      turno: document.querySelector('.sz-shift__txt strong')?.textContent.trim() ?? null,
      restante: document.querySelector('[data-shift-countdown]')?.dataset.shiftCountdown ?? null,
      phpErro: /(Warning|Fatal error|Deprecated|Undefined (variable|array key|index))/.test(document.body.innerText),
    }))),
  };
}

/**
 * POST autenticado com o CSRF da própria página. Devolve status e o TEXTO da resposta já
 * redirecionada: o aviso (flash) é consumido nesta resposta, não sobra para o próximo GET.
 */
async function post(acao, campos) {
  return page.evaluate(async (acao, campos) => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const body = new URLSearchParams({ ...campos, _csrf: token });
    const r = await fetch(acao, { method: 'POST', body, credentials: 'same-origin', redirect: 'follow' });
    return { status: r.status, texto: (await r.text()).toUpperCase() };
  }, acao, campos);
}

/* ------------------------------------------------------------- estado limpo */
{
  const r = await abrir('safe-zone');
  if (r.turno) { await post(`${BASE}/safe-zone/encerrar`, {}); }
}

/* ------------------------------------------------------------- Hunter (padrão) */
{
  const r = await abrir('safe-zone');
  check('safe-zone: 200 sem aviso PHP', r.status === 200 && !r.phpErro, `status=${r.status}`);
  // 3 governamentais (Vigia/Bravo/Charlie) + ZONA FRANCA + ENTREPOSTO CENTRAL
  check('safe-zone: as zonas conhecidas aparecem', r.zonas.length === 5, r.zonas.join(' | '));
  check('safe-zone: ZONA FRANCA existe ao lado das governamentais', r.zonas.some((z) => z.includes('FRANCA')));
  check('safe-zone: os seis papéis civis aparecem', r.papeis.length === 6, r.papeis.map((p) => p.nome).join(','));
  check('safe-zone: Hunter tem ao menos um papel assumível', r.papeis.some((p) => p.pode), r.papeis.filter((p) => p.pode).map((p) => p.key).join(','));
  check('safe-zone: papel travado explica o motivo',
    r.papeis.filter((p) => !p.pode).every((p) => (p.motivo ?? '').length > 3),
    r.papeis.filter((p) => !p.pode).map((p) => `${p.key}:${p.motivo}`).join(' | '));
  check('safe-zone: INSTRUTOR pede patente (RECRUTA não assume)',
    (r.papeis.find((p) => p.key === 'instrutor')?.motivo ?? '').includes('SARGENTO'));
  check('safe-zone: TRANSPORTADOR pede contrato mercenário',
    (r.papeis.find((p) => p.key === 'transportador')?.motivo ?? '').includes('CONTRATO'));
  check('safe-zone: nenhuma zona travada para o Hunter', r.travadas === 0, `travadas=${r.travadas}`);
}

/* ------------------------------------------------------------- assumir / trocar / encerrar */
{
  await abrir('safe-zone');
  const s1 = (await post(`${BASE}/safe-zone/assumir`, { zone: 'vigia', role: 'comerciante' })).status;
  const r1 = await abrir('safe-zone');
  check('assumir COMERCIANTE: turno aberto na zona escolhida',
    s1 < 400 && r1.turno === 'COMERCIANTE', `status=${s1} turno=${r1.turno}`);
  check('assumir: turno nasce com prazo (45 min)',
    Number(r1.restante) > 2500 && Number(r1.restante) <= 2700, `restante=${r1.restante}s`);
  check('assumir: o card do papel vira EM TURNO', r1.papeis.find((p) => p.key === 'comerciante')?.emTurno === true);

  // requisito não cumprido, postado direto: o servidor recusa e o turno anterior fica de pé
  const neg = await post(`${BASE}/safe-zone/assumir`, { zone: 'vigia', role: 'instrutor' });
  const r2 = await abrir('safe-zone');
  check('servidor recusa papel sem requisito, mesmo por POST direto',
    r2.turno === 'COMERCIANTE' && neg.texto.includes('REQUISITO NÃO CUMPRIDO'), `turno=${r2.turno}`);

  // sem CSRF: 419, nada muda
  const semToken = await page.evaluate(async (base) => {
    const r = await fetch(`${base}/safe-zone/assumir`, {
      method: 'POST', body: new URLSearchParams({ zone: 'vigia', role: 'comerciante' }), credentials: 'same-origin',
    });
    return r.status;
  }, BASE);
  check('POST sem CSRF é recusado (419)', semToken === 419, `status=${semToken}`);

  await post(`${BASE}/safe-zone/encerrar`, {});
  const r3 = await abrir('safe-zone');
  check('encerrar: nenhum papel assumido', r3.turno === null && r3.texto.includes('NENHUM PAPEL ASSUMIDO'));
}

/* ------------------------------------------------------------- Sobrevivente (dev preview) */
{
  const r = await abrir('safe-zone?estado=sobrevivente');
  check('sobrevivente: as três zonas do governo ficam travadas', r.travadas === 3, `travadas=${r.travadas}`);
  check('sobrevivente: a tela cai na ZONA FRANCA', r.zonaAtual.includes('FRANCA'), r.zonaAtual);
  check('sobrevivente: acesso à zona franca é TOLERADA', r.acesso.includes('TOLERADA'), r.acesso);
  check('sobrevivente: ainda há um papel possível na zona franca', r.papeis.some((p) => p.pode || p.emTurno));

  const gov = await abrir('safe-zone/vigia?estado=sobrevivente');
  check('sobrevivente: zona do governo mostra acesso BLOQUEADA', gov.acesso.includes('BLOQUEADA'), gov.acesso);
  check('sobrevivente: zona bloqueada não oferece nenhum papel', gov.papeis.every((p) => !p.pode));
}

/* ------------------------------------------------------------- Mercenário (dev preview) */
{
  const r = await abrir('safe-zone?estado=aegis');
  check('mercenário: TRANSPORTADOR libera com contrato ativo',
    r.papeis.find((p) => p.key === 'transportador')?.pode === true);
  check('mercenário: papéis do Hunter Command ficam fora',
    ['instrutor', 'analista'].every((k) => (r.papeis.find((p) => p.key === k)?.motivo ?? '').includes('HUNTER COMMAND')),
    r.papeis.filter((p) => ['instrutor', 'analista'].includes(p.key)).map((p) => `${p.key}:${p.motivo}`).join(' | '));
}

/* ------------------------------------------------------------- integração */
{
  await page.goto(`${BASE}/inicio`, { waitUntil: 'networkidle2' });
  const menu = await page.evaluate(() => [...document.querySelectorAll('.menu-btn strong')].map((e) => e.textContent.trim()));
  check('início: SAFE ZONE está no menu principal', menu.includes('SAFE ZONE'), menu.join(' | '));

  await page.goto(`${BASE}/mapa`, { waitUntil: 'networkidle2' });
  const mapa = await page.evaluate(() => ({
    marcadores: document.querySelectorAll('#map3d-layer .mk[data-kind="safe_zone"]').length,
    legenda: [...document.querySelectorAll('.mlegend__label')].some((e) => e.textContent.includes('SAFE ZONE')),
  }));
  check('mapa: Safe Zone aparece como marcador e na legenda', mapa.marcadores >= 1 && mapa.legenda, `marcadores=${mapa.marcadores}`);
}

await browser.close();
console.log(`\n${falhas === 0 ? 'TUDO OK' : falhas + ' FALHA(S)'}`);
process.exit(falhas ? 2 : 0);
