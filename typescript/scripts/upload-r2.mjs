/**
 * Sobe a mídia para o R2 (bucket do binding MEDIA em wrangler.toml).
 *
 *   node scripts/upload-r2.mjs --local            R2 local do `wrangler dev` (.wrangler/state)
 *   node scripts/upload-r2.mjs --remote           bucket na conta Cloudflare (pede confirmação)
 *   ... --so-media | --so-assets                  só uma das partes
 *   ... --forcar                                  reenvia mesmo o que não mudou
 *   ... --sim                                     não pergunta antes de gravar no bucket remoto
 *
 * O que sobe:
 *   1. ../media/**            -> chave media/<caminho>   (conteúdo do banco: itens, mapas, notícias, logo...)
 *   2. ../assets que o build marcou como grandes demais para Static Assets (> 24 MiB,
 *      hoje só models/Soldier.glb) -> chave assets/<caminho>. O Worker serve /assets/* que
 *      não existe no Static Assets a partir do R2 (src/index.ts).
 *
 * As mesmas extensões que o .htaccess proibia em media/ (php, html, svg, xml...) são puladas.
 * Um manifesto em .wrangler/r2-enviados-<alvo>.json guarda tamanho+mtime do que já subiu,
 * para a segunda execução enviar só o que mudou.
 */
import { spawn } from 'node:child_process';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import readline from 'node:readline/promises';
import { fileURLToPath } from 'node:url';

const AQUI = path.dirname(fileURLToPath(import.meta.url));
const RAIZ_TS = path.resolve(AQUI, '..');
const RAIZ_PHP = path.resolve(RAIZ_TS, '..');
const BUCKET = 'extracthunters-media';
// O R2 local guarda metadados num SQLite: escritores em paralelo colidem ("Unspecified error").
const PARALELO = process.argv.includes("--remote") ? 6 : 1;

const args = new Set(process.argv.slice(2));
const remoto = args.has('--remote');
if (!remoto && !args.has('--local')) {
  console.error('Use --local ou --remote.');
  process.exit(1);
}
const alvo = remoto ? '--remote' : '--local';

const PROIBIDA = /\.(php\d?|phtml|phps|phar|pht|cgi|pl|py|rb|sh|exe|htm|html|svg|xml|htaccess)$/i;
const TIPOS = {
  png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', webp: 'image/webp', gif: 'image/gif',
  avif: 'image/avif', glb: 'model/gltf-binary', mp4: 'video/mp4', webm: 'video/webm', json: 'application/json',
};

async function listar(dir, base = dir) {
  const out = [];
  let itens = [];
  try {
    itens = await fs.readdir(dir, { withFileTypes: true });
  } catch {
    return out;
  }
  for (const it of itens) {
    if (it.name.startsWith('.')) continue;
    const abs = path.join(dir, it.name);
    if (it.isDirectory()) out.push(...(await listar(abs, base)));
    else if (it.isFile()) out.push(path.relative(base, abs).split(path.sep).join('/'));
  }
  return out;
}

function wrangler(argv) {
  return new Promise((resolve) => {
    const p = spawn('npx', ['wrangler', ...argv], { cwd: RAIZ_TS, shell: true, stdio: ['ignore', 'pipe', 'pipe'] });
    let err = '';
    p.stderr.on('data', (d) => (err += d));
    p.stdout.on('data', (d) => (err += d));
    p.on('close', (code) => resolve({ code, err }));
  });
}

async function main() {
  const fila = [];
  if (!args.has('--so-assets')) {
    for (const rel of await listar(path.join(RAIZ_PHP, 'media'))) {
      if (PROIBIDA.test(rel)) continue;
      fila.push({ arquivo: path.join(RAIZ_PHP, 'media', rel), chave: 'media/' + rel });
    }
  }
  if (!args.has('--so-media')) {
    let r2 = [];
    try {
      r2 = JSON.parse(await fs.readFile(path.join(RAIZ_TS, 'src', 'generated', 'assets.json'), 'utf8')).r2 ?? [];
    } catch {
      console.error('src/generated/assets.json não existe: rode `npm run build` antes.');
      process.exit(1);
    }
    for (const rel of r2) fila.push({ arquivo: path.join(RAIZ_PHP, 'assets', rel), chave: 'assets/' + rel });
  }

  const manifestoArq = path.join(RAIZ_TS, '.wrangler', `r2-enviados-${remoto ? 'remote' : 'local'}.json`);
  let enviados = {};
  try {
    enviados = JSON.parse(await fs.readFile(manifestoArq, 'utf8'));
  } catch {
    enviados = {};
  }

  const pendentes = [];
  let bytes = 0;
  for (const f of fila) {
    const st = await fs.stat(f.arquivo);
    f.marca = `${st.size}:${Math.floor(st.mtimeMs)}`;
    if (!args.has('--forcar') && enviados[f.chave] === f.marca) continue;
    bytes += st.size;
    pendentes.push(f);
  }
  console.log(`${fila.length} arquivos; ${pendentes.length} a enviar (${(bytes / 1048576).toFixed(1)} MB) para ${BUCKET} ${alvo}.`);
  if (!pendentes.length) return;

  // --sim: confirmação já dada fora do terminal (ex.: execução não interativa).
  if (remoto && !args.has('--sim')) {
    const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
    const r = await rl.question('Isto grava no bucket R2 da conta Cloudflare. Continuar? (s/N) ');
    rl.close();
    if (r.trim().toLowerCase() !== 's') return;
  }

  let ok = 0;
  const falhas = [];
  let i = 0;
  async function trabalhador() {
    while (i < pendentes.length) {
      const f = pendentes[i++];
      const ext = f.chave.split('.').pop().toLowerCase();
      const argv = ['r2', 'object', 'put', `"${BUCKET}/${f.chave}"`, '--file', `"${f.arquivo}"`, alvo];
      if (TIPOS[ext]) argv.push('--content-type', TIPOS[ext]);
      const r = await wrangler(argv);
      if (r.code === 0) {
        ok++;
        enviados[f.chave] = f.marca;
      } else {
        falhas.push(`${f.chave}: ${r.err.trim().split('\n').slice(-2).join(' ')}`);
      }
      process.stdout.write(`\r${ok + falhas.length}/${pendentes.length}`);
    }
  }
  await Promise.all(Array.from({ length: PARALELO }, trabalhador));
  await fs.mkdir(path.dirname(manifestoArq), { recursive: true });
  await fs.writeFile(manifestoArq, JSON.stringify(enviados, null, 1));
  console.log(`\n${ok} enviados, ${falhas.length} falhas.`);
  for (const f of falhas) console.log('  ' + f);
  if (falhas.length) process.exit(1);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
