// Monta as peças de divulgação: arte de fundo + logo oficial + slogan, nas fontes do site.
//   node tools/divulgacao.mjs            gera tudo em divulgacao/
//
// A arte vem do Gemini SEM texto (tools/divulgacao_art_manifest.json -> divulgacao/_arte) e o
// texto entra aqui, em HTML renderizado pelo Chrome: assim a logo é o PNG oficial
// (media/logo/logo-final.png) e não uma imitação desenhada pelo modelo, e o slogan usa Anton e
// Barlow Condensed de verdade — o modelo de imagem erra letras e acentos.
//
// Cada peça é um quadro com o tamanho exato do formato; mudar o slogan é mudar BRAND abaixo.
import { createRequire } from 'node:module';
import { mkdirSync, writeFileSync, existsSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

const require = createRequire(import.meta.url);
const puppeteer = require('puppeteer-core');

const ROOT = path.resolve(import.meta.dirname, '..');
const OUT = path.join(ROOT, 'divulgacao');
const ARTE = path.join(OUT, '_arte');
const f = (p) => pathToFileURL(path.join(ROOT, p)).href;

const BRAND = {
  slogan: ['CAIU DO CÉU.', 'CRESCEU EM NÓS.'],
  tagline: 'SOBREVIVA. EXTRAIA. EVOLUA.',
  genero: 'SURVIVAL TÁTICO DE EXTRAÇÃO · PvPvE · MUNDO PERSISTENTE',
  url: 'EXTRACTHUNTERS.COM.BR',
  cta: 'ENTRE NA OPERAÇÃO',
};

/**
 * layout: onde o bloco de texto pousa.
 *   left   : coluna à esquerda (key art com o herói à direita)
 *   top    : centralizado no céu (artes com o esquadrão embaixo)
 *   story  : centralizado, respeitando as zonas seguras do Instagram (~250 px em cima, ~340 embaixo)
 */
const PECAS = [
  { arq: 'extract-hunters-keyart-1920x1080.jpg',  w: 1920, h: 1080, arte: 'keyart-16x9.png',     layout: 'left' },
  { arq: 'extract-hunters-fundo-1920x1080.jpg',   w: 1920, h: 1080, arte: 'keyart-16x9.png',     layout: 'none' },
  { arq: 'extract-hunters-esquadrao-1920x1080.jpg', w: 1920, h: 1080, arte: 'fundo-16x9-2k.png', layout: 'top' },
  { arq: 'extract-hunters-post-1080x1350.jpg',    w: 1080, h: 1350, arte: 'fundo-4x5-2k.png',    layout: 'top' },
  { arq: 'extract-hunters-story-1080x1920.jpg',   w: 1080, h: 1920, arte: 'fundo-9x16-2k.png',   layout: 'story' },
];

const html = (p) => {
  const comTexto = p.layout !== 'none';
  const esq = p.layout === 'left';
  // escala do texto pelo lado menor: as três proporções mantêm o mesmo peso visual
  const u = Math.min(p.w, p.h) / 1080;
  const logoW = esq ? 760 : p.layout === 'story' ? 900 : p.w >= 1900 ? 860 : 900;
  return `<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="${f('assets/fonts/fonts.css')}">
<style>
  *{box-sizing:border-box;margin:0}
  html,body{width:${p.w}px;height:${p.h}px;overflow:hidden;background:#070907}
  .bg{position:absolute;inset:0;background:url('${pathToFileURL(path.join(ARTE, p.arte)).href}') center/cover no-repeat}
  /* véu: escurece só onde o texto pousa, o resto da arte fica intacto */
  .veu{position:absolute;inset:0;background:${
    esq ? 'linear-gradient(90deg, rgba(5,8,5,.86) 0%, rgba(5,8,5,.55) 30%, rgba(5,8,5,0) 52%), linear-gradient(0deg, rgba(5,8,5,.7) 0%, rgba(5,8,5,0) 22%)'
        : 'linear-gradient(180deg, rgba(5,8,5,.88) 0%, rgba(5,8,5,.55) 34%, rgba(5,8,5,0) 54%), linear-gradient(0deg, rgba(5,8,5,.85) 0%, rgba(5,8,5,0) 24%)'}}
  .grao{position:absolute;inset:0;opacity:.07;mix-blend-mode:overlay;
    background:repeating-linear-gradient(0deg,#fff 0 1px,transparent 1px 3px)}
  .bloco{position:absolute;display:flex;flex-direction:column;${
    esq ? `left:${110 * u}px;top:${150 * u}px;width:${820 * u}px;align-items:flex-start`
        : p.layout === 'story' ? `left:0;right:0;top:${270 * u}px;align-items:center;text-align:center`
        : `left:0;right:0;top:${70 * u}px;align-items:center;text-align:center`}}
  .logo{width:${logoW * u}px;filter:drop-shadow(0 ${6 * u}px ${24 * u}px rgba(0,0,0,.75))}
  .slogan{margin-top:${(esq ? 46 : 26) * u}px;font-family:'Anton',sans-serif;font-weight:400;line-height:.98;
    letter-spacing:.015em;font-size:${(esq ? 104 : p.layout === 'story' ? 112 : 86) * u}px;color:#e8ede6;
    text-shadow:0 ${4 * u}px ${30 * u}px rgba(0,0,0,.8)}
  .slogan b{display:block;font-weight:400;color:#9be04a;text-shadow:0 0 ${28 * u}px rgba(155,224,74,.45),0 ${4 * u}px ${30 * u}px rgba(0,0,0,.8)}
  .tag{margin-top:${22 * u}px;display:flex;align-items:center;gap:${16 * u}px;font-family:'Barlow Condensed',sans-serif;
    font-weight:600;font-size:${30 * u}px;letter-spacing:.32em;color:#c2cabf}
  .tag i{display:block;width:${54 * u}px;height:${2 * u}px;background:#9be04a;box-shadow:0 0 ${10 * u}px rgba(155,224,74,.7)}
  .pe{position:absolute;left:${(esq ? 110 : 60) * u}px;right:${(esq ? 110 : 60) * u}px;bottom:${(p.layout === 'story' ? 330 : 54) * u}px;
    display:flex;align-items:center;justify-content:${esq ? 'space-between' : 'center'};${esq ? '' : 'flex-direction:column;'}gap:${16 * u}px;
    font-family:'Barlow Condensed',sans-serif;color:#c2cabf}
  .genero{font-size:${22 * u}px;font-weight:500;letter-spacing:.24em}
  .cta{display:inline-flex;align-items:center;gap:${14 * u}px;padding:${14 * u}px ${30 * u}px;border:${2 * u}px solid #9be04a;
    background:linear-gradient(180deg,rgba(82,134,42,.92),rgba(44,77,21,.92));color:#fff;
    font-family:'Barlow Condensed',sans-serif;font-weight:700;font-size:${28 * u}px;letter-spacing:.16em;
    box-shadow:0 0 ${26 * u}px rgba(155,224,74,.35)}
  .cta small{font-weight:500;font-size:${20 * u}px;letter-spacing:.2em;color:#d9f5b4}
  .canto{position:absolute;width:${46 * u}px;height:${46 * u}px;border-color:rgba(155,224,74,.7);border-style:solid;border-width:0}
  .c1{left:${28 * u}px;top:${28 * u}px;border-left-width:${2 * u}px;border-top-width:${2 * u}px}
  .c2{right:${28 * u}px;top:${28 * u}px;border-right-width:${2 * u}px;border-top-width:${2 * u}px}
  .c3{left:${28 * u}px;bottom:${28 * u}px;border-left-width:${2 * u}px;border-bottom-width:${2 * u}px}
  .c4{right:${28 * u}px;bottom:${28 * u}px;border-right-width:${2 * u}px;border-bottom-width:${2 * u}px}
</style></head><body>
<div class="bg"></div>
${comTexto ? `<div class="veu"></div><div class="grao"></div>
<div class="canto c1"></div><div class="canto c2"></div><div class="canto c3"></div><div class="canto c4"></div>
<div class="bloco">
  <img class="logo" src="${f('media/logo/logo-final.png')}" alt="">
  <h1 class="slogan">${BRAND.slogan[0]}<b>${BRAND.slogan[1]}</b></h1>
  <div class="tag"><i></i>${BRAND.tagline}<i></i></div>
</div>
<div class="pe">
  <span class="genero">${BRAND.genero}</span>
  <span class="cta">${BRAND.cta} <small>· ${BRAND.url}</small></span>
</div>` : ''}
</body></html>`;
};

mkdirSync(OUT, { recursive: true });
const browser = await puppeteer.launch({
  executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe',
  headless: true,
  args: ['--hide-scrollbars', '--allow-file-access-from-files'],
});
const page = await browser.newPage();
for (const p of PECAS) {
  if (!existsSync(path.join(ARTE, p.arte))) { console.log('FALTA', p.arte); continue; }
  const tmp = path.join(ARTE, '_peca.html');
  writeFileSync(tmp, html(p));
  await page.setViewport({ width: p.w, height: p.h, deviceScaleFactor: 1 });
  await page.goto(pathToFileURL(tmp).href, { waitUntil: 'networkidle0' });
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({ path: path.join(OUT, p.arq), type: 'jpeg', quality: 92 });
  console.log('ok', p.arq);
}
await browser.close();
