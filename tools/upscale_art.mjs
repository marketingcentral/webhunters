// Refaz uma arte já aprovada em resolução maior, pelo próprio Gemini.
//   node tools/upscale_art.mjs <entrada.jpg> <saida.png> [2K|4K]
//
// O gen_art devolve no tamanho padrão do modelo (~1376 px no lado maior), que serve para a
// HUD mas fica mole numa peça de divulgação de 1920 px ou num story de 1080x1920. Esticar com
// sharp só amplia o borrão; mandar a própria imagem de volta com imageSize 2K/4K faz o modelo
// redesenhar o detalhe mantendo a composição aprovada. Sempre compare com a original: o
// modelo às vezes mexe em rosto ou em pequenos elementos.
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import sharp from 'sharp';

const ROOT = path.resolve(import.meta.dirname, '..');
const [entrada, saida, tamanho = '2K'] = process.argv.slice(2);
if (!entrada || !saida) { console.error('uso: node tools/upscale_art.mjs <entrada> <saida> [2K|4K]'); process.exit(1); }

const env = {};
for (const line of readFileSync(path.join(ROOT, '.env'), 'utf8').split(/\r?\n/)) {
  const m = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$/);
  if (m) env[m[1]] = m[2].replace(/^["']|["']$/g, '');
}

const meta = await sharp(entrada).metadata();
const razoes = { '16:9': 16 / 9, '9:16': 9 / 16, '4:5': 4 / 5, '5:4': 5 / 4, '3:4': 3 / 4, '4:3': 4 / 3, '1:1': 1, '2:3': 2 / 3, '3:2': 3 / 2 };
const r = meta.width / meta.height;
const aspectRatio = Object.entries(razoes).sort((a, b) => Math.abs(a[1] - r) - Math.abs(b[1] - r))[0][0];

const res = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-image:generateContent?key=${env.GEMINI_APIKEY}`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    contents: [{ parts: [
      { inlineData: { mimeType: 'image/jpeg', data: (await sharp(entrada).jpeg({ quality: 95 }).toBuffer()).toString('base64') } },
      { text: 'Re-render this exact image at higher resolution. Keep the composition, framing, every subject, pose, colour and lighting identical; only add fine photographic detail and sharpness. Do not add or remove anything, no text.' },
    ] }],
    generationConfig: { responseModalities: ['IMAGE'], imageConfig: { aspectRatio, imageSize: tamanho } },
  }),
});
const json = await res.json();
if (!res.ok) { console.error(json.error?.message || res.status); process.exit(2); }
const part = (json.candidates?.[0]?.content?.parts || []).find(p => p.inlineData?.data);
if (!part) { console.error('sem imagem na resposta'); process.exit(3); }
writeFileSync(saida, Buffer.from(part.inlineData.data, 'base64'));
const out = await sharp(saida).metadata();
console.log(`${path.basename(entrada)} ${meta.width}x${meta.height} -> ${out.width}x${out.height} (${aspectRatio}, ${tamanho})`);
