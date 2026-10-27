/**
 * Build dos assets estáticos do Worker.
 *
 * O PHP serve ../assets direto do disco e versiona cada URL com `?v=<filemtime>` (asset()).
 * No Worker não há disco: este script
 *   1. copia ../assets para public/assets (o diretório de Static Assets do wrangler.toml),
 *      deixando de fora o que já não sobe no deploy PHP (img/_gen, assets/assets, _dev) e os
 *      rascunhos que o .htaccess bloqueia (.fix, .md, .bak...);
 *   2. separa os arquivos grandes demais para Static Assets (limite de 25 MiB por arquivo):
 *      eles vão para o R2 (scripts/upload-r2.mjs) e o Worker os entrega de lá;
 *   3. grava src/generated/assets.json com o mtime de cada arquivo — o mesmo número que o
 *      PHP põe no `?v=`, então as URLs saem idênticas às da produção atual;
 *   4. grava src/generated/media.json com o que existe em ../media (para media() saber
 *      quando cair de volta para assets/img, igual ao PHP);
 *   5. escreve public/_headers com os cabeçalhos de segurança e de cache.
 *
 * Cópia incremental: arquivo com mesmo tamanho e mtime não é copiado de novo.
 */
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const AQUI = path.dirname(fileURLToPath(import.meta.url));
const RAIZ_TS = path.resolve(AQUI, '..');
const RAIZ_PHP = path.resolve(RAIZ_TS, '..');
const ORIGEM = path.join(RAIZ_PHP, 'assets');
const MEDIA = path.join(RAIZ_PHP, 'media');
const DESTINO = path.join(RAIZ_TS, 'public', 'assets');
const GERADO = path.join(RAIZ_TS, 'src', 'generated');

/** Limite de Workers Static Assets por arquivo (25 MiB). Margem de 1 MiB. */
const LIMITE_ASSET = 24 * 1024 * 1024;

/** Pastas que não sobem (mesma decisão do deploy PHP, ver docs/NAVEGACAO-SPA.md). */
const PASTAS_FORA = ['img/_gen', 'assets', '_dev'];
/** Extensões que o .htaccess recusa em qualquer pasta. */
const EXT_FORA = /\.(md|lock|log|sql|ini|sh|bak|orig|dist|example|fix|yml|yaml|toml|php)$/i;

async function listar(dir, base = dir) {
  const out = [];
  let itens;
  try {
    itens = await fs.readdir(dir, { withFileTypes: true });
  } catch {
    return out;
  }
  for (const it of itens) {
    const abs = path.join(dir, it.name);
    const rel = path.relative(base, abs).split(path.sep).join('/');
    if (it.name.startsWith('.')) continue;
    if (it.isDirectory()) {
      if (PASTAS_FORA.includes(rel)) continue;
      out.push(...(await listar(abs, base)));
    } else if (it.isFile()) {
      if (EXT_FORA.test(it.name)) continue;
      out.push(rel);
    }
  }
  return out;
}

async function copiarSeMudou(origem, destino, st) {
  try {
    const d = await fs.stat(destino);
    if (d.size === st.size && Math.floor(d.mtimeMs / 1000) === Math.floor(st.mtimeMs / 1000)) return false;
  } catch {
    /* não existe ainda */
  }
  await fs.mkdir(path.dirname(destino), { recursive: true });
  await fs.copyFile(origem, destino);
  await fs.utimes(destino, st.atime, st.mtime);
  return true;
}

const HEADERS = `# Gerado por scripts/build.mjs — não editar à mão.
# Mesmos cabeçalhos estáticos que o .htaccess aplica aos arquivos servidos sem PHP.
/assets/*
  X-Content-Type-Options: nosniff
  X-Frame-Options: DENY
  Referrer-Policy: strict-origin-when-cross-origin
  Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()
  Cross-Origin-Resource-Policy: same-origin
  Cache-Control: public, max-age=86400
`;

async function main() {
  const t0 = Date.now();
  const arquivos = await listar(ORIGEM);
  const versoes = {};
  const r2 = [];
  let copiados = 0;
  let bytes = 0;

  for (const rel of arquivos) {
    const abs = path.join(ORIGEM, rel);
    const st = await fs.stat(abs);
    versoes[rel] = Math.floor(st.mtimeMs / 1000);
    if (st.size > LIMITE_ASSET) {
      r2.push(rel);
      continue;
    }
    bytes += st.size;
    if (await copiarSeMudou(abs, path.join(DESTINO, rel), st)) copiados++;
  }

  // Remove do public/ o que saiu da origem (arquivo apagado ou que passou a ir para o R2).
  const publicados = new Set(arquivos.filter((r) => !r2.includes(r)));
  for (const rel of await listar(DESTINO)) {
    if (!publicados.has(rel)) await fs.rm(path.join(DESTINO, rel), { force: true });
  }

  const media = {};
  for (const rel of await listar(MEDIA)) {
    const st = await fs.stat(path.join(MEDIA, rel));
    media[rel] = Math.floor(st.mtimeMs / 1000);
  }

  await fs.mkdir(GERADO, { recursive: true });
  await fs.writeFile(path.join(GERADO, 'assets.json'), JSON.stringify({ versoes, r2, aliases: Object.fromEntries(Object.keys(versoes).filter((k) => k !== k.toLowerCase()).map((k) => [k.toLowerCase(), k])) }));
  await fs.writeFile(path.join(GERADO, 'media.json'), JSON.stringify(media));
  await fs.writeFile(path.join(RAIZ_TS, 'public', '_headers'), HEADERS);

  // Índice estático dos JSON de src/data (o bundler não faz glob em tempo de execução).
  const dados = (await fs.readdir(path.join(RAIZ_TS, 'src', 'data'))).filter((f) => f.endsWith('.json')).sort();
  const ident = (f) => '_' + f.replace(/\.json$/, '').replace(/[^a-z0-9]/gi, '_');
  await fs.writeFile(
    path.join(GERADO, 'data-index.ts'),
    '// Gerado por scripts/build.mjs a partir de src/data/*.json — não editar.\n' +
      dados.map((f) => `import ${ident(f)} from '../data/${f}';`).join('\n') +
      '\n\nexport const DATA: Record<string, unknown> = {\n' +
      dados.map((f) => `  '${f.replace(/\.json$/, '')}': ${ident(f)},`).join('\n') +
      '\n};\n',
  );

  const mb = (bytes / 1048576).toFixed(1);
  console.log(
    `assets: ${arquivos.length} arquivos (${mb} MB em public/, ${copiados} copiados), ` +
      `${r2.length} só no R2 [${r2.join(', ')}], media: ${Object.keys(media).length} — ${Date.now() - t0} ms`,
  );
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
