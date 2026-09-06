/**
 * Blind A/B critic: shows the reference screenshot and our capture to Gemini in
 * alternating order over N rounds and asks which one looks like the real AAA game HUD.
 * The model never knows which is ours. Prints a scoreboard plus the concrete weaknesses
 * it found in OUR screen (mapped back after the fact) so the builder can fix them.
 *
 *   node tools/critic_blind.mjs inicio                 one screen (captures/inicio.png vs its reference)
 *   node tools/critic_blind.mjs --all                  every screen that has a reference
 *   node tools/critic_blind.mjs --ref a.png --cap b.png [--rounds 3] [--label lobby]
 *   node tools/critic_blind.mjs inicio --json          machine-readable output only
 *
 * Exit code 0 when ours wins or ties in >= ceil(rounds/2) rounds for every screen, 2 otherwise.
 * Reports: captures/reports/<label>.blind.json
 */
import { readFileSync, existsSync, writeFileSync, mkdirSync } from 'node:fs';
import path from 'node:path';
import sharp from 'sharp';
import { SCREENS, REF_DIR } from './capture.mjs';

const ROOT = path.resolve(import.meta.dirname, '..');
const CAP_DIR = path.join(ROOT, 'captures');
const REPORT_DIR = path.join(ROOT, 'captures/reports');
mkdirSync(REPORT_DIR, { recursive: true });

const env = {};
for (const line of readFileSync(path.join(ROOT, '.env'), 'utf8').split(/\r?\n/)) {
  const m = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$/);
  if (m) env[m[1]] = m[2].replace(/^["']|["']$/g, '');
}
const KEY = env.GEMINI_APIKEY;
if (!KEY) { console.error('GEMINI_APIKEY missing from .env'); process.exit(1); }

const argv = process.argv.slice(2);
const opt = (n, d) => { const i = argv.indexOf(n); return i >= 0 ? argv[i + 1] : d; };
const ROUNDS = Number(opt('--rounds', 3));
const JSON_ONLY = argv.includes('--json');
const MODELS = ['gemini-3.1-pro-preview', 'gemini-2.5-pro', 'gemini-pro-latest', 'gemini-3-flash-preview', 'gemini-2.5-flash', 'gemini-flash-latest'];

async function img64(file) {
  const buf = await sharp(file).resize({ width: 1280, withoutEnlargement: true }).jpeg({ quality: 88 }).toBuffer();
  return { inline_data: { mime_type: 'image/jpeg', data: buf.toString('base64') } };
}

const PROMPT = [
  'You are a senior UI/UX art director who has shipped AAA first-person shooters (Call of Duty, Battlefield, Escape from Tarkov).',
  'You are shown two screenshots, A then B, of the same menu screen of a military extraction-shooter game HUD.',
  'One of them may be a lower-quality reproduction of the other. Judge ONLY visual production quality as a AAA game menu:',
  'layout precision and alignment, typographic hierarchy and rendering, colour/contrast and glow discipline, border and panel',
  'treatment, icon quality, image quality and integration, polish and attention to detail, overall "shipped AAA game" feel.',
  'Be brutally honest and specific. Respond with STRICT JSON only, no markdown:',
  '{"winner":"A"|"B"|"tie","confidence":0-100,"reasons":["..."],"weaknesses_A":["specific visual issue with its location"],"weaknesses_B":["..."]}',
].join('\n');

async function ask(parts) {
  for (const model of MODELS) {
    try {
      const res = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent?key=${KEY}`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ contents: [{ parts }], generationConfig: { temperature: 0.4, responseMimeType: 'application/json' } }),
      });
      const json = await res.json();
      if (!res.ok) throw new Error(json.error?.message || `HTTP ${res.status}`);
      const text = json.candidates?.[0]?.content?.parts?.map(p => p.text).join('') || '';
      const m = text.match(/\{[\s\S]*\}/);
      if (!m) throw new Error('no JSON in answer');
      return { model, ...JSON.parse(m[0]) };
    } catch (e) { if (!JSON_ONLY) console.error(`  ${model}: ${e.message.slice(0, 160)}`); }
  }
  throw new Error('all models failed');
}

async function judge(label, refFile, capFile) {
  const ref = await img64(refFile), cap = await img64(capFile);
  const rounds = [];
  let wins = 0, ties = 0, losses = 0;
  const ourWeak = new Set();
  for (let r = 0; r < ROUNDS; r++) {
    const oursIsA = r % 2 === 0; // balanced alternation; the model is still blind
    const parts = [{ text: PROMPT }, { text: 'Screenshot A:' }, oursIsA ? cap : ref, { text: 'Screenshot B:' }, oursIsA ? ref : cap];
    const v = await ask(parts);
    const ours = oursIsA ? 'A' : 'B';
    const outcome = v.winner === 'tie' ? 'tie' : v.winner === ours ? 'win' : 'loss';
    if (outcome === 'win') wins++; else if (outcome === 'tie') ties++; else losses++;
    ((oursIsA ? v.weaknesses_A : v.weaknesses_B) || []).forEach(w => ourWeak.add(w));
    rounds.push({ round: r + 1, oursWas: ours, winner: v.winner, confidence: v.confidence, outcome, reasons: v.reasons, model: v.model });
    if (!JSON_ONLY) console.log(`  round ${r + 1}: ours=${ours} winner=${v.winner} (${v.confidence}%) -> ${outcome.toUpperCase()}`);
  }
  const pass = wins + ties >= Math.ceil(ROUNDS / 2);
  const report = { label, refFile, capFile, rounds, wins, ties, losses, pass, ourWeaknesses: [...ourWeak] };
  writeFileSync(path.join(REPORT_DIR, `${label}.blind.json`), JSON.stringify(report, null, 2));
  return report;
}

const targets = [];
if (opt('--ref') && opt('--cap')) {
  targets.push({ label: opt('--label', 'custom'), ref: opt('--ref'), cap: opt('--cap') });
} else {
  const slugs = argv.includes('--all') ? Object.keys(SCREENS).filter(s => SCREENS[s].ref) : argv.filter(a => SCREENS[a]);
  for (const s of slugs) {
    if (!SCREENS[s].ref) { console.error(`${s}: no reference image, skipping`); continue; }
    targets.push({ label: s, ref: path.join(REF_DIR, SCREENS[s].ref), cap: path.join(CAP_DIR, `${s}.png`) });
  }
}
if (!targets.length) { console.error('Nothing to judge. See usage in the file header.'); process.exit(1); }

let allPass = true;
const out = [];
for (const t of targets) {
  if (!existsSync(t.cap)) { console.error(`${t.label}: capture missing (${t.cap}) - run capture.mjs first`); allPass = false; continue; }
  if (!JSON_ONLY) console.log(`\n== ${t.label} ==`);
  const rep = await judge(t.label, t.ref, t.cap);
  out.push(rep);
  allPass = allPass && rep.pass;
  if (!JSON_ONLY) {
    console.log(`  => ${rep.pass ? 'PASS' : 'FAIL'}  wins=${rep.wins} ties=${rep.ties} losses=${rep.losses}`);
    if (rep.ourWeaknesses.length) console.log('  our weaknesses:\n   - ' + rep.ourWeaknesses.slice(0, 12).join('\n   - '));
  }
}
if (JSON_ONLY) console.log(JSON.stringify(out, null, 2));
process.exit(allPass ? 0 : 2);
