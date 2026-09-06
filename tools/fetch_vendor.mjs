// Vendor three.js (+ addons, recursively), a rigged soldier GLB and self-hosted fonts.
// Usage: node tools/fetch_vendor.mjs
import { writeFileSync, mkdirSync, existsSync } from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(import.meta.dirname, '..');
const THREE_DIR = path.join(ROOT, 'assets/vendor/three');
const FONT_DIR = path.join(ROOT, 'assets/fonts');
mkdirSync(THREE_DIR, { recursive: true });
mkdirSync(FONT_DIR, { recursive: true });

const VER = '0.180.0';
const BASE = `https://cdn.jsdelivr.net/npm/three@${VER}`;

const ENTRIES = [
  'examples/jsm/loaders/GLTFLoader.js',
  'examples/jsm/controls/OrbitControls.js',
  'examples/jsm/renderers/CSS2DRenderer.js',
  'examples/jsm/postprocessing/EffectComposer.js',
  'examples/jsm/postprocessing/RenderPass.js',
  'examples/jsm/postprocessing/ShaderPass.js',
  'examples/jsm/postprocessing/UnrealBloomPass.js',
  'examples/jsm/postprocessing/OutputPass.js',
  'examples/jsm/postprocessing/SMAAPass.js',
  'examples/jsm/shaders/CopyShader.js',
  'examples/jsm/shaders/VignetteShader.js',
  'examples/jsm/shaders/LuminosityHighPassShader.js',
  'examples/jsm/utils/BufferGeometryUtils.js',
  'examples/jsm/utils/SkeletonUtils.js',
];

const seen = new Set();
let fetched = 0;

async function grab(rel) {
  if (seen.has(rel)) return;
  seen.add(rel);
  const outPath = path.join(THREE_DIR, rel);
  if (existsSync(outPath)) { fetched++; return; }
  const res = await fetch(`${BASE}/${rel}`);
  if (!res.ok) { console.error(`  MISS ${rel} (HTTP ${res.status})`); return; }
  let src = await res.text();
  mkdirSync(path.dirname(outPath), { recursive: true });
  const toBuild = path
    .relative(path.dirname(outPath), path.join(THREE_DIR, 'build/three.module.js'))
    .split(path.sep).join('/');
  src = src.replace(/(from\s*['"])three(['"])/g, `$1${toBuild}$2`);
  const deps = [...src.matchAll(/from\s*['"](\.[^'"]+)['"]/g)].map(m => m[1]);
  writeFileSync(outPath, src);
  fetched++;
  process.stdout.write(`\r  fetched ${fetched} files ...`);
  for (const d of deps) {
    const depRel = path.posix.normalize(path.posix.join(path.posix.dirname(rel), d));
    if (depRel.startsWith('build/')) continue;
    await grab(depRel);
  }
}

console.log('three.js core ...');
await grab('build/three.module.js');
await grab('build/three.core.js');   // r170+ split build: three.module.js imports './three.core.js'
console.log('\nthree.js addons ...');
for (const e of ENTRIES) await grab(e);
console.log(`\n  ${fetched} three.js files -> assets/vendor/three/`);

// --- Rigged character GLB (idle/walk/run) ---
const glbOut = path.join(ROOT, 'assets/models/soldier.glb');
if (!existsSync(glbOut)) {
  console.log('character model ...');
  const gres = await fetch('https://cdn.jsdelivr.net/gh/mrdoob/three.js@r180/examples/models/gltf/Soldier.glb');
  if (gres.ok) {
    const buf = Buffer.from(await gres.arrayBuffer());
    mkdirSync(path.dirname(glbOut), { recursive: true });
    writeFileSync(glbOut, buf);
    console.log(`  soldier.glb ${(buf.length / 1024).toFixed(0)}KB -> assets/models/`);
  } else console.error('  GLB failed', gres.status);
}

// --- Google Fonts (self-hosted woff2) ---
const FONTS = [
  { css: 'Barlow+Condensed:wght@400;500;600;700', slug: 'barlow-condensed' },
  { css: 'Barlow:wght@400;500;600;700', slug: 'barlow' },
  { css: 'Share+Tech+Mono', slug: 'share-tech-mono' },
  { css: 'Anton', slug: 'anton' },
  { css: 'Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,300..600,0..1,0', slug: 'material-symbols' },
];
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
const faceCss = [];
console.log('fonts ...');
for (const f of FONTS) {
  const res = await fetch(`https://fonts.googleapis.com/css2?family=${f.css}&display=block`, { headers: { 'User-Agent': UA } });
  if (!res.ok) { console.error(`  ${f.slug} css HTTP ${res.status}`); continue; }
  let css = await res.text();
  const urls = [...new Set([...css.matchAll(/url\((https:\/\/[^)]+\.woff2)\)/g)].map(m => m[1]))];
  let i = 0;
  for (const u of urls) {
    const name = `${f.slug}-${i++}.woff2`;
    const dest = path.join(FONT_DIR, name);
    if (!existsSync(dest)) {
      const b = await fetch(u);
      if (!b.ok) continue;
      writeFileSync(dest, Buffer.from(await b.arrayBuffer()));
    }
    css = css.replaceAll(u, `./${name}`);
  }
  console.log(`  ${f.slug}: ${i} woff2`);
  faceCss.push(css);
}
faceCss.push(`
.ms {
  font-family: 'Material Symbols Outlined';
  font-weight: normal; font-style: normal; font-size: 1em; line-height: 1;
  letter-spacing: normal; text-transform: none; display: inline-block; white-space: nowrap;
  word-wrap: normal; direction: ltr; -webkit-font-smoothing: antialiased;
  font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
  user-select: none;
}
.ms.fill { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
`);
writeFileSync(path.join(FONT_DIR, 'fonts.css'), faceCss.join('\n'));
console.log('  -> assets/fonts/fonts.css');
