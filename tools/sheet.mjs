// Contact sheet of transparent PNGs over an olive panel colour, to inspect chroma-key quality.
//   node tools/sheet.mjs assets/img/items captures/_items_sheet.png
import sharp from 'sharp';
import { readdirSync } from 'node:fs';
import path from 'node:path';
const ROOT = path.resolve(import.meta.dirname, '..');
const dir = path.join(ROOT, process.argv[2] || 'assets/img/items');
const out = path.join(ROOT, process.argv[3] || 'captures/_sheet.png');
const files = readdirSync(dir).filter(f => f.endsWith('.png')).sort();
const cell = 200, cols = 7, rows = Math.ceil(files.length / cols);
const bg = await sharp({ create: { width: cols * cell, height: rows * cell, channels: 4, background: { r: 60, g: 80, b: 55, alpha: 1 } } }).png().toBuffer();
const comps = []; let i = 0;
for (const f of files) {
  const x = (i % cols) * cell, y = Math.floor(i / cols) * cell; i++;
  const buf = await sharp(path.join(dir, f)).resize({ width: cell - 16, height: cell - 16, fit: 'inside' }).png().toBuffer();
  const m = await sharp(buf).metadata();
  comps.push({ input: buf, left: x + Math.round((cell - m.width) / 2), top: y + Math.round((cell - m.height) / 2) });
  comps.push({ input: Buffer.from(`<svg width="${cell}" height="18"><text x="4" y="13" font-size="12" fill="#fff" font-family="Arial">${f.replace('.png', '')}</text></svg>`), left: x, top: y });
}
await sharp(bg).composite(comps).png().toFile(out);
console.log(files.length, 'files ->', path.relative(ROOT, out));
