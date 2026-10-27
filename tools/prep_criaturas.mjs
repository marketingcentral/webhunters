// Prepara a arte do Bestiário depois do gen_art (tools/criaturas_art_manifest.json).
//   node tools/prep_criaturas.mjs
//
// O Gemini devolve PNG de ~1.5 MB com extensão .jpg. A tela usa no máximo ~640 px de
// largura (ficha do Bestiário / carta da landing), então cada arte vira um JPEG de verdade
// e um WebP no mesmo nome, no tamanho que a tela usa. O original fica em _orig/ para poder
// reprocessar sem gastar outra geração.
import { readdirSync, mkdirSync, existsSync, copyFileSync } from 'node:fs';
import path from 'node:path';
import sharp from 'sharp';

const DIR = path.resolve(import.meta.dirname, '..', 'assets/img/criaturas');
const ORIG = path.join(DIR, '_orig');
mkdirSync(ORIG, { recursive: true });

for (const f of readdirSync(DIR).filter(n => n.endsWith('.jpg'))) {
  const src = path.join(DIR, f);
  const keep = path.join(ORIG, f.replace(/\.jpg$/, '.png'));
  if (!existsSync(keep)) copyFileSync(src, keep);
  const base = sharp(keep).resize({ width: 1280, height: 1280, fit: 'inside', withoutEnlargement: true });
  await base.clone().jpeg({ quality: 82, mozjpeg: true }).toFile(src);
  await base.clone().webp({ quality: 78 }).toFile(src.replace(/\.jpg$/, '.webp'));
  console.log('ok', f);
}
