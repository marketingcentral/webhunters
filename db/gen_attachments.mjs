// Gera PNGs simples de attachment (silhueta militar) a partir de SVG inline — sem IA.
import sharp from 'sharp';
import path from 'node:path';
const ROOT = path.resolve(import.meta.dirname, '..');
const OUT = path.join(ROOT, 'media/items');
const A = '#c7d2b8', D = '#8a9678', K = '#1a1e16';
const svgs = {
  'att-reddot': `<rect x="34" y="70" width="60" height="18" rx="3" fill="${D}"/><rect x="30" y="34" width="68" height="40" rx="6" fill="${A}"/><rect x="40" y="42" width="48" height="24" rx="3" fill="${K}"/><circle cx="64" cy="54" r="5" fill="#e5484d"/>`,
  'att-scope': `<rect x="18" y="52" width="92" height="24" rx="6" fill="${A}"/><rect x="14" y="46" width="20" height="36" rx="4" fill="${D}"/><rect x="94" y="44" width="22" height="40" rx="4" fill="${D}"/><circle cx="104" cy="64" r="10" fill="${K}"/><path d="M104 56v16M96 64h16" stroke="#9be04a" stroke-width="1.5"/>`,
  'att-suppressor': `<rect x="20" y="52" width="88" height="24" rx="12" fill="${A}"/><rect x="20" y="52" width="88" height="24" rx="12" fill="none" stroke="${K}" stroke-width="2"/><path d="M40 52v24M56 52v24M72 52v24M88 52v24" stroke="${D}" stroke-width="2"/>`,
  'att-grip': `<rect x="52" y="30" width="24" height="34" rx="5" fill="${A}"/><path d="M50 60h28l-4 34a10 10 0 0 1-20 0z" fill="${D}"/><path d="M58 66h12M57 74h14M57 82h14" stroke="${K}" stroke-width="2"/>`,
  'att-foregrip': `<rect x="40" y="34" width="48" height="14" rx="4" fill="${D}"/><rect x="56" y="46" width="16" height="48" rx="5" fill="${A}"/><path d="M60 54h8M60 64h8M60 74h8M60 84h8" stroke="${K}" stroke-width="2"/>`,
  'att-flashlight': `<rect x="30" y="54" width="60" height="20" rx="6" fill="${A}"/><rect x="86" y="50" width="18" height="28" rx="4" fill="${D}"/><circle cx="95" cy="64" r="7" fill="#fff8d0"/><rect x="30" y="74" width="40" height="10" rx="3" fill="${K}"/>`,
};
for (const [id, inner] of Object.entries(svgs)) {
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">${inner}</svg>`;
  await sharp(Buffer.from(svg)).resize(256, 256).png().toFile(path.join(OUT, id + '.png'));
  console.log('gerado', id);
}
