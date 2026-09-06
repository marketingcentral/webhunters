/**
 * Pack a reference screenshot and our capture as anonymous A/B files for a blind judge.
 *
 *   node tools/blindpack.mjs <slug> <parity 0|1> [--cap captures/<slug>.png]
 *
 * parity 0 -> A = ours, B = reference ; parity 1 -> A = reference, B = ours.
 * Writes captures/blind/<slug>-A.jpg and <slug>-B.jpg (both re-encoded to the same size/quality so
 * file metadata leaks nothing). Prints only the two output paths - never the mapping.
 */
import sharp from 'sharp';
import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { SCREENS, REF_DIR } from './capture.mjs';

const ROOT = path.resolve(import.meta.dirname, '..');
const [slug, parityArg] = process.argv.slice(2);
const opt = (n, d) => { const i = process.argv.indexOf(n); return i >= 0 ? process.argv[i + 1] : d; };
if (!slug || !SCREENS[slug] || !SCREENS[slug].ref) { console.error('usage: blindpack <slug-with-reference> <0|1>'); process.exit(1); }
const parity = Number(parityArg) % 2;
const ref = path.join(REF_DIR, SCREENS[slug].ref);
const cap = path.resolve(ROOT, opt('--cap', `captures/${slug}.png`));
const outDir = path.join(ROOT, 'captures/blind');
mkdirSync(outDir, { recursive: true });
const A = parity === 0 ? cap : ref, B = parity === 0 ? ref : cap;
const enc = (src, dst) => sharp(src).resize(1672, 941, { fit: 'fill' }).jpeg({ quality: 90, chromaSubsampling: '4:4:4' }).toFile(dst);
await enc(A, path.join(outDir, `${slug}-A.jpg`));
await enc(B, path.join(outDir, `${slug}-B.jpg`));
console.log(`captures/blind/${slug}-A.jpg\ncaptures/blind/${slug}-B.jpg`);
