/**
 * Stack the reference screenshot and our capture (optionally a crop, optionally zoomed)
 * into one image so a critic can compare them in a single Read.
 *
 *   node tools/cmp.mjs inventario                      full screen, reference above ours
 *   node tools/cmp.mjs inventario --crop 0,0,1672,90   only that region (x,y,w,h in reference pixels)
 *   node tools/cmp.mjs inventario --crop 355,0,200,95 --zoom 3
 *   node tools/cmp.mjs inventario --side               place them side by side instead of stacked
 *   node tools/cmp.mjs inventario --cap captures/inventario-hover.png --out captures/cmp-hover.png
 *
 * Default output: captures/cmp-<slug>.png
 */
import sharp from 'sharp';
import path from 'node:path';
import { existsSync, mkdirSync } from 'node:fs';
import { SCREENS, REF_DIR } from './capture.mjs';

const ROOT = path.resolve(import.meta.dirname, '..');
const argv = process.argv.slice(2);
const opt = (n, d) => { const i = argv.indexOf(n); return i >= 0 ? argv[i + 1] : d; };
const slug = argv.find(a => !a.startsWith('--') && argv[argv.indexOf(a) - 1] !== '--crop'
  && argv[argv.indexOf(a) - 1] !== '--zoom' && argv[argv.indexOf(a) - 1] !== '--cap' && argv[argv.indexOf(a) - 1] !== '--out');

if (!slug || !SCREENS[slug]) { console.error('usage: node tools/cmp.mjs <slug> [--crop x,y,w,h] [--zoom n] [--side] [--cap file] [--out file]'); process.exit(1); }
if (!SCREENS[slug].ref) { console.error(`${slug} has no reference image.`); process.exit(1); }

const refFile = path.join(REF_DIR, SCREENS[slug].ref);
const capFile = path.resolve(ROOT, opt('--cap', `captures/${slug}.png`));
const out = path.resolve(ROOT, opt('--out', `captures/cmp-${slug}.png`));
if (!existsSync(capFile)) { console.error(`capture missing: ${capFile} - run: node tools/capture.mjs ${slug}`); process.exit(1); }
mkdirSync(path.dirname(out), { recursive: true });

const crop = opt('--crop') ? opt('--crop').split(',').map(Number) : null;
const zoom = Number(opt('--zoom', 1));
const side = argv.includes('--side');

async function prep(file) {
  let im = sharp(file);
  const m = await im.metadata();
  if (m.width !== 1672 || m.height !== 941) im = sharp(await im.resize(1672, 941, { fit: 'fill' }).png().toBuffer());
  if (crop) im = sharp(await im.extract({ left: crop[0], top: crop[1], width: crop[2], height: crop[3] }).png().toBuffer());
  if (zoom !== 1) im = sharp(await im.resize({ width: Math.round((crop ? crop[2] : 1672) * zoom), kernel: 'nearest' }).png().toBuffer());
  return im.png().toBuffer();
}

const a = await prep(refFile), b = await prep(capFile);
const m = await sharp(a).metadata();
const LBL = 20;
const label = t => Buffer.from(`<svg width="${m.width}" height="${LBL}"><rect width="${m.width}" height="${LBL}" fill="#000"/><text x="8" y="15" font-size="14" fill="#9be04a" font-family="Arial">${t}</text></svg>`);

const W = side ? m.width * 2 + 8 : m.width;
const H = side ? m.height + LBL : (m.height + LBL) * 2;
await sharp({ create: { width: W, height: H, channels: 4, background: { r: 0, g: 0, b: 0, alpha: 1 } } })
  .composite(side
    ? [{ input: label('REFERENCIA'), left: 0, top: 0 }, { input: a, left: 0, top: LBL },
       { input: label('NOSSO'), left: m.width + 8, top: 0 }, { input: b, left: m.width + 8, top: LBL }]
    : [{ input: label('REFERENCIA'), left: 0, top: 0 }, { input: a, left: 0, top: LBL },
       { input: label('NOSSO'), left: 0, top: m.height + LBL }, { input: b, left: 0, top: m.height + LBL * 2 }])
  .png().toFile(out);
console.log(path.relative(ROOT, out).split(path.sep).join('/'));
