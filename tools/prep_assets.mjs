// Asset pipeline: the raw files in assets/assets/ (and generated art in assets/img/_gen/)
// are JPEGs with a .png extension and no alpha. This script:
//   * converts photographic assets to real, optimised JPEG (+ WebP) under assets/img/
//   * chroma-keys "icon style" renders (object on a solid dark background) into
//     trimmed transparent PNGs, using a border flood-fill so dark parts of the
//     object itself are preserved, then feathers and despills the edge.
//
//   node tools/prep_assets.mjs            process everything missing / stale
//   node tools/prep_assets.mjs --force    redo all
//   node tools/prep_assets.mjs --only items/ak103   only outputs whose path contains the fragment
import sharp from 'sharp';
import { readdirSync, existsSync, mkdirSync, statSync } from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(import.meta.dirname, '..');
const RAW = path.join(ROOT, 'assets/assets');
const GEN = path.join(ROOT, 'assets/img/_gen');
const OUT = path.join(ROOT, 'assets/img');

const args = process.argv.slice(2);
const FORCE = args.includes('--force');
const ONLY = (() => { const i = args.indexOf('--only'); return i >= 0 ? args[i + 1] : null; })();

// Photographic sets: {src dir, dest dir, max width, jpeg quality}
const PHOTO = [
  { src: path.join(RAW, 'backgrounds'), dest: 'bg',        maxW: 1920, q: 86 },
  { src: path.join(RAW, 'portraits'),   dest: 'portraits', maxW: 900,  q: 86 },
  { src: path.join(RAW, 'maps'),        dest: 'regions',   maxW: 1200, q: 84 },
  { src: path.join(RAW, 'missions'),    dest: 'missions',  maxW: 1200, q: 84 },
  { src: path.join(RAW, 'news'),        dest: 'news',      maxW: 900,  q: 84 },
  { src: path.join(GEN, 'bg'),          dest: 'bg',        maxW: 1920, q: 86 },
  { src: path.join(GEN, 'ui'),          dest: 'ui',        maxW: 1400, q: 86 },
  { src: path.join(GEN, 'maps'),        dest: 'maps',      maxW: 900,  q: 84 },
  { src: path.join(GEN, 'portraits'),   dest: 'portraits', maxW: 900,  q: 86 },
  { src: path.join(GEN, 'map'),         dest: 'map',       maxW: 2048, q: 88 },
];
// Icon-style renders to chroma-key into transparent PNGs
const KEYED = [
  { src: path.join(GEN, "magenta/items"), dest: "items", maxW: 512 },
  { src: path.join(GEN, "magenta/icons"), dest: "icons", maxW: 512 },
  { src: path.join(GEN, "magenta/logo"),  dest: "logo",  maxW: 1024 },
  { src: path.join(GEN, "magenta/keyed"), dest: "ui",    maxW: 1400 },
];

const list = d => existsSync(d) ? readdirSync(d).filter(f => /\.(png|jpe?g|webp)$/i.test(f) && !f.startsWith('_')) : [];
const stem = f => f.replace(/\.[^.]+$/, '');
const fresh = (src, dst) => FORCE || !existsSync(dst) || statSync(dst).mtimeMs < statSync(src).mtimeMs;
const want = p => !ONLY || p.split(path.sep).join('/').includes(ONLY);

let done = 0, skipped = 0, failed = 0;

async function photo(set) {
  for (const f of list(set.src)) {
    const src = path.join(set.src, f);
    const base = path.join(OUT, set.dest, stem(f));
    if (!want(base)) continue;
    const jpg = base + '.jpg', webp = base + '.webp';
    if (!fresh(src, jpg) && !fresh(src, webp)) { skipped++; continue; }
    try {
      mkdirSync(path.dirname(jpg), { recursive: true });
      const img = sharp(src).rotate();
      const meta = await img.metadata();
      const w = Math.min(set.maxW, meta.width);
      await img.clone().resize({ width: w, withoutEnlargement: true }).jpeg({ quality: set.q, mozjpeg: true, progressive: true }).toFile(jpg);
      await img.clone().resize({ width: w, withoutEnlargement: true }).webp({ quality: set.q - 6 }).toFile(webp);
      done++; console.log(`photo  ${set.dest}/${stem(f)}  ${w}px`);
    } catch (e) { failed++; console.error(`FAIL   ${src}: ${e.message}`); }
  }
}

