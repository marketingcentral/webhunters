// Re-key icon renders: ask Gemini (image editing) to keep the object untouched and replace the
// background with flat pure magenta, so prep_assets.mjs can chroma-key it losslessly.
//
//   node tools/rekey.mjs                      all sources missing a magenta version
//   node tools/rekey.mjs --force              redo all
//   node tools/rekey.mjs --only items/ak103   filter by path fragment
//
// Sources : assets/assets/items/*  assets/img/_gen/{items,logo,keyed,icons}/*
// Output  : assets/img/_gen/magenta/<group>/<name>.png   (consumed by prep_assets.mjs)
import { readFileSync, writeFileSync, mkdirSync, existsSync, readdirSync, statSync } from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(import.meta.dirname, '..');
const OUT = path.join(ROOT, 'assets/img/_gen/magenta');
const SOURCES = [
  { dir: path.join(ROOT, 'assets/assets/items'),  group: 'items' },
  { dir: path.join(ROOT, 'assets/img/_gen/items'), group: 'items' },
  { dir: path.join(ROOT, 'assets/img/_gen/icons'), group: 'icons' },
  { dir: path.join(ROOT, 'assets/img/_gen/logo'),  group: 'logo' },
  { dir: path.join(ROOT, 'assets/img/_gen/keyed'), group: 'keyed' },
];

const args = process.argv.slice(2);
const FORCE = args.includes('--force');
const ONLY = (() => { const i = args.indexOf('--only'); return i >= 0 ? args[i + 1] : null; })();
const CONCURRENCY = 3;

const env = {};
for (const line of readFileSync(path.join(ROOT, '.env'), 'utf8').split(/\r?\n/)) {
  const m = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$/);
  if (m) env[m[1]] = m[2].replace(/^["']|["']$/g, '');
}
const KEY = env.GEMINI_APIKEY;
if (!KEY) { console.error('GEMINI_APIKEY missing from .env'); process.exit(1); }

const MODELS = ['gemini-2.5-flash-image', 'gemini-3.1-flash-image', 'nano-banana-pro-preview', 'gemini-3-pro-image'];
const PROMPT = 'Edit this image. Keep the object EXACTLY as it is: same shape, same position, same size, same lighting, same colours, same fine details and its subtle rim glow. Replace ONLY the background with a completely flat, uniform, pure magenta colour (#FF00FF) filling the whole canvas: no gradient, no vignette, no texture, no shadow on the ground, no reflections. The object must not have any magenta tint. Output a single image.';

function mime(f) { const b = readFileSync(f); return (b[0] === 0xFF && b[1] === 0xD8) ? 'image/jpeg' : b[1] === 0x50 ? 'image/png' : 'image/webp'; }

async function rekey(job) {
  const b64 = readFileSync(job.src).toString('base64');
  for (const model of MODELS) {
    try {
      const res = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent?key=${KEY}`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          contents: [{ parts: [{ text: PROMPT }, { inline_data: { mime_type: mime(job.src), data: b64 } }] }],
          generationConfig: { responseModalities: ['IMAGE'] },
        }),
      });
      const json = await res.json();
      if (!res.ok) throw new Error(json.error?.message || `HTTP ${res.status}`);
      const part = (json.candidates?.[0]?.content?.parts || []).find(p => p.inlineData?.data || p.inline_data?.data);
      const data = part?.inlineData?.data || part?.inline_data?.data;
      if (!data) throw new Error('no image in response');
      mkdirSync(path.dirname(job.out), { recursive: true });
      writeFileSync(job.out, Buffer.from(data, 'base64'));
      return { ok: true, model };
    } catch (e) { if (model === MODELS[MODELS.length - 1]) return { ok: false, err: e.message.slice(0, 140) }; }
  }
}

let jobs = [];
for (const s of SOURCES) {
  if (!existsSync(s.dir)) continue;
  for (const f of readdirSync(s.dir)) {
    if (!/\.(png|jpe?g|webp)$/i.test(f) || f.startsWith('_')) continue;
    const src = path.join(s.dir, f);
    const out = path.join(OUT, s.group, f.replace(/\.[^.]+$/, '.png'));
    if (ONLY && !`${s.group}/${f}`.includes(ONLY)) continue;
    if (!FORCE && existsSync(out) && statSync(out).size > 2048) continue;
    jobs.push({ src, out, name: `${s.group}/${f}` });
  }
}
if (!jobs.length) { console.log('Nothing to re-key.'); process.exit(0); }
console.log(`Re-keying ${jobs.length} render(s), concurrency ${CONCURRENCY}\n`);

let done = 0; const failed = [];
async function worker(q) {
  while (q.length) {
    const j = q.shift(); const r = await rekey(j); done++;
    const tag = `[${String(done).padStart(2)}/${jobs.length}]`;
    if (r.ok) console.log(`${tag} OK   ${j.name} (${r.model})`); else { console.log(`${tag} FAIL ${j.name}  ${r.err}`); failed.push(j.name); }
  }
}
const q = [...jobs];
await Promise.all(Array.from({ length: CONCURRENCY }, () => worker(q)));
console.log(`\n${jobs.length - failed.length} ok, ${failed.length} failed.`);
if (failed.length) console.log('Failed:\n  ' + failed.join('\n  '));
process.exit(failed.length ? 3 : 0);
