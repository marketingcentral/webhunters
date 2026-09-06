// Generate every game art asset described in tools/art_manifest.json.
//   node tools/gen_art.mjs               generate anything missing
//   node tools/gen_art.mjs --force       regenerate everything
//   node tools/gen_art.mjs --only bg/    only files whose path starts with the prefix
//   node tools/gen_art.mjs --list        show what would run, generate nothing
import { readFileSync, writeFileSync, mkdirSync, existsSync, statSync } from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(import.meta.dirname, '..');
const IMG = path.join(ROOT, 'assets/img/_gen');

const args = process.argv.slice(2);
const FORCE = args.includes('--force');
const LIST = args.includes('--list');
const ONLY = (() => { const i = args.indexOf('--only'); return i >= 0 ? args[i + 1] : null; })();
const CONCURRENCY = 3;

const env = {};
for (const line of readFileSync(path.join(ROOT, '.env'), 'utf8').split(/\r?\n/)) {
  const m = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$/);
  if (m) env[m[1]] = m[2].replace(/^["']|["']$/g, '');
}
const KEY = env.GEMINI_APIKEY;
if (!KEY) { console.error('GEMINI_APIKEY missing from .env'); process.exit(1); }

const mf = JSON.parse(readFileSync(path.join(ROOT, 'tools/art_manifest.json'), 'utf8'));

// Fallback chain: if the preferred model refuses or is busy, try the next.
const FALLBACK = {
  'gemini-3-pro-image': ['gemini-3-pro-image', 'nano-banana-pro-preview', 'gemini-2.5-flash-image'],
  'gemini-2.5-flash-image': ['gemini-2.5-flash-image', 'gemini-3.1-flash-image', 'gemini-3-pro-image'],
};

function buildJob(j) {
  const isIcon = !!j.icon;
  const suffix = isIcon ? mf.iconSuffix : mf.styleSuffix;
  return {
    file: j.file,
    prompt: `${j.prompt}. ${suffix}`,
    aspectRatio: j.aspectRatio || (isIcon ? '1:1' : mf.defaults.aspectRatio),
    model: j.model || (isIcon ? 'gemini-2.5-flash-image' : mf.defaults.model),
    out: path.join(IMG, j.file),
  };
}

async function generate(job) {
  const chain = FALLBACK[job.model] || [job.model];
  for (const model of chain) {
    const url = `https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent?key=${KEY}`;
    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          contents: [{ parts: [{ text: job.prompt }] }],
          generationConfig: {
            responseModalities: ['IMAGE'],
            imageConfig: { aspectRatio: job.aspectRatio },
          },
        }),
      });
      const json = await res.json();
      if (!res.ok) throw new Error(json.error?.message || `HTTP ${res.status}`);
      const part = (json.candidates?.[0]?.content?.parts || [])
        .find(p => p.inlineData?.data || p.inline_data?.data);
      const data = part?.inlineData?.data || part?.inline_data?.data;
      if (!data) throw new Error('no image part in response');

      const buf = Buffer.from(data, 'base64');
      mkdirSync(path.dirname(job.out), { recursive: true });
      writeFileSync(job.out, buf);
      return { ok: true, model, kb: Math.round(buf.length / 1024) };
    } catch (e) {
      if (model === chain[chain.length - 1]) return { ok: false, err: e.message.slice(0, 140) };
    }
  }
  return { ok: false, err: 'exhausted' };
}

let jobs = mf.jobs.map(buildJob);
if (ONLY) jobs = jobs.filter(j => j.file.startsWith(ONLY));
if (!FORCE) jobs = jobs.filter(j => !existsSync(j.out) || statSync(j.out).size < 2048);

if (LIST) {
  console.log(`${jobs.length} job(s) would run:`);
  for (const j of jobs) console.log(`  ${j.model.padEnd(24)} ${j.aspectRatio.padEnd(5)} ${j.file}`);
  process.exit(0);
}
if (!jobs.length) { console.log('Nothing to generate - all assets present.'); process.exit(0); }

console.log(`Generating ${jobs.length} asset(s), concurrency ${CONCURRENCY}\n`);
const t0 = Date.now();
let done = 0, failed = [];

async function worker(queue) {
  while (queue.length) {
    const job = queue.shift();
    const r = await generate(job);
    done++;
    const tag = `[${String(done).padStart(2)}/${jobs.length}]`;
    if (r.ok) console.log(`${tag} OK   ${job.file}  (${r.model}, ${r.kb}KB)`);
    else { console.log(`${tag} FAIL ${job.file}  ${r.err}`); failed.push(job.file); }
  }
}

const queue = [...jobs];
await Promise.all(Array.from({ length: CONCURRENCY }, () => worker(queue)));

console.log(`\nFinished in ${((Date.now() - t0) / 1000 / 60).toFixed(1)} min. ` +
            `${jobs.length - failed.length} ok, ${failed.length} failed.`);
if (failed.length) { console.log('Failed:'); failed.forEach(f => console.log('  ' + f)); }
process.exit(failed.length ? 3 : 0);