// Chroma key. Two modes:
//  * MAGENTA: re-keyed render (flat magenta bg) -> global colour-distance key + magenta despill;
//    black letterbox bars touching the border are flood-filled away too.
//  * DARK: legacy dark-bg render -> border flood-fill with adaptive tolerance.
async function keyed(set) {
  for (const f of list(set.src)) {
    const src = path.join(set.src, f);
    const dst = path.join(OUT, set.dest, stem(f) + ".png");
    if (!want(dst)) continue;
    if (!fresh(src, dst)) { skipped++; continue; }
    try {
      mkdirSync(path.dirname(dst), { recursive: true });
      let loader = sharp(src).rotate().removeAlpha();
      { // renders framed by a white margin around a dark square: trim the white frame first
        const probe = await loader.clone().raw().toBuffer({ resolveWithObject: true });
        const pw = probe.info.width, ph = probe.info.height, pc = probe.info.channels; let wn = 0, wt = 0;
        for (let x = 0; x < pw; x++) for (const y of [0, ph - 1]) { const i = (y * pw + x) * pc; wt++; if (probe.data[i] > 235 && probe.data[i + 1] > 235 && probe.data[i + 2] > 235) wn++; }
        if (wn / wt > 0.9) loader = sharp(await loader.trim({ background: "#ffffff", threshold: 40 }).png().toBuffer()).removeAlpha();
      }
      const { data, info } = await loader.raw().toBuffer({ resolveWithObject: true });
      const W = info.width, H = info.height, C = info.channels, N = W * H;

      // Magenta statistics over the whole image.
      let mn = 0, mr = 0, mg = 0, mb = 0;
      for (let p = 0; p < N; p++) { const i = p * C, r = data[i], g = data[i + 1], b = data[i + 2]; if (r > 90 && b > 50 && g < r * 0.45 && g < b * 0.7) { mn++; mr += r; mg += g; mb += b; } }
      const MAGENTA = mn / N > 0.2;
      // Border ring statistics (dark mode background estimate).
      let n = 0, sr = 0, sg = 0, sb = 0; const ring = [];
      for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
        if (x > 5 && x < W - 6 && y > 5 && y < H - 6) continue;
        const i = (y * W + x) * C; ring.push(i); sr += data[i]; sg += data[i + 1]; sb += data[i + 2]; n++;
      }
      let br, bg, bb, T;
      if (MAGENTA) { br = mr / mn; bg = mg / mn; bb = mb / mn; T = 95; }
      else {
        br = sr / n; bg = sg / n; bb = sb / n;
        let sd = 0; for (const i of ring) { const d = Math.hypot(data[i] - br, data[i + 1] - bg, data[i + 2] - bb); sd += d * d; }
        T = Math.max(10, Math.min(26, 6 + Math.sqrt(sd / n) * 4));
      }
      const dist = i => Math.hypot(data[i] - br, data[i + 1] - bg, data[i + 2] - bb);
      const isBlack = i => data[i] < 34 && data[i + 1] < 34 && data[i + 2] < 34;

      const alpha = Buffer.alloc(N, 255);
      if (MAGENTA) {
        // magenta chroma: how much the pixel is "more red&blue than green". Background ~ mbg; items (red, blue, grey, olive) ~ 0.
        const mag = i => Math.min(data[i], data[i + 2]) - data[i + 1];
        const mbg = Math.max(40, Math.min(br, bb) - bg);
        const LO = 0.30, HI = 0.78;
        for (let p = 0; p < N; p++) {
          const k = mag(p * C) / mbg;                 // 1 = pure background, 0 = clean object
          if (k >= HI) alpha[p] = 0;
          else if (k > LO) { const t = 1 - (k - LO) / (HI - LO); alpha[p] = Math.round(t * t * (3 - 2 * t) * 255); }
        }
        // letterbox bars: flood-fill near-black pixels connected to the border
        const seen = new Uint8Array(N); const st = [];
        const push = (x, y) => { const p = y * W + x; if (!seen[p] && (alpha[p] === 0 || isBlack(p * C))) { seen[p] = 1; if (alpha[p] !== 0) { alpha[p] = 0; } st.push(p); } };
        for (let x = 0; x < W; x++) { push(x, 0); push(x, H - 1); } for (let y = 0; y < H; y++) { push(0, y); push(W - 1, y); }
        while (st.length) { const p = st.pop(); const x = p % W, y = (p - x) / W; if (x > 0) push(x - 1, y); if (x < W - 1) push(x + 1, y); if (y > 0) push(x, y - 1); if (y < H - 1) push(x, y + 1); }
      } else {
        const mask = new Uint8Array(N); const st = [];
        const push = (x, y) => { const p = y * W + x; if (!mask[p] && dist(p * C) <= T) { mask[p] = 1; st.push(p); } };
        for (let x = 0; x < W; x++) { push(x, 0); push(x, H - 1); } for (let y = 0; y < H; y++) { push(0, y); push(W - 1, y); }
        while (st.length) { const p = st.pop(); const x = p % W, y = (p - x) / W; if (x > 0) push(x - 1, y); if (x < W - 1) push(x + 1, y); if (y > 0) push(x, y - 1); if (y < H - 1) push(x, y + 1); }
        for (let p = 0; p < N; p++) {
          if (mask[p]) { alpha[p] = 0; continue; }
          const x = p % W, y = (p - x) / W;
          const nb = (x > 0 && mask[p - 1]) || (x < W - 1 && mask[p + 1]) || (y > 0 && mask[p - W]) || (y < H - 1 && mask[p + W]);
          if (nb) alpha[p] = Math.max(0, Math.min(255, Math.round(((dist(p * C) - T) / (T * 1.5)) * 255 + 90)));
        }
      }

      // 1px edge erosion: pixels touching a mostly-transparent neighbour lose coverage (kills the bright fringe line).
      if (MAGENTA) {
        const a0 = Buffer.from(alpha);
        for (let p = 0; p < N; p++) {
          if (a0[p] === 0) continue;
          const x = p % W, y = (p - x) / W;
          const mn = Math.min(x > 0 ? a0[p - 1] : 255, x < W - 1 ? a0[p + 1] : 255, y > 0 ? a0[p - W] : 255, y < H - 1 ? a0[p + W] : 255);
          if (mn < 128) alpha[p] = Math.round(a0[p] * (0.35 + 0.65 * (mn / 128)));
        }
      }

      // Despeckle: drop stray alpha islands smaller than 1.5% of the largest component.
      {
        const lab = new Int32Array(N).fill(-1); const sizes = []; const st = [];
        for (let p0 = 0; p0 < N; p0++) {
          if (alpha[p0] < 40 || lab[p0] >= 0) continue;
          const id = sizes.length; let cnt = 0; lab[p0] = id; st.push(p0);
          while (st.length) { const p = st.pop(); cnt++; const x = p % W, y = (p - x) / W;
            const nb = [x > 0 ? p - 1 : -1, x < W - 1 ? p + 1 : -1, y > 0 ? p - W : -1, y < H - 1 ? p + W : -1];
            for (const q of nb) if (q >= 0 && lab[q] < 0 && alpha[q] >= 40) { lab[q] = id; st.push(q); } }
          sizes.push(cnt);
        }
        const big = Math.max(0, ...sizes);
        for (let p = 0; p < N; p++) if (lab[p] >= 0 && sizes[lab[p]] < big * 0.015) alpha[p] = 0; else if (lab[p] < 0 && alpha[p] > 0) alpha[p] = Math.min(alpha[p], 30);
      }

      // Colour source: the magenta edit tints objects. Take RGB from the ORIGINAL dark-bg render when it exists,
      // has the same size and the object bounding boxes line up (<=3px); alpha still comes from the magenta mask.
      let cdata = data, cbr = br, cbg = bg, cbb = bb, colourSrc = "magenta";
      if (MAGENTA) {
        const origDirs = set.dest === "items" ? [path.join(RAW, "items"), path.join(GEN, "items")]
                       : set.dest === "logo" ? [path.join(GEN, "logo")]
                       : set.dest === "icons" ? [path.join(GEN, "icons")] : [path.join(GEN, "keyed")];
        const cand = origDirs.flatMap(d => ["png", "jpg", "jpeg", "webp"].map(x => path.join(d, stem(f) + "." + x))).find(p => existsSync(p));
        if (cand) {
          const o = await sharp(cand).rotate().removeAlpha().raw().toBuffer({ resolveWithObject: true });
          if (o.info.width === W && o.info.height === H) {
            const od = o.data, OC = o.info.channels;
            let on = 0, or = 0, og = 0, ob = 0;
            for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) { if (x > 5 && x < W - 6 && y > 5 && y < H - 6) continue; const i = (y * W + x) * OC; or += od[i]; og += od[i + 1]; ob += od[i + 2]; on++; }
            or /= on; og /= on; ob /= on;
            // bounding boxes: alpha mask vs "differs from dark bg" in original
            let ax0 = W, ay0 = H, ax1 = 0, ay1 = 0, ox0 = W, oy0 = H, ox1 = 0, oy1 = 0;
            for (let p = 0; p < N; p++) {
              const x = p % W, y = (p - x) / W;
              if (alpha[p] > 128) { if (x < ax0) ax0 = x; if (x > ax1) ax1 = x; if (y < ay0) ay0 = y; if (y > ay1) ay1 = y; }
              const i = p * OC; if (Math.hypot(od[i] - or, od[i + 1] - og, od[i + 2] - ob) > 60) { if (x < ox0) ox0 = x; if (x > ox1) ox1 = x; if (y < oy0) oy0 = y; if (y > oy1) oy1 = y; }
            }
            const off = Math.max(Math.abs(ax0 - ox0), Math.abs(ay0 - oy0), Math.abs(ax1 - ox1), Math.abs(ay1 - oy1));
            const aw = ax1 - ax0 + 1, ah = ay1 - ay0 + 1, ow = ox1 - ox0 + 1, oh = oy1 - oy0 + 1;
            const sx = ow / aw, sy = oh / ah;
            const consistent = sx > 0.6 && sx < 1.6 && sy > 0.6 && sy < 1.6 && Math.abs(sx / sy - 1) < 0.18;
            if (off <= 3 || consistent) {
              cdata = Buffer.alloc(N * 3);
              const identity = off <= 3;
              for (let p = 0; p < N; p++) {
                const x = p % W, y = (p - x) / W;
                let qx = x, qy = y;
                if (!identity) { qx = Math.round(ox0 + (x - ax0) * sx); qy = Math.round(oy0 + (y - ay0) * sy); }
                if (qx < 0 || qy < 0 || qx >= W || qy >= H) { cdata[p * 3] = or; cdata[p * 3 + 1] = og; cdata[p * 3 + 2] = ob; continue; }
                const i = (qy * W + qx) * OC; cdata[p * 3] = od[i]; cdata[p * 3 + 1] = od[i + 1]; cdata[p * 3 + 2] = od[i + 2];
              }
              cbr = or; cbg = og; cbb = ob; colourSrc = identity ? "original(off=" + off + ")" : "original(mapped s=" + sx.toFixed(2) + "/" + sy.toFixed(2) + ")";
            } else colourSrc = "magenta(misaligned off=" + off + " s=" + sx.toFixed(2) + "/" + sy.toFixed(2) + ")";
          }
        }
      }
      const CC = cdata === data ? C : 3;

      // Despill: un-premultiply edge colours against the background tint; suppress magenta fringe.
      const rgb = Buffer.alloc(N * 3);
      const DESPILL = MAGENTA && colourSrc.startsWith("magenta");
      for (let p = 0; p < N; p++) {
        const a = alpha[p] / 255, i = p * CC, o = p * 3;
        let r = cdata[i], g = cdata[i + 1], b = cdata[i + 2];
        if (a > 0 && a < 1) { r = (r - cbr * (1 - a)) / a; g = (g - cbg * (1 - a)) / a; b = (b - cbb * (1 - a)) / a; }
        if (DESPILL && a > 0) { const spill = Math.min(r, b) - g; const lim = a < 1 ? 10 : 60; if (spill > lim) { const k = (spill - lim) * 0.8; r -= k; b -= k; g += k * 0.3; } }
        rgb[o] = Math.max(0, Math.min(255, Math.round(r))); rgb[o + 1] = Math.max(0, Math.min(255, Math.round(g))); rgb[o + 2] = Math.max(0, Math.min(255, Math.round(b)));
      }

      const rgba = await sharp(rgb, { raw: { width: W, height: H, channels: 3 } })
        .joinChannel(alpha, { raw: { width: W, height: H, channels: 1 } }).png().toBuffer();
      const trimmed = await sharp(rgba).trim({ threshold: 8 }).png().toBuffer();
      const m = await sharp(trimmed).metadata();
      const pad = Math.round(Math.max(m.width, m.height) * 0.04);
      await sharp(trimmed)
        .extend({ top: pad, bottom: pad, left: pad, right: pad, background: { r: 0, g: 0, b: 0, alpha: 0 } })
        .resize({ width: set.maxW, height: set.maxW, fit: "inside", withoutEnlargement: true })
        .png({ compressionLevel: 9 })
        .toFile(dst);
      done++; console.log(`keyed  ${set.dest}/${stem(f)}  ${MAGENTA ? "magenta" : "dark"} bg=(${br | 0},${bg | 0},${bb | 0}) T=${T.toFixed(1)} colour=${colourSrc} -> ${m.width}x${m.height}`);
    } catch (e) { failed++; console.error(`FAIL   ${src}: ${e.message}`); }
  }
}

for (const s of PHOTO) await photo(s);
for (const s of KEYED) await keyed(s);
console.log(`\n${done} written, ${skipped} up to date, ${failed} failed.`);
process.exit(failed ? 3 : 0);
